<?php
// page-sitemap.php — Secure XML sitemap for static pages

// --------------------
// Security Headers
// --------------------
header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: public, max-age=3600');

// --------------------
// Block CLI Access
// --------------------
if (php_sapi_name() === 'cli') {
    http_response_code(403);
    exit('Forbidden - CLI access blocked.');
}

// --------------------
// Base URL Detection
// --------------------
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'yourdomain.com';
$baseUrl = $protocol . '://' . $host;

// --------------------
// Define Static Pages
// --------------------
$pages = [
    '/pages/about-us.php',
    '/pages/contact-us.php',
    '/pages/privacy-policies.php',
    '/pages/terms-conditions.php'
];

// --------------------
// Generate XML
// --------------------
$xml = new DOMDocument('1.0', 'UTF-8');
$xml->formatOutput = true;

$urlset = $xml->createElement('urlset');
$urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
$xml->appendChild($urlset);

$lastmod = date('Y-m-d');

foreach ($pages as $pagePath) {
    $loc = htmlspecialchars($baseUrl . $pagePath);

    $url = $xml->createElement('url');
    $url->appendChild($xml->createElement('loc', $loc));
    $url->appendChild($xml->createElement('lastmod', $lastmod));

    $urlset->appendChild($url);
}

// --------------------
// Output XML
// --------------------
echo $xml->saveXML();
