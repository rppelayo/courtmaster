<?php
session_start();
require_once "includes/db.php";
// Redirect if not admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'user') {
    header("Location: ../index.html");
    exit;
}

$user_id = $_SESSION['user_id'];
// Fetch all courts from DB
if($_SESSION['role'] === 'owner') {
  $stmt = $pdo->prepare("SELECT * FROM courts WHERE owner_id = ?");
  $stmt->execute([$user_id]);
}else{
  $stmt = $pdo->query("SELECT * FROM courts");
}

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
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/js/all.min.js" integrity="sha512-b+nQTCdtTBIRIbraqNEwsjB6UvL3UEMkXnhzd8awtCYh0Kcsjl9uEgwVFVbhoj3uu1DO1ZMacNvLoyJJiNfcvg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
</head>
<body class="bg-gray-100">

  <div class="p-6">
  <h1 class="text-2xl text-orange-600 font-bold mb-4">Court Schedules</h1>

    <table class="w-full border-collapse border border-gray-300">
      <thead class="bg-gray-800 text-white">
        <tr class="bg-gray-800">
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
                class="text-green-500"
              ><i class="fas fa-eye"></i>
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
      <div class="mb-4"><button id="mark-day-off-btn" class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded hidden" onclick="markWholeDayUnavailable()"><i class="fas fa-close"></i> Mark Whole Day as Closed</button></div>
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
      //const events = await fetchCourtReservations(courtId);
      renderAdminCalendar();//(events);
    }

    async function fetchCourtReservations(courtId) {
      const res = await fetch("api/get_court_reservations.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ court_id: courtId })
      });

      if (!res.ok) {
        alert("Failed to load reservations");
        return { reservations: [], openTime: "08:00", closeTime: "22:00" };
      }

      const data = await res.json();

      /* Build an array of FullCalendar event objects */
      const events = [];

      data.reservations.forEach(r => {
        if (!r.time_slots) return; // safety check
        
        // Remove duplicate times by using a Set
        const slotSet = new Set(r.time_slots.split(",").map(s => s.trim()));
        
        // section_number is a single number, so make it an array for easy looping
        const sections = [r.section_number.toString()];
        
        slotSet.forEach(slot => {
          const [hour, minute] = slot.split(":").map(Number);
          const start = `${r.date}T${slot}`;
          const endHour = hour + 1;
          const end = `${r.date}T${endHour.toString().padStart(2, "0")}:${minute.toString().padStart(2, "0")}:00`;
          
          sections.forEach(section => {
            const sectionLabel = (section === "9" || section === "0") ? "All" : section;
            events.push({
              id: `${r.id}-${slot}-${section}`,  // unique id per reservation/time/section
              title: (r.is_admin_set == 1 ? "Closed" : "Reserved") + "\nSection: " + sectionLabel,
              start,
              end,
              allDay: false,
              color: r.is_admin_set == 1 ? "green" : "red"
            });
          });
        });
      });


      return {
        reservations: events,
        openTime: data.open_time,
        closeTime: data.close_time
      };
    }



    async function renderAdminCalendar() {
      const { reservations, openTime, closeTime } = await fetchCourtReservations(selectedCourtId);
      //const { reservations, openTime, closeTime } = eventsData;

      if (adminCalendar) {
        adminCalendar.destroy();
      }
      const calendarEl = document.getElementById('admin-calendar');
      adminCalendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'timeGridDay',
        height: 600,
        slotMinTime: openTime,
        slotMaxTime: closeTime,
        selectable: true,
        events: async function(info, successCallback, failureCallback) {
          try {
            const data = await fetchCourtReservations(selectedCourtId);
            successCallback(data.reservations);
          } catch (err) {
            failureCallback(err);
          }
        },
        select: async function(info) {
          if (confirm(`Mark ${info.startStr} as NOT AVAILABLE?`)) {
            const courtId = selectedCourtId;
            const date = info.startStr.split("T")[0];
            const time = info.startStr.split("T")[1].slice(0, 5); // HH:MM

            // Calculate end time = start + 1 hour
            const [hour, minute] = time.split(':').map(Number);
            const endHour = hour + 1;
            const endTime = `${endHour.toString().padStart(2, '0')}:${minute.toString().padStart(2, '0')}`;

            const response = await fetch('api/get_court_reservations.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                court_id: courtId,
                date: date,
                time: time,
                end_time: endTime,   // new field to pass end time
                section_number: 9,
                is_admin_set: 1
              })
            });

            const result = await response.json();
            if (result.success) {
              alert("Marked as not available!");
              renderAdminCalendar();
            } else {
              alert("Failed to save.");
            }
          }
        },

        eventClick: function(info) {
          if (confirm(`Delete this reservation on ${info.event.start.toLocaleString()}?`)) {
            deleteReservation(info.event.id);
          }
        },
        headerToolbar: {
          left: 'prev,next today',
          center: 'title',
          right: 'timeGridWeek,timeGridDay'
        },
        viewDidMount: function(info) {
          const markBtn = document.getElementById('mark-day-off-btn');
          if (info.view.type === 'timeGridDay' || info.view.type === 'dayGridDay') {
            markBtn.classList.remove('hidden');
          } else {
            markBtn.classList.add('hidden');
          }
        },
        datesSet: function(info) {
          const currentDate = info.view.currentStart.toLocaleDateString('en-CA', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
          });
          console.log("Currently viewed day:", currentDate);
        }
      });
      adminCalendar.render();
    }

    async function deleteReservation(reservationId) {
      const res = await fetch('api/get_court_reservations.php', {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ reservation_id: reservationId })
      });
      const result = await res.json();
      if (result.success) {
        alert('Reservation deleted.');
        //const events = await fetchCourtReservations(selectedCourtId);
        renderAdminCalendar();//(events);
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

    async function markWholeDayUnavailable() {
      if (!selectedCourtId || !adminCalendar) return;

      //const currentDate = adminCalendar.getDate().toISOString().split('T')[0];
      //const currentDate = adminCalendar.view.currentStart.toISOString().split('T')[0];
      const currentDate = adminCalendar.view.currentStart.toLocaleDateString('en-CA', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
          });
      if (!confirm(`Mark entire ${currentDate} as closed?`)) return;

      // Fetch court open/close time
      const res = await fetch('api/get_court_reservations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ court_id: selectedCourtId })
      });

      const data = await res.json();
      const { open_time, close_time } = data;

      const startHour = parseInt(open_time.split(':')[0]);
      const endHour = parseInt(close_time.split(':')[0]);

      const timeList = [];
      for (let h = startHour; h < endHour; h++) {
        timeList.push(`${h.toString().padStart(2, '0')}:00`);
      }

      await fetch('api/get_court_reservations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          court_id: selectedCourtId,
          date: currentDate,
          time: timeList.join(','), // e.g. "08:00,09:00,10:00"
          is_admin_set: 1,
          section_number: 9
        })
      });

      alert(`Marked ${currentDate} as unavailable.`);
      adminCalendar.refetchEvents(); // Refresh calendar
    }

  </script>
</body>
</html>
