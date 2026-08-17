<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$page_title = 'Email Templates';

if (function_exists('email_ensure_starter_templates')) {
    $seeded = email_ensure_starter_templates($pdo, $_SESSION['user_id'] ?? null);
    if ($seeded > 0) {
        set_flash('success', $seeded . ' starter template' . ($seeded === 1 ? '' : 's') . ' added.');
        redirect(BASE_URL . '/email/templates.php');
    }
}

$templates = [];
try {
    $stmt = $pdo->query("SELECT id, name, subject, is_default, updated_at FROM email_templates ORDER BY is_default DESC, name ASC");
    $templates = $stmt ? $stmt->fetchAll() : [];
} catch (Throwable $e) {
    error_log('templates list: ' . $e->getMessage());
}

require_once __DIR__ . '/../helpers/layout_header.php';
?>

<div class="tf-page">
    <div class="tf-page-header">
        <div>
            <h1>Email templates</h1>
            <p>Reusable HTML for DOI, offers, and client updates. Merge fields: <code>{{name}}</code>, <code>{{first_name}}</code>, <code>{{email}}</code>, <code>{{country}}</code>, <code>{{unsubscribe}}</code>.</p>
        </div>
        <a href="<?php echo BASE_URL; ?>/email/template_create.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New template</a>
    </div>

    <?php if (empty($templates)): ?>
        <div class="tf-card">
            <div class="tf-card-body text-center py-5">
                <p class="text-muted mb-3">No templates yet. Create one, or make sure the <code>email_templates</code> table exists.</p>
                <a href="<?php echo BASE_URL; ?>/email/template_create.php" class="btn btn-primary">Create one</a>
            </div>
        </div>
    <?php else: ?>
        <div class="tf-tpl-grid">
            <?php foreach ($templates as $t): ?>
            <article class="tf-tpl-card">
                <div class="tf-tpl-frame tf-tpl-frame-mock" aria-hidden="true">
                    <div class="tf-tpl-mock-bar">Track Flow</div>
                    <div class="tf-tpl-mock-body">
                        <span class="tf-tpl-mock-kicker"><?php echo sanitize((string)$t['name']); ?></span>
                        <strong><?php echo sanitize((string)$t['subject']); ?></strong>
                        <span class="tf-tpl-mock-line"></span>
                        <span class="tf-tpl-mock-line is-short"></span>
                    </div>
                </div>
                <div class="tf-tpl-card-body">
                    <div class="d-flex align-items-start justify-content-between gap-2">
                        <h3 class="tf-tpl-card-title"><?php echo sanitize((string)$t['name']); ?></h3>
                        <?php if (!empty($t['is_default'])): ?><span class="badge bg-success">Default</span><?php endif; ?>
                    </div>
                    <p class="tf-tpl-card-sub"><?php echo sanitize((string)$t['subject']); ?></p>
                    <?php if (!empty($t['updated_at'])): ?>
                    <p class="tf-tpl-card-sub">Updated <?php echo sanitize((string)$t['updated_at']); ?></p>
                    <?php endif; ?>
                    <div class="tf-tpl-card-actions">
                        <a href="<?php echo BASE_URL; ?>/email/template_edit.php?id=<?php echo (int)$t['id']; ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i> Edit</a>
                        <?php if (empty($t['is_default'])): ?>
                        <form method="POST" action="<?php echo BASE_URL; ?>/email/template_delete.php" class="d-inline" data-confirm="Delete this template?">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm" aria-label="Delete"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
