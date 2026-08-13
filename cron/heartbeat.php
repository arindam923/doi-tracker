<?php
/**
 * TRACK FLOW — Heartbeat + log rotation (Phase 9, hardening #6)
 *
 * Writes a heartbeat line and purges storage/logs/*.log older than 30 days.
 * Runs via cron every 15 minutes:
 *   * / 15 * * * * cd /path/to/doi-tracker && php cron/heartbeat.php >> storage/logs/heartbeat.log 2>&1
 */

require_once __DIR__ . '/../config.php';

$log_dir = __DIR__ . '/../storage/logs';
if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);

// ── Heartbeat line ─────────────────────────────────────────────
$ok = true;
try {
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    $ok = false;
}
$line = '[' . date('c') . '] heartbeat db=' . ($ok ? 'ok' : 'FAIL: ' . $e->getMessage()) . ' php=' . PHP_VERSION . PHP_EOL;
if (is_writable($log_dir)) {
    @file_put_contents($log_dir . '/heartbeat.log', $line, FILE_APPEND | LOCK_EX);
}

// ── Purge logs older than 30 days ──────────────────────────────
$cutoff = time() - 30 * 86400;
$removed = 0;
foreach (glob($log_dir . '/*.log') ?: [] as $file) {
    if (is_file($file) && filemtime($file) < $cutoff) {
        if (@unlink($file)) $removed++;
    }
}

// Also purge stale QR / geo caches (older than 7 days)
foreach (['qr_cache', 'geo_cache'] as $cache_dir_name) {
    $cache_dir = __DIR__ . '/../storage/' . $cache_dir_name;
    foreach (glob($cache_dir . '/*') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < time() - 7 * 86400) {
            @unlink($file);
        }
    }
}

if ($removed > 0) {
    error_log('heartbeat: purged ' . $removed . ' log file(s) older than 30 days');
}
