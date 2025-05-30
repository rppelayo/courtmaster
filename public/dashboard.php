<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_name'])) {
  header("Location: index.html");
  exit;
}

include 'chatbox.php'; 

$user_name = htmlspecialchars($_SESSION['user_name']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CourtMaster – Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/js/all.min.js" integrity="sha512-b+nQTCdtTBIRIbraqNEwsjB6UvL3UEMkXnhzd8awtCYh0Kcsjl9uEgwVFVbhoj3uu1DO1ZMacNvLoyJJiNfcvg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <script>

  let allReservations = [];

  const userName = "<?php echo $user_name; ?>";

     window.onload = function () {
      document.getElementById("greeting").textContent = `Welcome back, ${userName}!`;
      loadReservations();
      showTab('dashboard');
    };

    function logout() {
      fetch("api/logout.php", { method: "POST" }).then(() => {
        window.location.href = "index.html";
      });
    }

    function showTab(tab) {
      const dashboardContent = document.getElementById("dashboard-content");
      const iframe = document.getElementById("tab-frame");

      if (tab === 'dashboard') {
        dashboardContent.classList.remove("hidden");
        iframe.classList.add("hidden");
      } else {
        dashboardContent.classList.add("hidden");
        iframe.classList.remove("hidden");

        if (tab === 'reserve') {
          iframe.src = 'reserve.html';
        } else if (tab === 'newsfeed') {
          iframe.src = 'newsfeed.html';
        } else if (tab === 'about') {
          iframe.src = 'about.html';
        } else if (tab === 'contact') {
          iframe.src = 'contact.html';
        }
      }

      // Update active tab styling
      document.querySelectorAll(".tab-btn").forEach(btn => btn.classList.remove("border-b-2", "border-orange-500", "text-orange-500"));
      document.getElementById(`tab-${tab}`).classList.add("border-b-2", "border-orange-500", "text-orange-500");

    }

    function showSubscribeTab() {
      document.getElementById("dashboard-content").classList.add("hidden");
      const iframe = document.getElementById("tab-frame");
      iframe.classList.remove("hidden");
      iframe.src = "about_subscribe.html";

      // Optional: remove active tab highlight
      document.querySelectorAll(".tab-btn").forEach(btn => 
        btn.classList.remove("border-b-2", "border-orange-500", "text-orange-500"));
    }


    function loadReservations() {
      fetch('api/my_reservations.php')
      .then(res => res.json())
      .then(data => {
        allReservations = data; // store for filtering
        renderReservations(allReservations);
      });
    }

    function renderReservations(data) {
      const list = document.getElementById('reservation-list');
      list.innerHTML = '';

      if (!data || data.length === 0) {
        list.innerHTML = '<li>No reservations found.</li>';
        return;
      }

      const sportIcons = {
        Basketball: '🏀',
        Volleyball: '🏐',
        Tennis: '🎾',
        Badminton: '🏸',
        Soccer: '⚽',
        Default: '🎯'
      };

      data.forEach(r => {
        const icon = sportIcons[r.sport] || sportIcons.Default;
        const li = document.createElement('li');

        const formatTo12Hour = (timeStr) => {
          const [hour, minute] = timeStr.split(":").map(Number);
          const ampm = hour >= 12 ? "PM" : "AM";
          const formattedHour = (hour % 12 || 12).toString();
          return `${formattedHour}:${minute.toString().padStart(2, "0")} ${ampm}`;
        };

        const timeArray = typeof r.time === "string" ? r.time.split(",") : [];
        const time_str = timeArray.length > 0 
          ? `${formatTo12Hour(timeArray[0])} - ${formatTo12Hour(timeArray[timeArray.length - 1])}` 
          : "N/A";

        li.className = 'flex justify-between items-center py-2 border-b cursor-pointer hover:bg-gray-100';
        li.onclick = () => showReservationModal(r);

        li.innerHTML = `
          <span class="ml-3">
            ${icon} <strong>${r.sport}</strong> at <strong>${r.court}</strong><br>
            <small>${r.date} at ${time_str}</small>
          </span>
          <button onclick="event.stopPropagation(); cancelReservation(${r.id})"
                  class="text-red-500 hover:text-red-700 text-lg mr-3">
            <i class="fas fa-trash-alt"></i>
          </button>
        `;
        list.appendChild(li);
      });
    }

    function filterReservations() {
      const term = document.getElementById('reservation-search').value.toLowerCase();
      const filtered = allReservations.filter(r =>
        r.sport.toLowerCase().includes(term) ||
        r.court.toLowerCase().includes(term) ||
        r.date.includes(term)
      );
      renderReservations(filtered);
    }


    function cancelReservation(id) {
      if (!confirm("Cancel this reservation?")) return;

      fetch('/api/cancel_reservation.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ reservation_id: id })
      }).then(res => res.json()).then(result => {
        if (result.success) {
          alert("Reservation cancelled.");
          loadReservations();
        } else {
          alert("Error: " + result.message);
        }
      });
    }

    function showReservationModal(r) {
      const modal = document.getElementById("reservation-modal");
      const modalBody = document.getElementById("modal-body");

      const formatTo12Hour = (timeStr) => {
        const [hour, minute] = timeStr.split(":").map(Number);
        const ampm = hour >= 12 ? "PM" : "AM";
        const formattedHour = (hour % 12 || 12).toString();
        return `${formattedHour}:${minute.toString().padStart(2, "0")} ${ampm}`;
      };

      const timeArray = typeof r.time === "string" ? r.time.split(",") : [];
      const startTime = formatTo12Hour(timeArray[0]);
      const endTime = formatTo12Hour(timeArray[timeArray.length - 1]);

      const time_str = timeArray.length > 0 ? `${startTime} - ${endTime}` : "N/A";

      modalBody.innerHTML = `
        <img src="images/courts/${r.image_path || 'default.jpg'}" class="w-full rounded-lg mb-4 shadow" alt="${r.court}">
        <p><strong>Sport:</strong> ${r.sport}</p>
        <p><strong>Court:</strong> ${r.court}</p>
        <p><strong>Section(s):</strong> ${r.sections === "0" ? "All" : r.sections}</p>
        <p><strong>Date:</strong> ${r.date}</p>
        <p><strong>Time:</strong> ${time_str}</p>
      `;
      modal.classList.remove("hidden");
    }
    function closeModal() {
      document.getElementById("reservation-modal").classList.add("hidden");
    }


  </script>
