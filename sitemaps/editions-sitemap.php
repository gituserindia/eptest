<?php
// sitemap.php — Secure and optimized for live dynamic sitemap

// --------------------
// Security Headers
// --------------------
header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: public, max-age=3600'); // 1 hour cache

// --------------------
// Resource Limits
// --------------------
ini_set('memory_limit', '64M');
set_time_limit(10);

// --------------------
// Block CLI direct access
// --------------------
if (php_sapi_name() === 'cli') {
    http_response_code(403);
    exit('Forbidden - CLI access blocked.');
}

// --------------------
// Load DB Configuration
// --------------------
require_once __DIR__ . '/../config/config.php'; // Assumes $pdo is defined and secure

// --------------------
// Base URL Detection
// --------------------
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'yourdomain.com';
$baseUrl = $protocol . '://' . $host;

// --------------------
// Fetch Editions Safely
// --------------------
try {
    $stmt = $pdo->prepare("
        SELECT edition_id, publication_date, updated_at
        FROM editions
        WHERE status = 'Published'
        ORDER BY publication_date DESC, edition_id DESC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
// Add Each Edition Entry
// --------------------
foreach ($rows as $row) {
    $editionId = urlencode($row['edition_id']);
    $pubDate = urlencode($row['publication_date']);
    $lastmod = htmlspecialchars(date('Y-m-d', strtotime($row['updated_at'])));
    $loc = $baseUrl . "/editions/view-edition.php?date=$pubDate&edition_id=$editionId";

    $url = $xml->createElement('url');
    $url->appendChild($xml->createElement('loc', htmlspecialchars($loc)));
    $url->appendChild($xml->createElement('lastmod', $lastmod));
    $urlset->appendChild($url);
}

// --------------------
// Optional: Log Access (monitor abuse)
// --------------------
// error_log("Sitemap accessed by " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . " at " . date('c'));

// --------------------
// Output Final XML
// --------------------
echo $xml->saveXML();
