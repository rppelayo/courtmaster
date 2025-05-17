<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['success' => false, 'message' => 'Not logged in']);
  exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$post_id = $data['post_id'] ?? 0;
$content = trim($data['content'] ?? '');

if ($content === '') {
  echo json_encode(['success' => false, 'message' => 'Empty comment']);
  exit;
}

try {
  $stmt = $pdo->prepare("INSERT INTO comments (user_id, post_id, content) VALUES (?, ?, ?)");
  $stmt->execute([$_SESSION['user_id'], $post_id, $content]);
  echo json_encode(['success' => true]);
} catch (PDOException $e) {
  echo json_encode(['success' => false, 'message' => 'DB Error']);
}
