<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$project_id = intval($_GET['project_id'] ?? 0);
if (!$project_id) redirect(BASE_URL . '/projects/list.php');

$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) {
    set_flash('danger', 'Project not found.');
    redirect(BASE_URL . '/projects/list.php');
}

$vstmt = $pdo->prepare("SELECT * FROM vendors WHERE project_id = ? ORDER BY vendor_name");
$vstmt->execute([$project_id]);
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
$vs_stmt->execute([$project_id]);
while ($row = $vs_stmt->fetch()) {
    $vendor_stats[$row['id']] = [
        'clicks' => $row['clicks'] ?? 0,
        'completes' => $row['cnt'] ?? 0,
        'revenue' => $row['rev'] ?? 0,
        'cost' => $row['cost'] ?? 0,
        'profit' => $row['profit'] ?? 0,
    ];
}

$currency = $project['default_currency'] ?? 'USD';
$page_title = 'Vendors — ' . $project['project_name'];
$page_actions = '<a href="' . BASE_URL . '/vendors/create.php?project_id=' . $project_id . '" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>Add Vendor</a>';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<style>
    .table-vendors thead th { font-size: .7rem; letter-spacing: .06em; text-transform: uppercase; color: #64748b; font-weight: 600; background: #f8fafc; }
    .table-vendors tbody td { vertical-align: middle; padding: .85rem 1rem; }
    .table-vendors code { background: #f1f5f9; color: #475569; padding: .125rem .5rem; border-radius: 4px; font-size: .75rem; }
</style>

<div class="mb-3">
    <a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo $project_id; ?>" class="text-decoration-none text-secondary d-inline-flex align-items-center gap-1 small fw-semibold">
        <i class="bi bi-arrow-left"></i>Back to Project
    </a>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover table-vendors align-middle mb-0">
            <thead>
                <tr>
                    <th>Vendor Name</th>
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
                        <p class="fw-semibold text-dark mb-1">No vendors yet</p>
                        <p class="small mb-3">Add vendors to start sending traffic.</p>
                        <a href="<?php echo BASE_URL; ?>/vendors/create.php?project_id=<?php echo $project_id; ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>Add First Vendor</a>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($vendors as $v):
                    $vs = $vendor_stats[$v['id']] ?? ['clicks'=>0,'completes'=>0,'revenue'=>0,'cost'=>0,'profit'=>0];
                    $vccr = calc_ccr($vs['completes'], $vs['clicks']);
                    $tracking_link = BASE_URL . '/tracking/click.php?project_id=' . $project_id . '&vendor_id=' . $v['id'];
                ?>
                <tr>
                    <td>
                        <strong><?php echo sanitize($v['vendor_name']); ?></strong>
                        <?php if (!empty($v['contact_info'])): ?>
                        <div class="small text-muted mt-1"><?php echo sanitize($v['contact_info']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?php echo format_currency($v['vendor_cpi'], $currency); ?></td>
                    <td>
                        <div class="input-group" style="max-width: 260px;">
                            <input type="text" class="form-control" style="font-family: ui-monospace, monospace; font-size: .8em;" readonly value="<?php echo sanitize($tracking_link); ?>">
                            <button class="btn btn-secondary" data-copy="<?php echo sanitize($tracking_link); ?>" aria-label="Copy tracking link"><i class="bi bi-clipboard"></i></button>
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
                        <div class="d-flex align-items-center gap-1">
                            <a href="<?php echo BASE_URL; ?>/vendors/edit.php?id=<?php echo $v['id']; ?>" class="btn btn-outline-primary btn-sm" title="Edit vendor" aria-label="Edit vendor"><i class="bi bi-pencil"></i></a>
                            <?php if ($v['status'] === 'active'): ?>
                            <form method="POST" action="<?php echo BASE_URL; ?>/vendors/status.php">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="id" value="<?php echo $v['id']; ?>">
                                <input type="hidden" name="action" value="paused">
                                <button type="submit" class="btn btn-outline-secondary btn-sm" title="Pause vendor" data-confirm="Pause this vendor?"><i class="bi bi-pause"></i></button>
                            </form>
                            <?php elseif ($v['status'] === 'paused'): ?>
                            <form method="POST" action="<?php echo BASE_URL; ?>/vendors/status.php">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="id" value="<?php echo $v['id']; ?>">
                                <input type="hidden" name="action" value="active">
                                <button type="submit" class="btn btn-outline-success btn-sm" title="Resume vendor"><i class="bi bi-play"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
