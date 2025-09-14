<?php
// File: privacy-policies.php
// This page displays the Privacy Policy of your website.

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

// Define BASE_PATH if it's not already defined (e.g., by router.php)
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__ . '/..'); // Adjust this path if your router.php is in a different location relative to this file
}

// Include centralized settings and theme variables
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/vars/settingsvars.php';
require_once BASE_PATH . '/vars/themevars.php'; // This will load $themeColors
require_once BASE_PATH . '/vars/logovars.php';
require_once BASE_PATH . '/vars/socialmediavars.php'; // Added social media vars path

// Set default timezone from settingsvars.php or fallback
date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Kolkata');

// Set page-specific variables for the header
$pageTitle = "Privacy Policy - " . (defined('APP_TITLE') ? APP_TITLE : 'Default E-Paper');
$loggedIn = isset($_SESSION['user_id']); // Assuming session is used for login status
$username = $loggedIn ? ($_SESSION['username'] ?? 'User') : 'Guest';

// Start output buffering to capture content for $pageContent
ob_start();
?>

<main class="flex-grow">
    <!-- Hero Section - Adapted from about-us.php -->
    <section class="bg-gradient-to-r from-blue-600 to-purple-600 text-white py-6 md:py-8 shadow-md flex items-center justify-center">
        <div class="container mx-auto px-4 text-center">
            <div class="flex flex-row items-center justify-center space-x-2 md:space-x-4 mb-2">
                <i class="fas fa-user-shield text-3xl md:text-5xl animate-subtle-bounce"></i> <!-- Icon for Privacy Policy -->
                <h1 class="text-xl md:text-4xl font-extrabold leading-tight">
                    Our <span class="text-yellow-300">Privacy Policy</span>
                </h1>
            </div>
            <p class="hidden md:block text-sm md:text-base max-w-xl mx-auto opacity-90">
                Your privacy is important to us. Learn how we collect, use, and protect your data.
            </p>
        </div>
    </section>

    <!-- Main Content Section - Styled similarly to about-us.php content blocks -->
    <section class="py-8 md:py-12">
        <div class="container mx-auto px-4 max-w-6xl">
            <div class="bg-white rounded-lg shadow-xl p-4 md:p-8 mb-8 border border-gray-100 transform hover:scale-[1.005] transition-transform duration-300 ease-out">
                <h2 class="text-xl md:text-3xl font-bold text-blue-700 mb-4 flex items-center">
                    <i class="fas fa-info-circle text-blue-500 mr-2 text-xl"></i> 1. Information We Collect
                </h2>
                <p class="text-sm md:text-base leading-relaxed mb-4 border-l-4 border-blue-400 pl-3">
                    We collect various types of information to provide and improve our services to you. This may include personal information you provide directly (e.g., name, email address) and data collected automatically (e.g., IP address, browser type, usage patterns).
                </p>

                <h2 class="text-xl md:text-3xl font-bold text-purple-700 mb-4 flex items-center">
                    <i class="fas fa-cogs text-purple-500 mr-2 text-xl"></i> 2. How We Use Your Information
                </h2>
                <p class="text-sm md:text-base leading-relaxed mb-4 border-l-4 border-purple-400 pl-3">
                    Your information is used to operate, maintain, and provide the features and functionality of our website, to personalize your experience, to communicate with you, and to analyze how our services are used.
                </p>

                <h2 class="text-xl md:text-3xl font-bold text-blue-700 mb-4 flex items-center">
                    <i class="fas fa-share-alt text-blue-500 mr-2 text-xl"></i> 3. Sharing Your Information
                </h2>
                <p class="text-sm md:text-base leading-relaxed mb-4 border-l-4 border-blue-400 pl-3">
                    We do not sell or rent your personal information to third parties. We may share your information with trusted service providers who assist us in operating our website and conducting our business, provided they agree to keep this information confidential.
                </p>

                <h2 class="text-xl md:text-3xl font-bold text-purple-700 mb-4 flex items-center">
                    <i class="fas fa-cookie-bite text-purple-500 mr-2 text-xl"></i> 4. Cookies and Tracking Technologies
                </h2>
                <p class="text-sm md:text-base leading-relaxed mb-4 border-l-4 border-purple-400 pl-3">
                    We use cookies and similar tracking technologies to track activity on our service and hold certain information. Cookies are files with a small amount of data which may include an anonymous unique identifier.
                </p>

                <h2 class="text-xl md:text-3xl font-bold text-blue-700 mb-4 flex items-center">
                    <i class="fas fa-lock text-blue-500 mr-2 text-xl"></i> 5. Data Security
                </h2>
                <p class="text-sm md:text-base leading-relaxed mb-4 border-l-4 border-blue-400 pl-3">
                    The security of your data is important to us, but remember that no method of transmission over the Internet, or method of electronic storage is 100% secure. While we strive to use commercially acceptable means to protect your Personal Data, we cannot guarantee its absolute security.
                </p>

                <h2 class="text-xl md:text-3xl font-bold text-purple-700 mb-4 flex items-center">
                    <i class="fas fa-globe text-purple-500 mr-2 text-xl"></i> 6. International Data Transfer
                </h2>
                <p class="text-sm md:text-base leading-relaxed mb-4 border-l-4 border-purple-400 pl-3">
                    Your information, including Personal Data, may be transferred to — and maintained on — computers located outside of your state, province, country or other governmental jurisdiction where the data protection laws may differ than those from your jurisdiction.
                </p>

                <h2 class="text-xl md:text-3xl font-bold text-blue-700 mb-4 flex items-center">
                    <i class="fas fa-user-cog text-blue-500 mr-2 text-xl"></i> 7. Your Data Protection Rights
                </h2>
                <p class="text-sm md:text-base leading-relaxed mb-4 border-l-4 border-blue-400 pl-3">
                    Depending on your location, you may have certain data protection rights, such as the right to access, update, or delete the information we have on you. Please contact us to exercise these rights.
                </p>

                <h2 class="text-xl md:text-3xl font-bold text-purple-700 mb-4 flex items-center">
                    <i class="fas fa-headset text-purple-500 mr-2 text-xl"></i> 8. Contact Us
                </h2>
                <p class="text-sm md:text-base leading-relaxed border-l-4 border-purple-400 pl-3">
                    If you have any questions about this Privacy Policy, please contact us at <?php echo APP_EMAIL; ?>.
                </p>
            </div>
        </div>
    </section>
</main>

<?php
// Capture the output and set it to $pageContent
$pageContent = ob_get_clean();

// Include the header file
require_once BASE_PATH . '/layout/header.php';

// The footer file will be included by header.php after $pageContent is echoed
// Ensure your footer.php contains the closing </body> and </html> tags.
require_once BASE_PATH . '/layout/footer.php';
?>
