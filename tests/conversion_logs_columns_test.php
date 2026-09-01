<?php

function assert_true($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

function assert_contains($source, $needle, $message) {
    assert_true(strpos($source, $needle) !== false, $message);
}

$list_source = file_get_contents(__DIR__ . '/../convlogs/list.php');
$export_source = file_get_contents(__DIR__ . '/../convlogs/export.php');

foreach (['Click Time', 'Conversion Time', 'Time Difference', 'Revenue', 'Payout', 'Profit', 'Status', 'Transaction ID', 'Click ID'] as $header) {
    assert_contains($list_source, '<th' . (in_array($header, ['Revenue', 'Payout', 'Profit'], true) ? ' class="is-numeric"' : '') . '>' . $header . '</th>', "UI has {$header} column");
}

assert_contains($list_source, "\$cv['click_time']", 'UI reads the conversion click time');
assert_contains($list_source, "click_time_from_click", 'UI has a related-click click-time fallback');
assert_contains($list_source, "\$cv['status'] ?? 'complete'", 'UI renders conversion status');
assert_contains($list_source, 'sanitize($click_id)', 'UI renders the full escaped Click ID');
assert_contains($list_source, 'sanitize($txn)', 'UI renders the full escaped Transaction ID');
assert_true(strpos($list_source, 'substr($click_id') === false, 'UI does not truncate Click ID');
assert_true(strpos($list_source, 'substr($txn') === false, 'UI does not truncate Transaction ID');
assert_contains($export_source, "'Conversion Time'", 'CSV labels conversion time explicitly');
assert_contains($export_source, "'Time Difference (s)'", 'CSV labels time difference explicitly');
assert_contains($export_source, 'COALESCE(cv.click_time, c.clicked_at)', 'CSV uses the conversion click time with a related-click fallback');

echo "All conversion log column tests passed.\n";
