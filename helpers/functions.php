<?php

/**
 * Redirect to a URL
 */
function redirect($url) {
    header('Location: ' . $url);
    exit;
}

/**
 * Sanitize user input
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate a unique click ID (32-char hex)
 */
function generate_click_id() {
    return bin2hex(random_bytes(16));
}

/**
 * Generate a postback token (64-char hex)
 */
function generate_postback_token() {
    return bin2hex(random_bytes(32));
}

/**
 * Generate project code: country code + YYMM + monthly sequence
 * Example: IN2607001 (country=IN, year=26, month=07, seq=001)
 * Uses a retry loop with UNIQUE constraint to handle race conditions
 */
function generate_project_code($pdo, $country) {
    $ym = date('ym');
    $countryCode = strtoupper(substr($country, 0, 2));
    if (strlen($countryCode) < 2) $countryCode = 'XX';

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $prefix = $countryCode . $ym;
        $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(project_code, 7) AS UNSIGNED)) as max_seq FROM projects WHERE project_code LIKE ?");
        $stmt->execute([$prefix . '%']);
        $seq = ($stmt->fetch()['max_seq'] ?? 0) + 1;

        $digits = $seq > 999 ? 4 : 3;
        $code = $prefix . str_pad($seq, $digits, '0', STR_PAD_LEFT);

        $check = $pdo->prepare("SELECT id FROM projects WHERE project_code = ?");
        $check->execute([$code]);
        if (!$check->fetch()) {
            return $code;
        }
    }

    return $countryCode . $ym . substr(bin2hex(random_bytes(2)), 0, 3);
}

/**
 * Get currency symbol
 */
function currency_symbol($currency = 'USD') {
    $symbols = [
        'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'INR' => '₹',
        'AED' => 'AED ', 'SAR' => 'SAR ', 'CAD' => 'C$', 'AUD' => 'A$',
    ];
    return $symbols[$currency] ?? '$';
}

/**
 * Format currency amount
 */
function format_currency($amount, $currency = 'USD') {
    return currency_symbol($currency) . number_format((float)$amount, 2);
}

/**
 * Relative time ago
 */
function time_ago($datetime) {
    $now = new DateTime();
    $then = new DateTime($datetime);
    $diff = $now->diff($then);

    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' min' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'Just now';
}

/**
 * Calculate pagination data
 */
function paginate($total, $per_page, $current_page) {
    $total_pages = max(1, ceil($total / $per_page));
    $current_page = max(1, min($current_page, $total_pages));
    $offset = ($current_page - 1) * $per_page;

    return [
        'total' => (int)$total,
        'per_page' => (int)$per_page,
        'current_page' => (int)$current_page,
        'total_pages' => (int)$total_pages,
        'offset' => (int)$offset,
        'has_prev' => $current_page > 1,
        'has_next' => $current_page < $total_pages,
    ];
}

/**
 * Render pagination HTML
 */
function render_pagination($pagination, $base_url) {
    if ($pagination['total_pages'] <= 1) return '';

    // $base_url may already contain query parameters (e.g. from http_build_query)
    $sep = strpos($base_url, '?') !== false ? '&' : '?';

    $html = '<nav class="tf-pagination" aria-label="Pagination"><ul>';

    // Previous
    if ($pagination['has_prev']) {
        $html .= '<li><a href="' . $base_url . $sep . 'page=' . ($pagination['current_page'] - 1) . '" aria-label="Previous page">&laquo;</a></li>';
    } else {
        $html .= '<li><span class="is-disabled" aria-hidden="true">&laquo;</span></li>';
    }

    // Page numbers
    $start = max(1, $pagination['current_page'] - 2);
    $end = min($pagination['total_pages'], $pagination['current_page'] + 2);

    if ($start > 1) {
        $html .= '<li><a href="' . $base_url . $sep . 'page=1">1</a></li>';
        if ($start > 2) $html .= '<li><span class="is-disabled" aria-hidden="true">...</span></li>';
    }

    for ($i = $start; $i <= $end; $i++) {
        $active = $i == $pagination['current_page'];
        $cls = $active ? ' class="is-active"' : '';
        $aria = $active ? ' aria-current="page"' : '';
        $html .= '<li><a' . $cls . $aria . ' href="' . $base_url . $sep . 'page=' . $i . '">' . $i . '</a></li>';
    }

    if ($end < $pagination['total_pages']) {
        if ($end < $pagination['total_pages'] - 1) $html .= '<li><span class="is-disabled" aria-hidden="true">...</span></li>';
        $html .= '<li><a href="' . $base_url . $sep . 'page=' . $pagination['total_pages'] . '">' . $pagination['total_pages'] . '</a></li>';
    }

    // Next
    if ($pagination['has_next']) {
        $html .= '<li><a href="' . $base_url . $sep . 'page=' . ($pagination['current_page'] + 1) . '" aria-label="Next page">&raquo;</a></li>';
    } else {
        $html .= '<li><span class="is-disabled" aria-hidden="true">&raquo;</span></li>';
    }

    $html .= '</ul></nav>';
    return $html;
}

