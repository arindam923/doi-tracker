<?php

/**
 * Track Flow — Email dedupe helpers (Phase 7, Item #11)
 * Used by email/lists.php (CSV import) and cron/email_batch.php.
 */

/**
 * Dedupe an array of emails case-insensitively.
 *
 * Input:  array of rows, each with an 'email' key (and optional 'name', 'metadata').
 * Output: array with:
 *   - 'records' => array of unique rows (order preserved, first occurrence wins)
 *   - 'unique'  => count of unique emails
 *   - 'dupes'   => count of duplicate (removed) emails
 *   - 'invalid' => count of malformed emails dropped
 */
function email_dedupe($rows) {
    $seen = [];
    $unique = [];
    $dupes = 0;
    $invalid = 0;

    foreach ((array)$rows as $row) {
        if (!is_array($row) || empty($row['email'])) {
            $invalid++;
            continue;
        }
        $email = strtolower(trim($row['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $invalid++;
            continue;
        }
        if (isset($seen[$email])) {
            $dupes++;
            continue;
        }
        $seen[$email] = true;
        $row['email'] = $email;
        $unique[] = $row;
    }

    return [
        'unique'   => $unique,
        'unique_count' => count($unique),
        'dupes'    => $dupes,
        'invalid'  => $invalid,
    ];
}

/**
 * Insert / ignore list entries using the (list_id, dedupe_hash) unique key.
 * Recomputes record_count on the owning list and sets is_deduped=1.
 *
 * Note: legacy email_lists rows may lack a `dedupe_hash` column on installs
 * that ran migration_v2 repeatedly; this helper is defensive.
 *
 * @return int number of rows actually inserted (0 for duplicates).
 */
function upsert_list_entries($pdo, $list_id, $rows) {
    $inserted = 0;
    $tx = !$pdo->inTransaction();
    if ($tx) $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            INSERT INTO email_list_entries (list_id, email, name, country, source, metadata_json, is_unsubscribed, dedupe_hash, added_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?, NOW())
            ON DUPLICATE KEY UPDATE dedupe_hash = VALUES(dedupe_hash)
        ");
        foreach ((array)$rows as $row) {
            $email = strtolower(trim($row['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $hash = sha1($email);
            $stmt->execute([
                $list_id,
                $email,
                $row['name'] ?? null,
                !empty($row['country']) ? strtoupper(substr($row['country'], 0, 2)) : null,
                $row['source'] ?? null,
                isset($row['metadata']) ? json_encode($row['metadata']) : null,
                $hash,
            ]);
            if ($stmt->rowCount() === 1) $inserted++;
        }

        $pdo->prepare("UPDATE email_lists SET is_deduped = 1, record_count = (SELECT COUNT(*) FROM email_list_entries WHERE list_id = ?) WHERE id = ?")
            ->execute([$list_id, $list_id]);

        if ($tx) $pdo->commit();
    } catch (Throwable $e) {
        if ($tx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return $inserted;
}

/**
 * Resolve a list (by id or name) to its array of active recipient entries.
 * Returns [{email, name, metadata}] or [] when absent/empty.
 */
function email_list_recipients($pdo, $list_id) {
    $stmt = $pdo->prepare("SELECT email, name FROM email_list_entries WHERE list_id = ? AND is_unsubscribed = 0 ORDER BY added_at DESC");
    $stmt->execute([$list_id]);
    return $stmt->fetchAll();
}
