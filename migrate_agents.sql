-- Migration script to update existing TrackerV3 database with new agent features
-- Run this on your existing database to add the new columns and settings

USE `tracker_v3`;

-- Add new columns to machines table
-- Note: MySQL 5.7+ supports IF NOT EXISTS, but we'll use separate statements for compatibility
-- If you get "Duplicate column name" errors, the columns already exist - safe to ignore

ALTER TABLE `machines` 
ADD COLUMN IF NOT EXISTS `display_name` VARCHAR(255) NULL AFTER `hostname`;

ALTER TABLE `machines` 
ADD COLUMN IF NOT EXISTS `email` VARCHAR(255) NULL AFTER `display_name`;

ALTER TABLE `machines` 
ADD COLUMN IF NOT EXISTS `upn` VARCHAR(255) NULL AFTER `email`;

ALTER TABLE `machines` 
ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `last_seen`;

-- Add indexes for email and upn
-- For MySQL 8.0+, you can use IF NOT EXISTS
-- For older versions, these will error if indexes exist (safe to ignore or check first)

-- Check MySQL version - IF NOT EXISTS for indexes is supported in MySQL 8.0+
-- If using MySQL 5.7 or older, use migrate_agents_manual.sql instead

CREATE INDEX IF NOT EXISTS `idx_email` ON `machines` (`email`);
CREATE INDEX IF NOT EXISTS `idx_upn` ON `machines` (`upn`);

-- Add new settings (INSERT IGNORE will skip if already exists)
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('delete_screenshots_after_sync', '1');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('agent_install_path', '');

-- Verify the changes
SELECT 'Migration completed. Checking results...' AS status;
SELECT COUNT(*) AS machines_count FROM `machines`;
SELECT `key`, `value` FROM `settings` WHERE `key` IN ('delete_screenshots_after_sync', 'agent_install_path', 'parallel_sync_workers');

