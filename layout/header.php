<?php
// File: layout/header.php
// This is your main layout template for public-facing pages.
// It handles HTML structure, CSS/JS includes, and the main header.
// It expects $pageTitle, $loggedIn, and $username
// to be set by the including page (which is now handled by the router).

// --- Include centralized settings and theme variables ---
// Use BASE_PATH for all includes, as router.php defines it.
$settingsVarsPath = BASE_PATH . '/vars/settingsvars.php';
$themeVarsPath = BASE_PATH . '/vars/themevars.php';
$logoVarsPath = BASE_PATH . '/vars/logovars.php';
$socialMediaVarsPath = BASE_PATH . '/vars/socialmediavars.php'; // Added social media vars path

// Include settingsvars.php if it exists
if (file_exists($settingsVarsPath)) {
    require_once $settingsVarsPath;
} else {
    if (!defined('APP_TITLE')) {
        define('APP_TITLE', 'Default E-Paper');
    }
}

// Include themevars.php if it exists
if (file_exists($themeVarsPath)) {
    require_once $themeVarsPath;
} else {
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

// Include logovars.php if it exists
if (file_exists($logoVarsPath)) {
    require_once $logoVarsPath;
} else {
    if (!defined('APP_LOGO_PATH')) {
        define('APP_LOGO_PATH', '/uploads/assets/logo.jpg');
    }
    if (!defined('APP_FAVICON_PATH')) {
        define('APP_FAVICON_PATH', '/uploads/assets/favicon.ico');
    }
}

// Include socialmediavars.php if it exists (for social media links in footer/header if needed)
if (file_exists($socialMediaVarsPath)) {
    require_once $socialMediaVarsPath;
}


// Default values for variables expected by the header (set by the page included by the router)
if (!isset($pageTitle)) $pageTitle = defined('APP_TITLE') ? APP_TITLE : 'Default E-Paper';
if (!isset($pageContent)) $pageContent = ""; // Re-added $pageContent for pages that buffer their output
if (!isset($loggedIn)) $loggedIn = false;
if (!isset($username)) $username = 'Guest';
$currentUserId = $loggedIn ? ($_SESSION['user_id'] ?? null) : null;

$logoPath = defined('APP_LOGO_PATH') ? APP_LOGO_PATH : '/uploads/assets/logo.jpg';
if (!empty($logoPath) && $logoPath[0] !== '/') {
    $logoPath = '/' . $logoPath;
}

$faviconPath = defined('APP_FAVICON_PATH') ? APP_FAVICON_PATH : '/uploads/assets/favicon.ico';
if (!empty($faviconPath) && $faviconPath[0] !== '/') {
    $faviconPath = '/' . $faviconPath;
}

$cssVars = [];
if (isset($themeColors) && is_array($themeColors)) {
    foreach ($themeColors as $name => $value) {
        $cssVars[] = "--color-" . str_replace('_', '-', $name) . ": " . $value;
    }
}
$cssVars[] = '--border-radius: 20px';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="theme-color" content="<?php echo $themeColors['main_color'] ?? '#3B82F6'; ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <?php if (!empty($faviconPath)): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($faviconPath); ?>" type="image/x-icon">
    <?php endif; ?>

    <style>
        /* Ensure HTML and Body take full viewport height and have no default margins/padding */
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            box-sizing: border-box; /* Include padding and border in the element's total width and height */
        }

        /* Make the body a flex container to stack header, main, and footer vertically */
        body {
            display: flex;
            flex-direction: column;
            justify-content: flex-start; /* Align content to the top of the flex container */
            font-family: 'Inter', sans-serif;
            background-color: var(--color-bg-gray-100);
            /* REMOVED: color: var(--color-main-text-color); from here to allow default dark text on light backgrounds */
        }

        /* Allow the main content area (wrapped by <main> in individual pages) to grow and fill available vertical space */
        main {
            flex-grow: 1; /* This is the key property */
            /* Removed display: flex, flex-direction: column, justify-content: center, align-items: center */
            /* These properties should be applied to content *within* main if needed for centering */
        }

        /* Custom CSS variables for theme colors and border-radius, easily integrated with Tailwind */
        :root {
            <?php foreach ($cssVars as $var): ?>
                <?php echo $var; ?>;
            <?php endforeach; ?>
        }

        /* Styling for the tool actions group (e.g., Date, Zoom, Share buttons) */
        .tool-actions {
            border: 1px solid var(--color-light-gray-border);
            border-radius: var(--border-radius); /* Using CSS variable */
            padding: 5px 5px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }
        .tool-actions button:hover {
            background-color: var(--color-hover-color); /* Using CSS variable */
            color: var(--color-hover-text-color); /* Assuming white text on hover for these buttons */
            border-radius: var(--border-radius); /* Using CSS variable */
            transform: translateY(-2px); /* Subtle lift effect */
        }
        .tool-actions i {
            font-size: 0.75rem;
        }
        /* Base styles for menu buttons (Home, About Us, Contact Us) */
        .menu-button {
            font-size: 0.85rem;
        }
        .menu-button i {
            font-size: 0.85rem;
        }
        /* Consistent hover styles applying across various interactive elements */
        .profile-dropdown a:hover,
        nav a:hover,
        .more-menu a:hover,
        .mobile-sidebar a:hover,
        .menu-button:hover {
            background-color: var(--color-hover-color) !important; /* Using CSS variable */
            color: var(--color-hover-text-color) !important; /* Assuming white text on hover for these links */
            border-radius: var(--border-radius); /* Using CSS variable */
            transform: translateY(-1px); /* Subtle lift on hover */
            transition: background-color 0.3s, color 0.3s, border-radius 0.3s, transform 0.2s ease-out; /* Add transform to transition */
        }
        /* Edition title badge styling */
        .edition-title {
            background-color: var(--color-main-color); /* Using CSS variable */
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        /* Styles for alerts on login page, etc. */
        .alert {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-top: 1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
        }
        .alert-success {
            background-color: var(--color-green-100); /* Using CSS variable */
            color: var(--color-green-600); /* Using CSS variable */
        }
        .alert-error {
            background-color: var(--color-red-100); /* Using CSS variable */
            color: var(--color-red-600); /* Using CSS variable */
        }
        .alert i {
            margin-right: 0.75rem;
        }

        /* New styles for sidebar menu items, consistent with previous desktop menu design */
        .sidebar-item {
            display: flex;
            align-items: center;
            gap: 1rem; /* Space between icon and text */
            padding: 0.75rem 1rem; /* Vertical and horizontal padding */
            border-radius: var(--border-radius); /* Rounded corners from global variable */
            color: var(--color-main-text-color); /* Default text color */
            transition: background-color 0.2s, color 0.2s;
            text-decoration: none; /* Remove underline from links */
        }

        .sidebar-item:hover {
            background-color: var(--color-hover-color); /* Hover background */
            color: var(--color-hover-text-color); /* Hover text color */
        }

        .sidebar-item i {
            width: 1.25rem; /* Fixed width for icons for alignment */
            text-align: center;
        }
        /* Style for icons within sidebar items on hover */
        .sidebar-item:hover i {
            color: var(--color-hover-text-color) !important; /* Ensure icon color changes on hover */
        }


        .sidebar-submenu {
            margin-left: 2.5rem; /* Indent sub-menu items */
            padding-top: 0.5rem;
            padding-bottom: 0.5rem;
        }

        .sidebar-submenu a {
            display: block;
            padding: 0.5rem 1rem;
            color: var(--color-main-text-color);
            transition: background-color 0.2s, color 0.2s;
            border-radius: var(--border-radius);
            text-decoration: none; /* Remove underline from links */
        }

        .sidebar-submenu a:hover {
            background-color: var(--color-hover-color);
            color: var(--color-hover-text-color);
        }

        .sidebar-section-title {
            font-size: 0.75rem; /* text-xs */
            text-transform: uppercase;
            color: var(--color-gray-600); /* Adjust if you have a specific variable for section titles */
            font-weight: 600; /* font-semibold */
            margin-top: 1.5rem;
            margin-bottom: 0.5rem;
            padding-left: 1rem; /* Align with menu items */
        }

        /* --- START: NEW/MODIFIED CSS FOR HAMBURGER/CLOSE ICON ANIMATION --- */
        /* These styles replace your existing #mobile-menu-button span styles */
        #mobile-menu-button {
            /* Keep your existing button styles, but ensure it's a container for the SVGs */
            width: 32px; /* Adjusted width for better SVG fit */
            height: 32px; /* Adjusted height for better SVG fit */
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0; /* Remove padding if any, SVGs handle size */
            background: transparent;
            border: none;
            cursor: pointer;
            outline: none; /* Remove outline on focus */
        }

        #mobile-menu-button svg {
            transition: opacity 0.3s ease-in-out, transform 0.3s ease-in-out;
            position: absolute; /* Position SVGs on top of each other */
            color: var(--color-main-text-color); /* Use theme color for icon */
        }

        #mobile-menu-button .hamburger-icon {
            opacity: 1;
            transform: rotate(0deg);
        }

        #mobile-menu-button .close-icon {
            opacity: 0;
            transform: rotate(-90deg); /* Start rotated for smooth entry */
        }

        #mobile-menu-button.open .hamburger-icon {
            opacity: 0;
            transform: rotate(90deg); /* Rotate out */
        }

        #mobile-menu-button.open .close-icon {
            opacity: 1;
            transform: rotate(0deg); /* Rotate in */
        }
        /* --- END: NEW/MODIFIED CSS FOR HAMBURGER/CLOSE ICON ANIMATION --- */


        /* Ensure body doesn't scroll when sidebar is open */
        body.overflow-hidden {
            overflow: hidden;
        }
    </style>
