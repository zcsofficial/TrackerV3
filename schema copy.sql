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

-- Website monitoring tables
CREATE TABLE IF NOT EXISTS `website_categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `description` TEXT NULL,
  `color` VARCHAR(7) NULL DEFAULT '#3498db',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `websites` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `domain` VARCHAR(255) NOT NULL,
  `full_url` VARCHAR(2048) NULL,
  `category_id` INT NULL,
  `title` VARCHAR(500) NULL,
  `first_seen` DATETIME NOT NULL,
  `last_seen` DATETIME NOT NULL,
  `total_visits` INT NOT NULL DEFAULT 0,
  `total_duration_seconds` BIGINT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_domain` (`domain`),
  INDEX `idx_category` (`category_id`),
  INDEX `idx_last_seen` (`last_seen`),
  CONSTRAINT `fk_websites_category` FOREIGN KEY (`category_id`) REFERENCES `website_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `website_blocks` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `website_id` BIGINT NULL,
  `category_id` INT NULL,
  `user_id` INT NULL,
  `machine_id` INT NULL,
  `block_type` ENUM('global','user','machine') NOT NULL DEFAULT 'global',
  `domain_pattern` VARCHAR(255) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `block_reason` TEXT NULL,
  `created_by` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_website` (`website_id`),
  INDEX `idx_category` (`category_id`),
  INDEX `idx_user` (`user_id`),
  INDEX `idx_machine` (`machine_id`),
  INDEX `idx_block_type` (`block_type`, `is_active`),
  INDEX `idx_domain_pattern` (`domain_pattern`),
  CONSTRAINT `fk_website_blocks_website` FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_website_blocks_category` FOREIGN KEY (`category_id`) REFERENCES `website_categories`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_website_blocks_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_website_blocks_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_website_blocks_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `website_visits` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `machine_id` INT NULL,
  `website_id` BIGINT NULL,
  `domain` VARCHAR(255) NOT NULL,
  `full_url` VARCHAR(2048) NOT NULL,
  `title` VARCHAR(500) NULL,
  `browser` VARCHAR(50) NULL,
  `is_private` TINYINT(1) NOT NULL DEFAULT 0,
  `is_incognito` TINYINT(1) NOT NULL DEFAULT 0,
  `visit_start` DATETIME NOT NULL,
  `visit_end` DATETIME NULL,
  `duration_seconds` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_time` (`user_id`, `visit_start`),
  INDEX `idx_machine_time` (`machine_id`, `visit_start`),
  INDEX `idx_website_time` (`website_id`, `visit_start`),
  INDEX `idx_domain_time` (`domain`, `visit_start`),
  CONSTRAINT `fk_website_visits_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_website_visits_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_website_visits_website` FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Application monitoring tables
CREATE TABLE IF NOT EXISTS `application_categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `description` TEXT NULL,
  `color` VARCHAR(7) NULL DEFAULT '#3498db',
  `is_productive` TINYINT(1) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `applications` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NULL,
  `process_name` VARCHAR(191) NOT NULL,
  `executable_path` VARCHAR(500) NULL,
  `category_id` INT NULL,
  `is_productive` TINYINT(1) NULL,
  `first_seen` DATETIME NOT NULL,
  `last_seen` DATETIME NOT NULL,
  `total_sessions` INT NOT NULL DEFAULT 0,
  `total_usage_seconds` BIGINT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_process_name` (`process_name`),
  INDEX `idx_app_category` (`category_id`),
  INDEX `idx_app_last_seen` (`last_seen`),
  CONSTRAINT `fk_applications_category` FOREIGN KEY (`category_id`) REFERENCES `application_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `application_blocks` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `application_id` BIGINT NULL,
  `category_id` INT NULL,
  `user_id` INT NULL,
  `machine_id` INT NULL,
  `block_type` ENUM('global','user','machine') NOT NULL DEFAULT 'global',
  `process_name` VARCHAR(191) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `block_reason` TEXT NULL,
  `created_by` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_application` (`application_id`),
  INDEX `idx_app_category` (`category_id`),
  INDEX `idx_app_user` (`user_id`),
  INDEX `idx_app_machine` (`machine_id`),
  INDEX `idx_block_type_active` (`block_type`, `is_active`),
  INDEX `idx_process_name` (`process_name`),
  CONSTRAINT `fk_application_blocks_application` FOREIGN KEY (`application_id`) REFERENCES `applications`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_application_blocks_category` FOREIGN KEY (`category_id`) REFERENCES `application_categories`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_application_blocks_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_application_blocks_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_application_blocks_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `application_usage` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `machine_id` INT NULL,
  `application_id` BIGINT NULL,
  `application_name` VARCHAR(255) NOT NULL,
  `process_name` VARCHAR(191) NOT NULL,
  `window_title` VARCHAR(500) NULL,
  `session_start` DATETIME NOT NULL,
  `session_end` DATETIME NULL,
  `duration_seconds` INT NOT NULL DEFAULT 0,
  `is_productive` TINYINT(1) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_time` (`user_id`, `session_start`),
  INDEX `idx_machine_time` (`machine_id`, `session_start`),
  INDEX `idx_application_time` (`application_id`, `session_start`),
  INDEX `idx_process_time` (`process_name`, `session_start`),
  CONSTRAINT `fk_app_usage_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_app_usage_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_app_usage_application` FOREIGN KEY (`application_id`) REFERENCES `applications`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Unified activity timeline (websites, applications, etc.)
