<?php
// File: upload-edition.php
// Allows SuperAdmins and Admins to upload new edition details and PDF files.

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

// Include database configuration
// Assuming config.php is in a 'config' directory one level up from the current script.
// For example, if upload-edition.php is in /public_html/editions/, and config.php is in /public_html/config/
require_once __DIR__ . '/../config/config.php';

// Set default timezone to Asia/Kolkata (IST) for consistent date/time handling
date_default_timezone_set('Asia/Kolkata');

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
        header("Location: login.php?timeout=1");
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// --- INITIAL AUTHORIZATION CHECKS ---
if (!$loggedIn) {
    $_SESSION['message'] = '<div class="alert alert-info"><i class="fas fa-info-circle mr-2"></i> Please log in to access this page.</div>';
    header("Location: login.php");
    exit;
}

if ($userRole !== 'SuperAdmin' && $userRole !== 'Admin') {
    $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-lock mr-2"></i> You do not have the required permissions to access this page.</div>';
    header("Location: dashboard.php"); // Redirect to a page they can access
    exit;
}

$pageTitle = "Upload New Edition";

// Fetch categories for the dropdown
$categories = [];
try {
    $stmt = $pdo->query("SELECT category_id, name FROM categories ORDER BY name ASC");
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
// IMPORTANT: VERIFY THESE PATHS ON YOUR SERVER!
// Configuration set for LINUX (common paths)
// These constants are moved to the global scope to avoid PHP Parse errors.
const GS_BIN_DIR = '/usr/bin'; // Common path for Ghostscript executables (gs, gsutil etc.)
const IMAGEMAGICK_HOME_DIR = '/usr'; // Common ImageMagick install root (where policies.xml might be)
const MAGICK_EXE_PATH = '/usr/bin/convert'; // Use 'convert' command if 'magick' is not found or preferred for older IM versions.

// Thumbnail sizes
const OG_THUMB_WIDTH = 1200; // Open Graph optimal width for landscape previews
const OG_THUMB_HEIGHT = 600; // Optimal height for top-aligned crop
const LIST_THUMB_HEIGHT = 1200; // Height for list view thumbnail, width will be proportional
// --- END Configuration for ImageMagick and Thumbnail Sizes ---


// --- Handle Form Submission (AJAX POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json'); // Ensure JSON response for AJAX
    ini_set('display_errors', 0); // Hide errors from output for JSON
    error_reporting(E_ALL);

    $title = trim($_POST['title'] ?? '');
    $publication_date = trim($_POST['publication_date'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $description = trim($_POST['description'] ?? '');
    $description = ($description === '') ? null : $description; // Set empty string to null for DB
    $status = trim($_POST['status'] ?? 'private'); // Default to 'private' if not set

    $pdf_file = $_FILES['pdf_file'] ?? null;

    // Basic validation
    if (empty($title)) {
        echo json_encode(['success' => false, 'message' => 'Edition title is required.']);
        exit;
    }
    if (empty($publication_date) || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $publication_date)) {
        echo json_encode(['success' => false, 'message' => 'Publication date is required and must be inYYYY-MM-DD format.']);
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

    $pdo->beginTransaction(); // Start transaction

    try {
        // 1. Create unique directory structure based on publication date and unique ID
        $base_upload_dir = '/../uploads/editions/';
        $pub_date_obj = DateTime::createFromFormat('Y-m-d', $publication_date);
        $pub_year = $pub_date_obj->format('Y');
        $pub_month = $pub_date_obj->format('m');
        $pub_date_day = $pub_date_obj->format('d');
        $current_time_hhmmss = date('His');

        $unique_folder_name = "{$publication_date}_{$current_time_hhmmss}_" . uniqid();
        $date_sub_path = "{$pub_year}/{$pub_month}/{$pub_date_day}/";
        $full_edition_dir = __DIR__ . '/' . $base_upload_dir . $date_sub_path . $unique_folder_name . '/';

        if (!is_dir($full_edition_dir)) {
            if (!mkdir($full_edition_dir, 0755, true)) {
                throw new Exception('Failed to create directory for edition.');
            }
        }

        // 2. Move uploaded PDF file
        $pdf_file_name = 'edition-' . $pub_date_obj->format('d-m-Y') . '.pdf';
        $pdf_target_path = $full_edition_dir . $pdf_file_name;
        if (!move_uploaded_file($pdf_file['tmp_name'], $pdf_target_path)) {
            throw new Exception('Failed to move uploaded PDF file.');
        }
        // Store web-accessible path for database
        $pdf_web_path = $base_upload_dir . $date_sub_path . $unique_folder_name . '/' . $pdf_file_name;
        if (!chmod($pdf_target_path, 0644)) {
            error_log("Failed to set permissions for PDF file: " . $pdf_target_path);
        }

        // 3. Create images subdirectory
        $images_dir = $full_edition_dir . 'images/';
        if (!is_dir($images_dir)) {
            if (!mkdir($images_dir, 0755)) {
                throw new Exception('Failed to create images directory.');
            }
        }

        // 4. Convert PDF to images using ImageMagick (via 'convert' command)
        // Set environment variables for ImageMagick/Ghostscript
        $original_path = getenv('PATH');
        putenv('PATH=' . GS_BIN_DIR . PATH_SEPARATOR . $original_path);
        putenv('MAGICK_HOME=' . IMAGEMAGICK_HOME_DIR);

        $magick_exe_escaped = escapeshellarg(MAGICK_EXE_PATH);
        $pdf_target_path_escaped = escapeshellarg($pdf_target_path);
        $temp_images_pattern = $images_dir . 'temp_raw_%03d.jpg';
        $temp_images_output_escaped = escapeshellarg($temp_images_pattern);

        // Command to extract all pages as raw JPGs
        // Corrected: $pdf_target_path_escaped is now correctly placed before output options
        $command = $magick_exe_escaped . ' -density 250 ' . $pdf_target_path_escaped .
                   ' -quality 85 -scene 1 ' . // Moved -quality and -scene after input PDF
                   $temp_images_output_escaped . ' 2>&1';

        error_log("Attempting ImageMagick command: " . $command);
        exec($command, $output, $return_var);

        if ($return_var !== 0) {
            // Restore original PATH before throwing error
            putenv('PATH=' . $original_path);
            throw new Exception("PDF conversion failed: " . implode("\n", $output));
        }

        // Rename temporary images to desired format (page-1.jpg, page-2.jpg etc.)
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
            if (!rename($temp_image_file, $final_image_path)) {
                error_log("Failed to rename temporary image file '{$temp_image_file}' to '{$final_image_path}'.");
                // Restore original PATH
                putenv('PATH=' . $original_path);
                throw new Exception("Failed to finalize image naming for page " . $page_count . ".");
            }
            if (!chmod($final_image_path, 0644)) {
                error_log("Failed to set permissions for image file: " . $final_image_path);
            }
            if ($page_count === 1) {
                $first_page_server_path = $final_image_path;
            }
        }

        // Generate Open Graph and List View Thumbnails from the first page
        $og_image_web_path = null;
        $list_thumb_web_path = null;

        if ($first_page_server_path) {
            // --- Open Graph Thumbnail ---
            $og_image_name = 'og-thumb.jpg';
            $og_image_server_path = $images_dir . $og_image_name;
            $og_image_web_path = $base_upload_dir . $date_sub_path . $unique_folder_name . '/images/' . $og_image_name;

            // Resize to target width, then crop 1200x600 from the top (North gravity)
            $command_og = $magick_exe_escaped . ' ' . escapeshellarg($first_page_server_path) .
                          ' -resize ' . OG_THUMB_WIDTH . 'x' . // Resize to target width, proportional height
                          ' -gravity North -crop ' . OG_THUMB_WIDTH . 'x' . OG_THUMB_HEIGHT . '+0+0 +repage' . // Crop from top-left to desired dimensions
                          ' -quality 85 ' . escapeshellarg($og_image_server_path) . ' 2>&1';

            error_log("Attempting ImageMagick command for OG thumbnail: " . $command_og);
            exec($command_og, $output_og, $return_var_og);

            if ($return_var_og !== 0) {
                error_log('OG thumbnail generation failed. Command: ' . $command_og . ' Output: ' . implode("\n", $output_og));
                $og_image_web_path = null; // Set to null if generation fails
            } else {
                if (!chmod($og_image_server_path, 0644)) {
                    error_log("Failed to set permissions for OG thumbnail: " . $og_image_server_path);
                }
            }

            // --- List View Thumbnail ---
            $list_thumb_name = 'list-thumb.jpg';
            $list_thumb_server_path = $images_dir . $list_thumb_name;
            $list_thumb_web_path = $base_upload_dir . $date_sub_path . $unique_folder_name . '/images/' . $list_thumb_name;

            // Resize to target height, width proportional
            $command_list_thumb = $magick_exe_escaped . ' ' . escapeshellarg($first_page_server_path) .
                                  ' -resize x' . LIST_THUMB_HEIGHT . ' -quality 85 ' .
                                  escapeshellarg($list_thumb_server_path) . ' 2>&1';

            error_log("Attempting ImageMagick command for List thumbnail: " . $command_list_thumb);
            exec($command_list_thumb, $output_list_thumb, $return_var_list_thumb);

            if ($return_var_list_thumb !== 0) {
                error_log('List thumbnail generation failed. Command: ' . $command_list_thumb . ' Output: ' . implode("\n", $output_list_thumb));
                $list_thumb_web_path = null; // Set to null if generation fails
            } else {
                if (!chmod($list_thumb_server_path, 0644)) {
                    error_log("Failed to set permissions for list thumbnail: " . $list_thumb_server_path);
                }
            }
        } else {
            error_log("Warning: First page image not found, skipping thumbnail generation.");
        }

        // Restore original PATH
        putenv('PATH=' . $original_path);

        // 5. Insert data into database
        $stmt = $pdo->prepare("
            INSERT INTO editions (
                title, publication_date, category_id, description, pdf_path,
                og_image_path, list_thumb_path, page_count, file_size_bytes,
                uploader_user_id, status, created_at, updated_at
            ) VALUES (
                :title, :publication_date, :category_id, :description, :pdf_path,
                :og_image_path, :list_thumb_path, :page_count, :file_size_bytes,
                :uploader_user_id, :status, NOW(), NOW()
            )
        ");

        $stmt->execute([
            ':title' => $title,
            ':publication_date' => $publication_date,
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

        $pdo->commit(); // Commit transaction

        echo json_encode(['success' => true, 'message' => 'Edition uploaded successfully!', 'redirect' => 'manage-editions.php']);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack(); // Rollback on error
        error_log("Edition upload failed: " . $e->getMessage());

        // Attempt to clean up partially created files/directories on failure
        if (isset($full_edition_dir) && is_dir($full_edition_dir)) {
            // Function to recursively delete directory contents
            function deleteDir($dir) {
                $files = array_diff(scandir($dir), array('.', '..'));
                foreach ($files as $file) {
                    (is_dir("$dir/$file")) ? deleteDir("$dir/$file") : unlink("$dir/$file");
                }
                return rmdir($dir);
            }
            error_log("Attempting to clean up directory: " . $full_edition_dir);
            try {
                deleteDir($full_edition_dir);
                error_log("Cleaned up directory: " . $full_edition_dir);
            } catch (Exception $cleanup_e) {
                error_log("Failed to clean up directory " . $full_edition_dir . ": " . $cleanup_e->getMessage());
            }
        }

        echo json_encode(['success' => false, 'message' => 'Upload failed: ' . $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en" x-data="{ sidebarOpen: false, profileMenuOpen: false, mobileProfileMenuOpen: false, moreMenuOpen: false }" x-cloak>
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* Your custom CSS variables and styles */
        :root {
            --primary-color: rgb(2, 118, 208);
            --hover-bg-color: var(--primary-color);
            --hover-text-color: white;
            --border-radius: 20px;
            --text-main-color: #374151;
        }

        /* Alpine.js cloak to prevent flash of unstyled content */
        [x-cloak] { display: none !important; }
        body { font-family: 'Inter', sans-serif; }

        /* General styles for consistent hover effects */
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
            transition: background-color 0.3s, color 0.3s, border-radius 0.3s;
            padding: 5px 10px;
            border-radius: 10px;
        }
        .tool-actions button:hover {
            background-color: var(--hover-bg-color);
            color: var(--hover-text-color);
            border-radius: var(--border-radius);
        }
        .tool-actions i {
            font-size: 0.75rem;
        }
        .menu-button {
            font-size: 0.85rem;
        }
        .menu-button i {
            font-size: 0.85rem;
        }
        .profile-dropdown a:hover,
        .tool-actions button:hover,
        nav a:hover,
        .more-menu a:hover,
        .mobile-sidebar a:hover {
            background-color: var(--hover-bg-color) !important;
            color: var(--hover-text-color) !important;
            border-radius: var(--border-radius);
        }
        .edition-title {
            background-color: var(--primary-color);
        }

        /* Specific styles for the form layout and elements */
        .upload-container {
            background: white;
            padding: 2rem;
            border-radius: 1rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            max-width: 1200px; /* Wider layout */
            margin: 2rem auto; /* Center the container horizontally */
        }

        .upload-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .upload-header i {
            font-size: 1.5rem;
            color: var(--primary-color);
        }

        .upload-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-main-color);
            margin: 0;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-main-color);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 1rem;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(2, 118, 208, 0.1);
        }

        .file-upload {
            position: relative;
            display: flex;
            flex-direction: row; /* Changed to row */
            align-items: center; /* Vertically align items */
            justify-content: center; /* Horizontally center content */
            gap: 0.75rem; /* Added gap between icon and text */
            padding: 1rem;
            border: 2px dashed #d1d5db;
            border-radius: 0.5rem;
            background-color: #f9fafb;
            cursor: pointer;
            transition: all 0.2s;
        }

        .file-upload:hover {
            border-color: var(--primary-color);
            background-color: rgba(2, 118, 208, 0.05);
        }

        .file-upload .icon { /* New class for the icon/image */
            width: 32px; /* Tailwind w-8 */
            height: 32px; /* Tailwind h-8 */
        }

        .file-upload p {
            margin: 0;
            color: var(--text-main-color);
            text-align: left; /* Changed text alignment */
            font-size: 0.9rem; /* Slightly smaller font for compactness */
        }

        .file-upload input {
            position: absolute;
            width: 100%;
            height: 100%;
            opacity: 0; /* Hide the default file input button */
            cursor: pointer;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 0.5rem;
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.2s;
            width: 100%; /* Make button full width */
        }

        .btn-primary:hover {
            background-color: #1a56db; /* A slightly darker blue on hover */
        }

        /* Progress and Alert Message Styles */
        .progress-container {
            background-color: #f3f4f6;
            border-radius: 0.5rem;
            padding: 0.75rem 1.5rem; /* Adjusted to match button padding */
        }

        .progress-bar {
            height: 0.5rem;
            background-color: #e5e7eb;
            border-radius: 0.25rem;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .progress-fill {
            height: 100%;
            background-color: var(--primary-color);
            width: 0%; /* Controls the fill percentage via JS */
            transition: width 0.3s ease; /* Smooth animation for progress bar */
        }

        .alert {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-top: 1.5rem;
            display: none; /* Hidden by default, shown by JS */
            align-items: flex-start;
            font-size: 0.95rem;
            line-height: 1.4;
            font-weight: 500;
            max-width: 100%;
            margin-left: auto;
            margin-right: auto;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            opacity: 1; /* Ensure initial visibility for fade-out */
            transition: opacity 0.5s ease-out; /* Smooth fade-out transition */
        }
        .alert i {
            margin-right: 0.5rem;
            margin-top: 0.125rem;
            font-size: 1.1rem;
        }
        .alert-success {
            background-color: #dcfce2;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }
        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
        }
        .alert-info {
            background-color: #e0f2fe;
            color: #0284c7;
            border: 1px solid #7dd3fc;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .upload-container {
                padding: 1.5rem;
                margin: 1rem;
            }

            .upload-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .upload-header h1 {
                font-size: 1.25rem;
            }
        }
    </style>
</head>
<body class="bg-gray-50">

<?php 
// Assuming headersidebar.php is in the 'layout' directory one level up from 'editions'
require_once __DIR__ . '/../layout/headersidebar.php';
?>

<main class="p-[20px] md:py-6 md:px-4 md:ml-64">
    <div class="max-w-full mx-auto py-0">
        <?php display_session_message(); ?>

        <div class="bg-white rounded-xl shadow-md overflow-hidden p-6">
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 mb-6">
                <div class="flex items-center gap-3">
                    <i class="fas fa-upload text-2xl text-blue-600"></i>
                    <h1 class="text-2xl font-semibold text-gray-800">Upload New Edition</h1>
                </div>
                <a href="manage-editions.php" class="inline-flex items-center px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400 transition duration-300 justify-center">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Editions
                </a>
            </div>

            <form id="uploadEditionForm" enctype="multipart/form-data">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-x-4">
                    <div class="form-group">
                        <label for="title">Edition Title</label>
                        <input type="text" id="title" name="title" class="form-control" placeholder="Enter edition title" required>
                    </div>

                    <div class="form-group">
                        <label for="publication_date">Publication Date</label>
                        <input type="date" id="publication_date" name="publication_date" class="form-control" max="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="category_id">Category</label>
                        <select id="category_id" name="category_id" class="form-control" required>
                            <option value="">Select a Category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= htmlspecialchars($category['category_id']) ?>">
                                    <?= htmlspecialchars($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="description">Description (Optional)</label>
                    <textarea id="description" name="description" class="form-control" rows="1" placeholder="Enter a brief description for this edition"></textarea>
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="form-control" required>
                        <option value="published">Published</option>
                        <option value="private">Private</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="block text-sm font-medium text-gray-700 mb-1">PDF File</label>
                    <div class="file-upload">
                        <!-- Replaced Icons8 image with an inline SVG for a file icon, styled with Tailwind CSS -->
                        <i class="fas fa-file-pdf text-2xl text-red-600"></i>
                        <p>Drag & drop your PDF here or click to browse</p>
                        <input type="file" name="pdf_file" id="pdf_file" accept="application/pdf" required>
                    </div>
                    <p class="text-sm text-gray-500 mt-1">Max file size: 50MB. Only PDF files are allowed.</p>
                </div>

                <div class="flex flex-col sm:flex-row justify-end gap-3 mt-6">
                    <div id="uploadActionContainer" class="flex-1 flex justify-end">
                        <button type="submit" id="uploadButton" class="btn-primary flex items-center justify-center sm:w-auto">
                            <i class="fas fa-cloud-upload-alt mr-2"></i> Upload Edition
                        </button>
                        <div id="progressContainer" class="progress-container hidden w-full sm:w-auto">
                            <div class="flex items-center justify-between text-sm text-gray-700">
                                <span id="uploadStatus">Uploading...</span>
                                <!-- Added a spinner icon here -->
                                <span id="processingSpinner" class="hidden"><i class="fas fa-spinner fa-spin ml-2 text-blue-500"></i></span>
                                <span id="uploadPercent">0%</span>
                            </div>
                            <div class="progress-bar">
                                <div id="progressFill" class="progress-fill"></div>
                            </div>
                        </div>
                    </div>
                    <a href="manage-editions.php" class="inline-flex items-center justify-center px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400 transition duration-300 sm:w-auto">
                        <i class="fas fa-times-circle mr-2"></i> Cancel
                    </a>
                </div>

                <div id="finalMessage" class="alert"></div>
            </form>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('uploadEditionForm');
    const fileUpload = document.querySelector('.file-upload');
    const fileInput = document.getElementById('pdf_file');
    const progressContainer = document.getElementById('progressContainer');
    const uploadStatus = document.getElementById('uploadStatus');
    const uploadPercent = document.getElementById('uploadPercent');
    const progressFill = document.getElementById('progressFill');
    const finalMessage = document.getElementById('finalMessage');
    const uploadButton = document.getElementById('uploadButton'); // Get reference to the upload button
    const processingSpinner = document.getElementById('processingSpinner'); // Get reference to the spinner

    const MAX_FILE_SIZE = 50 * 1024 * 1024; // 50MB in bytes
    let processingInterval; // Variable to hold the interval for simulated processing

    // Function to handle file selection and update display
    function handleFile(file) {
        if (!file) {
            fileUpload.querySelector('p').textContent = 'Drag & drop your PDF here or click to browse';
            hideAlert();
            return;
        }

        if (file.size > MAX_FILE_SIZE) {
            showError('❌ File size exceeds 50MB limit.');
            fileInput.value = ''; // Clear the input if invalid
            fileUpload.querySelector('p').textContent = 'Drag & drop your PDF here or click to browse';
            return;
        }
        if (file.type !== 'application/pdf') {
            showError('❌ Please upload a PDF file only.');
            fileInput.value = ''; // Clear the input if invalid
            fileUpload.querySelector('p').textContent = 'Drag & drop your PDF here or click to browse';
            return;
        }
        
        fileUpload.querySelector('p').textContent = file.name;
        hideAlert();
    }

    // Event listener for file input changes (user clicks and selects)
    fileInput.addEventListener('change', function() {
        handleFile(this.files[0]);
    });

    // Event listeners for drag and drop
    fileUpload.addEventListener('dragover', (e) => {
        e.preventDefault();
        fileUpload.classList.add('border-blue-500', 'bg-blue-50');
    });

    fileUpload.addEventListener('dragleave', () => {
        e.preventDefault(); // Prevent file from opening in browser
        fileUpload.classList.remove('border-blue-500', 'bg-blue-50');
    });

    fileUpload.addEventListener('drop', (e) => {
        e.preventDefault();
        fileUpload.classList.remove('border-blue-500', 'bg-blue-50');

        if (e.dataTransfer.files.length > 0) {
            fileInput.files = e.dataTransfer.files; // Assign dropped files to input
            // Manually dispatch a change event to trigger the handleFile function
            const event = new Event('change', { bubbles: true });
            fileInput.dispatchEvent(event);
        }
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        // Basic form validation before AJAX
        if (!form.title.value.trim()) {
            showError('❌ Please enter an Edition Title.');
            return;
        }
        if (!form.publication_date.value) {
            showError('❌ Please select a Publication Date.');
            return;
        }
        if (!form.category_id.value) {
            showError('❌ Please select a Category.');
            return;
        }
        if (!fileInput.files || fileInput.files.length === 0) {
            showError('❌ Please select a PDF file to upload.');
            return;
        }
        // Additional file validation (size/type) is done in handleFile and server-side

        uploadFile();
    });

    function showAlert(message, type) {
        finalMessage.className = `alert alert-${type}`;
        finalMessage.innerHTML = `<div class="flex items-center gap-2"><i class="fas ${type === 'success' ? 'fa-check-circle' : (type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle')}"></i><span>${message}</span></div>`;
        finalMessage.style.display = 'block';
        finalMessage.style.opacity = '1'; // Ensure it's fully visible before fading

        // Set timeout to hide after 3 seconds
        setTimeout(() => {
            finalMessage.style.opacity = '0'; // Start fade out
            finalMessage.addEventListener('transitionend', () => {
                finalMessage.style.display = 'none'; // Hide completely after transition
            }, { once: true }); // Ensure listener is removed after one execution
        }, 3000);
    }

    function showError(message) {
        showAlert(message, 'error');
        // Ensure progress container is hidden and button is shown on error
        progressContainer.classList.add('hidden');
        uploadButton.classList.remove('hidden');
        clearInterval(processingInterval); // Stop any ongoing simulation
        processingSpinner.classList.add('hidden'); // Hide spinner on error
    }

    function showSuccess(message) {
        showAlert(message, 'success');
    }

    function hideAlert() { // Function to hide alert immediately
        finalMessage.style.display = 'none';
        finalMessage.style.opacity = '1'; // Reset opacity for next time
    }

    function uploadFile() {
        // Hide the upload button and show the progress bar
        uploadButton.classList.add('hidden');
        progressContainer.classList.remove('hidden');
        processingSpinner.classList.add('hidden'); // Ensure spinner is hidden initially

        hideAlert(); // Hide any existing alerts immediately
        progressFill.style.width = '0%';
        uploadPercent.textContent = '0%';
        uploadStatus.textContent = 'Uploading...';

        const formData = new FormData(form);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'upload-edition.php'); // POST to itself for processing
        xhr.timeout = 300000; // 5 minutes timeout

        xhr.upload.addEventListener('progress', e => {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                progressFill.style.width = `${percent}%`;
                uploadPercent.textContent = `${percent}%`;
                if (percent === 100) {
                    uploadStatus.textContent = 'Processing files on server...';
                    processingSpinner.classList.remove('hidden'); // Show spinner when processing
                    // Start simulated processing progress after upload is 100%
                    startSimulatedProcessing();
                }
            }
        });

        xhr.onload = () => {
            clearInterval(processingInterval); // Stop simulated processing
            processingSpinner.classList.add('hidden'); // Hide spinner on load
            // Set final progress to 100% before hiding
            progressFill.style.width = '100%';
            uploadPercent.textContent = '100%'; // Ensure 100% is shown before hiding
            uploadPercent.classList.remove('hidden'); // Ensure percentage is visible if it was hidden

            // Hide progress bar and show button
            progressContainer.classList.add('hidden');
            uploadButton.classList.remove('hidden');

            try {
                const res = JSON.parse(xhr.responseText);
                if (res.success) {
                    showSuccess(res.message);
                    setTimeout(() => {
                        window.location.href = res.redirect || 'manage-editions.php';
                    }, 2000); // Redirect after 2 seconds
                } else {
                    showError(res.message);
                }
            } catch (e) {
                showError('Failed to process server response. This could be a server error. Check browser console and server logs.');
                console.error('Response parsing error:', e);
                console.error('Full server response received:', xhr.responseText);
            }
        };

        xhr.onerror = () => {
            clearInterval(processingInterval); // Stop simulated processing
            processingSpinner.classList.add('hidden'); // Hide spinner on error
            uploadPercent.classList.remove('hidden'); // Ensure percentage is visible on error
            // Hide progress bar and show button
            progressContainer.classList.add('hidden');
            uploadButton.classList.remove('hidden');
            showError('Network error occurred during upload. Please check your internet connection.');
        };

        xhr.ontimeout = () => {
            clearInterval(processingInterval); // Stop simulated processing
            processingSpinner.classList.add('hidden'); // Hide spinner on timeout
            uploadPercent.classList.remove('hidden'); // Ensure percentage is visible on timeout
            // Hide progress bar and show button
            progressContainer.classList.add('hidden');
            uploadButton.classList.remove('hidden');
            showError('Request timed out. Server processing took too long.');
        };

        xhr.send(formData);
    }

    // Function to start simulated processing progress
    function startSimulatedProcessing() {
        let simulatedPercent = 0;
        // The duration here is an estimate for how long the server might take.
        // Adjust this value (in milliseconds) based on typical server processing times.
        const duration = 10000; // 10 seconds for simulated processing to reach 100%
        const intervalTime = 50; // Update every 50ms
        const increment = (100 / (duration / intervalTime));

        const phases = [
            { threshold: 30, message: 'Image Conversions...' },
            { threshold: 70, message: 'Thumbnail Creation...' },
            { threshold: 100, message: 'Finalizing...' } // This phase will hide the percentage
        ];
        let currentPhaseIndex = 0;

        processingInterval = setInterval(() => {
            simulatedPercent += increment;
            if (simulatedPercent > 100) {
                simulatedPercent = 100; // Ensure it doesn't go over 100%
            }

            // Update status message based on phases
            if (currentPhaseIndex < phases.length && simulatedPercent >= phases[currentPhaseIndex].threshold) {
                uploadStatus.textContent = phases[currentPhaseIndex].message;
                // If it's the "Finalizing" phase, hide the percentage
                if (phases[currentPhaseIndex].message === 'Finalizing...') {
                    uploadPercent.classList.add('hidden'); // Hide the percentage
                } else {
                    uploadPercent.classList.remove('hidden'); // Ensure it's visible for other phases
                }
                currentPhaseIndex++;
            }

            // Only update percentage if it's not the finalizing stage
            if (!uploadPercent.classList.contains('hidden')) {
                uploadPercent.textContent = `${Math.round(simulatedPercent)}%`;
            }
            
            progressFill.style.width = `${simulatedPercent}%`;

            // If simulated progress reaches 100%, clear interval but keep UI at 100%
            // It will then wait for the actual server response to change status/redirect
            if (simulatedPercent === 100) {
                clearInterval(processingInterval);
            }
        }, intervalTime);
    }
});
</script>
</body>
</html>
