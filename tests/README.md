# Track Flow tests

Tests are plain executable PHP scripts so they can run on the same shared-hosting PHP stack as the application.

Create a disposable database whose name ends in `_test` for integration tests, then run:

```sh
php tests/project_lifecycle_test.php
php tests/tracking_links_test.php
php tests/vendor_assignment_test.php
php tests/postback_test.php
php tests/schema_migration_test.php
TRACK_FLOW_TEST_DB=track_flow_test php tests/schema_migration_test.php
```

`tests/bootstrap.php` exits before opening a database connection unless the database name ends with `_test`. Do not point tests at production.

Before a staging deployment, run the release checklist in `docs/client-review-acceptance.md` and attach the resulting database queries, screenshots, and HTTP responses.
