# Offer Details Link Hub Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a modern, compact link hub to the project detail page so campaign links are available together without six large, repetitive fields.

**Architecture:** Keep link generation server-side in `projects/detail.php`, using the existing project destination, preview URL, client postback, vendor short-link, test endpoint, and vendor global postback data. Replace the current offer-links presentation with a responsive featured-link grid plus a collapsible tracking/callback section; keep vendor-specific links selectable instead of rendering a long list. Add only scoped CSS in `assets/css/app.css`, preserving unrelated worktree changes.

**Tech Stack:** PHP templates, Bootstrap utility classes, existing Track Flow helper functions, CSS custom properties, vanilla JavaScript already loaded by the layout.

---

### Task 1: Prepare the link-hub data contract

**Files:**
- Modify: `projects/detail.php` near the project link variables and attached-vendor query

**Step 1: Write the failing test**

Add a lightweight source-level regression check in the existing PHP test style that asserts the detail page source contains all six labels and the tracking/test URL builders. This protects the requested surface without requiring a live database fixture.

**Step 2: Run test to verify it fails**

Run: `php tests/offer_details_links_test.php`

Expected: FAIL because the new test file and link-hub markers do not yet exist.

**Step 3: Write minimal implementation**

Prepare escaped/raw values for the stored landing/client destination, preview URL, client postback, attached vendor options, each vendor tracking URL, each vendor test URL (`tracking/test.php?c=...`), and the effective vendor global postback URL. Keep missing optional values represented as unavailable rather than emitting broken links.

**Step 4: Run test to verify it passes**

Run: `php tests/offer_details_links_test.php`

Expected: PASS for all six link types and both vendor URL builders.

**Step 5: Commit**

```bash
git add tests/offer_details_links_test.php projects/detail.php
git commit -m "feat: prepare offer details link hub data"
```

### Task 2: Build the compact Offer Details link hub

**Files:**
- Modify: `projects/detail.php` in the existing `Offer Links & Tokens` card

**Step 1: Write the failing test**

Extend the source-level test to require the hub container, featured link cards, collapsible tracking/callback area, vendor selector, copy controls, and accessible labels.

**Step 2: Run test to verify it fails**

Run: `php tests/offer_details_links_test.php`

Expected: FAIL on the missing compact UI markers.

**Step 3: Write minimal implementation**

Create an “Offer Details” card with:

- a small header/subtitle explaining that campaign links live here;
- a three-item featured grid for Landing Page, Client Link, and Preview Link, using concise values and copy/open actions;
- a native `<details>` section titled “Tracking & callbacks” containing a vendor selector and the selected vendor’s Tracking Link, Test Link, and Global Postback URL;
- a small client postback/token utility row so existing functionality is retained without competing with the primary links;
- graceful empty states for missing preview, vendor short-link, or global postback data;
- semantic labels, visible focus states, and no raw long URL overflow on narrow viewports.

Use the existing `tf_copy_button()` and `tracking_public_url()` helpers. Add a small inline script only if needed to switch vendor-specific values without a page reload; keep the raw link values in `data-*` attributes encoded safely with JSON/HTML escaping.

**Step 4: Run test to verify it passes**

Run: `php tests/offer_details_links_test.php`

Expected: PASS with all requested UI and link markers present.

**Step 5: Commit**

```bash
git add projects/detail.php tests/offer_details_links_test.php
git commit -m "feat: add compact offer details link hub"
```

### Task 3: Add responsive visual polish

**Files:**
- Modify: `assets/css/app.css` near project-detail/link-field styles

**Step 1: Write the failing test**

Add source assertions for the scoped link-hub classes and responsive breakpoint rules.

**Step 2: Run test to verify it fails**

Run: `php tests/offer_details_links_test.php`

Expected: FAIL because the scoped visual classes are not yet defined.

**Step 3: Write minimal implementation**

Add scoped styles for a subtle tinted header, link tiles with icon badges, compact action buttons, truncated monospace previews, open/closed details states, and a one-column mobile layout. Reuse existing design tokens and avoid changing global button/card behavior.

**Step 4: Run test to verify it passes**

Run: `php tests/offer_details_links_test.php && php -l projects/detail.php`

Expected: PASS and no PHP syntax errors.

**Step 5: Commit**

```bash
git add assets/css/app.css tests/offer_details_links_test.php
git commit -m "style: polish offer details link hub"
```

### Task 4: Verify the complete change

**Files:**
- Test: `tests/offer_details_links_test.php`
- Test: `projects/detail.php`
- Test: `assets/css/app.css`

**Step 1: Run the focused checks**

Run: `php tests/offer_details_links_test.php && php -l projects/detail.php && php -l helpers/functions.php`

Expected: all assertions pass and both PHP files report no syntax errors.

**Step 2: Run the existing relevant regression tests**

Run: `php tests/opaque_tracking_links_test.php && php tests/global_vendor_postback_test.php`

Expected: all existing tracking/postback assertions pass.

**Step 3: Inspect the final diff**

Run: `git diff -- projects/detail.php assets/css/app.css tests/offer_details_links_test.php`

Expected: only the compact link-hub UI, its scoped styles, and the focused regression test are included; unrelated pre-existing worktree changes remain untouched.
