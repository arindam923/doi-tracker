<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

function escape_csv($value) {
    $value = (string)$value;
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'])) {
        $value = "'" . $value;
    }
    return $value;
}

$export = tf_get_string('export', '') === 'csv';
$page = $export ? 1 : max(1, tf_get_int('page', 1));
$per_page = $export ? 0 : 50;
$status_raw = tf_get_string('status', '');
$status_filter = in_array($status_raw, ['sent', 'failed', 'queued', 'retrying'], true) ? $status_raw : '';
$batch_filter = tf_get_string('batch_id', '');
$date_from = tf_get_date('from', '');
$date_to = tf_get_date('to', '');

$where = [];
$params = [];

if (in_array($status_filter, ['sent', 'failed', 'queued', 'retrying'], true)) {
    $where[] = "e.status = ?";
    $params[] = $status_filter;
}
if ($batch_filter !== '') {
    $where[] = "e.batch_id = ?";
    $params[] = $batch_filter;
}
if ($date_from !== '') {
    $where[] = "e.created_at >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where[] = "e.created_at <= DATE_ADD(?, INTERVAL 1 DAY)";
    $params[] = $date_to;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM sent_emails e $where_sql");
    $count_stmt->execute($params);
    $total = (int)($count_stmt->fetch()['cnt'] ?? 0);
} catch (Throwable $e) {
    error_log('email history count failed: ' . $e->getMessage());
    $total = 0;
}
$pagination = [];
if (!$export) {
    $pagination = paginate($total, $per_page, $page);
}

try {
    $stmt = $pdo->prepare("
    SELECT e.*, u.username as sent_by_name
    FROM sent_emails e
    LEFT JOIN users u ON e.sent_by = u.id
    $where_sql
    ORDER BY e.created_at DESC
    " . ($export ? '' : "LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}")
    );
    $stmt->execute($params);
    $emails = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('email history fetch failed: ' . $e->getMessage());
    $emails = [];
    if (!$export) $total = 0;
}

if ($export) {
    $filename = 'email_history_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Time', 'Recipient Email', 'Recipient Name', 'Subject', 'Status', 'Resend ID', 'Error Message', 'Sent By']);
    foreach ($emails as $e) {
        fputcsv($output, [
            escape_csv($e['created_at']),
            escape_csv($e['recipient_email']),
            escape_csv($e['recipient_name']),
            escape_csv($e['subject']),
            escape_csv($e['status']),
            escape_csv($e['resend_id']),
            escape_csv($e['error_message']),
            escape_csv($e['sent_by_name']),
        ]);
    }
    fclose($output);
    exit;
}

$page_title = 'Email History';
$page_actions = '<a href="' . BASE_URL . '/email/compose.php" class="btn btn-primary btn-sm"><i class="bi bi-send"></i>Compose Email</a>';
require_once __DIR__ . '/../helpers/layout_header.php';
?>


<div class="tf-card mb-4">
    <form method="GET" class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label for="status" class="tf-label">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="queued" <?php echo $status_filter === 'queued' ? 'selected' : ''; ?>>Queued</option>
                    <option value="retrying" <?php echo $status_filter === 'retrying' ? 'selected' : ''; ?>>Retrying</option>
                    <option value="sent" <?php echo $status_filter === 'sent' ? 'selected' : ''; ?>>Sent</option>
                    <option value="failed" <?php echo $status_filter === 'failed' ? 'selected' : ''; ?>>Failed</option>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label for="from" class="tf-label">From Date</label>
                <input type="date" id="from" name="from" class="form-control" value="<?php echo $date_from; ?>">
            </div>
            <div class="col-12 col-md-3">
                <label for="to" class="tf-label">To Date</label>
                <input type="date" id="to" name="to" class="form-control" value="<?php echo $date_to; ?>">
            </div>
            <div class="col-12 col-md-3">
                <label for="batch_id" class="tf-label">Batch ID</label>
                <input type="text" id="batch_id" name="batch_id" class="form-control" value="<?php echo sanitize($batch_filter); ?>" placeholder="YYYYMMDDHHMMSS-xxxx">
            </div>
            <div class="col-12 col-md-3 d-flex justify-content-end gap-2">
                <a href="<?php echo BASE_URL; ?>/email/history.php" class="btn btn-outline-secondary btn-sm">Clear</a>
                <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-funnel"></i>Filter</button>
            </div>
        </div>
    </form>
    <?php
    $export_params = array_filter(['status' => $status_filter, 'batch_id' => $batch_filter, 'from' => $date_from, 'to' => $date_to], static fn($v) => $v !== '' && $v !== null);
    $export_href = BASE_URL . '/email/history.php?export=csv' . ($export_params ? '&' . http_build_query($export_params) : '');
    $history_params = array_filter(['status' => $status_filter, 'batch_id' => $batch_filter, 'from' => $date_from, 'to' => $date_to], static fn($v) => $v !== '' && $v !== null);
    $history_base = BASE_URL . '/email/history.php' . ($history_params ? '?' . http_build_query($history_params) : '');
    ?>
    <div class="card-footer bg-white border-top d-flex justify-content-end py-2">
        <a href="<?php echo $export_href; ?>" class="btn btn-outline-success btn-sm">
            <i class="bi bi-download"></i>Export CSV
        </a>
    </div>
