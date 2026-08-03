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

    $html = '<nav class="tf-pagination" aria-label="Pagination"><ul>';

    // Previous
    if ($pagination['has_prev']) {
        $html .= '<li><a href="' . $base_url . '?page=' . ($pagination['current_page'] - 1) . '" aria-label="Previous page">&laquo;</a></li>';
    } else {
        $html .= '<li><span class="is-disabled" aria-hidden="true">&laquo;</span></li>';
    }

    // Page numbers
    $start = max(1, $pagination['current_page'] - 2);
    $end = min($pagination['total_pages'], $pagination['current_page'] + 2);

    if ($start > 1) {
        $html .= '<li><a href="' . $base_url . '?page=1">1</a></li>';
        if ($start > 2) $html .= '<li><span class="is-disabled" aria-hidden="true">...</span></li>';
    }

    for ($i = $start; $i <= $end; $i++) {
        $active = $i == $pagination['current_page'];
        $cls = $active ? ' class="is-active"' : '';
        $aria = $active ? ' aria-current="page"' : '';
        $html .= '<li><a' . $cls . $aria . ' href="' . $base_url . '?page=' . $i . '">' . $i . '</a></li>';
    }

    if ($end < $pagination['total_pages']) {
        if ($end < $pagination['total_pages'] - 1) $html .= '<li><span class="is-disabled" aria-hidden="true">...</span></li>';
        $html .= '<li><a href="' . $base_url . '?page=' . $pagination['total_pages'] . '">' . $pagination['total_pages'] . '</a></li>';
    }

    // Next
    if ($pagination['has_next']) {
        $html .= '<li><a href="' . $base_url . '?page=' . ($pagination['current_page'] + 1) . '" aria-label="Next page">&raquo;</a></li>';
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
        'active' => 'bg-success',
        'paused' => 'bg-warning',
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
