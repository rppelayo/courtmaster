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

// Fetch reserved time slots by joining with reservation_slots
$stmt = $pdo->prepare("
    SELECT rs.time, r.section_number
    FROM reservations r
    JOIN reservation_slots rs ON r.id = rs.reservation_id
    WHERE r.date = ? AND r.court = ?
");
$stmt->execute([$date, $court]);
$times = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($times);
