<?php
session_start();
require_once "../includes/db.php";

$action = $_POST['action'] ?? '';

if ($action === 'register') {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)");
    try {
        $stmt->execute([$name, $email, $pass]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Email already exists']);
    }
}
elseif ($action === 'login') {
    $email = $_POST['email'];
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['email'] = $email;
        $_SESSION['role'] = $user['role'];
 
        echo json_encode(['success' => true, 'role' => $user['role']]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
    }
}
