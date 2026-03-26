<?php
// api/delete_post.php

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
$post_id = $data['post_id'] ?? null;

if (!$post_id) {
    echo json_encode(['success' => false, 'message' => 'Post ID missing']);
    exit;
}

// Check ownership
$stmt = $pdo->prepare("SELECT user_id FROM newsfeed WHERE id = ?");
$stmt->execute([$post_id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post || $post['user_id'] != $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized or post not found']);
    exit;
}

// Delete post and related likes/comments
$pdo->prepare("DELETE FROM likes WHERE post_id = ?")->execute([$post_id]);
$pdo->prepare("DELETE FROM comments WHERE post_id = ?")->execute([$post_id]);
$pdo->prepare("DELETE FROM newsfeed WHERE id = ?")->execute([$post_id]);

echo json_encode(['success' => true]);
