<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

echo json_encode([
    'success' => true,
    'user_email' => $_SESSION['email'],
    'user_id' => $_SESSION['user_id']
]);
