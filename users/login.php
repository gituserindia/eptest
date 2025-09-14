<?php
// File: login.php
// Handles user login and session management, using layout.php for presentation.

// --- HTTP Security Headers (Place this at the very top before any output) ---
header("X-Frame-Options: DENY"); // Prevents clickjacking
header("X-Content-Type-Options: nosniff"); // Prevents MIME type sniffing
header("X-XSS-Protection: 1; mode=block"); // Basic XSS protection
header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload"); // HSTS enabled

// Start session
// IMPORTANT: session_set_cookie_params() must be called BEFORE session_start()
// The session_start() call is moved below the cookie params setup.

// Include config.php first to define BASE_PATH and other global settings.
// Assuming config.php is in the 'config/' directory, relative to the root where login.php resides.
include __DIR__ . '/../config/config.php';

// Include settingsvars.php to get OG_IMAGE_PATH and other potential settings
//require_once BASE_PATH . '/../vars/settingsvars.php';
include __DIR__ . '/../vars/settingsvars.php';

// Define constants for session lifetimes
const SESSION_LIFETIME_REMEMBER_ME = 12 * 3600; // 12 hours in seconds
const SESSION_LIFETIME_REGULAR = 0; // Session cookie expires when browser closes

// Determine session cookie lifetime
$cookieLifetime = (isset($_POST['remember_me']) && $_POST['remember_me'] === 'on') ? SESSION_LIFETIME_REMEMBER_ME : SESSION_LIFETIME_REGULAR;

// Set session cookie parameters BEFORE starting the session
session_set_cookie_params([
    'lifetime' => $cookieLifetime,
    'path' => '/',
    'domain' => '', // Set your domain here (e.g., 'yourwebsite.com') or leave empty for current host
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', // True if using HTTPS
    'httponly' => true, // Prevents JavaScript access to the session cookie
    'samesite' => 'Lax' // 'Lax' or 'Strict' for CSRF protection
]);

// Start session - This must be AFTER session_set_cookie_params()
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Global variables for the layout
// These will be passed to header.php and footer.php
$loggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true; // Correctly check for 'loggedin'
$username = $loggedIn ? htmlspecialchars($_SESSION['username'] ?? 'Guest') : 'Guest';
$pageTitle = "Login - " . (defined('APP_TITLE') ? APP_TITLE : 'My App'); // Page title for SEO and OG:title
$pageDescription = "Log in to your account to access the dashboard and manage your profile."; // Page description for SEO and OG:description
$pageKeywords = "login, account, sign in, dashboard, user, secure"; // Keywords for SEO

// Set the OG Image Path from settingsvars.php
if (defined('OG_IMAGE_PATH')) {
    $ogImagePath = OG_IMAGE_PATH;
} elseif (isset($OG_IMAGE_PATH)) {
    $ogImagePath = $OG_IMAGE_PATH;
} else {
    $ogImagePath = 'https://placehold.co/1200x630/cccccc/333333?text=Default+OG+Image'; // Fallback if not defined
}

// Get current URL for OG:url
$currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";


// Redirect if already logged in (using router-friendly path)
if ($loggedIn) {
    header("Location: /dashboard"); // Redirect to dashboard via router
    exit;
}

// The $pdo object is now expected to be available from the included config.php.
// Removed the redundant PDO connection block as it's handled by config.php.
// If config.php somehow fails to provide $pdo, the script will naturally error out,
// which is better than a silent fallback that might use incorrect credentials.

$message = ''; // To store login messages

// --- IP Rate Limiting Configuration ---
const IP_MAX_FAILED_ATTEMPTS = 10;
const IP_ATTEMPT_WINDOW = 5 * 60; // 5 minutes

// Function to log IP attempts
function log_ip_attempt($pdo, $ip_address, $is_success = false) {
    try {
        $stmt = $pdo->prepare("INSERT INTO ip_login_attempts (ip_address, attempt_time, is_success) VALUES (:ip, NOW(), :is_success)");
        $stmt->execute([':ip' => $ip_address, ':is_success' => $is_success]);
    } catch (PDOException $e) {
        error_log("Error logging IP attempt: " . $e->getMessage());
    }
}

// Function to clean old IP attempts records
function clean_old_ip_attempts($pdo, $window) {
    try {
        $stmt = $pdo->prepare("DELETE FROM ip_login_attempts WHERE attempt_time < NOW() - INTERVAL :window SECOND");
        $stmt->execute([':window' => $window]);
    } catch (PDOException $e) {
        error_log("Error cleaning old IP attempts: " . $e->getMessage());
    }
}

// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    $current_ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    // 1. Perform IP rate limiting check
    clean_old_ip_attempts($pdo, IP_ATTEMPT_WINDOW);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ip_login_attempts WHERE ip_address = :ip AND attempt_time > NOW() - INTERVAL :window SECOND AND is_success = FALSE");
    $stmt->execute([':ip' => $current_ip, ':window' => IP_ATTEMPT_WINDOW]);
    $failed_ip_attempts = $stmt->fetchColumn();

    if ($failed_ip_attempts >= IP_MAX_FAILED_ATTEMPTS) {
        $message = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Too many failed login attempts from your IP address. Please try again later.</div>';
        error_log("IP rate limit hit for: " . $current_ip . " with identifier: " . $identifier);
        sleep(2);
    } elseif (empty($identifier) || empty($password)) {
        $message = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Please enter both your username/email and password.</div>';
    } else {
        try {
            // 2. Input Validation
            $field = 'username';
            if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                $field = 'email';
            } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
                $message = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Invalid username format.</div>';
                log_ip_attempt($pdo, $current_ip, false);
            }

            // 3. Fetch user data
            $stmt = $pdo->prepare("SELECT user_id, username, email, password, user_role, status, failed_login_attempts, last_failed_login_at, last_login_ip, last_login_user_agent FROM users WHERE {$field} = :identifier LIMIT 1");
            $stmt->execute([':identifier' => $identifier]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // --- User-Specific Account Status and Lockout Logic ---
                $maxLoginAttempts = 3;
                $lockoutDuration = 5 * 60; // 5 minutes

                $processLogin = false;
                switch ($user['status']) {
                    case 'suspended':
                        $message = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Your account has been suspended. Please contact support.</div>';
                        error_log("Suspended account login attempt for user_id: " . $user['user_id'] . " from IP: " . $current_ip);
                        log_ip_attempt($pdo, $current_ip, false);
                        break;
                    case 'inactive':
                        $message = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Your account is inactive. Please contact support.</div>';
                        error_log("Inactive account login attempt for user_id: " . $user['user_id'] . " from IP: " . $current_ip);
                        log_ip_attempt($pdo, $current_ip, false);
                        break;
                    case 'locked':
                        if (strtotime($user['last_failed_login_at']) + $lockoutDuration > time()) {
                            $remainingTime = (strtotime($user['last_failed_login_at']) + $lockoutDuration) - time();
                            $message = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Your account is locked due to too many failed attempts. Please try again in ' . ceil($remainingTime / 60) . ' minutes.</div>';
                            error_log("Locked account login attempt during lockout for user_id: " . $user['user_id'] . " from IP: " . $current_ip);
                            log_ip_attempt($pdo, $current_ip, false);
                        } else {
                            // Lockout period expired, reset attempts and status, then proceed
                            $updateStmt = $pdo->prepare("UPDATE users SET failed_login_attempts = 0, status = 'active', last_failed_login_at = NULL WHERE user_id = :user_id");
                            $updateStmt->execute([':user_id' => $user['user_id']]);
                            $processLogin = true;
                        }
                        break;
                    case 'active':
                    default:
                        $processLogin = true;
                        break;
                }

                if ($processLogin) {
                    if (password_verify($password, $user['password'])) {
                        // Password is correct!
                        $ipAddress = $current_ip;
                        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN', 0, 255);

                        $updateStmt = $pdo->prepare("
                            UPDATE users SET
                                last_login = NOW(),
                                last_login_ip = :ip,
                                last_login_user_agent = :ua,
                                last_activity = NOW(),
                                failed_login_attempts = 0,
                                last_failed_login_at = NULL,
                                status = 'active'
                            WHERE user_id = :user_id
                        ");
                        $updateStmt->execute([
                            ':ip' => $ipAddress,
                            ':ua' => $userAgent,
                            ':user_id' => $user['user_id']
                        ]);
                        log_ip_attempt($pdo, $ipAddress, true);

                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $user['user_id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['user_role'] = $user['user_role'];
                        $_SESSION['last_activity'] = time();
                        $_SESSION['loggedin'] = true; // Set the 'loggedin' flag

                        if (isset($_POST['remember_me']) && $_POST['remember_me'] === 'on') {
                            $_SESSION['remember_me'] = true;
                        } else {
                            unset($_SESSION['remember_me']);
                        }

                        header("Location: /dashboard"); // Redirect to dashboard via router
                        exit;
                    } else {
                        // Incorrect password - increment failed login attempts
                        $newAttempts = $user['failed_login_attempts'] + 1;
                        $updateStmt = $pdo->prepare("UPDATE users SET failed_login_attempts = :attempts, last_failed_login_at = NOW() WHERE user_id = :user_id");
                        $updateStmt->execute([
                            ':attempts' => $newAttempts,
                            ':user_id' => $user['user_id']
                        ]);
                        log_ip_attempt($pdo, $current_ip, false);

                        if ($newAttempts >= $maxLoginAttempts) {
                            $updateStmt = $pdo->prepare("UPDATE users SET status = 'locked' WHERE user_id = :user_id");
                            $updateStmt->execute([':user_id' => $user['user_id']]);
                            $message = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Too many failed login attempts. Your account has been locked for 5 minutes. Please try again later.</div>';
                            error_log("Account locked for user_id: " . $user['user_id'] . " after " . $newAttempts . " failed attempts from IP: " . $current_ip);
                        } else {
                            $message = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Invalid username/email or password.</div>';
                            error_log("Failed login attempt for user_id: " . $user['user_id'] . ". Attempts: " . $newAttempts . " from IP: " . $current_ip);
                        }
                    }
                }
            } else {
                // User not found - generic error message
                $message = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Invalid username/email or password.</div>';
                error_log("Failed login attempt for non-existent identifier: '" . $identifier . "' from IP: " . $current_ip);
                log_ip_attempt($pdo, $current_ip, false);
                sleep(1); // Delay for enumeration protection
            }
        } catch (PDOException $e) {
            error_log("Login database error: " . $e->getMessage());
            $message = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> A server error occurred. Please try again later.</div>';
        }
    }
}

