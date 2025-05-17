<?php
session_start();
require_once "../includes/db.php";

header('Content-Type: application/json');

if (!isset($_GET['sport']) || empty($_GET['sport'])) {
    echo json_encode([]);
    exit();
}

$sport = $_GET['sport'];

// Assuming you have a "sport" column in courts table.
// If not, you need to add it or map courts to sports elsewhere.

$stmt = $pdo->prepare("SELECT id, name, location, price, image_path FROM courts WHERE type = ?");
$stmt->execute([$sport]);
$courts = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($courts);
