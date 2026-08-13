<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/functions.php';

function tf_test_assert($condition, $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['click_id' => 'abc', 'token' => 'tok', 'status' => '1'];
$_GET = ['click_id' => 'from-get'];
tf_test_assert(tf_request_value('click_id') === 'abc', 'POST body must win over GET for postback fields');
tf_test_assert((string)tf_request_value('token') === 'tok', 'POST token must be read');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = [];
$_GET = ['click_id' => 'from-get', 'sale_amount' => '10.5'];
tf_test_assert(tf_request_value('click_id') === 'from-get', 'GET postback fields must still work');
tf_test_assert(tf_request_value('missing', 'x') === 'x', 'missing keys use the default');

$postback = file_get_contents(__DIR__ . '/../tracking/postback.php');
tf_test_assert(str_contains($postback, 'tf_request_value'), 'postback.php must accept GET and POST via tf_request_value');
tf_test_assert(str_contains($postback, 'OK:DUPLICATE_TXN'), 'duplicate transaction_id must be rejected');

echo "postback tests passed\n";
