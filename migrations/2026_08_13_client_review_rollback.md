# Client-review migration rollback

Do **not** attempt to reverse the fact-table `vendor_id` remap with ad-hoc `UPDATE` statements. After `clicks`, `conversions`, and related rows point at `global_vendors.id`, a DDL undo is not a safe rollback.

## Required rollback

1. Restore the verified database backup taken immediately before `migrations/2026_08_13_client_review.sql`.
2. Deploy the previous application code (the git revision that matched that backup).
3. Confirm `clicks` and `conversions` row counts match the pre-migration inventory.
4. Confirm a sample `/c/{code}` link and a test postback against the restored database.

## What this migration does not roll back

- `global_vendors` / `project_vendor` / `short_links` backfill
- Remapped `vendor_id` values on fact tables
- Generated opaque codes

If the migration aborts during **preflight** (`SIGNAL SQLSTATE 45000`), no remap has run and the database is unchanged aside from helper procedures. Re-run after fixing duplicate `vendor_code` values, missing projects, or orphaned click vendor references.
