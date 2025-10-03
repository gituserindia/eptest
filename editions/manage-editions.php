<?php
// File: manage-editions.php
// Manages editions: view, search, filter, edit, delete.
// Accessible by 'SuperAdmin' and 'Admin' roles only.

// --- HTTP Security Headers ---
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', realpath(__DIR__ . '/..'));
}

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/vars/logovars.php';
require_once BASE_PATH . '/vars/settingsvars.php';

date_default_timezone_set('Asia/Kolkata');

// --- User Authentication and Authorization ---
$loggedIn = isset($_SESSION['user_id']);
$username = $loggedIn ? htmlspecialchars($_SESSION['username']) : null;
$userRole = $loggedIn ? ($_SESSION['user_role'] ?? 'Viewer') : null;
$isDarkMode = $loggedIn && isset($_SESSION['dark_mode']) && $_SESSION['dark_mode'];


if (!$loggedIn || !in_array($userRole, ['SuperAdmin', 'Admin'])) {
    $_SESSION['toast_message'] = 'You do not have permission to access this page.';
    $_SESSION['toast_type'] = 'error';
    $redirect_url = $loggedIn ? '/dashboard' : '/login';
    header("Location: " . $redirect_url);
    exit;
}

$pageTitle = "Manage Editions";

// --- CSRF Token ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- Form Handling (POST requests) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Token Validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['toast_message'] = 'Invalid CSRF token.';
        $_SESSION['toast_type'] = 'error';
        header("Location: /editions/manage-editions.php");
        exit;
    }

    // --- Delete Edition Action ---
    if (isset($_POST['action']) && $_POST['action'] === 'delete_edition') {
        $edition_id = filter_input(INPUT_POST, 'edition_id', FILTER_VALIDATE_INT);

        if ($edition_id) {
            $pdo->beginTransaction();
            try {
                // First, get all relevant paths from the database
                $stmt = $pdo->prepare("SELECT pdf_path, og_image_path, list_thumb_path FROM editions WHERE edition_id = :edition_id");
                $stmt->execute([':edition_id' => $edition_id]);
                $edition = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($edition && !empty($edition['pdf_path'])) {
                    // Build absolute paths safely using BASE_PATH
                    $pdf_relative_path = ltrim($edition['pdf_path'], '/\\');
                    $file_path = rtrim(BASE_PATH, '/\\') . DIRECTORY_SEPARATOR . $pdf_relative_path;
                    
                    $edition_folder = dirname($file_path);
                    $images_folder = $edition_folder . DIRECTORY_SEPARATOR . 'images';

                    // 1. Delete the main PDF file
                    if (file_exists($file_path) && is_file($file_path)) {
                        if (!unlink($file_path)) throw new Exception("Failed to delete PDF file.");
                    }

                    // 2. Delete all image files within the 'images' subfolder
                    if (is_dir($images_folder)) {
                        $files = glob($images_folder . '/*'); // Get all files in the directory
                        foreach ($files as $file) {
                            if (is_file($file)) {
                                if (!unlink($file)) throw new Exception("Failed to delete image file: " . basename($file));
                            }
                        }
                        // 3. Delete the now-empty images directory
                        if (!rmdir($images_folder)) throw new Exception("Failed to delete the 'images' directory.");
                    }

                    // 4. Delete the main edition folder if it is now empty
                    if (is_dir($edition_folder) && count(scandir($edition_folder)) == 2) {
                        if (!rmdir($edition_folder)) throw new Exception("Failed to delete the main edition directory.");
                        
                        // 5. Best-effort cleanup of parent date directories
                        $day_folder = dirname($edition_folder);
                        if (is_dir($day_folder) && count(scandir($day_folder)) == 2) {
                            @rmdir($day_folder);
                        }
                    }
                }

                // 6. If all file operations were successful, delete the database record
                $deleteStmt = $pdo->prepare("DELETE FROM editions WHERE edition_id = :edition_id");
                $deleteStmt->execute([':edition_id' => $edition_id]);

                if ($deleteStmt->rowCount() > 0) {
                    $pdo->commit();
                    $_SESSION['toast_message'] = 'Edition deleted successfully!';
                    $_SESSION['toast_type'] = 'success';
                } else {
                    throw new Exception("File cleanup succeeded, but the database record was not found.");
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Deletion Error: " . $e->getMessage());
                $_SESSION['toast_message'] = 'A critical error occurred during deletion. ' . $e->getMessage();
                $_SESSION['toast_type'] = 'error';
            }
        } else {
            $_SESSION['toast_message'] = 'Invalid edition ID for deletion.';
            $_SESSION['toast_type'] = 'error';
        }

        header("Location: /editions/manage-editions.php");
        exit;
    }
}


