<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
  echo json_encode([]);
  exit;
}

$user_id = $_SESSION['user_id'];

try {
  $stmt = $pdo->prepare("SELECT id, sport, court, date, time FROM reservations WHERE user_id = ? ORDER BY date, time");
  $stmt->execute([$user_id]);

  $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode($reservations);

} catch (PDOException $e) {
  echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
