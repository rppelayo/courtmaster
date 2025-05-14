<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Complete Your Reservation – CourtMaster</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen text-gray-800">

  <div class="max-w-4xl mx-auto p-6 mt-8 bg-white rounded-xl shadow-lg">
    <h1 class="text-2xl font-bold text-blue-600 mb-6 text-center">Complete Your Reservation</h1>

    <!-- Reservation Info -->
    <div id="reservation-summary" class="mb-6">
      <h2 class="font-semibold text-xl mb-4">Reservation Information</h2>
      <div id="sport-info" class="mb-2"><strong>Sport:</strong> Basketball</div>
      <div id="court-info" class="mb-2"><strong>Court:</strong> Court A</div>
      <div id="date-info" class="mb-2"><strong>Date:</strong> 2025-04-20</div>
      <div id="time-info" class="mb-2"><strong>Time:</strong> 10:00 AM</div>
    </div>

    <!-- User Information Form -->
    <form id="reservation-form" class="space-y-4">
      <div>
        <label for="full-name" class="block text-sm font-medium text-gray-700">Full Name</label>
        <input type="text" id="full-name" class="mt-1 block w-full border px-3 py-2 rounded" required />
      </div>

      <div>
        <label for="contact-number" class="block text-sm font-medium text-gray-700">Contact Number</label>
        <input type="text" id="contact-number" class="mt-1 block w-full border px-3 py-2 rounded" required />
      </div>

      <div>
        <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
        <input type="email" id="email" class="mt-1 block w-full border px-3 py-2 rounded" required />
      </div>

      <div>
        <label for="reservation-notes" class="block text-sm font-medium text-gray-700">Additional Info (Optional)</label>
        <textarea id="reservation-notes" class="mt-1 block w-full border px-3 py-2 rounded"></textarea>
      </div>

      <div>
        <label for="fee" class="block text-sm font-medium text-gray-700">Fee</label>
        <input type="text" id="fee" class="mt-1 block w-full border px-3 py-2 rounded" readonly />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700">Payment Options</label>
        <div class="space-y-2 mx-4">
          <label class="mx-6">
            <input type="radio" name="payment-method" value="cash" class="mr-2" required />
            Cash On-site
          </label>
          <label class="mx-6">
            <input type="radio" name="payment-method" value="credit-card" class="mr-2" required />
            Credit Card
          </label>
          <label class="mx-6">
            <input type="radio" name="payment-method" value="paypal" class="mr-2" />
            GCash/Paymaya
          </label>
          <label class="mx-6">
            <input type="radio" name="payment-method" value="bank-transfer" class="mr-2" />
            Bank Transfer
          </label>
        </div>
      </div>

      <div class="flex justify-between">
        <button type="button" onclick="window.history.back()" class="bg-gray-300 text-gray-800 px-4 py-2 rounded hover:bg-gray-400">
          Back
        </button>
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
          Confirm Reservation
        </button>
      </div>
    </form>
  </div>

  <script>
    document.addEventListener("DOMContentLoaded", function () {
      // Get the reservation data passed from the previous page
      const sport = new URLSearchParams(window.location.search).get('sport');
      const court = new URLSearchParams(window.location.search).get('court');
      const date = new URLSearchParams(window.location.search).get('date');
      const time = new URLSearchParams(window.location.search).get('time');
      let email = new URLSearchParams(window.location.search).get('email');

      document.getElementById("sport-info").textContent = `Sport: ${sport}`;
      document.getElementById("court-info").textContent = `Court: ${court}`;
      document.getElementById("date-info").textContent = `Date: ${date}`;
      document.getElementById("time-info").textContent = `Time: ${time}`;
      document.getElementById("email").value = "<?php echo  $_SESSION['email'] ?>";

      email = "<?php echo  $_SESSION['email'] ?>";

      // Assuming the fee is constant for now, adjust as needed.
      document.getElementById("fee").value = "P250.00"; 

      document.getElementById("reservation-form").addEventListener("submit", async function(e) {
        e.preventDefault();

        const fullName = document.getElementById("full-name").value;
        const contactNumber = document.getElementById("contact-number").value;
        const reservationInfo = document.getElementById("reservation-notes").value;
        const paymentMethod = document.querySelector('input[name="payment-method"]:checked').value;

        const res = await fetch("../api/complete_reservation.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            fullName,
            contactNumber,
            email,
            reservationInfo,
            paymentMethod,
            sport,
            court,
            date,
            time
          })
        });

        const result = await res.json();
        if (result.success) {
          alert("Reservation confirmed!");
          parent.window.location.href = "dashboard.php";  // Redirect to the dashboard or another page.
        } else {
          alert("Error: " + result.message);
        }
      });
    });
  </script>
</body>
</html>
