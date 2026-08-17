<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$project_id = intval($_GET['project_id'] ?? 0);
if (!$project_id) redirect(BASE_URL . '/projects/list.php');

$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) {
    set_flash('danger', 'Project not found.');
    redirect(BASE_URL . '/projects/list.php');
}

$vendors = [];
try {
    $vlist = $pdo->prepare("SELECT pv.vendor_id AS id, gv.vendor_name, pv.payout AS vendor_cpi FROM project_vendor pv JOIN global_vendors gv ON gv.id = pv.vendor_id WHERE pv.project_id = ? ORDER BY gv.vendor_name");
    $vlist->execute([$project_id]);
    $vendors = $vlist->fetchAll();
} catch (Throwable $e) {
    error_log('manual vendor list: ' . $e->getMessage());
    try {
        $vlist = $pdo->prepare("SELECT pv.vendor_id AS id, gv.vendor_name, pv.vendor_cpi FROM project_vendor pv JOIN global_vendors gv ON gv.id = pv.vendor_id WHERE pv.project_id = ? ORDER BY gv.vendor_name");
        $vlist->execute([$project_id]);
        $vendors = $vlist->fetchAll();
    } catch (Throwable $e2) {
        error_log('manual vendor list fallback: ' . $e2->getMessage());
        $vendors = [];
    }
}

$currency = $project['currency'] ?? 'USD';