ob_start();
?>
<div class="login-container">
    <div class="login-header">
        <i class="fas fa-sign-in-alt text-5xl text-blue-600 mb-4"></i>
        <h1>Welcome Back!</h1>
        <p>Sign in to your account</p>
    </div>

    <?php
    if (isset($_SESSION['message'])) {
        echo $_SESSION['message'];
        unset($_SESSION['message']);
    }
    echo $message;
    ?>

    <form action="/users/login.php" method="POST"> <!-- Action updated to router-friendly path -->
        <div class="form-group">
            <label for="identifier">Username or Email</label>
            <input type="text" id="identifier" name="identifier" class="form-control" placeholder="Enter your username or email" value="<?php echo htmlspecialchars($_POST['identifier'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" required>
        </div>

        <div class="form-group flex items-center justify-between mt-4">
            <div class="flex items-center">
                <input type="checkbox" id="remember_me" name="remember_me" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded" <?php echo isset($_POST['remember_me']) && $_POST['remember_me'] === 'on' ? 'checked' : ''; ?>>
                <label for="remember_me" class="ml-2 block text-sm text-gray-900">Stay logged in</label>
            </div>
           </div>

        <button type="submit" class="btn-primary">
            <i class="fas fa-sign-in-alt mr-2"></i> Log In
        </button>
    </form>
<style>
    .login-container {
        background: white;
        padding: 2rem;
        border-radius: 1rem;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        max-width: 380px; /* Reduced max-width for compactness */
        width: 100%;
        margin: 2rem auto;
    }

    @media (max-width: 768px) {
        .login-container {
            padding: 1.5rem;
            margin: 1rem auto;
            max-width: 90%; /* Adjust for smaller screens to ensure it doesn't get too wide */
        }
        .login-header h1 {
            font-size: 1.75rem;
        }
        .login-header p {
            font-size: 0.9rem;
        }
    }

    .login-header {
        text-align: center;
        margin-bottom: 1.5rem;
    }
    .login-header h1 {
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 0.5rem;
    }
    .login-header p {
        color: #6b7280;
    }
    .form-group {
        margin-bottom: 1rem;
    }
    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 600;
        color: #374151;
    }
    .form-control {
        width: 100%;
        padding: 0.5rem 1rem; /* Reduced vertical padding for compactness */
        border: 1px solid #d1d5db;
        border-radius: 0.5rem;
        font-size: 1rem;
        transition: border-color 0.2s;
    }
    .form-control:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
    }
    .btn-primary {
        background-color: #3b82f6;
        color: white;
        border: none;
        border-radius: 0.5rem;
        padding: 0.75rem 1.5rem;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        width: 100%;
    }
    .btn-primary:hover {
        background-color: #2563eb;
    }

    .form-group .flex.items-center input[type="checkbox"] {
        vertical-align: middle;
        margin: 0;
        padding: 0;
    }

    .form-group .flex.items-center label {
        vertical-align: middle;
        line-height: 1rem;
    }

    /* Alert styles */
    .alert {
        padding: 0.75rem 1rem;
        margin-bottom: 1rem;
        border-radius: 0.5rem;
        display: flex;
        align-items: center;
        font-size: 0.9rem;
        font-weight: 500;
    }
    .alert-error {
        background-color: #fee2e2; /* Red-100 */
        color: #dc2626; /* Red-600 */
        border: 1px solid #fca5a5; /* Red-300 */
    }
    .alert-success {
        background-color: #dcfce7; /* Green-100 */
        color: #16a34a; /* Green-600 */
        border: 1px solid #86efac; /* Green-300 */
    }
    .alert i {
        margin-right: 0.75rem;
    }
</style>
<?php
$pageContent = ob_get_clean();
// Pass variables to header.php and footer.php
// Use BASE_PATH for includes now that config.php is included at the top
include BASE_PATH . '/layout/header.php';
include BASE_PATH . '/layout/footer.php';
?>
