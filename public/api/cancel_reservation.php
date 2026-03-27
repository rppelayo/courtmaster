<?php
session_start();
header('Content-Type: application/json');

require_once '../includes/db.php';
require_once '../includes/notifications.php';

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['success' => false, 'message' => 'User not logged in']);
  exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['reservation_id'])) {
  echo json_encode(['success' => false, 'message' => 'Missing reservation ID']);
  exit;
}

$reservation_id = $data['reservation_id'];
$user_id = $_SESSION['user_id'];

try {
  $reservationStmt = $pdo->prepare(
    "SELECT id, full_name, court, date
     FROM reservations
     WHERE id = :id AND user_id = :user_id
     LIMIT 1"
  );
  $reservationStmt->execute([
    ':id' => $reservation_id,
    ':user_id' => $user_id
  ]);
  $reservation = $reservationStmt->fetch(PDO::FETCH_ASSOC);

  if (!is_array($reservation)) {
    echo json_encode(['success' => false, 'message' => 'Reservation not found or not authorized']);
    exit;
  }

  $pdo->beginTransaction();

  $deleteGuests = $pdo->prepare('DELETE FROM reservation_guests WHERE reservation_id = :id');
  $deleteGuests->execute([':id' => $reservation_id]);

  $deleteSlots = $pdo->prepare('DELETE FROM reservation_slots WHERE reservation_id = :id');
  $deleteSlots->execute([':id' => $reservation_id]);

  $stmt = $pdo->prepare("DELETE FROM reservations WHERE id = :id AND user_id = :user_id");
  $stmt->execute([
    ':id' => $reservation_id,
    ':user_id' => $user_id
  ]);

  if ($stmt->rowCount() <= 0) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Reservation not found or not authorized']);
    exit;
  }

  $pdo->commit();

  notificationsCreateForRole($pdo, 'admin', [
    'type' => 'reservation_cancelled',
    'title' => 'Reservation cancelled',
    'message' => sprintf(
      '%s cancelled a reservation for %s on %s.',
      (string) ($reservation['full_name'] ?? 'A player'),
      (string) ($reservation['court'] ?? 'a court'),
      (string) ($reservation['date'] ?? 'the selected date')
    ),
    'link_url' => 'admin_reservations.php'
  ]);

  echo json_encode(['success' => true]);
} catch (PDOException $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
