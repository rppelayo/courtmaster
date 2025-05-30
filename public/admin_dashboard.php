<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'user') {
    header("Location: ../index.html");
    exit;
}

include 'chatbox.php'; 

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Dashboard – CourtMaster</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/js/all.min.js" integrity="sha512-b+nQTCdtTBIRIbraqNEwsjB6UvL3UEMkXnhzd8awtCYh0Kcsjl9uEgwVFVbhoj3uu1DO1ZMacNvLoyJJiNfcvg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
</head>
<body class="flex bg-gray-100 h-screen">

  <!-- Sidebar -->
  <div id="sidebar" class="w-64 bg-gray-800 text-white flex flex-col transition-all duration-300">
    <div class="p-4 flex justify-between items-center border-b border-gray-700">
      <span id="sidebar-title" class="text-xl font-bold">CourtMaster</span>
      <button onclick="toggleSidebar()" class="text-white focus:outline-none">
        <i class="fas fa-bars"></i>
      </button>
    </div>
    <nav class="flex-1 p-4 space-y-4">
      <?php if ($_SESSION['role'] === 'admin') { ?>
      <button id="menu-admin_users" onclick="loadPage('admin_users.php')" class="flex items-center w-full hover:bg-gray-700 px-3 py-2 rounded">
        <i class="fas fa-user mr-3"></i><span class="menu-label">Users</span>
      </button>
      <?php } ?>
      <button id="menu-admin_courts" onclick="loadPage('admin_courts.php')" class="flex items-center w-full hover:bg-gray-700 px-3 py-2 rounded">
        <i class="fas fa-basketball-ball mr-3"></i><span class="menu-label">Courts</span>
      </button>
      <button id="menu-admin_schedules" onclick="loadPage('admin_schedules.php')" class="flex items-center w-full hover:bg-gray-700 px-3 py-2 rounded">
        <i class="fas fa-calendar-alt mr-3"></i><span class="menu-label">Schedules</span>
      </button>
      <button id="menu-admin_reservations" onclick="loadPage('admin_reservations.php')" class="flex items-center w-full hover:bg-gray-700 px-3 py-2 rounded">
        <i class="fas fa-money-bill-wave mr-3"></i><span class="menu-label">Reservations</span>
      </button>
      <button onclick="logout()" class="flex items-center bg-orange-500 hover:bg-orange-600 px-3 py-2 rounded text-white mt-8 w-full">
        <i class="fas fa-sign-out-alt mr-3"></i><span class="menu-label">Logout</span>
      </button>
    </nav>
  </div>

  <!-- Main Content -->
  <div class="flex-1 overflow-hidden">
     <?php if ($_SESSION['role'] === 'admin') { ?>
      <iframe id="content-frame" src="admin_users.php" class="w-full h-full border-none"></iframe>
    <?php } else { ?>
      <iframe id="content-frame" src="admin_courts.php" class="w-full h-full border-none"></iframe>
    <?php } ?>
  </div>

  <script>

    function loadPage(page) {
      document.getElementById('content-frame').src = page;
      highlightMenu(page);
    }

    function logout() {
      fetch("../api/logout.php", { method: "POST" }).then(() => {
        window.location.href = "../index.html";
      });
    }

    function highlightMenu(page) {
      // Remove highlight from all menu buttons
      document.querySelectorAll('nav button').forEach(btn => {
        btn.classList.remove('bg-gray-700');
      });
      
      // Add highlight to the current page button
      const btn = document.getElementById('menu-' + page.replace('.php', ''));
      if (btn) {
        btn.classList.add('bg-gray-700');
      }
    }

    function toggleSidebar() {
      const sidebar = document.getElementById("sidebar");
      const labels = document.querySelectorAll(".menu-label");

      if (sidebar.classList.contains("w-64")) {
        sidebar.classList.remove("w-64");
        sidebar.classList.add("w-20");
        labels.forEach(label => label.classList.add("hidden"));
        document.getElementById("sidebar-title").classList.add("hidden");
      } else {
        sidebar.classList.remove("w-20");
        sidebar.classList.add("w-64");
        labels.forEach(label => label.classList.remove("hidden"));
        document.getElementById("sidebar-title").classList.remove("hidden");
      }
    }

    window.addEventListener('DOMContentLoaded', () => {
      const iframe = document.getElementById('content-frame');
      highlightMenu(iframe.src.split('/').pop()); // get just filename
    });
  </script>
</body>
</html>