</head>
<body class="bg-gray-800 min-h-screen text-gray-800">

  <!-- Header -->
    <header class="bg-orange-500 text-white py-4 shadow-md">
      <div class="container mx-auto px-4 flex items-center justify-between">
        
        <!-- Logo + Title -->
        <a href="">
            <div class="flex items-center space-x-4">
                <img src="images/resources/courtmaster-front-logo.jpg" alt="CourtMaster Logo" class="h-10 w-10 rounded-full shadow-md" />
                <div>
                    <h1 class="text-2xl font-bold leading-tight">CourtMaster</h1>
                    <p class="text-sm text-white opacity-80 -mt-1">Find Your Court, Book Your Game</p>
                 </div>
            </div>
        </a>

        <div class="flex items-center space-x-3">
          <?php if ($_SESSION['role'] === 'user') { ?>
          <button onclick="showSubscribeTab()" class="bg-red-600 hover:bg-red-700 transition text-white px-4 py-1 rounded">
            🎟️ Subscribe Now
          </button> 
          <?php } ?>
          <button onclick="logout()" class="bg-white text-orange-600 px-4 py-1 rounded hover:bg-gray-100 transition">
            <i class="fas fa-sign-out-alt mr-2"></i>Logout
          </button>
        </div>
      </div>
    </header>


  <!-- Tabs -->
<nav class="bg-gray-600 shadow mt-2">
    <div class="container mx-auto px-4 flex space-x-6 border-b">
      <button id="tab-dashboard" class="tab-btn py-3 text-white font-medium hover:text-orange-600 transition" onclick="showTab('dashboard')">Dashboard</button>
      <button id="tab-reserve" class="tab-btn py-3 text-white font-medium hover:text-orange-600 transition" onclick="showTab('reserve')">Reserve</button>
      <button id="tab-newsfeed" class="tab-btn py-3 text-white font-medium hover:text-orange-600 transition" onclick="showTab('newsfeed')">News Feed</button>
      <button id="tab-about" class="tab-btn py-3 text-white font-medium hover:text-orange-600 transition" onclick="showTab('about')">About</button>
      <button id="tab-contact" class="tab-btn py-3 text-white font-medium hover:text-orange-600 transition" onclick="showTab('contact')">Contact Us</button>
    </div>
  </nav>

  <!-- Main Content -->
  <main class="container mx-auto px-4 mt-6">

    <!-- Dashboard Section -->
    <div id="dashboard-content" class="space-y-6">
      <div class="bg-gray-900 rounded-xl shadow p-6">
        <h2 id="greeting" class="text-xl font-semibold mb-4 text-orange-600">Welcome back!</h2>

        <div class="bg-gray-800 p-4 rounded-lg shadow">
          <h3 class="text-lg font-medium mb-2 text-orange-500">Upcoming Reservations</h3>
          <input type="text" id="reservation-search" placeholder="Search reservations..." 
            class="mb-4 w-full px-4 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-orange-400" 
            oninput="filterReservations()" />

          <ul id="reservation-list" class="text-sm text-gray-600 space-y-1">
            <li>Loading reservations...</li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Iframe Container -->
    <iframe id="tab-frame" name="tab-frame" class="hidden w-full h-[1000px] border rounded-xl shadow" src=""></iframe>
  </main>
  <style>
    .tab-btn.active {
      border-bottom-width: 2px;
      border-color: #F97316; /* orange-500 */
      color: #F97316;
    }
  </style>

  <!-- Reservation Detail Modal -->
<div id="reservation-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex justify-center items-center hidden">
  <div class="bg-white rounded-lg shadow-lg max-w-md w-full p-6 relative">
    <button onclick="closeModal()" class="absolute top-2 right-2 text-gray-500 hover:text-gray-800 text-xl">
      <i class="fas fa-times"></i>
    </button>
    <h3 class="text-lg font-bold mb-4 text-orange-600">Reservation Details</h3>
    <div id="modal-body" class="text-sm space-y-2 text-gray-700">
      <!-- Filled by JS -->
    </div>
  </div>
</div>

</body>
</html>
