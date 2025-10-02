<?php
// File: upload-edition.php
// Allows SuperAdmins and Admins to upload new edition details and PDF files.

// --- HTTP Security Headers (Place this at the very top before any output) ---
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Configure secure session cookies before starting the session
ini_set('session.cookie_httponly', 1);
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
}

// Start session at the very beginning
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure BASE_PATH is defined using realpath for a clean, absolute path
if (!defined('BASE_PATH')) {
    define('BASE_PATH', realpath(__DIR__ . '/..'));
}

// Include database configuration and variables
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/vars/logovars.php';


// Set default timezone to Asia/Kolkata (IST) for consistent date/time handling
date_default_timezone_set('Asia/Kolkata');

// --- CSRF Token Generation ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- Define Logged-in State and User Role ---
$loggedIn = isset($_SESSION['user_id']);
$username = $loggedIn ? htmlspecialchars($_SESSION['username']) : null;
$userRole = $loggedIn ? ($_SESSION['user_role'] ?? 'Viewer') : null;
$uploaderUserId = $loggedIn ? $_SESSION['user_id'] : null;

// Session Management & Inactivity Timeout
const INACTIVITY_TIMEOUT = 1800; // 30 minutes in seconds
if ($loggedIn && !isset($_SESSION['remember_me'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > INACTIVITY_TIMEOUT)) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-hourglass-end mr-2"></i> Session expired due to inactivity. Please log in again.</div>';
        header("Location: /login?timeout=1");
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// --- INITIAL AUTHORIZATION CHECKS ---
if (!$loggedIn) {
    $_SESSION['message'] = '<div class="alert alert-info"><i class="fas fa-info-circle mr-2"></i> Please log in to access this page.</div>';
    header("Location: /login");
    exit;
}

if ($userRole !== 'SuperAdmin' && $userRole !== 'Admin') {
    $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-lock mr-2"></i> You do not have the required permissions to access this page.</div>';
    header("Location: /dashboard"); // Redirect to a page they can access
    exit;
}

$pageTitle = "Upload New Edition";

// Fetch categories for the dropdown, ordered by default, then featured, then name
$categories = [];
try {
    $stmt = $pdo->query("SELECT category_id, name, is_default, is_featured FROM categories ORDER BY is_default DESC, is_featured DESC, name ASC");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching categories: " . $e->getMessage());
    $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Error loading categories.</div>';
}


// Function to display session messages
if (!function_exists('display_session_message')) {
    function display_session_message() {
        if (isset($_SESSION['message'])) {
            echo $_SESSION['message'];
            unset($_SESSION['message']);
        }
    }
}

// --- Configuration for ImageMagick and Thumbnail Sizes ---
const GS_BIN_DIR = '/usr/bin';
const IMAGEMAGICK_HOME_DIR = '/usr';
const MAGICK_EXE_PATH = '/usr/bin/convert';
const OG_THUMB_WIDTH = 1200;
const OG_THUMB_HEIGHT = 600;
const LIST_THUMB_HEIGHT = 1200;

// --- Handle Form Submission (AJAX POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    error_reporting(E_ALL);

    // --- CSRF Token Validation ---
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token. Please refresh and try again.']);
        exit;
    }


    $title = trim($_POST['title'] ?? '');
    $publication_date = trim($_POST['publication_date'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $description = trim($_POST['description'] ?? '');
    $description = ($description === '') ? null : $description;
    
    $status = trim($_POST['status'] ?? 'Private');
    $schedule_date = trim($_POST['schedule_date'] ?? '');
    $schedule_time = trim($_POST['schedule_time'] ?? '');

    $scheduled_at = null; // Will hold the DATETIME for scheduled posts

    if ($status === 'Scheduled') {
        if (empty($schedule_date) || empty($schedule_time)) {
            echo json_encode(['success' => false, 'message' => 'Scheduled date and time are required for a scheduled post.']);
            exit;
        }
        // Combine date and time for the scheduled_at column
        $scheduled_at = $schedule_date . ' ' . $schedule_time . ':00';
    }

    // Validate status value
    if (!in_array($status, ['Published', 'Private', 'Scheduled'])) {
        $status = 'Private'; // Default to Private for security
    }

    $pdf_file = $_FILES['pdf_file'] ?? null;

    if (empty($title)) {
        echo json_encode(['success' => false, 'message' => 'Edition title is required.']);
        exit;
    }
    if (empty($publication_date) || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $publication_date)) {
        echo json_encode(['success' => false, 'message' => 'Publication date is required and must be in YYYY-MM-DD format.']);
        exit;
    }
    if ($category_id === null) {
        echo json_encode(['success' => false, 'message' => 'Category is required.']);
        exit;
    }
    if (!$pdf_file || $pdf_file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'PDF file upload failed or no file was selected.']);
        exit;
    }
    if ($pdf_file['type'] !== 'application/pdf') {
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Only PDF files are allowed.']);
        exit;
    }
    if ($pdf_file['size'] > 50 * 1024 * 1024) { // 50MB limit
        echo json_encode(['success' => false, 'message' => 'File size exceeds 50MB limit.']);
        exit;
    }

    $pdo->beginTransaction();

    try {
        // Use DIRECTORY_SEPARATOR for better cross-platform compatibility
        $ds = DIRECTORY_SEPARATOR;
        $base_upload_dir_segment = 'uploads' . $ds . 'editions' . $ds;
        
        $pub_date_obj = DateTime::createFromFormat('Y-m-d', $publication_date);
        $pub_year = $pub_date_obj->format('Y');
        $pub_month = $pub_date_obj->format('m');
        $pub_day = $pub_date_obj->format('d');
        $current_time_hhmmss = date('His');

        $unique_folder_name = "{$publication_date}_{$current_time_hhmmss}_" . uniqid();
        $date_sub_path = $pub_year . $ds . $pub_month . $ds . $pub_day . $ds;
        
        // Construct a clean, absolute path for the new edition directory
        $full_edition_dir = BASE_PATH . $ds . $base_upload_dir_segment . $date_sub_path . $unique_folder_name . $ds;

        if (!is_dir($full_edition_dir) && !mkdir($full_edition_dir, 0755, true)) {
            throw new Exception('Failed to create directory for edition.');
        }

        $pdf_file_name = 'edition-' . $pub_date_obj->format('d-m-Y') . '.pdf';
        $pdf_target_path = $full_edition_dir . $pdf_file_name;
        if (!move_uploaded_file($pdf_file['tmp_name'], $pdf_target_path)) {
            throw new Exception('Failed to move uploaded PDF file.');
        }
        
        // Construct the web-accessible path, relative to the web root (uses forward slashes)
        $pdf_web_path = '/../' . str_replace($ds, '/', $base_upload_dir_segment . $date_sub_path . $unique_folder_name . '/') . $pdf_file_name;
        chmod($pdf_target_path, 0644);

        $images_dir = $full_edition_dir . 'images' . $ds;
        if (!is_dir($images_dir) && !mkdir($images_dir, 0755)) {
            throw new Exception('Failed to create images directory.');
        }

        $original_path = getenv('PATH');
        putenv('PATH=' . GS_BIN_DIR . PATH_SEPARATOR . $original_path);
        putenv('MAGICK_HOME=' . IMAGEMAGICK_HOME_DIR);

        $magick_exe_escaped = escapeshellarg(MAGICK_EXE_PATH);
        $pdf_target_path_escaped = escapeshellarg($pdf_target_path);
        $temp_images_pattern = $images_dir . 'temp_raw_%03d.jpg';
        $temp_images_output_escaped = escapeshellarg($temp_images_pattern);

        $command = $magick_exe_escaped . ' -density 180 ' . $pdf_target_path_escaped . ' -quality 85 -scene 1 ' . $temp_images_output_escaped . ' 2>&1';
        // Log the command for debugging purposes
        error_log("Attempting ImageMagick command: " . $command);
        exec($command, $output, $return_var);

        if ($return_var !== 0) {
            putenv('PATH=' . $original_path);
            throw new Exception("PDF conversion failed: " . implode("\n", $output));
        }

        $generated_temp_files = glob($images_dir . 'temp_raw_*.jpg');
        if (empty($generated_temp_files)) {
            putenv('PATH=' . $original_path);
            throw new Exception("Image conversion completed but no temporary image files were found.");
        }

        $page_count = 0;
        $first_page_server_path = null;
        foreach ($generated_temp_files as $temp_image_file) {
            $page_count++;
            $final_image_name = sprintf('page-%d.jpg', $page_count);
            $final_image_path = $images_dir . $final_image_name;
            rename($temp_image_file, $final_image_path);
            chmod($final_image_path, 0644);
            if ($page_count === 1) {
                $first_page_server_path = $final_image_path;
            }
        }

        $og_image_web_path = null;
        $list_thumb_web_path = null;
        if ($first_page_server_path) {
            $og_image_name = 'og-thumb.jpg';
            $og_image_server_path = $images_dir . $og_image_name;
            $og_image_web_path = '/../' . str_replace($ds, '/', $base_upload_dir_segment . $date_sub_path . $unique_folder_name . '/images/') . $og_image_name;
            
            $command_og = $magick_exe_escaped . ' ' . escapeshellarg($first_page_server_path) . ' -resize ' . OG_THUMB_WIDTH . 'x -gravity North -crop ' . OG_THUMB_WIDTH . 'x' . OG_THUMB_HEIGHT . '+0+0 +repage -quality 85 ' . escapeshellarg($og_image_server_path) . ' 2>&1';
            exec($command_og, $output_og, $return_var_og);
            if ($return_var_og === 0) {
                chmod($og_image_server_path, 0644);
            } else {
                $og_image_web_path = null;
            }

            $list_thumb_name = 'list-thumb.jpg';
            $list_thumb_server_path = $images_dir . $list_thumb_name;
            $list_thumb_web_path = '/../' . str_replace($ds, '/', $base_upload_dir_segment . $date_sub_path . $unique_folder_name . '/images/') . $list_thumb_name;

            $command_list_thumb = $magick_exe_escaped . ' ' . escapeshellarg($first_page_server_path) . ' -resize x' . LIST_THUMB_HEIGHT . ' -quality 85 ' . escapeshellarg($list_thumb_server_path) . ' 2>&1';
            exec($command_list_thumb, $output_list_thumb, $return_var_list_thumb);
            if ($return_var_list_thumb === 0) {
                chmod($list_thumb_server_path, 0644);
            } else {
                $list_thumb_web_path = null;
            }
        }
        putenv('PATH=' . $original_path);

        $stmt = $pdo->prepare("INSERT INTO editions (title, publication_date, scheduled_at, category_id, description, pdf_path, og_image_path, list_thumb_path, page_count, file_size_bytes, uploader_user_id, status, created_at, updated_at) VALUES (:title, :publication_date, :scheduled_at, :category_id, :description, :pdf_path, :og_image_path, :list_thumb_path, :page_count, :file_size_bytes, :uploader_user_id, :status, NOW(), NOW())");
        $stmt->execute([
            ':title' => $title, 
            ':publication_date' => $publication_date, 
            ':scheduled_at' => $scheduled_at,
            ':category_id' => $category_id, 
            ':description' => $description, 
            ':pdf_path' => $pdf_web_path, 
            ':og_image_path' => $og_image_web_path, 
            ':list_thumb_path' => $list_thumb_web_path, 
            ':page_count' => $page_count, 
            ':file_size_bytes' => $pdf_file['size'], 
            ':uploader_user_id' => $uploaderUserId, 
            ':status' => $status
        ]);
        $editionId = $pdo->lastInsertId();

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Edition uploaded successfully!', 'edition_id' => $editionId, 'redirect' => '/manage-editions']);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Edition upload failed: " . $e->getMessage());
        if (isset($full_edition_dir) && is_dir($full_edition_dir)) {
            function rrmdir($dir) {
                if (is_dir($dir)) {
                    $objects = scandir($dir);
                    foreach ($objects as $object) {
                        if ($object != "." && $object != "..") {
                            if (is_dir($dir.DIRECTORY_SEPARATOR.$object)) rrmdir($dir.DIRECTORY_SEPARATOR.$object); else unlink($dir.DIRECTORY_SEPARATOR.$object);
                        }
                    }
                    rmdir($dir);
                }
            }
            rrmdir($full_edition_dir);
        }
        echo json_encode(['success' => false, 'message' => 'An error occurred during the upload process: ' . $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Edition Management</title>
    <link rel="icon" href="<?= htmlspecialchars($faviconPath ?? '/favicon.ico') ?>" type="image/x-icon">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.11.338/pdf.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
        }
        .dark body {
            background-color: #111827;
        }
        .progress-bar-bg {
            background-color: #e5e7eb;
        }
        .dark .progress-bar-bg {
             background-color: #374151;
        }
        .progress-fill {
            background-color: #4f46e5;
            border-radius: 9999px;
            transition: width 0.3s ease-in-out, background-color 0.3s ease-in-out;
            height: 100%;
        }
        .form-input {
            width: 100%;
            padding: 0.625rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            transition: border-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }
        .dark .form-input {
            background-color: #374151;
            border-color: #4b5563;
            color: #d1d5db;
        }
        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }
        .dark .form-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3);
        }
        .pdf-preview-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 100%;
            height: 100%;
            overflow: hidden;
            position: relative;
        }
        #pdf-preview-canvas {
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
         .dark #pdf-preview-canvas {
            border-color: #4b5563;
        }
        select {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            padding-right: 2.5rem; /* Space for the icon */
        }
        input[type="date"]::-webkit-calendar-picker-indicator {
            display: none;
            -webkit-appearance: none;
            margin: 0;
        }
         input[type="time"]::-webkit-calendar-picker-indicator {
            display: none;
            -webkit-appearance: none;
            margin: 0;
        }
        .pdf-nav-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            z-index: 10;
            background-color: rgba(0, 0, 0, 0.3);
            color: white;
            border-radius: 9999px;
            width: 2rem;
            height: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .pdf-nav-btn:hover {
            background-color: rgba(0, 0, 0, 0.5);
        }
        .pdf-nav-btn:disabled {
            background-color: rgba(0, 0, 0, 0.1);
            cursor: not-allowed;
        }
    </style>
