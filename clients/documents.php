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

function tf_doc_type_icon_class($ext) {
    $ext = strtolower($ext);
    if (in_array($ext, ['pdf'])) return 'is-pdf';
    if (in_array($ext, ['doc', 'docx'])) return 'is-doc';
    if (in_array($ext, ['xls', 'xlsx', 'csv'])) return 'is-xls';
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'])) return 'is-img';
    if (in_array($ext, ['zip'])) return 'is-zip';
    if (in_array($ext, ['txt'])) return 'is-txt';
    return 'is-other';
}

function tf_doc_type_icon($ext) {
    $ext = strtolower($ext);
    if (in_array($ext, ['pdf'])) return 'bi-file-earmark-pdf';
    if (in_array($ext, ['doc', 'docx'])) return 'bi-file-earmark-word';
    if (in_array($ext, ['xls', 'xlsx', 'csv'])) return 'bi-file-earmark-excel';
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'])) return 'bi-file-earmark-image';
    if (in_array($ext, ['zip'])) return 'bi-file-earmark-zip';
    if (in_array($ext, ['txt'])) return 'bi-file-earmark-text';
    return 'bi-file-earmark';
}

function tf_doc_type_badge_class($doc_type) {
    $map = [
        'Contract' => 'bg-primary',
        'Invoice' => 'is-accent',
        'Report' => 'bg-info',
        'ID Verification' => 'bg-warning',
        'Creative' => 'is-success',
        'Other' => 'bg-light',
    ];
    return $map[$doc_type] ?? 'bg-light';
}

$total_size = 0;
foreach ($documents as $d) $total_size += (int)$d['file_size'];

$total_size_str = $total_size >= 1048576
    ? number_format($total_size / 1048576, 1) . ' MB'
    : number_format($total_size / 1024, 1) . ' KB';
?>

<a href="<?php echo BASE_URL; ?>/clients/list.php" class="tf-back-link">
    <i class="bi bi-arrow-left"></i>
    <span>Back to Clients</span>
</a>

<div class="tf-page-header">
    <div class="tf-page-header-text">
        <h4 class="tf-page-title"><?php echo sanitize($client['client_name']); ?></h4>
        <span class="tf-page-subtitle"><?php echo sanitize($client['client_code']); ?></span>
    </div>
    <div class="tf-page-header-actions">
        <a href="<?php echo BASE_URL; ?>/clients/edit.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-pencil"></i> Edit Client
        </a>
    </div>
</div>

