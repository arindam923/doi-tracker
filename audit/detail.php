<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin']);

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    set_flash('danger', 'Missing audit entry id.');
    redirect(BASE_URL . '/audit/list.php');
}

$stmt = $pdo->prepare("
    SELECT a.*, u.username AS actor_name
    FROM audit_logs a
    LEFT JOIN users u ON a.actor_id = u.id
    WHERE a.id = ?
");
$stmt->execute([$id]);
$entry = $stmt->fetch();
if (!$entry) {
    set_flash('danger', 'Audit entry not found.');
    redirect(BASE_URL . '/audit/list.php');
}

function pretty_json($raw) {
    return json_encode(json_decode($raw ?: 'null', true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

$before = $entry['before_json'];
$after  = $entry['after_json'];

// Compute a text-level diff for changed fields (highlight added/removed keys)
$diff_pairs = [];
if ($before && $after) {
    $b = json_decode($before, true) ?: [];
    $a = json_decode($after, true) ?: [];
    foreach ($a as $k => $v) {
        if (!array_key_exists($k, $b)) {
            $diff_pairs[$k] = ['type' => 'added', 'before' => null,   'after' => $v];
        } elseif ($b[$k] !== $v) {
            $diff_pairs[$k] = ['type' => 'changed', 'before' => $b[$k], 'after' => $v];
        }
    }
    foreach ($b as $k => $v) {
        if (!array_key_exists($k, $a)) {
            $diff_pairs[$k] = ['type' => 'removed', 'before' => $v, 'after' => null];
        }
    }
}

$page_title = 'Audit Detail';
$page_actions = '<a href="' . BASE_URL . '/audit/list.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i>Back</a>';
require_once __DIR__ . '/../helpers/layout_header.php';
?>
<style>
    .json-pre { background: #0f172a; color: #e2e8f0; padding: 1rem 1.25rem; border-radius: .5rem; font-size: .8rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; max-height: 480px; overflow: auto; white-space: pre; }
    .diff-row td { vertical-align: top; padding: .6rem .75rem; border-bottom: 1px solid #f1f5f9; }
    .diff-key { font-family: ui-monospace, monospace; font-weight: 600; }
    .tag-added { background: #d1fae5; color: #047857; }.tag-removed { background: #fee2e2; color: #b91c1c; }.tag-changed { background: #fef3c7; color: #b45309; }
</style>

<div class="row g-4">
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-3"><h6 class="mb-0 fw-semibold">Entry Details</h6></div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-4 text-secondary">Time</dt><dd class="col-8"><?php echo sanitize($entry['created_at']); ?></dd>
                    <dt class="col-4 text-secondary">Actor</dt><dd class="col-8"><?php echo sanitize($entry['actor_name'] ?? 'System'); ?> <code class="small">#<?php echo sanitize($entry['actor_id'] ?? '—'); ?></code></dd>
                    <dt class="col-4 text-secondary">Action</dt><dd class="col-8"><span class="badge bg-light text-dark border"><?php echo sanitize(ucfirst($entry['action'])); ?></span></dd>
                    <dt class="col-4 text-secondary">Entity</dt><dd class="col-8"><code><?php echo sanitize($entry['entity_type']); ?></code> #<?php echo sanitize($entry['entity_id']); ?></dd>
                    <dt class="col-4 text-secondary">IP</dt><dd class="col-8"><?php echo sanitize($entry['ip_address'] ?? '—'); ?></dd>
                    <dt class="col-4 text-secondary">User-Agent</dt><dd class="col-8" style="word-break:break-word"><?php echo sanitize($entry['user_agent'] ?? '—'); ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <?php if (!empty($diff_pairs)): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom py-3"><h6 class="mb-0 fw-semibold">Field Changes</h6></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 small">
                    <thead class="bg-light"><tr><th>Field</th><th>Change</th><th>Before</th><th>After</th></tr></thead>
                    <tbody>
                        <?php foreach ($diff_pairs as $k => $d): ?>
                        <tr class="diff-row">
                            <td class="diff-key"><?php echo sanitize($k); ?></td>
                            <td><span class="badge tag-<?php echo $d['type']; ?>"><?php echo $d['type']; ?></span></td>
                            <td class="text-secondary"><?php echo sanitize(is_scalar($d['before']) || $d['before'] === null ? ($d['before'] ?? '—') : json_encode($d['before'])); ?></td>
                            <td><?php echo sanitize(is_scalar($d['after']) || $d['after'] === null ? ($d['after'] ?? '—') : json_encode($d['after'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-12 <?php echo ($before && $after) ? 'col-md-6' : ''; ?>">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom py-2"><small class="fw-semibold text-secondary">BEFORE</small></div>
                    <pre class="json-pre m-0"><?php echo sanitize(pretty_json($before)); ?></pre>
                </div>
            </div>
            <?php if ($before && $after): ?>
            <div class="col-12 col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom py-2"><small class="fw-semibold text-secondary">AFTER</small></div>
                    <pre class="json-pre m-0"><?php echo sanitize(pretty_json($after)); ?></pre>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
