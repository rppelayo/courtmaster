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

$courtWhereClauses = [];
$courtParams = [];
if ($userRole === 'owner') {
    $courtWhereClauses[] = 'owner_id = ?';
    $courtParams[] = $ownerId;
}

$courtWhereSql = $courtWhereClauses === [] ? '' : 'WHERE ' . implode(' AND ', $courtWhereClauses);
$courtSql = "SELECT id, name, type, price, open_time, close_time FROM courts {$courtWhereSql} ORDER BY name";
$courtStatement = $pdo->prepare($courtSql);
$courtStatement->execute($courtParams);
$availableCourts = $courtStatement->fetchAll(PDO::FETCH_ASSOC);
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
    const walkInToday = <?php echo json_encode($today); ?>;

    async function openEditModal(id) {
      const response = await fetch(`api/get_reservation.php?id=${id}`);
      const data = await response.json();

      if (!data || !data.success) {
        return;
      }

      const reservation = data.reservation;
      document.getElementById("edit-id").value = reservation.id;
      document.getElementById("edit-user").value = reservation.user_id || "";
      document.getElementById("edit-court").value = reservation.court || "";
      document.getElementById("edit-date").value = reservation.date || "";
      document.getElementById("payment_status_field").value = reservation.payment_status || "pending";
      document.getElementById("section-container").style.display = String(reservation.section_number) === "0" ? "none" : "block";
      document.getElementById("edit-section").value = reservation.section_number || 0;
      document.getElementById("edit-time").value = reservation.time || "";
      document.getElementById("editModal").classList.remove("hidden");
      document.body.classList.add("overflow-hidden");
    }

    function closeEditModal() {
      document.getElementById("editModal").classList.add("hidden");
      document.body.classList.remove("overflow-hidden");
    }

    async function saveEdit(event) {
      event.preventDefault();

      const payload = {
        id: document.getElementById("edit-id").value,
        court: document.getElementById("edit-court").value,
        date: document.getElementById("edit-date").value,
        time: document.getElementById("edit-time").value,
        payment_status: document.getElementById("payment_status_field").value
      };

      const response = await fetch("api/update_reservation.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      });

      if (response.ok) {
        location.reload();
        return;
      }

      alert("Failed to update reservation.");
    }

    document.addEventListener("DOMContentLoaded", function() {
      initializeWalkInForm();
    });

    function initializeWalkInForm() {
      const courtSelect = document.getElementById("walk-in-court");
      const startSelect = document.getElementById("walk-in-start-time");
      const endSelect = document.getElementById("walk-in-end-time");

      if (!courtSelect || courtSelect.options.length <= 1) {
        updateWalkInSummary();
        return;
      }

      courtSelect.addEventListener("change", populateWalkInStartTimes);
      startSelect.addEventListener("change", populateWalkInEndTimes);
      endSelect.addEventListener("change", updateWalkInSummary);

      populateWalkInStartTimes();
    }

    function getSelectedWalkInCourtOption() {
      const courtSelect = document.getElementById("walk-in-court");
      return courtSelect.options[courtSelect.selectedIndex] || null;
    }

    function populateWalkInStartTimes() {
      const selectedCourt = getSelectedWalkInCourtOption();
      const startSelect = document.getElementById("walk-in-start-time");

      startSelect.innerHTML = '<option value="">Select time-in</option>';

      if (!selectedCourt || !selectedCourt.value) {
        populateWalkInEndTimes();
        return;
      }

      const slots = buildHourlySlots(selectedCourt.dataset.openTime, selectedCourt.dataset.closeTime);
      const availableStarts = slots.filter((slot) => !isPastTimeSlot(slot));

      availableStarts.forEach((slot) => {
        const option = document.createElement("option");
        option.value = slot;
        option.textContent = formatTo12Hour(slot);
        startSelect.appendChild(option);
      });

      populateWalkInEndTimes();
    }

    function populateWalkInEndTimes() {
      const selectedCourt = getSelectedWalkInCourtOption();
      const startSelect = document.getElementById("walk-in-start-time");
      const endSelect = document.getElementById("walk-in-end-time");

      endSelect.innerHTML = '<option value="">Select time-out</option>';

      if (!selectedCourt || !selectedCourt.value || !startSelect.value) {
        updateWalkInSummary();
        return;
      }

      const slots = buildHourlySlots(selectedCourt.dataset.openTime, selectedCourt.dataset.closeTime);
      const startIndex = slots.indexOf(startSelect.value);

      if (startIndex === -1) {
        updateWalkInSummary();
        return;
      }

      for (let index = startIndex + 1; index <= slots.length; index++) {
        const previousSlot = slots[index - 1];
        const endTime = addOneHour(previousSlot);
        if (!endTime) {
          continue;
        }

        const option = document.createElement("option");
        option.value = endTime;
        option.textContent = formatTo12Hour(endTime);
        endSelect.appendChild(option);
      }

      updateWalkInSummary();
    }

    function buildHourlySlots(openTime, closeTime) {
      const slots = [];
      if (!openTime || !closeTime) {
        return slots;
      }

      let cursor = openTime;
      while (cursor < closeTime) {
        const next = addOneHour(cursor);
        if (!next || next > closeTime) {
          break;
        }

        slots.push(cursor);
        cursor = next;
      }

      return slots;
    }

    function addOneHour(timeStr) {
      if (!timeStr) {
        return "";
      }

      const [hour, minute] = timeStr.split(":").map(Number);
      if (Number.isNaN(hour) || Number.isNaN(minute)) {
        return "";
      }

      const nextHour = (hour + 1).toString().padStart(2, "0");
      return `${nextHour}:${minute.toString().padStart(2, "0")}:00`;
    }

    function formatTo12Hour(timeStr) {
      const [hour, minute] = timeStr.split(":").map(Number);
      const ampm = hour >= 12 ? "PM" : "AM";
      const formattedHour = hour % 12 || 12;
      return `${formattedHour}:${minute.toString().padStart(2, "0")} ${ampm}`;
    }

    function isPastTimeSlot(timeStr) {
      const now = new Date();
      const slotDateTime = new Date(`${walkInToday}T${timeStr}`);
      return slotDateTime < now;
    }

    function updateWalkInSummary() {
      const selectedCourt = getSelectedWalkInCourtOption();
      const startTime = document.getElementById("walk-in-start-time").value;
      const endTime = document.getElementById("walk-in-end-time").value;
      const hoursElement = document.getElementById("walk-in-hours");
      const totalElement = document.getElementById("walk-in-total");
      const rangeElement = document.getElementById("walk-in-range");

      if (!selectedCourt || !selectedCourt.value || !startTime || !endTime) {
        hoursElement.textContent = "0 hours";
        totalElement.textContent = "P0.00";
        rangeElement.textContent = "Select a court, time-in, and time-out.";
        return;
      }

      const timeOutSlots = buildReservationTimeOptions(startTime, endTime);
      const hours = timeOutSlots.length;
      const rate = Number(selectedCourt.dataset.rate || 0);
      const total = rate * hours;

      hoursElement.textContent = `${hours} hour${hours === 1 ? "" : "s"}`;
      totalElement.textContent = `P${total.toFixed(2)}`;
      rangeElement.textContent = `${selectedCourt.dataset.courtName || selectedCourt.textContent.trim()} | ${formatTo12Hour(startTime)} - ${formatTo12Hour(endTime)}`;
    }

    function buildReservationTimeOptions(startTime, endTime) {
      const range = [];
      let cursor = startTime;

      while (cursor && cursor < endTime) {
        range.push(cursor);
        cursor = addOneHour(cursor);
      }

      return range;
    }

    async function submitWalkInReservation(event) {
      event.preventDefault();

      const payload = {
        customer_name: document.getElementById("walk-in-customer-name").value,
        contact_number: document.getElementById("walk-in-contact").value,
        court_id: document.getElementById("walk-in-court").value,
        date: document.getElementById("walk-in-date").value,
        start_time: document.getElementById("walk-in-start-time").value,
        end_time: document.getElementById("walk-in-end-time").value,
        payment_method: document.getElementById("walk-in-payment-method").value,
        payment_status: document.getElementById("walk-in-payment-status").value,
        reservation_info: document.getElementById("walk-in-notes").value
      };

      const response = await fetch("api/create_walk_in_reservation.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      });

      const result = await response.json();
      if (!response.ok || !result.success) {
        alert(result.message || "Failed to create walk-in reservation.");
        return;
      }

      alert(`Walk-in reservation created. Total: P${Number(result.payment || 0).toFixed(2)}`);
      location.reload();
    }
  </script>
</head>
<body class="admin-theme-body admin-frame-body">
  <div class="admin-page-shell">
    <div class="admin-page-header">
      <div class="admin-overline">Reservation Management</div>
      <div class="admin-title">Monitor bookings and create same-day walk-ins</div>
      <div class="admin-copy">Keep staff operations moving with one place for advance bookings, walk-ins, payment follow-up, and reservation edits.</div>
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

      <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <div class="admin-pill"><?= htmlspecialchars(ucfirst($userRole)) ?> Access</div>
        <div class="text-sm text-slate-500">Use the walk-in form below for same-day in-person rentals.</div>
      </div>
    </form>

    <section class="admin-card mb-6">
      <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
        <div class="max-w-2xl">
          <div class="admin-overline">Walk-in Flow</div>
          <h2 class="mt-2 text-2xl font-bold text-slate-800">Create a same-day reservation</h2>
          <p class="mt-2 text-sm text-slate-500">
            Staff can record a player on arrival, choose the court and booking range, then capture payment details without leaving the admin panel.
          </p>
        </div>
        <div class="admin-stat-grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div class="admin-stat-card min-w-[150px]">
            <div class="admin-stat-label">Date</div>
            <div class="admin-stat-value text-base"><?= htmlspecialchars($today) ?></div>
          </div>
          <div class="admin-stat-card min-w-[150px]">
            <div class="admin-stat-label">Hours</div>
            <div id="walk-in-hours" class="admin-stat-value text-base">0 hours</div>
          </div>
          <div class="admin-stat-card min-w-[150px]">
            <div class="admin-stat-label">Total</div>
            <div id="walk-in-total" class="admin-stat-value text-base">P0.00</div>
          </div>
        </div>
      </div>

      <form class="mt-6 grid gap-4 lg:grid-cols-2" onsubmit="submitWalkInReservation(event)">
        <div>
          <label for="walk-in-customer-name" class="admin-field-label">Customer Name</label>
          <input id="walk-in-customer-name" type="text" class="admin-input" required>
        </div>

        <div>
          <label for="walk-in-contact" class="admin-field-label">Contact Number</label>
          <input id="walk-in-contact" type="text" class="admin-input" required>
        </div>

        <div>
          <label for="walk-in-court" class="admin-field-label">Court</label>
          <select id="walk-in-court" class="admin-select" required>
            <option value="">Select a court</option>
            <?php foreach ($availableCourts as $court): ?>
              <option
                value="<?php echo (int) $court['id']; ?>"
                data-court-name="<?php echo htmlspecialchars((string) $court['name']); ?>"
                data-rate="<?php echo htmlspecialchars((string) $court['price']); ?>"
                data-open-time="<?php echo htmlspecialchars((string) $court['open_time']); ?>"
                data-close-time="<?php echo htmlspecialchars((string) $court['close_time']); ?>"
              >
                <?php echo htmlspecialchars($court['name'] . ' | P' . number_format((float) $court['price'], 2) . '/hr'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label for="walk-in-date" class="admin-field-label">Walk-in Date</label>
          <input id="walk-in-date" type="date" value="<?php echo htmlspecialchars($today); ?>" class="admin-input" readonly>
        </div>

        <div>
          <label for="walk-in-start-time" class="admin-field-label">Time-in</label>
          <select id="walk-in-start-time" class="admin-select" required>
            <option value="">Select time-in</option>
          </select>
        </div>

        <div>
          <label for="walk-in-end-time" class="admin-field-label">Time-out</label>
          <select id="walk-in-end-time" class="admin-select" required>
            <option value="">Select time-out</option>
          </select>
        </div>

        <div>
          <label for="walk-in-payment-method" class="admin-field-label">Payment Method</label>
          <select id="walk-in-payment-method" class="admin-select" required>
            <option value="cash">Cash</option>
            <option value="gcash-maya">GCash</option>
            <option value="card">Card</option>
          </select>
        </div>

        <div>
          <label for="walk-in-payment-status" class="admin-field-label">Payment Status</label>
          <select id="walk-in-payment-status" class="admin-select" required>
            <option value="paid">Paid</option>
            <option value="pending">Pending</option>
          </select>
        </div>

        <div class="lg:col-span-2">
          <label for="walk-in-notes" class="admin-field-label">Notes</label>
          <textarea id="walk-in-notes" rows="3" class="admin-textarea" placeholder="Optional walk-in notes"></textarea>
        </div>

        <div class="lg:col-span-2 rounded-2xl border border-teal-100 bg-teal-50 px-4 py-4">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p id="walk-in-range" class="text-sm font-medium text-teal-900">Select a court, time-in, and time-out.</p>
            <button type="submit" class="admin-primary-btn">
              <i class="fas fa-plus-circle"></i>
              Create Walk-in Reservation
            </button>
          </div>
        </div>
      </form>
    </section>

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

  <div id="editModal" class="admin-modal-backdrop hidden">
    <div class="admin-modal-panel max-w-xl">
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

        <div>
          <label class="admin-field-label" for="edit-time">Time</label>
          <input type="time" step="3600" name="time" id="edit-time" class="admin-input">
          <input type="text" name="payment_status" id="payment_status_field" class="hidden admin-input">
        </div>

        <div class="mt-2 flex justify-end gap-3">
          <button type="button" onclick="closeEditModal()" class="admin-secondary-btn">Cancel</button>
          <button type="submit" class="admin-primary-btn">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
