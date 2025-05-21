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
    // Get all reservations for user
    $stmt = $pdo->prepare("SELECT id, sport, court, date FROM reservations WHERE user_id = ? ORDER BY date");
    $stmt->execute([$user_id]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare statement to fetch slots for a reservation
    $slotStmt = $pdo->prepare("SELECT time FROM reservation_slots WHERE reservation_id = ? ORDER BY time");

    // Loop through reservations and fetch slots
    foreach ($reservations as &$res) {
        $slotStmt->execute([$res['id']]);
        $slots = $slotStmt->fetchAll(PDO::FETCH_COLUMN);
        if ($slots) {
            $res['time'] = implode(',', $slots);
        } else {
            $res['time'] = '';
        }
    }

    echo json_encode($reservations);

} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
