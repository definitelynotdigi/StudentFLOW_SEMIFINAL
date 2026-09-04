<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php?login_error=expired");
    exit();
}

$timeout_duration = 900; 
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout_duration)) {
    session_unset();
    session_destroy(); 
    header("Location: index.php?login_error=expired");
    exit();
}
$_SESSION['last_activity'] = time();

require_once 'database.php';

// Ensure support messages table exists
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

// Define Admin/Department privilege check
$isAdminOrDept = ($_SESSION['role'] === 'Admin' || $_SESSION['role'] === 'Department');

// Fetch announcements list & total count
$announcements_stmt = $pdo->query("SELECT * FROM announcements ORDER BY id DESC");
$announcements_list = $announcements_stmt->fetchAll();
$ann_count = count($announcements_list);

// Fetch unread messages count for Admin/Department bell badge
$unread_msg_count = 0;
if ($isAdminOrDept) {
    $unread_stmt = $pdo->query("SELECT COUNT(*) FROM support_messages WHERE is_read = 0 AND sender_role = 'student'");
    $unread_msg_count = (int)$unread_stmt->fetchColumn();
}
$total_notifications = $ann_count + $unread_msg_count;

// Handle AJAX Chat Endpoints
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

// Handle POST actions for Department / Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_SESSION['role'] === 'Department' || $_SESSION['role'] === 'Admin') {
        
        // Post New Announcement
        if (isset($_POST['post_announcement'])) {
            $stmt = $pdo->prepare("INSERT INTO announcements (title, content, posted_by) VALUES (?, ?, ?)");
            $stmt->execute([$_POST['title'], $_POST['content'], $_SESSION['username']]);
            header("Location: dashboard.php");
            exit();
        }
        
        // Update Existing Announcement
        if (isset($_POST['edit_announcement'])) {
            $stmt = $pdo->prepare("UPDATE announcements SET title = ?, content = ? WHERE id = ?");
            $stmt->execute([$_POST['ann_title'], $_POST['ann_content'], $_POST['ann_id']]);
            header("Location: dashboard.php");
            exit();
        }

        // Delete Announcement
        if (isset($_POST['delete_announcement'])) {
            $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
            $stmt->execute([$_POST['ann_id']]);
            header("Location: dashboard.php");
            exit();
        }

        // Grade Recording Handler
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

        // Financial Ledger Recording Handler
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
    <title>GRC Portal - System Center</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --grc-primary: #800000;
            --grc-secondary: #ab0a0a;
            --grc-hover: #ff1e1e;
            --grc-dark: #200404;
            --grc-bg: #f4f6f9;
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--grc-bg); color: #2d3748; }
        .navbar-grc { background: linear-gradient(135deg, var(--grc-dark) 0%, var(--grc-secondary) 100%); border-bottom: 3px solid var(--grc-hover); }
        .dashboard-header { background: #ffffff; border-radius: 12px; border-left: 6px solid var(--grc-secondary); box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
        .card-custom { background: #ffffff; border: none; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.04); transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .card-custom:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.06); }
        .text-grc { color: var(--grc-secondary); }
        .btn-grc-action { background: linear-gradient(135deg, var(--grc-secondary) 0%, var(--grc-primary) 100%); color: #ffffff; border: none; border-radius: 6px; transition: all 0.2s ease; }
        .btn-grc-action:hover { background: var(--grc-hover); color: #ffffff; box-shadow: 0 4px 12px rgba(255, 30, 30, 0.3); }
        .announcement-item { border-left: 3px solid var(--grc-secondary); padding-left: 12px; }
        .bell-btn { position: relative; background: rgba(255, 255, 255, 0.15); color: #fff; border: none; border-radius: 50%; width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease; }
        .bell-btn:hover { background: rgba(255, 255, 255, 0.3); color: #fff; }
        .bell-badge { position: absolute; top: 2px; right: 2px; font-size: 0.65rem; padding: 2px 5px; border-radius: 10px; }
    </style>
</head>
<body>

<nav class="navbar navbar-grc navbar-dark navbar-expand-lg p-3 shadow-sm">
    <div class="container-fluid px-lg-4">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="index.php">
            <img src="assets/grc2.png" alt="Logo" style="height: 32px; width: auto;">
        </a>
        <div class="d-flex align-items-center gap-2">
            <button class="bell-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#announcementsDrawer" aria-controls="announcementsDrawer" title="View Notifications">
                <i class="fa-solid fa-bell"></i>
                <?php if ($total_notifications > 0): ?>
                    <span class="badge bg-danger bell-badge"><?= $total_notifications ?></span>
                <?php endif; ?>
            </button>

            <a href="logout.php" class="btn btn-outline-light btn-sm fw-semibold rounded-pill px-3 ms-2">
                <i class="fa-solid fa-right-from-bracket me-1"></i> Secure Logout
            </a>
        </div>
    </div>
</nav>

<!-- PROFILE INFORMATION MODAL -->
<div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header text-white" style="background: linear-gradient(135deg, var(--grc-dark) 0%, var(--grc-secondary) 100%);">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-gear me-2"></i>Registration Profile Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="text-center mb-4">
                    <div class="rounded-circle bg-danger bg-opacity-10 d-inline-flex p-3 text-grc mb-2">
                        <i class="fa-solid fa-user-circle fa-3x"></i>
                    </div>
                    <h5 class="fw-bold mb-0"><?= htmlspecialchars($display_name) ?></h5>
                    <span class="badge px-3 py-1 rounded-pill mt-1" style="background-color: var(--grc-primary);"><?= htmlspecialchars($user_data['role'] ?? 'User') ?></span>
                </div>
                <div class="list-group list-group-flush border-top border-bottom">
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold"><i class="fa-solid fa-id-card me-2 text-grc"></i><?= ($user_data['role'] === 'Department') ? 'Dept ID' : 'Student ID' ?>:</span>
                        <span class="fw-bold small"><?= htmlspecialchars($user_data['student_id'] ?? 'N/A') ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold"><i class="fa-solid fa-user me-2 text-grc"></i>Full Name:</span>
                        <span class="fw-bold small"><?= htmlspecialchars(($user_data['first_name'] ?? '') . ' ' . ($user_data['last_name'] ?? '')) ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold"><i class="fa-solid fa-at me-2 text-grc"></i>Username:</span>
                        <span class="fw-bold small"><?= htmlspecialchars($user_data['username'] ?? 'N/A') ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold"><i class="fa-solid fa-envelope me-2 text-grc"></i>Email Address:</span>
                        <span class="fw-bold small"><?= htmlspecialchars($user_data['email'] ?? 'N/A') ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold"><i class="fa-solid fa-phone me-2 text-grc"></i>Phone Number:</span>
                        <span class="fw-bold small"><?= htmlspecialchars($user_data['phone'] ?? 'N/A') ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold"><i class="fa-solid fa-graduation-cap me-2 text-grc"></i>Course / Department:</span>
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

<!-- OFFCANVAS NOTIFICATIONS & INBOX SIDE DRAWER -->
<div class="offcanvas offcanvas-end" style="width: 450px;" tabindex="-1" id="announcementsDrawer" aria-labelledby="announcementsDrawerLabel">
    <div class="offcanvas-header text-white" style="background: var(--grc-dark); border-bottom: 3px solid var(--grc-secondary);">
        <h5 class="offcanvas-title fw-bold" id="announcementsDrawerLabel">
            <i class="fa-solid fa-bell text-danger me-2"></i>Notifications
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    
    <?php if ($_SESSION['role'] === 'Admin' || $_SESSION['role'] === 'Department'): ?>
    <!-- Navigation Tabs for Admin/Department -->
    <ul class="nav nav-tabs nav-justified bg-light border-bottom" id="drawerTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-semibold text-dark py-2" id="inbox-tab" data-bs-toggle="tab" data-bs-target="#drawerInbox" type="button" role="tab">
                <i class="fa-solid fa-comments me-1 text-danger"></i> Messages
                <?php if ($unread_msg_count > 0): ?>
                    <span class="badge bg-danger rounded-pill ms-1"><?= $unread_msg_count ?></span>
                <?php endif; ?>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-semibold text-dark py-2" id="announcements-tab" data-bs-toggle="tab" data-bs-target="#drawerAnnouncements" type="button" role="tab">
                <i class="fa-solid fa-bullhorn me-1 text-danger"></i> Broadcasts
            </button>
        </li>
    </ul>
    <?php endif; ?>

    <div class="offcanvas-body bg-light">
        <div class="tab-content" id="drawerTabContent">
            
            <?php if ($_SESSION['role'] === 'Admin' || $_SESSION['role'] === 'Department'): ?>
            <!-- TAB 1: Solution Requests Inbox -->
            <div class="tab-pane fade show active" id="drawerInbox" role="tabpanel">
                <!-- Student List View -->
                <div id="drawerStudentListView">
                    <h6 class="fw-bold mb-3 text-grc"><i class="fa-solid fa-inbox me-2"></i>Student Requests</h6>
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
                                <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" onclick="openDrawerAdminChat(<?= $st['id'] ?>, '<?= addslashes($st_name) ?>')">
                                    <div>
                                        <strong class="d-block text-dark small"><?= $st_name ?></strong>
                                        <small class="text-muted"><?= htmlspecialchars($st['student_id'] ?: 'No ID') ?></small>
                                    </div>
                                    <?php if ($st['unread'] > 0): ?>
                                        <span class="badge bg-danger rounded-pill"><?= $st['unread'] ?></span>
                                    <?php endif; ?>
                                </button>
                        <?php 
                            endforeach;
                        else:
                        ?>
                            <div class="p-3 text-center text-muted small bg-white rounded border">No student solution messages yet.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Chat Conversation View -->
                <div id="drawerChatView" class="d-none">
                    <div class="d-flex align-items-center justify-content-between pb-2 mb-2 border-bottom">
                        <button class="btn btn-sm btn-outline-secondary rounded-pill" onclick="closeDrawerChat()">
                            <i class="fa-solid fa-arrow-left me-1"></i> Back to Inbox
                        </button>
                        <span class="fw-bold small text-dark" id="drawerChatHeader">Chat</span>
                    </div>
                    <div class="card-body p-2 bg-white rounded border" id="drawerChatContainer" style="height: 320px; overflow-y: auto;">
                    </div>
                    <form onsubmit="sendDrawerAdminReply(event)" class="mt-2">
                        <input type="hidden" id="drawerSelectedStudentId">
                        <div class="input-group">
                            <input type="text" id="drawerReplyMsg" class="form-control form-control-sm" placeholder="Type solution reply..." required>
                            <button type="submit" class="btn btn-grc-action btn-sm"><i class="fa-solid fa-paper-plane"></i></button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- TAB 2: Campus Announcements -->
            <div class="tab-pane fade <?php if ($_SESSION['role'] === 'Student') echo 'show active'; ?>" id="drawerAnnouncements" role="tabpanel">
                <?php if ($ann_count > 0): ?>
                    <?php foreach ($announcements_list as $ann): ?>
                        <div class="announcement-item mb-3 pb-2 bg-white p-3 rounded-3 shadow-sm border">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <strong class="text-dark">
                                    <i class="fa-solid fa-circle-info text-danger me-1 small"></i><?= htmlspecialchars($ann['title']) ?>
                                </strong>
                                
                                <?php if ($_SESSION['role'] === 'Department' || $_SESSION['role'] === 'Admin'): ?>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary btn-sm py-0 px-1 border-0 edit-ann-btn" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#editAnnouncementModal"
                                                data-id="<?= $ann['id'] ?>"
                                                data-title="<?= htmlspecialchars($ann['title']) ?>"
                                                data-content="<?= htmlspecialchars($ann['content']) ?>">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this announcement?');">
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
                    <div class="text-center text-muted py-5">
                        <i class="fa-regular fa-bell-slash fa-2x mb-2 d-block"></i>
                        <p class="small">No active announcements broadcasted.</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<!-- EDIT ANNOUNCEMENT MODAL -->
<div class="modal fade" id="editAnnouncementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header text-white" style="background: var(--grc-dark);">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2 text-danger"></i>Edit Announcement</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <input type="hidden" name="edit_announcement" value="1">
                    <input type="hidden" name="ann_id" id="editAnnId">
                    
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Title</label>
                        <input type="text" name="ann_title" id="editAnnTitle" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Content</label>
                        <textarea name="ann_content" id="editAnnContent" class="form-control form-control-sm" rows="4" required></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-grc-action fw-semibold">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="container my-5 px-3 px-lg-4">
    <div class="dashboard-header p-4 p-md-5 mb-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-danger bg-opacity-10 p-3 text-grc">
                    <i class="fa-solid fa-user-circle fa-2x"></i>
                </div>
                <div>
                    <h2 class="fw-bold mb-1">Welcome Back, <?= htmlspecialchars($display_name); ?>!</h2>
                    <p class="text-muted mb-0">Role Context Profile: <span class="badge px-3 py-2 rounded-pill" style="background-color: var(--grc-primary);"><i class="fa-solid fa-shield-halved me-1"></i><?= $_SESSION['role']; ?></span></p>
                </div>
            </div>
            <button class="btn btn-outline-danger btn-sm fw-semibold px-3 py-2 rounded-pill" data-bs-toggle="modal" data-bs-target="#profileModal">
                <i class="fa-solid fa-id-card me-1"></i> View Profile Info
            </button>
        </div>
    </div>

    <?php if ($_SESSION['role'] === 'Student'): ?>
        <div class="row g-4">
            <div class="col-lg-8">
                <!-- Academic Grades Section -->
                <div class="card card-custom p-4 mb-4">
                    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                        <h4 class="fw-bold text-grc mb-0"><i class="fa-solid fa-book-bookmark me-2"></i>Academic Report Card</h4>
                        <a href="print.php?type=grades" target="_blank" class="btn btn-grc-action btn-sm fw-semibold px-3 py-2 rounded-pill">
                            <i class="fa-solid fa-print me-1"></i> Print Official Grades
                        </a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-dark">
                                <tr><th>Code</th><th>Subject Title</th><th>Grade</th><th>Semester</th></tr>
                            </thead>
                            <tbody>
                                <?php
                                $stmt = $pdo->prepare("SELECT * FROM academic_records WHERE student_id = ?");
                                $stmt->execute([$_SESSION['user_id']]);
                                $grades = $stmt->fetchAll();
                                
                                $total_grades = 0;
                                $grade_count = count($grades);
                                
                                if ($grade_count > 0):
                                    foreach ($grades as $row):
                                        $total_grades += floatval($row['grade']);
                                ?>
                                        <tr>
                                            <td><span class='fw-semibold'><?= htmlspecialchars($row['subject_code']) ?></span></td>
                                            <td><?= htmlspecialchars($row['subject_title']) ?></td>
                                            <td><span class='badge bg-success px-2 py-1'><i class='fa-solid fa-check me-1'></i><?= number_format($row['grade'], 2) ?></span></td>
                                            <td><?= htmlspecialchars($row['semester']) ?></td>
                                        </tr>
                                <?php 
                                    endforeach;
                                else:
                                ?>
                                    <tr><td colspan="4" class="text-center text-muted py-3">No academic records found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                            <?php if ($grade_count > 0): ?>
                            <tfoot class="table-light border-top">
                                <tr>
                                    <td colspan="2" class="fw-bold text-end">General Weighted Average (GWA):</td>
                                    <td colspan="2" class="fw-bold text-success fs-6"><?= number_format($total_grades / $grade_count, 2) ?></td>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>

                <!-- Financial Ledger Statement Section -->
                <div class="card card-custom p-4 mb-4">
                    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                        <h4 class="fw-bold text-grc mb-0"><i class="fa-solid fa-receipt me-2"></i>Financial Statement Ledger</h4>
                        <a href="print.php?type=ledger" target="_blank" class="btn btn-grc-action btn-sm fw-semibold px-3 py-2 rounded-pill">
                            <i class="fa-solid fa-print me-1"></i> Print Official Statement
                        </a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-dark">
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
                                            $badge_class = 'bg-danger';
                                        } else {
                                            $total_balance -= $amt;
                                            $badge_class = 'bg-success';
                                        }
                                ?>
                                    <tr>
                                        <td><small class="text-muted"><?= date('M d, Y', strtotime($l_row['transaction_date'])) ?></small></td>
                                        <td><strong><?= htmlspecialchars($l_row['description']) ?></strong></td>
                                        <td><span class="badge <?= $badge_class ?>"><?= strtoupper(htmlspecialchars($l_row['transaction_type'])) ?></span></td>
                                        <td class="text-end fw-bold">₱<?= number_format($amt, 2) ?></td>
                                    </tr>
                                <?php 
                                    endforeach;
                                else: 
                                ?>
                                    <tr><td colspan="4" class="text-center text-muted py-3">No financial ledger records found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light border-top">
                                <tr>
                                    <td colspan="3" class="fw-bold text-end">Current Outstanding Balance:</td>
                                    <td class="text-end fw-bold text-danger fs-6">₱<?= number_format($total_balance, 2) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card card-custom p-4 h-100">
                    <h5 class="fw-bold text-grc mb-3"><i class="fa-solid fa-bullhorn me-2"></i>Campus Broadcasts</h5>
                    <hr class="mt-0">
                    <div class="overflow-auto" style="max-height: 500px;">
                        <?php if ($ann_count > 0): ?>
                            <?php foreach ($announcements_list as $row): ?>
                                <div class="announcement-item mb-3 pb-2 bg-light p-3 rounded-3 shadow-sm">
                                    <strong class="text-dark d-block mb-1"><i class="fa-solid fa-circle-info text-danger me-1 small"></i><?= htmlspecialchars($row['title']) ?></strong>
                                    <p class="small text-muted mb-2"><?= htmlspecialchars($row['content']) ?></p>
                                    <small class="text-secondary d-block text-end fw-semibold">— <?= htmlspecialchars($row['posted_by']) ?></small>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="small text-muted text-center py-4">No broadcasts posted yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Student Floating Solution Request Chat Widget -->
        <button class="btn btn-grc-action position-fixed bottom-0 end-0 m-4 rounded-circle shadow-lg p-3 d-flex align-items-center justify-content-center" style="z-index: 1000; width: 55px; height: 55px;" onclick="toggleStudentChat()">
            <i class="fa-solid fa-comments fs-4"></i>
        </button>

        <div id="studentChatBox" class="card position-fixed bottom-0 end-0 m-4 shadow-lg d-none" style="width: 350px; z-index: 1001; border-radius: 12px; overflow: hidden;">
            <div class="card-header text-white d-flex justify-content-between align-items-center" style="background: var(--grc-dark); border-bottom: 2px solid var(--grc-secondary);">
                <h6 class="mb-0 fw-bold"><i class="fa-solid fa-headset text-danger me-2"></i>Solution & Help Desk</h6>
                <button type="button" class="btn-close btn-close-white btn-sm" onclick="toggleStudentChat()"></button>
            </div>
            <div class="card-body p-3" id="studentChatContainer" style="height: 300px; overflow-y: auto; background-color: #f8f9fa;">
            </div>
            <div class="card-footer bg-white border-top p-2">
                <form onsubmit="sendStudentMessage(event)">
                    <div class="input-group">
                        <input type="text" id="studentChatMessage" class="form-control form-control-sm" placeholder="Ask for a solution..." required>
                        <button type="submit" class="btn btn-grc-action btn-sm"><i class="fa-solid fa-paper-plane"></i></button>
                    </div>
                </form>
            </div>
        </div>

    <?php elseif ($_SESSION['role'] === 'Admin' || $_SESSION['role'] === 'Department'): ?>
        <!-- Department & Admin Forms Section -->
        <div class="row g-4 mb-4">
            <!-- Academic Grade Form -->
            <div class="col-md-6">
                <div class="card card-custom p-4 h-100">
                    <h4 class="fw-bold text-grc mb-3"><i class="fa-solid fa-pen-to-square me-2"></i>Grade Recording Registry</h4>
                    <form method="POST">
                        <input type="hidden" name="add_grade" value="1">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Target Student Identifier ID</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="fa-solid fa-id-badge"></i></span>
                                <input type="text" name="stud_id" class="form-control" placeholder="e.g. 2024-08-01129 or ID Number" required>
                            </div>
                        </div>
                        <div class="row mb-3 g-2">
                            <div class="col-6">
                                <input type="text" name="sub_code" class="form-control form-control-sm" placeholder="Subject Code" required>
                            </div>
                            <div class="col-6">
                                <input type="text" name="sub_title" class="form-control form-control-sm" placeholder="Subject Title" required>
                            </div>
                        </div>
                        <div class="row mb-3 g-2">
                            <div class="col-6">
                                <input type="number" step="0.01" name="grade_val" class="form-control form-control-sm" placeholder="Grade Value" required>
                            </div>
                            <div class="col-6">
                                <select name="sem" class="form-select form-select-sm">
                                    <option>1st Semester</option>
                                    <option>2nd Semester</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-grc-action btn-sm w-100 py-2 fw-semibold"><i class="fa-solid fa-plus-circle me-1"></i>Commit Record Entry Changes</button>
                    </form>
                </div>
            </div>

            <!-- Financial Ledger Form -->
            <div class="col-md-6">
                <div class="card card-custom p-4 h-100">
                    <h4 class="fw-bold text-grc mb-3"><i class="fa-solid fa-calculator me-2"></i>Financial Ledger Entry System</h4>
                    <form method="POST">
                        <input type="hidden" name="add_ledger" value="1">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Target Student Identifier ID</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="fa-solid fa-id-badge"></i></span>
                                <input type="text" name="ledger_stud_id" class="form-control" placeholder="e.g. 2024-08-01129 or ID Number" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <input type="text" name="description" class="form-control form-control-sm" placeholder="Description (e.g. Tuition Fee, Payment)" required>
                        </div>
                        <div class="row mb-3 g-2">
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
                        <button type="submit" class="btn btn-grc-action btn-sm w-100 py-2 fw-semibold"><i class="fa-solid fa-file-invoice-dollar me-1"></i>Post Ledger Entry</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- System Alert Bulletin -->
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card card-custom p-4">
                    <h4 class="fw-bold text-grc mb-3"><i class="fa-solid fa-paper-plane me-2"></i>Broadcast New System Alert Bulletin</h4>
                    <form method="POST">
                        <input type="hidden" name="post_announcement" value="1">
                        <div class="mb-3">
                            <input type="text" name="title" class="form-control form-control-sm" placeholder="Announcement Title Heading" required>
                        </div>
                        <div class="mb-3">
                            <textarea name="content" class="form-control form-control-sm" rows="3" placeholder="Type notification information content body copy text..." required></textarea>
                        </div>
                        <button type="submit" class="btn btn-grc-action btn-sm w-100 py-2 fw-bold"><i class="fa-solid fa-share-from-square me-1"></i>Publish to Wall Feed</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="card card-custom p-4">
            <h4 class="fw-bold text-grc mb-4"><i class="fa-solid fa-users-gear me-2"></i>Core User Directory & Operational Auditing System Console Dashboard</h4>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-dark">
                        <tr><th>User ID</th><th>Username</th><th>Email</th><th>Role</th><th>Student / Dept ID</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $users_stmt = $pdo->query("SELECT id, username, email, role, student_id, first_name, last_name, phone, department_course FROM users");
                        while($row = $users_stmt->fetch()) {
                            $user_json = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                            echo "<tr>
                                    <td><span class='badge bg-secondary'>#{$row['id']}</span></td>
                                    <td><strong><i class='fa-regular fa-user me-2 text-muted'></i>{$row['username']}</strong></td>
                                    <td>{$row['email']}</td>
                                    <td><span class='badge px-3 py-1 rounded-pill' style='background-color: var(--grc-primary);'>{$row['role']}</span></td>
                                    <td><small class='fw-bold'>{$row['student_id']}</small></td>
                                    <td>
                                        <button class='btn btn-sm btn-outline-danger view-user-btn' data-user='{$user_json}'>
                                            <i class='fa-solid fa-eye me-1'></i> View Profile
                                        </button>
                                    </td>
                                  </tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ADMIN USER PROFILE DETAILS MODAL -->
<div class="modal fade" id="adminUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header text-white" style="background: var(--grc-dark);">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-circle me-2 text-danger"></i>User Detail Record</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="list-group list-group-flush">
                    <div class="list-group-item d-flex justify-content-between py-2"><span class="text-muted small">System ID:</span><span id="u_id" class="fw-bold small"></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2"><span class="text-muted small">ID Number:</span><span id="u_studentid" class="fw-bold small"></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2"><span class="text-muted small">Full Name:</span><span id="u_fullname" class="fw-bold small"></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2"><span class="text-muted small">Username:</span><span id="u_username" class="fw-bold small"></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2"><span class="text-muted small">Email Address:</span><span id="u_email" class="fw-bold small"></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2"><span class="text-muted small">Phone Number:</span><span id="u_phone" class="fw-bold small"></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2"><span class="text-muted small">Course / Department:</span><span id="u_dept" class="fw-bold small"></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2"><span class="text-muted small">Role:</span><span id="u_role" class="fw-bold small text-danger"></span></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Populate the Edit Announcement Modal
    const editBtns = document.querySelectorAll('.edit-ann-btn');
    editBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('editAnnId').value = btn.getAttribute('data-id');
            document.getElementById('editAnnTitle').value = btn.getAttribute('data-title');
            document.getElementById('editAnnContent').value = btn.getAttribute('data-content');
        });
    });

    // Populate Admin User Inspector Modal
    const viewUserBtns = document.querySelectorAll('.view-user-btn');
    viewUserBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const u = JSON.parse(btn.getAttribute('data-user'));
            document.getElementById('u_id').textContent = '#' + u.id;
            document.getElementById('u_studentid').textContent = u.student_id || 'N/A';
            document.getElementById('u_fullname').textContent = (u.first_name || '') + ' ' + (u.last_name || '');
            document.getElementById('u_username').textContent = u.username;
            document.getElementById('u_email').textContent = u.email;
            document.getElementById('u_phone').textContent = u.phone || 'N/A';
            document.getElementById('u_dept').textContent = u.department_course || 'N/A';
            document.getElementById('u_role').textContent = u.role;

            new bootstrap.Modal(document.getElementById('adminUserModal')).show();
        });
    });
});

