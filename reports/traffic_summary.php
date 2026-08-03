<?php
require_once __DIR__ . '/../config.php';
require_login();

$from_date = sanitize($_GET['from'] ?? date('Y-m-d', strtotime('-30 days')));
$to_date   = sanitize($_GET['to'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) $from_date = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) $to_date = date('Y-m-d');
$project_filter = intval($_GET['project_id'] ?? 0);

$sql = "SELECT v.id, v.vendor_name, v.vendor_cpi, v.status, p.project_code,
        COUNT(cl.id) as total_clicks,
        SUM(cl.is_converted) as total_converts,
        COALESCE(SUM(cv.client_revenue),0) as revenue,
        COALESCE(SUM(cv.vendor_cost),0) as cost,
        COALESCE(SUM(cv.profit),0) as profit
        FROM vendors v
        JOIN projects p ON v.project_id = p.id
        LEFT JOIN clicks cl ON cl.vendor_id = v.id AND cl.clicked_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
        LEFT JOIN conversions cv ON cv.vendor_id = v.id AND cv.status = 'complete' AND cv.converted_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
        WHERE 1=1";
$params = [$from_date, $to_date, $from_date, $to_date];

if ($project_filter) {
    $sql .= " AND v.project_id = ?";
    $params[] = $project_filter;
}

$sql .= " GROUP BY v.id ORDER BY total_clicks DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vendor_traffic = $stmt->fetchAll();

$projects_list = $pdo->query("SELECT id, project_code, project_name FROM projects ORDER BY project_name")->fetchAll();

$page_title = 'Traffic Summary';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<style>
    .table-traffic thead th { font-size: .7rem; letter-spacing: .06em; text-transform: uppercase; color: #64748b; font-weight: 600; background: #f8fafc; }
    .table-traffic tbody td { vertical-align: middle; padding: .85rem 1rem; }
    .table-traffic code { background: #f1f5f9; color: #475569; padding: .125rem .5rem; border-radius: 4px; font-size: .75rem; }
</style>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <form method="GET" class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label for="from" class="form-label small fw-semibold text-secondary">From</label>
                <input type="date" id="from" name="from" class="form-control" value="<?php echo $from_date; ?>">
            </div>
            <div class="col-12 col-md-3">
                <label for="to" class="form-label small fw-semibold text-secondary">To</label>
                <input type="date" id="to" name="to" class="form-control" value="<?php echo $to_date; ?>">
            </div>
            <div class="col-12 col-md-4">
                <label for="project_id" class="form-label small fw-semibold text-secondary">Project</label>
                <select id="project_id" name="project_id" class="form-select">
                    <option value="">All Projects</option>
                    <?php foreach ($projects_list as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo $project_filter == $p['id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($p['project_code'] . ' — ' . $p['project_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex justify-content-end">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i>Filter</button>
            </div>
        </div>
    </form>
</div>

<!-- Traffic Table -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
        <h5 class="mb-0 fw-semibold">Vendor Traffic Summary</h5>
        <span class="badge bg-light text-dark border"><?php echo $from_date; ?> → <?php echo $to_date; ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-traffic align-middle mb-0">
            <thead>
                <tr>
                    <th>Vendor</th>
                    <th>Project</th>
                    <th class="text-end">CPI</th>
                    <th class="text-end">Clicks</th>
                    <th class="text-end">Completes</th>
                    <th>CCR</th>
                    <th class="text-end">Revenue</th>
                    <th class="text-end">Cost</th>
                    <th class="text-end">Profit</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vendor_traffic)): ?>
                <tr>
                    <td colspan="10" class="text-center py-5 text-muted">
                        <i class="bi bi-bar-chart d-block mb-2" style="font-size: 2.5rem; color: #cbd5e1;"></i>
                        <p class="fw-semibold text-dark mb-0">No traffic data in this period</p>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($vendor_traffic as $vt):
                    $ccr = calc_ccr($vt['total_converts'], $vt['total_clicks']);
                ?>
                <tr>
                    <td><strong><?php echo sanitize($vt['vendor_name']); ?></strong></td>
                    <td><code><?php echo sanitize($vt['project_code']); ?></code></td>
                    <td class="text-end"><?php echo format_currency($vt['vendor_cpi']); ?></td>
                    <td class="text-end"><?php echo number_format($vt['total_clicks']); ?></td>
                    <td class="text-end"><?php echo number_format($vt['total_converts']); ?></td>
                    <td><span class="badge bg-<?php echo ccr_color($ccr); ?>"><?php echo $ccr; ?>%</span></td>
                    <td class="text-end fw-semibold text-success"><?php echo format_currency($vt['revenue']); ?></td>
                    <td class="text-end text-danger"><?php echo format_currency($vt['cost']); ?></td>
                    <td class="text-end fw-semibold <?php echo $vt['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo format_currency($vt['profit']); ?></td>
                    <td><?php echo status_badge($vt['status']); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
