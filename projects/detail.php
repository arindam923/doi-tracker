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

// Ensure short_code is set
if (empty($project['short_code'])) {
    ensure_project_short_code($pdo, $id);
    $project['short_code'] = $pdo->query("SELECT short_code FROM projects WHERE id = $id")->fetchColumn();
}

$vstmt = $pdo->prepare("
    SELECT pv.*, gv.id AS global_vendor_id, gv.vendor_code, gv.vendor_name, gv.company_name, gv.traffic_type,
           (SELECT sl.code FROM short_links sl WHERE sl.project_id = pv.project_id AND sl.vendor_id = pv.vendor_id LIMIT 1) AS vendor_short_code
    FROM project_vendor pv
    JOIN global_vendors gv ON gv.id = pv.vendor_id
    WHERE pv.project_id = ?
    ORDER BY gv.vendor_name
");
$vstmt->execute([$id]);
$vendors = $vstmt->fetchAll();

$vendor_stats = [];
$vs_stmt = $pdo->prepare("
    SELECT pv.vendor_id,
        (SELECT COUNT(*) FROM clicks WHERE vendor_id = pv.vendor_id AND project_id = pv.project_id) AS clicks,
        (SELECT COUNT(*) FROM conversions WHERE vendor_id = pv.vendor_id AND project_id = pv.project_id AND status = 'complete') AS cnt,
        (SELECT COUNT(*) FROM conversions WHERE vendor_id = pv.vendor_id AND project_id = pv.project_id AND status = 'complete' AND DATE(converted_at) = CURDATE()) AS today_cnt,
        (SELECT COALESCE(SUM(client_revenue),0) FROM conversions WHERE vendor_id = pv.vendor_id AND project_id = pv.project_id AND status = 'complete') AS rev,
        (SELECT COALESCE(SUM(vendor_cost),0)    FROM conversions WHERE vendor_id = pv.vendor_id AND project_id = pv.project_id AND status = 'complete') AS cost,
        (SELECT COALESCE(SUM(profit),0)         FROM conversions WHERE vendor_id = pv.vendor_id AND project_id = pv.project_id AND status = 'complete') AS profit
    FROM project_vendor pv
    WHERE pv.project_id = ?
");
$vs_stmt->execute([$id]);
while ($row = $vs_stmt->fetch()) {
    $vendor_stats[$row['vendor_id']] = [
        'clicks' => (int)($row['clicks'] ?? 0),
        'completes' => (int)($row['cnt'] ?? 0),
        'today_completes' => (int)($row['today_cnt'] ?? 0),
        'revenue' => (float)($row['rev'] ?? 0),
        'cost' => (float)($row['cost'] ?? 0),
        'profit' => (float)($row['profit'] ?? 0),
    ];
}

$live_click_stmt = $pdo->prepare("SELECT COUNT(*) FROM clicks WHERE project_id = ?");
$live_click_stmt->execute([$id]);
$live_clicks = (int)$live_click_stmt->fetchColumn();
$live_conv_stmt = $pdo->prepare("SELECT COUNT(*) FROM conversions WHERE project_id = ? AND status = 'complete'");
$live_conv_stmt->execute([$id]);
$live_completes = (int)$live_conv_stmt->fetchColumn();

$mysql_today = substr((string)$pdo->query('SELECT CURDATE()')->fetchColumn(), 0, 10);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $mysql_today)) {
    $mysql_today = date('Y-m-d');
}

$chart_click_data = [];
$cc_stmt = $pdo->prepare("SELECT DATE(clicked_at) AS d, COUNT(*) AS cnt FROM clicks WHERE project_id = ? GROUP BY DATE(clicked_at)");
$cc_stmt->execute([$id]);
while ($row = $cc_stmt->fetch()) {
    $d = substr((string)($row['d'] ?? ''), 0, 10);
    if ($d === '' || $d === '0000-00-00') {
        $d = $mysql_today;
    }
    $chart_click_data[$d] = ($chart_click_data[$d] ?? 0) + (int)$row['cnt'];
}

$chart_conv_data = [];
$ccv_stmt = $pdo->prepare("SELECT DATE(converted_at) AS d, COUNT(*) AS cnt FROM conversions WHERE project_id = ? AND status = 'complete' GROUP BY DATE(converted_at)");
$ccv_stmt->execute([$id]);
while ($row = $ccv_stmt->fetch()) {
    $d = substr((string)($row['d'] ?? ''), 0, 10);
    if ($d === '' || $d === '0000-00-00') {
        $d = $mysql_today;
    }
    $chart_conv_data[$d] = ($chart_conv_data[$d] ?? 0) + (int)$row['cnt'];
}

$chart_labels = []; $chart_clicks = []; $chart_conversions = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime($mysql_today . " -{$i} days"));
    $chart_labels[] = date('M j', strtotime($d));
    $chart_clicks[] = (int)($chart_click_data[$d] ?? 0);
    $chart_conversions[] = (int)($chart_conv_data[$d] ?? 0);
}
if (array_sum($chart_clicks) === 0 && $live_clicks > 0) {
    $chart_clicks[6] = $live_clicks;
}
if (array_sum($chart_conversions) === 0 && $live_completes > 0) {
    $chart_conversions[6] = $live_completes;
}
$chart_max = max(1, (int)max(max($chart_clicks) ?: 0, max($chart_conversions) ?: 0));

