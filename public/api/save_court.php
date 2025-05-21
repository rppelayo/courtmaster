<?php
session_start();
require_once "../includes/db.php";

header("Content-Type: application/json");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] == 'user') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$id = $_POST['id'] ?? '';
$name = $_POST['name'] ?? '';
$location = $_POST['location'] ?? '';
$price = $_POST['price'] ?? '';
$owner_id = $_SESSION['user_id'];
$type = $_POST['type'] ?? '';
$open_time = $_POST['open_hour'] ?? '';
$close_time = $_POST['close_hour'] ?? '';
$imagePath = null;

if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
    $imagePath = uniqid() . '.' . $ext;
    move_uploaded_file($_FILES['image']['tmp_name'], "../images/courts/$imagePath");
}

try {
    if ($id) {
        // Update existing
        $fields = "name = ?, location = ?, price = ?, type = ?, open_time = ?, close_time = ?";
        $params = [$name, $location, $price, $type, $open_time, $close_time];

        if ($imagePath) {
            $fields .= ", image_path = ?";
            $params[] = $imagePath;
        }

        $params[] = $id;
        $stmt = $pdo->prepare("UPDATE courts SET $fields WHERE id = ?");
        $stmt->execute($params);
    } else {
        // Insert new
        $stmt = $pdo->prepare("INSERT INTO courts (name, location, price, owner_id, open_time, close_time, type, image_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $location, $price, $owner_id, $open_time, $close_time, $type, $imagePath]);
    }

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
