<?php
declare(strict_types=1);

session_start();

require_once '../includes/db.php';
require_once '../includes/admin_activity.php';

function deleteReservationWantsJson(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

    return str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest';
}

function deleteReservationRespond(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);

    if (!deleteReservationWantsJson()) {
        $redirect = '/admin_reservations.php';
        if (!($payload['success'] ?? false)) {
            $redirect .= '?error=' . rawurlencode((string) ($payload['message'] ?? 'Unable to delete reservation.'));
        }

        header('Location: ' . $redirect);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    deleteReservationRespond(['success' => false, 'message' => 'Invalid request method.'], 405);
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') === 'user') {
    deleteReservationRespond(['success' => false, 'message' => 'Unauthorized'], 403);
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    deleteReservationRespond(['success' => false, 'message' => 'Missing reservation ID.'], 422);
}

try {
    $reservationStatement = $pdo->prepare(
        'SELECT id, full_name, guest_name, court, date, payment, booking_source
         FROM reservations
         WHERE id = ?
         LIMIT 1'
    );
    $reservationStatement->execute([$id]);
    $reservation = $reservationStatement->fetch(PDO::FETCH_ASSOC);

    if (!is_array($reservation)) {
        deleteReservationRespond(['success' => false, 'message' => 'Reservation not found.'], 404);
    }

    $pdo->beginTransaction();

    $deleteGuestsStatement = $pdo->prepare('DELETE FROM reservation_guests WHERE reservation_id = ?');
    $deleteGuestsStatement->execute([$id]);

    $deleteSlotsStatement = $pdo->prepare('DELETE FROM reservation_slots WHERE reservation_id = ?');
    $deleteSlotsStatement->execute([$id]);

    $deleteReservationStatement = $pdo->prepare('DELETE FROM reservations WHERE id = ?');
    $deleteReservationStatement->execute([$id]);

    $pdo->commit();

    $customerName = trim((string) ($reservation['full_name'] ?? ''));
    if ($customerName === '') {
        $customerName = trim((string) ($reservation['guest_name'] ?? ''));
    }

    adminActivityLog($pdo, [
        'action_type' => 'reservation_deleted',
        'subject_type' => 'reservation',
        'subject_id' => $id,
        'description' => 'Deleted reservation #' . $id,
        'metadata' => [
            'customer_name' => $customerName !== '' ? $customerName : null,
            'court_name' => $reservation['court'] ?? null,
            'date' => $reservation['date'] ?? null,
            'booking_source' => $reservation['booking_source'] ?? null,
            'payment' => isset($reservation['payment']) ? (float) $reservation['payment'] : null,
        ],
    ]);

    deleteReservationRespond(['success' => true]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    deleteReservationRespond([
        'success' => false,
        'message' => 'Failed to delete reservation: ' . $exception->getMessage(),
    ], 500);
}
