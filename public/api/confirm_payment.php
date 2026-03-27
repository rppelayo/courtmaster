<?php
declare(strict_types=1);

session_start();

require_once '../includes/db.php';
require_once '../includes/admin_activity.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin_reservations.php');
    exit;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') === 'user') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$reservationId = (int) ($_POST['id'] ?? 0);
if ($reservationId <= 0) {
    header('Location: /admin_reservations.php?error=' . rawurlencode('Missing reservation ID.'));
    exit;
}

try {
    $reservationStatement = $pdo->prepare(
        'SELECT id, full_name, guest_name, court, date, payment_status
         FROM reservations
         WHERE id = ?
         LIMIT 1'
    );
    $reservationStatement->execute([$reservationId]);
    $reservation = $reservationStatement->fetch(PDO::FETCH_ASSOC);

    if (!is_array($reservation)) {
        header('Location: /admin_reservations.php?error=' . rawurlencode('Reservation not found.'));
        exit;
    }

    $stmt = $pdo->prepare("UPDATE reservations SET payment_status = 'paid' WHERE id = ?");
    $stmt->execute([$reservationId]);

    adminActivityLog($pdo, [
        'action_type' => 'payment_confirmed',
        'subject_type' => 'reservation',
        'subject_id' => $reservationId,
        'description' => 'Confirmed payment for reservation #' . $reservationId,
        'metadata' => [
            'customer_name' => ($reservation['full_name'] ?? '') !== '' ? $reservation['full_name'] : ($reservation['guest_name'] ?? null),
            'court_name' => $reservation['court'] ?? null,
            'date' => $reservation['date'] ?? null,
            'previous_payment_status' => $reservation['payment_status'] ?? null,
        ],
    ]);
} catch (Throwable) {
    header('Location: /admin_reservations.php?error=' . rawurlencode('Failed to confirm payment.'));
    exit;
}

header('Location: /admin_reservations.php');
exit;
