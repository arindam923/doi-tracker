<?php
/**
 * TRACK FLOW — Click Tracking Engine (Phase 4)
 * Public traffic must arrive via /c/{code} → redirect.php (internal include).
 * Direct project_id/vendor_id query params are admin-only diagnostics.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

if (!defined('TF_INTERNAL_CLICK')) {
    require_once __DIR__ . '/config.php';
}

$project_id = 0;
$vendor_id = 0;
if (defined('TF_INTERNAL_CLICK') && TF_INTERNAL_CLICK) {
    $project_id = (int)($tf_click_project_id ?? 0);
    $vendor_id = (int)($tf_click_vendor_id ?? 0);
} elseif (tf_is_tracking_admin()) {
    $project_id = intval($_GET['project_id'] ?? 0);
    $vendor_id = intval($_GET['vendor_id'] ?? 0);
}

if (!$project_id || !$vendor_id) {
    http_response_code(404);
    die('Tracking link not found.');
}

$GLOBALS['project_id'] = $project_id;
$GLOBALS['vendor_id'] = $vendor_id;

// ── Rate limit: per-IP 100 clicks / minute (Phase 9 hardening) ─
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($client_ip !== '') {
    $rl = check_rate_limit($pdo, $client_ip, 'click', 100, 60);
    if (!$rl['allowed']) {
        header('Retry-After: ' . ($rl['retry_after'] ?? 60));
        http_response_code(429);
        die('Too many requests.');
    }
}

// Sub-params (Phase 4 — Items #27)
$sub1 = substr($_GET['sub1'] ?? '', 0, 200);
$sub2 = substr($_GET['sub2'] ?? '', 0, 200);
$sub3 = substr($_GET['sub3'] ?? '', 0, 200);
$sub4 = substr($_GET['sub4'] ?? '', 0, 200);
$sub5 = substr($_GET['sub5'] ?? '', 0, 200);

function log_click_error($message, $exception = null) {
    $log_dir = __DIR__ . '/../logs';
    $log_file = $log_dir . '/click-errors.log';
    $details = date('c') . ' | ' . $message;
    if ($exception instanceof Throwable) {
        $details .= ' | ' . get_class($exception) . ': ' . $exception->getMessage();
        $details .= ' | file=' . $exception->getFile() . ' | line=' . $exception->getLine();
    }
    $details .= ' | project_id=' . (int)($GLOBALS['project_id'] ?? 0);
    $details .= ' | vendor_id=' . (int)($GLOBALS['vendor_id'] ?? 0);
    $details .= ' | request_uri=' . ($_SERVER['REQUEST_URI'] ?? '');
    $details .= PHP_EOL;
    if (is_dir($log_dir) && is_writable($log_dir)) {
        file_put_contents($log_file, $details, FILE_APPEND | LOCK_EX);
    }
    error_log('Track Flow click tracking: ' . $details);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ? AND status = 'live' FOR UPDATE");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();

    if (!$project) {
        $pdo->rollBack();
        http_response_code(410);
        die('This campaign is not currently active.');
    }

    if ($project['total_quota'] > 0 && $project['completes_count'] >= $project['total_quota']) {
        $pdo->prepare("UPDATE projects SET status = 'hold' WHERE id = ? AND status = 'live'")->execute([$project_id]);
        $pdo->prepare("UPDATE project_vendor SET status = 'hold' WHERE project_id = ? AND status = 'active'")->execute([$project_id]);
        $pdo->rollBack();
        http_response_code(410);
        die('This campaign has reached its quota.');
    }

    $stmt = $pdo->prepare("SELECT pv.*, gv.vendor_status, gv.vendor_name FROM project_vendor pv JOIN global_vendors gv ON gv.id = pv.vendor_id WHERE pv.vendor_id = ? AND pv.project_id = ? AND pv.status = 'active' FOR UPDATE");
    $stmt->execute([$vendor_id, $project_id]);
    $vendor = $stmt->fetch();

    if (!$vendor) {
        $pdo->rollBack();
        http_response_code(410);
        die('Invalid or inactive traffic source.');
    }

    if ($vendor['vendor_status'] && in_array($vendor['vendor_status'], ['suspended', 'blacklisted'], true)) {
        $pdo->rollBack();
        http_response_code(410);
        die('This vendor is suspended.');
    }

    // Per-vendor click limit
    if ($vendor['allowed_clicks_limit'] > 0) {
        $cnt = $pdo->prepare("SELECT COUNT(*) as cnt FROM clicks WHERE vendor_id = ? AND project_id = ?");
        $cnt->execute([$vendor_id, $project_id]);
        $vendor_clicks = $cnt->fetch()['cnt'];

        if ($vendor_clicks >= $vendor['allowed_clicks_limit']) {
            $pdo->prepare("UPDATE project_vendor SET status = 'hold' WHERE vendor_id = ? AND project_id = ?")->execute([$vendor_id, $project_id]);
            $pdo->rollBack();
            http_response_code(410);
            die('This traffic source has reached its limit.');
        }
    }

    // Per-vendor daily cap (Item #26)
    if (($vendor['daily_cap'] ?? 0) > 0) {
        $dcnt = $pdo->prepare("SELECT COUNT(*) as cnt FROM clicks WHERE vendor_id = ? AND DATE(clicked_at) = CURDATE()");
        $dcnt->execute([$vendor_id]);
        $today_clicks = $dcnt->fetch()['cnt'];
        if ($today_clicks >= (int)$vendor['daily_cap']) {
            $pdo->rollBack();
            http_response_code(429);
            die('Vendor daily cap reached.');
        }
    }

    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000);
    $referrer   = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500);
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 0, 20);
    $device_type = detect_device_type($user_agent);
    $browser = detect_browser($user_agent);
    $os = detect_os($user_agent);

    // Target device policy
    $target_device = $project['target_device'] ?? 'All';
    $strict_device = get_setting($pdo, 'strict_target_device', '0') === '1';
    $device_allowed = true;
    if ($target_device !== 'All') {
        if ($target_device === 'Mobile' && !in_array($device_type, ['Mobile'], true)) $device_allowed = false;
        if ($target_device === 'Desktop' && !in_array($device_type, ['Desktop'], true)) $device_allowed = false;
        if ($target_device === 'Tablet' && !in_array($device_type, ['Tablet'], true)) $device_allowed = false;
    }
    if ($strict_device && !$device_allowed) {
        $pdo->prepare("INSERT INTO logs (log_type, project_id, vendor_id, status, message, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute(['click', $project_id, $vendor_id, 'blocked', 'Device mismatch (target=' . $target_device . ', got=' . $device_type . ')', $ip_address ?? '']);
        $pdo->rollBack();
        http_response_code(403);
        die('Traffic device not permitted for this campaign.');
    }

    // Best-effort IP enrichment (cached, opt-out via setting)
    $ip_enrichment_enabled = get_setting($pdo, 'ip_enrichment_enabled', '1') === '1';
    $country_code = 'XX';
    $isp = '';
    if ($ip_enrichment_enabled && $ip_address) {
        $country_code = lookup_country($ip_address);
        $isp = substr(lookup_isp($ip_address), 0, 150);
    }

    $is_duplicate_ip = 0;
    if ($ip_address) {
        $dup = $pdo->prepare("SELECT id FROM clicks WHERE project_id = ? AND ip_address = ? AND COALESCE(is_test, 0) = 0 AND clicked_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1");
        $dup->execute([$project_id, $ip_address]);
        if ($dup->fetch()) $is_duplicate_ip = 1;
    }

    $click_id = null;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $click_id = bin2hex(random_bytes(16));
        try {
            $stmt = $pdo->prepare("INSERT INTO clicks (click_id, project_id, vendor_id, ip_address, user_agent, referrer, device_type, browser, os, browser_lang, isp, country_code, is_duplicate_ip, sub1, sub2, sub3, sub4, sub5) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$click_id, $project_id, $vendor_id, $ip_address, $user_agent, $referrer, $device_type, $browser, $os, $browser_lang, $isp, $country_code, $is_duplicate_ip, $sub1, $sub2, $sub3, $sub4, $sub5]);
            break;
        } catch (PDOException $e) {
            if ($e->getCode() == 23000 && strpos($e->getMessage(), 'Duplicate') !== false) {
                $click_id = null;
                continue;
            }
            throw $e;
        }
    }

    if (!$click_id) {
        $pdo->rollBack();
        log_click_error('Click ID generation failed');
        http_response_code(500);
        die('System error. Please try again.');
    }

    $pdo->prepare("UPDATE projects SET clicks_count = clicks_count + 1 WHERE id = ?")->execute([$project_id]);
    $pdo->prepare("INSERT INTO logs (log_type, project_id, vendor_id, click_id, status, message, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute(['click', $project_id, $vendor_id, $click_id, 'success', 'Click recorded from ' . $device_type . '/' . $browser, $ip_address]);

    $pdo->commit();
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    log_click_error('Unhandled click tracking exception', $e);
    http_response_code(500);
    die('System error. Please try again later.');
}

$redirect_url = $project['client_survey_link'];
if (!filter_var($redirect_url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//', $redirect_url)) {
    http_response_code(500);
    die('Invalid survey link configuration.');
}

$separator = (strpos($redirect_url, '?') !== false) ? '&' : '?';
$redirect_url .= $separator . 'click_id=' . $click_id;

// Forward sub-params if present
foreach ([['sub1', $sub1], ['sub2', $sub2], ['sub3', $sub3], ['sub4', $sub4], ['sub5', $sub5]] as $pair) {
    [$k, $v] = $pair;
    if ($v !== '') $redirect_url .= '&' . urlencode($k) . '=' . urlencode($v);
}

header('Location: ' . $redirect_url, true, 302);
exit;
