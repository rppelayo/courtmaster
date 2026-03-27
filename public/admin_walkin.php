<?php
session_start();
require_once "includes/db.php";
require_once "includes/game_status.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') === 'user') {
    header("Location: ../index.html");
    exit;
}

function adminWalkInFormatTo12Hour(string $timeStr): string
{
    [$hour, $minute] = explode(':', $timeStr);
    $numericHour = (int) $hour;
    $ampm = $numericHour >= 12 ? 'PM' : 'AM';
    $hour12 = $numericHour % 12 === 0 ? 12 : $numericHour % 12;

    return $hour12 . ':' . $minute . ' ' . $ampm;
}

function adminWalkInTimeRange(?string $timeSlots): string
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

    return adminWalkInFormatTo12Hour($start) . ' - ' . adminWalkInFormatTo12Hour($endHour->modify('+1 hour')->format('H:i:s'));
}

function adminWalkInCustomer(array $reservation): string
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

    return 'Walk-in Reservation';
}

$ownerId = (int) $_SESSION['user_id'];
$userRole = (string) ($_SESSION['role'] ?? 'admin');
$today = (new DateTimeImmutable('today'))->format('Y-m-d');

$courtWhereClauses = [];
$courtParams = [];
if ($userRole === 'owner') {
    $courtWhereClauses[] = 'owner_id = ?';
    $courtParams[] = $ownerId;
}

$courtWhereSql = $courtWhereClauses === [] ? '' : 'WHERE ' . implode(' AND ', $courtWhereClauses);
$courtSql = "SELECT id, name, price, member_price, open_time, close_time FROM courts {$courtWhereSql} ORDER BY name";
$courtStatement = $pdo->prepare($courtSql);
$courtStatement->execute($courtParams);
$availableCourts = $courtStatement->fetchAll(PDO::FETCH_ASSOC);

$summaryParams = [];
$summaryWhereClauses = [];
$summaryJoinSql = 'LEFT JOIN courts c ON r.court_id = c.id';
if ($userRole === 'owner') {
    $summaryJoinSql = 'JOIN courts c ON r.court_id = c.id';
    $summaryWhereClauses[] = 'c.owner_id = ?';
    $summaryParams[] = $ownerId;
}

$summaryWhereSql = $summaryWhereClauses === [] ? '' : 'WHERE ' . implode(' AND ', $summaryWhereClauses);
$summarySql = "
    SELECT r.date, COALESCE(r.booking_source, 'advance') AS booking_source, COALESCE(r.payment_status, 'pending') AS payment_status
    FROM reservations r
    {$summaryJoinSql}
    {$summaryWhereSql}
";
$summaryStatement = $pdo->prepare($summarySql);
$summaryStatement->execute($summaryParams);
$summaryRows = $summaryStatement->fetchAll(PDO::FETCH_ASSOC);

$todayBookings = 0;
$todayWalkIns = 0;
$pendingWalkIns = 0;
foreach ($summaryRows as $summaryRow) {
    if (($summaryRow['date'] ?? '') !== $today) {
        continue;
    }

    $todayBookings++;

    if (($summaryRow['booking_source'] ?? 'advance') === 'walk-in') {
        $todayWalkIns++;

        if (strtolower((string) ($summaryRow['payment_status'] ?? 'pending')) !== 'paid') {
            $pendingWalkIns++;
        }
    }
}

$recentWalkInParams = [];
$recentWalkInWhereClauses = ["COALESCE(r.booking_source, 'advance') = 'walk-in'"];
$recentWalkInJoinSql = "LEFT JOIN courts c ON r.court_id = c.id
                        LEFT JOIN reservation_slots rs ON rs.reservation_id = r.id";
if ($userRole === 'owner') {
    $recentWalkInJoinSql = "JOIN courts c ON r.court_id = c.id
                            LEFT JOIN reservation_slots rs ON rs.reservation_id = r.id";
    $recentWalkInWhereClauses[] = 'c.owner_id = ?';
    $recentWalkInParams[] = $ownerId;
}

