<?php
// index.php
// This is the main page to view the latest uploaded edition or select one by date.

// --- HTTP Security Headers ---
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode:block");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Start session at the very beginning
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Include centralized settings and theme variables ---
// IMPORTANT: Define BASE_PATH relative to your project root, assuming index.php is in a public_html type folder.
// If index.php is in /home/srv882446.hstgr.cloud/public_html/
// Then __DIR__ will correctly point to /home/srv882446.hstgr.cloud/public_html/
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__ . '/');
}

// Include database configuration
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/vars/settingsvars.php';
require_once BASE_PATH . '/vars/themevars.php';
require_once BASE_PATH . '/vars/logovars.php';
require_once BASE_PATH . '/vars/socialmediavars.php';

// Set default timezone from settingsvars.php or fallback
date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Kolkata');

// --- Define Logged-in State and User Role (if applicable, though this is a public view) --
$loggedIn = isset($_SESSION['user_id']);
$username = $loggedIn ? htmlspecialchars($_SESSION['username']) : null;
$userRole = $loggedIn ? ($_SESSION['user_role'] ?? 'Viewer') : null; // Default to Viewer
// FIX: Define $currentUserId from session if logged in, otherwise null
$currentUserId = $loggedIn ? ($_SESSION['user_id'] ?? null) : null;

// --- Handle Date/Edition Selection ---
$requestedDate = $_GET['date'] ?? null; // Null if no date is explicitly requested on initial load
$requestedEditionId = $_GET['edition_id'] ?? null; // New: Get specific edition ID if requested
$selectedDate = date('Y-m-d'); // Default date picker value, will be updated by found edition's date

$edition = null;
$editionImages = [];
$editionTitle = defined('APP_TITLE') ? APP_TITLE : "Latest Edition"; // Use APP_TITLE from settingsvars.php
$notificationMessage = '';
$rawEditionTitle = ''; // New variable to store the raw edition title

try {
    // NEW LOGIC: Prioritize specific edition_id if provided
    if ($requestedEditionId !== null) {
        $stmtEditionId = $pdo->prepare("SELECT edition_id, title, publication_date, pdf_path, og_image_path FROM editions WHERE edition_id = :edition_id AND status = 'published' LIMIT 1");
        $stmtEditionId->execute([':edition_id' => $requestedEditionId]);
        $edition = $stmtEditionId->fetch(PDO::FETCH_ASSOC);

        if ($edition) {
            $selectedDate = $edition['publication_date'];
            $formattedDate = date('d-m-Y', strtotime($selectedDate));
            $editionTitle = htmlspecialchars($edition['title']) . " (" . htmlspecialchars($formattedDate) . ")";
            $rawEditionTitle = htmlspecialchars($edition['title']); // Store raw title
            error_log("DEBUG: Found edition by ID: " . $requestedEditionId . ", Title: " . $edition['title'] . ", PDF Path: " . $edition['pdf_path']);
        } else {
            $notificationMessage = "Edition with ID " . htmlspecialchars($requestedEditionId) . " not found or not published. ";
            error_log("DEBUG: Edition with ID " . $requestedEditionId . " not found or not published. Falling back.");
            // Fallback to default logic if specific edition not found
            $requestedEditionId = null; // Clear to trigger standard date/latest logic
        }
    }

    // Existing logic for date or initial page load (if no edition_id was specified or found)
    if ($edition === null) { // Only proceed if no edition was found by ID
        if ($requestedDate === null) {
            // --- NEW LOGIC FOR INITIAL PAGE LOAD (NO DATE SELECTED) ---
            // First, try to find an edition for the current calendar date
            $today = date('Y-m-d');
            $stmtToday = $pdo->prepare("SELECT edition_id, title, publication_date, pdf_path, og_image_path FROM editions WHERE publication_date = :today AND status = 'published' ORDER BY created_at DESC LIMIT 1");
            $stmtToday->execute([':today' => $today]);
            $editionToday = $stmtToday->fetch(PDO::FETCH_ASSOC);

            if ($editionToday) {
                // Found an edition for today's date, display it.
                $edition = $editionToday;
                $selectedDate = $edition['publication_date'];
                $formattedDate = date('d-m-Y', strtotime($selectedDate));
                $editionTitle = htmlspecialchars($edition['title']) . " (" . htmlspecialchars($formattedDate) . ")";
                $rawEditionTitle = htmlspecialchars($edition['title']); // Store raw title
                error_log("DEBUG: Found edition for today: " . $edition['title'] . ", PDF Path: " . $edition['pdf_path']);
            } else {
                // No edition found for today's date, fall back to the overall latest published edition.
                $stmtLatest = $pdo->prepare("SELECT edition_id, title, publication_date, pdf_path, og_image_path FROM editions WHERE status = 'published' ORDER BY publication_date DESC, created_at DESC LIMIT 1");
                $stmtLatest->execute();
                $edition = $stmtLatest->fetch(PDO::FETCH_ASSOC);

                if ($edition) {
                    $selectedDate = $edition['publication_date'];
                    $formattedDate = date('d-m-Y', strtotime($selectedDate));
                    $editionTitle = htmlspecialchars($edition['title']) . " (" . htmlspecialchars($formattedDate) . ")";
                    $rawEditionTitle = htmlspecialchars($edition['title']); // Store raw title
                    $notificationMessage .= "No edition found for today (" . htmlspecialchars(date('d-m-Y')) . "). Displaying the latest available edition (" . htmlspecialchars($formattedDate) . ").";
                    error_log("DEBUG: No edition for today. Displaying latest: " . $edition['title'] . ", PDF Path: " . $edition['pdf_path']);
                } else {
                    $editionTitle = "No Editions Available";
                    $notificationMessage .= "No editions available in the database.";
                    $rawEditionTitle = "No Edition"; // Default for raw title
                    error_log("DEBUG: No editions available in the database at all.");
                }
            }
        } else {
            // --- EXISTING LOGIC FOR DATE SELECTED VIA DATE PICKER ---
            // A date was explicitly requested (e.g., from date picker or direct URL with ?date=)
            error_log("DEBUG: Requested date: " . $requestedDate);

            // Step 1: Count how many published editions exist for the requested date
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM editions WHERE publication_date = :publication_date AND status = 'published'");
            $countStmt->execute([':publication_date' => $requestedDate]);
            $editionCount = $countStmt->fetchColumn();
            error_log("DEBUG: Edition count for " . $requestedDate . ": " . $editionCount);

            if ($editionCount === 1) {
                // Step 2: Exactly one edition found for the requested date, display it directly
                $stmt = $pdo->prepare("SELECT edition_id, title, publication_date, pdf_path, og_image_path FROM editions WHERE publication_date = :publication_date AND status = 'published' LIMIT 1");
                $stmt->execute([':publication_date' => $requestedDate]);
                $edition = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($edition) {
                    $selectedDate = $edition['publication_date']; // Confirm the selected date is the one found
                    // Format date to DD-MM-YYYY for desktop view
                    $formattedDate = date('d-m-Y', strtotime($selectedDate));
                    $editionTitle = htmlspecialchars($edition['title']) . " (for " . htmlspecialchars($formattedDate) . ")";
                    $rawEditionTitle = htmlspecialchars($edition['title']); // Store raw title
                    error_log("DEBUG: Found single edition for requested date: " . $edition['title'] . ", PDF Path: " . $edition['pdf_path']);
                } else {
                    // This case should ideally not happen if $editionCount was 1, but for robustness:
                    // Fallback to latest if for some reason the single fetch fails
                    $stmt = $pdo->prepare("SELECT edition_id, title, publication_date, pdf_path, og_image_path FROM editions WHERE status = 'published' ORDER BY publication_date DESC, created_at DESC LIMIT 1");
                    $stmt->execute();
                    $edition = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($edition) {
                        $selectedDate = $edition['publication_date'];
                        $formattedDate = date('d-m-Y', strtotime($selectedDate));
                        $editionTitle = htmlspecialchars($edition['title']) . " (" . htmlspecialchars($formattedDate) . ")";
                        $rawEditionTitle = htmlspecialchars($edition['title']); // Store raw title
                        $notificationMessage .= "No edition found for " . htmlspecialchars($requestedDate) . ". Displaying the latest edition (" . htmlspecialchars($formattedDate) . ").";
                        error_log("DEBUG: Single edition fetch failed. Displaying latest: " . $edition['title'] . ", PDF Path: " . $edition['pdf_path']);
                    } else {
                        $editionTitle = "No Editions Available";
                        $notificationMessage .= "No editions available in the database.";
                        $rawEditionTitle = "No Edition"; // Default for raw title
                        error_log("DEBUG: No editions available in database after requested date search failed.");
                    }
                }
            } elseif ($editionCount > 1) {
                // Step 3: More than one edition found, redirect to date-editions.php
                error_log("DEBUG: Multiple editions found for " . $requestedDate . ". Redirecting.");
                header("Location: /editions/date-editions.php?date=" . urlencode($requestedDate));
                exit(); // Important to stop script execution after redirection
            } else {
                // Step 4: No editions for the requested date, fall back to the very latest published edition
                error_log("DEBUG: No editions found for " . $requestedDate . ". Falling back to latest.");
                $stmt = $pdo->prepare("SELECT edition_id, title, publication_date, pdf_path, og_image_path FROM editions WHERE status = 'published' ORDER BY publication_date DESC, created_at DESC LIMIT 1");
                $stmt->execute();
                $edition = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($edition) {
                    $selectedDate = $edition['publication_date'];
                    $formattedDate = date('d-m-Y', strtotime($selectedDate));
                    $editionTitle = htmlspecialchars($edition['title']) . " (" . htmlspecialchars($formattedDate) . ")";
                    $rawEditionTitle = htmlspecialchars($edition['title']); // Store raw title
                    $notificationMessage .= "No edition found for " . htmlspecialchars($requestedDate) . ". Displaying the latest edition (" . htmlspecialchars($formattedDate) . ").";
                    error_log("DEBUG: Displaying latest edition after no match for requested date: " . $edition['title'] . ", PDF Path: " . $edition['pdf_path']);
                } else {
                    $editionTitle = "No Editions Available";
                    $notificationMessage .= "No editions available in the database.";
                    $rawEditionTitle = "No Edition"; // Default for raw title
                    error_log("DEBUG: No editions available in database after requested date search and latest fallback failed.");
                }
            }
        }
    }

    // --- Image Path Generation and Validation ---
    if ($edition && !empty($edition['pdf_path'])) {
        $db_pdf_path = $edition['pdf_path']; // e.g., "/../uploads/editions/YYYY/MM/DD/UNIQUE_ID/edition-DATE.pdf"

        // Clean the path from the DB for filesystem use.
        // This will remove leading /../ or ../ if present.
        // Result: "uploads/editions/YYYY/MM/DD/UNIQUE_ID/edition-DATE.pdf"
        $cleaned_relative_path_from_db = preg_replace('/^\/?\.\.\//', '', $db_pdf_path);

        // Get the directory containing the PDF (and thus the images) relative to web root (public_html)
        // Result: "uploads/editions/YYYY/MM/DD/UNIQUE_ID"
        $edition_base_dir_relative_to_web_root = dirname($cleaned_relative_path_from_db);

        // Construct the absolute path to the images directory on the server's filesystem
        // BASE_PATH is /home/srv882446.hstgr.cloud/public_html/
        // Result: /home/srv882446.hstgr.cloud/public_html/uploads/editions/YYYY/MM/DD/UNIQUE_ID/images/
        $images_dir_absolute = BASE_PATH . $edition_base_dir_relative_to_web_root . '/images/';

        // The web-accessible path for <img src> attributes
        // Result: /uploads/editions/YYYY/MM/DD/UNIQUE_ID/images/
        $images_dir_web_path_base = '/' . $edition_base_dir_relative_to_web_root . '/images/';


        error_log("DEBUG: Original PDF path from DB: " . $db_pdf_path);
        error_log("DEBUG: Cleaned relative path from DB (for filesystem): " . $cleaned_relative_path_from_db);
        error_log("DEBUG: Edition base dir relative to web root: " . $edition_base_dir_relative_to_web_root);
        error_log("DEBUG: BASE_PATH (public_html absolute): " . BASE_PATH);
        error_log("DEBUG: Calculated images_dir_absolute (filesystem path): " . $images_dir_absolute);
        error_log("DEBUG: Calculated images_dir_web_path_base (for img src): " . $images_dir_web_path_base);


        if (is_dir($images_dir_absolute)) {
            error_log("DEBUG: Image directory exists: " . $images_dir_absolute);
            // Updated glob pattern to match 'page-1.jpg' format
            $imageFiles = glob($images_dir_absolute . 'page-*.jpg');
            error_log("DEBUG: Found " . count($imageFiles) . " image files using glob in " . $images_dir_absolute);
            if (empty($imageFiles)) {
                error_log("DEBUG: No image files found in " . $images_dir_absolute . " with pattern 'page-*.jpg'");
            }

            // Sort images numerically by page number
            usort($imageFiles, function($a, $b) {
                preg_match('/page-(\d+)\.jpg$/', $a, $matchesA);
                preg_match('/page-(\d+)\.jpg$/', $b, $matchesB);

                // Safely get page numbers, defaulting to 0 or max value if not found
                $pageA = isset($matchesA[1]) ? (int)$matchesA[1] : 0;
                $pageB = isset($matchesB[1]) ? (int)$matchesB[1] : 0;

                return $pageA - $pageB;
            });

            foreach ($imageFiles as $file) {
                // Convert absolute file system path to web-accessible path
                // $file is like /home/srv882446.hstgr.cloud/public_html/uploads/.../page-X.jpg
                // We want /uploads/.../page-X.jpg
                $image_web_path = str_replace(BASE_PATH, '/', $file);
                $editionImages[] = $image_web_path;
            }
            error_log("DEBUG: Final editionImages array (relative web paths for src): " . json_encode($editionImages));
        } else {
            error_log("ERROR: Image directory DOES NOT exist: " . $images_dir_absolute);
            $notificationMessage .= "Image directory for this edition not found.";
        }
    } else {
        error_log("DEBUG: No edition found or pdf_path is empty. Cannot load images.");
        if ($edition) {
             $notificationMessage .= "PDF path for this edition is missing.";
        }
    }

} catch (PDOException $e) {
    error_log("Database error fetching edition for index page: " . $e->getMessage());
    // In a public facing page, avoid exposing detailed error messages
    $editionTitle = "Error loading editions. Please try again later.";
    $edition = null; // Ensure $edition is null if there's a DB error
    $notificationMessage = "A database error occurred while loading editions. Please try again later.";
    $rawEditionTitle = "Error"; // Default for raw title in case of error
}

