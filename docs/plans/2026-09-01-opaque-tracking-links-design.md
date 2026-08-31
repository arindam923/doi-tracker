# Opaque Tracking Links Design

## Goal

Ensure public tracking URLs never expose project or vendor database IDs. Public links will use vendor-specific opaque codes such as `/c/7Hd82K`, while project and vendor IDs remain server-side only.

## Architecture

Each project/vendor assignment receives a random, URL-safe code stored in `short_links`. The `/c/{code}` and `/go/{code}` rewrite routes resolve that row server-side and invoke the click-tracking engine in the same request. There will be no browser-visible redirect to `click.php` and no public ID-based click URL.

The old project-level `projects.short_code` fallback will no longer resolve tracking traffic. Existing schema columns can remain for now because they are also used for internal project display, but they will not be used to generate or resolve public tracking links.

## Data flow

1. Admin attaches a vendor to a project.
2. The app generates a cryptographically random code and inserts it into `short_links`.
3. Admin/vendor screens display `BASE_URL/c/{code}`.
4. A request to `/c/{code}` is rewritten to `tracking/redirect.php`.
5. `redirect.php` resolves the code, preserves tracking subparameters, and invokes click tracking internally.
6. Click tracking records the internal project/vendor IDs and redirects only to the configured client survey URL with the generated click ID.

## Error handling and security

Codes must be URL-safe, bounded in length, unique, and generated with `random_bytes`. Unknown or malformed codes return 400/404. Direct requests to `tracking/click.php` without an internal resolution context are rejected, preventing ID-based bypasses.

## Testing

Add focused PHP tests for random code generation, public link construction, opaque-code resolution, and the absence of ID-bearing redirects. Existing link validation should only query `short_links`.
