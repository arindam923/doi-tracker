# Offer Details Link Hub Design

**Date:** 2026-09-03

## Goal

Make the project detail page a quick, uncluttered place to access the campaign’s important links: Landing Page, Client Link, Preview Link, Tracking Link, Test Link, and Global Postback URL.

## Approved design

Use a compact link hub inside the existing offer-details area. The primary destinations appear as a three-item responsive grid with clear icons, short labels, truncated URL previews, copy actions, and open actions where safe. Tracking and callback links are grouped behind a native expandable section so the page remains calm at a glance.

Tracking Link, Test Link, and Global Postback URL are vendor-specific. A vendor selector switches the values in place instead of rendering one full group per vendor. The existing project client postback and token remain available as secondary utility items, preserving current functionality while reducing visual competition.

## Data flow and empty states

Link values are assembled server-side from the existing project and attached-vendor records. The selected vendor’s tracking URL uses its opaque short code, the test URL points to the existing non-consuming validator, and the global postback displays the effective reusable vendor URL. Missing optional links show a clear unavailable state and never render broken anchors.

## Accessibility and responsive behavior

Use semantic labels, native details/select controls, keyboard-accessible buttons, visible focus styles, and readable contrast. Long URLs are visually truncated without changing the copied value. The grid collapses to one column on small screens.

## Testing

Add a focused source-level regression test for all six link labels/builders and the compact UI markers. Run it with PHP lint plus the existing opaque tracking and global vendor postback tests.