// --- Data Fetching & Filtering ---
$searchQuery = trim($_GET['search'] ?? '');
$categoryFilter = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT);
$statusFilter = trim($_GET['status'] ?? '');
$sortColumn = $_GET['sort_column'] ?? 'publication_date';
$sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');

$allowedSortColumns = ['title', 'publication_date', 'created_at', 'status', 'views_count'];
if (!in_array($sortColumn, $allowedSortColumns)) {
    $sortColumn = 'publication_date';
}
if (!in_array($sortOrder, ['ASC', 'DESC'])) {
    $sortOrder = 'DESC';
}

$itemsPerPage = 10;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($currentPage - 1) * $itemsPerPage;

$whereClauses = [];
$params = [];

if (!empty($searchQuery)) {
    $whereClauses[] = "(e.title LIKE :search OR e.description LIKE :search)";
    $params[':search'] = '%' . $searchQuery . '%';
}
if ($categoryFilter) {
    $whereClauses[] = "e.category_id = :category_id";
    $params[':category_id'] = $categoryFilter;
}
if (!empty($statusFilter) && in_array($statusFilter, ['Published', 'Private'])) {
    $whereClauses[] = "e.status = :status";
    $params[':status'] = $statusFilter;
}


$whereSql = count($whereClauses) > 0 ? "WHERE " . implode(' AND ', $whereClauses) : '';

try {
    // Fetch categories for filter dropdown
    $catStmt = $pdo->query("SELECT category_id, name FROM categories ORDER BY name ASC");
    $categoriesForFilter = $catStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch editions
    $countStmt = $pdo->prepare("SELECT COUNT(e.edition_id) FROM editions e {$whereSql}");
    $countStmt->execute($params);
    $totalEditions = $countStmt->fetchColumn();
    $totalPages = ceil($totalEditions / $itemsPerPage);

    $sql = "
        SELECT e.*, c.name as category_name, u.username as uploader_name
        FROM editions e 
        LEFT JOIN categories c ON e.category_id = c.category_id
        LEFT JOIN users u ON e.uploader_user_id = u.user_id
        {$whereSql}
        ORDER BY {$sortColumn} {$sortOrder}
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $editions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    $editions = [];
    $totalEditions = 0;
    $totalPages = 1;
    $categoriesForFilter = [];
    $_SESSION['toast_message'] = 'Could not fetch editions.';
    $_SESSION['toast_type'] = 'error';
}

?>
<!DOCTYPE html>
<html lang="en" x-data="{
    isDarkMode: <?= json_encode($isDarkMode) ?>,
    deleteModalOpen: false,
    editionToDelete: null,
    filterModalOpen: false,
    deleteConfirmationInput: '',
    copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(() => {
            alert('Link copied to clipboard!');
        }).catch(err => {
            alert('Failed to copy text.');
        });
    }
}" :class="{'dark': isDarkMode}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="icon" href="<?= htmlspecialchars($faviconPath ?? '/favicon.ico') ?>" type="image/x-icon">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #312e81; /* indigo-900 */
            --secondary-color: #3730a3; /* indigo-800 */
        }
        body { font-family: 'Inter', sans-serif; }
        [x-cloak] { display: none !important; }

        .edition-card {
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        }
        .edition-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
        }
        
        [x-tooltip] {
            position: relative;
        }
        [x-tooltip]::after {
            content: attr(x-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%) translateY(-5px);
            background-color: #4338ca; /* indigo-600 */
            color: #eef2ff; /* indigo-50 */
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s, visibility 0.2s;
            pointer-events: none;
        }
        [x-tooltip]:hover::after {
            opacity: 1;
            visibility: visible;
            transform: translateX(-50%) translateY(-10px);
        }

    </style>
