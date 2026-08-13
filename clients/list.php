<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;

$where = [];
$params = [];

if ($search) {
    $where[] = "(client_name LIKE ? OR client_code LIKE ? OR contact_person LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($status_filter === 'active') {
    $where[] = "is_active = 1";
} elseif ($status_filter === 'archived') {
    $where[] = "is_active = 0";
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count_stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM clients $where_sql");
$count_stmt->execute($params);
$total = $count_stmt->fetch()['cnt'];

$pagination = paginate($total, $per_page, $page);

$stmt = $pdo->prepare("SELECT * FROM clients $where_sql ORDER BY created_at DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}");
$stmt->execute($params);
$clients = $stmt->fetchAll();

$client_ids = array_column($clients, 'id');
$project_counts = [];
if (!empty($client_ids)) {
    $placeholders = implode(',', array_fill(0, count($client_ids), '?'));
    $pstmt = $pdo->prepare("SELECT client_id, COUNT(*) as cnt FROM projects WHERE client_id IN ($placeholders) GROUP BY client_id");
    $pstmt->execute($client_ids);
    while ($row = $pstmt->fetch()) {
        $project_counts[$row['client_id']] = $row['cnt'];
    }
}

$page_title = 'Clients';
$page_actions = '<a href="' . BASE_URL . '/clients/create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>Add Client</a>';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<style>
    .table-clients thead th { font-size: .7rem; letter-spacing: .06em; text-transform: uppercase; color: #64748b; font-weight: 600; background: #f8fafc; }
    .table-clients tbody td { vertical-align: middle; padding: .85rem 1rem; }
    .table-clients code { background: #f1f5f9; color: #475569; padding: .125rem .5rem; border-radius: 4px; font-size: .75rem; }
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
            <div class="col-12 col-md-6">
                <label for="search" class="form-label small fw-semibold text-secondary">Search</label>
                <input type="text" id="search" name="search" class="form-control" placeholder="Search by name, code, contact..."
                       value="<?php echo sanitize($search); ?>">
            </div>
            <div class="col-12 col-md-3">
                <label for="status" class="form-label small fw-semibold text-secondary">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
                </select>
            </div>
            <div class="col-12 col-md-3 d-flex justify-content-end">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i>Filter</button>
            </div>
        </div>
    </form>
</div>

<!-- Clients Table -->
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover table-clients align-middle mb-0">
            <thead>
                <tr>
                    <th>Client Name</th>
                    <th>Code</th>
                    <th>Contact</th>
                    <th>Email</th>
                    <th>Country</th>
                    <th>Currency</th>
                    <th class="text-end">Projects</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clients)): ?>
                <tr>
                    <td colspan="9" class="text-center py-5 text-muted">
                        <i class="bi bi-building d-block mb-2" style="font-size: 2.5rem; color: #cbd5e1;"></i>
                        <p class="fw-semibold text-dark mb-1">No clients found</p>
                        <p class="small mb-3">Add your first client to get started.</p>
                        <a href="<?php echo BASE_URL; ?>/clients/create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>Add Client</a>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($clients as $c): ?>
                <tr>
                    <td><strong><?php echo sanitize($c['client_name']); ?></strong></td>
                    <td><code><?php echo sanitize($c['client_code']); ?></code></td>
                    <td><?php echo sanitize($c['contact_person'] ?? '-'); ?></td>
                    <td><?php echo sanitize($c['email'] ?? '-'); ?></td>
                    <td><?php echo sanitize($c['country'] ?? '-'); ?></td>
                    <td><span class="badge bg-light text-dark border"><?php echo sanitize($c['default_currency'] ?? 'USD'); ?></span></td>
                    <td class="text-end"><span class="badge bg-light"><?php echo $project_counts[$c['id']] ?? 0; ?></span></td>
                    <td><?php echo $c['is_active']
                        ? '<span class="badge bg-success">Active</span>'
                        : '<span class="badge bg-light">Archived</span>'; ?></td>
                    <td class="text-end">
                        <div class="tf-dropdown">
                            <button type="button" class="tf-dropdown-trigger" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for <?php echo sanitize($c['client_name']); ?>">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <div class="tf-dropdown-menu" role="menu" hidden>
                                <a href="<?php echo BASE_URL; ?>/clients/edit.php?id=<?php echo $c['id']; ?>" class="tf-dropdown-item" role="menuitem">
                                    <i class="bi bi-pencil"></i><span>Edit</span>
                                </a>
                                <a href="<?php echo BASE_URL; ?>/clients/documents.php?id=<?php echo $c['id']; ?>" class="tf-dropdown-item" role="menuitem">
                                    <i class="bi bi-file-earmark-text"></i><span>Documents</span>
                                </a>
                                <a href="<?php echo BASE_URL; ?>/projects/list.php?client_id=<?php echo $c['id']; ?>" class="tf-dropdown-item" role="menuitem">
                                    <i class="bi bi-folder2"></i><span>View Projects</span>
                                </a>
                                <div class="tf-dropdown-divider"></div>
                                <?php if ($c['is_active']): ?>
                                <form method="POST" action="<?php echo BASE_URL; ?>/clients/archive.php">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                    <input type="hidden" name="action" value="archive">
                                    <button type="submit" class="tf-dropdown-item is-warning" role="menuitem" data-confirm="Archive this client? Projects will not be affected.">
                                        <i class="bi bi-archive"></i><span>Archive</span>
                                    </button>
                                </form>
                                <?php else: ?>
                                <form method="POST" action="<?php echo BASE_URL; ?>/clients/archive.php">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                    <input type="hidden" name="action" value="restore">
                                    <button type="submit" class="tf-dropdown-item is-success" role="menuitem">
                                        <i class="bi bi-arrow-counterclockwise"></i><span>Restore</span>
                                    </button>
                                </form>
                                <?php endif; ?>
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
echo render_pagination($pagination, BASE_URL . '/clients/list.php');
require_once __DIR__ . '/../helpers/layout_footer.php';
?>
