<?php
session_start();
require_once "includes/db.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') === 'user') {
    header("Location: ../index.html");
    exit;
}

function adminFormatTo12Hour(string $timeStr): string
{
    [$hour, $minute] = explode(':', $timeStr);
    $numericHour = (int) $hour;
    $ampm = $numericHour >= 12 ? 'PM' : 'AM';
    $hour12 = $numericHour % 12 === 0 ? 12 : $numericHour % 12;

    return $hour12 . ':' . $minute . ' ' . $ampm;
}

function adminReservationHours(?string $timeSlots): int
{
    if (!is_string($timeSlots) || trim($timeSlots) === '') {
        return 0;
    }

    $slots = array_filter(array_map('trim', explode(',', $timeSlots)));
    return count($slots);
}

function adminReservationTimeRange(?string $timeSlots): string
{
    if (!is_string($timeSlots) || trim($timeSlots) === '') {
        return 'N/A';
    }

    $slots = array_values(array_filter(array_map('trim', explode(',', $timeSlots))));
    if ($slots === []) {
        return 'N/A';
    }

    sort($slots);
    $start = $slots[0];
    $end = $slots[count($slots) - 1];
    $endHour = DateTimeImmutable::createFromFormat('H:i:s', $end);
    if (!$endHour instanceof DateTimeImmutable) {
        return 'N/A';
    }

    return adminFormatTo12Hour($start) . ' - ' . adminFormatTo12Hour($endHour->modify('+1 hour')->format('H:i:s'));
}

function adminReservationCustomer(array $reservation): string
{
    if (!empty($reservation['full_name'])) {
        return (string) $reservation['full_name'];
    }

    if (!empty($reservation['guest_name'])) {
        return (string) $reservation['guest_name'];
    }

    if (!empty($reservation['email'])) {
        return (string) $reservation['email'];
    }

    if (!empty($reservation['user_id'])) {
        return 'User #' . $reservation['user_id'];
    }

    return 'Reservation';
}

$ownerId = (int) $_SESSION['user_id'];
$userRole = (string) ($_SESSION['role'] ?? 'admin');
$searchTerm = trim((string) ($_GET['search'] ?? ''));
$params = [];
$whereClauses = [];
$joins = "LEFT JOIN courts c ON r.court_id = c.id
          LEFT JOIN reservation_slots rs ON rs.reservation_id = r.id";

if ($userRole === 'owner') {
    $joins = "JOIN courts c ON r.court_id = c.id
              LEFT JOIN reservation_slots rs ON rs.reservation_id = r.id";
    $whereClauses[] = "c.owner_id = ?";
    $params[] = $ownerId;
}

if ($searchTerm !== '') {
    $searchLike = '%' . $searchTerm . '%';
    $whereClauses[] = "(
        CAST(r.id AS CHAR) LIKE ?
        OR COALESCE(r.full_name, '') LIKE ?
        OR COALESCE(r.guest_name, '') LIKE ?
        OR COALESCE(r.email, '') LIKE ?
        OR COALESCE(r.court, '') LIKE ?
        OR COALESCE(c.name, '') LIKE ?
        OR COALESCE(r.date, '') LIKE ?
        OR COALESCE(r.payment_method, '') LIKE ?
        OR COALESCE(r.booking_source, '') LIKE ?
    )";
    array_push(
        $params,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike
    );
}

$whereSql = $whereClauses === [] ? '' : 'WHERE ' . implode(' AND ', $whereClauses);

$reservationSql = "
    SELECT r.*, c.type AS sport, COALESCE(c.name, r.court) AS display_court, GROUP_CONCAT(DISTINCT rs.time ORDER BY rs.time) AS time_slots
    FROM reservations r
    {$joins}
    {$whereSql}
    GROUP BY r.id
    ORDER BY r.date DESC, time_slots DESC, r.created_at DESC
";

$reservationStatement = $pdo->prepare($reservationSql);
$reservationStatement->execute($params);
$reservations = $reservationStatement->fetchAll(PDO::FETCH_ASSOC);

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$todayBookings = 0;
$walkInCount = 0;
$pendingCount = 0;

