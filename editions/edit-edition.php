<?php
// File: edit-edition.php
// Allows SuperAdmins and Admins to edit existing edition details.

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
// For example, if edit-edition.php is in /public_html/editions/, and config.php is in /public_html/config/
require_once __DIR__ . '/../config/config.php';

date_default_timezone_set('Asia/Kolkata'); // Set timezone for date display

// --- Define Logged-in State and User Role ---
$loggedIn = isset($_SESSION['user_id']);
$username = $loggedIn ? htmlspecialchars($_SESSION['username']) : null;
$userRole = $loggedIn ? ($_SESSION['user_role'] ?? 'Viewer') : null;

// Session Management & Inactivity Timeout (copied from view-editions.php for consistency)
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

// --- Configuration for ImageMagick and Thumbnail Sizes ---
// IMPORTANT: VERIFY THESE PATHS ON YOUR SERVER!
const GS_BIN_DIR = '/usr/bin'; // Common path for Ghostscript executables (gs, gsutil etc.)
const IMAGEMAGICK_HOME_DIR = '/usr'; // Common ImageMagick install root (where policies.xml might be)
const MAGICK_EXE_PATH = '/usr/bin/convert'; // Use 'convert' command if 'magick' is not found or preferred for older IM versions.

// Thumbnail sizes
const OG_THUMB_WIDTH = 1200; // Open Graph optimal width for landscape previews
const OG_THUMB_HEIGHT = 600; // Optimal height for top-aligned crop
const LIST_THUMB_HEIGHT = 1200; // Height for list view thumbnail, width will be proportional
// --- END Configuration for ImageMagick and Thumbnail Sizes ---

