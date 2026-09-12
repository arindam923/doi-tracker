# Traffic Redirect Status Gating — Design

## Requirement

Traffic may redirect to the client only when all three conditions are true:

1. The project status is `live`.
2. The project-vendor assignment status is `active`.
3. The master vendor status is `approved`, which is the system's equivalent of live.

Any other project status (`hold`, `closed`, or `archived`) or assignment status (`hold` or `closed`) must stop traffic before a click is recorded or the client URL is constructed. Any non-approved master vendor status (`pending`, `suspended`, or `blacklisted`) must also stop traffic.

## Design

Keep the gating in the shared click engine used by opaque short links. Restrict the vendor lookup to `pv.status = 'active'` and explicitly require `gv.vendor_status = 'approved'`. Keep the existing project `status = 'live'` predicate. Update the non-consuming tracking-link validator to report the same effective state instead of always claiming that an existing link is active.

## Error handling

Blocked requests continue returning HTTP 410 and do not insert a click or send a `Location` header. The validator remains non-consuming and reports the project, assignment, and master-vendor statuses for diagnosis.

## Testing

Add source-level regression assertions covering the three production gates and validator status-aware behavior. Run all existing PHP tests and syntax checks for the changed files.
