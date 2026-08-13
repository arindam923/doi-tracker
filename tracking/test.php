<?php
/**
 * Track Flow — Test link validator (Phase 4 / Item #7)
 * Validates that a short code resolves without consuming a click.
 *
 * URL: /tracking/test.php?c=ABC123
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/config.php';

$code = trim($_GET['c'] ?? '');
if (!$code || !preg_match('/^[A-Za-z0-9_-]{4,16}$/', $code)) {
    http_response_code(400);
    die('Invalid code.');
}

$stmt = $pdo->prepare("
    SELECT p.id AS project_id, p.project_name, p.project_code, p.status AS project_status,
           pv.vendor_id AS vendor_id, gv.vendor_name, gv.vendor_code, pv.status AS vendor_status, pv.allowed_clicks_limit
    FROM short_links sl
    JOIN projects p ON p.id = sl.project_id
    JOIN project_vendor pv ON pv.project_id = sl.project_id AND pv.vendor_id = sl.vendor_id
    JOIN global_vendors gv ON gv.id = pv.vendor_id
    WHERE sl.code = ?
    LIMIT 1
");
$stmt->execute([$code]);
$row = $stmt->fetch();

header('Content-Type: text/plain; charset=utf-8');

if (!$row) {
    http_response_code(404);
    echo "❌ Tracking link not found.\n";
    echo "Code: $code\n";
    exit;
}

echo "✅ Link is active\n\n";
echo "Project: {$row['project_name']} ({$row['project_code']})\n";
echo "Project Status: {$row['project_status']}\n";
echo "Vendor: {$row['vendor_name']}\n";
echo "Vendor Status: {$row['vendor_status']}\n";
echo "Click Limit: " . (($row['allowed_clicks_limit'] ?? 0) > 0 ? number_format($row['allowed_clicks_limit']) : 'unlimited') . "\n";
echo "\nThis is a validation check — no click was recorded.\n";
