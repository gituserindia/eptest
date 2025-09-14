<?php
// File: logos-settings.php
// This script provides an interface for SuperAdmins and Admins to manage application logo and image settings.
// It handles displaying current settings, processing form submissions, uploading files, and updating logovars.php.

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

$pageTitle = "Logo & Image Settings - Admin Panel";

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
 * Updates the logovars.php file with the current image settings from the database.
 * @param PDO $pdo The PDO database connection object.
 * @param array $imageSettingKeys A list of image setting keys that should be in logovars.php.
 * @param string $filePath The full path to the logovars.php file.
 * @return bool True on success, false on failure.
 */
function updateGlobalVariablesFile(PDO $pdo, array $imageSettingKeys, string $filePath): bool {
    $imageSettings = [];
    try {
        $placeholders = implode(',', array_fill(0, count($imageSettingKeys), '?'));
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM website_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($imageSettingKeys);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $imageSettings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (PDOException $e) {
        error_log("Error fetching image settings for logovars.php update: " . $e->getMessage());
        return false;
    }

    $fileContent = "<?php\n// This file is dynamically generated by logos-settings.php\n// Do not edit this file directly.\n\n";

    foreach ($imageSettingKeys as $key) {
        $value = $imageSettings[$key] ?? ''; // Use empty string if not found
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
            error_log("Failed to write to logovars.php. Check file permissions for: " . $filePath);
            return false;
        }
    } catch (Exception $e) {
        error_log("Exception writing to logovars.php: " . $e->getMessage());
        return false;
    }
}


// --- Image Upload Configuration ---
const UPLOAD_DIR_RELATIVE = '/uploads/assets/'; // Relative path from public_html
// Use BASE_PATH to construct the full upload directory path
const UPLOAD_DIR_FULL = BASE_PATH . '/uploads/assets/'; // Absolute path to your upload directory

// Define accepted MIME types and max file size (in bytes)
const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
const MAX_FILE_SIZE = 2 * 1024 * 1024; // 2 MB

// List of image setting keys to manage
$imageSettingKeys = [
    'APP_LOGO_PATH',
    'APP_FAVICON_PATH',
    'OG_IMAGE_PATH',
    'APP_CROPPED_LOGO_PATH',
];

// Define the path to logovars.php (should be accessible by your application)
// Use BASE_PATH for consistency
$logovarsFilePath = BASE_PATH . '/vars/logovars.php';


// --- Load Existing Settings ---
$currentImageSettings = [];
try {
    foreach ($imageSettingKeys as $key) {
        $currentImageSettings[$key] = getSetting($pdo, $key) ?? '';
    }
} catch (Exception $e) {
    error_log("Error loading image settings from DB: " . $e->getMessage());
    $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Error loading current image settings. Please check database configuration and table.</div>';
    foreach ($imageSettingKeys as $key) {
        $currentImageSettings[$key] = ''; // Ensure empty string fallback on error
    }
}

// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $success = true;
    $errorMessage = '';

    try {
        $pdo->beginTransaction();

        foreach ($imageSettingKeys as $key) {
            $fileInputName = strtolower(str_replace('APP_', '', $key)); // e.g., 'logo_path'
            $oldPath = getSetting($pdo, $key); // Get current path BEFORE potential update

            if (isset($_FILES[$fileInputName]) && $_FILES[$fileInputName]['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES[$fileInputName];

                // Validate file type
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($file['tmp_name']);
                if (!in_array($mimeType, ALLOWED_IMAGE_TYPES)) {
                    $errorMessage .= "Invalid file type for {$key}: {$mimeType}. Only JPG, PNG, GIF, WEBP, SVG are allowed.<br>";
                    $success = false;
                    continue; // Skip this file and continue with others
                }

                // Validate file size
                if ($file['size'] > MAX_FILE_SIZE) {
                    $errorMessage .= "File size too large for {$key}. Max " . (MAX_FILE_SIZE / 1024 / 1024) . "MB allowed.<br>";
                    $success = false;
                    continue;
                }

                // Generate unique filename
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $newFileName = uniqid($key . '_', true) . '.' . $extension;
                $targetFilePath = UPLOAD_DIR_FULL . $newFileName;
                $newRelativePath = UPLOAD_DIR_RELATIVE . $newFileName;

                // Create upload directory if it doesn't exist
                if (!is_dir(UPLOAD_DIR_FULL)) {
                    mkdir(UPLOAD_DIR_FULL, 0755, true);
                }

                // Move uploaded file
                if (move_uploaded_file($file['tmp_name'], $targetFilePath)) {
                    // Update database with new path
                    if (setSetting($pdo, $key, $newRelativePath)) {
                        // Delete old file if it existed and was a valid uploaded file
                        // Use BASE_PATH to construct the full path for unlinking
                        $fullOldPath = BASE_PATH . $oldPath;
                        if (!empty($oldPath) && strpos($oldPath, UPLOAD_DIR_RELATIVE) === 0 && file_exists($fullOldPath)) {
                            if (unlink($fullOldPath)) {
                                error_log("Old file deleted: " . $fullOldPath); // Corrected concatenation
                            } else {
                                error_log("Failed to delete old file: " . $fullOldPath);
                            }
                        }
                        $currentImageSettings[$key] = $newRelativePath; // Update local array for display
                    } else {
                        $errorMessage .= "Failed to save {$key} path to database.<br>";
                        $success = false;
                    }
                } else {
                    $errorMessage .= "Failed to upload file for {$key}. Check directory permissions.<br>";
                    $success = false;
                }
            } else if (isset($_POST["clear_{$fileInputName}"]) && $_POST["clear_{$fileInputName}"] === '1') {
                // Handle clear/delete request for the image
                // Use BASE_PATH to construct the full path for unlinking
                $fullOldPath = BASE_PATH . $oldPath;
                if (!empty($oldPath) && strpos($oldPath, UPLOAD_DIR_RELATIVE) === 0 && file_exists($fullOldPath)) {
                    if (unlink($fullOldPath)) {
                        error_log("File cleared/deleted: " . $fullOldPath); // Corrected concatenation
                    } else {
                        error_log("Failed to delete file during clear operation: " . $fullOldPath);
                    }
                }
                // Set setting to empty in DB
                if (setSetting($pdo, $key, '')) {
                    $currentImageSettings[$key] = ''; // Update local array for display
                } else {
                    $errorMessage .= "Failed to clear {$key} from database.<br>";
                    $success = false;
                }
            }
        }

        if ($success) {
            $pdo->commit();
            // Update logovars.php after successful DB commit
            if (!updateGlobalVariablesFile($pdo, $imageSettingKeys, $logovarsFilePath)) {
                $_SESSION['message'] = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle mr-2"></i> Image settings saved to database, but failed to update variables file. Check file permissions for ' . htmlspecialchars($logovarsFilePath) . '.</div>';
            } else {
                $_SESSION['message'] = '<div class="alert alert-success"><i class="fas fa-check-circle mr-2"></i> Image settings updated successfully!</div>';
            }
        } else {
            $pdo->rollBack();
            $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Some images failed to update: ' . htmlspecialchars($errorMessage) . '</div>';
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error during image upload: " . $e->getMessage());
        $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> An unexpected error occurred: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
    header("Location: /settings/logos-settings.php"); // Router-friendly redirect
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
        body { font-family: 'Inter', sans-serif; overflow-x: hidden; overflow-y: auto; } /* Changed to overflow-y: auto; */

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

        /* Styles for file inputs and previews */
        .file-input-wrapper {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .file-input {
            display: block;
            width: 100%;
            padding: 0.625rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 1rem;
            background-color: #fff;
            color: #374151;
            cursor: pointer;
        }
        .image-preview {
            width: 80px; /* Small fixed width for preview */
            height: 80px; /* Small fixed height for preview */
            object-fit: contain; /* Ensure the image fits within the bounds */
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            background-color: #f9fafb;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0; /* Prevent shrinking in flex container */
        }
        .image-preview img {
            max-width: 100%;
            max-height: 100%;
        }
        .image-preview.empty {
            color: #9ca3af;
            font-size: 0.875rem;
            text-align: center;
            line-height: 1.2;
            padding: 0.5rem;
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
                <i class="fas fa-images text-2xl text-purple-600"></i>
                <h1 class="text-2xl font-semibold text-gray-800">Logo & Image Settings</h1>
            </div>

            <form action="/settings/logos-settings.php" method="POST" enctype="multipart/form-data" class="space-y-6">

                <p class="text-gray-600 mb-4">Upload and manage your application's logos and main images. Max file size: 2MB. Allowed types: JPG, PNG, GIF, WEBP, SVG.</p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="logo_path" class="block text-sm font-medium text-gray-700 mb-1">Application Logo</label>
                        <div class="file-input-wrapper">
                            <input type="file" name="logo_path" id="logo_path" accept="image/*" class="file-input">
                            <div id="appLogoPreview" class="image-preview <?= empty($currentImageSettings['APP_LOGO_PATH']) ? 'empty' : '' ?>">
                                <?php if (!empty($currentImageSettings['APP_LOGO_PATH'])): ?>
                                    <img src="<?= htmlspecialchars($currentImageSettings['APP_LOGO_PATH']) ?>" alt="Current App Logo">
                                <?php else: ?>
                                    No image
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($currentImageSettings['APP_LOGO_PATH'])): ?>
                                <button type="submit" name="clear_logo_path" value="1" class="text-red-500 hover:text-red-700 text-sm font-medium clear-button">Clear</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <label for="favicon_path" class="block text-sm font-medium text-gray-700 mb-1">Favicon</label>
                        <div class="file-input-wrapper">
                            <input type="file" name="favicon_path" id="favicon_path" accept="image/*" class="file-input">
                            <div id="faviconPreview" class="image-preview <?= empty($currentImageSettings['APP_FAVICON_PATH']) ? 'empty' : '' ?>">
                                <?php if (!empty($currentImageSettings['APP_FAVICON_PATH'])): ?>
                                    <img src="<?= htmlspecialchars($currentImageSettings['APP_FAVICON_PATH']) ?>" alt="Current Favicon">
                                <?php else: ?>
                                    No image
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($currentImageSettings['APP_FAVICON_PATH'])): ?>
                                <button type="submit" name="clear_favicon_path" value="1" class="text-red-500 hover:text-red-700 text-sm font-medium clear-button">Clear</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <label for="og_image_path" class="block text-sm font-medium text-gray-700 mb-1">Open Graph (OG) Image</label>
                        <div class="file-input-wrapper">
                            <input type="file" name="og_image_path" id="og_image_path" accept="image/*" class="file-input">
                            <div id="ogImagePreview" class="image-preview <?= empty($currentImageSettings['OG_IMAGE_PATH']) ? 'empty' : '' ?>">
                                <?php if (!empty($currentImageSettings['OG_IMAGE_PATH'])): ?>
                                    <img src="<?= htmlspecialchars($currentImageSettings['OG_IMAGE_PATH']) ?>" alt="Current OG Image">
                                <?php else: ?>
                                    No image
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($currentImageSettings['OG_IMAGE_PATH'])): ?>
                                <button type="submit" name="clear_og_image_path" value="1" class="text-red-500 hover:text-red-700 text-sm font-medium clear-button">Clear</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <label for="cropped_logo_path" class="block text-sm font-medium text-gray-700 mb-1">Cropped Logo (e.g., for specific sections)</label>
                        <div class="file-input-wrapper">
                            <input type="file" name="cropped_logo_path" id="cropped_logo_path" accept="image/*" class="file-input">
                            <div id="croppedLogoPreview" class="image-preview <?= empty($currentImageSettings['APP_CROPPED_LOGO_PATH']) ? 'empty' : '' ?>">
                                <?php if (!empty($currentImageSettings['APP_CROPPED_LOGO_PATH'])): ?>
                                    <img src="<?= htmlspecialchars($currentImageSettings['APP_CROPPED_LOGO_PATH']) ?>" alt="Current Cropped Logo">
                                <?php else: ?>
                                    No image
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($currentImageSettings['APP_CROPPED_LOGO_PATH'])): ?>
                                <button type="submit" name="clear_cropped_logo_path" value="1" class="text-red-500 hover:text-red-700 text-sm font-medium clear-button">Clear</button>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

                <div class="pt-4 border-t border-gray-200 mt-6 flex justify-end">
                    <button type="submit"
                            class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors duration-200">
                        <i class="fas fa-upload mr-2"></i> Save Image Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
    $(document).ready(function() {
        // jQuery for image preview
        function previewImage(event, previewId) {
            const $output = $('#' + previewId);
            const file = event.target.files[0];

            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    $output.empty(); // Clear existing content
                    $('<img>').attr('src', e.target.result).appendTo($output);
                    $output.removeClass('empty');
                };
                reader.readAsDataURL(file);
            } else {
                // If file is deselected, revert to "No image" or current image if available
                const currentPath = $output.data('current-path'); // Use .data() for data attributes
                if (currentPath) {
                    $output.empty();
                    $('<img>').attr('src', currentPath).attr('alt', 'Current Image').appendTo($output);
                    $output.removeClass('empty');
                } else {
                    $output.html('No image').addClass('empty');
                }
            }
        }

        // Attach previewImage to file input change events using jQuery
        $('#logo_path').on('change', function(event) { previewImage(event, 'appLogoPreview'); });
        $('#favicon_path').on('change', function(event) { previewImage(event, 'faviconPreview'); });
        $('#og_image_path').on('change', function(event) { previewImage(event, 'ogImagePreview'); });
        $('#cropped_logo_path').on('change', function(event) { previewImage(event, 'croppedLogoPreview'); });


        // Set data-current-path attributes on page load for preview fallback
        $('#appLogoPreview').attr('data-current-path', <?= json_encode($currentImageSettings['APP_LOGO_PATH']) ?>);
        $('#faviconPreview').attr('data-current-path', <?= json_encode($currentImageSettings['APP_FAVICON_PATH']) ?>);
        $('#ogImagePreview').attr('data-current-path', <?= json_encode($currentImageSettings['OG_IMAGE_PATH']) ?>);
        $('#croppedLogoPreview').attr('data-current-path', <?= json_encode($currentImageSettings['APP_CROPPED_LOGO_PATH']) ?>);

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