/**
 * Calculate CCR (Client Completion Rate)
 */
function calc_ccr($completes, $clicks) {
    if ($clicks == 0) return 0;
    return round(($completes / $clicks) * 100, 2);
}

/**
 * Get CCR color class
 */
function ccr_color($ccr) {
    if ($ccr >= 20) return 'success';
    if ($ccr >= 10) return 'warning';
    return 'danger';
}

/**
 * Get status badge HTML
 */
function status_badge($status) {
    $colors = [
        'live' => 'bg-success',
        'hold' => 'bg-warning',
        'closed' => 'bg-danger',
        'archived' => 'bg-light',
        'active' => 'bg-success',
        'complete' => 'bg-success',
        'rejected' => 'bg-danger',
        'duplicate' => 'bg-light',
        'manual' => 'bg-info',
    ];
    $color = $colors[$status] ?? 'bg-light';
    return '<span class="badge ' . $color . '">' . ucfirst($status) . '</span>';
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

/**
 * Get setting value from settings table
 */
function get_setting($pdo, $key, $default = '') {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

/**
 * Set/update a setting value
 */
function set_setting($pdo, $key, $value) {
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$key, $value, $value]);
}

/**
 * Flash message (set in session, display once)
 */
function set_flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Get and clear flash message
 */
function get_flash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Get current base URL for navigation
 */
function current_url() {
    return $_SERVER['REQUEST_URI'] ?? '';
}

/**
 * Encrypt sensitive data (SMTP passwords, etc.)
 * Uses OpenSSL if available, falls back to base64
 */
function encrypt_value($value, $key = null) {
    if (empty($value)) return '';
    if (!$key) $key = ENCRYPTION_KEY;

    if (function_exists('openssl_encrypt')) {
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . '::' . $encrypted);
    }
    return base64_encode($value);
}

/**
 * Decrypt sensitive data
 */
function decrypt_value($value, $key = null) {
    if (empty($value)) return '';
    if (!$key) $key = ENCRYPTION_KEY;

    if (function_exists('openssl_decrypt')) {
        $decoded = base64_decode($value, true);
        if ($decoded === false) return $value;
        $parts = explode('::', $decoded, 2);
        if (count($parts) === 2) {
            return openssl_decrypt($parts[1], 'AES-256-CBC', $key, 0, $parts[0]);
        }
    }
    $decoded = base64_decode($value, true);
    return $decoded !== false ? $decoded : $value;
}

/**
 * Generate an 8-character URL-safe short code (base62)
 * Collision-safe via the caller-supplied UNIQUE column on the table.
 */
function generate_short_code($length = 8) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $code = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, $max)];
    }
    return $code;
}

/**
 * Ensure the project has a unique short_code; idempotent.
 */
function ensure_project_short_code($pdo, $project_id, $existing = null) {
    if ($existing && !empty($existing)) return $existing;
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $code = generate_short_code(8);
        $stmt = $pdo->prepare("SELECT id FROM projects WHERE short_code = ?");
        $stmt->execute([$code]);
        if (!$stmt->fetch()) {
            $pdo->prepare("UPDATE projects SET short_code = ? WHERE id = ?")->execute([$code, $project_id]);
            return $code;
        }
    }
    // Last resort: append random suffix
    $code = generate_short_code(12);
    $pdo->prepare("UPDATE projects SET short_code = ? WHERE id = ?")->execute([$code, $project_id]);
    return $code;
}

