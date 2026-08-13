<?php
declare(strict_types=1);

if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://track-flow.test');
}

require_once __DIR__ . '/../helpers/functions.php';

function tf_test_assert($condition, $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

tf_test_assert(tf_is_valid_short_code('Ab12_-xyZ0'), '12-char opaque code must be valid');
tf_test_assert(!tf_is_valid_short_code('abc'), 'codes shorter than 4 must be rejected');
tf_test_assert(!tf_is_valid_short_code('abcdefghijklm'), 'codes longer than 12 must be rejected');
tf_test_assert(!tf_is_valid_short_code('ab cd'), 'spaces must be rejected');

$url = tf_opaque_tracking_url('VendorLink01');
tf_test_assert(strpos($url, 'project_id') === false, 'opaque URL must not contain project_id');
tf_test_assert(strpos($url, 'vendor_id') === false, 'opaque URL must not contain vendor_id');
tf_test_assert(str_ends_with($url, '/c/VendorLink01'), 'opaque URL must use /c/{code}');

$htaccess = file_get_contents(__DIR__ . '/../.htaccess');
tf_test_assert(str_contains($htaccess, '^c/([A-Za-z0-9_-]{4,12})$'), 'rewrite length must match PHP');
tf_test_assert(str_contains($htaccess, '^go/([A-Za-z0-9_-]{4,12})$'), '/go rewrite must match PHP');

$redirect = file_get_contents(__DIR__ . '/../tracking/redirect.php');
tf_test_assert(!str_contains($redirect, 'Location:'), 'redirect.php must not 302 to click.php with IDs');
tf_test_assert(str_contains($redirect, 'require __DIR__ . \'/click.php\''), 'redirect.php must include click.php internally');

$click = file_get_contents(__DIR__ . '/../tracking/click.php');
tf_test_assert(str_contains($click, 'tf_is_tracking_admin'), 'public click.php must gate diagnostic IDs');

$test_link = file_get_contents(__DIR__ . '/../tracking/test.php');
tf_test_assert(str_contains($test_link, 'is_test'), 'test.php must record a flagged test click');
tf_test_assert(str_contains($test_link, 'click_id='), 'test.php must pass click_id to the client landing page');

echo "tracking links tests passed\n";
