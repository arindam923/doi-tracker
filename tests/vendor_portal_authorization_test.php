<?php
require_once __DIR__ . '/../helpers/vendor_portal.php';

function vendor_test_expect($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

vendor_test_expect(tf_vendor_status_allowed('approved'), 'approved vendors may access the portal');
vendor_test_expect(tf_vendor_status_allowed('pending'), 'pending vendors may access the portal');
vendor_test_expect(!tf_vendor_status_allowed('suspended'), 'suspended vendors cannot access the portal');
vendor_test_expect(!tf_vendor_status_allowed('blacklisted'), 'blacklisted vendors cannot access the portal');

$assignment_sql = tf_vendor_assignment_sql('pv');
vendor_test_expect(strpos($assignment_sql, 'pv.vendor_id = ?') !== false, 'assignment predicate binds the authenticated vendor');
vendor_test_expect(strpos($assignment_sql, "pv.status = 'active'") !== false, 'assignment predicate requires an active assignment');

vendor_test_expect(tf_vendor_date('2026-08-01') === '2026-08-01', 'valid report date is retained');
vendor_test_expect(tf_vendor_date('not-a-date') === null, 'invalid report date is rejected');
vendor_test_expect(tf_vendor_date('') === null, 'blank report date is rejected');

$safe = tf_vendor_report_fields(['clicks' => 1, 'conversions' => 2, 'client_revenue' => 99, 'profit' => 50]);
vendor_test_expect(isset($safe['clicks'], $safe['conversions']), 'vendor report fields retain vendor metrics');
vendor_test_expect(!isset($safe['client_revenue'], $safe['profit']), 'vendor report fields exclude internal financial metrics');

echo "PASS: vendor portal authorization helpers\n";
