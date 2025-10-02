<?php
// File: edit_user.php
// Handles editing of existing user accounts with full validation and a live preview.

// --- Basic Setup and Security ---
ini_set('display_errors', 0);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__ . '/..');
}
require_once BASE_PATH . '/config/config.php';
if (!isset($pdo)) { die("Database connection not properly initialized."); }
date_default_timezone_set('Asia/Kolkata');

// --- Session and Authorization ---
const INACTIVITY_TIMEOUT = 1800;
if (isset($_SESSION['user_id']) && !isset($_SESSION['remember_me'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > INACTIVITY_TIMEOUT)) {
        session_unset(); session_destroy(); session_start();
        $_SESSION['message_type'] = 'error';
        $_SESSION['message_content'] = 'Session expired due to inactivity.';
        header("Location: /login");
        exit;
    }
    $_SESSION['last_activity'] = time();
}

$loggedIn = isset($_SESSION['user_id']);
$userRole = $loggedIn ? ($_SESSION['user_role'] ?? 'Viewer') : null;

if (!$loggedIn || !in_array($userRole, ['SuperAdmin', 'Admin'])) {
    $_SESSION['message_type'] = 'error';
    $_SESSION['message_content'] = 'You do not have permission to access this page.';
    header("Location: " . ($loggedIn ? '/dashboard' : '/login'));
    exit;
}

$isDarkMode = $loggedIn && !empty($_SESSION['dark_mode']);
$username = $loggedIn ? htmlspecialchars($_SESSION['username']) : null;

// --- User Fetching Logic ---
$user_id_to_edit = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
$user_to_edit = null;

if (!$user_id_to_edit) {
    $_SESSION['message_type'] = 'error';
    $_SESSION['message_content'] = 'No user specified for editing.';
    header('Location: /manage-users');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $user_id_to_edit]);
    $user_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("DB Error fetching user for edit: " . $e->getMessage());
    $_SESSION['message_type'] = 'error';
    $_SESSION['message_content'] = 'A database error occurred.';
    header('Location: /manage-users');
    exit;
}

if (!$user_to_edit) {
    $_SESSION['message_type'] = 'error';
    $_SESSION['message_content'] = 'User not found.';
    header('Location: /manage-users');
    exit;
}

$pageTitle = "Edit User: " . htmlspecialchars($user_to_edit['username']);

// --- Form State Management (PRG Pattern) ---
$form_state = $_SESSION['form_state'] ?? [];
unset($_SESSION['form_state']);

