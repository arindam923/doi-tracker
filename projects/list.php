<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

// ─── Filters ───
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$client_filter = intval($_GET['client_id'] ?? 0);
$campaign_type_filter = $_GET['campaign_type'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;

$where = [];
$params = [];

if ($search) {
    $where[] = "(p.project_code LIKE ? OR p.project_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status_filter && in_array($status_filter, ['live','hold','closed','archived'])) {
    $where[] = "p.status = ?";
    $params[] = $status_filter;
}
if ($client_filter) {
    $where[] = "p.client_id = ?";
    $params[] = $client_filter;
}
if ($campaign_type_filter && in_array($campaign_type_filter, ['CPL','CPC','CPA'], true)) {
    $where[] = "p.campaign_type = ?";
    $params[] = $campaign_type_filter;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count_stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM projects p $where_sql");
$count_stmt->execute($params);
$total = $count_stmt->fetch()['cnt'];

$pagination = paginate($total, $per_page, $page);

$stmt = $pdo->prepare("
    SELECT p.*, c.client_name
    FROM projects p
    LEFT JOIN clients c ON p.client_id = c.id
    $where_sql
    ORDER BY p.updated_at DESC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
");
$stmt->execute($params);
$projects = $stmt->fetchAll();

$clients_list = $pdo->query("SELECT id, client_name FROM clients WHERE is_active = 1 ORDER BY client_name")->fetchAll();

$project_vendor_map = [];
if (!empty($projects)) {
    $placeholders = implode(',', array_fill(0, count($projects), '?'));
    $vstmt = $pdo->prepare("SELECT pv.project_id, pv.vendor_id AS id, gv.vendor_name, pv.status, sl.code AS vendor_short_code FROM project_vendor pv JOIN global_vendors gv ON gv.id = pv.vendor_id LEFT JOIN short_links sl ON sl.project_id = pv.project_id AND sl.vendor_id = pv.vendor_id WHERE pv.project_id IN ($placeholders) ORDER BY gv.vendor_name");
    $vstmt->execute(array_column($projects, 'id'));
    while ($v = $vstmt->fetch()) {
        $project_vendor_map[$v['project_id']][] = $v;
    }
}

$page_title = 'Projects';
$page_actions = '<a href="' . BASE_URL . '/projects/create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>New Project</a>';
require_once __DIR__ . '/../helpers/layout_header.php';
?>


<!-- Filters -->
<div class="tf-card mb-4">
    <form method="GET" class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label for="search" class="tf-label">Search</label>
                <input type="text" id="search" name="search" class="form-control" placeholder="Search by code or name..."
                       value="<?php echo sanitize($search); ?>">
            </div>
            <div class="col-6 col-md-2">
                <label for="status" class="tf-label">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All Status</option>
                                    <option value="live" <?php echo $status_filter === 'live' ? 'selected' : ''; ?>>Live</option>
                                    <option value="hold" <?php echo $status_filter === 'hold' ? 'selected' : ''; ?>>Hold</option>
                                    <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                    <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="campaign_type" class="tf-label">Campaign Type</label>
                <select id="campaign_type" name="campaign_type" class="form-select">
                    <option value="">All Types</option>
                    <option value="CPL" <?php echo $campaign_type_filter === 'CPL' ? 'selected' : ''; ?>>CPL</option>
                    <option value="CPC" <?php echo $campaign_type_filter === 'CPC' ? 'selected' : ''; ?>>CPC</option>
                    <option value="CPA" <?php echo $campaign_type_filter === 'CPA' ? 'selected' : ''; ?>>CPA</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label for="client_id" class="tf-label">Client</label>
                <select id="client_id" name="client_id" class="form-select">
                    <option value="">All Clients</option>
                    <?php foreach ($clients_list as $cl): ?>
                    <option value="<?php echo $cl['id']; ?>" <?php echo $client_filter == $cl['id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($cl['client_name']); ?>
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

<!-- Projects Table -->
<div class="tf-card">
    <div class="table-responsive">
        <table class="table table-hover table-projects align-middle mb-0">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Project Name</th>
                    <th>Client</th>
                    <th>Status</th>
                    <th>Quota</th>
                    <th class="text-end">Clicks</th>
                    <th class="text-end">Completes</th>
                    <th>CCR</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($projects)): ?>
                <tr>
                    <td colspan="9" class="text-center py-5 text-muted">
                        <i class="bi bi-folder2-open d-block mb-2" style="font-size: 2.5rem; color: #cbd5e1;"></i>
                        <p class="fw-semibold text-dark mb-1">No projects found</p>
                        <p class="small mb-3">Create your first project to start tracking.</p>
                        <a href="<?php echo BASE_URL; ?>/projects/create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>New Project</a>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($projects as $p): ?>
                <?php $ccr = calc_ccr($p['completes_count'], $p['clicks_count']); ?>
                <tr id="projectRow<?php echo $p['id']; ?>">
                    <td>
                        <code class="project-code"><?php echo sanitize($p['project_code']); ?></code>
                        <div class="mt-1"><span class="badge bg-primary" style="font-size: .65rem;"><?php echo sanitize($p['campaign_type'] ?? 'CPL'); ?></span></div>
                    </td>
                    <td>
                        <a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo $p['id']; ?>" class="project-name">
                            <?php echo sanitize($p['project_name']); ?>
                        </a>
                    </td>
                    <td><?php echo sanitize($p['client_name'] ?? '-'); ?></td>
                    <td class="status-cell"><?php echo status_badge($p['status']); ?></td>
                    <td>
                        <?php if ($p['total_quota'] > 0):
                            $pct = min(100, ($p['completes_count'] / $p['total_quota']) * 100);
                            $barColor = $p['completes_count'] >= $p['total_quota'] ? 'bg-danger' : ($p['completes_count'] >= $p['total_quota'] * 0.8 ? 'bg-warning' : 'bg-success');
                        ?>
                        <div class="d-flex align-items-center gap-2">
                            <div class="progress progress-quota" role="progressbar" aria-label="Quota">
                                <div class="progress-bar <?php echo $barColor; ?>" style="width: <?php echo $pct; ?>%"></div>
                            </div>
                            <span class="small text-muted" style="font-family: ui-monospace, monospace; font-size: .75rem;"><?php echo (int)$p['completes_count']; ?>/<?php echo (int)$p['total_quota']; ?></span>
                        </div>
                        <?php else: ?>
                        <span class="text-muted small">No limit</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?php echo number_format($p['clicks_count']); ?></td>
                    <td class="text-end"><?php echo number_format($p['completes_count']); ?></td>
                    <td><span class="badge bg-<?php echo ccr_color($ccr); ?>"><?php echo $ccr; ?>%</span></td>
                    <td class="text-end">
                        <div class="tf-dropdown">
                            <button type="button" class="tf-dropdown-trigger" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for <?php echo sanitize($p['project_name']); ?>">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <div class="tf-dropdown-menu" role="menu" hidden>
                                <a href="<?php echo BASE_URL; ?>/projects/edit.php?id=<?php echo $p['id']; ?>" class="tf-dropdown-item" role="menuitem">
                                    <i class="bi bi-pencil"></i><span>Edit</span>
                                </a>
                                <form method="POST" action="<?php echo BASE_URL; ?>/projects/clone.php">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                    <button type="submit" class="tf-dropdown-item" role="menuitem" data-confirm="Clone this project?">
                                        <i class="bi bi-copy"></i><span>Clone</span>
                                    </button>
                                </form>
                                <div class="tf-dropdown-divider"></div>
                                <button type="button" class="tf-dropdown-item" role="menuitem" data-tf-modal-open="statusModal<?php echo $p['id']; ?>">
                                    <i class="bi bi-toggle2-left"></i><span>Change Status</span>
                                </button>
                                <button type="button" class="tf-dropdown-item" role="menuitem" data-tf-modal-open="linksModal<?php echo $p['id']; ?>">
                                    <i class="bi bi-link-45deg"></i><span>View Links</span>
                                </button>
                                <a href="<?php echo BASE_URL; ?>/vendors/list.php?project_id=<?php echo $p['id']; ?>" class="tf-dropdown-item" role="menuitem">
                                    <i class="bi bi-people"></i><span>Vendors</span>
                                </a>
                                <a href="<?php echo BASE_URL; ?>/reports/overview.php?project_id=<?php echo $p['id']; ?>" class="tf-dropdown-item" role="menuitem">
                                    <i class="bi bi-graph-up"></i><span>Report</span>
                                </a>
                                <a href="<?php echo BASE_URL; ?>/projects/export.php?id=<?php echo $p['id']; ?>" class="tf-dropdown-item" role="menuitem">
                                    <i class="bi bi-file-earmark-excel"></i><span>Export Traffic (XLSX)</span>
                                </a>
                                <div class="tf-dropdown-divider"></div>
                                <form method="POST" action="<?php echo BASE_URL; ?>/projects/delete.php">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                    <button type="submit" class="tf-dropdown-item is-danger" role="menuitem" data-confirm="Delete this project? This cannot be undone.">
                                        <i class="bi bi-trash"></i><span>Delete</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Project Modals -->
<?php if (!empty($projects)): ?>
<?php foreach ($projects as $p): ?>
<!-- Status Modal -->
<div id="statusModal<?php echo $p['id']; ?>" class="tf-modal is-sm" hidden role="dialog" aria-modal="true" aria-labelledby="statusModal<?php echo $p['id']; ?>-title">
    <div class="tf-modal-backdrop" data-tf-modal-close></div>
    <div class="tf-modal-dialog">
        <form method="POST" action="<?php echo BASE_URL; ?>/projects/status.php" class="status-form" data-project-id="<?php echo $p['id']; ?>">
            <input type="hidden" name="project_id" value="<?php echo $p['id']; ?>">
            <?php echo csrf_field(); ?>
            <div class="tf-modal-header">
                <h3 id="statusModal<?php echo $p['id']; ?>-title" class="tf-modal-title">Change Status</h3>
                <button type="button" class="tf-modal-close" data-tf-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="tf-modal-body">
                <div class="mb-3">
                    <label for="new_status_<?php echo $p['id']; ?>" class="tf-label">New Status</label>
                    <select id="new_status_<?php echo $p['id']; ?>" name="new_status" class="form-select">
                        <option value="live" <?php echo $p['status'] === 'live' ? 'selected' : ''; ?>>🟢 Live</option>
                        <option value="hold" <?php echo $p['status'] === 'hold' ? 'selected' : ''; ?>>🟡 Hold</option>
                        <option value="closed" <?php echo $p['status'] === 'closed' ? 'selected' : ''; ?>>🔴 Closed</option>
                        <option value="archived" <?php echo $p['status'] === 'archived' ? 'selected' : ''; ?>>📦 Archived</option>
                    </select>
                </div>
            </div>
            <div class="tf-modal-footer">
                <button type="button" class="btn btn-secondary" data-tf-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Update</button>
            </div>
        </form>
    </div>
</div>

<!-- Links Modal -->
<?php
$client_postback = BASE_URL . '/tracking/postback.php?click_id={click_id}&status=1&token=' . $p['postback_token'];
$project_vendors = $project_vendor_map[$p['id']] ?? [];
?>
<div id="linksModal<?php echo $p['id']; ?>" class="tf-modal" hidden role="dialog" aria-modal="true" aria-labelledby="linksModal<?php echo $p['id']; ?>-title">
    <div class="tf-modal-backdrop" data-tf-modal-close></div>
    <div class="tf-modal-dialog">
        <div class="tf-modal-header">
            <h3 id="linksModal<?php echo $p['id']; ?>-title" class="tf-modal-title">Project Links — <?php echo sanitize($p['project_name']); ?></h3>
            <button type="button" class="tf-modal-close" data-tf-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="tf-modal-body">
            <div class="mb-3">
                <label class="form-label small fw-semibold text-secondary d-flex align-items-center gap-2">
                    <span>Client Postback URL</span>
                    <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top" title="This postback URL is unique per project — the token segment identifies the campaign."></i>
                </label>
                <div class="input-group">
                    <input type="text" class="form-control" style="font-family: ui-monospace, monospace; font-size: .85em;" readonly onclick="this.select()" value="<?php echo sanitize($client_postback); ?>">
                    <?php echo tf_copy_button($client_postback); ?>
                </div>
            </div>
            <div class="mb-3">
                <label class="tf-label">Postback Token</label>
                <div class="input-group">
                    <input type="text" class="form-control" style="font-family: ui-monospace, monospace; font-size: .85em;" readonly onclick="this.select()" value="<?php echo sanitize($p['postback_token']); ?>">
                    <?php echo tf_copy_button($p['postback_token']); ?>
                </div>
            </div>

            <?php if ($project_vendors): ?>
            <hr>
            <h6 class="fw-semibold mb-3">Vendor Test Links</h6>
            <?php foreach ($project_vendors as $v):
                $vendor_url = !empty($v['vendor_short_code']) ? tracking_public_url(BASE_URL, $v['vendor_short_code']) : '';
            ?>
            <div class="mb-3">
                <label class="form-label small fw-semibold text-secondary d-flex align-items-center gap-2">
                    <span><?php echo sanitize($v['vendor_name']); ?></span>
                    <?php echo status_badge($v['status']); ?>
                </label>
                <div class="input-group">
                    <input type="text" class="form-control" style="font-family: ui-monospace, monospace; font-size: .85em;" readonly onclick="this.select()" value="<?php echo sanitize($vendor_url ?: 'Unavailable — attach vendor to generate link'); ?>">
                    <?php if ($vendor_url): ?><?php echo tf_copy_button($vendor_url); ?><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="tf-modal-footer">
            <button type="button" class="btn btn-secondary" data-tf-modal-close>Close</button>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php
echo render_pagination($pagination, BASE_URL . '/projects/list.php');

$extra_js = <<<EOT
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ── Status modal AJAX submit (Item #9: stay on list page) ──
    document.querySelectorAll('form.status-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const projectId = form.dataset.projectId;
            const btn = form.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Updating…';

            const formData = new FormData(form);
            fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // Close the modal
                    const modal = form.closest('.tf-modal');
                    if (modal) modal.hidden = true;
                    // Update the status badge in the row
                    const row = document.getElementById('projectRow' + projectId);
                    if (row) {
                        const badgeCell = row.querySelector('.status-cell');
                        if (badgeCell) {
                            const newStatus = form.querySelector('select[name="new_status"]').value;
                            const colorMap = { live: 'success', hold: 'warning', closed: 'danger', archived: 'light' };
                            const cls = colorMap[newStatus] || 'light';
                            badgeCell.innerHTML = '<span class="badge bg-' + cls + '">' + newStatus.charAt(0).toUpperCase() + newStatus.slice(1) + '</span>';
                        }
                    }
                    // Show inline success notice at top
                    showInlineFlash('success', data.message);
                } else {
                    showInlineFlash('danger', data.message);
                }
            })
            .catch(() => {
                showInlineFlash('danger', 'Failed to update status. Please try again.');
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = originalText;
            });
        });
    });

    function showInlineFlash(type, msg) {
        const colors = {
            success: 'bg-emerald-50 text-emerald-800 border-emerald-200',
            danger:  'bg-red-50 text-red-800 border-red-200',
            warning: 'bg-amber-50 text-amber-800 border-amber-200',
            info:    'bg-blue-50 text-blue-800 border-blue-200'
        };
        const existing = document.getElementById('inline-flash');
        if (existing) existing.remove();
        const div = document.createElement('div');
        div.id = 'inline-flash';
        div.className = 'mb-4 p-3 rounded-lg border flex justify-between items-start ' + (colors[type] || colors.info);
        div.innerHTML = '<div class="text-sm font-medium">' + msg + '</div>'
            + '<button type="button" class="text-current opacity-70 hover:opacity-100" onclick="this.parentElement.remove()"><i class="bi bi-x-lg"></i></button>';
        const content = document.querySelector('main .flex-1');
        if (content) content.insertBefore(div, content.firstChild);
        setTimeout(() => { if (div.parentElement) div.remove(); }, 4000);
    }
});
</script>
EOT;

require_once __DIR__ . '/../helpers/layout_footer.php';
?>
