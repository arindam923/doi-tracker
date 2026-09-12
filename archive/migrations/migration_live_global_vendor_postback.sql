-- TRACK FLOW — Live global vendor postback inheritance
--
-- project_vendor.postback_url now stores only an explicit project override.
-- Normalize legacy rows where the old implementation copied the current
-- global URL into the assignment. Differing URLs are preserved as overrides.

UPDATE project_vendor pv
JOIN global_vendors gv ON gv.id = pv.vendor_id
SET pv.postback_url = NULL
WHERE pv.postback_url IS NOT NULL
  AND gv.global_postback_url IS NOT NULL
  AND pv.postback_url = gv.global_postback_url;
