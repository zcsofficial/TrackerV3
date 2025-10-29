-- Migration script to add device monitoring tables
-- Run this on your existing database

USE `tracker_v3`;

-- Device monitoring table
CREATE TABLE IF NOT EXISTS `device_monitoring` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `machine_id` INT NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_machine_monitoring` (`machine_id`),
  CONSTRAINT `fk_device_monitoring_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Detected external devices
CREATE TABLE IF NOT EXISTS `devices` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NULL,
  `machine_id` INT NULL,
  `device_type` ENUM('USB','Bluetooth','Network','Other') NOT NULL DEFAULT 'USB',
  `vendor_id` VARCHAR(50) NULL,
  `product_id` VARCHAR(50) NULL,
  `serial_number` VARCHAR(255) NULL,
  `device_name` VARCHAR(255) NOT NULL,
  `device_path` VARCHAR(500) NULL,
  `first_seen` DATETIME NOT NULL,
  `last_seen` DATETIME NOT NULL,
  `is_blocked` TINYINT(1) NOT NULL DEFAULT 0,
  `is_allowed` TINYINT(1) NOT NULL DEFAULT 0,
  `block_reason` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_machine` (`user_id`, `machine_id`),
  INDEX `idx_device_hash` (`vendor_id`, `product_id`, `serial_number`),
  INDEX `idx_is_blocked` (`is_blocked`),
  CONSTRAINT `fk_devices_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_devices_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Device connection logs
CREATE TABLE IF NOT EXISTS `device_logs` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `device_id` BIGINT NOT NULL,
  `user_id` INT NULL,
  `machine_id` INT NULL,
  `action` ENUM('connected','disconnected','blocked','allowed') NOT NULL,
  `action_time` DATETIME NOT NULL,
  `details` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_device_time` (`device_id`, `action_time`),
  INDEX `idx_user_time` (`user_id`, `action_time`),
  CONSTRAINT `fk_device_logs_device` FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_device_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_device_logs_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add device_monitoring_enabled setting
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('device_monitoring_enabled', '0');

-- Add screenshot settings
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('screenshots_enabled', '1');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('screenshot_interval_seconds', '300');

SELECT 'Device monitoring tables and screenshot settings created successfully!' AS status;

