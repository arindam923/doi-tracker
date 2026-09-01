# Reporting Suite Design

## Goal

Provide consistent Daily, Weekly, Monthly, and Custom Date Range reporting grouped by Vendor, Project, Client, Country, or Device across the overview, traffic summary, export, and scheduled-report pages.

## Architecture

Add a shared reporting helper that validates report filters, resolves date ranges and time buckets, builds safe grouping SQL, and calculates all report metrics from the same approved-completed and rejected-lead rules. The overview page will consume the helper for KPI cards, chart data, and breakdown tables; export and scheduled delivery will use the same row builder; traffic summary will adopt the shared filters and formulas.

The existing routes and navigation remain intact. Existing project filters continue to work, while the new report controls are propagated through links and CSV exports. Country and device are sourced from clicks with explicit Unknown fallbacks; client is resolved through projects.

## Metric definitions

- Revenue: approved completed `client_revenue`
- Cost: approved completed `vendor_cost`
- Profit: revenue minus cost
- ROI: profit divided by cost, expressed as a percentage
- Conversion Rate: approved completed conversions divided by clicks, expressed as a percentage
- EPC: revenue divided by clicks
- Rejected Leads: conversions with `status = rejected` or `approval_status = rejected`

## Verification

Add deterministic PHP tests for date buckets, filter validation, grouping definitions, formulas, rejected-lead handling, and report-page integration. Run focused tests, all existing PHP tests, PHP lint, and whitespace/diff checks.
