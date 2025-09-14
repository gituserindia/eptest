<?php
// File: dashboard.php
// Main dashboard for logged-in users, displaying an overview of the system.

// --- HTTP Security Headers ---
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Start or resume session at the very beginning
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Corrected path for includes:
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/vars/settingsvars.php';
require_once BASE_PATH . '/vars/logovars.php';

// Ensure required constants are defined as a fallback
if (!defined('APP_TITLE')) define('APP_TITLE', 'Admin Panel');
if (!defined('APP_FAVICON_PATH')) define('APP_FAVICON_PATH', '/img/favicon.ico');
if (!defined('OG_IMAGE_PATH')) define('OG_IMAGE_PATH', '/img/og-image.png');

// --- Session and Authorization Logic ---
const INACTIVITY_TIMEOUT = 1800; // 30 minutes in seconds
$loggedIn = isset($_SESSION['user_id']);
$username = $loggedIn ? htmlspecialchars($_SESSION['username']) : null;
$userRole = $loggedIn ? ($_SESSION['user_role'] ?? 'Viewer') : null;

if ($loggedIn && !isset($_SESSION['remember_me'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > INACTIVITY_TIMEOUT)) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['message'] = '<div class="alert alert-error"><span class="material-icons mr-2">error</span> Session expired due to inactivity. Please log in again.</div>';
        header("Location: /login?timeout=1");
        exit;
    }
    $_SESSION['last_activity'] = time();
}

if (!$loggedIn) {
    $_SESSION['message'] = '<div class="alert alert-info"><span class="material-icons mr-2">info</span> Please log in to access this page.</div>';
    header("Location: /login");
    exit;
}

if ($userRole !== 'SuperAdmin' && $userRole !== 'Admin' && $userRole !== 'Editor') {
    $_SESSION['message'] = '<div class="alert alert-error"><span class="material-icons mr-2">lock</span> You do not have the required permissions to access this page.</div>';
    header("Location: dashboard");
    exit;
}

// --- CORS Headers ---
// This is a secure implementation that only allows requests from the same domain
// as the server, or a specifically trusted list of domains.
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http');
$currentDomain = $_SERVER['HTTP_HOST'];
$currentOrigin = $protocol . "://" . $currentDomain;

// Define a list of allowed origins. It's best practice to be explicit.
// In this case, we are only allowing the current origin.
$allowedOrigins = [$currentOrigin];

// You can add other trusted domains here for development or specific integrations.
// Example: $allowedOrigins = ['http://localhost:3000', $currentOrigin];

// Check if the request's origin is in our list of allowed origins.
if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
}

// These headers are still necessary to handle preflight requests for allowed origins.
header("Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");
header("Access-Control-Allow-Credentials: true");


// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- Get current URL and domain for dynamic meta tags ---
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http');
$currentDomain = $_SERVER['HTTP_HOST'];
$currentUrl = $protocol . "://" . $currentDomain . $_SERVER['REQUEST_URI'];
// Dynamically construct the full path for the OG image
$fullOgImagePath = $protocol . "://" . $currentDomain . OG_IMAGE_PATH;

// --- JSON-LD Structured Data for SEO ---
// We'll use the WebPage schema to describe the current admin page.
// This is a simple, effective way to provide structured context to search engines.
$jsonLd = [
    "@context" => "https://schema.org",
    "@type" => "WebPage",
    "name" => "Dashboard - " . htmlspecialchars(APP_TITLE),
    "description" => "View an overview of your e-paper publications, including editions, views, and disk usage.",
    "url" => $currentUrl,
    "potentialAction" => [
        "@type" => "SearchAction",
        "target" => [
            "@type" => "EntryPoint",
            "urlTemplate" => $protocol . "://" . $currentDomain . "/dashboard?search={search_term_string}"
        ],
        "query-input" => "required name=search_term_string"
    ]
];
$jsonLdString = json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

// Set the page title
$pageTitle = "Dashboard";

// --- Dashboard Data Fetching (Placeholders) ---
// In a real application, you would connect to your database here to fetch actual data.
// These are simple placeholders to demonstrate the dashboard structure.

// Total Editions: This would come from a database query, e.g., COUNT(*) from 'editions' table.
$totalEpapers = 538;

