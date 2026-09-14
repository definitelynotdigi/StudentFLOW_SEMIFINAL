-- =====================================================
-- GRC Student Portal - Clean Database Setup
-- =====================================================

CREATE DATABASE IF NOT EXISTS `student_portal_db` 
  DEFAULT CHARACTER SET utf8mb4 
  COLLATE utf8mb4_general_ci;

USE `student_portal_db`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `support_messages`;
DROP TABLE IF EXISTS `financial_ledgers`;
DROP TABLE IF EXISTS `academic_records`;
DROP TABLE IF EXISTS `announcements`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- ==================== USERS ====================
CREATE TABLE `users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('Student', 'Department', 'Admin') NOT NULL DEFAULT 'Student',
  `student_id` VARCHAR(50) DEFAULT NULL,
  `first_name` VARCHAR(50) DEFAULT NULL,
  `last_name` VARCHAR(50) DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `department_course` VARCHAR(100) DEFAULT NULL,
  `otp_code` VARCHAR(10) DEFAULT NULL,
  `otp_expires` DATETIME DEFAULT NULL,
  `is_verified` TINYINT(1) DEFAULT 0,
  `failed_attempts` INT(11) DEFAULT 0,
  `lockout_until` DATETIME DEFAULT NULL,
  `reset_token` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_email` (`email`),
  UNIQUE KEY `uk_student_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==================== ACADEMIC RECORDS ====================
CREATE TABLE `academic_records` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL,
  `subject_code` VARCHAR(20) NOT NULL,
  `subject_title` VARCHAR(100) NOT NULL,
  `grade` DECIMAL(3,2) NOT NULL,
  `semester` VARCHAR(20) NOT NULL,
  `academic_year` VARCHAR(15) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_academic_student` (`student_id`),
  CONSTRAINT `fk_academic_records_student` 
    FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==================== FINANCIAL LEDGERS ====================
CREATE TABLE `financial_ledgers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL,
  `description` VARCHAR(100) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `transaction_type` ENUM('Charge', 'Payment') NOT NULL,
  `transaction_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_ledger_student` (`student_id`),
  CONSTRAINT `fk_financial_ledgers_student` 
    FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==================== ANNOUNCEMENTS ====================
CREATE TABLE `announcements` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `posted_by` VARCHAR(50) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==================== SUPPORT MESSAGES (CHAT) ====================
CREATE TABLE `support_messages` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `sender_role` ENUM('student', 'admin') NOT NULL DEFAULT 'student',
  `message` TEXT NOT NULL,
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_support_user` (`user_id`),
  CONSTRAINT `fk_support_messages_user` 
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==================== SEED DATA ====================
INSERT INTO `users` 
(`id`, `username`, `email`, `password_hash`, `role`, `student_id`, `first_name`, `last_name`, `department_course`, `is_verified`) 
VALUES
(1, 'student2026', 'student@grc.edu.ph', '$2y$10$f.kOnZ5b7tYt3V6pExRbeuqGZfP6u9m2YvGstZ2.Iofm7L1Xj0u7G', 'Student', '2025-01-001', 'Default', 'Student', 'BSIT', 1),
(2, 'ccs_dept', 'department@grc.edu.ph', '$2y$10$f.kOnZ5b7tYt3V6pExRbeuqGZfP6u9m2YvGstZ2.Iofm7L1Xj0u7G', 'Department', 'DEPT-CCS', 'CCS', 'Department', 'CCS', 1),
(3, 'admin_grc', 'admin@grc.edu.ph', '$2y$10$f.kOnZ5b7tYt3V6pExRbeuqGZfP6u9m2YvGstZ2.Iofm7L1Xj0u7G', 'Admin', 'ADM-001', 'System', 'Admin', 'IT Office', 1);

INSERT INTO `academic_records` (`student_id`, `subject_code`, `subject_title`, `grade`, `semester`, `academic_year`) VALUES
(1, 'SYSARC1', 'Systems Architecture 1', 1.25, '1st Semester', '2025-2026'),
(1, 'SIA2', 'Systems Integration & Architecture', 1.50, '1st Semester', '2025-2026');

INSERT INTO `financial_ledgers` (`student_id`, `description`, `amount`, `transaction_type`) VALUES
(1, 'Tuition Fee - 1st Semester Baseline', 15000.00, 'Charge'),
(1, 'Partial Examination Payment Received', 5000.00, 'Payment');

INSERT INTO `announcements` (`title`, `content`, `posted_by`) VALUES
('Welcome to GRC Student Portal', 'Please review the system architectural integration files for SIA2 updates.', 'Admin System');