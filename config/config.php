<?php
// config.php
// Handles database connection and core error reporting.

// Define BASE_PATH if it's not already defined.
// This assumes config.php is located at /path/to/public_html/config/config.php
// and the application root (BASE_PATH) is /path/to/public_html/
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__ . '/..');
}

// Include sensitive database credentials and static application constants.
// FIX: Adjusted path for global_variables.php, as it's located one level above public_html.
// From config/config.php (__DIR__), we go up to public_html (../), then up again to the parent directory (../).
require_once __DIR__ . '/../../global_variables.php';

// ---------------------------
// Error Reporting (DEV ONLY)
// ---------------------------
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ---------------------------
// PDO Database Connection
// ---------------------------
try {
    // Ensure DB_HOST, DB_NAME, DB_USER, DB_PASS are defined in global_variables.php
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // throw exceptions
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); // fetch as assoc array
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false); // use native prepares
} catch (PDOException $e) {
    // In development: log the error and optionally display
    error_log("Database connection failed: " . $e->getMessage());
    // In production, you might want a more user-friendly message without revealing details
    die("⚠️ A critical database error occurred. Please try again later.");
}
