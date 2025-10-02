<?php
// File: manage_users.php
// Manages user accounts: view, search, edit, delete.
// Accessible by 'SuperAdmin' and 'Admin' roles only.

// --- Production Error Reporting ---
ini_set('display_errors', 0); // Set to 0 in production
ini_set('display_startup_errors', 0); // Set to 0 in production
error_reporting(E_ALL); // Still log all errors, but don't display them to the user

// --- HTTP Security Headers (Place this at the very top before any output) ---
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0"); // Stronger caching prevention
header("Pragma: no-cache"); // For older HTTP/1.0 proxies
// header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload"); // HSTS: Uncomment ONLY if your site is fully HTTPS and you understand the implications

// Start session at the very beginning
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Assume BASE_PATH is defined by the router (e.g., in public_html/router.php)
// If BASE_PATH is not defined, this will result in a fatal error,
// indicating that the router setup is incomplete or incorrect.
if (!defined('BASE_PATH')) {
    // Fallback for direct access during development, but ideally router defines this.
    // This assumes manage_users.php is in public_html/users/ and public_html is the base.
    define('BASE_PATH', __DIR__ . '/..');
}

require_once BASE_PATH . '/config/config.php'; // Ensure this path is correct for your database connection ($pdo)

// Check for PDO connection after including config.php
if (!isset($pdo) || !$pdo instanceof PDO) {
    die('<div style="font-family: sans-serif; padding: 20px; background-color: #fdd; border: 1px solid #f99; color: #c00;">Error: Database connection ($pdo) not properly initialized in config.php.</div>');
}

// Set default timezone to Asia/Kolkata (IST) for consistent date/time handling
date_default_timezone_set('Asia/Kolkata');

// --- Define Logged-in State and User Role ---
$loggedIn = isset($_SESSION['user_id']);
$username = $loggedIn ? htmlspecialchars($_SESSION['username']) : null;
$userRole = $loggedIn ? ($_SESSION['user_role'] ?? 'Viewer') : null; // Default to 'Viewer' if not set

// --- Dark Mode Check ---
$isDarkMode = isset($_SESSION['dark_mode']) && $_SESSION['dark_mode'] === true;


// Session Management & Inactivity Timeout
const INACTIVITY_TIMEOUT = 1800; // 30 minutes in seconds

// 30-minute inactivity timeout for non-"remember me" sessions
if ($loggedIn && !isset($_SESSION['remember_me'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > INACTIVITY_TIMEOUT)) {
        session_unset();
        session_destroy();
        session_start(); // Re-start session to store new message
        $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-hourglass-end mr-2"></i> Session expired due to inactivity. Please log in again.</div>';
        header("Location: /login.php?timeout=1"); // Router-friendly redirect
        exit;
    }
    $_SESSION['last_activity'] = time(); // Update last activity time
}

// --- INITIAL AUTHORIZATION CHECKS (PHP Header Redirects for Page Access) ---

// 1. If NOT Logged In: Immediate PHP header("Location: login.php"); exit;
if (!$loggedIn) {
    $_SESSION['message'] = '<div class="alert alert-info"><i class="fas fa-info-circle mr-2"></i> Please log in to access this page.</div>';
    header("Location: /login.php"); // Router-friendly redirect
    exit; // Crucial: Stop script execution after redirect
}

// 2. If Logged In but UNAUTHORIZED ROLE (not Admin/SuperAdmin): Immediate PHP header("Location: dashboard.php"); exit;
if ($userRole !== 'SuperAdmin' && $userRole !== 'Admin') {
    $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-lock mr-2"></i> You do not have the required permissions to access this page.</div>';
    header("Location: /dashboard.php"); // Router-friendly redirect
    exit; // Crucial: Stop script execution after redirect
}

// --- If script execution reaches here, the user is authenticated and authorized ---

// Initialize variables for filtering, sorting, and pagination
$search = $_GET['search'] ?? '';
$roleFilter = $_GET['role'] ?? '';
$statusFilter = $_GET['status'] ?? ''; // New status filter
$sortBy = $_GET['sort_by'] ?? 'user_id';
$sortOrder = $_GET['sort_order'] ?? 'ASC';
$limit = 10; // Number of users per page
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$allowedSortBy = ['username', 'email', 'user_role', 'created_at', 'user_id', 'displayname', 'status']; // Added status for sorting
if (!in_array($sortBy, $allowedSortBy)) {
    $sortBy = 'user_id'; // Default to a safe column
}