</head>
<body class="bg-gray-100">

<!-- Mobile Sidebar (Example Structure) -->
<!-- UPDATED: Using main-color for background instead of gradient, and adjusted padding -->
<div id="mobile-sidebar" class="fixed inset-y-0 left-0 w-64 bg-[var(--color-main-color)] shadow-lg z-40 transform -translate-x-full transition-transform duration-300 ease-in-out md:hidden">
    <div class="p-4">
        <!-- Logo or Title in Sidebar -->
        <div class="flex items-center justify-between mb-6">
            <a href="/">
                <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="Logo" class="h-8 w-auto rounded-[5px]">
            </a>
            <!-- Close Sidebar Button - Using a larger Font Awesome icon -->
            <button id="close-sidebar-button" class="text-[var(--color-main-text-color)] text-3xl focus:outline-none hover:text-[var(--color-hover-text-color)] transition-colors duration-200">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Mobile Navigation Links -->
        <!-- ADDED: space-y-2 for better spacing between menu items -->
        <nav class="flex flex-col space-y-2">
            <!-- UPDATED: Each <a> tag now uses theme colors and reduced font size -->
            <a href="/" class="flex items-center p-3 text-[var(--color-main-text-color)] text-base font-medium rounded-lg hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] transition duration-200 ease-in-out shadow-md">
                <i class="fas fa-home w-6 h-6 mr-3 flex-shrink-0"></i>Home
            </a>
            <a href="/about-us" class="flex items-center p-3 text-[var(--color-main-text-color)] text-base font-medium rounded-lg hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] transition duration-200 ease-in-out shadow-md">
                <i class="fas fa-info-circle w-6 h-6 mr-3 flex-shrink-0"></i>About Us
            </a>
            <a href="/contact-us" class="flex items-center p-3 text-[var(--color-main-text-color)] text-base font-medium rounded-lg hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] transition duration-200 ease-in-out shadow-md">
                <i class="fas fa-envelope w-6 h-6 mr-3 flex-shrink-0"></i>Contact Us
            </a>
            <a href="/privacy-policies" class="flex items-center p-3 text-[var(--color-main-text-color)] text-base font-medium rounded-lg hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] transition duration-200 ease-in-out shadow-md">
                <i class="fas fa-user-shield w-6 h-6 mr-3 flex-shrink-0"></i>Privacy Policies
            </a>
            <a href="/terms-conditions" class="flex items-center p-3 text-[var(--color-main-text-color)] text-base font-medium rounded-lg hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] transition duration-200 ease-in-out shadow-md">
                <i class="fas fa-file-contract w-6 h-6 mr-3 flex-shrink-0"></i>Terms & Conditions
            </a>
            <?php if ($loggedIn): ?>
                <hr class="border-t border-[var(--color-light-gray-border)] my-2">
                <div class="sidebar-section-title text-[var(--color-main-text-color)] opacity-75">Account</div> <!-- Adjusted color for section title -->
                <a href="/dashboard.php" class="flex items-center p-3 text-[var(--color-main-text-color)] text-base font-medium rounded-lg hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] transition duration-200 ease-in-out shadow-md">
                    <i class="fas fa-chart-line w-6 h-6 mr-3 flex-shrink-0"></i>Dashboard
                </a>
                <a href="/users/edit_user.php<?php echo $currentUserId ? '?id=' . $currentUserId : ''; ?>" class="flex items-center p-3 text-[var(--color-main-text-color)] text-base font-medium rounded-lg hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] transition duration-200 ease-in-out shadow-md">
                    <i class="fas fa-user-cog w-6 h-6 mr-3 flex-shrink-0"></i>Profile Settings
                </a>
                <a href="/logout.php" class="flex items-center p-3 text-[var(--color-main-text-color)] text-base font-medium rounded-lg hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] transition duration-200 ease-in-out shadow-md">
                    <i class="fas fa-sign-out-alt w-6 h-6 mr-3 flex-shrink-0"></i>Logout
                </a>
            <?php else: ?>
                <hr class="border-t border-[var(--color-light-gray-border)] my-2">
                <a href="/login" class="flex items-center p-3 text-[var(--color-main-text-color)] text-base font-medium rounded-lg hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] transition duration-200 ease-in-out shadow-md">
                    <i class="fas fa-sign-in-alt w-6 h-6 mr-3 flex-shrink-0"></i>Login
                </a>
            <?php endif; ?>
        </nav>
    </div>
