<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$project_filter = intval($_GET['project_id'] ?? 0);
$vendor_filter = intval($_GET['vendor_id'] ?? 0);
$status_filter = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

$where = [];
$params = [];

if ($project_filter) {
    $where[] = 'ec.project_id = ?';
    $params[] = $project_filter;
}
if ($vendor_filter) {
    $where[] = 'ec.vendor_id = ?';
    $params[] = $vendor_filter;
}
if ($status_filter && in_array($status_filter, ['draft','scheduled','running','paused','completed','failed'], true)) {
    $where[] = 'ec.status = ?';
    $params[] = $status_filter;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count_stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM email_campaigns ec $where_sql");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetch()['cnt'];
$pagination = paginate($total, $per_page, $page);

$stmt = $pdo->prepare("
    SELECT ec.*, p.project_code, p.project_name, gv.vendor_name,
           (SELECT COUNT(*) FROM email_campaign_sends WHERE campaign_id = ec.id) AS total_sends,
           (SELECT COUNT(*) FROM email_campaign_sends WHERE campaign_id = ec.id AND status = 'sent') AS sent_count,
           (SELECT COUNT(*) FROM email_campaign_sends WHERE campaign_id = ec.id AND status = 'opened') AS opened_count,
           (SELECT COUNT(*) FROM email_campaign_sends WHERE campaign_id = ec.id AND status = 'clicked') AS clicked_count,
           (SELECT COUNT(*) FROM email_campaign_sends WHERE campaign_id = ec.id AND status = 'converted') AS converted_count,
           (SELECT COUNT(*) FROM email_campaign_sends WHERE campaign_id = ec.id AND status = 'bounced') AS bounced_count,
           (SELECT COUNT(*) FROM email_campaign_sends WHERE campaign_id = ec.id AND status = 'failed') AS failed_count
    FROM email_campaigns ec
    JOIN projects p ON ec.project_id = p.id
    JOIN global_vendors gv ON ec.vendor_id = gv.id
    $where_sql
    ORDER BY ec.created_at DESC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
");
$stmt->execute($params);
$campaigns = $stmt->fetchAll();

$projects_list = $pdo->query("SELECT id, project_code, project_name FROM projects ORDER BY project_name")->fetchAll();
$vendors_list = $pdo->query("SELECT gv.id, gv.vendor_name, p.project_code FROM project_vendor pv JOIN global_vendors gv ON gv.id = pv.vendor_id JOIN projects p ON p.id = pv.project_id ORDER BY p.project_code, gv.vendor_name")->fetchAll();

$page_title = 'Email Campaigns';
$page_actions = '<a href="' . BASE_URL . '/email/campaign_create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>New Campaign</a>';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<style>
    .table-campaigns thead th { font-size: .7rem; letter-spacing: .06em; text-transform: uppercase; color: #64748b; font-weight: 600; background: #f8fafc; }
    .table-campaigns tbody td { vertical-align: middle; padding: .85rem 1rem; }
    .table-campaigns code { background: #f1f5f9; color: #475569; padding: .125rem .5rem; border-radius: 4px; font-size: .75rem; }
</style>

<div class="card border-0 shadow-sm mb-4">
    <form method="GET" class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-secondary">Project</label>
                <select name="project_id" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($projects_list as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo $project_filter == $p['id'] ? 'selected' : ''; ?>><?php echo sanitize($p['project_code'] . ' — ' . $p['project_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-secondary">Vendor</label>
                <select name="vendor_id" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($vendors_list as $v): ?>
                    <option value="<?php echo $v['id']; ?>" <?php echo $vendor_filter == $v['id'] ? 'selected' : ''; ?>><?php echo sanitize($v['project_code'] . ' — ' . $v['vendor_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-secondary">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach (['draft','scheduled','running','paused','completed','failed'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo $status_filter === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="bi bi-funnel"></i>Apply</button>
                <a href="<?php echo BASE_URL; ?>/email/campaigns.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </div>
    </form>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom py-3"><h5 class="mb-0 fw-semibold"><?php echo number_format($total); ?> campaigns</h5></div>
    <div class="table-responsive">
        <table class="table table-hover table-campaigns align-middle mb-0">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Vendor</th>
                    <th>Campaign</th>
                    <th>Status</th>
                    <th class="text-end">Sends</th>
                    <th class="text-end">Opens</th>
                    <th class="text-end">Clicks</th>
                    <th class="text-end">Conv</th>
                    <th class="text-end">Bounce</th>
                    <th class="text-end">Failed</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($campaigns)): ?>
                    <tr><td colspan="11" class="text-center py-5 text-muted">No campaigns match your filters.</td></tr>
                <?php else: foreach ($campaigns as $c): ?>
                    <tr>
                        <td><a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo (int)$c['project_id']; ?>"><code><?php echo sanitize($c['project_code']); ?></code></a></td>
                        <td><?php echo sanitize($c['vendor_name']); ?></td>
                        <td class="fw-semibold"><?php echo sanitize($c['name']); ?></td>
                        <td><?php echo status_badge($c['status']); ?></td>
                        <td class="text-end"><?php echo number_format((int)($c['total_sends'] ?? 0)); ?></td>
                        <td class="text-end"><?php echo number_format((int)($c['opened_count'] ?? 0)); ?></td>
                        <td class="text-end"><?php echo number_format((int)($c['clicked_count'] ?? 0)); ?></td>
                        <td class="text-end"><?php echo number_format((int)($c['converted_count'] ?? 0)); ?></td>
                        <td class="text-end"><?php echo number_format((int)($c['bounced_count'] ?? 0)); ?></td>
                        <td class="text-end"><?php echo number_format((int)($c['failed_count'] ?? 0)); ?></td>
                        <td>
                            <a href="<?php echo BASE_URL; ?>/email/campaign_detail.php?id=<?php echo (int)$c['id']; ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-eye"></i></a>
                            <a href="<?php echo BASE_URL; ?>/email/campaign_edit.php?id=<?php echo (int)$c['id']; ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i></a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white py-3"><?php echo render_pagination($pagination, BASE_URL . '/email/campaigns.php?' . http_build_query($_GET)); ?></div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
