<?php
// File: categories.php
// Manages edition categories: view, add, edit, delete.
// Accessible by 'SuperAdmin' and 'Admin' roles only.

// --- CORS Headers ---
// This is a secure implementation that only allows requests from the same domain
// as the server, or a specifically trusted list of domains.
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http');
$currentDomain = $_SERVER['HTTP_HOST'];
$currentOrigin = $protocol . "://" . $currentDomain;

// Define a list of allowed origins. It's best practice to be explicit.
// In this case, we are only allowing the current origin.
$allowedOrigins = [$currentOrigin];

// You can add other trusted domains here for development or specific integrations.
// Example: $allowedOrigins = ['http://localhost:3000', $currentOrigin];

// Check if the request's origin is in our list of allowed origins.
if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
}

// These headers are still necessary to handle preflight requests for allowed origins.
header("Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");
header("Access-Control-Allow-Credentials: true");

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

// Corrected path for config.php:
// Assuming config.php is in a 'config' directory one level up from the current script.
// For example, if categories.php is in /public_html/editions/, and config.php is in /public_html/config/
require_once __DIR__ . '/../config/config.php';

// --- Include settingsvars.php for APP_TITLE ---
// The user has specified the file /vars/settingsvars.php
// We'll assume it's in a 'vars' directory at the same level as the 'config' directory.
require_once __DIR__ . '/../vars/settingsvars.php';

// --- Include logovars.php for Favicon and OG Image ---
require_once __DIR__ . '/../vars/logovars.php';


// Ensure the APP_TITLE constant is defined as a fallback, in case the included file doesn't define it.
if (!defined('APP_TITLE')) {
    define('APP_TITLE', 'Admin Panel');
}

// Define fallback constants for favicon and OG image if the file doesn't define them
if (!defined('APP_FAVICON_PATH')) {
    define('APP_FAVICON_PATH', '/img/favicon.ico');
}
if (!defined('OG_IMAGE_PATH')) {
    define('OG_IMAGE_PATH', '/img/og-image.png');
}

// --- Define Logged-in State and User Role ---
$loggedIn = isset($_SESSION['user_id']);
$username = $loggedIn ? htmlspecialchars($_SESSION['username']) : null;
$userRole = $loggedIn ? ($_SESSION['user_role'] ?? 'Viewer') : null; // Default to 'Viewer' if not set

// Session Management & Inactivity Timeout
const INACTIVITY_TIMEOUT = 1800; // 30 minutes in seconds

if ($loggedIn && !isset($_SESSION['remember_me'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > INACTIVITY_TIMEOUT)) {
        session_unset();
        session_destroy();
        session_start(); // Re-start session to store new message
        $_SESSION['message'] = '<div class="alert alert-error"><span class="material-icons mr-2">error</span> Session expired due to inactivity. Please log in again.</div>';
        header("Location: login.php?timeout=1");
        exit;
    }
    $_SESSION['last_activity'] = time(); // Update last activity time
}

// --- INITIAL AUTHORIZATION CHECKS ---
if (!$loggedIn) {
    $_SESSION['message'] = '<div class="alert alert-info"><span class="material-icons mr-2">info</span> Please log in to access this page.</div>';
    header("Location: login.php");
    exit;
}

if ($userRole !== 'SuperAdmin' && $userRole !== 'Admin' && $userRole !== 'Editor') {
    $_SESSION['message'] = '<div class="alert alert-error"><span class="material-icons mr-2">lock</span> You do not have the required permissions to access this page.</div>';
    header("Location: dashboard");
    exit;
}

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Set the page title dynamically
$pageTitle = "Manage Categories";

// Helper function to build a hierarchical tree from a flat list
function buildCategoryTree(array $elements, $parentId = null) {
    $branch = [];
    foreach ($elements as $element) {
        // Strict comparison or check against null/empty for parent_id
        // Normalize parent_id to null if it's empty string or 0, before comparison
        $currentParentId = (empty($element['parent_id']) && $element['parent_id'] !== 0) ? null : (int)$element['parent_id'];

        if ($currentParentId === $parentId) {
            $children = buildCategoryTree($elements, $element['category_id']);
            if ($children) {
                $element['children'] = $children;
            }
            $branch[] = $element;
        }
    }
    return $branch;
}

