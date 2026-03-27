<?php
declare(strict_types=1);

function notificationsTableExists(PDO $pdo): bool
{
    static $cache = [];

    $cacheKey = spl_object_id($pdo);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $statement = $pdo->prepare(
            'SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1'
        );
        $statement->execute(['notifications']);
        $cache[$cacheKey] = (bool) $statement->fetchColumn();
    } catch (Throwable) {
        $cache[$cacheKey] = false;
    }

    return $cache[$cacheKey];
}

function notificationsNormalizeRole(string $role): string
{
    $normalized = strtolower(trim($role));
    return $normalized !== '' ? $normalized : 'user';
}

function notificationsNormalizeTime(string $timeValue): string
{
    $parts = explode(':', trim($timeValue));
    if (count($parts) < 2) {
        return '';
    }

    $hour = (int) $parts[0];
    $minute = (int) $parts[1];
    $second = isset($parts[2]) ? (int) $parts[2] : 0;

    return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
}

function notificationsFormatTime(string $timeValue): string
{
    $normalized = notificationsNormalizeTime($timeValue);
    if ($normalized === '') {
        return 'Time TBD';
    }

    [$hour, $minute] = array_map('intval', explode(':', $normalized));
    $suffix = $hour >= 12 ? 'PM' : 'AM';
    $hour12 = $hour % 12;
    if ($hour12 === 0) {
        $hour12 = 12;
    }

    return sprintf('%d:%02d %s', $hour12, $minute, $suffix);
}

function notificationsBuildTimeRange(array $timeSlots): string
{
    $normalizedSlots = array_values(
        array_filter(
            array_map(static fn(mixed $slot): string => notificationsNormalizeTime((string) $slot), $timeSlots),
            static fn(string $slot): bool => $slot !== ''
        )
    );

    if ($normalizedSlots === []) {
        return 'time TBD';
    }

    sort($normalizedSlots);
    $start = $normalizedSlots[0];
    $end = $normalizedSlots[count($normalizedSlots) - 1];

    try {
        $endTime = new DateTimeImmutable($end);
        $endLabel = notificationsFormatTime($endTime->modify('+1 hour')->format('H:i:s'));
    } catch (Throwable) {
        $endLabel = notificationsFormatTime($end);
    }

    return notificationsFormatTime($start) . ' - ' . $endLabel;
}

function notificationsBuildScheduleSummary(string $date, array $timeSlots): string
{
    $dateLabel = $date;
    try {
        $dateLabel = (new DateTimeImmutable($date))->format('M j, Y');
    } catch (Throwable) {
        // Use the raw date string when parsing fails.
    }

    return $dateLabel . ' • ' . notificationsBuildTimeRange($timeSlots);
}

function notificationsPaymentMethodLabel(string $method): string
{
    $normalized = strtolower(trim($method));
    if ($normalized === 'gcash-maya') {
        return 'GCash / Maya';
    }

    if ($normalized === '') {
        return 'Payment pending';
    }

    return ucwords(str_replace('-', ' ', $normalized));
}

function notificationsCreate(PDO $pdo, array $payload): bool
{
    if (!notificationsTableExists($pdo)) {
        return false;
    }

    $title = trim((string) ($payload['title'] ?? ''));
    $message = trim((string) ($payload['message'] ?? ''));
    $type = trim((string) ($payload['type'] ?? 'general'));
    if ($title === '' || $message === '') {
        return false;
    }

    $userId = array_key_exists('user_id', $payload) && $payload['user_id'] !== null
        ? (int) $payload['user_id']
        : null;
    $targetRole = array_key_exists('target_role', $payload) && $payload['target_role'] !== null
        ? notificationsNormalizeRole((string) $payload['target_role'])
        : null;
    $linkUrl = trim((string) ($payload['link_url'] ?? ''));
    $linkUrl = $linkUrl !== '' ? $linkUrl : null;

    if ($userId === null && $targetRole === null) {
        return false;
    }

    try {
        $statement = $pdo->prepare(
            'INSERT INTO notifications (
                user_id,
                target_role,
                type,
                title,
                message,
                link_url
            ) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $userId,
            $targetRole,
            $type,
            $title,
            $message,
            $linkUrl,
        ]);

        return true;
    } catch (Throwable) {
        return false;
    }
}

