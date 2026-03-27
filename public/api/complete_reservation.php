<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';
require_once '../includes/pricing.php';
require_once '../includes/reservation_rules.php';
require_once '../includes/game_status.php';
require_once '../includes/membership.php';
require_once '../includes/notifications.php';

const PAYMENT_PROOF_MAX_BYTES = 5242880;

function respond(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function requestData(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'multipart/form-data') !== false) {
        return $_POST;
    }

    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody) || trim($rawBody) === '') {
        return [];
    }

    $decoded = json_decode($rawBody, true);
    return is_array($decoded) ? $decoded : [];
}

function fieldMissing(array $data, string $key): bool
{
    if (!array_key_exists($key, $data)) {
        return true;
    }

    $value = $data[$key];
    if (is_array($value)) {
        return count($value) === 0;
    }

    return trim((string) $value) === '';
}

function normalizeList(mixed $value): array
{
    if (is_array($value)) {
        return array_values(
            array_filter(
                array_map(static fn(mixed $item): string => trim((string) $item), $value),
                static fn(string $item): bool => $item !== ''
            )
        );
    }

    if ($value === null) {
        return [];
    }

    return array_values(
        array_filter(
            array_map('trim', explode(',', (string) $value)),
            static fn(string $item): bool => $item !== ''
        )
    );
}

function uploadErrorMessage(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded proof of payment is too large.',
        UPLOAD_ERR_PARTIAL => 'The proof of payment upload was incomplete. Please try again.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload folder.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not save the proof of payment.',
        UPLOAD_ERR_EXTENSION => 'The proof of payment upload was stopped by the server.',
        default => 'The proof of payment could not be uploaded.',
    };
}

function savePaymentProof(?array $file): ?string
{
    if ($file === null || !isset($file['error'])) {
        return null;
    }

    $errorCode = (int) $file['error'];
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($errorCode !== UPLOAD_ERR_OK) {
        respond(['success' => false, 'message' => uploadErrorMessage($errorCode)], 422);
    }

    $fileSize = (int) ($file['size'] ?? 0);
    if ($fileSize > PAYMENT_PROOF_MAX_BYTES) {
        respond(['success' => false, 'message' => 'The proof of payment must be 5 MB or smaller.'], 422);
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        respond(['success' => false, 'message' => 'The uploaded proof of payment is invalid.'], 422);
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
    $allowedMimeTypes = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!is_string($mimeType) || !isset($allowedMimeTypes[$mimeType])) {
        respond(['success' => false, 'message' => 'Only JPG, PNG, WEBP, or PDF proof of payment files are allowed.'], 422);
    }

    $proofDirectory = __DIR__ . '/../uploads/payment-proofs';
    if (!is_dir($proofDirectory) && !mkdir($proofDirectory, 0775, true) && !is_dir($proofDirectory)) {
        respond(['success' => false, 'message' => 'The server could not prepare the proof of payment folder.'], 500);
    }

    try {
        $fileName = sprintf(
            'payment-proof-%s-%s.%s',
            date('Ymd-His'),
            bin2hex(random_bytes(8)),
            $allowedMimeTypes[$mimeType]
        );
    } catch (Throwable) {
        $fileName = sprintf(
            'payment-proof-%s-%s.%s',
            date('Ymd-His'),
            uniqid('', true),
            $allowedMimeTypes[$mimeType]
        );
    }

    $destinationPath = $proofDirectory . '/' . $fileName;
    if (!move_uploaded_file($tmpPath, $destinationPath)) {
        respond(['success' => false, 'message' => 'The proof of payment could not be saved.'], 500);
    }

    return 'uploads/payment-proofs/' . $fileName;
}

$data = requestData();

$required = ['fullName', 'contactNumber', 'email', 'paymentMethod', 'sport', 'court', 'court_id', 'date', 'time'];
foreach ($required as $key) {
    if (fieldMissing($data, $key)) {
        respond(['success' => false, 'message' => "Missing required field: {$key}"], 422);
    }
}

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$fullName = trim((string) $data['fullName']);
$contactNumber = trim((string) $data['contactNumber']);
$email = trim((string) $data['email']);
$reservationInfo = trim((string) ($data['reservationInfo'] ?? ''));
$paymentMethod = trim((string) $data['paymentMethod']);
$sport = trim((string) $data['sport']);
$court = trim((string) $data['court']);
$courtId = (int) $data['court_id'];
$date = trim((string) $data['date']);
$timeSlots = normalizeReservationTimeSlots($data['time']);

if ($timeSlots === []) {
    respond(['success' => false, 'message' => 'Time slots must be a non-empty array.'], 422);
}

if (!isValidReservationDate($date)) {
    respond(['success' => false, 'message' => 'Please provide a valid reservation date.'], 422);
}

if (!areReservationTimeSlotsContiguous($timeSlots)) {
    respond(['success' => false, 'message' => 'Please choose a continuous booking time range.'], 422);
}

$sections = [];
if (strtolower($sport) === 'badminton') {
    $sections = array_values(array_unique(normalizeList($data['section'] ?? [])));
    if ($sections === []) {
        respond(['success' => false, 'message' => 'Badminton reservations require section(s).'], 422);
    }
} else {
    $sections = [0];
}

$courtRecord = fetchReservationCourt($pdo, $courtId);
if ($courtRecord === null) {
    respond(['success' => false, 'message' => 'Selected court was not found.'], 404);
}

if ($courtRecord['name'] !== $court) {
    $court = $courtRecord['name'];
}

