<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_name'])) {
  header("Location: index.html");
  exit;
}
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
        }
      }

      // Update active tab styling
      document.querySelectorAll(".tab-btn").forEach(btn => btn.classList.remove("border-b-2", "border-orange-500", "text-orange-500"));
      document.getElementById(`tab-${tab}`).classList.add("border-b-2", "border-orange-500", "text-orange-500");

    }

    function loadReservations() {
      fetch('/api/my_reservations.php')
        .then(res => res.json())
        .then(data => {

          const list = document.getElementById('reservation-list');
          list.innerHTML = '';

          if (data.length === 0) {
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
            li.className = 'flex justify-between items-center py-2 border-b';
            li.innerHTML = `
              <span>${icon} <strong>${r.sport}</strong> at <strong>${r.court}</strong><br>
                <small>${r.date} at ${r.time}</small></span>
              <button onclick="cancelReservation(${r.id})"
                      class="text-red-500 hover:underline text-sm">Cancel</button>
            `;
            list.appendChild(li);
          });
        });
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
    
        <!-- Logout -->
        <button onclick="logout()" class="bg-white text-orange-600 px-4 py-1 rounded hover:bg-gray-100 transition">
          <i class="fas fa-sign-out-alt mr-3"></i>Logout
        </button>
      </div>
    </header>


  <!-- Tabs -->
<nav class="bg-gray-600 shadow mt-2">
    <div class="container mx-auto px-4 flex space-x-6 border-b">
      <button id="tab-dashboard" class="tab-btn py-3 text-white font-medium hover:text-orange-600 transition" onclick="showTab('dashboard')">Dashboard</button>
      <button id="tab-reserve" class="tab-btn py-3 text-white font-medium hover:text-orange-600 transition" onclick="showTab('reserve')">Reserve</button>
      <button id="tab-newsfeed" class="tab-btn py-3 text-white font-medium hover:text-orange-600 transition" onclick="showTab('newsfeed')">News Feed</button>
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
          <ul id="reservation-list" class="text-sm text-gray-600 space-y-1">
            <li>Loading reservations...</li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Iframe Container -->
    <iframe id="tab-frame" class="hidden w-full h-[1000px] border rounded-xl shadow" src=""></iframe>
  </main>
  <style>
    .tab-btn.active {
      border-bottom-width: 2px;
      border-color: #F97316; /* orange-500 */
      color: #F97316;
    }
  </style>
</body>
</html>
