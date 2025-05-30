<?php
//use PHPMailer\PHPMailer\PHPMailer;
//use PHPMailer\PHPMailer\Exception;
//require '../vendor/autoload.php'; // Adjust to your PHPMailer path

session_start();
require_once "../includes/db.php";

if (!isset($_SESSION['user_id']) || !isset($_SESSION['email'])) {
  http_response_code(403);
  exit;
}

$user_id = $_SESSION['user_id'];
$email = $_SESSION['email'];
$name = $_SESSION['user_name'];
$payment_method = $_POST['payment_method'] ?? '';

// 1. Update role

$stmt = $pdo->prepare("UPDATE users SET role = 'subscriber' WHERE id = ?");
$stmt->execute([$user_id]);

// 2. Prepare payment instructions
$instructions = match ($payment_method) {
  'cash' => "Pay on-site at the front desk before your next reservation.",
  'credit-card' => "Visit your profile > Billing to securely add your card.",
  'paypal' => "Send ₱75 to gcash@courtmaster.com or paymaya@courtmaster.com.",
  'bank-transfer' => "Transfer to BPI 1234-5678-90. Send receipt to billing@courtmaster.com.",
  default => "Please contact support for payment instructions.",
};
echo json_encode(["success" => true]);
// 3. Send email
/* $mail = new PHPMailer(true);
try {
  $mail->isSMTP();
  $mail->Host = 'smtp.example.com';
  $mail->SMTPAuth = true;
  $mail->Username = 'your@email.com';
  $mail->Password = 'yourpassword';
  $mail->SMTPSecure = 'tls';
  $mail->Port = 587;

  $mail->setFrom('no-reply@courtmaster.com', 'CourtMaster');
  $mail->addAddress($email, $name);
  $mail->Subject = "Subscription Confirmation";
  $mail->Body = "Hi $name,\n\nThank you for subscribing to CourtMaster!\n\nYour selected payment method: $payment_method\n\n$instructions\n\nHappy playing!\nCourtMaster Team";

  $mail->send();
  echo json_encode(["success" => true]);
} catch (Exception $e) {
  echo json_encode(["success" => false, "error" => $mail->ErrorInfo]);
} */
?>
