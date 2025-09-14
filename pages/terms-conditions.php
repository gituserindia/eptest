<?php
// File: terms-conditions.php
// This page displays the Terms & Conditions of your website.

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
$pageTitle = "Terms & Conditions - " . (defined('APP_TITLE') ? APP_TITLE : 'Default E-Paper');
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
                <i class="fas fa-file-contract text-3xl md:text-5xl animate-subtle-bounce"></i> <!-- Icon for Terms -->
                <h1 class="text-2xl md:text-4xl font-extrabold leading-tight">
                    Our <span class="text-yellow-300">Terms & Conditions</span>
                </h1>
            </div>
            <p class="hidden md:block text-sm md:text-base max-w-2xl mx-auto opacity-90">
                Understanding your rights and responsibilities when using our services.
            </p>
        </div>
    </section>

    <!-- Main Content Section - Styled similarly to about-us.php content blocks -->
    <section class="py-8 md:py-12">
        <div class="container mx-auto px-4 max-w-6xl">
            <div class="bg-white rounded-lg shadow-xl p-4 md:p-8 mb-8 border border-gray-100 transform hover:scale-[1.005] transition-transform duration-300 ease-out">
                <h2 class="text-2xl md:text-3xl font-bold text-blue-700 mb-4 flex items-center">
                    <i class="fas fa-check-circle text-blue-500 mr-2 text-2xl"></i> 1. Acceptance of Terms
                </h2>
                <p class="text-sm md:text-base leading-relaxed mb-4 border-l-4 border-blue-400 pl-3">
                    By accessing or using our website, you agree to be bound by these Terms and Conditions and our Privacy Policy. If you do not agree to these terms, please do not use our services.
                </p>

                <h2 class="text-2xl md:text-3xl font-bold text-purple-700 mb-4 flex items-center">
                    <i class="fas fa-sync-alt text-purple-500 mr-2 text-2xl"></i> 2. Changes to Terms
                </h2>
                <p class="text-sm md:text-base leading-relaxed mb-4 border-l-4 border-purple-400 pl-3">
                    We reserve the right to modify or replace these Terms at any time. We will provide notice of any changes by posting the updated Terms on this page. Your continued use of the service after any such changes constitutes your acceptance of the new Terms.
                </p>

                <h2 class="text-2xl md:text-3xl font-bold text-blue-700 mb-4 flex items-center">
                    <i class="fas fa-shield-alt text-blue-500 mr-2 text-2xl"></i> 3. Privacy Policy
                </h2>
                <p class="text-sm md:text-base leading-relaxed mb-4 border-l-4 border-blue-400 pl-3">
                    Your use of our website is also governed by our Privacy Policy, which is incorporated into these Terms by this reference. Please review our Privacy Policy to understand our practices.
                </p>

                <h2 class="text-2xl md:text-3xl font-bold text-purple-700 mb-4 flex items-center">
                    <i class="fas fa-user-check text-purple-500 mr-2 text-2xl"></i> 4. User Conduct
                </h2>
                <p class="text-sm md:text-base leading-relaxed mb-4 border-l-4 border-purple-400 pl-3">
                    You agree not to use the website for any unlawful purpose or in any way that might harm, abuse, or otherwise interfere with the proper functioning of the website or any other user's enjoyment of the website.
                </p>

                <h2 class="text-2xl md:text-3xl font-bold text-blue-700 mb-4 flex items-center">
                    <i class="fas fa-copyright text-blue-500 mr-2 text-2xl"></i> 5. Intellectual Property
                </h2>
                <p class="text-sm md:text-base leading-relaxed mb-4 border-l-4 border-blue-400 pl-3">
                    All content on this website, including text, graphics, logos, images, and software, is the property of [Your Company Name] or its content suppliers and protected by international copyright laws.
                </p>

                <h2 class="text-2xl md:text-3xl font-bold text-purple-700 mb-4 flex items-center">
                    <i class="fas fa-exclamation-triangle text-purple-500 mr-2 text-2xl"></i> 6. Limitation of Liability
                </h2>
                <p class="text-sm md:text-base leading-relaxed mb-4 border-l-4 border-purple-400 pl-3">
                    In no event shall [Your Company Name], nor its directors, employees, partners, agents, suppliers, or affiliates, be liable for any indirect, incidental, special, consequential or punitive damages, including without limitation, loss of profits, data, use, goodwill, or other intangible losses, resulting from (i) your access to or use of or inability to access or use the Service; (ii) any conduct or content of any third party on the Service; (iii) any content obtained from the Service; and (iv) unauthorized access, use or alteration of your transmissions or content, whether based on warranty, contract, tort (including negligence) or any other legal theory, whether or not we have been informed of the possibility of such damage, and even if a remedy set forth herein is found to have failed of its essential purpose.
                </p>

                <h2 class="text-2xl md:text-3xl font-bold text-blue-700 mb-4 flex items-center">
                    <i class="fas fa-gavel text-blue-500 mr-2 text-2xl"></i> 7. Governing Law
                </h2>
                <p class="text-sm md:text-base leading-relaxed mb-4 border-l-4 border-blue-400 pl-3">
                    These Terms shall be governed and construed in accordance with the laws of [Your Country/State], without regard to its conflict of law provisions.
                </p>

                <h2 class="text-2xl md:text-3xl font-bold text-purple-700 mb-4 flex items-center">
                    <i class="fas fa-headset text-purple-500 mr-2 text-2xl"></i> 8. Contact Us
                </h2>
                <p class="text-sm md:text-base leading-relaxed border-l-4 border-purple-400 pl-3">
                    If you have any questions about these Terms, please contact us at <?php echo APP_EMAIL; ?>.
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
require_once BASE_PATH . '/layout/footer.php';
// The footer file will be included by header.php after $pageContent is echoed
// Ensure your footer.php contains the closing </body> and </html> tags.
?>
