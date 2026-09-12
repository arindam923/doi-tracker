<?php
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/constants.php';

function expect_traffic($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$selected = tf_normalize_traffic_types(['Google', 'Email', 'Google', 'Invalid']);
expect_traffic($selected === ['Email', 'Google'], 'traffic types are filtered and stored in canonical order');
expect_traffic(tf_normalize_traffic_types('Email,Google') === ['Email', 'Google'], 'legacy comma-separated traffic values are supported');
expect_traffic(tf_traffic_type_includes('Email,Google', 'Email'), 'traffic type membership matches a selected value');
expect_traffic(!tf_traffic_type_includes('Email,Google', 'Native'), 'traffic type membership rejects an unselected value');

echo "PASS: traffic type normalization\n";
