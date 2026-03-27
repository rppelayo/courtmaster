<?php
declare(strict_types=1);

session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') === 'user') {
    header('Location: ../login.html');
    exit;
}

$courtStatement = $pdo->query(
    "SELECT id, name, location, price, member_price, open_time, close_time
     FROM courts
     WHERE type = 'pickleball'
     ORDER BY COALESCE(layout_row, 9999), COALESCE(layout_column, 9999), name"
);
$courts = $courtStatement->fetchAll(PDO::FETCH_ASSOC);

$selectedCourtId = max(0, (int) ($_GET['court_id'] ?? 0));
if ($selectedCourtId === 0 && $courts !== []) {
    $selectedCourtId = (int) ($courts[0]['id'] ?? 0);
}

$selectedCourt = null;
foreach ($courts as $court) {
    if ((int) ($court['id'] ?? 0) === $selectedCourtId) {
        $selectedCourt = $court;
        break;
    }
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Pickleball Admin - Schedules</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/js/all.min.js" integrity="sha512-b+nQTCdtTBIRIbraqNEwsjB6UvL3UEMkXnhzd8awtCYh0Kcsjl9uEgwVFVbhoj3uu1DO1ZMacNvLoyJJiNfcvg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <link rel="stylesheet" href="styles/admin-theme.css">
  <style>
    .hourly-schedule-table td,
    .hourly-schedule-table th {
      vertical-align: middle;
    }

    .hourly-schedule-table td {
      white-space: normal;
    }

    .schedule-slot-status {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border-radius: 999px;
      padding: 6px 12px;
      font-size: 0.76rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }
  </style>
</head>
<body class="admin-theme-body admin-frame-body">
  <div class="admin-page-shell">
    <div class="admin-page-header">
      <div class="admin-overline">Court Schedule</div>
      <div class="admin-title">Review one court by the hour</div>
      <div class="admin-copy">Open a specific court schedule from the Courts table, choose a date, and manage each hourly slot the way the venue actually operates.</div>
    </div>

    <?php if ($courts === []): ?>
      <div class="admin-card text-center">
        <div class="text-lg font-semibold text-slate-800">No courts are available yet.</div>
        <p class="mt-2 text-sm text-slate-500">Create at least one pickleball court first, then come back here to manage its hourly schedule.</p>
      </div>
    <?php else: ?>
      <div class="mb-5 grid gap-4 xl:grid-cols-[minmax(0,1fr)_320px]">
        <div class="admin-card">
          <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_220px_190px_auto] lg:items-end">
            <div>
              <label for="court-select" class="admin-field-label">Court</label>
              <select id="court-select" class="admin-select">
                <?php foreach ($courts as $court): ?>
                  <option value="<?= (int) $court['id'] ?>" <?= (int) ($court['id'] ?? 0) === $selectedCourtId ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) $court['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label for="schedule-date" class="admin-field-label">Date</label>
              <input id="schedule-date" type="date" class="admin-input" value="<?= htmlspecialchars($today) ?>" />
            </div>

            <div class="flex gap-3">
              <button type="button" class="admin-secondary-btn w-full" onclick="refreshSchedule()">
                <i class="fas fa-rotate-right"></i>
                Refresh
              </button>
            </div>

            <div class="flex gap-3">
              <button type="button" class="admin-secondary-btn w-full" onclick="openAdminPage('admin_courts.php')">
                <i class="fas fa-arrow-left"></i>
                Back to Courts
              </button>
            </div>
          </div>
        </div>

        <div class="admin-card">
          <div class="admin-stat-label">Selected Court</div>
          <div id="selected-court-name" class="admin-stat-value">
            <?= htmlspecialchars((string) ($selectedCourt['name'] ?? 'Court')) ?>
          </div>
          <div id="selected-court-meta" class="mt-2 text-sm text-slate-500">
            <?= $selectedCourt !== null ? htmlspecialchars((string) (($selectedCourt['location'] ?? '') !== '' ? $selectedCourt['location'] : 'Venue court')) : 'Choose a court to begin.' ?>
          </div>
        </div>
      </div>

      <div class="admin-stat-grid mb-5 md:grid-cols-4">
        <div class="admin-stat-card">
          <div class="admin-stat-label">Court Hours</div>
          <div id="stat-hours" class="admin-stat-value">-</div>
        </div>
        <div class="admin-stat-card">
          <div class="admin-stat-label">Available Hours</div>
          <div id="stat-available" class="admin-stat-value">0</div>
        </div>
        <div class="admin-stat-card">
          <div class="admin-stat-label">Reserved / Live</div>
          <div id="stat-reserved" class="admin-stat-value">0</div>
        </div>
        <div class="admin-stat-card">
          <div class="admin-stat-label">Venue Blocks</div>
          <div id="stat-blocked" class="admin-stat-value">0</div>
        </div>
      </div>

      <div class="mb-5 flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
        <div class="admin-filter-bar flex flex-col gap-3">
          <div class="text-sm font-semibold text-slate-800">Hourly slot controls</div>
          <p class="text-sm text-slate-500">Use the table below to block one hour at a time, reopen venue blocks, and quickly review player reservations without switching to a calendar.</p>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row">
          <button type="button" class="admin-danger-btn" onclick="blockWholeDay()">
            <i class="fas fa-ban"></i>
            Block Open Hours
          </button>
          <button type="button" class="admin-secondary-btn" onclick="reopenDayBlocks()">
            <i class="fas fa-lock-open"></i>
            Reopen Venue Blocks
          </button>
        </div>
      </div>

      <div class="admin-table-wrap">
        <table class="admin-table hourly-schedule-table">
          <thead>
            <tr>
              <th>Time</th>
              <th>Status</th>
              <th>Details</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody id="hourly-schedule-body">
            <tr>
              <td colspan="4" class="text-sm text-slate-500">Loading schedule...</td>
            </tr>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <script>
    const courts = <?= json_encode(array_map(static function (array $court): array {
        return [
            'id' => (int) ($court['id'] ?? 0),
            'name' => (string) ($court['name'] ?? ''),
            'location' => (string) ($court['location'] ?? ''),
            'price' => (float) ($court['price'] ?? 0),
            'member_price' => isset($court['member_price']) ? (float) $court['member_price'] : null,
            'open_time' => (string) ($court['open_time'] ?? '08:00:00'),
            'close_time' => (string) ($court['close_time'] ?? '22:00:00'),
        ];
    }, $courts), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const today = <?= json_encode($today, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const statusLabels = {
      reserved: "Reserved",
      checked_in: "Checked-in",
      in_progress: "In Progress",
      completed: "Completed"
    };
    const statusClasses = {
      available: "bg-emerald-100 text-emerald-700",
      blocked: "bg-slate-200 text-slate-700",
      reserved: "bg-sky-100 text-sky-700",
      checked_in: "bg-blue-100 text-blue-700",
      in_progress: "bg-amber-100 text-amber-700",
      completed: "bg-emerald-100 text-emerald-700",
      past: "bg-slate-100 text-slate-500"
    };

    let selectedCourtId = Number(<?= json_encode($selectedCourtId) ?>);
    let selectedDate = today;
    let scheduleData = {
      reservations: [],
      open_time: "08:00:00",
      close_time: "22:00:00"
    };

    document.addEventListener("DOMContentLoaded", function () {
      const courtSelect = document.getElementById("court-select");
      const dateInput = document.getElementById("schedule-date");

      if (!courtSelect || !dateInput) {
        return;
      }

      dateInput.value = today;
      dateInput.min = today;

      courtSelect.addEventListener("change", function () {
        selectedCourtId = Number(this.value || 0);
        syncSelectedCourtMeta();
        updateScheduleUrl();
        refreshSchedule();
      });

      dateInput.addEventListener("change", function () {
        selectedDate = this.value || today;
        refreshSchedule();
      });

      syncSelectedCourtMeta();
      refreshSchedule();
    });

    function openAdminPage(page) {
      if (window.parent && typeof window.parent.loadPage === "function") {
        window.parent.loadPage(page);
        return;
      }

      window.location.href = page;
    }

    function updateScheduleUrl() {
      const url = new URL(window.location.href);
      url.searchParams.set("court_id", String(selectedCourtId));
      window.history.replaceState({}, "", url);
    }

    function getSelectedCourt() {
      return courts.find((court) => Number(court.id) === Number(selectedCourtId)) || null;
    }

    function syncSelectedCourtMeta() {
      const selectedCourt = getSelectedCourt();
      if (!selectedCourt) {
        return;
      }

      document.getElementById("selected-court-name").textContent = selectedCourt.name;
      document.getElementById("selected-court-meta").textContent = selectedCourt.location || "Venue court";
    }

    function normalizeTime(timeValue) {
      const value = String(timeValue || "").trim();
      if (!value) {
        return "";
      }

      const parts = value.split(":");
      if (parts.length >= 2) {
        const hour = parts[0].padStart(2, "0");
        const minute = parts[1].padStart(2, "0");
        const second = (parts[2] || "00").padStart(2, "0");
        return `${hour}:${minute}:${second}`;
      }

      return "";
    }

    function formatTo12Hour(timeValue) {
      const normalized = normalizeTime(timeValue);
      if (!normalized) {
        return "N/A";
      }

      const [hourText, minuteText] = normalized.split(":");
      const hour = Number(hourText);
      const suffix = hour >= 12 ? "PM" : "AM";
      const displayHour = hour % 12 === 0 ? 12 : hour % 12;
      return `${displayHour}:${minuteText} ${suffix}`;
    }

    function buildHourlySlots(openTime, closeTime) {
      const start = normalizeTime(openTime);
      const end = normalizeTime(closeTime);
      if (!start || !end) {
        return [];
      }

      const [startHour, startMinute] = start.split(":").map(Number);
      const [endHour, endMinute] = end.split(":").map(Number);
      let currentMinutes = (startHour * 60) + startMinute;
      const endMinutes = (endHour * 60) + endMinute;
      const slots = [];

      while (currentMinutes < endMinutes) {
        const hour = String(Math.floor(currentMinutes / 60)).padStart(2, "0");
        const minute = String(currentMinutes % 60).padStart(2, "0");
        slots.push(`${hour}:${minute}:00`);
        currentMinutes += 60;
      }

      return slots;
    }

    function selectedDateReservations() {
      return scheduleData.reservations
        .filter((reservation) => String(reservation.date || "") === selectedDate)
        .map((reservation) => {
          const rawSlots = String(reservation.time_slots || "")
            .split(",")
            .map((slot) => normalizeTime(slot))
            .filter(Boolean);

          return {
            ...reservation,
            normalizedSlots: rawSlots
          };
        });
    }

    function buildSlotMap() {
      const slotMap = new Map();

      selectedDateReservations().forEach((reservation) => {
        reservation.normalizedSlots.forEach((slot) => {
          if (!slotMap.has(slot)) {
            slotMap.set(slot, reservation);
          }
        });
      });

      return slotMap;
    }

    function reservationLabel(reservation) {
      return reservation.full_name
        || reservation.guest_name
        || reservation.email
        || reservation.guest_email
        || `Reservation #${reservation.id}`;
    }

    function reservationTimeRange(reservation) {
      const slots = Array.isArray(reservation.normalizedSlots) ? reservation.normalizedSlots : [];
      if (slots.length === 0) {
        return "No time set";
      }

      const start = slots[0];
      const end = slots[slots.length - 1];
      const [endHourText, endMinuteText] = end.split(":");
      const endHour = Number(endHourText) + 1;
      const endTime = `${String(endHour).padStart(2, "0")}:${endMinuteText}:00`;

      return `${formatTo12Hour(start)} - ${formatTo12Hour(endTime)}`;
    }

    function isPastSlot(slot) {
      if (selectedDate !== today) {
        return false;
      }

      const normalized = normalizeTime(slot);
      if (!normalized) {
        return false;
      }

      const now = new Date();
      const [hourText, minuteText] = normalized.split(":");
      const slotEnd = new Date();
      slotEnd.setHours(Number(hourText), Number(minuteText), 0, 0);
      slotEnd.setHours(slotEnd.getHours() + 1);
      return slotEnd <= now;
    }

    async function refreshSchedule() {
      const selectedCourt = getSelectedCourt();
      if (!selectedCourt) {
        return;
      }

      const body = document.getElementById("hourly-schedule-body");
      body.innerHTML = '<tr><td colspan="4" class="text-sm text-slate-500">Loading hourly schedule...</td></tr>';

      try {
        const response = await fetch("api/get_court_reservations.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ court_id: selectedCourtId })
        });

        const result = await response.json();
        if (!response.ok) {
          throw new Error(result.error || "Failed to load schedule.");
        }

        scheduleData = {
          reservations: Array.isArray(result.reservations) ? result.reservations : [],
          open_time: normalizeTime(result.open_time || selectedCourt.open_time || "08:00:00"),
          close_time: normalizeTime(result.close_time || selectedCourt.close_time || "22:00:00")
        };

        renderScheduleTable();
      } catch (error) {
        body.innerHTML = `<tr><td colspan="4" class="text-sm text-rose-600">${escapeHtml(error.message || "Failed to load schedule.")}</td></tr>`;
      }
    }

    function renderScheduleTable() {
      const body = document.getElementById("hourly-schedule-body");
      const selectedCourt = getSelectedCourt();
      if (!body || !selectedCourt) {
        return;
      }

      const slots = buildHourlySlots(scheduleData.open_time || selectedCourt.open_time, scheduleData.close_time || selectedCourt.close_time);
      const slotMap = buildSlotMap();
      const reservations = selectedDateReservations();

      let availableCount = 0;
      let blockedCount = 0;
      let reservedCount = 0;

      const rows = slots.map((slot) => {
        const reservation = slotMap.get(slot) || null;
        const past = isPastSlot(slot);
        const endLabel = formatTo12Hour(addHour(slot));
        let statusKey = "available";
        let statusLabel = "Available";
        let details = "Open for booking or venue block.";
        let actionHtml = `<button type="button" class="admin-secondary-btn !px-4 !py-2 text-sm" onclick="blockHour('${slot}')" ${past ? "disabled" : ""}><i class="fas fa-ban"></i>Block Hour</button>`;

        if (past && !reservation) {
          statusKey = "past";
          statusLabel = "Past";
          details = "This hour has already passed.";
          actionHtml = '<span class="text-sm text-slate-400">No action</span>';
        } else if (reservation) {
          if (Number(reservation.is_admin_set || 0) === 1) {
            statusKey = "blocked";
            statusLabel = "Venue Block";
            details = `Blocked by staff • ${reservationTimeRange(reservation)}`;
            actionHtml = `<button type="button" class="admin-secondary-btn !px-4 !py-2 text-sm" onclick="openHour(${Number(reservation.id)}, '${slot}')"><i class="fas fa-lock-open"></i>Open Hour</button>`;
            blockedCount++;
          } else {
            const reservationStatus = String(reservation.game_status || "reserved").toLowerCase();
            statusKey = statusClasses[reservationStatus] ? reservationStatus : "reserved";
            statusLabel = statusLabels[statusKey] || "Reserved";
            details = `${escapeHtml(reservationLabel(reservation))} • ${reservationTimeRange(reservation)}`;
            actionHtml = '<span class="text-sm font-medium text-slate-500">Player reservation</span>';
            reservedCount++;
          }
        } else {
          availableCount++;
        }

        return `
          <tr>
            <td>
              <div class="font-semibold text-slate-800">${formatTo12Hour(slot)} - ${endLabel}</div>
            </td>
            <td>
              <span class="schedule-slot-status ${statusClasses[statusKey] || statusClasses.available}">
                ${escapeHtml(statusLabel)}
              </span>
            </td>
            <td class="text-sm text-slate-600">${details}</td>
            <td>${actionHtml}</td>
          </tr>
        `;
      });

      body.innerHTML = rows.join("") || '<tr><td colspan="4" class="text-sm text-slate-500">No hourly slots available for this court.</td></tr>';

      document.getElementById("stat-hours").textContent = `${formatTo12Hour(scheduleData.open_time)} - ${formatTo12Hour(scheduleData.close_time)}`;
      document.getElementById("stat-available").textContent = String(availableCount);
      document.getElementById("stat-reserved").textContent = String(reservedCount);
      document.getElementById("stat-blocked").textContent = String(blockedCount);
    }

    function addHour(slot) {
      const normalized = normalizeTime(slot);
      const [hourText, minuteText] = normalized.split(":");
      const nextHour = Number(hourText) + 1;
      return `${String(nextHour).padStart(2, "0")}:${minuteText}:00`;
    }

    async function blockHour(slot) {
      const selectedCourt = getSelectedCourt();
      if (!selectedCourt) {
        return;
      }

      if (!confirm(`Block ${selectedCourt.name} at ${formatTo12Hour(slot)} on ${selectedDate}?`)) {
        return;
      }

      try {
        const response = await fetch("api/get_court_reservations.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            court_id: selectedCourtId,
            date: selectedDate,
            time: slot,
            section_number: 9,
            is_admin_set: 1
          })
        });
        const result = await response.json();

        if (!response.ok || !result.success) {
          throw new Error(result.error || result.message || "Failed to block this hour.");
        }

        refreshSchedule();
      } catch (error) {
        alert(error.message || "Failed to block this hour.");
      }
    }

    async function openHour(reservationId, slot) {
      const reservation = selectedDateReservations().find((item) => Number(item.id) === Number(reservationId));
      if (!reservation) {
        return;
      }

      if (!confirm(`Open ${formatTo12Hour(slot)} for booking again?`)) {
        return;
      }

      const remainingSlots = reservation.normalizedSlots.filter((time) => time !== normalizeTime(slot));

      try {
        if (remainingSlots.length === 0) {
          await deleteReservationBlock(reservationId);
        } else {
          const response = await fetch("api/get_court_reservations.php", {
            method: "PUT",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              reservation_id: reservationId,
              date: selectedDate,
              time: remainingSlots,
              section_number: reservation.section_number || 9
            })
          });
          const result = await response.json();
          if (!response.ok || !result.success) {
            throw new Error(result.error || "Failed to update the venue block.");
          }
        }

        refreshSchedule();
      } catch (error) {
        alert(error.message || "Failed to reopen this hour.");
      }
    }

    async function deleteReservationBlock(reservationId) {
      const response = await fetch("api/get_court_reservations.php", {
        method: "DELETE",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ reservation_id: reservationId })
      });

      const result = await response.json();
      if (!response.ok || !result.success) {
        throw new Error(result.error || "Failed to remove the venue block.");
      }
    }

    async function blockWholeDay() {
      const slotMap = buildSlotMap();
      const slots = buildHourlySlots(scheduleData.open_time, scheduleData.close_time)
        .filter((slot) => !slotMap.has(slot))
        .filter((slot) => !isPastSlot(slot));

      if (slots.length === 0) {
        alert("There are no open hourly slots left to block on this date.");
        return;
      }

      if (!confirm(`Block ${slots.length} open hour(s) for the whole day?`)) {
        return;
      }

      try {
        for (const slot of slots) {
          const response = await fetch("api/get_court_reservations.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              court_id: selectedCourtId,
              date: selectedDate,
              time: slot,
              section_number: 9,
              is_admin_set: 1
            })
          });
          const result = await response.json();
          if (!response.ok || !result.success) {
            throw new Error(result.error || `Failed to block ${slot}.`);
          }
        }

        refreshSchedule();
      } catch (error) {
        alert(error.message || "Failed to block the whole day.");
      }
    }

    async function reopenDayBlocks() {
      const adminBlockIds = Array.from(new Set(
        selectedDateReservations()
          .filter((reservation) => Number(reservation.is_admin_set || 0) === 1)
          .map((reservation) => Number(reservation.id))
      ));

      if (adminBlockIds.length === 0) {
        alert("There are no venue blocks to reopen on this date.");
        return;
      }

      if (!confirm(`Reopen ${adminBlockIds.length} venue block(s) for this date?`)) {
        return;
      }

      try {
        for (const reservationId of adminBlockIds) {
          await deleteReservationBlock(reservationId);
        }

        refreshSchedule();
      } catch (error) {
        alert(error.message || "Failed to reopen the venue blocks.");
      }
    }

    function escapeHtml(value) {
      return String(value ?? "").replace(/[&<>'"]/g, function (character) {
        return {
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          "'": "&#39;",
          '"': "&quot;"
        }[character];
      });
    }
  </script>
</body>
</html>
