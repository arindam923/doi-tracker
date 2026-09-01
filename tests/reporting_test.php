<?php

function reporting_assert_true($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$helper_path = __DIR__ . '/../helpers/reporting.php';
reporting_assert_true(is_file($helper_path), 'shared reporting helper exists');
require_once $helper_path;

reporting_assert_true(tf_reporting_periods() === ['daily', 'weekly', 'monthly', 'custom'], 'all report periods are supported');
reporting_assert_true(tf_reporting_groups() === ['vendor', 'project', 'client', 'country', 'device'], 'all report groups are supported');

$filters = tf_reporting_normalize_filters([
    'period' => 'custom',
    'from' => '2026-08-01',
    'to' => '2026-08-15',
    'group' => 'country',
    'project_id' => '12',
]);
reporting_assert_true($filters['from'] === '2026-08-01' && $filters['to'] === '2026-08-15', 'custom date range is retained');
reporting_assert_true($filters['group'] === 'country' && $filters['project_id'] === 12, 'group and project filters are normalized');

$reversed = tf_reporting_normalize_filters(['period' => 'custom', 'from' => '2026-08-20', 'to' => '2026-08-01']);
reporting_assert_true($reversed['from'] === '2026-08-01' && $reversed['to'] === '2026-08-20', 'reversed custom dates are corrected');

reporting_assert_true(strpos(tf_reporting_bucket_expression('daily'), 'DATE(') !== false, 'daily bucket groups by date');
reporting_assert_true(strpos(tf_reporting_bucket_expression('weekly'), 'YEARWEEK(') !== false, 'weekly bucket groups by week');
reporting_assert_true(strpos(tf_reporting_bucket_expression('monthly'), 'DATE_FORMAT(') !== false, 'monthly bucket groups by month');

$metrics = tf_reporting_metrics([
    'clicks' => 200,
    'conversions' => 40,
    'rejected_leads' => 5,
    'revenue' => 1000,
    'cost' => 400,
]);
reporting_assert_true($metrics['profit'] === 600.0, 'profit is revenue minus cost');
reporting_assert_true($metrics['roi'] === 150.0, 'ROI is profit divided by cost');
reporting_assert_true($metrics['conversion_rate'] === 20.0, 'conversion rate is conversions divided by clicks');
reporting_assert_true($metrics['epc'] === 5.0, 'EPC is revenue divided by clicks');
reporting_assert_true($metrics['rejected_leads'] === 5, 'rejected leads are retained');

$zero_metrics = tf_reporting_metrics(['clicks' => 0, 'conversions' => 0, 'rejected_leads' => 0, 'revenue' => 0, 'cost' => 0]);
reporting_assert_true($zero_metrics['roi'] === 0.0 && $zero_metrics['conversion_rate'] === 0.0 && $zero_metrics['epc'] === 0.0, 'zero denominators produce zero metrics');
reporting_assert_true(strpos(tf_reporting_rejected_predicate(), "status = 'rejected'") !== false, 'rejected predicate includes conversion status');
reporting_assert_true(strpos(tf_reporting_rejected_predicate(), "approval_status = 'rejected'") !== false, 'rejected predicate includes approval status');

foreach (['../reports/overview.php', '../reports/export.php', '../reports/scheduled_reports.php', '../cron/reports_scheduler.php'] as $consumer) {
    $source = file_get_contents(__DIR__ . '/' . $consumer);
    reporting_assert_true(strpos($source, "helpers/reporting.php") !== false, basename($consumer) . ' uses the shared reporting helper');
}
$overview_source = file_get_contents(__DIR__ . '/../reports/overview.php');
foreach (['Daily', 'Weekly', 'Monthly', 'Custom Date Range', 'Revenue', 'Cost', 'Profit', 'ROI', 'Conversion Rate', 'EPC', 'Rejected Leads'] as $label) {
    reporting_assert_true(strpos($overview_source, $label) !== false, 'overview exposes ' . $label);
}
reporting_assert_true(strpos($overview_source, 'tf_reporting_groups()') !== false, 'overview exposes all grouping options');
reporting_assert_true(strpos(file_get_contents(__DIR__ . '/../reports/traffic_summary.php'), "overview.php") !== false, 'traffic summary uses the shared overview report');

echo "All reporting tests passed.\n";
