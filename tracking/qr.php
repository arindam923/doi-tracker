<?php
/**
 * Track Flow — QR Code generator (Item #33)
 *
 * Prefer a local encoder (qrencode CLI). Google Chart is only a last-resort
 * fill for the on-disk cache so the endpoint still works on hosts without
 * qrencode; cached files are served without a network round-trip.
 */
require_once __DIR__ . '/../config.php';
require_login();

$code = trim($_GET['c'] ?? '');
if (!tf_is_valid_short_code($code)) {
    http_response_code(400);
    die('Invalid code.');
}

$stmt = $pdo->prepare("SELECT code FROM short_links WHERE code = ?");
$stmt->execute([$code]);
if (!$stmt->fetch()) {
    http_response_code(404);
    die('Tracking link not found.');
}

$url = tf_opaque_tracking_url($code);
$size = max(150, min(800, intval($_GET['size'] ?? 300)));
$cache_dir = __DIR__ . '/../storage/qr_cache';
if (!is_dir($cache_dir)) {
    @mkdir($cache_dir, 0755, true);
}
$cache_file = $cache_dir . '/' . md5($code . $size) . '.png';

if (is_file($cache_file) && (time() - filemtime($cache_file)) < 86400) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    readfile($cache_file);
    exit;
}

$png = null;
$qrencode = trim((string)@shell_exec('command -v qrencode 2>/dev/null'));
if ($qrencode !== '') {
    $cmd = escapeshellcmd($qrencode) . ' -t PNG -s 6 -m 2 -o - ' . escapeshellarg($url);
    $png = @shell_exec($cmd);
}

if (!is_string($png) || strlen($png) < 100) {
    $chart_url = 'https://chart.googleapis.com/chart?cht=qr&chs=' . $size . 'x' . $size . '&chl=' . urlencode($url) . '&choe=UTF-8&chld=M|2';
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true, 'header' => "User-Agent: TrackFlow/1.0\r\n"]]);
    $png = @file_get_contents($chart_url, false, $ctx);
}

if (!is_string($png) || strlen($png) < 100) {
    header('Content-Type: image/svg+xml');
    $esc = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>'
        . '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '">'
        . '<rect width="100%" height="100%" fill="#fff"/>'
        . '<text x="50%" y="50%" text-anchor="middle" font-family="monospace" font-size="12">' . $esc . '</text>'
        . '</svg>';
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
echo $png;
@file_put_contents($cache_file, $png);