</head>
<body class="bg-gray-100 dark:bg-gray-900 text-gray-800 dark:text-gray-200" @toggle-dark-mode.window="isDarkMode = $event.detail.isDarkMode">

<?php require_once BASE_PATH . '/layout/headersidebar.php'; ?>

<main class="px-0 py-0 md:ml-0">
    <div class="max-w-full mx-auto py-0 px-2 sm:px-6 lg:px-2">

        <div class="bg-white dark:bg-gray-800 rounded shadow-md p-4 sm:p-6">
            <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4 mb-6">
                <div class="flex items-center gap-3">
                    <i class="fas fa-newspaper text-xl text-indigo-900 dark:text-indigo-400"></i>
                    <h1 class="text-xl font-bold">Manage Editions</h1>
                </div>
                <div class="w-full md:w-auto flex flex-col sm:flex-row items-center gap-2">
                    <!-- Filters Button -->
                    <button @click="filterModalOpen = true" class="h-9 w-full sm:w-auto inline-flex items-center justify-center px-4 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 font-semibold rounded hover:bg-gray-50 dark:hover:bg-gray-600 transition shadow-sm text-sm">
                        <i class="fas fa-filter mr-2"></i> Filters
                    </button>

                    <!-- Search Form -->
                    <form method="GET" class="flex-grow sm:flex-grow-0 w-full sm:w-auto">
                        <div class="flex items-center border border-gray-300 dark:border-gray-500 rounded overflow-hidden shadow-sm h-9">
                            <div class="relative flex-grow h-full">
                                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                <input type="text" name="search" placeholder="Search..." value="<?= htmlspecialchars($searchQuery) ?>" class="w-full h-full pl-10 pr-4 bg-gray-50 dark:bg-gray-700 focus:outline-none text-sm">
                            </div>
                            <button type="submit" class="h-full px-4 bg-indigo-900 text-white hover:bg-indigo-800 focus:outline-none">
                                <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </form>

                    <!-- Upload Button -->
                    <a href="/editions/upload-edition.php" class="h-9 w-full sm:w-auto inline-flex items-center justify-center px-4 bg-indigo-900 text-white font-semibold rounded hover:bg-indigo-800 transition shadow-sm text-sm">
                        <i class="fas fa-plus mr-2"></i> Upload New Edition
                    </a>
                </div>
            </div>

            <?php if (empty($editions)): ?>
                <div class="text-center py-12 px-6 bg-gray-50 dark:bg-gray-700/50 rounded">
                    <i class="fas fa-cloud-moon text-4xl text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-semibold text-gray-700 dark:text-gray-300">No Editions Found</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        <?php if(!empty($searchQuery) || $categoryFilter || !empty($statusFilter)): ?>
                            Your search or filter did not return any results.
                        <?php else: ?>
                            There are no editions to display. Why not upload one?
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6">
                    <?php foreach ($editions as $edition): ?>
                        <div class="edition-card bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded overflow-hidden flex flex-col h-[340px]">
                            <a href="/editions/view-edition.php?edition_id=<?= $edition['edition_id'] ?>" class="block h-1/2">
                                <img src="<?= htmlspecialchars($edition['list_thumb_path'] ?? 'https://placehold.co/600x400/e2e8f0/4a5568?text=No+Preview') ?>" alt="Cover of <?= htmlspecialchars($edition['title']) ?>" class="w-full h-full object-cover object-top">
                            </a>
                            <div class="p-3 flex flex-col flex-grow bg-indigo-100 dark:bg-indigo-900/20 h-1/2">
                                <h3 class="font-bold text-base mb-2 truncate" x-tooltip="<?= htmlspecialchars($edition['title']) ?>"><?= htmlspecialchars($edition['title']) ?></h3>
                                
                                <div class="grid grid-cols-2 gap-x-2 gap-y-1 text-xs text-gray-700 dark:text-gray-300">
                                    <div class="flex items-center bg-indigo-200 dark:bg-indigo-800/30 p-1 rounded">
                                        <i class="fas fa-calendar-alt w-4 text-center mr-1.5 text-indigo-600 dark:text-indigo-400"></i>
                                        <span class="flex-1"><?= date('d-m-y', strtotime($edition['publication_date'])) ?></span>
                                    </div>
                                    <div class="flex items-center bg-indigo-200 dark:bg-indigo-800/30 p-1 rounded">
                                        <i class="fas fa-folder w-4 text-center mr-1.5 text-indigo-600 dark:text-indigo-400"></i>
                                        <span class="flex-1 truncate" x-tooltip="<?= htmlspecialchars($edition['category_name'] ?? 'N/A') ?>"><?= htmlspecialchars($edition['category_name'] ?? 'N/A') ?></span>
                                    </div>
                                    <div class="flex items-center bg-indigo-200 dark:bg-indigo-800/30 p-1 rounded">
                                        <i class="fas fa-user-check w-4 text-center mr-1.5 text-indigo-600 dark:text-indigo-400"></i>
                                        <span class="flex-1 truncate" x-tooltip="<?= htmlspecialchars($edition['uploader_name'] ?? 'Admin') ?>"><?= htmlspecialchars($edition['uploader_name'] ?? 'Admin') ?></span>
                                    </div>
                                    <div class="flex items-center bg-indigo-200 dark:bg-indigo-800/30 p-1 rounded">
                                        <i class="fas w-4 text-center mr-1.5 <?= $edition['status'] === 'Published' ? 'fa-check-circle text-green-500' : 'fa-eye-slash text-yellow-500' ?>"></i>
                                        <span class="flex-1"><?= htmlspecialchars($edition['status']) ?></span>
                                    </div>
                                </div>

                                <div class="mt-auto flex items-center justify-start pt-2 border-t border-gray-300 dark:border-gray-700">
                                    <div class="flex items-center gap-2">
                                        <a href="/editions/view-edition.php?edition_id=<?= $edition['edition_id'] ?>" x-tooltip="View" class="w-8 h-8 flex items-center justify-center bg-blue-500 text-white rounded-full hover:bg-blue-600 transition-colors">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <button @click="copyToClipboard('<?= (defined('APP_SITE_URL') ? APP_SITE_URL : '') . '/editions/view-edition.php?edition_id=' . $edition['edition_id'] ?>')" x-tooltip="Copy Link" class="w-8 h-8 flex items-center justify-center bg-green-500 text-white rounded-full hover:bg-green-600 transition-colors">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                        <a href="/editions/edit-edition.php?id=<?= $edition['edition_id'] ?>" x-tooltip="Edit" class="w-8 h-8 flex items-center justify-center bg-orange-500 text-white rounded-full hover:bg-orange-600 transition-colors">
                                            <i class="fas fa-pencil-alt"></i>
                                        </a>
                                        <button @click="deleteModalOpen = true; editionToDelete = <?= htmlspecialchars(json_encode($edition), ENT_QUOTES) ?>" x-tooltip="Delete" class="w-8 h-8 flex items-center justify-center bg-red-500 text-white rounded-full hover:bg-red-600 transition-colors">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                         <button x-tooltip="Info" class="w-8 h-8 flex items-center justify-center bg-gray-500 text-white rounded-full hover:bg-gray-600 transition-colors">
                                            <i class="fas fa-info-circle"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <nav class="mt-8 flex justify-center" aria-label="Pagination">
                <?php
                    $queryParamsForPagination = [
                        'search' => $searchQuery,
                        'category' => $categoryFilter,
                        'status' => $statusFilter,
                    ];
                    $queryStringForPagination = http_build_query(array_filter($queryParamsForPagination));
                ?>
                <ul class="inline-flex items-center -space-x-px">
                    <li><a href="?page=<?= max(1, $currentPage-1) ?>&<?= $queryStringForPagination ?>" class="py-2 px-3 ml-0 leading-tight text-gray-500 bg-white rounded-l border border-gray-300 hover:bg-gray-100 hover:text-gray-700 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white">Previous</a></li>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li><a href="?page=<?= $i ?>&<?= $queryStringForPagination ?>" class="py-2 px-3 leading-tight <?= $i == $currentPage ? 'text-white bg-indigo-900 border-indigo-900' : 'text-gray-500 bg-white' ?> border border-gray-300 hover:bg-gray-100 hover:text-gray-700 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white"><?= $i ?></a></li>
                    <?php endfor; ?>
                    <li><a href="?page=<?= min($totalPages, $currentPage+1) ?>&<?= $queryStringForPagination ?>" class="py-2 px-3 leading-tight text-gray-500 bg-white rounded-r border border-gray-300 hover:bg-gray-100 hover:text-gray-700 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white">Next</a></li>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Delete Modal -->
