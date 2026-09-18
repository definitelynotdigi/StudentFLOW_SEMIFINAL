<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php?login_error=expired");
    exit();
}

$timeout_duration = 900; // 15 minutes
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout_duration)) {
    session_unset();
    session_destroy();
    header("Location: index.php?login_error=expired");
    exit();
}
$_SESSION['last_activity'] = time();

require_once 'database.php';

// Ensure required tables exist (safety)
$pdo->exec("CREATE TABLE IF NOT EXISTS support_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    sender_role ENUM('student', 'admin') NOT NULL DEFAULT 'student',
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS faculty_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    faculty_id INT NOT NULL,
    student_id INT NOT NULL,
    admin_id INT NULL,
    admin_target_id VARCHAR(50) NOT NULL,
    submission_status VARCHAR(20) DEFAULT 'submitted',
    cleared_by_admin TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (faculty_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
)");

// Fetch complete user details
$user_id = $_SESSION['user_id'];
$user_stmt = $pdo->prepare("SELECT id, username, email, role, student_id, first_name, last_name, phone, department_course FROM users WHERE id = ?");
$user_stmt->execute([$user_id]);
$user_data = $user_stmt->fetch();

$display_name = trim(($user_data['first_name'] ?? '') . ' ' . ($user_data['last_name'] ?? ''));
if (empty($display_name)) {
    $display_name = $user_data['username'] ?? $_SESSION['username'];
}

// Convert roles to lowercase for safe comparisons
$user_role   = strtolower($user_data['role'] ?? $_SESSION['role'] ?? '');
$isAdminOrDept = in_array($user_role, ['admin', 'department']);
$isProfessor   = in_array($user_role, ['faculty', 'professor']);

// Announcements
$announcements_stmt = $pdo->query("SELECT * FROM announcements ORDER BY id DESC");
$announcements_list = $announcements_stmt->fetchAll();
$ann_count = count($announcements_list);

// Unread messages & Pending Submissions for Admin/Department
$unread_msg_count = 0;
$pending_submissions_count = 0;

if ($isAdminOrDept) {
    // 1. Unread student support messages
    $unread_stmt = $pdo->query("SELECT COUNT(*) FROM support_messages WHERE is_read = 0 AND sender_role = 'student'");
    $unread_msg_count = (int)$unread_stmt->fetchColumn();

    // 2. Pending grade submissions sent specifically to THIS Admin ID
    $current_admin_code = $user_data['student_id'] ?? '';
    $sub_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM faculty_submissions WHERE admin_target_id = ? AND cleared_by_admin = 0");
    $sub_count_stmt->execute([$current_admin_code]);
    $pending_submissions_count = (int)$sub_count_stmt->fetchColumn();
}

// Total Notifications Badge Count
$total_notifications = $ann_count + $unread_msg_count + $pending_submissions_count;

// ========== AJAX Chat Endpoints ==========
if (isset($_GET['chat_action'])) {
    header('Content-Type: application/json');
    $action = $_GET['chat_action'];

    if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $target_user_id = ($isAdminOrDept && !empty($_POST['target_user_id'])) ? intval($_POST['target_user_id']) : $_SESSION['user_id'];
        $sender_role = $isAdminOrDept ? 'admin' : 'student';
        $message = trim($_POST['message'] ?? '');

        if (!empty($message)) {
            $stmt = $pdo->prepare("INSERT INTO support_messages (user_id, sender_role, message) VALUES (?, ?, ?)");
            $stmt->execute([$target_user_id, $sender_role, $message]);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Empty message']);
        }
        exit();
    }

    if ($action === 'fetch') {
        $target_user_id = ($isAdminOrDept && !empty($_GET['target_user_id'])) ? intval($_GET['target_user_id']) : $_SESSION['user_id'];

        $stmt = $pdo->prepare("SELECT sender_role, message, DATE_FORMAT(created_at, '%h:%i %p') as time FROM support_messages WHERE user_id = ? ORDER BY created_at ASC");
        $stmt->execute([$target_user_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($isAdminOrDept && $target_user_id) {
            $update = $pdo->prepare("UPDATE support_messages SET is_read = 1 WHERE user_id = ? AND sender_role = 'student'");
            $update->execute([$target_user_id]);
        }

        echo json_encode(['status' => 'success', 'messages' => $messages]);
        exit();
    }
}

// ========== POST Actions ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // PERMANENTLY DELETE ALL RELEASED RECORDS FROM STUDENT DASHBOARD
    if (isset($_POST['clear_all_student_records'])) {
        $clear_stmt = $pdo->prepare("
            DELETE FROM academic_records 
            WHERE student_id = ? AND is_released = 1
        ");
        $clear_stmt->execute([$_SESSION['user_id']]);
        
        $_SESSION['msg'] = "All released academic records permanently deleted from the database.";
        $_SESSION['msg_type'] = "warning";
        header("Location: dashboard.php");
        exit();
    }

    // PERMANENTLY DELETE A SINGLE RECORD FROM STUDENT DASHBOARD
    if (isset($_POST['delete_student_academic_record'])) {
        $record_id = intval($_POST['record_id']);
        
        $del_stmt = $pdo->prepare("
            DELETE FROM academic_records 
            WHERE id = ? AND student_id = ? AND is_released = 1
        ");
        $del_stmt->execute([$record_id, $_SESSION['user_id']]);
        
        $_SESSION['msg'] = "Grade record permanently deleted from database.";
        $_SESSION['msg_type'] = "warning";
        header("Location: dashboard.php");
        exit();
    }

    // SEND INDIVIDUAL RECORD TO TARGET STUDENT DASHBOARD ROUTINE
    if ($isAdminOrDept && isset($_POST['send_record_target_id'])) {
        $target_student_code = trim($_POST['send_record_target_id']);
        $record_id = intval($_POST['record_id']);

        $user_lookup = $pdo->prepare("SELECT id FROM users WHERE student_id = ? OR id = ?");
        $user_lookup->execute([$target_student_code, $target_student_code]);
        $target_user = $user_lookup->fetch();

        if ($target_user) {
            $student_db_id = $target_user['id'];

            $update_rec = $pdo->prepare("
                UPDATE academic_records 
                SET student_id = ?, is_released = 1, cleared_by_student = 0 
                WHERE student_id = ? OR id = ?
            ");
            $update_rec->execute([$student_db_id, $student_db_id, $record_id]);

            echo json_encode(['status' => 'success', 'message' => 'Grades released to student dashboard.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Student ID not found.']);
        }
        exit();
    }
    
    // PROFESSOR GRADE SUBMISSION ROUTINE (ENCODE GRADE)
    if ($isProfessor && isset($_POST['submit_prof_grades'])) {
        $input_student_id = trim($_POST['student_id_code']);
        $student_fullname = trim($_POST['student_fullname']);
        $subject_code     = trim($_POST['subject_code']);
        $semester         = trim($_POST['semester']);
        $academic_year    = trim($_POST['academic_year']);
        
        $attendance   = floatval($_POST['attendance']);
        $quizzes      = floatval($_POST['quizzes']);
        $prelim       = floatval($_POST['prelim_exam']);
        $midterm      = floatval($_POST['midterm_exam']);
        $final        = floatval($_POST['final_exam']);

        $user_lookup = $pdo->prepare("SELECT id FROM users WHERE student_id = ? OR id = ?");
        $user_lookup->execute([$input_student_id, $input_student_id]);
        $target_user = $user_lookup->fetch();

        if ($target_user) {
            $student_db_id = $target_user['id'];

            $final_score = ($attendance * 0.10) + ($quizzes * 0.20) + ($prelim * 0.20) + ($midterm * 0.25) + ($final * 0.25);

            if ($attendance <= 5.0 && $quizzes <= 5.0 && $prelim <= 5.0 && $midterm <= 5.0 && $final <= 5.0) {
                $final_grade = $final_score;
            } else {
                if ($final_score >= 97)      { $final_grade = 1.00; }
                elseif ($final_score >= 94) { $final_grade = 1.25; }
                elseif ($final_score >= 91) { $final_grade = 1.50; }
                elseif ($final_score >= 88) { $final_grade = 1.75; }
                elseif ($final_score >= 85) { $final_grade = 2.00; }
                elseif ($final_score >= 82) { $final_grade = 2.25; }
                elseif ($final_score >= 79) { $final_grade = 2.50; }
                elseif ($final_score >= 76) { $final_grade = 2.75; }
                elseif ($final_score >= 75) { $final_grade = 3.00; }
                elseif ($final_score >= 70) { $final_grade = 4.00; }
                else                        { $final_grade = 5.00; }
            }

            $check_stmt = $pdo->prepare("SELECT id FROM academic_records WHERE student_id = ? AND subject_code = ?");
            $check_stmt->execute([$student_db_id, $subject_code]);
            $existing = $check_stmt->fetch();

            if ($existing) {
                $update_stmt = $pdo->prepare("
                    UPDATE academic_records 
                    SET grade = ?, semester = ?, academic_year = ?, faculty_id = ?, cleared_by_faculty = 0, cleared_by_student = 0 
                    WHERE id = ?
                ");
                $update_stmt->execute([$final_grade, $semester, $academic_year, $_SESSION['user_id'], $existing['id']]);
            } else {
                $insert_stmt = $pdo->prepare("
                    INSERT INTO academic_records (faculty_id, student_id, subject_code, subject_title, grade, semester, academic_year, cleared_by_faculty, cleared_by_student) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0)
                ");
                $insert_stmt->execute([$_SESSION['user_id'], $student_db_id, $subject_code, $subject_code, $final_grade, $semester, $academic_year]);
            }

            // NOTE: Automatic admin view reset removed here to prevent pre-submission visibility.

            $_SESSION['msg'] = "Grade record (" . number_format($final_grade, 2) . ") saved successfully for student " . htmlspecialchars($student_fullname) . ".";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['msg'] = "Error: Student ID (" . htmlspecialchars($input_student_id) . ") not found in system records.";
            $_SESSION['msg_type'] = "danger";
        }
        header("Location: dashboard.php");
        exit();
    }

    // CLEAR FACULTY VIEW ONLY (SYSTEM SUBMITTED RECORDS BOX CLEAR)
    if ($isProfessor && isset($_POST['clear_all_records'])) {
        $clear_stmt = $pdo->prepare("
            UPDATE academic_records 
            SET cleared_by_faculty = 1 
            WHERE faculty_id = ?
        ");
        $clear_stmt->execute([$_SESSION['user_id']]);

        $_SESSION['msg'] = "Your submitted records have been cleared from your local view.";
        $_SESSION['msg_type'] = "warning";
        header("Location: dashboard.php");
        exit();
    }

    // PROFESSOR DELETE SINGLE RECORD ROUTINE
    if ($isProfessor && isset($_POST['delete_single_record'])) {
        $record_id = intval($_POST['record_id']);

        $delete_stmt = $pdo->prepare("
            UPDATE academic_records 
            SET cleared_by_faculty = 1 
            WHERE id = ? AND faculty_id = ?
        ");
        $delete_stmt->execute([$record_id, $_SESSION['user_id']]);

        $_SESSION['msg'] = "Grade record deleted successfully.";
        $_SESSION['msg_type'] = "warning";
        header("Location: dashboard.php");
        exit();
    }

    // PROFESSOR EDIT SINGLE RECORD ROUTINE
    if ($isProfessor && isset($_POST['update_single_record'])) {
        $record_id        = intval($_POST['record_id']);
        $student_id_code  = trim($_POST['student_id_code']);
        $student_fullname = trim($_POST['student_fullname']);
        $subject_code     = trim($_POST['subject_code']);
        $semester         = trim($_POST['semester']);
        $academic_year    = trim($_POST['academic_year']);

        $attendance   = floatval($_POST['edit_attendance']);
        $quizzes      = floatval($_POST['edit_quizzes']);
        $prelim       = floatval($_POST['edit_prelim_exam']);
        $midterm      = floatval($_POST['edit_midterm_exam']);
        $final        = floatval($_POST['edit_final_exam']);

        $user_lookup = $pdo->prepare("SELECT id FROM users WHERE student_id = ? OR id = ?");
        $user_lookup->execute([$student_id_code, $student_id_code]);
        $target_user = $user_lookup->fetch();

        if ($target_user) {
            $student_db_id = $target_user['id'];
            $final_score = ($attendance * 0.10) + ($quizzes * 0.20) + ($prelim * 0.20) + ($midterm * 0.25) + ($final * 0.25);

            if ($attendance <= 5.0 && $quizzes <= 5.0 && $prelim <= 5.0 && $midterm <= 5.0 && $final <= 5.0) {
                $new_grade = $final_score;
            } else {
                if ($final_score >= 97)      { $new_grade = 1.00; }
                elseif ($final_score >= 94) { $new_grade = 1.25; }
                elseif ($final_score >= 91) { $new_grade = 1.50; }
                elseif ($final_score >= 88) { $new_grade = 1.75; }
                elseif ($final_score >= 85) { $new_grade = 2.00; }
                elseif ($final_score >= 82) { $new_grade = 2.25; }
                elseif ($final_score >= 79) { $new_grade = 2.50; }
                elseif ($final_score >= 76) { $new_grade = 2.75; }
                elseif ($final_score >= 75) { $new_grade = 3.00; }
                elseif ($final_score >= 70) { $new_grade = 4.00; }
                else                        { $new_grade = 5.00; }
            }

            $update_stmt = $pdo->prepare("
                UPDATE academic_records 
                SET student_id = ?, subject_code = ?, subject_title = ?, grade = ?, semester = ?, academic_year = ? 
                WHERE id = ? AND faculty_id = ?
            ");
            $update_stmt->execute([$student_db_id, $subject_code, $subject_code, $new_grade, $semester, $academic_year, $record_id, $_SESSION['user_id']]);

            $_SESSION['msg'] = "Grade record updated successfully.";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['msg'] = "Error: Student ID (" . htmlspecialchars($student_id_code) . ") not found.";
            $_SESSION['msg_type'] = "danger";
        }
        header("Location: dashboard.php");
        exit();
    }

    // ROUTE ALL RECORDS TO ADMIN ID ROUTINE
    if ($isProfessor && isset($_POST['send_all_grades_to_admin'])) {
        $admin_id_code = trim($_POST['admin_id']);

        if (!empty($admin_id_code)) {
            $admin_lookup = $pdo->prepare("SELECT id FROM users WHERE student_id = ? OR username = ?");
            $admin_lookup->execute([$admin_id_code, $admin_id_code]);
            $target_admin = $admin_lookup->fetch();

            if ($target_admin) {
                $admin_db_id = $target_admin['id'];

                $rec_stmt = $pdo->prepare("
                    SELECT DISTINCT ar.student_id, u.student_id as stud_code, u.first_name, u.last_name 
                    FROM academic_records ar 
                    JOIN users u ON ar.student_id = u.id 
                    WHERE ar.faculty_id = ?
                ");
                $rec_stmt->execute([$_SESSION['user_id']]);
                $students_to_submit = $rec_stmt->fetchAll();

                if (count($students_to_submit) > 0) {
                    $count = 0;
                    foreach ($students_to_submit as $st) {
                        $sub_check = $pdo->prepare("SELECT id FROM faculty_submissions WHERE faculty_id = ? AND student_id = ? AND (admin_id = ? OR admin_target_id = ?)");
                        $sub_check->execute([$_SESSION['user_id'], $st['student_id'], $admin_db_id, $admin_id_code]);
                        
                        if (!$sub_check->fetch()) {
                            $ins_sub = $pdo->prepare("
                                INSERT INTO faculty_submissions (faculty_id, student_id, admin_id, admin_target_id, submission_status, cleared_by_admin) 
                                VALUES (?, ?, ?, ?, 'submitted', 0)
                            ");
                            $ins_sub->execute([$_SESSION['user_id'], $st['student_id'], $admin_db_id, $admin_id_code]);
                        } else {
                            $reset_sub = $pdo->prepare("
                                UPDATE faculty_submissions 
                                SET cleared_by_admin = 0, submission_status = 'submitted', admin_id = ?, admin_target_id = ? 
                                WHERE faculty_id = ? AND student_id = ?
                            ");
                            $reset_sub->execute([$admin_db_id, $admin_id_code, $_SESSION['user_id'], $st['student_id']]);
                        }

                        $student_fullname = trim($st['first_name'] . ' ' . $st['last_name']);
                        $admin_msg = "Faculty member submitted grade records for Student: {$student_fullname} ({$st['stud_code']}) | Routed to Admin ID: {$admin_id_code}";
                        
                        $log_stmt = $pdo->prepare("INSERT INTO announcements (title, content, posted_by) VALUES (?, ?, ?)");
                        $log_stmt->execute(["[GRADE SUBMISSION REVIEW] " . $st['stud_code'], $admin_msg, "Prof. " . ($user_data['last_name'] ?? 'Faculty')]);
                        $count++;
                    }
                    $_SESSION['msg'] = "Successfully routed grade records for {$count} student(s) to Target Admin ID: " . htmlspecialchars($admin_id_code);
                    $_SESSION['msg_type'] = "success";
                } else {
                    $_SESSION['msg'] = "Error: No active grade records found to send.";
                    $_SESSION['msg_type'] = "danger";
                }
            } else {
                $_SESSION['msg'] = "Error: Target Admin ID (" . htmlspecialchars($admin_id_code) . ") not found.";
                $_SESSION['msg_type'] = "danger";
            }
        } else {
            $_SESSION['msg'] = "Error: Please provide a valid Target Admin ID.";
            $_SESSION['msg_type'] = "danger";
        }
        header("Location: dashboard.php");
        exit();
    }

    // ADMIN / DEPARTMENT ACTIONS
    if ($isAdminOrDept) {
        if (isset($_POST['post_announcement'])) {
            $stmt = $pdo->prepare("INSERT INTO announcements (title, content, posted_by) VALUES (?, ?, ?)");
            $stmt->execute([$_POST['title'], $_POST['content'], $_SESSION['username']]);
            $_SESSION['msg'] = "Announcement posted successfully.";
            $_SESSION['msg_type'] = "success";
            header("Location: dashboard.php");
            exit();
        }

        if (isset($_POST['edit_announcement'])) {
            $stmt = $pdo->prepare("UPDATE announcements SET title = ?, content = ? WHERE id = ?");
            $stmt->execute([$_POST['ann_title'], $_POST['ann_content'], $_POST['ann_id']]);
            $_SESSION['msg'] = "Announcement updated successfully.";
            $_SESSION['msg_type'] = "success";
            header("Location: dashboard.php");
            exit();
        }

        if (isset($_POST['delete_announcement'])) {
            $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
            $stmt->execute([$_POST['ann_id']]);
            $_SESSION['msg'] = "Announcement deleted successfully.";
            $_SESSION['msg_type'] = "warning";
            header("Location: dashboard.php");
            exit();
        }

        if (isset($_POST['delete_faculty_submission'])) {
    $sub_id = intval($_POST['submission_id']);

    // Delete ONLY the submission review row so admin view is cleared,
    // while keeping academic_records intact for student dashboard viewing.
    $del_sub = $pdo->prepare("DELETE FROM faculty_submissions WHERE id = ?");
    $del_sub->execute([$sub_id]);

    $_SESSION['msg'] = "Submission cleared from Admin Dashboard.";
    $_SESSION['msg_type'] = "warning";

    header("Location: dashboard.php");
    exit();
}
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GRC Portal | Dashboard</title>
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        :root {
            --grc-primary: #800000;
            --grc-secondary: #ab0a0a;
            --grc-hover: #ff1e1e;
            --grc-dark: #120202;
            --grc-card-bg: #ffffff;
            --grc-bg: #f8fafc;
            --grc-border: #e2e8f0;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--grc-bg);
            color: #1e293b;
        }

        .navbar-grc {
            background: linear-gradient(135deg, var(--grc-dark) 0%, #3a0000 100%);
            border-bottom: 3px solid var(--grc-hover);
            backdrop-filter: blur(10px);
        }

        .dashboard-hero {
            background: linear-gradient(135deg, var(--grc-dark) 0%, var(--grc-primary) 60%, var(--grc-secondary) 100%);
            border-radius: 20px;
            color: #ffffff;
            padding: 2rem 2.5rem;
            box-shadow: 0 20px 25px -5px rgba(128, 0, 0, 0.15), 0 8px 10px -6px rgba(128, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .dashboard-hero::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 350px;
            height: 350px;
            background: rgba(255, 255, 255, 0.04);
            border-radius: 50%;
            pointer-events: none;
        }

        .stat-card-modern {
            background: #ffffff;
            border: 1px solid var(--grc-border);
            border-radius: 18px;
            padding: 1.5rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03);
            height: 100%;
        }

        .stat-card-modern:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px -3px rgba(128, 0, 0, 0.12);
            border-color: rgba(171, 10, 10, 0.3);
        }

        .icon-wrapper-gradient {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            background: linear-gradient(135deg, rgba(171, 10, 10, 0.1) 0%, rgba(128, 0, 0, 0.2) 100%);
            color: var(--grc-secondary);
        }

        .card-custom {
            background: #ffffff;
            border: 1px solid var(--grc-border);
            border-radius: 18px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02);
            transition: all 0.25s ease;
        }

        .card-custom:hover {
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
        }

        .table-container-modern {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid var(--grc-border);
            padding: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02);
        }

        .table-modern {
            margin-bottom: 0;
            vertical-align: middle;
        }

        .table-modern thead th {
            background: #f8fafc;
            text-transform: uppercase;
            font-size: 0.725rem;
            letter-spacing: 0.06em;
            color: #64748b;
            font-weight: 700;
            border-bottom: 1px solid var(--grc-border);
            padding: 1rem;
        }

        .table-modern tbody td {
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.9rem;
        }

        .btn-grc-action {
            background: linear-gradient(135deg, var(--grc-secondary) 0%, var(--grc-primary) 100%);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .btn-grc-action:hover {
            background: var(--grc-hover);
            color: #ffffff;
            box-shadow: 0 6px 16px rgba(255, 30, 30, 0.3);
            transform: translateY(-1px);
        }

        .bell-btn {
            position: relative;
            background: rgba(255, 255, 255, 0.12);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .bell-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            color: #fff;
        }

        .bell-badge {
            position: absolute;
            top: -3px;
            right: -3px;
            font-size: 0.65rem;
            padding: 3px 6px;
            border-radius: 10px;
            border: 2px solid var(--grc-dark);
        }

        .announcement-item {
            border-left: 4px solid var(--grc-secondary);
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .announcement-item:hover {
            background-color: #f1f5f9 !important;
        }

        .glass-chat-box {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        .floating-toast-container {
            position: fixed;
            top: 25px;
            right: 25px;
            z-index: 10000;
            min-width: 320px;
            max-width: 450px;
            background-color: #ffffff;
            border-left: 6px solid var(--grc-primary);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            border-radius: 12px;
            animation: slideInRight 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
        }

        .floating-toast-container.alert-success {
            border-left-color: #198754;
        }

        .floating-toast-container.alert-danger {
            border-left-color: #dc3545;
        }

        .floating-toast-container.alert-warning {
            border-left-color: #ffc107;
        }

        .floating-toast-container.alert-info {
            border-left-color: #0dcaf0;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(120%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @media print {
            body * {
                visibility: hidden;
            }
            #printableModalContent, #printableModalContent * {
                visibility: visible;
            }
            #printableModalContent {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
            .modal-backdrop {
                display: none !important;
            }
        }
    </style>
</head>
<body>

<!-- GLOBAL FLOATING TOAST NOTIFICATION CONTAINER -->
<?php if (isset($_SESSION['msg']) && !empty($_SESSION['msg'])): ?>
    <div class="floating-toast-container alert alert-<?= htmlspecialchars($_SESSION['msg_type'] ?? 'info') ?> alert-dismissible fade show d-flex align-items-center justify-content-between p-3" role="alert">
        <div class="d-flex align-items-center gap-2 me-3">
            <i class="fa-solid <?= ($_SESSION['msg_type'] ?? '') === 'success' ? 'fa-circle-check text-success' : (($_SESSION['msg_type'] ?? '') === 'danger' ? 'fa-circle-xmark text-danger' : 'fa-circle-exclamation text-warning') ?> fs-4"></i>
            <span class="fw-semibold text-dark small"><?= htmlspecialchars($_SESSION['msg']) ?></span>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php 
        unset($_SESSION['msg']); 
        unset($_SESSION['msg_type']); 
    ?>
<?php endif; ?>

<!-- ===================== TOP NAVBAR ===================== -->
<nav class="navbar navbar-grc navbar-dark navbar-expand-lg p-3 shadow-sm sticky-top">
    <div class="container-fluid px-lg-4">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="dashboard.php">
            <img src="assets/grc2.png" alt="GRC Logo" style="height: 34px; width: auto;">
        </a>

        <div class="d-flex align-items-center gap-3">
            <button class="bell-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#announcementsDrawer" title="Notifications">
                <i class="fa-solid fa-bell fs-6"></i>
                <?php if ($total_notifications > 0): ?>
                    <span class="badge bg-danger bell-badge"><?= $total_notifications ?></span>
                <?php endif; ?>
            </button>

            <div class="dropdown">
                <button class="btn btn-outline-light btn-sm dropdown-toggle d-flex align-items-center gap-2 rounded-pill px-3 py-2 border-white-50" type="button" data-bs-toggle="dropdown">
                    <i class="fa-solid fa-circle-user fs-5"></i>
                    <span class="d-none d-md-inline fw-semibold"><?= htmlspecialchars($display_name) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mt-2">
                    <li><h6 class="dropdown-header text-muted text-uppercase small fw-bold"><?= htmlspecialchars($user_data['role'] ?? 'User') ?> Profile</h6></li>
                    <li>
                        <a class="dropdown-item py-2" href="#" data-bs-toggle="modal" data-bs-target="#profileModal">
                            <i class="fa-solid fa-id-card me-2 text-danger"></i> My Profile
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item py-2 text-danger fw-semibold" href="logout.php">
                            <i class="fa-solid fa-right-from-bracket me-2"></i> Secure Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<!-- ===================== MAIN DASHBOARD BODY ===================== -->
<div class="container-fluid px-lg-5 py-4">

    <!-- Dashboard Hero Header -->
    <div class="dashboard-hero mb-4 d-flex justify-content-between align-items-center">
        <div>
            <span class="badge bg-white text-danger fw-bold rounded-pill px-3 py-1 mb-2 shadow-sm">
                <?= htmlspecialchars($user_data['role']) ?> Module
            </span>
            <h2 class="fw-extrabold mb-1">Welcome back, <?= htmlspecialchars($display_name) ?>!</h2>
            <p class="mb-0 text-white-50 small">
                ID: <?= htmlspecialchars($user_data['student_id'] ?? 'N/A') ?> · Department: <?= htmlspecialchars($user_data['department_course'] ?? 'General') ?>
            </p>
        </div>
        <div class="text-end d-none d-md-block">
            <h6 class="mb-0 text-white-50 small text-uppercase fw-semibold"><?= date('l') ?></h6>
            <h3 class="fw-bold mb-0"><?= date('F j, Y') ?></h3>
        </div>
    </div>

    <?php if ($isProfessor): ?>
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card-custom p-4">
                    <h5 class="fw-bold text-dark mb-4"><i class="fa-solid fa-pen-to-square me-2 text-danger"></i>Encode Student Grade Sheet</h5>
                    
                    <form action="dashboard.php" method="POST" id="profGradingForm">
                        <input type="hidden" name="submit_prof_grades" value="1">

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted">Student ID</label>
                                <input type="text" name="student_id_code" class="form-control" placeholder="e.g. 2025-01-001" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted">Full Name of Student</label>
                                <input type="text" name="student_fullname" class="form-control" placeholder="e.g. Default Student" required>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-12">
                                <label class="form-label fw-semibold small text-muted">Subject Code</label>
                                <select name="subject_code" class="form-select" required>
                                    <option value="" disabled selected>-- Choose Subject --</option>
                                    <option value="SIA2">SIA2</option>
                                    <option value="SYSARC1">SYSARC1</option>
                                    <option value="PROFLEC2">PROFLEC2</option>
                                    <option value="BMC">BMC</option>
                                    <option value="SYSARC2">SYSARC2</option>
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted">Semester</label>
                                <select name="semester" class="form-select" required>
                                    <option value="1st Semester">1st Semester</option>
                                    <option value="2nd Semester">2nd Semester</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted">Academic Year</label>
                                <input type="text" name="academic_year" class="form-control" value="2025-2026" required>
                            </div>
                        </div>

                        <hr class="my-4 text-muted">
                        <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-calculator me-2 text-danger"></i>Grade Breakdown Scores (0 - 100 Scale)</h6>

                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Attendance (10%)</label>
                                <input type="number" step="0.01" min="0" max="100" name="attendance" class="form-control grade-input" required oninput="calculatePreview()">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Quizzes (20%)</label>
                                <input type="number" step="0.01" min="0" max="100" name="quizzes" class="form-control grade-input" required oninput="calculatePreview()">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Prelim Exam (20%)</label>
                                <input type="number" step="0.01" min="0" max="100" name="prelim_exam" class="form-control grade-input" required oninput="calculatePreview()">
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Midterm Exam (25%)</label>
                                <input type="number" step="0.01" min="0" max="100" name="midterm_exam" class="form-control grade-input" required oninput="calculatePreview()">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Final Exam (25%)</label>
                                <input type="number" step="0.01" min="0" max="100" name="final_exam" class="form-control grade-input" required oninput="calculatePreview()">
                            </div>
                        </div>

                        <div class="p-3 bg-light rounded-3 border mb-4 d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-muted small fw-semibold d-block">Calculated Transmuted Grade:</span>
                                <h3 class="fw-bold text-success mb-0" id="gradePreview">—</h3>
                            </div>
                            <div class="text-end">
                                <span class="text-muted small fw-semibold d-block">Weighted Score Total:</span>
                                <h4 class="fw-bold text-dark mb-0" id="scorePreview">0.00%</h4>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary w-50 py-3 fw-bold" onclick="clearGradeForm()">
                                <i class="fa-solid fa-rotate-left me-2"></i>Clear Form
                            </button>
                            <button type="submit" class="btn btn-grc-action w-50 py-3 fw-bold">
                                <i class="fa-solid fa-floppy-disk me-2"></i>Save Grade Record
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card-custom p-4 h-100 d-flex flex-column justify-content-between">
                    <div>
                        <!-- Header with Title and Clear Button -->
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <h5 class="fw-bold text-dark mb-0 fs-6">
                                <i class="fa-solid fa-list-check me-2 text-danger"></i>System Submitted Records
                            </h5>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to clear your local submitted records view?');" class="m-0">
                                <input type="hidden" name="clear_all_records" value="1">
                                <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-2 py-1 small" style="font-size: 0.75rem;">
                                    <i class="fa-solid fa-trash-can me-1"></i>Clear
                                </button>
                            </form>
                        </div>
                        
                        <?php
                        $recent_stmt = $pdo->prepare("
                            SELECT 
                                ar.id,
                                ar.subject_code, 
                                ar.grade, 
                                ar.semester,
                                ar.academic_year,
                                u.student_id,
                                CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS student_fullname,
                                (SELECT AVG(ar_sub.grade) FROM academic_records ar_sub WHERE ar_sub.student_id = u.id) as student_gwa
                            FROM academic_records ar 
                            JOIN users u ON ar.student_id = u.id 
                            WHERE ar.faculty_id = ? 
                              AND (ar.cleared_by_faculty = 0 OR ar.cleared_by_faculty IS NULL)
                            ORDER BY ar.id DESC 
                            LIMIT 6
                        ");
                        $recent_stmt->execute([$_SESSION['user_id']]);
                        $recent_records = $recent_stmt->fetchAll();

                        $overall_gwa_stmt = $pdo->prepare("
                            SELECT AVG(grade) as overall_gwa 
                            FROM academic_records 
                            WHERE faculty_id = ? 
                              AND (cleared_by_faculty = 0 OR cleared_by_faculty IS NULL)
                        ");
                        $overall_gwa_stmt->execute([$_SESSION['user_id']]);
                        $overall_gwa_val = $overall_gwa_stmt->fetchColumn();

                        $overall_gwa = ($overall_gwa_val !== false && $overall_gwa_val !== null) 
                            ? number_format((float)$overall_gwa_val, 2) 
                            : '0.00';
                        ?>

                        <div class="p-3 bg-light rounded-3 border mb-3 d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-dark small"><i class="fa-solid fa-chart-line text-danger me-1"></i> Overall System GWA:</span>
                            <span class="badge bg-success fs-6 fw-bold"><?= $overall_gwa ?></span>
                        </div>

                        <!-- Responsive Table Container -->
                        <div class="table-responsive mb-3" style="max-height: 280px; overflow-y: auto;">
                            <table class="table table-hover align-middle small mb-0">
                                <thead class="table-dark sticky-top">
                                    <tr>
                                        <th>Subject / ID</th>
                                        <th class="text-center">Grade / GWA</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($recent_records) > 0): ?>
                                        <?php foreach ($recent_records as $rec): ?>
                                            <tr>
                                                <td class="text-break">
                                                    <span class="fw-bold text-dark"><?= htmlspecialchars($rec['subject_code']) ?></span><br>
                                                    <small class="text-muted"><?= htmlspecialchars($rec['student_id']) ?></small>
                                                </td>
                                                <td class="text-center text-nowrap">
                                                    <span class="fw-bold text-success"><?= number_format($rec['grade'], 2) ?></span><br>
                                                    <small class="text-primary fw-semibold">GWA: <?= number_format($rec['student_gwa'], 2) ?></small>
                                                </td>
                                                <td class="text-end text-nowrap">
                                                    <button type="button" 
                                                        class="btn btn-sm btn-outline-primary border-0 rounded-circle" 
                                                        title="Edit Grade"
                                                        onclick="openEditModal(
                                                            <?= $rec['id'] ?>, 
                                                            '<?= htmlspecialchars($rec['student_id'], ENT_QUOTES) ?>', 
                                                            '<?= htmlspecialchars(trim($rec['student_fullname']), ENT_QUOTES) ?>', 
                                                            '<?= htmlspecialchars($rec['subject_code'], ENT_QUOTES) ?>', 
                                                            '<?= htmlspecialchars($rec['semester'], ENT_QUOTES) ?>', 
                                                            '<?= htmlspecialchars($rec['academic_year'], ENT_QUOTES) ?>', 
                                                            '<?= number_format($rec['grade'], 2) ?>'
                                                        )">
                                                        <i class="fa-solid fa-pen-to-square"></i>
                                                    </button>

                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this grade record?');">
                                                        <input type="hidden" name="delete_single_record" value="1">
                                                        <input type="hidden" name="record_id" value="<?= $rec['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger border-0 rounded-circle" title="Delete Record">
                                                            <i class="fa-solid fa-trash-can"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="3" class="text-center text-muted py-3">No submitted grades found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- EDIT GRADE MODAL -->
                        <div class="modal fade" id="editGradeModal" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered modal-lg">
                                <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                                    <div class="modal-header text-white" style="background: var(--grc-dark);">
                                        <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2 text-danger"></i>Edit Grade Entry</h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST">
                                        <input type="hidden" name="update_single_record" value="1">
                                        <input type="hidden" name="record_id" id="editRecordId">
                                        
                                        <div class="modal-body p-4">
                                            <div class="row g-3 mb-3">
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold text-muted">Student ID</label>
                                                    <input type="text" name="student_id_code" id="editStudentId" class="form-control" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold text-muted">Student Full Name</label>
                                                    <input type="text" name="student_fullname" id="editStudentFullname" class="form-control" required>
                                                </div>
                                            </div>

                                            <div class="row g-3 mb-3">
                                                <div class="col-md-12">
                                                    <label class="form-label small fw-semibold text-muted">Subject Code</label>
                                                    <select name="subject_code" id="editSubjectCode" class="form-select" required>
                                                        <option value="SIA2">SIA2</option>
                                                        <option value="SYSARC1">SYSARC1</option>
                                                        <option value="PROFLEC2">PROFLEC2</option>
                                                        <option value="BMC">BMC</option>
                                                        <option value="SYSARC2">SYSARC2</option>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="row g-3 mb-3">
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold text-muted">Semester</label>
                                                    <select name="semester" id="editSemester" class="form-select" required>
                                                        <option value="1st Semester">1st Semester</option>
                                                        <option value="2nd Semester">2nd Semester</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold text-muted">Academic Year</label>
                                                    <input type="text" name="academic_year" id="editAcademicYear" class="form-control" required>
                                                </div>
                                            </div>

                                            <hr class="my-4 text-muted">
                                            <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-calculator me-2 text-danger"></i>Grade Breakdown Scores (0 - 100 Scale)</h6>

                                            <div class="row g-3 mb-3">
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold">Attendance (10%)</label>
                                                    <input type="number" step="0.01" min="0" max="100" name="edit_attendance" id="editAttendance" class="form-control edit-grade-input" required oninput="calculateEditPreview()">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold">Quizzes (20%)</label>
                                                    <input type="number" step="0.01" min="0" max="100" name="edit_quizzes" id="editQuizzes" class="form-control edit-grade-input" required oninput="calculateEditPreview()">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold">Prelim Exam (20%)</label>
                                                    <input type="number" step="0.01" min="0" max="100" name="edit_prelim_exam" id="editPrelim" class="form-control edit-grade-input" required oninput="calculateEditPreview()">
                                                </div>
                                            </div>

                                            <div class="row g-3 mb-4">
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold">Midterm Exam (25%)</label>
                                                    <input type="number" step="0.01" min="0" max="100" name="edit_midterm_exam" id="editMidterm" class="form-control edit-grade-input" required oninput="calculateEditPreview()">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold">Final Exam (25%)</label>
                                                    <input type="number" step="0.01" min="0" max="100" name="edit_final_exam" id="editFinal" class="form-control edit-grade-input" required oninput="calculateEditPreview()">
                                                </div>
                                            </div>

                                            <div class="p-3 bg-light rounded-3 border mb-3 d-flex justify-content-between align-items-center">
                                                <div>
                                                    <span class="text-muted small fw-semibold d-block">Calculated Transmuted Grade:</span>
                                                    <h3 class="fw-bold text-success mb-0" id="editGradePreview">—</h3>
                                                </div>
                                                <div class="text-end">
                                                    <span class="text-muted small fw-semibold d-block">Weighted Score Total:</span>
                                                    <h4 class="fw-bold text-dark mb-0" id="editScorePreview">0.00%</h4>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer bg-light">
                                            <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-grc-action btn-sm rounded-pill px-4">Update Grade Record</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-auto pt-3 border-top">
                        <form method="POST" class="mb-0">
                            <input type="hidden" name="send_all_grades_to_admin" value="1">
                            <div class="mb-2">
                                <label class="form-label small fw-semibold text-muted">Target Admin ID</label>
                                <input type="text" name="admin_id" class="form-control form-control-sm" placeholder="e.g. ADMIN-001" required>
                            </div>
                            <button type="submit" class="btn btn-grc-action w-100 py-2 fw-bold btn-sm">
                                <i class="fa-solid fa-paper-plane me-1"></i> Send All Records to Admin
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($_SESSION['role'] === 'Student'): ?>
        <!-- STUDENT DASHBOARD VIEW -->
        <?php
        $balance_stmt = $pdo->prepare("SELECT amount, transaction_type FROM financial_ledgers WHERE student_id = ?");
        $balance_stmt->execute([$_SESSION['user_id']]);
        $ledger_data = $balance_stmt->fetchAll();
        $balance = 0.00;
        foreach ($ledger_data as $item) {
            if ($item['transaction_type'] === 'Charge') {
                $balance += floatval($item['amount']);
            } else {
                $balance -= floatval($item['amount']);
            }
        }

        $gwa_stmt = $pdo->prepare("
            SELECT AVG(grade) as gwa, COUNT(*) as total 
            FROM academic_records 
            WHERE student_id = ? 
              AND is_released = 1 
              AND (cleared_by_student = 0 OR cleared_by_student IS NULL)
        ");
        $gwa_stmt->execute([$_SESSION['user_id']]);
        $gwa_data = $gwa_stmt->fetch();
        $gwa = $gwa_data['gwa'] ? number_format($gwa_data['gwa'], 2) : '—';
        $subject_count = (int)($gwa_data['total'] ?? 0);
        ?>

        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="stat-card-modern d-flex align-items-center">
                    <div class="icon-wrapper-gradient me-3">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                    <div>
                        <span class="text-uppercase text-muted fw-bold small" style="font-size: 0.7rem; letter-spacing: 0.05em;">General Average</span>
                        <h2 class="fw-bold text-dark mb-0"><?= $gwa ?></h2>
                        <small class="text-success fw-semibold"><i class="fa-solid fa-book-bookmark me-1"></i><?= $subject_count ?> Subjects Enrolled</small>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="stat-card-modern d-flex align-items-center">
                    <div class="icon-wrapper-gradient me-3" style="background: rgba(234, 179, 8, 0.15); color: #ca8a04;">
                        <i class="fa-solid fa-wallet"></i>
                    </div>
                    <div>
                        <span class="text-uppercase text-muted fw-bold small" style="font-size: 0.7rem; letter-spacing: 0.05em;">Account Balance</span>
                        <h2 class="fw-bold mb-0 <?= $balance > 0 ? 'text-danger' : 'text-success' ?>">
                            ₱<?= number_format($balance, 2) ?>
                        </h2>
                        <small class="text-muted"><?= $balance > 0 ? 'Pending Settlement' : 'Account Cleared' ?></small>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="stat-card-modern d-flex align-items-center">
                    <div class="icon-wrapper-gradient me-3" style="background: rgba(59, 130, 246, 0.15); color: #2563eb;">
                        <i class="fa-solid fa-bullhorn"></i>
                    </div>
                    <div>
                        <span class="text-uppercase text-muted fw-bold small" style="font-size: 0.7rem; letter-spacing: 0.05em;">Active Bulletins</span>
                        <h2 class="fw-bold text-dark mb-0"><?= $ann_count ?></h2>
                        <small class="text-primary fw-semibold"><i class="fa-solid fa-bell me-1"></i>Campus Announcements</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="table-container-modern mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-3">
                            <h5 class="fw-bold text-dark mb-0">
                                <i class="fa-solid fa-book-open me-2 text-danger"></i>Academic Records
                            </h5>
                            <span class="badge bg-success fs-6 fw-bold">GWA: <?= $gwa ?></span>
                        </div>
                        
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary fw-semibold" onclick="window.print()" title="Print All Records">
                                <i class="fa-solid fa-print me-1"></i> Print
                            </button>
                            
                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to clear all grade records from your view?');">
                                <input type="hidden" name="clear_all_student_records" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-danger fw-semibold" title="Clear All Records">
                                    <i class="fa-solid fa-trash-can me-1"></i> Clear
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-modern align-middle">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Subject Title</th>
                                    <th>Semester</th>
                                    <th class="text-center">Grade</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $grades_stmt = $pdo->prepare("
                                    SELECT * FROM academic_records
                                    WHERE student_id = ?
                                      AND is_released = 1
                                      AND (cleared_by_student = 0 OR cleared_by_student IS NULL)
                                    ORDER BY id DESC
                                ");
                                $grades_stmt->execute([$_SESSION['user_id']]);
                                $grades = $grades_stmt->fetchAll();

                                if (count($grades) > 0):
                                    foreach ($grades as $g):
                                ?>
                                    <tr>
                                        <td><span class="badge bg-light text-dark border fw-bold"><?= htmlspecialchars($g['subject_code']) ?></span></td>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($g['subject_title']) ?></td>
                                        <td><small class="text-muted"><?= htmlspecialchars($g['semester']) ?></small></td>
                                        <td class="text-center fw-bold text-success fs-6"><?= number_format($g['grade'], 2) ?></td>
                                    </tr>
                                <?php 
                                    endforeach; 
                                else: 
                                ?>
                                    <tr><td colspan="4" class="text-center text-muted py-4">No academic records released yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="table-container-modern">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-file-invoice-dollar me-2 text-danger"></i>Financial Ledger</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-modern">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th>Type</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $ledger_stmt = $pdo->prepare("SELECT * FROM financial_ledgers WHERE student_id = ? ORDER BY transaction_date ASC, id ASC");
                                $ledger_stmt->execute([$_SESSION['user_id']]);
                                $ledger_res = $ledger_stmt->fetchAll();
                                $total_balance = 0.00;
                                if (count($ledger_res) > 0):
                                    foreach ($ledger_res as $l_row):
                                        $amt = floatval($l_row['amount']);
                                        $type = $l_row['transaction_type'];
                                        if ($type === 'Charge') {
                                            $total_balance += $amt;
                                            $badge_class = 'bg-danger-subtle text-danger border border-danger-subtle';
                                        } else {
                                            $total_balance -= $amt;
                                            $badge_class = 'bg-success-subtle text-success border border-success-subtle';
                                        }
                                ?>
                                    <tr>
                                        <td><small class="text-muted fw-semibold"><?= date('M d, Y', strtotime($l_row['transaction_date'])) ?></small></td>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($l_row['description']) ?></td>
                                        <td><span class="badge rounded-pill px-3 <?= $badge_class ?>"><?= strtoupper(htmlspecialchars($type)) ?></span></td>
                                        <td class="text-end fw-bold">₱<?= number_format($amt, 2) ?></td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="4" class="text-center text-muted py-4">No financial records found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" class="fw-bold text-end pt-3">Current Balance:</td>
                                    <td class="text-end fw-bold text-danger fs-6 pt-3">₱<?= number_format($total_balance, 2) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card-custom p-4 h-100">
                    <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-bullhorn me-2 text-danger"></i>Campus Broadcasts</h5>
                    <div class="overflow-auto pr-1" style="max-height: 520px;">
                        <?php if ($ann_count > 0): ?>
                            <?php foreach ($announcements_list as $row): ?>
                                <div class="announcement-item mb-3 p-3 bg-light rounded-3">
                                    <strong class="text-dark d-block mb-1">
                                        <i class="fa-solid fa-circle-info text-danger me-1 small"></i>
                                        <?= htmlspecialchars($row['title']) ?>
                                    </strong>
                                    <p class="small text-muted mb-2"><?= htmlspecialchars($row['content']) ?></p>
                                    <small class="text-secondary d-block text-end fw-semibold">— <?= htmlspecialchars($row['posted_by']) ?></small>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="small text-muted text-center py-5">No broadcasts posted yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <button class="btn btn-grc-action position-fixed bottom-0 end-0 m-4 rounded-circle shadow-lg p-3 d-flex align-items-center justify-content-center"
                style="z-index: 1000; width: 60px; height: 60px;" onclick="toggleStudentChat()">
            <i class="fa-solid fa-comments fs-4"></i>
        </button>

        <div id="studentChatBox" class="glass-chat-box position-fixed bottom-0 end-0 m-4 d-none" style="width: 360px; z-index: 1001; overflow: hidden;">
            <div class="p-3 text-white d-flex justify-content-between align-items-center" style="background: var(--grc-dark); border-bottom: 2px solid var(--grc-secondary);">
                <h6 class="mb-0 fw-bold"><i class="fa-solid fa-headset text-danger me-2"></i>Help Desk</h6>
                <button type="button" class="btn-close btn-close-white btn-sm" onclick="toggleStudentChat()"></button>
            </div>
            <div class="card-body p-3" id="studentChatContainer" style="height: 320px; overflow-y: auto; background-color: rgba(248, 250, 252, 0.8);"></div>
            <div class="card-footer bg-white border-top p-2">
                <form onsubmit="sendStudentMessage(event)">
                    <div class="input-group">
                        <input type="text" id="studentChatMessage" class="form-control form-control-sm border-0 bg-light" placeholder="Type your message..." required>
                        <button type="submit" class="btn btn-grc-action btn-sm px-3"><i class="fa-solid fa-paper-plane"></i></button>
                    </div>
                </form>
            </div>
        </div>

    <?php elseif ($isAdminOrDept): ?>
        <!-- ADMIN / DEPARTMENT VIEW -->
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-desktop me-2 text-danger"></i>Screen Record</h5>
                    </div>

                    <div class="mb-2">
                        <h6 class="fw-bold text-dark small mb-2">
                            <i class="fa-solid fa-clipboard-list text-danger me-1"></i> 
                            Faculty Submissions for Admin ID: <?= htmlspecialchars($user_data['student_id'] ?? 'N/A') ?>
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle small border rounded">
                                <thead class="table-light">
                                    <tr>
                                        <th>Student Full Name & ID</th>
                                        <th>Target Admin ID</th>
                                        <th>Submitted At</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $current_admin_code = $user_data['student_id'] ?? '';

                                    $sub_stmt = $pdo->prepare("
                                        SELECT fs.*, u.id as user_db_id, u.student_id as stud_code, u.first_name, u.last_name, f.username as faculty_name 
                                        FROM faculty_submissions fs 
                                        JOIN users u ON fs.student_id = u.id 
                                        JOIN users f ON fs.faculty_id = f.id 
                                        WHERE fs.cleared_by_admin = 0 
                                          AND (fs.admin_id = ? OR fs.admin_target_id = ?)
                                        ORDER BY fs.id DESC LIMIT 10
                                    ");
                                    $sub_stmt->execute([$user_data['id'], $current_admin_code]);
                                    $submissions_list = $sub_stmt->fetchAll(PDO::FETCH_ASSOC);

                                    foreach ($submissions_list as &$sub) {
                                        $rec_q = $pdo->prepare("SELECT * FROM academic_records WHERE student_id = ? ORDER BY id DESC");
                                        $rec_q->execute([$sub['user_db_id']]);
                                        $sub['records'] = $rec_q->fetchAll(PDO::FETCH_ASSOC);

                                        $gwa_q = $pdo->prepare("SELECT AVG(grade) FROM academic_records WHERE student_id = ?");
                                        $gwa_q->execute([$sub['user_db_id']]);
                                        $avg = $gwa_q->fetchColumn();
                                        $sub['gwa'] = $avg ? number_format($avg, 2) : '—';
                                    }
                                    unset($sub);

                                    if (count($submissions_list) > 0):
                                        foreach ($submissions_list as $sub):
                                            $st_full = trim($sub['first_name'] . ' ' . $sub['last_name']);
                                            $sub_json = htmlspecialchars(json_encode($sub), ENT_QUOTES, 'UTF-8');
                                    ?>
                                        <tr>
                                            <td>
                                                <span class="fw-bold text-dark"><?= htmlspecialchars($st_full) ?></span><br>
                                                <small class="text-muted">ID: <?= htmlspecialchars($sub['stud_code']) ?></small>
                                            </td>
                                            <td><span class="badge bg-secondary"><?= htmlspecialchars($sub['admin_target_id']) ?></span></td>
                                            <td><small class="text-muted"><?= date('M d, Y h:i A', strtotime($sub['created_at'])) ?></small></td>
                                            <td class="text-end">
                                                <div class="d-flex justify-content-end align-items-center gap-1">
                                                    <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold view-record-btn" data-record='<?= $sub_json ?>'>
                                                        <i class="fa-solid fa-eye me-1"></i> View Record
                                                    </button>
                                                    
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to permanently delete this grade record from the database?');">
    <input type="hidden" name="delete_faculty_submission" value="1">
    <input type="hidden" name="submission_id" value="<?= $sub['id'] ?>">
    <button type="submit" class="btn btn-sm btn-outline-secondary rounded-circle" title="Delete Submission" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;">
        <i class="fa-solid fa-trash-can text-danger"></i>
    </button>
</form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; else: ?>
                                        <tr><td colspan="4" class="text-center text-muted py-3">No submissions routed to your Admin ID (<?= htmlspecialchars($current_admin_code) ?>) yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ledger Entry Card -->
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card-custom p-4">
                    <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-calculator me-2 text-danger"></i>Financial Ledger Entry</h5>
                    <form method="POST">
                        <input type="hidden" name="add_ledger" value="1">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Student ID / System ID</label>
                                <input type="text" name="ledger_stud_id" class="form-control form-control-sm" placeholder="e.g. 2025-01-001" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Description</label>
                                <input type="text" name="description" class="form-control form-control-sm" placeholder="Description" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Amount (₱)</label>
                                <input type="number" step="0.01" name="amount" class="form-control form-control-sm" placeholder="0.00" required>
                            </div>
                        </div>
                        <div class="row g-3 mt-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Transaction Type</label>
                                <select name="transaction_type" class="form-select form-select-sm">
                                    <option value="Charge">Charge (Debit)</option>
                                    <option value="Payment">Payment (Credit)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Date</label>
                                <input type="date" name="trans_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-grc-action btn-sm w-100 py-2 fw-semibold">
                            <i class="fa-solid fa-file-invoice-dollar me-1"></i> Post Financial Entry
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- New Broadcast Form -->
        <div class="card-custom p-4 mb-4">
            <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-paper-plane me-2 text-danger"></i>Broadcast New Announcement</h5>
            <form method="POST">
                <input type="hidden" name="post_announcement" value="1">
                <div class="mb-3">
                    <input type="text" name="title" class="form-control form-control-sm" placeholder="Announcement Title" required>
                </div>
                <div class="mb-3">
                    <textarea name="content" class="form-control form-control-sm" rows="3" placeholder="Content details..." required></textarea>
                </div>
                <button type="submit" class="btn btn-grc-action btn-sm px-4 py-2 fw-bold">
                    <i class="fa-solid fa-share-from-square me-1"></i> Publish Announcement
                </button>
            </form>
        </div>

        <!-- User Directory Table -->
        <div class="table-container-modern">
            <h5 class="fw-bold text-dark mb-4"><i class="fa-solid fa-users-gear me-2 text-danger"></i>User Directory</h5>
            <div class="table-responsive">
                <table class="table table-modern">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Student / Faculty / Dept ID</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $users_stmt = $pdo->query("SELECT id, username, email, role, student_id, first_name, last_name, phone, department_course FROM users ORDER BY id");
                        while ($row = $users_stmt->fetch()):
                            $user_json = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                        ?>
                            <tr>
                                <td><span class="badge bg-light text-dark border">#<?= $row['id'] ?></span></td>
                                <td><strong class="text-dark"><?= htmlspecialchars($row['username']) ?></strong></td>
                                <td><?= htmlspecialchars($row['email']) ?></td>
                                <td><span class="badge rounded-pill px-3" style="background-color: var(--grc-primary);"><?= htmlspecialchars($row['role']) ?></span></td>
                                <td><small class="fw-bold"><?= htmlspecialchars($row['student_id'] ?? '—') ?></small></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-danger rounded-pill px-3 view-user-btn" data-user='<?= $user_json ?>'>
                                        <i class="fa-solid fa-eye me-1"></i> View
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ===================== STUDENT RECORD FLOATING MODAL ===================== -->
<div class="modal fade" id="studentRecordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header text-white" style="background: var(--grc-dark);">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-file-lines me-2 text-danger"></i>Student Grade Record Review</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="printableModalContent">
            </div>
            
            <div class="modal-footer bg-light justify-content-between">
                <form id="sendRecordForm" class="d-flex align-items-center gap-2 w-50" onsubmit="sendRecordToStudentDashboard(event)">
                    <input type="hidden" id="modalRecordId" name="record_id">
                    <input type="text" id="targetStudentIdInput" name="target_student_id" class="form-control form-control-sm" placeholder="Enter Target Student ID" required>
                    <button type="submit" class="btn btn-grc-action btn-sm text-nowrap fw-semibold">
                        <i class="fa-solid fa-paper-plane me-1"></i> Send
                    </button>
                </form>
                <div>
                    <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3 fw-semibold" onclick="window.print()">
                        <i class="fa-solid fa-print me-1"></i> Print
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===================== PROFILE MODAL ===================== -->
<div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header text-white" style="background: linear-gradient(135deg, var(--grc-dark) 0%, var(--grc-secondary) 100%); border-radius: 20px 20px 0 0;">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-gear me-2"></i>My Profile</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="text-center mb-4">
                    <div class="rounded-circle bg-danger bg-opacity-10 d-inline-flex p-3 text-danger mb-2">
                        <i class="fa-solid fa-user-circle fa-3x"></i>
                    </div>
                    <h5 class="fw-bold mb-0"><?= htmlspecialchars($display_name) ?></h5>
                    <span class="badge px-3 py-1 rounded-pill mt-1" style="background-color: var(--grc-primary);">
                        <?= htmlspecialchars($user_data['role'] ?? 'User') ?>
                    </span>
                </div>
                <div class="list-group list-group-flush border-top border-bottom">
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold"><i class="fa-solid fa-id-card me-2 text-danger"></i>
                            <?= ($user_data['role'] === 'Department') ? 'Dept ID' : (($user_data['role'] === 'Professor' || $user_data['role'] === 'Faculty') ? 'Faculty ID' : 'Student ID') ?>:
                        </span>
                        <span class="fw-bold small"><?= htmlspecialchars($user_data['student_id'] ?? 'N/A') ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold"><i class="fa-solid fa-user me-2 text-danger"></i>Full Name:</span>
                        <span class="fw-bold small"><?= htmlspecialchars(($user_data['first_name'] ?? '') . ' ' . ($user_data['last_name'] ?? '')) ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold"><i class="fa-solid fa-at me-2 text-danger"></i>Username:</span>
                        <span class="fw-bold small"><?= htmlspecialchars($user_data['username'] ?? 'N/A') ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold"><i class="fa-solid fa-envelope me-2 text-danger"></i>Email:</span>
                        <span class="fw-bold small"><?= htmlspecialchars($user_data['email'] ?? 'N/A') ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold"><i class="fa-solid fa-phone me-2 text-danger"></i>Phone:</span>
                        <span class="fw-bold small"><?= htmlspecialchars($user_data['phone'] ?? 'N/A') ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold"><i class="fa-solid fa-graduation-cap me-2 text-danger"></i>Course / Dept:</span>
                        <span class="fw-bold small"><?= htmlspecialchars($user_data['department_course'] ?? 'N/A') ?></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light border-0">
                <button type="button" class="btn btn-secondary btn-sm rounded-pill px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ===================== NOTIFICATIONS OFFCANVAS ===================== -->
<div class="offcanvas offcanvas-end" style="width: 450px;" tabindex="-1" id="announcementsDrawer">
    <div class="offcanvas-header text-white" style="background: var(--grc-dark); border-bottom: 3px solid var(--grc-secondary);">
        <h5 class="offcanvas-title fw-bold"><i class="fa-solid fa-bell text-danger me-2"></i>Notifications</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>

    <?php if ($isAdminOrDept): ?>
    <ul class="nav nav-tabs nav-justified bg-light border-bottom" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-semibold text-dark py-2" data-bs-toggle="tab" data-bs-target="#drawerInbox" type="button">
                <i class="fa-solid fa-comments me-1 text-danger"></i> Messages
                <?php if ($unread_msg_count > 0): ?>
                    <span class="badge bg-danger rounded-pill ms-1"><?= $unread_msg_count ?></span>
                <?php endif; ?>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-semibold text-dark py-2" data-bs-toggle="tab" data-bs-target="#drawerAnnouncements" type="button">
                <i class="fa-solid fa-bullhorn me-1 text-danger"></i> Broadcasts
            </button>
        </li>
    </ul>
    <?php endif; ?>

    <div class="offcanvas-body bg-light">
        <div class="tab-content">
            <?php if ($isAdminOrDept): ?>
            <div class="tab-pane fade show active" id="drawerInbox">
                <div id="drawerStudentListView">
                    <h6 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-inbox me-2 text-danger"></i>Student Requests</h6>
                    <div class="list-group shadow-sm overflow-auto mb-3" style="max-height: 450px;">
                        <?php
                        $threads_stmt = $pdo->query("
                            SELECT u.id, u.first_name, u.last_name, u.student_id,
                                (SELECT message FROM support_messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_msg,
                                (SELECT created_at FROM support_messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_time,
                                (SELECT COUNT(*) FROM support_messages WHERE user_id = u.id AND is_read = 0 AND sender_role = 'student') as unread_count
                            FROM users u
                            WHERE EXISTS (SELECT 1 FROM support_messages WHERE user_id = u.id)
                            ORDER BY last_time DESC
                        ");
                        $threads = $threads_stmt->fetchAll();

                        if (count($threads) > 0):
                            foreach ($threads as $th):
                                $st_name = trim($th['first_name'] . ' ' . $th['last_name']);
                                if (empty($st_name)) $st_name = "Student #" . $th['id'];
                        ?>
                            <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-start p-3" onclick="openAdminChatThread(<?= $th['id'] ?>, '<?= htmlspecialchars($st_name, ENT_QUOTES) ?>')">
                                <div class="ms-2 me-auto text-truncate" style="max-width: 260px;">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($st_name) ?></div>
                                    <small class="text-muted text-truncate d-block"><?= htmlspecialchars($th['last_msg'] ?? 'No messages') ?></small>
                                </div>
                                <?php if ($th['unread_count'] > 0): ?>
                                    <span class="badge bg-danger rounded-pill mt-1"><?= $th['unread_count'] ?></span>
                                <?php endif; ?>
                            </button>
                        <?php 
                            endforeach; 
                        else: 
                        ?>
                            <div class="p-4 text-center text-muted small">No message threads found.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="drawerChatThreadView" class="d-none">
                    <button class="btn btn-sm btn-link text-danger p-0 mb-3 fw-semibold text-decoration-none" onclick="closeAdminChatThread()">
                        <i class="fa-solid fa-arrow-left me-1"></i> Back to Messages
                    </button>
                    <h6 class="fw-bold text-dark mb-2" id="adminThreadTitle">Chat Session</h6>
                    <div class="p-3 bg-white rounded border mb-3 overflow-auto" id="adminChatContainer" style="height: 320px;"></div>
                    <form onsubmit="sendAdminMessage(event)">
                        <div class="input-group">
                            <input type="text" id="adminChatMessage" class="form-control form-control-sm" placeholder="Type reply..." required>
                            <button type="submit" class="btn btn-grc-action btn-sm px-3"><i class="fa-solid fa-paper-plane"></i></button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <div class="tab-pane fade <?= !$isAdminOrDept ? 'show active' : '' ?>" id="drawerAnnouncements">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-bullhorn me-2 text-danger"></i>System Broadcasts</h6>
                </div>
                <div class="overflow-auto" style="max-height: 500px;">
                    <?php if ($ann_count > 0): ?>
                        <?php foreach ($announcements_list as $ann): ?>
                            <div class="card mb-3 border-0 shadow-sm rounded-3">
                                <div class="card-body p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <strong class="text-dark small"><?= htmlspecialchars($ann['title']) ?></strong>
                                        <small class="text-muted" style="font-size: 0.7rem;"><?= date('M d, Y', strtotime($ann['created_at'])) ?></small>
                                    </div>
                                    <p class="small text-muted mb-2"><?= htmlspecialchars($ann['content']) ?></p>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <small class="text-secondary fw-semibold" style="font-size: 0.75rem;">— <?= htmlspecialchars($ann['posted_by']) ?></small>
                                        <?php if ($isAdminOrDept): ?>
                                            <div>
                                                <button class="btn btn-sm btn-outline-secondary py-0 px-2 text-dark" style="font-size: 0.75rem;" onclick="editAnnouncement(<?= $ann['id'] ?>, '<?= htmlspecialchars($ann['title'], ENT_QUOTES) ?>', '<?= htmlspecialchars($ann['content'], ENT_QUOTES) ?>')">
                                                    <i class="fa-solid fa-pen"></i>
                                                </button>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this announcement?');">
                                                    <input type="hidden" name="delete_announcement" value="1">
                                                    <input type="hidden" name="ann_id" value="<?= $ann['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size: 0.75rem;">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="small text-muted text-center py-4">No active broadcasts found.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- EDIT ANNOUNCEMENT MODAL -->
<?php if ($isAdminOrDept): ?>
<div class="modal fade" id="editAnnouncementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header text-white" style="background: var(--grc-dark);">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2 text-danger"></i>Edit Announcement</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="edit_announcement" value="1">
                <input type="hidden" name="ann_id" id="editAnnId">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Title</label>
                        <input type="text" name="ann_title" id="editAnnTitle" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Content</label>
                        <textarea name="ann_content" id="editAnnContent" class="form-control" rows="4" required></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-grc-action btn-sm rounded-pill px-4">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- USER VIEW MODAL -->
<div class="modal fade" id="userViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header text-white" style="background: var(--grc-dark);">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-circle me-2 text-danger"></i>User Profile Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="userViewModalBody">
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary btn-sm rounded-pill px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Auto Dismiss Toast logic (4 seconds)
    const toast = document.querySelector('.floating-toast-container');
    if (toast) {
        setTimeout(() => {
            const alertInstance = bootstrap.Alert.getOrCreateInstance(toast);
            if (alertInstance) {
                alertInstance.close();
            }
        }, 4000);
    }
});

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function clearGradeForm() {
    const form = document.getElementById('profGradingForm');
    if (form) {
        form.reset();
        document.getElementById('gradePreview').textContent = '—';
        document.getElementById('scorePreview').textContent = '0.00%';
    }
}

function calculatePreview() {
    const inputs = document.querySelectorAll('.grade-input');
    if (!inputs.length) return;

    let attendance = parseFloat(inputs[0].value) || 0;
    let quizzes    = parseFloat(inputs[1].value) || 0;
    let prelim     = parseFloat(inputs[2].value) || 0;
    let midterm    = parseFloat(inputs[3].value) || 0;
    let final      = parseFloat(inputs[4].value) || 0;

    let isGradeScale = (attendance <= 5.0 && quizzes <= 5.0 && prelim <= 5.0 && midterm <= 5.0 && final <= 5.0);

    let score = (attendance * 0.10) + (quizzes * 0.20) + (prelim * 0.20) + (midterm * 0.25) + (final * 0.25);
    let grade = '5.00';

    if (isGradeScale) {
        grade = score.toFixed(2);
        document.getElementById('scorePreview').textContent = 'Scale Mode';
    } else {
        if (score >= 97)      grade = '1.00';
        else if (score >= 94) grade = '1.25';
        else if (score >= 91) grade = '1.50';
        else if (score >= 88) grade = '1.75';
        else if (score >= 85) grade = '2.00';
        else if (score >= 82) grade = '2.25';
        else if (score >= 79) grade = '2.50';
        else if (score >= 76) grade = '2.75';
        else if (score >= 75) grade = '3.00';
        else if (score >= 70) grade = '4.00';
        else                  grade = '5.00';

        document.getElementById('scorePreview').textContent = score.toFixed(2) + '%';
    }

    document.getElementById('gradePreview').textContent = grade;
}

function calculateEditPreview() {
    const inputs = document.querySelectorAll('.edit-grade-input');
    if (!inputs.length) return;

    let attendance = parseFloat(inputs[0].value) || 0;
    let quizzes    = parseFloat(inputs[1].value) || 0;
    let prelim     = parseFloat(inputs[2].value) || 0;
    let midterm    = parseFloat(inputs[3].value) || 0;
    let final      = parseFloat(inputs[4].value) || 0;

    let isGradeScale = (attendance <= 5.0 && quizzes <= 5.0 && prelim <= 5.0 && midterm <= 5.0 && final <= 5.0);

    let score = (attendance * 0.10) + (quizzes * 0.20) + (prelim * 0.20) + (midterm * 0.25) + (final * 0.25);
    let grade = '5.00';

    if (isGradeScale) {
        grade = score.toFixed(2);
        document.getElementById('editScorePreview').textContent = 'Scale Mode';
    } else {
        if (score >= 97)      grade = '1.00';
        else if (score >= 94) grade = '1.25';
        else if (score >= 91) grade = '1.50';
        else if (score >= 88) grade = '1.75';
        else if (score >= 85) grade = '2.00';
        else if (score >= 82) grade = '2.25';
        else if (score >= 79) grade = '2.50';
        else if (score >= 76) grade = '2.75';
        else if (score >= 75) grade = '3.00';
        else if (score >= 70) grade = '4.00';
        else                  grade = '5.00';

        document.getElementById('editScorePreview').textContent = score.toFixed(2) + '%';
    }

    document.getElementById('editGradePreview').textContent = grade;
}

function openEditModal(id, studId, fullName, subject, semester, ay, grade) {
    document.getElementById('editRecordId').value = id;
    document.getElementById('editStudentId').value = studId;
    document.getElementById('editStudentFullname').value = fullName;
    document.getElementById('editSubjectCode').value = subject;
    document.getElementById('editSemester').value = semester;
    document.getElementById('editAcademicYear').value = ay;

    document.getElementById('editAttendance').value = '';
    document.getElementById('editQuizzes').value = '';
    document.getElementById('editPrelim').value = '';
    document.getElementById('editMidterm').value = '';
    document.getElementById('editFinal').value = '';

    document.getElementById('editGradePreview').textContent = grade;
    document.getElementById('editScorePreview').textContent = 'Loaded';

    const modal = new bootstrap.Modal(document.getElementById('editGradeModal'));
    modal.show();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.view-record-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const data = JSON.parse(btn.getAttribute('data-record'));
            const fullName = `${data.first_name} ${data.last_name}`;
            const studentId = data.stud_code;
            const gwa = data.gwa;
            const records = data.records;

            document.getElementById('targetStudentIdInput').value = studentId;
            document.getElementById('modalRecordId').value = data.id;

            let recordsHtml = '';
            if (records && records.length > 0) {
                records.forEach(r => {
                    recordsHtml += `
                        <tr>
                            <td><span class="badge bg-light text-dark border fw-bold">${escapeHtml(r.subject_code)}</span></td>
                            <td class="fw-semibold text-dark">${escapeHtml(r.subject_title)}</td>
                            <td><small class="text-muted">${escapeHtml(r.semester)}</small></td>
                            <td><small class="text-muted">${escapeHtml(r.academic_year || '2025-2026')}</small></td>
                            <td class="text-end fw-bold text-success fs-6">${Number(r.grade).toFixed(2)}</td>
                        </tr>
                    `;
                });
            } else {
                recordsHtml = `<tr><td colspan="5" class="text-center text-muted py-4">No grade records found for this student.</td></tr>`;
            }

            const modalBody = document.getElementById('printableModalContent');
            modalBody.innerHTML = `
                <div class="p-3 bg-light rounded-3 border mb-4 d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small fw-semibold d-block">Student Name & ID:</span>
                        <h5 class="fw-bold text-dark mb-0">${escapeHtml(fullName)} (${escapeHtml(studentId)})</h5>
                    </div>
                    <div class="text-end">
                        <span class="text-muted small fw-semibold d-block">Computed GWA:</span>
                        <h3 class="fw-bold text-success mb-0">${gwa}</h3>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle small mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Subject Code</th>
                                <th>Subject Title</th>
                                <th>Semester</th>
                                <th>Academic Year</th>
                                <th class="text-end">Final Grade</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${recordsHtml}
                        </tbody>
                    </table>
                </div>
            `;

            const modal = new bootstrap.Modal(document.getElementById('studentRecordModal'));
            modal.show();
        });
    });

    document.querySelectorAll('.view-user-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const user = JSON.parse(btn.getAttribute('data-user'));
            const modalBody = document.getElementById('userViewModalBody');

            modalBody.innerHTML = `
                <div class="text-center mb-4">
                    <div class="rounded-circle bg-danger bg-opacity-10 d-inline-flex p-3 text-danger mb-2">
                        <i class="fa-solid fa-user-circle fa-3x"></i>
                    </div>
                    <h5 class="fw-bold mb-0">${escapeHtml((user.first_name || '') + ' ' + (user.last_name || '')) || escapeHtml(user.username)}</h5>
                    <span class="badge bg-danger px-3 py-1 rounded-pill mt-1">${escapeHtml(user.role)}</span>
                </div>
                <div class="list-group list-group-flush border-top border-bottom">
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold">ID Code:</span>
                        <span class="fw-bold small">${escapeHtml(user.student_id || 'N/A')}</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold">Username:</span>
                        <span class="fw-bold small">${escapeHtml(user.username || 'N/A')}</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold">Email:</span>
                        <span class="fw-bold small">${escapeHtml(user.email || 'N/A')}</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold">Phone:</span>
                        <span class="fw-bold small">${escapeHtml(user.phone || 'N/A')}</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold">Course / Dept:</span>
                        <span class="fw-bold small">${escapeHtml(user.department_course || 'N/A')}</span>
                    </div>
                </div>
            `;

            const modal = new bootstrap.Modal(document.getElementById('userViewModal'));
            modal.show();
        });
    });
});

function sendRecordToStudentDashboard(e) {
    e.preventDefault();
    const formData = new FormData();
    formData.append('send_record_target_id', document.getElementById('targetStudentIdInput').value);
    formData.append('record_id', document.getElementById('modalRecordId').value);

    fetch('dashboard.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        alert(data.message);
        if (data.status === 'success') {
            const modalEl = document.getElementById('studentRecordModal');
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
            location.reload();
        }
    })
    .catch(err => console.error(err));
}

function editAnnouncement(id, title, content) {
    document.getElementById('editAnnId').value = id;
    document.getElementById('editAnnTitle').value = title;
    document.getElementById('editAnnContent').value = content;

    const modal = new bootstrap.Modal(document.getElementById('editAnnouncementModal'));
    modal.show();
}

// Student Chat Logic
let studentChatInterval = null;

function toggleStudentChat() {
    const box = document.getElementById('studentChatBox');
    box.classList.toggle('d-none');
    if (!box.classList.contains('d-none')) {
        fetchStudentMessages();
        studentChatInterval = setInterval(fetchStudentMessages, 3000);
    } else {
        if (studentChatInterval) clearInterval(studentChatInterval);
    }
}

function fetchStudentMessages() {
    fetch('dashboard.php?chat_action=fetch')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const container = document.getElementById('studentChatContainer');
                let html = '';
                data.messages.forEach(msg => {
                    const isMe = msg.sender_role === 'student';
                    html += `
                        <div class="d-flex flex-column mb-2 ${isMe ? 'align-items-end' : 'align-items-start'}">
                            <div class="p-2 rounded-3 text-white small ${isMe ? 'bg-danger' : 'bg-dark'}" style="max-width: 80%;">
                                ${escapeHtml(msg.message)}
                            </div>
                            <span class="text-muted" style="font-size: 0.65rem;">${msg.time}</span>
                        </div>
                    `;
                });
                container.innerHTML = html;
                container.scrollTop = container.scrollHeight;
            }
        });
}

function sendStudentMessage(e) {
    e.preventDefault();
    const input = document.getElementById('studentChatMessage');
    const msg = input.value.trim();
    if (!msg) return;

    const formData = new FormData();
    formData.append('message', msg);

    fetch('dashboard.php?chat_action=send', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            input.value = '';
            fetchStudentMessages();
        }
    });
}

// Admin Chat Logic
let currentAdminChatUserId = null;
let adminChatInterval = null;

function openAdminChatThread(userId, name) {
    currentAdminChatUserId = userId;
    document.getElementById('drawerStudentListView').classList.add('d-none');
    document.getElementById('drawerChatThreadView').classList.remove('d-none');
    document.getElementById('adminThreadTitle').textContent = `Chat with ${name}`;

    fetchAdminMessages();
    adminChatInterval = setInterval(fetchAdminMessages, 3000);
}

function closeAdminChatThread() {
    if (adminChatInterval) clearInterval(adminChatInterval);
    currentAdminChatUserId = null;
    document.getElementById('drawerChatThreadView').classList.add('d-none');
    document.getElementById('drawerStudentListView').classList.remove('d-none');
}

function fetchAdminMessages() {
    if (!currentAdminChatUserId) return;
    fetch(`dashboard.php?chat_action=fetch&target_user_id=${currentAdminChatUserId}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const container = document.getElementById('adminChatContainer');
                let html = '';
                data.messages.forEach(msg => {
                    const isMe = msg.sender_role === 'admin';
                    html += `
                        <div class="d-flex flex-column mb-2 ${isMe ? 'align-items-end' : 'align-items-start'}">
                            <div class="p-2 rounded-3 text-white small ${isMe ? 'bg-danger' : 'bg-dark'}" style="max-width: 80%;">
                                ${escapeHtml(msg.message)}
                            </div>
                            <span class="text-muted" style="font-size: 0.65rem;">${msg.time}</span>
                        </div>
                    `;
                });
                container.innerHTML = html;
                container.scrollTop = container.scrollHeight;
            }
        });
}

function sendAdminMessage(e) {
    e.preventDefault();
    if (!currentAdminChatUserId) return;
    const input = document.getElementById('adminChatMessage');
    const msg = input.value.trim();
    if (!msg) return;

    const formData = new FormData();
    formData.append('target_user_id', currentAdminChatUserId);
    formData.append('message', msg);

    fetch('dashboard.php?chat_action=send', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            input.value = '';
            fetchAdminMessages();
        }
    });
}
</script>
</body>
</html>