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

// Ensure support_messages table exists (safety)
$pdo->exec("CREATE TABLE IF NOT EXISTS support_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    sender_role ENUM('student', 'admin') NOT NULL DEFAULT 'student',
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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

$isAdminOrDept = ($_SESSION['role'] === 'Admin' || $_SESSION['role'] === 'Department');

// Announcements
$announcements_stmt = $pdo->query("SELECT * FROM announcements ORDER BY id DESC");
$announcements_list = $announcements_stmt->fetchAll();
$ann_count = count($announcements_list);

// Unread messages for Admin/Department
$unread_msg_count = 0;
if ($isAdminOrDept) {
    $unread_stmt = $pdo->query("SELECT COUNT(*) FROM support_messages WHERE is_read = 0 AND sender_role = 'student'");
    $unread_msg_count = (int)$unread_stmt->fetchColumn();
}
$total_notifications = $ann_count + $unread_msg_count;

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

// ========== POST Actions (Admin / Department) ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isAdminOrDept) {

        if (isset($_POST['post_announcement'])) {
            $stmt = $pdo->prepare("INSERT INTO announcements (title, content, posted_by) VALUES (?, ?, ?)");
            $stmt->execute([$_POST['title'], $_POST['content'], $_SESSION['username']]);
            header("Location: dashboard.php");
            exit();
        }

        if (isset($_POST['edit_announcement'])) {
            $stmt = $pdo->prepare("UPDATE announcements SET title = ?, content = ? WHERE id = ?");
            $stmt->execute([$_POST['ann_title'], $_POST['ann_content'], $_POST['ann_id']]);
            header("Location: dashboard.php");
            exit();
        }

        if (isset($_POST['delete_announcement'])) {
            $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
            $stmt->execute([$_POST['ann_id']]);
            header("Location: dashboard.php");
            exit();
        }

        if (isset($_POST['add_grade'])) {
            $target_student_id = trim($_POST['stud_id']);
            $user_check = $pdo->prepare("SELECT id FROM users WHERE student_id = ? OR id = ?");
            $user_check->execute([$target_student_id, $target_student_id]);
            $student_user = $user_check->fetch();

            if ($student_user) {
                $stmt = $pdo->prepare("INSERT INTO academic_records (student_id, subject_code, subject_title, grade, semester, academic_year) VALUES (?, ?, ?, ?, ?, '2025-2026')");
                $stmt->execute([$student_user['id'], $_POST['sub_code'], $_POST['sub_title'], $_POST['grade_val'], $_POST['sem']]);
            }
            header("Location: dashboard.php");
            exit();
        }

        if (isset($_POST['add_ledger'])) {
            $target_student_id = trim($_POST['ledger_stud_id']);
            $user_check = $pdo->prepare("SELECT id FROM users WHERE student_id = ? OR id = ?");
            $user_check->execute([$target_student_id, $target_student_id]);
            $student_user = $user_check->fetch();

            if ($student_user) {
                $trans_date = !empty($_POST['trans_date']) ? $_POST['trans_date'] : date('Y-m-d H:i:s');
                $stmt = $pdo->prepare("INSERT INTO financial_ledgers (student_id, description, amount, transaction_type, transaction_date) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$student_user['id'], $_POST['description'], $_POST['amount'], $_POST['transaction_type'], $trans_date]);
            }
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
    <title>GRC Portal | Modern Dashboard</title>
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

        /* Modernized Top Navigation Header */
        .navbar-grc {
            background: linear-gradient(135deg, var(--grc-dark) 0%, #3a0000 100%);
            border-bottom: 3px solid var(--grc-hover);
            backdrop-filter: blur(10px);
        }

        /* Hero Banner Section */
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

        /* Elevated Metric Stat Cards */
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

        /* Modern Card Containers */
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

        /* Modernized Data Tables */
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

        /* Buttons & Actions */
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

        /* Glassmorphism Help Desk Box */
        .glass-chat-box {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
    </style>
</head>
<body>

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
                <?= htmlspecialchars($user_data['role']) ?> Portal
            </span>
            <h2 class="fw-extrabold mb-1">Welcome back, <?= htmlspecialchars($display_name) ?>!</h2>
            <p class="mb-0 text-white-50 small">
                Student ID: <?= htmlspecialchars($user_data['student_id'] ?? 'N/A') ?> · Department: <?= htmlspecialchars($user_data['department_course'] ?? 'General') ?>
            </p>
        </div>
        <div class="text-end d-none d-md-block">
            <h6 class="mb-0 text-white-50 small text-uppercase fw-semibold"><?= date('l') ?></h6>
            <h3 class="fw-bold mb-0"><?= date('F j, Y') ?></h3>
        </div>
    </div>

    <?php if ($_SESSION['role'] === 'Student'): ?>
        <?php
        // GWA Calculation
        $gwa_stmt = $pdo->prepare("SELECT AVG(grade) as gwa, COUNT(*) as total FROM academic_records WHERE student_id = ?");
        $gwa_stmt->execute([$_SESSION['user_id']]);
        $gwa_data = $gwa_stmt->fetch();
        $gwa = $gwa_data['gwa'] ? number_format($gwa_data['gwa'], 2) : '—';
        $subject_count = (int)($gwa_data['total'] ?? 0);

        // Outstanding Balance Calculation
        $bal_stmt = $pdo->prepare("SELECT SUM(CASE WHEN transaction_type = 'Charge' THEN amount ELSE -amount END) as balance FROM financial_ledgers WHERE student_id = ?");
        $bal_stmt->execute([$_SESSION['user_id']]);
        $balance = (float)($bal_stmt->fetchColumn() ?: 0);
        ?>

        <!-- Student Metrics Section -->
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

        <!-- Student Main Content -->
        <div class="row g-4">
            <!-- Academic & Ledger Tables -->
            <div class="col-lg-8">
                <!-- Academic Records -->
                <div class="table-container-modern mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-book-open me-2 text-danger"></i>Academic Records</h5>
                        <a href="print.php?type=grades" target="_blank" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold">
                            <i class="fa-solid fa-print me-1"></i> Print Grades
                        </a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-modern">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Subject Title</th>
                                    <th>Semester</th>
                                    <th class="text-end">Grade</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $grades_stmt = $pdo->prepare("SELECT * FROM academic_records WHERE student_id = ? ORDER BY id DESC");
                                $grades_stmt->execute([$_SESSION['user_id']]);
                                $grades = $grades_stmt->fetchAll();
                                if (count($grades) > 0):
                                    foreach ($grades as $g):
                                ?>
                                    <tr>
                                        <td><span class="badge bg-light text-dark border fw-bold"><?= htmlspecialchars($g['subject_code']) ?></span></td>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($g['subject_title']) ?></td>
                                        <td><small class="text-muted"><?= htmlspecialchars($g['semester']) ?></small></td>
                                        <td class="text-end fw-bold text-success fs-6"><?= number_format($g['grade'], 2) ?></td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="4" class="text-center text-muted py-4">No academic records found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Financial Ledger -->
                <div class="table-container-modern">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-file-invoice-dollar me-2 text-danger"></i>Financial Ledger</h5>
                        <a href="print.php?type=ledger" target="_blank" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold">
                            <i class="fa-solid fa-print me-1"></i> Print Statement
                        </a>
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

            <!-- Side Announcements Feed -->
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

        <!-- Floating Glassmorphism Chat Button & Window -->
        <button class="btn btn-grc-action position-fixed bottom-0 end-0 m-4 rounded-circle shadow-lg p-3 d-flex align-items-center justify-content-center"
                style="z-index: 1000; width: 60px; height: 60px;" onclick="toggleStudentChat()">
            <i class="fa-solid fa-comments fs-4"></i>
        </button>

        <div id="studentChatBox" class="glass-chat-box position-fixed bottom-0 end-0 m-4 d-none" style="width: 360px; z-index: 1001; overflow: hidden;">
            <div class="p-3 text-white d-flex justify-content-between align-items-center" style="background: var(--grc-dark); border-bottom: 2px solid var(--grc-secondary);">
                <h6 class="mb-0 fw-bold"><i class="fa-solid fa-headset text-danger me-2"></i>Help Desk Desk</h6>
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
        <!-- ===================== ADMIN / DEPARTMENT VIEW ===================== -->
        <div class="row g-4 mb-4">
            <!-- Grade Entry Card -->
            <div class="col-md-6">
                <div class="card-custom p-4 h-100">
                    <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-pen-to-square me-2 text-danger"></i>Grade Recording</h5>
                    <form method="POST">
                        <input type="hidden" name="add_grade" value="1">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Student ID / System ID</label>
                            <input type="text" name="stud_id" class="form-control form-control-sm" placeholder="e.g. 2025-01-001" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <input type="text" name="sub_code" class="form-control form-control-sm" placeholder="Subject Code" required>
                            </div>
                            <div class="col-6">
                                <input type="text" name="sub_title" class="form-control form-control-sm" placeholder="Subject Title" required>
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <input type="number" step="0.01" name="grade_val" class="form-control form-control-sm" placeholder="Grade" required>
                            </div>
                            <div class="col-6">
                                <select name="sem" class="form-select form-select-sm">
                                    <option>1st Semester</option>
                                    <option>2nd Semester</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-grc-action btn-sm w-100 py-2 fw-semibold">
                            <i class="fa-solid fa-plus-circle me-1"></i> Commit Grade Record
                        </button>
                    </form>
                </div>
            </div>

            <!-- Ledger Entry Card -->
            <div class="col-md-6">
                <div class="card-custom p-4 h-100">
                    <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-calculator me-2 text-danger"></i>Financial Ledger Entry</h5>
                    <form method="POST">
                        <input type="hidden" name="add_ledger" value="1">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Student ID / System ID</label>
                            <input type="text" name="ledger_stud_id" class="form-control form-control-sm" placeholder="e.g. 2025-01-001" required>
                        </div>
                        <div class="mb-3">
                            <input type="text" name="description" class="form-control form-control-sm" placeholder="Description" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <input type="number" step="0.01" name="amount" class="form-control form-control-sm" placeholder="Amount (₱)" required>
                            </div>
                            <div class="col-6">
                                <select name="transaction_type" class="form-select form-select-sm">
                                    <option value="Charge">Charge (Debit)</option>
                                    <option value="Payment">Payment (Credit)</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <input type="date" name="trans_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
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

        <!-- User Directory Modern Table -->
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
                            <th>Student / Dept ID</th>
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
                            <?= ($user_data['role'] === 'Department') ? 'Dept ID' : 'Student ID' ?>:
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
                        $inbox_students = $pdo->query("
                            SELECT DISTINCT u.id, u.first_name, u.last_name, u.username, u.student_id,
                            (SELECT COUNT(*) FROM support_messages WHERE user_id = u.id AND is_read = 0 AND sender_role = 'student') as unread
                            FROM users u
                            JOIN support_messages sm ON u.id = sm.user_id
                            ORDER BY unread DESC
                        ")->fetchAll();

                        if (count($inbox_students) > 0):
                            foreach ($inbox_students as $st):
                                $st_name = htmlspecialchars(trim($st['first_name'] . ' ' . $st['last_name']));
                                if (empty($st_name)) $st_name = htmlspecialchars($st['username']);
                        ?>
                            <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                    onclick="openDrawerAdminChat(<?= $st['id'] ?>, '<?= addslashes($st_name) ?>')">
                                <div>
                                    <strong class="d-block text-dark small"><?= $st_name ?></strong>
                                    <small class="text-muted"><?= htmlspecialchars($st['student_id'] ?: 'No ID') ?></small>
                                </div>
                                <?php if ($st['unread'] > 0): ?>
                                    <span class="badge bg-danger rounded-pill"><?= $st['unread'] ?></span>
                                <?php endif; ?>
                            </button>
                        <?php endforeach; else: ?>
                            <div class="p-3 text-center text-muted small bg-white rounded border">No student messages yet.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="drawerChatView" class="d-none">
                    <div class="d-flex align-items-center justify-content-between pb-2 mb-2 border-bottom">
                        <button class="btn btn-sm btn-outline-secondary rounded-pill" onclick="closeDrawerChat()">
                            <i class="fa-solid fa-arrow-left me-1"></i> Back
                        </button>
                        <span class="fw-bold small text-dark" id="drawerChatHeader">Chat</span>
                    </div>
                    <div class="card-body p-2 bg-white rounded border" id="drawerChatContainer" style="height: 320px; overflow-y: auto;"></div>
                    <form onsubmit="sendDrawerAdminReply(event)" class="mt-2">
                        <input type="hidden" id="drawerSelectedStudentId">
                        <div class="input-group">
                            <input type="text" id="drawerReplyMsg" class="form-control form-control-sm" placeholder="Type reply..." required>
                            <button type="submit" class="btn btn-grc-action btn-sm"><i class="fa-solid fa-paper-plane"></i></button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <div class="tab-pane fade <?= $_SESSION['role'] === 'Student' ? 'show active' : '' ?>" id="drawerAnnouncements">
                <?php if ($ann_count > 0): ?>
                    <?php foreach ($announcements_list as $ann): ?>
                        <div class="announcement-item mb-3 pb-2 bg-white p-3 rounded-3 shadow-sm border">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <strong class="text-dark">
                                    <i class="fa-solid fa-circle-info text-danger me-1 small"></i>
                                    <?= htmlspecialchars($ann['title']) ?>
                                </strong>
                                <?php if ($isAdminOrDept): ?>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary btn-sm py-0 px-1 border-0 edit-ann-btn"
                                                data-bs-toggle="modal" data-bs-target="#editAnnouncementModal"
                                                data-id="<?= $ann['id'] ?>"
                                                data-title="<?= htmlspecialchars($ann['title']) ?>"
                                                data-content="<?= htmlspecialchars($ann['content']) ?>">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this announcement?');">
                                            <input type="hidden" name="delete_announcement" value="1">
                                            <input type="hidden" name="ann_id" value="<?= $ann['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1 border-0">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <p class="small text-muted mb-2"><?= htmlspecialchars($ann['content']) ?></p>
                            <div class="d-flex justify-content-between align-items-center border-top pt-2">
                                <small class="text-muted" style="font-size: 0.75rem;">
                                    <?= isset($ann['created_at']) ? date('M d, Y', strtotime($ann['created_at'])) : 'Recent' ?>
                                </small>
                                <small class="text-secondary fw-semibold">— <?= htmlspecialchars($ann['posted_by']) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted text-center py-4">No announcements yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ===================== MODALS ===================== -->
<!-- Edit Announcement Modal -->
<div class="modal fade" id="editAnnouncementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 18px;">
            <div class="modal-header text-white" style="background: var(--grc-dark); border-radius: 18px 18px 0 0;">
                <h5 class="modal-title fw-bold">Edit Announcement</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <input type="hidden" name="edit_announcement" value="1">
                    <input type="hidden" name="ann_id" id="editAnnId">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Title</label>
                        <input type="text" name="ann_title" id="editAnnTitle" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Content</label>
                        <textarea name="ann_content" id="editAnnContent" class="form-control" rows="4" required></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-grc-action btn-sm rounded-pill px-4">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Admin User Detail Modal -->
<div class="modal fade" id="adminUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 18px;">
            <div class="modal-header text-white" style="background: var(--grc-dark); border-radius: 18px 18px 0 0;">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-circle me-2 text-danger"></i>User Detail</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="list-group list-group-flush">
                    <div class="list-group-item d-flex justify-content-between py-2"><span class="text-muted small">System ID:</span><span id="u_id" class="fw-bold small"></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2"><span class="text-muted small">ID Number:</span><span id="u_studentid" class="fw-bold small"></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2"><span class="text-muted small">Full Name:</span><span id="u_fullname" class="fw-bold small"></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2"><span class="text-muted small">Username:</span><span id="u_username" class="fw-bold small"></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2"><span class="text-muted small">Email:</span><span id="u_email" class="fw-bold small"></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2"><span class="text-muted small">Phone:</span><span id="u_phone" class="fw-bold small"></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2"><span class="text-muted small">Course / Dept:</span><span id="u_dept" class="fw-bold small"></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2"><span class="text-muted small">Role:</span><span id="u_role" class="fw-bold small text-danger"></span></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===================== JAVASCRIPT LOGIC ===================== -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Edit Announcement Modal Binding
    document.querySelectorAll('.edit-ann-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('editAnnId').value = btn.getAttribute('data-id') || '';
            document.getElementById('editAnnTitle').value = btn.getAttribute('data-title') || '';
            document.getElementById('editAnnContent').value = btn.getAttribute('data-content') || '';
        });
    });

    // Admin View User Modal Binding
    document.querySelectorAll('.view-user-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            try {
                const u = JSON.parse(btn.getAttribute('data-user'));
                document.getElementById('u_id').textContent = '#' + (u.id || '');
                document.getElementById('u_studentid').textContent = u.student_id || 'N/A';
                document.getElementById('u_fullname').textContent = ((u.first_name || '') + ' ' + (u.last_name || '')).trim() || 'N/A';
                document.getElementById('u_username').textContent = u.username || 'N/A';
                document.getElementById('u_email').textContent = u.email || 'N/A';
                document.getElementById('u_phone').textContent = u.phone || 'N/A';
                document.getElementById('u_dept').textContent = u.department_course || 'N/A';
                document.getElementById('u_role').textContent = u.role || 'N/A';

                new bootstrap.Modal(document.getElementById('adminUserModal')).show();
            } catch (e) {
                console.error('Error parsing user data:', e);
                alert('Unable to load user details.');
            }
        });
    });
});

