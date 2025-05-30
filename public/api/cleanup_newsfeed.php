<?php
// cleanup_newsfeed.php
require_once '../includes/db.php';

// Define how old posts should be to delete (e.g., older than 30 days)
$days_to_keep = 30;

$stmt = $pdo->prepare("DELETE FROM newsfeed WHERE created_at < NOW() - INTERVAL ? DAY");
$stmt->execute([$days_to_keep]);

echo "Old posts deleted.\n";
