<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/pricing.php';
require_once 'includes/membership.php';
$sessionEmail = $_SESSION['email'] ?? '';
$sessionRole = $_SESSION['role'] ?? '';
$sessionProcessFee = pricingProcessingFeeForRole($sessionRole);

if (isset($_SESSION['user_id'])) {
    $sessionUser = membershipFetchUser($pdo, (int) $_SESSION['user_id']);
    if (is_array($sessionUser)) {
        $sessionProcessFee = pricingProcessingFeeForUser($sessionUser);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Confirm Your Pickleball Reservation</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    :root {
      --page-bg: #f4f8f3;
      --surface: #ffffff;
      --surface-soft: #edf6f1;
      --border: #cfe0d8;
      --text: #173630;
      --muted: #607a72;
      --primary: #0f766e;
      --primary-strong: #0b5f58;
    }

    body {
      background:
        radial-gradient(circle at top left, rgba(15, 118, 110, 0.12), transparent 30%),
        linear-gradient(180deg, #fbfdf9 0%, var(--page-bg) 100%);
      color: var(--text);
    }

    .page-shell {
      background: rgba(255, 255, 255, 0.9);
      border: 1px solid rgba(207, 224, 216, 0.9);
      box-shadow: 0 30px 80px rgba(23, 54, 48, 0.12);
      backdrop-filter: blur(10px);
    }

    .brand-heading {
      color: var(--primary);
    }

    .step-card,
    .summary-card,
    .section-card,
    .modal-card {
      background: var(--surface);
      border: 1px solid rgba(207, 224, 216, 0.9);
      box-shadow: 0 15px 40px rgba(23, 54, 48, 0.08);
    }

    .step-card-complete {
      background: linear-gradient(180deg, #f7fdfa 0%, #f1faf5 100%);
      border-color: rgba(15, 118, 110, 0.24);
    }

    .step-card-active {
      background: linear-gradient(180deg, #fbfffd 0%, #eef8f4 100%);
      border-color: rgba(15, 118, 110, 0.5);
      box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12);
    }

    .step-badge {
      border: 1px solid rgba(15, 118, 110, 0.18);
      background: #eef7f4;
      color: var(--primary);
    }

    .step-badge-complete {
      background: var(--primary);
      color: #ffffff;
    }

    .field-label {
      color: var(--text);
    }

    .field-input {
      background: #fcfefd;
      border: 1px solid var(--border);
      color: var(--text);
    }

    .field-input::placeholder {
      color: #7d958e;
    }

    .summary-stat,
    .summary-tone,
    .breakdown-card {
      border: 1px solid rgba(207, 224, 216, 0.9);
      background: linear-gradient(180deg, #ffffff 0%, #f7fbf9 100%);
    }

    .summary-tone {
      border-color: rgba(15, 118, 110, 0.12);
      background: linear-gradient(180deg, #fbfffd 0%, #eef7f4 100%);
    }

    .primary-button {
      background: var(--primary);
      color: #ffffff;
    }

    .primary-button:hover {
      background: var(--primary-strong);
    }

    .secondary-button {
      background: #eef5f2;
      color: var(--text);
      border: 1px solid var(--border);
    }

    .secondary-button:hover {
      background: #e4f0eb;
    }

    .payment-option {
      border: 1px solid rgba(207, 224, 216, 0.9);
      background: #ffffff;
      transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    }

    .payment-option:hover {
      border-color: rgba(15, 118, 110, 0.42);
      background: #f9fdfb;
    }

    .payment-option-active {
      border-color: rgba(15, 118, 110, 0.58);
      background: linear-gradient(180deg, #fbfffd 0%, #eef8f4 100%);
      box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12);
    }

    .instruction-chip {
      border: 1px solid rgba(207, 224, 216, 0.9);
      background: #f8fbfa;
    }

    .upload-panel {
      border: 1px dashed rgba(15, 118, 110, 0.24);
      background: #f9fdfb;
    }

    .upload-panel-active {
      border-color: rgba(15, 118, 110, 0.5);
      background: linear-gradient(180deg, #fbfffd 0%, #eef8f4 100%);
    }

    .upload-status-error {
      color: #b91c1c;
    }
  </style>
</head>
<body class="min-h-screen px-4 pb-10 text-gray-800">

  <div class="page-shell mx-auto mt-8 max-w-5xl rounded-[28px] p-6 md:p-8">
    <header class="mb-8 text-center">
      <div class="inline-flex items-center rounded-full border border-teal-200 bg-teal-50 px-4 py-2 text-sm font-semibold text-teal-800">
        Step 3 of 3
      </div>
      <h1 class="brand-heading mt-5 text-3xl font-bold md:text-4xl">Review and confirm your booking</h1>
      <p class="mx-auto mt-3 max-w-2xl text-base text-slate-500">
        One last check before we reserve your selected pickleball court and time.
      </p>
    </header>

    <div class="mb-8 grid gap-3 md:grid-cols-3">
      <div class="step-card step-card-complete rounded-[22px] p-4">
        <div class="flex items-start gap-3">
          <div class="step-badge step-badge-complete flex h-10 w-10 items-center justify-center rounded-full text-sm font-bold">1</div>
          <div>
            <div class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Step 1</div>
            <div class="mt-1 text-lg font-semibold text-slate-800">Choose Court</div>
            <p class="mt-1 text-sm text-slate-500">Your selected court is saved.</p>
          </div>
        </div>
      </div>
      <div class="step-card step-card-complete rounded-[22px] p-4">
        <div class="flex items-start gap-3">
          <div class="step-badge step-badge-complete flex h-10 w-10 items-center justify-center rounded-full text-sm font-bold">2</div>
          <div>
            <div class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Step 2</div>
            <div class="mt-1 text-lg font-semibold text-slate-800">Pick Time</div>
            <p class="mt-1 text-sm text-slate-500">Your chosen schedule is locked in.</p>
          </div>
        </div>
      </div>
      <div class="step-card step-card-active rounded-[22px] p-4">
        <div class="flex items-start gap-3">
          <div class="step-badge flex h-10 w-10 items-center justify-center rounded-full text-sm font-bold">3</div>
          <div>
            <div class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Step 3</div>
            <div class="mt-1 text-lg font-semibold text-slate-800">Confirm</div>
            <p class="mt-1 text-sm text-slate-500">Complete your contact details and payment method.</p>
          </div>
        </div>
      </div>
    </div>

    <div id="reservation-summary" class="summary-card mb-8 rounded-[24px] p-5 md:p-6">
      <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
        <div class="max-w-2xl">
          <div class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">Reservation Summary</div>
          <h2 class="mt-2 text-2xl font-bold text-slate-800">Almost there</h2>
          <p class="mt-2 text-sm text-slate-500">
            Confirm your information below. Your court, date, and selected times are ready for checkout.
          </p>
        </div>

        <div class="grid grid-cols-2 gap-3 lg:min-w-[380px]">
          <div class="summary-stat rounded-[20px] px-4 py-4">
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Court</div>
            <div id="summary-court" class="mt-2 text-base font-semibold text-slate-800">Court A</div>
          </div>
          <div class="summary-stat rounded-[20px] px-4 py-4">
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Date</div>
            <div id="summary-date" class="mt-2 text-base font-semibold text-slate-800">Mar 26, 2026</div>
          </div>
          <div class="summary-stat rounded-[20px] px-4 py-4">
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Duration</div>
            <div id="summary-duration" class="mt-2 text-base font-semibold text-slate-800">0 hours</div>
          </div>
          <div class="summary-stat rounded-[20px] px-4 py-4">
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Total Due</div>
            <div id="summary-total" class="mt-2 text-base font-semibold text-slate-800">P0.00</div>
          </div>
        </div>
      </div>

      <div class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
        <div class="summary-tone rounded-[20px] px-4 py-4">
          <div class="text-xs font-semibold uppercase tracking-[0.18em] text-teal-700">Schedule</div>
          <div id="time-info" class="mt-2 text-lg font-semibold text-slate-800">10:00 AM - 11:00 AM</div>
          <div id="schedule-note" class="mt-1 text-sm text-slate-500">Selected time slots will appear here.</div>
        </div>
        <div class="breakdown-card rounded-[20px] px-4 py-4">
          <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Payment Breakdown</div>
          <div class="mt-3 space-y-2 text-sm text-slate-600">
            <div class="flex items-center justify-between">
              <span>Reservation subtotal</span>
              <span id="fee-subtotal" class="font-semibold text-slate-800">P0.00</span>
            </div>
            <div class="flex items-center justify-between">
              <span>Processing fee</span>
              <span id="fee-processing" class="font-semibold text-slate-800">P0.00</span>
            </div>
            <div class="flex items-center justify-between border-t border-slate-200 pt-2 text-base font-semibold text-slate-900">
              <span>Total due</span>
              <span id="fee-total-display">P0.00</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <form id="reservation-form" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]" enctype="multipart/form-data">
      <div class="space-y-6">
        <div class="section-card rounded-[24px] p-5 md:p-6">
          <h2 class="text-xl font-semibold text-slate-800">Your Details</h2>
          <p class="mt-2 text-sm text-slate-500">Enter the information that should appear on this booking.</p>

          <div class="mt-5 grid gap-4 md:grid-cols-2">
            <div>
              <label for="full-name" class="field-label block text-sm font-medium">Full Name</label>
              <input type="text" id="full-name" class="field-input mt-2 block w-full rounded-xl px-3 py-3" required />
              <p class="mt-2 text-xs text-slate-500">Use the name the front desk should look for.</p>
            </div>

            <div>
              <label for="contact-number" class="field-label block text-sm font-medium">Contact Number</label>
              <input type="text" id="contact-number" class="field-input mt-2 block w-full rounded-xl px-3 py-3" required />
              <p class="mt-2 text-xs text-slate-500">We only use this if there is a schedule or arrival issue.</p>
            </div>

            <div class="md:col-span-2">
              <label for="email" class="field-label block text-sm font-medium">Email Address</label>
              <input type="email" id="email" class="field-input mt-2 block w-full rounded-xl px-3 py-3" required />
              <p class="mt-2 text-xs text-slate-500">Use an address you can access for future booking updates.</p>
            </div>

            <div class="md:col-span-2">
              <label for="reservation-notes" class="field-label block text-sm font-medium">Additional Info (Optional)</label>
              <textarea id="reservation-notes" rows="4" class="field-input mt-2 block w-full rounded-xl px-3 py-3"></textarea>
              <p class="mt-2 text-xs text-slate-500">Add arrival notes, player info, or anything staff should know.</p>
            </div>
          </div>
        </div>
      </div>

      <aside class="space-y-6">
        <div class="section-card rounded-[24px] p-5">
          <h2 class="text-xl font-semibold text-slate-800">Payment Method</h2>
          <p class="mt-2 text-sm text-slate-500">Choose how you would like to settle this reservation.</p>

          <div class="mt-4 space-y-3">
            <label data-payment-option="cash" class="payment-option flex cursor-pointer items-start justify-between gap-3 rounded-[20px] p-4">
              <div>
                <div class="font-semibold text-slate-800">Cash On-site</div>
                <p class="mt-1 text-sm text-slate-500">Pay at the venue before or when you arrive.</p>
              </div>
              <input type="radio" name="payment-method" value="cash" class="mt-1 h-4 w-4" required />
            </label>

            <label data-payment-option="gcash-maya" class="payment-option flex cursor-pointer items-start justify-between gap-3 rounded-[20px] p-4">
              <div>
                <div class="font-semibold text-slate-800">GCash / Maya</div>
                <p class="mt-1 text-sm text-slate-500">Scan the available QR code and then return here to finish.</p>
              </div>
              <input type="radio" name="payment-method" value="gcash-maya" class="mt-1 h-4 w-4" />
            </label>
          </div>

          <p class="mt-4 text-xs text-slate-500">If you choose GCash or Maya, we will show the matching QR code in a pop-up before you finish.</p>

          <div id="payment-proof-panel" class="upload-panel mt-4 hidden rounded-[20px] px-4 py-4">
            <label for="payment-proof" class="field-label block text-sm font-medium">Proof of Payment</label>
            <input
              type="file"
              id="payment-proof"
              accept=".jpg,.jpeg,.png,.webp,.pdf"
              class="field-input mt-2 block w-full rounded-xl px-3 py-3"
            />
            <p class="mt-2 text-xs text-slate-500">Upload a JPG, PNG, WEBP, or PDF file up to 5 MB.</p>
            <p id="payment-proof-feedback" class="mt-2 text-xs text-slate-500">No file selected yet.</p>
          </div>
        </div>

        <div class="section-card rounded-[24px] p-5">
          <div class="text-xs font-semibold uppercase tracking-[0.18em] text-teal-700">Final Step</div>
          <h2 class="mt-2 text-xl font-semibold text-slate-800">Submit your reservation</h2>
          <p class="mt-2 text-sm text-slate-500">Please review your details carefully before you confirm.</p>

          <div class="mt-5 flex flex-col gap-3">
            <button type="button" onclick="window.history.back()" class="secondary-button rounded-xl px-4 py-3 font-semibold">
              Back
            </button>
            <button type="submit" class="primary-button rounded-xl px-4 py-3 font-semibold">
              Confirm Reservation
            </button>
          </div>
        </div>
      </aside>
    </form>
  </div>

  <div id="payment-qr-modal" class="fixed inset-0 z-50 hidden items-start justify-center overflow-y-auto bg-black bg-opacity-75 px-4 py-4 sm:items-center">
    <div class="modal-card relative my-2 max-h-[calc(100vh-2rem)] w-full max-w-4xl overflow-y-auto rounded-[28px] p-5 md:p-8">
      <button type="button" id="close-payment-qr" class="sticky top-0 z-10 ml-auto flex h-10 w-10 items-center justify-center rounded-full bg-white text-2xl text-gray-500 shadow-sm hover:text-gray-800">
        &times;
      </button>

      <div class="text-center">
        <div class="inline-flex items-center rounded-full border border-teal-200 bg-teal-50 px-4 py-2 text-sm font-semibold text-teal-800">
          Digital Payment
        </div>
        <h2 class="brand-heading mt-4 text-2xl font-bold md:text-3xl">Scan to Pay</h2>
        <p class="mx-auto mt-3 max-w-2xl text-sm text-slate-500">
          Use your preferred wallet, complete the payment, then return here and finish the reservation.
        </p>
      </div>

      <div class="mt-6 grid gap-3 md:grid-cols-3">
        <div class="instruction-chip rounded-2xl px-4 py-4 text-sm text-slate-700">
          <div class="font-semibold text-slate-800">1. Scan</div>
          <p class="mt-1">Use either GCash or Maya to open the QR code below.</p>
        </div>
        <div class="instruction-chip rounded-2xl px-4 py-4 text-sm text-slate-700">
          <div class="font-semibold text-slate-800">2. Pay</div>
          <p class="mt-1">Send the amount shown in your reservation summary.</p>
        </div>
        <div class="instruction-chip rounded-2xl px-4 py-4 text-sm text-slate-700">
          <div class="font-semibold text-slate-800">3. Finish</div>
          <p class="mt-1">Return to this page, tap Done, and confirm the booking.</p>
        </div>
      </div>

      <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2 md:gap-6">
        <div class="rounded-[24px] border border-[color:var(--border)] p-4 text-center">
          <h3 class="text-lg font-semibold text-gray-800">GCash</h3>
          <p class="mt-1 text-sm text-slate-500">Scan with the GCash app.</p>
          <img src="images/resources/payment_qr.jpeg" alt="GCash QR Code" class="mx-auto mt-4 w-full max-w-xs rounded-2xl border border-slate-200" />
        </div>
        <div class="rounded-[24px] border border-[color:var(--border)] p-4 text-center">
          <h3 class="text-lg font-semibold text-gray-800">Maya</h3>
          <p class="mt-1 text-sm text-slate-500">Scan with the Maya app.</p>
          <img src="images/resources/payment_qr.jpeg" alt="Maya QR Code" class="mx-auto mt-4 w-full max-w-xs rounded-2xl border border-slate-200" />
        </div>
      </div>

      <div class="mt-6 text-center">
        <button type="button" id="done-payment-qr" class="primary-button rounded-xl px-6 py-3 font-semibold">
          Done
        </button>
      </div>
    </div>
  </div>

  <div id="splash-screen" class="fixed inset-0 hidden items-center justify-center bg-black bg-opacity-75 px-4 z-50">
    <div class="modal-card w-full max-w-md rounded-[24px] p-6 text-center">
      <h2 class="brand-heading text-2xl font-bold">Reservation Confirmed!</h2>
      <div class="mt-4 space-y-2 text-gray-800">
        <div><strong>Court:</strong> <span id="splash-court"></span></div>
        <div><strong>Date:</strong> <span id="splash-date"></span></div>
        <div><strong>Time:</strong> <span id="splash-time"></span></div>
        <div><strong>Name:</strong> <span id="splash-name"></span></div>
        <div><strong>Contact:</strong> <span id="splash-contact"></span></div>
        <div><strong>Payment:</strong> <span id="splash-payment"></span></div>
      </div>
      <button id="close-splash" class="primary-button mt-6 rounded-xl px-4 py-2">
        Close
      </button>
    </div>
  </div>

  <script>
    document.addEventListener("DOMContentLoaded", function () {
      const params = new URLSearchParams(window.location.search);
      const sport = params.get("sport");
      const court = params.get("court");
      const courtId = params.get("court_id");
      let section = params.get("section");
      const date = params.get("date");
      const time = params.get("time");
      const fee = params.get("fee");
      const email = params.get("email");
      const sessionEmail = <?php echo json_encode($sessionEmail); ?>;
      const sessionRole = <?php echo json_encode($sessionRole); ?>;
      const numberFormatter = new Intl.NumberFormat("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });

      const emailInput = document.getElementById("email");
      const paymentQrModal = document.getElementById("payment-qr-modal");
      const paymentMethodInputs = document.querySelectorAll('input[name="payment-method"]');
      const paymentProofPanel = document.getElementById("payment-proof-panel");
      const paymentProofInput = document.getElementById("payment-proof");
      const paymentProofFeedback = document.getElementById("payment-proof-feedback");
      const maxPaymentProofSize = 5 * 1024 * 1024;
      const allowedPaymentProofTypes = [
        "application/pdf",
        "image/jpeg",
        "image/png",
        "image/webp"
      ];
      const allowedPaymentProofNamePattern = /\.(jpg|jpeg|png|webp|pdf)$/i;

      if (sessionEmail) {
        emailInput.value = sessionEmail;
      } else if (email) {
        emailInput.value = email;
      }

      const timeArray = typeof time === "string" && time.length > 0 ? time.split(",") : [];
      const feeValue = parseInt(fee, 10) || 0;
      const processFee = Number(<?= json_encode($sessionProcessFee) ?>);
      const payment = feeValue + processFee;
      const formattedDate = date ? formatDisplayDate(date) : "Not selected";
      const timeLabel = getTimeLabel(timeArray);
      const durationLabel = `${timeArray.length} hour${timeArray.length === 1 ? "" : "s"}`;

      paymentProofInput.addEventListener("change", function () {
        validatePaymentProofSelection(true);
      });

      document.getElementById("summary-court").textContent = court || "Not selected";
      document.getElementById("summary-date").textContent = formattedDate;
      document.getElementById("summary-duration").textContent = durationLabel;
      document.getElementById("summary-total").textContent = formatCurrency(payment);
      document.getElementById("time-info").textContent = timeLabel;
      document.getElementById("schedule-note").textContent = court
        ? `${court} is scheduled for ${formattedDate}.`
        : "Selected time slots will appear here.";
      document.getElementById("fee-subtotal").textContent = formatCurrency(feeValue);
      document.getElementById("fee-processing").textContent = formatCurrency(processFee);
      document.getElementById("fee-total-display").textContent = formatCurrency(payment);

      paymentMethodInputs.forEach((input) => {
        input.addEventListener("change", function () {
          syncPaymentOptionState();

          if (this.value === "gcash-maya" && this.checked) {
            openPaymentQrModal();
          }
        });
      });

      syncPaymentOptionState();

      document.getElementById("close-payment-qr").addEventListener("click", closePaymentQrModal);
      document.getElementById("done-payment-qr").addEventListener("click", closePaymentQrModal);

      paymentQrModal.addEventListener("click", function (event) {
        if (event.target === paymentQrModal) {
          closePaymentQrModal();
        }
      });

      document.getElementById("reservation-form").addEventListener("submit", async function (event) {
        event.preventDefault();

        const paymentMethodInput = document.querySelector('input[name="payment-method"]:checked');
        if (!paymentMethodInput) {
          alert("Please choose a payment method.");
          return;
        }

        const fullName = document.getElementById("full-name").value;
        const contactNumber = document.getElementById("contact-number").value;
        const reservationInfo = document.getElementById("reservation-notes").value;
        const paymentMethod = paymentMethodInput.value;
        const reservationEmail = document.getElementById("email").value;
        const requiresPaymentProof = paymentMethod === "gcash-maya";

        if (requiresPaymentProof) {
          if (!paymentProofInput.files || paymentProofInput.files.length === 0) {
            setPaymentProofFeedback("Please upload proof of payment for GCash / Maya.", true);
            alert("Please upload proof of payment for GCash / Maya.");
            return;
          }

          if (!validatePaymentProofSelection(true)) {
            return;
          }
        }

        if (!section) {
          section = 0;
        }

        const formData = new FormData();
        formData.append("fullName", fullName);
        formData.append("contactNumber", contactNumber);
        formData.append("email", reservationEmail);
        formData.append("reservationInfo", reservationInfo);
        formData.append("paymentMethod", paymentMethod);
        formData.append("sport", sport);
        formData.append("court", court);
        formData.append("court_id", courtId);
        formData.append("section", section);
        formData.append("date", date);
        formData.append("time", time);
        formData.append("payment", payment);

        if (paymentProofInput.files && paymentProofInput.files[0]) {
          formData.append("paymentProof", paymentProofInput.files[0]);
        }

        const response = await fetch("api/complete_reservation.php", {
          method: "POST",
          body: formData
        });

        const result = await response.json();
        if (!result.success) {
          alert("Error: " + result.message);
          return;
        }

        document.getElementById("splash-court").textContent = court;
        document.getElementById("splash-date").textContent = formattedDate;
        document.getElementById("splash-time").textContent = timeLabel;
        document.getElementById("splash-name").textContent = fullName;
        document.getElementById("splash-contact").textContent = contactNumber;
        document.getElementById("splash-payment").textContent = getPaymentLabel(paymentMethod);
        document.getElementById("splash-screen").classList.remove("hidden");
        document.getElementById("splash-screen").classList.add("flex");

        setTimeout(() => {
          window.location.href = "reserve.html";
        }, 3000);

        document.getElementById("close-splash").addEventListener("click", () => {
          window.location.href = "reserve.html";
        });
      });

      function syncPaymentOptionState() {
        document.querySelectorAll("[data-payment-option]").forEach((card) => {
          const input = card.querySelector('input[name="payment-method"]');
          if (input && input.checked) {
            card.classList.add("payment-option-active");
          } else {
            card.classList.remove("payment-option-active");
          }
        });

        const selectedMethod = document.querySelector('input[name="payment-method"]:checked')?.value || "";
        togglePaymentProofPanel(selectedMethod === "gcash-maya");
      }

      function openPaymentQrModal() {
        paymentQrModal.classList.remove("hidden");
        paymentQrModal.classList.add("flex");
      }

      function closePaymentQrModal() {
        paymentQrModal.classList.add("hidden");
        paymentQrModal.classList.remove("flex");
      }

      function getPaymentLabel(paymentMethod) {
        if (paymentMethod === "gcash-maya") {
          return "GCash / Maya";
        }

        return "Cash On-site";
      }

      function getTimeLabel(timeSlots) {
        if (!Array.isArray(timeSlots) || timeSlots.length === 0) {
          return "Time not available";
        }

        const sortedSlots = [...timeSlots].sort();
        if (areSlotsSequential(sortedSlots)) {
          return `${formatTo12Hour(sortedSlots[0])} - ${formatTo12Hour(addOneHour(sortedSlots[sortedSlots.length - 1]))}`;
        }

        return sortedSlots.map((slot) => formatTo12Hour(slot)).join(", ");
      }

      function areSlotsSequential(sortedSlots) {
        for (let index = 1; index < sortedSlots.length; index++) {
          if (sortedSlots[index] !== addOneHour(sortedSlots[index - 1])) {
            return false;
          }
        }

        return true;
      }

      function formatTo12Hour(timeStr) {
        const [hour, minute] = timeStr.split(":").map(Number);
        const ampm = hour >= 12 ? "PM" : "AM";
        const formattedHour = (hour % 12 || 12).toString();
        return `${formattedHour}:${minute.toString().padStart(2, "0")} ${ampm}`;
      }

      function addOneHour(timeStr) {
        const [hour, minute] = timeStr.split(":").map(Number);
        let nextHour = hour + 1;

        if (nextHour >= 24) {
          nextHour -= 24;
        }

        return `${nextHour.toString().padStart(2, "0")}:${minute.toString().padStart(2, "0")}:00`;
      }

      function formatDisplayDate(dateStr) {
        const formattedDateValue = new Date(`${dateStr}T00:00:00`);
        return formattedDateValue.toLocaleDateString(undefined, {
          weekday: "short",
          month: "short",
          day: "numeric",
          year: "numeric"
        });
      }

      function formatCurrency(value) {
        return `P${numberFormatter.format(Number(value) || 0)}`;
      }

      function togglePaymentProofPanel(isVisible) {
        paymentProofPanel.classList.toggle("hidden", !isVisible);
        paymentProofPanel.classList.toggle("upload-panel-active", isVisible);
        paymentProofInput.required = isVisible;

        if (!isVisible) {
          paymentProofInput.value = "";
          setPaymentProofFeedback("No file selected yet.", false);
          return;
        }

        if (paymentProofInput.files && paymentProofInput.files.length > 0) {
          validatePaymentProofSelection(false);
          return;
        }

        setPaymentProofFeedback("No file selected yet.", false);
      }

      function validatePaymentProofSelection(showAlert) {
        const selectedFile = paymentProofInput.files && paymentProofInput.files[0];

        if (!selectedFile) {
          setPaymentProofFeedback("No file selected yet.", false);
          return false;
        }

        const hasAllowedMimeType = allowedPaymentProofTypes.includes(selectedFile.type);
        const hasAllowedFileName = allowedPaymentProofNamePattern.test(selectedFile.name || "");

        if (!hasAllowedMimeType && !hasAllowedFileName) {
          paymentProofInput.value = "";
          setPaymentProofFeedback("Only JPG, PNG, WEBP, or PDF files are allowed.", true);
          if (showAlert) {
            alert("Only JPG, PNG, WEBP, or PDF files are allowed.");
          }
          return false;
        }

        if (selectedFile.size > maxPaymentProofSize) {
          paymentProofInput.value = "";
          setPaymentProofFeedback("The file is too large. The maximum size is 5 MB.", true);
          if (showAlert) {
            alert("The proof of payment file is too large. Please keep it under 5 MB.");
          }
          return false;
        }

        const formattedSize = formatFileSize(selectedFile.size);
        setPaymentProofFeedback(`Selected file: ${selectedFile.name} (${formattedSize})`, false);
        return true;
      }

      function setPaymentProofFeedback(message, isError) {
        paymentProofFeedback.textContent = message;
        paymentProofFeedback.classList.toggle("upload-status-error", Boolean(isError));
        paymentProofFeedback.classList.toggle("text-slate-500", !isError);
      }

      function formatFileSize(bytes) {
        if (bytes < 1024 * 1024) {
          return `${(bytes / 1024).toFixed(1)} KB`;
        }

        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
      }
    });
  </script>
</body>
</html>
