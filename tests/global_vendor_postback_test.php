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
expect_true(tf_resolve_postback_url('', 'https://vendor.example/global'), 'blank override uses global URL');
expect_true(tf_resolve_postback_url('https://project.example/pb', 'https://vendor.example/global') === 'https://project.example/pb', 'project override wins');
expect_true(tf_resolve_postback_url('', '') === '', 'blank override and blank global remain blank');

$macros = tf_postback_macros([
    'click_id' => 'click123',
    'status' => 0,
    'payout' => 2.5,
    'transaction_id' => 'txn-9',
    'sale_amount' => 10,
    'currency' => 'USD',
    'sub1' => 'facebook',
    'sub2' => 'US',
    'sub3' => 'campaign-42',
    'sub4' => 'creative-a',
    'sub5' => 'placement-7',
]);
$expanded = strtr(
    'click={click_id}&status={status}&payout={payout}&tx={transaction_id}&legacy={conversion_id}&sale={sale_amount}&currency={currency}&s1={sub1}&s2={sub2}&s3={sub3}&s4={sub4}&s5={sub5}',
    $macros
);
expect_true($expanded === 'click=click123&status=0&payout=2.5&tx=txn-9&legacy=txn-9&sale=10&currency=USD&s1=facebook&s2=US&s3=campaign-42&s4=creative-a&s5=placement-7', 'all postback macros expand including transaction and sub parameters');

echo "PASS: global vendor postback URL validation\n";
