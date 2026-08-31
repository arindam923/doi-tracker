<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/email.php';
require_role(['super_admin', 'campaign_manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/vendors/global.php');
}
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash('danger', 'Invalid form submission.');
    redirect(BASE_URL . '/vendors/global.php');
}

$project_id = intval($_POST['project_id'] ?? 0);
$global_vendor_id = intval($_POST['global_vendor_id'] ?? 0);
$payout = floatval($_POST['payout'] ?? 0);
$currency = strtoupper(trim($_POST['currency'] ?? '')) ?: 'USD';
$allowed_clicks_limit = intval($_POST['allowed_clicks_limit'] ?? 0);
$daily_cap = intval($_POST['daily_cap'] ?? 0);
$postback_url = trim($_POST['postback_url'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if (!$project_id || !$global_vendor_id) {
    set_flash('danger', 'Missing project or vendor.');
    redirect(BASE_URL . '/vendors/global.php');
}

$proj_stmt = $pdo->prepare("SELECT p.id, p.short_code, p.currency, p.vendor_default_cpi, c.default_currency FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
$proj_stmt->execute([$project_id]);
$project = $proj_stmt->fetch();
$gv_stmt = $pdo->prepare("SELECT * FROM global_vendors WHERE id = ?");
$gv_stmt->execute([$global_vendor_id]);
$gv = $gv_stmt->fetch();

if (!$project || !$gv) {
    set_flash('danger', 'Project or vendor not found.');
    redirect(BASE_URL . '/vendors/global.php');
}

$postback_url = tf_resolve_postback_url($postback_url, $gv['global_postback_url'] ?? '');
if (!tf_is_valid_postback_url($postback_url)) {
    set_flash('danger', 'Project override postback URL must be a valid HTTP or HTTPS URL.');
    redirect(BASE_URL . '/projects/detail.php?id=' . $project_id);
}

if ($currency === 'USD' && !empty($project['currency'])) $currency = $project['currency'];
if ($currency === 'USD' && !empty($project['default_currency'])) $currency = $project['default_currency'];
if ($payout <= 0) $payout = $gv['default_payout'] ?: ($project['vendor_default_cpi'] ?: 0);

try {
    $pdo->beginTransaction();

    // Insert project_vendor pivot
    $pdo->prepare("
        INSERT INTO project_vendor
            (project_id, vendor_id, payout, currency, status, postback_url, allowed_clicks_limit, daily_cap, assigned_by, assigned_at, notes)
        VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE
            payout = VALUES(payout),
            currency = VALUES(currency),
            status = 'active',
            postback_url = VALUES(postback_url),
            allowed_clicks_limit = VALUES(allowed_clicks_limit),
            daily_cap = VALUES(daily_cap),
            notes = VALUES(notes)
    ")->execute([$project_id, $global_vendor_id, $payout, $currency, $postback_url, $allowed_clicks_limit, $daily_cap, (int)($_SESSION['user_id'] ?? 0), $notes]);

    // Per-vendor short link
    $suffix = strtolower(substr(preg_replace('/[^A-Za-z0-9]/', '', $gv['vendor_name'] ?: $gv['vendor_code']), 0, 4));
    $short_base = $project['short_code'] ?? substr(strtoupper(bin2hex(random_bytes(4))), 0, 8);
    $short_vendor_code = $short_base . '-' . ($suffix ?: $global_vendor_id);
    $pdo->prepare("INSERT IGNORE INTO short_links (code, project_id, vendor_id, created_at) VALUES (?, ?, ?, NOW())")
        ->execute([$short_vendor_code, $project_id, $global_vendor_id]);

    audit_log($pdo, 'attach', 'project_vendor', $project_id . ':' . $global_vendor_id, null, [
        'project_id' => $project_id,
        'vendor_code' => $gv['vendor_code'],
        'payout' => $payout,
        'currency' => $currency
    ]);

    audit_log($pdo, 'attach', 'project_vendor', $project_id . ':' . $global_vendor_id, null, [
        'project_id' => $project_id,
        'vendor_code' => $gv['vendor_code'],
        'payout' => $payout,
        'currency' => $currency
    ]);

    if (($gv['traffic_type'] ?? '') === 'Email') {
        ensure_vendor_email_list($pdo, $global_vendor_id, (int)($_SESSION['user_id'] ?? 0));
    }

    $pdo->commit();
    regenerate_csrf_token();
    set_flash('success', 'Vendor ' . sanitize($gv['vendor_name']) . ' attached to project.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    set_flash('danger', 'Attach failed: ' . $e->getMessage());
}

redirect(BASE_URL . '/projects/detail.php?id=' . $project_id);