// Helper: Escape HTML (prevent XSS)
function escapeHtml(text) {
    if (typeof text !== 'string') return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ========== STUDENT CHAT FUNCTIONS ==========
function toggleStudentChat() {
    const box = document.getElementById('studentChatBox');
    if (!box) return;

    box.classList.toggle('d-none');
    if (!box.classList.contains('d-none')) {
        loadStudentMessages();
    }
}

function loadStudentMessages() {
    const container = document.getElementById('studentChatContainer');
    if (!container) return;

    fetch('dashboard.php?chat_action=fetch')
        .then(res => {
            if (!res.ok) throw new Error('Network response was not ok');
            return res.json();
        })
        .then(data => {
            if (data.status === 'success') {
                container.innerHTML = '';

                if (!data.messages || data.messages.length === 0) {
                    container.innerHTML = `
                        <div class="text-center text-muted py-5">
                            <i class="fa-solid fa-comments fa-2x mb-2 opacity-50"></i>
                            <p class="small mb-0">No messages yet.<br>Start a conversation!</p>
                        </div>`;
                    return;
                }

                data.messages.forEach(msg => {
                    const isStudent = msg.sender_role === 'student';
                    const align = isStudent ? 'text-end' : 'text-start';
                    const bg = isStudent ? 'bg-danger text-white' : 'bg-secondary text-white';

                    container.innerHTML += `
                        <div class="mb-2 ${align}">
                            <div class="d-inline-block p-2 rounded-3 small ${bg}" style="max-width: 80%; word-wrap: break-word;">
                                ${escapeHtml(msg.message)}
                            </div>
                            <div class="text-muted" style="font-size: 0.65rem;">${msg.time || ''}</div>
                        </div>`;
                });
                container.scrollTop = container.scrollHeight;
            }
        })
        .catch(err => {
            console.error('Failed to load student messages:', err);
        });
}

function sendStudentMessage(e) {
    e.preventDefault();
    const input = document.getElementById('studentChatMessage');
    if (!input) return;

    const msg = input.value.trim();
    if (!msg) return;

    const formData = new FormData();
    formData.append('message', msg);

    const btn = e.target.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;

    fetch('dashboard.php?chat_action=send', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            input.value = '';
            loadStudentMessages();
        } else {
            alert(data.message || 'Failed to send message.');
        }
    })
    .catch(err => {
        console.error(err);
        alert('Network error. Please try again.');
    })
    .finally(() => {
        if (btn) btn.disabled = false;
    });
}

