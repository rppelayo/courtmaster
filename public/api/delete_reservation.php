<?php
require_once "../includes/db.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? null;

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Missing reservation ID.']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM reservations WHERE id = ?");
    $deleted = $stmt->execute([$id]);

    if ($deleted) {
        echo json_encode(['success' => true]);
        header("Location: ../admin_reservations.php");
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete reservation.']);
    }
}
