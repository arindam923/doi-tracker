<?php
/**
 * TRACK FLOW — Postback Processing Engine (Phase 4)
 * Receives conversion notifications from client, validates, records, fires vendor postback
 *
 * URL: /tracking/postback.php?click_id=abc123&status=1&token=SECRET
 *      &sale_amount=10&currency=USD&payout=2&transaction_id=TXN123
 *      &sub1=foo&sub2=bar&sub3=baz&sub4=qux&sub5=quux
 */

error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/config.php';

function log_postback($pdo, $project_id, $vendor_id, $click_id, $status, $message, $payload, $ip_address, $response) {
    try {
        $stmt = $pdo->prepare("INSERT INTO logs (log_type, project_id, vendor_id, click_id, status, message, payload, ip_address, response_sent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute(['postback', $project_id, $vendor_id, $click_id, $status, $message, $payload, $ip_address, $response]);
    } catch (Exception $e) {
        error_log('Track Flow log_postback failed: ' . $e->getMessage());
    }
}

$click_id = trim($_GET['click_id'] ?? '');
$status   = intval($_GET['status'] ?? 0) === 1 ? 1 : 0;
$token    = trim($_GET['token'] ?? '');
$sale_amount = floatval($_GET['sale_amount'] ?? 0);
$currency = strtoupper(trim($_GET['currency'] ?? '')) ?: 'USD';
$payout = floatval($_GET['payout'] ?? 0);
$transaction_id = substr(trim($_GET['transaction_id'] ?? ''), 0, 100);
$sub1 = substr(trim($_GET['sub1'] ?? ''), 0, 200);
$sub2 = substr(trim($_GET['sub2'] ?? ''), 0, 200);
$sub3 = substr(trim($_GET['sub3'] ?? ''), 0, 200);
$sub4 = substr(trim($_GET['sub4'] ?? ''), 0, 200);
$sub5 = substr(trim($_GET['sub5'] ?? ''), 0, 200);
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
$payload  = http_build_query($_GET);

if (!preg_match('/^[a-f0-9]{32,64}$/', $click_id)) {
    http_response_code(400);
    log_postback($pdo, null, null, $click_id, 'failed', 'Invalid click_id format', $payload, $ip_address, '400:INVALID_FORMAT');
    die('Invalid click_id format');
}
if (!$token) {
    http_response_code(400);
    log_postback($pdo, null, null, $click_id, 'failed', 'Missing token', $payload, $ip_address, '400:MISSING_PARAMS');
    die('Missing parameters');
}

// ── Rate limits (Phase 9 hardening) ────────────────────────────
// Per-IP: 30 postbacks / minute; per click_id: 1 / minute
if ($ip_address !== '') {
    $rl_ip = check_rate_limit($pdo, $ip_address, 'postback', 30, 60);
    if (!$rl_ip['allowed']) {
        header('Retry-After: ' . ($rl_ip['retry_after'] ?? 60));
        http_response_code(429);
        die('Too many requests');
    }
}
$rl_click = check_rate_limit($pdo, $click_id, 'postback_click', 1, 60);
if (!$rl_click['allowed']) {
    http_response_code(429);
    die('Too many requests for this click');
}

try {
    $pdo->beginTransaction();

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

    if ($click['is_converted']) {
        $pdo->rollBack();
        http_response_code(200);
        log_postback($pdo, $project_id, $vendor_id, $click_id, 'duplicate', 'Click already converted', $payload, $ip_address, 'OK:DUPLICATE');
        die('OK:DUPLICATE');
    }

    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ? FOR UPDATE");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();

    if (!$project) {
        $pdo->rollBack();
        http_response_code(404);
        log_postback($pdo, $project_id, $vendor_id, $click_id, 'failed', 'Project not found', $payload, $ip_address, '404:PROJECT_NOT_FOUND');
        die('Project not found');
    }

    if (!hash_equals($project['postback_token'], $token)) {
        $pdo->rollBack();
        http_response_code(403);
        log_postback($pdo, $project_id, $vendor_id, $click_id, 'failed', 'Invalid token', $payload, $ip_address, '403:INVALID_TOKEN');
        die('Invalid token');
    }

    if ($project['status'] !== 'live') {
        $pdo->prepare("UPDATE clicks SET is_converted = 1 WHERE click_id = ?")->execute([$click_id]);
        $pdo->commit();
        http_response_code(200);
        log_postback($pdo, $project_id, $vendor_id, $click_id, 'rejected', 'Project not live (status: ' . $project['status'] . ')', $payload, $ip_address, 'OK:PROJECT_NOT_LIVE');
        die('OK:PROJECT_NOT_LIVE');
    }

    if ($project['total_quota'] > 0 && $project['completes_count'] >= $project['total_quota']) {
        $pdo->prepare("UPDATE projects SET status = 'hold' WHERE id = ? AND status = 'live'")->execute([$project_id]);
        $pdo->prepare("UPDATE project_vendor SET status = 'hold' WHERE project_id = ? AND status = 'active'")->execute([$project_id]);
        $pdo->commit();
        http_response_code(200);
        log_postback($pdo, $project_id, $vendor_id, $click_id, 'rejected', 'Quota reached', $payload, $ip_address, 'OK:QUOTA_REACHED');
        die('OK:QUOTA_REACHED');
    }

    if (($project['daily_cap'] ?? 0) > 0 && tf_daily_completes($pdo, $project_id) >= (int)$project['daily_cap']) {
        $pdo->rollBack();
        http_response_code(200);
        log_postback($pdo, $project_id, $vendor_id, $click_id, 'rejected', 'Project daily cap reached', $payload, $ip_address, 'OK:DAILY_CAP');
        die('OK:DAILY_CAP');
    }

    $stmt = $pdo->prepare("SELECT pv.*, gv.vendor_status, gv.vendor_name FROM project_vendor pv JOIN global_vendors gv ON gv.id = pv.vendor_id WHERE pv.vendor_id = ? AND pv.project_id = ?");
    $stmt->execute([$vendor_id, $project_id]);
    $vendor = $stmt->fetch();

    if (!$vendor) {
        $pdo->rollBack();
        http_response_code(400);
        log_postback($pdo, $project_id, $vendor_id, $click_id, 'failed', 'Vendor not found or does not belong to project', $payload, $ip_address, '400:INVALID_VENDOR');
        die('Invalid vendor');
    }

    // Master vendor status check
    if (in_array($vendor['vendor_status'] ?? '', ['suspended', 'blacklisted'], true)) {
        $pdo->rollBack();
        http_response_code(200);
        log_postback($pdo, $project_id, $vendor_id, $click_id, 'rejected', 'Vendor suspended/blacklisted', $payload, $ip_address, 'OK:VENDOR_BLOCKED');
        die('OK:VENDOR_BLOCKED');
    }

    if (($vendor['daily_cap'] ?? 0) > 0 && tf_daily_completes($pdo, $project_id, $vendor_id) >= (int)$vendor['daily_cap']) {
        try {
            $pdo->prepare("UPDATE project_vendor SET status = 'hold' WHERE vendor_id = ? AND project_id = ? AND status = 'active'")
                ->execute([$vendor_id, $project_id]);
        } catch (Throwable $e) {}
        $pdo->rollBack();
        http_response_code(200);
        log_postback($pdo, $project_id, $vendor_id, $click_id, 'rejected', 'Vendor daily cap reached', $payload, $ip_address, 'OK:VENDOR_DAILY_CAP');
        die('OK:VENDOR_DAILY_CAP');
    }

    $revenue = $sale_amount > 0 ? $sale_amount : $project['client_cpi'];
    $cost    = $payout > 0 ? $payout : $vendor['payout'];
    $profit  = $revenue - $cost;
    $click_time = $click['clicked_at'];
    $now = date('Y-m-d H:i:s');
    $time_diff_seconds = max(0, strtotime($now) - strtotime($click_time));

    $conversion_status = $status === 1 ? 'complete' : 'rejected';
    $stmt = $pdo->prepare("
        INSERT INTO conversions (click_id, project_id, vendor_id, status, client_revenue, sale_amount, currency,
            vendor_cost, payout, profit, transaction_id, click_time, time_diff_seconds, sub1, sub2, sub3, sub4, sub5)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$click_id, $project_id, $vendor_id, $conversion_status, $revenue, $sale_amount, $currency, $cost, $payout, $profit, $transaction_id, $click_time, $time_diff_seconds, $sub1, $sub2, $sub3, $sub4, $sub5]);

    $pdo->prepare("UPDATE clicks SET is_converted = 1 WHERE click_id = ?")->execute([$click_id]);
    if ($conversion_status === 'complete') {
        $pdo->prepare("UPDATE projects SET completes_count = completes_count + 1 WHERE id = ?")->execute([$project_id]);
    }

    if ($conversion_status === 'complete' && !empty($click['email_send_id'])) {
        try {
            email_mark_engagement($pdo, (int)$click['email_send_id'], 'converted');
        } catch (Throwable $e) {
            error_log('email conversion hook: ' . $e->getMessage());
        }
    }

    if ($conversion_status === 'complete' && $project['total_quota'] > 0) {
        $count_stmt = $pdo->prepare("SELECT completes_count FROM projects WHERE id = ?");
        $count_stmt->execute([$project_id]);
        $new_count = $count_stmt->fetch()['completes_count'];

        if ($new_count >= $project['total_quota']) {
            $pdo->prepare("UPDATE projects SET status = 'hold' WHERE id = ?")->execute([$project_id]);
            $pdo->prepare("UPDATE project_vendor SET status = 'hold' WHERE project_id = ? AND status = 'active'")->execute([$project_id]);

            $pdo->prepare("INSERT INTO logs (log_type, project_id, status, message) VALUES (?, ?, ?, ?)")
                ->execute(['status_change', $project_id, 'success', 'Auto-held: quota reached (' . $new_count . '/' . $project['total_quota'] . ')']);
        }
    }

    log_postback($pdo, $project_id, $vendor_id, $click_id, 'success', ucfirst($conversion_status) . ' postback recorded', $payload, $ip_address, 'OK:RECORDED');

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    log_postback($pdo, $project_id ?? null, $vendor_id ?? null, $click_id, 'error', 'Exception: ' . $e->getMessage(), $payload, $ip_address, '500:ERROR');
    die('System error');
}