<div class="row g-4">

    <div class="col-12 col-lg-4 tf-upload-col">
        <div class="tf-card">
            <div class="tf-card-header">
                <h5 class="tf-card-title">Upload Document</h5>
            </div>
            <div class="tf-card-body">
                <form method="POST" enctype="multipart/form-data" id="doc-upload-form">
                    <?php echo csrf_field(); ?>

                    <div class="mb-3">
                        <label for="doc_type" class="tf-label">Document Type</label>
                        <select id="doc_type" name="doc_type" class="form-select" required>
                            <?php foreach (tf_document_types() as $key => $label): ?>
                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="tf-dropzone" id="doc-dropzone">
                        <div class="tf-dropzone-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                        <p class="tf-dropzone-text">Drag &amp; drop or <span>browse</span></p>
                        <p class="tf-dropzone-hint">PDF, DOC, XLS, images, ZIP &mdash; max 25 MB</p>
                        <input type="file" id="document" name="document" accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.png,.jpg,.jpeg,.gif,.webp,.zip" required>
                    </div>

                    <div class="tf-hint-chips">
                        <span class="tf-hint-chip"><i class="bi bi-file-earmark-pdf"></i> PDF</span>
                        <span class="tf-hint-chip"><i class="bi bi-file-earmark-word"></i> DOC</span>
                        <span class="tf-hint-chip"><i class="bi bi-file-earmark-excel"></i> XLS</span>
                        <span class="tf-hint-chip"><i class="bi bi-file-earmark-image"></i> IMG</span>
                        <span class="tf-hint-chip"><i class="bi bi-file-earmark-zip"></i> ZIP</span>
                    </div>

                    <div id="file-preview" class="tf-file-preview" style="display:none;">
                        <div class="tf-file-preview-icon" id="file-preview-icon"><i class="bi bi-file-earmark"></i></div>
                        <div class="tf-file-preview-info">
                            <div class="tf-file-preview-name" id="file-preview-name"></div>
                            <div class="tf-file-preview-meta" id="file-preview-meta"></div>
                        </div>
                        <button type="button" class="tf-file-preview-remove" id="file-remove" aria-label="Remove file"><i class="bi bi-x-lg"></i></button>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 mt-3" id="doc-submit-btn" style="display:none;">
                        <i class="bi bi-cloud-arrow-up"></i> Upload
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="tf-card">
            <div class="tf-card-header">
                <h5 class="tf-card-title">Documents</h5>
                <span class="tf-doc-count-badge">
                    <i class="bi bi-file-earmark"></i>
                    <?php echo count($documents); ?> file<?php echo count($documents) !== 1 ? 's' : ''; ?>
                    <?php if ($total_size > 0): ?>
                    <span class="tf-doc-count-sep">&middot;</span> <?php echo $total_size_str; ?>
                    <?php endif; ?>
                </span>
            </div>
            <div class="tf-card-body tf-card-body--flush">
                <?php if (empty($documents)): ?>
                <div class="tf-empty">
                    <div class="tf-empty-icon"><i class="bi bi-folder2-open"></i></div>
                    <h5>No documents yet</h5>
                    <p>Upload contracts, invoices, reports, or any files related to this client.</p>
                </div>
                <?php else: ?>
                <div class="tf-doc-table">
                    <div class="tf-doc-table-header">
                        <span class="tf-doc-col tf-doc-col--name">File</span>
                        <span class="tf-doc-col tf-doc-col--type">Type</span>
                        <span class="tf-doc-col tf-doc-col--size">Size</span>
                        <span class="tf-doc-col tf-doc-col--date">Date</span>
                        <span class="tf-doc-col tf-doc-col--actions"></span>
                    </div>
                    <?php foreach ($documents as $d): ?>
                    <?php
                        $ext = strtolower(pathinfo($d['original_filename'], PATHINFO_EXTENSION));
                        $icon_class = tf_doc_type_icon_class($ext);
                        $icon = tf_doc_type_icon($ext);
                        $badge_class = tf_doc_type_badge_class($d['document_type']);
                        $size_str = $d['file_size'] >= 1048576
                            ? number_format($d['file_size'] / 1048576, 1) . ' MB'
                            : number_format($d['file_size'] / 1024, 1) . ' KB';
                        $date_str = date('d M Y', strtotime($d['created_at']));
                    ?>
                    <div class="tf-doc-row">
                        <span class="tf-doc-col tf-doc-col--name">
                            <span class="tf-doc-row-icon <?php echo $icon_class; ?>"><i class="bi <?php echo $icon; ?>"></i></span>
                            <span class="tf-doc-row-name" title="<?php echo sanitize($d['original_filename']); ?>"><?php echo sanitize($d['original_filename']); ?></span>
                        </span>
                        <span class="tf-doc-col tf-doc-col--type">
                            <span class="badge <?php echo $badge_class; ?>"><?php echo sanitize(tf_document_types()[$d['document_type']] ?? $d['document_type']); ?></span>
                        </span>
                        <span class="tf-doc-col tf-doc-col--size"><?php echo $size_str; ?></span>
                        <span class="tf-doc-col tf-doc-col--date"><?php echo $date_str; ?></span>
                        <span class="tf-doc-col tf-doc-col--actions">
                            <a href="<?php echo BASE_URL; ?>/clients/download.php?id=<?php echo $d['id']; ?>" class="tf-doc-action" title="Download"><i class="bi bi-download"></i></a>
                            <form method="POST" action="<?php echo BASE_URL; ?>/clients/doc_delete.php" class="d-inline" onsubmit="return confirm('Delete this document?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                                <input type="hidden" name="client_id" value="<?php echo $id; ?>">
                                <button type="submit" class="tf-doc-action tf-doc-action--danger" title="Delete"><i class="bi bi-trash3"></i></button>
                            </form>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var dropzone = document.getElementById('doc-dropzone');
    var fileInput = document.getElementById('document');
    var preview = document.getElementById('file-preview');
    var previewIcon = document.getElementById('file-preview-icon');
    var previewName = document.getElementById('file-preview-name');
    var previewMeta = document.getElementById('file-preview-meta');
    var removeBtn = document.getElementById('file-remove');
    var submitBtn = document.getElementById('doc-submit-btn');

    var iconMap = {
        pdf: 'bi-file-earmark-pdf', doc: 'bi-file-earmark-word', docx: 'bi-file-earmark-word',
        xls: 'bi-file-earmark-excel', xlsx: 'bi-file-earmark-excel', csv: 'bi-file-earmark-excel',
        png: 'bi-file-earmark-image', jpg: 'bi-file-earmark-image', jpeg: 'bi-file-earmark-image',
        gif: 'bi-file-earmark-image', webp: 'bi-file-earmark-image',
        zip: 'bi-file-earmark-zip', txt: 'bi-file-earmark-text'
    };
    var colorMap = {
        pdf: 'is-pdf', doc: 'is-doc', docx: 'is-doc',
        xls: 'is-xls', xlsx: 'is-xls', csv: 'is-xls',
        png: 'is-img', jpg: 'is-img', jpeg: 'is-img',
        gif: 'is-img', webp: 'is-img',
        zip: 'is-zip', txt: 'is-txt'
    };

    function formatSize(bytes) {
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
        return (bytes / 1024).toFixed(1) + ' KB';
    }

    function showPreview(file) {
        var ext = file.name.split('.').pop().toLowerCase();
        var icon = iconMap[ext] || 'bi-file-earmark';
        var color = colorMap[ext] || 'is-other';
        previewIcon.className = 'tf-file-preview-icon ' + color;
        previewIcon.innerHTML = '<i class="bi ' + icon + '"></i>';
        previewName.textContent = file.name;
        previewMeta.textContent = formatSize(file.size);
        preview.style.display = 'flex';
        submitBtn.style.display = 'flex';
        dropzone.style.display = 'none';
    }

    function clearPreview() {
        fileInput.value = '';
        preview.style.display = 'none';
        submitBtn.style.display = 'none';
        dropzone.style.display = 'flex';
    }

    fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files[0]) {
            showPreview(fileInput.files[0]);
        }
    });

    removeBtn.addEventListener('click', clearPreview);

    ['dragenter', 'dragover'].forEach(function (evt) {
        dropzone.addEventListener(evt, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.add('is-dragover');
        });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
        dropzone.addEventListener(evt, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.remove('is-dragover');
        });
    });
    dropzone.addEventListener('drop', function (e) {
        var files = e.dataTransfer.files;
        if (files && files[0]) {
            fileInput.files = files;
            showPreview(files[0]);
        }
    });
});
</script>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>