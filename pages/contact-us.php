<?php
// File: pages/contact-us.php
// This file contains the unique content for the Contact Us page.

// --- HTTP Security Headers ---
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode:block");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Start session at the very beginning
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Include centralized settings and theme variables ---
// Use BASE_PATH for all includes. Assume BASE_PATH is defined by a router or main entry point.
// For this file (contact-us.php) located in /public_html/pages/,
// __DIR__ resolves to /public_html/pages/.
// To set BASE_PATH to /public_html/, we go up one level: __DIR__ . '/..'.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__ . '/..');
}

// Include database configuration
require_once BASE_PATH . '/config/config.php';

require_once BASE_PATH . '/vars/settingsvars.php';
require_once BASE_PATH . '/vars/themevars.php'; // This will load $themeColors
require_once BASE_PATH . '/vars/logovars.php';
require_once BASE_PATH . '/vars/socialmediavars.php';

// Set default timezone from settingsvars.php or fallback
date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Kolkata');

// --- Define Logged-in State and User Role (if applicable, though this is a public view) --
$loggedIn = isset($_SESSION['user_id']); // Using 'user_id' as a more robust check for logged-in status
$username = $loggedIn ? htmlspecialchars($_SESSION['username']) : null;
$userRole = $loggedIn ? ($_SESSION['user_role'] ?? 'Viewer') : null; // Default to Viewer

// Set the page title for the header, using the APP_TITLE constant
$pageTitle = 'Contact Us - ' . (defined('APP_TITLE') ? APP_TITLE : '[Your E-Paper Name]');

// Include the header, which opens the HTML, head, and body tags.
// Corrected path from 'layouts' to 'layout' based on your directory structure.
require_once BASE_PATH . '/layout/header.php';
?>

<main class="flex-grow"> <!-- Added <main> tag to wrap main content and allow it to flex-grow -->
    <!-- Hero Section - Colors changed to match about-us.php, padding adjusted for vertical centering -->
    <section class="bg-gradient-to-r from-blue-600 to-purple-600 text-white py-6 md:py-8 shadow-md flex items-center justify-center">
        <div class="container mx-auto px-4 text-center">
            <!-- Adjusted for icon and heading to be on the same row in mobile view -->
            <div class="flex flex-row items-center justify-center space-x-2 md:space-x-4 mb-2">
                <i class="fas fa-headset text-3xl md:text-5xl animate-subtle-bounce"></i>
                <h1 class="text-2xl md::text-4xl font-extrabold leading-tight">
                    Contact <span class="text-yellow-300"><?php echo APP_TITLE; ?></span>
                </h1>
            </div>
            <!-- Tagline removed for mobile view, only visible on medium screens and up -->
            <p class="hidden md:block text-sm md:text-base max-w-2xl mx-auto opacity-90">
                We're here to help! Reach out to us for any inquiries or feedback.
            </p>
        </div>
    </section>

    <!-- Main Content Section -->
    <section class="py-8 md:py-12">
        <div class="container mx-auto px-4 max-w-6xl">
            <div class="grid grid-cols-1 gap-6 md:gap-8"> <!-- Changed to single column grid -->

                <!-- Get in Touch Section - Now the only column, with combined details -->
                <div class="bg-white p-4 md:p-8 rounded-xl shadow-lg border border-gray-100 transform hover:scale-[1.005] transition-transform duration-300 ease-out">
                    <h2 class="text-2xl md:text-3xl font-bold text-blue-700 mb-4 flex items-center"> <!-- Changed color -->
                        <i class="fas fa-paper-plane text-blue-500 mr-2 text-2xl"></i> Get in Touch
                    </h2>
                    <p class="text-sm md:text-base leading-relaxed mb-4 border-l-4 border-blue-400 pl-3"> <!-- Changed color -->
                        We'd love to hear from you! Please use the contact details below.
                    </p>

                    <div class="space-y-3 text-base mb-6">
                        <div class="flex items-start">
                            <i class="fas fa-envelope text-gray-500 text-lg mr-2 mt-1"></i>
                            <div>
                                <strong class="font-semibold text-gray-900">Email:</strong>
                                <p class="text-gray-700"><?php echo APP_EMAIL; ?></p>
                            </div>
                        </div>
                        <div class="flex items-start">
                            <i class="fas fa-phone-alt text-gray-500 text-lg mr-2 mt-1"></i>
                            <div>
                                <strong class="font-semibold text-gray-900">Phone Number:</strong>
                                <p class="text-gray-700"><?php echo APP_PHONE; ?></p>
                            </div>
                        </div>
                        <div class="flex items-start">
                            <i class="fas fa-map-marker-alt text-gray-500 text-lg mr-2 mt-1"></i>
                            <div>
                                <strong class="font-semibold text-gray-900">Address:</strong>
                                <p class="text-gray-700"><?php echo nl2br(APP_ADDRESS); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Social Media Icons -->
                    <div class="flex items-center mt-6 space-x-3 justify-center md:justify-start">
                        <span class="text-gray-700 font-semibold mr-1">Connect:</span>
                        <!-- Facebook -->
                        <a href="<?php echo APP_FACEBOOK_URL; ?>" target="_blank" rel="noopener noreferrer"
                           class="text-gray-500 hover:text-blue-600 transform hover:scale-125 transition-transform duration-300">
                            <i class="fab fa-facebook-f text-base"></i>
                        </a>
                        <!-- WhatsApp -->
                        <a href="<?php echo APP_WHATSAPP_URL; ?>" target="_blank" rel="noopener noreferrer"
                           class="text-gray-500 hover:text-green-500 transform hover:scale-125 transition-transform duration-300">
                            <i class="fab fa-whatsapp text-base"></i>
                        </a>
                        <!-- Instagram -->
                        <a href="<?php echo APP_INSTAGRAM_URL; ?>" target="_blank" rel="noopener noreferrer"
                           class="text-gray-500 hover:text-pink-500 transform hover:scale-125 transition-transform duration-300">
                            <i class="fab fa-instagram text-base"></i>
                        </a>
                        <!-- YouTube -->
                        <a href="<?php echo APP_YOUTUBE_URL; ?>" target="_blank" rel="noopener noreferrer"
                           class="text-gray-500 hover:text-red-600 transform hover:scale-125 transition-transform duration-300">
                            <i class="fab fa-youtube text-base"></i>
                        </a>
                        <!-- X (formerly Twitter) -->
                        <a href="<?php echo APP_TWITTER_URL; ?>" target="_blank" rel="noopener noreferrer"
                           class="text-gray-500 hover:text-gray-900 transform hover:scale-125 transition-transform duration-300">
                            <i class="fab fa-x-twitter text-base"></i>
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </section>
</main> <!-- Closing <main> tag -->

<?php
// Include the footer, which closes the main, body, and html tags.
// Ensure the path to your footer.php is correct relative to this file.
include BASE_PATH . '/layout/footer.php';
?>
