# Global Vendor Postback Design

**Date:** 2026-08-31

## Goal

Allow an administrator to save a reusable postback URL on a global vendor while creating or editing that vendor. New project assignments should start with that global URL, while retaining an editable project-specific override. Remove Company Name from all vendor-facing administration screens without deleting existing database data.

## Current context

The application uses `global_vendors` as the reusable vendor master and `project_vendor` as the project-specific assignment table. `project_vendor.postback_url` already exists and is used by `tracking/postback.php` for outbound vendor postbacks. The global vendor form currently has no postback field, so users must enter the URL again when creating or attaching a vendor to a project.

## Approved design

### Data ownership

- Add nullable `global_postback_url VARCHAR(500)` to `global_vendors`.
- Keep `project_vendor.postback_url` as the effective URL for an individual project assignment.
- On a new assignment, initialize `project_vendor.postback_url` from `global_vendors.global_postback_url` when no project override is supplied.
- Do not change existing assignment URLs during migration or when the global vendor URL is edited.
- Continue outbound delivery from `project_vendor.postback_url`; the tracking runtime does not need fallback logic.

### Vendor creation

Add an optional, URL-validated Global Postback URL field to the reusable vendor details section of `vendors/create.php`. The field must be available both when creating a standalone global vendor and when creating a vendor from a project.

When the form is opened from a project, the project-level Vendor Postback URL field remains available. It should be initialized from the submitted global URL for a new vendor, and the user may edit it to create a project-specific override before saving.

### Vendor editing

Add the saved global URL to `vendors/edit_global.php` as an editable field. Keep the existing project assignment editor in `vendors/assignment_edit.php` editable for per-project overrides. The labels and helper text must distinguish the reusable global URL from the project-specific effective URL.

### Existing-vendor attachment

The existing-vendor attach path must use the global URL as its default when no project override is supplied. The attachment UI in the project detail flow should expose the global URL in an editable project-level field, so users can review or override it before attaching.

### Company Name removal

Remove Company Name from every vendor screen, including create, edit, global library, performance view, project vendor views, and any vendor-specific filter or display. Preserve the `global_vendors.company_name` column and historical values for compatibility; this is a UI removal, not a destructive data migration.

### Validation and auditability

- Empty postback URLs remain allowed.
- Non-empty postback URLs must be valid `http` or `https` URLs, consistently across create, edit, and attach/assignment forms.
- Preserve CSRF protection and existing role checks.
- Include global postback changes in the vendor audit record; include effective assignment URL changes in assignment audit records where those records already exist.
- Do not log full URLs if query parameters can contain sensitive values; follow the project’s existing audit/logging conventions.

## Data flow

1. Admin enters vendor details and optionally a global postback URL.
2. The create handler validates and persists the global vendor record.
3. If a project is selected, the project URL is taken from the explicit project field, or from the new global URL when the project field is blank.
4. Attaching an existing vendor follows the same defaulting rule using the stored global URL.
5. Later global edits affect future assignments only; existing assignments continue using their stored project URL.
6. Tracking sends the stored project assignment URL exactly as it does today.

## Migration strategy

Add an idempotent schema migration that creates `global_vendors.global_postback_url` if it does not exist. Do not backfill from `project_vendor.postback_url`, because a reusable vendor can have different URLs across projects and there is no safe single value to choose.

## Testing strategy

Cover database migration shape, create behavior, edit behavior, attach defaulting, project override precedence, invalid URL rejection, preservation of existing assignments, and absence of Company Name in vendor UI output. Run PHP syntax checks and the repository’s existing test/smoke commands where available.

## Acceptance criteria

- A standalone vendor can be created with a global postback URL.
- A project-created vendor stores the global URL and initializes the project assignment URL from it.
- A project-specific URL overrides the global default for that assignment.
- Attaching an existing vendor defaults to its global URL and permits an override.
- The global URL is visible and editable on the global vendor edit screen.
- Existing project assignment URLs do not change unexpectedly.
- Company Name is no longer shown or requested on any vendor screen.
- Runtime vendor postbacks still use the project assignment URL.