/**
 * Return the one opaque public URL code assigned to a project/vendor pair.
 * The unique database constraint is the collision authority; retry instead of
 * falling back to a project-level URL, which would lose vendor attribution.
 */
function ensure_vendor_short_link($pdo, $project_id, $vendor_id) {
    $existing = $pdo->prepare('SELECT code FROM short_links WHERE project_id = ? AND vendor_id = ? LIMIT 1');
    $existing->execute([$project_id, $vendor_id]);
    $code = $existing->fetchColumn();
    if ($code) return $code;

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $candidate = generate_short_code(12);
        try {
            $insert = $pdo->prepare('INSERT INTO short_links (code, project_id, vendor_id, created_at) VALUES (?, ?, ?, NOW())');
            $insert->execute([$candidate, $project_id, $vendor_id]);
            return $candidate;
        } catch (PDOException $e) {
            // A collision is extremely unlikely; fetch in case another request
            // created the same pair while this request was in progress.
            $existing->execute([$project_id, $vendor_id]);
            $code = $existing->fetchColumn();
            if ($code) return $code;
        }
    }
    throw new RuntimeException('Could not allocate a unique tracking code.');
}

/**
 * Write to audit_logs. No-op if the table doesn't exist yet (Phase 1 not applied).
 * Sensitive/PII fields are redacted before persistence (Phase 9 hardening).
 */
function audit_redact(&$data) {
    if (!is_array($data)) return;
    // Fields whose plaintext values we never want in the audit trail
    $sensitive = ['password','password_hash','postback_token','reset_token','magic_token','smtp_pass',
                  'resend_api_key','api_key','billing_address','contact_info'];
    foreach ($data as $k => &$v) {
        $lk = strtolower((string)$k);
        if (is_array($v)) { audit_redact($v); continue; }
        if (in_array($lk, $sensitive, true)) {
            $v = is_scalar($v) && $v !== null && $v !== '' ? '••••redacted••••' : $v;
        } elseif (is_string($v) && (stripos($lk, 'email') !== false || $lk === 'phone')) {
            $v = '••••redacted••••';
        }
    }
}

function audit_log($pdo, $action, $entity_type, $entity_id, $before = null, $after = null, $actor_id = null) {
    try {
        if ($actor_id === null && isset($_SESSION['user_id'])) $actor_id = $_SESSION['user_id'];
        if (is_array($before)) audit_redact($before);
        if (is_array($after))  audit_redact($after);
        $pdo->prepare("INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, before_json, after_json, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $actor_id, $action, $entity_type, $entity_id,
                $before !== null ? json_encode($before) : null,
                $after !== null ? json_encode($after) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]);
    } catch (Throwable $e) {
        // Audit failures must never break the main flow.
        error_log('audit_log failed: ' . $e->getMessage());
    }
}

/**
 * Detect browser family from a User-Agent string.
 * Returns short label like 'Chrome', 'Firefox', 'Safari', 'Edge', 'Opera', 'IE', 'Unknown'.
 */
function detect_browser($ua) {
    if (empty($ua)) return 'Unknown';
    $ua = strtolower($ua);
    if (preg_match('/edg\/|edge\//', $ua)) return 'Edge';
    if (preg_match('/opr\/|opera/', $ua)) return 'Opera';
    if (preg_match('/chrome|crios/', $ua) && !preg_match('/chromium/', $ua)) return 'Chrome';
    if (preg_match('/firefox|fxios/', $ua)) return 'Firefox';
    if (preg_match('/safari/', $ua) && !preg_match('/chrome/', $ua)) return 'Safari';
    if (preg_match('/msie|trident/', $ua)) return 'IE';
    if (preg_match('/curl|wget|bot|spider|crawl/', $ua)) return 'Bot';
    return 'Other';
}

/**
 * Detect operating system from a User-Agent string.
 */
