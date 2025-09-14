<?php
// File: edit_user.php
// Handles editing of existing user accounts with robust input validation.
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
    // This assumes edit_user.php is in public_html/users/ and public_html is the base.
    define('BASE_PATH', __DIR__ . '/..');
}

require_once BASE_PATH . '/config/config.php'; // Database connection and other configurations

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
        $_SESSION['message_type'] = 'error';
        $_SESSION['message_content'] = '<i class="fas fa-hourglass-end mr-2"></i> Session expired due to inactivity. Please log in again.';
        header("Location: /login.php?timeout=1"); // Router-friendly redirect
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// --- INITIAL AUTHORIZATION CHECKS ---
$loggedIn = isset($_SESSION['user_id']);
$userRole = $loggedIn ? ($_SESSION['user_role'] ?? 'Viewer') : null;
$currentUserId = $_SESSION['user_id'] ?? null;

if (!$loggedIn) {
    $_SESSION['message_type'] = 'info';
    $_SESSION['message_content'] = '<i class="fas fa-info-circle mr-2"></i> Please log in to access this page.';
    header("Location: /login.php"); // Router-friendly redirect
    exit;
}

if ($userRole !== 'SuperAdmin' && $userRole !== 'Admin') {
    $_SESSION['message_type'] = 'error';
    $_SESSION['message_content'] = '<i class="fas fa-lock mr-2"></i> You do not have the required permissions to access this page.';
    header("Location: /dashboard.php"); // Router-friendly redirect
    exit;
}

$pageTitle = "Edit User";
$errors = []; // Array to store validation errors
$user_data = []; // Array to store fetched user data or old input

// Get user ID from URL
$user_id_to_edit = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;