// The image path for the logo from logovars.php
$logoPath = defined('APP_LOGO_PATH') ? APP_LOGO_PATH : 'uploads/assets/logo.jpg';
$pageTitle = defined('APP_TITLE') ? htmlspecialchars(APP_TITLE) : 'Ushodayam E-Paper'; // Use APP_TITLE for base page title
$pageTitle = " " . $editionTitle; // Override with edition title if available

// Path to the page turn sound effect
$pageTurnSoundPath = '/uploads/sounds/pageturn.wav'; // Assuming this path is relative to your web root

// --- Open Graph and SEO Meta Tag Generation ---
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];

// Use APP_SITE_URL if defined, otherwise construct from server variables
$baseSiteUrl = defined('APP_SITE_URL') ? APP_SITE_URL : "{$protocol}://{$host}";
$current_page_url = "{$baseSiteUrl}/index.php"; // Default base URL for og:url


$ogImageUrl = defined('OG_IMAGE_PATH') ? "{$baseSiteUrl}" . htmlspecialchars(OG_IMAGE_PATH) : '';
$ogDescription = defined('APP_TITLE') ? "Read the latest edition of " . htmlspecialchars(APP_TITLE) . " online. Stay updated with daily news and articles." : "Read the latest edition of E-Paper online. Stay updated with daily news and articles.";


if ($edition) {
    // If an edition is found, make the og:url specific to that edition's date
    $current_page_url = "{$baseSiteUrl}/index.php?date=" . htmlspecialchars($edition['publication_date']);
    // If an edition_id was used, make the OG URL specific to that ID
    if (!empty($requestedEditionId)) {
        $current_page_url .= "&edition_id=" . htmlspecialchars($edition['edition_id']);
    }

    // Use the og_image_path from the database if available
    if (!empty($edition['og_image_path'])) {
        $ogImageUrl = "{$baseSiteUrl}/{$edition['og_image_path']}";
    } else if (!empty($editionImages) && file_exists(__DIR__ . '/' . $editionImages[0])) {
        // Fallback to the first available page image if specific OG thumbnail not found
        // This is primarily for backward compatibility or if og_image_path wasn't generated
        $ogImageUrl = "{$baseSiteUrl}/{$editionImages[0]}";
    }

    // Update description to be more specific to the edition if possible, or keep generic
    if (!empty($edition['description'])) {
        $ogDescription = htmlspecialchars($edition['description']);
    } else {
        $ogDescription = "Read the " . htmlspecialchars($edition['title']) . " edition of " . (defined('APP_TITLE') ? htmlspecialchars(APP_TITLE) : 'E-Paper') . ", published on " . htmlspecialchars(date('d-m-Y', strtotime($edition['publication_date']))) . ".";
    }
}