function detect_os($ua) {
    if (empty($ua)) return 'Unknown';
    $ua = strtolower($ua);
    if (preg_match('/windows nt 10/', $ua)) return 'Windows 10/11';
    if (preg_match('/windows nt 6\.3/', $ua)) return 'Windows 8.1';
    if (preg_match('/windows nt 6\.2/', $ua)) return 'Windows 8';
    if (preg_match('/windows nt 6\.1/', $ua)) return 'Windows 7';
    if (preg_match('/windows/', $ua)) return 'Windows';
    if (preg_match('/mac os x|macintosh/', $ua)) {
        if (preg_match('/iphone|ipad|ipod/', $ua)) return 'iOS';
        return 'macOS';
    }
    if (preg_match('/android/', $ua)) return 'Android';
    if (preg_match('/linux/', $ua)) return 'Linux';
    if (preg_match('/cros/', $ua)) return 'ChromeOS';
    return 'Other';
}

/**
 * Sliding-window rate limiter backed by the `rate_limits` table.
 * Returns ['allowed' => bool, 'retry_after' => minutes|null].
 */
function check_rate_limit($pdo, $identity, $action, $max, $window_seconds = 60) {
    $identity = substr($identity, 0, 45);
    $stmt = $pdo->prepare("SELECT attempts, locked_until, created_at FROM rate_limits WHERE ip_address = ? AND action = ?");
    $stmt->execute([$identity, $action]);
    $row = $stmt->fetch();

    if ($row && $row['locked_until'] && strtotime($row['locked_until']) > time()) {
        return ['allowed' => false, 'retry_after' => max(1, ceil((strtotime($row['locked_until']) - time()) / 60))];
    }

    if (!$row) {
        $pdo->prepare("INSERT INTO rate_limits (ip_address, action, attempts, created_at) VALUES (?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE attempts = attempts")
            ->execute([$identity, $action]);
        return ['allowed' => true, 'retry_after' => null];
    }

    // Sliding window expired → reset
    if (strtotime($row['created_at']) < time() - $window_seconds) {
        $pdo->prepare("UPDATE rate_limits SET attempts = 1, locked_until = NULL, created_at = NOW() WHERE ip_address = ? AND action = ?")
            ->execute([$identity, $action]);
        return ['allowed' => true, 'retry_after' => null];
    }

    if ((int)$row['attempts'] >= $max) {
        $pdo->prepare("UPDATE rate_limits SET attempts = 0, locked_until = DATE_ADD(NOW(), INTERVAL 1 MINUTE) WHERE ip_address = ? AND action = ?")
            ->execute([$identity, $action]);
        return ['allowed' => false, 'retry_after' => 1];
    }

    $pdo->prepare("UPDATE rate_limits SET attempts = attempts + 1 WHERE ip_address = ? AND action = ?")
        ->execute([$identity, $action]);
    return ['allowed' => true, 'retry_after' => null];
}

/**
 * Best-effort country lookup with file cache.
 * Returns ISO-2 country code or 'XX' if unknown.
 * Tries ipapi.co (HTTPS, free, no key) with a 1-day cache.
 */
function lookup_country($ip) {
    if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) return 'XX';
    // Skip private/reserved IPs
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return 'XX';

    $cache_dir = __DIR__ . '/../storage/geo_cache';
    if (!is_dir($cache_dir)) @mkdir($cache_dir, 0755, true);
    $cache_file = $cache_dir . '/' . md5($ip) . '.txt';
    if (is_file($cache_file) && (time() - filemtime($cache_file)) < 86400) {
        $cached = trim(file_get_contents($cache_file));
        if ($cached !== '') return $cached;
    }

    $country = 'XX';
    $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    $body = @file_get_contents("https://ipapi.co/{$ip}/country/", false, $ctx);
    if (is_string($body) && preg_match('/^[A-Z]{2}$/', trim($body))) {
        $country = trim($body);
    }
    @file_put_contents($cache_file, $country);
    return $country;
}

/**
 * Best-effort ISP lookup with file cache. Same caching strategy as country.
 */