// Helper function to flatten the tree with level information for display
function flattenTree($branch, &$result, $level = 0) {
    foreach ($branch as $node) {
        $node['level'] = $level; // Add level for indentation
        // Capture children before unsetting, then recurse
        $children_nodes = isset($node['children']) ? $node['children'] : [];
        unset($node['children']); // Remove children to avoid recursion in JSON for Alpine
        $result[] = $node;
        if (!empty($children_nodes)) {
            flattenTree($children_nodes, $result, $level + 1);
        }
    }
}

// --- Function to fetch all categories and prepare for display with search filter ---
if (!function_exists('getCategoriesFlatWithLevel')) {
    function getCategoriesFlatWithLevel($pdo, $searchQuery = null) {
        try {
            // First, fetch ALL categories to ensure the tree can be built completely.
            $sql = "
                SELECT
                    c.category_id,
                    c.name,
                    c.parent_id,
                    pc.name AS parent_name,
                    c.created_at,
                    c.updated_at,
                    c.is_default,
                    c.is_featured
                FROM
                    categories c
                LEFT JOIN
                    categories pc ON c.parent_id = pc.category_id
                ORDER BY c.name ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $allFlatCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Normalize parent_id: Convert empty string or 0 to null for consistent comparison
            foreach ($allFlatCategories as &$cat) {
                if (empty($cat['parent_id']) && $cat['parent_id'] !== 0) {
                    $cat['parent_id'] = null;
                } else {
                    $cat['parent_id'] = (int)$cat['parent_id']; // Ensure it's an integer for comparison
                }
            }
            unset($cat); // Break the reference

            // Build hierarchical tree
            $tree = buildCategoryTree($allFlatCategories);

            // Flatten the full tree with level info for table display
            $allDisplayCategories = [];
            flattenTree($tree, $allDisplayCategories);

            // Now, apply the search filter on the prepared, flattened array in PHP
            if (!empty($searchQuery)) {
                $searchQuery = strtolower($searchQuery);
                $filteredCategories = array_filter($allDisplayCategories, function($category) use ($searchQuery) {
                    // Check if the category's name or its parent's name matches the search query
                    $categoryNameMatches = str_contains(strtolower($category['name']), $searchQuery);
                    $parentNameMatches = isset($category['parent_name']) && str_contains(strtolower(strval($category['parent_name'])), $searchQuery);
                    return $categoryNameMatches || $parentNameMatches;
                });
                return array_values($filteredCategories); // Re-index the array
            }

            return $allDisplayCategories; // Return all if no search query
        } catch (PDOException $e) {
            error_log("Database error fetching categories: " . $e->getMessage());
            $_SESSION['message'] = '<div class="alert alert-error"><span class="material-icons mr-2">error</span> Error fetching categories.</div>';
            return [];
        }
    }
}


/**
 * Helper function to generate styled alert messages.
 * @param string $type The type of alert (success, error, info).
 * @param string $message The message to display.
 * @return string The HTML for the alert message.
 */
function create_alert($type, $message) {
    $icon = '';
    $classes = '';
    switch ($type) {
        case 'success':
            $icon = 'check_circle';
            $classes = 'bg-green-100 text-green-700 border-green-200';
            break;
        case 'error':
            $icon = 'error';
            $classes = 'bg-red-100 text-red-700 border-red-200';
            break;
        case 'info':
            $icon = 'info';
            $classes = 'bg-blue-100 text-blue-700 border-blue-200';
            break;
        default:
            $icon = 'info';
            $classes = 'bg-gray-100 text-gray-700 border-gray-200';
            break;
    }
    return '<div class="alert ' . $classes . '"><span class="material-icons mr-1 text-base">'. $icon .'</span><span class="text-sm font-semibold">' . htmlspecialchars($message) . '</span></div>';
}


