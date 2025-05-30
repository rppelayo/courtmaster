<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';

// Load Composer's autoloader
//require '/home/olanpelayo0788/vendor/autoload.php'; // Adjust if needed
    
//use PHPMailer\PHPMailer\PHPMailer;
//use PHPMailer\PHPMailer\Exception;


$data = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required = ['fullName', 'contactNumber', 'email', 'paymentMethod', 'sport', 'court', 'court_id', 'date', 'time', 'payment'];
foreach ($required as $key) {
    if (empty($data[$key])) {
        echo json_encode(['success' => false, 'message' => "Missing required field: $key"]);
        exit;
    }
}

// Common fields
$user_id = $_SESSION['user_id'] ?? '';
$fullName = $data['fullName'];
$contactNumber = $data['contactNumber'];
$email = $data['email'];
$reservationInfo = $data['reservationInfo'] ?? '';
$paymentMethod = $data['paymentMethod'];
$sport = $data['sport'];
$court = $data['court'];
$court_id = $data['court_id'];
$payment = $data['payment'];
$date = $data['date'];
$timeSlots = is_array($data['time']) ? $data['time'] : explode(',', $data['time']);

if (empty($timeSlots)) {
    echo json_encode(['success' => false, 'message' => 'Time slots must be a non-empty array']);
    exit;
}

// Determine section(s)
$sections = [];
if (strtolower($sport) === 'badminton') {
    $sections = is_array($data['section']) ? $data['section'] : explode(',', (string)($data['section'] ?? ''));
    if (empty($sections) || (count($sections) === 1 && $sections[0] === '')) {
        echo json_encode(['success' => false, 'message' => 'Badminton reservations require section(s)']);
        exit;
    }
} else {
    $sections = [0]; // section_number = 0 for non-sectioned sports
}

// Remove duplicate time slots
$timeSlots = array_unique($timeSlots);

// Remove duplicate sections
$sections = array_unique($sections);


try {
    $pdo->beginTransaction();
    $reservation_ids = [];

    // Insert the reservation ONCE
    $stmt = $pdo->prepare("INSERT INTO reservations (user_id, full_name, contact_number, email, reservation_info, payment_method, sport, court, court_id, section_number, date, payment)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $user_id, $fullName, $contactNumber, $email,
        $reservationInfo, $paymentMethod, $sport, $court,
        $court_id, 0, $date, $payment // use section_number = 0 as a placeholder
    ]);
    $reservation_id = $pdo->lastInsertId();

    // Insert all (section, time) combinations into reservation_slots
    $slotStmt = $pdo->prepare("INSERT INTO reservation_slots (reservation_id, time, section_number) VALUES (?, ?, ?)");
    foreach ($sections as $sec) {
        foreach ($timeSlots as $slotTime) {
            $slotStmt->execute([$reservation_id, $slotTime, (int)$sec]);
        }
    }


    // Handle guest reservations
    if (!$user_id) {
        $guest_stmt = $pdo->prepare("INSERT INTO reservation_guests (reservation_id, guest_name, guest_contact, payment)
                                     VALUES (?, ?, ?, ?)");
        foreach ($reservation_ids as $rid) {
            $guest_stmt->execute([$rid, $fullName, $contactNumber, $payment]);
        }
    }

    $pdo->commit();

    // Format time slots
    $timesListHtml = '<ul>';
    foreach ($timeSlots as $slotTime) {
        $timesListHtml .= "<li>{$slotTime}</li>";
    }
    $timesListHtml .= '</ul>';

    // Email content (optional: enable PHPMailer block below)
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
            <li><strong>Fee:</strong> {$payment}</li>
        </ul>
        <p>If you have any questions, reply to this email.</p>
        <br/>
        <p>See you on the court!</p>
    </body>
    </html>
    ";

    // Uncomment to enable email sending
    /*
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'mail.courtmaster.online';
        $mail->SMTPAuth = true;
        $mail->Username = 'no-reply@courtmaster.online';
        $mail->Password = 'd=+P$tJwoLx2';
        $mail->SMTPSecure = 'ssl';
        $mail->Port = 465;

        $mail->setFrom('no-reply@courtmaster.online', 'CourtMaster');
        $mail->addAddress($to, $fullName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $message;

        $mail->send();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Mailer Error: ' . $mail->ErrorInfo]);
        exit;
    }
    */

    echo json_encode(['success' => true, 'to' => $to]);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