// Total Views: This would come from a database query, e.g., SUM(views) from 'editions' or a separate 'views' table.
// Using a large number as a placeholder.
$totalViews = 1256789;

// Disk Usage: This would be calculated by PHP from the file system.
// We'll use a placeholder value for demonstration.
$diskUsage = '2.5 GB';
$diskUsagePercentage = '50%'; // 50% of a 5 GB limit

// Expiry Date: This could be a static or dynamically calculated date from a user or subscription table.
$expiryDate = date('M j, Y', strtotime('+90 days'));


// --- Helper function for alert messages ---
function create_alert($type, $message) {
    $icon = '';
    $classes = '';
    switch ($type) {
        case 'success': $icon = 'check_circle'; $classes = 'bg-green-100 text-green-700 border-green-200'; break;
        case 'error': $icon = 'error'; $classes = 'bg-red-100 text-red-700 border-red-200'; break;
        case 'info': $icon = 'info'; $classes = 'bg-blue-100 text-blue-700 border-blue-200'; break;
        default: $icon = 'info'; $classes = 'bg-gray-100 text-gray-700 border-gray-200'; break;
    }
    return '<div class="alert ' . $classes . '"><span class="material-icons mr-1 text-base">'. $icon .'</span><span class="text-sm font-semibold">' . htmlspecialchars($message) . '</span></div>';
}

$message = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Updated Title to be dynamic -->
    <title>Dashboard - <?= htmlspecialchars(APP_TITLE) ?></title>
    
    <!-- Favicon and OG Image from logovars.php -->
    <link rel="icon" href="<?= htmlspecialchars(APP_FAVICON_PATH) ?>" type="image/x-icon">
    
    <!-- SEO and Social Media Meta Tags -->
    <meta name="description" content="View an overview of your e-paper publications, including editions, views, and disk usage.">
    <meta name="keywords" content="<?= htmlspecialchars(APP_TITLE) ?>, epaper, e-paper, online newspaper, digital newspaper, news, publication, dashboard, stats, admin panel, administration">
    <!-- Facebook Meta Tags -->
    <meta property="og:url" content="<?= htmlspecialchars($currentUrl) ?>">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Dashboard - <?= htmlspecialchars(APP_TITLE) ?>">
    <meta property="og:description" content="View an overview of your e-paper publications, including editions, views, and disk usage.">
    <meta property="og:image" content="<?= htmlspecialchars($fullOgImagePath) ?>">
    <!-- Twitter Meta Tags -->
    <meta name="twitter:card" content="summary_large_image">
    <meta property="twitter:domain" content="<?= htmlspecialchars($currentDomain) ?>">
    <meta property="twitter:url" content="<?= htmlspecialchars($currentUrl) ?>">
    <meta name="twitter:title" content="Dashboard - <?= htmlspecialchars(APP_TITLE) ?>">
    <meta name="twitter:description" content="View an overview of your e-paper publications, including editions, views, and disk usage.">
    <meta name="twitter:image" content="<?= htmlspecialchars($fullOgImagePath) ?>">
    
    <!-- JSON-LD Structured Data for SEO -->
    <script type="application/ld+json">
        <?= $jsonLdString ?>
    </script>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
            margin: 0;
        }
        .chart-container {
            position: relative;
            height: 40vh; /* Set a fixed height for the chart container */
            width: 100%;
        }
        @media (min-width: 1024px) {
            .content-area {
                margin-left: 16rem; /* Tailwind's w-64 is 16rem */
            }
        }
    </style>