CREATE TABLE IF NOT EXISTS `activity_timeline` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `machine_id` INT NULL,
  `activity_type` ENUM('website','application','screenshot','device','idle','active') NOT NULL,
  `website_id` BIGINT NULL,
  `application_id` BIGINT NULL,
  `item_name` VARCHAR(255) NULL,
  `item_detail` VARCHAR(500) NULL,
  `start_time` DATETIME NOT NULL,
  `end_time` DATETIME NULL,
  `duration_seconds` INT NOT NULL DEFAULT 0,
  `is_productive` TINYINT(1) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_time` (`user_id`, `start_time`),
  INDEX `idx_machine_time` (`machine_id`, `start_time`),
  INDEX `idx_type_time` (`activity_type`, `start_time`),
  CONSTRAINT `fk_timeline_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_timeline_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_timeline_website` FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_timeline_application` FOREIGN KEY (`application_id`) REFERENCES `applications`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('productive_hours_per_day_seconds', '28800');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('agent_sync_interval_seconds', '60');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('parallel_sync_workers', '1');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('delete_screenshots_after_sync', '1');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('agent_install_path', '');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('device_monitoring_enabled', '0');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('screenshots_enabled', '1');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('screenshot_interval_seconds', '300');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('website_monitoring_enabled', '1');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('website_monitoring_interval_seconds', '1');

-- Default website categories
INSERT IGNORE INTO `website_categories`(`name`, `description`, `color`) VALUES
('Social Media', 'Social networking and communication platforms', '#3b5998'),
('Entertainment', 'Video streaming, games, and entertainment sites', '#ff6b6b'),
('Shopping', 'E-commerce and online shopping', '#f39c12'),
('News', 'News and media websites', '#3498db'),
('Productivity', 'Work-related and productivity tools', '#2ecc71'),
('Education', 'Educational and learning platforms', '#9b59b6'),
('Adult', 'Adult content websites', '#e74c3c'),
('Gambling', 'Online gambling and betting sites', '#c0392b'),
('Unknown', 'Uncategorized websites', '#95a5a6');

-- Default application categories (ActivTrak-like)
INSERT IGNORE INTO `application_categories`(`name`, `description`, `color`, `is_productive`) VALUES
('Chat & Messaging', 'Instant messaging and chat applications', '#3498db', 1),
('Email & Calendar', 'Email clients and calendar applications', '#2ecc71', 1),
('Productivity', 'Office suites, text editors, note-taking', '#16a085', 1),
('Development', 'Code editors, IDEs, development tools', '#27ae60', 1),
('Design & Graphics', 'Image editors, design software', '#8e44ad', 1),
('Entertainment', 'Games, media players, streaming apps', '#e74c3c', 0),
('Social Media', 'Social networking desktop apps', '#3b5998', 0),
('Banking & Finance', 'Financial applications', '#f39c12', 1),
('Web Browser', 'Web browsers', '#3498db', 1),
('System', 'System utilities and OS components', '#95a5a6', 1),
('Unknown', 'Uncategorized applications', '#95a5a6', NULL);

-- Migration commands for existing databases:
-- Run these if you already have a database and need to add the new columns:
-- (MySQL 5.7+ supports IF NOT EXISTS, otherwise use migrate_agents_manual.sql)

-- ALTER TABLE `machines` ADD COLUMN IF NOT EXISTS `display_name` VARCHAR(255) NULL AFTER `hostname`;
-- ALTER TABLE `machines` ADD COLUMN IF NOT EXISTS `email` VARCHAR(255) NULL AFTER `display_name`;
-- ALTER TABLE `machines` ADD COLUMN IF NOT EXISTS `upn` VARCHAR(255) NULL AFTER `email`;
-- ALTER TABLE `machines` ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `last_seen`;
-- CREATE INDEX IF NOT EXISTS `idx_email` ON `machines` (`email`);
-- CREATE INDEX IF NOT EXISTS `idx_upn` ON `machines` (`upn`);


