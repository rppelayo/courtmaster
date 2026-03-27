<?php
declare(strict_types=1);

function adminActivityTableExists(PDO $pdo): bool
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
        $statement->execute(['admin_activity_logs']);
        $cache[$cacheKey] = (bool) $statement->fetchColumn();
    } catch (Throwable) {
        $cache[$cacheKey] = false;
    }

    return $cache[$cacheKey];
}

function adminActivityActorName(?array $session = null): string
{
    $sessionData = $session ?? ($_SESSION ?? []);

    foreach (['full_name', 'user_name', 'email'] as $key) {
        $value = trim((string) ($sessionData[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    $userId = (int) ($sessionData['user_id'] ?? 0);
    return $userId > 0 ? 'Admin #' . $userId : 'System';
}

function adminActivityLog(PDO $pdo, array $payload): bool
{
    if (!adminActivityTableExists($pdo)) {
        return false;
    }

    $actionType = trim((string) ($payload['action_type'] ?? ''));
    $subjectType = trim((string) ($payload['subject_type'] ?? ''));
    $description = trim((string) ($payload['description'] ?? ''));

    if ($actionType === '' || $subjectType === '' || $description === '') {
        return false;
    }

    $sessionData = $_SESSION ?? [];
    $actorUserId = array_key_exists('actor_user_id', $payload)
        ? ($payload['actor_user_id'] !== null ? (int) $payload['actor_user_id'] : null)
        : ((isset($sessionData['user_id']) && (int) $sessionData['user_id'] > 0) ? (int) $sessionData['user_id'] : null);
    $actorRole = strtolower(trim((string) ($payload['actor_role'] ?? ($sessionData['role'] ?? 'admin'))));
    $actorRole = $actorRole !== '' ? $actorRole : 'admin';
    $actorName = trim((string) ($payload['actor_name'] ?? adminActivityActorName($sessionData)));
    $actorName = $actorName !== '' ? $actorName : 'System';
    $subjectId = array_key_exists('subject_id', $payload) && $payload['subject_id'] !== null
        ? (int) $payload['subject_id']
        : null;
    $metadataJson = null;

    if (array_key_exists('metadata', $payload) && $payload['metadata'] !== null) {
        try {
            $metadataJson = json_encode(
                $payload['metadata'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (Throwable) {
            $metadataJson = null;
        }
    }

    try {
        $statement = $pdo->prepare(
            'INSERT INTO admin_activity_logs (
                actor_user_id,
                actor_role,
                actor_name,
                action_type,
                subject_type,
                subject_id,
                description,
                metadata_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $actorUserId,
            $actorRole,
            $actorName,
            $actionType,
            $subjectType,
            $subjectId,
            $description,
            $metadataJson,
        ]);

        return true;
    } catch (Throwable) {
        return false;
    }
}
