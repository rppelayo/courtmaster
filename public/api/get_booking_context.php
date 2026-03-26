<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once '../includes/db.php';
require_once '../includes/pricing.php';

$defaultPayload = [
    'success' => true,
    'isLoggedIn' => false,
    'isMember' => false,
    'email' => '',
    'fullName' => '',
    'contactNumber' => '',
    'role' => '',
    'processFee' => pricingProcessingFeeForRole(null),
];

if (!isset($_SESSION['user_id'])) {
    echo json_encode($defaultPayload);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$statement = $pdo->prepare('SELECT email, full_name, contact_number, role FROM users WHERE id = ? LIMIT 1');
$statement->execute([$userId]);
$user = $statement->fetch(PDO::FETCH_ASSOC);

$role = (string) ($user['role'] ?? ($_SESSION['role'] ?? ''));
$payload = [
    'success' => true,
    'isLoggedIn' => true,
    'isMember' => pricingRoleIsMember($role),
    'email' => (string) ($user['email'] ?? ($_SESSION['email'] ?? '')),
    'fullName' => (string) ($user['full_name'] ?? ''),
    'contactNumber' => (string) ($user['contact_number'] ?? ''),
    'role' => $role,
    'processFee' => pricingProcessingFeeForRole($role),
];

echo json_encode($payload);
