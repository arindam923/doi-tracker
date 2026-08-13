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
    SELECT pv.*, gv.id AS global_vendor_id, gv.vendor_code, gv.vendor_name, gv.company_name,
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
        (SELECT COUNT(*) FROM clicks WHERE vendor_id = pv.vendor_id AND project_id = pv.project_id AND COALESCE(is_test, 0) = 0) AS clicks,
        (SELECT COUNT(*) FROM conversions WHERE vendor_id = pv.vendor_id AND project_id = pv.project_id AND status = 'complete' AND COALESCE(is_test, 0) = 0) AS cnt,
        (SELECT COALESCE(SUM(client_revenue),0) FROM conversions WHERE vendor_id = pv.vendor_id AND project_id = pv.project_id AND status = 'complete' AND COALESCE(is_test, 0) = 0) AS rev,
        (SELECT COALESCE(SUM(vendor_cost),0)    FROM conversions WHERE vendor_id = pv.vendor_id AND project_id = pv.project_id AND status = 'complete' AND COALESCE(is_test, 0) = 0) AS cost,
        (SELECT COALESCE(SUM(profit),0)         FROM conversions WHERE vendor_id = pv.vendor_id AND project_id = pv.project_id AND status = 'complete' AND COALESCE(is_test, 0) = 0) AS profit
    FROM project_vendor pv
    WHERE pv.project_id = ?
");
$vs_stmt->execute([$id]);
while ($row = $vs_stmt->fetch()) {
    $vendor_stats[$row['vendor_id']] = [
        'clicks' => (int)($row['clicks'] ?? 0),
        'completes' => (int)($row['cnt'] ?? 0),
        'revenue' => (float)($row['rev'] ?? 0),
        'cost' => (float)($row['cost'] ?? 0),
        'profit' => (float)($row['profit'] ?? 0),
    ];
}

// Chart data
$chart_click_data = [];
$chart_conv_data = [];
$cc_stmt = $pdo->prepare("SELECT DATE(clicked_at) as d, COUNT(*) as cnt FROM clicks WHERE project_id = ? AND COALESCE(is_test, 0) = 0 AND clicked_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(clicked_at)");
$cc_stmt->execute([$id]);
while ($row = $cc_stmt->fetch()) { $chart_click_data[$row['d']] = $row['cnt']; }

$ccv_stmt = $pdo->prepare("SELECT DATE(converted_at) as d, COUNT(*) as cnt FROM conversions WHERE project_id = ? AND status = 'complete' AND COALESCE(is_test, 0) = 0 AND converted_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(converted_at)");
$ccv_stmt->execute([$id]);
while ($row = $ccv_stmt->fetch()) { $chart_conv_data[$row['d']] = $row['cnt']; }

$chart_labels = []; $chart_clicks = []; $chart_conversions = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chart_labels[] = date('M d', strtotime($d));
    $chart_clicks[] = $chart_click_data[$d] ?? 0;
    $chart_conversions[] = $chart_conv_data[$d] ?? 0;
}

// GEO list
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

$ccr = calc_ccr($project['completes_count'], $project['clicks_count']);
$currency = $project['currency'] ?? ($project['default_currency'] ?? 'USD');

$rev_stmt = $pdo->prepare("SELECT COALESCE(SUM(client_revenue),0) as rev, COALESCE(SUM(vendor_cost),0) as cost, COALESCE(SUM(profit),0) as profit FROM conversions WHERE project_id = ? AND status = 'complete' AND COALESCE(is_test, 0) = 0");
$rev_stmt->execute([$id]);
$rev_data = $rev_stmt->fetch();
$total_revenue = $rev_data['rev'];
$total_cost = $rev_data['cost'];
$total_profit = $rev_data['profit'];

$page_title = $project['project_name'];
$client_postback = BASE_URL . '/tracking/postback.php?click_id={click_id}&status=1&token=' . $project['postback_token'];

