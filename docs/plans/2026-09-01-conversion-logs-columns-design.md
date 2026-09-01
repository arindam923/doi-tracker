# Conversion Logs Columns Design

## Goal

Make the Conversion Logs table explicitly display Click Time, Conversion Time, Time Difference, Revenue, Payout, Profit, Status, Transaction ID, and the full Click ID.

## Design

The table will use one column per requested field, while retaining Campaign as useful existing context. Click Time will prefer the conversion row's stored `click_time` and fall back to the related click's `clicked_at`; Conversion Time will use `converted_at`; and Time Difference will use `time_diff_seconds`. IDs will be rendered without display truncation and remain HTML-escaped.

Status will show the conversion status (`complete`/`rejected`) and retain approval/manual/automatic indicators in the same cell so existing moderation actions remain available. CSV export will use the exact `Conversion Time` and `Time Difference` labels while continuing to export full IDs.

## Verification

Add a source-level regression test consistent with the repository's shell-based PHP tests. It will assert the required table headers, fallback click-time expression, full-ID rendering, and corrected CSV labels. Run that test, PHP lint on changed PHP files, and the existing test suite.
