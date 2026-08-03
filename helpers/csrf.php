<?php

/**
 * Generate or return existing CSRF token
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Output hidden CSRF field for forms
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . generate_csrf_token() . '">';
}

/**
 * Validate CSRF token (constant-time comparison)
 */
function validate_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Regenerate CSRF token (call after form submission)
 */
function regenerate_csrf_token() {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
