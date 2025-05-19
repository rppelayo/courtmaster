<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // Fetch reservations for a court

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['court_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'court_id required']);
        exit();
    }
    $court_id = $data['court_id'];

    // Validate court_id (optional: numeric)
    if (!is_numeric($court_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid court_id']);
        exit();
    }

    // Get court name by ID (assuming 'id' column in courts table)
    $stmt = $pdo->prepare("SELECT name FROM courts WHERE id = ?");
    $stmt->execute([$court_id]);
    $court = $stmt->fetch();
    if (!$court) {
        http_response_code(404);
        echo json_encode(['error' => 'Court not found']);
        exit();
    }
    $court_name = $court['name'];

    // Get reservations for that court name
    $stmt = $pdo->prepare("SELECT id, date, time FROM reservations WHERE court = ? ORDER BY date, time");
    $stmt->execute([$court_name]);
    $reservations = $stmt->fetchAll();

    echo json_encode($reservations);
    exit();

} elseif ($method === 'DELETE') {
    // Delete a reservation by ID (free up schedule)

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['reservation_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'reservation_id required']);
        exit();
    }
    $reservation_id = $data['reservation_id'];

    if (!is_numeric($reservation_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid reservation_id']);
        exit();
    }

    // Delete reservation
    $stmt = $pdo->prepare("DELETE FROM reservations WHERE id = ?");
    $deleted = $stmt->execute([$reservation_id]);

    if ($deleted) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete reservation']);
    }

    exit();

} else {
    // Unsupported method
    http_response_code(405);
    header('Allow: POST, DELETE');
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}
