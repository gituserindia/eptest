<?php
// File: website-settings.php
// This script provides an interface for SuperAdmins and Admins to manage general website settings.
// It handles displaying current settings, processing form submissions, and updating settingsvars.php.

// --- Production Error Reporting ---
ini_set('display_errors', 0); // Set to 0 in production
ini_set('display_startup_errors', 0); // Set to 0 in production
error_reporting(E_ALL); // Still log all errors, but don't display them to the user

// --- HTTP Security Headers (Place this at the very top before any output) ---
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0"); // Ensure no caching
header("Pragma: no-cache"); // For older HTTP/1.0 clients

// Start session at the very beginning
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure BASE_PATH is defined (from config.php, which router.php includes)
if (!defined('BASE_PATH')) {
    // Fallback if BASE_PATH isn't defined, assuming this file is in settings/
    define('BASE_PATH', __DIR__ . '/..'); // Go up one directory from 'settings/' to 'public_html/'
}

// --- Check for config.php and include it ---
// Use BASE_PATH for includes to ensure consistency with router.php
require_once BASE_PATH . '/config/config.php';


// --- Check for PDO connection ---
if (!isset($pdo) || !$pdo instanceof PDO) {
    die('<div style="font-family: sans-serif; padding: 20px; background-color: #fdd; border: 1px solid #f99; color: #c00;">Error: Database connection ($pdo) not properly initialized in config.php.</div>');
}

// Set default timezone to Asia/Kolkata (IST) for consistent date/time handling
date_default_timezone_set('Asia/Kolkata');

// --- Define Logged-in State and User Role ---
$loggedIn = isset($_SESSION['user_id']);
$username = $loggedIn ? htmlspecialchars($_SESSION['username']) : null;
$userRole = $loggedIn ? ($_SESSION['user_role'] ?? 'Viewer') : null;

// Session Management & Inactivity Timeout
const INACTIVITY_TIMEOUT = 1800; // 30 minutes in seconds

if ($loggedIn && !isset($_SESSION['remember_me'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > INACTIVITY_TIMEOUT)) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-hourglass-end mr-2"></i> Session expired due to inactivity. Please log in again.</div>';
        header("Location: /login.php?timeout=1"); // Router-friendly redirect
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// --- INITIAL AUTHORIZATION CHECKS ---
if (!$loggedIn) {
    $_SESSION['message'] = '<div class="alert alert-info"><i class="fas fa-info-circle mr-2"></i> Please log in to access this page.</div>';
    header("Location: /login.php"); // Router-friendly redirect
    exit;
}

if ($userRole !== 'SuperAdmin' && $userRole !== 'Admin') {
    $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-lock mr-2"></i> You do not have the required permissions to access this page.</div>';
    header("Location: /dashboard.php"); // Router-friendly redirect
    exit;
}

$pageTitle = "Website Settings - Admin Panel";

// --- Helper Functions ---

/**
 * Function to get a single setting from the database.
 * @param PDO $pdo The PDO database connection object.
 * @param string $setting_name The name of the setting to fetch.
 * @return string|null The setting value if found, otherwise null.
 */
function getSetting(PDO $pdo, string $setting_name): ?string {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM website_settings WHERE setting_key = :setting_key");
        $stmt->execute([':setting_key' => $setting_name]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['setting_value'] : null;
    } catch (PDOException $e) {
        error_log("Database error fetching setting '{$setting_name}': " . $e->getMessage());
        return null;
    }
}

/**
 * Function to set (insert or update) a single setting in the database.
 * @param PDO $pdo The PDO database connection object.
 * @param string $setting_name The name of the setting to set.
 * @param string $setting_value The value of the setting.
 * @return bool True on success, false on failure.
 */
function setSetting(PDO $pdo, string $setting_name, string $setting_value): bool {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO website_settings (setting_key, setting_value, updated_at)
            VALUES (:setting_key, :setting_value, NOW())
            ON DUPLICATE KEY UPDATE setting_value = :new_setting_value_on_update, updated_at = NOW()
        ");
        $success = $stmt->execute([
            ':setting_key' => $setting_name,
            ':setting_value' => $setting_value,
            ':new_setting_value_on_update' => $setting_value
        ]);
        return $success;
    } catch (PDOException $e) {
        error_log("PDOException when setting '{$setting_name}': " . $e->getMessage());
        return false;
    }
}

// Function to display session messages
if (!function_exists('display_session_message')) {
    function display_session_message() {
        if (isset($_SESSION['message'])) {
            echo $_SESSION['message'];
            unset($_SESSION['message']);
        }
    }
}

/**
 * Updates the settingsvars.php file with the current website settings from the database.
 * @param PDO $pdo The PDO database connection object.
 * @param array $websiteSettingKeys A list of website setting keys that should be in settingsvars.php.
 * @param string $filePath The full path to the settingsvars.php file.
 * @return bool True on success, false on failure.
 */
