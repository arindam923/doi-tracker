# Global Vendor Postback Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add reusable global vendor postbacks that seed new project assignments, preserve project-specific overrides, and remove Company Name from all vendor screens.

**Architecture:** Store the reusable URL on `global_vendors.global_postback_url`; continue storing the effective URL on `project_vendor.postback_url`. New vendor creation and existing-vendor attachment will resolve the assignment URL as `explicit project override || global vendor URL`, while existing assignments remain unchanged when the global value is edited.

**Tech Stack:** PHP 8-style server-rendered forms, PDO/MySQL, Bootstrap UI, existing CSRF/role/audit helpers, SQL migrations.

---

### Task 1: Add the global vendor postback column

**Files:**
- Modify: `doi-tracker/database.sql:global_vendors definition`
- Modify: `doi-tracker/migration_global_vendors.sql:global vendor migration sections`

**Step 1: Write the migration verification check**

Document the expected schema assertion for both fresh installs and upgrades:

```sql
SHOW COLUMNS FROM global_vendors LIKE 'global_postback_url';
```

Expected: one nullable `VARCHAR(500)` column.

**Step 2: Add the fresh-install column**

Add `global_postback_url VARCHAR(500) NULL` to the `global_vendors` table in `database.sql`, near the vendor defaults/contact fields.

**Step 3: Add an idempotent upgrade migration**

Add an upgrade-safe `ALTER TABLE` block to `migration_global_vendors.sql` that adds the column only when absent, following the migration’s existing compatibility approach. Do not backfill from `project_vendor.postback_url`, because one global vendor can have different project URLs.

**Step 4: Verify the SQL text**

Run:

```bash
rg -n "global_postback_url|CREATE TABLE `global_vendors`|ALTER TABLE global_vendors" database.sql migration_global_vendors.sql
```

Expected: the fresh schema and idempotent migration both define the new column, with no data backfill query.

**Step 5: Commit**

```bash
git add database.sql migration_global_vendors.sql
git commit -m "feat: add global vendor postback storage"
```

### Task 2: Add the global URL to vendor creation

**Files:**
- Modify: `doi-tracker/vendors/create.php:POST parsing, global vendor INSERT/UPDATE, form fields`

**Step 1: Add the failing behavior checks**

Exercise the handler with these inputs in a staging database:

- standalone vendor with `global_postback_url=https://vendor.example/pb?click_id={click_id}`;
- project vendor with global URL and blank project override;
- project vendor with both global URL and explicit `postback_url` override;
- non-HTTP URL such as `javascript:alert(1)`.

Expected before implementation: the global value is not persisted, and URL validation is absent.

**Step 2: Parse and validate the new field**

Read `global_postback_url` separately from the existing project `postback_url`. Normalize whitespace and allow blank values. For non-empty values, validate that `parse_url()` returns an `http` or `https` scheme and a host; add a form error and preserve `$_POST` in `$_SESSION['form_data']` on failure.

**Step 3: Persist the global URL**

Include `global_postback_url` in both the existing-vendor `UPDATE global_vendors` and new-vendor `INSERT INTO global_vendors` statements. Treat a deliberately submitted blank value as “no global postback”; on edit, a blank saved value clears the existing global URL. Cover persistence and clearing behavior in the tests.

**Step 4: Resolve the project assignment URL**

When `$project_id` is present, calculate the effective assignment URL as the explicit project `postback_url` when non-empty, otherwise the submitted `global_postback_url`. Persist that effective value in `project_vendor.postback_url`. The project field must remain an override, not another write to the global vendor field.

**Step 5: Update the form**

Add a reusable-vendor “Global Postback URL” field outside the project-only section. Keep “Vendor Postback URL” in the project section but rename it to “Project Override Postback URL” and add helper text explaining that blank uses the global URL. Use separate names/IDs: `global_postback_url` and `postback_url`.

**Step 6: Make the project form visibly default the override**

Use the existing form data and a small inline script or existing app JS to initialize the project override field from the global field only while the override is blank and the vendor is being newly created. Do not continually overwrite a user-edited override.

**Step 7: Run syntax verification**

Run:

```bash
php -l vendors/create.php
```

Expected: `No syntax errors detected`.

**Step 8: Commit**

```bash
git add vendors/create.php
git commit -m "feat: capture global postback when creating vendors"
```

### Task 3: Make the global URL editable on the vendor edit screen

**Files:**
- Modify: `doi-tracker/vendors/edit_global.php:POST parsing/update SQL/form`

**Step 1: Add the failing edit check**

Open an existing vendor with a stored global URL, submit a changed URL, then submit a blank value.

Expected before implementation: the field is not shown and the value cannot be changed.

**Step 2: Parse and validate the field**

Read `global_postback_url`, apply the same optional HTTP/HTTPS validation used by creation, and redirect back with a preserved error when invalid.

**Step 3: Update the global vendor record**

Add `global_postback_url` to the update statement. Include old/new URL presence (and the URL value only where the existing audit policy permits it) in the audit payload without exposing unnecessary full query strings in general logs. A blank submitted value clears the global URL.

**Step 4: Render the editable field**

Add an input in the reusable vendor details section, populated from `$vendor['global_postback_url']`, with macro helper text matching the supported outbound postback macros.

**Step 5: Run syntax verification**

Run:

```bash
php -l vendors/edit_global.php
```

Expected: `No syntax errors detected`.

**Step 6: Commit**

```bash
git add vendors/edit_global.php
git commit -m "feat: edit global vendor postback"
```

### Task 4: Default existing-vendor attachments to the global URL

**Files:**
- Modify: `doi-tracker/vendors/attach.php:POST resolution and INSERT/UPDATE`
- Modify: `doi-tracker/projects/detail.php:available vendor query, attach form, vendor option data`

**Step 1: Add the failing attachment checks**

For an existing vendor with a global URL, attach it once with no project URL and once with a project override.

Expected before implementation: the first assignment stores an empty URL, and the second stores the explicit override.

**Step 2: Expose the global URL in the project attach form**

Include `gv.global_postback_url` in the available-vendor query. Put it in each vendor option’s data attribute and add an editable `postback_url` input to the attach form. When the selected vendor changes, populate the input from its global value; preserve manual edits after the user changes the field.

**Step 3: Resolve the URL server-side**

In `attach.php`, load the vendor before writing and calculate `effective_postback_url` as the submitted project value when non-empty, otherwise `$gv['global_postback_url']`. Validate the effective value and use it in both insert and duplicate-update paths. Never rely only on client-side defaults.

**Step 4: Update the detail-page labels**

Label the field “Project Override Postback URL” and explain that leaving it blank uses the vendor’s global URL. This makes the edit point clear at attachment time.

**Step 5: Run syntax verification**

Run:

```bash
php -l vendors/attach.php
php -l projects/detail.php
```

Expected: both files pass.

**Step 6: Commit**

```bash
git add vendors/attach.php projects/detail.php
git commit -m "feat: default attachments to global vendor postback"
```

### Task 5: Clarify and preserve project-specific editing

**Files:**
- Modify: `doi-tracker/vendors/assignment_edit.php:query, POST validation, form labels`

**Step 1: Add the failing override-preservation check**

Edit an assignment that has a project-specific URL while the global vendor has a different URL.

Expected before implementation: the screen does not show which value is global, making the override relationship unclear.

**Step 2: Load the global URL alongside the assignment**

Select `gv.global_postback_url` with the existing assignment query.

**Step 3: Validate and save only the assignment URL**

Apply the shared HTTP/HTTPS URL validation to the assignment field. Keep the existing update target as `project_vendor.postback_url`; do not update `global_vendors` from this screen. If the submitted project override is blank, save the current `global_vendors.global_postback_url` as the effective assignment URL, providing an explicit reset-to-global behavior.

**Step 4: Render both values clearly**

Keep the project assignment URL editable and show the global URL as read-only reference/helper text. Explain that clearing the assignment URL restores the current global default on save. Use the current assignment URL as the input value, so an existing project override remains visible and editable.

**Step 5: Run syntax verification**

Run:

```bash
php -l vendors/assignment_edit.php
```

Expected: `No syntax errors detected`.

**Step 6: Commit**

