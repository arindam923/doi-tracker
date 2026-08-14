<?php
require_once __DIR__ . '/config.php';

if (is_logged_in()) {
    redirect(BASE_URL . '/dashboard.php');
}

$flash = get_flash();
$has_error = $flash && ($flash['type'] === 'danger' || $flash['type'] === 'warning');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Sign in to <?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?> — DOI registration and tracking platform.">
    <meta name="theme-color" content="#0f766e">
    <title>Sign In — <?php echo SITE_NAME; ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500;600&family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/css/app.css?v=<?php echo filemtime(__DIR__ . '/assets/css/app.css'); ?>" rel="stylesheet">
</head>
<body>

    <a class="tf-skip-link" href="#login-form">Skip to sign in form</a>

    <main class="tf-login-page">
        <div class="tf-login-card" role="region" aria-labelledby="login-heading">
            <div class="tf-login-header">
                <div class="tf-login-logo" aria-hidden="true">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
                <h1 id="login-heading" class="tf-login-title"><?php echo SITE_NAME; ?></h1>
                <p class="tf-login-tagline">DOI Tracking Platform</p>
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
                    <div class="alert <?php echo $alertClass; ?> mb-4" <?php echo $live; ?> id="login-alert">
                        <div class="alert-body"><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <button type="button" class="alert-close" aria-label="Dismiss" onclick="document.getElementById('login-alert').remove()">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <h2 class="tf-login-form-title">Welcome back</h2>

                <form method="POST" action="<?php echo BASE_URL; ?>/auth.php" id="login-form" novalidate>
                    <?php echo csrf_field(); ?>

                    <div class="mb-3">
                        <label for="username" class="tf-label">
                            Username
                            <span class="tf-required" aria-hidden="true">*</span>
                        </label>
                        <div class="tf-input-group">
                            <span class="tf-input-group-icon" aria-hidden="true"><i class="bi bi-person"></i></span>
                            <input type="text" id="username" name="username" placeholder="Enter your username" required
                                   class="form-control <?php echo $has_error ? 'is-invalid' : ''; ?>"
                                   aria-describedby="username-help<?php echo $has_error ? ' login-alert' : ''; ?>"
                                   <?php if ($has_error) echo 'aria-invalid="true"'; ?>
                                   autocomplete="username"
                                   autofocus>
                        </div>
                        <div id="username-help" class="tf-help">Your platform username</div>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="tf-label">
                            Password
                            <span class="tf-required" aria-hidden="true">*</span>
                        </label>
                        <div class="tf-input-group">
                            <span class="tf-input-group-icon" aria-hidden="true"><i class="bi bi-lock"></i></span>
                            <input type="password" id="password" name="password" placeholder="Enter your password" required
                                   class="form-control <?php echo $has_error ? 'is-invalid' : ''; ?>"
                                   aria-describedby="password-help<?php echo $has_error ? ' login-alert' : ''; ?>"
                                   <?php if ($has_error) echo 'aria-invalid="true"'; ?>
                                   autocomplete="current-password">
                            <button type="button" class="btn btn-icon position-absolute end-0 me-2" id="toggle-password" aria-label="Show password" aria-pressed="false" style="top: .25rem; color: var(--tf-muted);">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div id="password-help" class="tf-help">Your account password</div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block btn-lg">
                        <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>
                        Sign In
                    </button>
                </form>
            </div>

            <div class="tf-login-footer">
                <span>v1.0</span>
                <span aria-hidden="true">&bull;</span>
                <span><?php echo date('Y'); ?></span>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInput = document.getElementById('password');
            const toggleBtn = document.getElementById('toggle-password');
            if (toggleBtn && passwordInput) {
                toggleBtn.addEventListener('click', function() {
                    const isVisible = passwordInput.type === 'text';
                    passwordInput.type = isVisible ? 'password' : 'text';
                    toggleBtn.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
                    toggleBtn.setAttribute('aria-pressed', String(!isVisible));
                    toggleBtn.querySelector('i').className = isVisible ? 'bi bi-eye' : 'bi bi-eye-slash';
                });
            }

            // Move focus to alert if present, else username
            const alert = document.getElementById('login-alert');
            if (alert) {
                alert.focus();
            } else {
                const username = document.getElementById('username');
                if (username) username.focus();
            }
        });
    </script>
</body>
</html>