$allowedSortOrder = ['ASC', 'DESC'];
if (!in_array(strtoupper($sortOrder), $allowedSortOrder)) {
    $sortOrder = 'ASC'; // Default to a safe order
}

// Build the WHERE clause for search and role filter
$whereClauses = [];
// This array will hold only the parameters for the WHERE clause
$whereParams = []; // Will be used for both count and main queries

if (!empty($search)) {
    $searchPattern = '%' . $search . '%';
    $searchFields = ['username', 'email', 'first_name', 'last_name', 'displayname'];
    $searchConditions = [];
    foreach ($searchFields as $field) {
        $paramName = ":search_" . $field; // Create a unique parameter name for each field
        $searchConditions[] = "$field LIKE $paramName";
        $whereParams[$paramName] = $searchPattern;
    }
    $whereClauses[] = "(" . implode(" OR ", $searchConditions) . ")";
}

$allowedRoles = ['SuperAdmin', 'Admin', 'Editor', 'Viewer'];
if (!empty($roleFilter) && in_array($roleFilter, $allowedRoles)) {
    $whereClauses[] = "user_role = :role_filter";
    $whereParams[':role_filter'] = $roleFilter;
}

$allowedStatuses = ['active', 'inactive', 'pending', 'suspended', 'locked']; // Define allowed statuses
if (!empty($statusFilter) && in_array($statusFilter, $allowedStatuses)) {
    $whereClauses[] = "status = :status_filter";
    $whereParams[':status_filter'] = $statusFilter;
}

$whereSql = '';
if (!empty($whereClauses)) {
    $whereSql = " WHERE " . implode(" AND ", $whereClauses);
}

// Get total number of users for pagination
try {
    $sqlCount = "SELECT COUNT(*) FROM users" . $whereSql;
    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->execute($whereParams); // Use $whereParams for count query
    $totalUsers = $stmtCount->fetchColumn();
    $totalPages = ceil($totalUsers / $limit);
} catch (PDOException $e) {
    error_log("Database error fetching user count: " . $e->getMessage());
    $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Error fetching user count.</div>';
    $totalUsers = 0;
    $totalPages = 0;
}

// Fetch users
$users = [];
try {
    $sql = "SELECT user_id, username, email, first_name, last_name, displayname, phone_number, user_role, status, created_at FROM users"
         . $whereSql
         . " ORDER BY " . $sortBy . " " . $sortOrder
         . " LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    // Bind where parameters
    foreach ($whereParams as $key => &$val) {
        $stmt->bindParam($key, $val);
    }
    
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Database error fetching users: " . $e->getMessage());
    $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Error fetching users.</div>';
}

$pageTitle = "User Management - Admin Panel";

// CSRF token for delete actions
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle delete action (via POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Invalid CSRF token. Please try again.</div>';
        header("Location: /users/manage_users.php"); // Router-friendly redirect
        exit;
    }

    $userIdToDelete = $_POST['user_id'] ?? null;
    $usernameToDelete = $_POST['username_to_delete'] ?? null; // Added to prevent deleting self by ID manipulation

    if ($userIdToDelete && is_numeric($userIdToDelete)) {
        // Fetch the role of the user being deleted for granular permission check
        try {
            $stmtFetchTargetRole = $pdo->prepare("SELECT user_role, username FROM users WHERE user_id = :user_id");
            $stmtFetchTargetRole->execute([':user_id' => $userIdToDelete]);
            $targetUserData = $stmtFetchTargetRole->fetch(PDO::FETCH_ASSOC);

            if (!$targetUserData) {
                $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> User not found for deletion.</div>';
                header("Location: /users/manage_users.php"); // Router-friendly redirect
                exit;
            }

            $targetUserRole = $targetUserData['user_role'];
            $targetUsername = $targetUserData['username'];

            // Prevent deleting your own account
            if ((int)$userIdToDelete === (int)$_SESSION['user_id']) {
                $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> You cannot delete your own account.</div>';
                header("Location: /users/manage_users.php"); // Router-friendly redirect
                exit;
            }

            // Prevent Admin from deleting SuperAdmin
            if ($userRole === 'Admin' && $targetUserRole === 'SuperAdmin') {
                $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> As an Admin, you cannot delete a SuperAdmin account.</div>';
                header("Location: /users/manage_users.php"); // Router-friendly redirect
                exit;
            }

            // Optional: Re-confirm username match (for extra security, though ID is primary)
            if ($usernameToDelete && $usernameToDelete !== $targetUsername) {
                $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Username mismatch during deletion. Operation aborted.</div>';
                header("Location: /users/manage_users.php"); // Router-friendly redirect
                exit;
            }

            // Perform deletion
            $stmtDelete = $pdo->prepare("DELETE FROM users WHERE user_id = :user_id");
            if ($stmtDelete->execute([':user_id' => $userIdToDelete])) {
                $_SESSION['message'] = '<div class="alert alert-success"><i class="fas fa-check-circle mr-2"></i> User "'. htmlspecialchars($targetUsername) .'" successfully deleted.</div>';
            } else {
                $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Failed to delete user "'. htmlspecialchars($targetUsername) .'".</div>';
            }
        } catch (PDOException $e) {
            error_log("Database error deleting user: " . $e->getMessage());
            $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> A database error occurred during deletion.</div>';
        }
    } else {
        $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Invalid user ID for deletion.</div>';
    }

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Regenerate token after action
    header("Location: /users/manage_users.php"); // Router-friendly redirect
    exit;
}

