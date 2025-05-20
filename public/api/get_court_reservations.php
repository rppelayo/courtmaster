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
$data = json_decode(file_get_contents('php://input'), true);

if ($method === 'POST' && isset($data['court_id']) && !isset($data['date'], $data['time'])) {
    $court_id = $data['court_id'];

    // Get court details
    $stmt = $pdo->prepare("SELECT name, open_time, close_time FROM courts WHERE id = ?");
    $stmt->execute([$court_id]);
    $court = $stmt->fetch();
    if (!$court) {
        http_response_code(404);
        echo json_encode(['error' => 'Court not found']);
        exit();
    }

    $court_name = $court['name'];

    // Fetch reservations
    $stmt = $pdo->prepare("SELECT id, date, time, section_number, is_admin_set FROM reservations WHERE court = ? ORDER BY date, time");
    $stmt->execute([$court_name]);
    $reservations = $stmt->fetchAll();

    echo json_encode([
        'reservations' => $reservations,
        'open_time' => $court['open_time'],
        'close_time' => $court['close_time']
    ]);
    exit();

} elseif ($method === 'POST' && isset($data['court_id'], $data['date'], $data['time'])) {
    
    if (!isset($data['court_id'], $data['date'], $data['time'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing fields']);
        exit();
    }

    $court_id = $data['court_id'];
    $date = $data['date'];
    $time = $data['time'];
    $section = $data['section_number'] ?? 0;
    $is_admin_set = $data['is_admin_set'] ?? 0;

    // Fetch court name
    $stmt = $pdo->prepare("SELECT name FROM courts WHERE id = ?");
    $stmt->execute([$court_id]);
    $court = $stmt->fetch();
    if (!$court) {
        http_response_code(404);
        echo json_encode(['error' => 'Court not found']);
        exit();
    }
    $court_name = $court['name'];

    // Save reservation or admin-availability block
    $stmt = $pdo->prepare("INSERT INTO reservations (user_id, court, date, time, section_number, is_admin_set)
                           VALUES (?, ?, ?, ?, ?, ?)");
    $saved = $stmt->execute([$_SESSION['user_id'], $court_name, $date, $time, $section, $is_admin_set]);

    echo json_encode(['success' => $saved]);
    exit();

}elseif ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['reservation_id'], $data['date'], $data['time'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields for update']);
        exit();
    }

    $reservation_id = $data['reservation_id'];
    $date = $data['date'];
    $time = $data['time'];
    $section_number = $data['section_number'] ?? null;

    if (!is_numeric($reservation_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid reservation_id']);
        exit();
    }

    // Update reservation
    $stmt = $pdo->prepare("UPDATE reservations SET date = ?, time = ?, section_number = ? WHERE id = ?");
    $success = $stmt->execute([$date, $time, $section_number, $reservation_id]);

    if ($success) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update reservation']);
    }

    exit();
}

elseif ($method === 'DELETE') {
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
}

else {
    http_response_code(405);
    header('Allow: POST, PUT, DELETE');
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}
