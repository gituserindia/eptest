<?php
// File: social-media-settings.php
// This script provides an interface for SuperAdmins and Admins to manage only social media links.
// It handles displaying current settings, processing form submissions, and updating socialmediavars.php.

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
    // Fallback if BASE_PATH isn't defined.
    // Corrected: Go up one directory from 'settings/' to 'public_html/'
    define('BASE_PATH', __DIR__ . '/..');
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

// Session Management & Inactivity Timeout (consistent with other admin pages)
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

$pageTitle = "Social Media Settings - Admin Panel";

// --- Helper Functions ---

// Function to send JSON response and exit (consistent with other AJAX scripts)
function sendJsonResponse($success, $message, $redirectUrl = null) {
    echo json_encode(['success' => $success, 'message' => $message, 'redirect' => $redirectUrl]);
    exit;
}

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
        error_log("Attempting to set setting: {$setting_name} = '{$setting_value}'");
        $stmt = $pdo->prepare("
            INSERT INTO website_settings (setting_key, setting_value, updated_at)
            VALUES (:setting_key, :setting_value, NOW())
            ON DUPLICATE KEY UPDATE setting_value = :new_setting_value_on_update, updated_at = NOW()
        ");
        $success = $stmt->execute([
            ':setting_key' => $setting_name,
            ':setting_value' => $setting_value,
            ':new_setting_value_on_update' => $setting_value // Bind the value again for the UPDATE part
        ]);
        if ($success) {
            error_log("Setting '{$setting_name}' updated successfully.");
        } else {
            error_log("Failed to update setting '{$setting_name}'. PDO ErrorInfo: " . print_r($stmt->errorInfo(), true));
        }
        return $success;
    } catch (PDOException $e) {
        error_log("PDOException when setting '{$setting_name}': " . $e->getMessage());
        return false;
    }
}

// Function to display session messages (moved here for explicit definition)
if (!function_exists('display_session_message')) {
    function display_session_message() {
        if (isset($_SESSION['message'])) {
            echo $_SESSION['message'];
            unset($_SESSION['message']);
        }
    }
}

/**
 * Updates the socialmediavars.php file with the current social media settings from the database.
 * This function should be called after a successful database update.
 * It now fetches ONLY relevant social media settings for this specific file.
 * @param PDO $pdo The PDO database connection object.
 * @param array $socialMediaSettingsList A list of social media setting keys that should be in socialmediavars.php.
 * @param string $filePath The full path to the socialmediavars.php file.
 * @return bool True on success, false on failure.
 */
