<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';

$data = json_decode(file_get_contents('php://input'), true);
$date = $data['date'] ?? null;
$court = $data['court'] ?? null;

if (!$date || !$court) {
  echo json_encode([]);
  exit;
}

$stmt = $pdo->prepare("SELECT time FROM reservations WHERE date = ? AND court = ?");
$stmt->execute([$date, $court]);
$times = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode($times);