foreach ($reservations as $reservation) {
    if (($reservation['date'] ?? '') === $today) {
        $todayBookings++;
    }

    if (($reservation['booking_source'] ?? 'advance') === 'walk-in') {
        $walkInCount++;
    }

    if (strtolower((string) ($reservation['payment_status'] ?? 'pending')) !== 'paid' && (int) ($reservation['is_admin_set'] ?? 0) !== 1) {
        $pendingCount++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Pickleball Admin - Reservations</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/js/all.min.js" integrity="sha512-b+nQTCdtTBIRIbraqNEwsjB6UvL3UEMkXnhzd8awtCYh0Kcsjl9uEgwVFVbhoj3uu1DO1ZMacNvLoyJJiNfcvg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <link rel="stylesheet" href="styles/admin-theme.css">
  <script>
    const editState = {
      reservationId: null,
      courtSlots: [],
      reservedSlots: [],
      price: 0
    };

    document.addEventListener("DOMContentLoaded", () => {
      document.getElementById("edit-date").addEventListener("change", refreshEditAvailability);
      document.getElementById("edit-start-time").addEventListener("change", handleEditStartTimeChange);
      document.getElementById("edit-end-time").addEventListener("change", () => {
        renderEditSlotGrid();
        updateEditSelectionSummary();
      });
      document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && !document.getElementById("editModal").classList.contains("hidden")) {
          closeEditModal();
        }
      });
    });

    function normalizeEditTime(value) {
      const rawValue = String(value || "").trim();
      if (!rawValue) {
        return "";
      }

      if (/^\d{2}:\d{2}$/.test(rawValue)) {
        return `${rawValue}:00`;
      }

      return /^\d{2}:\d{2}:\d{2}$/.test(rawValue) ? rawValue : "";
    }

    function formatEditTimeLabel(timeStr) {
      const normalized = normalizeEditTime(timeStr);
      if (!normalized) {
        return "";
      }

      const [hourValue, minuteValue] = normalized.split(":").map(Number);
      const suffix = hourValue >= 12 ? "PM" : "AM";
      const displayHour = hourValue % 12 || 12;
      return `${displayHour}:${minuteValue.toString().padStart(2, "0")} ${suffix}`;
    }

    function addOneHourToEditTime(timeStr) {
      const normalized = normalizeEditTime(timeStr);
      if (!normalized) {
        return "";
      }

      const [hourValue, minuteValue] = normalized.split(":").map(Number);
      if (Number.isNaN(hourValue) || Number.isNaN(minuteValue)) {
        return "";
      }

      const nextHour = (hourValue + 1).toString().padStart(2, "0");
      return `${nextHour}:${minuteValue.toString().padStart(2, "0")}:00`;
    }

    function buildEditSlotsFromRange(startTime, endTime) {
      const normalizedStart = normalizeEditTime(startTime);
      const normalizedEnd = normalizeEditTime(endTime);
      if (!normalizedStart || !normalizedEnd || normalizedStart >= normalizedEnd) {
        return [];
      }

      const slots = [];
      let cursor = normalizedStart;

      while (cursor && cursor < normalizedEnd) {
        slots.push(cursor);
        cursor = addOneHourToEditTime(cursor);
      }

      return slots;
    }

    function getCurrentEditSlots() {
      const startTime = document.getElementById("edit-start-time").value;
      const endTime = document.getElementById("edit-end-time").value;
      return buildEditSlotsFromRange(startTime, endTime);
    }

    function isPastEditSlot(date, timeStr) {
      const normalizedDate = String(date || "").trim();
      const normalizedTime = normalizeEditTime(timeStr);
      if (!normalizedDate || !normalizedTime) {
        return false;
      }

      return new Date(`${normalizedDate}T${normalizedTime}`) < new Date();
    }

    function editSlotIsBlocked(date, timeStr) {
      const normalizedTime = normalizeEditTime(timeStr);
      return editState.reservedSlots.includes(normalizedTime) || isPastEditSlot(date, normalizedTime);
    }

    function areEditSlotsSelectable(date, timeSlots) {
      if (!Array.isArray(timeSlots) || timeSlots.length === 0) {
        return false;
      }

      return timeSlots.every((timeSlot) => {
        const normalizedTime = normalizeEditTime(timeSlot);
        return editState.courtSlots.includes(normalizedTime) && !editSlotIsBlocked(date, normalizedTime);
      });
    }

    function toggleEditSaveButton(enabled) {
      const saveButton = document.getElementById("edit-save-btn");
      saveButton.disabled = !enabled;
      saveButton.classList.toggle("opacity-60", !enabled);
      saveButton.classList.toggle("cursor-not-allowed", !enabled);
    }

    function populateEditStartTimes(preferredStart = "") {
      const selectedDate = document.getElementById("edit-date").value;
      const startSelect = document.getElementById("edit-start-time");
      startSelect.innerHTML = '<option value="">Select time-in</option>';

      editState.courtSlots.forEach((timeSlot) => {
        if (editSlotIsBlocked(selectedDate, timeSlot)) {
          return;
        }

        const option = document.createElement("option");
        option.value = timeSlot;
        option.textContent = formatEditTimeLabel(timeSlot);
        startSelect.appendChild(option);
      });

      const hasPreferredStart = Array.from(startSelect.options).some((option) => option.value === preferredStart);
      startSelect.value = hasPreferredStart ? preferredStart : "";
      startSelect.disabled = startSelect.options.length <= 1;
    }

    function populateEditEndTimes(preferredEnd = "") {
      const selectedDate = document.getElementById("edit-date").value;
      const startTime = document.getElementById("edit-start-time").value;
      const endSelect = document.getElementById("edit-end-time");

      endSelect.innerHTML = '<option value="">Select time-out</option>';

      if (!startTime) {
        endSelect.disabled = true;
        return;
      }

      const startIndex = editState.courtSlots.indexOf(startTime);
      if (startIndex === -1) {
        endSelect.disabled = true;
        return;
      }

      for (let endIndex = startIndex + 1; endIndex <= editState.courtSlots.length; endIndex++) {
        const selectedSlots = editState.courtSlots.slice(startIndex, endIndex);
        if (!areEditSlotsSelectable(selectedDate, selectedSlots)) {
          break;
        }

        const lastSlot = selectedSlots[selectedSlots.length - 1];
        const endTime = addOneHourToEditTime(lastSlot);
        const option = document.createElement("option");
        option.value = endTime;
        option.textContent = formatEditTimeLabel(endTime);
        endSelect.appendChild(option);
      }

      const hasPreferredEnd = Array.from(endSelect.options).some((option) => option.value === preferredEnd);
      if (hasPreferredEnd) {
        endSelect.value = preferredEnd;
      } else if (endSelect.options.length > 1) {
        endSelect.selectedIndex = 1;
      } else {
        endSelect.value = "";
      }

      endSelect.disabled = endSelect.options.length <= 1;
    }

    function renderEditSlotGrid() {
      const slotGrid = document.getElementById("edit-slot-grid");
      const selectedDate = document.getElementById("edit-date").value;
      const selectedSlots = new Set(getCurrentEditSlots());

      if (editState.courtSlots.length === 0) {
        slotGrid.innerHTML = `
          <div class="rounded-2xl border border-dashed border-slate-300 bg-white/80 px-4 py-5 text-sm text-slate-500 sm:col-span-2 xl:col-span-3">
            No hourly court schedule is available for this reservation.
          </div>
        `;
        return;
      }

      slotGrid.innerHTML = "";

      editState.courtSlots.forEach((timeSlot) => {
        const endTime = addOneHourToEditTime(timeSlot);
        const isSelected = selectedSlots.has(timeSlot);
        const isTaken = editState.reservedSlots.includes(timeSlot);
        const isPast = isPastEditSlot(selectedDate, timeSlot);

        let toneClasses = "border-slate-200 bg-white/90 text-slate-700";
        let statusLabel = "Available";
        let statusClasses = "text-slate-500";

        if (isSelected) {
          toneClasses = "border-teal-300 bg-teal-100 text-teal-900";
          statusLabel = "Selected";
          statusClasses = "text-teal-700";
        } else if (isTaken) {
          toneClasses = "border-rose-200 bg-rose-100 text-rose-800";
          statusLabel = "Taken";
          statusClasses = "text-rose-600";
        } else if (isPast) {
          toneClasses = "border-slate-300 bg-slate-200 text-slate-500";
          statusLabel = "Past";
          statusClasses = "text-slate-500";
        }

        const slotCard = document.createElement("div");
        slotCard.className = `rounded-2xl border px-4 py-3 ${toneClasses}`;
        slotCard.innerHTML = `
          <div class="text-sm font-semibold">${formatEditTimeLabel(timeSlot)} - ${formatEditTimeLabel(endTime)}</div>
          <div class="mt-1 text-[11px] font-semibold uppercase tracking-[0.18em] ${statusClasses}">${statusLabel}</div>
        `;

        slotGrid.appendChild(slotCard);
      });
    }

    function updateEditSelectionSummary(message = "") {
      const selectedDate = document.getElementById("edit-date").value;
      const selectedSlots = getCurrentEditSlots();
      const hoursElement = document.getElementById("edit-hours-played");
      const totalElement = document.getElementById("edit-total-payment");
      const summaryElement = document.getElementById("edit-selection-summary");
      const availabilityElement = document.getElementById("edit-availability-note");

      if (!selectedDate || selectedSlots.length === 0) {
        hoursElement.textContent = "0 hours";
        totalElement.textContent = "P0.00";
        summaryElement.textContent = "Select a new time-in and time-out to update this reservation.";
        availabilityElement.textContent = message || "Taken timeslots are shown in red and cannot be selected.";
        toggleEditSaveButton(false);
        return;
      }

      const selectedHours = selectedSlots.length;
      const selectedStart = selectedSlots[0];
      const selectedEnd = addOneHourToEditTime(selectedSlots[selectedSlots.length - 1]);
      const totalPrice = editState.price * selectedHours;
      const takenCount = editState.reservedSlots.length;

      hoursElement.textContent = `${selectedHours} hour${selectedHours === 1 ? "" : "s"}`;
      totalElement.textContent = `P${totalPrice.toFixed(2)}`;
      summaryElement.textContent = `${selectedDate} | ${formatEditTimeLabel(selectedStart)} - ${formatEditTimeLabel(selectedEnd)}`;
      availabilityElement.textContent = message || (
        takenCount > 0
          ? `${takenCount} taken time slot${takenCount === 1 ? "" : "s"} shown below in red.`
          : "No conflicting time slots on this date."
      );
      toggleEditSaveButton(true);
    }

    function applyEditContext(data, resetSelection = false) {
      editState.reservationId = Number(data.reservation?.id || 0);
      editState.courtSlots = Array.isArray(data.court_slots) ? data.court_slots.map(normalizeEditTime).filter(Boolean) : [];
      editState.reservedSlots = Array.isArray(data.reserved_slots) ? data.reserved_slots.map(normalizeEditTime).filter(Boolean) : [];
      editState.price = Number(data.court?.price || 0);

      const selectedDate = document.getElementById("edit-date").value;
      let preferredSlots = resetSelection
        ? (Array.isArray(data.time_slots) ? data.time_slots.map(normalizeEditTime).filter(Boolean) : [])
        : getCurrentEditSlots();

      let infoMessage = "Taken timeslots are shown in red and cannot be selected.";
      if (!areEditSlotsSelectable(selectedDate, preferredSlots)) {
        preferredSlots = [];
        if (!resetSelection) {
          infoMessage = "The previously selected time range is not available on this date. Choose a new range.";
        }
      }

      const preferredStart = preferredSlots[0] || "";
      const preferredEnd = preferredSlots.length > 0 ? addOneHourToEditTime(preferredSlots[preferredSlots.length - 1]) : "";

      populateEditStartTimes(preferredStart);
      populateEditEndTimes(preferredEnd);
      renderEditSlotGrid();
      updateEditSelectionSummary(infoMessage);
    }

    async function openEditModal(id) {
      const response = await fetch(`api/get_reservation.php?id=${id}`);
      const data = await response.json();

      if (!data || !data.success) {
        alert(data?.message || "Failed to load reservation details.");
        return;
      }

      const reservation = data.reservation;
      document.getElementById("edit-id").value = reservation.id;
      document.getElementById("edit-user").value = reservation.user_id || "";
      document.getElementById("edit-court").value = data.court?.name || reservation.court || "";
      document.getElementById("edit-date").value = data.context_date || reservation.date || "";
      document.getElementById("payment_status_field").value = reservation.payment_status || "pending";
      document.getElementById("section-container").style.display = String(reservation.section_number) === "0" ? "none" : "block";
      document.getElementById("edit-section").value = reservation.section_number || 0;
      applyEditContext(data, true);
      document.getElementById("editModal").classList.remove("hidden");
      document.body.classList.add("overflow-hidden");
    }

    function closeEditModal() {
      document.getElementById("editModal").classList.add("hidden");
      document.body.classList.remove("overflow-hidden");
    }

    function handleEditModalBackdropClick(event) {
      if (event.target === event.currentTarget) {
        closeEditModal();
      }
    }

    async function refreshEditAvailability() {
      const reservationId = document.getElementById("edit-id").value;
      const selectedDate = document.getElementById("edit-date").value;

      if (!reservationId || !selectedDate) {
        return;
      }

      const response = await fetch(`api/get_reservation.php?id=${reservationId}&date=${encodeURIComponent(selectedDate)}`);
      const data = await response.json();

      if (!data || !data.success) {
        alert(data?.message || "Failed to load time slot availability.");
        return;
      }

      applyEditContext(data, false);
    }

    function handleEditStartTimeChange() {
      populateEditEndTimes();
      renderEditSlotGrid();
      updateEditSelectionSummary();
    }

    async function saveEdit(event) {
      event.preventDefault();

      const timeSlots = getCurrentEditSlots();
      if (timeSlots.length === 0) {
        alert("Select a valid time range first.");
        return;
      }

      const payload = {
        id: document.getElementById("edit-id").value,
        date: document.getElementById("edit-date").value,
        time_slots: timeSlots,
        payment_status: document.getElementById("payment_status_field").value
      };

      const response = await fetch("api/update_reservation.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      });

      const result = await response.json().catch(() => ({}));

      if (response.ok && result.success) {
        location.reload();
        return;
      }

      alert(result.message || "Failed to update reservation.");
    }
  </script>
</head>
<body class="admin-theme-body admin-frame-body">
  <div class="admin-page-shell">
    <div class="admin-page-header">
      <div class="admin-overline">Reservation Management</div>
      <div class="admin-title">Monitor bookings and update reservation details</div>
      <div class="admin-copy">Review advance reservations, walk-ins, payment follow-up, and reservation edits from one cleaner operations page.</div>
    </div>

    <div class="admin-stat-grid mb-5 md:grid-cols-3">
      <div class="admin-stat-card">
        <div class="admin-stat-label">Today's Bookings</div>
        <div class="admin-stat-value"><?= $todayBookings ?></div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Walk-ins Logged</div>
        <div class="admin-stat-value"><?= $walkInCount ?></div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Pending Payments</div>
        <div class="admin-stat-value"><?= $pendingCount ?></div>
      </div>
    </div>

    <form method="get" class="admin-filter-bar mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
      <div class="w-full max-w-xl">
        <label for="search" class="admin-field-label">Search Reservations</label>
        <div class="flex flex-col gap-3 sm:flex-row">
          <div class="relative flex-1">
            <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
              <i class="fas fa-search"></i>
            </span>
            <input
              type="search"
              name="search"
              id="search"
              value="<?= htmlspecialchars($searchTerm) ?>"
              placeholder="Search by customer, court, date, payment, or reservation ID"
              class="admin-input pl-11"
            >
          </div>
          <div class="flex gap-3">
            <button type="submit" class="admin-primary-btn whitespace-nowrap">Search</button>
            <?php if ($searchTerm !== ''): ?>
              <a href="admin_reservations.php" class="admin-secondary-btn whitespace-nowrap">Clear</a>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
        <a href="admin_walkin.php" class="admin-secondary-btn whitespace-nowrap">
          <i class="fas fa-person-walking"></i>
          Open Walk-ins
        </a>
        <div class="admin-pill"><?= htmlspecialchars(ucfirst($userRole)) ?> Access</div>
      </div>
    </form>

    <section class="admin-card">
      <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="text-xl font-semibold text-slate-800">All Reservations</h2>
          <p class="text-sm text-slate-500">Advance reservations, walk-ins, and admin blocks are listed together for staff monitoring.</p>
        </div>
        <div class="admin-pill">Visible records: <?= count($reservations) ?></div>
      </div>

      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Type</th>
              <th>Customer</th>
              <th>Court</th>
              <th>Date</th>
              <th>Time</th>
              <th>Hours</th>
              <th>Payment</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($reservations as $reservation): ?>
              <?php
              $sourceLabel = $reservation['is_admin_set']
                  ? 'Admin Block'
                  : (($reservation['booking_source'] ?? 'advance') === 'walk-in' ? 'Walk-in' : 'Advance');
              $sourceClass = $sourceLabel === 'Walk-in'
                  ? 'bg-teal-100 text-teal-700'
                  : ($sourceLabel === 'Admin Block' ? 'bg-slate-200 text-slate-700' : 'bg-blue-100 text-blue-700');
              $paymentStatus = strtolower((string) ($reservation['payment_status'] ?? 'pending'));
              $paymentStatusClass = $paymentStatus === 'paid' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700';
              $customerLabel = adminReservationCustomer($reservation);
              $timeRange = adminReservationTimeRange($reservation['time_slots'] ?? '');
              $hoursPlayed = adminReservationHours($reservation['time_slots'] ?? '');
              $paymentMethod = ucfirst(str_replace('-', ' ', (string) ($reservation['payment_method'] ?? 'n/a')));
              ?>
              <tr>
                <td><?= (int) $reservation['id'] ?></td>
                <td><span class="admin-tag <?= $sourceClass ?>"><?= htmlspecialchars($sourceLabel) ?></span></td>
                <td><?= htmlspecialchars($customerLabel) ?></td>
                <td><?= htmlspecialchars((string) ($reservation['display_court'] ?? $reservation['court'])) ?></td>
                <td><?= htmlspecialchars((string) $reservation['date']) ?></td>
                <td><?= htmlspecialchars($timeRange) ?></td>
                <td><?= htmlspecialchars((string) $hoursPlayed) ?></td>
                <td>
                  <div class="font-medium text-slate-800"><?= htmlspecialchars($paymentMethod) ?></div>
                  <span class="admin-tag mt-2 <?= $paymentStatusClass ?>"><?= htmlspecialchars(ucfirst($paymentStatus)) ?></span>
                </td>
                <td class="text-sm text-slate-600"><?= htmlspecialchars((string) $reservation['created_at']) ?></td>
                <td>
                  <div class="flex items-center gap-3">
                    <button onclick="openEditModal(<?= (int) $reservation['id'] ?>)" class="admin-action-link" type="button"><i class="fas fa-edit"></i></button>
                    <form method="post" action="/api/delete_reservation.php" onsubmit="return confirm('Delete this reservation?')">
                      <input type="hidden" name="id" value="<?= (int) $reservation['id'] ?>">
                      <button class="text-red-500 transition hover:text-red-700" type="submit"><i class="fas fa-trash"></i></button>
                    </form>
                    <?php if ($paymentStatus !== 'paid' && !$reservation['is_admin_set']): ?>
                      <form method="post" action="api/confirm_payment.php" onsubmit="return confirm('Confirm payment for this reservation?')">
                        <input type="hidden" name="id" value="<?= (int) $reservation['id'] ?>">
                        <button class="text-emerald-600 transition hover:text-emerald-700" type="submit"><i class="fas fa-check"></i></button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <div id="editModal" class="admin-modal-backdrop hidden items-start overflow-y-auto px-4 py-4 sm:px-6 sm:py-6" onclick="handleEditModalBackdropClick(event)">
    <div class="admin-modal-panel my-auto max-h-[calc(100vh-2rem)] max-w-4xl overflow-y-auto">
      <div class="flex items-start justify-between gap-4">
        <div>
          <div class="admin-overline">Reservation Details</div>
          <div class="admin-title text-[1.4rem]">Edit Reservation</div>
        </div>
        <button onclick="closeEditModal()" class="admin-secondary-btn !px-3 !py-2" type="button">
          <i class="fas fa-xmark"></i>
        </button>
      </div>

      <form id="edit-form" class="mt-5 grid gap-4" onsubmit="saveEdit(event)">
        <input type="hidden" name="id" id="edit-id" />
        <input type="text" name="payment_status" id="payment_status_field" class="hidden admin-input">

        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <label class="admin-field-label" for="edit-user">User ID</label>
            <input type="text" name="user_id" id="edit-user" class="admin-input" readonly>
          </div>

          <div>
            <label class="admin-field-label" for="edit-court">Court</label>
            <input type="text" name="court" id="edit-court" class="admin-input" readonly>
          </div>

          <div id="section-container">
            <label class="admin-field-label" for="edit-section">Section</label>
            <input type="number" name="section" id="edit-section" class="admin-input">
          </div>

          <div>
            <label class="admin-field-label" for="edit-date">Date</label>
            <input type="date" name="date" id="edit-date" class="admin-input">
          </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <div class="text-sm font-semibold text-slate-800">Edit Reservation Time Range</div>
              <p class="mt-1 text-sm text-slate-500">Choose a time-in and time-out. Every 1-hour slot in between will be saved for this reservation.</p>
            </div>
            <div id="edit-hours-played" class="admin-pill">0 hours</div>
          </div>

          <div class="mt-4 grid gap-4 md:grid-cols-2">
            <div>
              <label class="admin-field-label" for="edit-start-time">Time-in</label>
              <select id="edit-start-time" class="admin-select">
                <option value="">Select time-in</option>
              </select>
            </div>

            <div>
              <label class="admin-field-label" for="edit-end-time">Time-out</label>
              <select id="edit-end-time" class="admin-select" disabled>
                <option value="">Select time-out</option>
              </select>
            </div>
          </div>

          <div class="mt-4 flex flex-wrap gap-2 text-[11px] font-semibold uppercase tracking-[0.18em]">
            <span class="rounded-full border border-slate-200 bg-white px-3 py-1 text-slate-500">Available</span>
            <span class="rounded-full border border-rose-200 bg-rose-100 px-3 py-1 text-rose-600">Taken</span>
            <span class="rounded-full border border-teal-300 bg-teal-100 px-3 py-1 text-teal-700">Selected</span>
            <span class="rounded-full border border-slate-300 bg-slate-200 px-3 py-1 text-slate-500">Past</span>
          </div>

          <div id="edit-slot-grid" class="mt-4 grid max-h-[320px] gap-3 overflow-y-auto pr-1 sm:grid-cols-2 xl:grid-cols-3"></div>
        </div>

        <div class="rounded-2xl border border-teal-100 bg-teal-50 px-4 py-4">
          <div class="text-xs font-semibold uppercase tracking-[0.18em] text-teal-700">Edit Summary</div>
          <div id="edit-selection-summary" class="mt-2 text-sm font-medium text-teal-900">Select a new time-in and time-out to update this reservation.</div>

          <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <div class="rounded-2xl border border-white/80 bg-white/85 px-4 py-3">
              <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Estimated Total</div>
              <div id="edit-total-payment" class="mt-2 text-lg font-semibold text-slate-800">P0.00</div>
            </div>
            <div class="rounded-2xl border border-white/80 bg-white/85 px-4 py-3">
              <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Availability</div>
              <div id="edit-availability-note" class="mt-2 text-sm text-slate-600">Taken timeslots are shown in red and cannot be selected.</div>
            </div>
          </div>
        </div>

        <div class="mt-2 flex justify-end gap-3">
          <button type="button" onclick="closeEditModal()" class="admin-secondary-btn">Cancel</button>
          <button type="submit" id="edit-save-btn" class="admin-primary-btn">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
