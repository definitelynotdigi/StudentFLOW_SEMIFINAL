/* ============================================================
   STUDENTFLOW DATABASE
   Complete database setup for the uploaded StudentFLOW project
   ============================================================ */

CREATE DATABASE IF NOT EXISTS student_portal_db
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE student_portal_db;


/* ============================================================
   1. USERS
   ============================================================ */

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,

    password_hash VARCHAR(255) NOT NULL,

    role ENUM(
        'Admin',
        'Department',
        'Professor',
        'Faculty',
        'Student'
    ) NOT NULL DEFAULT 'Student',

    student_id VARCHAR(50) DEFAULT NULL,

    first_name VARCHAR(100) DEFAULT NULL,
    last_name VARCHAR(100) DEFAULT NULL,

    phone VARCHAR(30) DEFAULT NULL,
    department_course VARCHAR(150) DEFAULT NULL,

    otp_code VARCHAR(20) DEFAULT NULL,
    otp_expires DATETIME DEFAULT NULL,

    is_verified TINYINT(1) NOT NULL DEFAULT 0,

    failed_attempts INT NOT NULL DEFAULT 0,
    lockout_until DATETIME DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_users_role (role),
    INDEX idx_users_student_id (student_id),
    INDEX idx_users_email (email)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


/* ============================================================
   2. ACADEMIC RECORDS
   ============================================================ */

