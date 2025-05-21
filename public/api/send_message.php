<?php
session_start();
require_once "../includes/db.php";

$data = json_decode(file_get_contents("php://input"), true);

$sender_id = $_SESSION['user_id'];
$is_admin = $_SESSION['role'] === 'admin';

$message = trim($data['message'] ?? '');
$receiver_id = $data['receiver_id'] ?? null;

// Admin MUST specify receiver_id
if ($is_admin) {
    if (!$receiver_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Receiver ID required for admin']);
        exit();
    }
} else {
    // Regular users send to admin
    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    $receiver_id = $stmt->fetchColumn();
    if (!$receiver_id) {
        http_response_code(500);
        echo json_encode(['error' => 'No admin available']);
        exit();
    }
}

$stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
$stmt->execute([$sender_id, $receiver_id, $message]);

echo json_encode(['success' => true]);

