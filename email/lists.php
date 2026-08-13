<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/dedupe.php';
require_role(['super_admin', 'campaign_manager']);

$action = $_GET['action'] ?? 'list';
$view_list = intval($_GET['list_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/email/lists.php');
    }

    $post_action = $_POST['action'] ?? '';

    // ── Create list ─────────────────────────────────────────────
    if ($post_action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $vendor_id = intval($_POST['vendor_id'] ?? 0);
        $owner = $pdo->prepare("SELECT id FROM global_vendors WHERE id = ? AND traffic_type = 'Email'");
        $owner->execute([$vendor_id]);
        if ($name === '') {
            set_flash('danger', 'List name is required.');
        } elseif (!$owner->fetch()) {
            set_flash('danger', 'Choose an Email-traffic vendor to own this list.');
        } else {
            try {
                $pdo->prepare("INSERT INTO email_lists (name, source, description, vendor_id, created_by, created_at) VALUES (?, 'manual', ?, ?, ?, NOW())")
                    ->execute([$name, $description, $vendor_id ?: null, $_SESSION['user_id'] ?? null]);
                set_flash('success', 'List "' . sanitize($name) . '" created.');
            } catch (Throwable $e) {
                set_flash('danger', 'Failed to create list: ' . $e->getMessage());
            }
        }
        redirect(BASE_URL . '/email/lists.php');
    }

    // ── Delete list ─────────────────────────────────────────────
    if ($post_action === 'delete') {
        $list_id = intval($_POST['list_id'] ?? 0);
        if ($list_id) {
            $pdo->prepare("DELETE FROM email_lists WHERE id = ?")->execute([$list_id]);
            set_flash('success', 'List deleted.');
        }
        redirect(BASE_URL . '/email/lists.php');
    }

    // ── CSV upload to a list ────────────────────────────────────
    if ($post_action === 'upload') {
        $list_id = intval($_POST['list_id'] ?? 0);
        if (!$list_id || !isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            set_flash('danger', 'Select a list and a valid CSV file.');
            redirect(BASE_URL . '/email/lists.php?action=upload&list_id=' . $list_id);
        }

        $tmp = $_FILES['csv_file']['tmp_name'];
        if (filesize($tmp) > 10 * 1024 * 1024) {
            set_flash('danger', 'CSV file is too large (max 10MB).');
            redirect(BASE_URL . '/email/lists.php?action=upload&list_id=' . $list_id);
        }

        $handle = fopen($tmp, 'r');
        if (!$handle) {
            set_flash('danger', 'Could not open uploaded file.');
            redirect(BASE_URL . '/email/lists.php?action=upload&list_id=' . $list_id);
        }

        // Sniff header row
        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            set_flash('danger', 'CSV file is empty.');
            redirect(BASE_URL . '/email/lists.php?action=upload&list_id=' . $list_id);
        }
        $header = array_map('strtolower', array_map('trim', (array)$header));
        $has_header = in_array('email', $header, true);

        $rows = [];
        if ($has_header) {
            $i_email = array_search('email', $header, true);
            $i_name  = array_search('name', $header, true);
            $i_first = array_search('first_name', $header, true);
            $i_last  = array_search('last_name', $header, true);
            $i_country = array_search('country', $header, true);
            $i_source = array_search('source', $header, true);
            while (($line = fgetcsv($handle)) !== false) {
                if (count($line) < 1) continue;
                $first = ($i_first !== false) ? trim((string)($line[$i_first] ?? '')) : '';
                $last = ($i_last !== false) ? trim((string)($line[$i_last] ?? '')) : '';
                $name = ($i_name !== false) ? trim((string)($line[$i_name] ?? '')) : trim($first . ' ' . $last);
                $rows[] = [
                    'email' => $line[$i_email] ?? '',
                    'name' => $name !== '' ? $name : null,
                    'country' => ($i_country !== false) ? strtoupper(substr(trim((string)($line[$i_country] ?? '')), 0, 2)) : null,
                    'source' => ($i_source !== false) ? trim((string)($line[$i_source] ?? '')) : null,
                ];
            }
        } else {
            // First line is data, not a header
            rewind($handle);
            while (($line = fgetcsv($handle)) !== false) {
                if (count($line) < 1 || trim($line[0]) === '') continue;
                $row = ['email' => $line[0]];
                if (isset($line[1]) && trim($line[1]) !== '') $row['name'] = $line[1];
                $rows[] = $row;
            }
        }
        fclose($handle);

        if (empty($rows)) {
            set_flash('danger', 'No valid rows found in CSV.');
            redirect(BASE_URL . '/email/lists.php?action=upload&list_id=' . $list_id);
        }

        try {
            $deduped = email_dedupe($rows);
            $inserted = upsert_list_entries($pdo, $list_id, $deduped['unique']);
            set_flash('success', 'Imported ' . $inserted . ' new recipient(s). ' .
                $deduped['dupes'] . ' duplicate(s) skipped, ' . $deduped['invalid'] . ' invalid row(s) dropped.');
        } catch (Throwable $e) {
            set_flash('danger', 'Import failed: ' . $e->getMessage());
        }
        redirect(BASE_URL . '/email/lists.php?action=entries&list_id=' . $list_id);
    }

    // ── Unsubscribe entry / bulk delete ─────────────────────────
    if ($post_action === 'delete_entries') {
        $list_id = intval($_POST['list_id'] ?? 0);
        $ids = array_map('intval', (array)($_POST['entry_ids'] ?? []));
        if ($list_id && $ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM email_list_entries WHERE id IN ($ph) AND list_id = ?")
                ->execute(array_merge($ids, [$list_id]));
            $pdo->prepare("UPDATE email_lists SET record_count = (SELECT COUNT(*) FROM email_list_entries WHERE list_id = ?) WHERE id = ?")
                ->execute([$list_id, $list_id]);
            set_flash('success', count($ids) . ' recipient(s) removed.');
        }
        redirect(BASE_URL . '/email/lists.php?action=entries&list_id=' . $list_id);
    }
}

