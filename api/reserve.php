<?php
// api/reserve.php

session_start();
header('Content-Type: application/json');

require_once '../includes/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Read JSON input
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['sport'], $data['court'], $data['date'], $data['time'])) {
    echo json_encode(['success' => false, 'message' => 'Incomplete data.']);
    exit;
}

$sport = $data['sport'];
$court = $data['court'];
$date = $data['date'];
$time = $data['time'];
$user_id = $_SESSION['user_id'];

try {
    // Check for double booking
    $checkStmt = $pdo->prepare("SELECT id FROM reservations WHERE sport = ? AND court = ? AND date = ? AND time = ?");
    $checkStmt->execute([$sport, $court, $date, $time]);

    if ($checkStmt->rowCount() > 0) {
        echo json_encode(['success' => false, 'message' => 'This time slot is already taken.']);
        exit;
    }

    // Insert reservation
    $stmt = $pdo->prepare("INSERT INTO reservations (user_id, sport, court, date, time) VALUES (?, ?, ?, ?, ?)");
    $success = $stmt->execute([$user_id, $sport, $court, $date, $time]);

    if ($success) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
