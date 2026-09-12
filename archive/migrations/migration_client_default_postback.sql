-- TRACK FLOW — Client default postback token (reusable across campaigns)
-- One token per client, inherited at runtime via click_id -> project -> client.
-- Projects keep their own token for backward compat; postback.php accepts either.

ALTER TABLE `clients` ADD COLUMN IF NOT EXISTS `postback_token` VARCHAR(100) NULL AFTER `client_code`;
CREATE INDEX IF NOT EXISTS idx_client_postback_token ON `clients` (`postback_token`);

-- Backfill: generate a token for existing clients that lack one.
-- MySQL 8: use random hex; fallback to UUID if random_bytes not available.
UPDATE `clients` SET `postback_token` = LOWER(HEX(RANDOM_BYTES(32))) WHERE `postback_token` IS NULL OR `postback_token` = '';
-- For MariaDB / older MySQL without RANDOM_BYTES, uncomment:
-- UPDATE `clients` SET `postback_token` = REPLACE(LOWER(UUID()), '-', '') WHERE `postback_token` IS NULL OR `postback_token` = '';