function notificationsCreateForUser(PDO $pdo, int $userId, array $payload): bool
{
    if ($userId <= 0) {
        return false;
    }

    $payload['user_id'] = $userId;
    return notificationsCreate($pdo, $payload);
}

function notificationsCreateForRole(PDO $pdo, string $role, array $payload): bool
{
    $payload['target_role'] = notificationsNormalizeRole($role);
    return notificationsCreate($pdo, $payload);
}

function notificationsFetchVisible(PDO $pdo, int $userId, string $role, int $limit = 8, bool $unreadOnly = false): array
{
    if (!notificationsTableExists($pdo)) {
        return [];
    }

    $limit = max(1, min(50, $limit));
    $whereClauses = [
        '(user_id = ? OR (user_id IS NULL AND target_role = ?))',
    ];
    $params = [$userId, notificationsNormalizeRole($role)];

    if ($unreadOnly) {
        $whereClauses[] = 'is_read = 0';
    }

    $statement = $pdo->prepare(
        'SELECT id, user_id, target_role, type, title, message, link_url, is_read, created_at, read_at
         FROM notifications
         WHERE ' . implode(' AND ', $whereClauses) . '
         ORDER BY is_read ASC, created_at DESC, id DESC
         LIMIT ' . $limit
    );
    $statement->execute($params);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function notificationsUnreadCount(PDO $pdo, int $userId, string $role): int
{
    if (!notificationsTableExists($pdo)) {
        return 0;
    }

    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM notifications
         WHERE is_read = 0
           AND (user_id = ? OR (user_id IS NULL AND target_role = ?))'
    );
    $statement->execute([$userId, notificationsNormalizeRole($role)]);

    return (int) $statement->fetchColumn();
}

function notificationsMarkRead(PDO $pdo, int $notificationId, int $userId, string $role): int
{
    if (!notificationsTableExists($pdo) || $notificationId <= 0) {
        return 0;
    }

    $statement = $pdo->prepare(
        'UPDATE notifications
         SET is_read = 1, read_at = NOW()
         WHERE id = ?
           AND (user_id = ? OR (user_id IS NULL AND target_role = ?))'
    );
    $statement->execute([$notificationId, $userId, notificationsNormalizeRole($role)]);

    return $statement->rowCount();
}

function notificationsMarkAllRead(PDO $pdo, int $userId, string $role): int
{
    if (!notificationsTableExists($pdo)) {
        return 0;
    }

    $statement = $pdo->prepare(
        'UPDATE notifications
         SET is_read = 1, read_at = NOW()
         WHERE is_read = 0
           AND (user_id = ? OR (user_id IS NULL AND target_role = ?))'
    );
    $statement->execute([$userId, notificationsNormalizeRole($role)]);

    return $statement->rowCount();
}

function notificationsTimeAgo(?string $timestamp): string
{
    if (!is_string($timestamp) || trim($timestamp) === '') {
        return 'Just now';
    }

    try {
        $createdAt = new DateTimeImmutable($timestamp);
        $now = new DateTimeImmutable('now');
        $seconds = max(0, $now->getTimestamp() - $createdAt->getTimestamp());
    } catch (Throwable) {
        return $timestamp;
    }

    if ($seconds < 60) {
        return 'Just now';
    }

    $minutes = (int) floor($seconds / 60);
    if ($minutes < 60) {
        return $minutes . ' min' . ($minutes === 1 ? '' : 's') . ' ago';
    }

    $hours = (int) floor($minutes / 60);
    if ($hours < 24) {
        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
    }

    $days = (int) floor($hours / 24);
    if ($days < 7) {
        return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    }

    return $createdAt->format('M j, Y g:i A');
}