$recentWalkInWhereSql = 'WHERE ' . implode(' AND ', $recentWalkInWhereClauses);
$recentWalkInsSql = "
    SELECT r.*, COALESCE(c.name, r.court) AS display_court, GROUP_CONCAT(DISTINCT rs.time ORDER BY rs.time) AS time_slots
    FROM reservations r
    {$recentWalkInJoinSql}
    {$recentWalkInWhereSql}
    GROUP BY r.id
    ORDER BY r.date DESC, time_slots DESC, r.created_at DESC
    LIMIT 10
";
$recentWalkInsStatement = $pdo->prepare($recentWalkInsSql);
$recentWalkInsStatement->execute($recentWalkInParams);
$recentWalkIns = $recentWalkInsStatement->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Pickleball Admin - Walk-ins</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/js/all.min.js" integrity="sha512-b+nQTCdtTBIRIbraqNEwsjB6UvL3UEMkXnhzd8awtCYh0Kcsjl9uEgwVFVbhoj3uu1DO1ZMacNvLoyJJiNfcvg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <link rel="stylesheet" href="styles/admin-theme.css">
  <script>
    const walkInServerToday = <?php echo json_encode($today); ?>;

    document.addEventListener("DOMContentLoaded", function() {
      applyClientWalkInDate();
      initializeWalkInForm();
    });

    function padWalkInValue(value) {
      return value.toString().padStart(2, "0");
    }

    function getClientNow() {
      return new Date();
    }

    function getClientToday() {
      const now = getClientNow();
      return `${now.getFullYear()}-${padWalkInValue(now.getMonth() + 1)}-${padWalkInValue(now.getDate())}`;
    }

    function buildClientLocalTimestamp() {
      const now = getClientNow();
      const offsetMinutes = -now.getTimezoneOffset();
      const sign = offsetMinutes >= 0 ? "+" : "-";
      const absoluteOffset = Math.abs(offsetMinutes);
      const offsetHours = padWalkInValue(Math.floor(absoluteOffset / 60));
      const offsetRemainderMinutes = padWalkInValue(absoluteOffset % 60);

      return `${now.getFullYear()}-${padWalkInValue(now.getMonth() + 1)}-${padWalkInValue(now.getDate())}T${padWalkInValue(now.getHours())}:${padWalkInValue(now.getMinutes())}:${padWalkInValue(now.getSeconds())}${sign}${offsetHours}:${offsetRemainderMinutes}`;
    }

    function applyClientWalkInDate() {
      const walkInDateInput = document.getElementById("walk-in-date");
      const summaryDate = document.getElementById("walk-in-summary-date");
      const clientToday = getClientToday() || walkInServerToday;

      if (walkInDateInput) {
        walkInDateInput.value = clientToday;
      }

      if (summaryDate) {
        summaryDate.textContent = clientToday;
      }
    }

    function initializeWalkInForm() {
      const courtSelect = document.getElementById("walk-in-court");
      const startSelect = document.getElementById("walk-in-start-time");
      const endSelect = document.getElementById("walk-in-end-time");
      const discountSelect = document.getElementById("walk-in-discount-type");

      if (!courtSelect || courtSelect.options.length <= 1) {
        updateWalkInSummary();
        return;
      }

      courtSelect.addEventListener("change", populateWalkInStartTimes);
      startSelect.addEventListener("change", populateWalkInEndTimes);
      endSelect.addEventListener("change", updateWalkInSummary);
      discountSelect.addEventListener("change", updateWalkInSummary);

      if (!courtSelect.value && courtSelect.options.length > 1) {
        courtSelect.selectedIndex = 1;
      }

      populateWalkInStartTimes();
    }

    function getSelectedWalkInCourtOption() {
      const courtSelect = document.getElementById("walk-in-court");
      return courtSelect.options[courtSelect.selectedIndex] || null;
    }

    function formatWalkInCurrency(value) {
      return `P${Number(value || 0).toFixed(2)}`;
    }

    function syncWalkInDiscountOptions(selectedCourt) {
      const discountSelect = document.getElementById("walk-in-discount-type");
      const memberOption = discountSelect.querySelector('option[value="member_rate"]');
      const regularRate = Number(selectedCourt?.dataset.rate || 0);
      const memberRate = Number(selectedCourt?.dataset.memberRate || 0);
      const hasMemberRate = selectedCourt && memberRate > 0 && memberRate < regularRate;

      memberOption.disabled = !hasMemberRate;
      memberOption.textContent = hasMemberRate
        ? `Member Rate (${formatWalkInCurrency(memberRate)}/hr)`
        : "Member Rate Unavailable";

      if (!hasMemberRate && discountSelect.value === "member_rate") {
        discountSelect.value = "none";
      }
    }

    function populateWalkInStartTimes() {
      const selectedCourt = getSelectedWalkInCourtOption();
      const startSelect = document.getElementById("walk-in-start-time");
      const endSelect = document.getElementById("walk-in-end-time");

      startSelect.innerHTML = '<option value="">Select time-in</option>';
      endSelect.innerHTML = '<option value="">Select time-out</option>';
      startSelect.disabled = true;
      endSelect.disabled = true;

      if (!selectedCourt || !selectedCourt.value) {
        syncWalkInDiscountOptions(null);
        updateWalkInSummary();
        return;
      }

      syncWalkInDiscountOptions(selectedCourt);

      const slots = buildHourlySlots(selectedCourt.dataset.openTime, selectedCourt.dataset.closeTime);
      const availableStarts = slots.filter((slot) => !isPastTimeSlot(slot));

      if (availableStarts.length === 0) {
        startSelect.innerHTML = '<option value="">No remaining time slots today</option>';
        updateWalkInSummary();
        return;
      }

      startSelect.disabled = false;

      availableStarts.forEach((slot) => {
        const option = document.createElement("option");
        option.value = slot;
        option.textContent = formatTo12Hour(slot);
        startSelect.appendChild(option);
      });

      if (startSelect.options.length > 1) {
        startSelect.selectedIndex = 1;
      }

      populateWalkInEndTimes();
    }

    function populateWalkInEndTimes() {
      const selectedCourt = getSelectedWalkInCourtOption();
      const startSelect = document.getElementById("walk-in-start-time");
      const endSelect = document.getElementById("walk-in-end-time");

      endSelect.innerHTML = '<option value="">Select time-out</option>';
      endSelect.disabled = true;

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

      if (endSelect.options.length > 1) {
        endSelect.disabled = false;
        endSelect.selectedIndex = 1;
      } else {
        endSelect.innerHTML = '<option value="">No valid time-out options</option>';
      }

      updateWalkInSummary();
    }

    function buildHourlySlots(openTime, closeTime) {
      const slots = [];
      const normalizedOpen = normalizeTimeValue(openTime);
      const normalizedClose = normalizeTimeValue(closeTime);

      if (!normalizedOpen || !normalizedClose) {
        return slots;
      }

      let cursor = normalizedOpen;
      while (toMinutes(cursor) < toMinutes(normalizedClose)) {
        const next = addOneHour(cursor);
        if (!next || toMinutes(next) > toMinutes(normalizedClose)) {
          break;
        }

        slots.push(cursor);
        cursor = next;
      }

      return slots;
    }

    function normalizeTimeValue(timeStr) {
      if (!timeStr || typeof timeStr !== "string") {
        return "";
      }

      const parts = timeStr.trim().split(":");
      if (parts.length < 2) {
        return "";
      }

      const hour = Number(parts[0]);
      const minute = Number(parts[1]);
      const second = parts.length > 2 ? Number(parts[2]) : 0;

      if ([hour, minute, second].some((part) => Number.isNaN(part))) {
        return "";
      }

      return `${hour.toString().padStart(2, "0")}:${minute.toString().padStart(2, "0")}:${second.toString().padStart(2, "0")}`;
    }

    function toMinutes(timeStr) {
      const normalized = normalizeTimeValue(timeStr);
      if (!normalized) {
        return Number.NaN;
      }

      const [hour, minute] = normalized.split(":").map(Number);
      return (hour * 60) + minute;
    }

    function addOneHour(timeStr) {
      const normalized = normalizeTimeValue(timeStr);
      if (!normalized) {
        return "";
      }

      const minutes = toMinutes(normalized);
      if (Number.isNaN(minutes)) {
        return "";
      }

      const nextMinutes = minutes + 60;
      const nextHour = Math.floor(nextMinutes / 60);
      const minute = nextMinutes % 60;

      return `${nextHour.toString().padStart(2, "0")}:${minute.toString().padStart(2, "0")}:00`;
    }

    function formatTo12Hour(timeStr) {
      const normalized = normalizeTimeValue(timeStr);
      if (!normalized) {
        return "";
      }

      const [hour, minute] = normalized.split(":").map(Number);
      const ampm = hour >= 12 ? "PM" : "AM";
      const formattedHour = hour % 12 || 12;
      return `${formattedHour}:${minute.toString().padStart(2, "0")} ${ampm}`;
    }

    function isPastTimeSlot(timeStr) {
      const normalized = normalizeTimeValue(timeStr);
      const walkInDateInput = document.getElementById("walk-in-date");
      const walkInDate = walkInDateInput && walkInDateInput.value ? walkInDateInput.value : getClientToday();
      if (!normalized) {
        return false;
      }

      const now = new Date();
      const slotDateTime = new Date(`${walkInDate}T${normalized}`);
      return slotDateTime < now;
    }

    function getWalkInPricing(selectedCourt, hours) {
      const discountType = document.getElementById("walk-in-discount-type").value || "none";
      const regularRate = Number(selectedCourt?.dataset.rate || 0);
      const rawMemberRate = Number(selectedCourt?.dataset.memberRate || 0);
      const memberRate = rawMemberRate > 0 ? rawMemberRate : null;
      let appliedRate = regularRate;
      let appliedDiscountType = "none";
      let discountLabel = "None";
      let discountAmount = 0;

      if (hours > 0) {
        if (discountType === "member_rate" && memberRate !== null && memberRate < regularRate) {
          appliedRate = memberRate;
          appliedDiscountType = "member_rate";
          discountLabel = "Member rate";
          discountAmount = Number(((regularRate - memberRate) * hours).toFixed(2));
        } else if (discountType === "senior_pwd") {
          appliedDiscountType = "senior_pwd";
          discountLabel = "Senior/PWD 20%";
          discountAmount = Number((regularRate * hours * 0.20).toFixed(2));
        }
      }

      const subtotal = Number((regularRate * hours).toFixed(2));
      const total = Number(Math.max(0, subtotal - discountAmount).toFixed(2));

      return {
        discountType: appliedDiscountType,
        discountLabel,
        regularRate,
        appliedRate,
        subtotal,
        discountAmount,
        total
      };
    }

    function updateWalkInSummary() {
      const selectedCourt = getSelectedWalkInCourtOption();
      const startTime = document.getElementById("walk-in-start-time").value;
      const endTime = document.getElementById("walk-in-end-time").value;
      const hoursElement = document.getElementById("walk-in-hours");
      const rateElement = document.getElementById("walk-in-rate");
      const discountElement = document.getElementById("walk-in-discount");
      const totalElement = document.getElementById("walk-in-total");
      const rangeElement = document.getElementById("walk-in-range");

      if (!selectedCourt || !selectedCourt.value || !startTime || !endTime) {
        hoursElement.textContent = "0 hours";
        rateElement.textContent = "P0.00/hr";
        discountElement.textContent = "None";
        totalElement.textContent = "P0.00";
        if (selectedCourt && selectedCourt.value) {
          rangeElement.textContent = `${selectedCourt.dataset.courtName || selectedCourt.textContent.trim()} | Select a time-in and time-out.`;
        } else {
          rangeElement.textContent = "Select a court, time-in, and time-out.";
        }
        return;
      }

      const timeOutSlots = buildReservationTimeOptions(startTime, endTime);
      const hours = timeOutSlots.length;
      const pricing = getWalkInPricing(selectedCourt, hours);

      hoursElement.textContent = `${hours} hour${hours === 1 ? "" : "s"}`;
      rateElement.textContent = `${formatWalkInCurrency(pricing.appliedRate)}/hr`;
      discountElement.textContent = pricing.discountAmount > 0
        ? `${pricing.discountLabel} (-${formatWalkInCurrency(pricing.discountAmount)})`
        : "None";
      totalElement.textContent = formatWalkInCurrency(pricing.total);
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
        discount_type: document.getElementById("walk-in-discount-type").value,
        payment_method: document.getElementById("walk-in-payment-method").value,
        payment_status: document.getElementById("walk-in-payment-status").value,
        game_status: document.getElementById("walk-in-game-status").value,
        reservation_info: document.getElementById("walk-in-notes").value,
        client_local_now: buildClientLocalTimestamp(),
        client_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || ""
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
      <div class="admin-overline">Front Desk</div>
      <div class="admin-title">Create same-day walk-in reservations</div>
      <div class="admin-copy">Use this dedicated page for guests who book on arrival. Court hours, overlap checks, and payment details are still validated automatically.</div>
    </div>

    <div class="admin-stat-grid mb-5 md:grid-cols-3">
      <div class="admin-stat-card">
        <div class="admin-stat-label">Today's Bookings</div>
        <div class="admin-stat-value"><?= $todayBookings ?></div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Walk-ins Today</div>
        <div class="admin-stat-value"><?= $todayWalkIns ?></div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Pending Walk-ins</div>
        <div class="admin-stat-value"><?= $pendingWalkIns ?></div>
      </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.55fr)_minmax(320px,0.95fr)]">
      <section class="admin-card">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div class="max-w-2xl">
            <div class="admin-overline">Walk-in Form</div>
            <h2 class="mt-2 text-2xl font-bold text-slate-800">Capture an in-person reservation</h2>
            <p class="mt-2 text-sm text-slate-500">
              Record the guest, pick the court, then choose the time-in and time-out for today's rental.
            </p>
          </div>
          <a href="admin_reservations.php" class="admin-secondary-btn self-start">
            <i class="fas fa-receipt"></i>
            View Reservations
          </a>
        </div>

        <form id="walk-in-form" class="mt-6 grid gap-4 lg:grid-cols-2" onsubmit="submitWalkInReservation(event)">
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
                  data-member-rate="<?php echo htmlspecialchars((string) ($court['member_price'] ?? '')); ?>"
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
            <label for="walk-in-discount-type" class="admin-field-label">Rate / Discount</label>
            <select id="walk-in-discount-type" class="admin-select" required>
              <option value="none">Regular Rate</option>
              <option value="member_rate">Member Rate Unavailable</option>
              <option value="senior_pwd">Senior / PWD 20%</option>
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

          <div>
            <label for="walk-in-game-status" class="admin-field-label">Initial Game Status</label>
            <select id="walk-in-game-status" class="admin-select" required>
              <?php foreach (gameStatusSelectOptions() as $statusValue => $statusLabel): ?>
                <option value="<?= htmlspecialchars($statusValue) ?>" <?= $statusValue === GAME_STATUS_CHECKED_IN ? 'selected' : '' ?>>
                  <?= htmlspecialchars($statusLabel) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="lg:col-span-2">
            <label for="walk-in-notes" class="admin-field-label">Notes</label>
            <textarea id="walk-in-notes" rows="3" class="admin-textarea" placeholder="Optional front desk notes"></textarea>
          </div>
        </form>
      </section>

      <section class="admin-card">
        <div class="admin-overline">Live Summary</div>
        <h2 class="mt-2 text-2xl font-bold text-slate-800">Review before saving</h2>
        <p class="mt-2 text-sm text-slate-500">The total updates automatically from the selected court rate and booking duration.</p>

        <div class="admin-stat-grid mt-6 gap-3 sm:grid-cols-2 xl:grid-cols-1">
          <div class="admin-stat-card">
            <div class="admin-stat-label">Date</div>
            <div id="walk-in-summary-date" class="admin-stat-value text-base"><?= htmlspecialchars($today) ?></div>
          </div>
          <div class="admin-stat-card">
            <div class="admin-stat-label">Courts Ready</div>
            <div class="admin-stat-value text-base"><?= count($availableCourts) ?></div>
          </div>
          <div class="admin-stat-card">
            <div class="admin-stat-label">Hours</div>
            <div id="walk-in-hours" class="admin-stat-value text-base">0 hours</div>
          </div>
          <div class="admin-stat-card">
            <div class="admin-stat-label">Applied Rate</div>
            <div id="walk-in-rate" class="admin-stat-value text-base">P0.00/hr</div>
          </div>
          <div class="admin-stat-card">
            <div class="admin-stat-label">Discount</div>
            <div id="walk-in-discount" class="admin-stat-value text-base">None</div>
          </div>
          <div class="admin-stat-card">
            <div class="admin-stat-label">Total</div>
            <div id="walk-in-total" class="admin-stat-value text-base">P0.00</div>
          </div>
        </div>

        <div class="mt-5 rounded-2xl border border-teal-100 bg-teal-50 px-4 py-4">
          <div class="text-xs font-semibold uppercase tracking-[0.18em] text-teal-700">Selected Range</div>
          <p id="walk-in-range" class="mt-2 text-sm font-medium text-teal-900">Select a court, time-in, and time-out.</p>
        </div>

        <div class="mt-5 grid gap-3">
          <div class="rounded-2xl border border-slate-200 bg-white/90 px-4 py-4">
            <div class="text-sm font-semibold text-slate-800">Quick reminders</div>
            <ul class="mt-2 space-y-2 text-sm text-slate-500">
              <li>Walk-ins can only be created for today's date.</li>
              <li>Past or overlapping time ranges are blocked automatically.</li>
              <li>Use pending payment if the guest will settle later.</li>
            </ul>
          </div>
        </div>

        <div class="mt-5 flex justify-end">
          <button type="submit" form="walk-in-form" class="admin-primary-btn">
            <i class="fas fa-plus-circle"></i>
            Create Walk-in Reservation
          </button>
        </div>
      </section>
    </div>

    <section class="admin-card mt-6">
      <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="text-xl font-semibold text-slate-800">Recent Walk-ins</h2>
          <p class="text-sm text-slate-500">A quick view of the most recent front desk reservations recorded by staff.</p>
        </div>
        <div class="admin-pill">Visible records: <?= count($recentWalkIns) ?></div>
      </div>

      <?php if ($recentWalkIns === []): ?>
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/70 px-5 py-8 text-center text-sm text-slate-500">
          No walk-in reservations have been recorded yet.
        </div>
      <?php else: ?>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Customer</th>
                <th>Court</th>
                <th>Date</th>
                <th>Time</th>
                <th>Status</th>
                <th>Payment</th>
                <th>Created</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentWalkIns as $reservation): ?>
                <?php
                $paymentStatus = strtolower((string) ($reservation['payment_status'] ?? 'pending'));
                $paymentStatusClass = $paymentStatus === 'paid' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700';
                $paymentMethod = ucfirst(str_replace('-', ' ', (string) ($reservation['payment_method'] ?? 'n/a')));
                $gameStatus = normalizeGameStatus((string) ($reservation['game_status'] ?? GAME_STATUS_RESERVED));
                ?>
                <tr>
                  <td><?= (int) $reservation['id'] ?></td>
                  <td><?= htmlspecialchars(adminWalkInCustomer($reservation)) ?></td>
                  <td><?= htmlspecialchars((string) ($reservation['display_court'] ?? $reservation['court'])) ?></td>
                  <td><?= htmlspecialchars((string) $reservation['date']) ?></td>
                  <td><?= htmlspecialchars(adminWalkInTimeRange($reservation['time_slots'] ?? '')) ?></td>
                  <td>
                    <span class="admin-tag <?= htmlspecialchars(gameStatusBadgeClass($gameStatus)) ?>">
                      <?= htmlspecialchars(gameStatusLabel($gameStatus)) ?>
                    </span>
                  </td>
                  <td>
                    <div class="font-medium text-slate-800"><?= htmlspecialchars($paymentMethod) ?></div>
                    <span class="admin-tag mt-2 <?= $paymentStatusClass ?>"><?= htmlspecialchars(ucfirst($paymentStatus)) ?></span>
                  </td>
                  <td class="text-sm text-slate-600"><?= htmlspecialchars((string) $reservation['created_at']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>
</body>
</html>
