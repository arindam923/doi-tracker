<?php

function offer_assert_true($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$detail_source = file_get_contents(__DIR__ . '/../projects/detail.php');
$css_source = file_get_contents(__DIR__ . '/../assets/css/app.css');

foreach (['Landing Page', 'Client Link', 'Preview Link', 'Tracking Link', 'Test Link', 'Global Postback URL'] as $label) {
    offer_assert_true(strpos($detail_source, $label) !== false, "detail page includes {$label}");
}

offer_assert_true(strpos($detail_source, "tracking_public_url(BASE_URL") !== false, 'detail page builds opaque tracking links');
offer_assert_true(strpos($detail_source, '/tracking/test.php?c=') !== false, 'detail page builds tracking test links');
offer_assert_true(strpos($detail_source, 'global_postback_url') !== false, 'detail page exposes vendor global postback data');
offer_assert_true(strpos($detail_source, 'tf-offer-link-hub') !== false, 'detail page uses the compact offer link hub');
offer_assert_true(strpos($detail_source, 'tf-offer-link-featured') !== false, 'detail page uses featured link tiles');
offer_assert_true(strpos($detail_source, 'tf-offer-link-details') !== false, 'detail page groups secondary links in expandable details');
offer_assert_true(strpos($detail_source, 'offer-vendor-select') !== false, 'detail page provides a vendor selector');
offer_assert_true(strpos($css_source, '.tf-offer-link-hub') !== false, 'link hub has scoped styles');
offer_assert_true(strpos($css_source, '@media (max-width: 768px)') !== false, 'link hub includes responsive styling');

echo "All offer details link tests passed.\n";
