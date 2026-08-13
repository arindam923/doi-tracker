<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$search = trim($_GET['search'] ?? '');
$vendor_filter = intval($_GET['vendor_id'] ?? 0);
$project_filter = intval($_GET['project_id'] ?? 0);
$country_filter = trim($_GET['country'] ?? '');
$device_filter = trim($_GET['device'] ?? '');
$browser_filter = trim($_GET['browser'] ?? '');
$isp_filter = trim($_GET['isp'] ?? '');
$click_id_filter = trim($_GET['click_id'] ?? '');
$from_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-7 days'));
$to_date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;

$where = ["c.clicked_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)"];
$params = [$from_date, $to_date];

if ($search) {
    $where[] = "(c.click_id LIKE ? OR c.ip_address LIKE ? OR c.user_agent LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($vendor_filter) {
    $where[] = "c.vendor_id = ?";
    $params[] = $vendor_filter;
}
if ($project_filter) {
    $where[] = "c.project_id = ?";
    $params[] = $project_filter;
}
if ($country_filter !== '') {
    $where[] = "c.country_code = ?";
    $params[] = strtoupper(substr($country_filter, 0, 2));
}
if ($device_filter !== '') {
    $where[] = "c.device_type = ?";
    $params[] = $device_filter;
}
if ($browser_filter !== '') {
    $where[] = "c.browser LIKE ?";
    $params[] = "%$browser_filter%";
}
if ($isp_filter !== '') {
    $where[] = "c.isp LIKE ?";
    $params[] = "%$isp_filter%";
}
if ($click_id_filter) {
    $where[] = "c.click_id = ?";
    $params[] = $click_id_filter;
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

$count = $pdo->prepare("SELECT COUNT(*) as cnt FROM clicks c $where_sql");
$count->execute($params);
$total = (int)$count->fetch()['cnt'];

$pagination = paginate($total, $per_page, $page);

$stmt = $pdo->prepare("
    SELECT c.*, gv.vendor_name, p.project_code, p.project_name
    FROM clicks c
    JOIN global_vendors gv ON c.vendor_id = gv.id
    JOIN projects p ON c.project_id = p.id
    $where_sql
    ORDER BY c.clicked_at DESC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
");
$stmt->execute($params);
$clicks = $stmt->fetchAll();

$vendors_list = $pdo->query("SELECT gv.id, gv.vendor_name, p.project_code FROM project_vendor pv JOIN global_vendors gv ON gv.id = pv.vendor_id JOIN projects p ON p.id = pv.project_id ORDER BY p.project_code, gv.vendor_name")->fetchAll();
$projects_list = $pdo->query("SELECT id, project_code, project_name FROM projects ORDER BY project_name")->fetchAll();

$page_title = 'Click Logs';
$page_actions = '<a href="' . BASE_URL . '/clicklogs/export.php?' . http_build_query($_GET) . '" class="btn btn-outline-success btn-sm"><i class="bi bi-download"></i>Export CSV</a>';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<style>
    .table-clicks thead th { font-size: .7rem; letter-spacing: .06em; text-transform: uppercase; color: #64748b; font-weight: 600; background: #f8fafc; }
    .table-clicks tbody td { vertical-align: middle; padding: .65rem .75rem; font-size: .8125rem; }
    .table-clicks code { background: #f1f5f9; color: #475569; padding: .125rem .375rem; border-radius: 4px; font-size: .75rem; }
</style>

<div class="card border-0 shadow-sm mb-4">
    <form method="GET" class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-2">
                <label class="form-label small fw-semibold text-secondary">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?php echo $from_date; ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label small fw-semibold text-secondary">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?php echo $to_date; ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label small fw-semibold text-secondary">Project</label>
                <select name="project_id" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($projects_list as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo $project_filter == $p['id'] ? 'selected' : ''; ?>><?php echo sanitize($p['project_code']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label small fw-semibold text-secondary">Vendor</label>
                <select name="vendor_id" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($vendors_list as $v): ?>
                    <option value="<?php echo $v['id']; ?>" <?php echo $vendor_filter == $v['id'] ? 'selected' : ''; ?>><?php echo sanitize($v['project_code'] . ' — ' . $v['vendor_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <label class="form-label small fw-semibold text-secondary">Country</label>
                <input type="text" name="country" maxlength="2" class="form-control form-control-sm" value="<?php echo sanitize($country_filter); ?>" placeholder="US">
            </div>
            <div class="col-6 col-md-1">
                <label class="form-label small fw-semibold text-secondary">Device</label>
                <select name="device" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="desktop" <?php echo $device_filter === 'desktop' ? 'selected' : ''; ?>>Desktop</option>
                    <option value="mobile" <?php echo $device_filter === 'mobile' ? 'selected' : ''; ?>>Mobile</option>
                    <option value="tablet" <?php echo $device_filter === 'tablet' ? 'selected' : ''; ?>>Tablet</option>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <label class="form-label small fw-semibold text-secondary">Browser</label>
                <input type="text" name="browser" class="form-control form-control-sm" value="<?php echo sanitize($browser_filter); ?>" placeholder="Chrome">
            </div>
            <div class="col-6 col-md-1">
                <label class="form-label small fw-semibold text-secondary">ISP</label>
                <input type="text" name="isp" class="form-control form-control-sm" value="<?php echo sanitize($isp_filter); ?>" placeholder="Comcast">
            </div>
            <div class="col-6 col-md-1">
                <label class="form-label small fw-semibold text-secondary">IP / UA</label>
                <input type="text" name="search" class="form-control form-control-sm" value="<?php echo sanitize($search); ?>" placeholder="...">
            </div>
            <div class="col-6 col-md-1">
                <label class="form-label small fw-semibold text-secondary">Click ID</label>
                <input type="text" name="click_id" class="form-control form-control-sm" value="<?php echo sanitize($click_id_filter); ?>">
            </div>
            <div class="col-12 col-md-12 mt-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i>Apply Filters</button>
                <a href="<?php echo BASE_URL; ?>/clicklogs/list.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </div>
    </form>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom py-3">
        <h5 class="mb-0 fw-semibold"><?php echo number_format($total); ?> clicks <small class="text-muted">(<?php echo $from_date; ?> → <?php echo $to_date; ?>)</small></h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-clicks align-middle mb-0">
            <thead>
                <tr>
                    <th>Click ID</th>
                    <th>Project</th>
                    <th>Vendor</th>
                    <th>Country</th>
                    <th>Device</th>
                    <th>Browser</th>
                    <th>OS</th>
                    <th>IP</th>
                    <th>Sub1-5</th>
                    <th>Status</th>
                    <th>Clicked</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clicks)): ?>
                <tr><td colspan="11" class="text-center py-5 text-muted">No clicks match your filters.</td></tr>
                <?php else: foreach ($clicks as $c): ?>
                <tr>
                    <td><code><?php echo substr(sanitize($c['click_id']), 0, 12); ?>…</code></td>
                    <td><a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo $c['project_id']; ?>"><?php echo sanitize($c['project_code']); ?></a></td>
                    <td><?php echo sanitize($c['vendor_name']); ?></td>
                    <td><?php echo sanitize($c['country_code'] ?? 'XX'); ?></td>
                    <td><?php echo sanitize(ucfirst($c['device_type'] ?? '')); ?></td>
                    <td><?php echo sanitize($c['browser'] ?? ''); ?></td>
                    <td class="text-secondary"><?php echo sanitize($c['os'] ?? ''); ?></td>
                    <td><code><?php echo sanitize($c['ip_address'] ?? ''); ?></code></td>
                    <td class="small text-secondary">
                        <?php $subs = array_filter([$c['sub1']??'', $c['sub2']??'', $c['sub3']??'', $c['sub4']??'', $c['sub5']??'']); echo $subs ? sanitize(implode(' · ', $subs)) : '—'; ?>
                    </td>
                    <td>
                        <?php if ($c['is_converted']): ?>
                        <span class="badge bg-success">Converted</span>
                        <?php else: ?>
                        <span class="badge bg-light text-dark border">Pending</span>
                        <?php endif; ?>
                        <?php if ($c['is_duplicate_ip']): ?>
                        <span class="badge bg-warning" title="Duplicate IP in last 24h">DUP</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-secondary"><?php echo sanitize($c['clicked_at']); ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white py-3"><?php echo render_pagination($pagination, BASE_URL . '/clicklogs/list.php?' . http_build_query($_GET)); ?></div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>