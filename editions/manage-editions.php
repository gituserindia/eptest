<?php
// File: manage-editions.php
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

// Corrected path for config.php:
// Assuming config.php is in a 'config' directory one level up from the current script.
// For example, if manage-editions.php is in /public_html/editions/, and config.php is in /public_html/config/
require_once __DIR__ . '/../config/config.php';

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

// --- Pagination Parameters ---
$itemsPerPage = 12; // Number of editions to display per page
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
// Ensure current page is at least 1
if ($currentPage < 1) {
    $currentPage = 1;
}
$offset = ($currentPage - 1) * $itemsPerPage;

// Sorting parameters (retained for pagination links)
$sortColumn = $_GET['sort_column'] ?? 'publication_date'; // Default sort column
$sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC'); // Default sort order

// Validate sort column to prevent SQL injection
$allowedSortColumns = [
    'title' => 'e.title',
    'publication_date' => 'e.publication_date',
    'category_name' => 'c.name',
    'uploaded_at' => 'e.created_at',
    'uploader_username' => 'u.username',
    'status' => 'e.status'
];

$sqlSortColumn = $allowedSortColumns[$sortColumn] ?? 'e.publication_date'; // Fallback to default if invalid
$sqlSortOrder = ($sortOrder === 'ASC') ? 'ASC' : 'DESC'; // Ensure valid sort order

// --- Filtering Parameters ---
$titleFilter = trim($_GET['title_filter'] ?? '');
$startDateFilter = trim($_GET['start_date_filter'] ?? '');
$endDateFilter = trim($_GET['end_date_filter'] ?? '');
$categoryFilter = !empty($_GET['category_filter']) ? (int)$_GET['category_filter'] : null;
$statusFilter = trim($_GET['status_filter'] ?? '');

// Fetch categories for the filter dropdown
$categories = [];
try {
    $catStmt = $pdo->query("SELECT category_id, name FROM categories ORDER BY name ASC");
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching categories: " . $e->getMessage());
    // Optionally set a user-friendly message
}


// --- Build WHERE clause for filters ---
$whereClauses = ["1=1"]; // Start with a true condition
$queryParams = [];

if (!empty($titleFilter)) {
    $whereClauses[] = "e.title LIKE :title_filter";
    $queryParams[':title_filter'] = '%' . $titleFilter . '%';
}

if (!empty($startDateFilter)) {
    $whereClauses[] = "e.publication_date >= :start_date_filter";
    $queryParams[':start_date_filter'] = $startDateFilter;
}

if (!empty($endDateFilter)) {
    $whereClauses[] = "e.publication_date <= :end_date_filter";
    $queryParams[':end_date_filter'] = $endDateFilter;
}

if ($categoryFilter !== null) {
    $whereClauses[] = "e.category_id = :category_filter";
    $queryParams[':category_filter'] = $categoryFilter;
}

if (!empty($statusFilter) && in_array($statusFilter, ['published', 'private'])) {
    $whereClauses[] = "e.status = :status_filter";
    $queryParams[':status_filter'] = $statusFilter;
}

$whereSql = " WHERE " . implode(" AND ", $whereClauses);


// --- Get Total Number of Editions for Pagination (with filters) ---
$countSql = "SELECT COUNT(*) FROM editions e LEFT JOIN categories c ON e.category_id = c.category_id " . $whereSql;
try {
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($queryParams); // Pass query parameters to count statement
    $totalEditions = 0; // Default to 0 on error
    if ($countStmt) { // Check if statement was prepared successfully
        $totalEditions = $countStmt->fetchColumn();
    }
} catch (PDOException $e) {
    error_log("Database error fetching filtered edition count: " . $e->getMessage());
    $totalEditions = 0; // Default to 0 on error
}

