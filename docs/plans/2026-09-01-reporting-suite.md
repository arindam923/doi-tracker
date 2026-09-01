# Reporting Suite Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement consistent period-based, grouped reporting and metrics across all reporting pages.

**Architecture:** Create a shared `helpers/reporting.php` module for validated filters, date buckets, safe grouping SQL, aggregate rows, and metric formulas. Refactor overview, export, traffic summary, and scheduled delivery to use the module while preserving current URLs and access control.

**Tech Stack:** PHP, MySQL/PDO, server-rendered HTML, Chart.js, shell-based PHP tests.

---

### Task 1: Add failing shared reporting tests

**Files:**
- Create: `tests/reporting_test.php`

**Step 1: Write the failing test**

Test the expected public helper behavior: supported period modes and grouping values, custom date normalization, Daily/Weekly/Monthly bucket expressions, metric calculations including zero denominators, and rejected-lead predicates.

**Step 2: Run it to verify it fails**

Run: `php tests/reporting_test.php`

Expected: FAIL because `helpers/reporting.php` does not yet exist.

### Task 2: Implement the shared reporting helper

**Files:**
- Create: `helpers/reporting.php`

**Step 1: Implement filter constants and validation**

Expose allowlists for `daily`, `weekly`, `monthly`, `custom` periods and `vendor`, `project`, `client`, `country`, `device` groups. Normalize ISO dates, ensure `to >= from`, and normalize optional project/client/vendor filters.

**Step 2: Implement date bucket and metric helpers**

Provide safe SQL bucket expressions for conversion dates and PHP metric calculations for Revenue, Cost, Profit, ROI, Conversion Rate, EPC, and Rejected Leads.

**Step 3: Implement shared aggregate queries**

Build one grouped query using safe allowlisted SQL fragments. Join projects, clients, vendors, and clicks only as required. Aggregate clicks separately using the same date range and grouping so conversion rate and EPC use correctly matched denominators.

**Step 4: Run the focused tests**

Run: `php tests/reporting_test.php`

Expected: PASS.

### Task 3: Refactor the overview report

**Files:**
- Modify: `reports/overview.php`

**Step 1: Replace local date/group logic**

Load the shared helper, accept period/group/filter parameters, and replace duplicated totals, breakdown, rejected, click, ROI, and EPC queries with shared results.

**Step 2: Add report controls**

Render Daily, Weekly, Monthly, and Custom Date Range controls, grouping options for all five dimensions, and preserve project/client/vendor filters in form submissions and export links.

**Step 3: Expand metrics and breakdown output**

Add KPI cards for Revenue, Cost, Profit, ROI, Conversion Rate, EPC, and Rejected Leads. Render the selected grouping label with rows containing all metrics and update chart labels/data to use the selected period bucket.

### Task 4: Align export and traffic summary

**Files:**
- Modify: `reports/export.php`
- Modify: `reports/traffic_summary.php`

**Step 1: Refactor CSV export**

Accept the same shared filters, generate grouped rows through the helper, and export period, grouping, label, clicks, conversions, rejected leads, revenue, cost, profit, ROI, conversion rate, and EPC.

**Step 2: Refactor traffic summary**

Use shared controls and metric calculations, adding the missing period modes, grouping selection, and requested financial/conversion metrics while preserving traffic-summary context.

### Task 5: Extend scheduled reports

**Files:**
- Modify: `reports/scheduled_reports.php`
- Modify: `cron/reports_scheduler.php`

**Step 1: Extend scheduling form/filter persistence**

Allow all five groupings and store period mode plus date/filter values in `filters_json`, validating them through the shared helper.

**Step 2: Generate scheduled output from shared rows**

Replace the scheduler’s custom aggregate query with the shared report builder and include all requested metrics in the emailed CSV.

### Task 6: Verify all report pages and commit

**Files:**
- Test: `tests/reporting_test.php`
- Verify: `helpers/reporting.php`, `reports/overview.php`, `reports/export.php`, `reports/traffic_summary.php`, `reports/scheduled_reports.php`, `cron/reports_scheduler.php`

**Step 1: Run focused and existing tests**

Run: `php tests/reporting_test.php` and `for test_file in tests/*_test.php; do php "$test_file" || exit 1; done`

Expected: all tests pass.

**Step 2: Run PHP lint**

Run: `php -l helpers/reporting.php && php -l reports/overview.php && php -l reports/export.php && php -l reports/traffic_summary.php && php -l reports/scheduled_reports.php && php -l cron/reports_scheduler.php && php -l tests/reporting_test.php`

Expected: no syntax errors.

**Step 3: Check the diff**

Run: `git diff --check && git diff --stat`

Expected: no whitespace errors and changes limited to the reporting feature, its tests, and plan docs.

**Step 4: Commit**

```bash
git add helpers/reporting.php reports/overview.php reports/export.php reports/traffic_summary.php reports/scheduled_reports.php cron/reports_scheduler.php tests/reporting_test.php docs/plans/2026-09-01-reporting-suite.md
git commit -m "feat: expand reporting suite metrics and grouping"
```
