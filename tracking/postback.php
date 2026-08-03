<?php
/**
 * TERNFLUENZY — Postback Processing Engine
 * Receives conversion notifications from client, validates, records, and fires vendor postback
 *
 * URL: /tracking/postback.php?click_id=abc123&status=1&token=SECRET
 */

// Minimal bootstrap — no session needed for postback
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/config.php';

/**
 * Log postback event
 */
function log_postback($pdo, $project_id, $vendor_id, $click_id, $status, $message, $payload, $ip_address, $response) {
    try {
        $stmt = $pdo->prepare("INSERT INTO logs (log_type, project_id, vendor_id, click_id, status, message, payload, ip_address, response_sent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute(['postback', $project_id, $vendor_id, $click_id, $status, $message, $payload, $ip_address, $response]);
    } catch (Exception $e) {
        error_log('Ternfluenzy log_postback failed: ' . $e->getMessage());
    }
}

// ─── Collect Parameters ───
$click_id = trim($_GET['click_id'] ?? '');
$status   = intval($_GET['status'] ?? 0);
$token    = trim($_GET['token'] ?? '');
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
$payload  = http_build_query($_GET);

// 1. Validate click_id format (32 hex chars)
if (!preg_match('/^[a-f0-9]{32,64}$/', $click_id)) {
    http_response_code(400);
    log_postback($pdo, null, null, $click_id, 'failed', 'Invalid click_id format', $payload, $ip_address, '400:INVALID_FORMAT');
    die('Invalid click_id format');
}

// 2. Validate required params
if (!$token) {
    http_response_code(400);
    log_postback($pdo, null, null, $click_id, 'failed', 'Missing token', $payload, $ip_address, '400:MISSING_PARAMS');
    die('Missing parameters');
}

try {
    // Begin transaction with SELECT ... FOR UPDATE to prevent race conditions
    $pdo->beginTransaction();

    // 3. Find the click record (lock it)
    $stmt = $pdo->prepare("SELECT * FROM clicks WHERE click_id = ? FOR UPDATE");
    $stmt->execute([$click_id]);
    $click = $stmt->fetch();

    if (!$click) {
        $pdo->rollBack();
        http_response_code(404);
        log_postback($pdo, null, null, $click_id, 'failed', 'Click not found', $payload, $ip_address, '404:NOT_FOUND');
        die('Click not found');
    }

    $project_id = $click['project_id'];
    $vendor_id  = $click['vendor_id'];

    // 4. Check if already converted (within the lock)
    if ($click['is_converted']) {
        $pdo->rollBack();
        http_response_code(200);
        log_postback($pdo, $project_id, $vendor_id, $click_id, 'duplicate', 'Click already converted', $payload, $ip_address, 'OK:DUPLICATE');
        die('OK:DUPLICATE');
    }

    // 5. Get project and validate token
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ? FOR UPDATE");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();

    if (!$project) {
        $pdo->rollBack();
        http_response_code(404);
        log_postback($pdo, $project_id, $vendor_id, $click_id, 'failed', 'Project not found', $payload, $ip_address, '404:PROJECT_NOT_FOUND');
        die('Project not found');
    }

    // Constant-time token comparison
    if (!hash_equals($project['postback_token'], $token)) {
        $pdo->rollBack();
        http_response_code(403);
        log_postback($pdo, $project_id, $vendor_id, $click_id, 'failed', 'Invalid token', $payload, $ip_address, '403:INVALID_TOKEN');
        die('Invalid token');
    }

    // 6. Check project status — if not live, store but don't count
    if ($project['status'] !== 'live') {
        // Still mark the click as converted to prevent re-processing if project comes back live
        $pdo->prepare("UPDATE clicks SET is_converted = 1 WHERE click_id = ?")->execute([$click_id]);
        $pdo->commit();
        http_response_code(200);
        log_postback($pdo, $project_id, $vendor_id, $click_id, 'rejected', 'Project not live (status: ' . $project['status'] . ')', $payload, $ip_address, 'OK:PROJECT_NOT_LIVE');
        die('OK:PROJECT_NOT_LIVE');
    }

    // 7. Check quota before recording
    if ($project['total_quota'] > 0 && $project['completes_count'] >= $project['total_quota']) {
        $pdo->prepare("UPDATE projects SET status = 'hold' WHERE id = ? AND status = 'live'")->execute([$project_id]);
        $pdo->prepare("UPDATE vendors SET status = 'paused' WHERE project_id = ? AND status = 'active'")->execute([$project_id]);
        $pdo->commit();
        http_response_code(200);
        log_postback($pdo, $project_id, $vendor_id, $click_id, 'rejected', 'Quota reached', $payload, $ip_address, 'OK:QUOTA_REACHED');
        die('OK:QUOTA_REACHED');
    }

    // 8. Get vendor details (validate vendor belongs to project)
    $stmt = $pdo->prepare("SELECT * FROM vendors WHERE id = ? AND project_id = ?");
    $stmt->execute([$vendor_id, $project_id]);
    $vendor = $stmt->fetch();

    if (!$vendor) {
        $pdo->rollBack();
        http_response_code(400);
        log_postback($pdo, $project_id, $vendor_id, $click_id, 'failed', 'Vendor not found or does not belong to project', $payload, $ip_address, '400:INVALID_VENDOR');
        die('Invalid vendor');
    }

    // 9. Calculate revenue, cost, profit
    $revenue = $project['client_cpi'];
    $cost    = $vendor['vendor_cpi'];
    $profit  = $revenue - $cost;

    // 10. Record the conversion
    $stmt = $pdo->prepare("INSERT INTO conversions (click_id, project_id, vendor_id, status, client_revenue, vendor_cost, profit) VALUES (?, ?, ?, 'complete', ?, ?, ?)");
    $stmt->execute([$click_id, $project_id, $vendor_id, $revenue, $cost, $profit]);

    // 11. Update click as converted
    $pdo->prepare("UPDATE clicks SET is_converted = 1 WHERE click_id = ?")->execute([$click_id]);

    // 12. Update project completes count atomically
    $pdo->prepare("UPDATE projects SET completes_count = completes_count + 1 WHERE id = ?")->execute([$project_id]);

    // 13. Re-check quota after increment (within the same transaction)
    if ($project['total_quota'] > 0) {
        $count_stmt = $pdo->prepare("SELECT completes_count FROM projects WHERE id = ?");
        $count_stmt->execute([$project_id]);
        $new_count = $count_stmt->fetch()['completes_count'];

        if ($new_count >= $project['total_quota']) {
            $pdo->prepare("UPDATE projects SET status = 'hold' WHERE id = ?")->execute([$project_id]);
            $pdo->prepare("UPDATE vendors SET status = 'paused' WHERE project_id = ? AND status = 'active'")->execute([$project_id]);

            $pdo->prepare("INSERT INTO logs (log_type, project_id, status, message) VALUES (?, ?, ?, ?)")
                ->execute(['status_change', $project_id, 'success', 'Auto-held: quota reached (' . $new_count . '/' . $project['total_quota'] . ')']);
        }
    }

    // 14. Log successful conversion (within transaction)
    log_postback($pdo, $project_id, $vendor_id, $click_id, 'success', 'Conversion recorded', $payload, $ip_address, 'OK:RECORDED');

    // Commit all changes atomically
    $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    log_postback($pdo, $project_id ?? null, $vendor_id ?? null, $click_id, 'error', 'Exception: ' . $e->getMessage(), $payload, $ip_address, '500:ERROR');
    die('System error');
}

// 15. Fire vendor postback (AFTER commit — non-blocking)
$vendor_postback_status = 'no_url';
if (!empty($vendor['postback_url'])) {
    $vendor_url = str_replace('{click_id}', $click_id, $vendor['postback_url']);

    // Validate vendor URL is HTTP/HTTPS
    if (preg_match('/^https?:\/\//', $vendor_url)) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $vendor_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);    // Required for async-like behavior
        curl_setopt($ch, CURLOPT_TCP_NODELAY, 1);  // Don't wait for response
        curl_exec($ch);

        if (curl_errno($ch)) {
            $vendor_postback_status = 'failed: ' . curl_error($ch);
        } else {
            $vendor_postback_status = 'success (HTTP ' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . ')';
        }
        curl_close($ch);
    } else {
        $vendor_postback_status = 'invalid_url';
    }

    // Log vendor postback result
    log_postback($pdo, $project_id, $vendor_id, $click_id, 'vendor_postback', 'Vendor postback: ' . $vendor_postback_status, '', $ip_address, '');
}

http_response_code(200);
die('OK:RECORDED');