// STUDENT CHAT FUNCTIONS
function toggleStudentChat() {
    const box = document.getElementById('studentChatBox');
    box.classList.toggle('d-none');
    if (!box.classList.contains('d-none')) {
        loadStudentMessages();
    }
}

function loadStudentMessages() {
    fetch('dashboard.php?chat_action=fetch')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const container = document.getElementById('studentChatContainer');
                container.innerHTML = '';
                data.messages.forEach(msg => {
                    const isStudent = msg.sender_role === 'student';
                    const align = isStudent ? 'text-end' : 'text-start';
                    const bg = isStudent ? 'bg-danger text-white' : 'bg-secondary text-white';
                    
                    container.innerHTML += `
                        <div class="mb-2 ${align}">
                            <div class="d-inline-block p-2 rounded-3 small ${bg}" style="max-width: 80%;">
                                ${msg.message}
                            </div>
                            <div class="text-muted" style="font-size: 0.65rem;">${msg.time}</div>
                        </div>
                    `;
                });
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

    fetch('dashboard.php?chat_action=send', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                input.value = '';
                loadStudentMessages();
            }
        });
}

// DRAWER ADMIN CHAT FUNCTIONS
function openDrawerAdminChat(studentId, studentName) {
    document.getElementById('drawerStudentListView').classList.add('d-none');
    document.getElementById('drawerChatView').classList.remove('d-none');
    document.getElementById('drawerChatHeader').textContent = studentName;
    document.getElementById('drawerSelectedStudentId').value = studentId;
    loadDrawerAdminMessages();
}

