<?php
// File: user_management.php
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
    // This assumes user_management.php is in public_html/users/ and public_html is the base.
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
$sortBy = $_GET['sort_by'] ?? 'created_at';
$sortOrder = $_GET['sort_order'] ?? 'DESC';
$limit = 5; // Number of users per page
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$allowedSortBy = ['username', 'email', 'user_role', 'created_at', 'user_id', 'displayname', 'status']; // Added status for sorting
if (!in_array($sortBy, $allowedSortBy)) {
    $sortBy = 'created_at'; // Default to a safe column
}

$allowedSortOrder = ['ASC', 'DESC'];
if (!in_array(strtoupper($sortOrder), $allowedSortOrder)) {
    $sortOrder = 'DESC'; // Default to a safe order
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

    // Combine all parameters, including limit and offset, into one array
    $allParams = array_merge($whereParams, [
        ':limit' => $limit,
        ':offset' => $offset
    ]);

    $stmt->execute($allParams); // Pass all parameters in one go
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
        header("Location: /users/user_management.php"); // Router-friendly redirect
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
                header("Location: /users/user_management.php"); // Router-friendly redirect
                exit;
            }

            $targetUserRole = $targetUserData['user_role'];
            $targetUsername = $targetUserData['username'];

            // Prevent deleting your own account
            if ((int)$userIdToDelete === (int)$_SESSION['user_id']) {
                $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> You cannot delete your own account.</div>';
                header("Location: /users/user_management.php"); // Router-friendly redirect
                exit;
            }

            // Prevent Admin from deleting SuperAdmin
            if ($userRole === 'Admin' && $targetUserRole === 'SuperAdmin') {
                $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> As an Admin, you cannot delete a SuperAdmin account.</div>';
                header("Location: /users/user_management.php"); // Router-friendly redirect
                exit;
            }

            // Optional: Re-confirm username match (for extra security, though ID is primary)
            if ($usernameToDelete && $usernameToDelete !== $targetUsername) {
                $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Username mismatch during deletion. Operation aborted.</div>';
                header("Location: /users/user_management.php"); // Router-friendly redirect
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
    header("Location: /users/user_management.php"); // Router-friendly redirect
    exit;
}

?>
<!DOCTYPE html>
<!-- Consolidated all Alpine.js state variables onto the <html> tag -->
<html lang="en" x-data="{
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
            border-radius: 0.5rem;
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

        /* Table specific styles for responsiveness */
        .table-header {
            cursor: pointer;
            position: relative;
            padding-right: 20px; /* Space for sort icon */
        }
        .table-header .sort-icon {
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.8em;
            opacity: 0.4;
        }
        .table-header.active .sort-icon {
            opacity: 1;
            color: #3b82f6; /* Blue for active sort */
        }

        /* Form input consistency */
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

        /* Removed .table-auto-hide-sm as all columns will be visible */
    </style>
</head>
<body class="bg-gray-50" x-cloak>

<?php require_once BASE_PATH . '/layout/headersidebar.php'; // Includes your header and sidebar ?>

