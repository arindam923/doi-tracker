# Conversion Logs Columns Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make Conversion Logs explicitly display every requested field with full Click ID and Transaction ID values.

**Architecture:** Keep the existing PHP/PDO query and pagination, but render dedicated table cells for the requested fields. Select `cv.click_time` with a fallback to `clicks.clicked_at`, and preserve moderation controls inside the Status cell. Keep CSV export aligned with the UI labels.

**Tech Stack:** PHP, MySQL/PDO, server-rendered HTML, shell-based PHP tests.

---

### Task 1: Add the failing regression test

**Files:**
- Create: `tests/conversion_logs_columns_test.php`

**Step 1: Write the failing test**

Create a source-level test that reads `convlogs/list.php` and `convlogs/export.php`, asserting the required UI headers, the `cv.click_time` fallback, untruncated ID output, conversion status output, and exact export labels.

**Step 2: Run it to verify it fails**

Run: `php tests/conversion_logs_columns_test.php`

Expected: FAIL because the current table uses `When`, `Lag`, `IDs`, and `State`, truncates IDs, and does not render conversion status.

### Task 2: Implement the explicit Conversion Logs fields

**Files:**
- Modify: `convlogs/list.php:32-40,123-196`

**Step 1: Select the correct click-time source**

Add `cv.click_time` to the query and calculate `$click_raw = $cv['click_time'] ?: $cv['click_time_from_click']` after the fetch. Alias the joined click timestamp as `click_time_from_click` to avoid ambiguity.

**Step 2: Render dedicated columns**

Use headers for Click Time, Conversion Time, Time Difference, Campaign, Revenue, Payout, Profit, Status, Transaction ID, and Click ID. Render both timestamps with the existing formatter, render time difference from `time_diff_seconds`, and output full escaped IDs without `substr()`.

**Step 3: Render actual conversion status**

Show `cv.status` in the Status cell, along with approval status and manual/automatic indicators and the existing approve/reject controls.

### Task 3: Align CSV export labels

**Files:**
- Modify: `convlogs/export.php:19-40`

**Step 1: Update export labels**

Rename `Convert Time` to `Conversion Time` and `Time Diff (s)` to `Time Difference (s)`. Keep full Click ID, Transaction ID, status, approval, financial fields, and existing export data.

### Task 4: Verify and commit

**Files:**
- Test: `tests/conversion_logs_columns_test.php`
- Verify: `convlogs/list.php`, `convlogs/export.php`

**Step 1: Run the focused regression test**

Run: `php tests/conversion_logs_columns_test.php`

Expected: PASS.

**Step 2: Run PHP lint**

Run: `php -l convlogs/list.php && php -l convlogs/export.php && php -l tests/conversion_logs_columns_test.php`

Expected: No syntax errors.

**Step 3: Run the repository PHP tests**

Run: `for test_file in tests/*_test.php; do php "$test_file" || exit 1; done`

Expected: All tests pass.

**Step 4: Review the diff**

Run: `git diff --check && git diff -- convlogs/list.php convlogs/export.php tests/conversion_logs_columns_test.php`

Expected: No whitespace errors and only the requested changes.
