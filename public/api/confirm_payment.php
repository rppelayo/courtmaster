<?php
require_once "../includes/db.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
  $stmt = $pdo->prepare("UPDATE reservations SET payment_status = 'paid' WHERE id = ?");
  $update = $stmt->execute([$_POST['id']]);
 
    if ($update) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to confirm payment.']);
    }
}

header("Location: ../admin_reservations.php");
exit;
