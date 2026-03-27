<?php
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') === 'user') {
    header("Location: ../login.html");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Pickleball Admin Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/js/all.min.js" integrity="sha512-b+nQTCdtTBIRIbraqNEwsjB6UvL3UEMkXnhzd8awtCYh0Kcsjl9uEgwVFVbhoj3uu1DO1ZMacNvLoyJJiNfcvg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <link rel="stylesheet" href="styles/admin-theme.css">
</head>
<body class="admin-dashboard-body">

  <div class="admin-dashboard-layout">
    <aside id="sidebar" class="admin-sidebar w-72 flex flex-col transition-all duration-300">
      <div class="admin-sidebar-header p-5">
        <div class="flex items-start justify-between gap-3">
          <div class="flex items-center gap-3">
            <div class="admin-brand-mark">P</div>
            <div id="sidebar-title">
              <div class="admin-sidebar-title">Pickleball Admin</div>
              <div class="admin-sidebar-copy">One venue, multiple courts</div>
            </div>
          </div>
          <button onclick="toggleSidebar()" class="admin-sidebar-toggle" type="button" aria-label="Toggle sidebar">
            <i class="fas fa-bars"></i>
          </button>
        </div>
      </div>

      <nav class="flex-1 p-4 space-y-3">
        <div id="admin-menu-heading" class="admin-menu-heading mb-2 px-2 text-xs font-semibold uppercase tracking-[0.18em] text-white/60">Admin Menu</div>
        <button id="menu-admin_overview" onclick="loadPage('admin_overview.php')" class="admin-menu-btn">
          <i class="fas fa-chart-line"></i><span class="menu-label">Overview</span>
        </button>
        <?php if (($_SESSION['role'] ?? '') === 'admin') { ?>
        <button id="menu-admin_users" onclick="loadPage('admin_users.php')" class="admin-menu-btn">
          <i class="fas fa-user"></i><span class="menu-label">Users</span>
        </button>
        <?php } ?>
        <button id="menu-admin_courts" onclick="loadPage('admin_courts.php')" class="admin-menu-btn">
          <i class="fas fa-table-cells-large"></i><span class="menu-label">Courts</span>
        </button>
        <button id="menu-admin_schedules" onclick="loadPage('admin_schedules.php')" class="admin-menu-btn">
          <i class="fas fa-calendar-alt"></i><span class="menu-label">Court Schedules</span>
        </button>
        <button id="menu-admin_walkin" onclick="loadPage('admin_walkin.php')" class="admin-menu-btn">
          <i class="fas fa-person-walking"></i><span class="menu-label">Walk-ins</span>
        </button>
        <button id="menu-admin_reservations" onclick="loadPage('admin_reservations.php')" class="admin-menu-btn">
          <i class="fas fa-receipt"></i><span class="menu-label">Reservations</span>
        </button>
        <button id="menu-admin_reports" onclick="loadPage('admin_reports.php')" class="admin-menu-btn">
          <i class="fas fa-chart-column"></i><span class="menu-label">Reports</span>
        </button>
        <button id="menu-admin_support" onclick="loadPage('admin_support.php')" class="admin-menu-btn">
          <i class="fas fa-toolbox"></i><span class="menu-label">Support Tools</span>
        </button>
      </nav>

      <div class="p-4">
        <button onclick="logout()" class="admin-menu-btn admin-logout-btn w-full">
          <i class="fas fa-sign-out-alt"></i><span class="menu-label">Logout</span>
        </button>
      </div>
    </aside>

    <main class="admin-main">
      <div class="admin-topbar">
        <div class="admin-topbar-card flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
          <div>
            <div class="admin-overline">Back Office</div>
            <div class="admin-topbar-title">Pickleball Venue Operations</div>
            <div class="admin-topbar-copy">Start from the live overview, then jump into courts, schedules, walk-ins, reservations, reports, and support tools from one place.</div>
          </div>
          <div class="admin-pill"><?php echo htmlspecialchars(ucfirst((string) $_SESSION['role'])); ?> Access</div>
        </div>
      </div>

      <div class="admin-iframe-wrap">
        <iframe id="content-frame" src="admin_overview.php" class="admin-iframe border-none"></iframe>
      </div>
    </main>
  </div>

  <script>
    function cacheBustPage(page) {
      const separator = page.includes("?") ? "&" : "?";
      return `${page}${separator}v=${Date.now()}`;
    }

    function loadPage(page) {
      document.getElementById("content-frame").src = cacheBustPage(page);
      highlightMenu(page);
    }

    function logout() {
      fetch("../api/logout.php", { method: "POST" }).then(() => {
        window.location.href = "../index.html";
      });
    }

    function highlightMenu(page) {
      document.querySelectorAll("nav button").forEach((button) => {
        button.classList.remove("admin-menu-btn-active");
      });

      const button = document.getElementById("menu-" + page.replace(".php", ""));
      if (button) {
        button.classList.add("admin-menu-btn-active");
      }
    }

    function syncSidebarCollapsedState(isCollapsed) {
      const sidebar = document.getElementById("sidebar");
      const labels = document.querySelectorAll(".menu-label");
      const title = document.getElementById("sidebar-title");
      const menuHeading = document.getElementById("admin-menu-heading");

      sidebar.classList.toggle("w-72", !isCollapsed);
      sidebar.classList.toggle("w-20", isCollapsed);
      sidebar.classList.toggle("admin-sidebar-collapsed", isCollapsed);
      labels.forEach((label) => label.classList.toggle("hidden", isCollapsed));
      title.classList.toggle("hidden", isCollapsed);
      menuHeading.classList.toggle("hidden", isCollapsed);
    }

    function sidebarLockedOnMobile() {
      return window.matchMedia("(max-width: 768px)").matches;
    }

    function applyResponsiveSidebar() {
      if (sidebarLockedOnMobile()) {
        syncSidebarCollapsedState(true);
      }
    }

    function toggleSidebar() {
      if (sidebarLockedOnMobile()) {
        syncSidebarCollapsedState(true);
        return;
      }

      const sidebar = document.getElementById("sidebar");
      if (sidebar.classList.contains("w-72")) {
        syncSidebarCollapsedState(true);
        return;
      }

      syncSidebarCollapsedState(false);
    }

    window.addEventListener("DOMContentLoaded", () => {
      const iframe = document.getElementById("content-frame");
      const initialPage = iframe.getAttribute("src");
      iframe.src = cacheBustPage(initialPage);
      highlightMenu(initialPage);
      applyResponsiveSidebar();
    });

    window.addEventListener("resize", applyResponsiveSidebar);
  </script>
</body>
</html>