function closeDrawerChat() {
    document.getElementById('drawerChatView').classList.add('d-none');
    document.getElementById('drawerStudentListView').classList.remove('d-none');
}

function loadDrawerAdminMessages() {
    const studentId = document.getElementById('drawerSelectedStudentId').value;
    if (!studentId) return;

    fetch(`dashboard.php?chat_action=fetch&target_user_id=${studentId}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const container = document.getElementById('drawerChatContainer');
                container.innerHTML = '';
                data.messages.forEach(msg => {
                    const isAdmin = msg.sender_role === 'admin';
                    const align = isAdmin ? 'text-end' : 'text-start';
                    const bg = isAdmin ? 'bg-danger text-white' : 'bg-light text-dark border';
                    
                    container.innerHTML += `
                        <div class="mb-2 ${align}">
                            <div class="d-inline-block p-2 rounded-3 small ${bg}" style="max-width: 85%;">
                                ${msg.message}
                            </div>
                            <div class="text-muted" style="font-size: 0.65rem;">${msg.time}</div>
                        </div>
                    `;
                });
                container.scrollTop = container.scrollHeight;
            }
        });
}

function sendDrawerAdminReply(e) {
    e.preventDefault();
    const studentId = document.getElementById('drawerSelectedStudentId').value;
    const input = document.getElementById('drawerReplyMsg');
    const msg = input.value.trim();
    if (!msg || !studentId) return;

    const formData = new FormData();
    formData.append('target_user_id', studentId);
    formData.append('message', msg);

    fetch('dashboard.php?chat_action=send', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                input.value = '';
                loadDrawerAdminMessages();
            }
        });
}

// Background polling loop
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