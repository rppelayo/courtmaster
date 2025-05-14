<?php
// api/complete_reservation.php
session_start();
header('Content-Type: application/json');

require_once '../includes/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

// Ensure all required fields are provided
if (!isset($data['fullName'], $data['contactNumber'], $data['email'], $data['paymentMethod'], $data['sport'], $data['court'], $data['date'], $data['time'])) {
    echo json_encode(['success' => false, 'message' => 'Incomplete data']);
    exit;
}

$user_id = $_SESSION['user_id'];
$fullName = $data['fullName'];
$contactNumber = $data['contactNumber'];
$email = $data['email'];
$reservationInfo = $data['reservationInfo'];
$paymentMethod = $data['paymentMethod'];
$sport = $data['sport'];
$court = $data['court'];
$date = $data['date'];
$time = $data['time'];

// Insert the reservation into the database
try {
    $stmt = $pdo->prepare("INSERT INTO reservations (user_id, full_name, contact_number, email, reservation_info, payment_method, sport, court, date, time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $fullName, $contactNumber, $email, $reservationInfo, $paymentMethod, $sport, $court, $date, $time]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
