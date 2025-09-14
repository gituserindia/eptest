<?php
// File: categories-editions.php
// Publicly lists editions, filtered by a selected category.
// Includes a category dropdown for easy navigation.

// --- HTTP Security Headers (Place this at the very top before any output) ---
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// No session start or authentication required for public access

// Corrected path for config.php:
// Assuming config.php is in a 'config' directory one level up from the current script.
require_once __DIR__ . '/../config/config.php';

// Set default timezone to Asia/Kolkata (IST)
date_default_timezone_set('Asia/Kolkata');

$pageTitle = "Editions by Category"; // This variable will be used in header.php

// --- Filtering Parameters ---
// Get the selected category ID from the URL, default to 0 (All Categories) if not provided or invalid
$selectedCategory = isset($_GET['category']) ? (int)$_GET['category'] : 0;

// Get the date from the URL (optional for this page, but good for consistency or future features)
$selectedDate = trim($_GET['date'] ?? ''); // No default date, as category is primary filter

// --- Fetch Categories for Dropdown (Only categories with published editions) ---
$categories = [];
try {
    // Select categories that have at least one published edition
    $categorySql = "
        SELECT DISTINCT c.category_id, c.name
        FROM categories c
        JOIN editions e ON c.category_id = e.category_id
        WHERE e.status = 'published'
        ORDER BY c.name ASC
    ";
    $categoryStmt = $pdo->prepare($categorySql);
    $categoryStmt->execute();
    $categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching categories with published editions: " . $e->getMessage());
    $categories = []; // Fallback to empty array
}

// Check if the selected category is valid, if not, reset to 0
// This part is crucial to ensure if a user manually types an invalid category, it defaults to All.
$isValidCategory = false;
foreach ($categories as $cat) {
    if ($cat['category_id'] == $selectedCategory) {
        $isValidCategory = true;
        break;
    }
}
// Also consider '0' (All Categories) as a valid selection implicitly.
if ($selectedCategory > 0 && !$isValidCategory) {
    $selectedCategory = 0; // Reset to "All Categories" if invalid category ID is provided or no editions in that category
}

// Get the name of the selected category for display
$selectedCategoryName = "All Categories";
if ($selectedCategory > 0) {
    foreach ($categories as $cat) {
        if ($cat['category_id'] == $selectedCategory) {
            $selectedCategoryName = $cat['name'];
            break;
        }
    }
}


// --- Pagination Parameters ---
$itemsPerPage = 12; // Example: 12 editions per page
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) {
    $currentPage = 1;
}
$offset = ($currentPage - 1) * $itemsPerPage;


// --- Build WHERE clause for fetching editions by category and status ---
$whereClauses = [
    "e.status = 'published'" // Only show published editions to public
];
$queryParams = [];

if ($selectedCategory > 0) {
    $whereClauses[] = "e.category_id = :selected_category";
    $queryParams[':selected_category'] = $selectedCategory;
}
// Optionally, if you also want to filter by date from URL, uncomment and adjust:
// if (!empty($selectedDate) && DateTime::createFromFormat('Y-m-d', $selectedDate)) {
//     $whereClauses[] = "e.publication_date = :selected_date";
//     $queryParams[':selected_date'] = $selectedDate;
// }

$whereSql = " WHERE " . implode(" AND ", $whereClauses);

// --- Get Total Number of Editions for Pagination (with filters) ---
$countSql = "SELECT COUNT(*) FROM editions e LEFT JOIN categories c ON e.category_id = c.category_id " . $whereSql;
try {
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($queryParams);
    $totalEditions = $countStmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Database error fetching total public edition count (by category): " . $e->getMessage());
    $totalEditions = 0;
}

$totalPages = ceil($totalEditions / $itemsPerPage);
if ($totalPages > 0 && $currentPage > $totalPages) {
    $currentPage = $totalPages;
    $offset = ($currentPage - 1) * $itemsPerPage;
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
        e.list_thumb_path,   /* Using list_thumb_path for display */
        c.name AS category_name
    FROM
        editions e
    LEFT JOIN
        categories c ON e.category_id = c.category_id
    {$whereSql}
    ORDER BY e.publication_date DESC, e.title ASC /* Order by date and then title */
    LIMIT :limit OFFSET :offset
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($queryParams as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $editions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching public editions by category: " . $e->getMessage());
    $editions = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .edition-card {
            background-color: #fff;
            border-radius: 0.75rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1), 0 1px 3px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            border: 1px solid #e5e7eb;
        }
        .edition-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.15), 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .edition-card img {
            width: 100%;
            height: 250px;
            object-fit: cover;
            object-position: top; /* Show from the beginning of the image */
            border-bottom: 1px solid #f3f4f6;
        }
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
        /* Style for date input if ever needed */
        input[type="date"] {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-color: #ffffff;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            padding: 0.625rem 1rem;
            font-size: 1rem;
            line-height: 1.5;
            color: #1f2937;
            cursor: pointer;
            outline: none;
            transition: border-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }
        input[type="date"]:hover {
            border-color: #9ca3af;
        }
        input[type="date"]:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }
        input[type="date"]::-webkit-calendar-picker-indicator {
            background: transparent;
            bottom: 0;
            color: transparent;
            cursor: pointer;
            height: auto;
            left: 0;
            position: absolute;
            right: 0;
            top: 0;
            width: auto;
        }
        input[type="date"]::-webkit-inner-spin-button,
        input[type="date"]::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">