?>
<!DOCTYPE html>
<!-- Consolidated all Alpine.js state variables onto the <html> tag -->
<html lang="en" class="<?php echo $isDarkMode ? 'dark' : ''; ?>" x-data="{
    sidebarOpen: false,
    profileMenuOpen: false,
    mobileProfileMenuOpen: false,
    moreMenuOpen: false,
    deleteModalOpen: false,
    userToDeleteId: null,
    userToDeleteUsername: '',
    deleteConfirmationInput: '',
    deleteError: '',
    viewModalOpen: false,
    viewedUser: {}
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
        /* This hides elements until Alpine.js initializes, preventing FOUC (Flash Of Unstyled Content) */
        [x-cloak] { display: none !important; }
        body { font-family: 'Inter', sans-serif; overflow-x: hidden; } /* Ensure no horizontal overflow from body */

        /* General Alert Styles - IMPORTANT: Keep these consistent across all pages */
        .alert {
            border-radius: 4px;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
            font-size: 0.95rem;
            line-height: 1.4;
            font-weight: 500;
            max-width: 100%;
            margin-left: auto;
            margin-right: auto;
        }
        .alert i {
            margin-right: 0.5rem;
            margin-top: 0.125rem;
            font-size: 1.1rem;
        }
        .alert-success {
            background-color: #dcfce7;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }
        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
        }
        .alert-error i {
            color: #dc2626;
        }
        .alert-info {
            background-color: #e0f2fe;
            color: #0284c7;
            border: 1px solid #7dd3fc;
        }
        .alert-info i {
            color: #0369a1;
        }

        /* Form input consistency */
        .form-input {
            width: 100%;
            padding: 0.625rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 1rem;
            transition: border-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }

        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        /* Custom styles for responsive table */
        @media (max-width: 767px) {
            .responsive-table thead {
                display: none;
            }
            .responsive-table, .responsive-table tbody {
                display: block;
                width: 100%;
            }
            .responsive-table tr {
                display: flex;
                flex-direction: column;
                margin-bottom: 1rem;
                border: 1px solid #e5e7eb;
                border-radius: 4px;
                overflow: hidden;
                box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            }
            .dark .responsive-table tr {
                border-color: #374151;
            }
            .responsive-table td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                text-align: right;
                padding: 0.75rem 1rem;
                border-bottom: 1px solid #e5e7eb;
                order: 1; /* Default order for all cells */
            }
            .dark .responsive-table td {
                border-bottom-color: #374151;
            }
            .responsive-table td:last-child {
                border-bottom: 0;
            }
            .responsive-table td::before {
                content: attr(data-label);
                font-weight: 600;
                text-align: left;
                padding-right: 1rem;
                color: #374151;
            }
            .dark .responsive-table td::before {
                color: #e5e7eb;
            }
             .responsive-table td.mobile-first {
                order: -1; /* This will move the element with this class to the top */
            }
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900" x-cloak>

<?php require_once BASE_PATH . '/layout/headersidebar.php'; // Includes your header and sidebar ?>

