-- ============================================================
-- TRACK FLOW — Global Vendor Model Migration
-- ============================================================
-- Transforms an existing install (created from the OLD database.sql
-- that still had the per-project `vendors` table) into the unified
-- global vendor model:
--
--   • `global_vendors`  → single source of truth (vendor identity + master config)
--   • `project_vendor`  → per-project assignment (payout, status, limits, postback)
--   • clicks/conversions/logs/email_campaigns/email_lists/email_campaign_sends
--     now store `global_vendors.id` in their vendor_id columns
--   • legacy `vendors` table is dropped
--
-- Safe to run twice (idempotent). Run in phpMyAdmin → SQL tab.
-- ============================================================

-- ─────────────────────────────────────────────────────────────
-- 1. Ensure global_vendors has every vendor that ever existed in
--    the legacy table (dedupe by vendor_code, prefer latest row).
-- ─────────────────────────────────────────────────────────────
INSERT INTO global_vendors
    (vendor_code, vendor_name, company_name, contact_person, email, telegram, skype,
     traffic_type, vendor_status, default_payout, currency, daily_cap, notes, created_at, updated_at)
SELECT v.vendor_code, v.vendor_name, v.company_name, v.contact_person, v.email, v.telegram, v.skype,
       v.traffic_type, v.vendor_status, v.vendor_cpi, COALESCE(p.currency, 'USD'), v.daily_cap, v.notes,
       v.created_at, NOW()
FROM vendors v
JOIN projects p ON p.id = v.project_id
JOIN (
    SELECT vendor_code, MAX(id) AS mid
    FROM vendors
    WHERE vendor_code IS NOT NULL AND vendor_code <> ''
    GROUP BY vendor_code
) m ON m.vendor_code = v.vendor_code AND m.mid = v.id
ON DUPLICATE KEY UPDATE
    vendor_name = COALESCE(NULLIF(VALUES(vendor_name), ''), global_vendors.vendor_name),
    company_name = COALESCE(NULLIF(VALUES(company_name), ''), global_vendors.company_name),
    contact_person = COALESCE(NULLIF(VALUES(contact_person), ''), global_vendors.contact_person),
    email = COALESCE(NULLIF(VALUES(email), ''), global_vendors.email),
    telegram = COALESCE(NULLIF(VALUES(telegram), ''), global_vendors.telegram),
    skype = COALESCE(NULLIF(VALUES(skype), ''), global_vendors.skype),
    traffic_type = VALUES(traffic_type),
    vendor_status = VALUES(vendor_status),
    default_payout = GREATEST(global_vendors.default_payout, VALUES(default_payout)),
    currency = VALUES(currency),
    daily_cap = GREATEST(global_vendors.daily_cap, VALUES(daily_cap)),
    notes = CONCAT_WS(' | ', NULLIF(global_vendors.notes, ''), NULLIF(VALUES(notes), '')),
    updated_at = NOW();

-- ─────────────────────────────────────────────────────────────
-- 2. Make project_vendor.status accept the per-project lifecycle
--    (active/hold/closed) and add per-project postback_url.
-- ─────────────────────────────────────────────────────────────
ALTER TABLE project_vendor
    MODIFY status ENUM('active','hold','closed') NOT NULL DEFAULT 'active',
    ADD COLUMN postback_url VARCHAR(500) NULL AFTER status;

-- Map existing pivot rows: approved→active, suspended→hold (best-effort).
UPDATE project_vendor SET status = 'active' WHERE status IN ('pending', 'approved');
UPDATE project_vendor SET status = 'hold'   WHERE status = 'suspended';

-- ─────────────────────────────────────────────────────────────
-- 3. Backfill project_vendor from legacy vendors rows.
-- ─────────────────────────────────────────────────────────────
INSERT INTO project_vendor
    (project_id, vendor_id, payout, currency, status, postback_url, allowed_clicks_limit, daily_cap, notes, assigned_at)
SELECT v.project_id, gv.id, v.vendor_cpi, COALESCE(p.currency, 'USD'),
       v.status, v.postback_url, v.allowed_clicks_limit, v.daily_cap, v.notes, v.created_at
FROM vendors v
JOIN global_vendors gv ON gv.vendor_code = v.vendor_code
JOIN projects p ON p.id = v.project_id
ON DUPLICATE KEY UPDATE
    payout = VALUES(payout),
    currency = VALUES(currency),
    status = VALUES(status),
    postback_url = VALUES(postback_url),
    allowed_clicks_limit = VALUES(allowed_clicks_limit),
    daily_cap = VALUES(daily_cap),
    notes = VALUES(notes);

-- ─────────────────────────────────────────────────────────────
-- 4. Remap fact tables: vendor_id → global_vendors.id
--    (Uses direct multi-table UPDATEs — no TEMPORARY TABLE.
--     InfinityFree does not grant CREATE TEMPORARY TABLES.)
-- ─────────────────────────────────────────────────────────────
UPDATE clicks c
JOIN vendors v ON c.vendor_id = v.id
JOIN global_vendors gv ON gv.vendor_code = v.vendor_code
SET c.vendor_id = gv.id;

UPDATE conversions cc
JOIN vendors v ON cc.vendor_id = v.id
JOIN global_vendors gv ON gv.vendor_code = v.vendor_code
SET cc.vendor_id = gv.id;

UPDATE logs l
JOIN vendors v ON l.vendor_id = v.id
JOIN global_vendors gv ON gv.vendor_code = v.vendor_code
SET l.vendor_id = gv.id;

UPDATE email_campaigns ec
JOIN vendors v ON ec.vendor_id = v.id
JOIN global_vendors gv ON gv.vendor_code = v.vendor_code
SET ec.vendor_id = gv.id;

UPDATE email_campaign_sends es
JOIN vendors v ON es.vendor_id = v.id
JOIN global_vendors gv ON gv.vendor_code = v.vendor_code
SET es.vendor_id = gv.id;

UPDATE email_lists el
JOIN vendors v ON el.vendor_id = v.id
JOIN global_vendors gv ON gv.vendor_code = v.vendor_code
SET el.vendor_id = gv.id;

-- ─────────────────────────────────────────────────────────────
-- 5. Drop FKs that point at the legacy vendors table, drop the
--    legacy table, then re-add the FK on clicks → global_vendors.
--    (Uses SET FOREIGN_KEY_CHECKS=0: InfinityFree denies SELECT
--     on information_schema, so we can't introspect the
--     auto-generated constraint names. With checks off, MySQL
--     drops `vendors` and silently removes any FK that referenced
--     it. The new FK re-instates the global constraint on clicks.)
-- ─────────────────────────────────────────────────────────────
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS vendors;
SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE clicks
    ADD CONSTRAINT fk_clicks_global_vendor
    FOREIGN KEY (vendor_id) REFERENCES global_vendors(id);

-- ─────────────────────────────────────────────────────────────
-- Done. The system now runs entirely on the global vendor model.
-- Fresh installs should use the updated database.sql instead.
-- ─────────────────────────────────────────────────────────────
