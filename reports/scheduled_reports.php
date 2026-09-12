<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/reporting.php';
require_role(['super_admin', 'campaign_manager']);

$page = max(1, tf_get_int('page', 1));
$per_page = 50;
$action_raw = tf_get_string('action', 'list');
$action = in_array($action_raw, ['list', 'new', 'edit'], true) ? $action_raw : 'list';
$edit_id = tf_get_int('id', 0);

// ─── Handle POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/reports/scheduled_reports.php');
    }

    $post_action = $_POST['post_action'] ?? 'create';

    if ($post_action === 'create' || $post_action === 'update') {
        $title = trim($_POST['title'] ?? '');
        $report_type = $_POST['report_type'] ?? 'overview';
        $group_by = $_POST['group_by'] ?? 'project';
        $frequency = $_POST['frequency'] ?? 'daily';
        $period = $_POST['period'] ?? 'daily';
        $from_date = $_POST['from'] ?? '';
        $to_date = $_POST['to'] ?? '';
        $custom_cron = trim($_POST['custom_cron'] ?? '');
        $recipients_csv = trim($_POST['recipients_csv'] ?? '');
        $project_id = intval($_POST['project_id'] ?? 0);
        $client_id = intval($_POST['client_id'] ?? 0);
        $vendor_id = intval($_POST['vendor_id'] ?? 0);
        $filters_json = null;

        $allowed_groups = ['project', 'vendor', 'client', 'country', 'device'];
        if (!in_array($group_by, $allowed_groups, true)) $group_by = 'project';
        if (!in_array($period, tf_reporting_periods(), true)) $period = 'daily';

        $allowed_freq = ['daily', 'weekly', 'monthly', 'custom_cron'];
        if (!in_array($frequency, $allowed_freq, true)) $frequency = 'daily';

        $allowed_types = ['overview', 'traffic_summary'];
        if (!in_array($report_type, $allowed_types, true)) $report_type = 'overview';

        $errors = [];
        if ($title === '') $errors[] = 'Report title is required.';
        $filtered_recipients = array_filter(array_map('trim', explode(',', $recipients_csv)));
        foreach ($filtered_recipients as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid recipient email: ' . $email;
            }
        }

        $filters = [];
        if ($project_id > 0) $filters['project_id'] = $project_id;
        if ($group_by !== 'project') $filters['group_by'] = $group_by;
        $normalized = tf_reporting_normalize_filters(['period' => $period, 'from' => $from_date, 'to' => $to_date, 'project_id' => $project_id, 'client_id' => $client_id, 'vendor_id' => $vendor_id], ['period' => $period]);
        $filters['period'] = $normalized['period'];
        $filters['from'] = $normalized['from'];
        $filters['to'] = $normalized['to'];
        if ($normalized['client_id']) $filters['client_id'] = $normalized['client_id'];
        if ($normalized['vendor_id']) $filters['vendor_id'] = $normalized['vendor_id'];
        if (!empty($filters)) {
            $filters_json = json_encode($filters);
        }

        if (empty($errors)) {
            $recipients_csv_clean = implode(',', $filtered_recipients);
            $owner_id = $_SESSION['user_id'] ?? 0;

            if ($post_action === 'create') {
                $pdo->prepare("
                    INSERT INTO scheduled_reports (owner_id, title, report_type, group_by, filters_json, frequency, custom_cron, recipients_csv, next_run_at, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ")->execute([
                    $owner_id, $title, $report_type, $group_by, $filters_json, $frequency, $frequency === 'custom_cron' ? $custom_cron : null, $recipients_csv_clean,
                    $frequency === 'daily' ? date('Y-m-d H:i:s', strtotime('+1 day')) :
                    ($frequency === 'weekly' ? date('Y-m-d H:i:s', strtotime('+1 week')) :
                     ($frequency === 'monthly' ? date('Y-m-d H:i:s', strtotime('+1 month')) : null))
                ]);
                $sr_id = (int)$pdo->lastInsertId();
                audit_log($pdo, 'create', 'scheduled_report', $sr_id, null, [
                    'title' => $title, 'frequency' => $frequency, 'recipients' => $recipients_csv_clean
                ]);
                set_flash('success', 'Scheduled report created.');
            } else {
                $update_errors = [];
                if (isset($_POST['is_active'])) {
                    $is_active = 1;
                } else {
                    $is_active = 0;
                }
                $next_run_at = $frequency === 'daily' ? date('Y-m-d H:i:s', strtotime('+1 day')) :
                    ($frequency === 'weekly' ? date('Y-m-d H:i:s', strtotime('+1 week')) :
                     ($frequency === 'monthly' ? date('Y-m-d H:i:s', strtotime('+1 month')) : null));
                $pdo->prepare("
                    UPDATE scheduled_reports SET title = ?, report_type = ?, group_by = ?, filters_json = ?, frequency = ?, custom_cron = ?, recipients_csv = ?, is_active = ?, next_run_at = ?
                    WHERE id = ?
                ")->execute([
                    $title, $report_type, $group_by, $filters_json, $frequency, $frequency === 'custom_cron' ? $custom_cron : null, $recipients_csv_clean, $is_active, $next_run_at, $edit_id
                ]);
                audit_log($pdo, 'edit', 'scheduled_report', $edit_id, null, [
                    'title' => $title, 'frequency' => $frequency, 'is_active' => $is_active
                ]);
                set_flash('success', 'Scheduled report updated.');
            }
        } else {
            set_flash('danger', implode(' | ', $errors));
        }
        redirect(BASE_URL . '/reports/scheduled_reports.php');
    }

    if ($post_action === 'delete') {
        $sr_id = intval($_POST['sr_id'] ?? 0);
        $pdo->prepare("DELETE FROM scheduled_reports WHERE id = ?")->execute([$sr_id]);
        audit_log($pdo, 'delete', 'scheduled_report', $sr_id);
        set_flash('success', 'Scheduled report deleted.');
        redirect(BASE_URL . '/reports/scheduled_reports.php');
    }

    if ($post_action === 'run_now') {
        $sr_id = intval($_POST['sr_id'] ?? 0);
        require_once __DIR__ . '/../cron/reports_scheduler.php';
        set_flash('info', 'Scheduled report runner triggered.');
        redirect(BASE_URL . '/reports/scheduled_reports.php');
    }
}

