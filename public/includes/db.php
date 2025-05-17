<?php
$host = 'localhost';
$dbname = 'courtmaster';
$user = 'olan88';
$pass = '@ha$K+hXi)@k';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}
?>
