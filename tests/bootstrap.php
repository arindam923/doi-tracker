<?php
declare(strict_types=1);

/**
 * Test-only application bootstrap.
 *
 * Set TRACK_FLOW_TEST_DB before running an integration test. The explicit
 * `_test` suffix prevents accidental execution against a production database.
 */
$testDb = getenv('TRACK_FLOW_TEST_DB') ?: '';
if ($testDb === '' || !preg_match('/_test$/', $testDb)) {
    fwrite(STDERR, "Refusing to run without TRACK_FLOW_TEST_DB ending in _test.\n");
    exit(2);
}

foreach (['TRACK_FLOW_TEST_DB_HOST' => '127.0.0.1', 'TRACK_FLOW_TEST_DB_USER' => 'root', 'TRACK_FLOW_TEST_DB_PASS' => ''] as $variable => $default) {
    $value = getenv($variable);
    define(str_replace('TRACK_FLOW_TEST_', '', $variable), $value === false ? $default : $value);
}
define('DB_NAME', $testDb);
define('ENCRYPTION_KEY', 'test-only-encryption-key-not-for-production');
define('BASE_URL', 'http://track-flow.test');

$_SERVER['SERVER_NAME'] = 'track-flow.test';
$_SERVER['HTTPS'] = 'off';

require_once __DIR__ . '/../config.php';

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