// ─── Load existing data for edit ────────────────────────────────
$edit_data = null;
$edit_filters = [];
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM scheduled_reports WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_data = $stmt->fetch();
    if (!$edit_data) {
        set_flash('danger', 'Report not found.');
        redirect(BASE_URL . '/reports/scheduled_reports.php');
    }
    $edit_filters = json_decode((string)$edit_data['filters_json'], true) ?: [];
}

try {
    $count = $pdo->prepare("SELECT COUNT(*) AS c FROM scheduled_reports");
    $count->execute();
    $total = (int)$count->fetch()['c'];
} catch (Throwable $e) {
    error_log('scheduled_reports count failed: ' . $e->getMessage());
    $total = 0;
}
$pagination = paginate($total, $per_page, $page);

try {
    $stmt = $pdo->prepare("
    SELECT sr.*, u.username AS owner_name
    FROM scheduled_reports sr
    LEFT JOIN users u ON sr.owner_id = u.id
    ORDER BY sr.is_active DESC, sr.next_run_at ASC, sr.created_at DESC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
");
    $stmt->execute();
    $reports = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('scheduled_reports fetch failed: ' . $e->getMessage());
    $reports = [];
}

$projects_list = $pdo->query("SELECT id, project_code, project_name FROM projects ORDER BY project_name")->fetchAll();
$clients_list = $pdo->query("SELECT id, client_name FROM clients ORDER BY client_name")->fetchAll();
$vendors_list = $pdo->query("SELECT id, vendor_name FROM global_vendors ORDER BY vendor_name")->fetchAll();

$page_title = 'Scheduled Reports';
$page_actions = '<a href="' . BASE_URL . '/reports/scheduled_reports.php?action=new" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>Schedule Report</a>';
require_once __DIR__ . '/../helpers/layout_header.php';
?>


<?php if ($action === 'new' || ($action === 'edit' && $edit_data)): ?>
<div class="tf-card mb-4">
    <div class="tf-card-header">
        <h5 class="mb-0 fw-semibold"><?php echo $edit_data ? 'Edit' : 'New'; ?> Scheduled Report</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="post_action" value="<?php echo $edit_data ? 'update' : 'create'; ?>">
            <?php if ($edit_data): ?>
                <input type="hidden" name="sr_id" value="<?php echo (int)$edit_data['id']; ?>">
            <?php endif; ?>
            <div class="row g-3">
                <div class="col-12">
                    <label class="tf-label">Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" required maxlength="255"
                           value="<?php echo sanitize($edit_data['title'] ?? ''); ?>" placeholder="Weekly Revenue Summary">
                </div>
                <div class="col-6 col-md-3">
                    <label class="tf-label">Report Type</label>
                    <select name="report_type" class="form-select">
                        <option value="overview" <?php echo ($edit_data['report_type'] ?? '') === 'overview' ? 'selected' : ''; ?>>Revenue Report</option>
                        <option value="traffic_summary" <?php echo ($edit_data['report_type'] ?? '') === 'traffic_summary' ? 'selected' : ''; ?>>Traffic Summary</option>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="tf-label">Report Period</label>
                    <select name="period" class="form-select">
                        <?php foreach (['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','custom'=>'Custom Date Range'] as $pv => $pl): ?><option value="<?php echo $pv; ?>" <?php echo ($edit_filters['period'] ?? 'daily') === $pv ? 'selected' : ''; ?>><?php echo $pl; ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="tf-label">Group By</label>
                    <select name="group_by" class="form-select">
                        <?php foreach (['project'=>'Project','vendor'=>'Vendor','client'=>'Client','country'=>'Country','device'=>'Device'] as $val => $lbl): ?>
                        <option value="<?php echo $val; ?>" <?php echo ($edit_data['group_by'] ?? 'project') === $val ? 'selected' : ''; ?>><?php echo sanitize($lbl); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="tf-label">Frequency</label>
                    <select name="frequency" class="form-select">
                        <option value="daily" <?php echo ($edit_data['frequency'] ?? 'daily') === 'daily' ? 'selected' : ''; ?>>Daily</option>
                        <option value="weekly" <?php echo ($edit_data['frequency'] ?? 'daily') === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                        <option value="monthly" <?php echo ($edit_data['frequency'] ?? 'daily') === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                        <option value="custom_cron" <?php echo ($edit_data['frequency'] ?? 'daily') === 'custom_cron' ? 'selected' : ''; ?>>Custom Cron</option>
                    </select>
                </div>
                <div class="col-6 col-md-3" id="customCronGroup" style="<?php echo ($edit_data['frequency'] ?? '') === 'custom_cron' ? '' : 'display:none;'; ?>">
                    <label class="tf-label">Cron Expression</label>
                    <input type="text" name="custom_cron" class="form-control font-monospace small"
                           value="<?php echo sanitize($edit_data['custom_cron'] ?? ''); ?>"
                           placeholder="0 9 * * 1">
                    <p class="form-text mb-0">5-field cron (min hour dom month dow)</p>
                </div>
                <div class="col-12">
                    <label class="tf-label">Project Filter</label>
                    <select name="project_id" class="form-select">
                        <option value="">All Projects</option>
                        <?php foreach ($projects_list as $p): ?>
                        <option value="<?php echo (int)$p['id']; ?>" <?php
                            $pf = $edit_filters['project_id'] ?? 0;
                            echo ((int)$pf === (int)$p['id']) ? 'selected' : ''; ?>>
                            <?php echo sanitize($p['project_code'] . ' — ' . $p['project_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3"><label class="tf-label">From</label><input type="date" name="from" class="form-control" value="<?php echo sanitize($edit_filters['from'] ?? date('Y-m-d', strtotime('-30 days'))); ?>"></div>
                <div class="col-6 col-md-3"><label class="tf-label">To</label><input type="date" name="to" class="form-control" value="<?php echo sanitize($edit_filters['to'] ?? date('Y-m-d')); ?>"></div>
                <div class="col-6 col-md-3"><label class="tf-label">Client Filter</label><select name="client_id" class="form-select"><option value="0">All Clients</option><?php foreach ($clients_list as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php echo (int)($edit_filters['client_id'] ?? 0) === (int)$c['id'] ? 'selected' : ''; ?>><?php echo sanitize($c['client_name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-md-3"><label class="tf-label">Vendor Filter</label><select name="vendor_id" class="form-select"><option value="0">All Vendors</option><?php foreach ($vendors_list as $v): ?><option value="<?php echo (int)$v['id']; ?>" <?php echo (int)($edit_filters['vendor_id'] ?? 0) === (int)$v['id'] ? 'selected' : ''; ?>><?php echo sanitize($v['vendor_name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-12">
                    <label class="tf-label">Recipients (CSV) <span class="text-danger">*</span></label>
                    <input type="text" name="recipients_csv" class="form-control font-monospace small" required
                           value="<?php echo sanitize($edit_data['recipients_csv'] ?? ''); ?>"
                           placeholder="alice@example.com, bob@example.com">
                    <p class="form-text mb-0">Comma-separated email addresses of report recipients.</p>
                </div>
                <?php if ($edit_data): ?>
                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" name="is_active" id="is_active" class="form-check-input"
                               <?php echo !empty($edit_data['is_active']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="tf-label">Last Run</label>
                    <div class="text-secondary small"><?php echo $edit_data['last_run_at'] ? sanitize(date('M j, Y H:i', strtotime($edit_data['last_run_at']))) : '<span class="text-muted">Never</span>'; ?></div>
                </div>
                <?php endif; ?>
            </div>
            <div class="d-flex justify-content-end gap-2 pt-3 mt-3 border-top">
                <a href="<?php echo BASE_URL; ?>/reports/scheduled_reports.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i><?php echo $edit_data ? 'Update' : 'Create'; ?> Report</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('change', function(e) {
        if (e.target.name === 'frequency') {
            var group = document.getElementById('customCronGroup');
            group.style.display = (e.target.value === 'custom_cron') ? '' : 'none';
        }
    });
</script>
<?php else: ?>

<div class="tf-card">
    <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-semibold"><?php echo number_format($total); ?> scheduled report(s)</h5>
        <a href="<?php echo BASE_URL; ?>/reports/scheduled_reports.php?action=new" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>Schedule Report</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-sr align-middle mb-0">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Type</th>
                    <th>Group By</th>
                    <th>Frequency</th>
                    <th>Project</th>
                    <th>Recipients</th>
                    <th>Status</th>
                    <th>Next Run</th>
                    <th>Owner</th>
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reports)): ?>
                <tr><td colspan="11" class="text-center py-5 text-muted">No scheduled reports yet.</td></tr>
                <?php else: foreach ($reports as $r): ?>
                <tr>
                    <td class="fw-semibold"><?php echo sanitize($r['title']); ?></td>
                    <td><?php echo sanitize(ucfirst($r['report_type'])); ?></td>
                    <td><?php echo sanitize(ucfirst($r['group_by'])); ?></td>
                    <td><?php echo sanitize($r['frequency'] === 'custom_cron' ? $r['custom_cron'] : ucfirst($r['frequency'])); ?></td>
                    <td>
                        <?php
                        $filters = json_decode((string)$r['filters_json'], true) ?: [];
                        echo isset($filters['project_id']) && $filters['project_id'] ? sanitize($filters['project_id']) : '<span class="text-muted">All</span>';
                        ?>
                    </td>
                    <td class="text-muted small fw-monospace"><?php echo sanitize($r['recipients_csv']); ?></td>
                    <td><?php echo $r['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-light text-dark border">Paused</span>'; ?></td>
                    <td class="small text-secondary"><?php echo $r['next_run_at'] ? sanitize(date('M j, Y g:i a', strtotime($r['next_run_at']))) : '<span class="text-muted">—</span>'; ?></td>
                    <td class="small"><?php echo sanitize($r['owner_name'] ?? '—'); ?></td>
                    <td class="small text-secondary"><?php echo sanitize(date('M j, Y', strtotime($r['created_at']))); ?></td>
                    <td class="text-end">
                        <a href="<?php echo BASE_URL; ?>/reports/scheduled_reports.php?id=<?php echo (int)$r['id']; ?>&action=edit" class="btn btn-outline-primary btn-sm" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this scheduled report?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="post_action" value="delete">
                            <input type="hidden" name="sr_id" value="<?php echo (int)$r['id']; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total > $per_page): ?>
    <div class="tf-card-footer">
        <?php echo render_pagination($pagination, BASE_URL . '/reports/scheduled_reports.php'); ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
