<?php
// File: create_user.php
// Handles creation of new user accounts with robust input validation, CSRF protection,
// and a visually engaging success state before redirecting.
// Accessible by 'SuperAdmin' and 'Admin' roles only.

// --- Production Error Reporting ---
ini_set('display_errors', 0); // Set to 0 in production
ini_set('display_startup_errors', 0); // Set to 0 in production
error_reporting(E_ALL); // Still log all errors

// --- HTTP Security Headers ---
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
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__ . '/..');
}

// Database connection
require_once BASE_PATH . '/config/config.php';

// Check for PDO connection
if (!isset($pdo) || !$pdo instanceof PDO) {
    die('<div style="font-family: sans-serif; padding: 20px; background-color: #fdd; border: 1px solid #f99; color: #c00;">Error: Database connection ($pdo) not properly initialized.</div>');
}

date_default_timezone_set('Asia/Kolkata');

// --- Session and Authorization ---
const INACTIVITY_TIMEOUT = 1800; // 30 minutes
if (isset($_SESSION['user_id']) && !isset($_SESSION['remember_me'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > INACTIVITY_TIMEOUT)) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['message_type'] = 'error';
        $_SESSION['message_content'] = '<i class="fas fa-hourglass-end mr-2"></i> Session expired. Please log in again.';
        header("Location: /login");
        exit;
    }
    $_SESSION['last_activity'] = time();
}

$loggedIn = isset($_SESSION['user_id']);
$isDarkMode = $loggedIn && isset($_SESSION['dark_mode']) && $_SESSION['dark_mode'];
$username = $loggedIn ? htmlspecialchars($_SESSION['username']) : null;
$userRole = $loggedIn ? ($_SESSION['user_role'] ?? 'Viewer') : null;

if (!$loggedIn || !in_array($userRole, ['SuperAdmin', 'Admin'])) {
    $_SESSION['message_type'] = 'error';
    $_SESSION['message_content'] = '<i class="fas fa-lock mr-2"></i> You do not have permission to access this page.';
    $redirect_url = $loggedIn ? '/dashboard' : '/login';
    header("Location: " . $redirect_url);
    exit;
}

$pageTitle = "Create New User";

// --- State Management using Session (PRG Pattern) ---
$form_state = $_SESSION['form_state'] ?? [
    'errors' => [],
    'old_input' => [],
    'message' => '',
    'message_type' => '',
    'success' => false,
    'created_user_data' => []
];
unset($_SESSION['form_state']); // Clear after use

