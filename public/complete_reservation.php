<?php
session_start();
$sessionEmail = $_SESSION['email'] ?? '';
$sessionRole = $_SESSION['role'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Complete Your Reservation - CourtMaster</title>
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

    .modal-card {
      background: var(--surface);
      box-shadow: 0 24px 60px rgba(23, 54, 48, 0.16);
    }
  </style>
</head>
<body class="min-h-screen text-gray-800">

  <div class="page-shell max-w-4xl mx-auto p-6 mt-8 rounded-[28px]">
    <h1 class="brand-heading text-2xl font-bold mb-6 text-center">Complete Your Reservation</h1>

    <div id="reservation-summary" class="mb-6 text-slate-800">
      <h2 class="font-semibold text-xl mb-4">Reservation Information</h2>
      <div id="court-info" class="mb-2"><strong>Court:</strong> Court A</div>
      <div id="date-info" class="mb-2"><strong>Date:</strong> 2025-04-20</div>
      <div id="time-info" class="mb-2"><strong>Time:</strong> 10:00 AM</div>
    </div>

    <form id="reservation-form" class="space-y-4">
      <div>
        <label for="full-name" class="field-label block text-sm font-medium">Full Name</label>
        <input type="text" id="full-name" class="field-input mt-1 block w-full px-3 py-2 rounded-xl" required />
      </div>

      <div>
        <label for="contact-number" class="field-label block text-sm font-medium">Contact Number</label>
        <input type="text" id="contact-number" class="field-input mt-1 block w-full px-3 py-2 rounded-xl" required />
      </div>

      <div>
        <label for="email" class="field-label block text-sm font-medium">Email Address</label>
        <input type="email" id="email" class="field-input mt-1 block w-full px-3 py-2 rounded-xl" required />
      </div>

      <div>
        <label for="reservation-notes" class="field-label block text-sm font-medium">Additional Info (Optional)</label>
        <textarea id="reservation-notes" class="field-input mt-1 block w-full px-3 py-2 rounded-xl"></textarea>
      </div>

      <div>
        <label for="fee" class="field-label block text-sm font-medium">Fee (Includes Processing Fee)</label>
        <input type="text" id="fee" class="field-input mt-1 block w-full px-3 py-2 rounded-xl" readonly />
      </div>

      <div>
        <label class="field-label block text-sm font-medium">Payment Options</label>
        <div class="space-y-2 mx-4 mt-2">
          <label class="flex items-center gap-2 text-slate-700">
            <input type="radio" name="payment-method" value="cash" class="mr-2" required />
            Cash On-site
          </label>
          <label class="flex items-center gap-2 text-slate-700">
            <input type="radio" name="payment-method" value="gcash-maya" class="mr-2" />
            GCash / Maya
          </label>
        </div>
      </div>

      <div class="flex justify-center gap-4">
        <button type="button" onclick="window.history.back()" class="secondary-button px-4 py-2 rounded-xl">
          Back
        </button>
        <button type="submit" class="primary-button px-4 py-2 rounded-xl">
          Confirm Reservation
        </button>
      </div>
    </form>
  </div>

  <div id="payment-qr-modal" class="fixed inset-0 hidden bg-black bg-opacity-75 items-center justify-center z-50 px-4">
    <div class="modal-card w-full max-w-3xl rounded-[24px] p-6 relative">
      <button type="button" id="close-payment-qr" class="absolute top-3 right-4 text-2xl text-gray-500 hover:text-gray-800">
        &times;
      </button>
      <h2 class="brand-heading text-2xl font-bold mb-2 text-center">Scan to Pay</h2>
      <p class="text-center text-gray-600 mb-6">Use GCash or Maya to scan the QR code below.</p>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="border rounded-lg p-4 text-center">
          <h3 class="text-lg font-semibold text-gray-800 mb-3">GCash</h3>
          <img src="images/resources/payment_qr.jpeg" alt="GCash QR Code" class="mx-auto w-full max-w-xs rounded-lg border" />
        </div>
        <div class="border rounded-lg p-4 text-center">
          <h3 class="text-lg font-semibold text-gray-800 mb-3">Maya</h3>
          <img src="images/resources/payment_qr.jpeg" alt="Maya QR Code" class="mx-auto w-full max-w-xs rounded-lg border" />
        </div>
      </div>

      <div class="mt-6 text-center">
        <button type="button" id="done-payment-qr" class="primary-button px-5 py-2 rounded-xl">
          Done
        </button>
      </div>
    </div>
  </div>

  <div id="splash-screen" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 hidden">
    <div class="modal-card p-6 rounded-[24px] text-center max-w-md w-full">
      <h2 class="brand-heading text-2xl font-bold mb-4">Reservation Confirmed!</h2>
      <div class="text-gray-800 mb-4 space-y-1">
        <div><strong>Court:</strong> <span id="splash-court"></span></div>
        <div><strong>Date:</strong> <span id="splash-date"></span></div>
        <div><strong>Time:</strong> <span id="splash-time"></span></div>
        <div><strong>Name:</strong> <span id="splash-name"></span></div>
        <div><strong>Contact:</strong> <span id="splash-contact"></span></div>
        <div><strong>Payment:</strong> <span id="splash-payment"></span></div>
      </div>
      <button id="close-splash" class="primary-button mt-4 px-4 py-2 rounded-xl">
        Close
      </button>
    </div>
  </div>

  <script>
    document.addEventListener("DOMContentLoaded", function () {
      const params = new URLSearchParams(window.location.search);
      const sport = params.get("sport");
      const court = params.get("court");
      const court_id = params.get("court_id");
      let section = params.get("section");
      const date = params.get("date");
      const time = params.get("time");
      const fee = params.get("fee");
      const email = params.get("email");
      const sessionEmail = <?php echo json_encode($sessionEmail); ?>;
      const emailInput = document.getElementById("email");
      const paymentQrModal = document.getElementById("payment-qr-modal");
      const paymentMethodInputs = document.querySelectorAll('input[name="payment-method"]');

      document.getElementById("court-info").textContent = `Court: ${court}`;
      document.getElementById("date-info").textContent = `Date: ${date}`;

      if (sessionEmail) {
        emailInput.value = sessionEmail;
      } else if (email) {
        emailInput.value = email;
      }

      const timeArray = typeof time === "string" ? time.split(",") : [];
      if (timeArray.length > 0) {
        const startTime = formatTo12Hour(timeArray[0]);
        const endTime = formatTo12Hour(addOneHour(timeArray[timeArray.length - 1]));
        document.getElementById("time-info").textContent = `Time: ${startTime} - ${endTime}`;
      } else {
        document.getElementById("time-info").textContent = "Time: N/A";
      }

      const feeValue = parseInt(fee, 10) || 0;
      const processFee = <?php echo json_encode($sessionRole === 'subscriber' ? 7 : 15); ?>;
      const payment = feeValue + processFee;
      document.getElementById("fee").value = "P" + payment;

      paymentMethodInputs.forEach((input) => {
        input.addEventListener("change", function () {
          if (this.value === "gcash-maya" && this.checked) {
            openPaymentQrModal();
          }
        });
      });

      document.getElementById("close-payment-qr").addEventListener("click", closePaymentQrModal);
      document.getElementById("done-payment-qr").addEventListener("click", closePaymentQrModal);

      paymentQrModal.addEventListener("click", function (event) {
        if (event.target === paymentQrModal) {
          closePaymentQrModal();
        }
      });

      document.getElementById("reservation-form").addEventListener("submit", async function (e) {
        e.preventDefault();

        const fullName = document.getElementById("full-name").value;
        const contactNumber = document.getElementById("contact-number").value;
        const reservationInfo = document.getElementById("reservation-notes").value;
        const paymentMethod = document.querySelector('input[name="payment-method"]:checked').value;
        const reservationEmail = document.getElementById("email").value;

        if (!section) {
          section = 0;
        }

        const res = await fetch("api/complete_reservation.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            fullName,
            contactNumber,
            email: reservationEmail,
            reservationInfo,
            paymentMethod,
            sport,
            court,
            court_id,
            section,
            date,
            time,
            payment
          })
        });

        const result = await res.json();
        if (!result.success) {
          alert("Error: " + result.message);
          return;
        }

        document.getElementById("splash-court").textContent = court;
        document.getElementById("splash-date").textContent = date;

        if (timeArray.length > 0) {
          const startTime = formatTo12Hour(timeArray[0]);
          const endTime = formatTo12Hour(addOneHour(timeArray[timeArray.length - 1]));
          document.getElementById("splash-time").textContent = `${startTime} - ${endTime}`;
        } else {
          document.getElementById("splash-time").textContent = "N/A";
        }

        document.getElementById("splash-name").textContent = fullName;
        document.getElementById("splash-contact").textContent = contactNumber;
        document.getElementById("splash-payment").textContent = getPaymentLabel(paymentMethod);
        document.getElementById("splash-screen").classList.remove("hidden");

        setTimeout(() => {
          window.location.href = "reserve.html";
        }, 3000);

        document.getElementById("close-splash").addEventListener("click", () => {
          window.location.href = "reserve.html";
        });
      });

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
    });
  </script>
</body>
</html>
