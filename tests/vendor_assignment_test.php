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

tf_test_assert(tf_vendor_can_be_assigned('approved') === true, 'approved vendors can be attached');
tf_test_assert(tf_vendor_can_be_assigned('pending') === false, 'pending vendors cannot be attached');
tf_test_assert(tf_vendor_can_be_assigned('suspended') === false, 'suspended vendors cannot be attached');
tf_test_assert(tf_vendor_can_be_assigned('blacklisted') === false, 'blacklisted vendors cannot be attached');

$attach = file_get_contents(__DIR__ . '/../vendors/attach.php');
tf_test_assert(str_contains($attach, 'tf_vendor_can_be_assigned'), 'attach.php must enforce vendor status');

echo "vendor assignment tests passed\n";