// --- Handle Form Submissions (Add, Edit, Delete, Toggle) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $_SESSION['message'] = create_alert('error', 'Invalid CSRF token. Please try again.');
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    switch ($action) {
        case 'add_category':
            $name = trim($_POST['name'] ?? '');
            $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

            if (empty($name)) {
                $_SESSION['message'] = create_alert('error', 'Category name cannot be empty.');
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO categories (name, parent_id) VALUES (?, ?)");
                    if ($stmt->execute([$name, $parent_id])) {
                        $_SESSION['message'] = create_alert('success', 'Category "' . htmlspecialchars($name) . '" added successfully!');
                    } else {
                        $_SESSION['message'] = create_alert('error', 'Failed to add category. It might already exist.');
                    }
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') { // Integrity constraint violation (e.g., duplicate name)
                        $_SESSION['message'] = create_alert('error', 'Category name already exists.');
                    } else {
                        error_log("Database error adding category: " . $e->getMessage());
                        $_SESSION['message'] = create_alert('error', 'A database error occurred while adding category.');
                    }
                }
            }
            break;

        case 'edit_category':
            $category_id = (int)($_POST['category_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
            $is_default = (int)($_POST['is_default'] ?? 0);
            $is_featured = (int)($_POST['is_featured'] ?? 0);

            if ($category_id === 0 || empty($name)) {
                $_SESSION['message'] = create_alert('error', 'Invalid category data for update.');
            } else if ($category_id === $parent_id) { // Prevent a category from being its own parent
                $_SESSION['message'] = create_alert('error', 'A category cannot be its own parent.');
            } else {
                try {
                    // If a new category is being set as default, unset the old one first
                    if ($is_default) {
                         $pdo->exec("UPDATE categories SET is_default = 0 WHERE is_default = 1");
                    }

                    $stmt = $pdo->prepare("UPDATE categories SET name = ?, parent_id = ?, is_default = ?, is_featured = ?, updated_at = CURRENT_TIMESTAMP WHERE category_id = ?");
                    if ($stmt->execute([$name, $parent_id, $is_default, $is_featured, $category_id])) {
                        $_SESSION['message'] = create_alert('success', 'Category "' . htmlspecialchars($name) . '" updated successfully!');
                    } else {
                        $_SESSION['message'] = create_alert('error', 'Failed to update category. It might already exist or the ID is invalid.');
                    }
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') { // Integrity constraint violation (e.g., duplicate name)
                        $_SESSION['message'] = create_alert('error', 'Category name already exists or invalid parent ID.');
                    } else {
                        error_log("Database error updating category: " . $e->getMessage());
                        $_SESSION['message'] = create_alert('error', 'A database error occurred while updating category.');
                    }
                }
            }
            break;

        case 'delete_category':
            $category_id = (int)($_POST['category_id'] ?? 0);

            if ($category_id === 0) {
                $_SESSION['message'] = create_alert('error', 'Invalid category ID for deletion.');
            } else {
                try {
                    // Check if category has subcategories
                    $stmtCheckChildren = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE parent_id = ?");
                    $stmtCheckChildren->execute([$category_id]);
                    if ($stmtCheckChildren->fetchColumn() > 0) {
                         $_SESSION['message'] = create_alert('error', 'Cannot delete category that has subcategories. Please delete subcategories first.');
                         header("Location: " . $_SERVER['REQUEST_URI']);
                         exit;
                    }

                    // Check if category is linked to any editions
                    // IMPORTANT: Assuming 'editions' table has 'category_id' column for this check.
                    $stmtCheckEditions = $pdo->prepare("SELECT COUNT(*) FROM editions WHERE category_id = ?");
                    $stmtCheckEditions->execute([$category_id]);
                    if ($stmtCheckEditions->fetchColumn() > 0) {
                        $_SESSION['message'] = create_alert('error', 'Cannot delete category that has editions associated. Please reassign editions first.');
                        header("Location: " . $_SERVER['REQUEST_URI']);
                        exit;
                    }

                    // Proceed with deletion if no dependencies
                    $stmt = $pdo->prepare("DELETE FROM categories WHERE category_id = ?");
                    if ($stmt->execute([$category_id])) {
                        $_SESSION['message'] = create_alert('success', 'Category deleted successfully!');
                    } else {
                        $_SESSION['message'] = create_alert('error', 'Failed to delete category.');
                    }
                } catch (PDOException $e) {
                    error_log("Database error deleting category: " . $e->getMessage());
                    $_SESSION['message'] = create_alert('error', 'A database error occurred while deleting category.');
                }
            }
            break;
        case 'toggle_status':
            $category_id = (int)($_POST['category_id'] ?? 0);
            $field = $_POST['field'] ?? '';
            $value = (int)($_POST['value'] ?? 0);

            if ($category_id === 0 || !in_array($field, ['is_default', 'is_featured'])) {
                $_SESSION['message'] = create_alert('error', 'Invalid toggle action.');
            } else {
                try {
                    // First, get the category's name for the success message
                    $stmtName = $pdo->prepare("SELECT name FROM categories WHERE category_id = ?");
                    $stmtName->execute([$category_id]);
                    $categoryName = $stmtName->fetchColumn();

                    // If setting a new default, unset the old one first
                    if ($field === 'is_default' && $value === 1) {
                        $pdo->exec("UPDATE categories SET is_default = 0 WHERE is_default = 1 AND category_id != " . $category_id);
                    }
                    $stmt = $pdo->prepare("UPDATE categories SET " . $field . " = ? WHERE category_id = ?");
                    $stmt->execute([$value, $category_id]);

                    if ($categoryName) {
                         if ($field === 'is_default') {
                             $message = $value === 1 ?
                                 '"' . htmlspecialchars($categoryName) . '" is now the default category.' :
                                 '"' . htmlspecialchars($categoryName) . '" is no longer the default category.';
                         } else { // is_featured
                              $message = $value === 1 ?
                                 '"' . htmlspecialchars($categoryName) . '" is now a featured category.' :
                                 '"' . htmlspecialchars($categoryName) . '" is no longer a featured category.';
                         }
                        $_SESSION['message'] = create_alert('success', $message);
                    } else {
                        $_SESSION['message'] = create_alert('error', 'Failed to update status.');
                    }
                } catch (PDOException $e) {
                    error_log("Database error toggling status: " . $e->getMessage());
                    $_SESSION['message'] = create_alert('error', 'Failed to update status.');
                }
            }
            break;
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Regenerate token after action
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// This function displays session messages and is also wrapped for safety
if (!function_exists('display_session_message')) { // Added check
    function display_session_message() {
        if (isset($_SESSION['message'])) {
            echo $_SESSION['message'];
            unset($_SESSION['message']);
        }
    }
}

// Get search query from URL
$searchQuery = $_GET['search'] ?? '';
$categories = getCategoriesFlatWithLevel($pdo, $searchQuery); // Fetch categories with search filter

// --- Get current URL and domain for dynamic meta tags ---
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http');
$currentDomain = $_SERVER['HTTP_HOST'];
$currentUrl = $protocol . "://" . $currentDomain . $_SERVER['REQUEST_URI'];
// Dynamically construct the full path for the OG image
$fullOgImagePath = $protocol . "://" . $currentDomain . OG_IMAGE_PATH;

// --- JSON-LD Structured Data for SEO ---
// We'll use the WebPage schema to describe the current admin page.
// This is a simple, effective way to provide structured context to search engines.
$jsonLd = [
    "@context" => "https://schema.org",
    "@type" => "WebPage",
    "name" => "Manage Categories - " . htmlspecialchars(APP_TITLE),
    "description" => "Manage edition categories for the " . htmlspecialchars(APP_TITLE) . " e-paper admin panel. Organize and feature news sections effortlessly.",
    "url" => $currentUrl,
    "potentialAction" => [
        "@type" => "SearchAction",
        "target" => [
            "@type" => "EntryPoint",
            "urlTemplate" => $protocol . "://" . $currentDomain . "/editions/categories.php?search={search_term_string}"
        ],
        "query-input" => "required name=search_term_string"
    ]
];
$jsonLdString = json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
?>
<!DOCTYPE html>
<html lang="en" x-data="{
    sidebarOpen: false,
    profileMenuOpen: false,
    mobileProfileMenuOpen: false,
    moreMenuOpen: false,
    addModalOpen: false,
    editModalOpen: false,
    deleteModalOpen: false,
    currentCategory: {}, // For edit modal
    categoryToDeleteId: null, // For delete modal
    categoryToDeleteName: '', // For delete modal confirmation text
    deleteConfirmationInput: '', // For delete modal confirmation
    deleteError: ''
}" x-cloak>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Updated Title to be dynamic -->
    <title>Manage Categories - <?= htmlspecialchars(APP_TITLE) ?></title>
    
    <!-- Favicon and OG Image from logovars.php -->
    <link rel="icon" href="<?= htmlspecialchars(APP_FAVICON_PATH) ?>" type="image/x-icon">
    
    <!-- SEO and Social Media Meta Tags -->
    <!-- Updated description to be dynamic -->
    <meta name="description" content="Manage edition categories for the <?= htmlspecialchars(APP_TITLE) ?> e-paper admin panel. Organize and feature news sections effortlessly.">
    <!-- Added Keywords Meta Tag -->
    <meta name="keywords" content="<?= htmlspecialchars(APP_TITLE) ?>, epaper, e-paper, online newspaper, digital newspaper, news, publication, categories, editions, manage categories, admin panel, administration">
    <!-- Facebook Meta Tags -->
    <meta property="og:url" content="<?= htmlspecialchars($currentUrl) ?>">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Manage Categories - <?= htmlspecialchars(APP_TITLE) ?>">
    <!-- Updated OG description to be dynamic -->
    <meta property="og:description" content="Manage edition categories for the <?= htmlspecialchars(APP_TITLE) ?> e-paper admin panel. Organize and feature news sections effortlessly.">
    <meta property="og:image" content="<?= htmlspecialchars($fullOgImagePath) ?>">
    <!-- Twitter Meta Tags -->
    <meta name="twitter:card" content="summary_large_image">
    <meta property="twitter:domain" content="<?= htmlspecialchars($currentDomain) ?>">
    <meta property="twitter:url" content="<?= htmlspecialchars($currentUrl) ?>">
    <meta name="twitter:title" content="Manage Categories - <?= htmlspecialchars(APP_TITLE) ?>">
    <!-- Updated Twitter description to be dynamic -->
    <meta name="twitter:description" content="Manage edition categories for the <?= htmlspecialchars(APP_TITLE) ?> e-paper admin panel. Organize and feature news sections effortlessly.">
    <meta name="twitter:image" content="<?= htmlspecialchars($fullOgImagePath) ?>">
    
    <!-- JSON-LD Structured Data for SEO -->
    <script type="application/ld+json">
        <?= $jsonLdString ?>
    </script>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'main-color': '#312E81',
                    }
                }
            }
        }
    </script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        [x-cloak] { display: none !important; }
        body { font-family: 'Inter', sans-serif; overflow-x: hidden; }

        /* Custom scrollbar for webkit browsers */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: #94a3b8;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #64748b;
        }

        /* Alert styling */
        .alert {
            padding: 0.5rem; /* Reduced padding */
            border-radius: 0.5rem;
            border: 1px solid;
            margin-bottom: 0.5rem; /* Reduced margin */
            display: flex;
            align-items: center;
            gap: 0.25rem; /* Reduced gap */
            opacity: 1;
            transition: opacity 0.5s ease-in-out;
        }
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 38px;
            height: 20px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 20px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 2px;
            bottom: 2px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: #312E81;
        }

        input:checked + .slider:before {
            transform: translateX(18px);
        }
    </style>
