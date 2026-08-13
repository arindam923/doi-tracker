<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$traffic_filter = $_GET['traffic'] ?? '';
$project_filter = intval($_GET['project_id'] ?? 0);
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(gv.vendor_code LIKE ? OR gv.vendor_name LIKE ? OR gv.company_name LIKE ? OR gv.email LIKE ?)";
    $needle = "%$search%";
    $params = array_merge($params, [$needle, $needle, $needle, $needle]);
}
if ($status_filter !== '' && array_key_exists($status_filter, tf_vendor_statuses())) {
    $where[] = "gv.vendor_status = ?";
    $params[] = $status_filter;
}
if ($traffic_filter !== '' && in_array($traffic_filter, tf_traffic_types(), true)) {
    $where[] = "gv.traffic_type = ?";
    $params[] = $traffic_filter;
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count = $pdo->prepare("SELECT COUNT(*) as cnt FROM global_vendors gv $where_sql");
$count->execute($params);
$total = (int)$count->fetch()['cnt'];
$pagination = paginate($total, $per_page, $page);

$vendors = $pdo->prepare("
    SELECT gv.*,
        (SELECT COUNT(DISTINCT pv.project_id) FROM project_vendor pv WHERE pv.vendor_id = gv.id) AS attached_projects,
        (SELECT COUNT(*) FROM clicks c WHERE c.vendor_id = gv.id) AS total_clicks,
        (SELECT COUNT(*) FROM conversions cc WHERE cc.vendor_id = gv.id AND cc.status='complete') AS total_conversions,
        (SELECT COALESCE(SUM(cc.profit),0) FROM conversions cc WHERE cc.vendor_id = gv.id AND cc.status='complete') AS total_profit
    FROM global_vendors gv
    $where_sql
    ORDER BY gv.vendor_status = 'approved' DESC, gv.created_at DESC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
");
$vendors->execute($params);
$gv_list = $vendors->fetchAll();

$projects_list = $pdo->query("SELECT id, project_code, project_name FROM projects ORDER BY project_name")->fetchAll();

$page_title = 'Global Vendor Library';
$page_actions = $project_filter
    ? '<a href="'.BASE_URL.'/vendors/list.php?project_id='.$project_filter.'" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back to Project Vendors</a>'
    : '';
require_once __DIR__ . '/../helpers/layout_header.php';
?>
<div class="card border-0 shadow-sm mb-4">
    <form method="GET" class="card-body">
        <?php if ($project_filter): ?><input type="hidden" name="project_id" value="<?php echo $project_filter; ?>"><?php endif; ?>
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label small fw-semibold text-secondary">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" value="<?php echo sanitize($search); ?>" placeholder="Vendor name / code / email / company...">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-secondary">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach (tf_vendor_statuses() as $k => $label): ?>
                    <option value="<?php echo $k; ?>" <?php echo $status_filter === $k ? 'selected' : ''; ?>><?php echo sanitize($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-secondary">Traffic</label>
                <select name="traffic" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach (tf_traffic_types() as $t): ?>
                    <option value="<?php echo $t; ?>" <?php echo $traffic_filter === $t ? 'selected' : ''; ?>><?php echo sanitize($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label small fw-semibold text-secondary">Attach to Project</label>
                <select name="project_id" class="form-select form-select-sm">
                    <option value="">(view only)</option>
                    <?php foreach ($projects_list as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo $project_filter === (int)$p['id'] ? 'selected' : ''; ?>><?php echo sanitize($p['project_code']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i>Filter</button>
                <a href="<?php echo BASE_URL; ?>/vendors/global_library.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </div>
    </form>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-semibold"><?php echo number_format($total); ?> global vendors</h5>
        <div>
            <?php if ($project_filter): ?>
                <span class="badge bg-info me-2">Attaching to: project #<?php echo $project_filter; ?></span>
            <?php endif; ?>
            <a href="<?php echo BASE_URL; ?>/vendors/global.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-grid-3x3-gap"></i>Performance View</a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-light">
                <tr>
                    <th>Code</th><th>Vendor</th><th>Traffic</th><th>Status</th>
                    <th class="text-center">Projects</th>
                    <th class="text-end">Clicks</th>
                    <th class="text-end">Conv.</th>
                    <th class="text-end">Profit</th>
                    <th>Contact</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($gv_list)): ?>
                <tr><td colspan="10" class="text-center py-5 text-muted">No vendors match.</td></tr>
                <?php else: foreach ($gv_list as $g): ?>
                <tr>
                    <td><code><?php echo sanitize($g['vendor_code']); ?></code></td>
                    <td>
                        <div class="fw-semibold"><?php echo sanitize($g['vendor_name']); ?></div>
                        <?php if (!empty($g['company_name'])): ?><div class="small text-secondary"><?php echo sanitize($g['company_name']); ?></div><?php endif; ?>
                    </td>
                    <td><?php echo sanitize($g['traffic_type']); ?></td>
                    <td><?php echo status_badge($g['vendor_status']); ?></td>
                    <td class="text-center"><?php echo (int)$g['attached_projects']; ?></td>
                    <td class="text-end"><?php echo number_format((int)$g['total_clicks']); ?></td>
                    <td class="text-end"><?php echo number_format((int)$g['total_conversions']); ?></td>
                    <td class="text-end <?php echo (float)$g['total_profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo format_currency((float)$g['total_profit'], $g['currency'] ?? 'USD'); ?>
                    </td>
                    <td class="small">
                        <?php if (!empty($g['contact_person'])): ?><div><?php echo sanitize($g['contact_person']); ?></div><?php endif; ?>
                        <?php if (!empty($g['email'])): ?><div><a href="mailto:<?php echo urlencode($g['email']); ?>"><?php echo sanitize($g['email']); ?></a></div><?php endif; ?>
                        <?php if (!empty($g['telegram'])): ?><div class="text-secondary">@<?php echo sanitize($g['telegram']); ?></div><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($project_filter): ?>
                            <form method="POST" action="<?php echo BASE_URL; ?>/vendors/attach.php" class="d-inline" onsubmit="return confirm('Attach this vendor to the selected project?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="project_id" value="<?php echo $project_filter; ?>">
                                <input type="hidden" name="global_vendor_id" value="<?php echo (int)$g['id']; ?>">
                                <input type="hidden" name="payout" value="<?php echo (float)$g['default_payout']; ?>">
                                <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-link-45deg"></i> Attach</button>
                            </form>
                        <?php else: ?>
                            <a href="<?php echo BASE_URL; ?>/vendors/edit_global.php?id=<?php echo (int)$g['id']; ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil"></i>Edit</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white py-3">
        <?php echo render_pagination($pagination, BASE_URL . '/vendors/global_library.php?' . http_build_query($_GET)); ?>
    </div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