<?php
// Assuming header.php is in the 'layout' directory one level up from 'editions'
require_once __DIR__ . '/../layout/header.php';
?>

<main class="py-6 px-4">
    <div class="px-4 py-6 sm:px-0">
        <div class="bg-white rounded-xl shadow-md overflow-hidden p-6 mb-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">
                Editions in: <?= htmlspecialchars($selectedCategoryName) ?>
                <?php if (!empty($selectedDate)): ?>
                    <span class="text-gray-500 text-base font-normal">(on <?= htmlspecialchars(date('d F Y', strtotime($selectedDate))) ?>)</span>
                <?php endif; ?>
            </h2>

            <div class="flex flex-col sm:flex-row items-center gap-4 mb-6">
                <label for="category-select" class="text-lg font-medium text-gray-700">Filter by Category:</label>
                <div class="relative w-full sm:w-auto">
                    <select id="category-select"
                            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2">
                        <option value="0">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= htmlspecialchars($category['category_id']) ?>"
                                <?= ($category['category_id'] == $selectedCategory) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (empty($_GET['category'])): // Only show date picker if no category is in the URL, or if you want it always visible ?>
                <label for="date-picker" class="text-lg font-medium text-gray-700 sm:ml-4">Select Date (Optional):</label>
                <div class="relative w-full sm:w-auto">
                    <input type="date" id="date-picker"
                           value="<?= htmlspecialchars($selectedDate) ?>"
                           max="<?= date('Y-m-d') ?>"
                           class="pr-10">
                    <i class="fas fa-calendar-alt absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"></i>
                </div>
                <?php endif; ?>
            </div>

            <?php if (empty($editions)): ?>
                <div class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-lg text-center">
                    <i class="fas fa-info-circle mr-2"></i> No published editions found for this category
                    <?php if (!empty($selectedDate)): ?>
                        on <?= htmlspecialchars(date('d F Y', strtotime($selectedDate))) ?>
                    <?php endif; ?>.
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-6">
                    <?php foreach ($editions as $edition):
                        $image_path_to_display = !empty($edition['list_thumb_path']) ? $edition['list_thumb_path'] : '';
                        $fallback_image = 'https://placehold.co/630x1200/E5E7EB/6B7280?text=No+Preview';
                    ?>
                        <div class="edition-card">
                            <a href="view-edition.php?date=<?= htmlspecialchars($edition['publication_date']) ?>&edition_id=<?= htmlspecialchars($edition['edition_id']) ?>"
                               title="View: <?= htmlspecialchars($edition['title']) ?>">
                                <img src="<?= htmlspecialchars($image_path_to_display) ?>"
                                     alt="Preview of <?= htmlspecialchars($edition['title']) ?>"
                                     onerror="this.onerror=null; this.src='<?= htmlspecialchars($fallback_image) ?>';"
                                     loading="lazy">
                            </a>
                            <div class="edition-card-content">
                                <h3 class="edition-card-title"><?= htmlspecialchars($edition['title']) ?></h3>
                                <p class="edition-card-meta">
                                    Published: <?= date('d M Y', strtotime($edition['publication_date'])) ?>
                                </p>
                                <p class="edition-card-meta">
                                    Category: <?= htmlspecialchars($edition['category_name'] ?? 'N/A') ?>
                                </p>
                                <div class="mt-4">
                                    <a href="view-edition.php?date=<?= htmlspecialchars($edition['publication_date']) ?>&edition_id=<?= htmlspecialchars($edition['edition_id']) ?>"
                                       class="inline-flex items-center justify-center w-full px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200">
                                        <i class="fas fa-book-open mr-2"></i> Read Edition
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav class="flex justify-center items-center gap-2 mt-8" aria-label="Pagination">
                        <?php
                        $currentScript = basename($_SERVER['PHP_SELF']);
                        $baseUrlParams = [
                            'category' => $selectedCategory
                        ];
                        if (!empty($selectedDate)) { // Include date in pagination if it was selected
                            $baseUrlParams['date'] = $selectedDate;
                        }
                        $baseUrl = $currentScript . '?' . http_build_query($baseUrlParams);
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

<script>
    const categorySelect = document.getElementById('category-select');
    const datePicker = document.getElementById('date-picker');

    function updateUrl() {
        const newCategory = categorySelect.value;
        let url = `<?= basename($_SERVER['PHP_SELF']) ?>?category=${newCategory}`;

        // If datePicker exists and has a value, include it
        if (datePicker && datePicker.value) {
            url += `&date=${datePicker.value}`;
        }
        window.location.href = url;
    }

    if (categorySelect) {
        categorySelect.addEventListener('change', updateUrl);
    }
    if (datePicker) {
        datePicker.addEventListener('change', updateUrl);
    }
</script>

</body>
</html>