// Function to recursively delete directory contents
if (!function_exists('deleteDir')) {
    function deleteDir($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? deleteDir("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }
}

// --- Handle Form Submission (AJAX POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json'); // Ensure JSON response for AJAX
    ini_set('display_errors', 0); // Hide errors from output for JSON
    error_reporting(E_ALL);

    $edition_id = filter_input(INPUT_POST, 'edition_id', FILTER_VALIDATE_INT);
    $title = trim($_POST['title'] ?? '');
    $publication_date = trim($_POST['publication_date'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $description = trim($_POST['description'] ?? '');
    $description = ($description === '') ? null : $description; // Set empty string to null for DB
    $status = trim($_POST['status'] ?? 'private'); // Default to 'private' if not set
    $current_pdf_path = trim($_POST['current_pdf_path'] ?? ''); // Path to the existing PDF
    $new_pdf_file = $_FILES['new_pdf'] ?? null; // New PDF file if uploaded

    // Basic validation
    if (empty($edition_id)) {
        echo json_encode(['success' => false, 'message' => 'Edition ID is missing.']);
        exit;
    }
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

    $pdo->beginTransaction(); // Start transaction

    try {
        $pdf_web_path = $current_pdf_path; // Default to current path
        $og_image_web_path = null;
        $list_thumb_web_path = null;
        $page_count = null;
        $file_size_bytes = null;

        // Check if a new PDF was uploaded
        if ($new_pdf_file && $new_pdf_file['error'] === UPLOAD_ERR_OK) {
            if ($new_pdf_file['type'] !== 'application/pdf') {
                throw new Exception('Invalid file type. Only PDF files are allowed for new upload.');
            }
            if ($new_pdf_file['size'] > 50 * 1024 * 1024) { // 50MB limit
                throw new Exception('New PDF file size exceeds 50MB limit.');
            }

            // 1. Determine base upload directory for current edition's files
            // Extract the unique folder name from the current_pdf_path
            // Example: /../uploads/editions/2024/01/15/2024-01-15_123456_uniqueid/edition-15-01-2024.pdf
            $base_upload_dir_relative = '/../uploads/editions/';
            $current_edition_dir_relative = dirname($current_pdf_path); // e.g., /../uploads/editions/2024/01/15/2024-01-15_123456_uniqueid
            $current_edition_dir_full_path = __DIR__ . '/' . $current_edition_dir_relative;

            // 2. Delete old PDF and images if they exist
            if (is_dir($current_edition_dir_full_path)) {
                if (!deleteDir($current_edition_dir_full_path)) {
                    error_log("Failed to clean up old edition directory: " . $current_edition_dir_full_path);
                    // Don't throw exception, just log, as we're proceeding with new upload
                }
            }

            // 3. Create new unique directory structure based on new publication date and unique ID
            $pub_date_obj = DateTime::createFromFormat('Y-m-d', $publication_date);
            $pub_year = $pub_date_obj->format('Y');
            $pub_month = $pub_date_obj->format('m');
            $pub_date_day = $pub_date_obj->format('d');
            $current_time_hhmmss = date('His');

            $unique_folder_name = "{$publication_date}_{$current_time_hhmmss}_" . uniqid();
            $date_sub_path = "{$pub_year}/{$pub_month}/{$pub_date_day}/";
            $full_edition_dir = __DIR__ . '/' . $base_upload_dir_relative . $date_sub_path . $unique_folder_name . '/';

            if (!is_dir($full_edition_dir)) {
                if (!mkdir($full_edition_dir, 0755, true)) {
                    throw new Exception('Failed to create new directory for edition.');
                }
            }

            // 4. Move new uploaded PDF file
            $pdf_file_name = 'edition-' . $pub_date_obj->format('d-m-Y') . '.pdf';
            $pdf_target_path = $full_edition_dir . $pdf_file_name;
            if (!move_uploaded_file($new_pdf_file['tmp_name'], $pdf_target_path)) {
                throw new Exception('Failed to move new uploaded PDF file.');
            }
            $pdf_web_path = $base_upload_dir_relative . $date_sub_path . $unique_folder_name . '/' . $pdf_file_name;
            if (!chmod($pdf_target_path, 0644)) {
                error_log("Failed to set permissions for new PDF file: " . $pdf_target_path);
            }

            // 5. Create new images subdirectory
            $images_dir = $full_edition_dir . 'images/';
            if (!is_dir($images_dir)) {
                if (!mkdir($images_dir, 0755)) {
                    throw new Exception('Failed to create new images directory.');
                }
            }

            // 6. Convert new PDF to images using ImageMagick
            $original_path = getenv('PATH');
            putenv('PATH=' . GS_BIN_DIR . PATH_SEPARATOR . $original_path);
            putenv('MAGICK_HOME=' . IMAGEMAGICK_HOME_DIR);

            $magick_exe_escaped = escapeshellarg(MAGICK_EXE_PATH);
            $pdf_target_path_escaped = escapeshellarg($pdf_target_path);
            $temp_images_pattern = $images_dir . 'temp_raw_%03d.jpg';
            $temp_images_output_escaped = escapeshellarg($temp_images_pattern);

            // ImageMagick command for PDF to JPG conversion
            // -density 300: Sets the resolution for rendering PDF pages. Higher density means larger, higher quality images, but consumes more memory.
            // -quality 85: Sets the JPEG compression quality. 85 is a good balance between file size and quality.
            // -scene 1: Starts numbering output files from 1 (e.g., temp_raw_001.jpg, temp_raw_002.jpg)
            $command = $magick_exe_escaped . ' -density 300 ' . $pdf_target_path_escaped .
                       ' -quality 85 -scene 1 ' . $temp_images_output_escaped . ' 2>&1';

            error_log("Attempting ImageMagick command for new PDF: " . $command);
            exec($command, $output, $return_var);

            // --- IMPORTANT: Troubleshooting ImageMagick Memory Issues ---
            // If you encounter "Insufficient memory" errors from ImageMagick, consider these solutions:
            // 1. Increase PHP's memory_limit: Edit your php.ini file (e.g., memory_limit = 256M or 512M).
            // 2. Adjust ImageMagick's policy.xml: This file (often in /etc/ImageMagick-6/policy.xml or /etc/ImageMagick/policy.xml)
            //    controls resource limits for ImageMagick. You might need to increase the 'disk', 'memory', or 'map' limits.
            //    Example: <policy domain="resource" name="memory" value="2GiB"/>
            //             <policy domain="resource" name="disk" value="4GiB"/>
            // 3. Reduce ImageMagick density: Lowering '-density' (e.g., to 150 or 72) will create smaller images,
            //    reducing memory consumption, but also reducing image quality.
            //    Example: $magick_exe_escaped . ' -density 150 ' . ...
            // 4. For very large PDFs, consider processing each page individually in a loop if your ImageMagick version supports it,
            //    though this requires more complex scripting.
            // --- End Troubleshooting Notes ---

            if ($return_var !== 0) {
                putenv('PATH=' . $original_path);
                throw new Exception("New PDF conversion failed: " . implode("\n", $output));
            }

            $generated_temp_files = glob($images_dir . 'temp_raw_*.jpg');
            if (empty($generated_temp_files)) {
                putenv('PATH=' . $original_path);
                throw new Exception("Image conversion completed but no temporary image files were found for new PDF.");
            }

            $page_count = 0;
            $first_page_server_path = null;

            foreach ($generated_temp_files as $temp_image_file) {
                $page_count++;
                $final_image_name = sprintf('page-%d.jpg', $page_count);
                $final_image_path = $images_dir . $final_image_name;
                if (!rename($temp_image_file, $final_image_path)) {
                    error_log("Failed to rename temporary image file '{$temp_image_file}' to '{$final_image_path}'.");
                    putenv('PATH=' . $original_path);
                    throw new Exception("Failed to finalize image naming for new PDF page " . $page_count . ".");
                }
                if (!chmod($final_image_path, 0644)) {
                    error_log("Failed to set permissions for new image file: " . $final_image_path);
                }
                if ($page_count === 1) {
                    $first_page_server_path = $final_image_path;
                }
            }

            // Generate Open Graph and List View Thumbnails from the first page of new PDF
            $og_image_name = 'og-thumb.jpg';
            $og_image_server_path = $images_dir . $og_image_name;
            $og_image_web_path = $base_upload_dir_relative . $date_sub_path . $unique_folder_name . '/images/' . $og_image_name;

            $command_og = $magick_exe_escaped . ' ' . escapeshellarg($first_page_server_path) .
                          ' -resize ' . OG_THUMB_WIDTH . 'x' .
                          ' -gravity North -crop ' . OG_THUMB_WIDTH . 'x' . OG_THUMB_HEIGHT . '+0+0 +repage' .
                          ' -quality 85 ' . escapeshellarg($og_image_server_path) . ' 2>&1';
            exec($command_og, $output_og, $return_var_og);
            if ($return_var_og !== 0) {
                error_log('New OG thumbnail generation failed. Command: ' . $command_og . ' Output: ' . implode("\n", $output_og));
                $og_image_web_path = null;
            } else {
                if (!chmod($og_image_server_path, 0644)) {
                    error_log("Failed to set permissions for new OG thumbnail: " . $og_image_server_path);
                }
            }

            $list_thumb_name = 'list-thumb.jpg';
            $list_thumb_server_path = $images_dir . $list_thumb_name;
            $list_thumb_web_path = $base_upload_dir_relative . $date_sub_path . $unique_folder_name . '/images/' . $list_thumb_name;

            $command_list_thumb = $magick_exe_escaped . ' ' . escapeshellarg($first_page_server_path) .
                                  ' -resize x' . LIST_THUMB_HEIGHT . ' -quality 85 ' .
                                  escapeshellarg($list_thumb_server_path) . ' 2>&1';
            exec($command_list_thumb, $output_list_thumb, $return_var_list_thumb);
            if ($return_var_list_thumb !== 0) {
                error_log('New List thumbnail generation failed. Command: ' . $command_list_thumb . ' Output: ' . implode("\n", $output_list_thumb));
                $list_thumb_web_path = null;
            } else {
                if (!chmod($list_thumb_server_path, 0644)) {
                    error_log("Failed to set permissions for new list thumbnail: " . $list_thumb_server_path);
                }
            }

            putenv('PATH=' . $original_path); // Restore original PATH
            $file_size_bytes = $new_pdf_file['size'];

        } else {
            // No new PDF uploaded, retrieve existing image paths and page count from DB
            $stmt_current_files = $pdo->prepare("SELECT og_image_path, list_thumb_path, page_count, file_size_bytes FROM editions WHERE edition_id = :edition_id");
            $stmt_current_files->execute([':edition_id' => $edition_id]);
            $current_file_data = $stmt_current_files->fetch(PDO::FETCH_ASSOC);
            if ($current_file_data) {
                $og_image_web_path = $current_file_data['og_image_path'];
                $list_thumb_web_path = $current_file_data['list_thumb_path'];
                $page_count = $current_file_data['page_count'];
                $file_size_bytes = $current_file_data['file_size_bytes'];
            }
            // If no new PDF, and current data is not found, this is an issue, but we proceed with nulls for now.
        }

        // Update data in database
        $stmt = $pdo->prepare("
            UPDATE editions SET
                title = :title,
                publication_date = :publication_date,
                category_id = :category_id,
                description = :description,
                pdf_path = :pdf_path,
                og_image_path = :og_image_path,
                list_thumb_path = :list_thumb_path,
                page_count = :page_count,
                file_size_bytes = :file_size_bytes,
                status = :status,
                updated_at = NOW()
            WHERE edition_id = :edition_id
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
            ':file_size_bytes' => $file_size_bytes,
            ':status' => $status,
            ':edition_id' => $edition_id
        ]);

        $pdo->commit(); // Commit transaction

        echo json_encode(['success' => true, 'message' => 'Edition updated successfully!', 'redirect' => 'manage-editions.php']);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack(); // Rollback on error
        error_log("Edition update failed: " . $e->getMessage());

        // Attempt to clean up partially created files/directories on failure if a new PDF was uploaded
        if (isset($full_edition_dir) && is_dir($full_edition_dir)) {
            error_log("Attempting to clean up new directory on failure: " . $full_edition_dir);
            try {
                deleteDir($full_edition_dir);
                error_log("Cleaned up new directory: " . $full_edition_dir);
            } catch (Exception $cleanup_e) {
                error_log("Failed to clean up new directory " . $full_edition_dir . ": " . $cleanup_e->getMessage());
            }
        }

        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
        exit;
    }
}


// --- Fetch Edition Data for Editing (for GET request to display the form) ---
$edition_id = $_GET['id'] ?? 0;
$edition = null;
$categories = []; // To populate category dropdown
$pageTitle = "Edit Edition";

if (!empty($edition_id)) {
    try {
        // Fetch edition details, including 'status' column
        $stmt = $pdo->prepare("SELECT edition_id, title, publication_date, category_id, description, pdf_path, created_at, updated_at, status FROM editions WHERE edition_id = :edition_id");
        $stmt->execute([':edition_id' => $edition_id]);
        $edition = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$edition) {
            $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Edition not found.</div>';
            header("Location: manage-editions.php"); // Redirect to manage-editions.php
            exit;
        }

        // Fetch all categories for the dropdown
        $stmt = $pdo->query("SELECT category_id, name FROM categories ORDER BY name ASC");
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Database error fetching edition or categories for editing: " . $e->getMessage());
        $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> Database error while loading edition for editing.</div>';
        header("Location: manage-editions.php"); // Redirect to manage-editions.php
        exit;
    }
} else {
    $_SESSION['message'] = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-2"></i> No edition ID provided for editing.</div>';
    header("Location: manage-editions.php"); // Redirect to manage-editions.php
    exit;
}

// Function to display session messages (copied for consistency)
if (!function_exists('display_session_message')) {
    function display_session_message() {
        if (isset($_SESSION['message'])) {
            echo $_SESSION['message'];
            unset($_SESSION['message']);
        }
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
        .edit-container {
            background: white;
            padding: 2rem;
            border-radius: 1rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            max-width: 1200px; /* Wider layout */
            margin: 2rem auto; /* Center the container horizontally */
        }

        .edit-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .edit-header i {
            font-size: 1.5rem;
            color: var(--primary-color);
        }

        .edit-header h1 {
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
            /* Adjusted padding to match progress bar height */
            padding: 0.75rem 1.5rem; /* Changed from 0.75rem to 0.75rem */
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
            background-color: #dcfce7;
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
            .edit-container {
                padding: 1.5rem;
                margin: 1rem;
            }

            .edit-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .edit-header h1 {
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
                    <i class="fas fa-edit text-2xl text-blue-600"></i>
                    <h1 class="text-2xl font-semibold text-gray-800">Edit Edition: <?= htmlspecialchars($edition['title']) ?></h1>
                </div>
                <a href="manage-editions.php" class="w-full sm:w-auto inline-flex items-center px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400 transition duration-300 justify-center">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Editions
                </a>
            </div>

            <form id="editEditionForm" enctype="multipart/form-data">
                <input type="hidden" name="edition_id" value="<?= htmlspecialchars($edition['edition_id']) ?>">
                <input type="hidden" name="current_pdf_path" value="<?= htmlspecialchars($edition['pdf_path']) ?>">

                <div class="grid grid-cols-1 md:grid-cols-3 gap-x-4">
                    <div class="form-group">
                        <label for="title">Edition Title</label>
                        <input type="text" id="title" name="title" class="form-control" placeholder="Enter edition title" value="<?= htmlspecialchars($edition['title']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="publication_date">Publication Date</label>
                        <input type="date" id="publication_date" name="publication_date" class="form-control" value="<?= htmlspecialchars($edition['publication_date']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="category_id">Category</label>
                        <select id="category_id" name="category_id" class="form-control" required>
                            <option value="">Select a Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat['category_id']) ?>"
                                    <?= ($cat['category_id'] == $edition['category_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="description">Description (Optional)</label>
                    <textarea id="description" name="description" class="form-control" rows="4" placeholder="Enter a brief description for this edition"><?= htmlspecialchars($edition['description']) ?></textarea>
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="form-control" required>
                        <option value="published" <?= ($edition['status'] == 'published') ? 'selected' : '' ?>>Published</option>
                        <option value="private" <?= ($edition['status'] == 'private') ? 'selected' : '' ?>>Private</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Current PDF File</label>
                    <div class="mb-2 p-3 bg-gray-100 border border-gray-200 rounded-md flex items-center justify-between flex-wrap gap-2">
                        <span class="text-sm text-gray-700 font-medium break-all mr-2">
                            <i class="fas fa-file-pdf text-2xl text-red-600 mr-2 inline-block"></i>
                            <?= htmlspecialchars(basename($edition['pdf_path'])) ?>
                        </span>
                        <a href="<?= htmlspecialchars($edition['pdf_path']) ?>" target="_blank" class="text-blue-600 hover:underline text-sm flex items-center">
                            View Current PDF <i class="fas fa-external-link-alt ml-1 text-xs"></i>
                        </a>
                    </div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 mt-4">Upload New PDF (Optional)</label>
                    <div class="file-upload">
                        <i class="fas fa-file-pdf text-2xl text-red-600"></i>
                        <p>Drag & drop new PDF here or click to browse (replaces current PDF)</p>
                        <input type="file" name="new_pdf" accept="application/pdf">
                    </div>
                    <p class="text-sm text-gray-500 mt-1">Max file size: 50MB. Leave empty to keep current PDF.</p>
                </div>

                <div class="flex flex-col sm:flex-row justify-end gap-3 mt-6">
                    <!-- New container for button and progress bar -->
                    <div id="saveActionContainer" class="flex-1 flex justify-end">
                        <button type="submit" id="saveButton" class="btn-primary flex items-center justify-center sm:w-auto">
                            <i class="fas fa-save mr-2"></i> Save Changes
                        </button>
                        <div id="progressContainer" class="progress-container hidden w-full sm:w-auto">
                            <div class="flex items-center justify-between text-sm text-gray-700">
                                <!-- Group status and spinner together -->
                                <div class="flex items-center">
                                    <span id="uploadStatus">Processing Changes...</span>
                                    <span id="processingSpinner" class="hidden"><i class="fas fa-spinner fa-spin ml-2 text-blue-500"></i></span>
                                </div>
                                <span id="uploadPercent">0%</span>
                            </div>
                            <div class="progress-bar">
                                <div id="progressFill" class="progress-fill"></div>
                            </div>
                        </div>
                    </div>
                    <a href="manage-editions.php" class="inline-flex items-center justify-center px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400 transition duration-300 sm:w-auto flex-shrink-0">
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
    const form = document.getElementById('editEditionForm');
    const fileUpload = document.querySelector('.file-upload');
    const fileInput = document.querySelector('.file-upload input[type="file"]');
    const progressContainer = document.getElementById('progressContainer');
    const uploadStatus = document.getElementById('uploadStatus');
    const uploadPercent = document.getElementById('uploadPercent');
    const progressFill = document.getElementById('progressFill');
    const finalMessage = document.getElementById('finalMessage');
    const saveButton = document.getElementById('saveButton');
    const processingSpinner = document.getElementById('processingSpinner');

    const MAX_FILE_SIZE = 50 * 1024 * 1024; // 50MB in bytes
    let processingInterval;

    function handleFile(file) {
        if (!file) {
            fileUpload.querySelector('p').textContent = 'Drag & drop new PDF here or click to browse (replaces current PDF)';
            hideAlert();
            return;
        }

        if (file.size > MAX_FILE_SIZE) {
            showError('❌ File size exceeds 50MB limit.');
            fileInput.value = '';
            fileUpload.querySelector('p').textContent = 'Drag & drop new PDF here or click to browse (replaces current PDF)';
            return;
        }
        if (file.type !== 'application/pdf') {
            showError('❌ Please upload a PDF file only.');
            fileInput.value = '';
            fileUpload.querySelector('p').textContent = 'Drag & drop new PDF here or click to browse (replaces current PDF)';
            return;
        }
        
        fileUpload.querySelector('p').textContent = file.name;
        hideAlert();
    }

    fileInput.addEventListener('change', function() {
        handleFile(this.files[0]);
    });

    fileUpload.addEventListener('dragover', (e) => {
        e.preventDefault();
        fileUpload.classList.add('border-blue-500', 'bg-blue-50');
    });

    fileUpload.addEventListener('dragleave', () => {
        e.preventDefault();
        fileUpload.classList.remove('border-blue-500', 'bg-blue-50');
    });

    fileUpload.addEventListener('drop', (e) => {
        e.preventDefault();
        fileUpload.classList.remove('border-blue-500', 'bg-blue-50');

        if (e.dataTransfer.files.length > 0) {
            fileInput.files = e.dataTransfer.files;
            const event = new Event('change', { bubbles: true });
            fileInput.dispatchEvent(event);
        }
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();

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

        saveChanges();
    });

    function showAlert(message, type) {
        finalMessage.className = `alert alert-${type}`;
        finalMessage.innerHTML = `<div class="flex items-center gap-2"><i class="fas ${type === 'success' ? 'fa-check-circle' : (type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle')}"></i><span>${message}</span></div>`;
        finalMessage.style.display = 'block';
        finalMessage.style.opacity = '1';

        setTimeout(() => {
            finalMessage.style.opacity = '0';
            finalMessage.addEventListener('transitionend', () => {
                finalMessage.style.display = 'none';
            }, { once: true });
        }, 3000);
    }

    function showError(message) {
        showAlert(message, 'error');
        progressContainer.classList.add('hidden');
        saveButton.classList.remove('hidden');
        clearInterval(processingInterval);
        processingSpinner.classList.add('hidden');
    }

    function showSuccess(message) {
        showAlert(message, 'success');
    }

    function hideAlert() {
        finalMessage.style.display = 'none';
        finalMessage.style.opacity = '1';
    }

    function saveChanges() {
        saveButton.classList.add('hidden');
        progressContainer.classList.remove('hidden');
        processingSpinner.classList.add('hidden');

        hideAlert();
        progressFill.style.width = '0%';
        uploadPercent.textContent = '0%';
        uploadStatus.textContent = 'Processing Changes...';

        const formData = new FormData(form);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'edit-edition.php');
        xhr.timeout = 300000;

        xhr.upload.addEventListener('progress', e => {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                progressFill.style.width = `${percent}%`;
                uploadPercent.textContent = `${percent}%`;
                if (percent === 100) {
                    uploadStatus.textContent = 'Processing files on server...'; // Matched upload-edition.php
                    processingSpinner.classList.remove('hidden');
                    startSimulatedProcessing();
                }
            }
        });

        xhr.onload = () => {
            clearInterval(processingInterval);
            processingSpinner.classList.add('hidden');
            progressFill.style.width = '100%';
            uploadPercent.textContent = '100%';
            uploadPercent.classList.remove('hidden');

            progressContainer.classList.add('hidden');
            saveButton.classList.remove('hidden');

            try {
                const res = JSON.parse(xhr.responseText);
                if (res.success) {
                    showSuccess(res.message);
                    setTimeout(() => {
                        window.location.href = 'manage-editions.php';
                    }, 2000);
                } else {
                    showError(res.message);
                }
            } catch (e) {
                showError('Failed to process server response for editing. This could be a server error. Check browser console and server logs.');
                console.error('Response parsing error:', e);
                console.error('Full server response received:', xhr.responseText);
            }
        };

        xhr.onerror = () => {
            clearInterval(processingInterval);
            processingSpinner.classList.add('hidden');
            uploadPercent.classList.remove('hidden');
            progressContainer.classList.add('hidden');
            saveButton.classList.remove('hidden');
            showError('Network error occurred during save. Please check your internet connection.');
        };

        xhr.ontimeout = () => {
            clearInterval(processingInterval);
            processingSpinner.classList.add('hidden');
            uploadPercent.classList.remove('hidden');
            progressContainer.classList.add('hidden');
            saveButton.classList.remove('hidden');
            showError('Request timed out. Server processing took too long.');
        };

        xhr.send(formData);
    }

    function startSimulatedProcessing() {
        let simulatedPercent = 0;
        const duration = 10000; // 10 seconds for simulated processing to reach 100%
        const intervalTime = 50;
        const increment = (100 / (duration / intervalTime));

        const phases = [
            { threshold: 30, message: 'Image Conversions...' },
            { threshold: 70, message: 'Thumbnail Creation...' },
            { threshold: 100, message: 'Finalizing...' }
        ];
        let currentPhaseIndex = 0;

        processingInterval = setInterval(() => {
            simulatedPercent += increment;
            if (simulatedPercent > 100) {
                simulatedPercent = 100;
            }

            if (currentPhaseIndex < phases.length && simulatedPercent >= phases[currentPhaseIndex].threshold) {
                uploadStatus.textContent = phases[currentPhaseIndex].message;
                if (phases[currentPhaseIndex].message === 'Finalizing...') {
                    uploadPercent.classList.add('hidden');
                } else {
                    uploadPercent.classList.remove('hidden');
                }
                currentPhaseIndex++;
            }

            if (!uploadPercent.classList.contains('hidden')) {
                uploadPercent.textContent = `${Math.round(simulatedPercent)}%`;
            }
            
            progressFill.style.width = `${simulatedPercent}%`;

            if (simulatedPercent === 100) {
                clearInterval(processingInterval);
            }
        }, intervalTime);
    }
});
</script>
</body>
</html>