$seoKeywords = defined('APP_TITLE') ? htmlspecialchars(APP_TITLE) . ", E-Paper, Newspaper, Online News, Daily Edition" : "E-Paper, Newspaper, Online News, Daily Edition";
$seoAuthor = defined('APP_EDITOR_NAME') ? htmlspecialchars(APP_EDITOR_NAME) : "E-Paper Publications";
?>
<!DOCTYPE html>
<html lang="en" x-data="{ sidebarOpen: false, profileMenuOpen: false, moreMenuOpen: false, scrolled: false }" x-cloak>
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo htmlspecialchars($pageTitle ?? (defined('APP_TITLE') ? APP_TITLE : 'Ushodayam E-Paper')); ?></title>

    <!-- SEO Meta Tags for Google and Bing -->
    <meta name="description" content="<?= htmlspecialchars($ogDescription) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($seoKeywords) ?>">
    <meta name="author" content="<?= htmlspecialchars($seoAuthor) ?>">

    <!-- Open Graph Meta Tags for Social Media Sharing (WhatsApp, Facebook) -->
    <meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($ogDescription) ?>">
    <?php if (!empty($ogImageUrl)): ?>
    <meta property="og:image" content="<?= htmlspecialchars($ogImageUrl) ?>">
    <?php endif; ?>
    <meta property="og:url" content="<?= htmlspecialchars($current_page_url) ?>">
    <meta property="og:type" content="article">

    <!-- Twitter Card Meta Tags -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="<?= defined('APP_TWITTER_URL') ? htmlspecialchars(APP_TWITTER_URL) : '@YourTwitterHandle' ?>">
    <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($ogDescription) ?>">
    <?php if (!empty($ogImageUrl)): ?>
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImageUrl) ?>">
    <?php endif; ?>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- Cropper.js CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css" rel="stylesheet">
    <style>
        /* Custom CSS variables for theme colors and border-radius, easily integrated with Tailwind */
        :root {
            /* Fallback values if themevars.php is not loaded or values are missing */
            --color-main-color: <?= $themeColors['main_color'] ?? '#3B82F6' ?>;
            --color-main-text-color: <?= $themeColors['main_text_color'] ?? '#FFFFFF' ?>;
            --color-hover-color: <?= $themeColors['hover_color'] ?? '#2563EB' ?>;
            --color-hover-text-color: <?= $themeColors['hover_text_color'] ?? '#FFFFFF' ?>;
            --color-light-gray-border: <?= $themeColors['light_gray_border'] ?? '#E5E7EB' ?>;
            --color-bg-gray-100: <?= $themeColors['bg_gray_100'] ?? '#F3F4F6' ?>;
            --color-bg-black-opacity-50: <?= $themeColors['bg_black_opacity_50'] ?? 'rgba(0, 0, 0, 0.5)' ?>;
            --color-green-100: <?= $themeColors['green_100'] ?? '#D1FAE5' ?>;
            --color-green-600: <?= $themeColors['green_600'] ?? '#059669' ?>;
            --color-red-100: <?= $themeColors['red_100'] ?? '#FEE2E2' ?>;
            --color-red-600: <?= $themeColors['red_600'] ?? '#DC2626' ?>;
            --color-gray-600: <?= $themeColors['gray_600'] ?? '#4B5563' ?>;
            --color-gray-text: <?= $themeColors['gray_text'] ?? '#374151' ?>; /* Added this from themevars.php */
            --primary-color: <?= $themeColors['blue_600'] ?? '#3b82f6' ?>; /* Using blue_600 as primary-color fallback */
            --hover-bg-color: <?= $themeColors['hover_color'] ?? '#2563eb' ?>; /* Using hover_color from themevars */
            --hover-text-color: <?= $themeColors['hover_text_color'] ?? '#ffffff' ?>; /* Using hover_text_color from themevars */
            --text-main-color: <?= $themeColors['gray_text'] ?? '#374151' ?>; /* Using gray_text from themevars */

            --border-radius: 20px;
        }
        /* Alpine.js cloak directive to prevent FOUC (Flash Of Unstyled Content) */
        [x-cloak] { display: none !important; }

        /* Global body styles - IMPORTANT for full height flexible layout */
        html, body {
            height: 100%; /* Ensure html and body take full viewport height */
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--color-bg-gray-100);
            display: flex; /* Make body a flex container */
            flex-direction: column; /* Stack header and main vertically */
            min-height: 100vh; /* Ensure body takes full viewport height */
            overflow-x: hidden; /* Prevent horizontal scroll from sidebar on mobile */
        }

        /* Header specific styles (can be adjusted) */
        header {
            flex-shrink: 0; /* Prevent header from shrinking */
        }

        /* Main content area - IMPORTANT for filling remaining vertical space */
        main {
            flex-grow: 1; /* Allows main to take all available vertical space */
            overflow: hidden; /* Keep content within bounds, inner elements will scroll */
            padding: 0; /* Removed padding top to close gap */
        }


        /* Styling for the tool actions group (e.g., Date, Zoom, Share buttons) */
        .tool-actions {
            border: 1px solid rgba(0, 0, 0, 0.3);
            border-radius: 25px;
            padding: 5px 5px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }
        .tool-actions button {
            font-size: 0.75rem;
            transition: background-color 0.3s, color 0.3s, border-radius 0.3s, transform 0.2s ease-out;
            padding: 5px 10px;
            border-radius: 10px;
            font-weight: bold; /* Made button text bold */
        }
        .tool-actions button:hover {
            background-color: var(--hover-bg-color);
            color: var(--hover-text-color);
            border-radius: var(--border-radius);
            transform: translateY(-2px); /* Subtle lift effect */
        }
        .tool-actions i {
            font-size: 0.75rem;
        }
        /* Base styles for menu buttons (Home, About Us, Contact Us) */
        .menu-button {
            font-size: 0.85rem;
            font-weight: normal; /* Changed to normal as requested */
        }
        .menu-button i {
            font-size: 0.85rem;
        }
        /* Consistent hover styles applying across various interactive elements */
        /* Applied consistent hover styles using variables where appropriate */
        .profile-dropdown a:hover {
            /* Removed !important and hardcoded colors to rely on Tailwind classes */
            background-color: var(--color-hover-color); /* Use theme variable for consistency */
            color: var(--color-hover-text-color); /* Use theme variable for consistency */
            border-radius: var(--border-radius);
            transform: translateY(-1px); /* Subtle lift on hover */
            transition: background-color 0.3s, color 0.3s, border-radius 0.3s, transform 0.2s ease-out;
        }
        /* MODIFIED: More menu hover effect */
        .more-menu a:hover {
            /* Removed !important and hardcoded colors to rely on Tailwind classes */
            background-color: var(--color-hover-color); /* Use theme variable for consistency */
            color: var(--color-hover-text-color); /* Use theme variable for consistency */
            border-radius: var(--border-radius);
            transform: translateY(-1px);
            transition: background-color 0.3s, color 0.3s, border-radius 0.3s, transform 0.2s ease-out;
        }
        nav a:hover {
            background-color: var(--hover-bg-color) !important;
            color: var(--hover-text-color) !important;
            border-radius: var(--border-radius);
            transform: translateY(-1px);
            transition: background-color 0.3s, color 0.3s, border-radius 0.3s, transform 0.2s ease-out;
        }

        /* Edition title badge styling - REMOVED specific background-color to use Tailwind class directly */
        /* .edition-title {
            background-color: var(--primary-color);
            font-weight: bold;
        } */
        /* Styles for alerts on login page, etc. */
        .alert {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-top: 1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
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

        /* Thumbnail Sidebar Styles (consistent with other pages) */
        #thumbnail-sidebar {
            position: fixed; /* Still fixed for mobile overlay */
            top: 0;
            left: 0;
            height: 100vh; /* Keep for mobile for full screen overlay effect */
            width: 150px;
            background-color: var(--color-main-color); /* Changed to main_color for mobile */
            color: var(--color-main-text-color); /* Changed to main_text_color for mobile */
            z-index: 20;
            border-right: 1px solid var(--color-light-gray-border);
            transform: translateX(-100%);
            transition: transform 0.3s ease-in-out;
            display: flex;
            flex-direction: column;
        }
        @media (min-width: 768px) {
            #thumbnail-sidebar {
                position: static; /* No longer fixed on desktop */
                transform: translateX(0);
                height: 100%; /* Explicitly take all available height */
                flex-grow: 1; /* Make it fill available space in the horizontal flex */
                flex-shrink: 0; /* Prevent shrinking */
                flex-basis: 150px; /* Suggest a preferred width */
                display: flex; /* Retain flex for desktop */
                flex-direction: column; /* Stack header and content vertically */
                min-height: 0; /* Important for scrollable flex items */
                background-color: white; /* Changed to white for desktop */
                color: gray; /* Changed to gray for desktop */
            }
        }
        #thumbnail-sidebar .sidebar-header {
            padding: 1rem;
            background-color: var(--color-main-color); /* Changed to main_color for mobile */
            color: var(--color-main-text-color); /* Changed to main_text_color for mobile */
            text-align: center;
            flex-shrink: 0; /* Prevent header from shrinking */
        }
        @media (min-width: 768px) {
            #thumbnail-sidebar .sidebar-header {
                background-color: white; /* Changed to white for desktop */
                color: gray; /* Changed to gray for desktop */
            }
        }
        #thumbnail-container {
            padding: 0.5rem;
            display: flex;
            flex-direction: column; /* Ensure vertical layout for thumbnails */
            align-items: center; /* Center thumbnails horizontally */
            flex-grow: 1; /* Allow thumbnail container to scroll */
            overflow-y: auto; /* Enable scrolling for thumbnails */
            min-height: 0; /* Critical for scrollable flex item */
            width: 100%; /* Take full width of sidebar */
        }
        .thumbnail-item {
            position: relative; /* Added for positioning the page number */
            padding: 5px;
            margin-bottom: 10px;
            cursor: pointer;
            border: 2px solid transparent;
            border-radius: 8px;
            transition: border-color 0.2s, background-color 0.2s;
            width: 120px; /* Fixed width for consistency */
            height: auto;
            flex-shrink: 0;
            max-width: 100%; /* Ensure image scales within its container */
        }
        .thumbnail-item:hover {
            background-color: rgba(0, 0, 0, 0.05);
            border-color: rgba(0, 0, 0, 0.1);
        }
        .thumbnail-item.active {
            border-color: var(--primary-color);
            background-color: rgba(2, 118, 208, 0.1);
        }
        .thumbnail-item img {
            width: 100%;
            height: auto;
            display: block;
            border-radius: 4px;
        }
        /* Style for the page number overlay */
        .page-number-overlay {
            position: absolute;
            bottom: 8px; /* Adjusted to be slightly from the bottom and right */
            right: 8px;
            background-color: rgba(0, 0, 0, 0.6);
            color: white;
            font-size: 0.75rem; /* Smaller font for number */
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 4px;
            z-index: 10; /* Ensure it's above the image */
        }
        /* Overlay for mobile sidebar */
        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background-color: var(--color-bg-black-opacity-50);
            z-index: 80;
            display: none;
        }

        /* Specific styles for the viewer (copied from index.php) */
        /* These styles will apply to elements within the main content of index.php */
        .viewer-controls {
            display: flex;
            justify-content: space-between; /* Changed to space-between for distribution */
            align-items: center;
            padding: 1rem;
            /* background-color: var(--color-bg-gray-100); */ /* Removed this */
            border-bottom: 1px solid var(--color-light-gray-border);
            gap: 0.5rem; /* reduced gap for more space */
            flex-wrap: nowrap; /* Prevent wrapping */
            overflow-x: auto; /* Allow horizontal scrolling if necessary */
            -webkit-overflow-scrolling: touch; /* Smooth scrolling on iOS */
            padding-bottom: 0.5rem; /* Add some padding at the bottom for scrollbar */
            flex-shrink: 0;
        }
        /* Ensure buttons don't shrink too much */
        .viewer-controls button,
        .viewer-controls input[type="date"],
        .viewer-controls a { /* Apply styles to anchor tag as well */
            flex-shrink: 0; /* Prevent items from shrinking */
            padding: 0.5rem 0.75rem; /* Adjusted padding for more compactness */
            font-size: 0.8rem; /* Slightly reduced font size if needed */
        }
        .viewer-controls button i,
        .viewer-controls a i { /* Ensure icons are also scaled with button font size */
            font-size: 0.8rem;
        }


        .viewer-controls button,
        .viewer-controls a { /* ADDED 'a' HERE */
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            border: 1px solid #d1d5db;
            background-color: #FFFFFF; /* Changed to white */
            color: #374151; /* Changed to a darker text color */
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.9rem;
            text-decoration: none; /* Ensure no underline for anchor tags acting as buttons */
        }
        .viewer-controls button:hover,
        .viewer-controls a:hover { /* ADDED 'a' HERE */
            background-color: #2563EB; /* Changed to hover_color */
            color: #FFFFFF; /* Changed to hover_text_color */
            border-color: #2563EB; /* Changed to hover_color */
        }
        .viewer-controls input[type="date"] {
             background-color: white; /* Keep date picker white */
             color: var(--text-main-color); /* Keep date picker text as gray_text */
        }
        .viewer-controls input[type="date"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(2, 118, 208, 0.1);
        }
        .page-display {
            font-weight: 600;
            /* color: var(--text-main-color); */ /* REMOVED THIS LINE */
            color: var(--color-main-text-color); /* Set page display text color to main_text_color */
        }
        .image-viewport {
            width: 100%;
            height: 100%; /* Added: Explicitly set height to 100% */
            flex-grow: 1; /* Take all available vertical space in its flex column parent */
            overflow: auto; /* Enable native scrollbars when content overflows */
            cursor: grab;
            position: relative;
            background-color: #f0f0f0;
            user-select: none;
            /* Removed touch-action: none; to enable native scrolling/panning */
        }
        .image-viewport.grabbing {
            cursor: grabbing;
        }
        .edition-page-image {
            display: block;
            user-select: none;
            width: auto;
            height: auto;
            max-width: none;
            max-height: none;
            pointer-events: none;
        }
        .no-edition-message {
            text-align: center;
            padding: 50px;
            font-size: 1.2rem;
            color: #6b7280;
        }
        @media (max-width: 768px) {
            /* Hide desktop viewer controls on mobile */
            .viewer-controls.desktop-only {
                display: none;
            }

            /* Styles for the mobile sticky toolbar */
            .mobile-toolbar {
                display: flex; /* Always display flex for mobile */
                flex-wrap: nowrap;
                justify-content: space-around;
                align-items: center;
                padding: 0.75rem 0.25rem; /* Adjusted padding to increase height */
                background-color: var(--color-main-color); /* Changed to main_color */
                border-top: 1px solid var(--color-light-gray-border);
                box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                width: 100%;
                z-index: 100;
            }

            .mobile-toolbar button,
            .mobile-toolbar a { /* Also target anchor tags for styling consistency */
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 0.3rem; /* Increased gap for comfort */
                padding: 0.35rem 0.3rem; /* Changed padding as requested */
                flex-grow: 1; /* Allow buttons to grow and fill space */
                max-width: calc(100% / 5); /* Now 5 items: Download, Prev, Next, Crop, Share, Date */
                border-radius: 0.5rem;
                transition: background-color 0.2s, color 0.2s;
                color: var(--color-main-text-color); /* Changed to main_text_color */
                font-size: 0.9rem; /* Increased font size for comfort */
                font-weight: 500;
                text-decoration: none; /* Remove underline for anchor tags */
            }

            .mobile-toolbar button:hover,
            .mobile-toolbar a:hover {
                background-color: var(--primary-color);
                color: var(--color-main-text-color);
            }
            /* Hide text in mobile toolbar buttons */
            .mobile-toolbar button span,
            .mobile-toolbar a span {
                display: none;
            }
            /* Show icon explicitly in mobile toolbar buttons */
            .mobile-toolbar button i,
            .mobile-toolbar a i {
                font-size: 1.2rem; /* Make icons larger for comfort */
            }

            /* Adjust main content padding to prevent content being hidden by sticky footer */
            main {
                /* Height of header (64px) + mobile top bar (approx 50px) + bottom mobile toolbar (approx 90px - adjusted) */
                padding-bottom: 90px; /* Adjusted for increased toolbar size */
            }

            /* New mobile-only top control bar */
            .mobile-top-controls {
                display: flex;
                flex-wrap: nowrap;
                justify-content: space-between; /* Use space-between to push elements to ends */
                align-items: center;
                padding: 0.3rem 1rem; /* reduced vertical padding */
                background-color: var(--color-bg-gray-100);
                border-bottom: 1px solid var(--color-light-gray-border);
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05); /* Subtle shadow */
                position: sticky;
                top: 64px; /* Adjust based on header height */
                left: 0;
                right: 0;
                width: 100%;
                z-index: 30; /* Below header, above main content */
                min-height: 40px; /* reduced min-height */
                border-radius: 0 0 1rem 1rem; /* Rounded bottom corners for the bar itself */
            }
            .mobile-top-controls > div { /* Apply to direct children for consistent spacing */
                display: flex;
                align-items: center;
                height: 100%; /* Ensure inner elements take full height of the reduced bar */
            }
        }
        html:-webkit-full-screen .image-viewport,
        html:-moz-full-screen .image-viewport,
        html:-ms-fullscreen .image-viewport,
        html:fullscreen .image-viewport {
            height: 100vh;
            width: 100vw;
            max-height: 100vh;
        }
        /* Custom styles for in-place cropper buttons */
        .cropper-buttons {
            position: absolute;
            z-index: 20; /* Ensure they are above the image and cropper box */
            display: flex;
            gap: 5px;
            background-color: rgba(0, 0, 0, 0.7);
            padding: 5px 10px;
            border-radius: 5px;
            /* Remove fixed top/right, managed by JS positionCropperButtons */
            visibility: hidden; /* Hidden by default */
            opacity: 0;
            transition: opacity 0.2s ease-in-out, visibility 0.2s ease-in-out;
            pointer-events: none; /* Allows clicks to pass through when hidden */
        }
        .cropper-buttons.visible {
            visibility: visible;
            opacity: 1;
            pointer-events: auto; /* Enable clicks when visible */
        }
        .cropper-buttons button {
            background-color: #4CAF50;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.8rem;
            transition: background-color 0.2s;
        }
        .cropper-buttons button:hover {
            background-color: #45a049;
        }
        .cropper-buttons .share-btn {
            background-color: #007bff; /* Blue for share */
        }
        .cropper-buttons .share-btn:hover {
            background-color: #0056b3;
        }
        .cropper-buttons .icon-only-btn { /* Style for icon-only buttons */
            padding: 8px; /* Square padding */
            width: 35px; /* Fixed width */
            height: 35px; /* Fixed height */
            display: flex; /* Use flex to center icon */
            justify-content: center; /* Center horizontally */
            align-items: center; /* Center vertically */
        }
        .cropper-buttons .icon-only-btn i {
            font-size: 1rem; /* Adjust icon size as needed */
        }
        .cropper-buttons .cancel-crop-btn {
            background-color: #f44336;
        }
        .cropper-buttons .cancel-crop-btn:hover {
            background-color: #da190b;
        }

        /* Styles for custom message box */
        .message-box {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            padding: 15px 25px;
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 1rem;
            font-weight: 500;
            color: white;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            gap: 10px;
            visibility: hidden;
            opacity: 0;
            transition: opacity 0.3s ease-in-out, visibility 0.3s ease-in-out;
            white-space: nowrap; /* Ensure content stays on one line */
        }
        .message-box.show {
            visibility: visible;
            opacity: 1;
        }
        .message-box.success {
            background-color: #28a745; /* Green for success */
        }
        .message-box.error {
            background-color: #dc3545; /* red for error */
        }
        .message-box i {
            font-size: 1.2rem;
            flex-shrink: 0; /* Prevent icon from shrinking */
        }

        /* Mobile-specific styles for the message box */
        @media (max-width: 768px) {
            .message-box {
                top: 10px; /* Adjust top position for mobile */
                padding: 8px 15px; /* reduce padding for mobile */
                font-size: 0.85rem; /* reduce font size for mobile */
                gap: 8px; /* Adjust gap between icon and text */
                max-width: 90%; /* Ensure it doesn't span full width */
                box-sizing: border-box; /* Include padding in width calculation */
            }
            .message-box i {
                font-size: 1rem; /* Adjust icon size for mobile */
            }
             .message-box #messageText {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            /* New: Styles for the notification message in mobile header */
            .mobile-header-notification {
                padding: 2px 0; /* reduced padding */
                font-size: 0.75rem; /* Smaller font size */
                font-weight: normal; /* Removed font-bold */
                color: var(--color-main-text-color); /* Changed to white */
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                display: flex;
                align-items: center;
                gap: 4px; /* Small gap between icon and text */
            }
            .mobile-header-notification i {
                font-size: 0.8rem; /* Icon size for header notification */
                flex-shrink: 0;
            }
        }
    </style>
</head>
<body>

