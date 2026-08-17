<?php

$__env_candidates = [__DIR__ . '/env.php', __DIR__ . '/../env.php'];
foreach ($__env_candidates as $__env) {
    if (is_file($__env)) {
        require_once $__env;
        break;
    }
}

// ─── Database Configuration ───
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'ENCRYPTION_KEY'] as $__required) {
    if (!defined($__required) || constant($__required) === '') {
        http_response_code(500);
        error_log('Track Flow configuration missing required constant: ' . $__required);
        exit('System configuration error.');
    }
}

// ─── Site Configuration ───
if (!defined('BASE_URL')) define('BASE_URL', 'https://arindam.freepage.cc');
define('SITE_NAME', 'Track Flow');
define('SESSION_TIMEOUT_HOURS', 8);

// ─── Error Reporting ───
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ─── Database Connection ───
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
        ]
    );
} catch (PDOException $e) {
    error_log('Track Flow DB connection failed: ' . $e->getMessage());
    die('System error. Please try again later.');
}

// ─── Session Configuration ───
// Public tracking endpoints must not start a session (InfinityFree session
// regenerate + cookie headers interfere with 302 click redirects).
$__is_tracking = defined('TF_TRACKING_REQUEST') && TF_TRACKING_REQUEST;
if (!$__is_tracking) {
    ini_set('session.gc_maxlifetime', SESSION_TIMEOUT_HOURS * 3600);
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor', 100);
    $__secure_cookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('ternfluenzy_session');
    session_set_cookie_params([
        'lifetime' => SESSION_TIMEOUT_HOURS * 3600,
        'path' => '/',
        'httponly' => true,
        'secure' => $__secure_cookie,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Regenerate session ID periodically to prevent fixation
    if (!isset($_SESSION['_last_regen']) || time() - $_SESSION['_last_regen'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['_last_regen'] = time();
    }
}

// ─── Load Helpers ───
require_once __DIR__ . '/helpers/functions.php';
require_once __DIR__ . '/helpers/constants.php';
require_once __DIR__ . '/helpers/dedupe.php';
require_once __DIR__ . '/helpers/email.php';
if (!$__is_tracking) {
    require_once __DIR__ . '/helpers/csrf.php';
    require_once __DIR__ . '/helpers/auth_middleware.php';
}
