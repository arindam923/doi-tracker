<?php
/**
 * Shared vendor-portal chrome (head + top bar).
 * Expects $page_title and $vendor (session vendor array).
 */
$__portal_name = $vendor['vendor_name'] ?? 'Vendor';
$__portal_code = $vendor['vendor_code'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Vendor portal for <?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="theme-color" content="#0f766e">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?> — <?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500;600&family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/css/app.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/app.css'); ?>" rel="stylesheet">
    <?php if (isset($extra_head)) echo $extra_head; ?>
</head>
<body>
    <a class="tf-skip-link" href="#main-content">Skip to main content</a>

    <header class="tf-portal-header">
        <div class="tf-portal-header-inner">
            <a href="<?php echo BASE_URL; ?>/vendor_portal/index.php" class="tf-portal-brand">
                <span class="tf-login-logo" aria-hidden="true"><i class="bi bi-graph-up-arrow"></i></span>
                <span><?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?> <span class="tf-hero-pill">Vendor</span></span>
            </a>
            <div class="tf-portal-actions">
                <span class="tf-hero-pill" aria-label="Vendor">
                    <i class="bi bi-person-badge" aria-hidden="true"></i>
                    <?php echo htmlspecialchars($__portal_code, ENT_QUOTES, 'UTF-8'); ?> — <?php echo htmlspecialchars($__portal_name, ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <?php if (!empty($portal_show_dashboard_link)): ?>
                <a href="<?php echo BASE_URL; ?>/vendor_portal/index.php" class="btn btn-sm btn-outline-light">Dashboard</a>
                <?php else: ?>
                <a href="<?php echo BASE_URL; ?>/vendor_portal/profile.php" class="btn btn-sm btn-outline-light">Profile</a>
                <?php endif; ?>
                <a href="<?php echo BASE_URL; ?>/vendor_portal/logout.php" class="btn btn-sm btn-outline-light">Logout</a>
            </div>
        </div>
    </header>

    <main id="main-content" class="tf-main tf-portal-main" tabindex="-1">
        <div class="tf-page<?php echo !empty($portal_narrow) ? ' tf-page-narrow' : ''; ?>">
            <?php
            $flash = get_flash();
            if ($flash):
                $alertClasses = ['success' => 'alert-success', 'danger' => 'alert-danger', 'warning' => 'alert-warning', 'info' => 'alert-info'];
                $alertClass = $alertClasses[$flash['type']] ?? 'alert-info';
                $live = $flash['type'] === 'danger' ? 'role="alert" aria-live="assertive"' : 'role="status" aria-live="polite"';
            ?>
            <div class="alert <?php echo $alertClass; ?> mb-4" <?php echo $live; ?> id="flash-alert">
                <div class="alert-body"><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?></div>
                <button type="button" class="alert-close" aria-label="Dismiss" onclick="document.getElementById('flash-alert').remove()">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </div>
            <?php endif; ?>
