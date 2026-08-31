# Vendor Login Portal Design

**Goal:** Give each vendor a secure, read-only view of only its currently assigned campaigns, including its tracking URL/QR code and campaign-level performance, without exposing client, internal network, or other-vendor data.

## Scope

- Use the authenticated `TF_VENDOR.global_vendor_id` plus `project_vendor` as the authorization source of truth.
- Show only active vendor assignments and their campaign metrics: clicks, complete conversions, conversion rate, and configured vendor payout/revenue.
- Provide a copyable vendor-specific short URL and QR-code action for each assigned campaign.
- Add campaign-wise reporting and date filtering within the existing vendor portal.
- Remove internal `client_revenue`, network cost, profit, and other-vendor information from vendor-facing output.
- Reject suspended/blacklisted vendors for password and magic-link authentication and revalidate account status on portal requests.
- Keep vendor profile editing limited to the vendor's own contact details; no campaign settings are writable from the portal.

## Architecture

The existing vendor portal remains the single UI boundary. Shared authorization helpers will resolve and validate the current vendor account, while each campaign/report query will join `project_vendor` on both the authenticated vendor ID and requested project ID. This prevents URL tampering and avoids relying only on denormalized `clicks.vendor_id` or `conversions.vendor_id` values.

The dashboard will load assigned campaign rows with correlated or aggregated metrics bounded by the selected date range. Vendor-specific short links will be joined from `short_links`; QR actions will reuse the existing `tracking/qr.php` endpoint. Invalid campaign filters will return an empty report view rather than disclose whether another campaign exists.

## Security and privacy

- Vendor pages require the vendor session and enabled portal setting.
- Each request verifies that the portal user is active and the master vendor status is approved/pending as appropriate; suspended and blacklisted vendors are rejected.
- All campaign/report data is constrained by `project_vendor.vendor_id = authenticated vendor ID` and active assignment status.
- Vendor-facing fields exclude client identity/details, client revenue, network cost, profit, internal notes, and other-vendor statistics.
- No vendor-facing POST action changes projects or project-vendor configuration.

## Testing

Add regression coverage for assignment isolation, tampered `project_id`, inactive assignments, date-filtered campaign metrics, short-link/QR rendering, sensitive-field exclusion, and login/session status enforcement. Run PHP syntax checks for all portal files and the repository's available test suite.
