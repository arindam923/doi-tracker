# Opaque Tracking Links Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make vendor tracking links opaque and ensure project/vendor IDs never appear in public tracking URLs or intermediate redirects.

**Architecture:** Store cryptographically random vendor-specific codes in `short_links`. Resolve `/c/{code}` or `/go/{code}` in `tracking/redirect.php`, then run the click engine internally with resolved IDs. Remove ID-based link generation and the project-level short-code fallback.

**Tech Stack:** PHP, MySQL/PDO, Apache rewrite rules, shell-based PHP tests.

---

### Task 1: Add regression tests for opaque link behavior

**Files:**
- Create: `tests/opaque_tracking_links_test.php`

**Step 1: Write the failing test**

Cover random URL-safe code generation, generated links containing `/c/` but neither `project_id` nor `vendor_id`, and source-level assertions that redirect handling does not issue a `Location` header containing those IDs.

**Step 2: Run test to verify it fails**

Run: `php tests/opaque_tracking_links_test.php`

Expected: FAIL because the generator/helper and current redirect behavior do not satisfy the assertions.

### Task 2: Centralize opaque code and public-link helpers

**Files:**
- Modify: `helpers/functions.php`
- Modify: `vendors/attach.php`
- Modify: `vendors/create.php`

**Step 1: Implement minimal code**

Add a secure random code helper and use it when creating `short_links`. Retry on the unique constraint if a collision occurs. Keep the code independent of project/vendor IDs and names.

**Step 2: Run focused tests**

Run: `php tests/opaque_tracking_links_test.php`

Expected: code-generation and public-format assertions pass.

### Task 3: Resolve opaque links without exposing IDs

**Files:**
- Modify: `tracking/redirect.php`
- Modify: `tracking/click.php`
- Modify: `tracking/test.php`
- Modify: `tracking/qr.php`

**Step 1: Implement internal resolution**

Resolve only `short_links.code`, preserve `sub1`–`sub5` and `sid`, and invoke the click engine without a browser-visible redirect to an ID-bearing URL. Reject direct ID-based requests to `click.php`.

**Step 2: Remove legacy fallback**

Delete project `short_code` fallback queries from redirect, validation, and QR lookup.

**Step 3: Run focused tests**

Run: `php tests/opaque_tracking_links_test.php`

Expected: resolution and no-ID redirect assertions pass.

### Task 4: Remove remaining legacy link generation

**Files:**
- Modify: `projects/list.php`
- Modify: `projects/detail.php`
- Modify: `vendor_portal/index.php`

**Step 1: Use stored short-link codes everywhere**

Replace fallback construction of `click.php?project_id=...&vendor_id=...` and project short-code links with vendor-specific `short_links` URLs. Show an unavailable state if no vendor-specific code exists.

**Step 2: Search for regressions**

Run: `rg -n "tracking/click\.php\?project_id|short_code.*vendor|vendor_id=.*project_id|FROM projects.*short_code" . -g '*.php'`

Expected: no public tracking-link generation or resolution remains using those patterns.

### Task 5: Verify the complete change

**Files:**
- Modify: any files required by test findings only

**Step 1: Run syntax checks**

Run: `find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l`

Expected: no PHP syntax errors.

**Step 2: Run focused regression tests**

Run: `php tests/opaque_tracking_links_test.php`

Expected: all assertions pass.

**Step 3: Inspect the final diff**

Run: `git diff --check && git diff --stat`

Expected: no whitespace errors and only scoped tracking-link changes.
