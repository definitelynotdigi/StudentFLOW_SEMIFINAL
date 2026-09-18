-- 1. DATABASE CREATION
CREATE DATABASE IF NOT EXISTS student_portal;
USE student_portal;

-- 2. USERS & AUTHENTICATION TABLE
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'student', 'faculty') NOT NULL DEFAULT 'student',
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 3. FACULTY / INSTRUCTORS TABLE
CREATE TABLE faculty (
    faculty_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    department VARCHAR(100),
    phone VARCHAR(20),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- 4. STUDENTS TABLE
CREATE TABLE students (
    student_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE,
    student_number VARCHAR(20) NOT NULL UNIQUE,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    dob DATE,
    gender ENUM('Male', 'Female', 'Other'),
    phone VARCHAR(20),
    address TEXT,
    enrollment_year INT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- 5. COURSES TABLE
CREATE TABLE courses (
    course_id INT AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(20) NOT NULL UNIQUE,
    course_name VARCHAR(100) NOT NULL,
    description TEXT,
    credits INT NOT NULL DEFAULT 3,
    faculty_id INT,
    FOREIGN KEY (faculty_id) REFERENCES faculty(faculty_id) ON DELETE SET NULL
);

-- 6. ACADEMIC PERIODS / SEMESTERS
CREATE TABLE semesters (
    semester_id INT AUTO_INCREMENT PRIMARY KEY,
    semester_name VARCHAR(50) NOT NULL, -- e.g., 'Fall 2026'
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_active BOOLEAN DEFAULT FALSE
);

-- 7. ENROLLMENTS & GRADES
CREATE TABLE enrollments (
    enrollment_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    course_id INT NOT NULL,
    semester_id INT NOT NULL,
    enrollment_date DATE DEFAULT (CURRENT_DATE),
    grade VARCHAR(5) DEFAULT NULL,
    status ENUM('enrolled', 'completed', 'dropped') DEFAULT 'enrolled',
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(course_id) ON DELETE CASCADE,
    FOREIGN KEY (semester_id) REFERENCES semesters(semester_id) ON DELETE CASCADE,
    UNIQUE (student_id, course_id, semester_id)
);

-- 8. TUITION & PAYMENTS
CREATE TABLE payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    semester_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    payment_method ENUM('Credit Card', 'Bank Transfer', 'Cash', 'Online') NOT NULL,
    payment_status ENUM('Pending', 'Completed', 'Failed') DEFAULT 'Completed',
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (semester_id) REFERENCES semesters(semester_id) ON DELETE CASCADE
);

-- 9. INITIAL SEED DATA
INSERT INTO users (username, email, password_hash, role) VALUES 
('admin1', 'admin@portal.com', '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe1234567890abcdefghijklm', 'admin'),
('prof_smith', 'smith@portal.com', '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe1234567890abcdefghijklm', 'faculty'),
('student_john', 'john@portal.com', '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe1234567890abcdefghijklm', 'student');

INSERT INTO faculty (user_id, first_name, last_name, department, phone) VALUES 
(2, 'John', 'Smith', 'Computer Science', '555-0192');

INSERT INTO students (user_id, student_number, first_name, last_name, dob, gender, phone, enrollment_year) VALUES 
(3, 'STU-2026-001', 'Jane', 'Doe', '2003-05-14', 'Female', '555-0143', 2026);

INSERT INTO courses (course_code, course_name, credits, faculty_id) VALUES 
('CS101', 'Introduction to Computer Science', 3, 1),
('DB201', 'Database Management Systems', 4, 1);

INSERT INTO semesters (semester_name, start_date, end_date, is_active) VALUES 
('Fall 2026', '2026-09-01', '2026-12-20', TRUE);