# Traffic Redirect Status Gating Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Ensure client redirects occur only for live projects, active project-vendor assignments, and approved master vendors, with the validator reporting inactive links accurately.

**Architecture:** Keep enforcement in `tracking/click.php`, the shared click engine used by opaque links. Add the master-vendor approval predicate to its vendor query, and make `tracking/test.php` calculate/report the same three-part eligibility state without recording traffic.

**Tech Stack:** PHP, PDO, MySQL, repository shell tests.

---

### Task 1: Add regression coverage

**Files:**
- Modify: `tests/opaque_tracking_links_test.php`

**Step 1: Write failing assertions**

Assert that the click engine requires `p.status = 'live'`, `pv.status = 'active'`, and `gv.vendor_status = 'approved'`, and that the validator contains status-aware eligibility logic rather than an unconditional active message.

**Step 2: Run the focused test**

Run: `php tests/opaque_tracking_links_test.php`

Expected: FAIL because the click query does not require approved master vendors and the validator unconditionally reports active.

### Task 2: Implement exact status gating

**Files:**
- Modify: `tracking/click.php:86-102`
- Modify: `tracking/test.php:19-47`

**Step 1: Update production enforcement**

Require `gv.vendor_status = 'approved'` in the vendor lookup. Retain the existing live-project and active-assignment predicates. Keep the pre-click HTTP 410 behavior.

**Step 2: Update validator behavior**

Select the master vendor status explicitly, compute eligibility from project `live`, assignment `active`, and master vendor `approved`, and print an active/inactive result with the reason/status values. Do not record a click.

### Task 3: Verify

**Files:**
- No additional files.

**Step 1: Run focused regression test**

Run: `php tests/opaque_tracking_links_test.php`

Expected: PASS.

**Step 2: Run all repository tests**

Run: `for f in tests/*test.php; do php "$f" || exit 1; done`

Expected: All tests pass.

**Step 3: Run syntax checks**

Run: `php -l tracking/click.php`, `php -l tracking/test.php`

Expected: No syntax errors.
