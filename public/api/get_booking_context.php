<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once '../includes/db.php';
require_once '../includes/pricing.php';
require_once '../includes/membership.php';

$defaultPayload = [
    'success' => true,
    'isLoggedIn' => false,
    'isMember' => false,
    'email' => '',
    'fullName' => '',
    'contactNumber' => '',
    'role' => '',
    'processFee' => pricingProcessingFeeForRole(null),
    'membershipStatus' => MEMBERSHIP_STATUS_INACTIVE,
    'membershipStatusLabel' => membershipStatusLabel(MEMBERSHIP_STATUS_INACTIVE),
    'membershipPlan' => '',
    'membershipExpiresAt' => '',
    'membershipBenefits' => [],
];

if (!isset($_SESSION['user_id'])) {
    echo json_encode($defaultPayload);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$user = membershipFetchUser($pdo, $userId);

$role = (string) ($user['role'] ?? ($_SESSION['role'] ?? ''));
$membershipStatus = is_array($user) ? membershipResolveStatus($user) : MEMBERSHIP_STATUS_INACTIVE;
$payload = [
    'success' => true,
    'isLoggedIn' => true,
    'isMember' => pricingUserIsMember($user),
    'email' => (string) ($user['email'] ?? ($_SESSION['email'] ?? '')),
    'fullName' => (string) ($user['full_name'] ?? ''),
    'contactNumber' => (string) ($user['contact_number'] ?? ''),
    'role' => $role,
    'processFee' => pricingProcessingFeeForUser($user),
    'membershipStatus' => $membershipStatus,
    'membershipStatusLabel' => membershipStatusLabel($membershipStatus),
    'membershipPlan' => (string) ($user['membership_plan'] ?? ''),
    'membershipExpiresAt' => (string) ($user['membership_expires_at'] ?? ''),
    'membershipBenefits' => membershipBenefitLines((string) ($user['membership_benefits'] ?? ''), pricingUserIsMember($user)),
];

echo json_encode($payload);
