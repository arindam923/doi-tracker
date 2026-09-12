<?php
/**
 * TRACK FLOW — Click Tracking Engine
 * Internal click-tracking engine. Public traffic must enter through /c/CODE
 * or /go/CODE and be resolved by redirect.php first.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/config.php';

if (!defined('TF_INTERNAL_CLICK_REQUEST') || TF_INTERNAL_CLICK_REQUEST !== true) {
    http_response_code(404);
    die('Tracking link not found.');
}

$project_id = intval($_GET['project_id'] ?? 0);
$vendor_id  = intval($_GET['vendor_id']  ?? 0);

if (!$project_id || !$vendor_id) {
    http_response_code(400);
    die('Invalid tracking link.');
}

$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($client_ip !== '') {
    try {
        $rl = check_rate_limit($pdo, $client_ip, 'click', 100, 60);
        if (!$rl['allowed']) {
            header('Retry-After: ' . ($rl['retry_after'] ?? 60));
            http_response_code(429);
            die('Too many requests.');
        }
    } catch (Throwable $e) {
        tf_log_event($pdo, 'click', 'warning', 'Rate limit skipped: ' . $e->getMessage(), $project_id, $vendor_id);
    }
}

$sub1 = substr($_GET['sub1'] ?? '', 0, 200);
$sub2 = substr($_GET['sub2'] ?? '', 0, 200);
$sub3 = substr($_GET['sub3'] ?? '', 0, 200);
$sub4 = substr($_GET['sub4'] ?? '', 0, 200);
$sub5 = substr($_GET['sub5'] ?? '', 0, 200);
$email_send_id = intval($_GET['sid'] ?? 0);

$ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000);
$referrer   = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500);
$browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 0, 20);
$device_type = detect_device_type($user_agent);
$browser = detect_browser($user_agent);
$os = detect_os($user_agent);

$country_code = 'XX';
$isp = '';
try {
    $ip_enrichment_enabled = get_setting($pdo, 'ip_enrichment_enabled', '1') === '1';
    if ($ip_enrichment_enabled && $ip_address) {
        $country_code = lookup_country($ip_address);
        $isp = substr(lookup_isp($ip_address), 0, 150);
    }
} catch (Throwable $e) {
    tf_log_event($pdo, 'click', 'warning', 'IP enrichment skipped: ' . $e->getMessage(), $project_id, $vendor_id);
}

try {
    $project = tf_fetch_one($pdo, "SELECT * FROM projects WHERE id = ? AND status = 'live'", [$project_id]);
    if (!$project) {
        http_response_code(410);
        die('This campaign is not currently active.');
    }

    if (!empty($project['total_quota']) && (int)$project['total_quota'] > 0
        && (int)($project['completes_count'] ?? 0) >= (int)$project['total_quota']) {
        try {
            $pdo->prepare("UPDATE projects SET status = 'hold' WHERE id = ? AND status = 'live'")->execute([$project_id]);
            $pdo->prepare("UPDATE project_vendor SET status = 'hold' WHERE project_id = ? AND status = 'active'")->execute([$project_id]);
        } catch (Throwable $e) {
            tf_log_event($pdo, 'click', 'warning', 'Quota hold update failed: ' . $e->getMessage(), $project_id, $vendor_id);
        }
        http_response_code(410);
        die('This campaign has reached its quota.');
    }

    $vendor = tf_fetch_one(
        $pdo,
        "SELECT pv.*, gv.vendor_status, gv.vendor_name
         FROM project_vendor pv
         JOIN global_vendors gv ON gv.id = pv.vendor_id
         WHERE pv.vendor_id = ? AND pv.project_id = ?
           AND pv.status = 'active'
           AND gv.vendor_status = 'approved'",
        [$vendor_id, $project_id]
    );
    if (!$vendor) {
        http_response_code(410);
        die('Invalid or inactive traffic source.');
    }

    if (!empty($vendor['allowed_clicks_limit']) && (int)$vendor['allowed_clicks_limit'] > 0) {
        $cnt = tf_fetch_one($pdo, "SELECT COUNT(*) as cnt FROM clicks WHERE vendor_id = ? AND project_id = ?", [$vendor_id, $project_id]);
        if ((int)($cnt['cnt'] ?? 0) >= (int)$vendor['allowed_clicks_limit']) {
            try {
                $pdo->prepare("UPDATE project_vendor SET status = 'hold' WHERE vendor_id = ? AND project_id = ?")->execute([$vendor_id, $project_id]);
            } catch (Throwable $e) {}
            http_response_code(410);
            die('This traffic source has reached its limit.');
        }
    }

    $target_device = $project['target_device'] ?? 'All';
    $strict_device = false;
    try {
        $strict_device = get_setting($pdo, 'strict_target_device', '0') === '1';
    } catch (Throwable $e) {}
    $got = strtolower((string)$device_type);
    $device_allowed = true;
    if ($target_device !== 'All') {
        if ($target_device === 'Mobile' && $got !== 'mobile') $device_allowed = false;
        if ($target_device === 'Desktop' && $got !== 'desktop') $device_allowed = false;
        if ($target_device === 'Tablet' && $got !== 'tablet') $device_allowed = false;
    }
    if ($strict_device && !$device_allowed) {
        tf_log_event($pdo, 'click', 'blocked', 'Device mismatch (target=' . $target_device . ', got=' . $device_type . ')', $project_id, $vendor_id);
        http_response_code(403);
        die('Traffic device not permitted for this campaign.');
    }

    $is_duplicate_ip = 0;
    if ($ip_address) {
        try {
            $dup = tf_fetch_one(
                $pdo,
                "SELECT id FROM clicks WHERE project_id = ? AND ip_address = ? AND clicked_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1",
                [$project_id, $ip_address]
            );
            if ($dup) $is_duplicate_ip = 1;
        } catch (Throwable $e) {}
    }

    $click_id = null;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $click_id = generate_click_id();
        try {
            tf_record_click($pdo, [
                'click_id' => $click_id,
                'project_id' => $project_id,
                'vendor_id' => $vendor_id,
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'referrer' => $referrer,
                'device_type' => $device_type,
                'browser' => $browser,
                'os' => $os,
                'browser_lang' => $browser_lang,
                'isp' => $isp,
                'country_code' => $country_code,
                'is_duplicate_ip' => $is_duplicate_ip,
                'sub1' => $sub1,
                'sub2' => $sub2,
                'sub3' => $sub3,
                'sub4' => $sub4,
                'sub5' => $sub5,
                'email_send_id' => $email_send_id > 0 ? $email_send_id : null,
            ]);
            break;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                $click_id = null;
                continue;
            }
            throw $e;
        }
    }

    if (!$click_id) {
        tf_log_event($pdo, 'click', 'failed', 'Click ID generation failed', $project_id, $vendor_id);
        http_response_code(500);
        die('System error. Please try again.');
    }
} catch (Throwable $e) {
    tf_log_event($pdo, 'click', 'failed', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), $project_id, $vendor_id);
    http_response_code(500);
    die('System error. Please try again later.');
}

try {
    $pdo->prepare("UPDATE projects SET clicks_count = clicks_count + 1 WHERE id = ?")->execute([$project_id]);
} catch (Throwable $e) {
    tf_log_event($pdo, 'click', 'warning', 'clicks_count update failed: ' . $e->getMessage(), $project_id, $vendor_id, $click_id);
}
tf_log_event($pdo, 'click', 'success', 'Click recorded from ' . $device_type . '/' . $browser, $project_id, $vendor_id, $click_id);

$redirect_url = $project['client_survey_link'] ?? '';
if (!filter_var($redirect_url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//', $redirect_url)) {
    http_response_code(500);
    die('Invalid survey link configuration.');
}

$separator = (strpos($redirect_url, '?') !== false) ? '&' : '?';
$redirect_url .= $separator . 'click_id=' . urlencode($click_id);
foreach ([['sub1', $sub1], ['sub2', $sub2], ['sub3', $sub3], ['sub4', $sub4], ['sub5', $sub5]] as $pair) {
    [$k, $v] = $pair;
    if ($v !== '') $redirect_url .= '&' . urlencode($k) . '=' . urlencode($v);
}

header('Location: ' . $redirect_url, true, 302);
exit;
