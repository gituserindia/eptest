<?php
// File: date-editions.php
// Publicly lists editions, filtered by a selected publication date.
// Includes a date picker for easy navigation.

// --- HTTP Security Headers (Place this at the very top before any output) ---
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// No session start or authentication required for public access

// Corrected path for config.php:
// Assuming config.php is in a 'config' directory one level up from the current script.
// For example, if date-editions.php is in /public_html/editions/, and config.php is in /public_html/config/
require_once __DIR__ . '/../config/config.php';

// Set default timezone to Asia/Kolkata (IST)
date_default_timezone_set('Asia/Kolkata');

$pageTitle = "Editions by Date"; // This variable will be used in header.php

// --- Filtering Parameters ---
// Get the date from the URL, default to today's date if not provided or invalid
$selectedDate = trim($_GET['date'] ?? date('Y-m-d')); // Default to today's date (YYYY-MM-DD)

// Validate date format (YYYY-MM-DD)
$dateObj = DateTime::createFromFormat('Y-m-d', $selectedDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $selectedDate) {
    $selectedDate = date('Y-m-d'); // Fallback to today if invalid date format
}

// --- Pagination Parameters (Optional for initial implementation, but good practice) ---
// For simplicity, we'll fetch all editions for the selected date for now.
// If performance is an issue with many editions per day, pagination can be added here.
$itemsPerPage = 12; // Example: 12 editions per page
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) {
    $currentPage = 1;
}
$offset = ($currentPage - 1) * $itemsPerPage;


// --- Build WHERE clause for fetching editions by date and status ---
$whereClauses = [
    "e.publication_date = :selected_date",
    "e.status = 'published'" // Only show published editions to public
];
$queryParams = [
    ':selected_date' => $selectedDate
];

$whereSql = " WHERE " . implode(" AND ", $whereClauses);

// --- Get Total Number of Editions for Pagination (with filters) ---
$countSql = "SELECT COUNT(*) FROM editions e LEFT JOIN categories c ON e.category_id = c.category_id " . $whereSql;
try {
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($queryParams);
    $totalEditions = $countStmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Database error fetching total public edition count: " . $e->getMessage());
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
    error_log("Database error fetching public editions by date: " . $e->getMessage());
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
        /* Style for date input */
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
        /* For the calendar icon */
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
// Assuming headersidebar.php is in the 'layout' directory one level up from 'editions'
require_once __DIR__ . '/../layout/header.php';
?>

<main class="py-6 px-4"> <!-- Removed max-w-7xl mx-auto to make it wider -->
    <div class="px-4 py-6 sm:px-0">
        <div class="bg-white rounded-xl shadow-md overflow-hidden p-6 mb-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">
                Editions for: <?= htmlspecialchars(date('d F Y', strtotime($selectedDate))) ?>
            </h2>

            <?php if (empty($_GET['date'])): // Show date picker only if no date is in the URL ?>
            <div class="flex flex-col sm:flex-row items-center gap-4 mb-6">
                <label for="date-picker" class="text-lg font-medium text-gray-700">Select Date:</label>
                <div class="relative w-full sm:w-auto">
                    <input type="date" id="date-picker"
                           value="<?= htmlspecialchars($selectedDate) ?>"
                           max="<?= date('Y-m-d') ?>"
                           class="pr-10">
                    <i class="fas fa-calendar-alt absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"></i>
                </div>
            </div>
            <?php endif; ?>

            <?php if (empty($editions)): ?>
                <div class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-lg text-center">
                    <i class="fas fa-info-circle mr-2"></i> No published editions found for this date.
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
                    <!-- Pagination Controls -->
                    <nav class="flex justify-center items-center gap-2 mt-8" aria-label="Pagination">
                        <?php
                        $currentScript = basename($_SERVER['PHP_SELF']);
                        $baseUrlParams = [
                            'date' => $selectedDate
                        ];
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
    // Get the date picker input element
    const datePicker = document.getElementById('date-picker');

    // Add an event listener for when the date value changes
    // This script will only run if the datePicker element exists (i.e., if it's rendered)
    if (datePicker) {
        datePicker.addEventListener('change', function() {
            // Construct the new URL with the selected date
            const newDate = this.value;
            if (newDate) {
                window.location.href = `<?= basename($_SERVER['PHP_SELF']) ?>?date=${newDate}`;
            }
        });
    }
</script>

</body>
</html>
