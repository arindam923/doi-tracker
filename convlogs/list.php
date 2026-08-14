<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$search = trim($_GET['search'] ?? '');
$project_filter = intval($_GET['project_id'] ?? 0);
$vendor_filter = intval($_GET['vendor_id'] ?? 0);
$status_filter = $_GET['approval_status'] ?? '';
$from_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to_date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;

$where = ["cv.converted_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)"];
$params = [$from_date, $to_date];

if ($search) {
    $where[] = "(cv.click_id LIKE ? OR cv.transaction_id LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($project_filter) { $where[] = "cv.project_id = ?"; $params[] = $project_filter; }
if ($vendor_filter) { $where[] = "cv.vendor_id = ?"; $params[] = $vendor_filter; }
if (in_array($status_filter, ['pending', 'approved', 'rejected'], true)) {
    $where[] = "cv.approval_status = ?"; $params[] = $status_filter;
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

$count = $pdo->prepare("SELECT COUNT(*) as cnt FROM conversions cv $where_sql");
$count->execute($params);
$total = (int)$count->fetch()['cnt'];
$pagination = paginate($total, $per_page, $page);

$stmt = $pdo->prepare("
    SELECT cv.*, p.project_code, p.project_name, gv.vendor_name,
           c.clicked_at AS click_time, c.ip_address, c.country_code, c.device_type
    FROM conversions cv
    JOIN projects p ON cv.project_id = p.id
    JOIN global_vendors gv ON cv.vendor_id = gv.id
    LEFT JOIN clicks c ON cv.click_id = c.click_id
    $where_sql
    ORDER BY cv.converted_at DESC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
");
$stmt->execute($params);
$conversions = $stmt->fetchAll();

$projects_list = $pdo->query("SELECT id, project_code, project_name FROM projects ORDER BY project_name")->fetchAll();
$vendors_list = $pdo->query("SELECT gv.id, gv.vendor_name, p.project_code FROM project_vendor pv JOIN global_vendors gv ON gv.id = pv.vendor_id JOIN projects p ON p.id = pv.project_id ORDER BY p.project_code, gv.vendor_name")->fetchAll();

$page_title = 'Conversion Logs';
$page_actions = '<a href="' . BASE_URL . '/convlogs/export.php?' . http_build_query($_GET) . '" class="btn btn-outline-success btn-sm"><i class="bi bi-download"></i>Export CSV</a>';
require_once __DIR__ . '/../helpers/layout_header.php';
?>


<div class="tf-card mb-4">
    <form method="GET" class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="tf-label">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?php echo $from_date; ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="tf-label">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?php echo $to_date; ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="tf-label">Project</label>
                <select name="project_id" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($projects_list as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo $project_filter == $p['id'] ? 'selected' : ''; ?>><?php echo sanitize($p['project_code']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="tf-label">Vendor</label>
                <select name="vendor_id" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($vendors_list as $v): ?>
                    <option value="<?php echo $v['id']; ?>" <?php echo $vendor_filter == $v['id'] ? 'selected' : ''; ?>><?php echo sanitize($v['project_code'] . ' — ' . $v['vendor_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="tf-label">Approval</label>
                <select name="approval_status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="tf-label">Click / Txn ID</label>
                <input type="text" name="search" class="form-control form-control-sm" value="<?php echo sanitize($search); ?>">
            </div>
            <div class="col-12 mt-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i>Apply</button>
                <a href="<?php echo BASE_URL; ?>/convlogs/list.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </div>
    </form>
</div>

<div class="tf-card">
    <div class="tf-card-header">
        <h5 class="mb-0 fw-semibold"><?php echo number_format($total); ?> conversions</h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-conv align-middle mb-0">
            <thead>
                <tr>
                    <th>Click Time</th>
                    <th>Convert Time</th>
                    <th>Δ</th>
                    <th>Project</th>
                    <th>Vendor</th>
                    <th>Click ID</th>
                    <th>Txn ID</th>
                    <th class="text-end">Revenue</th>
                    <th class="text-end">Payout</th>
                    <th class="text-end">Profit</th>
                    <th>Approval</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($conversions)): ?>
                <tr><td colspan="12" class="text-center py-5 text-muted">No conversions match your filters.</td></tr>
                <?php else: foreach ($conversions as $cv):
                    $td = (int)$cv['time_diff_seconds'];
                    $td_str = $td > 3600 ? round($td/3600, 1) . 'h' : ($td > 60 ? round($td/60, 1) . 'm' : $td . 's');
                ?>
                <tr>
                    <td class="text-secondary"><?php echo sanitize($cv['click_time'] ?? '-'); ?></td>
                    <td><?php echo sanitize($cv['converted_at']); ?></td>
                    <td><span class="badge bg-light text-dark border"><?php echo $td_str; ?></span></td>
                    <td><a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo $cv['project_id']; ?>"><?php echo sanitize($cv['project_code']); ?></a></td>
                    <td><?php echo sanitize($cv['vendor_name']); ?></td>
                    <td><code><?php echo substr(sanitize($cv['click_id']), 0, 12); ?>…</code></td>
                    <td><code><?php echo sanitize($cv['transaction_id'] ?? '—'); ?></code></td>
                    <td class="text-end"><?php echo format_currency($cv['client_revenue'], $cv['currency']); ?></td>
                    <td class="text-end"><?php echo format_currency($cv['vendor_cost']); ?></td>
                    <td class="text-end fw-semibold <?php echo $cv['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo format_currency($cv['profit'], $cv['currency']); ?></td>
                    <td>
                        <?php
                        $ap = $cv['approval_status'] ?? 'approved';
                        $cls = $ap === 'approved' ? 'success' : ($ap === 'rejected' ? 'danger' : 'warning');
                        echo '<span class="badge bg-' . $cls . '">' . sanitize(ucfirst($ap)) . '</span>';
                        ?>
                        <?php if ($ap === 'pending' || $ap === 'rejected'): ?>
                        <form method="POST" action="<?php echo BASE_URL; ?>/convlogs/approve.php" class="d-inline">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo $cv['id']; ?>">
                            <input type="hidden" name="redirect" value="<?php echo sanitize($_SERVER['REQUEST_URI']); ?>">
                            <button type="submit" class="btn btn-link btn-sm p-0 ms-1" title="Approve"><i class="bi bi-check-circle text-success"></i></button>
                        </form>
                        <?php endif; ?>
                        <?php if ($ap !== 'rejected'): ?>
                        <form method="POST" action="<?php echo BASE_URL; ?>/convlogs/reject.php" class="d-inline">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo $cv['id']; ?>">
                            <input type="hidden" name="redirect" value="<?php echo sanitize($_SERVER['REQUEST_URI']); ?>">
                            <button type="submit" class="btn btn-link btn-sm p-0 ms-1" title="Reject"><i class="bi bi-x-circle text-danger"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($cv['is_manual']): ?>
                        <span class="badge bg-info">Manual</span>
                        <?php else: ?>
                        <span class="badge bg-light text-dark border">Auto</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="tf-card-footer"><?php echo render_pagination($pagination, BASE_URL . '/convlogs/list.php?' . http_build_query($_GET)); ?></div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>