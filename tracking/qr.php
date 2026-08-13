<?php
/**
 * Track Flow — QR Code generator (Phase 4 / Item #33)
 *
 * Approach: server-side redirect to Google Chart API. This keeps the codebase
 * dependency-free (no composer, no GD/Font ext required) and produces a clean
 * PNG that scales correctly when printed.
 *
 * To go fully self-contained: replace the URL below with a pure-PHP QR encoder
 * (e.g. a vendored copy of BaconQrCode).
 */
require_once __DIR__ . '/../config.php';
require_login();

$code = trim($_GET['c'] ?? '');
if (!$code || !preg_match('/^[A-Za-z0-9_-]{4,16}$/', $code)) {
    http_response_code(400);
    die('Invalid code.');
}

// Verify the code exists in our DB (so QR isn't leaked for deleted projects)
$stmt = $pdo->prepare("SELECT code FROM short_links WHERE code = ?");
$stmt->execute([$code]);
if (!$stmt->fetch()) {
    http_response_code(404);
    die('Tracking link not found.');
}

$url = BASE_URL . '/c/' . $code;

// Use Google Chart's QR generator — public, no API key, well-tested.
// Width/height are in pixels. Output as PNG.
$size = max(150, min(800, intval($_GET['size'] ?? 300)));
$chart_url = 'https://chart.googleapis.com/chart?cht=qr&chs=' . $size . 'x' . $size . '&chl=' . urlencode($url) . '&choe=UTF-8&chld=M|2';

// Cache the resulting image for 1 day, then redirect.
$cache_dir = __DIR__ . '/../storage/qr_cache';
if (!is_dir($cache_dir)) @mkdir($cache_dir, 0755, true);
$cache_file = $cache_dir . '/' . md5($code . $size) . '.png';

if (is_file($cache_file) && (time() - filemtime($cache_file)) < 86400) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    readfile($cache_file);
    exit;
}

$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true, 'header' => "User-Agent: TrackFlow/1.0\r\n"]]);
$png = @file_get_contents($chart_url, false, $ctx);

if ($png === false || strlen($png) < 100) {
    // Fallback: render a simple SVG placeholder with the URL text
    header('Content-Type: image/svg+xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '">
  <rect width="100%" height="100%" fill="#fff"/>
  <rect x="2" y="2" width="' . ($size - 4) . '" height="' . ($size - 4) . '" fill="none" stroke="#0f172a" stroke-width="2"/>
  <text x="50%" y="50%" text-anchor="middle" font-family="monospace" font-size="14" fill="#0f172a">' . htmlspecialchars($url) . '</text>
  <text x="50%" y="' . ($size - 20) . '" text-anchor="middle" font-family="sans-serif" font-size="11" fill="#64748b">QR generation offline. URL above.</text>
</svg>';
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
echo $png;
@file_put_contents($cache_file, $png);