<main class="p-[20px] md:py-6 md:px-4 md:ml-64">
    <div class="max-w-full mx-auto py-0">
        <?php
        // Centralized function to display session messages
        // This function could be in a separate includes/helpers.php file
        function display_session_message() {
            if (isset($_SESSION['message'])) {
                echo $_SESSION['message'];
                unset($_SESSION['message']); // Clear the message after displaying it
            }
        }
        display_session_message();
        ?>

        <div class="bg-white rounded-xl shadow-md overflow-hidden p-6">
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 mb-6">
                <div class="flex items-center gap-3">
                    <i class="fas fa-users-cog text-2xl text-blue-600"></i>
                    <h1 class="text-2xl font-semibold text-gray-800">User Management</h1>
                </div>
                <!-- This button's width adapts to full width on small screens and auto on larger -->
                <a href="/users/create_user.php" class="w-full sm:w-auto inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-300 justify-center">
                    <i class="fas fa-plus mr-2"></i> Create New User
                </a>
            </div>

            <form method="GET" class="mb-6 bg-gray-50 p-4 rounded-lg shadow-sm">
                <!-- Grid layout for filters: single column on small, 3 columns on medium screens and up -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                        <input type="text" name="search" id="search" placeholder="Search by name, email, username..."
                               class="form-input" value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div>
                        <label for="role" class="block text-sm font-medium text-gray-700 mb-1">Filter by Role</label>
                        <select name="role" id="role" class="form-input">
                            <option value="">All Roles</option>
                            <?php foreach ($allowedRoles as $roleOption): ?>
                                <option value="<?= htmlspecialchars($roleOption) ?>"
                                    <?= ($roleFilter === $roleOption) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($roleOption) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Filter by Status</label>
                        <select name="status" id="status" class="form-input">
                            <option value="">All Statuses</option>
                            <?php foreach ($allowedStatuses as $statusOption): ?>
                                <option value="<?= htmlspecialchars($statusOption) ?>"
                                    <?= ($statusFilter === $statusOption) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(ucfirst($statusOption)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mt-4 flex flex-col sm:flex-row items-end gap-3 sm:justify-end">
                    <button type="submit" class="w-full sm:w-auto bg-blue-500 hover:bg-blue-600 text-white font-medium py-2 px-4 rounded-lg transition duration-300 flex items-center justify-center">
                        <i class="fas fa-filter mr-2"></i> Apply Filters
                    </button>
                    <!-- Reset Filter Button -->
                    <a href="/users/user_management.php" class="w-full sm:w-auto bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-lg transition duration-300 flex items-center justify-center">
                        <i class="fas fa-sync-alt mr-2"></i> Reset Filters
                    </a>
                </div>
            </form>

            <?php if (empty($users)): ?>
                <div class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-lg text-center">
                    <i class="fas fa-info-circle mr-2"></i> No users found matching your criteria.
                </div>
            <?php else: ?>
                <!-- Overflow-x-auto makes the table horizontally scrollable on small screens -->
                <div class="overflow-x-auto rounded-lg shadow-sm border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <a href="?search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=user_id&sort_order=<?= ($sortBy === 'user_id' && $sortOrder === 'ASC') ? 'DESC' : 'ASC' ?>"
                                       class="flex items-center whitespace-nowrap table-header <?= ($sortBy === 'user_id') ? 'active' : '' ?>">
                                        User ID
                                        <span class="sort-icon">
                                            <?php if ($sortBy === 'user_id'): ?>
                                                <i class="fas fa-arrow-<?= ($sortOrder === 'ASC') ? 'up' : 'down' ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort"></i>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <a href="?search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=username&sort_order=<?= ($sortBy === 'username' && $sortOrder === 'ASC') ? 'DESC' : 'ASC' ?>"
                                       class="flex items-center whitespace-nowrap table-header <?= ($sortBy === 'username') ? 'active' : '' ?>">
                                        Username
                                        <span class="sort-icon">
                                            <?php if ($sortBy === 'username'): ?>
                                                <i class="fas fa-arrow-<?= ($sortOrder === 'ASC') ? 'up' : 'down' ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort"></i>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <a href="?search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=email&sort_order=<?= ($sortBy === 'email' && $sortOrder === 'ASC') ? 'DESC' : 'ASC' ?>"
                                       class="flex items-center whitespace-nowrap table-header <?= ($sortBy === 'email') ? 'active' : '' ?>">
                                        Email
                                        <span class="sort-icon">
                                            <?php if ($sortBy === 'email'): ?>
                                                <i class="fas fa-arrow-<?= ($sortOrder === 'ASC') ? 'up' : 'down' ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort"></i>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <a href="?search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=displayname&sort_order=<?= ($sortBy === 'displayname' && $sortOrder === 'ASC') ? 'DESC' : 'ASC' ?>"
                                       class="flex items-center whitespace-nowrap table-header <?= ($sortBy === 'displayname') ? 'active' : '' ?>">
                                        Display Name
                                        <span class="sort-icon">
                                            <?php if ($sortBy === 'displayname'): ?>
                                                <i class="fas fa-arrow-<?= ($sortOrder === 'ASC') ? 'up' : 'down' ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort"></i>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <a href="?search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=user_role&sort_order=<?= ($sortBy === 'user_role' && $sortOrder === 'ASC') ? 'DESC' : 'ASC' ?>"
                                       class="flex items-center whitespace-nowrap table-header <?= ($sortBy === 'user_role') ? 'active' : '' ?>">
                                        Role
                                        <span class="sort-icon">
                                            <?php if ($sortBy === 'user_role'): ?>
                                                <i class="fas fa-arrow-<?= ($sortOrder === 'ASC') ? 'up' : 'down' ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort"></i>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <a href="?search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=status&sort_order=<?= ($sortBy === 'status' && $sortOrder === 'ASC') ? 'DESC' : 'ASC' ?>"
                                       class="flex items-center whitespace-nowrap table-header <?= ($sortBy === 'status') ? 'active' : '' ?>">
                                        Status
                                        <span class="sort-icon">
                                            <?php if ($sortBy === 'status'): ?>
                                                <i class="fas fa-arrow-<?= ($sortOrder === 'ASC') ? 'up' : 'down' ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort"></i>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <a href="?search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=created_at&sort_order=<?= ($sortBy === 'created_at' && $sortOrder === 'ASC') ? 'DESC' : 'ASC' ?>"
                                       class="flex items-center whitespace-nowrap table-header <?= ($sortBy === 'created_at') ? 'active' : '' ?>">
                                        Created At
                                        <span class="sort-icon">
                                            <?php if ($sortBy === 'created_at'): ?>
                                                <i class="fas fa-arrow-<?= ($sortOrder === 'ASC') ? 'up' : 'down' ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort"></i>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($user['user_id']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($user['username']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($user['email']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($user['displayname'] ?? 'N/A') ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($user['user_role']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 capitalize">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                            <?php
                                            // Status badge styling
                                            if ($user['status'] == 'active') echo 'bg-green-100 text-green-800';
                                            else if ($user['status'] == 'inactive') echo 'bg-red-100 text-red-800';
                                            else if ($user['status'] == 'pending') echo 'bg-yellow-100 text-yellow-800';
                                            else if ($user['status'] == 'suspended') echo 'bg-orange-100 text-orange-800';
                                            else if ($user['status'] == 'locked') echo 'bg-purple-100 text-purple-800';
                                            else echo 'bg-gray-100 text-gray-800'; // Default for unknown status
                                            ?>">
                                            <?= htmlspecialchars(ucfirst($user['status'])) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= date('Y-m-d H:i', strtotime($user['created_at'])) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-left text-sm font-medium">
                                        <!-- action-buttons-wrapper stacks action buttons vertically on small screens -->
                                        <div class="flex items-center space-x-2 action-buttons-wrapper">
                                            <!-- View User Button -->
                                            <button type="button"
                                                @click="viewModalOpen = true; viewedUser = <?= htmlspecialchars(json_encode($user), ENT_QUOTES, 'UTF-8') ?>;"
                                                class="text-blue-600 hover:text-blue-900" title="View User">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <a href="/users/edit_user.php?id=<?= $user['user_id'] ?>" class="text-indigo-600 hover:text-indigo-900" title="Edit User">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php
                                            // Only SuperAdmin can delete SuperAdmin accounts
                                            // An Admin cannot delete a SuperAdmin account
                                            $canDelete = true;
                                            if ($user['user_role'] === 'SuperAdmin' && $userRole === 'Admin') {
                                                $canDelete = false; // Admin cannot delete SuperAdmin
                                            }
                                            // User cannot delete their own account
                                            if ((int)$user['user_id'] === (int)$_SESSION['user_id']) {
                                                $canDelete = false;
                                            }

                                            if ($canDelete):
                                            ?>
                                            <!-- Updated delete button to open modal -->
                                            <button type="button"
                                                @click="deleteModalOpen = true; userToDeleteId = <?= $user['user_id'] ?>; userToDeleteUsername = '<?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?>'; deleteConfirmationInput = ''; deleteError = ''; $nextTick(() => $refs.deleteConfirmationInput.focus());"
                                                class="text-red-600 hover:text-red-900" title="Delete User">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                            <?php else: ?>
                                                <span class="text-gray-400 cursor-not-allowed" title="Cannot delete this user">
                                                    <i class="fas fa-trash-alt"></i>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <nav class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6">
                    <!-- Pagination navigation for small screens, hidden on larger -->
                    <div class="flex-1 flex justify-between sm:hidden">
                        <a href="?page=<?= max(1, $page - 1) ?>&search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=<?= htmlspecialchars($sortBy) ?>&sort_order=<?= htmlspecialchars($sortOrder) ?>"
                           class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            Previous
                        </a>
                        <a href="?page=<?= min($totalPages, $page + 1) ?>&search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=<?= htmlspecialchars($sortBy) ?>&sort_order=<?= htmlspecialchars($sortOrder) ?>"
                           class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            Next
                        </a>
                    </div>
                    <!-- Pagination navigation for larger screens, hidden on small -->
                    <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm text-gray-700">
                                Showing <span class="font-medium"><?= $totalUsers > 0 ? (($page - 1) * $limit) + 1 : 0 ?></span>
                                to <span class="font-medium"><?= min($page * $limit, $totalUsers) ?></span>
                                of <span class="font-medium"><?= $totalUsers ?></span> results
                            </p>
                        </div>
                        <div>
                            <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?= $page - 1 ?>&search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=<?= htmlspecialchars($sortBy) ?>&sort_order=<?= htmlspecialchars($sortOrder) ?>"
                                       class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                        <span class="sr-only">Previous</span>
                                        <i class="fas fa-chevron-left h-5 w-5"></i>
                                    </a>
                                <?php endif; ?>

                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <a href="?page=<?= $i ?>&search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=<?= htmlspecialchars($sortBy) ?>&sort_order=<?= htmlspecialchars($sortOrder) ?>"
                                       class="<?= $i === $page ? 'z-10 bg-blue-50 border-blue-500 text-blue-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50' ?> relative inline-flex items-center px-4 py-2 border text-sm font-medium">
                                        <?= $i ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                    <a href="?page=<?= $page + 1 ?>&search=<?= htmlspecialchars($search) ?>&role=<?= htmlspecialchars($roleFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&sort_by=<?= htmlspecialchars($sortBy) ?>&sort_order=<?= htmlspecialchars($sortOrder) ?>"
                                       class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                        <span class="sr-only">Next</span>
                                        <i class="fas fa-chevron-right h-5 w-5"></i>
                                    </a>
                                <?php endif; ?>
                            </nav>
                        </div>
                    </div>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Delete Confirmation Modal -->
<div class="fixed inset-0 z-[100] flex items-center justify-center p-4"
     x-show="deleteModalOpen"
     x-transition:enter="ease-out duration-300"
     x-transition:enter-start="opacity-0 scale-95"
     x-transition:enter-end="opacity-100 scale-100"
     x-transition:leave="ease-in duration-200"
     x-transition:leave-start="opacity-100 scale-100"
     x-transition:leave-end="opacity-0 scale-95"
     @click.away="deleteModalOpen = false; deleteError = ''; deleteConfirmationInput = '';">
    <div class="fixed inset-0 bg-black bg-opacity-50 transition-opacity" aria-hidden="true"></div>
    <div class="bg-white rounded-lg p-6 max-w-sm w-full shadow-xl relative z-10 sm:max-w-md">
        <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4" id="modal-title">Confirm Deletion</h3>
        <div class="mt-2">
            <p class="text-sm text-gray-700">
                You are about to permanently delete user "<strong x-text="userToDeleteUsername"></strong>". This action cannot be undone.
            </p>
            <p class="text-sm text-gray-700 mt-2">
                Please type "<strong>delete</strong>" in the box below to confirm.
            </p>
            <input type="text" x-model="deleteConfirmationInput" x-ref="deleteConfirmationInput"
                   class="mt-3 block w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-red-500 focus:border-red-500 sm:text-sm"
                   :class="deleteConfirmationInput === 'delete' ? 'border-green-500' : 'border-gray-300'"
                   placeholder="type 'delete' to confirm"
                   @keyup.enter="
                       if (deleteConfirmationInput === 'delete') {
                           document.getElementById('deleteUserForm').submit();
                       } else {
                           // Fixed: Used HTML entity for double quotes to prevent parsing errors
                           deleteError = 'Please type &quot;delete&quot; to confirm.';
                       }
                   ">
            <p x-show="deleteError" x-text="deleteError" class="text-red-600 text-xs mt-1 font-semibold"></p>
        </div>
        <div class="mt-4 flex flex-col sm:flex-row-reverse justify-end gap-3">
            <form id="deleteUserForm" method="POST" action="/users/user_management.php" class="w-full sm:w-auto">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" x-model="userToDeleteId">
                <input type="hidden" name="username_to_delete" x-model="userToDeleteUsername">
                <button type="button"
                        @click="
                            if (deleteConfirmationInput === 'delete') {
                                $el.closest('form').submit();
                            } else if (deleteConfirmationInput === '') {
                                // Fixed: Used HTML entity for double quotes to prevent parsing errors
                                deleteError = 'Please type &quot;delete&quot; to confirm and proceed.';
                            } else {
                                // Fixed: Used HTML entity for double quotes to prevent parsing errors
                                deleteError = 'Incorrect. Please type &quot;delete&quot; exactly to confirm.';
                            }
                        "
                        class="inline-flex justify-center w-full sm:w-auto py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors duration-200">
                    Delete User
                </button>
            </form>
            <button type="button"
                    @click="deleteModalOpen = false; deleteError = ''; deleteConfirmationInput = '';"
                    class="inline-flex justify-center w-full sm:w-auto py-2 px-4 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200">
                Cancel
            </button>
        </div>
    </div>
</div>

<!-- View User Details Modal -->
<div class="fixed inset-0 z-[100] flex items-center justify-center p-4"
     x-show="viewModalOpen"
     x-transition:enter="ease-out duration-300"
     x-transition:enter-start="opacity-0 scale-95"
     x-transition:enter-end="opacity-100 scale-100"
     x-transition:leave="ease-in duration-200"
     x-transition:leave-start="opacity-100 scale-100"
     x-transition:leave-end="opacity-0 scale-95"
     @click.away="viewModalOpen = false; viewedUser = {};">
    <div class="fixed inset-0 bg-black bg-opacity-50 transition-opacity" aria-hidden="true"></div>
    <div class="bg-white rounded-lg p-6 max-w-sm w-full shadow-xl relative z-10 sm:max-w-md">
        <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4" id="view-modal-title">User Details</h3>
        <div class="mt-2 text-gray-700 space-y-2">
            <p><strong>User ID:</strong> <span x-text="viewedUser.user_id"></span></p>
            <p><strong>Username:</strong> <span x-text="viewedUser.username"></span></p>
            <p><strong>Email:</strong> <span x-text="viewedUser.email"></span></p>
            <p x-show="viewedUser.first_name"><strong>First Name:</strong> <span x-text="viewedUser.first_name"></span></p>
            <p x-show="viewedUser.last_name"><strong>Last Name:</strong> <span x-text="viewedUser.last_name"></span></p>
            <p x-show="viewedUser.displayname"><strong>Display Name:</strong> <span x-text="viewedUser.displayname"></span></p>
            <p x-show="viewedUser.phone_number"><strong>Phone Number:</strong> <span x-text="viewedUser.phone_number"></span></p>
            <p><strong>Role:</strong> <span x-text="viewedUser.user_role"></span></p>
            <p><strong>Status:</strong> <span x-text="viewedUser.status"></span></p>
            <p><strong>Created At:</strong> <span x-text="new Date(viewedUser.created_at).toLocaleString()"></span></p>
            <!-- Password field intentionally omitted for security reasons. Passwords should only be stored as hashes and never displayed. -->
        </div>
        <div class="mt-6 flex justify-end">
            <button type="button"
                    @click="viewModalOpen = false; viewedUser = {};"
                    class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                Close
            </button>
        </div>
    </div>
</div>
</body>
</html>
