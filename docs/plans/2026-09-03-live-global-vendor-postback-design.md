# Live Global Vendor Postback Inheritance Design

**Date:** 2026-09-03

## Goal

Make a vendor's global postback URL the live default for every project assignment that does not have an explicit project override.

## Approved behavior

- `global_vendors.global_postback_url` owns the reusable vendor URL.
- `project_vendor.postback_url` stores only an explicit project-specific override; blank means inherit the global URL.
- New and existing vendor attachments leave the assignment override blank when the global URL should be used.
- Existing project assignments created by the previous implementation may contain a copied global URL. A migration normalizes only values that exactly match the vendor's current global URL to blank; differing values remain explicit overrides.
- The outbound postback runtime resolves `project_vendor.postback_url` first, then `global_vendors.global_postback_url`.
- Editing a global URL immediately affects all assignments with blank overrides.
- Clearing an assignment override restores the current global URL.

## UI and validation

- New vendor and attach forms show the global URL as the editable default/reference.
- Assignment edit shows the effective global URL and permits a project override.
- All nonblank URLs must be absolute HTTP(S) URLs.

## Testing

Add unit-level checks for override precedence, blank inheritance, and changed-global behavior; run PHP lint and the existing postback test.
