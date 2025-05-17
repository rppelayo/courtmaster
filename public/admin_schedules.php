<?php
session_start();
require_once "includes/db.php";
// Redirect if not admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.html");
    exit();
  }

// Fetch all courts from DB
$stmt = $pdo->query("SELECT id, name FROM courts ORDER BY name");
$courts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin Schedules – CourtMaster</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css" rel="stylesheet" />
  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
</head>
<body class="bg-gray-100">

  <div class="p-6">
  <h1 class="text-2xl text-orange-600 font-bold mb-4">Court Schedules</h1>

    <table class="w-full border-collapse border border-gray-300">
      <thead>
        <tr class="bg-gray-200">
          <th class="border border-gray-300 px-4 py-2 text-left">Court Name</th>
          <th class="border border-gray-300 px-4 py-2">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($courts as $court): ?>
          <tr class="hover:bg-gray-50">
            <td class="border border-gray-300 px-4 py-2"><?= htmlspecialchars($court['name']) ?></td>
            <td class="border border-gray-300 px-4 py-2 text-center">
              <button
                onclick="openScheduleModal(<?= $court['id'] ?>, '<?= htmlspecialchars($court['name']) ?>')"
                class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700"
              >
                Show Schedule
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Schedule Modal -->
  <div id="schedule-modal" class="fixed inset-0 hidden bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-5xl max-h-[90vh] overflow-auto">
      <div class="flex justify-between items-center mb-4">
        <h2 id="schedule-modal-title" class="text-xl font-bold"></h2>
        <button
          onclick="closeScheduleModal()"
          class="text-red-500 hover:text-red-700 font-bold text-2xl leading-none"
          aria-label="Close modal"
        >
          &times;
        </button>
      </div>
      <div id="admin-calendar"></div>
    </div>
  </div>

  <script>
    let adminCalendar;
    let selectedCourtId = null;

    async function openScheduleModal(courtId, courtName) {
      selectedCourtId = courtId;
      document.getElementById('schedule-modal-title').textContent = `Schedule for ${courtName}`;
      document.getElementById('schedule-modal').classList.remove('hidden');

      // Fetch and render reservations
      const events = await fetchCourtReservations(courtId);
      renderAdminCalendar(events);
    }

    async function fetchCourtReservations(courtId) {
      const res = await fetch('/api/get_court_reservations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ court_id: courtId })
      });
      if (!res.ok) {
        alert('Failed to load reservations');
        return [];
      }
      const reservations = await res.json();
      return reservations.map(r => ({
        id: r.id,
        title: 'Reserved',
        start: `${r.date}T${r.time}`,
        allDay: false,
        color: 'red'
      }));
    }

    function renderAdminCalendar(events) {
      if (adminCalendar) {
        adminCalendar.destroy();
      }
      const calendarEl = document.getElementById('admin-calendar');
      adminCalendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'timeGridWeek',
        height: 600,
        events: events,
        eventClick: function(info) {
          if (confirm(`Delete this reservation on ${info.event.start.toLocaleString()}?`)) {
            deleteReservation(info.event.id);
          }
        },
        headerToolbar: {
          left: 'prev,next today',
          center: 'title',
          right: 'timeGridWeek,timeGridDay'
        }
      });
      adminCalendar.render();
    }

    async function deleteReservation(reservationId) {
      const res = await fetch('/api/get_court_reservations.php', {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ reservation_id: reservationId })
      });
      const result = await res.json();
      if (result.success) {
        alert('Reservation deleted.');
        const events = await fetchCourtReservations(selectedCourtId);
        renderAdminCalendar(events);
      } else {
        alert('Failed to delete reservation.');
      }
    }

    function closeScheduleModal() {
      document.getElementById('schedule-modal').classList.add('hidden');
      if (adminCalendar) {
        adminCalendar.destroy();
        adminCalendar = null;
      }
    }
  </script>
</body>
</html>
