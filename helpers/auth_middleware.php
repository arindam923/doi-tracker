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
