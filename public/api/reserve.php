<?php
// api/reserve.php

session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';

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

$user_id = $_SESSION['user_id'] ?? null;
$guest_name = $data['guest_name'] ?? null;
$guest_email = $data['guest_email'] ?? null;

// Ensure either logged in or guest info provided
if (!$user_id && (!$guest_name || !$guest_email)) {
    echo json_encode(['success' => false, 'message' => 'Guest info missing']);
    exit;
}

try {
    // Check for double booking
    $checkStmt = $pdo->prepare("SELECT id FROM reservations WHERE sport = ? AND court = ? AND date = ? AND time = ?");
    $checkStmt->execute([$sport, $court, $date, $time]);

    if ($checkStmt->rowCount() > 0) {
        echo json_encode(['success' => false, 'message' => 'This time slot is already taken.']);
        exit;
    }

    // Insert reservation
    if ($user_id) {
        $stmt = $pdo->prepare("INSERT INTO reservations (user_id, sport, court, date, time) VALUES (?, ?, ?, ?, ?)");
        $success = $stmt->execute([$user_id, $sport, $court, $date, $time]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO reservations (guest_name, guest_email, sport, court, date, time) VALUES (?, ?, ?, ?, ?, ?)");
        $success = $stmt->execute([$guest_name, $guest_email, $sport, $court, $date, $time]);
    }

    echo json_encode(['success' => $success]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
