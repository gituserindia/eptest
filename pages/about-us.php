<?php
// File: pages/about-us.php
// This file contains the unique content for the About Us page.

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
// For this file (about-us.php) located in /public_html/pages/,
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
$pageTitle = 'About Us - ' . (defined('APP_TITLE') ? APP_TITLE : '[Your E-Paper Name]');

// Include the header, which opens the HTML, head, and body tags.
// Corrected path from 'layouts' to 'layout' based on your directory structure.
require_once BASE_PATH . '/layout/header.php';
?>

<main class="flex-grow"> <!-- Added <main> tag to wrap main content and allow it to flex-grow -->
    <!-- Hero Section - Padding adjusted to allow vertical centering of content within this section -->
    <section class="bg-gradient-to-r from-blue-600 to-purple-600 text-white py-6 md:py-8 shadow-md flex items-center justify-center">
        <div class="container mx-auto px-4 text-center">
            <!-- Adjusted for icon and heading to be on the same row in mobile view -->
            <div class="flex flex-row items-center justify-center space-x-2 md:space-x-4 mb-2">
                <i class="fas fa-newspaper text-3xl md:text-5xl animate-subtle-bounce"></i>
                <h1 class="text-2xl md::text-4xl font-extrabold leading-tight">
                    About <span class="text-yellow-300"><?php echo APP_TITLE; ?></span>
                </h1>
            </div>
            <!-- Tagline removed for mobile view, only visible on medium screens and up -->
            <p class="hidden md:block text-sm md:text-base max-w-2xl mx-auto opacity-90">
                Providing timely news and insightful perspectives. Empowering our society.
            </p>
        </div>
    </section>

    <!-- Main Content Section -->
    <section class="py-8 md:py-12">
        <div class="container mx-auto px-4 max-w-6xl">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 md:gap-8">

                <!-- Our Mission Section - Left Column on Desktop -->
                <div class="bg-white p-4 md:p-8 rounded-xl shadow-lg border border-gray-100 transform hover:scale-[1.005] transition-transform duration-300 ease-out">
                    <h2 class="text-2xl md:text-3xl font-bold text-blue-700 mb-4 flex items-center">
                        <i class="fas fa-info-circle text-blue-500 mr-2 text-2xl"></i> Our Mission
                    </h2>
                    <p class="text-sm md:text-base leading-relaxed mb-0 border-l-4 border-blue-400 pl-3">
                        A newspaper is more than just pages of ink and paper; it's the heartbeat of a society, a chronicle of our times, and a window to the world. For centuries, newspapers have served as vital pillars of democracy and informed citizenry, delivering essential news, insightful commentary, and diverse perspectives. They connect us to events near and far, spark conversations, and hold power accountable. In an ever-evolving media landscape, the core mission of a newspaper remains steadfast: to inform, educate, and inspire.
                    </p>
                </div>

                <!-- Our Details Section - Right Column on Desktop -->
                <div class="bg-white p-4 md:p-8 rounded-xl shadow-lg border border-gray-100 transform hover:scale-[1.005] transition-transform duration-300 ease-out">
                    <h2 class="text-2xl md::text-3xl font-bold text-purple-700 mb-4 flex items-center">
                        <i class="fas fa-building text-purple-500 mr-2 text-2xl"></i> Our Details
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-y-3 md:gap-y-4 md:gap-x-6 text-base">
                        <div class="flex items-start">
                            <i class="fas fa-fingerprint text-gray-500 text-lg mr-2 mt-1 transform hover:rotate-12 transition-transform duration-300"></i>
                            <div>
                                <strong class="font-semibold text-gray-900">E-Paper Name:</strong>
                                <p class="text-gray-700"><?php echo APP_TITLE; ?></p>
                            </div>
                        </div>
                        <div class="flex items-start">
                            <i class="fas fa-user-edit text-gray-500 text-lg mr-2 mt-1 transform hover:rotate-12 transition-transform duration-300"></i>
                            <div>
                                <strong class="font-semibold text-gray-900">Editor Name:</strong>
                                <p class="text-gray-700"><?php echo APP_EDITOR_NAME; ?></p>
                            </div>
                        </div>
                        <div class="flex items-start">
                            <i class="fas fa-map-marker-alt text-gray-500 text-lg mr-2 mt-1 transform hover:rotate-12 transition-transform duration-300"></i>
                            <div>
                                <strong class="font-semibold text-gray-900">Address:</strong>
                                <p class="text-gray-700"><?php echo nl2br(APP_ADDRESS); ?></p>
                            </div>
                        </div>
                        <div class="flex items-start">
                            <i class="fas fa-phone-alt text-gray-500 text-lg mr-2 mt-1 transform hover:rotate-12 transition-transform duration-300"></i>
                            <div>
                                <strong class="font-semibold text-gray-900">Phone Number:</strong>
                                <p class="text-gray-700"><?php echo APP_PHONE; ?></p>
                            </div>
                        </div>
                        <div class="flex items-start col-span-1 md:col-span-2">
                            <i class="fas fa-envelope text-gray-500 text-lg mr-2 mt-1 transform hover:rotate-12 transition-transform duration-300"></i>
                            <div>
                                <strong class="font-semibold text-gray-900">Email:</strong>
                                <p class="text-gray-700"><?php echo APP_EMAIL; ?></p>
                            </div>
                        </div>

                        <!-- Social Media Icons below Email -->
                        <div class="flex items-center col-span-1 md:col-span-2 mt-3 space-x-3 justify-center md:justify-start">
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
        </div>
    </section>
</main> <!-- Closing <main> tag -->
<br>
<br>

<?php
// Include the footer, which closes the main, body, and html tags.
// Ensure the path to your footer.php is correct relative to this file.
include BASE_PATH . '/layout/footer.php';
?>