<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$id = intval($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/clients/list.php');

$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$id]);
$client = $stmt->fetch();
if (!$client) {
    set_flash('danger', 'Client not found.');
    redirect(BASE_URL . '/clients/list.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/clients/documents.php?id=' . $id);
    }

    $doc_type = $_POST['doc_type'] ?? 'Other';
    if (!array_key_exists($doc_type, tf_document_types())) $doc_type = 'Other';

    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        set_flash('danger', 'Please choose a file to upload.');
        redirect(BASE_URL . '/clients/documents.php?id=' . $id);
    }

    $file = $_FILES['document'];
    if ($file['size'] > 25 * 1024 * 1024) {
        set_flash('danger', 'File too large (max 25 MB).');
        redirect(BASE_URL . '/clients/documents.php?id=' . $id);
    }

    $allowed = ['pdf','doc','docx','xls','xlsx','csv','txt','png','jpg','jpeg','gif','webp','zip'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        set_flash('danger', 'Unsupported file type: ' . sanitize($ext));
        redirect(BASE_URL . '/clients/documents.php?id=' . $id);
    }

    $storage_dir = __DIR__ . '/../storage/client_docs';
    if (!is_dir($storage_dir)) @mkdir($storage_dir, 0755, true);

    $hash = bin2hex(random_bytes(16));
    $stored_filename = $hash . '.' . $ext;
    $full_path = $storage_dir . '/' . $stored_filename;

    if (!move_uploaded_file($file['tmp_name'], $full_path)) {
        set_flash('danger', 'Upload failed. Check storage/client_docs permissions.');
        redirect(BASE_URL . '/clients/documents.php?id=' . $id);
    }

    $original_name = preg_replace('/[^\w\.\- ]/u', '_', $file['name']);
    $pdo->prepare("INSERT INTO client_documents (client_id, document_type, stored_filename, original_filename, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$id, $doc_type, $stored_filename, $original_name, (int)$file['size'], $_SESSION['user_id'] ?? null]);

    audit_log($pdo, 'upload_document', 'client', $id, null, ['doc_type' => $doc_type, 'filename' => $original_name]);

    regenerate_csrf_token();
    set_flash('success', 'Document uploaded.');
    redirect(BASE_URL . '/clients/documents.php?id=' . $id);
}

$docs_stmt = $pdo->prepare("SELECT * FROM client_documents WHERE client_id = ? ORDER BY created_at DESC");
$docs_stmt->execute([$id]);
$documents = $docs_stmt->fetchAll();

$page_title = 'Documents — ' . $client['client_name'];
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<div class="mb-3">
    <a href="<?php echo BASE_URL; ?>/clients/list.php" class="text-decoration-none text-secondary d-inline-flex align-items-center gap-1 small fw-semibold">
        <i class="bi bi-arrow-left"></i>Back to Clients
    </a>
</div>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><?php echo sanitize($client['client_name']); ?></h4>
        <code class="small text-muted"><?php echo sanitize($client['client_code']); ?></code>
    </div>
    <div class="d-flex gap-2">
        <a href="<?php echo BASE_URL; ?>/clients/edit.php?id=<?php echo $id; ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil"></i>Edit Client</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-4">
        <div class="tf-card">
            <div class="tf-card-header">
                <h5 class="mb-0 fw-semibold">Upload Document</h5>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label for="doc_type" class="tf-label">Document Type</label>
                        <select id="doc_type" name="doc_type" class="form-select" required>
                            <?php foreach (tf_document_types() as $key => $label): ?>
                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="document" class="tf-label">File (PDF, DOC, XLS, image, ZIP — max 25 MB)</label>
                        <input type="file" id="document" name="document" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-cloud-upload"></i> Upload</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="tf-card">
            <div class="tf-card-header">
                <h5 class="mb-0 fw-semibold">Documents (<?php echo count($documents); ?>)</h5>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Filename</th>
                            <th>Size</th>
                            <th>Uploaded</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($documents)): ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">No documents uploaded yet.</td></tr>
                        <?php else: foreach ($documents as $d): ?>
                        <tr>
                            <td><span class="badge bg-light text-dark border"><?php echo sanitize(tf_document_types()[$d['document_type']] ?? $d['document_type']); ?></span></td>
                            <td><?php echo sanitize($d['original_filename']); ?></td>
                            <td class="text-secondary"><?php echo number_format($d['file_size'] / 1024, 1); ?> KB</td>
                            <td class="text-secondary small"><?php echo sanitize($d['created_at']); ?></td>
                            <td class="text-end">
                                <a href="<?php echo BASE_URL; ?>/clients/download.php?id=<?php echo $d['id']; ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-download"></i></a>
                                <form method="POST" action="<?php echo BASE_URL; ?>/clients/doc_delete.php" class="d-inline" onsubmit="return confirm('Delete this document?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                                    <input type="hidden" name="client_id" value="<?php echo $id; ?>">
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

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>