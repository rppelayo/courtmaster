<?php
// api/delete_comment.php

session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$comment_id = $data['comment_id'] ?? null;

if (!$comment_id) {
    echo json_encode(['success' => false, 'message' => 'Comment ID missing']);
    exit;
}

// Check ownership
$stmt = $pdo->prepare("SELECT user_id FROM comments WHERE id = ?");
$stmt->execute([$comment_id]);
$comment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$comment || $comment['user_id'] != $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized or comment not found']);
    exit;
}

$pdo->prepare("DELETE FROM comments WHERE id = ?")->execute([$comment_id]);

echo json_encode(['success' => true]);