// ========== ADMIN / DEPARTMENT DRAWER CHAT ==========
function openDrawerAdminChat(studentId, studentName) {
    const listView = document.getElementById('drawerStudentListView');
    const chatView = document.getElementById('drawerChatView');
    if (!listView || !chatView) return;

    listView.classList.add('d-none');
    chatView.classList.remove('d-none');
    document.getElementById('drawerChatHeader').textContent = studentName || 'Chat';
    document.getElementById('drawerSelectedStudentId').value = studentId;
    loadDrawerAdminMessages();
}

function closeDrawerChat() {
    const listView = document.getElementById('drawerStudentListView');
    const chatView = document.getElementById('drawerChatView');
    if (!listView || !chatView) return;

    chatView.classList.add('d-none');
    listView.classList.remove('d-none');
}

function loadDrawerAdminMessages() {
    const studentId = document.getElementById('drawerSelectedStudentId')?.value;
    const container = document.getElementById('drawerChatContainer');
    if (!studentId || !container) return;

    fetch(`dashboard.php?chat_action=fetch&target_user_id=${studentId}`)
        .then(res => {
            if (!res.ok) throw new Error('Network response was not ok');
            return res.json();
        })
        .then(data => {
            if (data.status === 'success') {
                container.innerHTML = '';

                if (!data.messages || data.messages.length === 0) {
                    container.innerHTML = `
                        <div class="text-center text-muted py-4">
                            <p class="small mb-0">No messages in this conversation yet.</p>
                        </div>`;
                    return;
                }

                data.messages.forEach(msg => {
                    const isAdmin = msg.sender_role === 'admin';
                    const align = isAdmin ? 'text-end' : 'text-start';
                    const bg = isAdmin ? 'bg-danger text-white' : 'bg-light text-dark border';

                    container.innerHTML += `
                        <div class="mb-2 ${align}">
                            <div class="d-inline-block p-2 rounded-3 small ${bg}" style="max-width: 85%; word-wrap: break-word;">
                                ${escapeHtml(msg.message)}
                            </div>
                            <div class="text-muted" style="font-size: 0.65rem;">${msg.time || ''}</div>
                        </div>`;
                });
                container.scrollTop = container.scrollHeight;
            }
        })
        .catch(err => {
            console.error('Failed to load admin messages:', err);
        });
}

function sendDrawerAdminReply(e) {
    e.preventDefault();
    const studentId = document.getElementById('drawerSelectedStudentId')?.value;
    const input = document.getElementById('drawerReplyMsg');
    if (!input || !studentId) return;

    const msg = input.value.trim();
    if (!msg) return;

    const formData = new FormData();
    formData.append('target_user_id', studentId);
    formData.append('message', msg);

    const btn = e.target.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;

    fetch('dashboard.php?chat_action=send', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            input.value = '';
            loadDrawerAdminMessages();
        } else {
            alert(data.message || 'Failed to send reply.');
        }
    })
    .catch(err => {
        console.error(err);
        alert('Network error. Please try again.');
    })
    .finally(() => {
        if (btn) btn.disabled = false;
    });
}

// Background Polling (5s interval)
setInterval(() => {
    const studentBox = document.getElementById('studentChatBox');
    if (studentBox && !studentBox.classList.contains('d-none')) {
        loadStudentMessages();
    }

    const drawerChatView = document.getElementById('drawerChatView');
    if (drawerChatView && !drawerChatView.classList.contains('d-none')) {
        loadDrawerAdminMessages();
    }
}, 5000);
</script>
</body>
</html>