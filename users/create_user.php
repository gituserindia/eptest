<?php
// File: create_user.php
// Handles creation of new user accounts with robust input validation, CSRF protection,
// and inline error display (no full-screen modal pop-up).
// Accessible by 'SuperAdmin' and 'Admin' roles only.

// --- Production Error Reporting ---
ini_set('display_errors', 0); // Set to 0 in production
ini_set('display_startup_errors', 0); // Set to 0 in production
error_reporting(E_ALL); // Still log all errors, but don't display them to the user

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

// Assume BASE_PATH is defined by the router (e.g., in public_html/router.php)
// If BASE_PATH is not defined, this will result in a fatal error,
// indicating that the router setup is incomplete or incorrect.
if (!defined('BASE_PATH')) {
    // Fallback for direct access during development, but ideally router defines this.
    // This assumes create_user.php is in public_html/users/ and public_html is the base.
    define('BASE_PATH', __DIR__ . '/..');
}

// Database connection and other configurations, now using BASE_PATH
require_once BASE_PATH . '/config/config.php';

// Check for PDO connection after including config.php
if (!isset($pdo) || !$pdo instanceof PDO) {
    die('<div style="font-family: sans-serif; padding: 20px; background-color: #fdd; border: 1px solid #f99; color: #c00;">Error: Database connection ($pdo) not properly initialized in config.php.</div>');
}

// Set default timezone to Asia/Kolkata (IST) for consistent date/time handling
date_default_timezone_set('Asia/Kolkata');

