<?php
require_once __DIR__ . '/../config.php';

// ── Enable/disable gate ─────────────────────────────────────────
if (!vendor_portal_enabled($pdo)) {
    http_response_code(403);
    die('The vendor portal is currently disabled. Please contact your network administrator.');
}

$action = $_GET['action'] ?? '';
$token  = trim($_GET['token'] ?? '');

// ── Magic-link token validation ─────────────────────────────────
if ($token !== '') {
    $stmt = $pdo->prepare("SELECT vpu.*, gv.vendor_code, gv.vendor_name, gv.vendor_status FROM vendor_portal_users vpu JOIN global_vendors gv ON gv.id = vpu.global_vendor_id WHERE vpu.magic_token = ? AND vpu.magic_expires_at > NOW() AND vpu.is_active = 1");
    $stmt->execute([$token]);
    $vendor = $stmt->fetch();
    if ($vendor && tf_vendor_status_allowed($vendor['vendor_status'])) {
        session_regenerate_id(true);
        $_SESSION['TF_VENDOR'] = [
            'global_vendor_id' => (int)$vendor['global_vendor_id'],
            'email' => $vendor['email'],
            'vendor_code' => $vendor['vendor_code'],
            'vendor_name' => $vendor['vendor_name'],
            'login_time' => time(),
        ];
        $pdo->prepare("UPDATE vendor_portal_users SET magic_token = NULL, magic_expires_at = NULL, last_login_at = NOW() WHERE id = ?")->execute([$vendor['id']]);
        audit_log($pdo, 'login', 'vendor', $vendor['global_vendor_id'], null, ['via' => 'magic_link']);
        redirect(BASE_URL . '/vendor_portal/index.php');
    } else {
        set_flash('danger', 'That magic link is invalid or expired.');
    }
}

// ── Magic-link request ──────────────────────────────────────────
if (isset($_POST['request_magic'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/vendor_portal/auth.php');
    }
    $email = strtolower(trim($_POST['email'] ?? ''));
    $resend_api_key = get_setting($pdo, 'resend_api_key');

    set_flash('info', 'If that email belongs to a vendor account, a sign-in link has been sent.');

    if (filter_var($email, FILTER_VALIDATE_EMAIL) && $resend_api_key) {
        $vpu = $pdo->prepare("SELECT * FROM vendor_portal_users WHERE email = ? AND is_active = 1");
        $vpu->execute([$email]);
        $user = $vpu->fetch();
        if ($user) {
            $magic = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE vendor_portal_users SET magic_token = ?, magic_expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?")
                ->execute([$magic, $user['id']]);

            $link = BASE_URL . '/vendor_portal/auth.php?token=' . $magic;
            $from_address = get_setting($pdo, 'email_from_address', 'noreply@yourdomain.com');
            $from_name    = get_setting($pdo, 'email_from_name', 'Track Flow');

            $ch = curl_init('https://api.resend.com/emails');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $resend_api_key, 'Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'from' => $from_name . ' <' . $from_address . '>',
                'to' => [$email],
                'subject' => 'Your vendor portal sign-in link',
                'text' => "Hello,\n\nClick this link to sign in to the vendor portal:\n{$link}\n\nThe link expires in 15 minutes.\n",
            ]));
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_exec($ch);
            curl_close($ch);
        }
    }
    redirect(BASE_URL . '/vendor_portal/auth.php');
}

// ── Password login ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/vendor_portal/auth.php');
    }

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rl = $pdo->prepare("SELECT * FROM rate_limits WHERE ip_address = ? AND action = 'vendor_login'");
    $rl->execute([$ip]);
    $rl_row = $rl->fetch();
    if ($rl_row && $rl_row['locked_until'] && strtotime($rl_row['locked_until']) > time()) {
        set_flash('danger', 'Too many attempts. Try again later.');
        redirect(BASE_URL . '/vendor_portal/auth.php');
    }

    $stmt = $pdo->prepare("SELECT vpu.*, gv.vendor_code, gv.vendor_name, gv.vendor_status FROM vendor_portal_users vpu JOIN global_vendors gv ON gv.id = vpu.global_vendor_id WHERE vpu.email = ? AND vpu.is_active = 1");
    $stmt->execute([$email]);
    $vendor = $stmt->fetch();

    $valid = $vendor && !empty($vendor['password_hash']) && password_verify($password, $vendor['password_hash']);

    if (!$valid) {
        if ($rl_row) {
            $attempts = $rl_row['attempts'] + 1;
            if ($attempts >= 5) {
                $pdo->prepare("UPDATE rate_limits SET attempts = 0, locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE ip_address = ? AND action = 'vendor_login'")->execute([$ip]);
            } else {
                $pdo->prepare("UPDATE rate_limits SET attempts = ? WHERE ip_address = ? AND action = 'vendor_login'")->execute([$attempts, $ip]);
            }
        } else {
            $pdo->prepare("INSERT INTO rate_limits (ip_address, action, attempts) VALUES (?, 'vendor_login', 1) ON DUPLICATE KEY UPDATE attempts = attempts + 1")->execute([$ip]);
        }
        set_flash('danger', 'Invalid email or password.');
        redirect(BASE_URL . '/vendor_portal/auth.php');
    }

    if ($vendor['vendor_status'] === 'suspended' || $vendor['vendor_status'] === 'blacklisted') {
        set_flash('danger', 'This vendor account is not permitted to sign in.');
        redirect(BASE_URL . '/vendor_portal/auth.php');
    }

    $pdo->prepare("DELETE FROM rate_limits WHERE ip_address = ? AND action = 'vendor_login'")->execute([$ip]);
    session_regenerate_id(true);
    $_SESSION['TF_VENDOR'] = [
        'global_vendor_id' => (int)$vendor['global_vendor_id'],
        'email' => $vendor['email'],
        'vendor_code' => $vendor['vendor_code'],
        'vendor_name' => $vendor['vendor_name'],
        'login_time' => time(),
    ];
    $pdo->prepare("UPDATE vendor_portal_users SET last_login_at = NOW() WHERE id = ?")->execute([$vendor['id']]);
    audit_log($pdo, 'login', 'vendor', $vendor['global_vendor_id'], null, ['via' => 'password']);
    redirect(BASE_URL . '/vendor_portal/index.php');
}

