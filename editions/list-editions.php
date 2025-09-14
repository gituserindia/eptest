<?php
// File: list-editions.php
// Manages editions: view, search, filter, edit, delete.
// Accessible by 'SuperAdmin' and 'Admin' roles only.

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

require_once __DIR__ . '/../config/config.php';

//require_once '/../config/config.php'; // Ensure this path is correct for your database connection ($pdo)

// Set default timezone to Asia/Kolkata (IST)
date_default_timezone_set('Asia/Kolkata');

// --- Define Logged-in State and User Role ---
$loggedIn = isset($_SESSION['user_id']);
$username = $loggedIn ? htmlspecialchars($_SESSION['username']) : null; // Sanitize username for HTML output
$userRole = $loggedIn ? ($_SESSION['user_role'] ?? 'Viewer') : null; // Default to 'Viewer' if not set

// Session Management & Inactivity Timeout
const INACTIVITY_TIMEOUT = 1800; // 30 minutes in seconds

if ($loggedIn && !isset($_SESSION['remember_me'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > INACTIVITY_TIMEOUT)) {
        session_unset();
        session_destroy();
        session_start(); // Re-start session to store new message
        $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-hourglass-end mr-2"></i> Session expired due to inactivity. Please log in again.</div>';
        header("Location: login.php?timeout=1");
        exit;
    }
    $_SESSION['last_activity'] = time(); // Update last activity time
}

// --- INITIAL AUTHORIZATION CHECKS ---
if (!$loggedIn) {
    $_SESSION['message'] = '<div class="alert alert-info"><i class="fas fa-info-circle mr-2"></i> Please log in to access this page.</div>';
    header("Location: login.php");
    exit;
}

if ($userRole !== 'SuperAdmin' && $userRole !== 'Admin') {
    $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-lock mr-2"></i> You do not have the required permissions to access this page.</div>';
    header("Location: dashboard.php");
    exit;
}

// CSRF token generation (for delete action)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle = "Manage Editions - Admin Panel";

// --- Functions for data fetching and display ---

// This function displays session messages and is also wrapped for safety
if (!function_exists('display_session_message')) {
    function display_session_message() {
        if (isset($_SESSION['message'])) {
            echo $_SESSION['message'];
            unset($_SESSION['message']);
        }
    }
}

// Function to format file size from bytes to human-readable format (MB)
if (!function_exists('formatBytesToMB')) {
    function formatBytesToMB($bytes, $precision = 2) {
        if ($bytes === null || $bytes === '') {
            return 'N/A';
        }
        $megabytes = $bytes / (1024 * 1024);
        return round($megabytes, $precision) . ' MB';
    }
}

// Fetch all categories for the filter dropdown
$categoriesForFilter = [];
try {
    $stmt = $pdo->query("SELECT category_id, name FROM categories ORDER BY name ASC");
    $categoriesForFilter = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching categories for filter: " . $e->getMessage());
    // Fallback to empty array
    $categoriesForFilter = [];
}

// Fetch all uploaders for the filter dropdown
$uploadersForFilter = [];
try {
    $stmt = $pdo->query("SELECT user_id, username FROM users ORDER BY username ASC");
    $uploadersForFilter = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching uploaders for filter: " . $e->getMessage());
    $uploadersForFilter = [];
}

// Fetch all unique publication months/years for the filter dropdown
$monthsForFilter = [];
try {
    // Using DATE_FORMAT is standard across MySQL/MariaDB for this purpose.
    // For SQLite, you might use STRFTIME('%Y-%m', publication_date).
    $stmt = $pdo->query("SELECT DISTINCT DATE_FORMAT(publication_date, '%Y-%m') as month_year_val, DATE_FORMAT(publication_date, '%M %Y') as month_year_display FROM editions ORDER BY publication_date DESC");
    $monthsForFilter = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching months for filter: " . $e->getMessage());
    $monthsForFilter = [];
}

// Get search and filter parameters from URL
$searchQuery = $_GET['search'] ?? '';
$filterCategory = $_GET['category'] ?? ''; // This will be category_id
$filterUploader = $_GET['uploader'] ?? ''; // This will be uploader_user_id
$filterMonthYear = $_GET['month_year'] ?? ''; // This will be 'YYYY-MM'