// If no user ID provided or invalid, redirect
if ($user_id_to_edit === 0) {
    $_SESSION['message_type'] = 'error';
    $_SESSION['message_content'] = '<i class="fas fa-exclamation-circle mr-2"></i> No user ID provided for editing.';
    header("Location: /users/user_management.php"); // Router-friendly redirect
    exit;
}

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Fetch user data for pre-filling the form
try {
    $stmt = $pdo->prepare("SELECT user_id, username, email, first_name, last_name, displayname, phone_number, user_role, status FROM users WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $user_id_to_edit]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user_data) {
        $_SESSION['message_type'] = 'error';
        $_SESSION['message_content'] = '<i class="fas fa-exclamation-circle mr-2"></i> User not found.';
        header("Location: /users/user_management.php"); // Router-friendly redirect
        exit;
    }

    // Authorization check: Admin cannot edit SuperAdmin or another Admin (unless it's themself)
    if ($userRole === 'Admin') {
        if ($user_data['user_role'] === 'SuperAdmin' && (int)$user_id_to_edit !== (int)$currentUserId) {
            $_SESSION['message_type'] = 'error';
            $_SESSION['message_content'] = '<i class="fas fa-lock mr-2"></i> As an Admin, you cannot edit a SuperAdmin account unless it\'s your own.';
            header("Location: /users/user_management.php"); // Router-friendly redirect
            exit;
        }
        // Admin cannot edit another Admin's account
        if ($user_data['user_role'] === 'Admin' && (int)$user_id_to_edit !== (int)$currentUserId) {
            $_SESSION['message_type'] = 'error';
            $_SESSION['message_content'] = '<i class="fas fa-lock mr-2"></i> As an Admin, you cannot edit another Admin\'s account.';
            header("Location: /users/user_management.php"); // Router-friendly redirect
            exit;
        }
    }

} catch (PDOException $e) {
    error_log("Database error fetching user for edit: " . $e->getMessage());
    $_SESSION['message_type'] = 'error';
    $_SESSION['message_content'] = '<i class="fas fa-exclamation-circle mr-2"></i> Error loading user data.';
    header("Location: /users/user_management.php"); // Router-friendly redirect
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $_SESSION['message_type'] = 'error';
        $_SESSION['message_content'] = '<i class="fas fa-exclamation-circle mr-2"></i> Invalid CSRF token. Please try again.';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Regenerate token
        header("Location: /users/edit_user.php?id=" . $user_id_to_edit); // Router-friendly redirect back to refresh token
        exit;
    }

    // Collect and trim input data
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? ''; // New password (optional)
    $confirm_password = $_POST['confirm_password'] ?? ''; // Confirm new password (optional)
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $displayname = trim($_POST['displayname'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $user_role_post = $_POST['user_role'] ?? ''; // Role submitted via form
    $status_post = $_POST['status'] ?? ''; // Status submitted via form

    // Use current user data as default if form fields are empty or not submitted
    // This is important because not all fields might be changed
    $updated_fields = $user_data; // Start with current data

    // Overwrite with POST data if provided
    $updated_fields['username'] = $username;
    $updated_fields['email'] = $email;
    $updated_fields['first_name'] = $first_name;
    $updated_fields['last_name'] = $last_name;
    $updated_fields['displayname'] = $displayname;
    $updated_fields['phone_number'] = $phone_number;
    $updated_fields['user_role'] = $user_role_post;
    $updated_fields['status'] = $status_post;

    // --- Input Validation ---

    // Username validation
    if (empty($username)) {
        $errors['username'] = 'Username is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        $errors['username'] = 'Username must be 3-20 alphanumeric characters or underscores.';
    } else {
        // Check if username already exists for *other* users
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username AND user_id != :current_user_id");
            $stmt->execute([':username' => $username, ':current_user_id' => $user_id_to_edit]);
            if ($stmt->fetchColumn() > 0) {
                $errors['username'] = 'Username is already taken by another user.';
            }
        } catch (PDOException $e) {
            error_log("Database error checking username uniqueness during edit: " . $e->getMessage());
            $errors['database'] = 'A database error occurred. Please try again.';
        }
    }

    // Email validation
    if (empty($email)) {
        $errors['email'] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Invalid email format.';
    } else {
        // Check if email already exists for *other* users
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :email AND user_id != :current_user_id");
            $stmt->execute([':email' => $email, ':current_user_id' => $user_id_to_edit]);
            if ($stmt->fetchColumn() > 0) {
                $errors['email'] = 'Email is already registered by another user.';
            }
        } catch (PDOException $e) {
            error_log("Database error checking email uniqueness during edit: " . $e->getMessage());
            $errors['database'] = 'A database error occurred. Please try again.';
        }
    }

    // Password validation (only if new password is provided)
    $update_password = false;
    $hashed_password = null;
    if (!empty($password)) {
        $update_password = true;
        if (strlen($password) < 8) {
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
            $errors['confirm_password'] = 'Confirm new password is required.';
        } elseif ($password !== $confirm_password) {
            $errors['confirm_password'] = 'New passwords do not match.';
        }

        if (!isset($errors['password']) && !isset($errors['confirm_password'])) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        }
    } elseif (!empty($confirm_password)) {
        // If confirm_password is set but password isn't
        $errors['password'] = 'Please enter a new password if confirming.';
    }

    // First Name / Last Name / Display Name validation (optional, allow only letters, spaces, hyphens)
    if (!empty($first_name) && !preg_match('/^[a-zA-Z\s-]+$/', $first_name)) {
        $errors['first_name'] = 'First name can only contain letters, spaces, and hyphens.';
    }
    if (!empty($last_name) && !preg_match('/^[a-zA-Z\s-]+$/', $last_name)) {
        $errors['last_name'] = 'Last name can only contain letters, spaces, and hyphens.';
    }
    if (!empty($displayname) && !preg_match('/^[a-zA-Z0-9\s._-]+$/', $displayname)) {
        $errors['displayname'] = 'Display name can only contain letters, numbers, spaces, dots, underscores, and hyphens.';
    }

    // Phone Number validation (optional, basic numeric check, adjust regex for specific formats like +1 (XXX) XXX-XXXX)
    if (!empty($phone_number) && !preg_match('/^\+?[0-9\s\-()]{7,20}$/', $phone_number)) {
        $errors['phone_number'] = 'Invalid phone number format.';
    }

    // User Role validation
    $allowedRoles = ['SuperAdmin', 'Admin', 'Editor', 'Viewer'];
    // If the current user is Admin, they cannot change a user's role to SuperAdmin
    // They also cannot change another Admin's role.
    if ($userRole === 'Admin') {
        // If the user being edited is SuperAdmin and current user is Admin, cannot change their role.
        if ($user_data['user_role'] === 'SuperAdmin') {
             if ($user_role_post !== 'SuperAdmin') { // If admin tries to change SuperAdmin's role
                 $errors['user_role'] = 'As an Admin, you cannot change a SuperAdmin\'s role.';
             }
        } else {
             // Admin can only assign Editor or Viewer roles, cannot assign Admin to others
            if (!in_array($user_role_post, ['Editor', 'Viewer']) && (int)$user_id_to_edit !== (int)$currentUserId) {
                $errors['user_role'] = 'As an Admin, you can only assign Editor or Viewer roles to other users.';
            }
            // Admin can't change their own role to SuperAdmin
            if ((int)$user_id_to_edit === (int)$currentUserId && $user_role_post === 'SuperAdmin') {
                $errors['user_role'] = 'You cannot change your own role to SuperAdmin.';
            }
        }
    } elseif ($userRole === 'SuperAdmin') {
        // SuperAdmin can change to any role
        if (!in_array($user_role_post, $allowedRoles)) {
            $errors['user_role'] = 'Invalid user role selected.';
        }
    } else {
        // Should not reach here due to initial auth check, but good for robustness
        $errors['user_role'] = 'You do not have permission to change user roles.';
    }


    // Status validation
    $allowedStatuses = ['active', 'suspended', 'locked', 'inactive'];
    if (empty($status_post) || !in_array($status_post, $allowedStatuses)) {
        $errors['status'] = 'Invalid status selected.';
    }

    // --- If no validation errors, proceed with user update ---
    if (empty($errors)) {
        try {
            $update_sql = "UPDATE users SET username = :username, email = :email, first_name = :first_name, last_name = :last_name, displayname = :displayname, phone_number = :phone_number, user_role = :user_role, status = :status";
            $update_params = [
                ':username' => $updated_fields['username'],
                ':email' => $updated_fields['email'],
                ':first_name' => empty($updated_fields['first_name']) ? null : $updated_fields['first_name'],
                ':last_name' => empty($updated_fields['last_name']) ? null : $updated_fields['last_name'],
                ':displayname' => empty($updated_fields['displayname']) ? null : $updated_fields['displayname'],
                ':phone_number' => empty($updated_fields['phone_number']) ? null : $updated_fields['phone_number'],
                ':user_role' => $updated_fields['user_role'],
                ':status' => $updated_fields['status'],
                ':user_id' => $user_id_to_edit
            ];

            if ($update_password) {
                $update_sql .= ", password = :password_hash"; // Changed password_hash to password to match schema
                $update_params[':password_hash'] = $hashed_password;
            }

            $update_sql .= " WHERE user_id = :user_id";

            $stmt = $pdo->prepare($update_sql);
            if ($stmt->execute($update_params)) {
                $_SESSION['message_type'] = 'success';
                $_SESSION['message_content'] = '<i class="fas fa-check-circle mr-2"></i> User "' . htmlspecialchars($user_data['username']) . '" updated successfully!';
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Regenerate token after successful submission

                // If the currently logged-in user updated their own role, update session
                if ((int)$user_id_to_edit === (int)$currentUserId && $userRole !== $updated_fields['user_role']) {
                    $_SESSION['user_role'] = $updated_fields['user_role'];
                }
                header("Location: /users/user_management.php"); // Router-friendly redirect
                exit;
            } else {
                $_SESSION['message_type'] = 'error';
                $_SESSION['message_content'] = '<i class="fas fa-exclamation-circle mr-2"></i> Failed to update user.';
            }

        } catch (PDOException $e) {
            error_log("Database error updating user: " . $e->getMessage());
            $_SESSION['message_type'] = 'error';
            $_SESSION['message_content'] = '<i class="fas fa-exclamation-circle mr-2"></i> A database error occurred during update.';
        }
    } else {
        // If there are errors, set a general error message and keep old input for display
        $_SESSION['message_type'] = 'error';
        $_SESSION['message_content'] = '<i class="fas fa-exclamation-circle mr-2"></i> Please correct the errors below.';
        // If there are validation errors, ensure the form displays the POSTed values
        $user_data = $updated_fields;
    }
}

