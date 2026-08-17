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
$page_actions = '<a href="' . BASE_URL . '/convlogs/export.php?' . http_build_query($_GET) . '" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download"></i>Export CSV</a>';
require_once __DIR__ . '/../helpers/layout_header.php';

$fmt_when = static function ($dt) {
    if (!$dt || $dt === '-') {
        return ['—', ''];
    }
    $ts = strtotime((string)$dt);
    if (!$ts) {
        return [(string)$dt, ''];
    }
    return [date('M j, Y', $ts), date('H:i:s', $ts)];
};
?>

<div class="tf-page tf-convlogs">
    <form method="GET" class="tf-convlogs-filters">
        <div class="tf-field">
            <label class="tf-label" for="from">From</label>
            <input type="date" id="from" name="from" class="form-control" value="<?php echo $from_date; ?>">
        </div>
        <div class="tf-field">
            <label class="tf-label" for="to">To</label>
            <input type="date" id="to" name="to" class="form-control" value="<?php echo $to_date; ?>">
        </div>
        <div class="tf-field">
            <label class="tf-label" for="project_id">Project</label>
            <select id="project_id" name="project_id" class="form-select" data-tf-nice="off">
                <option value="">All projects</option>
                <?php foreach ($projects_list as $p): ?>
                <option value="<?php echo $p['id']; ?>" <?php echo $project_filter == $p['id'] ? 'selected' : ''; ?>><?php echo sanitize($p['project_code']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="tf-field">
            <label class="tf-label" for="vendor_id">Vendor</label>
            <select id="vendor_id" name="vendor_id" class="form-select" data-tf-nice="off">
                <option value="">All vendors</option>
                <?php foreach ($vendors_list as $v): ?>
                <option value="<?php echo $v['id']; ?>" <?php echo $vendor_filter == $v['id'] ? 'selected' : ''; ?>><?php echo sanitize($v['project_code'] . ' / ' . $v['vendor_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="tf-field">
            <label class="tf-label" for="approval_status">Approval</label>
            <select id="approval_status" name="approval_status" class="form-select" data-tf-nice="off">
                <option value="">All</option>
                <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
            </select>
        </div>
        <div class="tf-field is-search">
            <label class="tf-label" for="search">Click or transaction ID</label>
            <input type="search" id="search" name="search" class="form-control" value="<?php echo sanitize($search); ?>" placeholder="Search IDs">
        </div>
        <div class="tf-convlogs-actions">
            <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i>Apply</button>
            <a href="<?php echo BASE_URL; ?>/convlogs/list.php" class="btn btn-secondary">Reset</a>
        </div>
    </form>

    <div class="tf-card tf-convlogs-card">
        <div class="tf-card-header">
            <div>
                <h5 class="tf-card-title"><?php echo number_format($total); ?> conversions</h5>
                <p class="tf-card-subtitle"><?php echo sanitize($from_date); ?> to <?php echo sanitize($to_date); ?></p>
            </div>
        </div>
        <div class="tf-table-scroll">
            <table class="tf-table tf-conv-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Lag</th>
                        <th>Campaign</th>
                        <th>IDs</th>
                        <th class="is-numeric">Revenue</th>
                        <th class="is-numeric">Payout</th>
                        <th class="is-numeric">Profit</th>
                        <th>State</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($conversions)): ?>
                    <tr class="is-empty">
                        <td colspan="8">
                            <div class="tf-conv-empty">
                                <i class="bi bi-inbox" aria-hidden="true"></i>
                                <p>No conversions match these filters.</p>
                            </div>
                        </td>
                    </tr>
                    <?php else: foreach ($conversions as $cv):
                        $td = (int)($cv['time_diff_seconds'] ?? 0);
                        $td_str = $td > 3600 ? round($td / 3600, 1) . 'h' : ($td > 60 ? round($td / 60, 1) . 'm' : $td . 's');
                        $ap = $cv['approval_status'] ?? 'approved';
                        $profit = (float)($cv['profit'] ?? 0);
                        $cur = $cv['currency'] ?? 'USD';
                        [$conv_day, $conv_time] = $fmt_when($cv['converted_at'] ?? '');
                        [$click_day, $click_time] = $fmt_when($cv['click_time'] ?? '');
                        $row_class = [];
                        if (!empty($cv['is_manual'])) $row_class[] = 'is-manual';
                        $row_class[] = $profit >= 0 ? 'is-gain' : 'is-loss';
                        $click_id = (string)$cv['click_id'];
                        $txn = trim((string)($cv['transaction_id'] ?? ''));
                    ?>
                    <tr class="<?php echo implode(' ', $row_class); ?>">
                        <td>
                            <div class="tf-conv-when">
                                <strong><?php echo sanitize($conv_day); ?></strong>
                                <span><?php echo sanitize($conv_time); ?></span>
                                <?php if ($click_time): ?>
                                <em>clicked <?php echo sanitize($click_day === $conv_day ? $click_time : $click_day . ' ' . $click_time); ?></em>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><span class="tf-conv-lag"><?php echo sanitize($td_str); ?></span></td>
                        <td>
                            <div class="tf-conv-campaign">
                                <a class="tf-link-row" href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo (int)$cv['project_id']; ?>"><?php echo sanitize($cv['project_code']); ?></a>
                                <span>
                                    <?php if (!empty($cv['country_code']) && function_exists('tf_country_flag_html')) echo tf_country_flag_html($cv['country_code']); ?>
                                    <?php echo sanitize($cv['vendor_name']); ?>
                                </span>
                            </div>
                        </td>
                        <td>
                            <div class="tf-conv-ids">
                                <code title="<?php echo sanitize($click_id); ?>"><?php echo sanitize(strlen($click_id) > 12 ? substr($click_id, 0, 10) . '…' : $click_id); ?></code>
                                <?php if ($txn !== ''): ?>
                                <code class="is-txn" title="Transaction"><?php echo sanitize(strlen($txn) > 14 ? substr($txn, 0, 12) . '…' : $txn); ?></code>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="is-numeric"><span class="tf-money is-rev"><?php echo format_currency($cv['client_revenue'] ?? 0, $cur); ?></span></td>
                        <td class="is-numeric"><span class="tf-money is-pay"><?php echo format_currency($cv['vendor_cost'] ?? 0, $cur); ?></span></td>
                        <td class="is-numeric"><span class="tf-money <?php echo $profit >= 0 ? 'is-up' : 'is-down'; ?>"><?php echo format_currency($profit, $cur); ?></span></td>
                        <td>
                            <div class="tf-conv-state">
                                <span class="badge <?php echo $ap === 'approved' ? 'is-approved' : ($ap === 'rejected' ? 'is-danger' : 'is-warning'); ?>"><?php echo sanitize(ucfirst($ap)); ?></span>
                                <?php if (!empty($cv['is_manual'])): ?>
                                <span class="badge is-accent">Manual</span>
                                <?php else: ?>
                                <span class="badge is-light">Auto</span>
                                <?php endif; ?>
                                <span class="tf-conv-actions">
                                    <?php if ($ap === 'pending' || $ap === 'rejected'): ?>
                                    <form method="POST" action="<?php echo BASE_URL; ?>/convlogs/approve.php">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo (int)$cv['id']; ?>">
                                        <input type="hidden" name="redirect" value="<?php echo sanitize($_SERVER['REQUEST_URI']); ?>">
                                        <button type="submit" class="tf-icon-btn is-ok" title="Approve" aria-label="Approve"><i class="bi bi-check-lg"></i></button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if ($ap !== 'rejected'): ?>
                                    <form method="POST" action="<?php echo BASE_URL; ?>/convlogs/reject.php">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo (int)$cv['id']; ?>">
                                        <input type="hidden" name="redirect" value="<?php echo sanitize($_SERVER['REQUEST_URI']); ?>">
                                        <button type="submit" class="tf-icon-btn is-no" title="Reject" aria-label="Reject"><i class="bi bi-x-lg"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="tf-card-footer"><?php echo render_pagination($pagination, BASE_URL . '/convlogs/list.php?' . http_build_query($_GET)); ?></div>
    </div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>