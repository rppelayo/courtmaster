<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';
require_once '../includes/reservation_rules.php';

function walkInResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function resolveWalkInClientReference(array $data): DateTimeImmutable
{
    $clientTimezone = trim((string) ($data['client_timezone'] ?? ''));
    $clientLocalNow = trim((string) ($data['client_local_now'] ?? ''));

    $timezone = null;
    if ($clientTimezone !== '') {
        try {
            $timezone = new DateTimeZone($clientTimezone);
        } catch (Throwable) {
            $timezone = null;
        }
    }

    if ($clientLocalNow !== '') {
        try {
            $clientNow = new DateTimeImmutable($clientLocalNow);
            return $timezone instanceof DateTimeZone ? $clientNow->setTimezone($timezone) : $clientNow;
        } catch (Throwable) {
            // Fall through to server time if the client payload is malformed.
        }
    }

    return $timezone instanceof DateTimeZone
        ? new DateTimeImmutable('now', $timezone)
        : new DateTimeImmutable('now');
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') === 'user') {
    walkInResponse(['success' => false, 'message' => 'Access denied.'], 403);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    walkInResponse(['success' => false, 'message' => 'Invalid request payload.'], 422);
}

$required = ['customer_name', 'contact_number', 'court_id', 'date', 'start_time', 'end_time', 'payment_method'];
foreach ($required as $key) {
    if (!array_key_exists($key, $data) || trim((string) $data[$key]) === '') {
        walkInResponse(['success' => false, 'message' => "Missing required field: {$key}"], 422);
    }
}

$customerName = trim((string) $data['customer_name']);
$contactNumber = trim((string) $data['contact_number']);
$courtId = (int) $data['court_id'];
$date = trim((string) $data['date']);
$startTime = trim((string) $data['start_time']);
$endTime = trim((string) $data['end_time']);
$paymentMethod = trim((string) $data['payment_method']);
$paymentStatus = trim((string) ($data['payment_status'] ?? 'paid'));
$reservationInfo = trim((string) ($data['reservation_info'] ?? ''));
$userId = (int) $_SESSION['user_id'];
$userRole = (string) ($_SESSION['role'] ?? 'admin');

$clientReference = resolveWalkInClientReference($data);
$today = $clientReference->format('Y-m-d');
if ($date !== $today) {
    walkInResponse(['success' => false, 'message' => 'Walk-in reservations can only be created for today.'], 422);
}

if (!isValidReservationDate($date)) {
    walkInResponse(['success' => false, 'message' => 'Please provide a valid walk-in date.'], 422);
}

$allowedPaymentMethods = ['cash', 'gcash-maya', 'card'];
if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
    walkInResponse(['success' => false, 'message' => 'Please choose a valid payment method.'], 422);
}

$allowedPaymentStatuses = ['paid', 'pending'];
if (!in_array($paymentStatus, $allowedPaymentStatuses, true)) {
    walkInResponse(['success' => false, 'message' => 'Please choose a valid payment status.'], 422);
}

$court = fetchReservationCourt($pdo, $courtId);
if ($court === null) {
    walkInResponse(['success' => false, 'message' => 'Selected court was not found.'], 404);
}

if ($userRole === 'owner' && (int) $court['owner_id'] !== $userId) {
    walkInResponse(['success' => false, 'message' => 'You do not have access to this court.'], 403);
}

$timeSlots = buildReservationTimeSlotsFromRange($startTime, $endTime);
if ($timeSlots === []) {
    walkInResponse(['success' => false, 'message' => 'Please choose a valid time-in and time-out range.'], 422);
}

if (!doReservationTimeSlotsFitCourtHours($timeSlots, (string) $court['open_time'], (string) $court['close_time'])) {
    walkInResponse(['success' => false, 'message' => 'The selected walk-in time is outside the court schedule.'], 422);
}

if (hasPastReservationTimeSlots($date, $timeSlots, $clientReference)) {
    walkInResponse(['success' => false, 'message' => 'Walk-in reservations cannot start in the past.'], 422);
}

if (reservationTimeSlotsOverlap($pdo, $courtId, $date, $timeSlots)) {
    walkInResponse(['success' => false, 'message' => 'One or more selected walk-in time slots are already booked.'], 409);
}

$hoursPlayed = reservationHoursPlayed($timeSlots);
$payment = ((float) $court['price']) * $hoursPlayed;

try {
    $pdo->beginTransaction();

    $reservationStatement = $pdo->prepare(
        'INSERT INTO reservations (
            user_id,
            full_name,
            contact_number,
            email,
            reservation_info,
            payment_method,
            payment_status,
            sport,
            court,
            court_id,
            section_number,
            date,
            payment,
            booking_source
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $reservationStatement->execute([
        $userId,
        $customerName,
        $contactNumber,
        null,
        $reservationInfo,
        $paymentMethod,
        $paymentStatus,
        $court['type'],
        $court['name'],
        $courtId,
        0,
        $date,
        $payment,
        'walk-in',
    ]);

    $reservationId = (int) $pdo->lastInsertId();

    $slotStatement = $pdo->prepare(
        'INSERT INTO reservation_slots (reservation_id, time, section_number) VALUES (?, ?, 0)'
    );

    foreach ($timeSlots as $timeSlot) {
        $slotStatement->execute([$reservationId, $timeSlot]);
    }

    $guestStatement = $pdo->prepare(
        'INSERT INTO reservation_guests (reservation_id, guest_name, guest_contact, payment)
         VALUES (?, ?, ?, ?)'
    );
    $guestStatement->execute([$reservationId, $customerName, $contactNumber, $payment]);

    $pdo->commit();

    walkInResponse([
        'success' => true,
        'reservation_id' => $reservationId,
        'hours_played' => $hoursPlayed,
        'payment' => $payment,
    ]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    walkInResponse(['success' => false, 'message' => 'Failed to create the walk-in reservation: ' . $exception->getMessage()], 500);
}
?>
