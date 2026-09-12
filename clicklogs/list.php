<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$filters = tf_clicklogs_filters($_GET);
$from_date = $filters['from'];
$to_date = $filters['to'];
$vendor_filter = $filters['vendor_id'];
$project_filter = $filters['project_id'];
$country_filter = $filters['country'];
$device_filter = $filters['device'];
$browser_filter = $filters['browser'];
$os_filter = $filters['os'];
$isp_filter = $filters['isp'];
$ip_filter = $filters['ip_address'];
$click_id_filter = $filters['click_id'];
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;

$where_sql = $filters['where_sql'];
$params = $filters['params'];

$count = $pdo->prepare("SELECT COUNT(*) as cnt FROM clicks c $where_sql");
$count->execute($params);
$total = (int)$count->fetch()['cnt'];

$pagination = paginate($total, $per_page, $page);

$stmt = $pdo->prepare("
    SELECT c.*, gv.vendor_name, p.project_code, p.project_name
    FROM clicks c
    JOIN global_vendors gv ON c.vendor_id = gv.id
    JOIN projects p ON c.project_id = p.id
    $where_sql
    ORDER BY c.clicked_at DESC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
");
$stmt->execute($params);
$clicks = $stmt->fetchAll();

$vendors_list = $pdo->query("SELECT gv.id, gv.vendor_name, p.project_code FROM project_vendor pv JOIN global_vendors gv ON gv.id = pv.vendor_id JOIN projects p ON p.id = pv.project_id ORDER BY p.project_code, gv.vendor_name")->fetchAll();
$projects_list = $pdo->query("SELECT id, project_code, project_name FROM projects ORDER BY project_name")->fetchAll();

$page_title = 'Click Logs';
$page_actions = '<a href="' . BASE_URL . '/clicklogs/export.php?' . http_build_query($_GET) . '" class="btn btn-outline-success btn-sm"><i class="bi bi-download"></i>Export CSV</a>';
require_once __DIR__ . '/../helpers/layout_header.php';
?>


<div class="tf-card mb-4">
    <form method="GET" class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-sm-6 col-md-3">
                <label class="tf-label">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?php echo $from_date; ?>">
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <label class="tf-label">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?php echo $to_date; ?>">
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <label class="tf-label">Project</label>
                <select name="project_id" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($projects_list as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo $project_filter == $p['id'] ? 'selected' : ''; ?>><?php echo sanitize($p['project_code']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <label class="tf-label">Vendor</label>
                <select name="vendor_id" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($vendors_list as $v): ?>
                    <option value="<?php echo $v['id']; ?>" <?php echo $vendor_filter == $v['id'] ? 'selected' : ''; ?>><?php echo sanitize($v['project_code'] . ' — ' . $v['vendor_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-md-2">
                <label class="tf-label">Country</label>
                <input type="text" name="country" maxlength="2" class="form-control form-control-sm" value="<?php echo sanitize($country_filter); ?>" placeholder="US">
            </div>
            <div class="col-12 col-sm-6 col-md-2">
                <label class="tf-label">Device</label>
                <select name="device" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="desktop" <?php echo $device_filter === 'desktop' ? 'selected' : ''; ?>>Desktop</option>
                    <option value="mobile" <?php echo $device_filter === 'mobile' ? 'selected' : ''; ?>>Mobile</option>
                    <option value="tablet" <?php echo $device_filter === 'tablet' ? 'selected' : ''; ?>>Tablet</option>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-md-2">
                <label class="tf-label">Browser</label>
                <input type="text" name="browser" class="form-control form-control-sm" value="<?php echo sanitize($browser_filter); ?>" placeholder="Chrome">
            </div>
            <div class="col-12 col-sm-6 col-md-2">
                <label class="tf-label">Operating System</label>
                <input type="text" name="os" class="form-control form-control-sm" value="<?php echo sanitize($os_filter); ?>" placeholder="Windows">
            </div>
            <div class="col-12 col-sm-6 col-md-2">
                <label class="tf-label">ISP</label>
                <input type="text" name="isp" class="form-control form-control-sm" value="<?php echo sanitize($isp_filter); ?>" placeholder="Comcast">
            </div>
            <div class="col-12 col-sm-6 col-md-2">
                <label class="tf-label">IP Address</label>
                <input type="text" name="ip_address" class="form-control form-control-sm" value="<?php echo sanitize($ip_filter); ?>" placeholder="192.0.2.1">
            </div>
            <div class="col-12 col-sm-6 col-md-2">
                <label class="tf-label">Click ID</label>
                <input type="text" name="click_id" class="form-control form-control-sm" value="<?php echo sanitize($click_id_filter); ?>">
            </div>
            <div class="col-12 col-md-12 mt-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i>Apply Filters</button>
                <a href="<?php echo BASE_URL; ?>/clicklogs/list.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </div>
    </form>
</div>

<div class="tf-card">
    <div class="tf-card-header">
        <h5 class="mb-0 fw-semibold"><?php echo number_format($total); ?> clicks <small class="text-muted">(<?php echo $from_date; ?> → <?php echo $to_date; ?>)</small></h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-clicks align-middle mb-0">
            <thead>
                <tr>
                    <th>Click ID</th>
                    <th>Project</th>
                    <th>Vendor</th>
                    <th>Country</th>
                    <th>Device</th>
                    <th>Browser</th>
                    <th>OS</th>
                    <th>Lang</th>
                    <th>ISP</th>
                    <th>Referrer</th>
                    <th>IP</th>
                    <th>Sub1-5</th>
                    <th>Status</th>
                    <th>Clicked</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clicks)): ?>
                <tr><td colspan="14" class="text-center py-5 text-muted">No clicks match your filters.</td></tr>
                <?php else: foreach ($clicks as $c):
                    $ua = (string)($c['user_agent'] ?? '');
                    $ref = (string)($c['referrer'] ?? '');
                ?>
                <tr>
                    <td>
                        <span class="d-inline-flex align-items-center gap-1" data-full-click-id="<?php echo sanitize($c['click_id']); ?>">
                            <code title="<?php echo sanitize($c['click_id']); ?>"><?php echo substr(sanitize($c['click_id']), 0, 12); ?>…</code>
                            <?php echo tf_copy_button($c['click_id'], 'btn btn-link btn-sm p-0 text-secondary'); ?>
                        </span>
                    </td>
                    <td><a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo $c['project_id']; ?>"><?php echo sanitize($c['project_code']); ?></a></td>
                    <td><?php echo sanitize($c['vendor_name']); ?></td>
                    <td><?php echo sanitize($c['country_code'] ?? 'XX'); ?></td>
                    <td title="<?php echo sanitize($ua); ?>"><?php echo sanitize(ucfirst($c['device_type'] ?? '')); ?></td>
                    <td><?php echo sanitize($c['browser'] ?? ''); ?></td>
                    <td class="text-secondary"><?php echo sanitize($c['os'] ?? ''); ?></td>
                    <td class="small"><?php echo sanitize($c['browser_lang'] ?? ''); ?></td>
                    <td class="small" title="<?php echo sanitize($c['isp'] ?? ''); ?>"><?php echo sanitize($c['isp'] ?? ''); ?></td>
                    <td class="small text-secondary" style="max-width: 180px;" title="<?php echo sanitize($ref); ?>"><?php echo $ref !== '' ? sanitize(strlen($ref) > 40 ? substr($ref, 0, 37) . '...' : $ref) : '—'; ?></td>
                    <td><code><?php echo sanitize($c['ip_address'] ?? ''); ?></code></td>
                    <td class="small text-secondary">
                        <?php $subs = array_filter([$c['sub1']??'', $c['sub2']??'', $c['sub3']??'', $c['sub4']??'', $c['sub5']??'']); echo $subs ? sanitize(implode(' · ', $subs)) : '—'; ?>
                    </td>
                    <td>
                        <?php if ($c['is_converted']): ?>
                        <span class="badge bg-success">Converted</span>
                        <?php else: ?>
                        <span class="badge bg-light text-dark border">Pending</span>
                        <?php endif; ?>
                        <?php if ($c['is_duplicate_ip']): ?>
                        <span class="badge bg-warning" title="Duplicate IP in last 24h">DUP</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-secondary"><?php echo sanitize($c['clicked_at']); ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="tf-card-footer"><?php echo render_pagination($pagination, BASE_URL . '/clicklogs/list.php?' . http_build_query($_GET)); ?></div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
