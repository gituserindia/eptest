<?php
// category-sitemap.php — Secure, dynamic, and SEO-compliant

// --------------------
// Security Headers
// --------------------
header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: public, max-age=3600');

// --------------------
// Resource Limits
// --------------------
ini_set('memory_limit', '64M');
set_time_limit(10);

// --------------------
// Block CLI Execution
// --------------------
if (php_sapi_name() === 'cli') {
    http_response_code(403);
    exit('Forbidden - CLI access blocked.');
}

// --------------------
// Load DB Configuration
// --------------------
require_once __DIR__ . '/../config/config.php'; // Ensure this file exists and contains secure $pdo

// --------------------
// Base URL Detection
// --------------------
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'yourdomain.com';
$baseUrl = $protocol . '://' . $host;

// --------------------
// Fetch Categories
// --------------------
try {
    $stmt = $pdo->query("SELECT category_id, updated_at FROM categories ORDER BY updated_at DESC");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo '<?xml version="1.0" encoding="UTF-8"?><error>Internal Server Error</error>';
    exit;
}

// --------------------
// Initialize XML
// --------------------
$xml = new DOMDocument('1.0', 'UTF-8');
$xml->formatOutput = true;

$urlset = $xml->createElement('urlset');
$urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
$xml->appendChild($urlset);

// --------------------
// Add Category URLs
// --------------------
foreach ($categories as $cat) {
    $id = (int) $cat['category_id'];
    $lastmod = date('Y-m-d', strtotime($cat['updated_at']));

    $loc = htmlspecialchars("$baseUrl/editions/categories-editions.php?category=$id");

    $url = $xml->createElement('url');
    $url->appendChild($xml->createElement('loc', $loc));
    $url->appendChild($xml->createElement('lastmod', $lastmod));

    $urlset->appendChild($url);
}

// --------------------
// Output XML
// --------------------
echo $xml->saveXML();
