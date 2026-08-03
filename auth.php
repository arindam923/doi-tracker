<?php
require_once __DIR__ . '/config.php';

// ─── Rate limit cleanup (called periodically from frontend, requires auth) ───
if (isset($_GET['action']) && $_GET['action'] === 'cleanup_ratelimits') {
    // Only allow authenticated admin users to trigger cleanup
    if (!is_logged_in() || !has_role('super_admin')) {
        http_response_code(403);
        exit;
    }
    $pdo->exec("DELETE FROM rate_limits WHERE locked_until IS NOT NULL AND locked_until < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $pdo->exec("DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    http_response_code(204);
    exit;
}

// ─── Handle Logout (POST preferred, GET for backward compat) ───
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    redirect(BASE_URL . '/index.php');
}

// ─── Handle Login POST ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission. Please try again.');
        redirect(BASE_URL . '/index.php');
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        set_flash('danger', 'Please enter both username and password.');
        redirect(BASE_URL . '/index.php');
    }

    // Rate limiting: DB-based (cannot be bypassed by clearing cookies)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Check rate limit
    $rl_stmt = $pdo->prepare("SELECT * FROM rate_limits WHERE ip_address = ? AND action = 'login'");
    $rl_stmt->execute([$ip]);
    $rate_limit = $rl_stmt->fetch();

    if ($rate_limit && $rate_limit['locked_until'] && strtotime($rate_limit['locked_until']) > time()) {
        $remaining = ceil((strtotime($rate_limit['locked_until']) - time()) / 60);
        set_flash('danger', 'Too many failed attempts. Try again in ' . $remaining . ' minutes.');
        redirect(BASE_URL . '/index.php');
    }

    // Find user
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        // Increment failed attempts in DB
        if ($rate_limit) {
            $new_attempts = $rate_limit['attempts'] + 1;
            if ($new_attempts >= 5) {
                $pdo->prepare("UPDATE rate_limits SET attempts = 0, locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE ip_address = ? AND action = 'login'")
                    ->execute([$ip]);
            } else {
                $pdo->prepare("UPDATE rate_limits SET attempts = ? WHERE ip_address = ? AND action = 'login'")
                    ->execute([$new_attempts, $ip]);
            }
        } else {
            $pdo->prepare("INSERT INTO rate_limits (ip_address, action, attempts) VALUES (?, 'login', 1)
                ON DUPLICATE KEY SET attempts = attempts + 1")
                ->execute([$ip]);
        }

        // Log failed attempt
        $log_stmt = $pdo->prepare("INSERT INTO logs (log_type, status, message, ip_address) VALUES (?, ?, ?, ?)");
        $log_stmt->execute(['login', 'failed', 'Failed login attempt for username: ' . sanitize($username), $ip]);

        set_flash('danger', 'Invalid username or password.');
        redirect(BASE_URL . '/index.php');
    }

    // Check if account is active
    if (!$user['is_active']) {
        set_flash('danger', 'Your account has been suspended. Contact an administrator.');
        redirect(BASE_URL . '/index.php');
    }

    // Successful login — clear rate limit
    $pdo->prepare("DELETE FROM rate_limits WHERE ip_address = ? AND action = 'login'")->execute([$ip]);

    // Regenerate session ID to prevent fixation
    session_regenerate_id(true);

    // Set session data
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['email'] = $user['email'] ?? '';
    $_SESSION['login_time'] = time();

    // Update last_login
    $update = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $update->execute([$user['id']]);

    // Log successful login
    $log_stmt = $pdo->prepare("INSERT INTO logs (log_type, status, message, ip_address) VALUES (?, ?, ?, ?)");
    $log_stmt->execute(['login', 'success', 'User logged in: ' . $username, $ip]);

    // Check if first login (force password change)
    if (is_null($user['last_login'])) {
        $_SESSION['force_password_change'] = true;
        redirect(BASE_URL . '/settings/users.php?action=change_password');
    }

    regenerate_csrf_token();
    redirect(BASE_URL . '/dashboard.php');
}

// If already logged in, go to dashboard
if (is_logged_in()) {
    redirect(BASE_URL . '/dashboard.php');
}

redirect(BASE_URL . '/index.php');
