<?php
// File: layout/headersidebar.php
// This file acts as the START of your application's main layout,
// including the HTML head, mobile/desktop sidebars, and the opening structure
// for the main content area.
// It assumes the session is already started and BASE_PATH is defined by the router.
// It also expects $loggedIn, $username, $userRole, $pageTitle, and the currentUserId
// to be set by the script that includes this file (which is typically handled by the router).

// Prevent direct access if someone tries to load this file directly in the browser.
if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    header('Location: /'); // Redirect to your home page via router
    exit;
}

// --- Include centralized settings and theme variables ---
// Use BASE_PATH for all includes, as router.php defines it.
$configPath = BASE_PATH . '/config/config.php';
$settingsVarsPath = BASE_PATH . '/vars/settingsvars.php';
$logoVarsPath = BASE_PATH . '/vars/logovars.php';
$socialMediaVarsPath = BASE_PATH . '/vars/socialmediavars.php';

// Include config.php first for global definitions like APP_TITLE and BASE_PATH (if not already defined)
if (file_exists($configPath)) {
    require_once $configPath;
}

// --- Define Constants (Moved from previous file, ideally these are in config.php) ---
// If these are not already defined in config.php, they will be defined here as a fallback.
if (!defined('TOTAL_DISK_LIMIT_BYTES')) {
    define('TOTAL_DISK_LIMIT_BYTES', 5 * 1024 * 1024 * 1024); // 5 GB in bytes
}
if (!defined('PROGRESS_BAR_COLOR_GREEN')) {
    define('PROGRESS_BAR_COLOR_GREEN', '#4CAF50');
}
if (!defined('PROGRESS_BAR_COLOR_ORANGE')) {
    define('PROGRESS_BAR_COLOR_ORANGE', '#FFC107');
}
if (!defined('PROGRESS_BAR_COLOR_RED')) {
    define('PROGRESS_BAR_COLOR_RED', '#F44336');
}


// --- Disk Usage Calculation ---
$diskUsage = 'N/A'; // Default display if calculation fails
$diskUsagePercentage = '0'; // Default percentage as a number for width calculation
$diskUsagePercentageDisplay = '0%'; // Default percentage for display
$progressBarColor = PROGRESS_BAR_COLOR_GREEN; // Default to green
$rawDiskUsageBytes = null; // Initialize to null

// Ensure BASE_PATH is defined and shell_exec is enabled
if (defined('BASE_PATH') && function_exists('shell_exec')) {
    $rootDir = BASE_PATH;
    // Use escapeshellarg for security to prevent command injection
    $output = shell_exec("du -s " . escapeshellarg($rootDir));

    if ($output) {
        // Extract the size in kilobytes (first number from 'du -s' output)
        if (preg_match('/^([0-9]+)/', $output, $matches)) {
            $rawDiskUsageKB = (float)$matches[1];
            $rawDiskUsageBytes = $rawDiskUsageKB * 1024; // Convert kilobytes to actual bytes

            // Determine display unit (MB or GB) for current usage
            if ($rawDiskUsageBytes < (1024 * 1024 * 1024)) { // Less than 1GB, show in MB
                $diskUsageInMB = $rawDiskUsageBytes / (1024 * 1024);
                $diskUsage = sprintf('%.1f MB', $diskUsageInMB);
            } else { // 1GB or more, show in GB
                $diskUsageInGB = $rawDiskUsageBytes / (1024 * 1024 * 1024);
                $diskUsage = sprintf('%.1f GB', $diskUsageInGB);
            }

            // Calculate percentage based on total bytes
            if (TOTAL_DISK_LIMIT_BYTES > 0) {
                $percentage = ($rawDiskUsageBytes / TOTAL_DISK_LIMIT_BYTES) * 100;
                $diskUsagePercentage = sprintf('%.2f', $percentage); // For width calculation (e.g., 74.50)
                $diskUsagePercentageDisplay = sprintf('%.0f%%', $percentage); // For display (e.g., 74%)

                // Determine progress bar color based on percentage
                if ($percentage < 60) {
                    $progressBarColor = PROGRESS_BAR_COLOR_GREEN; // Green
                } elseif ($percentage >= 60 && $percentage < 90) {
                    $progressBarColor = PROGRESS_BAR_COLOR_ORANGE; // Orange
                } else {
                    $progressBarColor = PROGRESS_BAR_COLOR_RED; // Red
                }
            }
        } else {
            error_log("Error: 'du -s' output format unexpected: " . $output);
        }
    } else {
        error_log("Warning: 'du -s' command failed or returned no output.");
    }
} else {
    // Fallback if BASE_PATH is not defined or shell_exec is disabled
    error_log("Warning: Disk usage calculation failed. BASE_PATH not defined or shell_exec is disabled.");
}

// Convert total storage to MB or GB for display based on size
$totalStorageDisplayFormatted = 'N/A'; // Default
if (defined('TOTAL_DISK_LIMIT_BYTES')) {
    if (TOTAL_DISK_LIMIT_BYTES < (1024 * 1024 * 1024)) { // Less than 1GB, show in MB
        $totalStorageInMB = TOTAL_DISK_LIMIT_BYTES / (1024 * 1024);
        $totalStorageDisplayFormatted = sprintf('%d MB', $totalStorageInMB);
    } else { // 1GB or more, show in GB
        $totalStorageInGB = TOTAL_DISK_LIMIT_BYTES / (1024 * 1024 * 1024);
        $totalStorageDisplayFormatted = sprintf('%d GB', $totalStorageInGB);
    }
}


// Include logovars.php for APP_LOGO_PATH, APP_FAVICON_PATH
if (file_exists($logoVarsPath)) {
    require_once $logoVarsPath;
} else {
    if (!defined('APP_LOGO_PATH')) {
        define('APP_LOGO_PATH', '/uploads/assets/logo.jpg'); // Default placeholder
    }
    if (!defined('APP_FAVICON_PATH')) {
        define('APP_FAVICON_PATH', '/uploads/assets/favicon.ico'); // Default placeholder
    }
}
// Set $logoPath from APP_LOGO_PATH, ensuring it's defined
$logoPath = defined('APP_LOGO_PATH') ? APP_LOGO_PATH : '/assets/img/logos/default-logo.png';


