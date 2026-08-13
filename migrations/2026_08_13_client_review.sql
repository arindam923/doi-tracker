-- Track Flow client-review upgrade for an existing v1 installation.
-- Run once in phpMyAdmin after taking a verified database backup.
--
-- This migration intentionally keeps the legacy `vendors` table. The live
-- application uses `global_vendors` + `project_vendor`; retaining the old
-- table makes rollback and fact-table verification possible. Do not drop it
-- until the post-migration checks at the end have been signed off.

SET @tf_schema = DATABASE();

DELIMITER $$
DROP PROCEDURE IF EXISTS tf_add_column_if_missing$$
DROP PROCEDURE IF EXISTS tf_add_index_if_missing$$
DROP PROCEDURE IF EXISTS tf_drop_legacy_vendor_foreign_keys$$
DROP PROCEDURE IF EXISTS tf_add_global_vendor_fk_if_missing$$
DROP PROCEDURE IF EXISTS tf_client_review_preflight$$
DROP PROCEDURE IF EXISTS tf_remap_vendor_ids_if_table$$
CREATE PROCEDURE tf_add_column_if_missing(
    IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column
    ) THEN
        SET @tf_sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE tf_stmt FROM @tf_sql;
        EXECUTE tf_stmt;
        DEALLOCATE PREPARE tf_stmt;
    END IF;
END$$

CREATE PROCEDURE tf_add_index_if_missing(
    IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_index
    ) THEN
        SET @tf_sql = CONCAT('ALTER TABLE `', p_table, '` ADD ', p_definition);
        PREPARE tf_stmt FROM @tf_sql;
        EXECUTE tf_stmt;
        DEALLOCATE PREPARE tf_stmt;
    END IF;
END$$

CREATE PROCEDURE tf_drop_legacy_vendor_foreign_keys()
BEGIN
    DECLARE done INT DEFAULT 0;
    DECLARE v_table VARCHAR(64);
    DECLARE v_constraint VARCHAR(64);
    DECLARE fk_cursor CURSOR FOR
        SELECT TABLE_NAME, CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'vendors';
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    OPEN fk_cursor;
    fk_loop: LOOP
        FETCH fk_cursor INTO v_table, v_constraint;
        IF done THEN LEAVE fk_loop; END IF;
        SET @tf_sql = CONCAT('ALTER TABLE `', v_table, '` DROP FOREIGN KEY `', v_constraint, '`');
        PREPARE tf_stmt FROM @tf_sql;
        EXECUTE tf_stmt;
        DEALLOCATE PREPARE tf_stmt;
    END LOOP;
    CLOSE fk_cursor;
END$$

CREATE PROCEDURE tf_add_global_vendor_fk_if_missing(
    IN p_table VARCHAR(64), IN p_constraint VARCHAR(64)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = p_table
          AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = p_constraint
    ) THEN
        SET @tf_sql = CONCAT('ALTER TABLE `', p_table, '` ADD CONSTRAINT `', p_constraint,
            '` FOREIGN KEY (`vendor_id`) REFERENCES `global_vendors`(`id`)');
        PREPARE tf_stmt FROM @tf_sql;
        EXECUTE tf_stmt;
        DEALLOCATE PREPARE tf_stmt;
    END IF;
END$$

CREATE PROCEDURE tf_client_review_preflight()
BEGIN
    DECLARE v_has_vendors INT DEFAULT 0;
    DECLARE v_dupes INT DEFAULT 0;
    DECLARE v_missing_project INT DEFAULT 0;
    DECLARE v_orphan_clicks INT DEFAULT 0;

    SELECT COUNT(*) INTO v_has_vendors
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors';

    IF v_has_vendors > 0 THEN
        SELECT COUNT(*) INTO v_dupes FROM (
            SELECT vendor_code FROM vendors
            WHERE vendor_code IS NOT NULL AND vendor_code <> ''
            GROUP BY vendor_code HAVING COUNT(*) > 1
        ) d;
        IF v_dupes > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight failed: duplicate vendor_code in vendors';
        END IF;

        SELECT COUNT(*) INTO v_missing_project
        FROM vendors v LEFT JOIN projects p ON p.id = v.project_id
        WHERE p.id IS NULL;
        IF v_missing_project > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight failed: vendors rows reference missing projects';
        END IF;

        SELECT COUNT(*) INTO v_orphan_clicks
        FROM clicks c
        LEFT JOIN vendors v ON v.id = c.vendor_id
        LEFT JOIN global_vendors gv ON gv.id = c.vendor_id
        WHERE v.id IS NULL AND gv.id IS NULL;
        IF v_orphan_clicks > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight failed: clicks reference unknown vendor_id values';
        END IF;
    END IF;