// Display messages that were set during the form submission or redirects
$messageModalType = '';
$messageModalContent = '';
if (isset($_SESSION['message_type']) && isset($_SESSION['message_content'])) {
    $messageModalType = $_SESSION['message_type'];
    $messageModalContent = $_SESSION['message_content'];
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
    messageModalOpen: <?= json_encode($messageModalType !== '') ?>,
    messageModalType: '<?= htmlspecialchars($messageModalType) ?>',
    messageModalContent: '<?= htmlspecialchars($messageModalContent) ?>'
}" x-cloak>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        [x-cloak] { display: none !important; }
        body { font-family: 'Inter', sans-serif; overflow-x: hidden; }

        .message-box {
            border-radius: 0.5rem;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            font-size: 1rem;
            line-height: 1.5;
            font-weight: 500;
            min-width: 280px;
            max-width: 90%;
            color: #333;
            text-align: center; /* Center content within the box */
        }
        .message-box i {
            margin-right: 0.75rem;
            font-size: 1.2rem;
        }
        .message-box.success { background-color: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }
        .message-box.error { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .message-box.info { background-color: #e0f2fe; color: #0284c7; border: 1px solid #7dd3fc; }

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
    </style>
</head>
<body class="bg-gray-50" x-cloak>

<?php require_once BASE_PATH . '/layout/headersidebar.php'; // Includes your header and sidebar ?>

<main class="p-[20px] md:py-6 md:px-4 md:ml-64">
    <div class="max-w-full mx-auto py-2">

        <!-- Message Modal -->
        <div class="fixed inset-0 z-[100] flex items-center justify-center p-4"
             x-show="messageModalOpen"
             x-transition:enter="ease-out duration-300"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="ease-in duration-200"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             @click.away="messageModalOpen = false;">
            <div class="fixed inset-0 bg-black bg-opacity-50 transition-opacity" aria-hidden="true"></div>
            <div class="bg-white rounded-lg p-6 max-w-sm w-full shadow-xl relative z-10 message-box"
                 :class="messageModalType"
                 x-init="setTimeout(() => messageModalOpen = false, 3000)">
                <div x-html="messageModalContent" class="flex items-center justify-center w-full"></div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-md overflow-hidden p-6">
            <div class="flex items-center gap-3 mb-6">
                <i class="fas fa-user-edit text-2xl text-blue-600"></i>
                <h1 class="text-2xl font-semibold text-gray-800">Edit User: <?= htmlspecialchars($user_data['username'] ?? 'N/A') ?></h1>
            </div>

            <form method="POST" action="/users/edit_user.php?id=<?= htmlspecialchars($user_id_to_edit) ?>" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username <span class="text-red-500">*</span></label>
                        <input type="text" name="username" id="username" class="form-input"
                               value="<?= htmlspecialchars($user_data['username'] ?? '') ?>" required>
                        <?php if (isset($errors['username'])): ?>
                            <p class="error-message"><?= htmlspecialchars($errors['username']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                        <input type="email" name="email" id="email" class="form-input"
                               value="<?= htmlspecialchars($user_data['email'] ?? '') ?>" required>
                        <?php if (isset($errors['email'])): ?>
                            <p class="error-message"><?= htmlspecialchars($errors['email']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">New Password (Leave blank to keep current)</label>
                        <input type="password" name="password" id="password" class="form-input">
                        <?php if (isset($errors['password'])): ?>
                            <p class="error-message"><?= htmlspecialchars($errors['password']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
                        <input type="password" name="confirm_password" id="confirm_password" class="form-input">
                        <?php if (isset($errors['confirm_password'])): ?>
                            <p class="error-message"><?= htmlspecialchars($errors['confirm_password']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="first_name" class="block text-sm font-medium text-gray-700 mb-1">First Name (Optional)</label>
                        <input type="text" name="first_name" id="first_name" class="form-input"
                               value="<?= htmlspecialchars($user_data['first_name'] ?? '') ?>">
                        <?php if (isset($errors['first_name'])): ?>
                            <p class="error-message"><?= htmlspecialchars($errors['first_name']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label for="last_name" class="block text-sm font-medium text-gray-700 mb-1">Last Name (Optional)</label>
                        <input type="text" name="last_name" id="last_name" class="form-input"
                               value="<?= htmlspecialchars($user_data['last_name'] ?? '') ?>">
                        <?php if (isset($errors['last_name'])): ?>
                            <p class="error-message"><?= htmlspecialchars($errors['last_name']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="displayname" class="block text-sm font-medium text-gray-700 mb-1">Display Name (Optional)</label>
                        <input type="text" name="displayname" id="displayname" class="form-input"
                               value="<?= htmlspecialchars($user_data['displayname'] ?? '') ?>">
                        <?php if (isset($errors['displayname'])): ?>
                            <p class="error-message"><?= htmlspecialchars($errors['displayname']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label for="phone_number" class="block text-sm font-medium text-gray-700 mb-1">Phone Number (Optional)</label>
                        <input type="tel" name="phone_number" id="phone_number" class="form-input"
                               value="<?= htmlspecialchars($user_data['phone_number'] ?? '') ?>">
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
                            // Determine roles available for selection based on current user's role and user being edited
                            $allowedRolesForSelection = ['SuperAdmin', 'Admin', 'Editor', 'Viewer'];

                            if ($userRole === 'Admin') {
                                if ($user_data['user_role'] === 'SuperAdmin') {
                                    // Admin can view SuperAdmin's role but not change it or assign other roles
                                    $allowedRolesForSelection = ['SuperAdmin'];
                                } else {
                                    // Admin can only assign Editor or Viewer to others, or update their own Admin role
                                    $allowedRolesForSelection = ['Admin', 'Editor', 'Viewer'];
                                    // If editing someone else, remove Admin from the list
                                    if ((int)$user_id_to_edit !== (int)$currentUserId) {
                                        $allowedRolesForSelection = ['Editor', 'Viewer'];
                                    }
                                }
                            }
                            // SuperAdmin can edit any role to any role within allowed list.
                            // No specific restrictions for SuperAdmin here as they have full control.

                            foreach ($allowedRolesForSelection as $roleOption): ?>
                                <option value="<?= htmlspecialchars($roleOption) ?>"
                                    <?= ($user_data['user_role'] ?? '') === $roleOption ? 'selected' : '' ?>
                                    <?php
                                        // Disable SuperAdmin option for Admins editing others or themselves to SuperAdmin
                                        if ($userRole === 'Admin' && $roleOption === 'SuperAdmin' && (int)$user_id_to_edit !== (int)$currentUserId) {
                                            echo 'disabled';
                                        }
                                    ?>
                                    >
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
                            $allowedStatuses = ['active', 'suspended', 'locked', 'inactive']; // Updated allowed statuses
                            foreach ($allowedStatuses as $statusOption): ?>
                                <option value="<?= htmlspecialchars($statusOption) ?>"
                                    <?= ($user_data['status'] ?? 'pending') === $statusOption ? 'selected' : '' ?>>
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
                    <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200">
                        <i class="fas fa-save mr-2"></i> Update User
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>
</body>
</html>