// Include socialmediavars.php for social media links
if (file_exists($socialMediaVarsPath)) {
    require_once $socialMediaVarsPath;
} else {
    // Default social media links if not defined
    $socialMediaLinks = [
        'facebook' => '#',
        'twitter' => '#',
        'linkedin' => '#'
    ];
}

// Check if user is logged in (assuming a session variable like 'user_id' exists)
$loggedIn = isset($_SESSION['user_id']);
$username = $_SESSION['username'] ?? 'Guest'; // Default to 'Guest' if not set
$userRole = $_SESSION['user_role'] ?? 'user'; // Default to 'user' role
$currentUserId = $_SESSION['user_id'] ?? null; // Current user ID for profile link
$email = $_SESSION['email'] ?? 'guest@example.com'; // Added this line to define $email

// Set default page title if not provided by the including script
$pageTitle = $pageTitle ?? (defined('APP_TITLE') ? APP_TITLE : 'My Application'); // Use APP_TITLE from config.php, with a final fallback

// PHP logic to check for a 'theme' cookie and set the class on the html tag
$themeClass = 'light';
if (isset($_COOKIE['theme'])) {
    $themeClass = $_COOKIE['theme'];
}
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo htmlspecialchars($themeClass); ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($pageTitle); ?></title>

  <!-- Tailwind CSS v4.1 CDN -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Google Fonts - Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap"
    rel="stylesheet"
  />

  <!-- Google Material Icons -->
  <link
    href="https://fonts.googleapis.com/icon?family=Material+Icons"
    rel="stylesheet"
  />
  <link
    href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined"
    rel="stylesheet"
  />
  <!-- Font Awesome CDN -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" xintegrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />

  <script>
    // Tailwind CSS config for extended font family
    tailwind.config = {
      darkMode: 'class', // Enable dark mode based on the 'dark' class
      theme: {
        extend: {
          fontFamily: {
            inter: ["Inter", "sans-serif"],
          },
        },
      },
    };

    // Encapsulate all JavaScript in an IIFE to avoid polluting the global namespace
    (function() {
        /**
         * Toggles the visibility of a dropdown menu and rotates its arrow.
         * Closes other open dropdowns.
         * @param {string} dropdownId - The ID of the dropdown menu element.
         * @param {string} parentLinkId - The ID of the parent link element that triggers the dropdown.
         */
        function toggleDropdown(dropdownId, parentLinkId) {
            console.log('toggleDropdown called for:', dropdownId);
            const allDropdowns = document.querySelectorAll(".dropdown-menu");
            const allParentLinks = document.querySelectorAll("[id$='ParentLink']");
            const allArrows = document.querySelectorAll("[id$='Arrow']");

            // Close all other dropdowns
            allDropdowns.forEach((dropdown, index) => {
                if (dropdown.id !== dropdownId) {
                    dropdown.classList.remove("active");
                    allParentLinks[index].classList.remove("dropdown-parent-active");
                    allArrows[index].style.transform = "rotate(0deg)";
                }
            });

            const dropdown = document.getElementById(dropdownId);
            const parentLink = document.getElementById(parentLinkId);
            const arrow = document.getElementById(dropdownId.replace("Dropdown", "Arrow"));

            if (dropdown) { // Check if dropdown exists
                dropdown.classList.toggle("active");
                console.log('Dropdown active status:', dropdown.classList.contains('active'));

                if (parentLink) parentLink.classList.toggle("dropdown-parent-active", dropdown.classList.contains('active'));
                if (arrow) arrow.style.transform = dropdown.classList.contains("active") ? "rotate(180deg)" : "rotate(0deg)";
            }
        }

        /**
         * Toggles the sidebar visibility for mobile/tablet screens.
         * Manages the hamburger icon animation and backdrop.
         */
        function toggleSidebar() {
            console.log('toggleSidebar called');
            const sidebar = document.getElementById('sidebar'); // Get element inside function
            const mobileMenuButton = document.getElementById('mobile-menu-button'); // Get element inside function
            const sidebarBackdrop = document.getElementById('sidebar-backdrop'); // Get element inside function
            const mobileHeader = document.getElementById('mobile-header'); // Get mobile header

            if (sidebar) { // Check if sidebar exists
                sidebar.classList.toggle('-translate-x-full'); // Toggles sidebar visibility
                console.log('Sidebar translate-x-full status:', sidebar.classList.contains('-translate-x-full'));
            }
            if (mobileMenuButton) { // Check if button exists
                mobileMenuButton.classList.toggle('is-active'); // Toggles hamburger animation
            }

            // Toggle backdrop visibility and opacity
            if (sidebarBackdrop) { // Check if backdrop exists
                if (sidebar && sidebar.classList.contains('-translate-x-full')) {
                    sidebarBackdrop.classList.remove('opacity-90');
                    sidebarBackdrop.classList.add('hidden');
                    document.body.classList.remove('overflow-hidden'); // Allow scrolling
                    if (mobileHeader) mobileHeader.classList.remove('z-30'); // Lower header z-index
                    if (mobileHeader) mobileHeader.classList.add('z-50'); // Restore header z-index
                } else {
                    sidebarBackdrop.classList.remove('hidden');
                    // Small delay to allow 'hidden' to be removed before transition
                    setTimeout(() => {
                        sidebarBackdrop.classList.add('opacity-90');
                    }, 10);
                    document.body.classList.add('overflow-hidden'); // Stop scrolling
                    if (mobileHeader) mobileHeader.classList.remove('z-50'); // Lower header z-index
                    if (mobileHeader) mobileHeader.classList.add('z-30'); // Lower header z-index
                }
            }
        }

        /**
         * Toggles the visibility of a profile dropdown and rotates its chevron.
         * @param {string} triggerId - The ID of the element that triggers the dropdown.
         * @param {string} dropdownId - The ID of the dropdown menu element.
         * @param {string} chevronClass - The class of the chevron icon to rotate.
         */
        function toggleProfileDropdown(triggerId, dropdownId, chevronClass) {
            console.log('toggleProfileDropdown called for:', dropdownId);
            const dropdown = document.getElementById(dropdownId);
            const chevron = document.getElementById(triggerId) ? document.getElementById(triggerId).querySelector(`.${chevronClass}`) : null;

            if (dropdown) {
                dropdown.classList.toggle('hidden');
                console.log('Dropdown hidden status:', dropdown.classList.contains('hidden'));
                if (chevron) {
                    chevron.classList.toggle('rotate-180');
                }
            }
        }

        // --- Dark Mode Toggle Logic ---
        function getThemeFromCookie() {
          const name = "theme=";
          const decodedCookie = decodeURIComponent(document.cookie);
          const ca = decodedCookie.split(';');
          for(let i = 0; i < ca.length; i++) {
            let c = ca[i];
            while (c.charAt(0) === ' ') {
              c = c.substring(1);
            }
            if (c.indexOf(name) === 0) {
              return c.substring(name.length, c.length);
            }
          }
          return null;
        }

        function setTheme(theme) {
            const htmlElement = document.documentElement;
            if (theme === 'dark') {
                htmlElement.classList.add('dark');
            } else {
                htmlElement.classList.remove('dark');
            }
            // Update cookie
            document.cookie = `theme=${theme}; path=/; max-age=31536000`;
        }

        // Function to update the icon based on the current theme
        function updateThemeIcon() {
            const isDark = document.documentElement.classList.contains('dark');
            const desktopIcon = document.getElementById('theme-icon-desktop');
            const mobileIcon = document.getElementById('theme-icon-mobile');

            if (desktopIcon) {
                if (isDark) {
                    desktopIcon.classList.remove('fa-sun');
                    desktopIcon.classList.add('fa-moon');
                } else {
                    desktopIcon.classList.remove('fa-moon');
                    desktopIcon.classList.add('fa-sun');
                }
            }
            if (mobileIcon) {
                if (isDark) {
                    mobileIcon.classList.remove('fa-sun');
                    mobileIcon.classList.add('fa-moon');
                } else {
                    mobileIcon.classList.remove('fa-moon');
                    mobileIcon.classList.add('fa-sun');
                }
            }
        }


        function toggleTheme() {
            const htmlElement = document.documentElement;
            const currentTheme = htmlElement.classList.contains('dark') ? 'dark' : 'light';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            setTheme(newTheme);
            updateThemeIcon(); // Call the new function to update the icon
        }

        // Add event listeners once the DOM is fully loaded
        document.addEventListener("DOMContentLoaded", function () {
            console.log('DOMContentLoaded fired');

            // Set initial theme based on cookie or system preference
            const storedTheme = getThemeFromCookie();
            if (storedTheme) {
                setTheme(storedTheme);
            } else if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                setTheme('dark');
            } else {
                setTheme('light');
            }
            updateThemeIcon(); // Call the function to set the initial icon


            // Cache DOM elements here, after DOM is loaded
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const sidebarBackdrop = document.getElementById('sidebar-backdrop');
            const profileTriggerDesktop = document.getElementById('profile-trigger-desktop'); // Desktop trigger
            const profileTriggerMobile = document.getElementById('profile-trigger-mobile'); // Mobile trigger
            const desktopProfileDropdown = document.getElementById('desktop-profile-dropdown'); // Desktop dropdown
            const mobileProfileDropdown = document.getElementById('mobile-profile-dropdown'); // Mobile dropdown
            const sidebar = document.getElementById('sidebar');
            const mobileHeader = document.getElementById('mobile-header');
            const themeToggleDesktop = document.getElementById('theme-toggle-desktop');
            const themeToggleMobile = document.getElementById('theme-toggle-mobile');

            // Debugging: Log if elements are found AFTER DOMContentLoaded
            console.log('Sidebar element (DOMContentLoaded):', sidebar);
            console.log('Mobile Menu Button (DOMContentLoaded):', mobileMenuButton);
            console.log('Sidebar Backdrop (DOMContentLoaded):', sidebarBackdrop);
            console.log('Profile Trigger Desktop (DOMContentLoaded):', profileTriggerDesktop);
            console.log('Profile Trigger Mobile (DOMContentLoaded):', profileTriggerMobile);
            console.log('Desktop Profile Dropdown (DOMContentLoaded):', desktopProfileDropdown);
            console.log('Mobile Profile Dropdown (DOMContentLoaded):', mobileProfileDropdown);
            console.log('Mobile Header (DOMContentLoaded):', mobileHeader);
            console.log('Desktop Theme Toggle (DOMContentLoaded):', themeToggleDesktop);
            console.log('Mobile Theme Toggle (DOMContentLoaded):', themeToggleMobile);


            // Initialize dropdowns to closed state
            document.querySelectorAll(".dropdown-menu").forEach((dropdown) => {
                dropdown.classList.remove("active");
                console.log('Initialized dropdown:', dropdown.id);
            });
            document.querySelectorAll("[id$='ParentLink']").forEach((link) => link.classList.remove("dropdown-parent-active"));
            document.querySelectorAll("[id$='Arrow']").forEach((arrow) => (arrow.style.transform = "rotate(0deg)"));

            // Attach event listener for the sidebar toggle button
            if (mobileMenuButton) {
                mobileMenuButton.addEventListener('click', toggleSidebar);
                console.log('Mobile menu button listener attached.');
            } else {
                console.warn('Mobile menu button element not found AFTER DOMContentLoaded.');
            }

            // Attach event listener for the sidebar backdrop to close sidebar on outside click
            if (sidebarBackdrop) {
                sidebarBackdrop.addEventListener('click', toggleSidebar);
                console.log('Sidebar backdrop listener attached.');
            } else {
                console.warn('Sidebar backdrop element not found AFTER DOMContentLoaded.');
            }

            // Attach event listener for the desktop profile dropdown trigger
            if (profileTriggerDesktop) {
                profileTriggerDesktop.addEventListener('click', () => toggleProfileDropdown('profile-trigger-desktop', 'desktop-profile-dropdown', 'profile-chevron'));
                console.log('Desktop profile trigger listener attached.');
            } else {
                console.warn('Desktop profile trigger element not found AFTER DOMContentLoaded.');
            }

            // Attach event listener for the mobile profile dropdown trigger
            if (profileTriggerMobile) {
                profileTriggerMobile.addEventListener('click', () => toggleProfileDropdown('profile-trigger-mobile', 'mobile-profile-dropdown', 'profile-chevron'));
                console.log('Mobile profile trigger listener attached.');
            } else {
                console.warn('Mobile profile trigger element not found AFTER DOMContentLoaded.');
            }


            // Close profile dropdowns when clicking outside
            document.addEventListener('click', function(event) {
                const isClickInsideDesktopTrigger = profileTriggerDesktop && profileTriggerDesktop.contains(event.target);
                const isClickInsideDesktopDropdown = desktopProfileDropdown && desktopProfileDropdown.contains(event.target);
                const isClickInsideMobileTrigger = profileTriggerMobile && profileTriggerMobile.contains(event.target);
                const isClickInsideMobileDropdown = mobileProfileDropdown && mobileProfileDropdown.contains(event.target);


                // Close desktop dropdown if open and click is outside its trigger/dropdown
                if (desktopProfileDropdown && !desktopProfileDropdown.classList.contains('hidden') && !isClickInsideDesktopTrigger && !isClickInsideDesktopDropdown) {
                    console.log('Closing desktop profile dropdown (click outside).');
                    toggleProfileDropdown('profile-trigger-desktop', 'desktop-profile-dropdown', 'profile-chevron');
                }
                // Close mobile dropdown if open and click is outside its trigger/dropdown
                if (mobileProfileDropdown && !mobileProfileDropdown.classList.contains('hidden') && !isClickInsideMobileTrigger && !isClickInsideMobileDropdown) {
                    console.log('Closing mobile profile dropdown (click outside).');
                    toggleProfileDropdown('profile-trigger-mobile', 'mobile-profile-dropdown', 'profile-chevron');
                }
            });

            // Attach dark mode toggle listeners
            if (themeToggleDesktop) themeToggleDesktop.addEventListener('click', toggleTheme);
            if (themeToggleMobile) themeToggleMobile.addEventListener('click', toggleTheme);


            // Adjust sidebar and content on window resize (needs sidebar element)
            window.addEventListener('resize', function() {
                console.log('Window resized. Width:', window.innerWidth);
                if (window.innerWidth >= 768) { // Desktop view (md breakpoint in Tailwind)
                    if (sidebar) sidebar.classList.remove('-translate-x-full'); // Ensure sidebar is visible
                    if (mobileMenuButton) mobileMenuButton.classList.remove('is-active'); // Reset hamburger icon
                    if (sidebarBackdrop) {
                        sidebarBackdrop.classList.add('hidden'); // Hide backdrop on desktop
                        sidebarBackdrop.classList.remove('opacity-90');
                    }
                    document.body.classList.remove('overflow-hidden'); // Allow scrolling
                    if (mobileHeader) mobileHeader.classList.remove('z-30'); // Lower header z-index
                    if (mobileHeader) mobileHeader.classList.add('z-50'); // Restore header z-index
                } else { // Mobile/Tablet view
                    // If sidebar was open, keep it open, otherwise keep it closed
                    if (sidebar && !sidebar.classList.contains('-translate-x-full')) {
                        if (sidebarBackdrop) {
                            sidebarBackdrop.classList.remove('hidden');
                            sidebarBackdrop.classList.add('opacity-90');
                        }
                        document.body.classList.add('overflow-hidden'); // Stop scrolling
                        if (mobileHeader) mobileHeader.classList.remove('z-50'); // Lower header z-index
                        if (mobileHeader) mobileHeader.classList.add('z-30'); // Lower header z-index
                    } else {
                        if (sidebarBackdrop) {
                            sidebarBackdrop.classList.add('hidden');
                            sidebarBackdrop.classList.remove('opacity-90');
                        }
                        document.body.classList.remove('overflow-hidden'); // Allow scrolling
                        if (mobileHeader) mobileHeader.classList.remove('z-30'); // Restore header z-index
                        if (mobileHeader) mobileHeader.classList.add('z-50'); // Restore header z-index
                    }
                }
            });
        });

        // Expose toggleDropdown to global scope if needed for inline onclick attributes
        // It's generally better to use event listeners, but keeping for compatibility with existing HTML
        window.toggleDropdown = toggleDropdown;

    })(); // End of IIFE
  </script>

  <style>
    /* Apply Inter font globally */
    body {
      font-family: "Inter", sans-serif;
      transition: background-color 0.3s, color 0.3s;
    }

    /* Custom scrollbar styles for sidebar */
    .sidebar-scroll {
      scrollbar-width: thin;
      scrollbar-color: #d1d5db #312e81;
    }
    .dark .sidebar-scroll {
      scrollbar-color: #4b5563 #111827;
    }
    .sidebar-scroll::-webkit-scrollbar {
      width: 8px;
    }
    .sidebar-scroll::-webkit-scrollbar-track {
      background: #312e81;
      border-radius: 10px;
    }
    .dark .sidebar-scroll::-webkit-scrollbar-track {
        background: #111827;
    }
    .sidebar-scroll::-webkit-scrollbar-thumb {
      background-color: #d1d5db;
      border-radius: 10px;
      border: 2px solid #312e81;
    }
    .dark .sidebar-scroll::-webkit-scrollbar-thumb {
        background-color: #4b5563;
        border: 2px solid #111827;
    }

    /* Dropdown menu base styles */
    .dropdown-menu {
      display: none; /* Start hidden by default */
      position: relative;
      left: 0;
      top: 0;
      min-width: 100%;
      z-index: 10;
      flex-direction: column;
      border-radius: 0;
      box-shadow: none;
      border-left: 2px solid #4f46e5; /* Indigo-600 */
      padding-left: 1rem; /* space for the line */
      opacity: 0;
      transform: translateY(-10px);
      transition: all 0.3s ease;
    }

    /* Show dropdown when active */
    .dropdown-menu.active {
      display: flex;
      opacity: 1;
      transform: translateY(0);
    }

    /* Active state for dropdown parent */
    .dropdown-parent-active {
      background-color: #4338ca; /* Indigo-700 */
    }
    .dark .dropdown-parent-active {
        background-color: #374151; /* Gray-700 */
    }


    /* Dropdown arrow rotation */
    .rotate-180 {
      transform: rotate(180deg);
    }

    /* Dropdown menu items */
    .dropdown-menu a {
      padding-left: 0.5rem;
      font-size: 0.75rem; /* text-xs (12px), slightly smaller */
      height: 2rem;
      background-color: #312e81; /* Indigo-900 */
      display: flex;
      align-items: center;
      transition: background-color 0.2s ease;
    }
    .dark .dropdown-menu a {
        background-color: #111827; /* Gray-900 */
    }
    .dropdown-menu a:hover {
      background-color: #6366f1; /* Indigo-500 */
    }
    .dark .dropdown-menu a:hover {
        background-color: #4b5563; /* Gray-600 */
    }

    /* Hamburger menu animation styles */
    .mobile-menu-button {
      position: relative;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    /* Hamburger lines */
    .mobile-menu-button span {
      display: block;
      width: 1.5rem; /* w-6 */
      height: 2px;
      background-color: #E0E0E0; /* Light gray */
      transition: all 0.3s ease-in-out;
    }

    /* Spacing between hamburger lines */
    .mobile-menu-button span:nth-child(2) {
      margin-top: 0.375rem; /* space-y-1.5 */
      margin-bottom: 0.375rem; /* space-y-1.5 */
    }

    /* Hamburger active state */
    .mobile-menu-button.is-active span:nth-child(1) {
      transform: translateY(0.5625rem) rotate(45deg); /* Move down and rotate */
    }
    .mobile-menu-button.is-active span:nth-child(2) {
      opacity: 0; /* Hide middle line */
    }
    .mobile-menu-button.is-active span:nth-child(3) {
      transform: translateY(-0.5625rem) rotate(-45deg); /* Move up and rotate */
    }

    /* Profile dropdown specific styling */
    .profile-dropdown {
        right: 0;
        left: auto; /* Ensure it aligns to the right */
        min-width: 150px; /* Adjust as needed */
        padding: 0.5rem;
        box-sizing: border-box;
        border-radius: 0.5rem; /* rounded-lg */
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); /* shadow-lg */
        z-index: 50;
    }
    .profile-dropdown .dropdown-link {
        border-radius: 0.375rem; /* rounded-md */
        padding: 0.5rem 0.75rem; /* px-4 py-2 */
        display: flex;
        align-items: center;
        white-space: nowrap;
        font-size: 0.875rem; /* text-sm */
        font-weight: 500; /* font-medium */
        transition: background-color 0.2s ease, color 0.2s ease;
    }

    /* Desktop profile dropdown positioning (opens downwards, aligned right) */
    #desktop-profile-dropdown {
        position: absolute;
        top: calc(100% + 8px); /* Position below the trigger with some space */
        right: 0; /* Align to the right of the trigger */
        left: auto; /* Ensure it doesn't conflict with right */
        transform: none; /* Remove centering transform */
    }

    /* Mobile profile dropdown positioning (opens downwards) */
    #mobile-profile-dropdown {
        position: absolute;
        top: calc(100% + 8px); /* Position below the trigger with some space */
        right: 0;
    }

    /* New unified button styles */
    .header-button {
      background-color: #F3E8FF; /* purple-100 */
      color: #5B21B6; /* purple-700 */
      border-radius: 9999px; /* rounded-full */
      padding: 0.25rem 0.5rem; /* Updated to py-1 px-2 for a more compact look */
      font-weight: 500; /* font-medium */
      font-size: 0.875rem; /* text-sm */
      transition: all 0.2s ease;
    }

    .header-button:hover {
      background-color: #EDE9FE; /* purple-200 */
    }

    .dark .header-button {
      background-color: #374151; /* gray-700 */
      color: #D1D5DB; /* gray-300 */
    }

    .dark .header-button:hover {
      background-color: #4B5563; /* gray-600 */
    }

    /* Specific styles for the profile button */
    .profile-button {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
  </style>
</head>
<body class="flex flex-col min-h-screen bg-purple-100 dark:bg-gray-900 m-0 p-0 font-inter">
  <!-- Sidebar Backdrop (for closing on outside click on mobile) -->
  <div id="sidebar-backdrop" class="fixed inset-0 bg-black bg-opacity-90 z-40 hidden transition-opacity duration-300"></div>

  <!-- Mobile Header (Visible only on small screens, hidden on desktop) -->
  <header id="mobile-header" class="sticky top-0 z-50 md:hidden flex items-center justify-between py-2 w-full h-14 bg-indigo-900 dark:bg-gray-800 transition-colors duration-300">
      <!-- Left side: Hamburger Menu Button and Logo -->
      <div class="flex items-center flex-shrink-0 pl-4">
        <!-- Hamburger Menu Button -->
        <button id="mobile-menu-button" class="z-20 relative w-8 h-8 flex flex-col justify-center items-center group space-y-1.5" aria-label="Toggle navigation">
          <span class="h-0.5 w-6 bg-white block transition-all duration-300"></span>
          <span class="h-0.5 w-6 bg-white block transition-all duration-300"></span>
          <span class="h-0.5 w-6 bg-white block transition-all duration-300"></span>
        </button>

        <!-- Logo -->
        <div class="pl-1 flex items-center flex-shrink-0">
          <a href="/" aria-label="Home">
            <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="<?php echo htmlspecialchars(defined('APP_TITLE') ? APP_TITLE . ' Logo' : 'App Logo'); ?>" class="h-10 w-auto rounded-md">
          </a>
        </div>
      </div>

      <!-- Right side: User Info Box with Dropdown and Dark Mode Toggle (for mobile view) -->
      <div class="flex items-center gap-2 pr-4">
          <!-- Dark Mode Toggle for Mobile (First Icon) -->
          <button id="theme-toggle-mobile" class="flex items-center justify-center w-8 h-8 bg-indigo-800 dark:bg-gray-700 rounded-full text-indigo-200 dark:text-gray-300 hover:bg-indigo-700 dark:hover:bg-gray-600 transition-colors duration-200" aria-label="Toggle dark mode">
              <!-- UPDATED: Use a single icon and toggle its class with JS -->
              <i id="theme-icon-mobile" class="fas text-lg transition-all duration-200"></i>
          </button>
          <div class="relative">
              <div id="profile-trigger-mobile" class="flex items-center gap-[2px] bg-indigo-900 dark:bg-gray-700 border border-indigo-500 dark:border-gray-600 rounded-[10px] px-2 py-1 relative cursor-pointer group hover:bg-indigo-700 dark:hover:bg-gray-600" aria-haspopup="true" aria-expanded="false">
                <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($loggedIn ? substr($username, 0, 1) : 'L'); ?>&background=random&color=fff" alt="Profile Avatar" class="w-6 h-6 rounded-full">
                <?php if ($loggedIn): ?>
                  <span class="text-sm flex items-center gap-0.2 leading-none text-indigo-100 dark:text-gray-200 group-hover:text-white dark:group-hover:text-white">
                    <?php echo htmlspecialchars($username); ?>
                    <span class="material-icons-outlined ml-1 profile-chevron text-indigo-100 dark:text-gray-200 group-hover:text-white dark:group-hover:text-white transition-transform duration-200">expand_more</span>
                  </span>
                <?php else: ?>
                  <a href="/login.php" class="text-indigo-100 dark:text-gray-200 px-2 py-1 rounded-full text-sm font-medium hover:text-white dark:hover:text-white leading-none">Login</a>
                <?php endif; ?>
              </div>
              <?php if ($loggedIn): ?>
                <div id="mobile-profile-dropdown" class="profile-dropdown absolute w-40 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg z-50 hidden" role="menu" aria-orientation="vertical" aria-labelledby="profile-trigger-mobile">
                  <a href="/dashboard" class="block px-4 py-2 rounded-md dropdown-link text-gray-700 dark:text-gray-200 hover:bg-indigo-100 hover:text-indigo-900 dark:hover:bg-indigo-900 dark:hover:text-indigo-100" role="menuitem"><span class="material-icons-outlined mr-2">dashboard</span>Dashboard</a>
                  <a href="/edit-user<?php echo isset($currentUserId) ? '?id=' . htmlspecialchars($currentUserId) : ''; ?>" class="block px-4 py-2 rounded-md dropdown-link text-gray-700 dark:text-gray-200 hover:bg-indigo-100 hover:text-indigo-900 dark:hover:bg-indigo-900 dark:hover:text-indigo-100" role="menuitem"><span class="material-icons-outlined mr-2">account_circle</span>Edit Profile</a>
                  <a href="/password" class="block px-4 py-2 rounded-md dropdown-link text-gray-700 dark:text-gray-200 hover:bg-indigo-100 hover:text-indigo-900 dark:hover:bg-indigo-900 dark:hover:text-indigo-100" role="menuitem"><span class="material-icons-outlined mr-2">vpn_key</span>Password</a>
                  <a href="/logout" class="block px-4 py-2 rounded-md dropdown-link text-gray-700 dark:text-gray-200 hover:bg-indigo-100 hover:text-indigo-900 dark:hover:bg-indigo-900 dark:hover:text-indigo-100" role="menuitem"><span class="material-icons-outlined mr-2">logout</span>Logout</a>
                </div>
              <?php endif; ?>
          </div>
      </div>
    </header>

  <!-- Desktop Header (Visible only on desktop, hidden on mobile) -->
  <header class="sticky top-0 hidden md:flex items-center justify-end py-2 w-full h-14 bg-white dark:bg-gray-800 shadow-md z-30 pl-56 pr-4 transition-colors duration-300">
      <!-- Right side: User Info Box with Dropdown and Dark Mode Toggle (for desktop view) -->
      <div class="flex items-center gap-2">
         <!-- Cloud Upload Icon -->
          <a href="/upload-edition" class="flex items-center justify-center w-8 h-8 rounded-full cursor-pointer bg-purple-100 text-purple-700 dark:bg-gray-700 dark:text-gray-300 hover:bg-purple-200 dark:hover:bg-gray-600 transition-colors duration-200">
              <i class="material-icons-outlined text-xl">upload</i>
          </a>
          <!-- Dark Mode Toggle for Desktop -->
          <button id="theme-toggle-desktop" class="flex items-center justify-center w-8 h-8 rounded-full bg-purple-100 text-purple-700 dark:bg-gray-700 dark:text-gray-300 hover:bg-purple-200 dark:hover:bg-gray-600 transition-colors duration-200" aria-label="Toggle dark mode">
              <i id="theme-icon-desktop" class="fas text-lg transition-all duration-200"></i>
          </button>
          <!-- Notifications Icon -->
          <a href="/notifications" class="relative flex items-center justify-center w-8 h-8 rounded-full cursor-pointer bg-purple-100 text-purple-700 dark:bg-gray-700 dark:text-gray-300 hover:bg-purple-200 dark:hover:bg-gray-600 transition-colors duration-200">
              <i class="material-icons-outlined text-xl">notifications</i>
              <!-- Example for a notification badge, remove if not needed -->
              <!-- <span class="absolute top-0 right-0 inline-flex items-center justify-center px-1.5 py-0.5 text-[10px] font-bold leading-none text-red-100 bg-red-600 rounded-full">3</span> -->
          </a>
          <!-- Visit Site Button -->
          <a href="/" target="_blank" rel="noopener noreferrer" class="header-button flex items-center gap-1">
            <span class="material-icons-outlined text-xl">language</span>
            <span class="hidden lg:inline">Visit Site</span>
          </a>

          <!-- Wrapper div for profile dropdown -->
          <div class="relative  border-[1px] border-gray-300 rounded-full" >
              <!-- Profile Trigger Button with username -->
              <div id="profile-trigger-desktop" class="header-button profile-button cursor-pointer" aria-haspopup="true" aria-expanded="false">
                  <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($loggedIn ? substr($username, 0, 1) : 'L'); ?>&background=random&color=fff" alt="Profile Avatar" class="w-6 h-6 border-[2px] border-gray-400 rounded-full">
                  <span class="hidden lg:inline text-sm font-medium leading-none"><?php echo htmlspecialchars($username); ?></span>
                  <span class="material-icons-outlined profile-chevron text-gray-500 dark:text-gray-400 transition-transform duration-200">expand_more</span>
              </div>
              <!-- Dropdown is now a sibling of the trigger button -->
              <?php if ($loggedIn): ?>
                  <div id="desktop-profile-dropdown" class="profile-dropdown absolute w-40 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg z-50 hidden" role="menu" aria-orientation="vertical" aria-labelledby="profile-trigger-desktop">
                      <a href="/dashboard" class="block px-4 py-2 rounded-md dropdown-link text-gray-700 dark:text-gray-200 hover:bg-indigo-100 hover:text-indigo-900 dark:hover:bg-indigo-900 dark:hover:text-indigo-100" role="menuitem"><span class="material-icons-outlined mr-2">dashboard</span>Dashboard</a>
                      <a href="/edit-user<?php echo isset($currentUserId) ? '?id=' . htmlspecialchars($currentUserId) : ''; ?>" class="block px-4 py-2 rounded-md dropdown-link text-gray-700 dark:text-gray-200 hover:bg-indigo-100 hover:text-indigo-900 dark:hover:bg-indigo-900 dark:hover:text-indigo-100" role="menuitem"><span class="material-icons-outlined mr-2">account_circle</span>Edit Profile</a>
                      <a href="/password" class="block px-4 py-2 rounded-md dropdown-link text-gray-700 dark:text-gray-200 hover:bg-indigo-100 hover:text-indigo-900 dark:hover:bg-indigo-900 dark:hover:text-indigo-100" role="menuitem"><span class="material-icons-outlined mr-2">vpn_key</span>Password</a>
                      <a href="/logout" class="block px-4 py-2 rounded-md dropdown-link text-gray-700 dark:text-gray-200 hover:bg-indigo-100 hover:text-indigo-900 dark:hover:bg-indigo-900 dark:hover:text-indigo-100" role="menuitem"><span class="material-icons-outlined mr-2">logout</span>Logout</a>
                  </div>
              <?php endif; ?>
          </div>
      </div>
  </header>

  <!-- Desktop Sidebar (fixed) -->
  <aside
    id="sidebar"
    class="fixed left-0 top-0 flex flex-col w-52 h-full overflow-y-auto text-indigo-300 dark:text-gray-300 bg-indigo-900 dark:bg-gray-900 rounded-none sidebar-scroll transition-transform duration-300 -translate-x-full md:translate-x-0 md:flex-shrink-0 z-50"
    aria-label="Main sidebar navigation"
  >
      <!-- Logo Box -->
      <div class="pb-4">
      <div class="flex items-center w-full px-6 mt-4 mb-4,mx-auto" style="max-width: calc(100% - 1.5rem);">
          <a class="flex items-center w-full" href="/" aria-label="Home">
            <!-- Logo Image -->
            <img
              src="<?php echo htmlspecialchars($logoPath); ?>"
              alt="<?php echo htmlspecialchars(defined('APP_TITLE') ? APP_TITLE . ' Logo' : 'App Logo'); ?>"
              class="h-9 w-auto"
            />
          </a>
                </div>
      </div>

      <nav class="w-full px-2 flex-1">
        <div class="flex flex-col w-full border-t border-gray-600 dark:border-gray-700 pt-2">
          <!-- Dashboard Home -->
          <a
            class="flex items-center w-full h-10 px-3 mt-1 rounded hover:bg-indigo-700 dark:hover:bg-gray-800 transition-colors duration-200 text-sm font-medium text-indigo-100 dark:text-gray-200"
            href="/dashboard"
            aria-label="Dashboard"
          >
            <i class="material-icons text-2xl">dashboard</i>
            <span class="ml-2">Dashboard</span>
          </a>
          <!-- Upload Edition -->
          <a
            class="flex items-center w-full h-10 px-3 mt-1 rounded hover:bg-indigo-700 dark:hover:bg-gray-800 transition-colors duration-200 text-sm font-medium text-indigo-100 dark:text-gray-200"
            href="/upload-edition"
            aria-label="Upload Upload Edition"
          >
            <i class="material-icons text-2xl">cloud_upload</i>
            <span class="ml-2">Upload Edition</span>
          </a>
          <!-- Manage Editions -->
          <a
            class="flex items-center w-full h-10 px-3 mt-1 rounded hover:bg-indigo-700 dark:hover:bg-gray-800 transition-colors duration-200 text-sm font-medium text-indigo-100 dark:text-gray-200"
            href="/manage-editions"
            aria-label="Manage Editions"
          >
            <i class="material-icons text-2xl">article</i>
            <span class="ml-2">Editions</span>
          </a>
          <!-- Categories -->
          <a
            class="flex items-center w-full h-10 px-3 mt-1 rounded hover:bg-indigo-700 dark:hover:bg-gray-800 transition-colors duration-200 text-sm font-medium text-indigo-100 dark:text-gray-200"
            href="/categories"
            aria-label="Categories"
          >
            <i class="material-icons text-2xl">category</i>
            <span class="ml-2">Categories</span>
          </a>
          <!-- Pages -->
          <a
            class="flex items-center w-full h-10 px-3 mt-1 rounded hover:bg-indigo-700 dark:hover:bg-gray-800 transition-colors duration-200 text-sm font-medium text-indigo-100 dark:text-gray-200"
            href="/pages"
            aria-label="Pages"
          >
            <i class="material-icons text-2xl">description</i>
            <span class="ml-2">Pages</span>
          </a>
        </div>

        <div class="flex flex-col w-full mt-2 border-t border-gray-600 dark:border-gray-700 pt-2">
          <!-- Users Dropdown -->
          <div class="relative">
            <a
              id="usersParentLink"
              class="flex items-center w-full h-10 px-3 mt-1 rounded hover:bg-indigo-700 dark:hover:bg-gray-800 transition-colors duration-200 text-sm font-medium text-indigo-100 dark:text-gray-200"
              onclick="toggleDropdown('usersDropdown', 'usersParentLink')"
              aria-expanded="false"
              aria-controls="usersDropdown"
              aria-haspopup="true"
            >
              <i class="material-icons text-2xl">people</i>
              <span class="ml-2">Users</span>
              <i
                class="material-icons text-base ml-auto transform transition-transform duration-200"
                id="usersArrow"
                >arrow_drop_down</i
              >
            </a>
            <div id="usersDropdown" class="dropdown-menu flex flex-col bg-indigo-900 dark:bg-gray-900" role="menu" aria-labelledby="usersParentLink">
              <a
                class="flex items-center w-full rounded hover:bg-indigo-700 dark:hover:bg-gray-800 transition-colors duration-200 text-xs py-2 text-indigo-100 dark:text-gray-200"
                href="/add-user"
                role="menuitem"
                ><i class="material-icons text-xl mr-2">person_add</i>Add User</a
              >
              <a
                class="flex items-center w-full rounded hover:bg-indigo-700 dark:hover:bg-gray-800 transition-colors duration-200 text-xs py-2 text-indigo-100 dark:text-gray-200"
                href="/user-management"
                role="menuitem"
                ><i class="material-icons text-xl mr-2">manage_accounts</i>Edit Users</a
              >
            </div>
          </div>

          <!-- Settings Dropdown -->
          <div class="relative">
            <a
              id="settingsParentLink"
              class="flex items-center w-full h-10 px-3 mt-1 rounded hover:bg-indigo-700 dark:hover:bg-gray-800 transition-colors duration-200 text-sm font-medium text-indigo-100 dark:text-gray-200"
              onclick="toggleDropdown('settingsDropdown', 'settingsParentLink')"
              aria-expanded="false"
              aria-controls="settingsDropdown"
              aria-haspopup="true"
            >
              <i class="material-icons text-2xl">settings</i>
              <span class="ml-2">Settings</span>
              <i
                class="material-icons text-base ml-auto transform transition-transform duration-200"
                id="settingsArrow"
                >arrow_drop_down</i
              >
            </a>
            <div
              id="settingsDropdown"
              class="dropdown-menu flex flex-col bg-indigo-900 dark:bg-gray-900"
              role="menu"
              aria-labelledby="settingsParentLink"
            >
              <a
                class="flex items-center w-full rounded hover:bg-indigo-700 dark:hover:bg-gray-800 transition-colors duration-200 text-xs py-2 text-indigo-100 dark:text-gray-200"
                href="/theme"
                role="menuitem"
                ><i class="material-icons text-xl mr-2">palette</i>Theme</a
              >
              <a
                class="flex items-center w-full rounded hover:bg-indigo-700 dark:hover:bg-gray-800 transition-colors duration-200 text-xs py-2 text-indigo-100 dark:text-gray-200"
                href="/logos-settings"
                role="menuitem"
                ><i class="material-icons text-xl mr-2">image</i>Logos</a
              >

              <a
                class="flex items-center w-full rounded hover:bg-indigo-700 dark:hover:bg-gray-800 transition-colors duration-200 text-xs py-2 text-indigo-100 dark:text-gray-200"
                href="/social-media-settings"
                role="menuitem"
                ><i class="material-icons text-xl mr-2">share</i>Social Links</a
              >
              <a
                class="flex items-center w-full rounded hover:bg-indigo-700 dark:hover:bg-gray-800 transition-colors duration-200 text-xs py-2 text-indigo-100 dark:text-gray-200"
                href="/website-settings"
                role="menuitem"
                ><i class="material-icons text-xl mr-2">web</i>Site Settings</a
              >
              <a
                class="flex items-center w-full rounded hover:bg-indigo-700 dark:hover:bg-gray-800 transition-colors duration-200 text-xs py-2 text-indigo-100 dark:text-gray-200"
                href="/edit-user<?php echo isset($currentUserId) ? '?id=' . htmlspecialchars($currentUserId) : ''; ?>"
                role="menuitem"
                ><i class="material-icons text-xl mr-2">account_circle</i>Edit Profile</a
              >
            </div>
          </div>
        </div>
      </nav>

      <!-- Combined Storage Info Card (Visible on both desktop and mobile) -->
      <div class="w-full pb-2 pt-1 px-2 mt-auto">
        <div class="bg-indigo-800 dark:bg-gray-800 rounded-lg py-3 px-3 flex flex-col justify-center">
          <div class="text-indigo-200 dark:text-gray-300">
            <div class="flex items-center mb-1">
              <i class="material-icons text-xs mr-2 flex-shrink-0">cloud</i>
              <span class="text-xs font-medium whitespace-nowrap flex-grow">Storage</span>
              <span class="text-xs font-medium whitespace-nowrap"><?php echo htmlspecialchars($diskUsagePercentageDisplay); ?></span>
            </div>
            <div class="w-full bg-indigo-900 dark:bg-gray-900 rounded-full h-2 mb-1">
              <div
                class="h-2 rounded-full transition-all duration-300"
                style="width: <?php echo htmlspecialchars($diskUsagePercentage); ?>%; background-color: <?php echo htmlspecialchars($progressBarColor); ?>;"
                role="progressbar"
                aria-valuenow="<?php echo htmlspecialchars($diskUsagePercentage); ?>"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-label="Disk Usage Progress"
              ></div>
            </div>
            <div class="text-xs pt-[2px] font-medium text-center">
              <?php echo htmlspecialchars($diskUsage); ?> / <?php echo htmlspecialchars($totalStorageDisplayFormatted); ?> Used
            </div>
          </div>
        </div>
      </div>
    </aside>

  <!-- Main content area wrapper (this will contain the <main> from ue.php) -->
    <div class="flex-1 md:ml-52 pt-2 md:pt-4 overflow-y-auto">
    <!-- The <main> tag from ue.php will be injected here -->
