-- ============================================================
-- Click forensic columns (referrer, UA, device, language, ISP)
-- Skip any statement that errors with Duplicate column name.
-- ============================================================

ALTER TABLE `clicks` ADD COLUMN `user_agent` TEXT NULL AFTER `ip_address`;
ALTER TABLE `clicks` ADD COLUMN `referrer` VARCHAR(500) NULL AFTER `user_agent`;
ALTER TABLE `clicks` ADD COLUMN `device_type` VARCHAR(50) NULL AFTER `country_code`;
ALTER TABLE `clicks` ADD COLUMN `browser` VARCHAR(50) NULL AFTER `device_type`;
ALTER TABLE `clicks` ADD COLUMN `os` VARCHAR(50) NULL AFTER `browser`;
ALTER TABLE `clicks` ADD COLUMN `browser_lang` VARCHAR(20) NULL AFTER `os`;
ALTER TABLE `clicks` ADD COLUMN `isp` VARCHAR(150) NULL AFTER `browser_lang`;

ALTER TABLE `clicks` ADD INDEX `idx_browser` (`browser`);
ALTER TABLE `clicks` ADD INDEX `idx_os` (`os`);
