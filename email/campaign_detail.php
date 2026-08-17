<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$id = intval($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/projects/list.php');

$stmt = $pdo->prepare("SELECT ec.*, p.project_code, p.project_name, gv.vendor_name FROM email_campaigns ec JOIN projects p ON ec.project_id = p.id JOIN global_vendors gv ON ec.vendor_id = gv.id WHERE ec.id = ?");
$stmt->execute([$id]);
$campaign = $stmt->fetch();
if (!$campaign) {
    set_flash('danger', 'Campaign not found.');
    redirect(BASE_URL . '/projects/list.php');
}

$send_stats = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
        SUM(CASE WHEN status = 'opened' THEN 1 ELSE 0 END) AS opened,
        SUM(CASE WHEN status = 'clicked' THEN 1 ELSE 0 END) AS clicked,
        SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) AS converted,
        SUM(CASE WHEN status = 'bounced' THEN 1 ELSE 0 END) AS bounced,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
        SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) AS skipped
    FROM email_campaign_sends WHERE campaign_id = ?
");
$send_stats->execute([$id]);
$stats = $send_stats->fetch();

$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$search = trim($_GET['search'] ?? '');

$where = ['campaign_id = ?'];
$params = [$id];
if ($search) {
    $where[] = '(recipient_email LIKE ? OR recipient_name LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$where_sql = 'WHERE ' . implode(' AND ', $where);

$count_stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM email_campaign_sends $where_sql");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetch()['cnt'];
$pagination = paginate($total, $per_page, $page);

$stmt = $pdo->prepare("SELECT * FROM email_campaign_sends $where_sql ORDER BY created_at DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}");
$stmt->execute($params);
$sends = $stmt->fetchAll();

$page_title = 'Campaign Detail';
require_once __DIR__ . '/../helpers/layout_header.php';
?>


<div class="campaign-hero">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <div class="small text-white-50">Project <a class="text-white" href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo (int)$campaign['project_id']; ?>#email-campaigns"><code class="text-white"><?php echo sanitize($campaign['project_code']); ?></code></a></div>
            <h5 class="mb-0"><?php echo sanitize($campaign['name']); ?></h5>
            <div class="small text-white-50">Vendor: <?php echo sanitize($campaign['vendor_name']); ?> · Subject: <?php echo sanitize($campaign['subject']); ?></div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if (in_array($campaign['status'], ['draft', 'paused', 'scheduled'], true)): ?>
            <form method="POST" action="<?php echo BASE_URL; ?>/email/campaign_status.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                <input type="hidden" name="action" value="<?php echo $campaign['status'] === 'paused' ? 'resume' : 'launch'; ?>">
                <button type="submit" class="btn btn-light btn-sm"><i class="bi bi-play-fill"></i><?php echo $campaign['status'] === 'paused' ? 'Resume' : 'Launch'; ?></button>
            </form>
            <?php endif; ?>
            <?php if ($campaign['status'] === 'running'): ?>
            <form method="POST" action="<?php echo BASE_URL; ?>/email/campaign_status.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                <input type="hidden" name="action" value="pause">
                <button type="submit" class="btn btn-warning btn-sm"><i class="bi bi-pause-fill"></i>Pause</button>
            </form>
            <?php endif; ?>
            <a href="<?php echo BASE_URL; ?>/email/campaign_edit.php?id=<?php echo (int)$id; ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-pencil"></i>Edit</a>
            <form method="POST" action="<?php echo BASE_URL; ?>/email/campaign_delete.php" data-confirm="Delete this campaign? This cannot be undone.">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i>Delete</button>
            </form>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Total</div><div class="kpi-value"><?php echo number_format((int)($stats['total'] ?? 0)); ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Sent</div><div class="kpi-value"><?php echo number_format((int)($stats['sent'] ?? 0)); ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Opened</div><div class="kpi-value"><?php echo number_format((int)($stats['opened'] ?? 0)); ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Clicked</div><div class="kpi-value"><?php echo number_format((int)($stats['clicked'] ?? 0)); ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Converted</div><div class="kpi-value"><?php echo number_format((int)($stats['converted'] ?? 0)); ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Bounced</div><div class="kpi-value"><?php echo number_format((int)($stats['bounced'] ?? 0)); ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Failed</div><div class="kpi-value"><?php echo number_format((int)($stats['failed'] ?? 0)); ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Skipped</div><div class="kpi-value"><?php echo number_format((int)($stats['skipped'] ?? 0)); ?></div></div></div>
</div>

<div class="tf-card mb-4">
    <div class="tf-card-header"><h5 class="mb-0 fw-semibold">Configuration</h5></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-6 col-md-3"><strong>Status</strong><div><?php echo status_badge($campaign['status']); ?></div></div>
            <div class="col-6 col-md-3"><strong>Daily Limit</strong><div><?php echo number_format((int)$campaign['daily_limit']); ?></div></div>
            <div class="col-6 col-md-3"><strong>Total Limit</strong><div><?php echo (int)$campaign['total_limit'] > 0 ? number_format((int)$campaign['total_limit']) : 'Unlimited'; ?></div></div>
            <div class="col-6 col-md-3"><strong>Multi-step</strong><div><?php echo $campaign['is_multi_step'] ? 'Yes' : 'No'; ?></div></div>
        </div>
    </div>
</div>

<div class="tf-card">
    <div class="tf-card-header">
        <h5 class="mb-0 fw-semibold">Recipients</h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Recipient</th>
                    <th>Country</th>
                    <th>Status</th>
                    <th>Sent At</th>
                    <th>Opened</th>
                    <th>Clicked</th>
                    <th>Converted</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sends)): ?>
                    <tr><td colspan="7" class="text-center py-5 text-muted">No send records yet.</td></tr>
                <?php else: foreach ($sends as $s): ?>
                    <tr>
                        <td><?php echo sanitize($s['recipient_name'] ? $s['recipient_name'] . ' <' . $s['recipient_email'] . '>' : $s['recipient_email']); ?></td>
                        <td><?php echo sanitize($s['country'] ?? '-'); ?></td>
                        <td><?php echo status_badge($s['status']); ?></td>
                        <td class="text-muted small"><?php echo sanitize($s['sent_at'] ?? '-'); ?></td>
                        <td><?php echo $s['opened_at'] ? '<i class="bi bi-check2 text-success"></i>' : '-'; ?></td>
                        <td><?php echo $s['clicked_at'] ? '<i class="bi bi-check2 text-success"></i>' : '-'; ?></td>
                        <td><?php echo $s['converted_at'] ? '<i class="bi bi-check2 text-success"></i>' : '-'; ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="tf-card-footer"><?php echo render_pagination($pagination, BASE_URL . '/email/campaign_detail.php?id=' . $id . '&' . http_build_query($_GET)); ?></div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
