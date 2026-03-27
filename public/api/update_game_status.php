<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once '../includes/db.php';
require_once '../includes/game_status.php';

function gameStatusResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function gameStatusRequestData(): array
{
    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody) || trim($rawBody) === '') {
        return $_POST;
    }

    $decoded = json_decode($rawBody, true);
    return is_array($decoded) ? $decoded : $_POST;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gameStatusResponse(['success' => false, 'message' => 'Invalid request method.'], 405);
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') === 'user') {
    gameStatusResponse(['success' => false, 'message' => 'Access denied.'], 403);
}

$data = gameStatusRequestData();
$reservationId = (int) ($data['id'] ?? 0);
$requestedStatus = normalizeGameStatus((string) ($data['game_status'] ?? ''));
if ($reservationId <= 0) {
    gameStatusResponse(['success' => false, 'message' => 'Reservation ID is required.'], 422);
}

$reservationStatement = $pdo->prepare(
    'SELECT r.id, r.court_id, r.is_admin_set, r.game_status, r.checked_in_at, r.in_progress_at, r.completed_at, c.owner_id
     FROM reservations r
     LEFT JOIN courts c ON c.id = r.court_id
     WHERE r.id = ?
     LIMIT 1'
);
$reservationStatement->execute([$reservationId]);
$reservation = $reservationStatement->fetch(PDO::FETCH_ASSOC);

if (!is_array($reservation)) {
    gameStatusResponse(['success' => false, 'message' => 'Reservation not found.'], 404);
}

if ((int) ($reservation['is_admin_set'] ?? 0) === 1) {
    gameStatusResponse(['success' => false, 'message' => 'Admin court blocks do not use game statuses.'], 422);
}

$currentStatus = normalizeGameStatus((string) ($reservation['game_status'] ?? GAME_STATUS_RESERVED));
if ($currentStatus === $requestedStatus) {
    gameStatusResponse([
        'success' => true,
        'game_status' => $requestedStatus,
        'game_status_label' => gameStatusLabel($requestedStatus),
        'game_status_badge_class' => gameStatusBadgeClass($requestedStatus),
    ]);
}

$checkedInAt = $reservation['checked_in_at'] ?? null;
$inProgressAt = $reservation['in_progress_at'] ?? null;
$completedAt = $reservation['completed_at'] ?? null;
$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

switch ($requestedStatus) {
    case GAME_STATUS_RESERVED:
        $checkedInAt = null;
        $inProgressAt = null;
        $completedAt = null;
        break;

    case GAME_STATUS_CHECKED_IN:
        $checkedInAt = $checkedInAt ?: $now;
        $inProgressAt = null;
        $completedAt = null;
        break;

    case GAME_STATUS_IN_PROGRESS:
        $checkedInAt = $checkedInAt ?: $now;
        $inProgressAt = $inProgressAt ?: $now;
        $completedAt = null;
        break;

    case GAME_STATUS_COMPLETED:
        $completedAt = $completedAt ?: $now;
        break;
}

$updateStatement = $pdo->prepare(
    'UPDATE reservations
     SET game_status = ?, checked_in_at = ?, in_progress_at = ?, completed_at = ?
     WHERE id = ?'
);
$updateStatement->execute([
    $requestedStatus,
    $checkedInAt,
    $inProgressAt,
    $completedAt,
    $reservationId,
]);

gameStatusResponse([
    'success' => true,
    'game_status' => $requestedStatus,
    'game_status_label' => gameStatusLabel($requestedStatus),
    'game_status_badge_class' => gameStatusBadgeClass($requestedStatus),
    'checked_in_at' => $checkedInAt,
    'in_progress_at' => $inProgressAt,
    'completed_at' => $completedAt,
]);
