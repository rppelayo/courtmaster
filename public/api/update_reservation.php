<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once '../includes/db.php';
require_once '../includes/pricing.php';
require_once '../includes/reservation_rules.php';

function updateReservationResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function updateReservationTimeSlots(PDO $pdo, int $reservationId, ?string $fallbackTime = null): array
{
    $statement = $pdo->prepare(
        'SELECT DISTINCT time FROM reservation_slots WHERE reservation_id = ? ORDER BY time'
    );
    $statement->execute([$reservationId]);
    $timeSlots = array_values(
        array_filter(
            array_map(
                static fn(mixed $value): string => normalizeReservationTime((string) $value),
                $statement->fetchAll(PDO::FETCH_COLUMN)
            ),
            static fn(string $value): bool => $value !== ''
        )
    );

    if ($timeSlots !== []) {
        return $timeSlots;
    }

    $normalizedFallback = normalizeReservationTime((string) $fallbackTime);
    return $normalizedFallback === '' ? [] : [$normalizedFallback];
}

function updateReservationCourt(PDO $pdo, array $reservation): ?array
{
    $courtId = (int) ($reservation['court_id'] ?? 0);
    if ($courtId > 0) {
        return fetchReservationCourt($pdo, $courtId);
    }

    $courtName = trim((string) ($reservation['court'] ?? ''));
    if ($courtName === '') {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT id, name, type, price, member_price, open_time, close_time, owner_id
         FROM courts
         WHERE name = ?
         ORDER BY id DESC
         LIMIT 1'
    );
    $statement->execute([$courtName]);
    $court = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($court) ? $court : null;
}

function updateReservationSectionNumbers(PDO $pdo, array $reservation, int $reservationId): array
{
    $statement = $pdo->prepare(
        'SELECT DISTINCT section_number FROM reservation_slots WHERE reservation_id = ? ORDER BY section_number'
    );
    $statement->execute([$reservationId]);

    $sections = array_values(
        array_unique(
            array_map(
                static fn(mixed $value): int => (int) $value,
                $statement->fetchAll(PDO::FETCH_COLUMN)
            )
        )
    );

    if ($sections !== []) {
        return $sections;
    }

    return [(int) ($reservation['section_number'] ?? 0)];
}

function updateReservationRequestData(): array
{
    $rawBody = file_get_contents('php://input');
    if ((!is_string($rawBody) || trim($rawBody) === '') && PHP_SAPI === 'cli') {
        $rawBody = file_get_contents('php://stdin');
    }

    if (!is_string($rawBody) || trim($rawBody) === '') {
        return [];
    }

    $decoded = json_decode($rawBody, true);
    return is_array($decoded) ? $decoded : [];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    updateReservationResponse(['success' => false, 'message' => 'Invalid request method.'], 405);
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') === 'user') {
    updateReservationResponse(['success' => false, 'message' => 'Access denied.'], 403);
}

$data = updateReservationRequestData();
if ($data === []) {
    updateReservationResponse(['success' => false, 'message' => 'Invalid request payload.'], 422);
}

$reservationId = (int) ($data['id'] ?? 0);
$date = trim((string) ($data['date'] ?? ''));
$paymentStatus = strtolower(trim((string) ($data['payment_status'] ?? 'pending')));
$timeSlots = normalizeReservationTimeSlots($data['time_slots'] ?? ($data['time'] ?? []));

if ($reservationId <= 0 || $date === '' || $timeSlots === []) {
    updateReservationResponse(['success' => false, 'message' => 'Missing required reservation fields.'], 422);
}

if (!isValidReservationDate($date)) {
    updateReservationResponse(['success' => false, 'message' => 'Please provide a valid reservation date.'], 422);
}

if (!in_array($paymentStatus, ['pending', 'paid'], true)) {
    updateReservationResponse(['success' => false, 'message' => 'Please choose a valid payment status.'], 422);
}

if (!areReservationTimeSlotsContiguous($timeSlots)) {
    updateReservationResponse(['success' => false, 'message' => 'Please choose a continuous time range.'], 422);
}

$reservationStatement = $pdo->prepare('SELECT * FROM reservations WHERE id = ? LIMIT 1');
$reservationStatement->execute([$reservationId]);
$reservation = $reservationStatement->fetch(PDO::FETCH_ASSOC);

if (!is_array($reservation)) {
    updateReservationResponse(['success' => false, 'message' => 'Reservation not found.'], 404);
}

$court = updateReservationCourt($pdo, $reservation);
if (!is_array($court)) {
    updateReservationResponse(['success' => false, 'message' => 'The linked court could not be found for this reservation.'], 404);
}

$userRole = (string) ($_SESSION['role'] ?? 'admin');
$userId = (int) $_SESSION['user_id'];
if ($userRole === 'owner' && (int) $court['owner_id'] !== $userId) {
    updateReservationResponse(['success' => false, 'message' => 'You do not have access to this reservation.'], 403);
}

