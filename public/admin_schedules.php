<?php
session_start();
require_once "includes/db.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') === 'user') {
    header("Location: ../login.html");
    exit;
}

$userRole = (string) ($_SESSION['role'] ?? 'admin');
$statement = $pdo->query("SELECT * FROM courts ORDER BY name");

$courts = $statement->fetchAll(PDO::FETCH_ASSOC);
$courtCount = count($courts);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Pickleball Admin - Schedules</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css" rel="stylesheet" />
  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/js/all.min.js" integrity="sha512-b+nQTCdtTBIRIbraqNEwsjB6UvL3UEMkXnhzd8awtCYh0Kcsjl9uEgwVFVbhoj3uu1DO1ZMacNvLoyJJiNfcvg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <link rel="stylesheet" href="styles/admin-theme.css">
</head>
<body class="admin-theme-body admin-frame-body">
  <div class="admin-page-shell">
    <div class="admin-page-header">
      <div class="admin-overline">Court Schedules</div>
      <div class="admin-title">Manage each in-venue court schedule</div>
      <div class="admin-copy">Each court in this pickleball venue has its own schedule. Open Court A, Court B, or any other court below to review reservations, block single hours, or close a full day.</div>
    </div>

    <div class="mb-5 grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto]">
      <div class="admin-card">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
          <div>
            <div class="text-sm font-semibold text-slate-800">Keep every court calendar accurate</div>
            <p class="mt-1 text-sm text-slate-500">Use this screen to manage per-court schedules for your fixed venue courts, whether that is Court A, Court B, Court C, or future additions.</p>
          </div>
          <div class="admin-pill"><?= htmlspecialchars(ucfirst($userRole)) ?> Access</div>
        </div>
      </div>

      <div class="admin-stat-card">
        <div class="admin-stat-label">Venue Courts</div>
        <div class="admin-stat-value"><?= $courtCount ?></div>
      </div>
    </div>

    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Court Name</th>
            <th>Business Hours</th>
            <th>Rate</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($courts as $court): ?>
            <?php
            $openTime = !empty($court['open_time']) ? DateTimeImmutable::createFromFormat('H:i:s', (string) $court['open_time']) : null;
            $closeTime = !empty($court['close_time']) ? DateTimeImmutable::createFromFormat('H:i:s', (string) $court['close_time']) : null;
            $hoursLabel = ($openTime instanceof DateTimeImmutable && $closeTime instanceof DateTimeImmutable)
                ? $openTime->format('h:i A') . ' - ' . $closeTime->format('h:i A')
                : 'Hours not set';
            ?>
            <tr>
              <td>
                <div class="font-semibold text-slate-800"><?= htmlspecialchars((string) $court['name']) ?></div>
                <div class="mt-1 text-sm text-slate-500">Per-court calendar</div>
              </td>
              <td><?= htmlspecialchars($hoursLabel) ?></td>
              <td>P<?= number_format((float) ($court['price'] ?? 0), 2) ?>/hr</td>
              <td>
                <button
                  onclick='openScheduleModal(<?= (int) $court['id'] ?>, <?= json_encode((string) $court['name']) ?>)'
                  class="admin-primary-btn !px-4 !py-2 text-sm"
                  type="button"
                >
                  <i class="fas fa-calendar-days"></i>
                  Open Schedule
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div id="schedule-modal" class="admin-modal-backdrop hidden">
    <div class="admin-modal-panel max-w-6xl max-h-[92vh] overflow-y-auto">
      <div class="flex flex-col gap-4 border-b border-slate-200 pb-5 md:flex-row md:items-start md:justify-between">
        <div>
          <div class="admin-overline">Per-Court Calendar</div>
          <div id="schedule-modal-title" class="admin-title text-[1.45rem]">Schedule</div>
          <p class="mt-2 max-w-2xl text-sm text-slate-500">This calendar applies only to the selected court. Select an open slot to mark it unavailable, or open the day view and block the whole day when needed.</p>
        </div>
        <div class="flex items-center gap-3">
          <button
            id="mark-day-off-btn"
            class="admin-danger-btn hidden"
            onclick="markWholeDayUnavailable()"
            type="button"
          >
            <i class="fas fa-ban"></i>
            Close Day
          </button>
          <button
            onclick="closeScheduleModal()"
            class="admin-secondary-btn"
            type="button"
          >
            <i class="fas fa-xmark"></i>
            Close
          </button>
        </div>
      </div>

      <div class="mt-5 grid gap-4 xl:grid-cols-[300px_minmax(0,1fr)]">
        <div class="admin-card space-y-4">
          <div>
            <div class="admin-stat-label">How It Works</div>
            <div class="mt-3 space-y-3 text-sm text-slate-600">
              <p>Click and drag a time slot to mark that hour as unavailable.</p>
              <p>Select an existing event to delete a closure or remove a reservation block.</p>
              <p>Switch to the day view if you need to close the full operating day.</p>
            </div>
          </div>

          <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
            <div class="admin-stat-label">Legend</div>
            <div class="mt-3 space-y-2 text-sm text-slate-700">
              <div class="flex items-center gap-3">
                <span class="h-3 w-3 rounded-full bg-rose-500"></span>
                Reserved
              </div>
              <div class="flex items-center gap-3">
                <span class="h-3 w-3 rounded-full bg-teal-700"></span>
                Venue closed
              </div>
            </div>
          </div>
        </div>

        <div class="admin-card">
          <div id="admin-calendar"></div>
        </div>
      </div>
    </div>
  </div>

  <script>
    let adminCalendar;
    let selectedCourtId = null;

    async function openScheduleModal(courtId, courtName) {
      selectedCourtId = courtId;
      document.getElementById("schedule-modal-title").textContent = `Schedule for ${courtName}`;
      document.getElementById("schedule-modal").classList.remove("hidden");
      document.body.classList.add("overflow-hidden");
      renderAdminCalendar();
    }

    async function fetchCourtReservations(courtId) {
      const response = await fetch("api/get_court_reservations.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ court_id: courtId })
      });

      if (!response.ok) {
        alert("Failed to load reservations.");
        return { reservations: [], openTime: "08:00", closeTime: "22:00" };
      }

      const data = await response.json();
      const events = [];

      data.reservations.forEach((reservation) => {
        if (!reservation.time_slots) {
          return;
        }

        const slotSet = new Set(reservation.time_slots.split(",").map((slot) => slot.trim()).filter(Boolean));

        slotSet.forEach((slot) => {
          const [hour, minute] = slot.split(":").map(Number);
          const start = `${reservation.date}T${slot}`;
          const endHour = hour + 1;
          const end = `${reservation.date}T${endHour.toString().padStart(2, "0")}:${minute.toString().padStart(2, "0")}:00`;

          events.push({
            id: `${reservation.id}-${slot}`,
            title: reservation.is_admin_set == 1 ? "Closed" : "Reserved",
            start,
            end,
            allDay: false,
            color: reservation.is_admin_set == 1 ? "#0f766e" : "#e11d48"
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
      const { openTime, closeTime } = await fetchCourtReservations(selectedCourtId);

      if (adminCalendar) {
        adminCalendar.destroy();
      }

      const calendarEl = document.getElementById("admin-calendar");
      adminCalendar = new FullCalendar.Calendar(calendarEl, {
        initialView: "timeGridDay",
        height: 640,
        slotMinTime: openTime,
        slotMaxTime: closeTime,
        selectable: true,
        events: async function(info, successCallback, failureCallback) {
          try {
            const data = await fetchCourtReservations(selectedCourtId);
            successCallback(data.reservations);
          } catch (error) {
            failureCallback(error);
          }
        },
        select: async function(info) {
          if (!confirm(`Mark ${info.startStr} as NOT AVAILABLE?`)) {
            return;
          }

          const date = info.startStr.split("T")[0];
          const time = info.startStr.split("T")[1].slice(0, 5);
          const [hour, minute] = time.split(":").map(Number);
          const endHour = hour + 1;
          const endTime = `${endHour.toString().padStart(2, "0")}:${minute.toString().padStart(2, "0")}`;

          const response = await fetch("api/get_court_reservations.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              court_id: selectedCourtId,
              date: date,
              time: time,
              end_time: endTime,
              section_number: 9,
              is_admin_set: 1
            })
          });

          const result = await response.json();
          if (result.success) {
            alert("Slot marked as unavailable.");
            renderAdminCalendar();
            return;
          }

          alert("Failed to save this closure.");
        },
        eventClick: function(info) {
          if (confirm(`Delete this schedule item on ${info.event.start.toLocaleString()}?`)) {
            deleteReservation(info.event.id);
          }
        },
        headerToolbar: {
          left: "prev,next today",
          center: "title",
          right: "timeGridWeek,timeGridDay"
        },
        viewDidMount: function(info) {
          const markButton = document.getElementById("mark-day-off-btn");
          if (info.view.type === "timeGridDay" || info.view.type === "dayGridDay") {
            markButton.classList.remove("hidden");
            return;
          }

          markButton.classList.add("hidden");
        }
      });

      adminCalendar.render();
    }

    async function deleteReservation(reservationId) {
      const response = await fetch("api/get_court_reservations.php", {
        method: "DELETE",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ reservation_id: reservationId })
      });

      const result = await response.json();
      if (result.success) {
        alert("Schedule item deleted.");
        renderAdminCalendar();
        return;
      }

      alert("Failed to delete this item.");
    }

    function closeScheduleModal() {
      document.getElementById("schedule-modal").classList.add("hidden");
      document.body.classList.remove("overflow-hidden");

      if (adminCalendar) {
        adminCalendar.destroy();
        adminCalendar = null;
      }
    }

    async function markWholeDayUnavailable() {
      if (!selectedCourtId || !adminCalendar) {
        return;
      }

      const currentDate = adminCalendar.view.currentStart.toLocaleDateString("en-CA", {
        year: "numeric",
        month: "2-digit",
        day: "2-digit"
      });

      if (!confirm(`Mark entire ${currentDate} as closed?`)) {
        return;
      }

      const response = await fetch("api/get_court_reservations.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ court_id: selectedCourtId })
      });

      const data = await response.json();
      const startHour = parseInt(data.open_time.split(":")[0], 10);
      const endHour = parseInt(data.close_time.split(":")[0], 10);
      const timeList = [];

      for (let hour = startHour; hour < endHour; hour++) {
        timeList.push(`${hour.toString().padStart(2, "0")}:00`);
      }

      await fetch("api/get_court_reservations.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          court_id: selectedCourtId,
          date: currentDate,
          time: timeList.join(","),
          is_admin_set: 1,
          section_number: 9
        })
      });

      alert(`Marked ${currentDate} as unavailable.`);
      adminCalendar.refetchEvents();
    }
  </script>
</body>
</html>