<header class="sticky top-0 z-50 bg-[var(--color-main-color)] backdrop-blur-md transition-shadow duration-300 ease-in-out"
        x-data="{ scrolled: false }"
        @scroll.window="scrolled = (window.pageYOffset > 50 ? true : false)"
        :class="{ 'shadow-lg': scrolled, 'shadow-md': !scrolled }">
    <div class="relative max-w-full mx-auto px-4 py-2 md:py-2 flex items-center justify-between md:justify-start">
        <!-- Mobile hamburger menu button -->
        <button @click="sidebarOpen = !sidebarOpen" class="md:hidden z-20 relative w-8 h-8 flex flex-col justify-center items-center group">
            <span class="absolute w-6 h-0.5 bg-[var(--color-main-text-color)] transform transition duration-300 ease-in-out" :class="sidebarOpen ? 'rotate-45 translate-y-1.5' : '-translate-y-2'"></span>
            <span class="absolute w-6 h-0.5 bg-[var(--color-main-text-color)] transition duration-300 ease-in-out" :class="sidebarOpen ? 'opacity-0' : 'opacity-100'"></span>
            <span class="absolute w-6 h-0.5 bg-[var(--color-main-text-color)] transform transition duration-300 ease-in-out" :class="sidebarOpen ? '-rotate-45 -translate-y-1.5' : 'translate-y-2'"></span>
        </button>

        <!-- Logo for mobile view (moved next to hamburger) -->
        <div class="md:hidden ml-2"> <!-- Added ml-2 for spacing from menu icon -->
            <a href="/"> <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="Logo" class="h-10 w-auto rounded-[5px]">
            </a>
        </div>

        <!-- Mobile Profile Menu Trigger -->
        <div x-data="{ mobileProfileMenuOpen: false }" @click="mobileProfileMenuOpen = !mobileProfileMenuOpen" id="mobile-profile-trigger" class="md:hidden flex items-center gap-2 ml-auto border border-[var(--color-light-gray-border)] rounded-[var(--border-radius)] px-2 py-1 relative cursor-pointer group hover:bg-[var(--color-hover-color)]">
            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($loggedIn ? $username : 'Login'); ?>" alt="Profile" class="w-6 h-6 rounded-full">
            <?php if ($loggedIn): ?>
                <span class="text-[var(--color-main-text-color)] text-sm flex items-center gap-1 group-hover:text-[var(--color-hover-text-color)]"><?php echo htmlspecialchars($username); ?>
                    <i class="fas fa-chevron-down ml-1 text-xs mobile-profile-chevron group-hover:text-[var(--color-hover-text-color)]"></i>
                </span>
            <?php else: ?>
                <a href="/login" class="text-[var(--color-main-text-color)] px-2 py-1 rounded sidebar-link">Login</a>
            <?php endif; ?>
            <?php if ($loggedIn): ?>
                <div x-show="mobileProfileMenuOpen"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95"
                     @click.away="mobileProfileMenuOpen = false"
                     class="absolute top-full mt-2 right-0 w-48 bg-[var(--color-main-color)] border border-[var(--color-light-gray-border)] rounded-[var(--border-radius)] shadow-lg z-50 profile-dropdown">
                    <a href="/dashboard" class="block px-4 py-2 text-[var(--color-main-text-color)] hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] hover:rounded-[var(--border-radius)] font-bold"><i class="fas fa-dashboard mr-2"></i>Dashboard</a>
                    <a href="/users/edit_user.php<?php echo $currentUserId ? '?id=' . $currentUserId : ''; ?>" class="block px-4 py-2 text-[var(--color-main-text-color)] hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] hover:rounded-[var(--border-radius)] font-bold"><i class="fas fa-user-cog mr-2"></i>Profile Settings</a>
                    <a href="/logout" class="block px-4 py-2 text-[var(--color-main-text-color)] hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] hover:rounded-[var(--border-radius)] font-bold"><i class="fas fa-sign-out-alt mr-2"></i>Logout</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Desktop Logo (retained original position for desktop) -->
        <div class="hidden md:block absolute left-1/2 transform -translate-x-1/2 md:static md:transform-none">
            <a href="/"> <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="Logo" class="h-12 w-auto rounded-[5px]">
            </a>
        </div>

        <!-- Desktop Header Title -->
        <span class="hidden md:inline-block text-base font-semibold text-[var(--text-main-color)] ml-4 mr-auto bg-white rounded-[var(--border-radius)] px-3 py-1">
            <i class="fas fa-newspaper mr-2 text-[var(--text-main-color)]"></i> <?= $editionTitle ?>
        </span>


        <nav class="hidden md:flex items-center gap-2 ml-auto">
            <a href="/" class="block px-2 py-1 text-[var(--color-main-text-color)] hover:text-[var(--color-hover-text-color)] hover:bg-[var(--color-hover-color)] hover:rounded-[var(--border-radius)] menu-button">
                <i class="fas fa-home mr-1"></i>Home
            </a>
            <a href="/about-us" class="block px-2 py-1 text-[var(--color-main-text-color)] hover:text-[var(--color-hover-text-color)] hover:bg-[var(--color-hover-color)] hover:rounded-[var(--border-radius)] menu-button">
                <i class="fas fa-info-circle mr-1"></i>About Us
            </a>
            <a href="/contact-us" class="block px-2 py-1 text-[var(--color-main-text-color)] hover:text-[var(--color-hover-text-color)] hover:bg-[var(--color-hover-color)] hover:rounded-[var(--border-radius)] menu-button">
                <i class="fas fa-envelope mr-1"></i>Contact Us
            </a>
            <a href="/privacy-policies" class="block px-2 py-1 text-[var(--color-main-text-color)] hover:text-[var(--color-hover-text-color)] hover:bg-[var(--color-hover-color)] hover:rounded-[var(--border-radius)] menu-button">
                <i class="fas fa-user-shield mr-1"></i>Privacy Policies
            </a>
            <a href="/terms-conditions" class="block px-2 py-1 text-[var(--color-main-text-color)] hover:text-[var(--color-hover-text-color)] hover:bg-[var(--color-hover-color)] hover:rounded-[var(--border-radius)] menu-button">
                <i class="fas fa-file-contract mr-1"></i>Terms & Conditions
            </a>

            <div class="ml-4 relative" x-data="{ open: false }">
                <div id="desktop-profile-trigger" class="flex items-center gap-1 border border-[var(--color-light-gray-border)] rounded-[var(--border-radius)] px-3 py-1 cursor-pointer group hover:bg-[var(--color-hover-color)]"
                     @click="open = !open"
                     :class="{ 'bg-[var(--color-hover-color)] border-gray-400': open }">
                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($loggedIn ? $username : 'Login'); ?>" alt="Profile" class="w-8 h-8 rounded-full">
                    <?php if ($loggedIn): ?>
                        <span class="text-sm flex items-center gap-1 font-normal" :class="open ? 'text-[var(--color-hover-text-color)]' : 'text-[var(--color-main-text-color)] group-hover:text-[var(--color-hover-text-color)]'"><?php echo htmlspecialchars($username); ?> <i class="fas fa-chevron-down ml-1 text-xs desktop-profile-chevron" :class="{ 'text-[var(--color-hover-text-color)]': open }"></i></span>
                    <?php else: ?>
                        <a href="/login" class="text-[var(--color-main-text-color)] px-2 py-1 rounded sidebar-link">Login</a>
                    <?php endif; ?>
                </div>
                <?php if ($loggedIn): ?>
                <div x-show="open"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95"
                     @click.away="open = false"
                     class="absolute top-full mt-2 right-0 w-48 bg-[var(--color-main-color)] border border-[var(--color-light-gray-border)] rounded-[var(--border-radius)] shadow-lg z-50 profile-dropdown">
                    <a href="/dashboard" class="block px-4 py-2 text-[var(--color-main-text-color)] hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] hover:rounded-[var(--border-radius)] font-bold"><i class="fas fa-dashboard mr-2"></i>Dashboard</a>
                    <a href="/users/edit_user.php<?php echo $currentUserId ? '?id=' . $currentUserId : ''; ?>" class="block px-4 py-2 text-[var(--color-main-text-color)] hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] hover:rounded-[var(--border-radius)] font-bold"><i class="fas fa-user-cog mr-2"></i>Profile Settings</a>
                    <a href="/logout" class="block px-4 py-2 text-[var(--color-main-text-color)] hover:bg-[var(--color-hover-color)] hover:text-[var(--color-hover-text-color)] hover:rounded-[var(--border-radius)] font-bold"><i class="fas fa-sign-out-alt mr-2"></i>Logout</a>
                </div>
            <?php endif; ?>
        </div>
    </nav>
</div>
</header>

<!-- Mobile-only Top Control Bar (Edition Title & Date and Page Number) -->
<div class="mobile-top-controls md:hidden">
    <div class="flex-1 overflow-hidden px-3 py-1 rounded-lg mr-2 bg-[var(--color-main-color)]"> <!-- Left block for edition title and date with rounded corners -->
        <?php if (!empty($notificationMessage)): ?>
            <div class="mobile-header-notification flex items-center gap-1 text-[var(--color-main-text-color)]">
                <i class="fas fa-info-circle text-xs flex-shrink-0"></i>
                <span class="text-xs text-[var(--color-main-text-color)] whitespace-nowrap overflow-hidden text-ellipsis"><?= htmlspecialchars($notificationMessage) ?></span>
            </div>
        <?php else: ?>
            <span class="text-sm text-[var(--color-main-text-color)] whitespace-nowrap overflow-hidden text-ellipsis block"><?= $editionTitle ?></span>
        <?php endif; ?>
    </div>
    <div class="flex items-center space-x-2 page-display flex-shrink-0 px-3 py-1 rounded-lg bg-[var(--color-main-color)]"> <!-- Right block for page number with rounded corners -->
        <span class="text-sm text-[var(--color-main-text-color)]">Page <span id="mobileTopCurrentPageNum">1</span> / <span id="mobileTopTotalPagesNum">0</span></span>
    </div>
</div>

<div class="fixed inset-0 z-40" x-show="sidebarOpen" x-transition>
    <div class="absolute inset-0 bg-[var(--color-bg-black-opacity-50)]"
         x-transition:enter="transition-opacity ease-in-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-in-out duration-300"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="sidebarOpen = false"></div>
    <div class="relative w-64 h-full bg-[var(--color-main-color)] shadow-lg transform transition-transform duration-300"
         x-transition:enter="-translate-x-full" x-transition:enter-end="translate-x-0"
         x-transition:leave="translate-x-0" x-transition:leave-end="-translate-x-full">
        <div class="flex justify-between items-center px-4 py-3 border-b border-[var(--color-light-gray-border)]">
            <span class="text-lg font-semibold font-bold text-[var(--color-main-text-color)]">Menu</span>
            <button @click="sidebarOpen = false" class="absolute top-2 right-2 text-[var(--color-main-text-color)] md:hidden p-1 rounded-md bg-[var(--color-hover-color)]">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <nav class="flex flex-col px-2 py-6 space-y-0">
            <a href="/" class="block px-2 py-2 text-[var(--color-main-text-color)] hover:text-[var(--color-hover-text-color)] hover:bg-[var(--color-hover-color)] hover:rounded-[var(--border-radius)] font-bold"><i class="fas fa-home mr-2"></i>Home</a>
            <a href="/about-us" class="block px-2 py-2 text-[var(--color-main-text-color)] hover:text-[var(--color-hover-text-color)] hover:bg-[var(--color-hover-color)] hover:rounded-[var(--border-radius)] font-bold"><i class="fas fa-info-circle mr-2"></i>About Us</a>
            <a href="/contact-us" class="block px-2 py-2 text-[var(--color-main-text-color)] hover:text-[var(--color-hover-text-color)] hover:bg-[var(--color-hover-color)] hover:rounded-[var(--border-radius)] font-bold"><i class="fas fa-envelope mr-2"></i>Contact Us</a>
            <a href="/privacy-policies" class="block px-2 py-2 text-[var(--color-main-text-color)] hover:text-[var(--color-hover-text-color)] hover:bg-[var(--color-hover-color)] hover:rounded-[var(--border-radius)] font-bold"><i class="fas fa-user-shield mr-2"></i>Privacy Policies</a>
            <a href="/terms-conditions" class="block px-2 py-2 text-[var(--color-main-text-color)] hover:text-[var(--color-hover-text-color)] hover:bg-[var(--color-hover-color)] hover:rounded-[var(--border-radius)] font-bold"><i class="fas fa-file-contract mr-2"></i>Terms & Conditions</a>
        </nav>
    </div>
</div>