if (!doReservationTimeSlotsFitCourtHours($timeSlots, (string) $court['open_time'], (string) $court['close_time'])) {
    updateReservationResponse(['success' => false, 'message' => 'The selected time range is outside the court schedule.'], 422);
}

$existingTimeSlots = updateReservationTimeSlots($pdo, $reservationId, (string) ($reservation['time'] ?? ''));
$sameDate = $date === (string) ($reservation['date'] ?? '');
$sameSlots = $timeSlots === $existingTimeSlots;
if (hasPastReservationTimeSlots($date, $timeSlots) && !($sameDate && $sameSlots)) {
    updateReservationResponse(['success' => false, 'message' => 'Past booking times are not allowed.'], 422);
}

if (reservationTimeSlotsOverlap($pdo, (int) $court['id'], $date, $timeSlots, $reservationId)) {
    updateReservationResponse(['success' => false, 'message' => 'One or more selected time slots are already taken.'], 409);
}

$sectionNumbers = updateReservationSectionNumbers($pdo, $reservation, $reservationId);
$hoursPlayed = reservationHoursPlayed($timeSlots);
$discountType = normalizePricingDiscountType((string) ($data['discount_type'] ?? ($reservation['discount_type'] ?? PRICING_DISCOUNT_NONE)));
$processingFee = (float) ($reservation['processing_fee'] ?? 0);
$pricing = computeReservationPricing(
    $court,
    $hoursPlayed,
    [
        'processing_fee' => $processingFee,
        'discount_type' => $discountType,
        'allow_member_rate' => $discountType === PRICING_DISCOUNT_MEMBER_RATE,
    ]
);
$payment = (int) ($reservation['is_admin_set'] ?? 0) === 1
    ? (float) ($reservation['payment'] ?? 0)
    : $pricing['total'];

$startTime = $timeSlots[0] ?? null;

try {
    $pdo->beginTransaction();

    $updateStatement = $pdo->prepare(
        'UPDATE reservations
         SET court = ?, court_id = ?, date = ?, time = ?, payment_status = ?, hourly_rate = ?, subtotal = ?, discount_type = ?, discount_label = ?, discount_amount = ?, processing_fee = ?, payment = ?, section_number = ?
         WHERE id = ?'
    );
    $updateStatement->execute([
        $court['name'],
        (int) $court['id'],
        $date,
        $startTime,
        $paymentStatus,
        (int) ($reservation['is_admin_set'] ?? 0) === 1 ? (float) ($reservation['hourly_rate'] ?? 0) : $pricing['applied_rate'],
        (int) ($reservation['is_admin_set'] ?? 0) === 1 ? (float) ($reservation['subtotal'] ?? 0) : $pricing['subtotal'],
        (int) ($reservation['is_admin_set'] ?? 0) === 1 ? (string) ($reservation['discount_type'] ?? PRICING_DISCOUNT_NONE) : $pricing['discount_type'],
        (int) ($reservation['is_admin_set'] ?? 0) === 1 ? (string) ($reservation['discount_label'] ?? '') : ($pricing['discount_label'] !== '' ? $pricing['discount_label'] : null),
        (int) ($reservation['is_admin_set'] ?? 0) === 1 ? (float) ($reservation['discount_amount'] ?? 0) : $pricing['discount_amount'],
        (int) ($reservation['is_admin_set'] ?? 0) === 1 ? (float) ($reservation['processing_fee'] ?? 0) : $pricing['processing_fee'],
        $payment,
        $sectionNumbers[0] ?? 0,
        $reservationId,
    ]);

    $deleteSlotsStatement = $pdo->prepare('DELETE FROM reservation_slots WHERE reservation_id = ?');
    $deleteSlotsStatement->execute([$reservationId]);

    $insertSlotStatement = $pdo->prepare(
        'INSERT INTO reservation_slots (reservation_id, time, section_number) VALUES (?, ?, ?)'
    );

    foreach ($sectionNumbers as $sectionNumber) {
        foreach ($timeSlots as $timeSlot) {
            $insertSlotStatement->execute([$reservationId, $timeSlot, $sectionNumber]);
        }
    }

    $guestPaymentStatement = $pdo->prepare(
        'UPDATE reservation_guests SET payment = ? WHERE reservation_id = ?'
    );
    $guestPaymentStatement->execute([$payment, $reservationId]);

    $pdo->commit();

    updateReservationResponse([
        'success' => true,
        'hours_played' => $hoursPlayed,
        'payment' => $payment,
        'time_slots' => $timeSlots,
        'pricing' => $pricing,
    ]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    updateReservationResponse([
        'success' => false,
        'message' => 'Failed to update reservation: ' . $exception->getMessage(),
    ], 500);
}