$errors = $form_state['errors'] ?? [];
$old_input = $form_state['old_input'] ?? $user_to_edit;
$generalMessage = $form_state['message'] ?? '';
$generalMessageType = $form_state['message_type'] ?? '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $_SESSION['message_type'] = 'error';
        $_SESSION['message_content'] = 'Invalid form submission.';
        header("Location: /users/edit_user.php?user_id=" . $user_id_to_edit);
        exit;
    }

    $validation_errors = [];
    $input = [
        'user_id' => $user_id_to_edit,
        'username' => trim($_POST['username'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'password' => $_POST['password'] ?? '',
        'confirm_password' => $_POST['confirm_password'] ?? '',
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'displayname' => trim($_POST['displayname'] ?? ''),
        'phone_number' => trim($_POST['phone_number'] ?? ''),
        'user_role' => $_POST['user_role'] ?? '',
        'status' => $_POST['status'] ?? '',
    ];

    // --- Validation Logic ---
    if (empty($input['username'])) {
        $validation_errors['username'] = 'Username is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $input['username'])) {
        $validation_errors['username'] = 'Invalid username format.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username AND user_id != :user_id");
            $stmt->execute([':username' => $input['username'], ':user_id' => $input['user_id']]);
            if ($stmt->fetchColumn() > 0) {
                $validation_errors['username'] = 'Username is already taken by another user.';
            }
        } catch (PDOException $e) {
            $validation_errors['database'] = 'A database error occurred.';
        }
    }

    if (empty($input['email'])) {
        $validation_errors['email'] = 'Email is required.';
    } elseif (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        $validation_errors['email'] = 'Invalid email format.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :email AND user_id != :user_id");
            $stmt->execute([':email' => $input['email'], ':user_id' => $input['user_id']]);
            if ($stmt->fetchColumn() > 0) {
                $validation_errors['email'] = 'Email is already registered by another user.';
            }
        } catch (PDOException $e) {
            $validation_errors['database'] = 'A database error occurred.';
        }
    }

    if (!empty($input['password'])) {
        if (strlen($input['password']) < 8) {
            $validation_errors['password'] = 'Password must be at least 8 characters.';
        }
        if ($input['password'] !== $input['confirm_password']) {
            $validation_errors['confirm_password'] = 'Passwords do not match.';
        }
    }

    // If validation fails, redirect back with state
    if (!empty($validation_errors)) {
        $_SESSION['form_state'] = [
            'errors' => $validation_errors,
            'old_input' => $input,
            'message' => '<i class="fas fa-exclamation-circle mr-2"></i> Please correct the errors below.',
            'message_type' => 'error'
        ];
        header("Location: /users/edit_user.php?user_id=" . $input['user_id']);
        exit;
    }

    // If validation passes, update user
    try {
        $sql = "UPDATE users SET username = :username, email = :email, first_name = :first_name, last_name = :last_name, displayname = :displayname, phone_number = :phone_number, user_role = :user_role, status = :status";
        $params = [
            ':username' => $input['username'],
            ':email' => $input['email'],
            ':first_name' => $input['first_name'] ?: null,
            ':last_name' => $input['last_name'] ?: null,
            ':displayname' => $input['displayname'] ?: null,
            ':phone_number' => $input['phone_number'] ?: null,
            ':user_role' => $input['user_role'],
            ':status' => $input['status'],
            ':user_id' => $input['user_id']
        ];

        if (!empty($input['password'])) {
            $sql .= ", password = :password";
            $params[':password'] = password_hash($input['password'], PASSWORD_DEFAULT);
        }

        $sql .= " WHERE user_id = :user_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $_SESSION['message_type'] = 'success';
        $_SESSION['message_content'] = '<i class="fas fa-check-circle mr-2"></i> User "' . htmlspecialchars($input['username']) . '" updated successfully!';
        header("Location: /manage-users");
        exit;

    } catch (PDOException $e) {
        error_log("DB error updating user: " . $e->getMessage());
        $_SESSION['form_state'] = [
            'errors' => [],
            'old_input' => $input,
            'message' => '<i class="fas fa-exclamation-circle mr-2"></i> A database error occurred during update.',
            'message_type' => 'error'
        ];
        header("Location: /users/edit_user.php?user_id=" . $input['user_id']);
        exit;
    }
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
            height: 42px; 
            background-color: #f9fafb; 
            border: 1px solid #d1d5db;
        }
        .dark .form-input { background-color: #374151; border-color: #4b5563; }
        .label-icon { color: #312e81; margin-right: 0.5rem; width: 16px; text-align: center; }
        .dark .label-icon { color: #a5b4fc; }
        .error-message { color: #ef4444; font-size: 0.875rem; margin-top: 0.25rem; }

    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900" @toggle-dark-mode.window="isDarkMode = $event.detail.isDarkMode">

<?php require_once BASE_PATH . '/layout/headersidebar.php'; ?>

<main class="px-2 py-0 md:ml-0">
    <div class="max-w-7xl mx-auto">
        
        <div x-data='userEditForm(<?= json_encode($old_input) ?>)' class="bg-white rounded-[4px] shadow-md overflow-hidden dark:bg-gray-800 relative mx-2 md:mx-0 mt-4 md:mt-0">
            <div class="grid grid-cols-1 lg:grid-cols-2">
                <!-- Form Section -->
                <div class="p-6">
                    <div class="flex items-center gap-3 mb-6">
                        <i class="fas fa-edit text-2xl text-indigo-900 dark:text-indigo-400"></i>
                        <h1 class="text-xl font-semibold text-gray-800 dark:text-gray-100">Edit User: <span x-text="formData.username"></span></h1>
                    </div>
                    <?php if ($generalMessage): ?>
                        <div class="mb-4 p-3 rounded-md text-sm <?= $generalMessageType === 'error' ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700' ?>"><?= $generalMessage ?></div>
                    <?php endif; ?>

                    <form method="POST" action="/users/edit_user.php?user_id=<?= $user_id_to_edit ?>" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="user_id" value="<?= $user_id_to_edit ?>">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="username" class="flex items-center text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><i class="label-icon fas fa-user"></i> Username</label>
                                <input type="text" name="username" id="username" class="form-input w-full rounded-md px-3" x-model="formData.username" required>
                                <?php if (isset($errors['username'])): ?><p class="error-message"><?= $errors['username'] ?></p><?php endif; ?>
                            </div>
                            <div>
                                <label for="email" class="flex items-center text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><i class="label-icon fas fa-envelope"></i> Email</label>
                                <input type="email" name="email" id="email" class="form-input w-full rounded-md px-3" x-model="formData.email" required>
                                <?php if (isset($errors['email'])): ?><p class="error-message"><?= $errors['email'] ?></p><?php endif; ?>
                            </div>
                        </div>

                        <div>
                             <p class="text-sm text-gray-500 dark:text-gray-400">Leave password fields blank to keep the current password.</p>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div x-data="{ showPassword: false }">
                                <label for="password" class="flex items-center text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><i class="label-icon fas fa-lock"></i> New Password</label>
                                <div class="relative"><input :type="showPassword ? 'text' : 'password'" name="password" id="password" class="form-input w-full rounded-md pr-10 px-3" autocomplete="new-password"><button type="button" @click="showPassword = !showPassword" class="absolute top-1/2 right-0 -translate-y-1/2 px-3 flex items-center text-gray-500"><i class="fas" :class="showPassword ? 'fa-eye-slash' : 'fa-eye'"></i></button></div>
                                <?php if (isset($errors['password'])): ?><p class="error-message"><?= $errors['password'] ?></p><?php endif; ?>
                            </div>
                            <div x-data="{ showConfirmPassword: false }">
                                <label for="confirm_password" class="flex items-center text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><i class="label-icon fas fa-check-double"></i> Confirm New Password</label>
                                <div class="relative"><input :type="showConfirmPassword ? 'text' : 'password'" name="confirm_password" id="confirm_password" class="form-input w-full rounded-md pr-10 px-3" autocomplete="new-password"><button type="button" @click="showConfirmPassword = !showConfirmPassword" class="absolute top-1/2 right-0 -translate-y-1/2 px-3 flex items-center text-gray-500"><i class="fas" :class="showConfirmPassword ? 'fa-eye-slash' : 'fa-eye'"></i></button></div>
                                <?php if (isset($errors['confirm_password'])): ?><p class="error-message"><?= $errors['confirm_password'] ?></p><?php endif; ?>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="first_name" class="flex items-center text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><i class="label-icon fas fa-id-card-clip"></i> First Name</label>
                                <input type="text" name="first_name" id="first_name" class="form-input w-full rounded-md px-3" x-model="formData.first_name">
                            </div>
                            <div>
                                <label for="last_name" class="flex items-center text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><i class="label-icon fas fa-id-card-clip"></i> Last Name</label>
                                <input type="text" name="last_name" id="last_name" class="form-input w-full rounded-md px-3" x-model="formData.last_name">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                             <div>
                                <label for="displayname" class="flex items-center text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><i class="label-icon fas fa-vcard"></i> Display Name</label>
                                <input type="text" name="displayname" id="displayname" class="form-input w-full rounded-md px-3" x-model="formData.displayname">
                            </div>
                             <div>
                                <label for="phone_number" class="flex items-center text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><i class="label-icon fas fa-phone"></i> Phone Number</label>
                                <input type="tel" name="phone_number" id="phone_number" class="form-input w-full rounded-md px-3" x-model="formData.phone_number">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="user_role" class="flex items-center text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><i class="label-icon fas fa-user-shield"></i> User Role</label>
                                <select name="user_role" id="user_role" class="form-input w-full rounded-md" x-model="formData.user_role">
                                    <?php
                                    $availableRoles = [];
                                    if ($userRole === 'SuperAdmin') $availableRoles = ['SuperAdmin', 'Admin', 'Editor', 'Viewer'];
                                    elseif ($userRole === 'Admin') $availableRoles = ['Admin', 'Editor', 'Viewer'];
                                    foreach ($availableRoles as $roleOption): ?>
                                    <option value="<?= htmlspecialchars($roleOption) ?>"><?= htmlspecialchars($roleOption) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="status" class="flex items-center text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><i class="label-icon fas fa-toggle-on"></i> Status</label>
                                <select name="status" id="status" class="form-input w-full rounded-md" x-model="formData.status">
                                    <?php foreach(['active', 'suspended', 'locked', 'inactive', 'pending'] as $status): ?>
                                    <option value="<?= $status ?>"><?= ucfirst($status) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3 pt-4">
                            <a href="/manage-users" class="px-4 py-2 rounded-md text-sm font-medium bg-gray-200 hover:bg-gray-300 text-gray-800 dark:bg-gray-600 dark:text-gray-200 dark:hover:bg-gray-500">Cancel</a>
                            <button type="submit" class="px-4 py-2 rounded-md text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">Save Changes</button>
                        </div>
                    </form>
                </div>

                <!-- Preview Section -->
                <div class="hidden lg:flex flex-col p-6 border-l border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                     <div class="w-full max-w-sm mx-auto">
                        <div class="flex flex-col items-center text-center mb-6">
                            <div class="w-24 h-24 rounded-full bg-indigo-900 flex items-center justify-center text-white text-4xl font-bold mb-4 dark:bg-indigo-500"><span x-text="initial"></span></div>
                            <div class="flex items-center justify-center gap-2 mt-2"><i class="fas fa-user text-lg text-indigo-900 dark:text-indigo-400"></i><h4 class="text-xl font-bold text-gray-800 dark:text-gray-100" x-text="formData.username || 'Username'"></h4></div>
                            <div class="flex items-center justify-center gap-2 mt-1"><i class="fas fa-envelope text-sm text-indigo-900 dark:text-indigo-400"></i><p class="text-sm text-gray-500 dark:text-gray-400" x-text="formData.email || 'Email not provided'"></p></div>
                        </div>
                        <div class="text-sm space-y-0 rounded-lg overflow-hidden border border-gray-300 dark:border-gray-700">
                             <div class="py-2.5 px-4 grid grid-cols-5 gap-2 items-center bg-gray-100 dark:bg-gray-700/60"><span class="font-medium text-gray-600 col-span-2 dark:text-gray-400">Display Name</span><span class="col-span-3 text-gray-800 dark:text-gray-300" x-text="formData.displayname || 'N/A'"></span></div>
                             <div class="py-2.5 px-4 grid grid-cols-5 gap-2 items-center bg-white dark:bg-gray-800"><span class="font-medium text-gray-600 col-span-2 dark:text-gray-400">Role</span><span class="col-span-3"><span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-900" x-text="formData.user_role"></span></span></div>
                             <div class="py-2.5 px-4 grid grid-cols-5 gap-2 items-center bg-gray-100 dark:bg-gray-700/60"><span class="font-medium text-gray-600 col-span-2 dark:text-gray-400">Status</span><span class="col-span-3"><span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" :class="statusClasses"><span class="capitalize" x-text="formData.status"></span></span></span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('userEditForm', (initialData) => ({
        formData: {
            ...initialData,
            password: '',
            confirm_password: ''
        },
        get initial() {
            if (this.formData.displayname) return this.formData.displayname.charAt(0).toUpperCase();
            if (this.formData.username) return this.formData.username.charAt(0).toUpperCase();
            return '?';
        },
        get statusClasses() {
            switch(this.formData.status) {
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

