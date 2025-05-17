<?php
require_once "../includes/db.php";

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $id = $data['id'] ?? null;
} else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = $_GET['id'] ?? null;
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Missing reservation ID.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ?");
$stmt->execute([$id]);
$reservation = $stmt->fetch(PDO::FETCH_ASSOC);

if ($reservation) {
    echo json_encode(['success' => true, 'reservation' => $reservation]);
} else {
    echo json_encode(['success' => false, 'message' => 'Reservation not found.']);
}

