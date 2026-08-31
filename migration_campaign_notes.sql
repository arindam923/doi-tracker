-- ============================================================
-- Campaign Notes fields migration
-- ============================================================
-- Safe to run repeatedly. Uses the deployment-compatible MySQL
-- convention established in migration_global_vendors.sql.
-- ============================================================

ALTER TABLE `projects`
    ADD COLUMN IF NOT EXISTS `client_instructions` TEXT NULL AFTER `description`;

ALTER TABLE `projects`
    ADD COLUMN IF NOT EXISTS `optimization_notes` TEXT NULL AFTER `client_instructions`;

ALTER TABLE `projects`
    ADD COLUMN IF NOT EXISTS `publisher_restrictions` TEXT NULL AFTER `optimization_notes`;
