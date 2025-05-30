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
    // Get all reservations with court name and image
    $stmt = $pdo->prepare("
        SELECT 
            r.id, r.sport, r.date,
            c.name AS court,
            c.image_path
        FROM reservations r
        JOIN courts c ON r.court_id = c.id
        WHERE r.user_id = ?
        ORDER BY r.date
    ");
    $stmt->execute([$user_id]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare statements to fetch slots and sections for a reservation
    $slotStmt = $pdo->prepare("SELECT time FROM reservation_slots WHERE reservation_id = ? ORDER BY time");
    $sectionStmt = $pdo->prepare("SELECT DISTINCT section_number FROM reservation_slots WHERE reservation_id = ? ORDER BY section_number");

    foreach ($reservations as &$res) {
        // Get all time slots
        $slotStmt->execute([$res['id']]);
        $slots = $slotStmt->fetchAll(PDO::FETCH_COLUMN);
        $res['time'] = $slots ? implode(',', $slots) : '';

        // Get all distinct sections
        $sectionStmt->execute([$res['id']]);
        $sections = $sectionStmt->fetchAll(PDO::FETCH_COLUMN);
        $res['sections'] = $sections ? implode(',', $sections) : '';
    }

    echo json_encode($reservations);

} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
