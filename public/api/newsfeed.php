<?php
// api/newsfeed.php

session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';

// GET = fetch posts
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $posts = $pdo->query("
      SELECT n.id, n.content, n.created_at, u.email AS user_email
      FROM newsfeed n
      JOIN users u ON n.user_id = u.id
      ORDER BY n.created_at DESC
      LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
  
    foreach ($posts as &$post) {
      // Likes count
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = ?");
      $stmt->execute([$post['id']]);
      $post['likes'] = (int) $stmt->fetchColumn();
  
      // Comments
      $stmt = $pdo->prepare("
        SELECT c.content, c.created_at, u.email AS user_email
        FROM comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.post_id = ?
        ORDER BY c.created_at ASC
      ");
      $stmt->execute([$post['id']]);
      $post['comments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
  
    echo json_encode($posts);
    exit;
  }
  

// POST = add a new post
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $content = trim($data['content'] ?? '');

    if ($content === '') {
        echo json_encode(['success' => false, 'message' => 'Content is empty']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO newsfeed (user_id, content) VALUES (?, ?)");
    $stmt->execute([$_SESSION['user_id'], $content]);

    echo json_encode(['success' => true]);
    exit;
}

// fallback
echo json_encode(['success' => false, 'message' => 'Invalid request']);
