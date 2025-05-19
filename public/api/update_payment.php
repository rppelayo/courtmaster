<?php
require_once "../includes/db.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $id = $data['id'] ?? null;

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Missing reservation ID.']);
        exit;
    }

    $stmt = $db->prepare("UPDATE reservations SET payment_status = 'Paid' WHERE id = ?");
    $updated = $stmt->execute([$id]);

    if ($updated) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to confirm payment.']);
    }
}
