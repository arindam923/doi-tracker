-- ============================================================
-- TRACK FLOW — Complete Database Schema (v2.0)
-- Fresh-install schema. Single file, run once in phpMyAdmin → SQL tab.
--
-- Includes:
--   • Base tables (users, clients, projects, global_vendors, clicks, conversions, logs, etc.)
--   • All v2.0 enhancements (extended enums, new columns, new tables)
--   • Global vendor model: global_vendors master + project_vendor pivot (no legacy vendors table)
--   • Default admin user with a real bcrypt password hash
--
-- Default admin login:
--   Username: admin
--   Password: Admin@123
--   ⚠️  Change this password immediately after first login.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `email_list_entries`;
DROP TABLE IF EXISTS `email_lists`;
DROP TABLE IF EXISTS `scheduled_reports`;
DROP TABLE IF EXISTS `client_documents`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `vendor_portal_users`;
DROP TABLE IF EXISTS `short_links`;
DROP TABLE IF EXISTS `campaign_geo`;
DROP TABLE IF EXISTS `campaign_notes`;
DROP TABLE IF EXISTS `project_vendor`;
DROP TABLE IF EXISTS `global_vendors`;
DROP TABLE IF EXISTS `clicks`;
DROP TABLE IF EXISTS `conversions`;
DROP TABLE IF EXISTS `logs`;
DROP TABLE IF EXISTS `projects`;
DROP TABLE IF EXISTS `clients`;
DROP TABLE IF EXISTS `rate_limits`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `sent_emails`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- USERS
-- ============================================================
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `email` VARCHAR(150),
  `role` ENUM('super_admin','campaign_manager','viewer') DEFAULT 'campaign_manager',
  `is_active` TINYINT DEFAULT 1,
  `last_login` DATETIME,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default admin user
-- Username: admin
-- Password: Admin@123
-- IMPORTANT: Change this password immediately after first login!
-- To generate a new hash: php -r "echo password_hash('YourPassword', PASSWORD_DEFAULT);"
INSERT INTO `users` (`username`, `password`, `email`, `role`) VALUES
('admin', '$2y$12$80oy/IXijDqg4EYZplVAWu.E1P2.PT0NcIpt7FWO3ju63TdLRt9Ce', 'admin@ternfluenzy.com', 'super_admin');

-- ============================================================
-- CLIENTS
-- ============================================================
CREATE TABLE `clients` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `client_name` VARCHAR(200) NOT NULL,
  `client_code` VARCHAR(20) NOT NULL UNIQUE,
  `contact_person` VARCHAR(150),
  `email` VARCHAR(150),
  `phone` VARCHAR(50),
  `skype` VARCHAR(100),
  `telegram` VARCHAR(100),
  `country` VARCHAR(100),
  `default_currency` VARCHAR(10) DEFAULT 'USD',
  `payment_terms` VARCHAR(100),
  `billing_address` TEXT,
  `notes` TEXT,
  `is_active` TINYINT DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PROJECTS
