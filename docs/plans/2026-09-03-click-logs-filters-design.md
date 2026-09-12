# Click Logs filters and interaction fixes — design

## Goal

Give Click Logs complete, reliable filtering for Date Range, Vendor, Project, Country, Device, Browser, Operating System, IP Address, and Click ID. Make the same filters apply to CSV export, make Click IDs copyable, and fix the Device/Project dropdowns so they open consistently.

## Design

Keep filtering server-side in `clicklogs/list.php` and `clicklogs/export.php`, using validated scalar inputs and parameterized SQL. The list and export endpoints will share the same filter names and matching predicates. The existing generic search field will be replaced or narrowed to the requested dedicated IP filter so each control has clear semantics.

The filter controls will use responsive Bootstrap grid sizing: date, project, vendor, and text filters will wrap naturally, while Device and Project selects will not be forced into unusably narrow columns. Existing selected values and reset/export links will remain preserved.

Click IDs will continue to display compactly in the table, but each row will expose the full ID through a copy button using the app’s shared clipboard helper and an accessible label/title. The full ID will not be placed in an unsafe or truncated copy attribute.

The dropdown issue will be traced through the shared dropdown initialization, positioning, and CSS overflow/z-index rules. The fix will preserve keyboard behavior and outside-click closing, while ensuring menus are positioned relative to their trigger and remain visible above responsive table/card containers.

## Verification

Add focused regression checks for all filter inputs and predicates in both list and export endpoints, plus markup checks for the copy control and dropdown structure. Run the repository’s available PHP tests and `php -l` on modified PHP files. Inspect the final diff to ensure unrelated user changes remain untouched.