<div x-show="deleteModalOpen" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-75 p-4">
    <div @click.away="deleteModalOpen = false; deleteConfirmationInput = ''" class="bg-white dark:bg-gray-800 rounded-lg shadow-xl p-6 w-full max-w-sm">
        <div class="text-center">
            <div class="w-12 h-12 rounded-full bg-red-100 dark:bg-red-900/50 flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-trash-alt text-red-600 dark:text-red-400 text-xl"></i>
            </div>
            <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Are you sure?</h3>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">You are about to delete the edition "<span class="font-semibold" x-text="editionToDelete ? editionToDelete.title : ''"></span>".<br>To confirm, type "<strong>delete</strong>" below:
            </p>
            <input type="text" x-model="deleteConfirmationInput" class="mt-4 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm bg-white dark:bg-gray-700 text-black dark:text-gray-200">
        </div>
        <div class="mt-6 grid grid-cols-2 gap-3">
            <button @click="deleteModalOpen = false; deleteConfirmationInput = ''" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-500 rounded-md hover:bg-gray-50 dark:hover:bg-gray-600 w-full">Cancel</button>
            <form method="POST" action="/editions/manage-editions.php" @submit.prevent="if(deleteConfirmationInput.toLowerCase() === 'delete') $el.submit()" class="w-full">
                <input type="hidden" name="action" value="delete_edition">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="edition_id" :value="editionToDelete ? editionToDelete.edition_id : ''">
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-md hover:bg-red-700 w-full" :disabled="deleteConfirmationInput.toLowerCase() !== 'delete'" :class="{'opacity-50 cursor-not-allowed': deleteConfirmationInput.toLowerCase() !== 'delete'}">Delete Edition</button>
            </form>
        </div>
    </div>
