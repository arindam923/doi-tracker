<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$page_title = 'Send Email';

if (function_exists('email_ensure_starter_templates')) {
    email_ensure_starter_templates($pdo, $_SESSION['user_id'] ?? null);
}

$clients = $pdo->query("SELECT id, client_name, email, contact_person FROM clients WHERE is_active = 1 AND email != '' AND email IS NOT NULL ORDER BY client_name")->fetchAll();
$client_count = count($clients);
$lists = $pdo->query("SELECT l.id, l.name, (SELECT COUNT(*) FROM email_list_entries e WHERE e.list_id = l.id AND e.is_unsubscribed = 0) AS cnt FROM email_lists l ORDER BY l.name")->fetchAll();
$templates = [];
try {
    $templates = $pdo->query("SELECT id, name, subject, html_body FROM email_templates ORDER BY is_default DESC, name ASC")->fetchAll();
} catch (Throwable $e) {
    error_log('compose templates: ' . $e->getMessage());
}

$tpl_payload = [];
foreach ($templates as $t) {
    $plain = trim(html_entity_decode(strip_tags((string)($t['html_body'] ?? '')), ENT_QUOTES, 'UTF-8'));
    $plain = preg_replace("/[ \t]+/", ' ', $plain);
    $plain = preg_replace("/\n{3,}/", "\n\n", $plain);
    $tpl_payload[] = [
        'id' => (int)$t['id'],
        'name' => (string)$t['name'],
        'subject' => (string)$t['subject'],
        'body' => (string)$plain,
    ];
}

require_once __DIR__ . '/../helpers/layout_header.php';
?>