```bash
git add vendors/assignment_edit.php
git commit -m "feat: clarify project vendor postback overrides"
```

### Task 6: Remove Company Name from all vendor screens

**Files:**
- Modify: `doi-tracker/vendors/create.php:vendor form and POST payload`
- Modify: `doi-tracker/vendors/edit_global.php:vendor form and POST payload`
- Modify: `doi-tracker/vendors/global.php:search/display output`
- Modify: `doi-tracker/vendors/global_library.php:search/display output`
- Modify: `doi-tracker/vendors/list.php:project vendor query/display output`
- Modify: `doi-tracker/projects/detail.php:vendor query/display output if rendered`

**Step 1: Add the failing UI search check**

Run:

```bash
rg -n "Company Name|company_name" vendors projects/detail.php --glob '*.php'
```

Expected before implementation: vendor screens still contain form fields, search clauses, selected columns, or display markup.

**Step 2: Remove input and write handling**

Remove the Company Name form controls and stop reading/writing `company_name` in create/edit vendor handlers. Preserve the database column and historical values.

**Step 3: Remove vendor-screen display and filters**

Remove Company Name from vendor list/library/performance output and remove it from vendor search predicates where that search is presented as a vendor-screen field. Keep unrelated reporting/database compatibility queries only if they are not part of the vendor UI.

**Step 4: Verify absence**

Run the same `rg` command and inspect any remaining matches. Expected: no vendor-screen UI or handler references; `global_vendors.company_name` may remain in schema/migration compatibility code if required.

**Step 5: Run syntax verification**

Run:

```bash
php -l vendors/create.php
php -l vendors/edit_global.php
php -l vendors/global.php
php -l vendors/global_library.php
php -l vendors/list.php
php -l projects/detail.php
```

Expected: all files pass.

**Step 6: Commit**

```bash
git add vendors/create.php vendors/edit_global.php vendors/global.php vendors/global_library.php vendors/list.php projects/detail.php
git commit -m "refactor: remove company name from vendor screens"
```

### Task 7: Add regression coverage and run the full verification pass

**Files:**
- Create or modify: `doi-tracker/tests/global_vendor_postback_test.php` if the project’s test harness supports PHP tests; otherwise document the cases in `docs/plans/2026-08-31-global-vendor-postback.md` and use the staging smoke checklist below.

**Step 1: Write regression cases**

Cover:

1. migration adds the nullable global column;
2. standalone creation persists a global URL;
3. project creation defaults assignment URL from the global URL;
4. explicit project URL overrides the global URL;
5. existing-vendor attachment defaults from the global URL;
6. attachment override wins;
7. global edit changes future defaults without changing existing assignments;
8. invalid schemes are rejected;
9. Company Name is absent from all vendor forms and rendered list output.

**Step 2: Run syntax and static checks**

Run:

```bash
find vendors projects -name '*.php' -print0 | xargs -0 -n1 php -l
rg -n "global_postback_url|Project Override Postback URL|Global Postback URL" vendors projects/detail.php database.sql migration_global_vendors.sql
```

Expected: no PHP syntax errors, and all new behavior has corresponding references.

**Step 3: Run the staged browser/manual smoke test**

Verify in order:

- Add standalone vendor with global URL; reopen edit and change it.
- Add vendor from a project; confirm global URL and project override are visible/editable.
- Attach an existing vendor; confirm global URL is prefilled and can be overridden.
- Edit an assignment; confirm the project URL is editable and global reference is visible.
- Confirm a previously configured project assignment remains unchanged after global edit.
- Open every vendor screen and confirm Company Name is absent.
- Record one successful conversion/postback test and confirm outbound delivery still uses the assignment URL.

**Step 4: Review the diff and working tree**

Run:

```bash
git diff --check
git status --short
```

Expected: no whitespace errors. Do not stage or modify unrelated existing changes in `.DS_Store`, `Archive.zip`, `assets/css/app.css`, client files, or other vendor edits already present in the worktree.

**Step 5: Commit**

```bash
git add tests docs/plans/2026-08-31-global-vendor-postback.md
git commit -m "test: cover global vendor postback defaults"
```