// Session Management & Inactivity Timeout
const INACTIVITY_TIMEOUT = 1800; // 30 minutes in seconds
if (isset($_SESSION['user_id']) && !isset($_SESSION['remember_me'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > INACTIVITY_TIMEOUT)) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['message_type'] = 'error'; // Keep for consistency if other pages still use it
        $_SESSION['message_content'] = '<i class="fas fa-hourglass-end mr-2"></i> Session expired due to inactivity. Please log in again.';
        header("Location: /login.php?timeout=1"); // Router-friendly redirect
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// --- INITIAL AUTHORIZATION CHECKS ---
$loggedIn = isset($_SESSION['user_id']);
$username = $loggedIn ? htmlspecialchars($_SESSION['username']) : null; // Define username for headersidebar
$userRole = $loggedIn ? ($_SESSION['user_role'] ?? 'Viewer') : null;

if (!$loggedIn) {
    $_SESSION['message_type'] = 'info'; // Keep for consistency if other pages still use it
    $_SESSION['message_content'] = '<i class="fas fa-info-circle mr-2"></i> Please log in to access this page.';
    header("Location: /login.php"); // Router-friendly redirect
    exit;
}

if ($userRole !== 'SuperAdmin' && $userRole !== 'Admin') {
    $_SESSION['message_type'] = 'error'; // Keep for consistency if other pages still use it
    $_SESSION['message_content'] = '<i class="fas fa-lock mr-2"></i> You do not have the required permissions to access this page.';
    header("Location: /dashboard.php"); // Router-friendly redirect
    exit;
}

$pageTitle = "Create New User";
$errors = []; // Array to store validation errors for individual fields
$old_input = []; // Array to store old input for re-population
$generalMessage = ''; // Variable for inline general messages (error or success)
$generalMessageType = ''; // 'success' or 'error'

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $generalMessageType = 'error';
        $generalMessage = '<i class="fas fa-exclamation-circle mr-2"></i> Invalid CSRF token. Please try again.';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Regenerate token
        // Do NOT populate old_input on CSRF failure for security
        // Redirect to clear POST data and show the error on a clean page load
        header("Location: /users/create_user.php"); // Router-friendly redirect
        exit;
    }

    // Collect and trim input data
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $displayname = trim($_POST['displayname'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $user_role_post = $_POST['user_role'] ?? '';
    $status_post = $_POST['status'] ?? 'pending'; // Default status for new users

    // Store old input to re-populate form in case of errors
    $old_input = [
        'username' => $username,
        'email' => $email,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'displayname' => $displayname,
        'phone_number' => $phone_number,
        'user_role' => $user_role_post,
        'status' => $status_post,
    ];

    // --- Input Validation ---

    // Username validation
    if (empty($username)) {
        $errors['username'] = 'Username is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        $errors['username'] = 'Username must be 3-20 alphanumeric characters or underscores.';
    } else {
        // Check if username already exists
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
            $stmt->execute([':username' => $username]);
            if ($stmt->fetchColumn() > 0) {
                $errors['username'] = 'Username is already taken.';
            }
        } catch (PDOException $e) {
            error_log("Database error checking username uniqueness during creation: " . $e->getMessage());
            $errors['database'] = 'A database error occurred. Please try again.';
        }
    }

    // Email validation
    if (empty($email)) {
        $errors['email'] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Invalid email format.';
    } else {
        // Check if email already exists
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
            $stmt->execute([':email' => $email]);
            if ($stmt->fetchColumn() > 0) {
                $errors['email'] = 'Email is already registered.';
            }
        } catch (PDOException $e) {
            error_log("Database error checking email uniqueness during creation: " . $e->getMessage());
            $errors['database'] = 'A database error occurred. Please try again.';
        }
    }

    // Password validation (required for creation)
    if (empty($password)) {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $errors['password'] = 'Password must contain at least one uppercase letter.';
    } elseif (!preg_match('/[a-z]/', $password)) {
        $errors['password'] = 'Password must contain at least one lowercase letter.';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $errors['password'] = 'Password must contain at least one number.';
    } elseif (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors['password'] = 'Password must contain at least one special character.';
    }

    if (empty($confirm_password)) {
        $errors['confirm_password'] = 'Confirm password is required.';
    } elseif ($password !== $confirm_password) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    // First Name / Last Name / Display Name validation
    if (!empty($first_name) && !preg_match('/^[a-zA-Z\s-]+$/', $first_name)) {
        $errors['first_name'] = 'First name can only contain letters, spaces, and hyphens.';
    }
    if (!empty($last_name) && !preg_match('/^[a-zA-Z\s-]+$/', $last_name)) {
        $errors['last_name'] = 'Last name can only contain letters, spaces, and hyphens.';
    }
    if (!empty($displayname) && !preg_match('/^[a-zA-Z0-9\s._-]+$/', $displayname)) {
        $errors['displayname'] = 'Display name can only contain letters, numbers, spaces, dots, underscores, and hyphens.';
    }

    // Phone Number validation
    if (!empty($phone_number) && !preg_match('/^\+?[0-9\s\-()]{7,20}$/', $phone_number)) {
        $errors['phone_number'] = 'Invalid phone number format.';
    }

    // User Role validation
    $allowedRoles = ['SuperAdmin', 'Admin', 'Editor', 'Viewer'];
    if ($userRole === 'Admin') {
        // Admin can only create users with Editor or Viewer roles
        if (!in_array($user_role_post, ['Editor', 'Viewer'])) {
            $errors['user_role'] = 'As an Admin, you can only create users with Editor or Viewer roles.';
        }
    } elseif ($userRole === 'SuperAdmin') {
        // SuperAdmin can create any role
        if (!in_array($user_role_post, $allowedRoles)) {
            $errors['user_role'] = 'Invalid user role selected.';
        }
    } else {
        // Should not reach here due to initial auth check
        $errors['user_role'] = 'You do not have permission to assign user roles.';
    }

    // Status validation
    $allowedStatuses = ['active', 'suspended', 'locked', 'inactive', 'pending'];
    if (empty($status_post) || !in_array($status_post, $allowedStatuses)) {
        $errors['status'] = 'Invalid status selected.';
    }

    // --- If no validation errors, proceed with user creation ---
    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, first_name, last_name, displayname, phone_number, user_role, status) VALUES (:username, :email, :password_hash, :first_name, :last_name, :displayname, :phone_number, :user_role, :status)");
            $insert_params = [
                ':username' => $username,
                ':email' => $email,
                ':password_hash' => $hashed_password,
                ':first_name' => empty($first_name) ? null : $first_name,
                ':last_name' => empty($last_name) ? null : $last_name,
                ':displayname' => empty($displayname) ? null : $displayname,
                ':phone_number' => empty($phone_number) ? null : $phone_number,
                ':user_role' => $user_role_post,
                ':status' => $status_post,
            ];

            if ($stmt->execute($insert_params)) {
                $generalMessageType = 'success';
                $generalMessage = '<i class="fas fa-check-circle mr-2"></i> User "' . htmlspecialchars($username) . '" created successfully!';
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Regenerate token after successful submission
                // On success, clear old input so form is fresh for next creation
                $old_input = [];
            } else {
                $generalMessageType = 'error';
                $generalMessage = '<i class="fas fa-exclamation-circle mr-2"></i> Failed to create user. Please try again.';
            }

        } catch (PDOException $e) {
            error_log("Database error creating user: " . $e->getMessage());
            $generalMessageType = 'error';
            $generalMessage = '<i class="fas fa-exclamation-circle mr-2"></i> A database error occurred during creation. Please try again.';
        }
    } else {
        // If there are validation errors
        $generalMessageType = 'error';
        $generalMessage = '<i class="fas fa-exclamation-circle mr-2"></i> Please correct the errors below.';
        // old_input is already set to POST data, so form fields will retain values
    }
}

