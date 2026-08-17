-- ============================================================
-- TRACK FLOW — Vendor email migration (resume 7)
-- Skip: last_name already exists.
-- ============================================================

UPDATE email_list_entries ele
JOIN email_lists el ON el.id = ele.list_id
SET ele.vendor_id = el.vendor_id
WHERE ele.vendor_id IS NULL AND el.vendor_id IS NOT NULL;

ALTER TABLE `email_list_entries`
  ADD INDEX `idx_vendor` (`vendor_id`);

ALTER TABLE `email_list_entries`
  ADD INDEX `idx_list_status_country` (`list_id`, `status`, `country`);

UPDATE email_campaign_sends SET list_id = NULL WHERE list_id = 0;
ALTER TABLE `email_campaign_sends` MODIFY `list_id` INT NULL;
