-- Simple Migration Script - Works with all MySQL versions
-- Run this if you're not sure about your MySQL version or getting errors

USE `tracker_v3`;

-- Add display_name column (will fail silently if exists - safe to ignore error)
ALTER TABLE `machines` ADD COLUMN `display_name` VARCHAR(255) NULL AFTER `hostname`;

-- Add email column
ALTER TABLE `machines` ADD COLUMN `email` VARCHAR(255) NULL AFTER `display_name`;

-- Add upn column
ALTER TABLE `machines` ADD COLUMN `upn` VARCHAR(255) NULL AFTER `email`;

-- Add created_at column
ALTER TABLE `machines` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `last_seen`;

-- Add indexes (will fail if already exist - safe to ignore)
CREATE INDEX `idx_email` ON `machines` (`email`);
CREATE INDEX `idx_upn` ON `machines` (`upn`);

-- Add new settings (will skip if already exist)
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('delete_screenshots_after_sync', '1');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('agent_install_path', '');

SELECT 'Migration completed!' AS status;

