<?php
session_start();
require_once "../includes/db.php";

header('Content-Type: application/json');

if (!isset($_GET['sport']) || empty($_GET['sport'])) {
    echo json_encode([]);
    exit();
}

$sport = $_GET['sport'];

$stmt = $pdo->prepare("
    SELECT 
        courts.id, 
        courts.name, 
        courts.location, 
        courts.open_time, 
        courts.close_time, 
        courts.price, 
        courts.image_path,
        users.contact_number
    FROM courts
    LEFT JOIN users ON courts.owner_id = users.id
    WHERE courts.type = ?
");

$stmt->execute([$sport]);
$courts = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($courts);
