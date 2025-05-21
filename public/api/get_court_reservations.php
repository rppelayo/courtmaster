<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] == 'user') {
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

    // Fetch reservations joined with reservation_slots for time slots
    $stmt = $pdo->prepare("
        SELECT r.id, r.date, r.section_number, r.is_admin_set, 
               GROUP_CONCAT(DISTINCT rs.time ORDER BY rs.time) AS time_slots
        FROM reservations r
        LEFT JOIN reservation_slots rs ON rs.reservation_id = r.id
        WHERE r.court = ?
        GROUP BY r.id, rs.section_number
        ORDER BY r.date
    ");
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
    $stmt = $pdo->prepare("INSERT INTO reservations (user_id, court, date, section_number, is_admin_set)
                       VALUES (?, ?, ?, ?, ?)");
    $saved = $stmt->execute([$_SESSION['user_id'], $court_name, $date, $section, $is_admin_set]);

    if ($saved) {
        $reservation_id = $pdo->lastInsertId();
        // Insert each time slot into reservation_slots
        $times = explode(',', $time);
        $stmtSlot = $pdo->prepare("INSERT INTO reservation_slots (reservation_id, time) VALUES (?, ?)");
        foreach ($times as $t) {
            $stmtSlot->execute([$reservation_id, $t]);
        }
    }

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

    // Begin transaction to keep updates consistent
    $pdo->beginTransaction();

    try {
        // Update reservation date and section_number
        $stmt = $pdo->prepare("UPDATE reservations SET date = ?, section_number = ? WHERE id = ?");
        $stmt->execute([$date, $section_number, $reservation_id]);

        // Delete old reservation_slots for this reservation
        $stmtDel = $pdo->prepare("DELETE FROM reservation_slots WHERE reservation_id = ?");
        $stmtDel->execute([$reservation_id]);

        // Insert new time slots
        $stmtSlot = $pdo->prepare("INSERT INTO reservation_slots (reservation_id, time) VALUES (?, ?)");

        // Support time either as comma-separated string or array
        if (is_string($time)) {
            $times = explode(',', $time);
        } elseif (is_array($time)) {
            $times = $time;
        } else {
            $times = [];
        }

        foreach ($times as $t) {
            $trimmed = trim($t);
            if ($trimmed !== '') {
                $stmtSlot->execute([$reservation_id, $trimmed]);
            }
        }

        $pdo->commit();

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update reservation: ' . $e->getMessage()]);
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

    // Begin transaction for consistency
    $pdo->beginTransaction();

    try {
        // Delete reservation_slots first
        $stmtSlots = $pdo->prepare("DELETE FROM reservation_slots WHERE reservation_id = ?");
        $stmtSlots->execute([$reservation_id]);

        // Delete reservation
        $stmt = $pdo->prepare("DELETE FROM reservations WHERE id = ?");
        $stmt->execute([$reservation_id]);

        $pdo->commit();

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete reservation: ' . $e->getMessage()]);
    }
    exit();
}

else {
    http_response_code(405);
    header('Allow: POST, PUT, DELETE');
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}
