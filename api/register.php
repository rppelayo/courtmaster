<?php
session_start();
require_once '../includes/db.php';

$name = trim($_POST['name']);
$email = trim($_POST['email']);
$password = $_POST['password'];

if (!$name || !$email || !$password) {
  die("Missing fields.");
}

// Check if user already exists
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);

if ($stmt->rowCount() > 0) {
  echo "Email already registered.";
  exit;
}

// Hash password (SHA256 for simplicity, use bcrypt in real-world apps)
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Insert new user
$stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)");
$success = $stmt->execute([$name, $email, $hashed_password]);

if ($success) {
  // Optionally auto-login the user
  $_SESSION['user_id'] = $pdo->lastInsertId();
  $_SESSION['user_name'] = $name;
  header("Location: ../public/dashboard2.html");
  exit;
} else {
  echo "Registration failed.";
}