$page_actions = '
<div class="d-flex align-items-center gap-2 flex-wrap">
    <button type="button" class="btn btn-outline-secondary btn-sm" data-tf-modal-open="statusModal' . $id . '"><i class="bi bi-toggle2-left"></i>Change Status</button>
    <a href="' . BASE_URL . '/projects/export.php?id=' . $id . '" class="btn btn-success btn-sm"><i class="bi bi-file-earmark-excel"></i>Export Traffic</a>
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
    .note-card { background: #f8fafc; border-left: 4px solid #4f46e5; border-radius: .5rem; padding: 1rem; }
    .kpi-card { border: 1px solid #e2e8f0; border-radius: .875rem; background: #fff; padding: 1rem; }
    .kpi-card .kpi-label { font-size: .7rem; text-transform: uppercase; letter-spacing: .06em; color: #64748b; font-weight: 600; }
    .kpi-card .kpi-value { font-size: 1.25rem; font-weight: 700; color: #0f172a; }
</style>

<!-- Project Info Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100 stat-tile" id="projectStatusTile">
            <div class="stat-label">Status</div>
            <div id="projectStatusBadge"><?php echo status_badge($project['status']); ?></div>
            <div class="small text-muted mt-1" id="projectCampaignStatus"><?php echo sanitize(tf_campaign_status()[$project['campaign_status'] ?? $project['status']] ?? ($project['campaign_status'] ?? $project['status'])); ?></div>
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

<!-- Project Info Cards (2-column layout) -->
<div class="row g-3 mb-4">
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom py-3"><h5 class="mb-0 fw-semibold">Project Info</h5></div>
            <div class="card-body p-0">
                <table class="table info-table mb-0">
                    <tbody>
                        <tr><th>Code</th><td><code style="background: #f1f5f9; color: #475569; padding: .125rem .5rem; border-radius: 4px; font-size: .85em;"><?php echo sanitize($project['project_code']); ?></code></td></tr>
                        <tr><th>Client</th><td><?php echo sanitize($project['client_name'] ?? '-'); ?> (<?php echo sanitize($project['client_code'] ?? '-'); ?>)</td></tr>
                        <tr><th>Currency</th><td><?php echo sanitize($project['currency'] ?? 'USD'); ?></td></tr>
                        <tr><th>Country</th><td><?php echo sanitize($project['country_target'] ?? '-'); ?></td></tr>
                        <tr><th>GEOs</th><td>
                            <?php if (!empty($geo_list)): ?>
                                <?php foreach ($geo_list as $g): ?>
                                <span class="badge bg-light text-dark border me-1 mb-1"><?php echo sanitize($g['country_code']); ?></span>
                                <?php endforeach; ?>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
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
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom py-3"><h5 class="mb-0 fw-semibold">Offer Links &amp; Tokens</h5></div>
            <div class="card-body">

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Landing Page (Client Link)</label>
                    <div class="input-group">
                        <input type="text" class="form-control" style="font-family: ui-monospace, monospace; font-size: .85em;" readonly value="<?php echo sanitize($project['client_survey_link'] ?? ''); ?>">
                        <button class="btn btn-secondary" data-copy="<?php echo sanitize($project['client_survey_link'] ?? ''); ?>" aria-label="Copy"><i class="bi bi-clipboard"></i></button>
                    </div>
                </div>

                <?php if (!empty($project['preview_link'])): ?>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Preview Link</label>
                    <div class="input-group">
                        <input type="text" class="form-control" style="font-family: ui-monospace, monospace; font-size: .85em;" readonly value="<?php echo sanitize($project['preview_link']); ?>">
                        <button class="btn btn-secondary" data-copy="<?php echo sanitize($project['preview_link']); ?>" aria-label="Copy"><i class="bi bi-clipboard"></i></button>
                        <a href="<?php echo sanitize($project['preview_link']); ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary" title="Open preview"><i class="bi bi-box-arrow-up-right"></i></a>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($vendors)): ?>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Vendor-Specific Tracking Links</label>
                    <div class="list-group list-group-flush border rounded">
                        <?php foreach ($vendors as $v):
                            $link = !empty($v['vendor_short_code']) ? BASE_URL . '/c/' . $v['vendor_short_code'] : null;
                        ?>
                        <div class="list-group-item px-3 py-2 d-flex align-items-center gap-2 flex-wrap">
                            <div class="fw-semibold text-dark" style="min-width: 180px;"><?php echo sanitize($v['vendor_name']); ?></div>
                            <?php if ($link): ?>
                                <div class="input-group input-group-sm flex-grow-1">
                                    <input type="text" class="form-control" style="font-family: ui-monospace, monospace; font-size: .75em;" readonly value="<?php echo sanitize($link); ?>">
                                    <button class="btn btn-secondary" data-copy="<?php echo sanitize($link); ?>" aria-label="Copy"><i class="bi bi-clipboard"></i></button>
                                    <a href="<?php echo sanitize($link); ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary" title="Open"><i class="bi bi-box-arrow-up-right"></i></a>
                                    <a href="<?php echo BASE_URL; ?>/tracking/qr.php?c=<?php echo urlencode($v['vendor_short_code']); ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary" title="QR"><i class="bi bi-qr-code"></i></a>
                                </div>
                                <div class="w-100 small text-secondary mt-1">
                                    Test link: <code><?php echo sanitize(BASE_URL . '/tracking/test.php?c=' . $v['vendor_short_code']); ?></code>
                                </div>
                            <?php else: ?>
                                <span class="small text-warning">No opaque link yet — re-save this assignment after the migration.</span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary d-flex align-items-center gap-2">
                        <span>Client Postback URL</span>
                        <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top" title="Per-project. Append this URL to the client's conversion tracking setup."></i>
                    </label>
                    <div class="input-group">
                        <input type="text" class="form-control" style="font-family: ui-monospace, monospace; font-size: .85em;" readonly value="<?php echo sanitize($client_postback); ?>">
                        <button class="btn btn-secondary" data-copy="<?php echo sanitize($client_postback); ?>" aria-label="Copy"><i class="bi bi-clipboard"></i></button>
                    </div>
                    <details class="mt-2"><summary class="small text-muted cursor-pointer">More parameters</summary>
                        <div class="small text-muted mt-2" style="font-family: ui-monospace, monospace;">
                            <code>sale_amount</code>, <code>currency</code>, <code>payout</code>, <code>transaction_id</code>, <code>sub1</code>…<code>sub5</code><br>
                            See <a href="<?php echo BASE_URL; ?>/tracking/help.php" target="_blank">Postback Help</a> for full reference.
                        </div>
                    </details>
                </div>

                <div class="mb-0">
                    <label class="form-label small fw-semibold text-secondary">Postback Token</label>
                    <div class="input-group">
                        <input type="text" class="form-control" style="font-family: ui-monospace, monospace; font-size: .85em;" readonly value="<?php echo sanitize($project['postback_token']); ?>">
                        <button class="btn btn-secondary" data-copy="<?php echo sanitize($project['postback_token']); ?>" aria-label="Copy"><i class="bi bi-clipboard"></i></button>
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
    <div class="card-body"><div style="height: 280px;"><canvas id="projectChart"></canvas></div></div>
</div>

<!-- Vendors Table -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center gap-2 flex-wrap py-3">
        <h5 class="mb-0 fw-semibold">Vendors</h5>
        <?php if ($project['status'] === 'live'): ?>
        <form method="POST" action="<?php echo BASE_URL; ?>/vendors/attach.php" class="d-flex align-items-center gap-2">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="project_id" value="<?php echo $id; ?>">
            <select name="global_vendor_id" class="form-select form-select-sm" style="max-width: 280px;" required>
                <option value="">Select vendor from library…</option>
                <?php foreach ($available_vendors as $av): ?>
                <option value="<?php echo (int)$av['id']; ?>">
                    <?php echo sanitize($av['vendor_name']); ?> (<?php echo sanitize($av['vendor_code']); ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-link-45deg"></i>Attach</button>
            <a href="<?php echo BASE_URL; ?>/vendors/create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>Library</a>
        </form>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-vendors align-middle mb-0">
            <thead>
                <tr>
                    <th>Vendor</th>
                    <th class="text-end">Payout</th>
                    <th>Test Link</th>
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
                        <p class="small text-secondary mb-3">Attach an approved vendor from the library.</p>
                        <a href="<?php echo BASE_URL; ?>/vendors/create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>Add Vendor to Library</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php else: foreach ($vendors as $v):
                    $vs = $vendor_stats[$v['vendor_id']] ?? ['clicks'=>0,'completes'=>0,'revenue'=>0,'cost'=>0,'profit'=>0];
                    $vccr = calc_ccr($vs['completes'], $vs['clicks']);
                    $vlink = !empty($v['vendor_short_code']) ? (BASE_URL . '/c/' . $v['vendor_short_code']) : null;
                ?>
                <tr>
                    <td><strong><?php echo sanitize($v['vendor_name']); ?></strong>
                    <?php if (!empty($v['vendor_code'])): ?><div class="small text-muted"><?php echo sanitize($v['vendor_code']); ?></div><?php endif; ?>
                    </td>
                    <td class="text-end"><?php echo format_currency($v['payout'], $v['currency'] ?? $currency); ?></td>
                    <td>
                        <?php if ($vlink): ?><div class="input-group" style="max-width: 300px;">
                            <input type="text" class="form-control" style="font-family: ui-monospace, monospace; font-size: .75em;" readonly value="<?php echo sanitize($vlink); ?>">
                            <button class="btn btn-secondary" data-copy="<?php echo sanitize($vlink); ?>" aria-label="Copy"><i class="bi bi-clipboard"></i></button>
                            <?php if (!empty($v['vendor_short_code'])): ?>
                            <a href="<?php echo BASE_URL; ?>/tracking/qr.php?c=<?php echo urlencode($v['vendor_short_code']); ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary" title="QR"><i class="bi bi-qr-code"></i></a>
                            <?php endif; ?>
                        </div><?php else: ?><span class="small text-warning">Opaque link pending migration</span><?php endif; ?>
                    </td>
                    <td class="text-end"><?php echo number_format($vs['clicks']); ?></td>
                    <td class="text-end"><?php echo number_format($vs['completes']); ?></td>
                    <td><span class="badge bg-<?php echo ccr_color($vccr); ?>"><?php echo $vccr; ?>%</span></td>
                    <td class="text-end"><?php echo format_currency($vs['revenue'], $currency); ?></td>
                    <td class="text-end"><?php echo format_currency($vs['cost'], $currency); ?></td>
                    <td class="text-end fw-semibold <?php echo $vs['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo format_currency($vs['profit'], $currency); ?></td>
                    <td><?php echo status_badge($v['status']); ?></td>
                    <td>
                        <a href="<?php echo BASE_URL; ?>/vendors/edit_global.php?id=<?php echo (int)$v['global_vendor_id']; ?>" class="btn btn-outline-primary btn-sm" title="Edit vendor"><i class="bi bi-pencil"></i></a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Email Campaign Summary -->
<?php
$ec = $pdo->prepare("
    SELECT COUNT(*) AS campaigns,
           SUM(sent_count) AS sent,
           SUM(opened_count) AS opened,
           SUM(clicked_count) AS clicked,
           SUM(converted_count) AS converted,
           SUM(bounced_count) AS bounced,
           SUM(failed_count) AS failed
    FROM email_campaigns WHERE project_id = ?
");
$ec->execute([$id]);
$ec_stats = $ec->fetch();
?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
        <h5 class="mb-0 fw-semibold"><i class="bi bi-envelope-paper me-1"></i>Email Campaigns</h5>
        <a href="<?php echo BASE_URL; ?>/email/campaigns.php?project_id=<?php echo $id; ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-funnel"></i>View Campaigns</a>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Campaigns</div><div class="kpi-value"><?php echo number_format((int)($ec_stats['campaigns'] ?? 0)); ?></div></div></div>
            <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Sent</div><div class="kpi-value"><?php echo number_format((int)($ec_stats['sent'] ?? 0)); ?></div></div></div>
            <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Opened</div><div class="kpi-value"><?php echo number_format((int)($ec_stats['opened'] ?? 0)); ?></div></div></div>
            <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Clicked</div><div class="kpi-value"><?php echo number_format((int)($ec_stats['clicked'] ?? 0)); ?></div></div></div>
            <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Converted</div><div class="kpi-value"><?php echo number_format((int)($ec_stats['converted'] ?? 0)); ?></div></div></div>
            <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Bounced</div><div class="kpi-value"><?php echo number_format((int)($ec_stats['bounced'] ?? 0)); ?></div></div></div>
            <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Failed</div><div class="kpi-value"><?php echo number_format((int)($ec_stats['failed'] ?? 0)); ?></div></div></div>
        </div>
    </div>
</div>

<!-- Campaign Notes (Item #36) -->
<div class="card border-0 shadow-sm mb-4" id="notes">
    <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
        <h5 class="mb-0 fw-semibold"><i class="bi bi-journal-text me-1"></i>Campaign Notes</h5>
        <span class="badge bg-light text-dark border"><?php echo count($notes); ?></span>
    </div>
    <div class="card-body">
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
            <div class="note-card mb-2">
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
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom py-3"><h5 class="mb-0 fw-semibold">Description</h5></div>
    <div class="card-body"><p class="mb-0"><?php echo nl2br(sanitize($project['description'])); ?></p></div>
</div>
<?php endif; ?>

<div id="statusModal<?php echo $id; ?>" class="tf-modal is-sm" hidden role="dialog" aria-modal="true" aria-labelledby="statusModal<?php echo $id; ?>-title">
    <div class="tf-modal-backdrop" data-tf-modal-close></div>
    <div class="tf-modal-dialog">
        <form method="POST" action="<?php echo BASE_URL; ?>/projects/status.php" class="status-form" data-project-id="<?php echo $id; ?>">
            <input type="hidden" name="project_id" value="<?php echo $id; ?>">
            <?php echo csrf_field(); ?>
            <div class="tf-modal-header">
                <h3 id="statusModal<?php echo $id; ?>-title" class="tf-modal-title">Change Status</h3>
                <button type="button" class="tf-modal-close" data-tf-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="tf-modal-body">
                <label for="new_status_<?php echo $id; ?>" class="form-label small fw-semibold text-secondary">New Status</label>
                <select id="new_status_<?php echo $id; ?>" name="new_status" class="form-select">
                    <?php $current_campaign_status = $project['campaign_status'] ?? $project['status']; ?>
                    <?php foreach (tf_campaign_status() as $status_key => $status_label): ?>
                    <option value="<?php echo sanitize($status_key); ?>" <?php echo $current_campaign_status === $status_key ? 'selected' : ''; ?>><?php echo sanitize($status_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tf-modal-footer">
                <button type="button" class="btn btn-secondary" data-tf-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Update</button>
            </div>
        </form>
    </div>
</div>

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
            { label: 'Clicks', data: {$cc_json}, backgroundColor: 'rgba(99, 102, 241, 0.85)', borderRadius: 4, borderSkipped: false, barPercentage: 0.6, categoryPercentage: 0.8 },
            { label: 'Conversions', data: {$cvt_json}, backgroundColor: 'rgba(16, 185, 129, 0.85)', borderRadius: 4, borderSkipped: false, barPercentage: 0.6, categoryPercentage: 0.8 }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'top', align: 'end' } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
});
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('form.status-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = form.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = 'Updating…';
            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    alert(data.message || 'Failed to update status.');
                    return;
                }
                const modal = form.closest('.tf-modal');
                if (modal) modal.hidden = true;
                const badge = document.getElementById('projectStatusBadge');
                if (badge && data.status_html) badge.innerHTML = data.status_html;
                const campaign = document.getElementById('projectCampaignStatus');
                if (campaign && data.campaign_status_label) campaign.textContent = data.campaign_status_label;
            })
            .catch(() => alert('Failed to update status. Please try again.'))
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = originalText;
            });
        });
    });
});
</script>
EOT;

require_once __DIR__ . '/../helpers/layout_footer.php';