$totalPages = ceil($totalEditions / $itemsPerPage);
// Ensure current page does not exceed total pages (unless total pages is 0)
if ($totalPages > 0 && $currentPage > $totalPages) {
    $currentPage = $totalPages;
    $offset = ($currentPage - 1) * $itemsPerPage; // Re-calculate offset for the adjusted page
} elseif ($totalPages == 0) {
    $currentPage = 1; // If no editions, stay on page 1
    $offset = 0;
}


// Build SQL query for editions with LIMIT and OFFSET
$sql = "
    SELECT
        e.edition_id,
        e.title,
        e.publication_date,
        e.pdf_path,
        e.og_image_path,
        e.list_thumb_path,   /* IMPORTANT: Fetch the list thumbnail path */
        c.name AS category_name,
        u.username AS uploader_username,
        e.created_at,
        e.status
    FROM
        editions e
    LEFT JOIN
        categories c ON e.category_id = c.category_id
    LEFT JOIN
        users u ON e.uploader_user_id = u.user_id
    {$whereSql}
";

// Add dynamic sorting
$sql .= " ORDER BY {$sqlSortColumn} {$sqlSortOrder}, e.created_at DESC";

// Add LIMIT and OFFSET for pagination
$sql .= " LIMIT :limit OFFSET :offset";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    // Bind all filter parameters
    foreach ($queryParams as $key => $value) {
        $stmt->bindValue($key, $value);
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
    $edition_id = (int)($_POST['edition_id'] ?? 0);
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Invalid CSRF token. Please try again.</div>';
        header("Location: " . basename($_SERVER['PHP_SELF'])); // Redirect back to the current page
        exit;
    }

    if ($edition_id === 0) {
        $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Invalid edition ID for deletion.</div>';
    } else {
        try {
            // First, get the PDF path and the folder containing images
            $stmt = $pdo->prepare("SELECT pdf_path, og_image_path, list_thumb_path FROM editions WHERE edition_id = ?");
            $stmt->execute([$edition_id]);
            $edition = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($edition && !empty($edition['pdf_path'])) {
                // IMPORTANT: __DIR__ ensures this path is absolute on the server for file system operations.
                // For example: if __DIR__ is /var/www/html/your_app/
                // and $edition['pdf_path'] is 'uploads/2023/10/26/unique_id/document.pdf'
                // Then $file_path will be /var/www/html/your_app/uploads/2023/10/26/unique_id/document.pdf
                $file_path = __DIR__ . '/' . $edition['pdf_path'];
                $edition_folder = dirname($file_path); // Get the parent folder of the PDF (e.g., .../unique_id/)
                $images_folder = $edition_folder . '/images'; // Path to the images subfolder (e.g., .../unique_id/images/)

                // Delete the actual PDF file
                if (file_exists($file_path)) {
                    unlink($file_path);
                }

                // Delete image files within the 'images' subfolder, including new thumbnails
                if (is_dir($images_folder)) {
                    $files = array_merge(
                        glob($images_folder . '/page-*.jpg'),
                        glob($images_folder . '/page_*.jpg'), // Include old format for robust cleanup
                        glob($images_folder . '/temp_raw_*.jpg'),
                        glob($images_folder . '/og-thumb.jpg'),
                        glob($images_folder . '/list-thumb.jpg')
                    );
                    foreach($files as $file){
                        if(is_file($file)) {
                            unlink($file); // Delete each file
                        }
                    }
                    rmdir($images_folder); // Delete the empty images directory
                }

                // Delete the edition's main unique folder if it's empty
                // scandir returns . and .. so an empty directory has 2 entries.
                if (is_dir($edition_folder) && count(scandir($edition_folder)) == 2) {
                    rmdir($edition_folder);
                    // Try to remove higher level empty date directories if they become empty
                    $day_folder = dirname($edition_folder);
                    if (is_dir($day_folder) && count(scandir($day_folder)) == 2) { rmdir($day_folder); }
                    $month_folder = dirname($day_folder);
                    if (is_dir($month_folder) && count(scandir($month_folder)) == 2) { rmdir($month_folder); }
                    $year_folder = dirname($month_folder);
                    if (is_dir($year_folder) && count(scandir($year_folder)) == 2) { rmdir($year_folder); }
                }
            }

            // Then, delete the record from the database
            $stmt = $pdo->prepare("DELETE FROM editions WHERE edition_id = ?");
            if ($stmt->execute([$edition_id])) {
                $_SESSION['message'] = '<div class="alert alert-success"><i class="fas fa-check-circle mr-2"></i> Edition deleted successfully!</div>';
            } else {
                $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Failed to delete edition from database.</div>';
            }
        } catch (PDOException $e) {
            error_log("Database error deleting edition: " . $e->getMessage());
            $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> A database error occurred while deleting edition.</div>';
        }
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Regenerate token after action
    header("Location: " . basename($_SERVER['PHP_SELF'])); // Redirect back to the current page
    exit;
}

