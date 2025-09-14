<?php
// theme.php
// This script provides an interface for managing website theme settings.
// It handles displaying current settings, processing form submissions, and updating themevars.php directly.

// --- Temporary Debugging: Enable error display ---
ini_set('display_errors', 1); // Set to 1 for debugging, set back to 0 in production
ini_set('display_startup_errors', 1); // Set to 1 for production
error_reporting(E_ALL);

// --- HTTP Security Headers (Place this at the very top before any output) ---
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Start session at the very beginning
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure BASE_PATH is defined (from config.php, which router.php includes)
if (!defined('BASE_PATH')) {
    // Fallback if BASE_PATH isn't defined, assuming this file is in settings/
    // Corrected: Go up one directory from 'settings/' to 'public_html/'
    define('BASE_PATH', __DIR__ . '/..');
}

// Path to the themevars.php file
$themeVarsFilePath = BASE_PATH . '/vars/themevars.php';

// Clear PHP's file stat cache before including themevars.php
// This helps ensure PHP checks the file's modification time on disk,
// potentially bypassing some OPcache issues where the file's metadata is cached.
clearstatcache(true, $themeVarsFilePath);

// Include themevars.php to get default/current theme settings
require_once $themeVarsFilePath;

// Function to save theme colors to themevars.php
// This function now accepts the existing themeColors array to merge with new dynamic colors
function saveThemeColors($newDynamicColors, $filePath, $existingThemeColors) {
    // Merge new dynamic colors with existing static/other colors
    $updatedColors = array_merge($existingThemeColors, $newDynamicColors);

    $content = "<?php\n";
    $content .= "// file: vars/themevars.php\n";
    $content .= "// This file centralizes core theme color definitions.\n\n";
    $content .= "// Define theme colors\n";
    $content .= "// These values are updated from theme.php\n";
    $content .= "\$themeColors = [\n";
    foreach ($updatedColors as $key => $value) {
        $content .= "    '{$key}' => '{$value}',\n";
    }
    $content .= "];\n";

    $result = file_put_contents($filePath, $content);

    // Attempt to invalidate OPcache for the file if OPcache is enabled and the function exists
    // The 'true' argument ensures the file's bytecode cache is cleared, not just its stat cache.
    if ($result !== false && function_exists('opcache_invalidate')) {
        opcache_invalidate($filePath, true);
    }

    return $result;
}

$message = '';
$messageType = ''; // 'success' or 'error'

// Check for messages in session (set by a previous redirect or form submission)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['messageType'];
    // Clear the session variables immediately after retrieving them
    unset($_SESSION['message']);
    unset($_SESSION['messageType']);
}


// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newColors = [];
    $newColors['main_color'] = $_POST['main_color'] ?? $themeColors['main_color'];
    $newColors['main_text_color'] = $_POST['main_text_color'] ?? $themeColors['main_text_color'];
    $newColors['hover_color'] = $_POST['hover_color'] ?? $themeColors['hover_color'];
    $newColors['hover_text_color'] = $_POST['hover_text_color'] ?? $themeColors['hover_text_color'];

    // Validate colors (simple hex validation)
    foreach ($newColors as $key => $color) {
        if (!preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color)) {
            $_SESSION['message'] = 'Error: Invalid color format for ' . str_replace('_', ' ', $key) . '. Please use a valid hex code (e.g., #RRGGBB).';
            $_SESSION['messageType'] = 'error';
            header('Location: ' . $_SERVER['PHP_SELF']); // Redirect to clear POST data
            exit;
        }
    }

    if (empty($message)) { // Only proceed if no validation errors
        // Save the new colors directly to themevars.php, merging with existing colors
        if (saveThemeColors($newColors, $themeVarsFilePath, $themeColors)) {
            $_SESSION['message'] = 'Theme settings updated successfully!';
            $_SESSION['messageType'] = 'success';
            header('Location: ' . $_SERVER['PHP_SELF']); // Redirect to clear POST data and show message
            exit;
        } else {
            $_SESSION['message'] = 'Error: Could not save theme settings. Check file permissions for ' . htmlspecialchars($themeVarsFilePath) . '.';
            $_SESSION['messageType'] = 'error';
            header('Location: ' . $_SERVER['PHP_SELF']); // Redirect to clear POST data
            exit;
        }
    }
}

// Set initial form values from $themeColors (after potential update)
$currentMainColor = $themeColors['main_color'];
$currentMainTextColor = $themeColors['main_text_color'];
$currentHoverColor = $themeColors['hover_color'];
$currentHoverTextColor = $themeColors['hover_text_color'];

