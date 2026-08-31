# Campaign Notes Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add durable internal Client Instructions, Optimization Notes, and Publisher Restrictions to project editing and the project detail page while preserving the existing dated notes timeline.

**Architecture:** Store the three categories as nullable `TEXT` columns on `projects`. Extend the existing role-protected, CSRF-protected project edit transaction to persist them, and render them in a dedicated internal-only card on `projects/detail.php`; leave `campaign_notes` unchanged for historical/ad-hoc entries. Add an idempotent SQL migration for deployed databases and update the canonical schema.

**Tech Stack:** PHP 7+/PDO, MySQL, Bootstrap-style existing UI, SQL migrations, shell-based PHP lint/integration checks.

---

### Task 1: Add schema support

**Files:**
- Create: `migration_campaign_notes.sql`
- Modify: `database.sql:89-132` (projects table definition)

**Step 1: Write the failing verification**

Add a focused shell check that searches the migration and schema for all three column names and fails while they are absent:

```sh
for column in client_instructions optimization_notes publisher_restrictions; do
  rg -q "${column}" migration_campaign_notes.sql database.sql
done
```

**Step 2: Run it to verify it fails**

Run from `doi-tracker/`. Expected: FAIL because the migration and schema columns do not yet exist.

**Step 3: Write the minimal schema change**

Add nullable `TEXT` columns to `projects`, grouped near `description`:

```sql
  `client_instructions` TEXT NULL,
  `optimization_notes` TEXT NULL,
  `publisher_restrictions` TEXT NULL,
```

Create `migration_campaign_notes.sql` using guarded `ADD COLUMN` statements so it can be safely applied to an existing installation. Keep the migration independent of the destructive fresh-install `database.sql` drops.

**Step 4: Run the verification**

Run the same shell check. Expected: PASS for all three names. If a MySQL instance is configured, apply the migration in a disposable/test database and confirm it can be run twice without error.

**Step 5: Commit**

```sh
git add database.sql migration_campaign_notes.sql
git commit -m "feat: add campaign notes fields"
```

### Task 2: Persist fields through project editing

**Files:**
- Modify: `projects/edit.php:31-79` (POST extraction and UPDATE)
- Modify: `projects/edit.php:261-265` (form fields)

**Step 1: Write the failing verification**

Create a focused static/integration check that asserts the edit handler reads, writes, and renders all three field names:

```sh
for field in client_instructions optimization_notes publisher_restrictions; do
  rg -q "${field}" projects/edit.php
done
```

**Step 2: Run it to verify it fails**

Run from `doi-tracker/`. Expected: FAIL because the edit handler has no references to the new fields.

**Step 3: Write the minimal implementation**

Trim each POST value, include the three columns in the existing parameterized `UPDATE projects` statement, pass the values in matching order, and add three labeled `<textarea>` controls to the existing edit form. Bind their values through the existing `$f` array and `sanitize()` helper. Do not add a second save endpoint or bypass the current transaction, CSRF, role, and audit flow.

**Step 4: Run the verification**

Run the field-reference check and `php -l projects/edit.php`. Expected: PASS with no syntax errors. If a configured database is available, submit a test edit and reload the record to verify round-trip persistence.

**Step 5: Commit**

```sh
git add projects/edit.php
git commit -m "feat: persist campaign note categories"
```

### Task 3: Render the internal Campaign Notes card

**Files:**
- Modify: `projects/detail.php:669-705` (Campaign Notes section)

**Step 1: Write the failing verification**

Add a focused static check that requires the detail page to contain the three labels and escaped project values:

```sh
for label in "Client Instructions" "Optimization Notes" "Publisher Restrictions"; do
  rg -q "$label" projects/detail.php
done
for field in client_instructions optimization_notes publisher_restrictions; do
  rg -q "project\['${field}'\]" projects/detail.php
done
```

**Step 2: Run it to verify it fails**

Run from `doi-tracker/`. Expected: FAIL because the existing card only contains the dated timeline form.

**Step 3: Write the minimal implementation**

Add a three-column responsive internal note summary above the existing timeline. Render non-empty values with `sanitize()` and show `Not added` for empty values. Add concise internal-only copy, preserve the existing `id="notes"` anchor, and retain the current add/delete timeline controls below it. Avoid exposing the fields in vendor-facing pages.

**Step 4: Run the verification**

Run the label/value check and `php -l projects/detail.php`. Expected: PASS. Inspect the HTML structure for valid escaping and responsive Bootstrap classes; if the local app can run, load a project with both populated and empty fields and confirm both states.

**Step 5: Commit**

```sh
git add projects/detail.php
git commit -m "feat: show campaign notes on project detail"
```

### Task 4: Verify the complete change

**Files:**
- Test: modified PHP and SQL files

**Step 1: Run syntax checks**

```sh
php -l projects/edit.php
php -l projects/detail.php
```

Expected: no syntax errors.

**Step 2: Run schema and field checks**

```sh
for column in client_instructions optimization_notes publisher_restrictions; do
  rg -q "${column}" database.sql migration_campaign_notes.sql projects/edit.php projects/detail.php
done
```

Expected: PASS for every column.

**Step 3: Review the diff**

```sh
git diff HEAD~3..HEAD --check
git status --short
```

Expected: no whitespace errors and only the intended feature commits plus any pre-existing user changes untouched.

**Step 4: Commit any required verification-only cleanup**

Only if the checks reveal a feature issue, correct it and rerun all checks before committing with a focused message.
