<?php
declare(strict_types=1);

session_start();

require_once '../includes/db.php';
require_once '../includes/notifications.php';

function notificationRequestData(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return [];
    }

    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $rawBody = file_get_contents('php://input');
        if (is_string($rawBody) && trim($rawBody) !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    return $_POST;
}

function notificationWantsJson(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

    return str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest';
}

function notificationRedirect(string $next, array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);

    if (notificationWantsJson()) {
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }

    $target = trim($next) !== '' ? $next : '../dashboard.php';
    header('Location: ' . $target);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    notificationRedirect('../index.html', ['success' => false, 'message' => 'Unauthorized'], 403);
}

$data = notificationRequestData();
$scope = strtolower(trim((string) ($data['scope'] ?? 'single')));
$notificationId = (int) ($data['notification_id'] ?? 0);
$next = (string) ($data['next'] ?? '../dashboard.php');
$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['role'] ?? 'user');

try {
    $affected = 0;
    if ($scope === 'all') {
        $affected = notificationsMarkAllRead($pdo, $userId, $role);
    } else {
        $affected = notificationsMarkRead($pdo, $notificationId, $userId, $role);
    }

    notificationRedirect($next, [
        'success' => true,
        'affected' => $affected,
    ]);
} catch (Throwable $exception) {
    notificationRedirect($next, [
        'success' => false,
        'message' => $exception->getMessage(),
    ], 500);
}
