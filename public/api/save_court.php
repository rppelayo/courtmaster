<?php
session_start();
require_once "../includes/db.php";
require_once "../includes/admin_activity.php";

header("Content-Type: application/json");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] == 'user') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$id = $_POST['id'] ?? '';
$name = $_POST['name'] ?? '';
$location = $_POST['location'] ?? '';
$price = $_POST['price'] ?? '';
$member_price = $_POST['member_price'] ?? null;
$owner_id = $_SESSION['user_id'];
$type = 'pickleball';
$open_time = $_POST['open_hour'] ?? '';
$close_time = $_POST['close_hour'] ?? '';
$imagePath = null;

if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
    $imagePath = uniqid() . '.' . $ext;
    move_uploaded_file($_FILES['image']['tmp_name'], "../images/courts/$imagePath");
}

try {
    $memberPriceValue = trim((string) $member_price);
    $memberPriceValue = $memberPriceValue === '' ? null : $memberPriceValue;
    $subjectId = null;
    $actionType = '';
    $description = '';

    if ($id) {
        // Update existing
        $fields = "name = ?, location = ?, price = ?, member_price = ?, type = ?, open_time = ?, close_time = ?";
        $params = [$name, $location, $price, $memberPriceValue, $type, $open_time, $close_time];

        if ($imagePath) {
            $fields .= ", image_path = ?";
            $params[] = $imagePath;
        }

        $params[] = $id;
        $stmt = $pdo->prepare("UPDATE courts SET $fields WHERE id = ?");
        $stmt->execute($params);
        $subjectId = (int) $id;
        $actionType = 'court_updated';
        $description = "Updated court {$name}";
    } else {
        // Insert new
        $stmt = $pdo->prepare("INSERT INTO courts (name, location, price, member_price, owner_id, open_time, close_time, type, image_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $location, $price, $memberPriceValue, $owner_id, $open_time, $close_time, $type, $imagePath]);
        $subjectId = (int) $pdo->lastInsertId();
        $actionType = 'court_created';
        $description = "Created court {$name}";
    }

    adminActivityLog($pdo, [
        'action_type' => $actionType,
        'subject_type' => 'court',
        'subject_id' => $subjectId,
        'description' => $description,
        'metadata' => [
            'court_name' => $name,
            'location' => $location,
            'regular_rate' => (float) $price,
            'member_rate' => $memberPriceValue !== null ? (float) $memberPriceValue : null,
            'open_time' => $open_time,
            'close_time' => $close_time,
        ],
    ]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