function lookup_isp($ip) {
    if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) return '';
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return '';

    $cache_dir = __DIR__ . '/../storage/geo_cache';
    if (!is_dir($cache_dir)) @mkdir($cache_dir, 0755, true);
    $cache_file = $cache_dir . '/' . md5($ip . '_isp') . '.txt';
    if (is_file($cache_file) && (time() - filemtime($cache_file)) < 86400) {
        return trim(file_get_contents($cache_file));
    }

    $isp = '';
    $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    $body = @file_get_contents("https://ipapi.co/{$ip}/org/", false, $ctx);
    if (is_string($body)) {
        $isp = trim($body);
    }
    @file_put_contents($cache_file, $isp);
    return $isp;
}

/** Opaque tracking codes are 4–12 URL-safe characters (matches short_links.code). */
function tf_short_code_regex()
{
    return '/^[A-Za-z0-9_-]{4,12}$/';
}

function tf_is_valid_short_code($code)
{
    return is_string($code) && (bool)preg_match(tf_short_code_regex(), $code);
}

function tf_opaque_tracking_url($code)
{
    return rtrim(BASE_URL, '/') . '/c/' . $code;
}

function tf_vendor_can_be_assigned($vendor_status)
{
    return $vendor_status === 'approved';
}

function tf_is_tracking_admin()
{
    if (!function_exists('is_logged_in') || !is_logged_in()) {
        return false;
    }
    return in_array($_SESSION['role'] ?? '', ['super_admin', 'campaign_manager'], true);
}

function tf_not_test_sql($alias)
{
    return ' AND COALESCE(' . $alias . '.is_test, 0) = 0';
}

function tf_request_subs()
{
    $subs = [];
    foreach (['sub1', 'sub2', 'sub3', 'sub4', 'sub5'] as $key) {
        $value = trim((string)($_GET[$key] ?? ''));
        $subs[$key] = $value !== '' ? substr($value, 0, 200) : '';
    }
    return $subs;
}

/** @return array{0:string,1:array} */
function tf_sub_sql($alias, $subs = null)
{
    $subs = $subs ?? tf_request_subs();
    $sql = '';
    $params = [];
    foreach ($subs as $key => $value) {
        if ($value === '') {
            continue;
        }
        $sql .= ' AND ' . $alias . '.' . $key . ' = ?';
        $params[] = $value;
    }
    return [$sql, $params];
}

/**
 * Stream an Excel-compatible SpreadsheetML workbook (opens in Excel without Composer).
 */
function tf_output_excel($filename, $headers, $rows)
{
    $xml_escape = static function ($value) {
        return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
    echo '<Worksheet ss:Name="Export"><Table>';
    echo '<Row>';
    foreach ($headers as $header) {
        echo '<Cell><Data ss:Type="String">' . $xml_escape($header) . '</Data></Cell>';
    }
    echo '</Row>';
    foreach ($rows as $row) {
        echo '<Row>';
        foreach ($row as $cell) {
            $numeric = is_numeric($cell) && !is_string($cell);
            $type = $numeric ? 'Number' : 'String';
            echo '<Cell><Data ss:Type="' . $type . '">' . $xml_escape($cell) . '</Data></Cell>';
        }
        echo '</Row>';
    }
    echo '</Table></Worksheet></Workbook>';
}

function tf_request_value($key, $default = '')
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && array_key_exists($key, $_POST)) {
        return $_POST[$key];
    }
    if (array_key_exists($key, $_GET)) {
        return $_GET[$key];
    }
    return $default;
}

/**
 * Resolve an opaque code to an active project-vendor assignment.
 * Inactive, suspended, or unknown codes return null (callers should 404).
 */
function tf_resolve_active_short_link($pdo, $code)
{
    if (!tf_is_valid_short_code($code)) {
        return null;
    }
    $stmt = $pdo->prepare("
        SELECT sl.project_id, sl.vendor_id, sl.code, pv.status AS assignment_status, gv.vendor_status
        FROM short_links sl
        INNER JOIN project_vendor pv ON pv.project_id = sl.project_id AND pv.vendor_id = sl.vendor_id
        INNER JOIN global_vendors gv ON gv.id = sl.vendor_id
        WHERE sl.code = ?
        LIMIT 1
    ");
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if (($row['assignment_status'] ?? '') !== 'active') {
        return null;
    }
    if (in_array($row['vendor_status'] ?? '', ['suspended', 'blacklisted'], true)) {
        return null;
    }
    return $row;
}
