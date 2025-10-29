-- Manual Migration Script (for MySQL versions that don't support IF NOT EXISTS)
-- Use this if the automated script fails

USE `tracker_v3`;

-- Step 1: Check if columns exist before adding
-- Run these checks manually and only run ALTER if columns don't exist

-- Check for display_name column
SELECT COUNT(*) AS exists_display_name
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'tracker_v3' 
  AND TABLE_NAME = 'machines' 
  AND COLUMN_NAME = 'display_name';

-- If result is 0, run:
ALTER TABLE `machines` ADD COLUMN `display_name` VARCHAR(255) NULL AFTER `hostname`;

-- Check for email column
SELECT COUNT(*) AS exists_email
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'tracker_v3' 
  AND TABLE_NAME = 'machines' 
  AND COLUMN_NAME = 'email';

-- If result is 0, run:
ALTER TABLE `machines` ADD COLUMN `email` VARCHAR(255) NULL AFTER `display_name`;

-- Check for upn column
SELECT COUNT(*) AS exists_upn
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'tracker_v3' 
  AND TABLE_NAME = 'machines' 
  AND COLUMN_NAME = 'upn';

-- If result is 0, run:
ALTER TABLE `machines` ADD COLUMN `upn` VARCHAR(255) NULL AFTER `email`;

-- Check for created_at column
SELECT COUNT(*) AS exists_created_at
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'tracker_v3' 
  AND TABLE_NAME = 'machines' 
  AND COLUMN_NAME = 'created_at';

-- If result is 0, run:
ALTER TABLE `machines` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `last_seen`;

-- Step 2: Add indexes (check if they exist first)

-- Check for idx_email index
SELECT COUNT(*) AS exists_idx_email
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_SCHEMA = 'tracker_v3' 
  AND TABLE_NAME = 'machines' 
  AND INDEX_NAME = 'idx_email';

-- If result is 0, run:
CREATE INDEX `idx_email` ON `machines` (`email`);

-- Check for idx_upn index
SELECT COUNT(*) AS exists_idx_upn
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_SCHEMA = 'tracker_v3' 
  AND TABLE_NAME = 'machines' 
  AND INDEX_NAME = 'idx_upn';

-- If result is 0, run:
CREATE INDEX `idx_upn` ON `machines` (`upn`);

-- Step 3: Add new settings
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('delete_screenshots_after_sync', '1');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('agent_install_path', '');

-- Verify
SELECT 'Migration completed!' AS status;

