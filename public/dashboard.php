<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_name'])) {
  header("Location: login.html");
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
  <script>
  const userName = "<?php echo $user_name; ?>";

     window.onload = function () {
      document.getElementById("greeting").textContent = `Welcome back, ${userName}!`;
      loadReservations();
      showTab('dashboard');
    };

    function logout() {
      fetch("../api/logout.php", { method: "POST" }).then(() => {
        window.location.href = "login.html";
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
      document.querySelectorAll(".tab-btn").forEach(btn => btn.classList.remove("border-b-2", "border-blue-600", "text-blue-600"));
      document.getElementById(`tab-${tab}`).classList.add("border-b-2", "border-blue-600", "text-blue-600");
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
<body class="bg-gray-100 min-h-screen text-gray-800">

  <!-- Header -->
  <header class="bg-blue-600 text-white py-4 shadow-md">
    <div class="container mx-auto px-4 flex justify-between items-center">
      <h1 class="text-2xl font-bold">CourtMaster</h1>
      <button onclick="logout()" class="bg-white text-blue-600 px-4 py-1 rounded hover:bg-gray-100">
        Logout
      </button>
    </div>
  </header>

  <!-- Tabs -->
  <nav class="bg-white shadow mt-2">
    <div class="container mx-auto px-4 flex space-x-6 border-b">
      <button id="tab-dashboard" class="tab-btn py-3 text-gray-700 font-medium" onclick="showTab('dashboard')">Dashboard</button>
      <button id="tab-reserve" class="tab-btn py-3 text-gray-700 font-medium" onclick="showTab('reserve')">Reserve</button>
      <button id="tab-newsfeed" class="tab-btn py-3 text-gray-700 font-medium" onclick="showTab('newsfeed')">News Feed</button>
    </div>
  </nav>

  <!-- Main Content -->
  <main class="container mx-auto px-4 mt-6">

    <!-- Dashboard Section -->
    <div id="dashboard-content" class="space-y-6">
      <div class="bg-white rounded-xl shadow p-6">
        <h2 id="greeting" class="text-xl font-semibold mb-4">Welcome back!</h2>

        <div class="bg-gray-50 p-4 rounded-lg shadow">
          <h3 class="text-lg font-medium mb-2">Upcoming Reservations</h3>
          <ul id="reservation-list" class="text-sm text-gray-600 space-y-1">
            <li>Loading reservations...</li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Iframe Container -->
    <iframe id="tab-frame" class="hidden w-full h-[600px] border rounded-xl shadow" src=""></iframe>
  </main>
</body>
</html>
