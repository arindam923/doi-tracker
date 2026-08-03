<?php
require_once __DIR__ . '/config.php';

if (is_logged_in()) {
    redirect(BASE_URL . '/dashboard.php');
}

$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?php echo SITE_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --bs-primary: #4f46e5;
            --bs-primary-rgb: 79, 70, 229;
            --bs-body-font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            -webkit-font-smoothing: antialiased;
            position: relative;
            overflow: hidden;
            color: #0f172a;
        }
        .login-blob {
            position: absolute;
            border-radius: 9999px;
            filter: blur(64px);
            pointer-events: none;
        }
        .login-blob.is-indigo { background: rgba(99, 102, 241, .15); }
        .login-blob.is-emerald { background: rgba(16, 185, 129, .15); }
        .btn-primary {
            --bs-btn-bg: #4f46e5;
            --bs-btn-border-color: #4f46e5;
            --bs-btn-hover-bg: #4338ca;
            --bs-btn-hover-border-color: #4338ca;
            --bs-btn-active-bg: #4338ca;
            --bs-btn-active-border-color: #4338ca;
        }
        .login-card { box-shadow: 0 10px 30px -5px rgba(15, 23, 42, .1); border: 0; }
    </style>
</head>
<body>

    <div aria-hidden="true">
        <div class="login-blob is-indigo" style="top: -10rem; right: -10rem; width: 24rem; height: 24rem;"></div>
        <div class="login-blob is-emerald" style="bottom: -10rem; left: -10rem; width: 24rem; height: 24rem;"></div>
    </div>

    <div style="width: 100%; max-width: 28rem; padding: 0 1.5rem; position: relative; z-index: 1;">
        <div class="text-center mb-4">
            <div class="d-inline-flex align-items-center justify-content-center mb-3" style="width: 4rem; height: 4rem; border-radius: 1rem; background: #4f46e5; color: #fff; box-shadow: 0 10px 25px -5px rgba(79, 70, 229, .3);">
                <i class="bi bi-lightning-charge-fill" style="font-size: 1.875rem;"></i>
            </div>
            <h1 class="h2 fw-bold mb-1" style="letter-spacing: -0.025em;"><?php echo SITE_NAME; ?></h1>
            <p class="text-muted small fw-semibold mb-0 text-uppercase" style="letter-spacing: .05em; font-size: .75rem;">DOI Registration &amp; Tracking Platform</p>
        </div>

        <?php if ($flash):
            $alertClass = match($flash['type']) {
                'success' => 'alert-success',
                'danger'  => 'alert-danger',
                'warning' => 'alert-warning',
                'info'    => 'alert-info',
                default   => 'alert-info',
            };
        ?>
        <div class="alert <?php echo $alertClass; ?>" role="alert">
            <div><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php endif; ?>

        <div class="card login-card">
            <div class="card-body p-4">
                <h2 class="h5 fw-semibold mb-3">Sign In</h2>

                <form method="POST" action="<?php echo BASE_URL; ?>/auth.php">
                    <?php echo csrf_field(); ?>

                    <div class="mb-3">
                        <label for="username" class="form-label small fw-semibold text-secondary">Username</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus
                                   class="form-control">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label small fw-semibold text-secondary">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" id="password" name="password" placeholder="Enter your password" required
                                   class="form-control">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-box-arrow-in-right"></i>Sign In
                    </button>
                </form>
            </div>
        </div>

        <p class="text-center text-muted mt-4 mb-0 small fw-semibold">v1.0 &mdash; <?php echo date('Y'); ?></p>
    </div>
</body>
</html>
