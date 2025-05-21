<?php
session_start();
require_once "../includes/db.php";

// Check if admin (optional, restrict access)
if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit();
}

// Fetch all users except admins, or whatever you prefer
$stmt = $pdo->prepare("SELECT id, name FROM users WHERE role != 'admin' ORDER BY name");
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($users);
