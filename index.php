<?php
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

// Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Database Connection
$conn = new mysqli("localhost", "root", "", "student_portal_db");

// State flags for modal popups
$active_modal = ""; // 'login', 'register', 'forgot'
$msg = "";
$msg_type = "";

// Fetch announcements for landing page
$announcements = [];
try {
    $res_ann = $conn->query("SELECT title, content, created_at FROM announcements ORDER BY created_at DESC LIMIT 3");
    if ($res_ann) {
        $announcements = $res_ann->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    $announcements = [];
}

// -----------------------------------------------------------------------------
// 1. FORGOT PASSWORD HANDLER
// -----------------------------------------------------------------------------
$forgot_step = $_SESSION['forgot_step'] ?? 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_change_email') {
    $active_modal = 'forgot';
    $_SESSION['forgot_step'] = 1;
    $forgot_step = 1;
    unset($_SESSION['reset_email']);
    unset($_SESSION['otp_verified']);
}

// STEP 1: Send OTP to Email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_send_otp') {
    $active_modal = 'forgot';
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Validation Failed: Invalid CSRF Token.");
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $email = trim($_POST['email']);
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {
        $otp = sprintf("%06d", random_int(100000, 999999));
        $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        $update = $conn->prepare("UPDATE users SET otp_code = ?, otp_expires = ? WHERE email = ?");
        $update->bind_param("sss", $otp, $expires, $email);
        $update->execute();

        $_SESSION['reset_email'] = $email;

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'digi.nymous@gmail.com';
            $mail->Password   = 'rbogocbeamvbqtlk';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            $mail->setFrom($mail->Username, 'GRC Student Portal');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = 'Password Reset OTP Verification Code';
            $mail->Body    = "<div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #ddd;'>
                                <h3 style='color: #ab0a0a;'>Global Reciprocal Colleges</h3>
                                <p>Your OTP verification code to reset your password is:</p>
                                <h1 style='letter-spacing: 5px; color: #ab0a0a;'>{$otp}</h1>
                                <p><small>This code will expire in 10 minutes.</small></p>
                              </div>";
            $mail->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));
            $mail->send();

            $_SESSION['forgot_step'] = 2;
            $forgot_step = 2;
            $msg = "A 6-digit OTP code has been sent to " . htmlspecialchars($email);
            $msg_type = "success";
        } catch (Exception $e) {
            $msg = "Error sending OTP email: " . $mail->ErrorInfo;
            $msg_type = "danger";
        }
    } else {
        $_SESSION['reset_email'] = $email;
        $_SESSION['forgot_step'] = 2;
        $forgot_step = 2;
        $msg = "If that email exists in our records, an OTP code has been dispatched.";
        $msg_type = "info";
    }
}

// STEP 2: Verify OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_verify_otp') {
    $active_modal = 'forgot';
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Validation Failed: Invalid CSRF Token.");
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $email = $_SESSION['reset_email'] ?? '';
    $otp_input = trim($_POST['otp_code']);

    $stmt = $conn->prepare("SELECT id, otp_expires FROM users WHERE email = ? AND otp_code = ?");
    $stmt->bind_param("ss", $email, $otp_input);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {
        $user = $res->fetch_assoc();
        if (strtotime($user['otp_expires']) >= time()) {
            $_SESSION['otp_verified'] = true;
            $_SESSION['forgot_step'] = 3;
            $forgot_step = 3;
            $msg = "OTP verified successfully! Please enter your new password.";
            $msg_type = "success";
        } else {
            $forgot_step = 2;
            $msg = "The OTP code has expired. Please request a new one.";
            $msg_type = "danger";
        }
    } else {
        $forgot_step = 2;
        $msg = "Invalid OTP verification code.";
        $msg_type = "danger";
    }
}

// STEP 3: Reset Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_reset_password') {
    $active_modal = 'forgot';
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Validation Failed: Invalid CSRF Token.");
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    if (empty($_SESSION['otp_verified']) || $_SESSION['otp_verified'] !== true) {
        $forgot_step = 1;
        $_SESSION['forgot_step'] = 1;
        $msg = "Session invalid. Please start over.";
        $msg_type = "danger";
    } else {
        $email = $_SESSION['reset_email'] ?? '';
        $new_pass = $_POST['new_password'];
        $confirm_pass = $_POST['confirm_password'];
        $forgot_step = 3;

        $email_username = explode('@', $email)[0];
        $pattern = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*(),.?":{}|<>]).{8,}$/';

        if ($new_pass !== $confirm_pass) {
            $msg = "Passwords do not match.";
            $msg_type = "danger";
        } elseif (!preg_match($pattern, $new_pass)) {
            $msg = "Password must be at least 8 characters with upper, lower, number, and special character.";
            $msg_type = "danger";
        } elseif (!empty($email_username) && stripos($new_pass, $email_username) !== false) {
            $msg = "Password must not include your email username.";
            $msg_type = "danger";
        } else {
            $hash = password_hash($new_pass, PASSWORD_BCRYPT);
            $update = $conn->prepare("UPDATE users SET password_hash = ?, otp_code = NULL, otp_expires = NULL, failed_attempts = 0, lockout_until = NULL WHERE email = ?");
            $update->bind_param("ss", $hash, $email);
            $update->execute();

            unset($_SESSION['reset_email']);
            unset($_SESSION['forgot_step']);
            unset($_SESSION['otp_verified']);
            $active_modal = 'login';
            $msg = "Password reset successful! Please log in.";
            $msg_type = "success";
        }
    }
}

