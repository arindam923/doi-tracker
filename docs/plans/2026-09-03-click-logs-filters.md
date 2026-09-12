# Click Logs Filters and Interaction Fixes Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add complete Click Logs filters, synchronize CSV export filtering, make Click IDs copyable, and fix the shared dropdown behavior/layout.

**Architecture:** Keep all filtering server-side with validated inputs and parameterized SQL. Extract the shared Click Logs filter parsing/predicate logic so list and export cannot drift. Use the existing `tfCopyText` helper for copy buttons and repair the shared dropdown positioning/visibility behavior in the common assets.

**Tech Stack:** PHP, PDO/MySQL, Bootstrap classes, vanilla JavaScript, CSS, repository PHP test scripts.

---

### Task 1: Add failing regression coverage for Click Logs filter parity

**Files:**
- Create: `tests/clicklogs_filters_test.php`
- Modify: `clicklogs/list.php`
- Modify: `clicklogs/export.php`

**Steps:**

1. Write static regression checks that require both endpoints to accept `from`, `to`, `vendor_id`, `project_id`, `country`, `device`, `browser`, `os`, `ip_address`, and `click_id`.
2. Require each endpoint to include the corresponding SQL predicate and parameterized value.
3. Require the list markup to include an accessible copy action using the full Click ID and the filter form to use responsive column classes.
4. Run `php tests/clicklogs_filters_test.php` and confirm it fails against the current implementation because OS/IP are absent as dedicated filters and export parity is incomplete.

### Task 2: Implement shared Click Logs filter parsing

**Files:**
- Create: `helpers/clicklogs.php`
- Modify: `clicklogs/list.php`
- Modify: `clicklogs/export.php`

**Steps:**

1. Add a helper that validates dates, normalizes country/device/text values, and returns a filter state plus `WHERE` fragments and bound parameters.
2. Use exact matching for vendor, project, country, device, and Click ID; use partial matching for browser, OS, and IP address.
3. Preserve the existing default date range and ensure an inverted range is normalized safely.
4. Replace duplicated parsing in both endpoints with the helper.
5. Run the focused test and confirm it passes.

### Task 3: Update the Click Logs filter UI and copyable Click IDs

**Files:**
- Modify: `clicklogs/list.php`

**Steps:**

1. Add the Operating System and IP Address fields with clear labels and query-string persistence.
2. Remove the ambiguous `IP / UA` generic field from the primary filter row; keep filtering scoped to the requested dedicated IP field.
3. Reflow the filter controls with responsive widths so select menus are not squeezed into one-character columns.
4. Keep export/reset/pagination query strings intact.
5. Render the shortened ID plus a button calling `tfCopyText()` with the full ID, including an accessible label and title.
6. Run the focused test and `php -l clicklogs/list.php`.

### Task 4: Synchronize CSV export

**Files:**
- Modify: `clicklogs/export.php`

**Steps:**

1. Apply every Click Logs filter through the shared helper.
2. Preserve all existing CSV columns and output escaping.
3. Run `php -l clicklogs/export.php` and the focused test.

### Task 5: Fix dropdown open/position behavior

**Files:**
- Modify: `assets/js/app.js`
- Modify: `assets/css/app.css`

**Steps:**

1. Inspect the dropdown initialization and event handling for trigger/menu ownership, hidden state, and body reparenting.
2. Add a regression assertion for the shared dropdown trigger/menu structure or fix the concrete issue found without changing unrelated menus.
3. Ensure menus remain visible above responsive containers and have usable width/max-height while retaining keyboard/outside-click behavior.
4. Run available PHP tests and, if the local app can be served, use the browser testing workflow to open Click Logs, open Project and Device menus, and capture the result.

### Task 6: Verify and hand off

**Files:**
- Verify: modified PHP, JS, CSS, and tests

**Steps:**

1. Run `php tests/clicklogs_filters_test.php`.
2. Run `php -l` on every modified PHP file.
3. Run the repository’s available test scripts.
4. Review `git diff` and `git status --short`, ensuring pre-existing user changes are preserved.
5. Report exact verification results and note that commits are unavailable if `.git` remains read-only.
