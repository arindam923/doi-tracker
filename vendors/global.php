<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$traffic_filter = $_GET['traffic'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(gv.vendor_code LIKE ? OR gv.vendor_name LIKE ? OR gv.email LIKE ?)";
    $needle = "%$search%";
    $params = array_merge($params, [$needle, $needle, $needle]);
}
if ($status_filter !== '' && array_key_exists($status_filter, tf_vendor_statuses())) {
    $where[] = "gv.vendor_status = ?";
    $params[] = $status_filter;
}
if ($traffic_filter !== '' && in_array($traffic_filter, tf_traffic_types(), true)) {
    $where[] = "FIND_IN_SET(?, gv.traffic_type)";
    $params[] = $traffic_filter;
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count_stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM global_vendors gv $where_sql");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetch()['cnt'];

$pagination = paginate($total, $per_page, $page);

$stmt = $pdo->prepare("
    SELECT gv.*,
        (SELECT COUNT(DISTINCT pv.project_id) FROM project_vendor pv WHERE pv.vendor_id = gv.id) AS attached_projects,
        (SELECT COUNT(*) FROM clicks c WHERE c.vendor_id = gv.id) AS total_clicks,
        (SELECT COUNT(*) FROM conversions cc WHERE cc.vendor_id = gv.id AND cc.status = 'complete') AS total_completes,
        (SELECT COALESCE(SUM(cc.profit),0) FROM conversions cc WHERE cc.vendor_id = gv.id AND cc.status = 'complete') AS total_profit
    FROM global_vendors gv
    $where_sql
    ORDER BY gv.created_at DESC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
");
$stmt->execute($params);
$vendors = $stmt->fetchAll();

$page_title = 'Vendors';
$page_actions = '<a href="' . BASE_URL . '/vendors/create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>Add Vendor</a>';
require_once __DIR__ . '/../helpers/layout_header.php';
?>


<!-- Filters -->
<div class="tf-card mb-4">
    <form method="GET" class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-4">
                <label for="search" class="tf-label">Search</label>
                <input type="text" id="search" name="search" class="form-control" placeholder="Name, code, email..."
                       value="<?php echo sanitize($search); ?>">
            </div>
            <div class="col-6 col-md-3">
                <label for="status" class="tf-label">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All Status</option>
                    <?php foreach (tf_vendor_statuses() as $k => $label): ?>
                    <option value="<?php echo $k; ?>" <?php echo $status_filter === $k ? 'selected' : ''; ?>><?php echo sanitize($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label for="traffic" class="tf-label">Traffic Type</label>
                <select id="traffic" name="traffic" class="form-select">
                    <option value="">All Traffic</option>
                    <?php foreach (tf_traffic_types() as $t): ?>
                    <option value="<?php echo $t; ?>" <?php echo $traffic_filter === $t ? 'selected' : ''; ?>><?php echo sanitize($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex justify-content-end">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i>Filter</button>
            </div>
        </div>
    </form>
</div>

<!-- Vendors Table -->
<div class="tf-card">
    <div class="table-responsive">
        <table class="table table-hover table-vendors align-middle mb-0">
            <thead>
                <tr>
                    <th>Vendor</th>
                    <th>Code</th>
                    <th>Traffic</th>
                    <th>Status</th>
                    <th class="text-end">Default Payout</th>
                    <th class="text-center">Projects</th>
                    <th class="text-end">Clicks</th>
                    <th class="text-end">Completes</th>
                    <th class="text-end">Profit</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vendors)): ?>
                <tr>
                    <td colspan="10" class="text-center py-5 text-muted">
                        <i class="bi bi-people d-block mb-2" style="font-size: 2.5rem; color: #cbd5e1;"></i>
                        <p class="fw-semibold text-dark mb-1">No vendors found</p>
                        <p class="small mb-3">Add your first vendor to start running campaigns.</p>
                        <a href="<?php echo BASE_URL; ?>/vendors/create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>Add Vendor</a>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($vendors as $v): ?>
                <tr>
                    <td>
                        <strong><?php echo sanitize($v['vendor_name']); ?></strong>
                        <?php if (!empty($v['email'])): ?><div class="small text-secondary"><a href="mailto:<?php echo urlencode($v['email']); ?>"><?php echo sanitize($v['email']); ?></a></div><?php endif; ?>
                    </td>
                    <td><code><?php echo sanitize($v['vendor_code']); ?></code></td>
                    <td><?php echo sanitize(str_replace(',', ', ', $v['traffic_type'] ?? '')); ?></td>
                    <td><?php echo status_badge($v['vendor_status']); ?></td>
                    <td class="text-end"><?php echo format_currency($v['default_payout'], $v['currency'] ?? 'USD'); ?></td>
                    <td class="text-center"><span class="badge bg-light"><?php echo (int)$v['attached_projects']; ?></span></td>
                    <td class="text-end"><?php echo number_format((int)$v['total_clicks']); ?></td>
                    <td class="text-end"><?php echo number_format((int)$v['total_completes']); ?></td>
                    <td class="text-end fw-semibold <?php echo (float)$v['total_profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo format_currency((float)$v['total_profit'], $v['currency'] ?? 'USD'); ?>
                    </td>
                    <td class="text-end">
                        <div class="tf-dropdown">
                            <button type="button" class="tf-dropdown-trigger" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for <?php echo sanitize($v['vendor_name']); ?>">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <div class="tf-dropdown-menu" role="menu" hidden>
                                <a href="<?php echo BASE_URL; ?>/vendors/edit_global.php?id=<?php echo (int)$v['id']; ?>" class="tf-dropdown-item" role="menuitem">
                                    <i class="bi bi-pencil"></i><span>Edit Vendor</span>
                                </a>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
echo render_pagination($pagination, BASE_URL . '/vendors/global.php');
require_once __DIR__ . '/../helpers/layout_footer.php';
?>
