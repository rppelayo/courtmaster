<?php
// api/edit_comment.php

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
$new_content = trim($data['content'] ?? '');

if (!$comment_id || $new_content === '') {
    echo json_encode(['success' => false, 'message' => 'Missing or empty content']);
    exit;
}

// Check if current user owns the comment
$stmt = $pdo->prepare("SELECT user_id FROM comments WHERE id = ?");
$stmt->execute([$comment_id]);
$comment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$comment || $comment['user_id'] != $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized or comment not found']);
    exit;
}

// Update comment
$stmt = $pdo->prepare("UPDATE comments SET content = ? WHERE id = ?");
$stmt->execute([$new_content, $comment_id]);

echo json_encode(['success' => true]);