// --- Sorting parameters ---
$sortColumn = $_GET['sort_column'] ?? 'publication_date'; // Default sort column
$sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC'); // Default sort order

// Validate sort column to prevent SQL injection
$allowedSortColumns = [
    'title' => 'e.title',
    'publication_date' => 'e.publication_date',
    'category_name' => 'c.name',
    'uploaded_at' => 'e.created_at',
    'uploader_username' => 'u.username',
    'status' => 'e.status',
    'page_count' => 'e.page_count',
    'views_count' => 'e.views_count',
    'file_size_bytes' => 'e.file_size_bytes'
];

$sqlSortColumn = $allowedSortColumns[$sortColumn] ?? 'e.publication_date'; // Fallback to default if invalid
$sqlSortOrder = ($sortOrder === 'ASC') ? 'ASC' : 'DESC'; // Ensure valid sort order

// --- Pagination Parameters ---
$resultsPerPageOptions = [10, 25, 50, 100]; // Options for results per page
$resultsPerPage = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $resultsPerPageOptions) ? (int)$_GET['per_page'] : 25;
$currentPage = isset($_GET['page']) && is_numeric($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($currentPage - 1) * $resultsPerPage;

// Build SQL WHERE clause for editions (for both count and fetch queries)
$whereSql = "WHERE 1=1";
$params = [];

if (!empty($searchQuery)) {
    $whereSql .= " AND (e.title LIKE :search_title OR e.description LIKE :search_description)";
    $params[':search_title'] = '%' . $searchQuery . '%';
    $params[':search_description'] = '%' . $searchQuery . '%';
}

if (!empty($filterCategory)) {
    $whereSql .= " AND e.category_id = :category_id";
    $params[':category_id'] = (int)$filterCategory;
}

if (!empty($filterUploader)) {
    $whereSql .= " AND e.uploader_user_id = :uploader_id";
    $params[':uploader_id'] = (int)$filterUploader;
}

if (!empty($filterMonthYear)) {
    $whereSql .= " AND DATE_FORMAT(e.publication_date, '%Y-%m') = :month_year";
    $params[':month_year'] = $filterMonthYear;
}

// --- Total records count query ---
$countSql = "SELECT COUNT(e.edition_id) AS total_records FROM editions e LEFT JOIN categories c ON e.category_id = c.category_id LEFT JOIN users u ON e.uploader_user_id = u.user_id " . $whereSql;
try {
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $totalRecords = $stmt->fetch(PDO::FETCH_ASSOC)['total_records'];
} catch (PDOException $e) {
    error_log("Database error counting editions: " . $e->getMessage());
    $totalRecords = 0;
}

$totalPages = ceil($totalRecords / $resultsPerPage);

// Ensure current page is not greater than total pages if filters reduce total count
if ($currentPage > $totalPages && $totalPages > 0) {
    $currentPage = $totalPages;
    $offset = ($currentPage - 1) * $resultsPerPage;
} elseif ($totalPages == 0) {
    $currentPage = 1;
    $offset = 0;
}


// Build SQL query for editions
$sql = "
    SELECT
        e.edition_id,
        e.title,
        e.publication_date,
        e.pdf_path,
        e.og_image_path,
        e.list_thumb_path,
        e.page_count,
        e.file_size_bytes,
        e.created_at,
        e.uploader_user_id,
        e.category_id,
        e.description,
        e.updated_at,
        e.views_count,
        e.status,
        e.status_reason,
        c.name AS category_name,
        u.username AS uploader_username
    FROM
        editions e
    LEFT JOIN
        categories c ON e.category_id = c.category_id
    LEFT JOIN
        users u ON e.uploader_user_id = u.user_id
    {$whereSql}
    ORDER BY {$sqlSortColumn} {$sqlSortOrder}, e.created_at DESC
    LIMIT :limit OFFSET :offset
";

try {
    $stmt = $pdo->prepare($sql);
    // Bind pagination parameters
    $stmt->bindValue(':limit', $resultsPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    // Bind other filter parameters
    foreach ($params as $key => &$val) {
        $stmt->bindParam($key, $val);
    }
    $stmt->execute();
    $editions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching editions: " . $e->getMessage());
    $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Error fetching editions.</div>';
    $editions = [];
}

// --- Handle Delete Action (via POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_edition') {
    $edition_ids = $_POST['edition_ids'] ?? []; // Changed to handle array of IDs
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Invalid CSRF token. Please try again.</div>';
        header("Location: list-editions.php"); // Redirect back to this page
        exit;
    }

    if (empty($edition_ids)) {
        $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> No editions selected for deletion.</div>';
    } else {
        try {
            $pdo->beginTransaction();
            $deletedCount = 0;

            foreach ($edition_ids as $edition_id) {
                // First, get the PDF path and image paths to delete the files
                $stmt = $pdo->prepare("SELECT pdf_path, og_image_path, list_thumb_path FROM editions WHERE edition_id = ?");
                $stmt->execute([$edition_id]);
                $edition = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($edition) {
                    // Delete PDF file
                    if (!empty($edition['pdf_path'])) {
                        $file_path = __DIR__ . '/' . $edition['pdf_path']; // Adjust path if needed
                        if (file_exists($file_path)) {
                            unlink($file_path);
                        }
                    }
                    // Delete OG image file
                    if (!empty($edition['og_image_path'])) {
                        $file_path = __DIR__ . '/' . $edition['og_image_path'];
                        if (file_exists($file_path)) {
                            unlink($file_path);
                        }
                    }
                    // Delete list thumbnail file
                    if (!empty($edition['list_thumb_path'])) {
                        $file_path = __DIR__ . '/' . $edition['list_thumb_path'];
                        if (file_exists($file_path)) {
                            unlink($file_path);
                        }
                    }
                }

                // Then, delete the record from the database
                $stmt = $pdo->prepare("DELETE FROM editions WHERE edition_id = ?");
                if ($stmt->execute([$edition_id])) {
                    $deletedCount++;
                }
            }

            $pdo->commit();
            if ($deletedCount > 0) {
                $_SESSION['message'] = '<div class="alert alert-success"><i class="fas fa-check-circle mr-2"></i> Successfully deleted ' . $deletedCount . ' edition(s)!</div>';
            } else {
                $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Failed to delete any editions.</div>';
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Database error deleting edition(s): " . $e->getMessage());
            $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> A database error occurred while deleting edition(s).</div>';
        }
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Regenerate token after action
    header("Location: list-editions.php?" . http_build_query($_GET)); // Redirect back preserving filters/pagination
    exit;
}

?>
<!DOCTYPE html>
<html lang="en" x-data="{
    sidebarOpen: false,
    profileMenuOpen: false,
    mobileProfileMenuOpen: false,
    moreMenuOpen: false,
    deleteModalOpen: false,
    editionToDeleteId: null, // For single delete modal
    deleteConfirmationInput: '', // For delete modal confirmation
    deleteError: '',
    selectedEditions: [], // Array to store selected edition IDs for bulk delete
    selectAll: false // For select all checkbox
}" x-cloak>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        [x-cloak] { display: none !important; }
        body { font-family: 'Inter', sans-serif; overflow-x: hidden; }

        /* General Alert Styles (consistent with other pages) */
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
            opacity: 1; /* Ensure initial visibility */
            transition: opacity 0.5s ease-out; /* Smooth fade-out transition */
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
            border-radius: 0.5rem;
            font-size: 1rem;
            transition: border-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }

        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        /* Modal specific styling */
        .modal-overlay {
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background-color: white;
            border-radius: 0.5rem;
            padding: 1.25rem; /* 20px padding for mobile and up */
            max-width: 28rem;
            width: 100%;
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
            position: relative;
        }
        @media (min-width: 640px) { /* sm breakpoint */
            .modal-content {
                padding: 1.5rem; /* 24px on larger screens (original p-6) */
            }
        }
    </style>
