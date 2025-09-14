<?php
// File: layout/footer.php
// This file contains the closing HTML tags for your application.
// It is included by individual page files after their main content.

// --- Include centralized settings and theme variables ---
// Use BASE_PATH for all includes, as router.php defines it.
$settingsVarsPath = BASE_PATH . '/vars/settingsvars.php';
$themeVarsPath = BASE_PATH . '/vars/themevars.php';

// Include settingsvars.php if it exists for APP_TITLE
if (file_exists($settingsVarsPath)) {
    require_once $settingsVarsPath;
} else {
    if (!defined('APP_TITLE')) {
        define('APP_TITLE', 'Default E-Paper');
    }
}

// Include themevars.php if it exists for themeColors
if (file_exists($themeVarsPath)) {
    require_once $themeVarsPath;
} else {
    // Define default theme colors if themevars.php is missing
    $themeColors = [
        'main_color' => '#3B82F6',
        'main_text_color' => '#FFFFFF',
        'hover_color' => '#2563EB',
        'hover_text_color' => '#FFFFFF',
        'light_gray_border' => '#E5E7EB',
        'bg_gray_100' => '#F3F4F6',
        'bg_black_opacity_50' => 'rgba(0, 0, 0, 0.5)',
        'green_100' => '#D1FAE5',
        'green_600' => '#059669',
        'red_100' => '#FEE2E2',
        'red_600' => '#DC2626',
        'gray_600' => '#4B5563'
    ];
}

// Ensure $themeColors and its keys exist, provide fallbacks if not
$footerBgColor = isset($themeColors['main_color']) ? htmlspecialchars($themeColors['main_color']) : '#ffffff'; // Default to white
$footerTextColor = isset($themeColors['main_text_color']) ? htmlspecialchars($themeColors['main_text_color']) : '#6b7280'; // Default to gray_600
?>
<footer class="shadow-lg py-4 fixed bottom-0 w-full z-40" style="background-color: <?php echo $footerBgColor; ?>;">
    <div class="container mx-auto px-4 text-center text-xs" style="color: <?php echo $footerTextColor; ?>;">
        &copy; <?php echo date("Y"); ?> <?php echo defined('APP_TITLE') ? APP_TITLE : 'Your E-Paper Name'; ?>. All rights reserved.
    </div>
</footer>

</body>
</html>