if (!doReservationTimeSlotsFitCourtHours($timeSlots, (string) $courtRecord['open_time'], (string) $courtRecord['close_time'])) {
    respond(['success' => false, 'message' => 'The selected booking time is outside the court schedule.'], 422);
}

if (hasPastReservationTimeSlots($date, $timeSlots)) {
    respond(['success' => false, 'message' => 'Past booking times are not allowed.'], 422);
}

if (reservationTimeSlotsOverlap($pdo, $courtId, $date, $timeSlots)) {
    respond(['success' => false, 'message' => 'One or more selected time slots are already booked.'], 409);
}

$bookingUser = null;
if (isset($_SESSION['user_id'])) {
    $bookingUser = membershipFetchUser($pdo, (int) $_SESSION['user_id']);
}
$requestedDiscountType = normalizePricingDiscountType((string) ($data['discountType'] ?? PRICING_DISCOUNT_NONE));
if ($requestedDiscountType === PRICING_DISCOUNT_SENIOR_PWD) {
    $requestedDiscountType = PRICING_DISCOUNT_NONE;
}

$pricing = computeReservationPricing(
    $courtRecord,
    reservationHoursPlayed($timeSlots),
    [
        'processing_fee' => pricingProcessingFeeForUser($bookingUser),
        'discount_type' => $requestedDiscountType,
        'allow_member_rate' => pricingUserIsMember($bookingUser),
        'auto_member_rate' => true,
    ]
);

$paymentProofPath = null;
if ($paymentMethod === 'gcash-maya') {
    $paymentProofPath = savePaymentProof($_FILES['paymentProof'] ?? null);
    if ($paymentProofPath === null) {
        respond(['success' => false, 'message' => 'Proof of payment is required for GCash / Maya reservations.'], 422);
    }
}

try {
    $pdo->beginTransaction();

    $reservationIds = [];
    $insertReservation = $pdo->prepare(
        'INSERT INTO reservations (
            user_id,
            full_name,
            contact_number,
            email,
            reservation_info,
            payment_method,
            sport,
            court,
            court_id,
            section_number,
            date,
            hourly_rate,
            subtotal,
            discount_type,
            discount_label,
            discount_amount,
            processing_fee,
            payment,
            payment_proof_path,
            booking_source,
            game_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $insertReservation->execute([
        $userId,
        $fullName,
        $contactNumber,
        $email,
        $reservationInfo,
        $paymentMethod,
        $sport,
        $court,
        $courtId,
        0,
        $date,
        $pricing['applied_rate'],
        $pricing['subtotal'],
        $pricing['discount_type'],
        $pricing['discount_label'] !== '' ? $pricing['discount_label'] : null,
        $pricing['discount_amount'],
        $pricing['processing_fee'],
        $pricing['total'],
        $paymentProofPath,
        'advance',
        GAME_STATUS_RESERVED,
    ]);

    $reservationId = (int) $pdo->lastInsertId();
    $reservationIds[] = $reservationId;

    $slotStatement = $pdo->prepare(
        'INSERT INTO reservation_slots (reservation_id, time, section_number) VALUES (?, ?, ?)'
    );

    foreach ($sections as $section) {
        foreach ($timeSlots as $slotTime) {
            $slotStatement->execute([$reservationId, $slotTime, (int) $section]);
        }
    }

    if (!$userId) {
        $guestStatement = $pdo->prepare(
            'INSERT INTO reservation_guests (reservation_id, guest_name, guest_contact, payment)
             VALUES (?, ?, ?, ?)'
        );

        foreach ($reservationIds as $guestReservationId) {
            $guestStatement->execute([$guestReservationId, $fullName, $contactNumber, $pricing['total']]);
        }
    }

    $pdo->commit();

    $scheduleSummary = notificationsBuildScheduleSummary($date, $timeSlots);
    $paymentLabel = notificationsPaymentMethodLabel($paymentMethod);

    if ($userId > 0) {
        $playerMessage = sprintf(
            'Your reservation for %s on %s has been saved. Payment method: %s.',
            $court,
            $scheduleSummary,
            $paymentLabel
        );
        if ($paymentMethod === 'gcash-maya' && $paymentProofPath !== null) {
            $playerMessage .= ' Your proof of payment is attached and waiting for staff review.';
        }

        notificationsCreateForUser($pdo, $userId, [
            'type' => 'reservation_confirmed',
            'title' => 'Reservation confirmed',
            'message' => $playerMessage,
            'link_url' => 'dashboard.php',
        ]);
    }

    $adminTitle = $paymentMethod === 'gcash-maya'
        ? 'Payment proof uploaded'
        : 'New online reservation';
    $adminMessage = sprintf(
        '%s booked %s on %s via %s.',
        $fullName,
        $court,
        $scheduleSummary,
        $paymentLabel
    );
    if ($paymentMethod === 'gcash-maya' && $paymentProofPath !== null) {
        $adminMessage .= ' Review the uploaded proof of payment.';
    }

    notificationsCreateForRole($pdo, 'admin', [
        'type' => $paymentMethod === 'gcash-maya' ? 'payment_review' : 'reservation_created',
        'title' => $adminTitle,
        'message' => $adminMessage,
        'link_url' => 'admin_reservations.php',
    ]);

    respond([
        'success' => true,
        'to' => $email,
        'paymentProofPath' => $paymentProofPath,
        'pricing' => $pricing,
    ]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($paymentProofPath !== null) {
        $savedProofPath = __DIR__ . '/../' . $paymentProofPath;
        if (is_file($savedProofPath)) {
            @unlink($savedProofPath);
        }
    }

    respond(['success' => false, 'message' => 'Database error: ' . $exception->getMessage()], 500);
}
?>