</head>
<body class="bg-gray-50" x-cloak>

<?php require_once __DIR__ . '/../layout/headersidebar.php';?>

<main class="p-[20px] md:py-6 md:px-4 md:ml-64">
    <div class="max-w-full mx-auto py-0">
        <?php
        display_session_message();
        ?>

        <div class="bg-white rounded-xl shadow-md overflow-hidden p-6">
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 mb-6">
                <div class="flex items-center gap-3">
                    <i class="fas fa-file-pdf text-2xl text-blue-600"></i>
                    <h1 class="text-2xl font-semibold text-gray-800">Manage Editions</h1>
                </div>
                <a href="upload-edition.php" class="w-full sm:w-auto inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-300 justify-center">
                    <i class="fas fa-upload mr-2"></i> Upload New Edition
                </a>
            </div>

            <!-- Search and Filter Form -->
            <form method="GET" class="mb-6 bg-gray-50 p-4 rounded-lg shadow-sm">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                    <div class="form-group">
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search Title/Desc.</label>
                        <input type="text" name="search" id="search" placeholder="Search editions..."
                               class="form-input" value="<?= htmlspecialchars($searchQuery) ?>">
                    </div>
                    <div class="form-group">
                        <label for="category_filter" class="block text-sm font-medium text-gray-700 mb-1">Filter by Category</label>
                        <select name="category" id="category_filter" class="form-input">
                            <option value="">All Categories</option>
                            <?php foreach ($categoriesForFilter as $category): ?>
                                <option value="<?= htmlspecialchars($category['category_id']) ?>"
                                    <?= ($filterCategory == $category['category_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="uploader_filter" class="block text-sm font-medium text-gray-700 mb-1">Filter by Uploader</label>
                        <select name="uploader" id="uploader_filter" class="form-input">
                            <option value="">All Uploaders</option>
                            <?php foreach ($uploadersForFilter as $uploader): ?>
                                <option value="<?= htmlspecialchars($uploader['user_id']) ?>"
                                    <?= ($filterUploader == $uploader['user_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($uploader['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="month_year_filter" class="block text-sm font-medium text-gray-700 mb-1">Filter by Month</label>
                        <select name="month_year" id="month_year_filter" class="form-input">
                            <option value="">All Months</option>
                            <?php foreach ($monthsForFilter as $month): ?>
                                <option value="<?= htmlspecialchars($month['month_year_val']) ?>"
                                    <?= ($filterMonthYear == $month['month_year_val']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($month['month_year_display']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row items-center justify-end gap-2">
                    <button type="submit" class="w-full sm:w-auto bg-blue-500 hover:bg-blue-600 text-white font-medium py-2 px-4 rounded-lg transition duration-300 flex items-center justify-center">
                        <i class="fas fa-filter mr-2"></i> Apply Filters
                    </button>
                    <a href="list-editions.php" class="w-full sm:w-auto bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-lg transition duration-300 flex items-center justify-center">
                        <i class="fas fa-sync-alt mr-2"></i> Reset Filters
                    </a>
                </div>
            </form>

            <?php if (empty($editions)): ?>
                <div class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-lg text-center">
                    <i class="fas fa-info-circle mr-2"></i> No editions found matching your criteria.
                </div>
            <?php else: ?>
                <!-- Bulk Actions and Results Per Page -->
                <div class="flex flex-col sm:flex-row justify-between items-center mb-4 gap-3">
                    <form method="GET" class="flex items-center gap-2">
                        <label for="per_page_select" class="text-sm font-medium text-gray-700">Show</label>
                        <select name="per_page" id="per_page_select" class="form-input w-20" onchange="this.form.submit()">
                            <?php foreach ($resultsPerPageOptions as $option): ?>
                                <option value="<?= $option ?>" <?= ($resultsPerPage == $option) ? 'selected' : '' ?>>
                                    <?= $option ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="text-sm font-medium text-gray-700">results per page</span>
                        <?php
                        // Preserve other GET parameters when changing per_page
                        foreach ($_GET as $key => $value) {
                            if ($key !== 'per_page' && $key !== 'page') {
                                if (is_array($value)) {
                                    foreach ($value as $val) {
                                        echo '<input type="hidden" name="' . htmlspecialchars($key) . '[]" value="' . htmlspecialchars($val) . '">';
                                    }
                                } else {
                                    echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">';
                                }
                            }
                        }
                        ?>
                    </form>

                    <button type="button"
                            @click="deleteModalOpen = true; editionToDeleteId = null; deleteConfirmationInput = ''; deleteError = ''; $nextTick(() => $refs.deleteConfirmInput.focus());"
                            :disabled="selectedEditions.length === 0"
                            class="w-full sm:w-auto inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition duration-300 justify-center"
                            :class="{'opacity-50 cursor-not-allowed': selectedEditions.length === 0}">
                        <i class="fas fa-trash-alt mr-2"></i> Bulk Delete (<span x-text="selectedEditions.length"></span>)
                    </button>
                </div>


                <div class="overflow-x-auto rounded-lg shadow-sm border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <input type="checkbox" x-model="selectAll" @change="selectedEditions = selectAll ? Array.from(document.querySelectorAll('input[name=\'selected_editions[]\']')).map(el => el.value) : []" class="rounded text-blue-600 focus:ring-blue-500">
                                </th>
                                <?php
                                $currentQuery = $_GET; // Get current GET parameters

                                function getSortLink($column, $currentSortColumn, $currentSortOrder, $currentQuery) {
                                    $newOrder = 'ASC';
                                    $icon = '';
                                    if ($currentSortColumn === $column) {
                                        $newOrder = ($currentSortOrder === 'ASC') ? 'DESC' : 'ASC';
                                        $icon = ($currentSortOrder === 'ASC') ? '<i class="fas fa-sort-up ml-1"></i>' : '<i class="fas fa-sort-down ml-1"></i>';
                                    } else {
                                        $icon = '<i class="fas fa-sort ml-1 text-gray-400"></i>';
                                    }

                                    $currentQuery['sort_column'] = $column;
                                    $currentQuery['sort_order'] = $newOrder;
                                    // Preserve pagination on sort
                                    $currentQuery['page'] = $GLOBALS['currentPage'];
                                    $currentQuery['per_page'] = $GLOBALS['resultsPerPage'];
                                    $queryString = http_build_query($currentQuery);

                                    return [
                                        'href' => "list-editions.php?" . $queryString,
                                        'icon' => $icon
                                    ];
                                }
                                ?>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Edition ID</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <?php $link = getSortLink('title', $sortColumn, $sortOrder, $currentQuery); ?>
                                    <a href="<?= $link['href'] ?>" class="flex items-center whitespace-nowrap">
                                        Title <?= $link['icon'] ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <?php $link = getSortLink('publication_date', $sortColumn, $sortOrder, $currentQuery); ?>
                                    <a href="<?= $link['href'] ?>" class="flex items-center whitespace-nowrap">
                                        Publication Date <?= $link['icon'] ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">PDF Path</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">OG Image</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">List Thumb</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <?php $link = getSortLink('page_count', $sortColumn, $sortOrder, $currentQuery); ?>
                                    <a href="<?= $link['href'] ?>" class="flex items-center whitespace-nowrap">
                                        Pages <?= $link['icon'] ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <?php $link = getSortLink('file_size_bytes', $sortColumn, $sortOrder, $currentQuery); ?>
                                    <a href="<?= $link['href'] ?>" class="flex items-center whitespace-nowrap">
                                        File Size (MB) <?= $link['icon'] ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <?php $link = getSortLink('uploaded_at', $sortColumn, $sortOrder, $currentQuery); ?>
                                    <a href="<?= $link['href'] ?>" class="flex items-center whitespace-nowrap">
                                        Created At <?= $link['icon'] ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Uploader ID</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category ID</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Updated At</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <?php $link = getSortLink('views_count', $sortColumn, $sortOrder, $currentQuery); ?>
                                    <a href="<?= $link['href'] ?>" class="flex items-center whitespace-nowrap">
                                        Views <?= $link['icon'] ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <?php $link = getSortLink('status', $sortColumn, $sortOrder, $currentQuery); ?>
                                    <a href="<?= $link['href'] ?>" class="flex items-center whitespace-nowrap">
                                        Status <?= $link['icon'] ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status Reason</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <?php $link = getSortLink('category_name', $sortColumn, $sortOrder, $currentQuery); ?>
                                    <a href="<?= $link['href'] ?>" class="flex items-center whitespace-nowrap">
                                        Category Name <?= $link['icon'] ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($editions as $edition): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <input type="checkbox" name="selected_editions[]" value="<?= htmlspecialchars($edition['edition_id']) ?>" x-model="selectedEditions" class="rounded text-blue-600 focus:ring-blue-500">
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($edition['edition_id']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <a href="<?= htmlspecialchars($edition['pdf_path']) ?>" target="_blank" class="text-blue-600 hover:text-blue-800 hover:underline flex items-center">
                                            <img src="https://img.icons8.com/color/48/pdf-2--v1.png" alt="PDF icon" class="w-5 h-5 mr-2 inline-block">
                                            <?= htmlspecialchars($edition['title']) ?>
                                        </a>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('Y-m-d', strtotime($edition['publication_date'])) ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500 truncate max-w-xs">
                                        <a href="<?= htmlspecialchars($edition['pdf_path']) ?>" target="_blank" class="text-blue-600 hover:underline">
                                            <?= htmlspecialchars(basename($edition['pdf_path'])) ?>
                                        </a>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        <?php if (!empty($edition['og_image_path'])): ?>
                                            <img src="<?= htmlspecialchars($edition['og_image_path']) ?>" alt="OG Image" class="w-10 h-auto rounded">
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        <?php if (!empty($edition['list_thumb_path'])): ?>
                                            <img src="<?= htmlspecialchars($edition['list_thumb_path']) ?>" alt="Thumbnail" class="w-10 h-auto rounded">
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars($edition['page_count'] ?? 'N/A') ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= formatBytesToMB($edition['file_size_bytes']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('Y-m-d h:i A', strtotime($edition['created_at'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars($edition['uploader_user_id'] ?? 'N/A') ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars($edition['category_id'] ?? 'N/A') ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate" title="<?= htmlspecialchars($edition['description'] ?? '') ?>">
                                        <?= htmlspecialchars($edition['description'] ?? 'N/A') ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= ($edition['updated_at']) ? date('Y-m-d h:i A', strtotime($edition['updated_at'])) : 'N/A' ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars($edition['views_count'] ?? '0') ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                            <?= ($edition['status'] === 'published') ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>">
                                            <?= htmlspecialchars(ucfirst($edition['status'])) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate" title="<?= htmlspecialchars($edition['status_reason'] ?? '') ?>">
                                        <?= htmlspecialchars($edition['status_reason'] ?? 'N/A') ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars($edition['category_name'] ?? 'N/A') ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-left text-sm font-medium">
                                        <div class="flex items-center space-x-2">
                                            <a href="edit-edition.php?id=<?= htmlspecialchars($edition['edition_id']) ?>"
                                                class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 transition duration-150 ease-in-out">
                                                <i class="fas fa-edit mr-1"></i> Edit
                                            </a>
                                            <button type="button"
                                                @click='deleteModalOpen = true; editionToDeleteId = <?= $edition['edition_id'] ?>; selectedEditions = []; deleteConfirmationInput = ""; deleteError = ""; $nextTick(() => $refs.deleteConfirmInput.focus());'
                                                class="inline-flex items-center px-3 py-1.5 bg-red-600 text-white rounded-md hover:bg-red-700 transition duration-150 ease-in-out">
                                                <i class="fas fa-trash-alt mr-1"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Controls -->
                <nav class="mt-6 flex items-center justify-between">
                    <div class="text-sm text-gray-700">
                        Showing <span class="font-semibold"><?= min($totalRecords, $offset + 1) ?></span> to <span class="font-semibold"><?= min($totalRecords, $offset + $resultsPerPage) ?></span> of <span class="font-semibold"><?= $totalRecords ?></span> results
                    </div>
                    <div class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                        <?php
                        // Helper to generate pagination links
                        function getPaginationLink($page, $currentQuery) {
                            $tempQuery = $currentQuery;
                            $tempQuery['page'] = $page;
                            return "list-editions.php?" . http_build_query($tempQuery);
                        }
                        ?>

                        <a href="<?= getPaginationLink($currentPage - 1, $_GET) ?>"
                           class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 <?= ($currentPage <= 1) ? 'opacity-50 cursor-not-allowed' : '' ?>"
                           <?= ($currentPage <= 1) ? 'aria-disabled="true"' : '' ?>>
                            <span class="sr-only">Previous</span>
                            <i class="fas fa-chevron-left h-5 w-5"></i>
                        </a>

                        <?php
                        // Display 5 page numbers around the current page
                        $startPage = max(1, $currentPage - 2);
                        $endPage = min($totalPages, $currentPage + 2);

                        if ($startPage > 1) {
                            echo '<a href="' . getPaginationLink(1, $_GET) . '" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">1</a>';
                            if ($startPage > 2) {
                                echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>';
                            }
                        }

                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <a href="<?= getPaginationLink($i, $_GET) ?>"
                               class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 <?= ($i == $currentPage) ? 'z-10 bg-blue-50 border-blue-500 text-blue-600' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>

                        <?php
                        if ($endPage < $totalPages) {
                            if ($endPage < $totalPages - 1) {
                                echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>';
                            }
                            echo '<a href="' . getPaginationLink($totalPages, $_GET) . '" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">' . $totalPages . '</a>';
                        }
                        ?>

                        <a href="<?= getPaginationLink($currentPage + 1, $_GET) ?>"
                           class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 <?= ($currentPage >= $totalPages) ? 'opacity-50 cursor-not-allowed' : '' ?>"
                           <?= ($currentPage >= $totalPages) ? 'aria-disabled="true"' : '' ?>>
                            <span class="sr-only">Next</span>
                            <i class="fas fa-chevron-right h-5 w-5"></i>
                        </a>
                    </div>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Delete Confirmation Modal -->
<div class="fixed inset-0 z-50 modal-overlay" x-show="deleteModalOpen" x-transition @click.away="deleteModalOpen = false; deleteError = ''; deleteConfirmationInput = '';">
    <div class="modal-content" @click.stop>
        <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4" x-text="selectedEditions.length > 0 ? 'Confirm Bulk Deletion (' + selectedEditions.length + ' editions)' : 'Confirm Deletion'"></h3>
        <div class="mt-2">
            <p class="text-sm text-gray-700">
                You are about to permanently delete <span x-text="selectedEditions.length > 0 ? selectedEditions.length + ' editions' : 'this edition'"></span> and their associated PDF files. This action cannot be undone.
                <br><br>
                Please type "<strong>delete</strong>" in the box below to confirm.
            </p>
            <input type="text" x-model="deleteConfirmationInput" x-ref="deleteConfirmInput"
                   class="mt-3 block w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-red-500 focus:border-red-500 sm:text-sm"
                   :class="deleteConfirmationInput === 'delete' ? 'border-green-500' : 'border-gray-300'"
                   placeholder="type 'delete' to confirm"
                   @keyup.enter="
                       if (deleteConfirmationInput === 'delete') {
                           document.getElementById('deleteEditionForm').submit();
                       } else {
                           deleteError = 'Please type &quot;delete&quot; to confirm.';
                       }
                   ">
            <p x-show="deleteError" x-text="deleteError" class="text-red-600 text-xs mt-1 font-semibold"></p>
        </div>
        <div class="mt-4 flex flex-col sm:flex-row-reverse justify-end gap-3">
            <form id="deleteEditionForm" method="POST" action="list-editions.php" class="w-full sm:w-auto">
                <input type="hidden" name="action" value="delete_edition">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <!-- This input handles either a single ID or multiple IDs -->
                <template x-if="selectedEditions.length > 0">
                    <template x-for="id in selectedEditions" :key="id">
                        <input type="hidden" name="edition_ids[]" :value="id">
                    </template>
                </template>
                <template x-if="selectedEditions.length === 0 && editionToDeleteId !== null">
                    <input type="hidden" name="edition_ids[]" :value="editionToDeleteId">
                </template>

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
                        class="inline-flex justify-center w-full sm:w-auto py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors duration-200">
                    Delete Edition(s)
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