END$$

CREATE PROCEDURE tf_remap_vendor_ids_if_table(IN p_table VARCHAR(64))
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = 'vendor_id'
    ) THEN
        SET @tf_sql = CONCAT('UPDATE `', p_table, '` t JOIN migration_vendor_map m ON t.vendor_id = m.legacy_vendor_id SET t.vendor_id = m.global_vendor_id');
        PREPARE tf_stmt FROM @tf_sql;
        EXECUTE tf_stmt;
        DEALLOCATE PREPARE tf_stmt;
    END IF;
END$$
DELIMITER ;

CALL tf_client_review_preflight();

-- Existing-table extensions.
CALL tf_add_column_if_missing('clients', 'skype', 'VARCHAR(100) NULL');
CALL tf_add_column_if_missing('clients', 'telegram', 'VARCHAR(100) NULL');
CALL tf_add_column_if_missing('clients', 'payment_terms', 'VARCHAR(100) NULL');
CALL tf_add_column_if_missing('clients', 'billing_address', 'TEXT NULL');

CALL tf_add_column_if_missing('projects', 'preview_link', 'VARCHAR(500) NULL');
CALL tf_add_column_if_missing('projects', 'short_code', 'VARCHAR(12) NULL');
CALL tf_add_column_if_missing('projects', 'currency', "VARCHAR(10) NOT NULL DEFAULT 'USD'");
CALL tf_add_column_if_missing('projects', 'vertical', "VARCHAR(100) NOT NULL DEFAULT 'Other'");
CALL tf_add_column_if_missing('projects', 'conversion_type', "VARCHAR(30) NOT NULL DEFAULT 'SOI'");
CALL tf_add_column_if_missing('projects', 'target_device', "VARCHAR(20) NOT NULL DEFAULT 'All'");
CALL tf_add_column_if_missing('projects', 'daily_cap', 'INT NOT NULL DEFAULT 0');
CALL tf_add_column_if_missing('projects', 'campaign_status', "VARCHAR(30) NOT NULL DEFAULT 'live'");
CALL tf_add_column_if_missing('projects', 'visibility', "VARCHAR(30) NOT NULL DEFAULT 'private'");
CALL tf_add_index_if_missing('projects', 'uk_projects_short_code', 'UNIQUE KEY `uk_projects_short_code` (`short_code`)');

ALTER TABLE projects MODIFY status ENUM('live','hold','closed','archived') NOT NULL DEFAULT 'live';
ALTER TABLE projects MODIFY campaign_type ENUM('CPL','CPC','CPA','CPS','CPI','CPM','RevShare','Hybrid') NOT NULL DEFAULT 'CPL';

CALL tf_add_column_if_missing('vendors', 'vendor_code', 'VARCHAR(20) NULL');
CALL tf_add_column_if_missing('vendors', 'company_name', 'VARCHAR(200) NULL');
CALL tf_add_column_if_missing('vendors', 'contact_person', 'VARCHAR(150) NULL');
CALL tf_add_column_if_missing('vendors', 'email', 'VARCHAR(150) NULL');
CALL tf_add_column_if_missing('vendors', 'telegram', 'VARCHAR(100) NULL');
CALL tf_add_column_if_missing('vendors', 'skype', 'VARCHAR(100) NULL');
CALL tf_add_column_if_missing('vendors', 'traffic_type', "VARCHAR(30) NOT NULL DEFAULT 'Other'");
CALL tf_add_column_if_missing('vendors', 'vendor_status', "VARCHAR(30) NOT NULL DEFAULT 'approved'");
CALL tf_add_column_if_missing('vendors', 'daily_cap', 'INT NOT NULL DEFAULT 0');

