<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/constants.php';

function lifecycle_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

lifecycle_assert_same('live', tf_operational_status_for_campaign('live'), 'Live campaign must accept traffic.');
lifecycle_assert_same('hold', tf_operational_status_for_campaign('testing'), 'Testing campaign must not accept production traffic.');
lifecycle_assert_same('hold', tf_operational_status_for_campaign('paused'), 'Paused campaign must stop traffic.');
lifecycle_assert_same('closed', tf_operational_status_for_campaign('completed'), 'Completed campaign must close traffic.');
lifecycle_assert_same('archived', tf_operational_status_for_campaign('archived'), 'Archived campaign must remain archived.');
lifecycle_assert_same('draft', tf_campaign_status_for_request('draft'), 'Known lifecycle status must be accepted.');
lifecycle_assert_same(null, tf_campaign_status_for_request('invalid'), 'Unknown lifecycle status must be rejected.');

echo "project lifecycle tests passed\n";