</div>

<div class="tf-card">
    <div class="table-responsive">
        <table class="table table-hover table-emails align-middle mb-0">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Recipient</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th>Sent By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($emails)): ?>
                <tr>
                    <td colspan="6" class="text-center py-5 text-muted">
                        <i class="bi bi-envelope d-block mb-2" style="font-size: 2.5rem; color: #cbd5e1;"></i>
                        <p class="fw-semibold text-dark mb-1">No emails sent yet</p>
                        <a href="<?php echo BASE_URL; ?>/email/compose.php">Send your first email</a>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($emails as $e): ?>
                <tr>
                    <td class="text-nowrap small text-muted"><?php echo time_ago($e['created_at']); ?></td>
                    <td>
                        <strong><?php echo sanitize($e['recipient_name'] ?? $e['recipient_email']); ?></strong>
                        <div class="small text-muted mt-1"><?php echo sanitize($e['recipient_email']); ?></div>
                    </td>
                    <td><?php echo sanitize(mb_substr($e['subject'], 0, 60)) . (mb_strlen($e['subject']) > 60 ? '…' : ''); ?></td>
                    <td>
                        <?php if ($e['status'] === 'sent'): ?>
                        <span class="badge bg-success">Sent</span>
                        <?php elseif ($e['status'] === 'queued'): ?>
                        <span class="badge bg-light text-dark border">Queued</span>
                        <?php elseif ($e['status'] === 'retrying'): ?>
                        <span class="badge bg-warning" title="<?php echo sanitize($e['error_message'] ?? ''); ?>">Retrying</span>
                        <?php else: ?>
                        <span class="badge bg-danger" title="<?php echo sanitize($e['error_message'] ?? ''); ?>">Failed</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo sanitize($e['sent_by_name'] ?? '-'); ?></td>
                    <td>
                        <button class="btn btn-outline-secondary btn-sm btn-icon" data-view-email='<?php echo htmlspecialchars(json_encode($e), ENT_QUOTES, 'UTF-8'); ?>' aria-label="View email">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!$export && !empty($pagination)) echo render_pagination($pagination, $history_base ?? BASE_URL . '/email/history.php'); ?>

<!-- View Email Modal -->
<div id="viewEmailModal" class="tf-modal is-lg" hidden role="dialog" aria-modal="true" aria-labelledby="viewEmailModal-title">
    <div class="tf-modal-backdrop" data-tf-modal-close></div>
    <div class="tf-modal-dialog">
        <div class="tf-modal-header">
            <h3 id="viewEmailModal-title" class="tf-modal-title">Email Details</h3>
            <button type="button" class="tf-modal-close" data-tf-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="tf-modal-body">
            <div class="mb-3">
                <label class="tf-label">Recipient:</label>
                <p class="mb-0" id="viewRecipient">—</p>
            </div>
            <div class="mb-3">
                <label class="tf-label">Subject:</label>
                <p class="mb-0" id="viewSubject">—</p>
            </div>
            <div class="mb-3">
                <label class="tf-label">Status:</label>
                <p class="mb-0" id="viewStatus">—</p>
            </div>
            <div class="mb-3" id="viewErrorWrap" hidden>
                <label class="form-label small fw-semibold text-danger">Error:</label>
                <p class="mb-0 text-danger" id="viewError">—</p>
            </div>
            <div class="mb-3">
                <label class="tf-label">Resend ID:</label>
                <p class="mb-0 small" style="font-family: ui-monospace, monospace;" id="viewResendId">—</p>
            </div>
            <div class="mb-0">
                <label class="tf-label">Message:</label>
                <pre class="preview-pre" id="viewBody">—</pre>
            </div>
        </div>
        <div class="tf-modal-footer">
            <button type="button" class="btn btn-secondary" data-tf-modal-close>Close</button>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('[data-view-email]').forEach(btn => {
    btn.addEventListener('click', () => {
        const email = JSON.parse(btn.dataset.viewEmail);
        document.getElementById('viewRecipient').textContent = (email.recipient_name || '') + ' <' + email.recipient_email + '>';
        document.getElementById('viewSubject').textContent = email.subject;
        document.getElementById('viewBody').textContent = email.body;
        document.getElementById('viewResendId').textContent = email.resend_id || '—';

        const statusEl = document.getElementById('viewStatus');
        if (email.status === 'sent') {
            statusEl.innerHTML = '<span class="badge bg-success">Sent</span>';
        } else {
            statusEl.innerHTML = '<span class="badge bg-danger">Failed</span>';
        }

        const errorWrap = document.getElementById('viewErrorWrap');
        if (email.error_message) {
            errorWrap.hidden = false;
            document.getElementById('viewError').textContent = email.error_message;
        } else {
            errorWrap.hidden = true;
        }

        openModal('viewEmailModal');
    });
});
</script>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