</head>
<body class="bg-gray-100 flex flex-col min-h-screen">

<?php require_once BASE_PATH . '/layout/headersidebar.php'; ?>

<main class="flex-1 py-1 px-4 md:p-1 md:ml-0">
    <div class="max-w-7xl mx-auto">
        <?php display_session_message(); ?>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
             <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Left Column: Form -->
                <div class="lg:col-span-1">
                     <div class="flex items-center gap-2 mb-4 justify-center md:justify-start">
                        <i class="fa-solid fa-cloud-arrow-up text-xl text-indigo-900 dark:text-indigo-400"></i>
                        <h1 class="text-xl font-bold text-gray-800 dark:text-gray-100"><?= htmlspecialchars($pageTitle) ?></h1>
                    </div>
                    <hr class="mb-6 border-gray-200 dark:border-gray-700">
                    <form id="uploadForm" method="POST" enctype="multipart/form-data" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700 dark:text-gray-300 flex items-center mb-1 gap-1.5">
                                <i class="fa-solid fa-newspaper text-indigo-900 dark:text-indigo-400 w-4 text-center text-sm"></i>Edition Title
                                <span class="text-red-500 ml-1">*</span>
                            </label>
                            <input type="text" name="title" id="title" required class="form-input">
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="publication_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 flex items-center mb-1 gap-1.5">
                                    <i class="fa-solid fa-calendar-days text-indigo-900 dark:text-indigo-400 w-4 text-center text-sm"></i>Publication Date
                                    <span class="text-red-500 ml-1">*</span>
                                </label>
                                <div class="relative cursor-pointer">
                                    <input type="date" name="publication_date" id="publication_date" value="<?= date('Y-m-d') ?>" required class="form-input pr-10">
                                     <i class="fa-solid fa-calendar-alt text-indigo-900 dark:text-indigo-400 absolute right-3 top-1/2 transform -translate-y-1/2"></i>
                                </div>
                            </div>
                            <div>
                                <label for="category_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 flex items-center mb-1 gap-1.5">
                                    <i class="fa-solid fa-folder-open text-indigo-900 dark:text-indigo-400 w-4 text-center text-sm"></i>Category
                                    <span class="text-red-500 ml-1">*</span>
                                </label>
                                <div class="relative">
                                    <select name="category_id" id="category_id" required class="form-input">
                                        <option value="">Select a Category</option>
                                        <?php 
                                            $default_categories = array_filter($categories, function($c) { return $c['is_default']; });
                                            $featured_categories = array_filter($categories, function($c) { return $c['is_featured'] && !$c['is_default']; });
                                            $other_categories = array_filter($categories, function($c) { return !$c['is_featured'] && !$c['is_default']; });
                                        ?>
                                        <?php if(!empty($default_categories)): ?>
                                            <optgroup label="Default">
                                            <?php foreach ($default_categories as $category): ?>
                                                <option value="<?= htmlspecialchars($category['category_id']) ?>" selected><?= htmlspecialchars($category['name']) ?></option>
                                            <?php endforeach; ?>
                                            </optgroup>
                                        <?php endif; ?>
                                         <?php if(!empty($featured_categories)): ?>
                                            <optgroup label="Featured">
                                            <?php foreach ($featured_categories as $category): ?>
                                                <option value="<?= htmlspecialchars($category['category_id']) ?>"><?= htmlspecialchars($category['name']) ?></option>
                                            <?php endforeach; ?>
                                            </optgroup>
                                        <?php endif; ?>
                                         <?php if(!empty($other_categories)): ?>
                                            <optgroup label="Other">
                                            <?php foreach ($other_categories as $category): ?>
                                                <option value="<?= htmlspecialchars($category['category_id']) ?>"><?= htmlspecialchars($category['name']) ?></option>
                                            <?php endforeach; ?>
                                            </optgroup>
                                        <?php endif; ?>
                                    </select>
                                    <i class="fa-solid fa-chevron-down text-indigo-900 dark:text-indigo-400 absolute right-3 top-1/2 transform -translate-y-1/2 pointer-events-none"></i>
                                </div>
                            </div>
                        </div>

                        <div class="hidden md:block">
                             <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 flex items-center mb-1 gap-1.5">
                                <i class="fa-solid fa-file-lines text-indigo-900 dark:text-indigo-400 w-4 text-center text-sm"></i>Description
                            </label>
                            <textarea id="description" rows="1" class="form-input"></textarea>
                        </div>
                         <input type="hidden" name="description" id="description_hidden">
                        
                        <div>
                            <div class="text-sm font-medium text-gray-700 dark:text-gray-300 flex items-center shrink-0 gap-1.5 mb-2">
                                <i class="fa-solid fa-circle-check text-indigo-900 dark:text-indigo-400 w-4 text-center text-sm"></i>Status<span class="text-red-500 ml-1">*</span>
                            </div>
                            <div class="relative">
                                <select name="status" id="status_select" class="form-input">
                                    <option value="Published">Public</option>
                                    <option value="Private">Private</option>
                                    <option value="Scheduled">Schedule</option>
                                </select>
                                <i class="fa-solid fa-chevron-down text-indigo-900 dark:text-indigo-400 absolute right-3 top-1/2 transform -translate-y-1/2 pointer-events-none"></i>
                            </div>
                             <span id="schedule-display" class="text-xs text-indigo-700 dark:text-indigo-400 font-medium mt-2 block"></span>
                        </div>


                         <div>
                            <label for="pdf_file" class="block text-sm font-medium text-gray-700 dark:text-gray-300 flex items-center mb-1 gap-1.5">
                                <i class="fa-solid fa-file-pdf text-indigo-900 dark:text-indigo-400 w-4 text-center text-sm"></i>PDF
                                <span class="text-red-500 ml-1">*</span>
                            </label>
                            <label for="pdf_file" class="mt-1 flex justify-center items-center px-4 py-2.5 border-2 border-gray-300 dark:border-gray-600 border-dashed rounded-md cursor-pointer hover:border-indigo-500 bg-indigo-50 dark:bg-gray-700 transition-colors duration-200">
                                <div class="flex items-center gap-3">
                                    <i class="fa-solid fa-file-pdf text-xl text-red-500"></i>
                                    <div class="text-gray-600 dark:text-gray-400 text-left">
                                        <p class="hidden md:block text-xs">Drag & drop your PDF or <span class="font-semibold text-indigo-600 dark:text-indigo-400">click to browse</span> (Max 50MB)</p>
                                        <p class="md:hidden font-bold text-xs">Click to upload PDF (Max. 50MB)</p>
                                    </div>
                                </div>
                                <input type="file" name="pdf_file" id="pdf_file" class="sr-only" accept="application/pdf" required>
                            </label>
                            <p id="fileName" class="text-center text-xs text-gray-500 dark:text-gray-400 mt-2"></p>
                        </div>


                        <div class="flex justify-end space-x-3 pt-4">
                             <button type="button" id="clearBtn" class="inline-flex items-center justify-center w-10 h-10 md:w-auto md:px-4 md:py-2 border border-gray-300 dark:border-gray-500 shadow-sm text-sm font-medium rounded-md text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600">
                                <i class="fa-solid fa-eraser text-base md:mr-2"></i>
                                <span class="hidden md:inline">Clear</span>
                            </button>
                            <a href="/upload-edition" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-indigo-800 bg-indigo-200 hover:bg-indigo-300 dark:bg-indigo-900 dark:text-indigo-300 dark:hover:bg-indigo-800">
                                <i class="fa-solid fa-times mr-2"></i>
                                <span>Cancel</span>
                            </a>
                            <button type="submit" id="submitBtn" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-900 hover:bg-indigo-800 dark:bg-indigo-600 dark:hover:bg-indigo-700">
                                <i class="fa-solid fa-cloud-arrow-up mr-2"></i>
                                <span>Upload Edition</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Right Column: Info Panel -->
                <div class="lg:col-span-1 relative">
                    <!-- Divider -->
                    <div class="hidden lg:block absolute top-0 bottom-0 -left-4">
                         <div class="h-full border-l border-gray-200 dark:border-gray-700"></div>
                    </div>

                    <div>
                         <div id="preview-container" class="flex flex-col items-center text-center w-full max-w-md mx-auto">
                            
                            <div id="preview-placeholder" class="w-full h-[375px] bg-indigo-50 dark:bg-gray-700 rounded-lg flex flex-col items-center justify-center p-4 border border-indigo-100 dark:border-gray-600 mb-4">
                                <div class="bg-indigo-900 dark:bg-indigo-600 w-[100px] h-[100px] rounded-full flex items-center justify-center mb-4">
                                    <i class="fa-solid fa-file-image text-5xl text-white"></i>
                                </div>
                                <p class="text-slate-500 dark:text-gray-400 font-medium">(Please select PDF to preview)</p>
                            </div>

                            <div id="pdf-preview-container" class="hidden w-full h-[375px] bg-indigo-50 dark:bg-gray-700 rounded-lg p-2 mb-4">
                                <div class="pdf-preview-wrapper">
                                    <canvas id="pdf-preview-canvas"></canvas>
                                    <button id="prev-page" class="pdf-nav-btn left-2 hidden"><i class="fas fa-chevron-left"></i></button>
                                    <button id="next-page" class="pdf-nav-btn right-2 hidden"><i class="fas fa-chevron-right"></i></button>
                                    <div id="page-counter" class="absolute bottom-2 left-1/2 -translate-x-1/2 bg-black/50 text-white text-xs font-bold px-2 py-1 rounded-full hidden">
                                        <span id="page-num">0</span> / <span id="page-count">0</span>
                                    </div>
                                </div>
                            </div>


                            <h3 id="preview-main-title" class="text-lg font-bold text-gray-800 dark:text-gray-100 break-all mb-4">Edition Preview</h3>
                            
                            <div id="live-details-preview" class="w-full flex flex-wrap justify-center items-center gap-2 mb-4">
                                <div id="preview-date-chip" class="flex items-center bg-orange-100 text-orange-800 text-xs font-bold px-3 py-1 rounded-full">
                                    <i class="fa-solid fa-calendar-days text-sm mr-1.5"></i>
                                    <span id="preview-date-live">N/A</span>
                                </div>
                                <div id="preview-category-chip" class="flex items-center bg-indigo-100 text-indigo-800 text-xs font-bold px-3 py-1 rounded-full">
                                    <i class="fa-solid fa-folder-open text-sm mr-1.5"></i>
                                    <span id="preview-category-live">N/A</span>
                                </div>
                                <div id="preview-status-chip" class="flex items-center text-xs font-bold px-3 py-1 rounded-full">
                                    <i class="fa-solid fa-circle-check text-sm mr-1.5"></i>
                                    <span id="preview-status-live">N/A</span>
                                </div>
                            </div>

                             <div id="progressContainer" class="hidden w-full p-4 bg-indigo-50 dark:bg-gray-700 border border-indigo-200 dark:border-gray-600 rounded-lg mt-4">
                                <div class="flex items-center justify-between mb-2">
                                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 flex items-center gap-2">
                                        <i class="fa-solid fa-cloud-arrow-up text-xl text-indigo-900 dark:text-indigo-400"></i>
                                        <span>Upload Status</span>
                                    </h3>
                                    <i id="statusIcon" class="fa-solid fa-spinner fa-spin text-lg text-indigo-900 dark:text-indigo-400 hidden"></i>
                                </div>
                                <div class="h-2.5 w-full bg-gray-200 dark:bg-gray-600 rounded-full overflow-hidden progress-bar-bg">
                                    <div id="progressFill" class="progress-fill h-full" style="width: 0%;"></div>
                                </div>
                                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mt-2 text-xs gap-y-1">
                                    <div class="w-full sm:w-auto flex items-center text-gray-600 dark:text-gray-300 min-w-0">
                                        <span id="uploadStatus" class="font-medium truncate">Awaiting upload...</span>
                                    </div>
                                    <div class="w-full sm:w-auto flex items-center justify-between sm:justify-end sm:gap-2 flex-shrink-0">
                                        <div id="speedContainer" class="hidden flex items-center text-gray-500 dark:text-gray-400">
                                            <i class="fas fa-wifi text-xs mr-1"></i>
                                            <span id="uploadSpeed" class="font-medium whitespace-nowrap"></span>
                                            <span class="text-gray-400 dark:text-gray-500 hidden sm:inline-block mx-1">|</span>
                                        </div>
                                        <span id="uploadPercent" class="font-medium text-indigo-600 dark:text-indigo-400">0%</span>
                                    </div>
                                </div>
                             </div>
                         </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Schedule Modal -->
