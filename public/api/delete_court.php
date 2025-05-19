<?php
session_start();
require_once "../includes/db.php";

header("Content-Type: application/json");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$id = $_GET['id'] ?? null;

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Missing court ID']);
    exit;
}

try {
    // Optional: delete court image from server if needed
    $stmt = $pdo->prepare("SELECT image_path FROM courts WHERE id = ?");
    $stmt->execute([$id]);
    $court = $stmt->fetch();
    if ($court && $court['image_path']) {
        $file = "../uploads/courts/" . $court['image_path'];
        if (file_exists($file)) {
            unlink($file);
        }
    }

    $stmt = $pdo->prepare("DELETE FROM courts WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