</div>


<!-- Filter Modal -->
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4" x-show="filterModalOpen" x-transition @click.away="filterModalOpen = false">
    <div class="bg-white dark:bg-gray-800 rounded shadow-xl p-6 w-full max-w-lg" @click.stop>
        <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100 mb-4">Filter Editions</h3>
        <form method="GET" class="space-y-4">
            <input type="hidden" name="search" value="<?= htmlspecialchars($searchQuery) ?>">
            
            <div>
                <label for="category_filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Category</label>
                <select id="category_filter" name="category" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white dark:bg-gray-700 dark:border-gray-600 rounded shadow-sm focus:outline-none focus:ring-indigo-900 focus:border-indigo-900 sm:text-sm">
                    <option value="">All Categories</option>
                    <?php foreach($categoriesForFilter as $category): ?>
                        <option value="<?= $category['category_id'] ?>" <?= ($categoryFilter == $category['category_id']) ? 'selected' : '' ?> >
                            <?= htmlspecialchars($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="status_filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                <select id="status_filter" name="status" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white dark:bg-gray-700 dark:border-gray-600 rounded shadow-sm focus:outline-none focus:ring-indigo-900 focus:border-indigo-900 sm:text-sm">
                    <option value="">All Statuses</option>
                    <option value="Published" <?= ($statusFilter == 'Published') ? 'selected' : '' ?>>Published</option>
                    <option value="Private" <?= ($statusFilter == 'Private') ? 'selected' : '' ?>>Private</option>
                </select>
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <a href="/editions/manage-editions.php" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-200 dark:hover:bg-gray-500">Reset</a>
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-indigo-900 rounded hover:bg-indigo-800">Apply Filters</button>
            </div>
        </form>
    </div>
</div>

<!-- Toast Notification -->
<div x-data="{
        show: false,
        message: '',
        type: 'info',
        showToast(detail) {
            this.message = detail.message;
            this.type = detail.type || 'info';
            this.show = true;
            setTimeout(() => this.show = false, 5000);
        }
    }"
    @show-toast.window="showToast($event.detail)"
    x-show="show"
    x-transition:enter="transform ease-out duration-300 transition"
    x-transition:enter-start="translate-y-2 opacity-0 sm:translate-y-0 sm:translate-x-2"
    x-transition:enter-end="translate-y-0 opacity-100 sm:translate-x-0"
    x-transition:leave="transition ease-in duration-100"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="fixed top-5 right-5 w-full max-w-xs z-50"
    x-cloak