<div class="tf-page">
    <div class="tf-compose-hero">
        <h1>Compose email</h1>
        <p>Queue a message to clients or a saved list. Each recipient gets their own copy.</p>
    </div>

    <div class="tf-compose-grid">
        <div class="tf-card">
            <div class="tf-card-header">
                <div>
                    <h5 class="tf-card-title">Message</h5>
                    <p class="tf-card-subtitle">Pick who should receive it, then write the note.</p>
                </div>
            </div>
            <div class="tf-card-body">
                <form method="POST" action="<?php echo BASE_URL; ?>/email/send.php" id="emailForm" class="tf-form">
                    <?php echo csrf_field(); ?>

                    <div class="tf-field mb-4">
                        <label class="tf-label">Recipients <span class="tf-required">*</span></label>
                        <div class="tf-mode-pills" role="radiogroup" aria-label="Recipient mode">
                            <label class="tf-mode-pill is-on" id="pillSelected">
                                <input class="form-check-input" type="radio" name="recipient_mode" id="modeSelected" value="selected" checked>
                                <i class="bi bi-people" aria-hidden="true"></i>
                                <div>
                                    <strong>Selected clients</strong>
                                    <span>Search and pick specific accounts</span>
                                </div>
                            </label>
                            <label class="tf-mode-pill" id="pillAll">
                                <input class="form-check-input" type="radio" name="recipient_mode" id="modeAll" value="all">
                                <i class="bi bi-globe" aria-hidden="true"></i>
                                <div>
                                    <strong>All clients</strong>
                                    <span><?php echo (int)$client_count; ?> with an email on file</span>
                                </div>
                            </label>
                            <label class="tf-mode-pill" id="pillList">
                                <input class="form-check-input" type="radio" name="recipient_mode" id="modeList" value="list">
                                <i class="bi bi-list-check" aria-hidden="true"></i>
                                <div>
                                    <strong>Email list</strong>
                                    <span>Every active person on a list</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="tf-field mb-4" id="listSelector" hidden>
                        <label for="list_id" class="tf-label">List</label>
                        <select class="form-select" name="list_id" id="list_id">
                            <option value="">Choose a list</option>
                            <?php foreach ($lists as $l): ?>
                            <option value="<?php echo (int)$l['id']; ?>"><?php echo sanitize($l['name']); ?> (<?php echo (int)$l['cnt']; ?> active)</option>
                            <?php endforeach; ?>
                        </select>
                        <p class="tf-help">Sends to every active, non-unsubscribed address on that list.</p>
                    </div>

                    <div class="tf-field mb-4" id="recipientSelector">
                        <label for="client_ids" class="tf-label">Clients</label>
                        <select class="form-select" name="client_ids[]" id="client_ids" multiple>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>">
                                <?php echo sanitize($c['client_name']); ?>
                                <?php if ($c['contact_person']): ?> — <?php echo sanitize($c['contact_person']); ?><?php endif; ?>
                                (<?php echo sanitize($c['email']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="tf-help"><?php echo (int)$client_count; ?> clients available</p>
                    </div>

                    <div id="allRecipientNotice" hidden>
                        <div class="alert alert-info d-flex align-items-start mb-4">
                            <i class="bi bi-info-circle me-2 mt-1"></i>
                            <div>This will queue mail for all <?php echo (int)$client_count; ?> clients with an email address.</div>
                        </div>
                    </div>

                    <?php if ($tpl_payload): ?>
                    <div class="tf-field mb-4">
                        <label class="tf-label">Start from a template</label>
                        <div class="tf-tpl-quick" id="tplQuick">
                            <button type="button" data-tpl-id="0" class="is-on">Blank</button>
                            <?php foreach ($tpl_payload as $t): ?>
                            <button type="button" data-tpl-id="<?php echo (int)$t['id']; ?>"><?php echo sanitize($t['name']); ?></button>
                            <?php endforeach; ?>
                        </div>
                        <p class="tf-help">HTML templates are converted to plain text for this composer.</p>
                    </div>
                    <script type="application/json" id="tplData"><?php echo json_encode($tpl_payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE); ?></script>
                    <?php endif; ?>

                    <div class="tf-field mb-3">
                        <label for="subject" class="tf-label">Subject <span class="tf-required">*</span></label>
                        <input type="text" id="subject" name="subject" class="form-control" required maxlength="300" placeholder="A clear subject line">
                    </div>

                    <div class="tf-field mb-3">
                        <label for="body" class="tf-label">Message <span class="tf-required">*</span></label>
                        <textarea id="body" name="body" class="form-control" rows="14" required placeholder="Write the email…"></textarea>
                        <p class="tf-help">Plain text. Each recipient receives an individual copy.</p>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="previewEmail()"><i class="bi bi-eye"></i> Preview</button>
                        <button type="submit" class="btn btn-primary" id="sendBtn"><i class="bi bi-send"></i> Queue email</button>
                    </div>
                </form>
            </div>
        </div>

        <aside class="tf-compose-aside">
            <div class="tf-card">
                <div class="tf-card-header">
                    <h5 class="tf-card-title">At a glance</h5>
                </div>
                <div class="tf-card-body">
                    <div class="tf-stat-tile">
                        <div class="stat-label">Clients with email</div>
                        <div class="stat-value"><?php echo (int)$client_count; ?></div>
                    </div>
                    <div class="tf-stat-tile">
                        <div class="stat-label">Recipients selected</div>
                        <div class="stat-value text-primary" id="selectedCount">0</div>
                    </div>
                    <div class="tf-stat-tile">
                        <div class="stat-label">Saved lists</div>
                        <div class="stat-value"><?php echo count($lists); ?></div>
                    </div>
                    <a href="<?php echo BASE_URL; ?>/email/history.php" class="btn btn-outline-primary btn-sm w-100 mt-3">
                        <i class="bi bi-clock-history"></i> Sent history
                    </a>
                    <a href="<?php echo BASE_URL; ?>/email/templates.php" class="btn btn-outline-secondary btn-sm w-100 mt-2">
                        <i class="bi bi-file-earmark-text"></i> HTML templates
                    </a>
                </div>
            </div>
        </aside>
    </div>
</div>

<div id="previewModal" class="tf-modal is-lg" hidden role="dialog" aria-modal="true" aria-labelledby="previewModal-title">
    <div class="tf-modal-backdrop" data-tf-modal-close></div>
    <div class="tf-modal-dialog">
        <div class="tf-modal-header">
            <h3 id="previewModal-title" class="tf-modal-title">Email preview</h3>
            <button type="button" class="tf-modal-close" data-tf-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="tf-modal-body">
            <div class="mb-3">
                <label class="tf-label">To</label>
                <p class="mb-0" style="white-space: pre-line;" id="previewTo">—</p>
            </div>
            <div class="mb-3">
                <label class="tf-label">Subject</label>
                <p class="mb-0" id="previewSubject">—</p>
            </div>
            <div class="mb-0">
                <label class="tf-label">Message</label>
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
    document.querySelectorAll('.tf-mode-pill').forEach((pill) => {
        const on = pill.querySelector('input').checked;
        pill.classList.toggle('is-on', on);
    });
    updateSelectedCount();
}

function updateSelectedCount() {
    const mode = document.querySelector('input[name="recipient_mode"]:checked').value;
    const el = document.getElementById('selectedCount');
    if (mode === 'all') {
        el.textContent = '<?php echo (int)$client_count; ?>';
        return;
    }
    if (mode === 'list') {
        const sel = document.getElementById('list_id');
        const opt = sel.selectedOptions[0];
        const match = opt && opt.textContent.match(/\((\d+)\s+active\)/);
        el.textContent = match ? match[1] : '—';
        return;
    }
    el.textContent = document.getElementById('client_ids').selectedOptions.length;
}

document.querySelectorAll('input[name="recipient_mode"]').forEach((input) => {
    input.addEventListener('change', toggleRecipientMode);
});
document.getElementById('client_ids').addEventListener('change', updateSelectedCount);
document.getElementById('list_id').addEventListener('change', updateSelectedCount);

document.getElementById('tplQuick') && document.getElementById('tplQuick').addEventListener('click', function (e) {
    const btn = e.target.closest('button');
    if (!btn) return;
    const id = btn.getAttribute('data-tpl-id');
    let subject = '';
    let body = '';
    const raw = document.getElementById('tplData');
    if (id && id !== '0' && raw) {
        const list = JSON.parse(raw.textContent || '[]');
        const hit = list.filter(function (t) { return String(t.id) === String(id); })[0];
        if (hit) {
            subject = hit.subject || '';
            body = hit.body || '';
        }
    }
    document.getElementById('subject').value = subject;
    document.getElementById('body').value = body;
    document.querySelectorAll('#tplQuick button').forEach(function (b) { b.classList.toggle('is-on', b === btn); });
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
        recipients = 'All clients with email (<?php echo (int)$client_count; ?> recipients)';
    } else if (mode === 'list') {
        const sel = document.getElementById('list_id');
        recipients = sel.selectedOptions.length && sel.value ? 'Email list: ' + sel.selectedOptions[0].textContent : '(no list selected)';
    } else {
        const selected = Array.from(select.selectedOptions).map(o => o.textContent.trim());
        recipients = selected.length ? selected.join('\n') : '(none selected)';
    }

    document.getElementById('previewTo').textContent = recipients;
    document.getElementById('previewSubject').textContent = subject;
    document.getElementById('previewBody').textContent = body;
    openModal('previewModal');
}

toggleRecipientMode();
</script>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
