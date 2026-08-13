<?php
/**
 * Test tracking link (Item #7).
 * Records a flagged test click, then sends the visitor to the client landing page
 * with click_id so their postback can be verified. Test rows are excluded from live KPIs.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/config.php';

$code = trim($_GET['c'] ?? '');
$row = tf_resolve_active_short_link($pdo, $code);
if (!$row) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    die("Tracking link not found.\n");
}

$project_id = (int)$row['project_id'];
$vendor_id = (int)$row['vendor_id'];

$project = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$project->execute([$project_id]);
$project = $project->fetch();
if (!$project) {
    http_response_code(404);
    die('Campaign not found.');
}

$sub1 = substr((string)($_GET['sub1'] ?? 'test'), 0, 200);
$sub2 = substr((string)($_GET['sub2'] ?? ''), 0, 200);
$sub3 = substr((string)($_GET['sub3'] ?? ''), 0, 200);
$sub4 = substr((string)($_GET['sub4'] ?? ''), 0, 200);
$sub5 = substr((string)($_GET['sub5'] ?? ''), 0, 200);
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000);
$referrer = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500);
$browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 0, 20);
$device_type = detect_device_type($user_agent);
$browser = detect_browser($user_agent);
$os = detect_os($user_agent);

$click_id = bin2hex(random_bytes(16));
$pdo->prepare("
    INSERT INTO clicks (click_id, project_id, vendor_id, ip_address, user_agent, referrer, device_type, browser, os, browser_lang, country_code, is_duplicate_ip, is_test, sub1, sub2, sub3, sub4, sub5)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'XX', 0, 1, ?, ?, ?, ?, ?)
")->execute([$click_id, $project_id, $vendor_id, $ip_address, $user_agent, $referrer, $device_type, $browser, $os, $browser_lang, $sub1, $sub2, $sub3, $sub4, $sub5]);

$pdo->prepare('INSERT INTO logs (log_type, project_id, vendor_id, click_id, status, message, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute(['click', $project_id, $vendor_id, $click_id, 'success', 'Test click recorded', $ip_address]);

$redirect_url = $project['client_survey_link'];
if (!filter_var($redirect_url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//', $redirect_url)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Test click recorded.\n";
    echo "Click ID: {$click_id}\n";
    echo "Fire the project postback with this click_id to complete the test conversion.\n";
    exit;
}

$separator = (strpos($redirect_url, '?') !== false) ? '&' : '?';
$redirect_url .= $separator . 'click_id=' . urlencode($click_id) . '&tf_test=1';
header('Location: ' . $redirect_url, true, 302);
exit;
