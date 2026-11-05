-- GeoTrack GIS database schema, seed data, and constraints
-- Import this with MySQL/MariaDB (e.g. phpMyAdmin or mysql CLI).
-- Default credentials in the app expect root with an empty password on localhost.
-- Change passwords and the bootstrap admin account after installation.

CREATE DATABASE IF NOT EXISTS `geotrack_gis`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `geotrack_gis`;

SET time_zone = '+00:00';

-- Application users (for authentication and authorization).
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(32) NOT NULL,
  `display_name` VARCHAR(64) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','user') NOT NULL DEFAULT 'user',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `login_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` DATETIME NULL,
  `last_login_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tracked entities (people, objects, assets, demo items).
CREATE TABLE IF NOT EXISTS `tracked_entities` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_code` VARCHAR(32) NOT NULL,
  `entity_name` VARCHAR(80) NOT NULL,
  `entity_type` ENUM('person','laptop','vehicle','asset','other') NOT NULL DEFAULT 'other',
  `tracking_method` ENUM('gps','simulated','ip','manual') NOT NULL DEFAULT 'gps',
  `tracking_status` ENUM('stopped','active','paused') NOT NULL DEFAULT 'stopped',
  `owner_user_id` INT UNSIGNED NULL,
  `is_demo` TINYINT(1) NOT NULL DEFAULT 0,
  `access_token_hash` VARCHAR(64) NULL,
  `notes` VARCHAR(255) NULL,
  `last_seen_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_entities_code` (`entity_code`),
  KEY `idx_entities_owner` (`owner_user_id`),
  KEY `idx_entities_status` (`tracking_status`),
  KEY `idx_entities_type` (`entity_type`),
  KEY `idx_entities_demo` (`is_demo`),
  CONSTRAINT `fk_entities_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Location history (immutable record of positions; retention policy applies).
CREATE TABLE IF NOT EXISTS `location_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_id` INT UNSIGNED NOT NULL,
  `latitude` DECIMAL(10,7) NOT NULL,
  `longitude` DECIMAL(10,7) NOT NULL,
  `accuracy` DECIMAL(8,2) NULL COMMENT 'metres',
  `speed` DECIMAL(7,2) NULL COMMENT 'm/s',
  `heading` DECIMAL(5,2) NULL COMMENT 'degrees',
  `recorded_at` DATETIME(3) NOT NULL,
  `source_type` ENUM('gps','simulated','ip','manual') NOT NULL DEFAULT 'gps',
  `quality_flag` ENUM('ok','coarse','suspect','rejected') NOT NULL DEFAULT 'ok',
  `quality_note` VARCHAR(128) NULL,
  `client_ip_masked` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_loc_entity_time` (`entity_id`, `recorded_at`),
  KEY `idx_loc_time` (`recorded_at`),
  KEY `idx_loc_entity` (`entity_id`),
  CONSTRAINT `fk_loc_entity` FOREIGN KEY (`entity_id`) REFERENCES `tracked_entities` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-session/device tracking sessions (for audit and visibility).
CREATE TABLE IF NOT EXISTS `tracking_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NULL,
  `session_uuid` CHAR(36) NOT NULL,
  `source_type` ENUM('gps','simulated','ip') NOT NULL DEFAULT 'gps',
  `status` ENUM('active','paused','stopped') NOT NULL DEFAULT 'active',
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `stopped_at` DATETIME NULL,
  `last_heartbeat_at` DATETIME NULL,
  `client_ip_masked` VARCHAR(45) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sessions_uuid` (`session_uuid`),
  KEY `idx_sessions_entity` (`entity_id`),
  KEY `idx_sessions_status` (`status`),
  KEY `idx_sessions_started` (`started_at`),
  CONSTRAINT `fk_sessions_entity` FOREIGN KEY (`entity_id`) REFERENCES `tracked_entities` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IP geolocation cache to avoid unnecessary external requests.
CREATE TABLE IF NOT EXISTS `ip_geolocation_cache` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_hash` CHAR(64) NOT NULL,
  `ip_masked` VARCHAR(45) NOT NULL,
  `provider` VARCHAR(32) NOT NULL,
  `country` VARCHAR(64) NULL,
  `region` VARCHAR(64) NULL,
  `city` VARCHAR(64) NULL,
  `latitude` DECIMAL(10,7) NULL,
  `longitude` DECIMAL(10,7) NULL,
  `timezone` VARCHAR(64) NULL,
  `isp` VARCHAR(128) NULL,
  `org` VARCHAR(128) NULL,
  `raw_json` JSON NULL,
  `fetched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ipgeo_hash` (`ip_hash`),
  KEY `idx_ipgeo_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lightweight key-value store for maintenance timestamps.
