<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php'; // Adjust path if needed

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $userName = htmlspecialchars($_POST['name']);
    $userEmail = htmlspecialchars($_POST['email']);
    $userMessage = nl2br(htmlspecialchars($_POST['message']));

    $mail = new PHPMailer(true);

    try {
        // SMTP server config
        $mail->isSMTP();
        $mail->Host = 'mail.courtmaster.online';
        $mail->SMTPAuth = true;
        $mail->Username = 'admin@courtmaster.online';
        $mail->Password = 'y)H^!Hm3m#wz'; // Replace with actual password
        $mail->SMTPSecure = 'ssl';
        $mail->Port = 465;

        // Email details
        $mail->setFrom('no-reply@courtmaster.online', 'CourtMaster Contact Form');
        $mail->addAddress('admin@courtmaster.online', 'CourtMaster Admin');

        $mail->isHTML(true);
        $mail->Subject = "New Contact Form Submission from $userName";
        $mail->Body = "
            <h2>Contact Form Message</h2>
            <p><strong>Name:</strong> {$userName}</p>
            <p><strong>Email:</strong> {$userEmail}</p>
            <p><strong>Message:</strong><br>{$userMessage}</p>
        ";

        $mail->send();

        echo json_encode(['success' => true, 'message' => 'Message sent successfully.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Mailer Error: ' . $mail->ErrorInfo]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
}
