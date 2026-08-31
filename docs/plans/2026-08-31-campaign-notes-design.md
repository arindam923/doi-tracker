# Campaign Notes Design

## Goal

Add a clearly visible, internal Campaign Notes section to the project detail page for durable campaign guidance:

- Client Instructions
- Optimization Notes
- Publisher Restrictions

The existing dated campaign notes timeline remains available for ad-hoc operational updates.

## Design

Store the three durable note categories as nullable text columns on `projects`. This keeps each category attached to the campaign, makes the values available wherever the project record is loaded, and avoids changing the existing `campaign_notes` history table or endpoints.

The project edit form will expose the three fields. The project detail page will display them in a dedicated internal-only card, with empty values shown as `Not added`. Existing access control (`super_admin` and `campaign_manager`) and CSRF protection will apply through the current project edit flow.

## Data flow

1. A permitted internal user edits a project.
2. The edit handler trims and persists the three text fields in the existing transaction.
3. The project detail query loads the values with the rest of the project.
4. The detail page escapes and renders the values in the Campaign Notes card.
5. Existing timeline notes continue to be created and deleted through their current protected endpoints.

## Migration and compatibility

Add an idempotent migration that adds the three nullable `TEXT` columns to existing `projects` tables. Update `database.sql` so fresh installations include the columns. Existing projects remain valid with empty note fields.

## Error handling and security

Use the existing edit validation, transaction rollback, flash-message behavior, role restrictions, and CSRF token validation. Render note content through the existing escaping helper to prevent stored HTML/script injection. Audit the project update through the existing project update audit event.

## Verification

- Add focused tests for persistence and safe rendering where the project test harness permits.
- Run PHP syntax checks for modified PHP files.
- Run the available automated test suite and inspect the migration/schema diff.
