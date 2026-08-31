<?php
require_once __DIR__ . '/../config.php';
$vendor = require_vendor_login($pdo);
$global_vendor_id = (int)$vendor['global_vendor_id'];

$from_date = tf_vendor_date($_GET['from'] ?? '') ?: date('Y-m-d', strtotime('-29 days'));
$to_date = tf_vendor_date($_GET['to'] ?? '') ?: date('Y-m-d');
if ($from_date > $to_date) [$from_date, $to_date] = [$to_date, $from_date];
$project_filter = max(0, intval($_GET['project_id'] ?? 0));
$approval_filter = trim($_GET['approval'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 25;

$date_clicks = " AND c.clicked_at >= ? AND c.clicked_at < DATE_ADD(?, INTERVAL 1 DAY)";
$date_conversions = " AND cc.converted_at >= ? AND cc.converted_at < DATE_ADD(?, INTERVAL 1 DAY)";
$project_clause = $project_filter ? ' AND p.id = ?' : '';
$project_params = [$from_date, $to_date, $from_date, $to_date, $from_date, $to_date, $global_vendor_id];
if ($project_filter) $project_params[] = $project_filter;

$campaign_stmt = $pdo->prepare("SELECT
        p.id AS project_id, p.project_code, p.project_name, pv.payout, pv.currency,
        sl.code AS short_code,
        (SELECT COUNT(*) FROM clicks c WHERE c.project_id = p.id AND c.vendor_id = pv.vendor_id {$date_clicks}) AS clicks,
        (SELECT COUNT(*) FROM conversions cc WHERE cc.project_id = p.id AND cc.vendor_id = pv.vendor_id AND cc.status = 'complete' {$date_conversions}) AS conversions,
        (SELECT COALESCE(SUM(cc.vendor_cost), 0) FROM conversions cc WHERE cc.project_id = p.id AND cc.vendor_id = pv.vendor_id AND cc.status = 'complete' {$date_conversions}) AS vendor_revenue
    FROM project_vendor pv
    JOIN projects p ON p.id = pv.project_id
    LEFT JOIN short_links sl ON sl.project_id = pv.project_id AND sl.vendor_id = pv.vendor_id
    WHERE " . tf_vendor_assignment_sql('pv') . $project_clause . "
    ORDER BY p.project_name");
$campaign_stmt->execute($project_params);
$campaigns = [];
foreach ($campaign_stmt->fetchAll() as $row) {
    $row['clicks'] = (int)$row['clicks'];
    $row['conversions'] = (int)$row['conversions'];
    $row['conversion_rate'] = calc_ccr($row['conversions'], $row['clicks']);
    $row['vendor_revenue'] = (float)$row['vendor_revenue'];
    $row['tracking_url'] = !empty($row['short_code']) ? BASE_URL . '/c/' . $row['short_code'] : '';
    $row['qr_url'] = !empty($row['short_code']) ? BASE_URL . '/tracking/qr.php?c=' . urlencode($row['short_code']) : '';
    $campaigns[] = tf_vendor_report_fields($row) + ['vendor_revenue' => $row['vendor_revenue']];
}

$totals = ['clicks' => 0, 'conversions' => 0, 'vendor_revenue' => 0.0, 'payout_enabled' => false];
foreach ($campaigns as $campaign) {
    $totals['clicks'] += $campaign['clicks'];
    $totals['conversions'] += $campaign['conversions'];
    if ((float)$campaign['payout'] > 0) $totals['vendor_revenue'] += $campaign['vendor_revenue'];
    $totals['payout_enabled'] = $totals['payout_enabled'] || (float)$campaign['payout'] > 0;
}
$totals['conversion_rate'] = calc_ccr($totals['conversions'], $totals['clicks']);

$pending_stmt = $pdo->prepare("SELECT COUNT(*) FROM conversions cc
    JOIN project_vendor pv ON pv.project_id = cc.project_id AND pv.vendor_id = cc.vendor_id
    WHERE " . tf_vendor_assignment_sql('pv') . " AND cc.status = 'complete' AND cc.approval_status = 'pending'
    AND cc.converted_at >= ? AND cc.converted_at < DATE_ADD(?, INTERVAL 1 DAY)");
$pending_stmt->execute([$global_vendor_id, $from_date, $to_date]);
$pending = (int)$pending_stmt->fetchColumn();

$wheres = ["cc.vendor_id = ?", "pv.status = 'active'", "cc.status = 'complete'", "cc.converted_at >= ?", "cc.converted_at < DATE_ADD(?, INTERVAL 1 DAY)"];
$params = [$global_vendor_id, $from_date, $to_date];
if ($project_filter) { $wheres[] = 'cc.project_id = ?'; $params[] = $project_filter; }
if (in_array($approval_filter, ['pending', 'approved', 'rejected'], true)) { $wheres[] = 'cc.approval_status = ?'; $params[] = $approval_filter; }
$where_sql = implode(' AND ', $wheres);
$cnt = $pdo->prepare("SELECT COUNT(*) FROM conversions cc JOIN project_vendor pv ON pv.project_id = cc.project_id AND pv.vendor_id = cc.vendor_id WHERE {$where_sql}");
$cnt->execute($params);
$pagination = paginate((int)$cnt->fetchColumn(), $per_page, $page);
$conv_stmt = $pdo->prepare("SELECT cc.vendor_cost, cc.currency, cc.approval_status, cc.converted_at, p.project_code
    FROM conversions cc JOIN projects p ON p.id = cc.project_id
    JOIN project_vendor pv ON pv.project_id = cc.project_id AND pv.vendor_id = cc.vendor_id
    WHERE {$where_sql} ORDER BY cc.converted_at DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}");
$conv_stmt->execute($params);
$conversions = $conv_stmt->fetchAll();

$page_title = 'Vendor Dashboard';
require_once __DIR__ . '/../helpers/portal_header.php';
?>
            <form method="GET" class="tf-card mb-4 p-3 d-flex flex-wrap align-items-end gap-3">
                <div><label for="from" class="tf-label">From</label><input id="from" type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($from_date, ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div><label for="to" class="tf-label">To</label><input id="to" type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($to_date, ENT_QUOTES, 'UTF-8'); ?>"></div>
                <button class="btn btn-primary" type="submit"><i class="bi bi-filter" aria-hidden="true"></i> Apply</button>
            </form>

            <div class="row g-3 mb-4">
                <?php foreach ([
                    ['Clicks', number_format($totals['clicks']), 'is-blue', 'bi-cursor-fill'],
                    ['Conversions', number_format($totals['conversions']), 'is-indigo', 'bi-check-circle-fill'],
                    ['Conversion Rate', $totals['conversion_rate'] . '%', 'is-amber', 'bi-percent'],
                    ['Pending', number_format($pending), 'is-slate', 'bi-hourglass-split'],
                ] as $stat): ?>
                <div class="col-6 col-md-3"><article class="tf-stat"><div class="tf-stat-body"><p class="tf-stat-label"><?php echo $stat[0]; ?></p><p class="tf-stat-value"><?php echo $stat[1]; ?></p></div><div class="tf-stat-icon <?php echo $stat[2]; ?>"><i class="bi <?php echo $stat[3]; ?>" aria-hidden="true"></i></div></article></div>
                <?php endforeach; ?>
                <?php if ($totals['payout_enabled']): ?><div class="col-6 col-md-3"><article class="tf-stat"><div class="tf-stat-body"><p class="tf-stat-label">Payout</p><p class="tf-stat-value is-currency is-positive"><?php echo format_currency($totals['vendor_revenue']); ?></p></div><div class="tf-stat-icon is-emerald"><i class="bi bi-arrow-up-circle-fill" aria-hidden="true"></i></div></article></div><?php endif; ?>
            </div>

            <section class="tf-card mb-4" aria-labelledby="campaign-performance-title">
                <div class="tf-card-header"><div><h5 id="campaign-performance-title" class="tf-card-title">Campaign Performance</h5><p class="tf-card-subtitle">Your active campaign assignments from <?php echo htmlspecialchars($from_date, ENT_QUOTES, 'UTF-8'); ?> to <?php echo htmlspecialchars($to_date, ENT_QUOTES, 'UTF-8'); ?>.</p></div></div>
                <div class="tf-table-scroll"><table class="tf-table"><thead><tr><th>Campaign</th><th>Tracking Link</th><th class="is-numeric">Clicks</th><th class="is-numeric">Conversions</th><th class="is-numeric">Rate</th><?php if ($totals['payout_enabled']): ?><th class="is-numeric">Payout</th><?php endif; ?><th>QR</th></tr></thead><tbody>
                <?php if (!$campaigns): ?><tr class="is-empty"><td colspan="<?php echo $totals['payout_enabled'] ? 7 : 6; ?>" class="text-center py-5 text-muted">No active campaigns assigned for this period.</td></tr><?php else: foreach ($campaigns as $campaign): ?><tr>
                    <td><div class="fw-semibold"><?php echo htmlspecialchars($campaign['project_code'], ENT_QUOTES, 'UTF-8'); ?></div><div class="small text-secondary"><?php echo htmlspecialchars($campaign['project_name'], ENT_QUOTES, 'UTF-8'); ?></div></td>
                    <td><?php if ($campaign['tracking_url']): ?><div class="input-group input-group-sm" style="min-width:240px"><input class="form-control" readonly value="<?php echo htmlspecialchars($campaign['tracking_url'], ENT_QUOTES, 'UTF-8'); ?>" onclick="this.select()"><button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)" aria-label="Copy tracking link"><i class="bi bi-copy"></i></button></div><?php else: ?><span class="text-muted">Not available</span><?php endif; ?></td>
                    <td class="is-numeric"><?php echo number_format($campaign['clicks']); ?></td><td class="is-numeric"><?php echo number_format($campaign['conversions']); ?></td><td class="is-numeric"><?php echo $campaign['conversion_rate']; ?>%</td>
                    <?php if ($totals['payout_enabled']): ?><td class="is-numeric"><?php echo (float)$campaign['payout'] > 0 ? format_currency($campaign['vendor_revenue'], $campaign['currency'] ?: 'USD') : '—'; ?></td><?php endif; ?>
                    <td><?php if ($campaign['qr_url']): ?><a class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener" href="<?php echo htmlspecialchars($campaign['qr_url'], ENT_QUOTES, 'UTF-8'); ?>" title="Show QR code"><i class="bi bi-qr-code"></i></a><?php else: ?>—<?php endif; ?></td>
                </tr><?php endforeach; endif; ?></tbody></table></div>
            </section>

            <section class="tf-card" aria-labelledby="recent-conversions-title"><div class="tf-card-header"><h5 id="recent-conversions-title" class="tf-card-title">Recent Conversions</h5><form method="GET" class="d-flex gap-2"><input type="hidden" name="from" value="<?php echo htmlspecialchars($from_date, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="to" value="<?php echo htmlspecialchars($to_date, ENT_QUOTES, 'UTF-8'); ?>"><?php if ($project_filter): ?><input type="hidden" name="project_id" value="<?php echo $project_filter; ?>"><?php endif; ?><label for="approval-filter" class="tf-visually-hidden">Filter by approval status</label><select id="approval-filter" name="approval" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">All statuses</option><option value="pending" <?php echo $approval_filter === 'pending' ? 'selected' : ''; ?>>Pending</option><option value="approved" <?php echo $approval_filter === 'approved' ? 'selected' : ''; ?>>Approved</option><option value="rejected" <?php echo $approval_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option></select></form></div><div class="tf-table-scroll"><table class="tf-table"><thead><tr><th>Campaign</th><?php if ($totals['payout_enabled']): ?><th class="is-numeric">Payout</th><?php endif; ?><th>Status</th><th>Time</th></tr></thead><tbody><?php if (!$conversions): ?><tr class="is-empty"><td colspan="<?php echo $totals['payout_enabled'] ? 4 : 3; ?>" class="text-center py-5 text-muted">No conversions in this view.</td></tr><?php else: foreach ($conversions as $conversion): ?><tr><td><?php echo htmlspecialchars($conversion['project_code'], ENT_QUOTES, 'UTF-8'); ?></td><?php if ($totals['payout_enabled']): ?><td class="is-numeric text-success"><?php echo format_currency($conversion['vendor_cost'], $conversion['currency'] ?: 'USD'); ?></td><?php endif; ?><td><?php $cls = $conversion['approval_status'] === 'approved' ? 'bg-success' : ($conversion['approval_status'] === 'rejected' ? 'bg-danger' : 'bg-warning'); ?><span class="badge <?php echo $cls; ?>"><?php echo ucfirst($conversion['approval_status']); ?></span></td><td class="small text-secondary"><?php echo htmlspecialchars(date('M j, H:i', strtotime($conversion['converted_at'])), ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach; endif; ?></tbody></table></div><div class="tf-card-footer"><?php echo render_pagination($pagination, BASE_URL . '/vendor_portal/index.php?' . http_build_query($_GET)); ?></div></section>
        </div>
    </main>
</body>
</html>
