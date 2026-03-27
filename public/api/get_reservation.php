<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once '../includes/db.php';
require_once '../includes/reservation_rules.php';

function reservationResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function reservationRequestId(): int
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        return (int) ($data['id'] ?? 0);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        return (int) ($_GET['id'] ?? 0);
    }

    reservationResponse(['success' => false, 'message' => 'Invalid request method.'], 405);
}

function reservationContextDate(array $reservation): string
{
    $requestedDate = trim((string) ($_GET['date'] ?? ''));
    if ($requestedDate === '') {
        return (string) ($reservation['date'] ?? '');
    }

    if (!isValidReservationDate($requestedDate)) {
        reservationResponse(['success' => false, 'message' => 'Please provide a valid reservation date.'], 422);
    }

    return $requestedDate;
}

function reservationTimeSlotsForId(PDO $pdo, int $reservationId, ?string $fallbackTime = null): array
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

function findCourtForReservation(PDO $pdo, array $reservation): ?array
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

function reservedTimeSlotsForContext(PDO $pdo, array $court, string $courtName, string $date, int $reservationId): array
{
    $statement = $pdo->prepare(
        'SELECT DISTINCT rs.time
         FROM reservations r
         JOIN reservation_slots rs ON rs.reservation_id = r.id
         WHERE r.date = ?
           AND (r.court_id = ? OR (r.court_id IS NULL AND r.court = ?))
           AND r.id <> ?
         ORDER BY rs.time'
    );
    $statement->execute([$date, (int) $court['id'], $courtName, $reservationId]);

    return array_values(
        array_filter(
            array_map(
                static fn(mixed $value): string => normalizeReservationTime((string) $value),
                $statement->fetchAll(PDO::FETCH_COLUMN)
            ),
            static fn(string $value): bool => $value !== ''
        )
    );
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') === 'user') {
    reservationResponse(['success' => false, 'message' => 'Access denied.'], 403);
}

$reservationId = reservationRequestId();
if ($reservationId <= 0) {
    reservationResponse(['success' => false, 'message' => 'Missing reservation ID.'], 422);
}

$reservationStatement = $pdo->prepare('SELECT * FROM reservations WHERE id = ? LIMIT 1');
$reservationStatement->execute([$reservationId]);
$reservation = $reservationStatement->fetch(PDO::FETCH_ASSOC);

if (!is_array($reservation)) {
    reservationResponse(['success' => false, 'message' => 'Reservation not found.'], 404);
}

$court = findCourtForReservation($pdo, $reservation);
if (!is_array($court)) {
    reservationResponse(['success' => false, 'message' => 'The linked court could not be found for this reservation.'], 404);
}

$contextDate = reservationContextDate($reservation);
$timeSlots = reservationTimeSlotsForId($pdo, $reservationId, (string) ($reservation['time'] ?? ''));
$courtSlots = buildCourtHourlySlots((string) $court['open_time'], (string) $court['close_time']);
$reservedSlots = reservedTimeSlotsForContext($pdo, $court, (string) ($reservation['court'] ?? $court['name']), $contextDate, $reservationId);

$reservation['court_id'] = (int) $court['id'];
$reservation['court'] = (string) $court['name'];
$reservation['time_slots'] = $timeSlots;

reservationResponse([
    'success' => true,
    'reservation' => $reservation,
    'court' => [
        'id' => (int) $court['id'],
        'name' => (string) $court['name'],
        'price' => (float) $court['price'],
        'member_price' => isset($court['member_price']) ? (float) $court['member_price'] : null,
        'open_time' => (string) $court['open_time'],
        'close_time' => (string) $court['close_time'],
    ],
    'context_date' => $contextDate,
    'time_slots' => $timeSlots,
    'court_slots' => $courtSlots,
    'reserved_slots' => $reservedSlots,
]);
