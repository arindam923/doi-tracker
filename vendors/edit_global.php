<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/email.php';
require_role(['super_admin', 'campaign_manager']);

$id = intval($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/vendors/global.php');

$stmt = $pdo->prepare("SELECT * FROM global_vendors WHERE id = ?");
$stmt->execute([$id]);
$vendor = $stmt->fetch();
if (!$vendor) {
    set_flash('danger', 'Vendor not found.');
    redirect(BASE_URL . '/vendors/global.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/vendors/edit_global.php?id=' . $id);
    }

    if (($_POST['action'] ?? '') === 'upload_emails') {
        if (!tf_traffic_type_includes($vendor['traffic_type'] ?? '', 'Email')) {
            set_flash('danger', 'Email database uploads are only available for Email vendors.');
            redirect(BASE_URL . '/vendors/edit_global.php?id=' . $id);
        }
        if (!isset($_FILES['contact_file']) || $_FILES['contact_file']['error'] !== UPLOAD_ERR_OK) {
            set_flash('danger', 'Select a valid CSV or XLSX file.');
            redirect(BASE_URL . '/vendors/edit_global.php?id=' . $id . '#email-db');
        }
        $tmp = $_FILES['contact_file']['tmp_name'];
        if (filesize($tmp) > 10 * 1024 * 1024) {
            set_flash('danger', 'File is too large (max 10MB).');
            redirect(BASE_URL . '/vendors/edit_global.php?id=' . $id . '#email-db');
        }
        try {
            $list_id = ensure_vendor_email_list($pdo, $id, (int)($_SESSION['user_id'] ?? 0));
            $rows = email_parse_contact_upload($tmp, $_FILES['contact_file']['name'] ?? 'upload.csv');
            $deduped = email_dedupe($rows);
            $inserted = upsert_list_entries($pdo, $list_id, $deduped['unique'], $id);
            audit_log($pdo, 'upload', 'vendor_email_list', $id, null, ['inserted' => $inserted]);
            set_flash('success', 'Imported ' . $inserted . ' new address(es). ' .
                $deduped['dupes'] . ' duplicate(s) skipped, ' . $deduped['invalid'] . ' invalid row(s) dropped.');
        } catch (Throwable $e) {
            error_log('Vendor email upload failed: ' . $e->getMessage());
            set_flash('danger', 'Import failed: ' . $e->getMessage());
        }
        redirect(BASE_URL . '/vendors/edit_global.php?id=' . $id . '#email-db');
    }

    $vendor_name = trim($_POST['vendor_name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telegram = trim($_POST['telegram'] ?? '');
    $skype = trim($_POST['skype'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $traffic_type = implode(',', tf_normalize_traffic_types($_POST['traffic_type'] ?? []));
    $vendor_status = $_POST['vendor_status'] ?? 'approved';
    $default_payout = floatval($_POST['default_payout'] ?? 0);
    $currency = $_POST['currency'] ?? 'USD';
    $daily_cap = intval($_POST['daily_cap'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $global_postback_url = trim($_POST['global_postback_url'] ?? '');
    $push_to_all = isset($_POST['push_to_all']) && $_POST['push_to_all'] === '1';

    if (empty($vendor_name)) {
        set_flash('danger', 'Vendor name is required.');
        redirect(BASE_URL . '/vendors/edit_global.php?id=' . $id);
    }
    if (!array_key_exists($vendor_status, tf_vendor_statuses())) $vendor_status = 'approved';
    if (!in_array($currency, tf_currencies(), true)) $currency = 'USD';
    if (!tf_is_valid_postback_url($global_postback_url)) {
        set_flash('danger', 'Global postback URL must be a valid HTTP or HTTPS URL.');
        redirect(BASE_URL . '/vendors/edit_global.php?id=' . $id);
    }

    $pdo->prepare("UPDATE global_vendors SET vendor_name=?, contact_person=?, email=?, telegram=?, skype=?, phone=?, traffic_type=?, vendor_status=?, default_payout=?, currency=?, daily_cap=?, notes=?, global_postback_url=?, updated_at=NOW() WHERE id=?")
        ->execute([$vendor_name, $contact_person, $email, $telegram, $skype, $phone, $traffic_type, $vendor_status, $default_payout, $currency, $daily_cap, $notes, $global_postback_url, $id]);

    if ($push_to_all) {
        $prev_url = trim((string)($vendor['global_postback_url'] ?? ''));
        if ($global_postback_url !== '') {
            $cleared = $pdo->prepare("UPDATE project_vendor pv JOIN global_vendors gv ON gv.id = pv.vendor_id SET pv.postback_url = NULL WHERE pv.vendor_id = ? AND pv.postback_url = gv.global_postback_url")->execute([$id]);
            $cnt = $pdo->prepare("SELECT ROW_COUNT()")->execute() ? 0 : 0;
            try { $cnt_stmt = $pdo->query("SELECT ROW_COUNT()"); $cnt = (int)$cnt_stmt->fetchColumn(); } catch (Throwable $e) {}
        } else {
            $cnt = 0;
        }
        audit_log($pdo, 'update', 'vendor', $id, ['push_to_all' => false], ['push_to_all' => true, 'global_postback_url' => $global_postback_url !== '' ? 'set' : 'cleared']);
        set_flash('success', 'Vendor updated.' . ($global_postback_url !== '' ? ' Overrides that matched the global URL were cleared — assignments now inherit the current global postback.' : ''));
        regenerate_csrf_token();
        redirect(BASE_URL . '/vendors/global.php');
    }

    if (tf_traffic_type_includes($traffic_type, 'Email')) {
        ensure_vendor_email_list($pdo, $id, (int)($_SESSION['user_id'] ?? 0));
    }

    audit_log($pdo, 'update', 'vendor', $id, [
        'vendor_name' => $vendor['vendor_name'],
        'global_postback_configured' => !empty($vendor['global_postback_url'])
    ], [
        'vendor_name' => $vendor_name,
        'global_postback_configured' => $global_postback_url !== ''
    ]);

    regenerate_csrf_token();
    set_flash('success', 'Vendor updated.');
    redirect(BASE_URL . '/vendors/global.php');
}

$page_title = 'Edit Vendor';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<div class="mb-3">
    <a href="<?php echo BASE_URL; ?>/vendors/global.php" class="text-decoration-none text-secondary d-inline-flex align-items-center gap-1 small fw-semibold">
        <i class="bi bi-arrow-left"></i>Back to Vendors
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-12 col-lg-10">
        <div class="tf-card">
            <div class="tf-card-header">
                <div class="d-flex flex-column">
                    <h5 class="mb-0 fw-semibold">Edit: <?php echo sanitize($vendor['vendor_name']); ?></h5>
                    <small class="text-muted"><code><?php echo sanitize($vendor['vendor_code']); ?></code></small>
                </div>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrf_field(); ?>

                    <div class="row g-3">
                        <div class="col-12 col-md-8">
                            <label for="vendor_name" class="tf-label">Vendor Name <span class="text-danger">*</span></label>
                            <input type="text" id="vendor_name" name="vendor_name" class="form-control"
                                   value="<?php echo sanitize($vendor['vendor_name']); ?>" required>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="vendor_status" class="tf-label">Master Status</label>
                            <select id="vendor_status" name="vendor_status" class="form-select">
                                <?php foreach (tf_vendor_statuses() as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo ($vendor['vendor_status'] ?? 'approved') === $key ? 'selected' : ''; ?>><?php echo sanitize($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="traffic_type" class="tf-label">Traffic Type</label>
                            <select id="traffic_type" name="traffic_type[]" class="form-select" multiple size="5">
                                <?php foreach (tf_traffic_types() as $t): ?>
                                <option value="<?php echo $t; ?>" <?php echo tf_traffic_type_includes($vendor['traffic_type'] ?? '', $t) ? 'selected' : ''; ?>><?php echo sanitize($t); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-text mb-0 small">Hold Ctrl/Cmd to select multiple traffic types.</p>
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="contact_person" class="tf-label">Contact Person</label>
                            <input type="text" id="contact_person" name="contact_person" class="form-control"
                                   value="<?php echo sanitize($vendor['contact_person'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="email" class="tf-label">Email</label>
                            <input type="email" id="email" name="email" class="form-control"
                                   value="<?php echo sanitize($vendor['email'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="phone" class="tf-label">Phone</label>
                            <input type="text" id="phone" name="phone" class="form-control"
                                   value="<?php echo sanitize($vendor['phone'] ?? ''); ?>">
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="telegram" class="tf-label">Telegram</label>
                            <input type="text" id="telegram" name="telegram" class="form-control"
                                   value="<?php echo sanitize($vendor['telegram'] ?? ''); ?>">
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="skype" class="tf-label">Skype</label>
                            <input type="text" id="skype" name="skype" class="form-control"
                                   value="<?php echo sanitize($vendor['skype'] ?? ''); ?>">
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="currency" class="tf-label">Currency</label>
                            <select id="currency" name="currency" class="form-select">
                                <?php foreach (tf_currencies() as $c): ?>
                                <option value="<?php echo $c; ?>" <?php echo ($vendor['currency'] ?? 'USD') === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-6">
                            <label for="default_payout" class="tf-label">Default Payout (per conversion)</label>
                            <input type="number" id="default_payout" name="default_payout" class="form-control"
                                   value="<?php echo $vendor['default_payout']; ?>" step="0.01" min="0">
                        </div>

                        <div class="col-12">
                            <label for="global_postback_url" class="tf-label">Global Postback URL — reusable for all campaigns</label>
                            <div class="input-group">
                                <input type="url" id="global_postback_url" name="global_postback_url" class="form-control"
                                       value="<?php echo sanitize($vendor['global_postback_url'] ?? ''); ?>"
                                       placeholder="https://vendor.com/postback?click_id={click_id}&status={status}&payout={payout}&tx={transaction_id}&s1={sub1}">
                                <?php if (!empty($vendor['global_postback_url'])) echo tf_copy_button($vendor['global_postback_url'], 'btn btn-outline-secondary'); ?>
                            </div>
                            <p class="form-text mb-0 small">Reusable default for every project assignment. Blank assignments inherit this URL instantly via <code>tf_effective_postback_url()</code>. Supports <code>{click_id}</code>, <code>{status}</code>, <code>{sale_amount}</code>, <code>{currency}</code>, <code>{payout}</code>, <code>{transaction_id}</code>, <code>{sub1}</code>…<code>{sub5}</code>. Saving blank clears the global postback.</p>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" value="1" id="push_to_all" name="push_to_all">
                                <label class="form-check-label small" for="push_to_all">Push to all assignments now — clear project overrides that exactly match this global URL so they inherit future changes</label>
                            </div>
                            <?php
                            $override_cnt = 0;
                            try {
                                $oc = $pdo->prepare("SELECT COUNT(*) FROM project_vendor WHERE vendor_id = ? AND postback_url IS NOT NULL AND postback_url <> ''");
                                $oc->execute([$id]);
                                $override_cnt = (int)$oc->fetchColumn();
                            } catch (Throwable $e) {}
                            if ($override_cnt > 0): ?>
                            <p class="small text-muted mt-1"><?php echo $override_cnt; ?> assignment(s) currently have a project-specific override.</p>
                            <?php endif; ?>
                        </div>

                        <div class="col-6 col-md-6">
                            <label for="daily_cap" class="tf-label">Daily Cap</label>
                            <input type="number" id="daily_cap" name="daily_cap" class="form-control"
                                   value="<?php echo (int)$vendor['daily_cap']; ?>" min="0">
                            <p class="form-text mb-0 small">0 = unlimited</p>
                        </div>

                        <div class="col-12">
                            <label for="notes" class="tf-label">Notes</label>
                            <textarea id="notes" name="notes" class="form-control" rows="3"><?php echo sanitize($vendor['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 mt-3 border-top">
                        <a href="<?php echo BASE_URL; ?>/vendors/global.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i>Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
if (tf_traffic_type_includes($vendor['traffic_type'] ?? '', 'Email')):
    $list_id = ensure_vendor_email_list($pdo, $id, (int)($_SESSION['user_id'] ?? 0));
    $status_filter = $_GET['estatus'] ?? '';
    $search = trim($_GET['esearch'] ?? '');
    $epage = max(1, intval($_GET['page'] ?? 1));
    $where = ['ele.list_id = ?'];
    $params = [$list_id];
    if (in_array($status_filter, ['active', 'unsubscribed', 'bounced', 'invalid'], true)) {
        $where[] = 'ele.status = ?';
        $params[] = $status_filter;
    }
    if ($search !== '') {
        $where[] = '(ele.email LIKE ? OR ele.first_name LIKE ? OR ele.last_name LIKE ? OR ele.name LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $where_sql = 'WHERE ' . implode(' AND ', $where);
    $total_contacts = email_count_scalar($pdo, "SELECT COUNT(*) FROM email_list_entries ele $where_sql", $params);
    $epagination = paginate($total_contacts, 50, $epage);
    $estmt = $pdo->prepare("SELECT ele.* FROM email_list_entries ele $where_sql ORDER BY ele.added_at DESC LIMIT {$epagination['per_page']} OFFSET {$epagination['offset']}");
    $estmt->execute($params);
    $entries = $estmt->fetchAll();
    $list_total = email_count_scalar($pdo, 'SELECT COUNT(*) FROM email_list_entries WHERE list_id = ?', [$list_id]);
?>
<div class="row justify-content-center mt-4" id="email-db">
    <div class="col-12 col-lg-10">
        <div class="tf-card mb-4">
            <div class="tf-card-header">
                <div>
                    <h5 class="mb-0 fw-semibold"><i class="bi bi-envelope-at me-1"></i>Email Database</h5>
                    <p class="text-muted small mb-0">Upload once. Later files append new addresses and skip duplicates. Reused on every campaign for this vendor.</p>
                </div>
                <span class="badge bg-light text-dark border"><?php echo number_format($list_total); ?> contacts</span>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end mb-3">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="upload_emails">
                    <div class="col-12 col-md-8">
                        <label class="tf-label">CSV / XLSX file</label>
                        <input type="file" name="contact_file" class="form-control" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                        <p class="form-text mb-0">Columns: <code>email</code>, <code>country</code>, <code>first_name</code>, <code>last_name</code>, <code>source</code> (header row recommended). Max 10MB.</p>
                    </div>
                    <div class="col-12 col-md-4">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-upload"></i> Upload &amp; append</button>
                    </div>
                </form>
                <form method="GET" class="row g-2 align-items-end">
                    <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                    <div class="col-12 col-md-5">
                        <input type="text" name="esearch" class="form-control form-control-sm" value="<?php echo sanitize($search); ?>" placeholder="Search email or name">
                    </div>
                    <div class="col-6 col-md-3">
                        <select name="estatus" class="form-select form-select-sm">
                            <option value="">All statuses</option>
                            <?php foreach (['active','unsubscribed','bounced','invalid'] as $st): ?>
                            <option value="<?php echo $st; ?>" <?php echo $status_filter === $st ? 'selected' : ''; ?>><?php echo ucfirst($st); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-4 d-flex gap-2">
                        <button class="btn btn-outline-primary btn-sm flex-fill" type="submit">Filter</button>
                        <a class="btn btn-outline-secondary btn-sm" href="<?php echo BASE_URL; ?>/vendors/edit_global.php?id=<?php echo (int)$id; ?>#email-db">Reset</a>
                    </div>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>Country</th>
                            <th>Name</th>
                            <th>Source</th>
                            <th>Status</th>
                            <th>Uploaded</th>
                            <th>Last sent</th>
                            <th class="text-end">Total sent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($entries)): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted">No contacts yet. Upload a list to get started.</td></tr>
                        <?php else: foreach ($entries as $e): ?>
                        <tr>
                            <td><?php echo sanitize($e['email']); ?></td>
                            <td><?php echo sanitize($e['country'] ?? '—'); ?></td>
                            <td><?php echo sanitize(trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? '')) ?: ($e['name'] ?? '—')); ?></td>
                            <td><?php echo sanitize($e['source'] ?? '—'); ?></td>
                            <td><?php echo status_badge($e['status'] ?? 'active'); ?></td>
                            <td class="small text-muted"><?php echo sanitize($e['added_at'] ?? '—'); ?></td>
                            <td class="small text-muted"><?php echo sanitize($e['last_emailed_at'] ?? '—'); ?></td>
                            <td class="text-end"><?php echo number_format((int)($e['total_emails_sent'] ?? 0)); ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="tf-card-footer"><?php echo render_pagination($epagination, BASE_URL . '/vendors/edit_global.php?id=' . (int)$id . '&esearch=' . urlencode($search) . '&estatus=' . urlencode($status_filter)); ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