function updateGlobalVariablesFile(PDO $pdo, array $websiteSettingKeys, string $filePath): bool {
    $websiteSettings = [];
    try {
        $placeholders = implode(',', array_fill(0, count($websiteSettingKeys), '?'));
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM website_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($websiteSettingKeys);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $websiteSettings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (PDOException $e) {
        error_log("Error fetching website settings for settingsvars.php update: " . $e->getMessage());
        return false;
    }

    $fileContent = "<?php\n// This file is dynamically generated by website-settings.php\n// Do not edit this file directly.\n\n";

    foreach ($websiteSettingKeys as $key) {
        $value = $websiteSettings[$key] ?? ''; // Use empty string if not found
        $fileContent .= "define('" . $key . "', '" . addslashes($value) . "');\n";
    }

    try {
        // Ensure directory exists
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true); // Create recursively with appropriate permissions
        }

        if (file_put_contents($filePath, $fileContent) !== false) {
            return true;
        } else {
            error_log("Failed to write to settingsvars.php. Check file permissions for: " . $filePath);
            return false;
        }
    } catch (Exception $e) {
        error_log("Exception writing to settingsvars.php: " . $e->getMessage());
        return false;
    }
}

// List of website setting keys to manage
$websiteSettingKeys = [
    'APP_TITLE',
    'APP_SITE_URL',
    'APP_ADMIN_EMAIL',
    'APP_EDITOR_NAME',
    'APP_MD_CHAIRMEN_NAME',
    'APP_EMAIL',
    'APP_PHONE',
    'APP_ADDRESS',
];

// Define the path to settingsvars.php (should be accessible by your application)
$settingsvarsFilePath = BASE_PATH . '/vars/settingsvars.php'; // Adjusted path to use BASE_PATH

// --- Load Existing Settings ---
$currentWebsiteSettings = [];
try {
    foreach ($websiteSettingKeys as $key) {
        $currentWebsiteSettings[$key] = getSetting($pdo, $key) ?? '';
    }

    // Always calculate and set APP_SITE_URL to the current domain being accessed
    // This makes it auto-fetched and dynamically updated based on the server's URL.
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $currentWebsiteSettings['APP_SITE_URL'] = $protocol . "://" . $host;

} catch (Exception $e) {
    error_log("Error loading website settings from DB: " . $e->getMessage());
    $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Error loading current website settings. Please check database configuration and table.</div>';
    foreach ($websiteSettingKeys as $key) {
        $currentWebsiteSettings[$key] = ''; // Ensure empty string fallback on error
    }
}

// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $success = true;
    try {
        $pdo->beginTransaction();

        foreach ($websiteSettingKeys as $key) {
            $postKey = strtolower(str_replace('APP_', 'app_', $key)); // e.g., app_title, app_site_url

            if ($key === 'APP_SITE_URL') {
                // For APP_SITE_URL, use the value that was *just computed* based on the current request,
                // as the input is disabled and its value should be dynamically set.
                $value = $currentWebsiteSettings[$key];
            } else {
                // For other fields, use the submitted POST value.
                $value = trim($_POST[$postKey] ?? '');
            }

            if (!setSetting($pdo, $key, $value)) {
                $success = false;
                error_log("Failed to save setting: " . $key);
            }
        }

        if ($success) {
            $pdo->commit();
            // Update settingsvars.php after successful DB commit
            if (!updateGlobalVariablesFile($pdo, $websiteSettingKeys, $settingsvarsFilePath)) {
                $_SESSION['message'] = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle mr-2"></i> Website settings saved to database, but failed to update variables file. Check file permissions for ' . htmlspecialchars($settingsvarsFilePath) . '.</div>';
            } else {
                $_SESSION['message'] = '<div class="alert alert-success"><i class="fas fa-check-circle mr-2"></i> Website settings updated successfully!</div>';
            }
        } else {
            $pdo->rollBack();
            $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Failed to update some website settings. Please check logs.</div>';
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error during website settings update: " . $e->getMessage());
        $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> An unexpected error occurred: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
    header("Location: /settings/website-settings.php"); // Router-friendly redirect
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; overflow-x: hidden; overflow-y: auto; }

        @media (min-width: 768px) {
            main {
                width: calc(100vw - 256px);
            }
        }

        /* General Alert Styles */
        .alert {
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
            font-size: 0.95rem;
            line-height: 1.4;
            font-weight: 500;
            max-width: 100%;
            margin-left: auto;
            margin-right: auto;
        }
        .alert i {
            margin-right: 0.5rem;
            margin-top: 0.125rem;
            font-size: 1.1rem;
        }
        .alert-success { background-color: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }
        .alert-error { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .alert-error i { color: #dc2626; }
        .alert-info { background-color: #e0f2fe; color: #0284c7; border: 1px solid #7dd3fc; }
        .alert-info i { color: #0369a1; }

        /* Form input consistency for text/url inputs */
        .form-input {
            width: 100%;
            padding: 0.625rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 1rem;
            transition: border-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            background-color: #fff;
            color: #374151;
        }
        .form-input:disabled {
            background-color: #f3f4f6;
            color: #6b7280;
            cursor: not-allowed;
        }
        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }
    </style>
</head>
<body>

<?php require_once BASE_PATH . '/layout/headersidebar.php'; // Adjust path if necessary ?>

<main class="p-[20px] md:py-6 md:px-4 md:ml-64 min-h-screen flex flex-col">
    <div class="max-w-7xl mx-auto py-0 w-full">
        <?php display_session_message(); ?>

        <div class="bg-white rounded-xl shadow-md overflow-hidden p-6">
            <div class="flex items-center gap-3 mb-6">
                <i class="fas fa-globe text-2xl text-blue-600"></i>
                <h1 class="text-2xl font-semibold text-gray-800">Website General Settings</h1>
            </div>

            <form action="/settings/website-settings.php" method="POST" class="space-y-6"> <!-- Router-friendly action -->

                <p class="text-gray-600 mb-4">Manage fundamental details of your website, including title, URLs, and contact information.</p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="app_title" class="block text-sm font-medium text-gray-700 mb-1">Application Title</label>
                        <input type="text" name="app_title" id="app_title"
                               class="form-input" value="<?= htmlspecialchars($currentWebsiteSettings['APP_TITLE'] ?? '') ?>"
                               placeholder="e.g., My Awesome Website">
                    </div>

                    <div>
                        <label for="app_site_url" class="block text-sm font-medium text-gray-700 mb-1">Website URL</label>
                        <input type="url" name="app_site_url" id="app_site_url"
                               class="form-input" value="<?= htmlspecialchars($currentWebsiteSettings['APP_SITE_URL'] ?? '') ?>"
                               placeholder="https://epaper.google.com" disabled>
                    </div>

                    <div>
                        <label for="app_admin_email" class="block text-sm font-medium text-gray-700 mb-1">Admin Email</label>
                        <input type="email" name="app_admin_email" id="app_admin_email"
                               class="form-input" value="<?= htmlspecialchars($currentWebsiteSettings['APP_ADMIN_EMAIL'] ?? '') ?>"
                               placeholder="e.g., admin@example.com">
                    </div>

                    <div>
                        <label for="app_editor_name" class="block text-sm font-medium text-gray-700 mb-1">Editor's Name</label>
                        <input type="text" name="app_editor_name" id="app_editor_name"
                               class="form-input" value="<?= htmlspecialchars($currentWebsiteSettings['APP_EDITOR_NAME'] ?? '') ?>"
                               placeholder="e.g., John Doe">
                    </div>

                    <div>
                        <label for="app_md_chairmen_name" class="block text-sm font-medium text-gray-700 mb-1">MD / Chairmen's Name</label>
                        <input type="text" name="app_md_chairmen_name" id="app_md_chairmen_name"
                               class="form-input" value="<?= htmlspecialchars($currentWebsiteSettings['APP_MD_CHAIRMEN_NAME'] ?? '') ?>"
                               placeholder="e.g., Jane Smith">
                    </div>

                    <div>
                        <label for="app_email" class="block text-sm font-medium text-gray-700 mb-1">Contact Email</label>
                        <input type="email" name="app_email" id="app_email"
                               class="form-input" value="<?= htmlspecialchars($currentWebsiteSettings['APP_EMAIL'] ?? '') ?>"
                               placeholder="e.g., info@example.com">
                    </div>

                    <div>
                        <label for="app_phone" class="block text-sm font-medium text-gray-700 mb-1">Contact Phone</label>
                        <input type="tel" name="app_phone" id="app_phone"
                               class="form-input" value="<?= htmlspecialchars($currentWebsiteSettings['APP_PHONE'] ?? '') ?>"
                               placeholder="e.g., +1234567890">
                    </div>

                    <div class="md:col-span-2">
                        <label for="app_address" class="block text-sm font-medium text-gray-700 mb-1">Company Address</label>
                        <textarea name="app_address" id="app_address" rows="3"
                                  class="form-input" placeholder="e.g., 123 Main St, Anytown, USA"><?= htmlspecialchars($currentWebsiteSettings['APP_ADDRESS'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="pt-4 border-t border-gray-200 mt-6 flex justify-end">
                    <button type="submit"
                            class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors duration-200">
                        <i class="fas fa-save mr-2"></i> Save Website Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
    $(document).ready(function() {
        // Auto-hide alert messages after 3 seconds
        setTimeout(function() {
            $('.alert').fadeOut('slow', function() {
                $(this).remove();
            });
        }, 3000); // 3000 milliseconds = 3 seconds
    });
</script>

</body>
</html>
