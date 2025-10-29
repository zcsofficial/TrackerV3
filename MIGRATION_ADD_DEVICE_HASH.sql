-- Migration: Add device_hash column to devices table
-- Run this on existing databases

ALTER TABLE `devices` ADD COLUMN IF NOT EXISTS `device_hash` VARCHAR(64) NULL AFTER `device_path`;

-- Update existing devices to have device_hash (if they don't have one)
UPDATE `devices` SET `device_hash` = MD5(CONCAT(IFNULL(`vendor_id`, ''), '-', IFNULL(`product_id`, ''), '-', IFNULL(`serial_number`, ''), '-', `device_name`))
WHERE `device_hash` IS NULL OR `device_hash` = '';

-- Add index on device_hash if it doesn't exist
CREATE INDEX IF NOT EXISTS `idx_device_hash` ON `devices` (`device_hash`);

SELECT 'Migration completed: device_hash column added' AS status;

