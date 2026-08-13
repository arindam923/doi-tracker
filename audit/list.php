<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin']);

$actor_filter = intval($_GET['actor_id'] ?? 0);
$action_filter = trim($_GET['action'] ?? '');
$entity_type_filter = trim($_GET['entity_type'] ?? '');
$entity_id_filter = trim($_GET['entity_id'] ?? '');
$from_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$to_date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;

$where = [];
$params = [];
if ($actor_filter) { $where[] = "a.actor_id = ?"; $params[] = $actor_filter; }
if ($action_filter !== '') { $where[] = "a.action = ?"; $params[] = $action_filter; }
if ($entity_type_filter !== '') { $where[] = "a.entity_type = ?"; $params[] = $entity_type_filter; }
if ($entity_id_filter !== '') { $where[] = "a.entity_id = ?"; $params[] = $entity_id_filter; }
if ($from_date) { $where[] = "a.created_at >= ?"; $params[] = $from_date; }
if ($to_date) { $where[] = "a.created_at <= DATE_ADD(?, INTERVAL 1 DAY)"; $params[] = $to_date; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Interesting actions (subscribed list for the filter dropdown)
$action_options = ['create','edit','update','delete','attach','detach','approve','reject','login','logout','status_change','upload','download','note','hold','resume'];

$cnt = $pdo->prepare("SELECT COUNT(*) AS c FROM audit_logs a $where_sql");
$cnt->execute($params);
$total = (int)$cnt->fetch()['c'];
$pagination = paginate($total, $per_page, $page);

$stmt = $pdo->prepare("
    SELECT a.*, u.username AS actor_name
    FROM audit_logs a
    LEFT JOIN users u ON a.actor_id = u.id
    $where_sql
    ORDER BY a.created_at DESC, a.id DESC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
");
$stmt->execute($params);
$entries = $stmt->fetchAll();

$users_opt = $pdo->query("SELECT id, username FROM users ORDER BY username")->fetchAll();

$page_title = 'Audit Log';
require_once __DIR__ . '/../helpers/layout_header.php';
?>
<style>
    .table-audit thead th { font-size: .7rem; letter-spacing: .06em; text-transform: uppercase; color: #64748b; font-weight: 600; background: #f8fafc; }
    .table-audit tbody td { vertical-align: middle; padding: .85rem 1rem; }
    .audit-badge { font-size: .68rem; letter-spacing: .02em; font-weight: 600; padding: .25rem .5rem; border-radius: 9999px; }
</style>

<div class="card border-0 shadow-sm mb-4">
    <form method="GET" class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-2">
                <label class="form-label small fw-semibold text-secondary">Actor</label>
                <select name="actor_id" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($users_opt as $u): ?>
                    <option value="<?php echo (int)$u['id']; ?>" <?php echo $actor_filter == $u['id'] ? 'selected' : ''; ?>><?php echo sanitize($u['username']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-secondary">Action</label>
                <select name="action" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($action_options as $a): ?>
                    <option value="<?php echo $a; ?>" <?php echo $action_filter === $a ? 'selected' : ''; ?>><?php echo ucfirst($a); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-secondary">Entity Type</label>
                <select name="entity_type" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="project" <?php echo $entity_type_filter === 'project' ? 'selected' : ''; ?>>Project</option>
                    <option value="client" <?php echo $entity_type_filter === 'client' ? 'selected' : ''; ?>>Client</option>
                    <option value="vendor" <?php echo $entity_type_filter === 'vendor' ? 'selected' : ''; ?>>Vendor</option>
                    <option value="project_vendor" <?php echo $entity_type_filter === 'project_vendor' ? 'selected' : ''; ?>>Project / Vendor</option>
                    <option value="user" <?php echo $entity_type_filter === 'user' ? 'selected' : ''; ?>>User</option>
                    <option value="setting" <?php echo $entity_type_filter === 'setting' ? 'selected' : ''; ?>>Setting</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-secondary">Entity ID</label>
                <input type="text" name="entity_id" class="form-control form-control-sm" value="<?php echo sanitize($entity_id_filter); ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-secondary">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?php echo sanitize($from_date); ?>">
            </div>
            <div class="col-6 col-md-1">
                <label class="form-label small fw-semibold text-secondary">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?php echo sanitize($to_date); ?>">
            </div>
            <div class="col-12 col-md-1 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i></button>
                <a href="<?php echo BASE_URL; ?>/audit/list.php" class="btn btn-outline-secondary btn-sm" title="Reset"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </div>
    </form>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom py-3">
        <h5 class="mb-0 fw-semibold"><?php echo number_format($total); ?> audit entries</h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-audit align-middle mb-0">
            <thead>
                <tr><th>Time</th><th>Actor</th><th>Action</th><th>Entity</th><th>Changes</th><th>IP</th><th></th></tr>
            </thead>
            <tbody>
                <?php if (empty($entries)): ?>
                <tr><td colspan="7" class="text-center py-5 text-muted">No audit entries match your filters.</td></tr>
                <?php else: foreach ($entries as $e):
                    $has_before = !empty($e['before_json']);
                    $has_after = !empty($e['after_json']); ?>
                <tr>
                    <td class="text-nowrap small text-muted"><?php echo sanitize(date('M j, Y H:i', strtotime($e['created_at']))); ?></td>
                    <td><?php echo sanitize($e['actor_name'] ?? 'System'); ?></td>
                    <td><span class="badge bg-light text-dark border"><?php echo sanitize(ucfirst($e['action'])); ?></span></td>
                    <td><code><?php echo sanitize($e['entity_type']); ?></code> <span class="text-secondary">#<?php echo sanitize($e['entity_id']); ?></span></td>
                    <td>
                        <?php if ($has_after): ?><span class="badge bg-success">modified</span><?php endif; ?>
                        <?php if ($has_before && !$has_after): ?><span class="badge bg-warning">deleted</span><?php endif; ?>
                        <?php if (!$has_before && $has_after): ?><span class="badge bg-info">created</span><?php endif; ?>
                    </td>
                    <td class="small text-secondary"><?php echo sanitize($e['ip_address'] ?? '—'); ?></td>
                    <td>
                        <a href="<?php echo BASE_URL; ?>/audit/detail.php?id=<?php echo (int)$e['id']; ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye"></i>View</a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white py-3"><?php echo render_pagination($pagination, BASE_URL . '/audit/list.php?' . http_build_query($_GET)); ?></div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
