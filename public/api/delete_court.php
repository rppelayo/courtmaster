<?php
session_start();
require_once "../includes/db.php";
require_once "../includes/admin_activity.php";

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
    $stmt = $pdo->prepare("SELECT name, image_path FROM courts WHERE id = ?");
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

    adminActivityLog($pdo, [
        'action_type' => 'court_deleted',
        'subject_type' => 'court',
        'subject_id' => (int) $id,
        'description' => 'Deleted court ' . trim((string) ($court['name'] ?? ('#' . $id))),
        'metadata' => [
            'court_name' => $court['name'] ?? null,
        ],
    ]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
