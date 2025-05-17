<?php
require_once "../includes/db.php";


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);

    $id = $data['id'] ?? null;
    $court = $data['court'] ?? '';
    $date = $data['date'] ?? '';
    $time = $data['time'] ?? '';
    $payment_status = $data['payment_status'] ?? 'Pending';

    echo json_encode(['id' => $id, 'court' => $court, 'date' => $date, 'time' => $time, 'payment' => $payment_status]);
   

    if (!$id || !$court || !$date || !$time) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE reservations SET court = ?, date = ?, time = ?, payment_status = ? WHERE id = ?");
    $updated = $stmt->execute([$court, $date, $time, $payment_status, $id]);

    if ($updated) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update reservation.']);
    }
}
