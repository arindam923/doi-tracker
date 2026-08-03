-- ============================================================
-- TERNFLUENZY — Complete Database Setup SQL v1.0
-- Run in phpMyAdmin → SQL tab
-- ============================================================

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `email` VARCHAR(150),
  `role` ENUM('super_admin','campaign_manager','viewer') DEFAULT 'campaign_manager',
  `is_active` TINYINT DEFAULT 1,
  `last_login` DATETIME,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Default admin user
-- Username: admin
-- Password: Admin@123
-- IMPORTANT: Change this password immediately after first login!
-- To generate a new hash, run: php -r "echo password_hash('YourPassword', PASSWORD_DEFAULT);"
INSERT INTO `users` (`username`, `password`, `email`, `role`) VALUES
('admin', '$2y$10$YourHashHereChangeThis', 'admin@ternfluenzy.com', 'super_admin');

CREATE TABLE IF NOT EXISTS `clients` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `client_name` VARCHAR(200) NOT NULL,
  `client_code` VARCHAR(20) NOT NULL UNIQUE,
  `contact_person` VARCHAR(150),
  `email` VARCHAR(150),
  `phone` VARCHAR(50),
  `country` VARCHAR(100),
  `default_currency` VARCHAR(10) DEFAULT 'USD',
  `notes` TEXT,
  `is_active` TINYINT DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `projects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_code` VARCHAR(30) NOT NULL UNIQUE,
  `project_name` VARCHAR(300) NOT NULL,
  `client_id` INT NOT NULL,
  `client_survey_link` VARCHAR(500),
  `postback_token` VARCHAR(100) NOT NULL,
  `client_cpi` DECIMAL(10,2) DEFAULT 0.00,
  `vendor_default_cpi` DECIMAL(10,2) DEFAULT 0.00,
  `total_quota` INT DEFAULT 0,
  `completes_count` INT DEFAULT 0,
  `clicks_count` INT DEFAULT 0,
  `status` ENUM('live','hold','closed') DEFAULT 'live',
  `country_target` VARCHAR(100),
  `start_date` DATE,
  `end_date` DATE,
  `description` TEXT,
  `created_by` INT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`)
);

CREATE TABLE IF NOT EXISTS `vendors` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT NOT NULL,
  `vendor_name` VARCHAR(200) NOT NULL,
  `contact_info` VARCHAR(300),
  `vendor_cpi` DECIMAL(10,2) DEFAULT 0.00,
  `postback_url` VARCHAR(500),
  `allowed_clicks_limit` INT DEFAULT 0,
  `status` ENUM('active','paused','closed') DEFAULT 'active',
  `notes` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`)
);

CREATE TABLE IF NOT EXISTS `clicks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `click_id` VARCHAR(64) NOT NULL UNIQUE,
  `project_id` INT NOT NULL,
  `vendor_id` INT NOT NULL,
  `ip_address` VARCHAR(45),
  `user_agent` TEXT,
  `referrer` VARCHAR(500),
  `country_detected` VARCHAR(100),
  `device_type` VARCHAR(50),
  `clicked_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `is_converted` TINYINT DEFAULT 0,
  `is_duplicate_ip` TINYINT DEFAULT 0,
  INDEX idx_click_id (`click_id`),
  INDEX idx_project (`project_id`),
  INDEX idx_vendor (`vendor_id`),
  INDEX idx_clicked_at (`clicked_at`),
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`),
  FOREIGN KEY (`vendor_id`) REFERENCES `vendors`(`id`)
);

CREATE TABLE IF NOT EXISTS `conversions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `click_id` VARCHAR(64) NOT NULL,
  `project_id` INT NOT NULL,
  `vendor_id` INT NOT NULL,
  `status` VARCHAR(20) DEFAULT 'complete',
  `client_revenue` DECIMAL(10,2) DEFAULT 0.00,
  `vendor_cost` DECIMAL(10,2) DEFAULT 0.00,
  `profit` DECIMAL(10,2) DEFAULT 0.00,
  `rejection_reason` VARCHAR(200),
  `is_manual` TINYINT DEFAULT 0,
  `converted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_click_id (`click_id`),
  INDEX idx_project (`project_id`),
  INDEX idx_converted_at (`converted_at`)
);

CREATE TABLE IF NOT EXISTS `logs` (
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
);

CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(45) NOT NULL,
  `action` VARCHAR(50) NOT NULL DEFAULT 'login',
  `attempts` INT DEFAULT 1,
  `locked_until` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `ip_action` (`ip_address`, `action`),
  INDEX idx_locked_until (`locked_until`)
);

CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `sent_emails` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `recipient_email` VARCHAR(150) NOT NULL,
  `recipient_name` VARCHAR(200),
  `client_id` INT,
  `subject` VARCHAR(300) NOT NULL,
  `body` TEXT NOT NULL,
  `status` VARCHAR(20) DEFAULT 'sent',
  `resend_id` VARCHAR(100),
  `error_message` TEXT,
  `sent_by` INT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_client (`client_id`),
  INDEX idx_created_at (`created_at`)
);

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('site_name', 'Ternfluenzy'),
('default_currency', 'USD'),
('quota_buffer_percent', '0'),
('session_timeout_hours', '8'),
('smtp_enabled', '0'),
('resend_api_key', ''),
('email_from_address', 'noreply@yourdomain.com'),
('email_from_name', 'Ternfluenzy')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