<main class="md:ml-0 p-4 sm:p-6 lg:p-0">
    <div class="max-w-7xl mx-auto py-0">
        <?php
        // Centralized function to display session messages
        function display_session_message() {
            if (isset($_SESSION['message'])) {
                echo $_SESSION['message'];
                unset($_SESSION['message']); // Clear the message after displaying it
            }
        }
        display_session_message();
        ?>

        <div class="bg-white dark:bg-gray-800 shadow-md overflow-hidden p-4 sm:p-6 rounded">
             <div class="flex flex-col md:flex-row items-center justify-between gap-4 mb-6">
                <div class="flex items-center gap-3">
                    <i class="fas fa-users-cog text-3xl text-indigo-900 dark:text-indigo-400"></i>
                    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Manage Users</h2>
                </div>
                <div class="flex flex-col sm:flex-row items-center gap-2 w-full md:w-auto">
                    <!-- Search Form -->
                    <form method="GET" class="flex items-stretch rounded border border-slate-300 dark:border-slate-600 overflow-hidden w-full sm:w-auto">
                        <div class="relative flex-1">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500"></i>
                            <input type="text" name="search" placeholder="Search..."
                                   class="w-full pl-10 pr-3 py-2 text-sm bg-white text-gray-900 dark:bg-gray-700 dark:text-gray-300 focus:outline-none border-0"
                                   value="<?= htmlspecialchars($search ?? '') ?>">
                        </div>

                        <?php if (!empty($search)): ?>
                            <a href="/users/manage_users.php" class="flex items-center justify-center px-4 py-2 bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300 font-semibold hover:bg-slate-300 dark:hover:bg-slate-600 transition-colors text-sm flex-shrink-0">
                                <i class="fas fa-times text-base mr-2"></i>
                                <span class="hidden sm:inline">Reset</span>
                            </a>
                        <?php else: ?>
                            <button type="submit"
                                    class="flex items-center justify-center px-4 bg-indigo-900 dark:bg-indigo-600 text-white hover:bg-indigo-900/90 dark:hover:bg-indigo-600/90 transition-colors">
                                <i class="fas fa-arrow-right"></i>
                            </button>
                        <?php endif; ?>
                    </form>

                    <!-- Add New User Button -->
                    <a href="/users/create_user.php"
                       class="inline-flex items-center justify-center px-4 py-2 bg-indigo-900 dark:bg-indigo-600 text-white text-sm font-semibold rounded shadow-md hover:bg-indigo-900/90 dark:hover:bg-indigo-600/90 transition-all duration-300 whitespace-nowrap w-full sm:w-auto">
                        <i class="fas fa-plus mr-2"></i> Add New User
                    </a>
                </div>
            </div>

            <?php if (empty($users)): ?>
                <div class="bg-blue-50 dark:bg-gray-700 border border-blue-200 dark:border-blue-800 text-blue-800 dark:text-blue-200 p-5 rounded text-center shadow-inner">
                    <i class="fas fa-info-circle text-3xl text-blue-500 dark:text-blue-400 mb-2"></i>
                    <p class="text-base font-medium">No users found matching your criteria.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full border-collapse responsive-table">
                        <thead class="bg-purple-100 dark:bg-gray-700">
                            <tr>
                                 <th scope="col" class="px-6 py-3 text-left text-sm font-semibold text-purple-800 dark:text-purple-300 uppercase tracking-wider border border-gray-200 dark:border-gray-600">
                                    <a href="?search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=user_id&sort_order=<?= ($sortBy === 'user_id' && $sortOrder === 'ASC') ? 'DESC' : 'ASC' ?>" class="inline-flex items-center">ID<span class="ml-1"><?php if ($sortBy === 'user_id'): ?><i class="fas fa-arrow-<?= ($sortOrder === 'ASC') ? 'up' : 'down' ?>"></i><?php else: ?><i class="fas fa-sort text-gray-400"></i><?php endif; ?></span></a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-sm font-semibold text-purple-800 dark:text-purple-300 uppercase tracking-wider border border-gray-200 dark:border-gray-600">
                                     <a href="?search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=username&sort_order=<?= ($sortBy === 'username' && $sortOrder === 'ASC') ? 'DESC' : 'ASC' ?>" class="inline-flex items-center">Username<span class="ml-1"><?php if ($sortBy === 'username'): ?><i class="fas fa-arrow-<?= ($sortOrder === 'ASC') ? 'up' : 'down' ?>"></i><?php else: ?><i class="fas fa-sort text-gray-400"></i><?php endif; ?></span></a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-sm font-semibold text-purple-800 dark:text-purple-300 uppercase tracking-wider border border-gray-200 dark:border-gray-600">
                                     <a href="?search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=email&sort_order=<?= ($sortBy === 'email' && $sortOrder === 'ASC') ? 'DESC' : 'ASC' ?>" class="inline-flex items-center">Email<span class="ml-1"><?php if ($sortBy === 'email'): ?><i class="fas fa-arrow-<?= ($sortOrder === 'ASC') ? 'up' : 'down' ?>"></i><?php else: ?><i class="fas fa-sort text-gray-400"></i><?php endif; ?></span></a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-sm font-semibold text-purple-800 dark:text-purple-300 uppercase tracking-wider border border-gray-200 dark:border-gray-600">
                                     <a href="?search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=user_role&sort_order=<?= ($sortBy === 'user_role' && $sortOrder === 'ASC') ? 'DESC' : 'ASC' ?>" class="inline-flex items-center">Role<span class="ml-1"><?php if ($sortBy === 'user_role'): ?><i class="fas fa-arrow-<?= ($sortOrder === 'ASC') ? 'up' : 'down' ?>"></i><?php else: ?><i class="fas fa-sort text-gray-400"></i><?php endif; ?></span></a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-sm font-semibold text-purple-800 dark:text-purple-300 uppercase tracking-wider border border-gray-200 dark:border-gray-600">
                                    <a href="?search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=status&sort_order=<?= ($sortBy === 'status' && $sortOrder === 'ASC') ? 'DESC' : 'ASC' ?>" class="inline-flex items-center">Status<span class="ml-1"><?php if ($sortBy === 'status'): ?><i class="fas fa-arrow-<?= ($sortOrder === 'ASC') ? 'up' : 'down' ?>"></i><?php else: ?><i class="fas fa-sort text-gray-400"></i><?php endif; ?></span></a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-sm font-semibold text-purple-800 dark:text-purple-300 uppercase tracking-wider border border-gray-200 dark:border-gray-600">
                                    <a href="?search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=created_at&sort_order=<?= ($sortBy === 'created_at' && $sortOrder === 'ASC') ? 'DESC' : 'ASC' ?>" class="inline-flex items-center">Created<span class="ml-1"><?php if ($sortBy === 'created_at'): ?><i class="fas fa-arrow-<?= ($sortOrder === 'ASC') ? 'up' : 'down' ?>"></i><?php else: ?><i class="fas fa-sort text-gray-400"></i><?php endif; ?></span></a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-sm font-semibold text-purple-800 dark:text-purple-300 uppercase tracking-wider border border-gray-200 dark:border-gray-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800">
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td data-label="ID" class="px-6 py-2 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-600"><?= htmlspecialchars($user['user_id']) ?></td>
                                    <td data-label="Username" class="mobile-first px-6 py-2 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-600 md:bg-transparent bg-indigo-200 dark:bg-indigo-900/50"><?= htmlspecialchars($user['username']) ?></td>
                                    <td data-label="Email" class="px-6 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 border border-gray-200 dark:border-gray-600"><?= htmlspecialchars($user['email']) ?></td>
                                    <td data-label="Role" class="px-6 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 border border-gray-200 dark:border-gray-600"><?= htmlspecialchars($user['user_role']) ?></td>
                                    <td data-label="Status" class="px-6 py-2 whitespace-nowrap text-sm text-gray-500 capitalize border border-gray-200 dark:border-gray-600">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                            <?php
                                            if ($user['status'] == 'active') echo 'bg-green-100 text-green-800';
                                            elseif ($user['status'] == 'inactive') echo 'bg-red-100 text-red-800';
                                            elseif ($user['status'] == 'pending') echo 'bg-yellow-100 text-yellow-800';
                                            elseif ($user['status'] == 'suspended') echo 'bg-orange-100 text-orange-800';
                                            elseif ($user['status'] == 'locked') echo 'bg-purple-100 text-purple-800';
                                            else echo 'bg-gray-100 text-gray-800';
                                            ?>">
                                            <?= htmlspecialchars(ucfirst($user['status'])) ?>
                                        </span>
                                    </td>
                                    <td data-label="Created" class="px-6 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 border border-gray-200 dark:border-gray-600"><?= date('M d, Y', strtotime($user['created_at'])) ?></td>
                                    <td data-label="Actions" class="px-6 py-2 whitespace-nowrap text-left text-sm font-medium border border-gray-200 dark:border-gray-600">
                                        <div class="flex items-center justify-end flex-wrap gap-2">
                                            <button @click="viewModalOpen = true; viewedUser = <?= htmlspecialchars(json_encode($user), ENT_QUOTES, 'UTF-8') ?>;" class="inline-flex items-center px-2 py-1 bg-indigo-900 text-white text-xs font-bold rounded hover:bg-indigo-900/90 transform hover:scale-120 transition-all duration-200" title="View User"><i class="fas fa-eye mr-1"></i> View</button>
                                            <a href="/users/edit_user.php?user_id=<?= $user['user_id'] ?>" class="inline-flex items-center px-2 py-1 bg-orange-600 text-white text-xs font-bold rounded hover:bg-orange-700 transform hover:scale-120 transition-all duration-200" title="Edit User"><i class="fas fa-edit mr-1"></i> Edit</a>
                                            <?php if (!($user['user_role'] === 'SuperAdmin' && $userRole === 'Admin') && ((int)$user['user_id'] !== (int)$_SESSION['user_id'])): ?>
                                                <button type="button" @click="deleteModalOpen = true; userToDeleteId = <?= $user['user_id'] ?>; userToDeleteUsername = '<?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?>';" class="inline-flex items-center px-2 py-1 bg-red-600 text-white text-xs font-bold rounded hover:bg-red-700 transform hover:scale-120 transition-all duration-200" title="Delete User"><i class="fas fa-trash-alt mr-1"></i> Delete</button>
                                            <?php else: ?>
                                                <button class="inline-flex items-center px-2 py-1 bg-gray-300 text-white text-xs font-bold rounded cursor-not-allowed" title="Cannot delete this user" disabled><i class="fas fa-trash-alt mr-1"></i> Delete</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                <div class="mt-6">
                    <!-- Desktop Pagination -->
                    <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm text-gray-700 dark:text-gray-300">
                                Showing <span class="font-medium"><?= $totalUsers > 0 ? (($page - 1) * $limit) + 1 : 0 ?></span>
                                to <span class="font-medium"><?= min($page * $limit, $totalUsers) ?></span>
                                of <span class="font-medium"><?= $totalUsers ?></span> results
                            </p>
                        </div>
                        <div>
                            <nav class="relative z-0 inline-flex rounded shadow-sm -space-x-px" aria-label="Pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?= $page - 1 ?>&search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=<?= htmlspecialchars($sortBy) ?>&sort_order=<?= htmlspecialchars($sortOrder) ?>" class="relative inline-flex items-center justify-center w-10 h-10 rounded-l border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-sm font-medium text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700"><i class="fas fa-chevron-left"></i></a>
                                <?php endif; ?>
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <a href="?page=<?= $i ?>&search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=<?= htmlspecialchars($sortBy) ?>&sort_order=<?= htmlspecialchars($sortOrder) ?>" class="<?= $i === $page ? 'z-10 bg-indigo-50 dark:bg-indigo-900 border-indigo-500 dark:border-indigo-500 text-indigo-600 dark:text-white' : 'bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700' ?> relative inline-flex items-center justify-center w-10 h-10 border text-sm font-medium"><?= $i ?></a>
                                <?php endfor; ?>
                                <?php if ($page < $totalPages): ?>
                                    <a href="?page=<?= $page + 1 ?>&search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=<?= htmlspecialchars($sortBy) ?>&sort_order=<?= htmlspecialchars($sortOrder) ?>" class="relative inline-flex items-center justify-center w-10 h-10 rounded-r border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-sm font-medium text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700"><i class="fas fa-chevron-right"></i></a>
                                <?php endif; ?>
                            </nav>
                        </div>
                    </div>
                    <!-- Mobile Pagination -->
                    <div class="sm:hidden flex items-center justify-between">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>&search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=<?= htmlspecialchars($sortBy) ?>&sort_order=<?= htmlspecialchars($sortOrder) ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 text-sm font-medium rounded text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700">Previous</a>
                        <?php endif; ?>
                        <span class="text-sm text-gray-700 dark:text-gray-300">Page <?= $page ?> of <?= $totalPages ?></span>
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?= $page + 1 ?>&search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=<?= htmlspecialchars($sortBy) ?>&sort_order=<?= htmlspecialchars($sortOrder) ?>" class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 text-sm font-medium rounded text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Modals -->
<!-- Delete Confirmation Modal -->
<div class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black bg-opacity-50"
     x-show="deleteModalOpen"
     x-transition:enter="ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @keydown.escape.window="deleteModalOpen = false">
    <div class="bg-white dark:bg-gray-800 rounded p-6 shadow-2xl max-w-sm w-full transform transition-all" @click.away="deleteModalOpen = false">
        <div class="flex items-center justify-center mb-3">
            <div class="bg-red-100 text-red-600 p-3 rounded-full flex items-center justify-center w-12 h-12">
                <i class="fas fa-trash-alt text-2xl"></i>
            </div>
        </div>
        <h3 class="text-xl font-bold text-slate-800 dark:text-gray-200 text-center mb-1">Confirm Deletion</h3>
        <p class="text-center text-slate-500 dark:text-gray-400 text-sm mb-4">You are about to permanently delete user "<strong x-text="userToDeleteUsername"></strong>". This action cannot be undone. Please type "<strong>delete</strong>" to confirm.</p>
        <form id="deleteUserForm" method="POST" action="/users/manage_users.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="user_id" x-model="userToDeleteId">
            <input type="hidden" name="username_to_delete" x-model="userToDeleteUsername">
            <div class="mb-3">
                <input type="text" x-model="deleteConfirmationInput" x-ref="deleteConfirmInput" class="w-full px-3 py-2 text-sm border dark:bg-gray-700 dark:border-slate-600 dark:text-gray-300 rounded focus:ring-1 focus:ring-red-400 focus:border-red-400 focus:outline-none transition" :class="deleteConfirmationInput === 'delete' ? 'border-green-500' : 'border-slate-300'" placeholder="type 'delete' to confirm">
                <p x-show="deleteError" x-text="deleteError" class="text-red-600 text-xs mt-1 font-semibold"></p>
            </div>
            <div class="flex gap-3">
                <button type="button" @click="deleteModalOpen = false; deleteError = ''; deleteConfirmationInput = '';" class="flex-1 px-4 py-2 bg-slate-200 dark:bg-slate-600 text-slate-700 dark:text-slate-200 font-semibold rounded hover:bg-slate-300 dark:hover:bg-slate-500 transition text-sm">Cancel</button>
                <button type="button" @click="if (deleteConfirmationInput === 'delete') { $el.closest('form').submit(); } else { deleteError = 'Incorrect confirmation text.'; }" class="flex-1 px-4 py-2 bg-red-600 text-white font-semibold rounded hover:bg-red-700 transition text-sm">Delete User</button>
            </div>
        </form>
    </div>
