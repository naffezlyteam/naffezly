CREATE DATABASE IF NOT EXISTS `naffezly` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `naffezly`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(180) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `language` VARCHAR(10) NOT NULL DEFAULT 'en',
  `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `verification_token` VARCHAR(255) DEFAULT NULL,
  `verification_expires_at` DATETIME DEFAULT NULL,
  `password_reset_token` VARCHAR(255) DEFAULT NULL,
  `password_reset_expires_at` DATETIME DEFAULT NULL,
  `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `projects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(180) NOT NULL,
  `title_ar` VARCHAR(180) DEFAULT NULL,
  `slug` VARCHAR(160) NOT NULL,
  `type` VARCHAR(40) NOT NULL,
  `summary` TEXT NOT NULL,
  `summary_ar` TEXT DEFAULT NULL,
  `description` LONGTEXT NOT NULL,
  `description_ar` LONGTEXT DEFAULT NULL,
  `benefits` LONGTEXT DEFAULT NULL,
  `benefits_ar` LONGTEXT DEFAULT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `is_free` TINYINT(1) NOT NULL DEFAULT 1,
  `image` VARCHAR(255) DEFAULT NULL,
  `gallery` JSON DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'published',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `project_id` INT DEFAULT NULL,
  `project_title` VARCHAR(180) DEFAULT NULL,
  `project_title_ar` VARCHAR(180) DEFAULT NULL,
  `project_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `contact_name` VARCHAR(120) DEFAULT NULL,
  `email` VARCHAR(180) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'pending_review',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `user_id` (`user_id`),
  KEY `project_id` (`project_id`),
  CONSTRAINT `orders_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `custom_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `project_name` VARCHAR(180) NOT NULL,
  `project_type` VARCHAR(40) NOT NULL,
  `purpose` TEXT NOT NULL,
  `specifications` LONGTEXT NOT NULL,
  `preferred_payment_method` VARCHAR(80) DEFAULT NULL,
  `budget_egp` DECIMAL(10,2) DEFAULT NULL,
  `additional_details` LONGTEXT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'pending_review',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `custom_user_idx` (`user_id`),
  CONSTRAINT `custom_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT DEFAULT NULL,
  `user_id` INT DEFAULT NULL,
  `sender` VARCHAR(20) NOT NULL DEFAULT 'user',
  `message` TEXT NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `order_idx` (`order_id`),
  KEY `user_idx` (`user_id`),
  CONSTRAINT `messages_order_fk` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
