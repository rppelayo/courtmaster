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

try {
  $stmt = $pdo->prepare("INSERT IGNORE INTO likes (user_id, post_id) VALUES (?, ?)");
  $stmt->execute([$_SESSION['user_id'], $post_id]);
  echo json_encode(['success' => true]);
} catch (PDOException $e) {
  echo json_encode(['success' => false, 'message' => 'DB Error']);
}
