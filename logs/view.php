<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$log_type = tf_get_string('type');
$status_filter = tf_get_string('status');
$date_from = tf_get_date('from', '');
$date_to = tf_get_date('to', '');
$page = max(1, tf_get_int('page', 1));
$per_page = 100;

$where = [];
$params = [];

if ($log_type) {
    $where[] = "l.log_type = ?";
    $params[] = $log_type;
}
if ($status_filter) {
    $where[] = "l.status = ?";
    $params[] = $status_filter;
}
if ($date_from) {
    $where[] = "l.created_at >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $where[] = "l.created_at <= DATE_ADD(?, INTERVAL 1 DAY)";
    $params[] = $date_to;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$logs_params = array_filter(['type'=>$log_type,'status'=>$status_filter,'from'=>$date_from,'to'=>$date_to], fn($v)=>$v!=='' && $v!==null);
$logs_qs = http_build_query($logs_params);
$logs_base = BASE_URL . '/logs/view.php' . ($logs_qs !== '' ? '?' . $logs_qs : '');

try {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM logs l $where_sql");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetch()['cnt'];
    $pagination = paginate($total, $per_page, $page);
    $stmt = $pdo->prepare("
    SELECT l.*, p.project_code, gv.vendor_name
    FROM logs l
    LEFT JOIN projects p ON l.project_id = p.id
    LEFT JOIN global_vendors gv ON l.vendor_id = gv.id
    $where_sql
    ORDER BY l.created_at DESC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
 ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('logs/view query failed: '.$e->getMessage());
    $total = 0;
    $pagination = paginate(0, $per_page, $page);
    $logs = [];
}

$log_types = ['click', 'postback', 'error', 'status_change', 'manual', 'vendor_postback', 'login', 'vendor_change'];
$statuses = ['success', 'failed', 'duplicate', 'rejected'];

$page_title = 'System Logs';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<!-- Filters -->
<div class="tf-card mb-4">
    <form method="GET" class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label for="type" class="tf-label">Type</label>
                <select id="type" name="type" class="form-select">
                    <option value="">All Types</option>
                    <?php foreach ($log_types as $lt): ?>
                    <option value="<?php echo $lt; ?>" <?php echo $log_type === $lt ? 'selected' : ''; ?>><?php echo $lt; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label for="status" class="tf-label">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All Status</option>
                    <?php foreach ($statuses as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo $status_filter === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label for="from" class="tf-label">From</label>
                <input type="date" id="from" name="from" class="form-control" value="<?php echo $date_from; ?>">
            </div>
            <div class="col-12 col-md-2">
                <label for="to" class="tf-label">To</label>
                <input type="date" id="to" name="to" class="form-control" value="<?php echo $date_to; ?>">
            </div>
            <div class="col-12 col-md-2 d-flex justify-content-end gap-2">
                <a href="<?php echo BASE_URL; ?>/logs/view.php" class="btn btn-outline-secondary btn-sm">Clear</a>
                <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-funnel"></i>Filter</button>
            </div>
        </div>
    </form>
</div>

<!-- Logs Table -->
<div class="tf-card">
    <div class="table-responsive">
        <table class="table table-hover table-logs align-middle mb-0">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Project</th>
                    <th>Vendor</th>
                    <th>Click ID</th>
                    <th>Message</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="8" class="text-center py-5 text-muted">
                        <i class="bi bi-journal-text d-block mb-2" style="font-size: 2.5rem; color: #cbd5e1;"></i>
                        <p class="fw-semibold text-dark mb-1">No logs found</p>
                        <p class="small mb-0">Try adjusting your filters.</p>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="text-nowrap small text-muted"><?php echo time_ago($log['created_at']); ?></td>
                    <td><span class="badge bg-light"><?php echo sanitize($log['log_type']); ?></span></td>
                    <td><?php echo status_badge($log['status'] ?? '-'); ?></td>
                    <td><?php echo $log['project_code'] ? '<code>' . sanitize($log['project_code']) . '</code>' : '-'; ?></td>
                    <td><?php echo sanitize($log['vendor_name'] ?? '-'); ?></td>
                    <td><?php echo $log['click_id'] ? '<code>' . substr($log['click_id'], 0, 12) . '…</code>' : '-'; ?></td>
                    <td class="small text-secondary"><?php echo sanitize($log['message'] ?? ''); ?></td>
                    <td class="small text-muted"><?php echo sanitize($log['ip_address'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
echo render_pagination($pagination, $logs_base);
require_once __DIR__ . '/../helpers/layout_footer.php';
?>
