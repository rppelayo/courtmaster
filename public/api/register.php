<?php
session_start();
require_once '../includes/db.php';

$full_name = trim($_POST['name']);
$email = trim($_POST['email']);
$parts = explode("@", $email);
$name = $parts[0];
$password = $_POST['password'];
$contact = $_POST['contact_number'];

if (!$full_name || !$email || !$password || !$name || !$contact) {
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
$stmt = $pdo->prepare("INSERT INTO users (name, full_name, email, contact_number, password_hash) VALUES (?, ?, ?, ?, ?)");
$success = $stmt->execute([$name, $full_name, $email, $contact, $hashed_password]);

if ($success) {
  // Optionally auto-login the user
  $_SESSION['user_id'] = $pdo->lastInsertId();
  $_SESSION['user_name'] = $name;
  header("Location: ../dashboard.php");
  exit;
} else {
  echo "Registration failed.";
}
