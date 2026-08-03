<?php
// ─── Database Configuration ───
define('DB_HOST', 'sql101.infinityfree.com');
define('DB_NAME', 'if0_42533255_bhbhbh');
define('DB_USER', 'if0_42533255');
define('DB_PASS', 'vamaC9JgzFRljk');

// ─── Site Configuration ───
define('BASE_URL', 'https://djcsdcd.ct.ws');
define('SITE_NAME', 'Ternfluenzy');
define('SESSION_TIMEOUT_HOURS', 8);
define('ENCRYPTION_KEY', '1bee453ee7dbde0a971b17254cd94d2eeb212f7347ecaf59b9cef5b5ac92b324');
if (ENCRYPTION_KEY === 'change_this_to_a_random_32_char_string_in_production' && $_SERVER['SERVER_NAME'] !== 'localhost') {
    error_log('WARNING: Default ENCRYPTION_KEY in use. Generate a unique key for production.');
}

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
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log('Ternfluenzy DB connection failed: ' . $e->getMessage());
    die('System error. Please try again later.');
}

// ─── Session Configuration ───
ini_set('session.gc_maxlifetime', SESSION_TIMEOUT_HOURS * 3600);
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);
session_name('ternfluenzy_session');
session_set_cookie_params([
    'lifetime' => SESSION_TIMEOUT_HOURS * 3600,
    'path' => '/',
    'httponly' => true,
    'secure' => true,
    'samesite' => 'Lax',
]);
session_start();

// Regenerate session ID periodically to prevent fixation
if (!isset($_SESSION['_last_regen']) || time() - $_SESSION['_last_regen'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['_last_regen'] = time();
}

// ─── Load Helpers ───
require_once __DIR__ . '/helpers/functions.php';
require_once __DIR__ . '/helpers/csrf.php';
require_once __DIR__ . '/helpers/auth_middleware.php';
