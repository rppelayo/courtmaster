<?php
// api/edit_post.php

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
$new_content = trim($data['content'] ?? '');

if (!$post_id || $new_content === '') {
    echo json_encode(['success' => false, 'message' => 'Missing or empty content']);
    exit;
}

// Check if the current user owns the post
$stmt = $pdo->prepare("SELECT user_id FROM newsfeed WHERE id = ?");
$stmt->execute([$post_id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post || $post['user_id'] != $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized or post not found']);
    exit;
}

// Update post
$stmt = $pdo->prepare("UPDATE newsfeed SET content = ? WHERE id = ?");
$stmt->execute([$new_content, $post_id]);

echo json_encode(['success' => true]);
