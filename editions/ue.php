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
            $command_list_thumb = $magick_exe_escaped . ' ' . escapeshellarg($first_page_server_path) . ' -resize x' . LIST_THUMB_HEIGHT . ' -quality 85 ' . escapeshellarg($list_thumb_server_path) . ' 2>&1';
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
                title, publication_date, category_id, description,
                pdf_path, og_image_path, list_thumb_path, page_count,
                file_size_bytes, uploader_user_id, status, created_at, updated_at
            ) VALUES (
                :title, :publication_date, :category_id, :description,
                :pdf_path, :og_image_path, :list_thumb_path, :page_count,
                :file_size_bytes, :uploader_user_id, :status, NOW(), NOW()
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
            ':status' => $status,
        ]);

        $editionId = $pdo->lastInsertId();

        $pdo->commit(); // Commit transaction

        echo json_encode(['success' => true, 'message' => 'Edition uploaded successfully!', 'edition_id' => $editionId]);

    } catch (Exception $e) {
        $pdo->rollBack(); // Rollback on error
        error_log("Edition upload failed: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred during the upload process: ' . $e->getMessage()]);

        // Cleanup any partial files/directories created
        if (isset($full_edition_dir) && is_dir($full_edition_dir)) {
            // A simple rrmdir function to delete directory and its contents
            function rrmdir($dir) {
                if (is_dir($dir)) {
                    $objects = scandir($dir);
                    foreach ($objects as $object) {
                        if ($object != "." && $object != "..") {
                            if (is_dir($dir."/".$object)) {
                                rrmdir($dir."/".$object);
                            } else {
                                unlink($dir."/".$object);
                            }
                        }
                    }
                    rmdir($dir);
                }
            }
            rrmdir($full_edition_dir);
        }
    }
    exit;
}
// --- END Handle Form Submission ---

// --- HTML PAGE STRUCTURE ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Edition Management</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome CDN for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- Custom CSS for the progress bar -->
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
        }
        .progress-bar {
            background-color: #e5e7eb;
            border-radius: 9999px; /* Tailwind's rounded-full */
            height: 1.5rem; /* h-6 */
            width: 100%;
        }
        .progress-fill {
            background-color: #4f46e5; /* Tailwind's indigo-600 */
            border-radius: 9999px;
            transition: width 0.5s ease-in-out;
            height: 100%;
        }
        .hidden {
            display: none;
        }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">