<div id="scheduleModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/50 hidden">
    <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-2xl max-w-sm w-full transform transition-all">
        <div class="flex items-center justify-center mb-3">
            <div class="bg-indigo-100 text-indigo-600 p-3 rounded-full flex items-center justify-center w-12 h-12">
                <i class="fa-solid fa-clock text-2xl"></i>
            </div>
        </div>
        <h3 class="text-xl font-bold text-slate-800 dark:text-gray-100 text-center mb-1">Schedule Publication</h3>
        <p class="text-center text-slate-500 dark:text-gray-400 text-sm mb-4">Set the date and time for the edition to be published.</p>
        <div class="space-y-4">
            <div>
                <label for="schedule_date" class="block text-xs font-semibold text-slate-700 dark:text-gray-300 mb-1">SCHEDULE DATE</label>
                 <div class="relative cursor-pointer">
                    <input type="date" id="schedule_date" class="form-input pr-10">
                    <i class="fa-solid fa-calendar-alt text-indigo-900 dark:text-indigo-400 absolute right-3 top-1/2 transform -translate-y-1/2"></i>
                </div>
            </div>
            <div>
                <label for="schedule_time" class="block text-xs font-semibold text-slate-700 dark:text-gray-300 mb-1">SCHEDULE TIME</label>
                <div class="relative cursor-pointer">
                    <input type="time" id="schedule_time" class="form-input pr-10">
                    <i class="fa-solid fa-clock text-indigo-900 dark:text-indigo-400 absolute right-3 top-1/2 transform -translate-y-1/2"></i>
                </div>
            </div>
        </div>
        <div class="flex gap-3 mt-6">
            <button type="button" id="cancelScheduleBtn" class="flex-1 px-4 py-2 bg-slate-200 text-slate-700 font-semibold rounded-md hover:bg-slate-300 dark:bg-gray-600 dark:text-gray-200 dark:hover:bg-gray-500 transition text-sm">Cancel</button>
            <button type="button" id="confirmScheduleBtn" class="flex-1 px-4 py-2 bg-indigo-900 text-white font-semibold rounded-md hover:bg-indigo-800 dark:bg-indigo-600 dark:hover:bg-indigo-700 transition text-sm">Confirm</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    pdfjsLib.GlobalWorkerOptions.workerSrc = `https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.11.338/pdf.worker.min.js`;

    const uploadForm = document.getElementById('uploadForm');
    const submitBtn = document.getElementById('submitBtn');
    const clearBtn = document.getElementById('clearBtn');
    const pdfFileInput = document.getElementById('pdf_file');
    const progressContainer = document.getElementById('progressContainer');
    const progressFill = document.getElementById('progressFill');
    const uploadStatus = document.getElementById('uploadStatus');
    const uploadPercent = document.getElementById('uploadPercent');
    const uploadSpeed = document.getElementById('uploadSpeed');
    const speedContainer = document.getElementById('speedContainer');
    const statusIcon = document.getElementById('statusIcon');
    const fileNameDisplay = document.getElementById('fileName');
    const fileUploadLabel = document.querySelector('label[for="pdf_file"]');
    const previewMainTitle = document.getElementById('preview-main-title');
    const previewDateLive = document.getElementById('preview-date-live');
    const previewCategoryLive = document.getElementById('preview-category-live');
    const previewStatusLive = document.getElementById('preview-status-live');
    const previewStatusChip = document.getElementById('preview-status-chip');
    const previewPlaceholder = document.getElementById('preview-placeholder');
    const pdfPreviewContainer = document.getElementById('pdf-preview-container');
    const pdfPreviewCanvas = document.getElementById('pdf-preview-canvas');
    const titleInput = document.getElementById('title');
    const dateInput = document.getElementById('publication_date');
    const categorySelect = document.getElementById('category_id');
    const descriptionInput = document.getElementById('description');
    const descriptionHidden = document.getElementById('description_hidden');
    const statusSelect = document.getElementById('status_select');
    const scheduleModal = document.getElementById('scheduleModal');
    const scheduleDateInput = document.getElementById('schedule_date');
    const scheduleTimeInput = document.getElementById('schedule_time');
    const confirmScheduleBtn = document.getElementById('confirmScheduleBtn');
    const cancelScheduleBtn = document.getElementById('cancelScheduleBtn');
    const scheduleDisplay = document.getElementById('schedule-display');
    
    // PDF Preview elements
    const prevPageBtn = document.getElementById('prev-page');
    const nextPageBtn = document.getElementById('next-page');
    const pageCounter = document.getElementById('page-counter');
    const pageNumSpan = document.getElementById('page-num');
    const pageCountSpan = document.getElementById('page-count');

    let pdfDoc = null;
    let currentPage = 1;
    let pageRendering = false;
    let pageNumPending = null;

    let previousStatusValue = statusSelect.value;
    let processingTimeout;
    let uploadStartTime;

    const hiddenScheduleDate = document.createElement('input');
    hiddenScheduleDate.type = 'hidden';
    hiddenScheduleDate.name = 'schedule_date';
    uploadForm.appendChild(hiddenScheduleDate);

    const hiddenScheduleTime = document.createElement('input');
    hiddenScheduleTime.type = 'hidden';
    hiddenScheduleTime.name = 'schedule_time';
    uploadForm.appendChild(hiddenScheduleTime);

     clearBtn.addEventListener('click', () => {
        uploadForm.reset();
        fileNameDisplay.textContent = '';
        pdfPreviewContainer.classList.add('hidden');
        previewPlaceholder.classList.remove('hidden');
        [prevPageBtn, nextPageBtn, pageCounter].forEach(el => el.classList.add('hidden'));
        updateLivePreview();
        updateDescription();
    });
    
    statusSelect.addEventListener('change', (e) => {
        if (e.target.value === 'Scheduled') {
            scheduleModal.classList.remove('hidden');
        } else {
            previousStatusValue = e.target.value;
            hiddenScheduleDate.value = '';
            hiddenScheduleTime.value = '';
            scheduleDisplay.textContent = '';
        }
        updateLivePreview();
    });

    cancelScheduleBtn.addEventListener('click', () => {
        scheduleModal.classList.add('hidden');
        statusSelect.value = previousStatusValue;
        updateLivePreview();
    });

    confirmScheduleBtn.addEventListener('click', () => {
        if (scheduleDateInput.value && scheduleTimeInput.value) {
            hiddenScheduleDate.value = scheduleDateInput.value;
            hiddenScheduleTime.value = scheduleTimeInput.value;
            const date = new Date(scheduleDateInput.value + 'T' + scheduleTimeInput.value);
            const formattedDate = date.toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' }).replace(/\//g, '-');
            const formattedTime = date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
            scheduleDisplay.textContent = `(${formattedDate} at ${formattedTime})`;
            scheduleModal.classList.add('hidden');
        } else {
            alert('Please select both a date and time to schedule.');
        }
    });

    // Make calendar icons clickable
    dateInput.parentElement.addEventListener('click', (e) => {
        // Prevent re-triggering if the input itself is clicked
        if (e.target !== dateInput) {
            dateInput.showPicker();
        }
    });
    scheduleDateInput.parentElement.addEventListener('click', (e) => {
         if (e.target !== scheduleDateInput) {
            scheduleDateInput.showPicker();
        }
    });
    scheduleTimeInput.parentElement.addEventListener('click', (e) => {
        if (e.target !== scheduleTimeInput) {
            scheduleTimeInput.showPicker();
        }
    });

    function formatBytes(bytes, decimals = 2) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }

    function updateDescription() {
        const title = titleInput.value.trim();
        const dateValue = dateInput.value;
        let newDescriptionValue = '';
        if (title && dateValue) {
            const dateParts = dateValue.split('-');
            const formattedDate = `${dateParts[2]}-${dateParts[1]}-${dateParts[0]}`;
            newDescriptionValue = `${title} - ${formattedDate}`;
        } else if (title) {
            newDescriptionValue = title;
        }
        if (document.getElementById('description')) {
             const descriptionTextarea = document.getElementById('description');
             const oldAutoValue = descriptionTextarea.dataset.autoValue || '';
             if (descriptionTextarea.value === '' || descriptionTextarea.value === oldAutoValue) {
                descriptionTextarea.value = newDescriptionValue;
                descriptionTextarea.dataset.autoValue = newDescriptionValue;
             }
        }
        descriptionHidden.value = document.getElementById('description')?.value || newDescriptionValue;
    }

    function updateLivePreview() {
        previewMainTitle.textContent = titleInput.value.trim() || 'Edition Preview';
        previewDateLive.textContent = dateInput.value ? new Date(dateInput.value + 'T00:00:00').toLocaleDateString('en-GB') : 'N/A';
        const selectedCategory = categorySelect.options[categorySelect.selectedIndex];
        previewCategoryLive.textContent = selectedCategory.value ? selectedCategory.text : 'N/A';
        const selectedStatusText = statusSelect.options[statusSelect.selectedIndex].text;
        previewStatusLive.textContent = selectedStatusText;
        const selectedStatusValue = statusSelect.value;
        if (selectedStatusValue === 'Published') {
            previewStatusChip.className = 'flex items-center bg-green-100 text-green-800 text-xs font-bold px-3 py-1 rounded-full';
        } else if (selectedStatusValue === 'Private') {
            previewStatusChip.className = 'flex items-center bg-yellow-100 text-yellow-800 text-xs font-bold px-3 py-1 rounded-full';
        } else { // Scheduled
             previewStatusChip.className = 'flex items-center bg-blue-100 text-blue-800 text-xs font-bold px-3 py-1 rounded-full';
        }
    }

    titleInput.addEventListener('input', () => { updateLivePreview(); updateDescription(); });
    dateInput.addEventListener('change', () => { updateLivePreview(); updateDescription(); });
    if(document.getElementById('description')){
        document.getElementById('description').addEventListener('input', (e) => {
            document.getElementById('description_hidden').value = e.target.value;
            delete e.target.dataset.autoValue;
        });
    }
    categorySelect.addEventListener('change', updateLivePreview);
    
    if(fileUploadLabel) {
        ['dragover', 'dragleave', 'drop'].forEach(eventName => fileUploadLabel.addEventListener(eventName, e => e.preventDefault()));
        fileUploadLabel.addEventListener('dragover', () => fileUploadLabel.classList.add('border-indigo-500'));
        fileUploadLabel.addEventListener('dragleave', () => fileUploadLabel.classList.remove('border-indigo-500'));
        fileUploadLabel.addEventListener('drop', e => {
            fileUploadLabel.classList.remove('border-indigo-500');
            if(e.dataTransfer.files.length > 0){
                pdfFileInput.files = e.dataTransfer.files;
                pdfFileInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    pdfFileInput.addEventListener('change', (e) => {
        const file = e.target.files[0];
        if (file) {
            fileNameDisplay.textContent = `Selected: ${file.name}`;
            if (file.type === 'application/pdf') {
                loadAndRenderPdf(file);
            }
            else {
                pdfPreviewContainer.classList.add('hidden');
                previewPlaceholder.classList.remove('hidden');
                 [prevPageBtn, nextPageBtn, pageCounter].forEach(el => el.classList.add('hidden'));
            }
        } else {
            fileNameDisplay.textContent = '';
            pdfPreviewContainer.classList.add('hidden');
            previewPlaceholder.classList.remove('hidden');
            [prevPageBtn, nextPageBtn, pageCounter].forEach(el => el.classList.add('hidden'));
        }
    });

    function renderPage(num) {
        pageRendering = true;
        
        pdfDoc.getPage(num).then(function(page) {
            const container = pdfPreviewContainer.querySelector('.pdf-preview-wrapper').parentElement;
            const viewport = page.getViewport({ scale: 1.0 });
            
            const scaleX = container.clientWidth / viewport.width;
            const scaleY = container.clientHeight / viewport.height;
            const scale = Math.min(scaleX, scaleY);
            
            const scaledViewport = page.getViewport({ scale: scale });
            
            pdfPreviewCanvas.height = scaledViewport.height;
            pdfPreviewCanvas.width = scaledViewport.width;

            const renderContext = {
                canvasContext: pdfPreviewCanvas.getContext('2d'),
                viewport: scaledViewport
            };
            const renderTask = page.render(renderContext);

            renderTask.promise.then(function() {
                pageRendering = false;
                if (pageNumPending !== null) {
                    renderPage(pageNumPending);
                    pageNumPending = null;
                }
            });
        });

        pageNumSpan.textContent = num;
        prevPageBtn.disabled = num <= 1;
        nextPageBtn.disabled = num >= pdfDoc.numPages;
    }

    function queueRenderPage(num) {
        if (pageRendering) {
            pageNumPending = num;
        } else {
            renderPage(num);
        }
    }

    prevPageBtn.addEventListener('click', () => {
        if (currentPage <= 1) return;
        currentPage--;
        queueRenderPage(currentPage);
    });

    nextPageBtn.addEventListener('click', () => {
        if (currentPage >= pdfDoc.numPages) return;
        currentPage++;
        queueRenderPage(currentPage);
    });

    async function loadAndRenderPdf(file) {
        const fileReader = new FileReader();
        fileReader.onload = async function() {
            const typedarray = new Uint8Array(this.result);
            try {
                previewPlaceholder.classList.add('hidden');
                pdfPreviewContainer.classList.remove('hidden');
                
                pdfjsLib.getDocument({ data: typedarray }).promise.then(function(pdfDoc_) {
                    pdfDoc = pdfDoc_;
                    pageCountSpan.textContent = pdfDoc.numPages;
                    currentPage = 1;
                    renderPage(currentPage);

                    if (pdfDoc.numPages > 1) {
                         [prevPageBtn, nextPageBtn, pageCounter].forEach(el => el.classList.remove('hidden'));
                    } else {
                         [prevPageBtn, nextPageBtn, pageCounter].forEach(el => el.classList.add('hidden'));
                    }
                });

            } catch (error) {
                console.error('Error rendering PDF preview:', error);
                pdfPreviewContainer.classList.add('hidden');
                previewPlaceholder.classList.remove('hidden');
                 [prevPageBtn, nextPageBtn, pageCounter].forEach(el => el.classList.add('hidden'));
            }
        };
        fileReader.readAsArrayBuffer(file);
    }

    uploadForm.addEventListener('submit', function(e) {
        e.preventDefault();
        progressContainer.classList.remove('hidden');
        if (window.innerWidth < 768) { 
            progressContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        progressFill.style.backgroundColor = '#4338ca';
        progressFill.style.transition = 'width 0.3s ease-in-out, background-color 0.3s ease-in-out';
        progressFill.style.width = '0%';
        uploadStatus.textContent = 'Awaiting upload...';
        uploadPercent.textContent = '0%';
        speedContainer.classList.add('hidden');
        statusIcon.className = 'fa-solid fa-spinner fa-spin text-lg text-indigo-900 dark:text-indigo-400';
        statusIcon.classList.remove('hidden');
        submitBtn.disabled = true;

        if(processingTimeout) clearInterval(processingTimeout);

        const formData = new FormData(uploadForm);
        const xhr = new XMLHttpRequest();
        
        xhr.open('POST', '/editions/upload-edition.php', true);

        uploadStartTime = Date.now();

        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                const percentComplete = Math.round((e.loaded / e.total) * 90);
                progressFill.style.width = percentComplete + '%';
                uploadPercent.textContent = percentComplete + '%';
                
                const elapsedTime = (Date.now() - uploadStartTime) / 1000;
                if (elapsedTime > 0) {
                    speedContainer.classList.remove('hidden');
                    const speed = e.loaded / elapsedTime;
                    uploadSpeed.textContent = formatBytes(speed) + '/s';
                }

                uploadStatus.textContent = `Uploading PDF... (${formatBytes(e.loaded)} / ${formatBytes(e.total)})`;
            }
        };
        
        xhr.upload.onload = function() {
            progressFill.style.width = '90%';
            uploadPercent.textContent = '90%';
            speedContainer.classList.add('hidden');
            statusIcon.className = 'fa-solid fa-gear fa-spin text-lg text-indigo-900 dark:text-indigo-400';
            let statuses = ['Converting images...', 'Making thumbnails...', 'Finalizing...'];
            let progressSteps = [93, 96];
            let statusIndex = 0;
            uploadStatus.textContent = statuses[statusIndex];

            processingTimeout = setInterval(() => {
                statusIndex++;
                if (statusIndex < statuses.length) {
                    uploadStatus.textContent = statuses[statusIndex];
                    if(progressSteps[statusIndex - 1]) {
                        const newProgress = progressSteps[statusIndex - 1];
                        progressFill.style.width = newProgress + '%';
                        uploadPercent.textContent = newProgress + '%';
                    }
                } else {
                    clearInterval(processingTimeout);
                }
            }, 2500);
        };

        xhr.onload = function() {
            clearInterval(processingTimeout);
            speedContainer.classList.add('hidden');

            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    const result = JSON.parse(xhr.responseText);
                    if (result.success) {
                        progressFill.style.width = '100%';
                        uploadPercent.textContent = '100%';
                        uploadStatus.textContent = 'Successfully completed!';
                        progressFill.style.backgroundColor = '#22c55e';
                        statusIcon.className = 'fa-solid fa-circle-check text-lg text-green-500';
                        setTimeout(() => {
                            window.location.href = result.redirect || '/manage-editions';
                        }, 1500);
                    } else {
                        uploadStatus.textContent = 'Upload failed: ' + result.message;
                        progressFill.style.backgroundColor = '#ef4444';
                        uploadPercent.textContent = 'Error';
                        statusIcon.className = 'fa-solid fa-circle-xmark text-lg text-red-500';
                        submitBtn.disabled = false;
                    }
                } catch(e) {
                    uploadStatus.textContent = 'Error: Invalid server response.';
                    progressFill.style.backgroundColor = '#ef4444';
                    uploadPercent.textContent = 'Error';
                    statusIcon.className = 'fa-solid fa-circle-xmark text-lg text-red-500';
                    submitBtn.disabled = false;
                }
            } else {
                 uploadStatus.textContent = 'Upload error: ' + xhr.status;
                 progressFill.style.backgroundColor = '#ef4444';
                 uploadPercent.textContent = 'Error';
                 statusIcon.className = 'fa-solid fa-circle-xmark text-lg text-red-500';
                 submitBtn.disabled = false;
            }
        };

        xhr.onerror = function() {
            clearInterval(processingTimeout);
            speedContainer.classList.add('hidden');
            uploadStatus.textContent = 'Network error. Please try again.';
            progressFill.style.backgroundColor = '#ef4444';
            uploadPercent.textContent = 'Error';
            statusIcon.className = 'fa-solid fa-circle-xmark text-lg text-red-500';
            submitBtn.disabled = false;
        };

        xhr.send(formData);
    });

    updateLivePreview();
    updateDescription();
});
</script>
</body>
</html>