</head>
<body class="bg-slate-50 antialiased" x-cloak>

<?php
// Assuming headersidebar.php is in the parent directory (e.g., /public_html/)
require_once __DIR__ . '/../layout/headersidebar.php';
?>

<main class="p-4">
    <div class="max-w-7xl mx-auto">
        <?php display_session_message(); ?>
        
        <div class="bg-white overflow-hidden p-5 border border-slate-200 rounded-xl shadow-sm">
            <!-- Consolidated header with search and buttons -->
            <div class="flex flex-col md:flex-row items-stretch md:items-center justify-between gap-4 mb-5">
                <!-- Title -->
                <div class="flex items-center gap-3 md:flex-shrink-0">
                    <span class="material-icons text-3xl text-main-color">category</span>
                    <h1 class="text-2xl font-bold text-slate-800">Manage Categories</h1>
                </div>

                <!-- Search and Buttons (Updated) -->
                <div class="flex flex-1 md:flex-initial md:justify-end flex-row items-stretch gap-2">
                    <!-- Search Form - Now uses flex-1 to fill space -->
                    <form method="GET" class="flex items-stretch flex-1 sm:flex-initial sm:w-80 rounded-lg border border-slate-300 overflow-hidden transition-all duration-300 ease-in-out">
                        <div class="relative flex-1">
                            <span class="material-icons absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">search</span>
                            <!-- Placeholder text is shorter for mobile view -->
                            <input type="text" name="search" id="search" placeholder="Search..."
                                class="w-full pl-10 pr-3 py-2 text-sm bg-white focus:outline-none border-0"
                                value="<?= htmlspecialchars($searchQuery) ?>">
                        </div>
                        <?php if (!empty($searchQuery)): ?>
                            <a href="?" class="flex items-center justify-center px-4 py-2 bg-slate-300 text-slate-800 font-semibold shadow-sm hover:bg-slate-400 transition-all text-sm flex-shrink-0">
                                <span class="material-icons text-base">close</span>
                                <span class="ml-2 hidden sm:inline">Reset search</span>
                            </a>
                        <?php else: ?>
                            <button type="submit" class="flex items-center justify-center w-10 px-2 py-2 bg-main-color text-white font-semibold shadow-sm hover:bg-purple-700 transition-all text-sm flex-shrink-0">
                                <span class="material-icons text-base">arrow_forward</span>
                            </button>
                        <?php endif; ?>
                    </form>

                    <!-- Add New Category Button -->
                    <button type="button" @click="addModalOpen = true" class="inline-flex items-center justify-center px-3 py-2 bg-main-color text-white text-sm font-semibold rounded-lg shadow-md hover:bg-purple-700 transition-all duration-300 flex-shrink-0">
                        <span class="material-icons mr-2 text-base hidden sm:inline">add</span> 
                        <span class="sm:hidden">Add New</span>
                        <span class="hidden sm:inline">Add New Category</span>
                    </button>
                </div>
            </div>

            <?php if (empty($categories)): ?>
                <div class="bg-blue-50 border border-blue-200 text-blue-800 p-5 rounded-lg text-center shadow-inner">
                    <span class="material-icons text-3xl text-blue-500 mb-2">info</span>
                    <p class="text-base font-medium">No categories found matching your criteria.</p>
                    <p class="text-sm text-blue-600 mt-1">Click the "Add New Category" button to get started.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full border-collapse border border-slate-200">
                        <thead class="bg-purple-100">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider border-r border-slate-200">Name</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider border-r border-slate-200">Parent Category</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider border-r border-slate-200">Default</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider border-r border-slate-200">Featured</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white">
                            <?php foreach ($categories as $category): ?>
                                <tr class="hover:bg-slate-50 transition-colors duration-150">
                                    <td class="px-4 py-2 whitespace-nowrap text-sm font-medium text-slate-900 border-r border-b border-slate-200" style="padding-left: <?= ($category['level'] * 20) + 16 ?>px;">
                                        <div class="flex items-center gap-2" style="height: auto; vertical-align: top;">
                                            <?php if ($category['parent_id'] !== null): ?>
                                                <span class="material-icons text-main-color text-lg">chevron_right</span>
                                            <?php else: ?>
                                                <span class="material-icons text-yellow-500 text-lg">folder</span>
                                            <?php endif; ?>
                                            <span><?= htmlspecialchars($category['name']) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm text-slate-500 border-r border-b border-slate-200">
                                        <?= htmlspecialchars($category['parent_name'] ?? 'None') ?>
                                    </td>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm border-r border-b border-slate-200">
                                        <form method="POST" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>" class="flex items-center">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="category_id" value="<?= htmlspecialchars($category['category_id']) ?>">
                                            <input type="hidden" name="field" value="is_default">
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="value" value="1" <?= $category['is_default'] ? 'checked' : '' ?> onchange="this.form.submit()">
                                                <span class="slider"></span>
                                            </label>
                                        </form>
                                    </td>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm border-r border-b border-slate-200">
                                        <form method="POST" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>" class="flex items-center">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="category_id" value="<?= htmlspecialchars($category['category_id']) ?>">
                                            <input type="hidden" name="field" value="is_featured">
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="value" value="1" <?= $category['is_featured'] ? 'checked' : '' ?> onchange="this.form.submit()">
                                                <span class="slider"></span>
                                            </label>
                                        </form>
                                    </td>
                                    <td class="px-4 py-2 whitespace-nowrap text-left text-sm font-medium border-b border-slate-200">
                                        <div class="flex items-center gap-2">
                                            <button type="button"
                                                @click='editModalOpen = true; currentCategory = <?= json_encode($category) ?>;'
                                                class="inline-flex items-center px-2 py-1 rounded-md shadow-md bg-main-color text-white hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition duration-150 ease-in-out text-xs"
                                                title="Edit Category">
                                                <span class="material-icons text-xs mr-1">edit_square</span>
                                                Edit
                                            </button>
                                            <button type="button"
                                                @click='deleteModalOpen = true; categoryToDeleteId = <?= $category['category_id'] ?>; categoryToDeleteName = "<?= htmlspecialchars($category['name'], ENT_QUOTES) ?>"; deleteConfirmationInput = ""; deleteError = ""; $nextTick(() => $refs.deleteConfirmInput.focus());'
                                                class="inline-flex items-center px-2 py-1 rounded-md shadow-md bg-red-600 text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 transition duration-150 ease-in-out text-xs"
                                                title="Delete Category">
                                                <span class="material-icons text-xs mr-1">delete</span> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Add Category Modal -->