CALL tf_add_column_if_missing('clicks', 'browser', 'VARCHAR(100) NULL');
CALL tf_add_column_if_missing('clicks', 'os', 'VARCHAR(100) NULL');
CALL tf_add_column_if_missing('clicks', 'browser_lang', 'VARCHAR(50) NULL');
CALL tf_add_column_if_missing('clicks', 'isp', 'VARCHAR(200) NULL');
CALL tf_add_column_if_missing('clicks', 'country_code', 'CHAR(2) NULL');
CALL tf_add_column_if_missing('clicks', 'sub1', 'VARCHAR(200) NULL');
CALL tf_add_column_if_missing('clicks', 'sub2', 'VARCHAR(200) NULL');
CALL tf_add_column_if_missing('clicks', 'sub3', 'VARCHAR(200) NULL');
CALL tf_add_column_if_missing('clicks', 'sub4', 'VARCHAR(200) NULL');
CALL tf_add_column_if_missing('clicks', 'sub5', 'VARCHAR(200) NULL');

CALL tf_add_column_if_missing('conversions', 'sale_amount', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
CALL tf_add_column_if_missing('conversions', 'currency', "VARCHAR(10) NOT NULL DEFAULT 'USD'");
CALL tf_add_column_if_missing('conversions', 'payout', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
CALL tf_add_column_if_missing('conversions', 'transaction_id', 'VARCHAR(150) NULL');
CALL tf_add_column_if_missing('conversions', 'click_time', 'DATETIME NULL');
CALL tf_add_column_if_missing('conversions', 'time_diff_seconds', 'INT NULL');
CALL tf_add_column_if_missing('conversions', 'approval_status', "VARCHAR(20) NOT NULL DEFAULT 'approved'");
CALL tf_add_column_if_missing('conversions', 'sub1', 'VARCHAR(200) NULL');
CALL tf_add_column_if_missing('conversions', 'sub2', 'VARCHAR(200) NULL');
CALL tf_add_column_if_missing('conversions', 'sub3', 'VARCHAR(200) NULL');
CALL tf_add_column_if_missing('conversions', 'sub4', 'VARCHAR(200) NULL');
CALL tf_add_column_if_missing('conversions', 'sub5', 'VARCHAR(200) NULL');

-- New normalized vendor model and client-review modules.
CREATE TABLE IF NOT EXISTS global_vendors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_code VARCHAR(20) NOT NULL UNIQUE,
    vendor_name VARCHAR(200) NOT NULL,
    company_name VARCHAR(200) NULL,
    contact_person VARCHAR(150) NULL,
    email VARCHAR(150) NULL,
    telegram VARCHAR(100) NULL,
    skype VARCHAR(100) NULL,
    phone VARCHAR(50) NULL,
    traffic_type VARCHAR(30) NOT NULL DEFAULT 'Other',
    vendor_status VARCHAR(30) NOT NULL DEFAULT 'approved',
    default_payout DECIMAL(12,4) NOT NULL DEFAULT 0,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    daily_cap INT NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_global_vendors_status (vendor_status),
    KEY idx_global_vendors_traffic (traffic_type),
    KEY idx_global_vendors_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_vendor (
    project_id INT NOT NULL,
    vendor_id INT NOT NULL,
    payout DECIMAL(12,4) NOT NULL DEFAULT 0,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    postback_url VARCHAR(500) NULL,
    allowed_clicks_limit INT NOT NULL DEFAULT 0,
    daily_cap INT NOT NULL DEFAULT 0,
    assigned_by INT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL,
    PRIMARY KEY (project_id, vendor_id),
    KEY idx_project_vendor_vendor (vendor_id),
    KEY idx_project_vendor_status (status),
    CONSTRAINT fk_project_vendor_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_vendor_vendor FOREIGN KEY (vendor_id) REFERENCES global_vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS migration_vendor_map (
    legacy_vendor_id INT NOT NULL PRIMARY KEY,
    global_vendor_id INT NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_migration_vendor_map_global FOREIGN KEY (global_vendor_id) REFERENCES global_vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS short_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(12) NOT NULL UNIQUE,
    project_id INT NOT NULL,
    vendor_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_short_links_project (project_id),
    KEY idx_short_links_vendor (vendor_id),
    CONSTRAINT fk_short_links_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_short_links_vendor FOREIGN KEY (vendor_id) REFERENCES global_vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaign_geo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    country_code CHAR(2) NOT NULL,
    country_name VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_campaign_geo (project_id, country_code),
    CONSTRAINT fk_campaign_geo_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaign_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    body TEXT NOT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_campaign_notes_project (project_id),
    CONSTRAINT fk_campaign_notes_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id VARCHAR(100) NOT NULL,
    before_json TEXT NULL,
    after_json TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_actor (actor_id),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS client_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    project_id INT NULL,
    document_type VARCHAR(50) NOT NULL DEFAULT 'Other',
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    file_size BIGINT NOT NULL DEFAULT 0,
    mime_type VARCHAR(100) NULL,
    uploaded_by INT NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_client_documents_client (client_id),
    KEY idx_client_documents_project (project_id),
    CONSTRAINT fk_client_documents_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_documents_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_lists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    source VARCHAR(100) NOT NULL DEFAULT 'manual',
    client_id INT NULL,
    project_id INT NULL,
    vendor_id INT NULL,
    is_deduped TINYINT(1) NOT NULL DEFAULT 0,
    record_count INT NOT NULL DEFAULT 0,
    description TEXT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_email_lists_client (client_id), KEY idx_email_lists_project (project_id), KEY idx_email_lists_vendor (vendor_id),
    CONSTRAINT fk_email_lists_vendor FOREIGN KEY (vendor_id) REFERENCES global_vendors(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_list_entries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    list_id INT NOT NULL,
    email VARCHAR(254) NOT NULL,
    name VARCHAR(200) NULL,
    metadata_json TEXT NULL,
    country CHAR(2) NULL,
    source VARCHAR(100) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    is_unsubscribed TINYINT(1) NOT NULL DEFAULT 0,
    dedupe_hash CHAR(40) NOT NULL,
    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_emailed_at DATETIME NULL,
    total_emails_sent INT NOT NULL DEFAULT 0,
    UNIQUE KEY uk_email_list_dedupe (list_id, dedupe_hash),
    KEY idx_email_list_entries_country (country),
    CONSTRAINT fk_email_list_entries_list FOREIGN KEY (list_id) REFERENCES email_lists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    subject VARCHAR(300) NOT NULL,
    html_body TEXT NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    vendor_id INT NOT NULL,
    template_id INT NULL,
    list_id INT NULL,
    name VARCHAR(200) NOT NULL,
    subject VARCHAR(300) NOT NULL,
    html_body TEXT NOT NULL,
    from_name VARCHAR(150) NULL,
    from_email VARCHAR(150) NULL,
    daily_limit INT NOT NULL DEFAULT 1000,
    total_limit INT NOT NULL DEFAULT 0,
    sent_count INT NOT NULL DEFAULT 0,
    delivered_count INT NOT NULL DEFAULT 0,
    opened_count INT NOT NULL DEFAULT 0,
    clicked_count INT NOT NULL DEFAULT 0,
    converted_count INT NOT NULL DEFAULT 0,
    bounced_count INT NOT NULL DEFAULT 0,
    unsubscribed_count INT NOT NULL DEFAULT 0,
    failed_count INT NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    is_multi_step TINYINT(1) NOT NULL DEFAULT 0,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_email_campaigns_project (project_id), KEY idx_email_campaigns_vendor (vendor_id), KEY idx_email_campaigns_status (status),
    CONSTRAINT fk_email_campaigns_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_email_campaigns_vendor FOREIGN KEY (vendor_id) REFERENCES global_vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_campaign_sends (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    project_id INT NOT NULL,
    vendor_id INT NOT NULL,
    list_id INT NOT NULL,
    entry_id BIGINT NOT NULL,
    recipient_email VARCHAR(254) NOT NULL,
    recipient_name VARCHAR(200) NULL,
    country CHAR(2) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    resend_id VARCHAR(100) NULL,
    sent_at DATETIME NULL,
    error_message TEXT NULL,
    retry_count INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_email_campaign_recipient (campaign_id, recipient_email),
    KEY idx_email_campaign_sends_status (status),
    CONSTRAINT fk_email_campaign_sends_campaign FOREIGN KEY (campaign_id) REFERENCES email_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_email_campaign_sends_list FOREIGN KEY (list_id) REFERENCES email_lists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS scheduled_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    report_type VARCHAR(50) NOT NULL DEFAULT 'overview',
    group_by VARCHAR(50) NOT NULL DEFAULT 'project',
    filters_json TEXT NULL,
    frequency VARCHAR(20) NOT NULL DEFAULT 'daily',
    custom_cron VARCHAR(50) NULL,
    recipients_csv TEXT NULL,
    last_run_at DATETIME NULL,
    next_run_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_scheduled_reports_due (is_active, next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vendor_portal_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    global_vendor_id INT NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NULL,
    reset_token VARCHAR(255) NULL,
    reset_expires_at DATETIME NULL,
    magic_token VARCHAR(255) NULL,
    magic_expires_at DATETIME NULL,
    last_login_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_vendor_portal_users_email (email),
    CONSTRAINT fk_vendor_portal_users_vendor FOREIGN KEY (global_vendor_id) REFERENCES global_vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill stable opaque codes before link generation.
UPDATE projects
SET short_code = UPPER(LEFT(SHA2(CONCAT('project:', id, ':', postback_token), 256), 10))
WHERE short_code IS NULL OR short_code = '';

UPDATE vendors
SET vendor_code = CONCAT('V', LPAD(id, 6, '0'))
WHERE vendor_code IS NULL OR vendor_code = '';

INSERT INTO global_vendors
    (vendor_code, vendor_name, company_name, contact_person, email, telegram, skype,
     traffic_type, vendor_status, default_payout, currency, daily_cap, notes, created_at)
SELECT v.vendor_code, v.vendor_name, v.company_name, v.contact_person, v.email, v.telegram, v.skype,
       COALESCE(NULLIF(v.traffic_type, ''), 'Other'),
       CASE v.vendor_status WHEN 'blacklisted' THEN 'blacklisted' WHEN 'suspended' THEN 'suspended' WHEN 'pending' THEN 'pending' ELSE 'approved' END,
       v.vendor_cpi, COALESCE(NULLIF(p.currency, ''), 'USD'), v.daily_cap, v.notes, v.created_at
FROM vendors v
JOIN projects p ON p.id = v.project_id
ON DUPLICATE KEY UPDATE vendor_name = VALUES(vendor_name), company_name = VALUES(company_name),
    contact_person = VALUES(contact_person), email = VALUES(email), telegram = VALUES(telegram), skype = VALUES(skype),
    traffic_type = VALUES(traffic_type), vendor_status = VALUES(vendor_status), default_payout = VALUES(default_payout),
    currency = VALUES(currency), daily_cap = VALUES(daily_cap), notes = VALUES(notes);

INSERT INTO migration_vendor_map (legacy_vendor_id, global_vendor_id)
SELECT v.id, gv.id FROM vendors v JOIN global_vendors gv ON gv.vendor_code = v.vendor_code
ON DUPLICATE KEY UPDATE global_vendor_id = VALUES(global_vendor_id);

INSERT INTO project_vendor
    (project_id, vendor_id, payout, currency, status, postback_url, allowed_clicks_limit, daily_cap, notes, assigned_at)
SELECT v.project_id, m.global_vendor_id, v.vendor_cpi, COALESCE(NULLIF(p.currency, ''), 'USD'),
       CASE WHEN v.status IN ('closed') THEN 'closed' WHEN v.status IN ('paused', 'hold') THEN 'hold' ELSE 'active' END,
       v.postback_url, v.allowed_clicks_limit, v.daily_cap, v.notes, v.created_at
FROM vendors v
JOIN migration_vendor_map m ON m.legacy_vendor_id = v.id
JOIN projects p ON p.id = v.project_id
ON DUPLICATE KEY UPDATE payout = VALUES(payout), currency = VALUES(currency), status = VALUES(status),
    postback_url = VALUES(postback_url), allowed_clicks_limit = VALUES(allowed_clicks_limit), daily_cap = VALUES(daily_cap), notes = VALUES(notes);

-- Fact remap is safe to re-run because mapping retains both old and new IDs.
CALL tf_drop_legacy_vendor_foreign_keys();
SET FOREIGN_KEY_CHECKS = 0;
UPDATE clicks c JOIN migration_vendor_map m ON c.vendor_id = m.legacy_vendor_id SET c.vendor_id = m.global_vendor_id;
UPDATE conversions c JOIN migration_vendor_map m ON c.vendor_id = m.legacy_vendor_id SET c.vendor_id = m.global_vendor_id;
CALL tf_remap_vendor_ids_if_table('logs');
CALL tf_remap_vendor_ids_if_table('email_campaigns');
CALL tf_remap_vendor_ids_if_table('email_campaign_sends');
CALL tf_remap_vendor_ids_if_table('email_lists');
UPDATE logs l JOIN migration_vendor_map m ON l.vendor_id = m.legacy_vendor_id SET l.vendor_id = m.global_vendor_id;
SET FOREIGN_KEY_CHECKS = 1;
CALL tf_add_global_vendor_fk_if_missing('clicks', 'fk_clicks_global_vendor');

INSERT IGNORE INTO short_links (code, project_id, vendor_id)
SELECT LEFT(SHA2(CONCAT('link:', pv.project_id, ':', pv.vendor_id), 256), 12), pv.project_id, pv.vendor_id
FROM project_vendor pv;

CALL tf_add_column_if_missing('email_campaigns', 'list_id', 'INT NULL');
CALL tf_add_index_if_missing('email_campaign_sends', 'uk_campaign_entry', 'UNIQUE KEY `uk_campaign_entry` (`campaign_id`, `entry_id`)');

INSERT INTO settings (setting_key, setting_value) VALUES
    ('global_postback_enabled', '0'), ('global_postback_url', ''), ('ip_enrichment_enabled', '1'),
    ('vendor_login_enabled', '0'), ('strict_target_device', '0'), ('email_rate_per_minute', '50'),
    ('vendor_portal_show_network_economics', '0')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- Verification: these queries must return 0 before the legacy `vendors` table is retired.
SELECT COUNT(*) AS unmapped_legacy_vendors FROM vendors v LEFT JOIN migration_vendor_map m ON m.legacy_vendor_id = v.id WHERE m.legacy_vendor_id IS NULL;
SELECT COUNT(*) AS orphaned_click_vendor_refs FROM clicks c LEFT JOIN global_vendors gv ON gv.id = c.vendor_id WHERE gv.id IS NULL;
SELECT COUNT(*) AS orphaned_conversion_vendor_refs FROM conversions c LEFT JOIN global_vendors gv ON gv.id = c.vendor_id WHERE gv.id IS NULL;
SELECT COUNT(*) AS project_vendor_without_link FROM project_vendor pv LEFT JOIN short_links sl ON sl.project_id = pv.project_id AND sl.vendor_id = pv.vendor_id WHERE sl.id IS NULL;

DROP PROCEDURE IF EXISTS tf_add_column_if_missing;
DROP PROCEDURE IF EXISTS tf_add_index_if_missing;
DROP PROCEDURE IF EXISTS tf_drop_legacy_vendor_foreign_keys;
DROP PROCEDURE IF EXISTS tf_add_global_vendor_fk_if_missing;
DROP PROCEDURE IF EXISTS tf_client_review_preflight;
DROP PROCEDURE IF EXISTS tf_remap_vendor_ids_if_table;