</div>

<!-- Overlay for when sidebar is open -->
<!-- No changes needed here, it's already good. -->
<div id="sidebar-overlay" class="fixed inset-0 bg-[var(--color-bg-black-opacity-50)] z-30 hidden"></div>

<header class="sticky top-0 z-50 bg-[var(--color-main-color)] transition-shadow duration-300 ease-in-out"
        id="main-header">
    <div class="relative max-w-full mx-auto px-4 py-2 md:py-2 flex items-center justify-between">
        <!-- Container for mobile-only elements -->
        <div class="flex items-center justify-start w-full gap-1 md:hidden">
            <!-- Mobile Menu Button (Hamburger) - REPLACED WITH SVGs -->
            <button id="mobile-menu-button" class="z-20 relative group">
                <!-- Hamburger Icon (SVG) -->
                <svg id="hamburgerIcon" class="w-8 h-8 hamburger-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
                <!-- Close Icon (SVG) - initially hidden -->
                <svg id="closeIcon" class="w-8 h-8 close-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>

            <!-- Logo for Mobile -->
            <div>
                <a href="/"> <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="Logo" class="h-10 w-auto rounded-[5px]"> </a>
            </div>

            <!-- Mobile Profile Trigger - Changed px-0.5 py-0.5 to px-0.75 py-0.75 and gap-0.5 to gap-0.75 -->
            <div id="mobile-profile-trigger" class="flex items-center gap-0.5 ml-auto border border-[var(--color-light-gray-border)] rounded-[var(--border-radius)] px-1 py-1 relative cursor-pointer group hover:bg-[var(--color-hover-color)]">
              <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($loggedIn ? $username : 'Login'); ?>" alt="Profile" class="w-6 h-6 rounded-full">
              <?php if ($loggedIn): ?>
                <span class="text-[var(--color-main-text-color)] text-sm flex items-center gap-0.5 leading-none group-hover:text-[var(--color-hover-text-color)]"><?php echo $username; ?>
                  <i class="fas fa-chevron-down ml-1 text-xs mobile-profile-chevron group-hover:text-[var(--color-hover-text-color)]"></i>
                </span>
              <?php else: ?>
                <a href="/login" class="text-[var(--color-main-text-color)] px-2 py-1 rounded sidebar-link group-hover:text-[var(--color-hover-text-color)] leading-none">Login</a>
              <?php endif; ?>
              <?php if ($loggedIn): ?>
                <div id="mobile-profile-dropdown" class="absolute top-full mt-2 right-0 w-48 bg-[var(--color-main-color)] border border-[var(--color-light-gray-border)] rounded-[var(--border-radius)] shadow-lg z-50" style="display: none;">
                <a href="/dashboard.php" class="block px-4 py-2 text-[var(--color-main-text-color)] hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] hover:rounded-[var(--border-radius)] dropdown-link"><i class="fas fa-chart-line mr-2"></i>Dashboard</a>
                <a href="/users/edit_user.php<?php echo $currentUserId ? '?id=' . $currentUserId : ''; ?>" class="block px-4 py-2 text-[var(--color-main-text-color)] hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] hover:rounded-[var(--border-radius)] dropdown-link"><i class="fas fa-user-cog mr-2"></i>Profile Settings</a>
                <a href="/logout.php" class="block px-4 py-2 text-[var(--color-main-text-color)] hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] hover:rounded-[var(--border-radius)] dropdown-link"><i class="fas fa-sign-out-alt mr-2"></i>Logout</a>
                </div>
              <?php endif; ?>
            </div>
        </div>

        <!-- Container for desktop-only elements -->
        <div class="hidden md:flex items-center justify-between w-full">
            <!-- Logo for Desktop -->
            <div class="md:static md:transform-none">
                <a href="/"> <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="Logo" class="h-10 w-auto rounded-[5px]"> </a>
            </div>

            <!-- Desktop Navigation Links -->
            <nav class="flex items-center gap-2 ml-auto">
                <a href="/" class="block px-2 py-1 text-[var(--color-main-text-color)] hover:text-[var(--color-hover-text-color)] hover:bg-[var(--color-hover-color)] hover:rounded-[var(--border-radius)] menu-button">
                    <i class="fas fa-home mr-1"></i>Home
                </a>
                <a href="/about-us" class="block px-2 py-1 text-[var(--color-main-text-color)] hover:text-[var(--color-hover-text-color)] hover:bg-[var(--color-hover-color)] hover:rounded-[var(--border-radius)] menu-button">
                    <i class="fas fa-info-circle mr-1"></i>About Us
                </a>
                <a href="/contact-us" class="block px-2 py-1 text-[var(--color-main-text-color)] hover:text-[var(--color-hover-text-color)] hover:bg-[var(--color-hover-color)] hover:rounded-[var(--border-radius)] menu-button">
                    <i class="fas fa-envelope mr-1"></i>Contact Us
                </a>
                <a href="/privacy-policies" class="block px-2 py-1 text-[var(--color-main-text-color)] hover:text-[var(--color-hover-text-color)] hover:bg-[var(--color-hover-color)] hover:rounded-[var(--border-radius)] menu-button">
                    <i class="fas fa-user-shield mr-1"></i>Privacy Policies
                </a>
                <a href="/terms-conditions" class="block px-2 py-1 text-[var(--color-main-text-color)] hover:text-[var(--color-hover-text-color)] hover:bg-[var(--color-hover-color)] hover:rounded-[var(--border-radius)] menu-button">
                    <i class="fas fa-file-contract mr-1"></i>Terms & Conditions
                </a>

                <div class="ml-4 relative">
                    <!-- Desktop Profile Trigger - Updated icon and text sizes -->
                    <div id="desktop-profile-trigger" class="flex items-center gap-0.5 border border-[var(--color-light-gray-border)] rounded-[var(--border-radius)] px-1 py-1 cursor-pointer group hover:bg-[var(--color-hover-color)]">
                        <!-- Reduced image size from w-8 h-8 to w-6 h-6 -->
                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($loggedIn ? $username : 'Login'); ?>" alt="Profile" class="w-6 h-6 rounded-full">
                        <?php if ($loggedIn): ?>
                            <!-- Reduced text size from text-sm to text-xs -->
                            <span class="text-xs text-[var(--color-main-text-color)] flex items-center gap-0.5 leading-none group-hover:text-[var(--color-hover-text-color)]"><?php echo htmlspecialchars($username); ?> <i class="fas fa-chevron-down ml-1 text-xs desktop-profile-chevron group-hover:text-[var(--color-hover-text-color)]"></i></span>
                        <?php else: ?>
                            <!-- Reduced text size from text-[var(--color-main-text-color)] to text-xs -->
                            <a href="/login" class="text-xs text-[var(--color-main-text-color)] px-2 py-1 rounded sidebar-link group-hover:text-[var(--color-hover-text-color)] leading-none">Login</a>
                        <?php endif; ?>
                    </div>
                    <?php if ($loggedIn): ?>
                    <div id="desktop-profile-dropdown" class="absolute top-full mt-2 right-0 w-48 bg-[var(--color-main-color)] border border-[var(--color-light-gray-border)] rounded-[var(--border-radius)] shadow-lg z-50" style="display: none;">
                        <a href="/dashboard.php" class="block px-4 py-2 text-[var(--color-main-text-color)] hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] hover:rounded-[var(--border-radius)] dropdown-link"><i class="fas fa-chart-line mr-2"></i>Dashboard</a>
                        <a href="/users/edit_user.php<?php echo $currentUserId ? '?id=' . $currentUserId : ''; ?>" class="block px-4 py-2 text-[var(--color-main-text-color)] hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] hover:rounded-[var(--border-radius)] dropdown-link"><i class="fas fa-user-cog mr-2"></i>Profile Settings</a>
                        <a href="/logout.php" class="block px-4 py-2 text-[var(--color-main-text-color)] hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] hover:rounded-[var(--border-radius)] dropdown-link"><i class="fas fa-sign-out-alt mr-2"></i>Logout</a>
                    </div>
                    <?php endif; ?>
                </div>
            </nav>
        </div>
    </div>
