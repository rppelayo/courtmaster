<?php
// api/complete_reservation.php
session_start();
header('Content-Type: application/json');

require_once '../includes/db.php';

$data = json_decode(file_get_contents('php://input'), true);

// Ensure all required fields are provided
if (!isset($data['fullName'], $data['contactNumber'], $data['email'], $data['paymentMethod'], $data['sport'], $data['court'], $data['court_id'], $data['section'], $data['date'], $data['time'])) {
    echo json_encode(['success' => false, 'message' => 'Incomplete data']);
    exit;
}

// Common data
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : '';
$fullName = $data['fullName'];
$contactNumber = $data['contactNumber'];
$email = $data['email'];
$reservationInfo = $data['reservationInfo'] ?? '';
$paymentMethod = $data['paymentMethod'];
$sport = $data['sport'];
$court = $data['court'];
$court_id = $data['court_id'];
$section = $data['section'];
$date = $data['date'];
$timeSlots = $data['time']; // Could be string or array

if (is_string($timeSlots)) {
    $timeSlots = explode(',', $timeSlots);
}

if (!is_array($timeSlots) || count($timeSlots) === 0) {
    echo json_encode(['success' => false, 'message' => 'Time slots must be a non-empty array']);
    exit;
}


if (!is_array($timeSlots) || count($timeSlots) === 0) {
    echo json_encode(['success' => false, 'message' => 'Time slots must be a non-empty array']);
    exit;
}

$section = $data['section']; // could be string or array

if (is_string($section)) {
    $section = explode(',', $section);
}

if (!is_array($section) || count($section) === 0) {
    echo json_encode(['success' => false, 'message' => 'Section must be a non-empty array']);
    exit;
}


try {
    // Start transaction
    $pdo->beginTransaction();

    // Insert the reservation WITHOUT time column
    $reservation_ids = [];

    foreach ($section as $sec) {
        // Insert reservation for this section number
        $stmt = $pdo->prepare("INSERT INTO reservations (user_id, full_name, contact_number, email, reservation_info, payment_method, sport, court, court_id, section_number, date)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $fullName, $contactNumber, $email, $reservationInfo, $paymentMethod, $sport, $court, $court_id, $sec, $date]);

        $reservation_id = $pdo->lastInsertId();
        $reservation_ids[] = $reservation_id;

        // Insert all time slots for this reservation & section
        $slotStmt = $pdo->prepare("INSERT INTO reservation_slots (reservation_id, time, section_number) VALUES (?, ?, ?)");
        foreach ($timeSlots as $slotTime) {
            $slotStmt->execute([$reservation_id, $slotTime, $sec]);
        }
    }

    // Handle guest_reservations for all reservation ids
    if (!$user_id) {
        $guest_stmt = $pdo->prepare("INSERT INTO reservation_guests (reservation_id, guest_name, guest_contact)
                                     VALUES (?, ?, ?)");
        foreach ($reservation_ids as $rid) {
            $guest_stmt->execute([$rid, $fullName, $contactNumber]);
        }
    }

    // Commit transaction
    $pdo->commit();

    // Format the times as a list for the email
    $timesListHtml = '<ul>';
    foreach ($timeSlots as $slotTime) {
        $timesListHtml .= "<li>{$slotTime}</li>";
    }
    $timesListHtml .= '</ul>';

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
            <li><strong>Time Slots:</strong> {$timesListHtml}</li>
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
    // mail($to, $subject, $message, $headers);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    // Rollback on error
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
