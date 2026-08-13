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

$vstmt = $pdo->prepare("
    SELECT pv.*, gv.vendor_code, gv.vendor_name, gv.company_name, gv.vendor_status AS master_status,
           gv.traffic_type, gv.default_payout,
           (SELECT sl.code FROM short_links sl WHERE sl.project_id = pv.project_id AND sl.vendor_id = pv.vendor_id LIMIT 1) AS vendor_short_code
    FROM project_vendor pv
    JOIN global_vendors gv ON gv.id = pv.vendor_id
    WHERE pv.project_id = ?
    ORDER BY gv.vendor_name
");
$vstmt->execute([$project_id]);
$vendors = $vstmt->fetchAll();

$vendor_stats = [];
$vs_stmt = $pdo->prepare("
    SELECT pv.vendor_id,
        (SELECT COUNT(*) FROM clicks WHERE vendor_id = pv.vendor_id AND project_id = pv.project_id) AS clicks,
        (SELECT COUNT(*) FROM conversions WHERE vendor_id = pv.vendor_id AND project_id = pv.project_id AND status = 'complete') AS cnt,
        (SELECT COALESCE(SUM(client_revenue),0) FROM conversions WHERE vendor_id = pv.vendor_id AND project_id = pv.project_id AND status = 'complete') AS rev,
        (SELECT COALESCE(SUM(vendor_cost),0)    FROM conversions WHERE vendor_id = pv.vendor_id AND project_id = pv.project_id AND status = 'complete') AS cost,
        (SELECT COALESCE(SUM(profit),0)         FROM conversions WHERE vendor_id = pv.vendor_id AND project_id = pv.project_id AND status = 'complete') AS profit
    FROM project_vendor pv
    WHERE pv.project_id = ?
");
$vs_stmt->execute([$project_id]);
while ($row = $vs_stmt->fetch()) {
    $vendor_stats[$row['vendor_id']] = [
        'clicks' => (int)($row['clicks'] ?? 0),
        'completes' => (int)($row['cnt'] ?? 0),
        'revenue' => (float)($row['rev'] ?? 0),
        'cost' => (float)($row['cost'] ?? 0),
        'profit' => (float)($row['profit'] ?? 0),
    ];
}

$currency = $project['currency'] ?? 'USD';
$page_title = 'Vendors — ' . $project['project_name'];
$page_actions = '
    <a href="' . BASE_URL . '/vendors/create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>Add Vendor</a>
    <a href="' . BASE_URL . '/vendors/global_library.php?project_id=' . $project_id . '" class="btn btn-outline-primary btn-sm"><i class="bi bi-link-45deg"></i>Attach From Library</a>
';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<style>
    .info-banner { background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%); border-left: 4px solid #4f46e5; border-radius: .5rem; padding: 1rem 1.25rem; }

    .vendor-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: .75rem;
        transition: box-shadow .15s ease, border-color .15s ease;
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    .vendor-card:hover { box-shadow: 0 8px 24px rgba(15, 23, 42, .08); border-color: #cbd5e1; }

    .vendor-card-head {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: .75rem;
    }
    .vendor-card-head .vendor-identity { min-width: 0; flex: 1; }
    .vendor-card-head .vendor-name {
        font-weight: 600;
        font-size: 1.05rem;
        color: #0f172a;
        margin: 0;
        line-height: 1.3;
        word-break: break-word;
    }
    .vendor-card-head .vendor-meta {
        font-size: .8rem;
        color: #64748b;
        margin-top: .15rem;
    }
    .vendor-card-head .vendor-meta code {
        background: #f1f5f9;
        color: #475569;
        padding: .1rem .45rem;
        border-radius: 4px;
        font-size: .75rem;
    }

    .vendor-card-body { padding: 1rem 1.25rem; flex: 1; }

    .stat-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: .5rem;
        margin-bottom: .75rem;
    }
    .stat-cell {
        background: #f8fafc;
        border-radius: .5rem;
        padding: .55rem .5rem;
        text-align: center;
    }
    .stat-cell .stat-label {
        font-size: .65rem;
        letter-spacing: .05em;
        text-transform: uppercase;
        color: #64748b;
        font-weight: 600;
        margin-bottom: .15rem;
    }
    .stat-cell .stat-value {
        font-size: 1.05rem;
        font-weight: 600;
        color: #0f172a;
        line-height: 1.1;
    }

    .finance-row {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: .5rem;
        padding: .65rem .5rem;
        background: #f8fafc;
        border-radius: .5rem;
        margin-bottom: 1rem;
    }
    .finance-row .fin-cell { text-align: center; }
    .finance-row .fin-label {
        font-size: .65rem;
        letter-spacing: .05em;
        text-transform: uppercase;
        color: #64748b;
        font-weight: 600;
        margin-bottom: .15rem;
    }
    .finance-row .fin-value { font-size: .9rem; font-weight: 600; color: #0f172a; }
    .finance-row .fin-value.is-profit-positive { color: #047857; }
    .finance-row .fin-value.is-profit-negative { color: #b91c1c; }

    .vendor-link-box {
        display: flex;
        align-items: center;
        gap: .5rem;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-radius: .5rem;
        padding: .45rem .55rem;
    }
    .vendor-link-box .link-url {
        flex: 1;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: .75rem;
        color: #475569;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        min-width: 0;
    }
    .vendor-link-box .btn-copy {
        background: #fff;
        border: 1px solid #cbd5e1;
        color: #475569;
        border-radius: .375rem;
        width: 32px;
        height: 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        cursor: pointer;
        transition: background .15s ease, color .15s ease, border-color .15s ease;
    }
    .vendor-link-box .btn-copy:hover { background: #4f46e5; color: #fff; border-color: #4f46e5; }
    .vendor-link-box .btn-copy.is-copied { background: #047857; color: #fff; border-color: #047857; }

    .vendor-card-foot {
        padding: .85rem 1.25rem;
        border-top: 1px solid #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .5rem;
    }

    .empty-state {
        background: #fff;
        border: 1px dashed #cbd5e1;
        border-radius: .75rem;
        padding: 3rem 1rem;
        text-align: center;
    }
</style>

<div class="mb-3">
    <a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo $project_id; ?>" class="text-decoration-none text-secondary d-inline-flex align-items-center gap-1 small fw-semibold">
        <i class="bi bi-arrow-left"></i>Back to Project
    </a>
</div>

<div class="info-banner mb-3 d-flex align-items-center gap-3 flex-wrap">
    <i class="bi bi-people fs-3 text-primary"></i>
    <div class="flex-grow-1 min-w-0">
        <div class="fw-semibold text-dark"><?php echo sanitize($project['project_name']); ?></div>
        <div class="small text-secondary">
            <code class="me-2"><?php echo sanitize($project['project_code']); ?></code>
            <?php if ($project['total_quota'] > 0): ?>
            Quota: <strong><?php echo number_format($project['completes_count']); ?> / <?php echo number_format($project['total_quota']); ?></strong>
            <?php else: ?>
            Quota: <span class="text-muted">Unlimited</span>
            <?php endif; ?>
        </div>
    </div>
    <a href="<?php echo BASE_URL; ?>/vendors/global.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-grid"></i>All Vendors</a>
</div>

<?php if (empty($vendors)): ?>
<div class="empty-state">
    <i class="bi bi-people d-block mb-3" style="font-size: 3rem; color: #cbd5e1;"></i>
    <p class="fw-semibold text-dark mb-1 fs-5">No vendors attached</p>
    <p class="text-muted small mb-3">Attach vendors from the global library to start sending traffic.</p>
    <a href="<?php echo BASE_URL; ?>/vendors/global_library.php?project_id=<?php echo $project_id; ?>" class="btn btn-primary"><i class="bi bi-link-45deg"></i>Attach From Library</a>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($vendors as $v):
        $vs = $vendor_stats[$v['vendor_id']] ?? ['clicks'=>0,'completes'=>0,'revenue'=>0,'cost'=>0,'profit'=>0];
        $vccr = calc_ccr($vs['completes'], $vs['clicks']);
        $test_link = !empty($v['vendor_short_code'])
            ? (BASE_URL . '/tracking/test.php?c=' . $v['vendor_short_code'])
            : null;
        $profit_class = $vs['profit'] >= 0 ? 'is-profit-positive' : 'is-profit-negative';
    ?>
    <div class="col-12 col-md-6 col-xl-4">
        <div class="vendor-card">
            <div class="vendor-card-head">
                <div class="vendor-identity">
                    <h6 class="vendor-name"><?php echo sanitize($v['vendor_name']); ?></h6>
                    <?php if (!empty($v['vendor_code'])): ?>
                    <div class="vendor-meta"><code><?php echo sanitize($v['vendor_code']); ?></code></div>
                    <?php endif; ?>
                </div>
                <div class="tf-dropdown">
                    <button type="button" class="tf-dropdown-trigger" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for <?php echo sanitize($v['vendor_name']); ?>">
                        <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <div class="tf-dropdown-menu" role="menu" hidden>
                        <a href="<?php echo BASE_URL; ?>/vendors/edit_global.php?id=<?php echo (int)$v['vendor_id']; ?>" class="tf-dropdown-item" role="menuitem">
                            <i class="bi bi-pencil"></i><span>Edit Vendor</span>
                        </a>
                        <?php if ($v['status'] === 'active'): ?>
                        <form method="POST" action="<?php echo BASE_URL; ?>/vendors/status.php">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                            <input type="hidden" name="vendor_id" value="<?php echo (int)$v['vendor_id']; ?>">
                            <input type="hidden" name="action" value="hold">
                            <button type="submit" class="tf-dropdown-item is-warning" role="menuitem" data-confirm="Hold this vendor? Stops recording clicks.">
                                <i class="bi bi-pause-circle"></i><span>Hold Vendor</span>
                            </button>
                        </form>
                        <?php elseif ($v['status'] === 'hold'): ?>
                        <form method="POST" action="<?php echo BASE_URL; ?>/vendors/status.php">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                            <input type="hidden" name="vendor_id" value="<?php echo (int)$v['vendor_id']; ?>">
                            <input type="hidden" name="action" value="active">
                            <button type="submit" class="tf-dropdown-item is-success" role="menuitem">
                                <i class="bi bi-play-circle"></i><span>Resume Vendor</span>
                            </button>
                        </form>
                        <?php endif; ?>
                        <div class="tf-dropdown-separator"></div>
                        <a href="<?php echo BASE_URL; ?>/vendors/detach.php?project_id=<?php echo $project_id; ?>&vendor_id=<?php echo (int)$v['vendor_id']; ?>" class="tf-dropdown-item is-danger" role="menuitem">
                            <i class="bi bi-unlink"></i><span>Detach from Project</span>
                        </a>
                    </div>
                </div>
            </div>

            <div class="vendor-card-body">
                <div class="stat-grid">
                    <div class="stat-cell">
                        <div class="stat-label">Clicks</div>
                        <div class="stat-value"><?php echo number_format($vs['clicks']); ?></div>
                    </div>
                    <div class="stat-cell">
                        <div class="stat-label">Completes</div>
                        <div class="stat-value"><?php echo number_format($vs['completes']); ?></div>
                    </div>
                    <div class="stat-cell">
                        <div class="stat-label">CCR</div>
                        <div class="stat-value"><span class="badge bg-<?php echo ccr_color($vccr); ?>"><?php echo $vccr; ?>%</span></div>
                    </div>
                    <div class="stat-cell">
                        <div class="stat-label">Payout</div>
                        <div class="stat-value"><?php echo format_currency($v['payout'], $currency); ?></div>
                    </div>
                </div>

                <div class="finance-row">
                    <div class="fin-cell">
                        <div class="fin-label">Revenue</div>
                        <div class="fin-value"><?php echo format_currency($vs['revenue'], $currency); ?></div>
                    </div>
                    <div class="fin-cell">
                        <div class="fin-label">Cost</div>
                        <div class="fin-value"><?php echo format_currency($vs['cost'], $currency); ?></div>
                    </div>
                    <div class="fin-cell">
                        <div class="fin-label">Profit</div>
                        <div class="fin-value <?php echo $profit_class; ?>"><?php echo format_currency($vs['profit'], $currency); ?></div>
                    </div>
                </div>

                <div class="vendor-link-box">
                    <span class="link-url" title="<?php echo sanitize($test_link); ?>"><?php echo sanitize($test_link); ?></span>
                    <button class="btn-copy" data-copy="<?php echo sanitize($test_link); ?>" title="Copy test link" aria-label="Copy test link">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>
            </div>

            <div class="vendor-card-foot">
                <div><?php echo status_badge($v['status']); ?></div>
                <div class="small text-muted">
                    <?php if (!empty($v['traffic_type'])): ?>
                    <i class="bi bi-broadcast"></i> <?php echo sanitize($v['traffic_type']); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.btn-copy[data-copy]');
    if (!btn) return;
    const url = btn.getAttribute('data-copy');
    const icon = btn.querySelector('i');
    const reset = () => {
        btn.classList.remove('is-copied');
        if (icon) { icon.classList.remove('bi-check2'); icon.classList.add('bi-clipboard'); }
    };
    const flash = () => {
        btn.classList.add('is-copied');
        if (icon) { icon.classList.remove('bi-clipboard'); icon.classList.add('bi-check2'); }
        setTimeout(reset, 1500);
    };
    const fallback = () => {
        const ta = document.createElement('textarea');
        ta.value = url;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (err) {}
        document.body.removeChild(ta);
        flash();
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(flash).catch(fallback);
    } else {
        fallback();
    }
});
</script>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
