<?php

require_once __DIR__ . '/../helpers/functions.php';

function assert_true($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$codes = [];
for ($i = 0; $i < 20; $i++) {
    $code = generate_tracking_code();
    $codes[] = $code;
    assert_true(preg_match('/^[A-Za-z0-9_-]{8,32}$/', $code) === 1, 'tracking code is URL-safe and opaque');
    assert_true(strpos($code, 'project') === false && strpos($code, 'vendor') === false, 'tracking code does not contain identifiers');
}
assert_true(count(array_unique($codes)) === count($codes), 'tracking codes are unique across generated samples');

$public_url = tracking_public_url('https://example.test', $codes[0]);
assert_true($public_url === 'https://example.test/c/' . $codes[0], 'public URL uses the /c/{code} format');
assert_true(strpos($public_url, 'project_id') === false && strpos($public_url, 'vendor_id') === false, 'public URL does not expose IDs');

$redirect_source = file_get_contents(__DIR__ . '/../tracking/redirect.php');
assert_true(strpos($redirect_source, "click.php?' . \$qs") === false, 'opaque resolver does not redirect browser to click.php');
assert_true(strpos($redirect_source, "FROM projects p") === false, 'opaque resolver has no project short-code fallback');

$test_source = file_get_contents(__DIR__ . '/../tracking/test.php');
assert_true(strpos($test_source, "FROM projects p") === false, 'test validator has no project short-code fallback');

$list_source = file_get_contents(__DIR__ . '/../projects/list.php');
assert_true(strpos($list_source, '/tracking/click.php?project_id=') === false, 'project list has no legacy tracking URL generation');

$click_source = file_get_contents(__DIR__ . '/../tracking/click.php');
assert_true(strpos($click_source, 'TF_INTERNAL_CLICK_REQUEST') !== false, 'click engine rejects direct public requests');
assert_true(strpos($click_source, "FROM projects WHERE id = ? AND status = 'live'") !== false, 'click engine requires a live project');
assert_true(strpos($click_source, "pv.status = 'active'") !== false, 'click engine requires an active vendor assignment');
assert_true(strpos($click_source, "gv.vendor_status = 'approved'") !== false, 'click engine requires an approved master vendor');

$validator_source = file_get_contents(__DIR__ . '/../tracking/test.php');
assert_true(strpos($validator_source, "project_status'] === 'live'") !== false, 'link validator checks project status');
assert_true(strpos($validator_source, "vendor_status'] === 'active'") !== false, 'link validator checks assignment status');
assert_true(strpos($validator_source, "master_vendor_status'] === 'approved'") !== false, 'link validator checks master vendor status');
assert_true(strpos($validator_source, 'echo "✅ Link is active\\n\\n";') === false, 'link validator does not always claim active');

echo "All opaque tracking link tests passed.\n";