// -----------------------------------------------------------------------------
// 2. REGISTER HANDLER
// -----------------------------------------------------------------------------
$reg_step = $_SESSION['reg_step'] ?? 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reg_back_step1') {
    $_SESSION['reg_step'] = 1;
    $reg_step = 1;
    $active_modal = 'register';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reg_submit_details') {
    $active_modal = 'register';
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Validation Failed: Invalid CSRF Token.");
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $role = $_POST['role'];
    $student_id = trim($_POST['student_id']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $dept_course = trim($_POST['department_course']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    $email_username = explode('@', $email)[0];
    $pattern = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*(),.?":{}|<>]).{8,}$/';

    $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? OR student_id = ?");
    $check->bind_param("sss", $username, $email, $student_id);
    $check->execute();
    $res = $check->get_result();

    if ($res->num_rows > 0) {
        $msg = "Username, email, or ID Number is already registered.";
        $msg_type = "danger";
    } elseif ($password !== $confirm_password) {
        $msg = "Passwords do not match.";
        $msg_type = "danger";
    } elseif (!preg_match($pattern, $password)) {
        $msg = "Password must be at least 8 characters with upper, lower, number, and special character.";
        $msg_type = "danger";
    } elseif (!empty($email_username) && stripos($password, $email_username) !== false) {
        $msg = "Password must not include your email username.";
        $msg_type = "danger";
    } else {
        $otp = sprintf("%06d", random_int(100000, 999999));
        $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        $pass_hash = password_hash($password, PASSWORD_BCRYPT);

        $existing = $conn->prepare("SELECT id FROM users WHERE email = ? AND is_verified = 0");
        $existing->bind_param("s", $email);
        $existing->execute();
        $ex_res = $existing->get_result();

        if ($ex_res->num_rows > 0) {
            $user_id = $ex_res->fetch_assoc()['id'];
            $stmt = $conn->prepare("UPDATE users SET username = ?, password_hash = ?, role = ?, student_id = ?, first_name = ?, last_name = ?, phone = ?, department_course = ?, otp_code = ?, otp_expires = ? WHERE id = ?");
            $stmt->bind_param("ssssssssssi", $username, $pass_hash, $role, $student_id, $first_name, $last_name, $phone, $dept_course, $otp, $expires, $user_id);
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, student_id, first_name, last_name, phone, department_course, otp_code, otp_expires, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
            $stmt->bind_param("sssssssssss", $username, $email, $pass_hash, $role, $student_id, $first_name, $last_name, $phone, $dept_course, $otp, $expires);
            $stmt->execute();
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'digi.nymous@gmail.com';
            $mail->Password   = 'rbogocbeamvbqtlk';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            $mail->setFrom($mail->Username, 'GRC Student Portal');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = 'Account Registration OTP Code';
            $mail->Body    = "<div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #ddd;'>
                                <h3 style='color: #ab0a0a;'>Global Reciprocal Colleges</h3>
                                <p>Welcome! Your OTP code to complete account registration is:</p>
                                <h1 style='letter-spacing: 5px; color: #ab0a0a;'>{$otp}</h1>
                                <p><small>This code is valid for 10 minutes.</small></p>
                              </div>";
            $mail->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));
            $mail->send();

            $_SESSION['reg_email'] = $email;
            $_SESSION['reg_step'] = 2;
            $reg_step = 2;
        } catch (Exception $e) {
            $msg = "Error sending OTP email: " . $mail->ErrorInfo;
            $msg_type = "danger";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reg_verify_otp') {
    $active_modal = 'register';
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Validation Failed: Invalid CSRF Token.");
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $email = $_SESSION['reg_email'] ?? '';
    $otp_input = trim($_POST['otp_code']);

    $stmt = $conn->prepare("SELECT id, otp_expires FROM users WHERE email = ? AND otp_code = ?");
    $stmt->bind_param("ss", $email, $otp_input);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {
        $user = $res->fetch_assoc();
        if (strtotime($user['otp_expires']) >= time()) {
            $update = $conn->prepare("UPDATE users SET is_verified = 1, otp_code = NULL, otp_expires = NULL WHERE id = ?");
            $update->bind_param("i", $user['id']);
            $update->execute();

            unset($_SESSION['reg_email']);
            unset($_SESSION['reg_step']);
            $active_modal = 'login';
            $msg = "Registration successful! Please log in.";
            $msg_type = "success";
        } else {
            $msg = "OTP expired. Please start registration again.";
            $msg_type = "danger";
        }
    } else {
        $msg = "Invalid OTP code entered.";
        $msg_type = "danger";
    }
}

// -----------------------------------------------------------------------------
// 3. LOGIN URL ERROR REDIRECT CHECK
// -----------------------------------------------------------------------------
if (isset($_GET['login_error'])) {
    $active_modal = 'login';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global Reciprocal Colleges | Student & Faculty Portal</title>
    <!-- Google Fonts & Font Awesome & Bootstrap Icons -->
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
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--grc-bg); 
            color: #2d3748; 
        }
        .navbar-grc { 
            background: linear-gradient(135deg, var(--grc-dark) 0%, var(--grc-secondary) 100%); 
            border-bottom: 3px solid var(--grc-hover); 
        }
        .hero-section {
            background: linear-gradient(135deg, #1e0202 0%, #4a0505 50%, #110101 100%);
            color: white;
            padding: 130px 0;
            border-bottom: 4px solid var(--grc-secondary);
        }
        .feature-card {
            background: #ffffff;
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
        }
        .text-grc { color: var(--grc-secondary); }
        .text-accent { color: #ff5e36; }
        .btn-grc-accent {
            background: linear-gradient(135deg, var(--grc-secondary) 0%, var(--grc-primary) 100%);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        .btn-grc-accent:hover {
            background: var(--grc-hover);
            color: #ffffff;
            box-shadow: 0 6px 15px rgba(255, 30, 30, 0.4);
            transform: translateY(-1px);
        }
        .announcement-card {
            border-left: 4px solid var(--grc-secondary);
            border-radius: 8px;
            background: #ffffff;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .announcement-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
        }
        .modal-dark-custom {
            background-color: #212529;
            color: #f8f9fa;
            border: 1px solid #343a40;
            border-radius: 16px;
        }
        .about-card-dark {
            background: #181b1e;
            border: 1px solid #2b3036;
            border-radius: 12px;
        }
        .btn-grc { 
            background: linear-gradient(135deg, var(--grc-secondary) 0%, var(--grc-primary) 100%); 
            color: #ffffff; 
            border: none; 
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        .btn-grc:hover { 
            background: var(--grc-hover); 
            color: #ffffff; 
            box-shadow: 0 6px 15px rgba(204, 0, 0, 0.4);
        }
        a.grc-link { color: var(--grc-secondary); text-decoration: none; font-weight: 600; cursor: pointer; }
        a.grc-link:hover { color: var(--grc-hover); text-decoration: underline; }

        @keyframes floatLogo {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0px); }
        }

        .hero-logo {
            max-width: 800px;
            width: 100%;
            height: auto;
            filter: drop-shadow(0 10px 20px rgba(0, 0, 0, 0.45));
            animation: floatLogo 4s ease-in-out infinite;
            transition: transform 0.3s ease, filter 0.3s ease;
        }

        .hero-logo:hover {
            transform: scale(1.05);
            filter: drop-shadow(0 15px 25px rgba(255, 30, 30, 0.35));
        }

        .btn-portal-hero {
            background: #ffffff;
            color: var(--grc-dark);
            border: 2px solid rgba(255, 255, 255, 0.8);
            border-radius: 50px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            letter-spacing: 0.3px;
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .btn-portal-hero:hover {
            background-color: var(--grc-hover);
            color: #ffffff;
            border-color: var(--grc-hover);
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(255, 30, 30, 0.4);
        }

        .btn-portal-hero .chevron-icon {
            transition: transform 0.3s ease;
        }

        .btn-portal-hero:hover .chevron-icon {
            transform: translateX(5px);
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

    <!-- Navigation Bar -->
    <nav class="navbar navbar-grc navbar-dark navbar-expand-lg p-3 shadow-sm">
        <div class="container px-lg-4">
            <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="index.php">
                <img src="assets/grc3.png" alt="GRC Logo" height="35" class="d-inline-block align-text-top">
                <img src="assets/grc2.png" alt="GRC Logo" height="35" class="d-inline-block align-text-top">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item me-2"><a class="nav-link active fw-medium" href="index.php">Home</a></li>
                    <?php if (!isset($_SESSION['user_id'])): ?>
                        <li class="nav-item me-2">
                            <a class="nav-link fw-medium" href="#" data-bs-toggle="modal" data-bs-target="#announcementsListModal">Announcements</a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item me-3">
                        <a class="nav-link fw-medium" href="#" data-bs-toggle="modal" data-bs-target="#aboutModal">About GRC</a>
                    </li>
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <li class="nav-item">
                            <a class="btn btn-grc-accent fw-semibold px-3 py-2" href="dashboard.php">
                                Go to Dashboard
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <button class="btn btn-outline-light btn-sm fw-semibold rounded-pill px-3 py-2" data-bs-toggle="modal" data-bs-target="#loginModal">
                                <i class="bi bi-box-arrow-in-right me-1"></i> Log In
                            </button>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <header class="hero-section text-center text-lg-start">
        <div class="container px-4">
            <div class="row align-items-center g-4">
                <div class="col-lg-5 text-center">
                    <img src="assets/sf.png" alt="Student Flow Logo" class="hero-logo img-fluid d-block mx-auto">
                </div>
                <div class="col-lg-7">
                    <h1 class="display-4 fw-bold mb-3">Empowering Minds, Shaping Futures</h1>
                    <p class="lead mb-4 text-light opacity-75" style="max-width: 650px;">
                        Welcome to the official Global Reciprocal Colleges (GRC) Web Portal. Access academic reports, view tuition statements, and manage account services seamlessly.
                    </p>
                    <div class="mt-4">
                        <?php if (!isset($_SESSION['user_id'])): ?>
                            <button class="btn btn-portal-hero btn-lg fw-bold px-4 py-3 shadow" data-bs-toggle="modal" data-bs-target="#loginModal">
                                <span>Access Portal</span>
                                <i class="bi bi-arrow-right ms-2 chevron-icon"></i>
                            </button>
                        <?php else: ?>
                            <a href="dashboard.php" class="btn btn-portal-hero btn-lg fw-bold px-4 py-3 shadow">
                                <span class="btn-icon-wrapper me-2">
                                </span>
                                <span>Open Dashboard</span>
                                <i class="bi bi-arrow-right ms-2 chevron-icon"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Core Portal Features -->
    <section class="py-5">
        <div class="container">
            <h2 class="text-center fw-bold mb-5 text-dark">Portal Capabilities</h2>
            <div class="row g-4 text-center">
                <div class="col-md-4">
                    <div class="card feature-card h-100 p-4">
                        <div class="card-body">
                            <i class="bi bi-mortarboard-fill text-grc display-4 mb-3 d-block"></i>
                            <h5 class="card-title fw-bold text-dark">Academic Grades</h5>
                            <p class="card-text text-muted">View subject scores, evaluation marks, and semester GPA performance metrics in real time.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card feature-card h-100 p-4">
                        <div class="card-body">
                            <i class="bi bi-receipt-cutoff text-grc display-4 mb-3 d-block"></i>
                            <h5 class="card-title fw-bold text-dark">Financial Statements</h5>
                            <p class="card-text text-muted">Track tuition ledger histories, view account assessment details, and monitor running balances.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card feature-card h-100 p-4">
                        <div class="card-body">
                            <i class="bi bi-printer-fill text-grc display-4 mb-3 d-block"></i>
                            <h5 class="card-title fw-bold text-dark">Print Manifests</h5>
                            <p class="card-text text-muted">Generate official printable PDF documents, grade certificates, and enrollment verifications.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- MODAL: ANNOUNCEMENTS LIST POPUP -->
    <div class="modal fade" id="announcementsListModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header border-0 pb-0">
                    <h4 class="fw-bold text-dark mb-0"><i class="bi bi-megaphone-fill me-2 text-grc"></i>Latest Announcements</h4>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <?php if (count($announcements) > 0): ?>
                            <?php foreach ($announcements as $announcement): ?>
                                <div class="col-12">
                                    <div class="card announcement-card p-2 shadow-sm" 
                                         style="cursor: pointer;"
                                         data-bs-toggle="modal" 
                                         data-bs-target="#announcementModal"
                                         data-title="<?= htmlspecialchars($announcement['title']) ?>"
                                         data-date="<?= date('M d, Y', strtotime($announcement['created_at'])) ?>"
                                         data-content="<?= htmlspecialchars($announcement['content']) ?>">
                                        <div class="card-body d-flex flex-column">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <h5 class="card-title fw-bold text-dark mb-0"><?= htmlspecialchars($announcement['title']) ?></h5>
                                                <span class="badge bg-secondary">
                                                    <?= date('M d, Y', strtotime($announcement['created_at'])) ?>
                                                </span>
                                            </div>
                                            <p class="card-text text-muted small text-truncate mb-2"><?= htmlspecialchars($announcement['content']) ?></p>
                                            <span class="small grc-link">Read Full Announcement <i class="bi bi-arrow-right"></i></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="col-12">
                                <div class="alert alert-info border-0 shadow-sm mb-0">No announcements published at this time.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 1: LOGIN POPUP -->
    <div class="modal fade" id="loginModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
                <div class="p-4 text-center text-white" style="background: linear-gradient(135deg, var(--grc-primary) 0%, var(--grc-secondary) 100%); border-bottom: 4px solid var(--grc-hover);">
                    <button type="button" class="btn-close btn-close-white float-end" data-bs-dismiss="modal" aria-label="Close"></button>
                    <img src="assets/grc4.png" alt="GRC Logo" class="mb-2" style="height: 55px; width: auto; object-fit: contain;">
                    <h4 class="mb-0 fw-bold">Global Reciprocal Colleges</h4>
                    <small style="color: #ffcccc; font-weight: 600; letter-spacing: 1px;">STUDENT PORTAL LOGIN</small>
                </div>
                <div class="modal-body p-4">
                    
                    <?php if (isset($_GET['login_error'])): ?>
                        <div class="alert alert-danger py-2 bg-danger bg-opacity-10 text-danger border-0 rounded-3 small mb-3">
                            <i class="fa-solid fa-circle-exclamation me-1"></i>
                            <?php 
                                $err = $_GET['login_error'];
                                if ($err == 'invalid') echo "Invalid username or password.";
                                elseif ($err == 'locked') echo "Your account has been locked due to multiple failed login attempts.";
                                elseif ($err == 'empty') echo "Please enter both username and password.";
                                elseif ($err == 'expired') echo "Session expired. Please log in again.";
                            ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($active_modal === 'login' && !empty($msg)): ?>
                        <div class="alert alert-<?= $msg_type; ?> py-2 border-0 bg-opacity-10 rounded-3 small mb-3">
                            <i class="fa-solid fa-circle-info me-1"></i> <?= htmlspecialchars($msg); ?>
                        </div>
                    <?php endif; ?>

                    <form action="authenticate.php" method="POST" id="loginForm">
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-dark small">Username or Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                                <input type="text" class="form-control" name="identity" required placeholder="Enter username or email">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-dark small">Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                                <input type="password" class="form-control" name="password" required placeholder="Enter password">
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="rememberMe" name="rememberMe">
                                <label class="form-check-label text-secondary small" for="rememberMe">Remember Me</label>
                            </div>
                            <a class="small grc-link" data-bs-target="#forgotModal" data-bs-toggle="modal">Forgot Password?</a>
                        </div>

                        <button type="submit" class="btn btn-grc w-100 py-2.5 fw-bold"><i class="fa-solid fa-right-to-bracket me-1"></i> Login</button>
                    </form>

                    <div class="text-center mt-4 pt-2 border-top">
                        <span class="small text-secondary">Don't have an account?</span> 
                        <a class="small grc-link ms-1" data-bs-target="#registerModal" data-bs-toggle="modal">Register Here</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 2: REGISTER POPUP -->
    <div class="modal fade" id="registerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 pt-0">
                    <div class="text-center mb-4">
                        <i class="fa-solid fa-user-plus fa-2x text-danger mb-2"></i>
                        <h4 class="fw-bold text-dark mb-1">Create Portal Account</h4>
                        <span class="badge bg-secondary">Global Reciprocal Colleges</span>
                    </div>

                    <?php if ($active_modal === 'register' && !empty($msg)): ?>
                        <div class="alert alert-danger border-0 bg-danger bg-opacity-10 text-danger py-2 small mb-3 rounded-3">
                            <i class="fa-solid fa-circle-exclamation me-1"></i> <?= htmlspecialchars($msg); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($reg_step === 1): ?>
                        <p class="text-secondary small text-center mb-3">Fill in your details to register as a Student or Department User.</p>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="reg_submit_details">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                            
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold text-dark">Role</label>
                                    <select name="role" id="roleSelect" class="form-select form-select-sm" required>
                                        <option value="Student">Student</option>
                                        <option value="Department">Department</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label id="idLabel" class="form-label small fw-semibold text-dark">Student ID</label>
                                    <input type="text" name="student_id" id="idInput" class="form-control form-control-sm" required placeholder="20XX-XX-XXXXX">
                                </div>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold text-dark">First Name</label>
                                    <input type="text" name="first_name" class="form-control form-control-sm" required placeholder="First Name">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold text-dark">Last Name</label>
                                    <input type="text" name="last_name" class="form-control form-control-sm" required placeholder="Last Name">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold text-dark">Username</label>
                                <input type="text" name="username" class="form-control form-control-sm" required placeholder="Username">
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold text-dark">Email Address</label>
                                <input type="email" id="regEmailInput" name="email" class="form-control form-control-sm" required placeholder="user@grc.edu.ph" value="<?= htmlspecialchars($_SESSION['reg_email'] ?? ''); ?>">
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold text-dark">Phone Number</label>
                                    <input type="text" name="phone" class="form-control form-control-sm" required placeholder="09123456789">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold text-dark">Course / Dept</label>
                                    <input type="text" name="department_course" class="form-control form-control-sm" required placeholder="BSIT / CCS">
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small fw-semibold text-dark">Password</label>
                                <input type="password" id="regPassInput" name="password" class="form-control form-control-sm" required>
                            </div>

                            <div class="progress mb-2" style="height: 6px; background-color: #e9ecef;">
                                <div id="regStrengthBar" class="progress-bar" role="progressbar" style="width: 0%;"></div>
                            </div>
                            <div class="d-flex justify-content-between mb-3">
                                <span class="small text-muted" style="font-size: 0.75rem;">Strength:</span>
                                <span id="regStrengthText" class="fw-bold small" style="font-size: 0.75rem; color: #888;">Weak</span>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small fw-semibold text-dark">Confirm Password</label>
                                <input type="password" id="regConfirmPassInput" name="confirm_password" class="form-control form-control-sm" required>
                            </div>

                            <div class="progress mb-2" style="height: 6px; background-color: #e9ecef;">
                                <div id="regMatchBar" class="progress-bar" role="progressbar" style="width: 0%;"></div>
                            </div>
                            <div class="d-flex justify-content-between mb-4">
                                <span class="small text-muted" style="font-size: 0.75rem;">Match:</span>
                                <span id="regMatchStatusText" class="fw-bold small" style="font-size: 0.75rem; color: #888;">Not Matching</span>
                            </div>

                            <button type="submit" class="btn btn-grc w-100 py-2.5 fw-bold"><i class="fa-solid fa-paper-plane me-1"></i> Register & Send OTP</button>
                        </form>
                    <?php else: ?>
                        <p class="text-secondary small text-center mb-3">Enter the 6-digit OTP sent to <strong><?= htmlspecialchars($_SESSION['reg_email'] ?? ''); ?></strong></p>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="reg_verify_otp">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                            
                            <div class="mb-4">
                                <label class="form-label small fw-semibold text-dark">OTP Verification Code</label>
                                <input type="text" name="otp_code" class="form-control text-center fw-bold fs-3" maxlength="6" required placeholder="123456">
                            </div>
                            <button type="submit" class="btn btn-grc w-100 py-2.5 fw-bold mb-2"><i class="fa-solid fa-shield-check me-1"></i> Verify OTP & Activate Account</button>
                        </form>

                        <form method="POST" class="mt-2">
                            <input type="hidden" name="action" value="reg_back_step1">
                            <button type="submit" class="btn btn-outline-secondary w-100 py-2 fw-semibold btn-sm">
                                <i class="fa-solid fa-pen-to-square me-1"></i> Change Email / Details
                            </button>
                        </form>
                    <?php endif; ?>

                    <div class="text-center mt-4 pt-2 border-top">
                        <a class="small grc-link" data-bs-target="#loginModal" data-bs-toggle="modal"><i class="fa-solid fa-arrow-left me-1"></i> Already have an account? Log in</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 3: FORGOT PASSWORD POPUP -->
    <div class="modal fade" id="forgotModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 pt-0">
                    <div class="text-center mb-4">
                        <i class="fa-solid fa-key fa-2x text-danger mb-2"></i>
                        <h4 class="fw-bold text-dark mb-1">Password Recovery</h4>
                        <span class="badge bg-secondary">Global Reciprocal Colleges</span>
                    </div>

                    <?php if ($active_modal === 'forgot' && !empty($msg)): ?>
                        <div class="alert alert-<?= $msg_type === 'danger' ? 'danger' : ($msg_type === 'success' ? 'success' : 'info'); ?> border-0 bg-opacity-10 py-2 small mb-3 rounded-3">
                            <i class="fa-solid fa-circle-info me-1"></i> <?= htmlspecialchars($msg); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($forgot_step === 1): ?>
                        <!-- STEP 1: Enter Email -->
                        <p class="text-secondary small text-center mb-4">Enter your registered email address to receive a 6-digit OTP code.</p>
                        <form method="POST">
                            <input type="hidden" name="action" value="forgot_send_otp">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                            
                            <div class="mb-4">
                                <label class="form-label small fw-semibold text-dark">Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-envelope"></i></span>
                                    <input type="email" name="email" class="form-control" required placeholder="user@grc.edu.ph">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-grc w-100 py-2.5 fw-bold"><i class="fa-solid fa-paper-plane me-1"></i> Send OTP Code</button>
                        </form>

                    <?php elseif ($forgot_step === 2): ?>
                        <!-- STEP 2: Verify OTP Only -->
                        <div class="d-flex justify-content-between align-items-center mb-3 p-2 bg-light rounded border">
                            <small class="text-muted text-truncate me-2">
                                Sent to: <strong><?= htmlspecialchars($_SESSION['reset_email'] ?? ''); ?></strong>
                            </small>
                            <form method="POST" class="m-0">
                                <input type="hidden" name="action" value="forgot_change_email">
                                <button type="submit" class="btn btn-sm btn-outline-danger py-1 px-2 fw-semibold" style="font-size: 0.75rem;">
                                    <i class="fa-solid fa-pen-to-square me-1"></i> Change Email
                                </button>
                            </form>
                        </div>

                        <form method="POST">
                            <input type="hidden" name="action" value="forgot_verify_otp">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                            
                            <div class="mb-4">
                                <label class="form-label small fw-semibold text-dark">Enter 6-Digit OTP Code</label>
                                <input type="text" name="otp_code" class="form-control text-center fw-bold fs-3" maxlength="6" required placeholder="123456" autofocus>
                            </div>

                            <button type="submit" class="btn btn-grc w-100 py-2.5 fw-bold"><i class="fa-solid fa-shield-check me-1"></i> Verify OTP Code</button>
                        </form>

                    <?php elseif ($forgot_step === 3): ?>
                        <!-- STEP 3: Create New Password (Only shown after valid OTP) -->
                        <p class="text-secondary small text-center mb-3">OTP verified. Please enter your new password below.</p>

                        <form method="POST">
                            <input type="hidden" name="action" value="forgot_reset_password">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                            
                            <div class="mb-2">
                                <label class="form-label small fw-semibold text-dark">New Password</label>
                                <input type="password" id="forgotPassInput" name="new_password" class="form-control" required placeholder="Enter new password">
                            </div>

                            <div class="progress mb-2" style="height: 6px; background-color: #e9ecef;">
                                <div id="forgotStrengthBar" class="progress-bar" role="progressbar" style="width: 0%;"></div>
                            </div>
                            <div class="d-flex justify-content-between mb-3">
                                <span class="small text-muted" style="font-size: 0.75rem;">Strength:</span>
                                <span id="forgotStrengthText" class="fw-bold small" style="font-size: 0.75rem; color: #888;">Weak</span>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small fw-semibold text-dark">Confirm New Password</label>
                                <input type="password" id="forgotConfirmPassInput" name="confirm_password" class="form-control" required placeholder="Confirm new password">
                            </div>

                            <div class="progress mb-2" style="height: 6px; background-color: #e9ecef;">
                                <div id="forgotMatchBar" class="progress-bar" role="progressbar" style="width: 0%;"></div>
                            </div>
                            <div class="d-flex justify-content-between mb-4">
                                <span class="small text-muted" style="font-size: 0.75rem;">Match:</span>
                                <span id="forgotMatchStatusText" class="fw-bold small" style="font-size: 0.75rem; color: #888;">Not Matching</span>
                            </div>

                            <button type="submit" class="btn btn-grc w-100 py-2.5 fw-bold"><i class="fa-solid fa-lock me-1"></i> Update Password</button>
                        </form>
                    <?php endif; ?>

                    <div class="text-center mt-4 pt-2 border-top">
                        <a class="small grc-link" data-bs-target="#loginModal" data-bs-toggle="modal"><i class="fa-solid fa-arrow-left me-1"></i> Back to Login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- About Us Pop-up Modal -->
    <div class="modal fade" id="aboutModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content modal-dark-custom p-3 p-lg-4">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-4 align-items-center">
                        <div class="col-lg-6">
                            <div class="pe-lg-3">
                                <h2 class="fw-bold mb-3 text-white d-flex align-items-center gap-2">
                                    <i class="bi bi-bank text-accent"></i>
                                    <span>About Global Reciprocal Colleges</span>
                                </h2>
                                <h5 class="fw-bold text-accent mb-3">
                                    Building a brighter future through quality, accessible higher education.
                                </h5>
                                <p class="text-secondary mb-3">
                                    Global Reciprocal Colleges (GRC) is an educational institution dedicated to providing accessible, high-quality, and values-oriented tertiary education.
                                </p>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <div class="card about-card-dark h-100 p-3">
                                        <div class="card-body">
                                            <i class="bi bi-bullseye text-accent fs-3 mb-2 d-block"></i>
                                            <h5 class="fw-bold text-white mb-2">Our Mission</h5>
                                            <p class="text-secondary small mb-0">“GRC is creating a culture for successful, socially responsible, morally upright skilled workers and highly competent professionals through values-based quality education.”</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="card about-card-dark h-100 p-3">
                                        <div class="card-body">
                                            <i class="bi bi-eye-fill text-accent fs-3 mb-2 d-block"></i>
                                            <h5 class="fw-bold text-white mb-2">Our Vision</h5>
                                            <p class="text-secondary small mb-0">“A global community of excellent individuals with values.”</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="card about-card-dark h-100 p-3">
                                        <div class="card-body">
                                            <i class="bi bi-gem text-accent fs-3 mb-2 d-block"></i>
                                            <h5 class="fw-bold text-white mb-2">Core Values</h5>
                                            <p class="text-secondary small mb-0">“God-Fearing, Reciprocating, Committing to Excellence.”</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="card about-card-dark h-100 p-3">
                                        <div class="card-body">
                                            <i class="bi bi-lightbulb-fill text-accent fs-3 mb-2 d-block"></i>
                                            <h5 class="fw-bold text-white mb-2">Philosophy</h5>
                                            <p class="text-secondary small mb-0">“Touching Hearts, Renewing Minds, Transforming Lives.”</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 4: ANNOUNCEMENT POPUP DETAIL -->
    <div class="modal fade" id="announcementModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header border-0 pb-0">
                    <span class="badge bg-secondary" id="modalAnnDate"></span>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 pt-2">
                    <h4 class="fw-bold text-dark mb-3" id="modalAnnTitle"></h4>
                    <hr class="text-muted opacity-25">
                    <p class="text-secondary mb-0" id="modalAnnContent" style="white-space: pre-line;"></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="mt-auto py-4" style="background-color: var(--grc-dark); color: #ffffff;">
        <div class="container text-center">
            <p class="mb-1">&copy; <?= date('Y') ?> Global Reciprocal Colleges. All rights reserved.</p>
            <small class="text-secondary">Student & Faculty Web Portal System</small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const activeModal = "<?= $active_modal ?>";
        if (activeModal === 'login') {
            const loginModal = new bootstrap.Modal(document.getElementById('loginModal'));
            loginModal.show();
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.pathname);
            }
        } else if (activeModal === 'register') {
            new bootstrap.Modal(document.getElementById('registerModal')).show();
        } else if (activeModal === 'forgot') {
            new bootstrap.Modal(document.getElementById('forgotModal')).show();
        }

        const annModal = document.getElementById('announcementModal');
        if (annModal) {
            annModal.addEventListener('show.bs.modal', (event) => {
                const card = event.relatedTarget;
                const title = card.getAttribute('data-title');
                const date = card.getAttribute('data-date');
                const content = card.getAttribute('data-content');

                document.getElementById('modalAnnTitle').textContent = title;
                document.getElementById('modalAnnDate').textContent = date;
                document.getElementById('modalAnnContent').textContent = content;
            });
        }

        const roleSelect = document.getElementById('roleSelect');
        const idLabel = document.getElementById('idLabel');
        const idInput = document.getElementById('idInput');
        if (roleSelect) {
            roleSelect.addEventListener('change', () => {
                if (roleSelect.value === 'Department') {
                    idLabel.textContent = 'Dept ID';
                    idInput.placeholder = 'DEP-20XX-XXX';
                } else {
                    idLabel.textContent = 'Student ID';
                    idInput.placeholder = '2024-08-01129';
                }
            });
        }

        function bindPasswordValidator(passId, confirmId, barId, textId, matchBarId, matchTextId) {
            const passInput = document.getElementById(passId);
            const confirmInput = document.getElementById(confirmId);
            const bar = document.getElementById(barId);
            const text = document.getElementById(textId);
            const matchBar = document.getElementById(matchBarId);
            const matchText = document.getElementById(matchTextId);

            if (!passInput) return;

            passInput.addEventListener('input', () => {
                const val = passInput.value;
                let passed = 0;
                if (/.{8,}/.test(val)) passed++;
                if (/[A-Z]/.test(val)) passed++;
                if (/[a-z]/.test(val)) passed++;
                if (/\d/.test(val)) passed++;
                if (/[!@#$%^&*(),.?":{}|<>]/.test(val)) passed++;

                let percent = (passed / 5) * 100;
                bar.style.width = percent + '%';

                if (passed <= 2) {
                    bar.className = 'progress-bar bg-danger';
                    text.textContent = 'Weak';
                    text.style.color = '#dc3545';
                } else if (passed <= 4) {
                    bar.className = 'progress-bar bg-warning';
                    text.textContent = 'Medium';
                    text.style.color = '#ffc107';
                } else {
                    bar.className = 'progress-bar bg-success';
                    text.textContent = 'Strong';
                    text.style.color = '#198754';
                }
            });

            if (confirmInput && matchBar) {
                confirmInput.addEventListener('input', () => {
                    if (confirmInput.value.length === 0) {
                        matchBar.style.width = '0%';
                        matchText.textContent = 'Not Matching';
                        matchText.style.color = '#888';
                    } else if (passInput.value === confirmInput.value) {
                        matchBar.style.width = '100%';
                        matchBar.className = 'progress-bar bg-success';
                        matchText.textContent = 'Passwords Match';
                        matchText.style.color = '#198754';
                    } else {
                        matchBar.style.width = '100%';
                        matchBar.className = 'progress-bar bg-danger';
                        matchText.textContent = 'Do Not Match';
                        matchText.style.color = '#dc3545';
                    }
                });
            }
        }

        bindPasswordValidator('regPassInput', 'regConfirmPassInput', 'regStrengthBar', 'regStrengthText', 'regMatchBar', 'regMatchStatusText');
        bindPasswordValidator('forgotPassInput', 'forgotConfirmPassInput', 'forgotStrengthBar', 'forgotStrengthText', 'forgotMatchBar', 'forgotMatchStatusText');
    });
    </script>
</body>
</html>