<?php
session_start();
require_once "../includes/db.php";

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(["error" => "Unauthorized"]);
    exit();
}

$currentUserId = $_SESSION['user_id'];

// Get user role and name
$stmt = $pdo->prepare("SELECT role, name FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(403);
    exit();
}

$is_admin = ($user['role'] === 'admin');

if ($is_admin) {
    // Admin sees all messages, with sender and receiver names
    $stmt = $pdo->query("
        SELECT 
            m.message, 
            m.timestamp, 
            u1.name AS sender, 
            u2.name AS receiver 
        FROM messages m
        JOIN users u1 ON m.sender_id = u1.id
        JOIN users u2 ON m.receiver_id = u2.id
        ORDER BY m.timestamp ASC
    ");
} else {
    // User sees only messages between themselves and the admin(s)
    $stmt = $pdo->prepare("
        SELECT 
            m.message, 
            m.timestamp,
            u1.name AS sender,
            u2.name AS receiver
        FROM messages m
        JOIN users u1 ON m.sender_id = u1.id
        JOIN users u2 ON m.receiver_id = u2.id
        WHERE (m.sender_id = :user_id OR m.receiver_id = :user_id)
          AND (u1.role = 'admin' OR u2.role = 'admin')
        ORDER BY m.timestamp ASC
    ");
    $stmt->execute(['user_id' => $currentUserId]);
}

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
