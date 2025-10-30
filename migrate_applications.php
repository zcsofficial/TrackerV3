<?php
/**
 * Migration Script - Add Application Monitoring Tables
 * Run this script to add application monitoring tables to your database
 * Usage: php migrate_applications.php
 */

require_once __DIR__ . '/config.php';

try {
    $pdo = db();
    
    echo "Starting migration...\n";
    
    // Create application_categories table
    echo "Creating application_categories table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `application_categories` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `name` VARCHAR(100) NOT NULL UNIQUE,
          `description` TEXT NULL,
          `color` VARCHAR(7) NULL DEFAULT '#3498db',
          `is_productive` TINYINT(1) NOT NULL DEFAULT 1,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ application_categories table created\n";
    
    // Create applications table
    echo "Creating applications table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `applications` (
          `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
          `name` VARCHAR(255) NOT NULL,
          `process_name` VARCHAR(255) NOT NULL,
          `executable_path` VARCHAR(1000) NULL,
          `vendor` VARCHAR(255) NULL,
          `category_id` INT NULL,
          `is_productive` TINYINT(1) NULL,
          `first_seen` DATETIME NOT NULL,
          `last_seen` DATETIME NOT NULL,
          `total_usage_seconds` BIGINT NOT NULL DEFAULT 0,
          `total_sessions` INT NOT NULL DEFAULT 0,
          `icon_path` VARCHAR(500) NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_process_name` (`process_name`),
          INDEX `idx_category` (`category_id`),
          INDEX `idx_name` (`name`),
          INDEX `idx_last_seen` (`last_seen`),
          CONSTRAINT `fk_applications_category` FOREIGN KEY (`category_id`) REFERENCES `application_categories`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ applications table created\n";
    
    // Create application_usage table
    echo "Creating application_usage table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `application_usage` (
          `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `machine_id` INT NULL,
          `application_id` BIGINT NULL,
          `application_name` VARCHAR(255) NOT NULL,
          `process_name` VARCHAR(255) NOT NULL,
          `window_title` VARCHAR(500) NULL,
          `session_start` DATETIME NOT NULL,
          `session_end` DATETIME NULL,
          `duration_seconds` INT NOT NULL DEFAULT 0,
          `is_productive` TINYINT(1) NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_user_time` (`user_id`, `session_start`),
          INDEX `idx_machine_time` (`machine_id`, `session_start`),
          INDEX `idx_application_time` (`application_id`, `session_start`),
          INDEX `idx_process_name` (`process_name`),
          CONSTRAINT `fk_app_usage_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_app_usage_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE SET NULL,
          CONSTRAINT `fk_app_usage_application` FOREIGN KEY (`application_id`) REFERENCES `applications`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ application_usage table created\n";
    
    // Create application_blocks table
    echo "Creating application_blocks table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `application_blocks` (
          `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
          `application_id` BIGINT NULL,
          `category_id` INT NULL,
          `user_id` INT NULL,
          `machine_id` INT NULL,
          `process_name` VARCHAR(255) NULL,
          `block_type` ENUM('global','user','machine') NOT NULL DEFAULT 'global',
          `is_active` TINYINT(1) NOT NULL DEFAULT 1,
          `block_reason` TEXT NULL,
          `created_by` INT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX `idx_application` (`application_id`),
          INDEX `idx_category` (`category_id`),
          INDEX `idx_user` (`user_id`),
          INDEX `idx_machine` (`machine_id`),
          INDEX `idx_process` (`process_name`),
          INDEX `idx_block_type` (`block_type`, `is_active`),
          CONSTRAINT `fk_app_blocks_application` FOREIGN KEY (`application_id`) REFERENCES `applications`(`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_app_blocks_category` FOREIGN KEY (`category_id`) REFERENCES `application_categories`(`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_app_blocks_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_app_blocks_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_app_blocks_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ application_blocks table created\n";
    
    // Create activity_timeline table (unified timeline)
    echo "Creating activity_timeline table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `activity_timeline` (
          `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `machine_id` INT NULL,
          `activity_type` ENUM('application','website','idle') NOT NULL,
          `application_id` BIGINT NULL,
          `website_id` BIGINT NULL,
          `item_name` VARCHAR(255) NOT NULL,
          `item_detail` VARCHAR(500) NULL,
          `start_time` DATETIME NOT NULL,
          `end_time` DATETIME NULL,
          `duration_seconds` INT NOT NULL DEFAULT 0,
          `is_productive` TINYINT(1) NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_user_time` (`user_id`, `start_time`),
          INDEX `idx_machine_time` (`machine_id`, `start_time`),
          INDEX `idx_type_time` (`activity_type`, `start_time`),
          INDEX `idx_application` (`application_id`),
          INDEX `idx_website` (`website_id`),
          CONSTRAINT `fk_timeline_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_timeline_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE SET NULL,
          CONSTRAINT `fk_timeline_application` FOREIGN KEY (`application_id`) REFERENCES `applications`(`id`) ON DELETE SET NULL,
          CONSTRAINT `fk_timeline_website` FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ activity_timeline table created\n";
    
    // Insert default application categories
    echo "Inserting default application categories...\n";
    $categories = [
        ['Chat & Messaging', 'Instant messaging and chat applications', '#3498db', 1],
        ['Email & Calendar', 'Email clients and calendar applications', '#2ecc71', 1],
        ['Productivity', 'Office suites, text editors, note-taking', '#16a085', 1],
        ['Development', 'Code editors, IDEs, development tools', '#27ae60', 1],
        ['Design & Graphics', 'Image editors, design software', '#8e44ad', 1],
        ['Entertainment', 'Games, media players, streaming apps', '#e74c3c', 0],
        ['Social Media', 'Social networking desktop apps', '#3b5998', 0],
        ['Banking & Finance', 'Financial applications', '#f39c12', 1],
        ['Web Browser', 'Web browsers', '#3498db', 1],
        ['System', 'System utilities and OS components', '#95a5a6', 1],
        ['Unknown', 'Uncategorized applications', '#95a5a6', NULL]
    ];
    
    $stmt = $pdo->prepare('
        INSERT IGNORE INTO `application_categories`(`name`, `description`, `color`, `is_productive`) 
        VALUES (?, ?, ?, ?)
    ');
    
    foreach ($categories as $cat) {
        $stmt->execute($cat);
    }
    echo "✓ Default categories inserted\n";
    
    // Insert default settings
    echo "Inserting default settings...\n";
    $settings = [
        ['application_monitoring_enabled', '1'],
        ['application_monitoring_interval_seconds', '2']
    ];
    
    $stmt = $pdo->prepare('INSERT IGNORE INTO `settings`(`key`, `value`) VALUES (?, ?)');
    foreach ($settings as $setting) {
        $stmt->execute($setting);
    }
    echo "✓ Default settings inserted\n";
    
    echo "\n✅ Migration completed successfully!\n";
    echo "\nYou can now:\n";
    echo "1. Access the Applications page: " . BASE_URL . "applications.php\n";
    echo "2. Restart your agent to start monitoring applications\n";
    echo "3. Configure application monitoring in Settings\n";
    
} catch (Exception $e) {
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}


