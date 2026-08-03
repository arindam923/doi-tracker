<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

// ─── Filters ───
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$client_filter = intval($_GET['client_id'] ?? 0);
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;

$where = [];
$params = [];

if ($search) {
    $where[] = "(p.project_code LIKE ? OR p.project_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status_filter && in_array($status_filter, ['live','hold','closed'])) {
    $where[] = "p.status = ?";
    $params[] = $status_filter;
}
if ($client_filter) {
    $where[] = "p.client_id = ?";
    $params[] = $client_filter;
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
    $vstmt = $pdo->prepare("SELECT * FROM vendors WHERE project_id IN ($placeholders) ORDER BY vendor_name");
    $vstmt->execute(array_column($projects, 'id'));
    while ($v = $vstmt->fetch()) {
        $project_vendor_map[$v['project_id']][] = $v;
    }
}

$page_title = 'Projects';
$page_actions = '<a href="' . BASE_URL . '/projects/create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>New Project</a>';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<style>
    .table-projects thead th { font-size: .7rem; letter-spacing: .06em; text-transform: uppercase; color: #64748b; font-weight: 600; background: #f8fafc; }
    .table-projects tbody td { vertical-align: middle; padding: .85rem 1rem; }
    .table-projects .project-name { color: #0f172a; font-weight: 600; text-decoration: none; }
    .table-projects .project-name:hover { color: #4f46e5; }
    .table-projects .project-code { background: #f1f5f9; color: #475569; padding: .125rem .5rem; border-radius: 4px; font-size: .75rem; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .progress-quota { width: 80px; height: 6px; }
    .tf-pagination ul { display: inline-flex; align-items: center; list-style: none; margin: 0; padding: 0; border-radius: .5rem; overflow: hidden; border: 1px solid #e2e8f0; background: #fff; }
    .tf-pagination li a, .tf-pagination li span { display: inline-flex; align-items: center; justify-content: center; min-width: 2.25rem; height: 2.25rem; padding: 0 .75rem; font-size: .875rem; font-weight: 500; color: #64748b; background: #fff; border-right: 1px solid #e2e8f0; text-decoration: none; }
    .tf-pagination li:last-child a, .tf-pagination li:last-child span { border-right: 0; }
    .tf-pagination a:hover { background: #f8fafc; color: #0f172a; text-decoration: none; }
    .tf-pagination .is-active { background: #eef2ff !important; color: #4f46e5 !important; font-weight: 600; }
    .tf-pagination .is-disabled { color: #cbd5e1; background: #f8fafc; cursor: not-allowed; }
</style>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <form method="GET" class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-4">
                <label for="search" class="form-label small fw-semibold text-secondary">Search</label>
                <input type="text" id="search" name="search" class="form-control" placeholder="Search by code or name..."
                       value="<?php echo sanitize($search); ?>">
            </div>
            <div class="col-6 col-md-3">
                <label for="status" class="form-label small fw-semibold text-secondary">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="live" <?php echo $status_filter === 'live' ? 'selected' : ''; ?>>Live</option>
                    <option value="hold" <?php echo $status_filter === 'hold' ? 'selected' : ''; ?>>Hold</option>
                    <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label for="client_id" class="form-label small fw-semibold text-secondary">Client</label>
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
<div class="card border-0 shadow-sm">
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
                <tr>
                    <td><code class="project-code"><?php echo sanitize($p['project_code']); ?></code></td>
                    <td>
                        <a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo $p['id']; ?>" class="project-name">
                            <?php echo sanitize($p['project_name']); ?>
                        </a>
                    </td>
                    <td><?php echo sanitize($p['client_name'] ?? '-'); ?></td>
                    <td><?php echo status_badge($p['status']); ?></td>
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

                        <!-- Status Modal -->
                        <div id="statusModal<?php echo $p['id']; ?>" class="tf-modal is-sm" hidden role="dialog" aria-modal="true" aria-labelledby="statusModal<?php echo $p['id']; ?>-title">
                            <div class="tf-modal-backdrop" data-tf-modal-close></div>
                            <div class="tf-modal-dialog">
                                <form method="POST" action="<?php echo BASE_URL; ?>/projects/status.php">
                                    <input type="hidden" name="project_id" value="<?php echo $p['id']; ?>">
                                    <?php echo csrf_field(); ?>
                                    <div class="tf-modal-header">
                                        <h3 id="statusModal<?php echo $p['id']; ?>-title" class="tf-modal-title">Change Status</h3>
                                        <button type="button" class="tf-modal-close" data-tf-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
                                    </div>
                                    <div class="tf-modal-body">
                                        <div class="mb-3">
                                            <label for="new_status_<?php echo $p['id']; ?>" class="form-label small fw-semibold text-secondary">New Status</label>
                                            <select id="new_status_<?php echo $p['id']; ?>" name="new_status" class="form-select">
                                                <option value="live" <?php echo $p['status'] === 'live' ? 'selected' : ''; ?>>🟢 Live</option>
                                                <option value="hold" <?php echo $p['status'] === 'hold' ? 'selected' : ''; ?>>🟡 Hold</option>
                                                <option value="closed" <?php echo $p['status'] === 'closed' ? 'selected' : ''; ?>>🔴 Closed</option>
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
                                        <label class="form-label small fw-semibold text-secondary">Client Postback URL</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" style="font-family: ui-monospace, monospace; font-size: .85em;" readonly value="<?php echo sanitize($client_postback); ?>">
                                            <button class="btn btn-secondary" data-copy="<?php echo sanitize($client_postback); ?>" aria-label="Copy"><i class="bi bi-clipboard"></i></button>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold text-secondary">Postback Token</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" style="font-family: ui-monospace, monospace; font-size: .85em;" readonly value="<?php echo sanitize($p['postback_token']); ?>">
                                            <button class="btn btn-secondary" data-copy="<?php echo sanitize($p['postback_token']); ?>" aria-label="Copy"><i class="bi bi-clipboard"></i></button>
                                        </div>
                                    </div>

                                    <?php if ($project_vendors): ?>
                                    <hr>
                                    <h6 class="fw-semibold mb-3">Vendor Tracking Links</h6>
                                    <?php foreach ($project_vendors as $v):
                                        $vendor_url = BASE_URL . '/tracking/click.php?project_id=' . $p['id'] . '&vendor_id=' . $v['id'];
                                    ?>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold text-secondary d-flex align-items-center gap-2">
                                            <span><?php echo sanitize($v['vendor_name']); ?></span>
                                            <?php echo status_badge($v['status']); ?>
                                        </label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" style="font-family: ui-monospace, monospace; font-size: .85em;" readonly value="<?php echo sanitize($vendor_url); ?>">
                                            <button class="btn btn-secondary" data-copy="<?php echo sanitize($vendor_url); ?>" aria-label="Copy"><i class="bi bi-clipboard"></i></button>
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
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
echo render_pagination($pagination, BASE_URL . '/projects/list.php');
require_once __DIR__ . '/../helpers/layout_footer.php';
?>
