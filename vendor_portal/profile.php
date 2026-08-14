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
$portal_show_dashboard_link = true;
$portal_narrow = true;
require_once __DIR__ . '/../helpers/portal_header.php';
?>

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
