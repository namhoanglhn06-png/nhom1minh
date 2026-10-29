-- ========================================================
-- CƠ SỞ DỮ LIỆU ĐỀ TÀI 9: CÔNG CỤ SAO LƯU TĂNG DẦN & KHÔI PHỤC DỮ LIỆU
-- PHP 8.2 & MariaDB / MySQL (XAMPP)
-- ========================================================

CREATE DATABASE IF NOT EXISTS `btl_backup_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `btl_backup_db`;

-- 1. Bảng quản lý tác vụ sao lưu
CREATE TABLE IF NOT EXISTS `backup_jobs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `source_path` VARCHAR(500) NOT NULL,
    `target_path` VARCHAR(500) NOT NULL,
    `excluded_extensions` TEXT NULL COMMENT 'Đuôi file loại trừ, vd: .tmp, .log, .cache',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Bảng quản lý kho lưu trữ tệp vật lý độc bản (Content-Addressable Storage Deduplication)
CREATE TABLE IF NOT EXISTS `objects` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sha256_hash` CHAR(64) NOT NULL UNIQUE,
    `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `storage_path` VARCHAR(500) NOT NULL,
    `ref_count` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_objects_hash` (`sha256_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Bảng phiên bản sao lưu (Hỗ trợ Atomic Transaction: IN_PROGRESS -> COMPLETED / FAILED)
CREATE TABLE IF NOT EXISTS `versions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `job_id` INT NOT NULL,
    `version_number` INT UNSIGNED NOT NULL,
    `status` ENUM('IN_PROGRESS', 'COMPLETED', 'FAILED') DEFAULT 'IN_PROGRESS',
    `total_files` INT UNSIGNED DEFAULT 0,
    `total_bytes` BIGINT UNSIGNED DEFAULT 0,
    `added_count` INT UNSIGNED DEFAULT 0,
    `modified_count` INT UNSIGNED DEFAULT 0,
    `deleted_count` INT UNSIGNED DEFAULT 0,
    `unchanged_count` INT UNSIGNED DEFAULT 0,
    `started_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME NULL,
    FOREIGN KEY (`job_id`) REFERENCES `backup_jobs`(`id`) ON DELETE CASCADE,
    INDEX `idx_versions_job_status` (`job_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Bảng danh mục tệp chi tiết theo phiên bản (Entries)
CREATE TABLE IF NOT EXISTS `entries` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `version_id` INT NOT NULL,
    `object_id` INT NULL,
    `relative_path` VARCHAR(500) NOT NULL,
    `file_size` BIGINT UNSIGNED DEFAULT 0,
    `file_mtime` VARCHAR(30) NULL,
    `change_type` ENUM('ADDED', 'MODIFIED', 'DELETED', 'UNCHANGED') NOT NULL,
    `verified_hash` CHAR(64) NULL,
    FOREIGN KEY (`version_id`) REFERENCES `versions`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`object_id`) REFERENCES `objects`(`id`) ON DELETE SET NULL,
    INDEX `idx_entries_version` (`version_id`),
    INDEX `idx_entries_path` (`relative_path`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Bảng nhật ký chạy (Run logs)
CREATE TABLE IF NOT EXISTS `run_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `job_id` INT NULL,
    `version_id` INT NULL,
    `log_level` ENUM('INFO', 'WARNING', 'ERROR') DEFAULT 'INFO',
    `stage` VARCHAR(50) NOT NULL,
    `message` TEXT NOT NULL,
    `file_target` VARCHAR(500) NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_logs_version` (`version_id`),
    INDEX `idx_logs_level` (`log_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Bảng lập lịch sao lưu tự động (Automated Cron / Schedules)
CREATE TABLE IF NOT EXISTS `schedules` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `job_id` INT NOT NULL,
    `frequency` ENUM('HOURLY', 'DAILY', 'WEEKLY', 'MONTHLY') NOT NULL DEFAULT 'DAILY',
    `run_time` TIME NOT NULL DEFAULT '00:00:00',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_run` DATETIME NULL,
    `next_run` DATETIME NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`job_id`) REFERENCES `backup_jobs`(`id`) ON DELETE CASCADE,
    INDEX `idx_schedules_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Bảng người dùng và xác thực tài khoản (Authentication & RBAC)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `role` ENUM('ADMIN', 'OPERATOR', 'VIEWER') DEFAULT 'OPERATOR',
    `avatar` VARCHAR(255) NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `last_login` DATETIME NULL,
    `status` ENUM('ACTIVE', 'SUSPENDED') DEFAULT 'ACTIVE',
    INDEX `idx_users_username` (`username`),
    INDEX `idx_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mật khẩu mặc định: admin123 (bcrypt)
INSERT IGNORE INTO `users` (`id`, `username`, `email`, `password_hash`, `full_name`, `role`)
VALUES (1, 'admin', 'admin@backuptool.local', '$2y$10$w8pQ4T3tLh9n.q/U0g4FfOi686tq9z9pQh6nQ4L0m5jZ1rQzW7Z0G', 'Quản Trị Viên Hệ Thống', 'ADMIN');