// Already logged in
if (vendor_logged_in()) {
    redirect(BASE_URL . '/vendor_portal/index.php');
}

$flash = get_flash();
$has_error = $flash && ($flash['type'] === 'danger' || $flash['type'] === 'warning');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Vendor portal sign in for <?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="theme-color" content="#0f766e">
    <title>Vendor Portal — Sign In — <?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500;600&family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/css/app.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/app.css'); ?>" rel="stylesheet">
</head>
<body>

    <a class="tf-skip-link" href="#vendor-login-form">Skip to sign in form</a>

    <main class="tf-login-page">
        <div class="tf-login-card" role="region" aria-labelledby="vendor-login-heading">
            <div class="tf-login-header">
                <div class="tf-login-logo" aria-hidden="true">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
                <h1 id="vendor-login-heading" class="tf-login-title"><?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?></h1>
                <p class="tf-login-tagline">Vendor Portal</p>
            </div>

            <div class="tf-login-body">
                <?php if ($flash): ?>
                    <?php
                    $alertClass = match($flash['type']) {
                        'success' => 'alert-success',
                        'danger'  => 'alert-danger',
                        'warning' => 'alert-warning',
                        'info'    => 'alert-info',
                        default   => 'alert-info',
                    };
                    $live = $flash['type'] === 'danger' ? 'role="alert" aria-live="assertive"' : 'role="status" aria-live="polite"';
                    ?>
                    <div class="alert <?php echo $alertClass; ?> mb-4" <?php echo $live; ?> id="vendor-alert">
                        <div class="alert-body"><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <button type="button" class="alert-close" aria-label="Dismiss" onclick="document.getElementById('vendor-alert').remove()">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <h2 class="tf-login-form-title">Vendor sign in</h2>

                <form method="POST" id="vendor-login-form" novalidate>
                    <?php echo csrf_field(); ?>

                    <div class="mb-3">
                        <label for="vendor-email" class="tf-label">
                            Email Address
                            <span class="tf-required" aria-hidden="true">*</span>
                        </label>
                        <div class="tf-input-group">
                            <span class="tf-input-group-icon" aria-hidden="true"><i class="bi bi-envelope"></i></span>
                            <input type="email" id="vendor-email" name="email" placeholder="you@vendor.com" required
                                   class="form-control <?php echo $has_error ? 'is-invalid' : ''; ?>"
                                   autocomplete="email"
                                   autofocus>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="vendor-password" class="tf-label">
                            Password
                            <span class="tf-required" aria-hidden="true">*</span>
                        </label>
                        <div class="tf-input-group">
                            <span class="tf-input-group-icon" aria-hidden="true"><i class="bi bi-lock"></i></span>
                            <input type="password" id="vendor-password" name="password" placeholder="••••••••"
                                   class="form-control <?php echo $has_error ? 'is-invalid' : ''; ?>"
                                   autocomplete="current-password"
                                   aria-describedby="vendor-password-help">
                            <button type="button" class="btn btn-icon position-absolute end-0 me-2" id="toggle-vendor-password" aria-label="Show password" aria-pressed="false" style="top: .25rem; color: var(--tf-muted);">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div id="vendor-password-help" class="tf-help">Leave blank to use the magic link option below.</div>
                    </div>

                    <button type="submit" name="login" class="btn btn-primary btn-block">
                        <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>
                        Sign In
                    </button>
                </form>

                <div class="tf-divider my-4"></div>

                <h3 class="h6 fw-bold mb-3">Or email a magic link</h3>
                <form method="POST" novalidate>
                    <?php echo csrf_field(); ?>
                    <div class="tf-input-group mb-2">
                        <span class="tf-input-group-icon" aria-hidden="true"><i class="bi bi-envelope"></i></span>
                        <input type="email" name="email" class="form-control" placeholder="Email for magic link" required autocomplete="email">
                    </div>
                    <button type="submit" name="request_magic" class="btn btn-outline-secondary btn-block">
                        <i class="bi bi-send" aria-hidden="true"></i>
                        Send Link
                    </button>
                    <div class="tf-help mt-2">We'll email you a secure sign-in link (valid 15 minutes).</div>
                </form>
            </div>

            <div class="tf-login-footer">
                <span>Vendor Portal</span>
                <span aria-hidden="true">&bull;</span>
                <span><?php echo date('Y'); ?></span>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInput = document.getElementById('vendor-password');
            const toggleBtn = document.getElementById('toggle-vendor-password');
            if (toggleBtn && passwordInput) {
                toggleBtn.addEventListener('click', function() {
                    const isVisible = passwordInput.type === 'text';
                    passwordInput.type = isVisible ? 'password' : 'text';
                    toggleBtn.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
                    toggleBtn.setAttribute('aria-pressed', String(!isVisible));
                    toggleBtn.querySelector('i').className = isVisible ? 'bi bi-eye' : 'bi bi-eye-slash';
                });
            }

            const alert = document.getElementById('vendor-alert');
            if (alert) {
                alert.focus();
            } else {
                const email = document.getElementById('vendor-email');
                if (email) email.focus();
            }
        });
    </script>
</body>
</html>