<main class="flex-grow px-4 py-0 md:px-4 h-full">
    <div class="max-w-full mx-auto py-0 h-full">
        <div class="bg-white rounded-xl shadow-md overflow-hidden p-0 h-full">
            <div class="flex flex-grow overflow-hidden h-full">
                <!-- Thumbnail sidebar (visible on desktop, hidden by default on mobile) -->
                <aside id="thumbnail-sidebar"
                       x-data="{ sidebarOpen: false }"
                       :class="{ 'translate-x-0': sidebarOpen, 'absolute left-0 z-50': window.innerWidth < 768 }"
                       class="w-[150px] bg-[var(--color-main-color)] text-[var(--color-main-text-color)] flex-shrink-0
                              transform -translate-x-full transition-transform duration-300 ease-in-out
                              md:static md:translate-x-0 md:flex md:flex-col md:flex-grow md:min-h-0 border-r border-gray-200">
                    <div class="sidebar-header">
                        <h2 class="text-base font-semibold">Pages</h2>
                        <button @click="sidebarOpen = false" class="absolute top-2 right-2 text-[var(--color-main-text-color)] md:hidden p-1 rounded-md bg-[var(--color-hover-color)]">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div id="thumbnail-container">
                        <!-- Thumbnails here -->
                    </div>
                </aside>

                <!-- Overlay for mobile screens when sidebar is open -->
                <div x-show="sidebarOpen && window.innerWidth < 768" @click="sidebarOpen = false"
                     class="sidebar-overlay"
                     x-transition:enter="transition-opacity ease-in-out duration-300"
                     x-transition:enter-start="opacity-0"
                     x-transition:enter-end="opacity-100"
                     x-transition:leave="transition-opacity ease-in-out duration-300"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0">
                </div>

                <!-- Main Viewer Section -->
                <div class="flex-grow flex flex-col overflow-hidden min-h-0">
                    <!-- Notification Message -->
                    <?php if (!empty($notificationMessage)): ?>
                        <div class="alert alert-info bg-[var(--color-green-100)] text-[var(--color-green-600)] px-4 py-3 rounded-lg flex items-center justify-center my-2 mx-4" role="alert">
                            <i class="fas fa-info-circle mr-2"></i>
                            <span><?= htmlspecialchars($notificationMessage) ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- Desktop Viewer Controls (hidden on mobile) -->
                    <div class="viewer-controls desktop-only hidden md:flex" >
                        <!-- All viewer controls -->
                        <input type="date" id="editionDatePicker" value="<?= htmlspecialchars($selectedDate) ?>"
                               class="bg-white text-[var(--text-main-color)] px-3 py-2 rounded-md border border-gray-300 focus:outline-none focus:ring-2 focus:ring-[var(--primary-color)]">
                        <button id="prevPage" class="text-[#374151] hover:bg-[#2563EB] hover:text-[#FFFFFF] disabled:opacity-50 disabled:cursor-not-allowed">
                            <i class="fas fa-chevron-left mr-2"></i> Previous Page
                        </button>
                        <span class="page-display text-[var(--color-main-text-color)]">Page <span id="currentPageNum">1</span> / <span id="totalPagesNum">0</span></span>
                        <button id="nextPage" class="text-[#374151] hover:bg-[#2563EB] hover:text-[#FFFFFF] disabled:opacity-50 disabled:cursor-not-allowed">
                            Next Page <i class="fas fa-chevron-right ml-2"></i>
                        </button>
                        <button id="zoomIn" class="text-[#374151] hover:bg-[#2563EB] hover:text-[#FFFFFF]">
                            <i class="fas fa-search-plus"></i> Zoom In
                        </button>
                        <button id="zoomOut" class="text-[#374151] hover:bg-[#2563EB] hover:text-[#FFFFFF]">
                            <i class="fas fa-search-minus"></i> Zoom Out
                        </button>
                        <button id="resetZoom" class="text-[#374151] hover:bg-[#2563EB] hover:text-[#FFFFFF]">
                            <i class="fas fa-compress-arrows-alt"></i> Reset Zoom
                        </button>
                        <!-- Updated Toggle Crop Mode Button -->
                        <button id="toggleCropModeBtn" class="text-[#374151] hover:bg-[#2563EB] hover:text-[#FFFFFF]">
                            <i class="fas fa-crop-alt"></i> Crop
                        </button>
                        <!-- START Full Screen Button -->
                        <button id="fullScreenBtn" class="text-[#374151] hover:bg-[#2563EB] hover:text-[#FFFFFF]">
                            <i class="fas fa-expand-arrows-alt"></i> Full Screen
                        </button>
                        <!-- END Full Screen Button -->
                        <!-- START Download PDF Button -->
                        <?php if ($edition && !empty($edition['pdf_path'])): ?>
                            <a href="<?= htmlspecialchars($edition['pdf_path']) ?>" download class="font-bold py-2 px-4 rounded-[0.5rem] flex items-center gap-1">
                                <img src="https://img.icons8.com/color/48/pdf-2--v1.png" alt="PDF Icon" class="h-5 w-5"> Download PDF
                            </a>
                        <?php endif; ?>
                        <!-- END Download PDF Button -->
                    </div>
                    <div class="image-viewport flex-grow" id="imageViewport">
                        <!-- Image here -->
                        <?php if ($edition && !empty($editionImages)): ?>
                            <?php foreach ($editionImages as $index => $imagePath): ?>
                                <img src="<?= htmlspecialchars($imagePath) ?>"
                                     class="edition-page-image"
                                     id="pageImage_<?= $index ?>"
                                     alt="Edition Page <?= $index + 1 ?>"
                                     style="<?= $index === 0 ? 'display: block;' : 'display: none;' ?>">
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="no-edition-message">
                                <i class="fas fa-info-circle mr-2"></i> No edition found for selected date (<?= htmlspecialchars($selectedDate) ?>).
                                <?php if (empty($editionImages) && $edition): ?>
                                    Images for this edition could not be loaded.
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                        <!-- Cropper.js Sticky Buttons (initially hidden) -->
                        <div class="cropper-buttons" id="cropperButtons">
                            <button id="cropDownloadBtn"><i class="fas fa-crop-alt mr-1"></i> Crop</button>
                            <button id="shareCropBtn" class="share-btn icon-only-btn"><i class="fas fa-share-alt"></i></button>
                            <button id="cancelCropBtn" class="cancel-crop-btn icon-only-btn"><i class="fas fa-times-circle"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Mobile Sticky Toolbar (actions) -->
<div class="mobile-toolbar md:hidden">
    <?php if ($edition && !empty($edition['pdf_path'])): ?>
    <a href="<?= htmlspecialchars($edition['pdf_path']) ?>" download id="mobileDownloadPdfBtn" title="Download">
        <i class="fas fa-download"></i> <span>PDF</span>
    </a>
    <?php endif; ?>
    <button id="mobilePrevPageBtn" title="Previous Page">
        <i class="fas fa-chevron-left"></i> <span>Prev</span>
    </button>
    <button id="mobileNextPageBtn" title="Next Page">
        <i class="fas fa-chevron-right"></i> <span>Next</span>
    </button>
    <button id="mobileToggleCropModeBtn" title="Crop">
        <i class="fas fa-crop-alt"></i> <span>Crop</span>
    </button>
    <button id="mobileShareCropBtn" title="Share">
        <i class="fas fa-share-alt"></i> <span>Share</span>
    </button>
    <button id="mobileDatePickerBtn" title="Pick Date">
        <i class="fas fa-calendar-alt"></i> <span>Date</span>
    </button>
</div>

<!-- Custom Message Box HTML -->
<div id="messageBox" class="message-box">
    <i id="messageIcon"></i>
    <span id="messageText"></span>
</div>

<!-- Cropper.js JavaScript -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>