function updateGlobalVariablesFile(PDO $pdo, array $socialMediaSettingsList, string $filePath): bool {
    error_log("Attempting to update socialmediavars.php file: " . $filePath);
    $socialMediaSettings = [];
    try {
        // Fetch only social media settings
        $placeholders = implode(',', array_fill(0, count($socialMediaSettingsList), '?'));
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM website_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($socialMediaSettingsList);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $socialMediaSettings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (PDOException $e) {
        error_log("Error fetching social media settings for socialmediavars.php update: " . $e->getMessage());
        return false;
    }

    $fileContent = "<?php\n// This file is dynamically generated by social-media-settings.php\n// Do not edit this file directly.\n\n";

    // Define a mapping from database setting_key to PHP constant name
    // This list should now ONLY contain social media constants
    $constantMapping = [
        'APP_FACEBOOK_URL' => 'APP_FACEBOOK_URL',
        'APP_TWITTER_URL' => 'APP_TWITTER_URL',
        'APP_INSTAGRAM_URL' => 'APP_INSTAGRAM_URL',
        'APP_WHATSAPP_URL' => 'APP_WHATSAPP_URL',
        'APP_YOUTUBE_URL' => 'APP_YOUTUBE_URL',
    ];

    foreach ($constantMapping as $dbKey => $constantName) {
        // Use a default empty string if the setting is not found, to prevent undefined errors
        $value = $socialMediaSettings[$dbKey] ?? '';
        $fileContent .= "define('" . $constantName . "', '" . addslashes($value) . "');\n";
    }

    try {
        if (file_put_contents($filePath, $fileContent) !== false) {
            error_log("socialmediavars.php updated successfully.");
            return true;
        } else {
            error_log("Failed to write to socialmediavars.php. Check file permissions for: " . $filePath);
            return false;
        }
    } catch (Exception $e) {
        error_log("Exception writing to socialmediavars.php: " . $e->getMessage());
        return false;
    }
}


// --- Load Existing Settings (only social media for this page, but all for themevars.php update) ---
$socialMediaSettings = [];
$socialMediaSettingsList = [
    'APP_FACEBOOK_URL',
    'APP_TWITTER_URL',
    'APP_INSTAGRAM_URL',
    'APP_WHATSAPP_URL',
    'APP_YOUTUBE_URL',
];

// Define the exact list of settings that should go into socialmediavars.php
// This is now explicitly the social media settings.
$settingsToUpdateSocialMediaVars = [
    'APP_FACEBOOK_URL',
    'APP_TWITTER_URL',
    'APP_INSTAGRAM_URL',
    'APP_WHATSAPP_URL',
    'APP_YOUTUBE_URL',
];

// Define the path to socialmediavars.php
$socialMediaVarsFilePath = BASE_PATH . '/vars/socialmediavars.php';


// Wrap loading settings in try-catch to avoid blank page on DB error
try {
    foreach ($socialMediaSettingsList as $settingName) {
        // Fetch the setting. If getSetting returns null, default to an empty string for display.
        $socialMediaSettings[$settingName] = getSetting($pdo, $settingName) ?? '';
    }
} catch (Exception $e) {
    error_log("Error loading social media settings from DB: " . $e->getMessage());
    $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Error loading current social media settings. Please check database configuration and table.</div>';
    // Provide fallback empty string values so the page can still render
    foreach ($socialMediaSettingsList as $settingName) {
        $socialMediaSettings[$settingName] = ''; // Ensure empty string fallback
    }
}

// Ensure default values if settings are not found (or if loading failed)
// These are for initial display if DB is empty or value is an empty string,
// providing a helpful placeholder.
$socialMediaSettings['APP_FACEBOOK_URL'] = $socialMediaSettings['APP_FACEBOOK_URL'] ?: 'https://facebook.com';
$socialMediaSettings['APP_TWITTER_URL'] = $socialMediaSettings['APP_TWITTER_URL'] ?: 'https://x.com';
$socialMediaSettings['APP_INSTAGRAM_URL'] = $socialMediaSettings['APP_INSTAGRAM_URL'] ?: 'https://instagram.com';
$socialMediaSettings['APP_WHATSAPP_URL'] = $socialMediaSettings['APP_WHATSAPP_URL'] ?: 'https://wa.me/';
$socialMediaSettings['APP_YOUTUBE_URL'] = $socialMediaSettings['APP_YOUTUBE_URL'] ?: 'https://youtube.com';


// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("--- Social Media Form Submission Started ---");
    try {
        error_log("Starting PDO transaction.");
        $pdo->beginTransaction(); // Start transaction

        // Handle social media text inputs
        setSetting($pdo, 'APP_FACEBOOK_URL', trim($_POST['app_facebook_url'] ?? ''));
        setSetting($pdo, 'APP_TWITTER_URL', trim($_POST['app_twitter_url'] ?? ''));
        setSetting($pdo, 'APP_INSTAGRAM_URL', trim($_POST['app_instagram_url'] ?? ''));
        setSetting($pdo, 'APP_WHATSAPP_URL', trim($_POST['app_whatsapp_url'] ?? ''));
        setSetting($pdo, 'APP_YOUTUBE_URL', trim($_POST['app_youtube_url'] ?? ''));

        error_log("Attempting to commit PDO transaction.");
        $pdo->commit(); // Commit the transaction
        error_log("PDO transaction committed successfully.");

        // --- Update socialmediavars.php file after successful DB commit ---
        // Pass only the social media settings to ensure socialmediavars.php is specific
        if (!updateGlobalVariablesFile($pdo, $settingsToUpdateSocialMediaVars, $socialMediaVarsFilePath)) {
            error_log("Warning: Failed to update socialmediavars.php after successful social media settings save.");
            $_SESSION['message'] = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle mr-2"></i> Social media settings saved to database, but failed to update social media variables file. Check file permissions for ' . htmlspecialchars($socialMediaVarsFilePath) . '.</div>';
        } else {
             $_SESSION['message'] = '<div class="alert alert-success"><i class="fas fa-check-circle mr-2"></i> Social media settings updated successfully!</div>';
        }

        // Redirect to the specified path (this page itself)
        header("Location: /settings/social-media-settings.php"); // Router-friendly redirect
        exit;

    } catch (Exception $e) {
        error_log("Caught Exception during social media form submission. Rolling back transaction.");
        if ($pdo->inTransaction()) {
            $pdo->rollBack(); // Rollback on error
            error_log("PDO transaction rolled back.");
        }
        error_log("Error updating social media settings: " . $e->getMessage());
        $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Error updating social media settings: ' . htmlspecialchars($e->getMessage()) . '</div>';
        // Redirect to the specified path even on error to show the message
        header("Location: /settings/social-media-settings.php"); // Router-friendly redirect
        exit;
    } finally {
        error_log("--- Social Media Form Submission Finished ---");
    }
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
        body {
            font-family: 'Inter', sans-serif;
            overflow-x: hidden;
            overflow-y: auto; /* Changed to overflow-y: auto; */
        }

        /* Ensure main content does not overflow horizontally when sidebar is active */
        @media (min-width: 768px) { /* Equivalent to Tailwind's md breakpoint */
            main {
                width: calc(100vw - 256px); /* 256px is Tailwind's ml-64 */
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
        .alert-success {
            background-color: #dcfce7;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }
        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
        }
        .alert-error i {
            color: #dc2626;
        }
        .alert-info {
            background-color: #e0f2fe;
            color: #0284c7;
            border: 1px solid #7dd3fc;
        }
        .alert-info i {
            color: #0369a1;
        }

        /* Form input consistency */
        .form-input {
            width: 100%;
            padding: 0.625rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 1rem;
            transition: border-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            background-color: #fff; /* Ensure white background */
            color: #374151; /* Darker text for readability */
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

<?php require_once BASE_PATH . '/layout/headersidebar.php'; ?>

<main class="p-[20px] md:py-6 md:px-4 md:ml-64 min-h-screen flex flex-col">
    <div class="max-w-7xl mx-auto py-0 w-full">
        <?php display_session_message(); ?>

        <div class="bg-white rounded-xl shadow-md overflow-hidden p-6">
            <div class="flex items-center gap-3 mb-6">
                <i class="fas fa-share-alt text-2xl text-blue-600"></i>
                <h1 class="text-2xl font-semibold text-gray-800">Social Media Links</h1>
            </div>

            <form action="/settings/social-media-settings.php" method="POST" class="space-y-6">

                <div class="space-y-6">
                    <p class="text-gray-600">Update the URLs for your website's social media profiles.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="app_facebook_url" class="block text-sm font-medium text-gray-700 mb-1">Facebook URL</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fab fa-facebook-f text-blue-600"></i>
                                </span>
                                <input type="url" name="app_facebook_url" id="app_facebook_url"
                                       class="form-input pl-10" placeholder="https://facebook.com/yourpage">
                            </div>
                        </div>
                        <div>
                            <label for="app_twitter_url" class="block text-sm font-medium text-gray-700 mb-1">Twitter URL</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fab fa-twitter text-blue-400"></i>
                                </span>
                                <input type="url" name="app_twitter_url" id="app_twitter_url"
                                       class="form-input pl-10" placeholder="https://x.com/yourhandle">
                            </div>
                        </div>
                        <div>
                            <label for="app_instagram_url" class="block text-sm font-medium text-gray-700 mb-1">Instagram URL</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fab fa-instagram text-pink-600"></i>
                                </span>
                                <input type="url" name="app_instagram_url" id="app_instagram_url"
                                       class="form-input pl-10" placeholder="https://instagram.com/yourprofile">
                            </div>
                        </div>
                        <div>
                            <label for="app_whatsapp_url" class="block text-sm font-medium text-gray-700 mb-1">WhatsApp URL</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fab fa-whatsapp text-green-500"></i>
                                </span>
                                <input type="url" name="app_whatsapp_url" id="app_whatsapp_url"
                                       class="form-input pl-10" placeholder="https://wa.me/yourphonenumber">
                            </div>
                        </div>
                        <div class="md:col-span-2">
                            <label for="app_youtube_url" class="block text-sm font-medium text-gray-700 mb-1">YouTube URL</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fab fa-youtube text-red-600"></i>
                                </span>
                                <input type="url" name="app_youtube_url" id="app_youtube_url"
                                       class="form-input pl-10" placeholder="https://youtube.com/yourchannel">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pt-4 border-t border-gray-200 mt-6 flex justify-end">
                    <button type="submit"
                            class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors duration-200">
                        <i class="fas fa-save mr-2"></i> Save Social Media Links
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
    // Define the PHP fetched data as a JavaScript object
    var socialMediaData = {
        facebook: <?= json_encode($socialMediaSettings['APP_FACEBOOK_URL']) ?>,
        twitter: <?= json_encode($socialMediaSettings['APP_TWITTER_URL']) ?>,
        instagram: <?= json_encode($socialMediaSettings['APP_INSTAGRAM_URL']) ?>,
        whatsapp: <?= json_encode($socialMediaSettings['APP_WHATSAPP_URL']) ?>,
        youtube: <?= json_encode($socialMediaSettings['APP_YOUTUBE_URL']) ?>
    };

    // Use jQuery to set the input values once the document is ready
    $(document).ready(function() {
        $('#app_facebook_url').val(socialMediaData.facebook);
        $('#app_twitter_url').val(socialMediaData.twitter);
        $('#app_instagram_url').val(socialMediaData.instagram);
        $('#app_whatsapp_url').val(socialMediaData.whatsapp);
        $('#app_youtube_url').val(socialMediaData.youtube);

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
