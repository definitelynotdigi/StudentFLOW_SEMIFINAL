<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$conn = new mysqli("localhost", "root", "", "student_portal_db");

// SEND MESSAGE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send') {
    $user_id = ($_SESSION['role'] === 'Admin' && isset($_POST['target_user_id'])) ? intval($_POST['target_user_id']) : $_SESSION['user_id'];
    $sender_role = ($_SESSION['role'] === 'Admin') ? 'admin' : 'student';
    $message = trim($_POST['message'] ?? '');

    if (!empty($message)) {
        $stmt = $conn->prepare("INSERT INTO support_messages (user_id, sender_role, message) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $user_id, $sender_role, $message);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to save message']);
        }
    }
    exit;
}

// FETCH MESSAGES
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'fetch') {
    $user_id = ($_SESSION['role'] === 'Admin' && isset($_GET['target_user_id'])) ? intval($_GET['target_user_id']) : $_SESSION['user_id'];
    
    $stmt = $conn->prepare("SELECT sender_role, message, DATE_FORMAT(created_at, '%h:%i %p') as time FROM support_messages WHERE user_id = ? ORDER BY created_at ASC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $messages = [];
    while ($row = $res->fetch_assoc()) {
        $messages[] = $row;
    }

    // Mark as read if admin opens it
    if ($_SESSION['role'] === 'Admin') {
        $update = $conn->prepare("UPDATE support_messages SET is_read = 1 WHERE user_id = ? AND sender_role = 'student'");
        $update->bind_param("i", $user_id);
        $update->execute();
    }

    echo json_encode(['status' => 'success', 'messages' => $messages]);
    exit;
}