// Fire vendor postback with macro expansion
$vendor_postback_status = 'no_url';
if (!empty($vendor['postback_url'])) {
    $vendor_url = $vendor['postback_url'];
    $vendor_url = strtr($vendor_url, tf_postback_macros([
        'click_id' => $click_id, 'status' => $status, 'payout' => $cost,
        'transaction_id' => $transaction_id, 'sale_amount' => $sale_amount,
        'currency' => $currency, 'sub1' => $sub1, 'sub2' => $sub2,
        'sub3' => $sub3, 'sub4' => $sub4, 'sub5' => $sub5,
    ]));

    if (preg_match('/^https?:\/\//', $vendor_url)) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $vendor_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_setopt($ch, CURLOPT_TCP_NODELAY, 1);
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

    log_postback($pdo, $project_id, $vendor_id, $click_id, 'vendor_postback', 'Vendor postback: ' . $vendor_postback_status, '', $ip_address, '');
}

// Fire global postback (Phase 4 — setting wired in Settings UI)
$global_enabled = get_setting($pdo, 'global_postback_enabled', '0') === '1';
$global_url     = trim(get_setting($pdo, 'global_postback_url', ''));
$global_status  = 'disabled';
if ($global_enabled && $global_url !== '') {
    $fire_url = strtr($global_url, tf_postback_macros([
        'click_id' => $click_id, 'status' => $status, 'payout' => $cost ?? 0,
        'transaction_id' => $transaction_id, 'sale_amount' => $sale_amount,
        'currency' => $currency, 'sub1' => $sub1, 'sub2' => $sub2,
        'sub3' => $sub3, 'sub4' => $sub4, 'sub5' => $sub5,
    ]));
    if (preg_match('/^https?:\/\//', $fire_url)) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fire_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_setopt($ch, CURLOPT_TCP_NODELAY, 1);
        curl_exec($ch);
        if (curl_errno($ch)) {
            $global_status = 'failed: ' . curl_error($ch);
        } else {
            $global_status = 'success (HTTP ' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . ')';
        }
        curl_close($ch);
    } else {
        $global_status = 'invalid_url';
    }
    log_postback($pdo, $project_id, $vendor_id, $click_id, 'global_postback', 'Global postback: ' . $global_status, '', $ip_address, '');
}

http_response_code(200);
die('OK:RECORDED');
