-- Schema for Employee Tracking System (TrackerV3)
-- Import order:
-- 1) CREATE DATABASE (adjust name if needed)
-- 2) USE database
-- 3) Tables

CREATE DATABASE IF NOT EXISTS `tracker_v3` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `tracker_v3`;

-- Users
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(191) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('superadmin','admin','hr','employee') NOT NULL DEFAULT 'employee',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Machines (Agents) registered to users
CREATE TABLE IF NOT EXISTS `machines` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `machine_id` VARCHAR(191) NOT NULL,
  `user_id` INT NULL,
  `hostname` VARCHAR(191) NULL,
  `display_name` VARCHAR(255) NULL,
  `email` VARCHAR(255) NULL,
  `upn` VARCHAR(255) NULL,
  `last_seen` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_machine` (`machine_id`),
  INDEX `idx_email` (`email`),
  INDEX `idx_upn` (`upn`),
  CONSTRAINT `fk_machines_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Activity aggregates (1-minute windows)
CREATE TABLE IF NOT EXISTS `activity` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `machine_id` INT NULL,
  `start_time` DATETIME NOT NULL,
  `end_time` DATETIME NOT NULL,
  `productive_seconds` INT NOT NULL DEFAULT 0,
  `unproductive_seconds` INT NOT NULL DEFAULT 0,
  `idle_seconds` INT NOT NULL DEFAULT 0,
  `mouse_moves` INT NOT NULL DEFAULT 0,
  `key_presses` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_time` (`user_id`, `start_time`),
  CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_activity_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Screenshots metadata
CREATE TABLE IF NOT EXISTS `screenshots` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `machine_id` INT NULL,
  `taken_at` DATETIME NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `filesize_kb` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_time` (`user_id`, `taken_at`),
  CONSTRAINT `fk_screens_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_screens_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- App settings
CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(100) NOT NULL UNIQUE,
  `value` VARCHAR(255) NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Device monitoring tables
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
  `device_hash` VARCHAR(64) NULL,
  `first_seen` DATETIME NOT NULL,
  `last_seen` DATETIME NOT NULL,
  `is_blocked` TINYINT(1) NOT NULL DEFAULT 0,
  `is_allowed` TINYINT(1) NOT NULL DEFAULT 0,
  `block_reason` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_machine` (`user_id`, `machine_id`),
  INDEX `idx_device_hash` (`device_hash`),
  INDEX `idx_device_ids` (`vendor_id`, `product_id`, `serial_number`),
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

INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('productive_hours_per_day_seconds', '28800');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('agent_sync_interval_seconds', '60');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('parallel_sync_workers', '1');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('delete_screenshots_after_sync', '1');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('agent_install_path', '');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('device_monitoring_enabled', '0');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('screenshots_enabled', '1');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('screenshot_interval_seconds', '300');

-- Migration commands for existing databases:
-- Run these if you already have a database and need to add the new columns:
-- (MySQL 5.7+ supports IF NOT EXISTS, otherwise use migrate_agents_manual.sql)

-- ALTER TABLE `machines` ADD COLUMN IF NOT EXISTS `display_name` VARCHAR(255) NULL AFTER `hostname`;
-- ALTER TABLE `machines` ADD COLUMN IF NOT EXISTS `email` VARCHAR(255) NULL AFTER `display_name`;
-- ALTER TABLE `machines` ADD COLUMN IF NOT EXISTS `upn` VARCHAR(255) NULL AFTER `email`;
-- ALTER TABLE `machines` ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `last_seen`;
-- CREATE INDEX IF NOT EXISTS `idx_email` ON `machines` (`email`);
-- CREATE INDEX IF NOT EXISTS `idx_upn` ON `machines` (`upn`);