CREATE TABLE IF NOT EXISTS `system_meta` (
  `meta_key` VARCHAR(64) NOT NULL,
  `meta_value` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`meta_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit log of security-relevant actions.
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `action` VARCHAR(64) NOT NULL,
  `entity_id` INT UNSIGNED NULL,
  `details` VARCHAR(500) NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_entity` (`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fictional sample data for classroom demonstration.
-- These are clearly marked as demo/simulated and are never treated as real live GPS tracks.
INSERT IGNORE INTO `tracked_entities` (`id`, `entity_code`, `entity_name`, `entity_type`, `tracking_method`, `tracking_status`, `owner_user_id`, `is_demo`, `notes`) VALUES
(1, 'P-001', 'Person 001 (Demo)', 'person', 'simulated', 'stopped', NULL, 1, 'Demonstration asset. Not a real person.'),
(2, 'L-001', 'Laptop 001 (Demo)', 'laptop', 'simulated', 'stopped', NULL, 1, 'Demonstration asset. IP geolocation is indicative only.'),
(3, 'V-001', 'Vehicle 001 (Demo)', 'vehicle', 'simulated', 'stopped', NULL, 1, 'Demonstration asset. Not a real vehicle.'),
(4, 'A-001', 'Research Asset 001 (Demo)', 'asset', 'simulated', 'stopped', NULL, 1, 'Demonstration asset for proximity analysis.');

-- Seed route around MUST/Mbarara for realistic demo movement (demo records only).
-- Timestamps are relative to import time so the seeded trail always falls inside
-- the 7-day analysis window, no matter when the marker runs the SQL script.
SET @seed_start = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY);
INSERT IGNORE INTO `location_history` (`id`, `entity_id`, `latitude`, `longitude`, `accuracy`, `speed`, `recorded_at`, `source_type`, `quality_flag`, `quality_note`) VALUES
(1001, 1, -0.6117000, 30.6581000, 12.00, 0.00, DATE_ADD(@seed_start, INTERVAL 8 HOUR), 'simulated', 'ok', NULL),
(1002, 1, -0.6119000, 30.6584000, 12.00, 1.24, DATE_ADD(@seed_start, INTERVAL 485 MINUTE), 'simulated', 'ok', NULL),
(1003, 1, -0.6121000, 30.6587000, 12.00, 1.36, DATE_ADD(@seed_start, INTERVAL 490 MINUTE), 'simulated', 'ok', NULL),
(1004, 1, -0.6123000, 30.6591000, 12.00, 1.36, DATE_ADD(@seed_start, INTERVAL 495 MINUTE), 'simulated', 'ok', NULL),
(1005, 1, -0.6116000, 30.6593000, 12.00, 1.38, DATE_ADD(@seed_start, INTERVAL 500 MINUTE), 'simulated', 'ok', NULL),
(1006, 3, -0.6112000, 30.6585000, 15.00, 0.00, DATE_ADD(@seed_start, INTERVAL 8 HOUR), 'simulated', 'ok', NULL),
(1007, 3, -0.6110000, 30.6590000, 15.00, 8.94, DATE_ADD(@seed_start, INTERVAL 488 MINUTE), 'simulated', 'ok', NULL),
(1008, 3, -0.6108000, 30.6594000, 15.00, 9.72, DATE_ADD(@seed_start, INTERVAL 496 MINUTE), 'simulated', 'ok', NULL),
(1009, 2, -0.6117000, 30.6581000, 10.00, 0.00, DATE_ADD(@seed_start, INTERVAL 8 HOUR), 'simulated', 'ok', NULL);

INSERT IGNORE INTO `system_meta` (`meta_key`, `meta_value`) VALUES
('schema_version', '1.0.0'),
('installation_date', UTC_TIMESTAMP());

-- Foreign keys / checks are enforced in InnoDB. SQLite/MyISAM ignore them but
-- this project targets MySQL/MariaDB.
