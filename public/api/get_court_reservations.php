<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || (string) ($_SESSION['role'] ?? 'user') === 'user') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit();
}

function scheduleRequestData(): array
{
    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody) || trim($rawBody) === '') {
        return [];
    }

    $decoded = json_decode($rawBody, true);
    return is_array($decoded) ? $decoded : [];
}

function scheduleNormalizeTime(string $value): ?string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    $candidate = strlen($trimmed) === 5 ? $trimmed . ':00' : $trimmed;
    $time = DateTimeImmutable::createFromFormat('H:i:s', $candidate);
    if (!$time instanceof DateTimeImmutable) {
        return null;
    }

    return $time->format('H:i:s');
}

function scheduleNormalizeTimes(string|array $value): array
{
    $values = is_array($value) ? $value : explode(',', (string) $value);
    $times = [];

    foreach ($values as $timeValue) {
        $normalized = scheduleNormalizeTime((string) $timeValue);
        if ($normalized !== null) {
            $times[] = $normalized;
        }
    }

    $times = array_values(array_unique($times));
    sort($times);
    return $times;
}

$method = $_SERVER['REQUEST_METHOD'];
$data = scheduleRequestData();

if ($method === 'POST' && isset($data['court_id']) && !isset($data['date'], $data['time'])) {
    $courtId = (int) $data['court_id'];

    $courtStatement = $pdo->prepare('SELECT id, name, open_time, close_time FROM courts WHERE id = ? LIMIT 1');
    $courtStatement->execute([$courtId]);
    $court = $courtStatement->fetch(PDO::FETCH_ASSOC);

    if (!is_array($court)) {
        http_response_code(404);
        echo json_encode(['error' => 'Court not found']);
        exit();
    }

    $reservationStatement = $pdo->prepare(
        "SELECT
            r.id,
            r.court_id,
            r.date,
            r.section_number,
            r.is_admin_set,
            r.game_status,
            r.full_name,
            r.guest_name,
            r.email,
            r.guest_email,
            GROUP_CONCAT(DISTINCT rs.time ORDER BY rs.time) AS time_slots
         FROM reservations r
         LEFT JOIN reservation_slots rs ON rs.reservation_id = r.id
         WHERE (r.court_id = ? OR (r.court_id IS NULL AND r.court = ?))
         GROUP BY r.id
         ORDER BY r.date ASC, time_slots ASC, r.id ASC"
    );
    $reservationStatement->execute([$courtId, (string) $court['name']]);
    $reservations = $reservationStatement->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'court_name' => (string) $court['name'],
        'open_time' => (string) $court['open_time'],
        'close_time' => (string) $court['close_time'],
        'reservations' => $reservations,
    ]);
    exit();
}

if ($method === 'POST' && isset($data['court_id'], $data['date'], $data['time'])) {
    $courtId = (int) $data['court_id'];
    $date = trim((string) $data['date']);
    $sectionNumber = isset($data['section_number']) ? (int) $data['section_number'] : 0;
    $isAdminSet = isset($data['is_admin_set']) ? (int) $data['is_admin_set'] : 0;
    $times = scheduleNormalizeTimes($data['time']);

    if ($courtId <= 0 || $date === '' || $times === []) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit();
    }

    $courtStatement = $pdo->prepare('SELECT id, name FROM courts WHERE id = ? LIMIT 1');
    $courtStatement->execute([$courtId]);
    $court = $courtStatement->fetch(PDO::FETCH_ASSOC);

    if (!is_array($court)) {
        http_response_code(404);
        echo json_encode(['error' => 'Court not found']);
        exit();
    }

    $placeholders = implode(',', array_fill(0, count($times), '?'));
    $conflictStatement = $pdo->prepare(
        "SELECT DISTINCT r.id
         FROM reservations r
         JOIN reservation_slots rs ON rs.reservation_id = r.id
         WHERE (r.court_id = ? OR (r.court_id IS NULL AND r.court = ?))
           AND r.date = ?
           AND rs.time IN ({$placeholders})
         LIMIT 1"
    );
    $conflictStatement->execute(array_merge([$courtId, (string) $court['name'], $date], $times));

    if ($conflictStatement->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['error' => 'One or more selected hours are already reserved or blocked.']);
        exit();
    }

    $pdo->beginTransaction();

    try {
        $reservationStatement = $pdo->prepare(
            "INSERT INTO reservations (
                user_id,
                court,
                court_id,
                date,
                time,
                section_number,
                is_admin_set,
                payment_status,
                booking_source,
                game_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $reservationStatement->execute([
            (int) $_SESSION['user_id'],
            (string) $court['name'],
            $courtId,
            $date,
            $times[0],
            $sectionNumber,
            $isAdminSet,
            'pending',
            'advance',
            'reserved',
        ]);

        $reservationId = (int) $pdo->lastInsertId();
        $slotStatement = $pdo->prepare(
            'INSERT INTO reservation_slots (reservation_id, time, section_number) VALUES (?, ?, ?)'
        );
        foreach ($times as $time) {
            $slotStatement->execute([$reservationId, $time, $sectionNumber]);
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'reservation_id' => $reservationId,
        ]);
    } catch (Throwable $exception) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save the schedule item: ' . $exception->getMessage()]);
    }

    exit();
}

if ($method === 'PUT') {
    $reservationId = isset($data['reservation_id']) ? (int) $data['reservation_id'] : 0;
    $date = trim((string) ($data['date'] ?? ''));
    $sectionNumber = isset($data['section_number']) ? (int) $data['section_number'] : 0;
    $times = scheduleNormalizeTimes($data['time'] ?? []);

    if ($reservationId <= 0 || $date === '' || $times === []) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields for update']);
        exit();
    }

    $pdo->beginTransaction();

    try {
        $reservationStatement = $pdo->prepare(
            'UPDATE reservations SET date = ?, time = ?, section_number = ? WHERE id = ?'
        );
        $reservationStatement->execute([$date, $times[0], $sectionNumber, $reservationId]);

        $deleteSlotsStatement = $pdo->prepare('DELETE FROM reservation_slots WHERE reservation_id = ?');
        $deleteSlotsStatement->execute([$reservationId]);

        $slotStatement = $pdo->prepare(
            'INSERT INTO reservation_slots (reservation_id, time, section_number) VALUES (?, ?, ?)'
        );
        foreach ($times as $time) {
            $slotStatement->execute([$reservationId, $time, $sectionNumber]);
        }

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Throwable $exception) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update reservation: ' . $exception->getMessage()]);
    }

    exit();
}

if ($method === 'DELETE') {
    $reservationId = isset($data['reservation_id']) ? (int) $data['reservation_id'] : 0;
    if ($reservationId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'reservation_id required']);
        exit();
    }

    $pdo->beginTransaction();

    try {
        $deleteSlotsStatement = $pdo->prepare('DELETE FROM reservation_slots WHERE reservation_id = ?');
        $deleteSlotsStatement->execute([$reservationId]);

        $deleteReservationStatement = $pdo->prepare('DELETE FROM reservations WHERE id = ?');
        $deleteReservationStatement->execute([$reservationId]);

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Throwable $exception) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete reservation: ' . $exception->getMessage()]);
    }

    exit();
}

http_response_code(405);
header('Allow: POST, PUT, DELETE');
echo json_encode(['error' => 'Method not allowed']);
exit();
