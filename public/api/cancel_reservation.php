<?php
session_start();
header('Content-Type: application/json');

require_once '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['success' => false, 'message' => 'User not logged in']);
  exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['reservation_id'])) {
  echo json_encode(['success' => false, 'message' => 'Missing reservation ID']);
  exit;
}

$reservation_id = $data['reservation_id'];
$user_id = $_SESSION['user_id'];

try {
  $stmt = $pdo->prepare("DELETE FROM reservations WHERE id = :id AND user_id = :user_id");
  $stmt->execute([
    ':id' => $reservation_id,
    ':user_id' => $user_id
  ]);

  if ($stmt->rowCount() > 0) {
    echo json_encode(['success' => true]);
  } else {
    echo json_encode(['success' => false, 'message' => 'Reservation not found or not authorized']);
  }
} catch (PDOException $e) {
  echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
