<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$page_title = 'Send Email';

$clients = $pdo->query("SELECT id, client_name, email, contact_person FROM clients WHERE is_active = 1 AND email != '' AND email IS NOT NULL ORDER BY client_name")->fetchAll();
$client_count = count($clients);
$lists = $pdo->query("SELECT l.id, l.name, (SELECT COUNT(*) FROM email_list_entries e WHERE e.list_id = l.id AND e.is_unsubscribed = 0) AS cnt FROM email_lists l ORDER BY l.name")->fetchAll();

require_once __DIR__ . '/../helpers/layout_header.php';
?>


<div class="row g-4">
    <div class="col-12 col-lg-8">
        <div class="tf-card">
            <div class="tf-card-header">
                <h5 class="mb-0 fw-semibold">Compose Email</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="<?php echo BASE_URL; ?>/email/send.php" id="emailForm">
                    <?php echo csrf_field(); ?>

                    <div class="mb-3">
                        <label class="tf-label">Recipients <span class="text-danger">*</span></label>
                        <div class="d-flex gap-3 mb-2">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="recipient_mode" id="modeSelected" value="selected" checked onchange="toggleRecipientMode()">
                                <label class="form-check-label" for="modeSelected">Selected clients</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="recipient_mode" id="modeAll" value="all" onchange="toggleRecipientMode()">
                                <label class="form-check-label" for="modeAll">All clients with email</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="recipient_mode" id="modeList" value="list" onchange="toggleRecipientMode()">
                                <label class="form-check-label" for="modeList">Pick from email list</label>
                            </div>
                        </div>

                        <div id="listSelector" hidden>
                            <select class="form-select" name="list_id" id="list_id">
                                <option value="">— Select a list —</option>
                                <?php foreach ($lists as $l): ?>
                                <option value="<?php echo (int)$l['id']; ?>"><?php echo sanitize($l['name']); ?> (<?php echo (int)$l['cnt']; ?> active)</option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-text">Send to every active recipient in a saved email list.</p>
                        </div>

                        <div id="recipientSelector">
                            <select class="form-select" name="client_ids[]" id="client_ids" multiple size="8">
                                <?php foreach ($clients as $c): ?>
                                <option value="<?php echo $c['id']; ?>">
                                    <?php echo sanitize($c['client_name']); ?>
                                    <?php if ($c['contact_person']): ?>— <?php echo sanitize($c['contact_person']); ?><?php endif; ?>
                                    (<?php echo sanitize($c['email']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-text"><?php echo $client_count; ?> clients with email addresses</p>
                        </div>

                        <div id="allRecipientNotice" hidden>
                            <div class="alert alert-info d-flex align-items-start mb-0">
                                <i class="bi bi-info-circle me-2 mt-1"></i>
                                <div>Email will be sent to all <?php echo $client_count; ?> clients with email addresses.</div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="subject" class="tf-label">Subject <span class="text-danger">*</span></label>
                        <input type="text" id="subject" name="subject" class="form-control" required maxlength="300" placeholder="Enter email subject">
                    </div>

                    <div class="mb-3">
                        <label for="body" class="tf-label">Message <span class="text-danger">*</span></label>
                        <textarea id="body" name="body" class="form-control" rows="14" required placeholder="Write your email message here..."></textarea>
                        <p class="form-text">Plain text only. Each recipient will receive an individual email.</p>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 mt-3 border-top">
                        <button type="button" class="btn btn-secondary" onclick="previewEmail()"><i class="bi bi-eye"></i>Preview</button>
                        <button type="submit" class="btn btn-primary" id="sendBtn"><i class="bi bi-send"></i>Send Email</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-4">
        <div class="tf-card">
            <div class="tf-card-header">
                <h5 class="mb-0 fw-semibold">Recipients Overview</h5>
            </div>
            <div class="card-body">
                <div class="stat-label">Total clients with email</div>
                <div class="stat-value mb-4"><?php echo $client_count; ?></div>

                <hr>

                <div class="stat-label">Recipients selected</div>
                <div class="stat-value text-primary mb-4" id="selectedCount">0</div>

                <a href="<?php echo BASE_URL; ?>/email/history.php" class="btn btn-outline-primary btn-sm w-100">
                    <i class="bi bi-clock-history"></i>View Sent History
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div id="previewModal" class="tf-modal is-lg" hidden role="dialog" aria-modal="true" aria-labelledby="previewModal-title">
    <div class="tf-modal-backdrop" data-tf-modal-close></div>
    <div class="tf-modal-dialog">
        <div class="tf-modal-header">
            <h3 id="previewModal-title" class="tf-modal-title">Email Preview</h3>
            <button type="button" class="tf-modal-close" data-tf-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="tf-modal-body">
            <div class="mb-3">
                <label class="tf-label">To:</label>
                <p class="mb-0" style="white-space: pre-line;" id="previewTo">—</p>
            </div>
            <div class="mb-3">
                <label class="tf-label">Subject:</label>
                <p class="mb-0" id="previewSubject">—</p>
            </div>
            <div class="mb-0">
                <label class="tf-label">Message:</label>
                <pre class="preview-pre" id="previewBody">—</pre>
            </div>
        </div>
        <div class="tf-modal-footer">
            <button type="button" class="btn btn-secondary" data-tf-modal-close>Close</button>
        </div>
    </div>
</div>

<script>
function toggleRecipientMode() {
    const mode = document.querySelector('input[name="recipient_mode"]:checked').value;
    document.getElementById('recipientSelector').hidden = mode !== 'selected';
    document.getElementById('allRecipientNotice').hidden = mode !== 'all';
    document.getElementById('listSelector').hidden = mode !== 'list';
}

document.getElementById('client_ids').addEventListener('change', function() {
    document.getElementById('selectedCount').textContent = this.selectedOptions.length;
});

document.getElementById('emailForm').addEventListener('submit', function(e) {
    const mode = document.querySelector('input[name="recipient_mode"]:checked').value;
    if (mode === 'selected' && document.getElementById('client_ids').selectedOptions.length === 0) {
        e.preventDefault();
        alert('Please select at least one recipient.');
        return;
    }
    if (mode === 'list' && !document.getElementById('list_id').value) {
        e.preventDefault();
        alert('Please pick an email list.');
        return;
    }
    let target = mode === 'all' ? 'ALL clients'
        : mode === 'list' ? 'the selected email list'
        : document.getElementById('client_ids').selectedOptions.length + ' selected client(s)';
    if (!confirm('Queue this email for ' + target + '?')) {
        e.preventDefault();
        return;
    }
    const btn = document.getElementById('sendBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Queuing…';
});

function previewEmail() {
    const subject = document.getElementById('subject').value || '(no subject)';
    const body = document.getElementById('body').value || '(empty)';
    const mode = document.querySelector('input[name="recipient_mode"]:checked').value;
    const select = document.getElementById('client_ids');
    let recipients;

    if (mode === 'all') {
        recipients = 'All clients with email (<?php echo $client_count; ?> recipients)';
    } else if (mode === 'list') {
        const sel = document.getElementById('list_id');
        recipients = sel.selectedOptions.length ? 'Email list: ' + sel.selectedOptions[0].textContent : '(no list selected)';
    } else {
        const selected = Array.from(select.selectedOptions).map(o => o.textContent.trim());
        recipients = selected.length ? selected.join('\n') : '(none selected)';
    }

    document.getElementById('previewTo').textContent = recipients;
    document.getElementById('previewSubject').textContent = subject;
    document.getElementById('previewBody').textContent = body;
    openModal('previewModal');
}

document.getElementById('selectedCount').textContent = document.getElementById('client_ids').selectedOptions.length;
</script>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
