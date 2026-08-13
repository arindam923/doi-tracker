<?php
require_once __DIR__ . '/../config.php';
$vendor = require_vendor_login($pdo);

$global_vendor_id = (int)$vendor['global_vendor_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/vendor_portal/profile.php');
    }

    $contact_person = trim($_POST['contact_person'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $telegram = trim($_POST['telegram'] ?? '');
    $skype = trim($_POST['skype'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        set_flash('danger', 'A valid email address is required.');
        redirect(BASE_URL . '/vendor_portal/profile.php');
    }

    try {
        $pdo->beginTransaction();

        $before = $pdo->prepare("SELECT contact_person, email, telegram, skype, phone FROM global_vendors WHERE id = ? FOR UPDATE");
        $before->execute([$global_vendor_id]);
        $prev = $before->fetch();

        $pdo->prepare("UPDATE global_vendors SET contact_person = ?, email = ?, telegram = ?, skype = ?, phone = ? WHERE id = ?")
            ->execute([$contact_person, $email, $telegram, $skype, $phone, $global_vendor_id]);

        $pdo->prepare("UPDATE vendor_portal_users SET email = ? WHERE global_vendor_id = ?")
            ->execute([$email, $global_vendor_id]);

        audit_log($pdo, 'update', 'vendor', $global_vendor_id, $prev, [
            'contact_person' => $contact_person,
            'email' => $email,
            'telegram' => $telegram,
            'skype' => $skype,
        ]);

        $pdo->commit();
        $_SESSION['TF_VENDOR']['email'] = $email;
        $_SESSION['TF_VENDOR']['vendor_name'] = $contact_person ?: $vendor['vendor_name'];
        regenerate_csrf_token();
        set_flash('success', 'Profile updated.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash('danger', 'Update failed: ' . $e->getMessage());
    }
    redirect(BASE_URL . '/vendor_portal/profile.php');
}

$stmt = $pdo->prepare("SELECT * FROM global_vendors WHERE id = ?");
$stmt->execute([$global_vendor_id]);
$gv = $stmt->fetch();

$page_title = 'Vendor Profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Vendor profile for <?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="theme-color" content="#4f46e5">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?> — <?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/css/app.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/app.css'); ?>" rel="stylesheet">
</head>
<body>

    <a class="tf-skip-link" href="#main-content">Skip to main content</a>

    <header class="tf-hero" style="border-radius: 0; margin-bottom: 0; padding: 1rem 1.5rem;">
        <div class="container-fluid" style="position: relative; z-index: 1;">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <a href="<?php echo BASE_URL; ?>/vendor_portal/index.php" class="d-flex align-items-center gap-2 text-white text-decoration-none">
                     <span class="tf-login-logo" style="width: 2.25rem; height: 2.25rem; font-size: 1.1rem; margin: 0;" aria-hidden="true"><i class="bi bi-graph-up-arrow"></i></span>
                    <span class="fw-bold fs-5"><?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?> <span class="tf-hero-pill ms-1">Vendor</span></span>
                </a>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <a href="<?php echo BASE_URL; ?>/vendor_portal/index.php" class="btn btn-sm btn-outline-light"><i class="bi bi-arrow-left" aria-hidden="true"></i> Dashboard</a>
                    <a href="<?php echo BASE_URL; ?>/vendor_portal/logout.php" class="btn btn-sm btn-outline-light">Logout</a>
                </div>
            </div>
        </div>
    </header>

    <main id="main-content" class="tf-main" style="height: auto; min-height: calc(100vh - 4.5rem);">
        <div class="tf-page" style="max-width: 42rem;">

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

            <section class="tf-card" aria-labelledby="profile-title">
                <div class="tf-card-header is-muted">
                    <div>
                        <h5 id="profile-title" class="tf-card-title">Your Profile</h5>
                        <p class="tf-card-subtitle">Code: <code><?php echo htmlspecialchars($gv['vendor_code'] ?? '', ENT_QUOTES, 'UTF-8'); ?></code> &middot; Status: <?php echo ucfirst($gv['vendor_status'] ?? ''); ?></p>
                    </div>
                </div>
                <div class="tf-card-body">
                    <form method="POST" class="tf-form">
                        <?php echo csrf_field(); ?>
                        <div class="tf-form-row">
                            <div class="tf-field col-12">
                                <label for="contact_person" class="tf-label">Contact Person</label>
                                <input type="text" id="contact_person" name="contact_person" class="form-control" maxlength="150" value="<?php echo htmlspecialchars($gv['contact_person'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="tf-form-row">
                            <div class="tf-field col-12">
                                <label for="email" class="tf-label">
                                    Email
                                    <span class="tf-required" aria-hidden="true">*</span>
                                </label>
                                <input type="email" id="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($gv['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" autocomplete="email">
                                <p class="tf-help">Used for portal sign-in.</p>
                            </div>
                        </div>
                        <div class="tf-form-row">
                            <div class="tf-field col-12 col-md-6">
                                <label for="telegram" class="tf-label">Telegram</label>
                                <input type="text" id="telegram" name="telegram" class="form-control" maxlength="100" value="<?php echo htmlspecialchars($gv['telegram'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="@username">
                            </div>
                            <div class="tf-field col-12 col-md-6">
                                <label for="skype" class="tf-label">Skype</label>
                                <input type="text" id="skype" name="skype" class="form-control" maxlength="100" value="<?php echo htmlspecialchars($gv['skype'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="tf-form-row">
                            <div class="tf-field col-12">
                                <label for="phone" class="tf-label">Phone</label>
                                <input type="text" id="phone" name="phone" class="form-control" maxlength="50" value="<?php echo htmlspecialchars($gv['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" autocomplete="tel">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Save Profile</button>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </main>

</body>
</html>
