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
<body class="bg-gray-900 min-h-screen text-gray-800">

  <div class="max-w-4xl mx-auto p-6 mt-8 bg-gray-800 rounded-xl shadow-lg">
    <h1 class="text-2xl font-bold text-orange-600 mb-6 text-center">Complete Your Reservation</h1>

    <!-- Reservation Info -->
    <div id="reservation-summary" class="mb-6 text-white">
      <h2 class="font-semibold text-xl mb-4">Reservation Information</h2>
      <div id="sport-info" class="mb-2"><strong>Sport:</strong> Basketball</div>
      <div id="court-info" class="mb-2"><strong>Court:</strong> Court A</div>
      <div id="section-info" class="mb-2"><strong>Section:</strong> Section 1</div>
      <div id="date-info" class="mb-2"><strong>Date:</strong> 2025-04-20</div>
      <div id="time-info" class="mb-2"><strong>Time:</strong> 10:00 AM</div>
    </div>

    <!-- User Information Form -->
    <form id="reservation-form" class="space-y-4">
      <div>
        <label for="full-name" class="block text-sm font-medium text-white ">Full Name</label>
        <input type="text" id="full-name" class="mt-1 block w-full border px-3 py-2 rounded" required />
      </div>

      <div>
        <label for="contact-number" class="block text-sm font-medium text-white ">Contact Number</label>
        <input type="text" id="contact-number" class="mt-1 block w-full border px-3 py-2 rounded" required />
      </div>

      <div>
        <label for="email" class="block text-sm font-medium text-white ">Email Address</label>
        <input type="email" id="email" class="mt-1 block w-full border px-3 py-2 rounded" required />
      </div>

      <div>
        <label for="reservation-notes" class="block text-sm font-medium text-white ">Additional Info (Optional)</label>
        <textarea id="reservation-notes" class="mt-1 block w-full border px-3 py-2 rounded"></textarea>
      </div>

      <div>
        <label for="fee" class="block text-sm font-medium text-white ">Fee</label>
        <input type="text" id="fee" class="mt-1 block w-full border px-3 py-2 rounded" readonly />
      </div>

      <div>
        <label class="block text-sm font-medium text-white ">Payment Options</label>
        <div class="space-y-2 mx-4">
          <label class="mx-6 text-white ">
            <input type="radio" name="payment-method" value="cash" class="mr-2" required />
            Cash On-site
          </label>
          <label class="mx-6 text-white ">
            <input type="radio" name="payment-method" value="credit-card" class="mr-2" />
            Credit Card
          </label>
          <label class="mx-6 text-white ">
            <input type="radio" name="payment-method" value="paypal" class="mr-2" />
            GCash/Paymaya
          </label>
          <label class="mx-6 text-white ">
            <input type="radio" name="payment-method" value="bank-transfer" class="mr-2" />
            Bank Transfer
          </label>
        </div>

        </div>
      </div>

      <div class="flex justify-center gap-4">
        <button type="button" onclick="window.history.back()" class="bg-gray-300 text-gray-800 px-4 py-2 rounded hover:bg-gray-400">
          Back
        </button>
        <button type="submit" class="bg-orange-600 text-white px-4 py-2 rounded hover:bg-blue-700">
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
      const court_id = new URLSearchParams(window.location.search).get('court_id');
      let section = new URLSearchParams(window.location.search).get('section');
      const date = new URLSearchParams(window.location.search).get('date');
      const time = new URLSearchParams(window.location.search).get('time');
      const fee =  new URLSearchParams(window.location.search).get('fee');
      let email = new URLSearchParams(window.location.search).get('email');
      let sessionEmail = "<?php echo isset($_SESSION['user_id']) ? $_SESSION['email'] : ''; ?>";
      let emailInput = document.getElementById("email");
      
      document.getElementById("sport-info").textContent = `Sport: ${sport}`;
      document.getElementById("court-info").textContent = `Court: ${court}`;
      let uniqueSections = section == 0 
          ? ["All"] 
          : [...new Set(section.split(',').map(s => s.trim()))];
        
      document.getElementById("section-info").textContent = `Section: ${uniqueSections.join(', ')}`;

      document.getElementById("date-info").textContent = `Date: ${date}`;
      
      if (sessionEmail) {
          emailInput.value = sessionEmail;
      } else if (email) {
          emailInput.value = email;
      }
      
      const formatTo12Hour = (timeStr) => {
          const [hour, minute] = timeStr.split(":").map(Number);
          const ampm = hour >= 12 ? "PM" : "AM";
          const formattedHour = (hour % 12 || 12).toString();
          return `${formattedHour}:${minute.toString().padStart(2, "0")} ${ampm}`;
      };

      const timeArray = typeof time === "string" ? time.split(",") : [];

      if (timeArray.length > 0) {
          const startTime = formatTo12Hour(timeArray[0]);
          let [endHour, endMinute] = timeArray[timeArray.length - 1].split(":").map(Number);
          endHour += 1;
          if (endHour === 24) endHour = 0; // handle wrap-around if needed
          const endTime = formatTo12Hour(`${endHour}:${endMinute}`);
          document.getElementById("time-info").textContent = `Time: ${startTime} - ${endTime}`;
      } else {
          document.getElementById("time-info").textContent = "Time: N/A";
      }


      // Assuming the fee is constant for now, adjust as needed.
      document.getElementById("fee").value = "P"+fee; 

      document.getElementById("reservation-form").addEventListener("submit", async function(e) {
        e.preventDefault();

        const fullName = document.getElementById("full-name").value;
        const contactNumber = document.getElementById("contact-number").value;
        const reservationInfo = document.getElementById("reservation-notes").value;
        const paymentMethod = document.querySelector('input[name="payment-method"]:checked').value;
        let email = document.getElementById("email").value;
        let payment = fee;

        if(!section) 
          section = 0

        const res = await fetch("api/complete_reservation.php", {
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
            court_id,
            section,
            date,
            time,
            payment
          })
        });

        const result = await res.json();
        if (result.success) {
          document.getElementById("splash-sport").textContent = sport;
          document.getElementById("splash-court").textContent = court;
          let uniqueSections = section == 0 
              ? ["All"] 
              : [...new Set(section.split(',').map(s => s.trim()))];
            
          document.getElementById("section-info").textContent = `Section: ${uniqueSections.join(', ')}`;

          document.getElementById("splash-date").textContent = date;
          //document.getElementById("splash-time").textContent = time;
          const formatTo12Hour = (timeStr) => {
              const [hour, minute] = timeStr.split(":").map(Number);
              const ampm = hour >= 12 ? "PM" : "AM";
              const formattedHour = (hour % 12 || 12).toString();
              return `${formattedHour}:${minute.toString().padStart(2, "0")} ${ampm}`;
          };
    
          const timeArray = typeof time === "string" ? time.split(",") : [];
    
          if (timeArray.length > 0) {
              const startTime = formatTo12Hour(timeArray[0]);
              let [endHour, endMinute] = timeArray[timeArray.length - 1].split(":").map(Number);
              endHour += 1;
              if (endHour === 24) endHour = 0; // handle wrap-around if needed
              const endTime = formatTo12Hour(`${endHour}:${endMinute}`);
              document.getElementById("splash-time").textContent = `Time: ${startTime} - ${endTime}`;
          } else {
              document.getElementById("splash-time").textContent = "Time: N/A";
          }
          document.getElementById("splash-name").textContent = fullName;
          document.getElementById("splash-contact").textContent = contactNumber;
          document.getElementById("splash-payment").textContent = paymentMethod;
        
          // Show splash screen
          document.getElementById("splash-screen").classList.remove("hidden");
        
          setTimeout(() => {
            window.location.href = "reserve.html";
          }, 3000);
        
          // Manual close handler
          document.getElementById("close-splash").addEventListener("click", () => {
            window.location.href = "reserve.html";
          });
        } else {
          alert("Error: " + result.message);
        }
      });
    });
  </script>
  <!-- Splash Screen -->
    <div id="splash-screen" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 hidden">
      <div class="bg-white p-6 rounded-lg text-center max-w-md w-full shadow-xl">
        <h2 class="text-2xl font-bold text-orange-600 mb-4">Reservation Confirmed!</h2>
        <div class="text-gray-800 mb-4 space-y-1">
          <div><strong>Sport:</strong> <span id="splash-sport"></span></div>
          <div><strong>Court:</strong> <span id="splash-court"></span></div>
          <div><strong>Section:</strong> <span id="splash-section"></span></div>
          <div><strong>Date:</strong> <span id="splash-date"></span></div>
          <div><strong>Time:</strong> <span id="splash-time"></span></div>
          <div><strong>Name:</strong> <span id="splash-name"></span></div>
          <div><strong>Contact:</strong> <span id="splash-contact"></span></div>
          <div><strong>Payment:</strong> <span id="splash-payment"></span></div>
        </div>
        <button id="close-splash" class="mt-4 bg-orange-600 text-white px-4 py-2 rounded hover:bg-orange-700">
          Close
        </button>
      </div>
    </div>

</body>
</html>
