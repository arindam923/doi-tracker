<?php

/**
 * Check if user is logged in and session hasn't timed out
 */
function is_logged_in() {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        return false;
    }

    // Enforce session timeout
    $timeout_hours = defined('SESSION_TIMEOUT_HOURS') ? SESSION_TIMEOUT_HOURS : 8;
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > $timeout_hours * 3600)) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        return false;
    }

    return true;
}

/**
 * Get current user data from session
 */
function current_user() {
    if (!is_logged_in()) return null;
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'role' => $_SESSION['role'],
        'email' => $_SESSION['email'] ?? '',
    ];
}

/**
 * Require user to be logged in — redirect to login if not
 */
function require_login() {
    if (!is_logged_in()) {
        set_flash('warning', 'Please log in to continue.');
        redirect(BASE_URL . '/index.php');
    }
}

/**
 * Require user to have one of the specified roles
 */
function require_role($allowed_roles) {
    require_login();
    $user = current_user();
    if (!in_array($user['role'], (array)$allowed_roles)) {
        set_flash('danger', 'You do not have permission to access this page.');
        redirect(BASE_URL . '/dashboard.php');
    }
}

/**
 * Check if current user has a specific role
 */
function has_role($role) {
    $user = current_user();
    return $user && $user['role'] === $role;
}

/**
 * ─── Vendor Portal helpers (Phase 8B) ───────────────────────────
 * Vendors authenticate into a separate session namespace so an admin and a
 * vendor can be logged in simultaneously without collision.
 */

/**
 * Check whether the vendor portal feature is enabled.
 */
function vendor_portal_enabled($pdo) {
    return get_setting($pdo, 'vendor_login_enabled', '0') === '1';
}

/**
 * Resolve current logged-in vendor from $_SESSION['TF_VENDOR'].
 */
function current_vendor() {
    return isset($_SESSION['TF_VENDOR']) && is_array($_SESSION['TF_VENDOR']) ? $_SESSION['TF_VENDOR'] : null;
}

/**
 * Whether a vendor is currently authenticated in the vendor namespace.
 */
function vendor_logged_in() {
    return isset($_SESSION['TF_VENDOR']['global_vendor_id']) && !empty($_SESSION['TF_VENDOR']['global_vendor_id']);
}

/**
 * Require vendor login; bounce to the vendor auth page otherwise.
 * Respects the vendor_login_enabled kill-switch.
 */
function require_vendor_login($pdo) {
    if (!vendor_portal_enabled($pdo)) {
        header('HTTP/1.1 403 Forbidden');
        die('The vendor portal is currently disabled.');
    }
    if (!vendor_logged_in()) {
        set_flash('danger', 'Please sign in to your vendor account.');
        redirect(BASE_URL . '/vendor_portal/auth.php');
    }
    return current_vendor();
}
