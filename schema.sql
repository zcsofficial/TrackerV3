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

-- Machines registered to users
CREATE TABLE IF NOT EXISTS `machines` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `machine_id` VARCHAR(191) NOT NULL,
  `user_id` INT NULL,
  `hostname` VARCHAR(191) NULL,
  `last_seen` TIMESTAMP NULL,
  UNIQUE KEY `uniq_machine` (`machine_id`),
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

INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('productive_hours_per_day_seconds', '28800');
INSERT IGNORE INTO `settings`(`key`,`value`) VALUES ('agent_sync_interval_seconds', '60');


