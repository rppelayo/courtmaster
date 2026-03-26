<?php
declare(strict_types=1);

function normalizeReservationTime(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    if (preg_match('/^\d{2}:\d{2}$/', $trimmed) === 1) {
        return $trimmed . ':00';
    }

    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $trimmed) === 1) {
        return $trimmed;
    }

    return '';
}

function normalizeReservationTimeSlots(mixed $value): array
{
    $rawValues = is_array($value) ? $value : explode(',', (string) $value);

    $normalized = [];
    foreach ($rawValues as $item) {
        $time = normalizeReservationTime((string) $item);
        if ($time !== '') {
            $normalized[] = $time;
        }
    }

    $normalized = array_values(array_unique($normalized));
    sort($normalized);

    return $normalized;
}

function addReservationHour(string $time): string
{
    $dateTime = DateTimeImmutable::createFromFormat('H:i:s', normalizeReservationTime($time));
    if (!$dateTime instanceof DateTimeImmutable) {
        return '';
    }

    return $dateTime->modify('+1 hour')->format('H:i:s');
}

function areReservationTimeSlotsContiguous(array $timeSlots): bool
{
    $sorted = normalizeReservationTimeSlots($timeSlots);
    if ($sorted === []) {
        return false;
    }

    for ($index = 1; $index < count($sorted); $index++) {
        if ($sorted[$index] !== addReservationHour($sorted[$index - 1])) {
            return false;
        }
    }

    return true;
}

function buildCourtHourlySlots(string $openTime, string $closeTime): array
{
    $open = normalizeReservationTime($openTime);
    $close = normalizeReservationTime($closeTime);
    if ($open === '' || $close === '') {
        return [];
    }

    $current = DateTimeImmutable::createFromFormat('H:i:s', $open);
    $end = DateTimeImmutable::createFromFormat('H:i:s', $close);
    if (!$current instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable || $current >= $end) {
        return [];
    }

    $slots = [];
    while ($current < $end) {
        $next = $current->modify('+1 hour');
        if ($next > $end) {
            break;
        }

        $slots[] = $current->format('H:i:s');
        $current = $next;
    }

    return $slots;
}

function buildReservationTimeSlotsFromRange(string $startTime, string $endTime): array
{
    $start = normalizeReservationTime($startTime);
    $end = normalizeReservationTime($endTime);
    if ($start === '' || $end === '') {
        return [];
    }

    $current = DateTimeImmutable::createFromFormat('H:i:s', $start);
    $finish = DateTimeImmutable::createFromFormat('H:i:s', $end);
    if (!$current instanceof DateTimeImmutable || !$finish instanceof DateTimeImmutable || $current >= $finish) {
        return [];
    }

    $slots = [];
    while ($current < $finish) {
        $next = $current->modify('+1 hour');
        if ($next > $finish) {
            return [];
        }

        $slots[] = $current->format('H:i:s');
        $current = $next;
    }

    return $slots;
}

function isValidReservationDate(string $date): bool
{
    $normalized = trim($date);
    if ($normalized === '') {
        return false;
    }

    $dateValue = DateTimeImmutable::createFromFormat('Y-m-d', $normalized);
    return $dateValue instanceof DateTimeImmutable && $dateValue->format('Y-m-d') === $normalized;
}

function hasPastReservationTimeSlots(string $date, array $timeSlots, ?DateTimeImmutable $now = null): bool
{
    $reference = $now ?? new DateTimeImmutable('now');
    foreach (normalizeReservationTimeSlots($timeSlots) as $slot) {
        $slotStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' ' . $slot);
        if ($slotStart instanceof DateTimeImmutable && $slotStart < $reference) {
            return true;
        }
    }

    return false;
}

function doReservationTimeSlotsFitCourtHours(array $timeSlots, string $openTime, string $closeTime): bool
{
    $allowedSlots = buildCourtHourlySlots($openTime, $closeTime);
    if ($allowedSlots === []) {
        return false;
    }

    foreach (normalizeReservationTimeSlots($timeSlots) as $slot) {
        if (!in_array($slot, $allowedSlots, true)) {
            return false;
        }
    }

    return true;
}

function fetchReservationCourt(PDO $pdo, int $courtId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, name, type, price, open_time, close_time, owner_id FROM courts WHERE id = ? LIMIT 1'
    );
    $statement->execute([$courtId]);
    $court = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($court) ? $court : null;
}

function reservationTimeSlotsOverlap(PDO $pdo, int $courtId, string $date, array $timeSlots, ?int $ignoreReservationId = null): bool
{
    $normalizedSlots = normalizeReservationTimeSlots($timeSlots);
    if ($normalizedSlots === []) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($normalizedSlots), '?'));
    $sql = "
        SELECT 1
        FROM reservations r
        JOIN reservation_slots rs ON rs.reservation_id = r.id
        WHERE r.court_id = ?
          AND r.date = ?
          AND rs.time IN ($placeholders)
    ";

    $params = [$courtId, $date, ...$normalizedSlots];

    if ($ignoreReservationId !== null) {
        $sql .= ' AND r.id <> ?';
        $params[] = $ignoreReservationId;
    }

    $sql .= ' LIMIT 1';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return (bool) $statement->fetchColumn();
}

function reservationHoursPlayed(array $timeSlots): int
{
    return count(normalizeReservationTimeSlots($timeSlots));
}