-- ============================================================
CREATE TABLE `projects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_code` VARCHAR(30) NOT NULL UNIQUE,
  `short_code` VARCHAR(12) UNIQUE,
  `project_name` VARCHAR(300) NOT NULL,
  `client_id` INT NOT NULL,
  `client_survey_link` VARCHAR(500),
  `preview_link` VARCHAR(500) NULL,
  `postback_token` VARCHAR(100) NOT NULL,
  `client_cpi` DECIMAL(10,2) DEFAULT 0.00,
  `currency` VARCHAR(10) DEFAULT 'USD',
  `vendor_default_cpi` DECIMAL(10,2) DEFAULT 0.00,
  `campaign_type` ENUM('CPL','CPC','CPA','CPS','CPI','CPM','RevShare','Hybrid') DEFAULT 'CPL',
  `vertical` ENUM('Survey','E-commerce','Finance','Insurance','Home Improvement','Home Services','Real Estate','Education','Employment','Healthcare','Legal','Travel','Automotive','Gaming','Gambling','Dating','Sweepstakes','Mobile Apps','Software','Utilities','Subscription','Telecom','Food & Beverage','Beauty & Fashion','Electronics','Business Services','Lead Generation','Nonprofit','Entertainment','Other') DEFAULT 'Other',
  `conversion_type` ENUM('SOI','DOI','Lead','Sale','Install','Call','Trial','Subscription') DEFAULT 'SOI',
  `target_device` ENUM('All','Desktop','Mobile','Tablet') DEFAULT 'All',
  `total_quota` INT DEFAULT 0,
  `daily_cap` INT DEFAULT 0,
  `completes_count` INT DEFAULT 0,
  `clicks_count` INT DEFAULT 0,
  `status` ENUM('live','hold','closed','archived') DEFAULT 'live',
  `visibility` ENUM('private','public','invite_only') DEFAULT 'private',
  `campaign_status` ENUM('draft','pending_approval','testing','live','paused','completed','archived') DEFAULT 'live',
  `country_target` VARCHAR(100),
  `start_date` DATE,
  `end_date` DATE,
  `description` TEXT,
  `created_by` INT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_client (`client_id`),
  INDEX idx_status (`status`),
  INDEX idx_campaign_status (`campaign_status`),
  FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- GLOBAL VENDORS (source of truth — master vendor library)
