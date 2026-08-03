<?php
/**
 * TERNFLUENZY — Click Tracking Engine
 * Records every click and redirects to client survey with click_id
 *
 * URL: /tracking/click.php?project_id=101&vendor_id=5
 */

// Minimal bootstrap — no session needed for click tracking
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/config.php';

function log_click_error($message, $exception = null) {
    $log_dir = __DIR__ . '/../logs';
    $log_file = $log_dir . '/click-errors.log';
    $details = date('c') . ' | ' . $message;

    if ($exception instanceof Throwable) {
        $details .= ' | ' . get_class($exception) . ': ' . $exception->getMessage();
        $details .= ' | file=' . $exception->getFile() . ' | line=' . $exception->getLine();
    }

    $details .= ' | project_id=' . (int)($_GET['project_id'] ?? 0);
    $details .= ' | vendor_id=' . (int)($_GET['vendor_id'] ?? 0);
    $details .= ' | request_uri=' . ($_SERVER['REQUEST_URI'] ?? '');
    $details .= PHP_EOL;

    if (is_dir($log_dir) && is_writable($log_dir)) {
        file_put_contents($log_file, $details, FILE_APPEND | LOCK_EX);
    }
    error_log('Ternfluenzy click tracking: ' . $details);
}

/**
 * Detect device type from user agent
 */
function detect_device_type($user_agent) {
    $ua = strtolower($user_agent);
    if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobile))/i', $ua)) {
        return 'tablet';
    }
    if (preg_match('/mobile|android|iphone|ipod|opera mini|iemobile/i', $ua)) {
        return 'mobile';
    }
    return 'desktop';
}

$project_id = intval($_GET['project_id'] ?? 0);
$vendor_id  = intval($_GET['vendor_id']  ?? 0);

// 1. Validate inputs
if (!$project_id || !$vendor_id) {
    http_response_code(400);
    die('Invalid tracking link.');
}

try {
    // Begin transaction to prevent race conditions
    $pdo->beginTransaction();

    // 2. Check project is Live (SELECT ... FOR UPDATE to lock the row)
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ? AND status = 'live' FOR UPDATE");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();

    if (!$project) {
        $pdo->rollBack();
        http_response_code(410);
        die('This campaign is not currently active.');
    }

    // 3. Check quota not exceeded
    if ($project['total_quota'] > 0 && $project['completes_count'] >= $project['total_quota']) {
        // Auto-hold the project
        $pdo->prepare("UPDATE projects SET status = 'hold' WHERE id = ? AND status = 'live'")->execute([$project_id]);
        $pdo->prepare("UPDATE vendors SET status = 'paused' WHERE project_id = ? AND status = 'active'")->execute([$project_id]);
        $pdo->rollBack();
        http_response_code(410);
        die('This campaign has reached its quota.');
    }

    // 4. Check vendor is Active and belongs to this project
    $stmt = $pdo->prepare("SELECT * FROM vendors WHERE id = ? AND project_id = ? AND status = 'active' FOR UPDATE");
    $stmt->execute([$vendor_id, $project_id]);
    $vendor = $stmt->fetch();

    if (!$vendor) {
        $pdo->rollBack();
        http_response_code(410);
        die('Invalid or inactive traffic source.');
    }

    // 5. Check vendor click limit
    if ($vendor['allowed_clicks_limit'] > 0) {
        $click_count_stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM clicks WHERE vendor_id = ? AND project_id = ?");
        $click_count_stmt->execute([$vendor_id, $project_id]);
        $vendor_clicks = $click_count_stmt->fetch()['cnt'];

        if ($vendor_clicks >= $vendor['allowed_clicks_limit']) {
            $pdo->prepare("UPDATE vendors SET status = 'paused' WHERE id = ?")->execute([$vendor_id]);
            $pdo->rollBack();
            http_response_code(410);
            die('This traffic source has reached its limit.');
        }
    }

    // 6. Collect click data
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000); // Truncate
    $referrer   = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500);     // Truncate
    $device_type = detect_device_type($user_agent);

    // 7. Check for duplicate IP (flag, don't block)
    $is_duplicate_ip = 0;
    if ($ip_address) {
        $dup_stmt = $pdo->prepare("SELECT id FROM clicks WHERE project_id = ? AND ip_address = ? AND clicked_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1");
        $dup_stmt->execute([$project_id, $ip_address]);
        if ($dup_stmt->fetch()) {
            $is_duplicate_ip = 1;
        }
    }

    // 8. Generate unique Click ID with collision retry
    $max_retries = 3;
    $click_id = null;
    for ($attempt = 0; $attempt < $max_retries; $attempt++) {
        $click_id = bin2hex(random_bytes(16));
        try {
            $stmt = $pdo->prepare("INSERT INTO clicks (click_id, project_id, vendor_id, ip_address, user_agent, referrer, device_type, is_duplicate_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$click_id, $project_id, $vendor_id, $ip_address, $user_agent, $referrer, $device_type, $is_duplicate_ip]);
            break; // Success
        } catch (PDOException $e) {
            if ($e->getCode() == 23000 && strpos($e->getMessage(), 'Duplicate') !== false) {
                $click_id = null; // Collision, retry
                continue;
            }
            throw $e; // Other error
        }
    }

    if (!$click_id) {
        $pdo->rollBack();
        log_click_error('Click ID generation failed');
        http_response_code(500);
        die('System error. Please try again.');
    }

    // 9. Update project click counter atomically
    $pdo->prepare("UPDATE projects SET clicks_count = clicks_count + 1 WHERE id = ?")->execute([$project_id]);

    // 10. Log the click
    $pdo->prepare("INSERT INTO logs (log_type, project_id, vendor_id, click_id, status, message, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute(['click', $project_id, $vendor_id, $click_id, 'success', 'Click recorded from ' . $device_type, $ip_address]);

    // Commit transaction
    $pdo->commit();

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_click_error('Unhandled click tracking exception', $e);
    http_response_code(500);
    die('System error. Please try again later.');
}

// 11. Redirect to client survey link with click_id
$redirect_url = $project['client_survey_link'];

// Validate URL before redirect
if (!filter_var($redirect_url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//', $redirect_url)) {
    http_response_code(500);
    die('Invalid survey link configuration.');
}

$separator = (strpos($redirect_url, '?') !== false) ? '&' : '?';
$redirect_url .= $separator . 'click_id=' . $click_id;

header('Location: ' . $redirect_url, true, 302);
exit;
