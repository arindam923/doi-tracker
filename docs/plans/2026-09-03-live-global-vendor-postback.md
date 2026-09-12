# Live Global Vendor Postback Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make blank project assignment postbacks inherit the vendor global URL dynamically while preserving explicit project overrides.

**Architecture:** Store only explicit overrides in `project_vendor.postback_url`. Resolve the effective URL by joining the assignment to `global_vendors` at delivery time. Normalize legacy copied values that exactly equal the current global value, leaving differing values untouched.

**Tech Stack:** PHP, PDO/MySQL, server-rendered forms, existing test scripts.

---

### Task 1: Add failing inheritance tests

**Files:**
- Modify: `tests/global_vendor_postback_test.php`
- Modify: `helpers/functions.php`

**Step 1:** Add tests asserting blank override returns the global URL, explicit override wins, and a changed global URL is returned for a blank override.

**Step 2:** Run `php tests/global_vendor_postback_test.php` and confirm the new helper assertions fail before implementation.

### Task 2: Implement runtime inheritance

**Files:**
- Modify: `helpers/functions.php`
- Modify: `tracking/postback.php`

**Step 1:** Add a helper that resolves an assignment override against a global URL.

**Step 2:** Select `gv.global_postback_url` with the project assignment in the tracking runtime and resolve the effective URL before delivery.

**Step 3:** Run the focused test and PHP lint.

### Task 3: Store only explicit assignment overrides

**Files:**
- Modify: `vendors/create.php`
- Modify: `vendors/attach.php`
- Modify: `vendors/assignment_edit.php`

**Step 1:** Change create, attach, and assignment-edit handlers to persist blank `project_vendor.postback_url` when no explicit override is submitted.

**Step 2:** Keep UI values helpful by showing the global URL as a placeholder/reference and preserving explicitly typed values.

**Step 3:** Run PHP lint and focused tests.

### Task 4: Normalize legacy copied defaults

**Files:**
- Modify: `migration_global_vendors.sql`

**Step 1:** Add an idempotent update that changes assignment URLs to `NULL` only where they exactly equal the current global vendor URL.

**Step 2:** Do not alter differing assignment URLs.

**Step 3:** Verify migration SQL references and run all available tests.