$errors = $form_state['errors'];
$old_input = $form_state['old_input'];
$generalMessage = $form_state['message'];
$generalMessageType = $form_state['message_type'];
$showSuccessState = $form_state['success'];
$createdUserData = $form_state['created_user_data'];

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Validation
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $_SESSION['message_type'] = 'error';
        $_SESSION['message_content'] = '<i class="fas fa-exclamation-circle mr-2"></i> Invalid form submission. Please try again.';
        header("Location: /users/create_user.php");
        exit;
    }

    $validation_errors = [];
    $input = [
        'username' => trim($_POST['username'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'password' => $_POST['password'] ?? '',
        'confirm_password' => $_POST['confirm_password'] ?? '',
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'displayname' => trim($_POST['displayname'] ?? ''),
        'phone_number' => trim($_POST['phone_number'] ?? ''),
        'user_role' => $_POST['user_role'] ?? 'Admin',
        'status' => $_POST['status'] ?? 'active',
    ];

    // --- Input Validation Logic ---
    if (empty($input['username'])) {
        $validation_errors['username'] = 'Username is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $input['username'])) {
        $validation_errors['username'] = 'Username must be 3-20 characters and can only contain letters, numbers, and underscores.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
            $stmt->execute([':username' => $input['username']]);
            if ($stmt->fetchColumn() > 0) {
                $validation_errors['username'] = 'Username is already taken.';
            }
        } catch (PDOException $e) {
            error_log("DB error checking username: " . $e->getMessage());
            $validation_errors['database'] = 'A database error occurred.';
        }
    }

    if (empty($input['email'])) {
        $validation_errors['email'] = 'Email is required.';
    } elseif (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        $validation_errors['email'] = 'Please enter a valid email address.';
    } else {
         try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
            $stmt->execute([':email' => $input['email']]);
            if ($stmt->fetchColumn() > 0) {
                $validation_errors['email'] = 'Email is already registered.';
            }
        } catch (PDOException $e) {
            error_log("DB error checking email: " . $e->getMessage());
            $validation_errors['database'] = 'A database error occurred.';
        }
    }

    if (empty($input['password'])) {
        $validation_errors['password'] = 'Password is required.';
    } elseif (strlen($input['password']) < 8) {
        $validation_errors['password'] = 'Password must be at least 8 characters.';
    } elseif (!preg_match('/[A-Z]/', $input['password']) || !preg_match('/[a-z]/', $input['password']) || !preg_match('/[0-9]/', $input['password']) || !preg_match('/[^A-Za-z0-9]/', $input['password'])) {
        $validation_errors['password'] = 'Password does not meet all complexity requirements.';
    }
    
    if ($input['password'] !== $input['confirm_password']) {
        $validation_errors['confirm_password'] = 'Passwords do not match.';
    }
    
    // If validation fails
    if (!empty($validation_errors)) {
        $_SESSION['form_state'] = [
            'errors' => $validation_errors,
            'old_input' => $input,
            'message' => '<i class="fas fa-exclamation-circle mr-2"></i> Please correct the errors below.',
            'message_type' => 'error',
            'success' => false,
            'created_user_data' => []
        ];
        header("Location: /users/create_user.php");
        exit;
    }

    // If validation passes, create user
    try {
        $hashed_password = password_hash($input['password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, first_name, last_name, displayname, phone_number, user_role, status) VALUES (:username, :email, :password_hash, :first_name, :last_name, :displayname, :phone_number, :user_role, :status)");
        $stmt->execute([
            ':username' => $input['username'],
            ':email' => $input['email'],
            ':password_hash' => $hashed_password,
            ':first_name' => $input['first_name'] ?: null,
            ':last_name' => $input['last_name'] ?: null,
            ':displayname' => $input['displayname'] ?: null,
            ':phone_number' => $input['phone_number'] ?: null,
            ':user_role' => $input['user_role'],
            ':status' => $input['status'],
        ]);

        $_SESSION['form_state'] = [
            'errors' => [],
            'old_input' => [],
            'message' => '',
            'message_type' => '',
            'success' => true,
            'created_user_data' => $input // Pass the newly created user's data
        ];

    } catch (PDOException $e) {
        error_log("DB error creating user: " . $e->getMessage());
        $_SESSION['form_state'] = [
            'errors' => ['database' => 'A database error occurred.'],
            'old_input' => $input,
            'message' => '<i class="fas fa-exclamation-circle mr-2"></i> A database error occurred.',
            'message_type' => 'error',
            'success' => false,
            'created_user_data' => []
        ];
    }
    header("Location: /users/create_user.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" x-data="{
    sidebarOpen: false,
    profileMenuOpen: false,
    mobileProfileMenuOpen: false,
    moreMenuOpen: false,
    isDarkMode: <?= json_encode($isDarkMode) ?>
}" x-cloak :class="{'dark': isDarkMode}">
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
        
        .form-input { 
            border: 1px solid #d1d5db; /* gray-300 */ 
            background-color: #f9fafb; /* gray-50 */
            height: 42px;
        }
        .dark .form-input { background-color: #374151; border-color: #4b5563; color: #d1d5db; }
        .form-input:focus { border-color: #312e81; /* indigo-900 */ box-shadow: 0 0 0 3px rgba(49, 46, 129, 0.2); }
        .dark .form-input:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.3); }
        .label-icon {
            color: #312e81;
            margin-right: 0.5rem;
            width: 16px;
            text-align: center;
        }
        .dark .label-icon {
            color: #a5b4fc;
        }
        .error-message { color: #ef4444; font-size: 0.875rem; margin-top: 0.25rem; }
        .general-message { border-radius: 4px; padding: 10px 1.25rem; margin-bottom: 1rem; font-weight: 500; font-size: 0.875rem; display: flex; align-items: center; text-align: left; }
        .general-message.error { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .dark .general-message.error { background-color: #3f2222; color: #fca5a5; border-color: #7f1d1d; }
        .general-message.success { background-color: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }
        .dark .general-message.success { background-color: #142e1c; color: #86efac; border-color: #166534; }
        .general-message i { margin-right: 0.75rem; font-size: 1.2rem; }
        .all-sides-shadow { box-shadow: 0 0 20px rgba(0, 0, 0, 0.1); }
        .dark .all-sides-shadow { box-shadow: 0 0 20px rgba(0, 0, 0, 0.25); }
        .strength-bar { height: 6px; border-radius: 3px; transition: width 0.3s ease-in-out, background-color 0.3s ease-in-out; }
        input[type="password"]::-ms-reveal, input[type="password"]::-ms-clear, input[type="password"]::-webkit-reveal, input[type="password"]::-webkit-password-reveal-button { display: none !important; -webkit-appearance: none !important; }
        .tick-animation { stroke-dasharray: 50; stroke-dashoffset: 50; animation: draw 0.5s ease-out forwards 0.2s; }
        @keyframes draw { to { stroke-dashoffset: 0; } }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900" @toggle-dark-mode.window="isDarkMode = $event.detail.isDarkMode">

<?php require_once BASE_PATH . '/layout/headersidebar.php'; ?>

<main class="px-2 py-0 md:ml-0">
    <div class="max-w-7xl mx-auto">
        
        <div x-data="userCreateForm" class="bg-white rounded-[4px] all-sides-shadow overflow-hidden dark:bg-gray-800 relative mx-2 md:mx-0 mt-4 md:mt-0">
            
            <div class="grid grid-cols-1 lg:grid-cols-2">
                <!-- Form / Success Section -->
                <div class="p-6 relative min-h-[600px]">
                    <!-- Success Animation Overlay -->
                    <div x-show="showSuccessAnimation" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="absolute inset-0 bg-indigo-100 dark:bg-indigo-900/80 z-20 flex flex-col items-center justify-center p-6 text-center">
                        <div class="w-24 h-24 bg-indigo-900 dark:bg-white rounded-full flex items-center justify-center mb-4">
                            <svg class="w-16 h-16" viewBox="0 0 52 52">
                                <circle class="stroke-current text-indigo-200 dark:text-indigo-400" cx="26" cy="26" r="25" fill="none" stroke-width="3"/>
                                <path class="tick-animation stroke-current text-white dark:text-indigo-900" fill="none" stroke-width="4" d="M14 27l5.917 4.917L38.417 16.583"/>
                            </svg>
                        </div>
                        <h2 class="text-2xl font-bold text-indigo-900 dark:text-white">Successfully Created</h2>
                        <p class="text-indigo-700 dark:text-indigo-200 mt-2">User: <strong class="font-semibold" x-text="createdUser.username"></strong> has been added.</p>
                        <div class="mt-8 flex flex-col sm:flex-row gap-3 w-full max-w-xs">
                            <a href="/manage-users" class="w-full inline-flex items-center justify-center px-4 py-2 bg-green-600 text-white font-semibold rounded-md hover:bg-green-700 transition">
                                <span x-text="`Redirecting... (${countdown}s)`"></span>
                            </a>
                            <a href="/users/create_user.php" class="w-full inline-flex items-center justify-center px-4 py-2 bg-indigo-200 text-indigo-900 font-semibold rounded-md hover:bg-indigo-300 transition">Create Another User</a>
                        </div>
                    </div>
                    
                    <!-- Form Section -->
                    <div x-show="!showSuccessAnimation">
                        <div class="flex items-center justify-center md:justify-start gap-3 mb-6">
                            <i class="fas fa-user-plus text-2xl text-indigo-900 dark:text-indigo-400"></i>
                            <h1 class="text-xl font-semibold text-gray-800 dark:text-gray-100">Create New User</h1>
                        </div>

                        <?php if (!empty($generalMessage)): ?>
                            <div class="general-message mb-4 <?= htmlspecialchars($generalMessageType) ?>"><?= $generalMessage ?></div>
                        <?php endif; ?>

                        <form method="POST" action="/users/create_user.php" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="username" class="flex items-center text-sm font-medium text-gray-700 mb-1 dark:text-gray-300"><i class="label-icon fas fa-user"></i> Username <span class="text-red-500 ml-1">*</span></label>
                                    <input type="text" name="username" id="username" class="form-input w-full rounded-md px-3" :class="{'bg-indigo-100 dark:bg-indigo-900/50': username.trim() !== ''}" x-model="username" value="<?= htmlspecialchars($old_input['username'] ?? '') ?>" required>
                                    <?php if (isset($errors['username'])): ?><p class="error-message"><?= htmlspecialchars($errors['username']) ?></p><?php endif; ?>
                                </div>
                                <div>
                                    <label for="email" class="flex items-center text-sm font-medium text-gray-700 mb-1 dark:text-gray-300"><i class="label-icon fas fa-envelope"></i> Email <span class="text-red-500 ml-1">*</span></label>
                                    <input type="email" name="email" id="email" class="form-input w-full rounded-md px-3" :class="{'bg-indigo-100 dark:bg-indigo-900/50': email.trim() !== ''}" x-model="email" value="<?= htmlspecialchars($old_input['email'] ?? '') ?>" required>
                                    <?php if (isset($errors['email'])): ?><p class="error-message"><?= htmlspecialchars($errors['email']) ?></p><?php endif; ?>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div x-data="{ showPassword: false }">
                                    <label for="password" class="flex items-center text-sm font-medium text-gray-700 mb-1 dark:text-gray-300"><i class="label-icon fas fa-lock"></i> Password <span class="text-red-500 ml-1">*</span></label>
                                    <div class="relative"><input :type="showPassword ? 'text' : 'password'" name="password" id="password" class="form-input w-full rounded-md pr-10 px-3" :class="{'bg-indigo-100 dark:bg-indigo-900/50': password.trim() !== ''}" x-model="password" required autocomplete="new-password"><button type="button" @click="showPassword = !showPassword" class="absolute top-1/2 right-0 -translate-y-1/2 px-3 flex items-center text-gray-500"><i class="fas" :class="showPassword ? 'fa-eye-slash' : 'fa-eye'"></i></button></div>
                                    <?php if (isset($errors['password'])): ?><p class="error-message"><?= htmlspecialchars($errors['password']) ?></p><?php endif; ?>
                                </div>
                                <div x-data="{ showConfirmPassword: false }">
                                    <label for="confirm_password" class="flex items-center text-sm font-medium text-gray-700 mb-1 dark:text-gray-300"><i class="label-icon fas fa-check-double"></i> Confirm Password <span class="text-red-500 ml-1">*</span></label>
                                    <div class="relative"><input :type="showConfirmPassword ? 'text' : 'password'" name="confirm_password" id="confirm_password" class="form-input w-full rounded-md pr-10 px-3" :class="{'bg-indigo-100 dark:bg-indigo-900/50': confirmPassword.trim() !== ''}" x-model="confirmPassword" required autocomplete="new-password"><button type="button" @click="showConfirmPassword = !showConfirmPassword" class="absolute top-1/2 right-0 -translate-y-1/2 px-3 flex items-center text-gray-500"><i class="fas" :class="showConfirmPassword ? 'fa-eye-slash' : 'fa-eye'"></i></button></div>
                                    <?php if (isset($errors['confirm_password'])): ?><p class="error-message"><?= htmlspecialchars($errors['confirm_password']) ?></p><?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="first_name" class="flex items-center text-sm font-medium text-gray-700 mb-1 dark:text-gray-300"><i class="label-icon fas fa-id-card-clip"></i> First Name (Optional)</label>
                                    <input type="text" name="first_name" id="first_name" class="form-input w-full rounded-md px-3" :class="{'bg-indigo-100 dark:bg-indigo-900/50': firstName.trim() !== ''}" x-model="firstName" value="<?= htmlspecialchars($old_input['first_name'] ?? '') ?>">
                                </div>
                                <div>
                                    <label for="last_name" class="flex items-center text-sm font-medium text-gray-700 mb-1 dark:text-gray-300"><i class="label-icon fas fa-id-card-clip"></i> Last Name (Optional)</label>
                                    <input type="text" name="last_name" id="last_name" class="form-input w-full rounded-md px-3" :class="{'bg-indigo-100 dark:bg-indigo-900/50': lastName.trim() !== ''}" x-model="lastName" value="<?= htmlspecialchars($old_input['last_name'] ?? '') ?>">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                 <div>
                                    <label for="displayname" class="flex items-center text-sm font-medium text-gray-700 mb-1 dark:text-gray-300"><i class="label-icon fas fa-vcard"></i> Display Name (Optional)</label>
                                    <input type="text" name="displayname" id="displayname" class="form-input w-full rounded-md px-3" :class="{'bg-indigo-100 dark:bg-indigo-900/50': displayName.trim() !== ''}" x-model="displayName" value="<?= htmlspecialchars($old_input['displayname'] ?? '') ?>">
                                </div>
                                 <div>
                                    <label for="phone_number" class="flex items-center text-sm font-medium text-gray-700 mb-1 dark:text-gray-300"><i class="label-icon fas fa-phone"></i> Phone Number (Optional)</label>
                                    <input type="tel" name="phone_number" id="phone_number" class="form-input w-full rounded-md px-3" :class="{'bg-indigo-100 dark:bg-indigo-900/50': phoneNumber.trim() !== ''}" x-model="phoneNumber" value="<?= htmlspecialchars($old_input['phone_number'] ?? '') ?>">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="relative" x-data="{ roleDropdownOpen: false }">
                                    <label for="user_role" class="flex items-center text-sm font-medium text-gray-700 mb-1 dark:text-gray-300"><i class="label-icon fas fa-user-shield"></i> User Role <span class="text-red-500 ml-1">*</span></label>
                                    <input type="hidden" name="user_role" x-model="userRole">
                                    <button @click="roleDropdownOpen = !roleDropdownOpen" type="button" class="form-input w-full rounded-md px-3 text-left flex justify-between items-center" :class="{'bg-indigo-100 dark:bg-indigo-900/50': userRole !== ''}">
                                        <span x-text="userRole || 'Select Role'"></span>
                                        <i class="fas fa-chevron-down text-xs text-gray-400 transition-transform" :class="{'rotate-180': roleDropdownOpen}"></i>
                                    </button>
                                    <div x-show="roleDropdownOpen" @click.away="roleDropdownOpen = false" class="absolute z-10 w-full bg-white dark:bg-gray-700 rounded-md shadow-lg border dark:border-gray-600 bottom-full mb-1 divide-y divide-gray-100 dark:divide-gray-600" x-transition>
                                        <?php
                                        $availableRoles = [];
                                        if ($userRole === 'SuperAdmin') $availableRoles = ['SuperAdmin', 'Admin', 'Editor', 'Viewer'];
                                        elseif ($userRole === 'Admin') $availableRoles = ['Admin', 'Editor', 'Viewer'];
                                        foreach ($availableRoles as $roleOption): ?>
                                            <a href="#" @click.prevent="userRole = '<?= htmlspecialchars($roleOption) ?>'; roleDropdownOpen = false" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-200 hover:bg-indigo-100 dark:hover:bg-indigo-800"><?= htmlspecialchars($roleOption) ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (isset($errors['user_role'])): ?><p class="error-message"><?= htmlspecialchars($errors['user_role']) ?></p><?php endif; ?>
                                </div>
                                <div class="relative" x-data="{ statusDropdownOpen: false }">
                                    <label for="status" class="flex items-center text-sm font-medium text-gray-700 mb-1 dark:text-gray-300"><i class="label-icon fas fa-toggle-on"></i> Status <span class="text-red-500 ml-1">*</span></label>
                                    <input type="hidden" name="status" x-model="status">
                                     <button @click="statusDropdownOpen = !statusDropdownOpen" type="button" class="form-input w-full rounded-md px-3 text-left flex justify-between items-center" :class="{'bg-indigo-100 dark:bg-indigo-900/50': status !== ''}">
                                        <span class="capitalize" x-text="status || 'Select Status'"></span>
                                        <i class="fas fa-chevron-down text-xs text-gray-400 transition-transform" :class="{'rotate-180': statusDropdownOpen}"></i>
                                    </button>
                                    <div x-show="statusDropdownOpen" @click.away="statusDropdownOpen = false" class="absolute z-10 w-full bg-white dark:bg-gray-700 rounded-md shadow-lg border dark:border-gray-600 bottom-full mb-1 divide-y divide-gray-100 dark:divide-gray-600" x-transition>
                                        <?php foreach (['active', 'suspended', 'locked', 'inactive', 'pending'] as $statusOption): ?>
                                            <a href="#" @click.prevent="status = '<?= htmlspecialchars($statusOption) ?>'; statusDropdownOpen = false" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-200 hover:bg-indigo-100 dark:hover:bg-indigo-800 capitalize"><?= htmlspecialchars(ucfirst($statusOption)) ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-end space-x-3 pt-4">
                                <a href="/manage-users" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-[4px] text-indigo-900 bg-indigo-200 hover:bg-indigo-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-900">Cancel</a>
                                <button type="submit" class="inline-flex items-center justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-[4px] text-white bg-indigo-900 hover:bg-indigo-800 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-900"><i class="fas fa-user-plus mr-2"></i> Create User</button>
                            </div>
                        </form>
                    </div>
                </div>
                <!-- Preview Section -->
                <div class="hidden lg:flex flex-col p-6 border-l border-gray-300 bg-gray-50/50 dark:border-gray-700 dark:bg-gray-900/50">
                     <div class="w-full max-w-sm mx-auto">
                        <div class="flex flex-col items-center text-center mb-6">
                            <div class="w-24 h-24 rounded-full bg-indigo-900 flex items-center justify-center text-white text-4xl font-bold mb-4 dark:bg-indigo-500"><span x-text="initial"></span></div>
                            <div class="flex items-center justify-center gap-2 mt-2"><i class="fas fa-user text-lg text-indigo-900 dark:text-indigo-400"></i><h4 class="text-xl font-bold text-gray-800 dark:text-gray-100" x-text="showSuccessAnimation ? createdUser.username : username || 'Username'"></h4></div>
                            <div class="flex items-center justify-center gap-2 mt-1"><i class="fas fa-envelope text-sm text-indigo-900 dark:text-indigo-400"></i><p class="text-sm text-gray-500 dark:text-gray-400" x-text="showSuccessAnimation ? createdUser.email : email || 'Email not provided'"></p></div>
                        </div>

                        <div class="text-sm space-y-0 rounded-lg overflow-hidden border border-gray-300 dark:border-gray-700">
                            <div class="py-2.5 px-4 grid grid-cols-5 gap-2 items-center bg-gray-100 dark:bg-gray-700/60"><span class="font-medium text-gray-600 flex items-center col-span-2 dark:text-gray-400"><i class="fas fa-id-badge w-6 text-indigo-900 dark:text-indigo-400"></i>Display Name</span><span class="col-span-3 text-gray-800 dark:text-gray-300" x-text="showSuccessAnimation ? createdUser.displayname : displayName || 'N/A'"></span></div>
                            <div class="py-2.5 px-4 grid grid-cols-5 gap-2 items-center bg-white dark:bg-gray-800"><span class="font-medium text-gray-600 flex items-center col-span-2 dark:text-gray-400"><i class="fas fa-phone w-6 text-indigo-900 dark:text-indigo-400"></i>Phone</span><span class="col-span-3 text-gray-800 dark:text-gray-300" x-text="showSuccessAnimation ? createdUser.phone_number : phoneNumber || 'N/A'"></span></div>
                            <div class="py-2.5 px-4 grid grid-cols-5 gap-2 items-center bg-gray-100 dark:bg-gray-700/60"><span class="font-medium text-gray-600 flex items-center col-span-2 dark:text-gray-400"><i class="fas fa-user-shield w-6 text-indigo-900 dark:text-indigo-400"></i>Role</span><span class="col-span-3"><span x-show="showSuccessAnimation ? createdUser.userRole : userRole" x-text="showSuccessAnimation ? createdUser.userRole : userRole" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-900 dark:bg-indigo-900 dark:text-indigo-300"></span><span x-show="!(showSuccessAnimation ? createdUser.userRole : userRole)" class="text-gray-400">Not set</span></span></div>
                            <div class="py-2.5 px-4 grid grid-cols-5 gap-2 items-center bg-white dark:bg-gray-800"><span class="font-medium text-gray-600 flex items-center col-span-2 dark:text-gray-400"><i class="fas fa-toggle-on w-6 text-indigo-900 dark:text-indigo-400"></i>Status</span><span class="col-span-3"><span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" :class="statusClasses"><span class="capitalize" x-text="showSuccessAnimation ? createdUser.status : status"></span></span></span></div>
                        </div>

                        <div class="pt-4 mt-auto">
                            <div class="flex justify-between items-center"><span class="font-medium text-gray-500 text-sm dark:text-gray-400">Password Strength:</span><span class="text-sm font-semibold text-gray-600 dark:text-gray-300" x-text="`${strengthPercentage}%`"></span></div>
                            <div class="mt-1 bg-gray-200 rounded-full w-full dark:bg-gray-700"><div class="strength-bar" :class="strengthColor" :style="{ width: strengthWidth }"></div></div>
                            <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                <ul class="grid grid-cols-2 gap-x-6 gap-y-1">
                                    <li :class="{ 'text-green-600': password.length >= 8, 'text-gray-500': password.length < 8 }"><i class="fas fa-fw" :class="password.length >= 8 ? 'fa-check-circle' : 'fa-times-circle'"></i> 8+ characters</li>
                                    <li :class="{ 'text-green-600': /[A-Z]/.test(password), 'text-gray-500': !/[A-Z]/.test(password) }"><i class="fas fa-fw" :class="/[A-Z]/.test(password) ? 'fa-check-circle' : 'fa-times-circle'"></i> Uppercase</li>
                                    <li :class="{ 'text-green-600': /[a-z]/.test(password), 'text-gray-500': !/[a-z]/.test(password) }"><i class="fas fa-fw" :class="/[a-z]/.test(password) ? 'fa-check-circle' : 'fa-times-circle'"></i> Lowercase</li>
                                    <li :class="{ 'text-green-600': /[0-9]/.test(password), 'text-gray-500': !/[0-9]/.test(password) }"><i class="fas fa-fw" :class="/[0-9]/.test(password) ? 'fa-check-circle' : 'fa-times-circle'"></i> Number</li>
                                    <li :class="{ 'text-green-600': /[^A-Za-z0-9]/.test(password), 'text-gray-500': !/[^A-Za-z0-9]/.test(password) }"><i class="fas fa-fw" :class="/[^A-Za-z0-9]/.test(password) ? 'fa-check-circle' : 'fa-times-circle'"></i> Special char</li>
                                    <li :class="{ 'text-green-600': password && password === confirmPassword, 'text-gray-500': !password || password !== confirmPassword }"><i class="fas fa-fw" :class="password && password === confirmPassword ? 'fa-check-circle' : 'fa-times-circle'"></i> Passwords match</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('userCreateForm', () => ({
            // Live form data
            username: <?= json_encode($old_input['username'] ?? '') ?>,
            email: <?= json_encode($old_input['email'] ?? '') ?>,
            firstName: <?= json_encode($old_input['first_name'] ?? '') ?>,
            lastName: <?= json_encode($old_input['last_name'] ?? '') ?>,
            displayName: <?= json_encode($old_input['displayname'] ?? '') ?>,
            phoneNumber: <?= json_encode($old_input['phone_number'] ?? '') ?>,
            userRole: <?= json_encode($old_input['user_role'] ?? 'Admin') ?>,
            status: <?= json_encode($old_input['status'] ?? 'active') ?>,
            password: '',
            confirmPassword: '',

            // Data for the success state
            showSuccessAnimation: <?= json_encode($showSuccessState) ?>,
            createdUser: <?= json_encode($createdUserData) ?>,
            countdown: 10,
            
            init() {
                if (this.showSuccessAnimation) {
                    const countdownInterval = setInterval(() => {
                        if (this.countdown > 0) {
                            this.countdown--;
                        } else {
                            clearInterval(countdownInterval);
                        }
                    }, 1000);

                    setTimeout(() => {
                        window.location.href = '/manage-users';
                    }, 10000); // 10-second delay
                }
            },

            get initial() {
                let userForInitial = this.showSuccessAnimation ? this.createdUser : this;
                if (userForInitial.displayname) return userForInitial.displayname.charAt(0).toUpperCase();
                if (userForInitial.first_name) return userForInitial.first_name.charAt(0).toUpperCase();
                if (userForInitial.username) return userForInitial.username.charAt(0).toUpperCase();
                return '?';
            },
            get strengthPercentage() {
                let score = 0;
                if (this.password.length >= 8) score++;
                if (/[A-Z]/.test(this.password)) score++;
                if (/[a-z]/.test(this.password)) score++;
                if (/[0-9]/.test(this.password)) score++;
                if (/[^A-Za-z0-9]/.test(this.password)) score++;
                if (this.password && this.password === this.confirmPassword) score++;
                return Math.round((score / 6) * 100);
            },
            get strengthColor() {
                if (this.password.length === 0) return 'bg-gray-200 dark:bg-gray-600';
                const percentage = this.strengthPercentage;
                if (percentage < 40) return 'bg-red-500';
                if (percentage < 75) return 'bg-orange-500';
                return 'bg-green-500';
            },
            get strengthWidth() {
                if (this.password.length === 0) return '0%';
                return this.strengthPercentage + '%';
            },
            get statusClasses() {
                let userStatus = this.showSuccessAnimation ? this.createdUser.status : this.status;
                switch(userStatus) {
                    case 'active': return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300';
                    case 'suspended': return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300';
                    case 'locked': return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300';
                    case 'inactive': return 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
                    case 'pending': return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300';
                    default: return 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
                }
            }
        }));
    });
</script>
</body>
</html>

