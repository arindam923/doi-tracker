<?php
/**
 * Track Flow — Short-link redirector (Phase 4)
 * Resolves /c/CODE → click.php?project_id=X&vendor_id=Y
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/config.php';

$code = trim($_GET['c'] ?? '');
if (!$code || !preg_match('/^[A-Za-z0-9_-]{4,32}$/', $code)) {
    http_response_code(400);
    die('Invalid tracking code.');
}

// Path 1: vendor-specific short link (short_links.code)
$stmt = $pdo->prepare("
    SELECT sl.project_id, sl.vendor_id
    FROM short_links sl
    WHERE sl.code = ?
    LIMIT 1
");
$stmt->execute([$code]);
$row = $stmt->fetch();

if (!$row) {
    // Path 2: legacy project short_code — pick a random active assigned vendor
    $stmt = $pdo->prepare("
        SELECT p.id AS project_id, pv.vendor_id AS vendor_id
        FROM projects p
        JOIN project_vendor pv ON pv.project_id = p.id AND pv.status = 'active'
        WHERE p.short_code = ?
        ORDER BY RAND()
        LIMIT 1
    ");
    $stmt->execute([$code]);
    $row = $stmt->fetch();
}

if (!$row || empty($row['project_id']) || empty($row['vendor_id'])) {
    http_response_code(404);
    die('Tracking link not found.');
}

// Forward to click.php with the canonical params (internal redirect)
$qs = http_build_query([
    'project_id' => $row['project_id'],
    'vendor_id'  => $row['vendor_id'],
]);
header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/click.php?' . $qs, true, 302);
exit;