// Include the header and sidebar layout
$pageTitle = "Theme Settings";
ob_start(); // Start output buffering
?>

<div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Manage Theme Colors</h1>

    <?php if ($message): ?>
        <div id="alert-message" class="alert alert-<?php echo $messageType; ?> mb-4">
            <?php if ($messageType === 'success'): ?>
                <i class="fas fa-check-circle mr-2"></i>
            <?php else: ?>
                <i class="fas fa-exclamation-triangle mr-2"></i>
            <?php endif; ?>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="bg-white p-6 rounded-lg shadow-md">
        <form action="/settings/theme.php" method="POST"> <!-- Updated action to router-friendly path -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div>
                    <label for="main_color" class="block text-sm font-medium text-gray-700 mb-1">Main Color</label>
                    <input type="color" id="main_color" name="main_color" value="<?php echo htmlspecialchars($currentMainColor); ?>"
                           class="w-full h-10 rounded-md border border-gray-300 focus:ring-blue-500 focus:border-blue-500">
                    <input type="text" id="main_color_hex" value="<?php echo htmlspecialchars($currentMainColor); ?>"
                           class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                </div>
                <div>
                    <label for="main_text_color" class="block text-sm font-medium text-gray-700 mb-1">Main Text Color</label>
                    <input type="color" id="main_text_color" name="main_text_color" value="<?php echo htmlspecialchars($currentMainTextColor); ?>"
                           class="w-full h-10 rounded-md border border-gray-300 focus:ring-blue-500 focus:border-blue-500">
                    <input type="text" id="main_text_color_hex" value="<?php echo htmlspecialchars($currentMainTextColor); ?>"
                           class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                </div>
                <div>
                    <label for="hover_color" class="block text-sm font-medium text-gray-700 mb-1">Hover Color</label>
                    <input type="color" id="hover_color" name="hover_color" value="<?php echo htmlspecialchars($currentHoverColor); ?>"
                           class="w-full h-10 rounded-md border border-gray-300 focus:ring-blue-500 focus:border-blue-500">
                    <input type="text" id="hover_color_hex" value="<?php echo htmlspecialchars($currentHoverColor); ?>"
                           class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                </div>
                <div>
                    <label for="hover_text_color" class="block text-sm font-medium text-gray-700 mb-1">Hover Text Color</label>
                    <input type="color" id="hover_text_color" name="hover_text_color" value="<?php echo htmlspecialchars($currentHoverTextColor); ?>"
                           class="w-full h-10 rounded-md border border-gray-300 focus:ring-blue-500 focus:border-blue-500">
                    <input type="text" id="hover_text_color_hex" value="<?php echo htmlspecialchars($currentHoverTextColor); ?>"
                           class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200">
                    <i class="fas fa-save mr-2"></i> Save Theme Settings
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    $(document).ready(function() {
        // Synchronize color picker and text input using jQuery
        $('#main_color').on('input', function() {
            $('#main_color_hex').val(this.value);
        });
        $('#main_text_color').on('input', function() {
            $('#main_text_color_hex').val(this.value);
        });
        $('#hover_color').on('input', function() {
            $('#hover_color_hex').val(this.value);
        });
        $('#hover_text_color').on('input', function() {
            $('#hover_text_color_hex').val(this.value);
        });

        // Synchronize text input to color picker (reverse direction)
        $('#main_color_hex').on('input', function() {
            $('#main_color').val(this.value);
        });
        $('#main_text_color_hex').on('input', function() {
            $('#main_text_color').val(this.value);
        });
        $('#hover_color_hex').on('input', function() {
            $('#hover_color').val(this.value);
        });
        $('#hover_text_color_hex').on('input', function() {
            $('#hover_text_color').val(this.value);
        });


        // Auto-hide alert message on success using jQuery
        <?php if ($messageType === 'success'): ?>
            const $alertMessage = $('#alert-message');
            if ($alertMessage.length) {
                // Add a CSS transition for opacity (Tailwind's transition class might be better)
                $alertMessage.css('transition', 'opacity 0.5s ease-out');

                setTimeout(() => {
                    $alertMessage.css('opacity', '0'); // Start fading out
                }, 3500); // Start fading out after 3.5 seconds

                setTimeout(() => {
                    $alertMessage.hide(); // Hide completely after fade
                }, 4000); // Hide after 4 seconds (3.5s delay + 0.5s transition)
            }
        <?php endif; ?>
    });
</script>

<?php
$pageContent = ob_get_clean(); // Get buffered content and assign to $pageContent
// Corrected path: Use BASE_PATH for consistency with router setup
include BASE_PATH . '/layout/headersidebar.php'; // Include the main layout
?>