</div>

<!-- View User Details Modal -->
<div class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black bg-opacity-50"
     x-show="viewModalOpen"
     x-transition:enter="ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @keydown.escape.window="viewModalOpen = false">
    <div class="bg-white dark:bg-gray-800 rounded p-4 sm:p-6 shadow-2xl max-w-md w-full transform transition-all" @click.away="viewModalOpen = false">
        <div class="flex items-center justify-center mb-3">
            <div class="bg-indigo-100 text-indigo-600 p-3 rounded-full flex items-center justify-center w-12 h-12">
                <i class="fas fa-user-circle text-2xl"></i>
            </div>
        </div>
        <h3 class="text-xl font-bold text-slate-800 dark:text-gray-200 text-center mb-1">User Details</h3>
        <p class="text-center text-slate-500 dark:text-gray-400 text-sm mb-4" x-text="'Details for ' + viewedUser.username"></p>
        <hr class="border-gray-200 dark:border-gray-600 my-4">
        
        <!-- Responsive Details List -->
        <div class="mt-4 space-y-3 text-sm">
            <div class="flex justify-between items-start gap-4 border-b border-gray-100 dark:border-gray-700 pb-2">
                <span class="font-semibold text-gray-600 dark:text-gray-300">User ID:</span>
                <span class="text-gray-800 dark:text-gray-200 text-right" x-text="viewedUser.user_id"></span>
            </div>
            <div class="flex justify-between items-start gap-4 border-b border-gray-100 dark:border-gray-700 pb-2">
                <span class="font-semibold text-gray-600 dark:text-gray-300">Username:</span>
                <span class="text-gray-800 dark:text-gray-200 text-right break-all" x-text="viewedUser.username"></span>
            </div>
            <div class="flex justify-between items-start gap-4 border-b border-gray-100 dark:border-gray-700 pb-2">
                <span class="font-semibold text-gray-600 dark:text-gray-300">Email:</span>
                <span class="text-gray-800 dark:text-gray-200 text-right break-all" x-text="viewedUser.email"></span>
            </div>
            <template x-if="viewedUser.first_name">
                <div class="flex justify-between items-start gap-4 border-b border-gray-100 dark:border-gray-700 pb-2">
                    <span class="font-semibold text-gray-600 dark:text-gray-300">First Name:</span>
                    <span class="text-gray-800 dark:text-gray-200 text-right break-all" x-text="viewedUser.first_name"></span>
                </div>
            </template>
            <template x-if="viewedUser.last_name">
                <div class="flex justify-between items-start gap-4 border-b border-gray-100 dark:border-gray-700 pb-2">
                    <span class="font-semibold text-gray-600 dark:text-gray-300">Last Name:</span>
                    <span class="text-gray-800 dark:text-gray-200 text-right break-all" x-text="viewedUser.last_name"></span>
                </div>
            </template>
            <template x-if="viewedUser.displayname">
                <div class="flex justify-between items-start gap-4 border-b border-gray-100 dark:border-gray-700 pb-2">
                    <span class="font-semibold text-gray-600 dark:text-gray-300">Display Name:</span>
                    <span class="text-gray-800 dark:text-gray-200 text-right break-all" x-text="viewedUser.displayname"></span>
                </div>
            </template>
            <template x-if="viewedUser.phone_number">
                <div class="flex justify-between items-start gap-4 border-b border-gray-100 dark:border-gray-700 pb-2">
                    <span class="font-semibold text-gray-600 dark:text-gray-300">Phone:</span>
                    <span class="text-gray-800 dark:text-gray-200 text-right break-all" x-text="viewedUser.phone_number"></span>
                </div>
            </template>
            <div class="flex justify-between items-start gap-4 border-b border-gray-100 dark:border-gray-700 pb-2">
                <span class="font-semibold text-gray-600 dark:text-gray-300">Role:</span>
                <span class="text-gray-800 dark:text-gray-200 text-right" x-text="viewedUser.user_role"></span>
            </div>
            <div class="flex justify-between items-start gap-4 border-b border-gray-100 dark:border-gray-700 pb-2">
                <span class="font-semibold text-gray-600 dark:text-gray-300">Status:</span>
                <span class="capitalize px-2 inline-flex text-xs leading-5 font-semibold rounded-full" :class="{
                    'bg-green-100 text-green-800': viewedUser.status == 'active',
                    'bg-red-100 text-red-800': viewedUser.status == 'inactive',
                    'bg-yellow-100 text-yellow-800': viewedUser.status == 'pending',
                    'bg-orange-100 text-orange-800': viewedUser.status == 'suspended',
                    'bg-purple-100 text-purple-800': viewedUser.status == 'locked'
                }" x-text="viewedUser.status"></span>
            </div>
             <div class="flex justify-between items-start gap-4 pb-2">
                <span class="font-semibold text-gray-600 dark:text-gray-300">Created At:</span>
                <span class="text-gray-800 dark:text-gray-200 text-right" x-text="new Date(viewedUser.created_at).toLocaleString()"></span>
            </div>
        </div>
        
        <div class="mt-6 flex items-center gap-3">
            <button type="button" @click="viewModalOpen = false; deleteModalOpen = true; userToDeleteId = viewedUser.user_id; userToDeleteUsername = viewedUser.username;" class="flex-1 justify-center inline-flex px-4 py-2 bg-red-600 text-white font-semibold rounded hover:bg-red-700 transition text-sm">Delete</button>
            <a x-bind:href="'/users/edit_user.php?user_id=' + viewedUser.user_id" class="flex-1 justify-center inline-flex px-4 py-2 bg-orange-600 text-white font-semibold rounded hover:bg-orange-700 transition text-sm">Edit</a>
            <button type="button" @click="viewModalOpen = false; viewedUser = {};" class="flex-1 justify-center inline-flex px-4 py-2 bg-slate-200 dark:bg-slate-600 text-slate-700 dark:text-slate-200 font-semibold rounded hover:bg-slate-300 dark:hover:bg-slate-500 transition text-sm">Close</button>
        </div>
    </div>
</div>
<script>
    function userManagement() {
        return {
            sidebarOpen: false,
            profileMenuOpen: false,
            mobileProfileMenuOpen: false,
            moreMenuOpen: false,
            deleteModalOpen: false,
            userToDeleteId: null,
            userToDeleteUsername: '',
            deleteConfirmationInput: '',
            deleteError: '',
            viewModalOpen: false,
            viewedUser: {}
        }
    }
</script>
</body>
</html>