<div class="max-w-2xl w-full mx-auto p-6 bg-white rounded-xl shadow-lg mt-10">

    <!-- Header and Navigation -->
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-900"><?= htmlspecialchars($pageTitle) ?></h1>
        <nav>
            <a href="dashboard.php" class="text-indigo-600 hover:text-indigo-800 transition-colors duration-200 font-medium">
                <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
            </a>
        </nav>
    </div>

    <!-- Session Messages -->
    <div class="mb-4">
        <?php display_session_message(); ?>
    </div>

    <!-- Upload Form -->
    <form id="uploadForm" method="POST" enctype="multipart/form-data" class="space-y-6">

        <!-- Title -->
        <div>
            <label for="title" class="block text-sm font-medium text-gray-700">Edition Title</label>
            <input type="text" name="title" id="title" required
                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
        </div>

        <!-- Publication Date -->
        <div>
            <label for="publication_date" class="block text-sm font-medium text-gray-700">Publication Date</label>
            <input type="date" name="publication_date" id="publication_date" required
                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
        </div>

        <!-- Category -->
        <div>
            <label for="category_id" class="block text-sm font-medium text-gray-700">Category</label>
            <select name="category_id" id="category_id" required
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2">
                <option value="">Select a Category</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= htmlspecialchars($category['category_id']) ?>">
                        <?= htmlspecialchars($category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Description -->
        <div>
            <label for="description" class="block text-sm font-medium text-gray-700">Description (Optional)</label>
            <textarea name="description" id="description" rows="3"
                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2"></textarea>
        </div>

        <!-- Status -->
        <div>
            <label class="block text-sm font-medium text-gray-700">Status</label>
            <div class="mt-2 space-y-2">
                <div class="flex items-center">
                    <input id="status-private" name="status" type="radio" value="private" checked
                           class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300">
                    <label for="status-private" class="ml-3 block text-sm font-medium text-gray-700">
                        Private <span class="text-gray-500">(Only visible to Admins/SuperAdmins)</span>
                    </label>
                </div>
                <div class="flex items-center">
                    <input id="status-public" name="status" type="radio" value="public"
                           class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300">
                    <label for="status-public" class="ml-3 block text-sm font-medium text-gray-700">
                        Public <span class="text-gray-500">(Visible to all users)</span>
                    </label>
                </div>
            </div>
        </div>

        <!-- PDF File Upload -->
        <div>
            <label for="pdf_file" class="block text-sm font-medium text-gray-700">
                PDF File <span class="text-gray-500">(Max 50MB)</span>
            </label>
            <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md">
                <div class="space-y-1 text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48" aria-hidden="true">
                        <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H16a4 4 0 01-4-4v-4m32-4l-3.26-1.53A4 4 0 0038 18.57V12a4 4 0 00-4-4h-8m-2 10.74l-6 1.83m6 2.37v2.85M16 28h12" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <div class="flex text-sm text-gray-600">
                        <label for="pdf_file" class="relative cursor-pointer bg-white rounded-md font-medium text-indigo-600 hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                            <span>Upload a file</span>
                            <input id="pdf_file" name="pdf_file" type="file" class="sr-only" accept="application/pdf" required>
                        </label>
                        <p class="pl-1">or drag and drop</p>
                    </div>
                    <p id="fileName" class="text-xs text-gray-500"></p>
                </div>
            </div>
        </div>

        <!-- Submit Button -->
        <div>
            <button type="submit" id="submitBtn" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200">
                <i class="fas fa-cloud-upload-alt mr-2"></i>Upload Edition
            </button>
        </div>
    </form>

    <!-- Upload Progress & Status -->
    <div id="progressContainer" class="mt-8 p-4 bg-gray-50 rounded-xl hidden">
        <h3 class="text-xl font-bold text-gray-800 mb-2">Upload Status</h3>
        <div class="progress-bar">
            <div class="progress-fill" style="width: 0%;"></div>
        </div>
        <div class="flex justify-between mt-2 text-sm">
            <span id="uploadStatus" class="font-medium text-gray-700">Awaiting file upload...</span>
            <span id="uploadPercent" class="font-medium text-indigo-600">0%</span>
        </div>
        <div id="uploadMessage" class="mt-4 text-center"></div>
    </div>

</div>

<!-- JavaScript for AJAX Upload and Progress Simulation -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const uploadForm = document.getElementById('uploadForm');
    const submitBtn = document.getElementById('submitBtn');
    const pdfFileInput = document.getElementById('pdf_file');
    const progressContainer = document.getElementById('progressContainer');
    const progressFill = progressContainer.querySelector('.progress-fill');
    const uploadStatus = document.getElementById('uploadStatus');
    const uploadPercent = document.getElementById('uploadPercent');
    const uploadMessage = document.getElementById('uploadMessage');
    const fileNameDisplay = document.getElementById('fileName');
    let processingInterval;
    const intervalTime = 500; // Time in milliseconds for each progress step

    // Display selected file name
    pdfFileInput.addEventListener('change', (e) => {
        const fileName = e.target.files[0]?.name;
        fileNameDisplay.textContent = fileName || '';
    });

    uploadForm.addEventListener('submit', async function(e) {
        e.preventDefault();

        // Show progress bar and reset state
        progressContainer.classList.remove('hidden');
        progressFill.style.width = '0%';
        uploadStatus.textContent = 'Starting upload...';
        uploadPercent.textContent = '0%';
        uploadPercent.classList.remove('hidden');
        uploadMessage.innerHTML = '';
        submitBtn.disabled = true;

        const formData = new FormData(uploadForm);
        const file = pdfFileInput.files[0];

        // Simulate file upload progress
        startSimulatedUpload(file.size);

        try {
            const response = await fetch('upload-edition.php', {
                method: 'POST',
                body: formData
            });

            // Clear the simulation interval as the real response is here
            clearInterval(processingInterval);

            const result = await response.json();

            // Update UI based on server response
            if (result.success) {
                uploadStatus.textContent = 'Upload successful!';
                uploadPercent.textContent = '100%';
                progressFill.style.width = '100%';
                uploadMessage.innerHTML = `<div class="alert alert-success text-green-700 bg-green-100 p-3 rounded-md mt-4"><i class="fas fa-check-circle mr-2"></i>${result.message} Redirecting...</div>`;
                // Wait a moment then redirect
                setTimeout(() => {
                    window.location.href = `view_edition.php?id=${result.edition_id}`;
                }, 2000);
            } else {
                uploadStatus.textContent = 'Upload failed';
                uploadMessage.innerHTML = `<div class="alert alert-error text-red-700 bg-red-100 p-3 rounded-md mt-4"><i class="fas fa-exclamation-circle mr-2"></i>${result.message}</div>`;
                progressFill.style.width = '100%'; // Show full red bar if error happens after start
            }

        } catch (error) {
            clearInterval(processingInterval); // Stop simulation on network error
            uploadStatus.textContent = 'Network error';
            uploadMessage.innerHTML = `<div class="alert alert-error text-red-700 bg-red-100 p-3 rounded-md mt-4"><i class="fas fa-exclamation-triangle mr-2"></i> A network error occurred. Please try again.</div>`;
            error_log('Fetch error:', error);
        } finally {
            submitBtn.disabled = false;
        }
    });

    function startSimulatedUpload(fileSize) {
        let simulatedPercent = 0;
        let currentPhaseIndex = 0;
        const phases = [
            { threshold: 10, message: 'Uploading file...' },
            { threshold: 50, message: 'Processing PDF...' },
            { threshold: 80, message: 'Generating images...' },
            { threshold: 95, message: 'Finalizing...' }
        ];

        // A simple formula to make progress seem realistic
        const totalSteps = 100;
        const totalTime = 10000; // 10 seconds total simulation time
        const increment = (100 / totalSteps);
        const intervalTime = totalTime / totalSteps;

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