<div class="fixed inset-0 z-50 modal-overlay flex items-center justify-center p-4 bg-black/50" x-show="addModalOpen" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-90" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-90" @click.away="addModalOpen = false">
    <div class="bg-white rounded-xl p-6 shadow-2xl max-w-sm w-full transform transition-all" @click.stop>
        <div class="flex items-center justify-center mb-3">
            <div class="bg-main-color/10 text-main-color p-3 rounded-full flex items-center justify-center w-12 h-12">
                <span class="material-icons text-2xl">add_circle</span>
            </div>
        </div>
        <h3 class="text-xl font-bold text-slate-800 text-center mb-1">Add New Category</h3>
        <p class="text-center text-slate-500 text-sm mb-4">Enter the details for the new category below.</p>
        <form method="POST" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
            <input type="hidden" name="action" value="add_category">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="mb-3">
                <label for="add_name" class="block text-xs font-semibold text-slate-700 mb-1">Category Name</label>
                <input type="text" name="name" id="add_name" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-md focus:ring-1 focus:ring-main-color focus:border-main-color focus:outline-none" required>
            </div>
            <div class="mb-4">
                <label for="add_parent_id" class="block text-xs font-semibold text-slate-700 mb-1">Parent Category (Optional)</label>
                <select name="parent_id" id="add_parent_id" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-md focus:ring-1 focus:ring-main-color focus:border-main-color focus:outline-none">
                    <option value="">None</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat['category_id']) ?>">
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-3">
                <button type="button" @click="addModalOpen = false" class="flex-1 px-4 py-2 bg-slate-200 text-slate-700 font-semibold rounded-md hover:bg-slate-300 transition text-sm">Cancel</button>
                <button type="submit" class="flex-1 px-4 py-2 bg-main-color text-white font-semibold rounded-md hover:bg-purple-700 transition text-sm">Add Category</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="fixed inset-0 z-50 modal-overlay flex items-center justify-center p-4 bg-black/50" x-show="editModalOpen" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-90" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-90" @click.away="editModalOpen = false">
    <div class="bg-white rounded-xl p-6 shadow-2xl max-w-sm w-full transform transition-all" @click.stop>
        <div class="flex items-center justify-center mb-3">
            <div class="bg-indigo-100 text-indigo-600 p-3 rounded-full flex items-center justify-center w-12 h-12">
                <span class="material-icons text-2xl">edit_square</span>
            </div>
        </div>
        <h3 class="text-xl font-bold text-slate-800 text-center mb-1">Edit Category</h3>
        <p class="text-center text-slate-500 text-sm mb-4">Update the details for the selected category.</p>
        <form method="POST" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
            <input type="hidden" name="action" value="edit_category">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="category_id" x-model="currentCategory.category_id">
            <div class="mb-3">
                <label for="edit_name" class="block text-xs font-semibold text-slate-700 mb-1">Category Name</label>
                <input type="text" name="name" id="edit_name" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-md focus:ring-1 focus:ring-main-color focus:border-main-color focus:outline-none" x-model="currentCategory.name" required>
            </div>
            <div class="mb-4">
                <label for="edit_parent_id" class="block text-xs font-semibold text-slate-700 mb-1">Parent Category (Optional)</label>
                <select name="parent_id" id="edit_parent_id" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-md focus:ring-1 focus:ring-main-color focus:border-main-color focus:outline-none" x-model="currentCategory.parent_id">
                    <option value="">None</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat['category_id']) ?>">
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <label for="edit_is_default" class="block text-xs font-semibold text-slate-700">Set as Default Category</label>
                    <label class="toggle-switch">
                        <input type="checkbox" name="is_default" id="edit_is_default" value="1" x-model="currentCategory.is_default">
                        <span class="slider"></span>
                    </label>
                </div>
                <div class="flex items-center justify-between">
                    <label for="edit_is_featured" class="block text-xs font-semibold text-slate-700">Set as Featured Category</label>
                    <label class="toggle-switch">
                        <input type="checkbox" name="is_featured" id="edit_is_featured" value="1" x-model="currentCategory.is_featured">
                        <span class="slider"></span>
                    </label>
                </div>
            </div>
            <div class="flex gap-3 mt-4">
                <button type="button" @click="editModalOpen = false" class="flex-1 px-4 py-2 bg-slate-200 text-slate-700 font-semibold rounded-md hover:bg-slate-300 transition text-sm">Cancel</button>
                <button type="submit" class="flex-1 px-4 py-2 bg-main-color text-white font-semibold rounded-md hover:bg-purple-700 transition text-sm">Update Category</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="fixed inset-0 z-50 modal-overlay flex items-center justify-center p-4 bg-black/50" x-show="deleteModalOpen" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-90" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-90" @click.away="deleteModalOpen = false; deleteError = ''; deleteConfirmationInput = '';">
    <div class="bg-white rounded-xl p-6 shadow-2xl max-w-sm w-full transform transition-all" @click.stop>
        <div class="flex items-center justify-center mb-3">
            <div class="bg-red-100 text-red-600 p-3 rounded-full flex items-center justify-center w-12 h-12">
                <span class="material-icons text-2xl">delete</span>
            </div>
        </div>
        <h3 class="text-xl font-bold text-slate-800 text-center mb-1">Confirm Deletion</h3>
        <p class="text-center text-slate-500 text-sm mb-4">You are about to permanently delete the category "<strong><span x-text="categoryToDeleteName"></span></strong>". This action cannot be undone. Please type "<strong>delete</strong>" in the box below to confirm.</p>
        <form id="deleteCategoryForm" method="POST" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
            <input type="hidden" name="action" value="delete_category">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="category_id" x-model="categoryToDeleteId">
            <div class="mb-3">
                <input type="text" x-model="deleteConfirmationInput" x-ref="deleteConfirmInput"
                       class="w-full px-3 py-2 text-sm border rounded-md focus:ring-1 focus:ring-red-400 focus:border-red-400 focus:outline-none transition"
                       :class="deleteConfirmationInput === 'delete' ? 'border-green-500' : 'border-slate-300'"
                       placeholder="type 'delete' to confirm"
                       @keyup.enter.prevent="
                           if (deleteConfirmationInput === 'delete') {
                               $el.closest('form').submit();
                           } else {
                               deleteError = 'Please type &quot;delete&quot; to confirm.';
                           }
                       ">
                <p x-show="deleteError" x-text="deleteError" class="text-red-600 text-xs mt-1 font-semibold"></p>
            </div>
            <div class="flex gap-3">
                <button type="button" @click="deleteModalOpen = false; deleteError = ''; deleteConfirmationInput = '';" class="flex-1 px-4 py-2 bg-slate-200 text-slate-700 font-semibold rounded-md hover:bg-slate-300 transition text-sm">Cancel</button>
                <button type="button"
                        @click="
                            if (deleteConfirmationInput === 'delete') {
                                $el.closest('form').submit();
                            } else if (deleteConfirmationInput === '') {
                                deleteError = 'Please type &quot;delete&quot; to confirm and proceed.';
                            } else {
                                deleteError = 'Incorrect. Please type &quot;delete&quot; exactly to confirm.';
                            }
                        "
                        class="flex-1 px-4 py-2 bg-red-600 text-white font-semibold rounded-md hover:bg-red-700 transition text-sm">
                    Delete Category
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // JavaScript to make alerts disappear after 3 seconds
    document.addEventListener('DOMContentLoaded', () => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0'; // Start fade out
                // Remove element after transition completes
                alert.addEventListener('transitionend', () => {
                    alert.remove();
                });
            }, 3000); // 3000 milliseconds = 3 seconds
        });
    });
</script>

</body>
</html>