-- ============================================================
CREATE TABLE `global_vendors` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `vendor_code` VARCHAR(20) NOT NULL UNIQUE,
  `vendor_name` VARCHAR(200) NOT NULL,
  `company_name` VARCHAR(200) NULL,
  `contact_person` VARCHAR(150) NULL,
  `email` VARCHAR(150) NULL,
  `telegram` VARCHAR(100) NULL,
  `skype` VARCHAR(100) NULL,
  `phone` VARCHAR(50) NULL,
  `traffic_type` ENUM('Email','Facebook','Google','Native','Push','Incent','Search','Display','Influencer','API','Other') DEFAULT 'Other',
  `vendor_status` ENUM('pending','approved','suspended','blacklisted') DEFAULT 'approved',
  `default_payout` DECIMAL(10,2) DEFAULT 0.00,
  `currency` VARCHAR(10) DEFAULT 'USD',
  `daily_cap` INT DEFAULT 0,
  `notes` TEXT NULL,
  `created_by` INT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (`vendor_status`),
  INDEX idx_traffic (`traffic_type`),
  INDEX idx_email (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PROJECT ↔ VENDOR PIVOT (per-project assignment config)
-- ============================================================
CREATE TABLE `project_vendor` (
  `project_id` INT NOT NULL,
  `vendor_id` INT NOT NULL,
  `payout` DECIMAL(10,2) DEFAULT 0.00,
  `currency` VARCHAR(10) DEFAULT 'USD',
  `status` ENUM('active','hold','closed') DEFAULT 'active',
  `postback_url` VARCHAR(500),
  `allowed_clicks_limit` INT DEFAULT 0,
  `daily_cap` INT DEFAULT 0,
  `assigned_by` INT NULL,
  `assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `notes` TEXT NULL,
  PRIMARY KEY (`project_id`, `vendor_id`),
  INDEX idx_vendor (`vendor_id`),
  INDEX idx_status (`status`),
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`vendor_id`) REFERENCES `global_vendors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- CLICKS
-- ============================================================
CREATE TABLE `clicks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `click_id` VARCHAR(64) NOT NULL UNIQUE,
  `project_id` INT NOT NULL,
  `vendor_id` INT NOT NULL,
  `ip_address` VARCHAR(45),
  `user_agent` TEXT,
  `referrer` VARCHAR(500),
  `country_detected` VARCHAR(100),
  `country_code` CHAR(2),
  `device_type` VARCHAR(50),
  `browser` VARCHAR(50),
  `os` VARCHAR(50),
  `browser_lang` VARCHAR(20),
  `isp` VARCHAR(150),
  `clicked_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `is_converted` TINYINT DEFAULT 0,
  `is_duplicate_ip` TINYINT DEFAULT 0,
  `sub1` VARCHAR(200),
  `sub2` VARCHAR(200),
  `sub3` VARCHAR(200),
  `sub4` VARCHAR(200),
  `sub5` VARCHAR(200),
  INDEX idx_click_id (`click_id`),
  INDEX idx_project (`project_id`),
  INDEX idx_vendor (`vendor_id`),
  INDEX idx_clicked_at (`clicked_at`),
  INDEX idx_country_code (`country_code`),
  INDEX idx_browser (`browser`),
  INDEX idx_os (`os`),
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`),
  FOREIGN KEY (`vendor_id`) REFERENCES `global_vendors`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- CONVERSIONS
-- ============================================================
CREATE TABLE `conversions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `click_id` VARCHAR(64) NOT NULL,
  `project_id` INT NOT NULL,
  `vendor_id` INT NOT NULL,
  `status` VARCHAR(20) DEFAULT 'complete',
  `client_revenue` DECIMAL(10,2) DEFAULT 0.00,
  `sale_amount` DECIMAL(10,2) DEFAULT 0.00,
  `currency` VARCHAR(10) DEFAULT 'USD',
  `transaction_id` VARCHAR(100),
  `click_time` DATETIME,
  `time_diff_seconds` INT,
  `vendor_cost` DECIMAL(10,2) DEFAULT 0.00,
  `profit` DECIMAL(10,2) DEFAULT 0.00,
  `rejection_reason` VARCHAR(200),
  `is_manual` TINYINT DEFAULT 0,
  `approval_status` ENUM('pending','approved','rejected') DEFAULT 'approved',
  `converted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_click_id (`click_id`),
  INDEX idx_project (`project_id`),
  INDEX idx_converted_at (`converted_at`),
  INDEX idx_approval_status (`approval_status`),
  INDEX idx_transaction_id (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- LOGS
-- ============================================================
CREATE TABLE `logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `log_type` VARCHAR(50),
  `project_id` INT,
  `vendor_id` INT,
  `click_id` VARCHAR(64),
  `status` VARCHAR(20),
  `message` TEXT,
  `payload` TEXT,
  `ip_address` VARCHAR(45),
  `response_sent` VARCHAR(200),
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_project (`project_id`),
  INDEX idx_created_at (`created_at`),
  INDEX idx_log_type (`log_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- RATE LIMITS
-- ============================================================
CREATE TABLE `rate_limits` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(45) NOT NULL,
  `action` VARCHAR(50) NOT NULL DEFAULT 'login',
  `attempts` INT DEFAULT 1,
  `locked_until` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `ip_action` (`ip_address`, `action`),
  INDEX idx_locked_until (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SETTINGS
-- ============================================================
CREATE TABLE `settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('site_name', 'Track Flow'),
('default_currency', 'USD'),
('quota_buffer_percent', '0'),
('session_timeout_hours', '8'),
('smtp_enabled', '0'),
('resend_api_key', ''),
('email_from_address', 'noreply@yourdomain.com'),
('email_from_name', 'Track Flow'),
('global_postback_enabled', '0'),
('ip_enrichment_enabled', '1'),
('vendor_login_enabled', '0'),
('global_postback_url', ''),
('strict_target_device', '0'),
('email_bucket_tokens', '50'),
('email_bucket_last_ts', '0'),
('email_rate_per_minute', '50');

-- ============================================================
-- SENT EMAILS
-- ============================================================
CREATE TABLE `sent_emails` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `recipient_email` VARCHAR(150) NOT NULL,
  `recipient_name` VARCHAR(200),
  `client_id` INT,
  `subject` VARCHAR(300) NOT NULL,
  `body` TEXT NOT NULL,
  `status` ENUM('sent','failed','queued','retrying') DEFAULT 'queued',
  `resend_id` VARCHAR(100),
  `error_message` TEXT,
  `retry_count` INT DEFAULT 0,
  `scheduled_for` DATETIME NULL,
  `batch_id` VARCHAR(50) NULL,
  `sent_by` INT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_client (`client_id`),
  INDEX idx_created_at (`created_at`),
  INDEX idx_status_sched (`status`, `scheduled_for`),
  INDEX idx_batch (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SHORT LINKS
-- ============================================================
CREATE TABLE `short_links` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(12) NOT NULL UNIQUE,
  `project_id` INT NOT NULL,
  `vendor_id` INT NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_project (`project_id`),
  INDEX idx_vendor (`vendor_id`),
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`vendor_id`) REFERENCES `global_vendors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- CAMPAIGN GEO
-- ============================================================
CREATE TABLE `campaign_geo` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT NOT NULL,
  `country_code` CHAR(2) NOT NULL,
  `country_name` VARCHAR(100),
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_project_country (`project_id`, `country_code`),
  INDEX idx_country_code (`country_code`),
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- CAMPAIGN NOTES
-- ============================================================
CREATE TABLE `campaign_notes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT NOT NULL,
  `body` TEXT NOT NULL,
  `created_by` INT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_project (`project_id`),
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- AUDIT LOGS
-- ============================================================
CREATE TABLE `audit_logs` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `actor_id` INT NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_id` VARCHAR(100) NOT NULL,
  `before_json` JSON NULL,
  `after_json` JSON NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(500) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_actor (`actor_id`),
  INDEX idx_entity (`entity_type`, `entity_id`),
  INDEX idx_created_at (`created_at`),
  INDEX idx_action (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- EMAIL LISTS
-- ============================================================
CREATE TABLE `email_lists` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(200) NOT NULL,
  `source` VARCHAR(100) DEFAULT 'manual',
  `client_id` INT NULL,
  `project_id` INT NULL,
  `vendor_id` INT NULL,
  `is_deduped` TINYINT(1) DEFAULT 0,
  `record_count` INT DEFAULT 0,
  `description` TEXT NULL,
  `created_by` INT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_client (`client_id`),
  INDEX idx_project (`project_id`),
  INDEX idx_vendor (`vendor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `email_list_entries` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `list_id` INT NOT NULL,
  `email` VARCHAR(254) NOT NULL,
  `name` VARCHAR(200) NULL,
  `metadata_json` JSON NULL,
  `country` CHAR(2) NULL,
  `source` VARCHAR(100) NULL,
  `status` ENUM('active','unsubscribed','bounced','invalid') DEFAULT 'active',
  `is_unsubscribed` TINYINT(1) DEFAULT 0,
  `dedupe_hash` CHAR(40) NOT NULL,
  `added_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `last_emailed_at` DATETIME NULL,
  `total_emails_sent` INT DEFAULT 0,
  UNIQUE KEY uk_list_dedupe (`list_id`, `dedupe_hash`),
  INDEX idx_list (`list_id`),
  INDEX idx_email (`email`),
  INDEX idx_unsub (`is_unsubscribed`),
  INDEX idx_country (`country`),
  FOREIGN KEY (`list_id`) REFERENCES `email_lists`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- EMAIL TEMPLATES
-- ============================================================
CREATE TABLE `email_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(200) NOT NULL,
  `subject` VARCHAR(300) NOT NULL,
  `html_body` TEXT NOT NULL,
  `is_default` TINYINT(1) DEFAULT 0,
  `created_by` INT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- EMAIL CAMPAIGNS
-- ============================================================
CREATE TABLE `email_campaigns` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT NOT NULL,
  `vendor_id` INT NOT NULL,
  `template_id` INT NULL,
  `name` VARCHAR(200) NOT NULL,
  `subject` VARCHAR(300) NOT NULL,
  `html_body` TEXT NOT NULL,
  `from_name` VARCHAR(150) NULL,
  `from_email` VARCHAR(150) NULL,
  `daily_limit` INT DEFAULT 1000,
  `total_limit` INT DEFAULT 0,
  `sent_count` INT DEFAULT 0,
  `delivered_count` INT DEFAULT 0,
  `opened_count` INT DEFAULT 0,
  `clicked_count` INT DEFAULT 0,
  `converted_count` INT DEFAULT 0,
  `bounced_count` INT DEFAULT 0,
  `unsubscribed_count` INT DEFAULT 0,
  `failed_count` INT DEFAULT 0,
  `status` ENUM('draft','scheduled','running','paused','completed','failed') DEFAULT 'draft',
  `is_multi_step` TINYINT(1) DEFAULT 0,
  `started_at` DATETIME NULL,
  `completed_at` DATETIME NULL,
  `created_by` INT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_project (`project_id`),
  INDEX idx_vendor (`vendor_id`),
  INDEX idx_status (`status`),
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`vendor_id`) REFERENCES `global_vendors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- EMAIL CAMPAIGN SENDS
-- ============================================================
CREATE TABLE `email_campaign_sends` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `campaign_id` INT NOT NULL,
  `project_id` INT NOT NULL,
  `vendor_id` INT NOT NULL,
  `list_id` INT NOT NULL,
  `entry_id` BIGINT NOT NULL,
  `recipient_email` VARCHAR(254) NOT NULL,
  `recipient_name` VARCHAR(200) NULL,
  `country` CHAR(2) NULL,
  `status` ENUM('queued','sent','delivered','opened','clicked','converted','bounced','failed','skipped') DEFAULT 'queued',
  `resend_id` VARCHAR(100) NULL,
  `sent_at` DATETIME NULL,
  `opened_at` DATETIME NULL,
  `clicked_at` DATETIME NULL,
  `converted_at` DATETIME NULL,
  `error_message` TEXT NULL,
  `retry_count` INT DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_campaign_recipient (`campaign_id`, `recipient_email`),
  INDEX idx_campaign (`campaign_id`),
  INDEX idx_list (`list_id`),
  INDEX idx_status (`status`),
  FOREIGN KEY (`campaign_id`) REFERENCES `email_campaigns`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`list_id`) REFERENCES `email_lists`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- CLIENT DOCUMENTS
-- ============================================================
CREATE TABLE `client_documents` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `client_id` INT NOT NULL,
  `project_id` INT NULL,
  `document_type` VARCHAR(50) DEFAULT 'Other',
  `original_filename` VARCHAR(255) NOT NULL,
  `stored_filename` VARCHAR(255) NOT NULL,
  `file_size` BIGINT DEFAULT 0,
  `mime_type` VARCHAR(100) NULL,
  `uploaded_by` INT NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_client (`client_id`),
  INDEX idx_project (`project_id`),
  INDEX idx_doc_type (`document_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SCHEDULED REPORTS
-- ============================================================
CREATE TABLE `scheduled_reports` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `owner_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `report_type` VARCHAR(50) DEFAULT 'overview',
  `group_by` VARCHAR(50) DEFAULT 'project',
  `filters_json` JSON NULL,
  `frequency` ENUM('daily','weekly','monthly','custom_cron') DEFAULT 'daily',
  `custom_cron` VARCHAR(50) NULL,
  `recipients_csv` TEXT NULL,
  `last_run_at` DATETIME NULL,
  `next_run_at` DATETIME NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_owner (`owner_id`),
  INDEX idx_next_run (`next_run_at`),
  INDEX idx_active (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- VENDOR PORTAL USERS
-- ============================================================
CREATE TABLE `vendor_portal_users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `global_vendor_id` INT NOT NULL UNIQUE,
  `email` VARCHAR(150) NOT NULL,
  `password_hash` VARCHAR(255) NULL,
  `reset_token` VARCHAR(255) NULL,
  `reset_expires_at` DATETIME NULL,
  `magic_token` VARCHAR(255) NULL,
  `magic_expires_at` DATETIME NULL,
  `last_login_at` DATETIME NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`global_vendor_id`) REFERENCES `global_vendors`(`id`) ON DELETE CASCADE,
  INDEX idx_email (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Done. Fresh database is ready.
-- Default admin: admin / Admin@123  (change immediately)
-- ============================================================