// Display messages that were set from redirects (like session timeout or initial auth failures)
// We will NOT be using these for form submission feedback anymore.
$sessionMessageType = '';
$sessionMessageContent = '';
if (isset($_SESSION['message_type']) && isset($_SESSION['message_content'])) {
    $sessionMessageType = $_SESSION['message_type'];
    $sessionMessageContent = $_SESSION['message_content'];
    unset($_SESSION['message_type']);
    unset($_SESSION['message_content']);
}

?>
<!DOCTYPE html>
<html lang="en" x-data="{
    sidebarOpen: false,
    profileMenuOpen: false,
    mobileProfileMenuOpen: false,
    moreMenuOpen: false,
    showAlert: <?= !empty($generalMessage) ? 'true' : 'false' ?>
}" x-cloak>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Removed jQuery -->
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        [x-cloak] { display: none !important; }
        body { font-family: 'Inter', sans-serif; overflow-x: hidden; }

        .form-input {
            width: 100%;
            padding: 0.625rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 1rem;
            transition: border-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }
        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }
        .error-message {
            color: #ef4444; /* Tailwind red-500 */
            font-size: 0.875rem; /* text-sm */
            margin-top: 0.25rem; /* mt-1 */
        }
        .general-message { /* Combined for both success and error */
            border-radius: 0.5rem;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            text-align: left;
            opacity: 1; /* Default opacity for Alpine transition */
            transition: opacity 0.5s ease-out; /* Smooth fade out */
        }
        .general-message.error {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
        }
        .general-message.success {
            background-color: #dcfce7;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }
        .general-message i {
            margin-right: 0.75rem;
            font-size: 1.2rem;
        }
    </style>
</head>
<body class="bg-gray-50" x-cloak>

<?php
// Include headersidebar.php, now using BASE_PATH
// This assumes headersidebar.php is in public_html/layout/
require_once BASE_PATH . '/layout/headersidebar.php';
?>

<main class="p-[20px] md:py-6 md:px-4 md:ml-64">
    <div class="max-w-full mx-auto py-2">

        <!-- Initial Session Messages (for redirects like timeout, not form submission) -->
        <?php if (!empty($sessionMessageContent)): ?>
            <div class="general-message mb-4 <?= htmlspecialchars($sessionMessageType) ?>"
                 x-data="{ show: true }"
                 x-init="setTimeout(() => show = false, 3000)"
                 x-show="show"
                 x-transition:leave.duration.500ms
            >
                <?= $sessionMessageContent ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow-md overflow-hidden p-6">
            <div class="flex items-center gap-3 mb-6">
                <i class="fas fa-user-plus text-2xl text-blue-600"></i>
                <h1 class="text-2xl font-semibold text-gray-800">Create New User</h1>
            </div>

            <?php if (!empty($generalMessage)): ?>
                <div class="general-message <?= htmlspecialchars($generalMessageType) ?>"
                     x-data="{ show: true }"
                     x-init="setTimeout(() => show = false, 3000)"
                     x-show="show"
                     x-transition:leave.duration.500ms
                >
                    <?= $generalMessage ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="/users/create_user.php" class="space-y-6"> <!-- Router-friendly action -->
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username <span class="text-red-500">*</span></label>
                        <input type="text" name="username" id="username" class="form-input"
                               value="<?= htmlspecialchars($old_input['username'] ?? '') ?>" required>
                        <?php if (isset($errors['username'])): ?>
                            <p class="error-message"><?= htmlspecialchars($errors['username']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                        <input type="email" name="email" id="email" class="form-input"
                               value="<?= htmlspecialchars($old_input['email'] ?? '') ?>" required>
                        <?php if (isset($errors['email'])): ?>
                            <p class="error-message"><?= htmlspecialchars($errors['email']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password <span class="text-red-500">*</span></label>
                        <input type="password" name="password" id="password" class="form-input" required>
                        <?php if (isset($errors['password'])): ?>
                            <p class="error-message"><?= htmlspecialchars($errors['password']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm Password <span class="text-red-500">*</span></label>
                        <input type="password" name="confirm_password" id="confirm_password" class="form-input" required>
                        <?php if (isset($errors['confirm_password'])): ?>
                            <p class="error-message"><?= htmlspecialchars($errors['confirm_password']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="first_name" class="block text-sm font-medium text-gray-700 mb-1">First Name (Optional)</label>
                        <input type="text" name="first_name" id="first_name" class="form-input"
                               value="<?= htmlspecialchars($old_input['first_name'] ?? '') ?>">
                        <?php if (isset($errors['first_name'])): ?>
                            <p class="error-message"><?= htmlspecialchars($errors['first_name']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label for="last_name" class="block text-sm font-medium text-gray-700 mb-1">Last Name (Optional)</label>
                        <input type="text" name="last_name" id="last_name" class="form-input"
                               value="<?= htmlspecialchars($old_input['last_name'] ?? '') ?>">
                        <?php if (isset($errors['last_name'])): ?>
                            <p class="error-message"><?= htmlspecialchars($errors['last_name']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="displayname" class="block text-sm font-medium text-gray-700 mb-1">Display Name (Optional)</label>
                        <input type="text" name="displayname" id="displayname" class="form-input"
                               value="<?= htmlspecialchars($old_input['displayname'] ?? '') ?>">
                        <?php if (isset($errors['displayname'])): ?>
                            <p class="error-message"><?= htmlspecialchars($errors['displayname']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label for="phone_number" class="block text-sm font-medium text-gray-700 mb-1">Phone Number (Optional)</label>
                        <input type="tel" name="phone_number" id="phone_number" class="form-input"
                               value="<?= htmlspecialchars($old_input['phone_number'] ?? '') ?>">
                        <?php if (isset($errors['phone_number'])): ?>
                            <p class="error-message"><?= htmlspecialchars($errors['phone_number']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="user_role" class="block text-sm font-medium text-gray-700 mb-1">User Role <span class="text-red-500">*</span></label>
                        <select name="user_role" id="user_role" class="form-input" required>
                            <option value="">Select Role</option>
                            <?php
                            $availableRoles = [];
                            if ($userRole === 'SuperAdmin') {
                                $availableRoles = ['SuperAdmin', 'Admin', 'Editor', 'Viewer'];
                            } elseif ($userRole === 'Admin') {
                                // Admin can only create Editor or Viewer roles
                                $availableRoles = ['Editor', 'Viewer'];
                            }
                            foreach ($availableRoles as $roleOption): ?>
                                <option value="<?= htmlspecialchars($roleOption) ?>"
                                    <?= ($old_input['user_role'] ?? '') === $roleOption ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($roleOption) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['user_role'])): ?>
                            <p class="error-message"><?= htmlspecialchars($errors['user_role']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status <span class="text-red-500">*</span></label>
                        <select name="status" id="status" class="form-input" required>
                            <?php
                            $allowedStatuses = ['active', 'suspended', 'locked', 'inactive', 'pending'];
                            foreach ($allowedStatuses as $statusOption): ?>
                                <option value="<?= htmlspecialchars($statusOption) ?>"
                                    <?= ($old_input['status'] ?? 'pending') === $statusOption ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(ucfirst($statusOption)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['status'])): ?>
                            <p class="error-message"><?= htmlspecialchars($errors['status']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="flex justify-end space-x-3 mt-6">
                    <a href="/users/user_management.php" class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200">
                        Cancel
                    </a>
                    <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors duration-200">
                        <i class="fas fa-user-plus mr-2"></i> Create User
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>
</body>
</html>
