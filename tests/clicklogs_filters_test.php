<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$list = file_get_contents($root . '/clicklogs/list.php');
$export = file_get_contents($root . '/clicklogs/export.php');
$helper = file_get_contents($root . '/helpers/clicklogs.php');
require_once $root . '/helpers/clicklogs.php';

function require_text(string $source, string $needle, string $message): void
{
    if (strpos($source, $needle) === false) {
        throw new RuntimeException($message . " (missing: {$needle})");
    }
}

foreach (['from', 'to', 'vendor_id', 'project_id', 'country', 'device', 'browser', 'os', 'isp', 'ip_address', 'click_id'] as $field) {
    require_text($helper, "'{$field}'", "Shared Click Logs filters must read {$field}");
}

foreach (['c.os', 'c.ip_address', 'c.click_id'] as $predicate) {
    require_text($helper, $predicate, "Shared Click Logs filters must filter by {$predicate}");
}

require_text($list, 'tf_copy_button(', 'Click IDs must use the shared copy helper');
require_text($list, 'data-full-click-id', 'Click IDs must expose a full value for copying');
require_text($list, 'col-12 col-sm-6 col-md-3', 'Click Logs filters must use responsive wider controls');

$parsed = tf_clicklogs_filters([
    'from' => '2026-09-03',
    'to' => '2026-09-01',
    'vendor_id' => '7',
    'project_id' => '8',
    'country' => 'us',
    'device' => 'Mobile',
    'browser' => 'Chrome',
    'os' => 'Windows',
    'isp' => 'Example ISP',
    'ip_address' => '192.0.2.',
    'click_id' => 'abc123',
]);
if ($parsed['from'] !== '2026-09-01' || $parsed['to'] !== '2026-09-03'
    || $parsed['country'] !== 'US' || $parsed['device'] !== 'mobile'
    || count($parsed['params']) !== 11
    || strpos($parsed['where_sql'], 'c.os LIKE ?') === false
    || strpos($parsed['where_sql'], 'c.ip_address LIKE ?') === false) {
    throw new RuntimeException('Click Logs filter normalization or predicate binding is incorrect');
}

echo "clicklogs filters regression checks passed\n";
