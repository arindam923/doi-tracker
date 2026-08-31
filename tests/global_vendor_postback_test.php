<?php
require_once __DIR__ . '/../helpers/functions.php';

function expect_true($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

expect_true(tf_is_valid_postback_url(''), 'blank postback URL is allowed');
expect_true(tf_is_valid_postback_url('https://vendor.example/postback?click_id={click_id}'), 'HTTPS postback URL is allowed');
expect_true(tf_is_valid_postback_url('http://vendor.example/postback'), 'HTTP postback URL is allowed');
expect_true(!tf_is_valid_postback_url('javascript:alert(1)'), 'javascript URL is rejected');
expect_true(!tf_is_valid_postback_url('/relative/postback'), 'relative URL is rejected');
expect_true(!tf_is_valid_postback_url('https:///missing-host'), 'URL without a host is rejected');

echo "PASS: global vendor postback URL validation\n";
