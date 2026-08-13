<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$page_title = 'Email Templates';
require_once __DIR__ . '/../helpers/layout_header.php';

$stmt = $pdo->query("SELECT * FROM email_templates ORDER BY is_default DESC, name ASC");
$templates = $stmt->fetchAll();
?>

<div class="tf-page">
    <div class="tf-card">
        <div class="tf-card-header">
            <div>
                <h5 class="tf-card-title">Email Templates</h5>
                <p class="tf-card-subtitle">Reusable templates for email campaigns.</p>
            </div>
            <a href="<?php echo BASE_URL; ?>/email/template_create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>New Template</a>
        </div>
        <div class="tf-card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Subject</th>
                            <th>Default</th>
                            <th>Updated</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($templates)): ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted">No templates yet.</td></tr>
                        <?php else: foreach ($templates as $t): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo sanitize($t['name']); ?></td>
                                <td><?php echo sanitize($t['subject']); ?></td>
                                <td><?php echo $t['is_default'] ? '<span class="badge bg-success">Default</span>' : '<span class="text-muted">—</span>'; ?></td>
                                <td class="text-muted small"><?php echo sanitize($t['updated_at']); ?></td>
                                <td class="text-end">
                                    <a href="<?php echo BASE_URL; ?>/email/template_edit.php?id=<?php echo (int)$t['id']; ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i>Edit</a>
                                    <?php if (!$t['is_default']): ?>
                                    <form method="POST" action="<?php echo BASE_URL; ?>/email/template_delete.php" class="d-inline" data-confirm="Delete this template?">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