>
    <div class="p-4 rounded-md shadow-lg border-l-4"
         :class="{
            'bg-green-100 dark:bg-green-900 border-green-400 text-green-700 dark:text-green-200': type === 'success',
            'bg-red-100 dark:bg-red-900 border-red-400 text-red-700 dark:text-red-200': type === 'error',
            'bg-blue-100 dark:bg-blue-900 border-blue-400 text-blue-700 dark:text-blue-200': type === 'info',
            'bg-yellow-100 dark:bg-yellow-900 border-yellow-400 text-yellow-700 dark:text-yellow-200': type === 'warning'
         }"
    >
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas" :class="{
                    'fa-check-circle text-green-500': type === 'success',
                    'fa-times-circle text-red-500': type === 'error',
                    'fa-info-circle text-blue-500': type === 'info',
                    'fa-exclamation-triangle text-yellow-500': type === 'warning'
                }"></i>
            </div>
            <div class="ml-3">
                <p class="text-sm font-medium" x-text="message"></p>
            </div>
            <div class="ml-auto pl-3">
                <div class="-mx-1.5 -my-1.5">
                    <button @click="show = false" type="button" class="inline-flex rounded-md p-1.5 focus:outline-none focus:ring-2 focus:ring-offset-2"
                            :class="{
                                'text-green-500 hover:bg-green-200 dark:hover:bg-green-800 focus:ring-offset-green-50 dark:focus:ring-offset-green-900 focus:ring-green-600': type === 'success',
                                'text-red-500 hover:bg-red-200 dark:hover:bg-red-800 focus:ring-offset-red-50 dark:focus:ring-offset-red-900 focus:ring-red-600': type === 'error',
                                'text-blue-500 hover:bg-blue-200 dark:hover:bg-blue-800 focus:ring-offset-blue-50 dark:focus:ring-offset-blue-900 focus:ring-blue-600': type === 'info',
                                'text-yellow-500 hover:bg-yellow-200 dark:hover:bg-yellow-800 focus:ring-offset-yellow-50 dark:focus:ring-offset-yellow-900 focus:ring-yellow-600': type === 'warning'
                            }">
                        <span class="sr-only">Dismiss</span>
                        <i class="fas fa-times h-4 w-4"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
if (isset($_SESSION['toast_message']) && isset($_SESSION['toast_type'])) {
    echo "<script>
        document.addEventListener('alpine:init', () => {
            window.dispatchEvent(new CustomEvent('show-toast', {
                detail: {
                    message: '" . addslashes($_SESSION['toast_message']) . "',
                    type: '" . addslashes($_SESSION['toast_type']) . "'
                }
            }));
        });
    </script>";
    unset($_SESSION['toast_message']);
    unset($_SESSION['toast_type']);
}
?>

</body>
</html>

