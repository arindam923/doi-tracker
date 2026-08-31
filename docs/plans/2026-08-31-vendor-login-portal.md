# Vendor Login Portal Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Complete a secure vendor portal that exposes only assigned campaigns, vendor links, vendor metrics, and campaign-wise reports.

**Architecture:** Add reusable vendor authorization/query helpers, then make the existing dashboard use assignment-bounded aggregates. Keep the portal read-only for campaign data, use existing short-link and QR infrastructure, and enforce current account status on every portal request.

**Tech Stack:** PHP 8+, PDO/MySQL, server-rendered HTML, Bootstrap, Chart.js, repository PHP smoke tests.

---

### Task 1: Add failing authorization and reporting tests

**Files:**
- Create: `tests/vendor_portal_authorization_test.php`
- Create: `helpers/vendor_portal.php`

**Steps:**

1. Write tests for assignment predicate generation, allowed date filters, sensitive-field allowlisting, and suspended/blacklisted status rejection.
2. Run `php tests/vendor_portal_authorization_test.php`; confirm it fails because the helper does not exist.
3. Implement the smallest pure helper functions needed by the tests.
4. Run the test again and confirm it passes.

### Task 2: Harden vendor authentication and session validation

**Files:**
- Modify: `helpers/auth_middleware.php`
- Modify: `vendor_portal/auth.php`
- Modify: `vendor_portal/logout.php`

**Steps:**

1. Extend the failing tests for allowed/blocked vendor statuses.
2. Run the focused test and confirm failure.
3. Make magic-link login apply the same status check as password login, and make `require_vendor_login()` reload the account and reject inactive/suspended/blacklisted accounts.
4. Ensure logout clears the vendor namespace and regenerates the session as appropriate.
5. Run the focused test and all PHP syntax checks.

### Task 3: Replace aggregate-only portal data with assignment-bounded campaign reporting

**Files:**
- Modify: `vendor_portal/index.php`
- Modify: `helpers/vendor_portal.php`

**Steps:**

1. Add failing assertions covering campaign rows, clicks, conversions, conversion rate, payout, tracking URL, QR URL, and date filtering.
2. Run the test and confirm the current dashboard cannot satisfy the assertions.
3. Query campaigns by active `project_vendor` rows for the authenticated vendor, left-join vendor short links, and calculate metrics only inside the selected date range.
4. Validate `project_id` against the assigned campaign set; return no data for tampered/unassigned IDs.
5. Render a campaign-wise table with copyable URL and QR action, and retain a vendor-scoped recent-conversion table without client/internal financial fields.
6. Remove network cost, client revenue, and profit KPIs; display vendor payout only when a configured payout is present.
7. Run focused tests and PHP lint.

### Task 4: Verify isolation and regressions

**Files:**
- Modify: `tests/vendor_portal_authorization_test.php`

**Steps:**

1. Add tests proving another vendor’s campaign, inactive assignment, and unassigned historical conversion are excluded.
2. Run all repository tests with `for f in tests/*.php; do php "$f"; done`.
3. Run `find vendor_portal -name '*.php' -print0 | xargs -0 -n1 php -l`.
4. Inspect the diff for accidental client/internal fields or campaign-setting POST actions.
5. Commit the implementation with `git add` limited to the portal, helper, tests, and plan files.
