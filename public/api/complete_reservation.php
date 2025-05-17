<?php
// api/complete_reservation.php
session_start();
header('Content-Type: application/json');

require_once '../includes/db.php';

$data = json_decode(file_get_contents('php://input'), true);

// Ensure all required fields are provided
if (!isset($data['fullName'], $data['contactNumber'], $data['email'], $data['paymentMethod'], $data['sport'], $data['court'], $data['date'], $data['time'])) {
    echo json_encode(['success' => false, 'message' => 'Incomplete data']);
    exit;
}

// Common data
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : '';
$fullName = $data['fullName'];
$contactNumber = $data['contactNumber'];
$email = $data['email'];
$reservationInfo = $data['reservationInfo'];
$paymentMethod = $data['paymentMethod'];
$sport = $data['sport'];
$court = $data['court'];
$date = $data['date'];
$time = $data['time'];

try {
    // Insert the reservation
    $stmt = $pdo->prepare("INSERT INTO reservations (user_id, full_name, contact_number, email, reservation_info, payment_method, sport, court, date, time)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $fullName, $contactNumber, $email, $reservationInfo, $paymentMethod, $sport, $court, $date, $time]);

    $reservation_id = $pdo->lastInsertId();

    // If guest (no user_id), also save to guest_reservations table
    if (!$user_id) {
        $guest_stmt = $pdo->prepare("INSERT INTO reservation_guests (reservation_id, guest_name, guest_contact)
                                     VALUES (?, ?, ?)");
        $guest_stmt->execute([$reservation_id, $fullName, $contactNumber]);
    }
    
    $to = $email;
    $subject = "Your CourtMaster Reservation Confirmation";

    $message = "
    <html>
    <head><title>Reservation Confirmation</title></head>
    <body>
        <h2>Hi {$fullName},</h2>
        <p>Thank you for reserving with <strong>CourtMaster</strong>! Here are your reservation details:</p>
        <ul>
            <li><strong>Sport:</strong> {$sport}</li>
            <li><strong>Court:</strong> {$court}</li>
            <li><strong>Date:</strong> {$date}</li>
            <li><strong>Time:</strong> {$time}</li>
            <li><strong>Payment Method:</strong> {$paymentMethod}</li>
            <li><strong>Additional Info:</strong> {$reservationInfo}</li>
            <li><strong>Fee:</strong> P250.00</li>
        </ul>
        <p>If you have any questions, reply to this email.</p>
        <br/>
        <p>See you on the court!</p>
    </body>
    </html>
    ";

    $headers  = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: CourtMaster <no-reply@courtmaster.online>" . "\r\n";

    // Send the email
    mail($to, $subject, $message, $headers);
    // -----------------------------

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