</header>

<?php
// headersidebar.php is now a separate layout for admin/dashboard pages.
// It should NOT be included in header.php for public-facing pages like login.
// If this header is used for admin pages, then headersidebar.php should be the main layout file.
?>

<!-- This is where the content from individual pages (like login) will be inserted -->
<!-- UPDATED: Wrapped $pageContent in a <main> tag to ensure it expands and pushes the footer down -->
<main class="flex-grow">
    <?php echo $pageContent; ?>
</main>

<script>
    $(document).ready(function() {
        // Handle scroll for header shadow
        $(window).on('scroll', function() {
            if ($(window).scrollTop() > 50) {
                $('#main-header').addClass('shadow-lg').removeClass('shadow-md');
            } else {
                $('#main-header').addClass('shadow-md').removeClass('shadow-lg');
            }
        });

        // Mobile Profile Dropdown Toggle
        $('#mobile-profile-trigger').on('click', function(e) {
            e.stopPropagation(); // Prevent click from bubbling to document
            $('#mobile-profile-dropdown').slideToggle(200);
            $('.mobile-profile-chevron').toggleClass('fa-chevron-down fa-chevron-up');
        });

        // Desktop Profile Dropdown Toggle
        $('#desktop-profile-trigger').on('click', function(e) {
            e.stopPropagation(); // Prevent click from bubbling to document
            $('#desktop-profile-dropdown').slideToggle(200);
            $('.desktop-profile-chevron').toggleClass('fa-chevron-down fa-chevron-up');
        });

        // Click-away for profile dropdowns
        $(document).on('click', function(e) {
            // Mobile profile dropdown
            if (!$(e.target).closest('#mobile-profile-trigger').length && !$(e.target).closest('#mobile-profile-dropdown').length) {
                $('#mobile-profile-dropdown').slideUp(200);
                $('.mobile-profile-chevron').removeClass('fa-chevron-up').addClass('fa-chevron-down');
            }
            // Desktop profile dropdown
            if (!$(e.target).closest('#desktop-profile-trigger').length && !$(e.target).closest('#desktop-profile-dropdown').length) {
                $('#desktop-profile-dropdown').slideUp(200);
                $('.desktop-profile-chevron').removeClass('fa-chevron-up').addClass('fa-chevron-down');
            }
            // Click-away for mobile sidebar
            if (!$(e.target).closest('#mobile-menu-button').length && !$(e.target).closest('#mobile-sidebar').length) {
                if (!$('#mobile-sidebar').hasClass('-translate-x-full')) {
                    $('#mobile-sidebar').addClass('-translate-x-full');
                    $('#sidebar-overlay').addClass('hidden');
                    $('#mobile-menu-button').removeClass('open'); // Reset hamburger icon
                    $('body').removeClass('overflow-hidden'); // Re-enable body scroll
                }
            }
        });

        // Mobile Menu Button (Hamburger) functionality
        $('#mobile-menu-button').on('click', function() {
            $('#mobile-sidebar').toggleClass('-translate-x-full');
            $('#sidebar-overlay').toggleClass('hidden');
            // UPDATED: Toggle 'open' class on the button itself for SVG animation
            $(this).toggleClass('open');
            $('body').toggleClass('overflow-hidden'); // Prevent body scroll when sidebar is open
        });

        // Close Sidebar Button
        $('#close-sidebar-button, #sidebar-overlay').on('click', function() {
            $('#mobile-sidebar').addClass('-translate-x-full');
            $('#sidebar-overlay').addClass('hidden');
            // UPDATED: Remove 'open' class from the button for SVG animation
            $('#mobile-menu-button').removeClass('open');
            $('body').removeClass('overflow-hidden'); // Re-enable body scroll
        });
    });
</script>

<?php
// Include the footer, which closes the main, body, and html tags.
// Ensure the path to your footer.php is correct relative to this file.
include BASE_PATH . '/layout/footer.php';
?>