$geo_stmt = $pdo->prepare("SELECT country_code, country_name FROM campaign_geo WHERE project_id = ? ORDER BY country_code");
$geo_stmt->execute([$id]);
$geo_list = $geo_stmt->fetchAll();

// Notes
$notes_stmt = $pdo->prepare("
    SELECT n.*, u.username
    FROM campaign_notes n
    LEFT JOIN users u ON n.created_by = u.id
    WHERE n.project_id = ?
    ORDER BY n.created_at DESC
");
$notes_stmt->execute([$id]);
$notes = $notes_stmt->fetchAll();

// Vendors not yet attached to this project (for the attach dropdown)
$av_stmt = $pdo->prepare("
    SELECT gv.id, gv.vendor_code, gv.vendor_name, gv.default_payout, gv.currency
    FROM global_vendors gv
    WHERE gv.vendor_status = 'approved'
      AND gv.id NOT IN (SELECT pv.vendor_id FROM project_vendor pv WHERE pv.project_id = ?)
    ORDER BY gv.vendor_name
");
$av_stmt->execute([$id]);
$available_vendors = $av_stmt->fetchAll();

$display_clicks = $live_clicks;
$display_completes = $live_completes;
$ccr = calc_ccr($display_completes, $display_clicks);
$currency = $project['currency'] ?? ($project['default_currency'] ?? 'USD');
$can_attach = in_array($project['status'], ['live', 'hold'], true);
$campaign_daily_cap = (int)($project['daily_cap'] ?? 0);
$vendor_cap_sum = 0;
foreach ($vendors as $cap_v) {
    if ((int)($cap_v['daily_cap'] ?? 0) > 0) {
        $vendor_cap_sum += (int)$cap_v['daily_cap'];
    }
}
$cap_sum_warning = $campaign_daily_cap > 0 && $vendor_cap_sum > $campaign_daily_cap;
$default_attach_payout = $project['vendor_default_cpi'] ?? 0;

$rev_stmt = $pdo->prepare("SELECT COALESCE(SUM(client_revenue),0) as rev, COALESCE(SUM(vendor_cost),0) as cost, COALESCE(SUM(profit),0) as profit FROM conversions WHERE project_id = ? AND status = 'complete'");
$rev_stmt->execute([$id]);
$rev_data = $rev_stmt->fetch();
$total_revenue = $rev_data['rev'];
$total_cost = $rev_data['cost'];
$total_profit = $rev_data['profit'];

$page_title = $project['project_name'];
$client_postback = BASE_URL . '/tracking/postback.php?click_id={click_id}&status=1&token=' . $project['postback_token'];
$tracking_short = BASE_URL . '/c/' . $project['short_code'];
$test_postback = BASE_URL . '/tracking/test.php?c=' . $project['short_code'];

$page_actions = '
<div class="d-flex align-items-center gap-2 flex-wrap">
    <a href="' . BASE_URL . '/projects/export.php?id=' . $id . '" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download"></i>Export</a>
    <a href="' . BASE_URL . '/tracking/manual.php?project_id=' . $id . '" class="btn btn-outline-secondary btn-sm"><i class="bi bi-plus-circle"></i>Manual</a>
    <a href="' . BASE_URL . '/projects/edit.php?id=' . $id . '" class="btn btn-primary btn-sm"><i class="bi bi-pencil"></i>Edit</a>
    <form method="POST" action="' . BASE_URL . '/projects/clone.php" class="d-inline">
        ' . csrf_field() . '
        <input type="hidden" name="id" value="' . $id . '">
        <button type="submit" class="btn btn-outline-secondary btn-sm" data-confirm="Clone this project?"><i class="bi bi-copy"></i>Clone</button>
    </form>
</div>';

require_once __DIR__ . '/../helpers/layout_header.php';
?>

<div class="tf-project-detail">

<!-- Project Info Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="tf-stat h-100">
            <div class="tf-stat-body">
                <div class="tf-stat-label">Status</div>
                <div><?php echo status_badge($project['status']); ?></div>
                <?php if (!empty($project['campaign_status']) && $project['campaign_status'] !== $project['status']): ?>
                <div class="tf-stat-meta"><?php echo sanitize(tf_campaign_status()[$project['campaign_status']] ?? $project['campaign_status']); ?></div>
                <?php endif; ?>
            </div>
            <div class="tf-stat-icon is-slate" aria-hidden="true"><i class="bi bi-activity"></i></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="tf-stat h-100">
            <div class="tf-stat-body">
                <div class="tf-stat-label">Clicks</div>
                <div class="tf-stat-value"><?php echo number_format($display_clicks); ?></div>
            </div>
            <div class="tf-stat-icon is-indigo" aria-hidden="true"><i class="bi bi-cursor"></i></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="tf-stat h-100">
            <div class="tf-stat-body">
                <div class="tf-stat-label">Completes</div>
                <div class="tf-stat-value"><?php echo number_format($display_completes); ?></div>
            </div>
            <div class="tf-stat-icon is-emerald" aria-hidden="true"><i class="bi bi-check2-circle"></i></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="tf-stat h-100">
            <div class="tf-stat-body">
                <div class="tf-stat-label">CCR</div>
                <div><span class="badge bg-<?php echo ccr_color($ccr); ?>"><?php echo $ccr; ?>%</span></div>
            </div>
            <div class="tf-stat-icon is-amber" aria-hidden="true"><i class="bi bi-percent"></i></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="tf-stat h-100">
            <div class="tf-stat-body">
                <div class="tf-stat-label">Revenue</div>
                <div class="tf-stat-value is-currency is-positive"><?php echo format_currency($total_revenue, $currency); ?></div>
            </div>
            <div class="tf-stat-icon is-emerald" aria-hidden="true"><i class="bi bi-cash-stack"></i></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="tf-stat h-100">
            <div class="tf-stat-body">
                <div class="tf-stat-label">Profit</div>
                <div class="tf-stat-value is-currency <?php echo $total_profit >= 0 ? 'is-positive' : 'is-negative'; ?>"><?php echo format_currency($total_profit, $currency); ?></div>
            </div>
            <div class="tf-stat-icon <?php echo $total_profit >= 0 ? 'is-emerald' : 'is-red'; ?>" aria-hidden="true"><i class="bi bi-graph-up-arrow"></i></div>
        </div>
    </div>
</div>

<!-- Quota Progress -->
<?php if ($project['total_quota'] > 0):
    $pct = min(100, ($display_completes / $project['total_quota']) * 100);
    $barTone = $pct >= 100 ? 'is-danger' : ($pct >= 80 ? 'is-warning' : 'is-success');
?>
<div class="tf-card mb-4">
    <div class="tf-card-body">
        <div class="d-flex justify-content-between mb-2">
            <span class="fw-semibold">Quota Progress</span>
            <span class="small text-muted"><?php echo (int)$display_completes; ?> / <?php echo number_format($project['total_quota']); ?> completes</span>
        </div>
        <div class="tf-progress is-lg" role="progressbar" aria-valuenow="<?php echo (int)round($pct); ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="tf-progress-bar <?php echo $barTone; ?>" style="width: <?php echo $pct; ?>%"><?php echo round($pct); ?>%</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Project Info Cards (2-column layout) -->
<div class="row g-3 mb-4">
    <div class="col-12 col-lg-6">
        <div class="tf-card h-100">
            <div class="tf-card-header"><h5 class="tf-card-title">Project Info</h5></div>
            <div class="card-body p-0">
                <table class="table info-table mb-0">
                    <tbody>
                        <tr><th>Code</th><td><code class="tf-code"><?php echo sanitize($project['project_code']); ?></code></td></tr>
                        <tr><th>Client</th><td><?php echo sanitize($project['client_name'] ?? '-'); ?> (<?php echo sanitize($project['client_code'] ?? '-'); ?>)</td></tr>
                        <tr><th>Currency</th><td><?php echo sanitize($project['currency'] ?? 'USD'); ?></td></tr>
                        <tr><th>Country</th><td>
                            <?php if (!empty($geo_list)): ?>
                                <?php foreach ($geo_list as $g): ?>
                                <span class="badge bg-light text-dark border me-1 mb-1 d-inline-flex align-items-center gap-1">
                                    <?php echo tf_country_flag_html($g['country_code']); ?>
                                    <?php echo sanitize($g['country_code']); ?>
                                </span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php echo sanitize($project['country_target'] ?? '-'); ?>
                            <?php endif; ?>
                        </td></tr>
                        <tr><th>Vertical</th><td><?php echo sanitize($project['vertical'] ?? 'Other'); ?></td></tr>
                        <tr><th>Conversion</th><td><?php echo sanitize($project['conversion_type'] ?? 'SOI'); ?> · <?php echo sanitize($project['target_device'] ?? 'All'); ?></td></tr>
                        <tr><th>Campaign Type</th><td><span class="badge bg-primary"><?php echo sanitize($project['campaign_type'] ?? 'CPL'); ?></span></td></tr>
                        <tr><th>Payout</th><td><?php echo format_currency($project['client_cpi'], $currency); ?></td></tr>
                        <tr><th>Vendor Default</th><td><?php echo format_currency($project['vendor_default_cpi'], $currency); ?></td></tr>
                        <tr><th>Total / Daily Cap</th><td><?php echo $project['total_quota'] > 0 ? number_format($project['total_quota']) : '∞'; ?> / <?php echo ($project['daily_cap'] ?? 0) > 0 ? number_format($project['daily_cap']) : '∞'; ?></td></tr>
                        <tr><th>Visibility</th><td><?php echo sanitize(tf_visibility()[$project['visibility']] ?? $project['visibility']); ?></td></tr>
                        <tr><th>Dates</th><td><?php echo $project['start_date'] ?: '-'; ?> → <?php echo $project['end_date'] ?: '-'; ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="tf-card h-100">
            <div class="tf-card-header"><h5 class="tf-card-title">Offer Links &amp; Tokens</h5></div>
            <div class="tf-card-body">

                <div class="mb-3">
                    <label class="tf-label">Landing Page (Client Link)</label>
                    <div class="input-group tf-link-field">
                        <input type="text" class="form-control" readonly onclick="this.select()" value="<?php echo sanitize($project['client_survey_link'] ?? ''); ?>">
                        <?php echo tf_copy_button($project['client_survey_link'] ?? ''); ?>
                    </div>
                </div>

                <?php if (!empty($project['preview_link'])): ?>
                <div class="mb-3">
                    <label class="tf-label">Preview Link</label>
                    <div class="input-group tf-link-field">
                        <input type="text" class="form-control" readonly onclick="this.select()" value="<?php echo sanitize($project['preview_link']); ?>">
                        <?php echo tf_copy_button($project['preview_link']); ?>
                        <a href="<?php echo sanitize($project['preview_link']); ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary" title="Open preview"><i class="bi bi-box-arrow-up-right"></i></a>
                    </div>
                </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary d-flex align-items-center justify-content-between gap-2">
                        <span>Short Tracking Link <span class="badge bg-light text-dark border">/c/<?php echo sanitize($project['short_code']); ?></span>
                        <span class="text-muted small fw-normal">(routes to a random attached vendor)</span>
                    </label>
                    <div class="input-group tf-link-field">
                        <input type="text" class="form-control" readonly onclick="this.select()" value="<?php echo sanitize($tracking_short); ?>">
                        <?php echo tf_copy_button($tracking_short); ?>
                        <a href="<?php echo sanitize($tracking_short); ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary" title="Open in new tab"><i class="bi bi-box-arrow-up-right"></i></a>
                        <a href="<?php echo BASE_URL; ?>/tracking/qr.php?c=<?php echo urlencode($project['short_code']); ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary" title="Show QR"><i class="bi bi-qr-code"></i></a>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary d-flex align-items-center gap-2">
                        <span>Test Link</span>
                        <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top" title="Validates the link is reachable and shows the bound project/vendor, without consuming a click."></i>
                    </label>
                    <div class="input-group tf-link-field">
                        <input type="text" class="form-control" readonly onclick="this.select()" value="<?php echo sanitize($test_postback); ?>">
                        <?php echo tf_copy_button($test_postback); ?>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary d-flex align-items-center gap-2">
                        <span>Client Postback URL</span>
                        <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top" title="Per-project. Append this URL to the client's conversion tracking setup."></i>
                    </label>
                    <div class="input-group tf-link-field">
                        <input type="text" class="form-control" readonly onclick="this.select()" value="<?php echo sanitize($client_postback); ?>">
                        <?php echo tf_copy_button($client_postback); ?>
                    </div>
                    <details class="mt-2"><summary class="small text-muted cursor-pointer">More parameters</summary>
                        <div class="small text-muted mt-2" style="font-family: ui-monospace, monospace;">
                            <code>sale_amount</code>, <code>currency</code>, <code>payout</code>, <code>transaction_id</code>, <code>sub1</code>…<code>sub5</code><br>
                            See <a href="<?php echo BASE_URL; ?>/tracking/help.php" target="_blank">Postback Help</a> for full reference.
                        </div>
                    </details>
                </div>

                <div class="mb-0">
                    <label class="tf-label">Postback Token</label>
                    <div class="input-group tf-link-field">
                        <input type="text" class="form-control" readonly onclick="this.select()" value="<?php echo sanitize($project['postback_token']); ?>">
                        <?php echo tf_copy_button($project['postback_token']); ?>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Chart -->
<div class="tf-card mb-4">
    <div class="tf-card-header">
        <h5 class="tf-card-title">Clicks &amp; Conversions</h5>
        <span class="badge bg-light">Last 7 Days</span>
    </div>
    <div class="tf-chart-body position-relative" style="height: 280px; min-height: 280px;">
        <canvas id="projectChart" class="w-100 h-100" style="display:none; position:absolute; inset:0;" role="img" aria-label="Project clicks and conversions over the last 7 days"></canvas>
        <div id="projectChartFallback" class="h-100 d-flex flex-column px-3 pb-3">
            <div class="d-flex justify-content-end gap-3 small text-muted mb-2">
                <span><span class="tf-chart-swatch is-clicks"></span>Clicks</span>
                <span><span class="tf-chart-swatch is-conv"></span>Conversions</span>
            </div>
            <div class="d-flex align-items-stretch gap-2 flex-grow-1" style="min-height: 180px;">
                <?php foreach ($chart_labels as $idx => $lab):
                    $ch = (int)$chart_clicks[$idx];
                    $cv = (int)$chart_conversions[$idx];
                    $ch_h = (int)round(($ch / $chart_max) * 100);
                    $cv_h = (int)round(($cv / $chart_max) * 100);
                ?>
                <div class="d-flex flex-column align-items-center flex-fill" style="min-width: 0;">
                    <div class="d-flex align-items-end justify-content-center gap-1 flex-grow-1 w-100" style="height: 160px;">
                        <div title="Clicks: <?php echo $ch; ?>" style="width: 42%; max-width: 18px; height: <?php echo max($ch > 0 ? 8 : 2, $ch_h); ?>%; background: var(--tf-primary-600); border-radius: 4px 4px 0 0;"></div>
                        <div title="Conversions: <?php echo $cv; ?>" style="width: 42%; max-width: 18px; height: <?php echo max($cv > 0 ? 8 : 2, $cv_h); ?>%; background: var(--tf-accent); border-radius: 4px 4px 0 0;"></div>
                    </div>
                    <div class="small text-muted mt-2 text-center" style="font-size: .7rem;"><?php echo sanitize($lab); ?></div>
                    <div class="small fw-semibold"><?php echo $ch; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Vendor Management -->
<div class="tf-card mb-4" id="vendor-management">
    <div class="tf-card-header flex-wrap">
        <div>
            <h5 class="tf-card-title">Vendor Management</h5>
            <p class="tf-card-subtitle">Per-vendor payout, tracking link, daily conversion cap, status, and notes. Campaign daily cap: <?php echo $campaign_daily_cap > 0 ? number_format($campaign_daily_cap) : '∞'; ?> completes.</p>
        </div>
        <?php if ($can_attach): ?>
        <a href="<?php echo BASE_URL; ?>/vendors/create.php?project_id=<?php echo $id; ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>New Vendor</a>
        <?php endif; ?>
    </div>
    <div class="tf-card-body">
        <?php if ($cap_sum_warning): ?>
        <div class="alert alert-warning mb-3 py-2">Vendor daily caps sum to <?php echo number_format($vendor_cap_sum); ?>, which exceeds the campaign daily cap of <?php echo number_format($campaign_daily_cap); ?>. The campaign cap remains the hard ceiling.</div>
        <?php endif; ?>
        <?php if ($can_attach && !empty($available_vendors)): ?>
        <form method="POST" action="<?php echo BASE_URL; ?>/vendors/attach.php" class="row g-2 align-items-end" id="attach-vendor-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="project_id" value="<?php echo $id; ?>">
            <div class="col-12 col-md-4">
                <label class="tf-label">Vendor</label>
                <select name="global_vendor_id" id="attach_global_vendor_id" class="form-select form-select-sm" required>
                    <option value="" data-payout="<?php echo sanitize((string)$default_attach_payout); ?>">Select vendor from library…</option>
                    <?php foreach ($available_vendors as $av):
                        $opt_payout = ($av['default_payout'] > 0) ? $av['default_payout'] : $default_attach_payout;
                    ?>
                    <option value="<?php echo (int)$av['id']; ?>" data-payout="<?php echo sanitize((string)$opt_payout); ?>">
                        <?php echo sanitize($av['vendor_name']); ?> (<?php echo sanitize($av['vendor_code']); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="tf-label" for="attach_payout">Payout</label>
                <input type="number" step="0.01" min="0" name="payout" id="attach_payout" class="form-control form-control-sm" value="<?php echo sanitize((string)$default_attach_payout); ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="tf-label" for="attach_daily_cap">Daily cap (completes)</label>
                <input type="number" min="0" name="daily_cap" id="attach_daily_cap" class="form-control form-control-sm" value="0" placeholder="0 = none">
            </div>
            <div class="col-12 col-md-3">
                <label class="tf-label" for="attach_notes">Notes</label>
                <input type="text" name="notes" id="attach_notes" class="form-control form-control-sm" maxlength="500" placeholder="Optional">
            </div>
            <div class="col-12 col-md-1">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-link-45deg"></i>Attach</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-vendors align-middle mb-0">
            <thead>
                <tr>
                    <th>Vendor</th>
                    <th class="text-end">Payout</th>
                    <th>Tracking Link</th>
                    <th class="text-end">Daily Cap</th>
                    <th class="text-end">Clicks</th>
                    <th class="text-end">Completes</th>
                    <th>CCR</th>
                    <th class="text-end">Revenue</th>
                    <th class="text-end">Cost</th>
                    <th class="text-end">Profit</th>
                    <th>Status</th>
                    <th>Notes</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vendors)): ?>
                <tr>
                    <td colspan="13" class="text-center py-5 text-muted">
                        <i class="bi bi-people d-block mb-2" style="font-size: 2.5rem; color: var(--tf-border-strong);"></i>
                        <p class="fw-semibold text-dark mb-2">No vendors added yet</p>
                        <?php if ($can_attach): ?>
                        <p class="small text-secondary mb-3">Attach a vendor from the form above, or add a new one to the library.</p>
                        <a href="<?php echo BASE_URL; ?>/vendors/create.php?project_id=<?php echo $id; ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>Add First Vendor</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php else: foreach ($vendors as $v):
                    $vs = $vendor_stats[$v['vendor_id']] ?? ['clicks'=>0,'completes'=>0,'today_completes'=>0,'revenue'=>0,'cost'=>0,'profit'=>0];
                    $vccr = calc_ccr($vs['completes'], $vs['clicks']);
                    $vlink = !empty($v['vendor_short_code']) ? (BASE_URL . '/c/' . $v['vendor_short_code']) : (BASE_URL . '/c/' . $project['short_code']);
                    $vcap = (int)($v['daily_cap'] ?? 0);
                    $today_used = (int)($vs['today_completes'] ?? 0);
                ?>
                <tr>
                    <td><strong><?php echo sanitize($v['vendor_name']); ?></strong>
                    <?php if (!empty($v['vendor_code'])): ?><div class="small text-muted"><?php echo sanitize($v['vendor_code']); ?></div><?php endif; ?>
                    </td>
                    <td class="text-end"><?php echo format_currency($v['payout'], $v['currency'] ?? $currency); ?></td>
                    <td>
                        <div class="input-group tf-link-field" style="min-width: 220px; max-width: 320px;">
                            <input type="text" class="form-control" style="font-size: .75em;" readonly onclick="this.select()" value="<?php echo sanitize($vlink); ?>">
                            <?php echo tf_copy_button($vlink); ?>
                            <?php if (!empty($v['vendor_short_code'])): ?>
                            <a href="<?php echo BASE_URL; ?>/tracking/qr.php?c=<?php echo urlencode($v['vendor_short_code']); ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary" title="QR"><i class="bi bi-qr-code"></i></a>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="text-end">
                        <?php if ($vcap > 0): ?>
                            <?php echo number_format($today_used); ?> / <?php echo number_format($vcap); ?>
                        <?php else: ?>
                            <span class="text-muted">∞</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?php echo number_format($vs['clicks']); ?></td>
                    <td class="text-end"><?php echo number_format($vs['completes']); ?></td>
                    <td><span class="badge bg-<?php echo ccr_color($vccr); ?>"><?php echo $vccr; ?>%</span></td>
                    <td class="text-end"><?php echo format_currency($vs['revenue'], $currency); ?></td>
                    <td class="text-end"><?php echo format_currency($vs['cost'], $currency); ?></td>
                    <td class="text-end fw-semibold <?php echo $vs['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo format_currency($vs['profit'], $currency); ?></td>
                    <td><?php echo status_badge($v['status']); ?></td>
                    <td class="small text-secondary" style="max-width: 160px;"><?php
                        $note = trim((string)($v['notes'] ?? ''));
                        echo $note !== '' ? sanitize(strlen($note) > 80 ? substr($note, 0, 77) . '...' : $note) : '—';
                    ?></td>
                    <td class="text-nowrap">
                        <a href="<?php echo BASE_URL; ?>/vendors/assignment_edit.php?project_id=<?php echo $id; ?>&vendor_id=<?php echo (int)$v['vendor_id']; ?>" class="btn btn-outline-primary btn-sm" title="Edit assignment"><i class="bi bi-pencil"></i></a>
                        <?php if ($v['status'] === 'active'): ?>
                        <form method="POST" action="<?php echo BASE_URL; ?>/vendors/status.php" class="d-inline">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="project_id" value="<?php echo $id; ?>">
                            <input type="hidden" name="vendor_id" value="<?php echo (int)$v['vendor_id']; ?>">
                            <input type="hidden" name="action" value="hold">
                            <button type="submit" class="btn btn-outline-warning btn-sm" title="Hold">Hold</button>
                        </form>
                        <?php elseif ($v['status'] === 'hold'): ?>
                        <form method="POST" action="<?php echo BASE_URL; ?>/vendors/status.php" class="d-inline">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="project_id" value="<?php echo $id; ?>">
                            <input type="hidden" name="vendor_id" value="<?php echo (int)$v['vendor_id']; ?>">
                            <input type="hidden" name="action" value="active">
                            <button type="submit" class="btn btn-outline-success btn-sm" title="Activate">Activate</button>
                        </form>
                        <?php endif; ?>
                        <a href="<?php echo BASE_URL; ?>/vendors/detach.php?project_id=<?php echo $id; ?>&vendor_id=<?php echo (int)$v['vendor_id']; ?>" class="btn btn-outline-danger btn-sm" title="Detach"><i class="bi bi-x-lg"></i></a>
                        <?php if (($v['traffic_type'] ?? '') === 'Email'): ?>
                        <a href="<?php echo BASE_URL; ?>/vendors/edit_global.php?id=<?php echo (int)$v['global_vendor_id']; ?>#email-db" class="btn btn-outline-secondary btn-sm" title="Email database"><i class="bi bi-envelope-at"></i></a>
                        <?php endif; ?>
                        <a href="<?php echo BASE_URL; ?>/vendors/edit_global.php?id=<?php echo (int)$v['global_vendor_id']; ?>" class="btn btn-outline-secondary btn-sm" title="Library profile"><i class="bi bi-building"></i></a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Email Marketing -->
<?php
$email_campaigns = $pdo->prepare("
    SELECT ec.*, gv.vendor_name
    FROM email_campaigns ec
    JOIN global_vendors gv ON gv.id = ec.vendor_id
    WHERE ec.project_id = ?
    ORDER BY ec.created_at DESC
");
$email_campaigns->execute([$id]);
$email_campaigns = $email_campaigns->fetchAll();

$send_agg = $pdo->prepare("
    SELECT
        SUM(CASE WHEN status IN ('sent','delivered','opened','clicked','converted','bounced') THEN 1 ELSE 0 END) AS sent,
        SUM(CASE WHEN status IN ('delivered','opened','clicked','converted') THEN 1 ELSE 0 END) AS delivered,
        SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
        SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
        SUM(CASE WHEN converted_at IS NOT NULL THEN 1 ELSE 0 END) AS converted,
        SUM(CASE WHEN status = 'bounced' THEN 1 ELSE 0 END) AS bounced,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
    FROM email_campaign_sends WHERE project_id = ?
");
$send_agg->execute([$id]);
$sa = $send_agg->fetch() ?: [];
$unsub_total = email_count_scalar($pdo, 'SELECT COALESCE(SUM(unsubscribed_count),0) FROM email_campaigns WHERE project_id = ?', [$id]);

$eligible_total = 0;
$pending_total = 0;
foreach ($email_campaigns as &$ec_row) {
    $elig = email_campaign_eligible_count($pdo, $id, (int)$ec_row['vendor_id']);
    $sent_c = email_count_scalar($pdo, "SELECT COUNT(*) FROM email_campaign_sends WHERE campaign_id = ? AND status NOT IN ('queued','skipped')", [(int)$ec_row['id']]);
    $ec_row['_eligible'] = $elig;
    $ec_row['_sent'] = $sent_c;
    $ec_row['_pending'] = max(0, $elig - $sent_c);
    $eligible_total += $elig;
    $pending_total += $ec_row['_pending'];
}
unset($ec_row);
$sent_n = (int)($sa['sent'] ?? 0);
?>
<div class="tf-card mb-4" id="email-campaigns">
    <div class="tf-card-header">
        <h5 class="tf-card-title"><i class="bi bi-envelope-paper me-1"></i>Email Marketing</h5>
        <a href="<?php echo BASE_URL; ?>/email/campaign_create.php?project_id=<?php echo (int)$id; ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>New campaign</a>
    </div>
    <div class="tf-card-body">
        <div class="tf-metric-grid">
            <div class="tf-metric"><span class="tf-metric-label">Eligible</span><span class="tf-metric-value"><?php echo number_format($eligible_total); ?></span></div>
            <div class="tf-metric"><span class="tf-metric-label">Sent</span><span class="tf-metric-value"><?php echo number_format($sent_n); ?></span></div>
            <div class="tf-metric"><span class="tf-metric-label">Pending</span><span class="tf-metric-value"><?php echo number_format($pending_total); ?></span></div>
            <div class="tf-metric"><span class="tf-metric-label">Delivered</span><span class="tf-metric-value"><?php echo number_format((int)($sa['delivered'] ?? 0)); ?></span></div>
            <div class="tf-metric"><span class="tf-metric-label">Open rate</span><span class="tf-metric-value"><?php echo email_pct($sa['opened'] ?? 0, $sent_n); ?></span></div>
            <div class="tf-metric"><span class="tf-metric-label">Click rate</span><span class="tf-metric-value"><?php echo email_pct($sa['clicked'] ?? 0, $sent_n); ?></span></div>
            <div class="tf-metric"><span class="tf-metric-label">Conversions</span><span class="tf-metric-value"><?php echo number_format((int)($sa['converted'] ?? 0)); ?></span></div>
            <div class="tf-metric"><span class="tf-metric-label">Bounce rate</span><span class="tf-metric-value"><?php echo email_pct($sa['bounced'] ?? 0, $sent_n); ?></span></div>
            <div class="tf-metric"><span class="tf-metric-label">Unsubscribes</span><span class="tf-metric-value"><?php echo number_format($unsub_total); ?></span></div>
            <div class="tf-metric"><span class="tf-metric-label">Failed</span><span class="tf-metric-value"><?php echo number_format((int)($sa['failed'] ?? 0)); ?></span></div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Campaign</th>
                    <th>Vendor</th>
                    <th>Status</th>
                    <th class="text-end">Eligible</th>
                    <th class="text-end">Sent</th>
                    <th class="text-end">Pending</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($email_campaigns)): ?>
                <tr><td colspan="7" class="text-center py-4 text-muted">No email campaigns yet. Attach an Email vendor, then create a campaign.</td></tr>
                <?php else: foreach ($email_campaigns as $ec): ?>
                <tr>
                    <td class="fw-semibold"><?php echo sanitize($ec['name']); ?></td>
                    <td><?php echo sanitize($ec['vendor_name']); ?></td>
                    <td><?php echo status_badge($ec['status']); ?></td>
                    <td class="text-end"><?php echo number_format((int)$ec['_eligible']); ?></td>
                    <td class="text-end"><?php echo number_format((int)$ec['_sent']); ?></td>
                    <td class="text-end"><?php echo number_format((int)$ec['_pending']); ?></td>
                    <td class="text-nowrap">
                        <a href="<?php echo BASE_URL; ?>/email/campaign_detail.php?id=<?php echo (int)$ec['id']; ?>" class="btn btn-outline-secondary btn-sm" title="Send log"><i class="bi bi-eye"></i></a>
                        <?php if (in_array($ec['status'], ['draft','paused','scheduled'], true)): ?>
                        <form method="POST" action="<?php echo BASE_URL; ?>/email/campaign_status.php" class="d-inline">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo (int)$ec['id']; ?>">
                            <input type="hidden" name="action" value="<?php echo $ec['status'] === 'paused' ? 'resume' : 'launch'; ?>">
                            <button class="btn btn-outline-success btn-sm" type="submit"><?php echo $ec['status'] === 'paused' ? 'Resume' : 'Launch'; ?></button>
                        </form>
                        <?php elseif ($ec['status'] === 'running'): ?>
                        <form method="POST" action="<?php echo BASE_URL; ?>/email/campaign_status.php" class="d-inline">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo (int)$ec['id']; ?>">
                            <input type="hidden" name="action" value="pause">
                            <button class="btn btn-outline-warning btn-sm" type="submit">Pause</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Campaign Notes (Item #36) -->
<div class="tf-card mb-4" id="notes">
    <div class="tf-card-header">
        <h5 class="tf-card-title"><i class="bi bi-journal-text me-1"></i>Campaign Notes</h5>
        <span class="badge bg-light"><?php echo count($notes); ?></span>
    </div>
    <div class="tf-card-body">
        <form method="POST" action="<?php echo BASE_URL; ?>/projects/notes_add.php" class="mb-3">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="project_id" value="<?php echo $id; ?>">
            <div class="d-flex gap-2">
                <textarea name="body" class="form-control" rows="2" placeholder="Internal note (client instructions, optimization, publisher restrictions)…"></textarea>
                <button type="submit" class="btn btn-primary align-self-start"><i class="bi bi-plus-lg"></i>Add</button>
            </div>
        </form>

        <?php if (empty($notes)): ?>
            <p class="text-muted text-center mb-0">No notes yet. Use this for client instructions, optimization observations, or publisher restrictions.</p>
        <?php else: foreach ($notes as $n): ?>
            <div class="tf-note mb-2">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <strong class="small"><?php echo sanitize($n['username'] ?? 'system'); ?></strong>
                    <span class="text-muted small"><?php echo sanitize($n['created_at']); ?></span>
                </div>
                <p class="mb-1" style="white-space: pre-wrap;"><?php echo sanitize($n['body']); ?></p>
                <form method="POST" action="<?php echo BASE_URL; ?>/projects/notes_delete.php" class="text-end">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo $n['id']; ?>">
                    <input type="hidden" name="project_id" value="<?php echo $id; ?>">
                    <button type="submit" class="btn btn-link btn-sm text-danger p-0" onclick="return confirm('Delete this note?');"><i class="bi bi-trash"></i></button>
                </form>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<!-- Description -->
<?php if ($project['description']): ?>
<div class="tf-card">
    <div class="tf-card-header"><h5 class="tf-card-title">Description</h5></div>
    <div class="tf-card-body"><p class="mb-0"><?php echo nl2br(sanitize($project['description'])); ?></p></div>
</div>
<?php endif; ?>

</div>

<?php
$cl_json = json_encode($chart_labels);
$cc_json = json_encode($chart_clicks);
$cvt_json = json_encode($chart_conversions);

$extra_js = <<<EOT
<script>
(function () {
    var canvas = document.getElementById('projectChart');
    var fallback = document.getElementById('projectChartFallback');
    function drawProjectChart() {
        if (!window.Chart || !canvas) return false;
        canvas.style.display = 'block';
        canvas.style.position = 'absolute';
        canvas.style.inset = '0';
        if (fallback) fallback.style.display = 'none';
        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.color = '#57534e';
        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: {$cl_json},
                datasets: [
                    { label: 'Clicks', data: {$cc_json}, backgroundColor: '#0f766e', borderRadius: 4, borderSkipped: false, barPercentage: 0.6, categoryPercentage: 0.8 },
                    { label: 'Conversions', data: {$cvt_json}, backgroundColor: '#c2410c', borderRadius: 4, borderSkipped: false, barPercentage: 0.6, categoryPercentage: 0.8 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top', align: 'end' } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
        return true;
    }
    if (canvas) canvas.style.display = 'none';
    if (!drawProjectChart()) {
        var tries = 0;
        var t = setInterval(function () {
            tries += 1;
            if (drawProjectChart() || tries > 20) clearInterval(t);
        }, 150);
    }
    var sel = document.getElementById('attach_global_vendor_id');
    var payout = document.getElementById('attach_payout');
    if (sel && payout) {
        sel.addEventListener('change', function () {
            var opt = sel.options[sel.selectedIndex];
            if (opt && opt.getAttribute('data-payout')) {
                payout.value = opt.getAttribute('data-payout');
            }
        });
    }
})();
</script>
EOT;

require_once __DIR__ . '/../helpers/layout_footer.php';
