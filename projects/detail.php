<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$id = intval($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/projects/list.php');

$stmt = $pdo->prepare("SELECT p.*, c.client_name, c.client_code, c.default_currency FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
$stmt->execute([$id]);
$project = $stmt->fetch();
if (!$project) {
    set_flash('danger', 'Project not found.');
    redirect(BASE_URL . '/projects/list.php');
}

$vstmt = $pdo->prepare("SELECT * FROM vendors WHERE project_id = ? ORDER BY vendor_name");
$vstmt->execute([$id]);
$vendors = $vstmt->fetchAll();

$vendor_stats = [];
$vs_stmt = $pdo->prepare("
    SELECT v.id,
        COUNT(DISTINCT cl.id) as clicks,
        SUM(cl.is_converted) as converts,
        COUNT(DISTINCT cv.id) as cnt,
        COALESCE(SUM(cv.client_revenue),0) as rev,
        COALESCE(SUM(cv.vendor_cost),0) as cost,
        COALESCE(SUM(cv.profit),0) as profit
    FROM vendors v
    LEFT JOIN clicks cl ON cl.vendor_id = v.id AND cl.project_id = v.project_id
    LEFT JOIN conversions cv ON cv.vendor_id = v.id AND cv.project_id = v.project_id AND cv.status = 'complete'
    WHERE v.project_id = ?
    GROUP BY v.id
");
$vs_stmt->execute([$id]);
while ($row = $vs_stmt->fetch()) {
    $vendor_stats[$row['id']] = [
        'clicks' => $row['clicks'] ?? 0,
        'completes' => $row['cnt'] ?? 0,
        'revenue' => $row['rev'] ?? 0,
        'cost' => $row['cost'] ?? 0,
        'profit' => $row['profit'] ?? 0,
    ];
}

$chart_labels = [];
$chart_clicks = [];
$chart_conversions = [];

$chart_click_data = [];
$chart_conv_data = [];

$cc_stmt = $pdo->prepare("SELECT DATE(clicked_at) as d, COUNT(*) as cnt FROM clicks WHERE project_id = ? AND clicked_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(clicked_at)");
$cc_stmt->execute([$id]);
while ($row = $cc_stmt->fetch()) { $chart_click_data[$row['d']] = $row['cnt']; }

$ccv_stmt = $pdo->prepare("SELECT DATE(converted_at) as d, COUNT(*) as cnt FROM conversions WHERE project_id = ? AND status = 'complete' AND converted_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(converted_at)");
$ccv_stmt->execute([$id]);
while ($row = $ccv_stmt->fetch()) { $chart_conv_data[$row['d']] = $row['cnt']; }

for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chart_labels[] = date('M d', strtotime($d));
    $chart_clicks[] = $chart_click_data[$d] ?? 0;
    $chart_conversions[] = $chart_conv_data[$d] ?? 0;
}

$ccr = calc_ccr($project['completes_count'], $project['clicks_count']);
$currency = $project['default_currency'] ?? 'USD';

$rev_stmt = $pdo->prepare("SELECT COALESCE(SUM(client_revenue),0) as rev, COALESCE(SUM(vendor_cost),0) as cost, COALESCE(SUM(profit),0) as profit FROM conversions WHERE project_id = ? AND status = 'complete'");
$rev_stmt->execute([$id]);
$rev_data = $rev_stmt->fetch();
$total_revenue = $rev_data['rev'];
$total_cost = $rev_data['cost'];
$total_profit = $rev_data['profit'];

$page_title = $project['project_name'];
$client_postback = BASE_URL . '/tracking/postback.php?click_id={click_id}&status=1&token=' . $project['postback_token'];

$page_actions = '
<div class="d-flex align-items-center gap-2 flex-wrap">
    <a href="' . BASE_URL . '/tracking/manual.php?project_id=' . $id . '" class="btn btn-warning btn-sm"><i class="bi bi-plus-circle"></i>Manual Conversion</a>
    <a href="' . BASE_URL . '/projects/edit.php?id=' . $id . '" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil"></i>Edit</a>
    <form method="POST" action="' . BASE_URL . '/projects/clone.php" class="d-inline">
        ' . csrf_field() . '
        <input type="hidden" name="id" value="' . $id . '">
        <button type="submit" class="btn btn-outline-secondary btn-sm" data-confirm="Clone this project?"><i class="bi bi-copy"></i>Clone</button>
    </form>
</div>';

require_once __DIR__ . '/../helpers/layout_header.php';
?>

<style>
    .stat-tile { padding: 1rem 1.25rem; }
    .stat-tile .stat-label { font-size: .7rem; letter-spacing: .06em; text-transform: uppercase; color: #64748b; font-weight: 600; margin-bottom: .5rem; }
    .stat-tile .stat-value { font-size: 1.5rem; font-weight: 700; color: #0f172a; line-height: 1.1; }
    .table-vendors thead th { font-size: .7rem; letter-spacing: .06em; text-transform: uppercase; color: #64748b; font-weight: 600; background: #f8fafc; }
    .table-vendors tbody td { vertical-align: middle; padding: .85rem 1rem; }
    .table-vendors code { background: #f1f5f9; color: #475569; padding: .125rem .5rem; border-radius: 4px; font-size: .75rem; }
    .info-table th { width: 40%; color: #64748b; font-weight: 500; padding: .75rem 1rem; }
    .info-table td { padding: .75rem 1rem; }
</style>

<!-- Project Info Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100 stat-tile">
            <div class="stat-label">Status</div>
            <div><?php echo status_badge($project['status']); ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100 stat-tile">
            <div class="stat-label">Clicks</div>
            <div class="stat-value"><?php echo number_format($project['clicks_count']); ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100 stat-tile">
            <div class="stat-label">Completes</div>
            <div class="stat-value"><?php echo number_format($project['completes_count']); ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100 stat-tile">
            <div class="stat-label">CCR</div>
            <div><span class="badge bg-<?php echo ccr_color($ccr); ?>" style="font-size: 1rem; padding: .4rem .8rem;"><?php echo $ccr; ?>%</span></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100 stat-tile">
            <div class="stat-label">Revenue</div>
            <div class="stat-value text-success" style="font-size: 1.25rem;"><?php echo format_currency($total_revenue, $currency); ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100 stat-tile">
            <div class="stat-label">Profit</div>
            <div class="stat-value <?php echo $total_profit >= 0 ? 'text-success' : 'text-danger'; ?>" style="font-size: 1.25rem;"><?php echo format_currency($total_profit, $currency); ?></div>
        </div>
    </div>
</div>

<!-- Quota Progress -->
<?php if ($project['total_quota'] > 0):
    $pct = min(100, ($project['completes_count'] / $project['total_quota']) * 100);
    $barColor = $pct >= 100 ? 'bg-danger' : ($pct >= 80 ? 'bg-warning' : 'bg-success');
?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between mb-2">
            <span class="fw-semibold">Quota Progress</span>
            <span class="text-muted small"><?php echo (int)$project['completes_count']; ?> / <?php echo number_format($project['total_quota']); ?> completes</span>
        </div>
        <div class="progress" style="height: 20px;">
            <div class="progress-bar <?php echo $barColor; ?>" style="width: <?php echo $pct; ?>%"><?php echo round($pct); ?>%</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Project Details -->
<div class="row g-3 mb-4">
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom py-3">
                <h5 class="mb-0 fw-semibold">Project Info</h5>
            </div>
            <div class="card-body p-0">
                <table class="table info-table mb-0">
                    <tbody>
                        <tr><th>Code</th><td><code style="background: #f1f5f9; color: #475569; padding: .125rem .5rem; border-radius: 4px; font-size: .85em;"><?php echo sanitize($project['project_code']); ?></code></td></tr>
                        <tr><th>Client</th><td><?php echo sanitize($project['client_name'] ?? '-'); ?></td></tr>
                        <tr><th>Country</th><td><?php echo sanitize($project['country_target'] ?? '-'); ?></td></tr>
                        <tr><th>Client CPI</th><td><?php echo format_currency($project['client_cpi'], $currency); ?></td></tr>
                        <tr><th>Default Vendor Payout</th><td><?php echo format_currency($project['vendor_default_cpi'], $currency); ?></td></tr>
                        <tr><th>Start Date</th><td><?php echo $project['start_date'] ?: '-'; ?></td></tr>
                        <tr><th>End Date</th><td><?php echo $project['end_date'] ?: '-'; ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom py-3">
                <h5 class="mb-0 fw-semibold">Links &amp; Tokens</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Client Postback URL</label>
                    <div class="input-group">
                        <input type="text" class="form-control" style="font-family: ui-monospace, monospace; font-size: .85em;" readonly value="<?php echo sanitize($client_postback); ?>">
                        <button class="btn btn-secondary" data-copy="<?php echo sanitize($client_postback); ?>" aria-label="Copy"><i class="bi bi-clipboard"></i></button>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Postback Token</label>
                    <div class="input-group">
                        <input type="text" class="form-control" style="font-family: ui-monospace, monospace; font-size: .85em;" readonly value="<?php echo sanitize($project['postback_token']); ?>">
                        <button class="btn btn-secondary" data-copy="<?php echo sanitize($project['postback_token']); ?>" aria-label="Copy"><i class="bi bi-clipboard"></i></button>
                    </div>
                </div>
                <div class="mb-0">
                    <label class="form-label small fw-semibold text-secondary">Survey Link</label>
                    <div class="input-group">
                        <input type="text" class="form-control" style="font-family: ui-monospace, monospace; font-size: .85em;" readonly value="<?php echo sanitize($project['client_survey_link'] ?? ''); ?>">
                        <button class="btn btn-secondary" data-copy="<?php echo sanitize($project['client_survey_link'] ?? ''); ?>" aria-label="Copy"><i class="bi bi-clipboard"></i></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chart -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
        <h5 class="mb-0 fw-semibold">Clicks &amp; Conversions</h5>
        <span class="badge bg-light text-dark border">Last 7 Days</span>
    </div>
    <div class="card-body">
        <div style="height: 280px;">
            <canvas id="projectChart"></canvas>
        </div>
    </div>
</div>

<!-- Vendors Table -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
        <h5 class="mb-0 fw-semibold">Vendors</h5>
        <?php if ($project['status'] === 'live'): ?>
        <a href="<?php echo BASE_URL; ?>/vendors/create.php?project_id=<?php echo $id; ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>Add Vendor</a>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-vendors align-middle mb-0">
            <thead>
                <tr>
                    <th>Vendor</th>
                    <th class="text-end">CPI</th>
                    <th>Tracking Link</th>
                    <th class="text-end">Clicks</th>
                    <th class="text-end">Completes</th>
                    <th>CCR</th>
                    <th class="text-end">Revenue</th>
                    <th class="text-end">Cost</th>
                    <th class="text-end">Profit</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vendors)): ?>
                <tr>
                    <td colspan="11" class="text-center py-5 text-muted">
                        <i class="bi bi-people d-block mb-2" style="font-size: 2.5rem; color: #cbd5e1;"></i>
                        <p class="fw-semibold text-dark mb-2">No vendors added yet</p>
                        <?php if ($project['status'] === 'live'): ?>
                        <a href="<?php echo BASE_URL; ?>/vendors/create.php?project_id=<?php echo $id; ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>Add First Vendor</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($vendors as $v):
                    $vs = $vendor_stats[$v['id']] ?? ['clicks'=>0,'completes'=>0,'revenue'=>0,'cost'=>0,'profit'=>0];
                    $vccr = calc_ccr($vs['completes'], $vs['clicks']);
                    $vendor_url = BASE_URL . '/tracking/click.php?project_id=' . $id . '&vendor_id=' . $v['id'];
                ?>
                <tr>
                    <td><strong><?php echo sanitize($v['vendor_name']); ?></strong></td>
                    <td class="text-end"><?php echo format_currency($v['vendor_cpi'], $currency); ?></td>
                    <td>
                        <div class="input-group" style="max-width: 280px;">
                            <input type="text" class="form-control" style="font-family: ui-monospace, monospace; font-size: .8em;" readonly value="<?php echo sanitize($vendor_url); ?>">
                            <button class="btn btn-secondary" data-copy="<?php echo sanitize($vendor_url); ?>" aria-label="Copy"><i class="bi bi-clipboard"></i></button>
                        </div>
                    </td>
                    <td class="text-end"><?php echo number_format($vs['clicks']); ?></td>
                    <td class="text-end"><?php echo number_format($vs['completes']); ?></td>
                    <td><span class="badge bg-<?php echo ccr_color($vccr); ?>"><?php echo $vccr; ?>%</span></td>
                    <td class="text-end"><?php echo format_currency($vs['revenue'], $currency); ?></td>
                    <td class="text-end"><?php echo format_currency($vs['cost'], $currency); ?></td>
                    <td class="text-end fw-semibold <?php echo $vs['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo format_currency($vs['profit'], $currency); ?></td>
                    <td><?php echo status_badge($v['status']); ?></td>
                    <td>
                        <a href="<?php echo BASE_URL; ?>/vendors/edit.php?id=<?php echo $v['id']; ?>" class="btn btn-outline-primary btn-sm" title="Edit vendor" aria-label="Edit vendor"><i class="bi bi-pencil"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Description -->
<?php if ($project['description']): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom py-3">
        <h5 class="mb-0 fw-semibold">Description</h5>
    </div>
    <div class="card-body">
        <p class="mb-0"><?php echo nl2br(sanitize($project['description'])); ?></p>
    </div>
</div>
<?php endif; ?>

<?php
$cl_json = json_encode($chart_labels);
$cc_json = json_encode($chart_clicks);
$cvt_json = json_encode($chart_conversions);

$extra_js = <<<EOT
<script>
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.color = '#64748b';

new Chart(document.getElementById('projectChart'), {
    type: 'bar',
    data: {
        labels: {$cl_json},
        datasets: [
            { label: 'Clicks', data: {$cc_json}, backgroundColor: 'rgba(99, 102, 241, 0.85)', hoverBackgroundColor: 'rgba(79, 70, 229, 1)', borderRadius: 4, borderSkipped: false, barPercentage: 0.6, categoryPercentage: 0.8 },
            { label: 'Conversions', data: {$cvt_json}, backgroundColor: 'rgba(16, 185, 129, 0.85)', hoverBackgroundColor: 'rgba(5, 150, 105, 1)', borderRadius: 4, borderSkipped: false, barPercentage: 0.6, categoryPercentage: 0.8 }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'top', align: 'end', labels: { usePointStyle: true, boxWidth: 8, padding: 20 } } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9', drawBorder: false } }, x: { grid: { display: false, drawBorder: false } } }
    }
});
</script>
EOT;

require_once __DIR__ . '/../helpers/layout_footer.php';
?>