CREATE TABLE IF NOT EXISTS academic_records (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    faculty_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,

    subject_code VARCHAR(50) NOT NULL,
    subject_title VARCHAR(150) NOT NULL,

    grade DECIMAL(5,2) DEFAULT NULL,

    semester VARCHAR(50) NOT NULL,
    academic_year VARCHAR(50) NOT NULL,

    is_released TINYINT(1) NOT NULL DEFAULT 0,

    cleared_by_faculty TINYINT(1) NOT NULL DEFAULT 0,
    cleared_by_student TINYINT(1) NOT NULL DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_academic_student (student_id),
    INDEX idx_academic_faculty (faculty_id),
    INDEX idx_academic_subject (subject_code),

    CONSTRAINT fk_academic_faculty
        FOREIGN KEY (faculty_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_academic_student
        FOREIGN KEY (student_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    UNIQUE KEY unique_student_subject (
        student_id,
        subject_code
    )
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


/* ============================================================
   3. ANNOUNCEMENTS
   ============================================================ */

CREATE TABLE IF NOT EXISTS announcements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,

    posted_by VARCHAR(150) DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_announcements_created (
        created_at
    )
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


/* ============================================================
   4. SUPPORT / CHAT MESSAGES
   ============================================================ */

CREATE TABLE IF NOT EXISTS support_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,

    sender_role ENUM(
        'student',
        'admin'
    ) NOT NULL DEFAULT 'student',

    message TEXT NOT NULL,

    is_read TINYINT(1) NOT NULL DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_support_user (user_id),
    INDEX idx_support_read (is_read),

    CONSTRAINT fk_support_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


/* ============================================================
   5. FACULTY SUBMISSIONS
   ============================================================ */

CREATE TABLE IF NOT EXISTS faculty_submissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    faculty_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,

    admin_id INT UNSIGNED DEFAULT NULL,

    admin_target_id VARCHAR(50) NOT NULL,

    submission_status VARCHAR(20)
        NOT NULL DEFAULT 'submitted',

    cleared_by_admin TINYINT(1)
        NOT NULL DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_submission_faculty (faculty_id),
    INDEX idx_submission_student (student_id),
    INDEX idx_submission_admin (admin_target_id),
    INDEX idx_submission_status (cleared_by_admin),

    CONSTRAINT fk_submission_faculty
        FOREIGN KEY (faculty_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_submission_student
        FOREIGN KEY (student_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_submission_admin
        FOREIGN KEY (admin_id)
        REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


/* ============================================================
   6. FINANCIAL LEDGER
   ============================================================
   The PHP dashboard still reads this table, even though the
   financial ledger feature was removed from the project.
   This table is therefore retained so the dashboard/print pages
   do not generate "table doesn't exist" errors.
   ============================================================ */

CREATE TABLE IF NOT EXISTS financial_ledgers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    student_id INT UNSIGNED NOT NULL,

    faculty_id INT UNSIGNED DEFAULT NULL,

    description VARCHAR(255) NOT NULL,

    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    transaction_type ENUM(
        'Charge',
        'Payment'
    ) NOT NULL DEFAULT 'Charge',

    transaction_date DATE NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_ledger_student (student_id),
    INDEX idx_ledger_faculty (faculty_id),
    INDEX idx_ledger_date (transaction_date),

    CONSTRAINT fk_ledger_student
        FOREIGN KEY (student_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_ledger_faculty
        FOREIGN KEY (faculty_id)
        REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


/* ============================================================
   7. SAMPLE ADMIN ACCOUNT
   ============================================================
   Password hash below is a PHP bcrypt hash.
   You can replace it with your own account through the system.
   ============================================================ */

INSERT INTO users (
    username,
    email,
    password_hash,
    role,
    student_id,
    first_name,
    last_name,
    department_course,
    is_verified
)
SELECT
    'admin1',
    'admin@portal.com',
    '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe1234567890abcdefghijklm',
    'Admin',
    'ADMIN-001',
    'System',
    'Administrator',
    'Administration',
    1
WHERE NOT EXISTS (
    SELECT 1
    FROM users
    WHERE username = 'admin1'
);


/* ============================================================
   8. SAMPLE FACULTY ACCOUNT
   ============================================================ */

INSERT INTO users (
    username,
    email,
    password_hash,
    role,
    student_id,
    first_name,
    last_name,
    phone,
    department_course,
    is_verified
)
SELECT
    'prof_smith',
    'smith@portal.com',
    '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe1234567890abcdefghijklm',
    'Professor',
    'FAC-001',
    'John',
    'Smith',
    '555-0192',
    'Computer Science',
    1
WHERE NOT EXISTS (
    SELECT 1
    FROM users
    WHERE username = 'prof_smith'
);


/* ============================================================
   9. SAMPLE STUDENT ACCOUNT
   ============================================================ */

INSERT INTO users (
    username,
    email,
    password_hash,
    role,
    student_id,
    first_name,
    last_name,
    phone,
    department_course,
    is_verified
)
SELECT
    'student_jane',
    'jane@portal.com',
    '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe1234567890abcdefghijklm',
    'Student',
    'STU-2026-001',
    'Jane',
    'Doe',
    '555-0143',
    'Computer Science',
    1
WHERE NOT EXISTS (
    SELECT 1
    FROM users
    WHERE username = 'student_jane'
);


/* ============================================================
   10. SAMPLE ANNOUNCEMENT
   ============================================================ */

INSERT INTO announcements (
    title,
    content,
    posted_by
)
SELECT
    'Welcome to StudentFLOW',
    'Welcome to the StudentFLOW Student and Faculty Portal.',
    'System Administrator'
WHERE NOT EXISTS (
    SELECT 1
    FROM announcements
    WHERE title = 'Welcome to StudentFLOW'
);


/* ============================================================
   11. SAMPLE ACADEMIC RECORDS
   ============================================================ */

INSERT INTO academic_records (
    faculty_id,
    student_id,
    subject_code,
    subject_title,
    grade,
    semester,
    academic_year,
    is_released,
    cleared_by_faculty,
    cleared_by_student
)
SELECT
    f.id,
    s.id,
    'CS101',
    'Introduction to Computer Science',
    1.50,
    '1st Semester',
    '2026-2027',
    1,
    0,
    0
FROM users f
JOIN users s
WHERE f.username = 'prof_smith'
  AND s.username = 'student_jane'
  AND NOT EXISTS (
      SELECT 1
      FROM academic_records ar
      WHERE ar.student_id = s.id
        AND ar.subject_code = 'CS101'
  );


INSERT INTO academic_records (
    faculty_id,
    student_id,
    subject_code,
    subject_title,
    grade,
    semester,
    academic_year,
    is_released,
    cleared_by_faculty,
    cleared_by_student
)
SELECT
    f.id,
    s.id,
    'DB201',
    'Database Management Systems',
    1.75,
    '1st Semester',
    '2026-2027',
    1,
    0,
    0
FROM users f
JOIN users s
WHERE f.username = 'prof_smith'
  AND s.username = 'student_jane'
  AND NOT EXISTS (
      SELECT 1
      FROM academic_records ar
      WHERE ar.student_id = s.id
        AND ar.subject_code = 'DB201'
  );


/* ============================================================
   12. VERIFY INSTALLATION
   ============================================================ */

SELECT
    'users' AS table_name,
    COUNT(*) AS records
FROM users

UNION ALL

SELECT
    'academic_records',
    COUNT(*)
FROM academic_records

UNION ALL

SELECT
    'announcements',
    COUNT(*)
FROM announcements

UNION ALL

SELECT
    'support_messages',
    COUNT(*)
FROM support_messages

UNION ALL

SELECT
    'faculty_submissions',
    COUNT(*)
FROM faculty_submissions

UNION ALL

SELECT
    'financial_ledgers',
    COUNT(*)
FROM financial_ledgers;