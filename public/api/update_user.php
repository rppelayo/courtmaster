<?php
// api/update_user.php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';
require_once '../includes/admin_activity.php';
require_once '../includes/membership.php';

// Only allow admins
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
} 

// Get input data
$data = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($data['id'], $data['full_name'], $data['email'], $data['contact_number'], $data['role'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$membershipStatus = membershipNormalizeStatus((string) ($data['membership_status'] ?? MEMBERSHIP_STATUS_INACTIVE));
$membershipPlan = trim((string) ($data['membership_plan'] ?? ''));
$memberSince = trim((string) ($data['member_since'] ?? ''));
$membershipExpiresAt = trim((string) ($data['membership_expires_at'] ?? ''));
$membershipBenefits = trim((string) ($data['membership_benefits'] ?? ''));
$role = strtolower(trim((string) $data['role']));
$allowedRoles = ['user', 'admin'];
if (!in_array($role, $allowedRoles, true)) {
    $role = 'user';
}

if ($membershipStatus === MEMBERSHIP_STATUS_ACTIVE && $memberSince === '') {
    $memberSince = (new DateTimeImmutable('today'))->format('Y-m-d');
}

$memberSinceValue = $memberSince !== '' ? $memberSince : null;
$membershipExpiresValue = $membershipExpiresAt !== '' ? $membershipExpiresAt : null;
$membershipPlanValue = $membershipPlan !== '' ? $membershipPlan : null;
$membershipBenefitsValue = $membershipBenefits !== '' ? $membershipBenefits : null;

try {
    $targetUserId = (int) $data['id'];
    $stmt = $pdo->prepare(
        "UPDATE users
         SET name = ?, full_name = ?, email = ?, contact_number = ?, role = ?, membership_status = ?, membership_plan = ?, member_since = ?, membership_expires_at = ?, membership_benefits = ?, updated_at = NOW()
         WHERE id = ?"
    );
    $stmt->execute([
        $data['name'],
        $data['full_name'],
        $data['email'],
        $data['contact_number'],
        $role,
        $membershipStatus,
        $membershipPlanValue,
        $memberSinceValue,
        $membershipExpiresValue,
        $membershipBenefitsValue,
        $targetUserId
    ]);

    adminActivityLog($pdo, [
        'action_type' => 'user_updated',
        'subject_type' => 'user',
        'subject_id' => $targetUserId,
        'description' => 'Updated user ' . trim((string) ($data['full_name'] ?? $data['email'] ?? ('#' . $targetUserId))),
        'metadata' => [
            'login' => $data['name'] ?? null,
            'full_name' => $data['full_name'] ?? null,
            'email' => $data['email'] ?? null,
            'role' => $role,
            'membership_status' => $membershipStatus,
            'membership_plan' => $membershipPlanValue,
            'membership_expires_at' => $membershipExpiresValue,
        ],
    ]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
