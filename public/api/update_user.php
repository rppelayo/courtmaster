<?php
// api/update_user.php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';

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

try {
    $stmt = $pdo->prepare("UPDATE users SET name = ?, full_name = ?, email = ?, contact_number = ?, role = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([
        $data['name'],
        $data['full_name'],
        $data['email'],
        $data['contact_number'],
        $data['role'],
        $data['id']
    ]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