// Generate the heading for the current view (simplified as no filters)
$currentViewHeading = "All Editions";


?>
<!DOCTYPE html>
<html lang="en" x-data="{
    sidebarOpen: false,
    profileMenuOpen: false,
    mobileProfileMenuOpen: false,
    moreMenuOpen: false,
    deleteModalOpen: false,
    editionToDeleteId: null, // For delete modal
    deleteConfirmationInput: '', // For delete modal confirmation
    deleteError: '',
    filterMenuOpen: false, // for filter dropdown
    showCopyMessage: false, // For copy link success message
    copyMessageText: '', // Text for the copy message
    copyMessageType: '', // Type of message (e.g., 'success', 'info')
    
    // Function to copy text to clipboard
    copyToClipboard(text) {
        let textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed'; // Keep it off-screen
        textarea.style.left = '-9999px';
        textarea.style.top = '-9999px';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        try {
            const successful = document.execCommand('copy');
            if (successful) {
                this.copyMessageText = 'Link copied to clipboard!';
                this.copyMessageType = 'success';
            } else {
                this.copyMessageText = 'Failed to copy link. Please try again.';
                this.copyMessageType = 'error';
            }
        } catch (err) {
            console.error('Failed to copy text: ', err);
            this.copyMessageText = 'Error copying link. Browser might not support this feature.';
            this.copyMessageType = 'error';
        } finally {
            document.body.removeChild(textarea);
            this.showCopyMessage = true;
            setTimeout(() => { this.showCopyMessage = false; }, 3000); // Hide after 3 seconds
        }
    }
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

        /* Card specific styles */
        .edition-card {
            background-color: #fff;
            border-radius: 0.75rem; /* More rounded corners for cards */
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1), 0 1px 3px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            border: 1px solid #e5e7eb; /* Light border for separation */
        }
        .edition-card:hover {
            transform: translateY(-5px); /* Slight lift effect on hover */
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.15), 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .edition-card img {
            width: 100%;
            height: 250px; /* Fixed height for image consistency */
            object-fit: cover;
            /* IMPORTANT CHANGE: Position the image from the top */
            object-position: top;
            border-bottom: 1px solid #f3f4f6;
        }
        .status-badge {
            font-size: 0.75rem; /* text-xs */
            padding: 0.25rem 0.625rem; /* px-2 py-1 */
            border-radius: 9999px; /* rounded-full */
            font-weight: 600; /* font-semibold */
            display: inline-flex;
            align-items: center;
            margin-bottom: 0.5rem; /* Space below status badge */
        }
        .status-published {
            background-color: #dcfce7; /* bg-green-100 */
            color: #16a34a; /* text-green-800 */
        }
        .status-private {
            background-color: #fef3c7; /* bg-yellow-100 */
            color: #b45309; /* text-yellow-800 */
        }
        /* New/Adjusted styles for card content and actions */
        .edition-card-content {
            padding: 1rem;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .edition-card-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }
        .edition-card-meta {
            font-size: 0.875rem;
            color: #6b7280;
            margin-bottom: 0.5rem;
        }
        .edition-card-actions {
            margin-top: 1rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .edition-card-actions a,
        .edition-card-actions button {
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            border-radius: 0.5rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-grow: 1;
            text-align: center;
        }


    </style>
</head>
<body class="bg-gray-50" x-cloak>

<?php 
// Assuming headersidebar.php is in the parent directory of the 'editions' folder,
// and 'editions' is directly under 'public_html'.
// So, from 'public_html/editions/manage-editions.php', you need to go up one level (..)
// to 'public_html/', and then into a potential 'layout' folder, or directly to 'headersidebar.php'
// If headersidebar.php is directly in public_html:
// require_once __DIR__ . '/../headersidebar.php';
// If headersidebar.php is in public_html/layout/:
require_once __DIR__ . '/../layout/headersidebar.php';
?>

<main class="p-[20px] md:py-6 md:px-4 md:ml-64">
    <div class="max-w-full mx-auto py-0">
        <?php
        display_session_message();
        ?>

        <!-- Custom Copy Message Info Card -->
        <div x-show="showCopyMessage"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 transform -translate-y-full"
             x-transition:enter-end="opacity-100 transform translate-y-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 transform translate-y-0"
             x-transition:leave-end="opacity-0 transform -translate-y-full"
             class="fixed top-4 left-1/2 -translate-x-1/2 z-50 w-full max-w-sm px-4">
            <div class="alert"
                 :class="{
                     'alert-success': copyMessageType === 'success',
                     'alert-info': copyMessageType === 'info',
                     'alert-error': copyMessageType === 'error'
                 }"
                 role="alert">
                <i class="fas" :class="{ 'fa-info-circle': copyMessageType === 'info', 'fa-check-circle': copyMessageType === 'success', 'fa-exclamation-circle': copyMessageType === 'error' }"></i>
                <span x-text="copyMessageText"></span>
            </div>
        </div>

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

            <h2 class="text-xl font-semibold text-gray-800 mb-4"><?= htmlspecialchars($currentViewHeading) ?></h2>

            <!-- Filter Section -->
            <div class="mb-6 bg-gray-50 p-4 rounded-lg shadow-inner">
                <button @click="filterMenuOpen = !filterMenuOpen"
                        class="w-full bg-gray-200 text-gray-800 px-4 py-2 rounded-md flex items-center justify-between hover:bg-gray-300 transition-colors duration-200">
                    <span><i class="fas fa-filter mr-2"></i> Filter Editions</span>
                    <i class="fas" :class="filterMenuOpen ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                </button>

                <div x-show="filterMenuOpen" x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 transform -translate-y-2"
                     x-transition:enter-end="opacity-100 transform translate-y-0"
                     x-transition:leave="transition ease-in duration-200"
                     x-transition:leave-start="opacity-100 transform translate-y-0"
                     x-transition:leave-end="opacity-0 transform -translate-y-2"
                     class="mt-4 border-t border-gray-200 pt-4">
                    <form method="GET" action="<?= htmlspecialchars(basename($_SERVER['PHP_SELF'])) ?>" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div>
                            <label for="title_filter" class="block text-sm font-medium text-gray-700 mb-1">Title Keyword:</label>
                            <input type="text" id="title_filter" name="title_filter" value="<?= htmlspecialchars($titleFilter) ?>"
                                   placeholder="Search by title"
                                   class="form-input">
                        </div>
                        <div>
                            <label for="start_date_filter" class="block text-sm font-medium text-gray-700 mb-1">From Date (Publication):</label>
                            <input type="date" id="start_date_filter" name="start_date_filter" value="<?= htmlspecialchars($startDateFilter) ?>"
                                   class="form-input">
                        </div>
                        <div>
                            <label for="end_date_filter" class="block text-sm font-medium text-gray-700 mb-1">To Date (Publication):</label>
                            <input type="date" id="end_date_filter" name="end_date_filter" value="<?= htmlspecialchars($endDateFilter) ?>"
                                   class="form-input">
                        </div>
                        <div>
                            <label for="category_filter" class="block text-sm font-medium text-gray-700 mb-1">Category:</label>
                            <select id="category_filter" name="category_filter" class="form-input">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= htmlspecialchars($category['category_id']) ?>"
                                        <?= ($categoryFilter == $category['category_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($category['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="status_filter" class="block text-sm font-medium text-gray-700 mb-1">Status:</label>
                            <select id="status_filter" name="status_filter" class="form-input">
                                <option value="">All Statuses</option>
                                <option value="published" <?= ($statusFilter === 'published') ? 'selected' : '' ?>>Published</option>
                                <option value="private" <?= ($statusFilter === 'private') ? 'selected' : '' ?>>Private</option>
                            </select>
                        </div>
                        <div class="col-span-full flex flex-col sm:flex-row gap-3 mt-2">
                            <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200">
                                <i class="fas fa-search mr-2"></i> Apply Filters
                            </button>
                            <a href="<?= htmlspecialchars(basename($_SERVER['PHP_SELF'])) ?>" class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200">
                                <i class="fas fa-sync-alt mr-2"></i> Reset Filters
                            </a>
                        </div>
                    </form>
                </div>
            </div>
            <!-- End Filter Section -->

            <?php if (empty($editions)): ?>
                <div class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-lg text-center">
                    <i class="fas fa-info-circle mr-2"></i> No editions found matching your criteria.
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                    <?php foreach ($editions as $edition):
                        // IMPORTANT CHANGE: Use list_thumb_path for the card display
                        $image_path_to_display = !empty($edition['list_thumb_path']) ? $edition['list_thumb_path'] : '';

                        // Fallback image path: Placeholder with dimensions for list-thumb (height 1200, proportional width, so 630x1200 is a safe portrait placeholder)
                        $fallback_image = 'https://placehold.co/630x1200/E5E7EB/6B7280?text=No+Preview';
                        
                        // Construct the URL for the view button
                        $view_url = "view-edition.php?date=" . htmlspecialchars($edition['publication_date']) . "&edition_id=" . htmlspecialchars($edition['edition_id']);
                    ?>
                        <div class="edition-card">
                            <!-- REMOVED <a> TAG: No action when clicking list_thumbnail -->
                            <img src="<?= htmlspecialchars($image_path_to_display) ?>"
                                 alt="Preview of <?= htmlspecialchars($edition['title']) ?>"
                                 onerror="this.onerror=null; this.src='<?= htmlspecialchars($fallback_image) ?>';"
                                 loading="lazy">
                            <div class="edition-card-content">
                                <div>
                                    <h3 class="edition-card-title"><?= htmlspecialchars($edition['title']) ?></h3>
                                    <p class="edition-card-meta">
                                        Published: <?= date('d M Y', strtotime($edition['publication_date'])) ?>
                                    </p>
                                    <p class="edition-card-meta">
                                        Category: <?= htmlspecialchars($edition['category_name'] ?? 'N/A') ?>
                                    </p>
                                    <p class="edition-card-meta">
                                        Uploaded By: <?= htmlspecialchars($edition['uploader_username'] ?? 'N/A') ?>
                                    </p>
                                    <span class="status-badge <?= ($edition['status'] === 'published') ? 'status-published' : 'status-private' ?>">
                                        <?= htmlspecialchars(ucfirst($edition['status'])) ?>
                                    </span>
                                </div>
                                <div class="edition-card-actions">
                                    <!-- UPDATED: View Button links to view-edition.php with parameters -->
                                    <a href="<?= $view_url ?>"
                                       class="bg-blue-600 text-white hover:bg-blue-700 transition-colors duration-150">
                                        <i class="fas fa-eye mr-1"></i> View
                                    </a>
                                    <!-- UPDATED: Copy Link Button to use the Alpine.js function -->
                                    <button type="button"
                                            @click="copyToClipboard(new URL('<?= $view_url ?>', window.location.origin).href)"
                                            class="bg-green-600 text-white hover:bg-green-700 transition-colors duration-150">
                                        <i class="fas fa-copy mr-1"></i> Copy Link
                                    </button>
                                    <!-- NEW: Edit Button - Color changed to a thicker orange -->
                                    <a href="edit-edition.php?id=<?= htmlspecialchars($edition['edition_id']) ?>"
                                       class="bg-orange-600 text-white hover:bg-orange-700 transition-colors duration-150">
                                        <i class="fas fa-edit mr-1"></i> Edit
                                    </a>
                                    <button type="button"
                                            @click='deleteModalOpen = true; editionToDeleteId = <?= $edition['edition_id'] ?>; deleteConfirmationInput = ""; deleteError = ""; $nextTick(() => $refs.deleteConfirmInput.focus());'
                                            class="bg-red-600 text-white hover:bg-red-700 transition-colors duration-150">
                                        <i class="fas fa-trash-alt mr-1"></i> Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                    <!-- Pagination Controls -->
                    <nav class="flex justify-center items-center gap-2 mt-8" aria-label="Pagination">
                        <?php
                        // Construct the base URL for pagination links, preserving sort and filter parameters
                        $currentScript = basename($_SERVER['PHP_SELF']);
                        $baseUrlParams = [
                            'sort_column' => $sortColumn,
                            'sort_order' => $sortOrder,
                            'title_filter' => $titleFilter,
                            'start_date_filter' => $startDateFilter,
                            'end_date_filter' => $endDateFilter,
                            'category_filter' => $categoryFilter,
                            'status_filter' => $statusFilter
                        ];
                        // Remove empty parameters to keep URLs cleaner
                        $filteredBaseUrlParams = array_filter($baseUrlParams, function($value) {
                            return $value !== null && $value !== '';
                        });
                        $baseUrl = $currentScript . '?' . http_build_query($filteredBaseUrlParams);
                        ?>

                        <a href="<?= htmlspecialchars($baseUrl . '&page=' . max(1, $currentPage - 1)) ?>"
                           class="relative inline-flex items-center rounded-md px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 <?= ($currentPage <= 1) ? 'opacity-50 cursor-not-allowed pointer-events-none' : '' ?>">
                            <span class="sr-only">Previous</span>
                            <i class="fas fa-chevron-left h-5 w-5" aria-hidden="true"></i>
                        </a>

                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="<?= htmlspecialchars($baseUrl . '&page=' . $i) ?>"
                               class="relative inline-flex items-center rounded-md px-3 py-2 text-sm font-semibold <?= ($i === $currentPage) ? 'bg-blue-600 text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600' : 'text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>

                        <a href="<?= htmlspecialchars($baseUrl . '&page=' . min($totalPages, $currentPage + 1)) ?>"
                           class="relative inline-flex items-center rounded-md px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 <?= ($currentPage >= $totalPages) ? 'opacity-50 cursor-not-allowed pointer-events-none' : '' ?>">
                            <span class="sr-only">Next</span>
                            <i class="fas fa-chevron-right h-5 w-5" aria-hidden="true"></i>
                        </a>
                    </nav>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Delete Confirmation Modal -->
<div class="fixed inset-0 z-50 modal-overlay" x-show="deleteModalOpen" x-transition @click.away="deleteModalOpen = false; deleteError = ''; deleteConfirmationInput = '';">
    <div class="modal-content" @click.stop>
        <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Confirm Deletion</h3>
        <div class="mt-2">
            <p class="text-sm text-gray-700">
                You are about to permanently delete this edition and its associated PDF file. This action cannot be undone.
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
            <form id="deleteEditionForm" method="POST" action="<?= htmlspecialchars(basename($_SERVER['PHP_SELF'])) ?>" class="w-full sm:w-auto">
                <input type="hidden" name="action" value="delete_edition">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="edition_id" x-model="editionToDeleteId">
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
                    Delete Edition
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

</body>
</html>