// ─── Handle POST ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/tracking/manual.php?project_id=' . $project_id);
    }

    $vendor_id = intval($_POST['vendor_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $custom_click_id = trim($_POST['click_id'] ?? '');
    $sale_amount = floatval($_POST['sale_amount'] ?? 0);
    $transaction_id = trim($_POST['transaction_id'] ?? '');
    $sub1 = substr(trim($_POST['sub1'] ?? ''), 0, 200);
    $sub2 = substr(trim($_POST['sub2'] ?? ''), 0, 200);

    if (!$vendor_id || $reason === '') {
        set_flash('danger', 'Vendor and reason are required.');
        redirect(BASE_URL . '/tracking/manual.php?project_id=' . $project_id);
    }

    $click_id = $custom_click_id ?: bin2hex(random_bytes(16));
    $existing = null;

    try {
        if ($custom_click_id) {
            $check = $pdo->prepare("SELECT * FROM clicks WHERE click_id = ?");
            $check->execute([$click_id]);
            $existing = $check->fetch();
            if ($check) {
                $check->closeCursor();
            }

            if ($existing) {
                if (!empty($existing['is_converted'])) {
                    set_flash('danger', 'This click is already converted.');
                    redirect(BASE_URL . '/tracking/manual.php?project_id=' . $project_id);
                }
                $vendor_id = (int)$existing['vendor_id'];
            }
        }

        $vstmt = $pdo->prepare("SELECT * FROM project_vendor WHERE vendor_id = ? AND project_id = ?");
        $vstmt->execute([$vendor_id, $project_id]);
        $vendor = $vstmt->fetch();
        if ($vstmt) {
            $vstmt->closeCursor();
        }

        if (!$vendor) {
            set_flash('danger', 'Vendor not found or does not belong to this project.');
            redirect(BASE_URL . '/tracking/manual.php?project_id=' . $project_id);
        }

        if (function_exists('tf_daily_completes')) {
            if ((int)($project['daily_cap'] ?? 0) > 0 && tf_daily_completes($pdo, $project_id) >= (int)$project['daily_cap']) {
                set_flash('danger', 'Campaign daily conversion cap has been reached.');
                redirect(BASE_URL . '/tracking/manual.php?project_id=' . $project_id);
            }
            if ((int)($vendor['daily_cap'] ?? 0) > 0 && tf_daily_completes($pdo, $project_id, $vendor_id) >= (int)$vendor['daily_cap']) {
                set_flash('danger', 'This vendor has reached its daily conversion cap.');
                redirect(BASE_URL . '/tracking/manual.php?project_id=' . $project_id);
            }
        }

        $revenue = $project['client_cpi'];
        $cost = $vendor['payout'] ?? $vendor['vendor_cpi'] ?? 0;
        $profit = $revenue - $cost;
        $now = date('Y-m-d H:i:s');

        if (!$custom_click_id || empty($existing)) {
            tf_record_click($pdo, [
                'click_id' => $click_id,
                'project_id' => $project_id,
                'vendor_id' => $vendor_id,
                'ip_address' => 'manual',
                'user_agent' => 'manual',
            ]);
            try {
                $pdo->prepare("UPDATE projects SET clicks_count = clicks_count + 1 WHERE id = ?")->execute([$project_id]);
            } catch (Throwable $e) {
                error_log('manual clicks_count: ' . $e->getMessage());
            }
        }
        try {
            $pdo->prepare("UPDATE clicks SET is_converted = 1 WHERE click_id = ?")->execute([$click_id]);
        } catch (Throwable $e) {
            error_log('manual mark converted: ' . $e->getMessage());
        }

        tf_record_conversion($pdo, [
            'click_id' => $click_id,
            'project_id' => $project_id,
            'vendor_id' => $vendor_id,
            'status' => 'complete',
            'client_revenue' => $revenue,
            'sale_amount' => $sale_amount,
            'currency' => $currency,
            'vendor_cost' => $cost,
            'payout' => $cost,
            'profit' => $profit,
            'transaction_id' => $transaction_id,
            'click_time' => $now,
            'time_diff_seconds' => 0,
            'is_manual' => 1,
            'sub1' => $sub1,
            'sub2' => $sub2,
            'sub3' => '',
            'sub4' => '',
            'sub5' => '',
        ]);

        $pdo->prepare("UPDATE projects SET completes_count = completes_count + 1 WHERE id = ?")->execute([$project_id]);

        if (($project['total_quota'] ?? 0) > 0) {
            $count_stmt = $pdo->prepare("SELECT completes_count FROM projects WHERE id = ?");
            $count_stmt->execute([$project_id]);
            $row = $count_stmt->fetch();
            if ($count_stmt) {
                $count_stmt->closeCursor();
            }
            $new_count = (int)($row['completes_count'] ?? 0);

            if ($new_count >= $project['total_quota']) {
                $pdo->prepare("UPDATE projects SET status = 'hold' WHERE id = ?")->execute([$project_id]);
                try {
                    $pdo->prepare("UPDATE project_vendor SET status = 'hold' WHERE project_id = ? AND status = 'active'")->execute([$project_id]);
                } catch (Throwable $e) {
                    error_log('manual hold vendors: ' . $e->getMessage());
                }
            }
        }

        tf_log_event($pdo, 'manual', 'success', 'Manual conversion: ' . $reason, $project_id, $vendor_id, $click_id);

        regenerate_csrf_token();
        set_flash('success', 'Manual conversion recorded.');
        redirect(BASE_URL . '/projects/detail.php?id=' . $project_id);
    } catch (Throwable $e) {
        error_log('manual conversion: ' . $e->getMessage());
        set_flash('danger', 'Could not record conversion. Check that clicks/conversions tables match the current schema.');
        redirect(BASE_URL . '/tracking/manual.php?project_id=' . $project_id);
    }
}

$page_title = 'Manual Conversion';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<div class="mb-4">
    <a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo $project_id; ?>" class="tf-back-link">
        <i class="bi bi-arrow-left"></i>Back to Project
    </a>
</div>

<div class="max-w-2xl mx-auto">
    <div class="tf-card">
        <div class="tf-card-header">
            <h2 class="tf-card-title">Record Manual Conversion</h2>
        </div>
        <div class="tf-card-body">
            <div class="alert alert-info">
                <div class="alert-body"><i class="bi bi-info-circle"></i> Manual conversions are flagged in the system and displayed separately in reports.</div>
            </div>

            <form method="POST" class="tf-form">
                <?php echo csrf_field(); ?>

                <div class="tf-field">
                    <label for="vendor_id" class="tf-label">Vendor <span class="tf-required">*</span></label>
                    <select id="vendor_id" name="vendor_id" class="form-select" required>
                        <option value="">Select Vendor</option>
                        <?php foreach ($vendors as $v): ?>
                        <option value="<?php echo $v['id']; ?>"><?php echo sanitize($v['vendor_name']); ?> (CPI: <?php echo format_currency($v['vendor_cpi']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="tf-field">
                    <label for="click_id" class="tf-label">Click ID <span class="text-xs text-slate-500 font-normal">(optional)</span></label>
                    <input type="text" id="click_id" name="click_id" class="form-control tf-mono"
                           placeholder="Leave blank to auto-generate">
                    <p class="tf-help">Enter an existing click_id to convert it, or leave blank to create a new one.</p>
                </div>

                <div class="tf-field">
                    <label for="reason" class="tf-label">Reason <span class="tf-required">*</span></label>
                    <textarea id="reason" name="reason" class="form-control" rows="3"
                              placeholder="Why is this being recorded manually?" required></textarea>
                </div>

                <details class="mb-3">
                    <summary class="small fw-semibold text-secondary">Optional postback fields</summary>
                    <div class="row g-2 mt-2">
                        <div class="col-6">
                            <label for="sale_amount" class="form-label small">Sale Amount</label>
                            <input type="number" id="sale_amount" name="sale_amount" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-6">
                            <label for="transaction_id" class="form-label small">Transaction ID</label>
                            <input type="text" id="transaction_id" name="transaction_id" class="form-control">
                        </div>
                        <div class="col-6">
                            <label for="sub1" class="form-label small">Sub 1</label>
                            <input type="text" id="sub1" name="sub1" class="form-control">
                        </div>
                        <div class="col-6">
                            <label for="sub2" class="form-label small">Sub 2</label>
                            <input type="text" id="sub2" name="sub2" class="form-control">
                        </div>
                    </div>
                </details>

                <div class="form-actions">
                    <a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo $project_id; ?>" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i>Record Conversion</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>