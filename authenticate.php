<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once 'database.php';

if (empty($_POST['identity']) || empty($_POST['password'])) {
    header("Location: index.php?login_error=empty");
    exit();
}

$identity = trim($_POST['identity']);
$password = $_POST['password'];

$stmt = $pdo->prepare("SELECT id, username, password_hash, role, failed_attempts, lockout_until FROM users WHERE username = ? OR email = ?");
$stmt->execute([$identity, $identity]);
$user = $stmt->fetch();

if ($user) {
    $now = date('Y-m-d H:i:s');

    if ($user['lockout_until'] && $user['lockout_until'] > $now) {
        header("Location: index.php?login_error=locked");
        exit();
    }

    if (password_verify($password, $user['password_hash'])) {
        $reset_stmt = $pdo->prepare("UPDATE users SET failed_attempts = 0, lockout_until = NULL WHERE id = ?");
        $reset_stmt->execute([$user['id']]);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['last_activity'] = time();

        header("Location: dashboard.php");
        exit();
    } else {
        $failed_attempts = $user['failed_attempts'] + 1;
        if ($failed_attempts >= 5) {
            $lockout_time = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            $lock_stmt = $pdo->prepare("UPDATE users SET failed_attempts = ?, lockout_until = ? WHERE id = ?");
            $lock_stmt->execute([$failed_attempts, $lockout_time, $user['id']]);
            header("Location: index.php?login_error=locked");
        } else {
            $update_stmt = $pdo->prepare("UPDATE users SET failed_attempts = ? WHERE id = ?");
            $update_stmt->execute([$failed_attempts, $user['id']]);
            header("Location: index.php?login_error=invalid");
        }
        exit();
    }
} else {
    header("Location: index.php?login_error=invalid");
    exit();
}