<!-- Your custom JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // PHP variables made available in JavaScript
    const logoPath = "<?php echo htmlspecialchars(defined('APP_LOGO_PATH') ? APP_LOGO_PATH : 'uploads/assets/logo.jpg'); ?>";
    const selectedDate = "<?php echo htmlspecialchars($selectedDate); ?>";
    const editionName = "<?php echo htmlspecialchars($rawEditionTitle); ?>"; // Pass raw edition title
    const pageTurnSoundPath = "<?php echo htmlspecialchars($pageTurnSoundPath); ?>"; // Path to the WAV file

    const editionImages = <?= json_encode($editionImages); ?>;
    // --- IMPORTANT DEBUGGING STEP ---
    console.log('PHP passed editionImages:', editionImages);
    // --- END DEBUGGING STEP ---

    const imageViewport = document.getElementById('imageViewport');
    const prevPageBtn = document.getElementById('prevPage');
    const nextPageBtn = document.getElementById('nextPage');
    const currentPageNumSpan = document.getElementById('currentPageNum');
    const totalPagesNumSpan = document.getElementById('totalPagesNum');
    const zoomInBtn = document.getElementById('zoomIn');
    const zoomOutBtn = document.getElementById('zoomOut');
    const resetZoomBtn = document.getElementById('resetZoom');
    const datePicker = document.getElementById('editionDatePicker');

    // Cropper.js elements
    const toggleCropModeBtn = document.getElementById('toggleCropModeBtn');
    const cropperButtons = document.getElementById('cropperButtons');
    const cropDownloadBtn = document.getElementById('cropDownloadBtn');
    const shareCropBtn = document.getElementById('shareCropBtn');
    const cancelCropBtn = document.getElementById('cancelCropBtn');

    // Full Screen: New elements for Full Screen integration
    const fullScreenBtn = document.getElementById('fullScreenBtn');

    // Thumbnail Sidebar: New elements for thumbnail integration
    const thumbnailContainer = document.getElementById('thumbnail-container');

    // Message Box elements
    const messageBox = document.getElementById('messageBox');
    const messageIcon = document.getElementById('messageIcon');
    const messageText = document.getElementById('messageText');

    // Mobile Toolbar Elements (bottom actions)
    const mobileDatePickerBtn = document.getElementById('mobileDatePickerBtn');
    const mobileToggleCropModeBtn = document.getElementById('mobileToggleCropModeBtn');
    const mobileDownloadPdfBtn = document.getElementById('mobileDownloadPdfBtn');
    const mobileShareCropBtn = document.getElementById('mobileShareCropBtn');
    const mobilePrevPageBtn = document.getElementById('mobilePrevPageBtn'); // New mobile prev button
    const mobileNextPageBtn = document.getElementById('mobileNextPageBtn'); // New mobile next button


    // Mobile Top Bar Page Number display
    const mobileTopCurrentPageNumSpan = document.getElementById('mobileTopCurrentPageNum');
    const mobileTopTotalPagesNumSpan = document.getElementById('mobileTopTotalPagesNum');


    let cropper;
    let currentPageIndex = 0;
    let currentImageNaturalWidth = 0;
    let currentImageNaturalHeight = 0;
    let zoomLevel = 1;
    let isDragging = false; // For mouse panning (desktop)
    let startScrollLeft;
    let startScrollTop;
    let startMouseX;
    let startMouseY;

    // Swipe variables (mobile)
    let touchStartX = 0;
    let touchStartY = 0;
    const swipeMinDistance = 50; // Minimum distance for a swipe to be recognized
    const swipeMaxVerticalDeviationForHorizontalSwipe = 75; // Max vertical movement allowed for a horizontal page swipe


    // Double-tap variables
    let lastTapTime = 0;
    const DOUBLE_TAP_DELAY = 300; // ms

    // Pinch-to-zoom variables
    let initialPinchDistance = 0;
    let zoomLevelAtPinchStart = 1; // Tracks the zoom level at the beginning of a pinch gesture
    let isPinching = false;
    let wasPinchingThisGesture = false; // New flag to track if a pinch occurred during the touch sequence
    const MAX_ZOOM_LEVEL = 4; // Maximum zoom
    const MIN_ZOOM_LEVEL_THRESHOLD = 0.01; // For comparing floating point numbers

    // Audio element for page turn sound
    const pageTurnAudio = new Audio(pageTurnSoundPath);
    pageTurnAudio.preload = 'auto'; // Preload the audio for faster playback

    // Set total pages display for desktop and mobile top bar
    totalPagesNumSpan.textContent = editionImages.length;
    mobileTopTotalPagesNumSpan.textContent = editionImages.length;

    // --- Sound and Vibration Functions ---

    /**
     * Plays a sound and/or vibrates the device for page turns.
     * @param {string} direction 'prev' or 'next'. (Not directly used for sound, but for context)
     */
    function playPageTurnFeedback(direction) {
        // Play sound
        if (pageTurnAudio) {
            // Reset and play to allow rapid successive plays
            pageTurnAudio.currentTime = 0;
            pageTurnAudio.play().catch(e => console.error("Error playing audio:", e));
        }

        // Vibrate the device (will respect system vibration settings)
        if (navigator.vibrate) {
            navigator.vibrate(50); // Short vibration for 50ms
        }
    }

    // --- Helper Functions ---

    /**
     * Displays a custom message box with a given message and type (success/error).
     * @param {string} message The message to display.
     * @param {string} type 'success' or 'error'.
     * @param {number} duration Duration in milliseconds (default: 3000).
     */
    function showMessageBox(message, type, duration = 3000) {
        messageBox.classList.remove('success', 'error');
        messageBox.classList.add(type);
        messageText.textContent = message;
        messageIcon.className = '';
        if (type === 'success') {
            messageIcon.classList.add('fas', 'fa-check-circle');
        } else if (type === 'error') {
            messageIcon.classList.add('fas', 'fa-times-circle');
        }

        messageBox.classList.add('show');

        setTimeout(() => {
            messageBox.classList.remove('show');
        }, duration);
    }

    /**
     * Generates and displays thumbnails in the sidebar.
     */
    function generateThumbnails() {
        thumbnailContainer.innerHTML = '';
        editionImages.forEach((imagePath, index) => {
            const thumbItem = document.createElement('div');
            thumbItem.classList.add('thumbnail-item');
            thumbItem.dataset.index = index;

            const thumbImg = document.createElement('img');
            thumbImg.src = imagePath;
            thumbImg.alt = `Page ${index + 1}`;
            thumbImg.loading = 'lazy';

            const pageNumberOverlay = document.createElement('div');
            pageNumberOverlay.classList.add('page-number-overlay');
            pageNumberOverlay.textContent = index + 1;

            thumbItem.appendChild(thumbImg);
            thumbItem.appendChild(pageNumberOverlay);
            thumbnailContainer.appendChild(thumbItem);

            thumbItem.addEventListener('click', () => {
                currentPageIndex = index;
                renderPage(currentPageIndex);
                if (window.innerWidth < 768) {
                    // Access the sidebarOpen variable from the html tag's x-data
                    document.documentElement.x_data_alpine_instance.sidebarOpen = false;
                }
            });
        });
    }

    /**
     * Updates the current image's displayed size based on zoomLevel.
     * Does NOT handle scrolling.
     */
    function updateImageDisplay() {
        if (editionImages.length === 0) return;
        const currentImage = document.getElementById(`pageImage_${currentPageIndex}`);
        if (!currentImage || !currentImageNaturalWidth || !currentImageNaturalHeight) {
            // If image not loaded yet, set onload to re-attempt update.
            // This case should ideally be handled by renderPage ensuring image.complete
            currentImage.onload = () => {
                currentImageNaturalWidth = currentImage.naturalWidth;
                currentImageNaturalHeight = currentImage.naturalHeight;
                updateImageDisplay();
            };
            return;
        }
        currentImage.style.width = (currentImageNaturalWidth * zoomLevel) + 'px';
        currentImage.style.height = 'auto'; // Maintain aspect ratio
    }

    /**
     * Clamps the scroll position to ensure it's within valid bounds.
     * Centers the image if it's smaller than the viewport.
     */
    function clampScroll() {
        const currentImage = document.getElementById(`pageImage_${currentPageIndex}`);
        if (!currentImage) return;

        const viewportWidth = imageViewport.clientWidth;
        const viewportHeight = imageViewport.clientHeight;
        const imageWidth = currentImage.clientWidth;
        const imageHeight = currentImage.clientHeight;

        let newScrollLeft = imageViewport.scrollLeft;
        let newScrollTop = imageViewport.scrollTop;

        // Clamp horizontal scroll
        if (imageWidth <= viewportWidth) {
            newScrollLeft = (imageWidth - viewportWidth) / 2; // Center if smaller
        } else {
            newScrollLeft = Math.max(0, Math.min(newScrollLeft, imageWidth - viewportWidth));
        }

        // Clamp vertical scroll
        if (imageHeight <= viewportHeight) {
            newScrollTop = (imageHeight - viewportHeight) / 2; // Center if smaller
        } else {
            newScrollTop = Math.max(0, Math.min(newScrollTop, imageHeight - viewportHeight));
        }

        imageViewport.scrollLeft = newScrollLeft;
        imageViewport.scrollTop = newScrollTop;
    }

    /**
     * Resets the zoom level to fit the image to the viewport width.
     * @param {boolean} showMessage Whether to display a message box.
     */
    function resetZoom(showMessage = true) {
        const currentImage = document.getElementById(`pageImage_${currentPageIndex}`);
        if (!currentImage || !currentImageNaturalWidth) return;

        zoomLevel = imageViewport.clientWidth / currentImageNaturalWidth;
        updateImageDisplay();
        clampScroll();
        if (showMessage) {
            showMessageBox('Zoom reset to fit width.', 'success');
        }
    }

    /**
     * Disables or enables all main viewer controls.
     * @param {boolean} disable True to disable, false to enable.
     */
    function disableAllControls(disable) {
        const controls = [
            prevPageBtn, nextPageBtn, zoomInBtn, zoomOutBtn, resetZoomBtn,
            toggleCropModeBtn, fullScreenBtn, datePicker, mobilePrevPageBtn,
            mobileNextPageBtn, mobileToggleCropModeBtn, mobileDatePickerBtn
        ];
        controls.forEach(control => {
            if (control) { // Check if the element exists
                control.disabled = disable;
                control.classList.toggle('opacity-50', disable);
                control.classList.toggle('cursor-not-allowed', disable);
            }
        });
        // Special handling for download PDF link (it's an anchor tag)
        if (mobileDownloadPdfBtn) {
            if (disable) {
                mobileDownloadPdfBtn.style.pointerEvents = 'none';
                mobileDownloadPdfBtn.classList.add('opacity-50');
            } else {
                mobileDownloadPdfBtn.style.pointerEvents = 'auto';
                mobileDownloadPdfBtn.classList.remove('opacity-50');
            }
        }
        // Also disable desktop download PDF if it exists
        const desktopDownloadPdfBtn = document.querySelector('.viewer-controls a[download]');
        if (desktopDownloadPdfBtn) {
            if (disable) {
                desktopDownloadPdfBtn.style.pointerEvents = 'none';
                desktopDownloadPdfBtn.classList.add('opacity-50');
            } else {
                desktopDownloadPdfBtn.style.pointerEvents = 'auto';
                desktopDownloadPdfBtn.classList.remove('opacity-50');
            }
        }
    }

    /**
     * Disables or enables controls that might interfere with cropping.
     * @param {boolean} disable True to disable, false to enable.
     */
    function disableControlsForCrop(disable) {
        const controlsToDisable = [
            prevPageBtn, nextPageBtn, zoomInBtn, zoomOutBtn, resetZoomBtn,
            fullScreenBtn, datePicker, mobilePrevPageBtn, mobileNextPageBtn,
            mobileDatePickerBtn, mobileDownloadPdfBtn
        ];
        controlsToDisable.forEach(control => {
            if (control) {
                control.disabled = disable;
                control.classList.toggle('opacity-50', disable);
                control.classList.toggle('cursor-not-allowed', disable);
            }
        });

        // Special handling for anchor tags like download PDF
        const desktopDownloadPdfBtn = document.querySelector('.viewer-controls a[download]');
        if (desktopDownloadPdfBtn) {
            if (disable) {
                desktopDownloadPdfBtn.style.pointerEvents = 'none';
                desktopDownloadPdfBtn.classList.add('opacity-50');
            } else {
                desktopDownloadPdfBtn.style.pointerEvents = 'auto';
                desktopDownloadPdfBtn.classList.remove('opacity-50');
            }
        }

        // Toggle the crop button itself's active state
        toggleCropModeBtn.classList.toggle('bg-blue-600', disable);
        toggleCropModeBtn.classList.toggle('text-white', disable);
        toggleCropModeBtn.classList.toggle('hover:bg-blue-700', disable);
        toggleCropModeBtn.classList.toggle('bg-white', !disable);
        toggleCropModeBtn.classList.toggle('text-[#374151]', !disable);
        toggleCropModeBtn.classList.toggle('hover:bg-[#2563EB]', !disable);
        toggleCropModeBtn.classList.toggle('hover:text-[#FFFFFF]', !disable);

        mobileToggleCropModeBtn.classList.toggle('bg-blue-600', disable);
        mobileToggleCropModeBtn.classList.toggle('text-white', disable);
        mobileToggleCropModeBtn.classList.toggle('hover:bg-blue-700', disable);
        mobileToggleCropModeBtn.classList.toggle('bg-[var(--color-main-color)]', !disable); // Use theme variable
        mobileToggleCropModeBtn.classList.toggle('text-[var(--color-main-text-color)]', !disable); // Use theme variable
        mobileToggleCropModeBtn.classList.toggle('hover:bg-[var(--primary-color)]', !disable); // Use theme variable
        mobileToggleCropModeBtn.classList.toggle('hover:text-[var(--color-main-text-color)]', !disable); // Use theme variable
    }


    /**
     * Renders the specified page, hiding others.
     * Also loads the image to get its natural dimensions for zoom.
     * @param {number} index The index of the page to display.
     */
    function renderPage(index) {
        if (editionImages.length === 0) {
            disableAllControls(true);
            return;
        }

        // Hide all images
        document.querySelectorAll('.edition-page-image').forEach(img => {
            img.style.display = 'none';
        });

        const currentImage = document.getElementById(`pageImage_${index}`);
        if (currentImage) {
            currentImage.style.display = 'block';
            currentPageNumSpan.textContent = index + 1; // Desktop page number
            mobileTopCurrentPageNumSpan.textContent = index + 1; // Mobile top page number

            // Update active thumbnail in sidebar
            document.querySelectorAll('.thumbnail-item').forEach(item => {
                item.classList.remove('active');
            });
            const activeThumbnail = thumbnailContainer.querySelector(`[data-index="${index}"]`);
            if (activeThumbnail) {
                activeThumbnail.classList.add('active');
                // Scroll thumbnail into view if necessary
                activeThumbnail.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            // Load natural dimensions or reset zoom once loaded
            if (currentImage.complete) {
                currentImageNaturalWidth = currentImage.naturalWidth;
                currentImageNaturalHeight = currentImage.naturalHeight;
                zoomLevel = imageViewport.clientWidth / currentImageNaturalWidth; // Reset zoom to fit width
                updateImageDisplay(); // Update image size
                clampScroll(); // Center or reset scroll
            } else {
                currentImage.onload = () => {
                    currentImageNaturalWidth = currentImage.naturalWidth;
                    currentImageNaturalHeight = currentImage.naturalHeight;
                    zoomLevel = imageViewport.clientWidth / currentImageNaturalWidth; // Reset zoom to fit width
                    updateImageDisplay(); // Update image size
                    clampScroll(); // Center or reset scroll
                };
            }
        }

        // Enable controls (if not already disabled by something else like cropping)
        disableAllControls(false);
    }


    // --- Event Handlers ---

    // Page Navigation (Desktop)
    prevPageBtn.addEventListener('click', () => {
        if (currentPageIndex > 0) {
            currentPageIndex--;
            renderPage(currentPageIndex);
            playPageTurnFeedback('prev');
        }
    });

    nextPageBtn.addEventListener('click', () => {
        if (currentPageIndex < editionImages.length - 1) {
            currentPageIndex++;
            renderPage(currentPageIndex);
            playPageTurnFeedback('next');
        }
    });

    // Mobile Page Navigation
    mobilePrevPageBtn.addEventListener('click', () => {
        if (currentPageIndex > 0) {
            currentPageIndex--;
            renderPage(currentPageIndex);
            playPageTurnFeedback('prev');
        }
    });

    mobileNextPageBtn.addEventListener('click', () => {
        if (currentPageIndex < editionImages.length - 1) {
            currentPageIndex++;
            renderPage(currentPageIndex);
            playPageTurnFeedback('next');
        }
    });


    // Pan using mouse drag (Desktop only)
    imageViewport.addEventListener('mousedown', (e) => {
        // Only pan if on desktop and not in cropping mode
        if (window.innerWidth >= 768 && !(cropper && cropper.cropping)) {
            if (e.buttons === 1) { // Left mouse button
                isDragging = true;
                imageViewport.classList.add('grabbing');
                startMouseX = e.clientX;
                startMouseY = e.clientY;
                startScrollLeft = imageViewport.scrollLeft;
                startScrollTop = imageViewport.scrollTop;
                e.preventDefault(); // Prevent default image drag behavior
            }
        }
    });

    imageViewport.addEventListener('mousemove', (e) => {
        if (!isDragging) return;
        if (window.innerWidth >= 768) { // Only pan if on desktop
            const dx = e.clientX - startMouseX;
            const dy = e.clientY - startMouseY;
            imageViewport.scrollLeft = startScrollLeft - dx;
            imageViewport.scrollTop = startScrollTop - dy;
        }
    });

    imageViewport.addEventListener('mouseup', () => {
        isDragging = false;
        imageViewport.classList.remove('grabbing');
    });

    imageViewport.addEventListener('mouseleave', () => {
        isDragging = false;
        imageViewport.classList.remove('grabbing');
    });

    // Zoom In/Out/Reset buttons for desktop
    zoomInBtn.addEventListener('click', () => {
        if (cropper && cropper.cropping) return; // Do not zoom if cropper is active
        const oldZoomLevel = zoomLevel;
        zoomLevel = Math.min(zoomLevel * 1.2, MAX_ZOOM_LEVEL);
        updateImageDisplay();
        clampScroll();
        if (zoomLevel === MAX_ZOOM_LEVEL && oldZoomLevel < MAX_ZOOM_LEVEL) {
            showMessageBox('Maximum zoom level reached.', 'success');
        }
    });

    zoomOutBtn.addEventListener('click', () => {
        if (cropper && cropper.cropping) return; // Do not zoom if cropper is active
        const currentImage = document.getElementById(`pageImage_${currentPageIndex}`);
        const fitToWidthScale = currentImage && currentImage.naturalWidth ? (imageViewport.clientWidth / currentImage.naturalWidth) : 0;
        const oldZoomLevel = zoomLevel;
        zoomLevel = Math.max(zoomLevel / 1.2, fitToWidthScale);
        updateImageDisplay();
        clampScroll();
        // Show "Zoom reset to fit width" only if it actually reset to fit width from a larger zoom
        if (Math.abs(zoomLevel - fitToWidthScale) < MIN_ZOOM_LEVEL_THRESHOLD && Math.abs(oldZoomLevel - fitToWidthScale) >= MIN_ZOOM_LEVEL_THRESHOLD) {
            showMessageBox('Zoom reset to fit width.', 'success');
        }
    });

    resetZoomBtn.addEventListener('click', () => {
        if (cropper && cropper.cropping) return; // Do not reset zoom if cropper is active
        resetZoom(true); // Show info message when button is clicked
    });


    // --- Touch Event Handlers for Mobile Gestures ---
    imageViewport.addEventListener('touchstart', (e) => {
        if (window.innerWidth < 768) { // Only for mobile
            if (cropper && cropper.cropping) {
                isPinching = false; // Ensure pinch doesn't interfere with cropper
                return;
            }

            if (e.touches.length === 1) {
                // Single touch for swipe or potential double tap
                touchStartX = e.touches[0].clientX;
                touchStartY = e.touches[0].clientY;
                // No need for custom dragging logic here, native panning will handle it
            } else if (e.touches.length === 2) {
                // Two touches for pinch zoom
                isPinching = true;
                initialPinchDistance = getPinchDistance(e.touches);
                zoomLevelAtPinchStart = zoomLevel; // Capture the current zoom level when pinch starts
                wasPinchingThisGesture = true; // Set flag if a pinch is detected
            }
        }
    }, { passive: true }); // Using passive: true for touchstart for better responsiveness

    imageViewport.addEventListener('touchmove', (e) => {
        if (window.innerWidth < 768) { // Only for mobile
            if (cropper && cropper.cropping) return;

            if (isPinching && e.touches.length === 2) {
                wasPinchingThisGesture = true; // Confirm pinch occurred during move
                e.preventDefault(); // Prevent default scroll/zoom behavior
                const currentPinchDistance = getPinchDistance(e.touches);
                if (initialPinchDistance === 0) {
                    initialPinchDistance = currentPinchDistance;
                    zoomLevelAtPinchStart = zoomLevel;
                    return;
                }

                const scale = currentPinchDistance / initialPinchDistance;
                let newZoomLevel = zoomLevelAtPinchStart * scale;

                const currentImage = document.getElementById(`pageImage_${currentPageIndex}`);
                if (!currentImage || !currentImageNaturalWidth) return;

                const fitToWidthScale = imageViewport.clientWidth / currentImageNaturalWidth;
                newZoomLevel = Math.min(Math.max(newZoomLevel, fitToWidthScale), MAX_ZOOM_LEVEL);

                // Calculate new scroll position to zoom around the pinch center
                const midpointX = (e.touches[0].clientX + e.touches[1].clientX) / 2 - imageViewport.getBoundingClientRect().left;
                const midpointY = (e.touches[0].clientY + e.touches[1].clientY) / 2 - imageViewport.getBoundingClientRect().top;

                const currentScrollX = imageViewport.scrollLeft;
                const currentScrollY = imageViewport.scrollTop;

                const imgX = (midpointX + currentScrollX) / zoomLevel;
                const imgY = (midpointY + currentScrollY) / zoomLevel;

                zoomLevel = newZoomLevel;
                updateImageDisplay(); // Changed from updateImageSizeAndCenterScroll

                // Adjust scroll to keep pinch midpoint in view
                imageViewport.scrollLeft = (imgX * zoomLevel) - midpointX;
                imageViewport.scrollTop = (imgY * zoomLevel) - midpointY;
                clampScroll(); // Ensure scroll is valid after pinch zoom
            }
            // For single touch, native panning will handle scrolling as touch-action is removed.
            // No custom logic needed for single-touch pan in touchmove.
        }
    }, { passive: false }); // Use passive: false for touchmove to allow preventDefault for pinch-zoom

    imageViewport.addEventListener('touchend', (e) => {
        if (window.innerWidth < 768) { // Only for mobile
            if (cropper && cropper.cropping) {
                isPinching = false;
                wasPinchingThisGesture = false;
                return;
            }

            isPinching = false;

            // If a pinch gesture just occurred, do not process double-tap or swipe
            if (wasPinchingThisGesture) {
                wasPinchingThisGesture = false; // Reset the flag
                return; // Exit to prevent further processing
            }

            // Double-tap detection
            const currentTime = new Date().getTime();
            const tapLength = currentTime - lastTapTime;

            const currentImage = document.getElementById(`pageImage_${currentPageIndex}`);
            const fitToWidthScale = currentImage && currentImage.naturalWidth ? (imageViewport.clientWidth / currentImage.naturalWidth) : 0;
            const isZoomedIn = zoomLevel > fitToWidthScale + MIN_ZOOM_LEVEL_THRESHOLD;

            if (tapLength < DOUBLE_TAP_DELAY && tapLength > 0) {
                // Double tap detected
                e.preventDefault();
                if (!currentImage || !currentImageNaturalWidth) return;

                if (isZoomedIn) { // If currently zoomed in, reset zoom
                    zoomLevel = fitToWidthScale;
                    updateImageDisplay();
                    clampScroll(); // Center or reset scroll after zooming out
                    showMessageBox('Zoom reset to fit width.', 'success');
                } else { // If currently zoomed out or fit-to-width, zoom in to the tapped area
                    const tapX = e.changedTouches[0].clientX - imageViewport.getBoundingClientRect().left;
                    const tapY = e.changedTouches[0].clientY - imageViewport.getBoundingClientRect().top;

                    // Calculate coordinates relative to the *natural* image size
                    const imageX_natural = (tapX + imageViewport.scrollLeft) / zoomLevel;
                    const imageY_natural = (tapY + imageViewport.scrollTop) / zoomLevel;

                    // Target zoom level: 0.4 times the natural size (1.0 is natural size)
                    let targetZoom = 0.4; // Changed as requested

                    // Ensure targetZoom is not less than the fitToWidthScale
                    // If 0.4x natural is smaller than fit-to-width (e.g., if image is very large),
                    // then we should zoom to fit-to-width, or slightly more than fit-to-width
                    // to make a noticeable change.
                    if (targetZoom < fitToWidthScale) {
                        targetZoom = fitToWidthScale * 0.4; // Changed as requested
                    }

                    // Ensure targetZoom does not exceed MAX_ZOOM_LEVEL
                    zoomLevel = Math.min(targetZoom, MAX_ZOOM_LEVEL);

                    console.log('Double tap zoomLevel:', zoomLevel); // Log the zoom level after double tap

                    updateImageDisplay(); // Apply new zoomLevel to image style

                    // Calculate new scroll position to center the tapped point
                    const newScrollX = (imageX_natural * zoomLevel) - (imageViewport.clientWidth / 2);
                    const newScrollY = (imageY_natural * zoomLevel) - (imageViewport.clientHeight / 2);

                    // Apply and clamp scroll values
                    imageViewport.scrollLeft = newScrollX;
                    imageViewport.scrollTop = newScrollY;
                    clampScroll(); // Ensure scroll is valid after setting specific scroll

                    showMessageBox('Zoomed in to tapped area.', 'success');
                }
                lastTapTime = 0; // Reset last tap time after a double tap
            }

            // Horizontal Swipe detection for page navigation (only if not zoomed in)
            const touchEndY = e.changedTouches[0].clientY;
            const touchEndX = e.changedTouches[0].clientX;
            const deltaY = touchEndY - touchStartY;
            const deltaX = touchEndX - touchStartX;

            // Check for horizontal swipe for page navigation when not zoomed in
            if (!isZoomedIn && Math.abs(deltaX) > swipeMinDistance && Math.abs(deltaY) < swipeMaxVerticalDeviationForHorizontalSwipe) {
                e.preventDefault(); // Prevent default browser scroll if our swipe handles it.
                if (deltaX < 0) { // Swiped left (next page)
                    if (currentPageIndex < editionImages.length - 1) {
                        currentPageIndex++;
                        renderPage(currentPageIndex);
                        playPageTurnFeedback('next'); // Play next page sound/vibration
                        showMessageBox(`Page ${currentPageIndex + 1} / ${editionImages.length}`, 'success', 1000);
                    } else {
                        showMessageBox('Already on the last page.', 'error', 1000);
                    }
                } else if (deltaX > 0) { // Swiped right (previous page)
                    if (currentPageIndex > 0) {
                        currentPageIndex--;
                        renderPage(currentPageIndex);
                        playPageTurnFeedback('prev'); // Play previous page sound/vibration
                        showMessageBox(`Page ${currentPageIndex + 1} / ${editionImages.length}`, 'success', 1000);
                    } else {
                        showMessageBox('Already on the first page.', 'error', 1000);
                    }
                }
            }
        }
    });


    /**
     * Calculates the distance between two touch points for pinch zoom.
     * @param {TouchList} touches
     * @returns {number} Distance between touches.
     */
    function getPinchDistance(touches) {
        const dx = touches[0].clientX - touches[1].clientX;
        const dy = touches[0].clientY - touches[1].clientY;
        return Math.sqrt(dx * dx + dy * dy);
    }


    // Date Picker Change (Desktop)
    datePicker.addEventListener('change', function() {
        // This will now trigger the PHP redirect logic on page reload
        // The PHP script will check edition count for this specific date and redirect if > 1
        window.location.href = `index.php?date=${this.value}`;
    });

    // Date Picker Button (Mobile) - triggers the desktop date picker click
    mobileDatePickerBtn.addEventListener('click', () => {
        datePicker.click();
        datePicker.showPicker();
    });


    // --- START Cropper.js Integration ---

    // Function to position buttons just above the selection tool
    function positionCropperButtons() {
        if (!cropper) {
            cropperButtons.classList.remove('visible');
            return;
        }

        const cropBoxData = cropper.getCropBoxData();
        const margin = 5;

        let targetTop = cropBoxData.top - cropperButtons.offsetHeight - margin;
        let targetLeft = cropBoxData.left + cropBoxData.width - cropperButtons.offsetWidth - margin;

        cropperButtons.style.left = `${targetLeft}px`;
        cropperButtons.style.top = `${targetTop}px`;

        cropperButtons.classList.add('visible');
    }

    // Toggle Crop Mode Button click (Desktop)
    toggleCropModeBtn.addEventListener('click', () => {
        if (editionImages.length === 0) {
            console.warn('No e-paper page available for cropping.');
            return;
        }

        const currentImageElement = document.getElementById(`pageImage_${currentPageIndex}`);
        if (!currentImageElement || currentImageElement.style.display === 'none') {
            console.error('Current page image element not found or not displayed.');
            return;
        }

        if (cropper) {
            cropper.destroy();
            cropper = null;
            cropperButtons.classList.remove('visible');
            disableControlsForCrop(false);
            imageViewport.classList.remove('cropper-active');
        } else {
            cropper = new Cropper(currentImageElement, {
                aspectRatio: NaN,
                viewMode: 0,
                autoCropArea: 0.8,
                background: false,
                zoomable: false, // Cropper.js built-in zoom
                movable: false, // Cropper.js built-in move
                rotatable: false,
                scalable: false,
                highlight: false,
                cropBoxMovable: true,
                cropBoxResizable: true,
                ready() {
                    positionCropperButtons();
                },
                cropmove() {
                    positionCropperButtons();
                },
                cropend() {
                    positionCropperButtons();
                }
            });
            disableControlsForCrop(true);
            imageViewport.classList.add('cropper-active');
            positionCropperButtons();
        }
    });

    // Toggle Crop Mode Button click (Mobile) - points to the same logic
    mobileToggleCropModeBtn.addEventListener('click', () => {
        toggleCropModeBtn.click();
    });

    /**
     * Composes the final image with logo and text info.
     * @param {HTMLCanvasElement} croppedCanvas The canvas with the cropped image.
     * @returns {Promise<HTMLCanvasElement>} A promise that resolves with the final composed canvas.
     */
    async function composeFinalImage(croppedCanvas) {
        const originalCroppedWidth = croppedCanvas.width;
        const originalCroppedHeight = croppedCanvas.height;

        // Define heights for header (logo) and footer (text info)
        const HEADER_HEIGHT = 200; // Increased Height for the logo section
        const FOOTER_HEIGHT = 150; // Increased Height for URL, date, page number

        // Create a new canvas to combine all elements
        const finalCanvas = document.createElement('canvas');
        finalCanvas.width = originalCroppedWidth;
        finalCanvas.height = originalCroppedHeight + HEADER_HEIGHT + FOOTER_HEIGHT;
        const ctx = finalCanvas.getContext('2d');


        // Draw background for the header (logo section)
        ctx.fillStyle = '#f3f4f6'; // Light gray background for header
        ctx.fillRect(0, 0, finalCanvas.width, HEADER_HEIGHT);

        // Draw the logo
        const logoImg = new Image();
        logoImg.src = logoPath; // Use the PHP-injected logoPath
        await new Promise(resolve => {
            logoImg.onload = resolve;
            logoImg.onerror = () => {
                console.warn('Failed to load logo image for composite image. Skipping logo.');
                resolve(); // Resolve anyway to not block the process
            };
        });

        if (logoImg.complete && logoImg.naturalWidth > 0) {
            const desiredLogoWidth = 400; // Desired width for the logo - INCREASED TO 400
            const logoAspectRatio = logoImg.naturalWidth / logoImg.naturalHeight;
            let logoDrawWidth = desiredLogoWidth;
            let logoDrawHeight = desiredLogoWidth / logoAspectRatio;

            // Ensure logo doesn't exceed the header height or canvas width
            if (logoDrawHeight > HEADER_HEIGHT - 20) { // Adjust for padding
                logoDrawHeight = HEADER_HEIGHT - 20;
                logoDrawWidth = logoDrawHeight * logoAspectRatio;
            }
            if (logoDrawWidth > finalCanvas.width - 20) { // Adjust for padding
                logoDrawWidth = finalCanvas.width - 20;
                logoDrawHeight = logoDrawWidth / logoAspectRatio;
            }

            const logoX = (finalCanvas.width - logoDrawWidth) / 2; // Center horizontally
            const logoY = (HEADER_HEIGHT - logoDrawHeight) / 2; // Center vertically within header
            ctx.drawImage(logoImg, logoX, logoY, logoDrawWidth, logoDrawHeight);
        }

        // Draw the cropped image below the header
        ctx.drawImage(croppedCanvas, 0, HEADER_HEIGHT);

        // Draw background for the footer (text info section)
        ctx.fillStyle = '#f3f4f6'; // Light gray background for footer
        ctx.fillRect(0, HEADER_HEIGHT + originalCroppedHeight, finalCanvas.width, FOOTER_HEIGHT);

        // Text information
        const pageUrl = "<?php echo htmlspecialchars(defined('APP_SITE_URL') ? APP_SITE_URL : ($protocol . '://' . $host)); ?>"; // Use APP_SITE_URL or dynamically generated
        const appTitle = "<?php echo htmlspecialchars(defined('APP_TITLE') ? APP_TITLE : 'E-Paper'); ?>"; // Use APP_TITLE

        // Format date to DD-MM-YYYY
        const dateParts = selectedDate.split('-');
        const formattedDate = `${dateParts[2]}-${dateParts[1]}-${dateParts[0]}`;

        const pageNumText = `Page: ${currentPageIndex + 1}`; // Show only current page

        ctx.fillStyle = 'black';
        ctx.font = '20px Inter, sans-serif'; // Increased font size for better visibility
        ctx.textAlign = 'left'; // Align text to the left

        const textStartX = 10; // Padding from left
        let textY = HEADER_HEIGHT + originalCroppedHeight + 30; // Start Y position in the footer, adjusted for larger font/spacing

        ctx.fillText(`Website: ${pageUrl}`, textStartX, textY); // Changed "URL" to "Website"
        textY += 30; // Move to next line (approx line height)
        ctx.fillText(`Date: ${formattedDate}`, textStartX, textY); // Added "Date:" prefix and used formatted date
        textY += 30; // Move to next line
        ctx.fillText(pageNumText, textStartX, textY); // Updated to show only current page

        return finalCanvas;
    }


    // Download Cropped Image
    cropDownloadBtn.addEventListener('click', async () => {
        if (cropper) {
            // FIX: Corrected typo from getCropppedCanvas() to getCroppedCanvas()
            const croppedCanvas = cropper.getCroppedCanvas();
            const finalImageCanvas = await composeFinalImage(croppedCanvas); // Await the composition

            const imageDataURL = finalImageCanvas.toDataURL('image/png');
            // Format: Edition-name-publication-date-pagenumber-random-number.png
            const formattedDateForFilename = selectedDate.split('-').join(''); //YYYYMMDD
            const randomNumber = Math.floor(Math.random() * 9000) + 1000; // 4-digit random number
            const filename = `${editionName.replace(/\s+/g, '-')}-${formattedDateForFilename}-${currentPageIndex + 1}-${randomNumber}.png`;


            const downloadLink = document.createElement('a');
            downloadLink.href = imageDataURL;
            downloadLink.download = filename; // Updated filename
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);

            cropper.destroy();
            cropper = null;
            cropperButtons.classList.remove('visible');
            disableControlsForCrop(false);
            imageViewport.classList.remove('cropper-active');
            showMessageBox('Cropped image downloaded successfully!', 'success');
        } else {
            showMessageBox('Cropper not active for download.', 'error');
        }
    });

    // Share Cropped Image (copies image data URL to clipboard or uses Web Share API)
    const handleShareCrop = async () => {
        if (cropper) {
            // Logic for sharing cropped image
            const croppedCanvas = cropper.getCropCanvas();
            const finalImageCanvas = await composeFinalImage(croppedCanvas); // Await the composition

            try {
                const blob = await new Promise(resolve => finalImageCanvas.toBlob(resolve, 'image/png', 1));
                const fileName = `cropped-epaper-page-${currentPageIndex + 1}.png`;
                const file = new File([blob], fileName, { type: 'image/png' });

                if (navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
                    try {
                        await navigator.share({
                            files: [file],
                            title: 'E-Paper Cropped Image',
                            text: `Check out this cropped image from page ${currentPageIndex + 1} of the E-Paper!`
                        });
                        showMessageBox('Cropped image shared successfully!', 'success');
                    } catch (error) {
                        if (error.name === 'AbortError') {
                            showMessageBox('Image share canceled.', 'error');
                        } else {
                            showMessageBox('Error sharing cropped image. Falling back to clipboard.', 'error');
                            console.error('Error sharing cropped image:', error);
                            fallbackToClipboard(finalImageCanvas); // This fallback is for images
                        }
                    }
                } else {
                    showMessageBox('Native share not available. Copying cropped image to clipboard.', 'error');
                    fallbackToClipboard(finalImageCanvas); // This fallback is for images
                }
            } catch (error) {
                showMessageBox('Error preparing cropped image for sharing.', 'error');
                console.error('Error preparing cropped image for sharing:', error);
            } finally {
                cropper.destroy();
                cropper = null;
                cropperButtons.classList.remove('visible');
                disableControlsForCrop(false);
                imageViewport.classList.remove('cropper-active');
            }
        } else {
            // Logic for sharing current page URL
            const urlToShare = window.location.href; // This will now be the canonical URL if an edition is loaded
            const titleToShare = document.title;
            const textToShare = `Check out this E-Paper page: ${titleToShare}`;

            if (navigator.share) {
                try {
                    await navigator.share({
                        title: titleToShare,
                        text: textToShare,
                        url: urlToShare,
                    });
                    showMessageBox('Page URL shared successfully!', 'success');
                } catch (error) {
                    if (error.name === 'AbortError') {
                        showMessageBox('Page URL share canceled.', 'error');
                    } else {
                        showMessageBox('Error sharing page URL. Falling back to clipboard.', 'error');
                        console.error('Error sharing page URL:', error);
                        // Fallback to clipboard for URL
                        copyTextToClipboard(urlToShare);
                    }
                }
            } else {
                showMessageBox('Native share not available. Copying page URL to clipboard.', 'error');
                copyTextToClipboard(urlToShare);
            }
        }
    };
    shareCropBtn.addEventListener('click', handleShareCrop);
    mobileShareCropBtn.addEventListener('click', handleShareCrop);


    /**
     * Fallback function to copy image data URL to clipboard if native share is not available.
     * @param {HTMLCanvasElement} canvas The canvas element containing the image to copy.
     */
    function fallbackToClipboard(canvas) {
        // This is a fallback for copying an image (not just text)
        // This is more complex and not universally supported via document.execCommand
        // For images, a common approach is to provide instructions to the user to right-click/long-press and save/copy.
        // Or, for simple data, convert to base64 and copy as text (but that's not an image copy).
        // Given the limitations of iframes and clipboard API for images, we'll try a text fallback for now,
        // and ideally, rely on the Web Share API.
        const imageDataURL = canvas.toDataURL('image/png');
        // For actual image copy to clipboard, `navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })])` is needed
        // which might not work in iframe without user gesture or proper permissions.
        // As a simple workaround for browsers that might support it for image data URLs in textarea (rare but some might try):
        const textArea = document.createElement('textarea');
        textArea.value = imageDataURL; // This will just copy the base64 string
        document.body.appendChild(textArea);
        textArea.select();
        try {
            document.execCommand('copy');
            showMessageBox('Image data (base64) copied to clipboard! You may need to paste into an image editor.', 'success', 5000);
        } catch (fallbackErr) {
            showMessageBox('Could not copy image to clipboard manually. Please try using native share if available.', 'error', 5000);
            console.error('Could not copy image to clipboard manually:', fallbackErr);
        } finally {
            document.body.removeChild(textArea);
        }
    }


    /**
     * Function to copy text to clipboard.
     * @param {string} text The text to copy.
     */
    function copyTextToClipboard(text) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        try {
            document.execCommand('copy');
            showMessageBox('Page URL copied to clipboard!', 'success');
        } catch (err) {
            showMessageBox('Could not copy page URL to clipboard.', 'error');
            console.error('Could not copy text to clipboard manually:', err);
        } finally {
            document.body.removeChild(textArea);
        }
    }

    // Cancel Cropping
    cancelCropBtn.addEventListener('click', () => {
        if (cropper) {
            cropper.destroy();
            cropper = null;
            cropperButtons.classList.remove('visible');
            disableControlsForCrop(false);
            imageViewport.classList.remove('cropper-active');
            showMessageBox('Cropping canceled.', 'error');
        }
    });

    // Event listener for the Escape key to cancel cropping
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && cropper) {
            cropper.destroy();
            cropper = null;
            cropperButtons.classList.remove('visible');
            disableControlsForCrop(false);
            imageViewport.classList.remove('cropper-active');
            showMessageBox('Cropping canceled by Escape key.', 'error');
        }
    });

    // --- END Cropper.js Integration ---

    // --- START Full Screen Integration ---
    fullScreenBtn.addEventListener('click', () => {
        const element = document.documentElement;

        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else {
            if (element.requestFullscreen) {
                element.requestFullscreen();
            } else if (element.mozRequestFullScreen) {
                element.mozRequestFullScreen();
            } else if (element.webkitRequestFullscreen) {
                element.webkitRequestFullscreen();
            } else if (element.msRequestFullscreen) {
                element.msRequestFullscreen();
            }
        }
    });

    // Listen for fullscreen change events to update the button text/icon and viewport size
    document.addEventListener('fullscreenchange', () => {
        if (document.fullscreenElement) {
            fullScreenBtn.innerHTML = '<i class="fas fa-compress-arrows-alt"></i> Exit Full Screen';
        } else {
            fullScreenBtn.innerHTML = '<i class="fas fa-expand-arrows-alt"></i> Full Screen';
        }
        resetZoom(false); // Do not show info message when fullscreen changes
    });
    // --- END Full Screen Integration ---

    // Handle resize events to re-render and adjust zoom/scroll
    window.addEventListener('resize', () => {
        if (cropper) {
            cropper.destroy();
            cropper = null;
            cropperButtons.classList.remove('visible');
            disableControlsForCrop(false);
            imageViewport.classList.remove('cropper-active');
        }
        renderPage(currentPageIndex);
    });

    // --- Initial Load ---
    if (editionImages.length > 0) {
        generateThumbnails();
        const firstImage = document.getElementById('pageImage_0');
        if (firstImage) {
            if (firstImage.complete) {
                currentImageNaturalWidth = firstImage.naturalWidth;
                currentImageNaturalHeight = firstImage.naturalHeight;
                renderPage(currentPageIndex);
            } else {
                firstImage.onload = () => {
                    currentImageNaturalWidth = firstImage.naturalWidth;
                    currentImageNaturalHeight = firstImage.naturalHeight;
                    renderPage(currentPageIndex);
                };
            }
        } else {
            console.error("No image elements found despite editionImages array not being empty.");
            disableAllControls(true);
        }
    } else {
        disableAllControls(true);
        imageViewport.style.display = 'none';
    }
});
</script>