// ── GET: render ─────────────────────────────────────────────────
$lists = $pdo->query("
    SELECT l.*, gv.vendor_name, gv.vendor_code,
        (SELECT COUNT(*) FROM email_list_entries e WHERE e.list_id = l.id) AS entries
    FROM email_lists l
    LEFT JOIN global_vendors gv ON gv.id = l.vendor_id
    ORDER BY l.created_at DESC
")->fetchAll();

$email_vendors = $pdo->query("SELECT id, vendor_name, vendor_code FROM global_vendors WHERE traffic_type = 'Email' ORDER BY vendor_name")->fetchAll();

$page_title = 'Email Lists';
$page_actions = '<a href="' . BASE_URL . '/email/campaigns.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-funnel"></i>Campaigns</a>';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<style>
    .table-lists thead th { font-size: .7rem; letter-spacing: .06em; text-transform: uppercase; color: #64748b; font-weight: 600; background: #f8fafc; }
    .table-lists tbody td { vertical-align: middle; padding: .85rem 1rem; }
</style>

<?php if ($action === 'upload' || $action === 'entries'): $current = null;
    foreach ($lists as $l) { if ((int)$l['id'] === $view_list) { $current = $l; break; } } ?>

    <?php if (!$current): ?>
    <div class="alert alert-danger">List not found. <a href="<?php echo BASE_URL; ?>/email/lists.php">Back to lists</a></div>
    <?php else: ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0 fw-semibold"><?php echo sanitize($current['name']); ?></h4>
            <small class="text-secondary"><?php echo number_format($current['entries']); ?> recipients
                <?php if ($current['is_deduped']): ?>&middot; <span class="text-success">deduped</span><?php endif; ?></small>
        </div>
        <div class="d-flex gap-2">
            <a href="<?php echo BASE_URL; ?>/email/lists.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> All Lists</a>
            <a href="<?php echo BASE_URL; ?>/email/lists.php?action=upload&list_id=<?php echo $view_list; ?>" class="btn btn-primary btn-sm"><i class="bi bi-upload"></i> Import CSV</a>
        </div>
    </div>

    <?php if ($action === 'upload'): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-3"><h6 class="mb-0 fw-semibold">Import recipients into "<?php echo sanitize($current['name']); ?>"</h6></div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="list_id" value="<?php echo $view_list; ?>">

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">CSV File</label>
                    <input type="file" name="csv_file" accept=".csv,text/csv" class="form-control" required>
                    <p class="form-text mb-0">
                        Format: <code>email,country,first_name,last_name,source</code> (header row optional). Excel is not accepted in this release — export CSV from Excel first. Emails are deduped case-insensitively. Max 10MB.
                    </p>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <a href="<?php echo BASE_URL; ?>/email/sample.csv" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download"></i> Sample CSV</a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Import</button>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($action === 'entries'):
        $page = max(1, intval($_GET['page'] ?? 1));
        $per_page = 50;
        $search = trim($_GET['search'] ?? '');
        $where = ['e.list_id = ?'];
        $params = [$view_list];
        if ($search !== '') {
            $where[] = "(e.email LIKE ? OR e.name LIKE ?)";
            $params[] = "%$search%"; $params[] = "%$search%";
        }
        $where_sql = 'WHERE ' . implode(' AND ', $where);
        $cnt = $pdo->prepare("SELECT COUNT(*) AS c FROM email_list_entries e $where_sql");
        $cnt->execute($params);
        $total = (int)$cnt->fetch()['c'];
        $pagination = paginate($total, $per_page, $page);
        $stmt = $pdo->prepare("SELECT e.* FROM email_list_entries e $where_sql ORDER BY e.added_at DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}");
        $stmt->execute($params);
        $entries = $stmt->fetchAll();
    ?>
    <div class="card border-0 shadow-sm mb-4">
        <form method="GET" class="card-body">
            <input type="hidden" name="action" value="entries">
            <input type="hidden" name="list_id" value="<?php echo $view_list; ?>">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-8">
                    <input type="text" name="search" class="form-control form-control-sm" value="<?php echo sanitize($search); ?>" placeholder="Search email or name...">
                </div>
                <div class="col-6 col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i>Search</button>
                </div>
                <div class="col-6 col-md-2">
                    <a href="<?php echo BASE_URL; ?>/email/lists.php?action=entries&list_id=<?php echo $view_list; ?>" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <div class="card border-0 shadow-sm">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="delete_entries">
            <input type="hidden" name="list_id" value="<?php echo $view_list; ?>">
            <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold"><?php echo number_format($total); ?> recipient(s)</h6>
                <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete selected recipients?')"><i class="bi bi-trash"></i> Delete Selected</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-lists align-middle mb-0">
                    <thead><tr><th style="width:2rem"></th><th>Email</th><th>Name</th><th>Status</th><th>Added</th></tr></thead>
                    <tbody>
                        <?php if (empty($entries)): ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">No recipients in this list yet.</td></tr>
                        <?php else: foreach ($entries as $e): ?>
                        <tr>
                            <td><input type="checkbox" name="entry_ids[]" value="<?php echo (int)$e['id']; ?>" class="form-check-input"></td>
                            <td><?php echo sanitize($e['email']); ?></td>
                            <td><?php echo sanitize($e['name'] ?? '—'); ?></td>
                            <td><?php echo $e['is_unsubscribed']
                                ? '<span class="badge bg-light text-dark border">Unsubscribed</span>'
                                : '<span class="badge bg-success">Active</span>'; ?></td>
                            <td class="text-secondary"><?php echo sanitize($e['added_at']); ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white py-3"><?php echo render_pagination($pagination, BASE_URL . '/email/lists.php?action=entries&list_id=' . $view_list); ?></div>
        </form>
    </div>
    <?php endif; endif; ?>

<?php else: ?>
<div class="row g-4">
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-3"><h5 class="mb-0 fw-semibold">Create List</h5></div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Owner vendor <span class="text-danger">*</span></label>
                        <select name="vendor_id" class="form-select" required>
                            <option value="">Email vendor…</option>
                            <?php foreach ($email_vendors as $ev): ?>
                            <option value="<?php echo (int)$ev['id']; ?>"><?php echo sanitize($ev['vendor_name']); ?> (<?php echo sanitize($ev['vendor_code']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-text">Lists are reusable across projects assigned to this vendor.</p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">List Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="200" placeholder="e.g. June Newsletter">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Optional notes about this list"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Create List</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-3">
                <h5 class="mb-0 fw-semibold"><?php echo count($lists); ?> email lists</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-lists align-middle mb-0">
                    <thead>
                        <tr><th>Name</th><th>Owner</th><th class="text-center">Recipients</th><th>Deduped</th><th>Created</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lists)): ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted">No lists yet. Create one on the left.</td></tr>
                        <?php else: foreach ($lists as $l): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?php echo sanitize($l['name']); ?></div>
                                <?php if ($l['description']): ?><div class="small text-secondary"><?php echo sanitize($l['description']); ?></div><?php endif; ?>
                            </td>
                            <td class="small"><?php echo $l['vendor_name'] ? sanitize($l['vendor_name']) : '—'; ?></td>
                            <td class="text-center"><?php echo number_format((int)$l['entries']); ?></td>
                            <td><?php echo $l['is_deduped'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-light text-dark border">No</span>'; ?></td>
                            <td class="text-secondary small"><?php echo sanitize(date('M j, Y', strtotime($l['created_at']))); ?></td>
                            <td class="text-nowrap">
                                <a href="<?php echo BASE_URL; ?>/email/lists.php?action=entries&list_id=<?php echo (int)$l['id']; ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-person-lines-fill"></i> View</a>
                                <a href="<?php echo BASE_URL; ?>/email/lists.php?action=upload&list_id=<?php echo (int)$l['id']; ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-upload"></i> Import</a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this list and all its recipients?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="list_id" value="<?php echo (int)$l['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