</head>
<body class="flex min-h-screen relative">
    <?php 
        // Including the header and sidebar from the user-specified path.
        // The __DIR__ constant ensures the path is relative to the current file.
        require_once __DIR__ . '/../layout/headersidebar.php'; 
    ?>

    <main class="flex-1 p-6 overflow-auto content-area pt-20 lg:pt-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-6 hidden lg:block">Dashboard</h1>
        
        <?php if (!empty($message)): ?>
            <div class="mb-4">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">

            <!-- Total Editions Box -->
            <div class="bg-white rounded-xl shadow-md p-6 flex flex-col justify-between transition-transform duration-300 transform hover:scale-105">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-xs font-semibold text-gray-500 uppercase">Total Editions</span>
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-book-text text-indigo-500">
                        <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>
                        <path d="M8 8h8"/><path d="M8 12h8"/>
                    </svg>
                </div>
                <div>
                    <p class="text-3xl font-bold text-gray-800"><?= htmlspecialchars($totalEpapers) ?></p>
                </div>
                <div class="h-1 bg-indigo-500 rounded-full w-1/3 mt-4"></div>
            </div>

            <!-- Total Views Box -->
            <div class="bg-white rounded-xl shadow-md p-6 flex flex-col justify-between transition-transform duration-300 transform hover:scale-105">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-xs font-semibold text-gray-500 uppercase">Total Views</span>
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-eye text-green-500">
                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                </div>
                <div>
                    <p class="text-3xl font-bold text-gray-800"><?= htmlspecialchars(number_format($totalViews)) ?></p>
                </div>
                <div class="h-1 bg-green-500 rounded-full w-1/3 mt-4"></div>
            </div>

            <!-- Storage Usage Box -->
            <div class="bg-white rounded-xl shadow-md p-6 flex flex-col justify-between transition-transform duration-300 transform hover:scale-105">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-xs font-semibold text-gray-500 uppercase">Storage Usage</span>
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-hard-drive-upload text-red-500">
                        <path d="M12 22a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2z"/>
                        <path d="M22 15h-8.8a2 2 0 0 0-1.66.9L7 8"/>
                    </svg>
                </div>
                <div>
                    <p class="text-3xl font-bold text-gray-800">
                        <span id="diskUsage"><?= htmlspecialchars($diskUsage) ?></span>
                    </p>
                    <p class="text-sm text-gray-500 mt-1">
                        <span id="diskUsagePercentage"><?= htmlspecialchars($diskUsagePercentage) ?></span> used
                    </p>
                </div>
                <div class="h-1 bg-red-500 rounded-full mt-4" style="width: <?= htmlspecialchars($diskUsagePercentage) ?>;"></div>
            </div>

            <!-- Expiry Date Box -->
            <div class="bg-white rounded-xl shadow-md p-6 flex flex-col justify-between transition-transform duration-300 transform hover:scale-105">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-xs font-semibold text-gray-500 uppercase">Expiry Date</span>
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-calendar-clock text-orange-500">
                        <path d="M21 7.5V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h7.5"/>
                        <path d="M16 2v4"/><path d="M8 2v4"/>
                        <path d="M3 10h18"/>
                        <path d="M16 22a6 6 0 1 0 0-12 6 6 0 0 0 0 12Z"/>
                        <path d="M16 12v4l2 1"/>
                    </svg>
                </div>
                <div>
                    <p class="text-3xl font-bold text-gray-800" id="expiryDate"><?= htmlspecialchars($expiryDate) ?></p>
                </div>
                <div class="h-1 bg-orange-500 rounded-full w-1/3 mt-4"></div>
            </div>

        </div>

        <!-- Website Traffic Graph -->
        <div class="bg-white rounded-xl shadow-md p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-gray-800">Website Traffic</h2>
                <select id="timePeriodSelect" class="bg-gray-100 rounded-md text-sm p-2">
                    <option value="initial">Last 7 days</option>
                    <option value="30d">Last 30 days</option>
                    <option value="90d">Last 90 days</option>
                    <option value="1y">Last Year</option>
                </select>
            </div>
            <div class="chart-container">
                <canvas id="trafficChart"></canvas>
            </div>
            <div class="flex justify-between items-center text-sm text-gray-500 mt-4">
                <div class="flex items-center">
                    <div class="w-2 h-2 rounded-full bg-indigo-500 mr-2"></div>
                    Total Views
                </div>
                <div class="flex items-center">
                    <div class="w-2 h-2 rounded-full bg-green-500 mr-2"></div>
                    Page Views
                </div>
                <div class="flex items-center">
                    <div class="w-2 h-2 rounded-full bg-red-500 mr-2"></div>
                    Unique Visitors
                </div>
            </div>
        </div>
    </main>

    <!-- JavaScript for Chart.js and Mobile Menu -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('trafficChart').getContext('2d');
            let trafficChart;

            // Function to generate fake data
            function generateData(period) {
                let labels = [];
                let totalViews = [];
                let pageViews = [];
                let uniqueVisitors = [];
                let count;

                switch (period) {
                    case '30d':
                        count = 30;
                        for (let i = 0; i < count; i++) {
                            labels.push(`Day ${i+1}`);
                            totalViews.push(Math.floor(Math.random() * 5000) + 1000);
                            pageViews.push(Math.floor(Math.random() * 4000) + 500);
                            uniqueVisitors.push(Math.floor(Math.random() * 2000) + 200);
                        }
                        break;
                    case '90d':
                        count = 90;
                        for (let i = 0; i < count; i++) {
                            labels.push(`Day ${i+1}`);
                            totalViews.push(Math.floor(Math.random() * 15000) + 5000);
                            pageViews.push(Math.floor(Math.random() * 12000) + 3000);
                            uniqueVisitors.push(Math.floor(Math.random() * 6000) + 1000);
                        }
                        break;
                    case '1y':
                        count = 12;
                        const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
                        for (let i = 0; i < count; i++) {
                            labels.push(months[i]);
                            totalViews.push(Math.floor(Math.random() * 100000) + 20000);
                            pageViews.push(Math.floor(Math.random() * 80000) + 15000);
                            uniqueVisitors.push(Math.floor(Math.random() * 40000) + 5000);
                        }
                        break;
                    default: // initial and 7d
                        count = 7;
                        const days = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
                        for (let i = 0; i < count; i++) {
                            labels.push(days[i]);
                            totalViews.push(Math.floor(Math.random() * 1000) + 500);
                            pageViews.push(Math.floor(Math.random() * 800) + 300);
                            uniqueVisitors.push(Math.floor(Math.random() * 400) + 100);
                        }
                        break;
                }
                return { labels, totalViews, pageViews, uniqueVisitors };
            }

            // Function to update the traffic graph
            function updateTrafficGraph(period) {
                const data = generateData(period);

                // Destroy old chart if it exists
                if (trafficChart) {
                    trafficChart.destroy();
                }

                trafficChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Total Views',
                            data: data.totalViews,
                            backgroundColor: 'rgba(99, 102, 241, 0.2)',
                            borderColor: '#6366f1',
                            borderWidth: 2,
                            pointRadius: 4,
                            pointBackgroundColor: '#6366f1',
                            tension: 0.4,
                            fill: true,
                        }, {
                            label: 'Page Views',
                            data: data.pageViews,
                            backgroundColor: 'rgba(52, 211, 153, 0.2)',
                            borderColor: '#34d399',
                            borderWidth: 2,
                            pointRadius: 4,
                            pointBackgroundColor: '#34d399',
                            tension: 0.4,
                            fill: true,
                        }, {
                            label: 'Unique Visitors',
                            data: data.uniqueVisitors,
                            backgroundColor: 'rgba(239, 68, 68, 0.2)',
                            borderColor: '#ef4444',
                            borderWidth: 2,
                            pointRadius: 4,
                            pointBackgroundColor: '#ef4444',
                            tension: 0.4,
                            fill: true,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false
                            }
                        },
                        scales: {
                            x: {
                                display: true,
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    color: '#64748b'
                                }
                            },
                            y: {
                                display: true,
                                grid: {
                                    color: '#e2e8f0',
                                    borderDash: [5, 5]
                                },
                                ticks: {
                                    callback: function(value) {
                                        return value.toLocaleString();
                                    },
                                    color: '#64748b'
                                }
                            }
                        }
                    }
                });
            }

            // Event listener for the time period dropdown
            document.getElementById('timePeriodSelect').addEventListener('change', function() {
                updateTrafficGraph(this.value);
            });

            // Add a resize listener to re-render the chart
            window.addEventListener('resize', function() {
                const currentPeriod = document.getElementById('timePeriodSelect').value;
                updateTrafficGraph(currentPeriod);
            });

            // Initial call to update functions on page load
            setTimeout(() => {
                updateTrafficGraph('initial');
            }, 0);
        });
    </script>
</body>
</html>
