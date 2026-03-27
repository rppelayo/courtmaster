<?php
// api/delete_user.php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';
require_once '../includes/admin_activity.php';

// Only allow admins
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get input data
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['id'])) {
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit;
}

try {
    $targetUserId = (int) $data['id'];

    $userStatement = $pdo->prepare("SELECT full_name, email, role FROM users WHERE id = ? LIMIT 1");
    $userStatement->execute([$targetUserId]);
    $user = $userStatement->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$targetUserId]);

    adminActivityLog($pdo, [
        'action_type' => 'user_deleted',
        'subject_type' => 'user',
        'subject_id' => $targetUserId,
        'description' => 'Deleted user ' . trim((string) (($user['full_name'] ?? '') !== '' ? $user['full_name'] : ($user['email'] ?? ('#' . $targetUserId)))),
        'metadata' => [
            'email' => $user['email'] ?? null,
            'role' => $user['role'] ?? null,
        ],
    ]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
