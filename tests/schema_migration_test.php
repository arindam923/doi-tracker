<?php
declare(strict_types=1);

$sql = file_get_contents(__DIR__ . '/../migrations/2026_08_13_client_review.sql');
$rollback = __DIR__ . '/../migrations/2026_08_13_client_review_rollback.md';

function tf_test_assert($condition, $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

tf_test_assert(is_string($sql) && $sql !== '', 'migration SQL must exist');
tf_test_assert(is_file($rollback), 'rollback document must exist');
tf_test_assert(str_contains($sql, 'tf_client_review_preflight'), 'migration must define aborting preflight');
tf_test_assert(str_contains($sql, "SIGNAL SQLSTATE '45000'"), 'preflight must SIGNAL on failure');
tf_test_assert(str_contains($sql, "tf_remap_vendor_ids_if_table('email_campaigns')"), 'email_campaigns vendor_id must be remapped');
tf_test_assert(str_contains($sql, "tf_remap_vendor_ids_if_table('logs')"), 'logs vendor_id must be remapped');
tf_test_assert(str_contains($sql, 'vendor_portal_show_network_economics'), 'portal economics setting must be seeded');
tf_test_assert(str_contains($sql, 'uk_campaign_entry'), 'send uniqueness must include campaign+entry');
tf_test_assert(str_contains($sql, "'clicks', 'is_test'"), 'clicks.is_test must be added');
tf_test_assert(str_contains($sql, "'conversions', 'is_test'"), 'conversions.is_test must be added');

echo "schema migration tests passed\n";
