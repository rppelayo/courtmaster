<?php
declare(strict_types=1);

session_start();
require_once 'includes/db.php';
require_once 'includes/game_status.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') === 'user') {
    header('Location: ../index.html');
    exit;
}

function adminOverviewFormatTo12Hour(string $timeStr): string
{
    [$hour, $minute] = explode(':', $timeStr);
    $numericHour = (int) $hour;
    $ampm = $numericHour >= 12 ? 'PM' : 'AM';
    $hour12 = $numericHour % 12 === 0 ? 12 : $numericHour % 12;

    return $hour12 . ':' . $minute . ' ' . $ampm;
}

function adminOverviewCustomer(array $reservation): string
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

function adminOverviewNormalizeSlots(?string $timeSlots): array
{
    if (!is_string($timeSlots) || trim($timeSlots) === '') {
        return [];
    }

    $slots = array_values(array_filter(array_map('trim', explode(',', $timeSlots))));
    sort($slots);
    return $slots;
}

function adminOverviewHoursPlayed(?string $timeSlots): int
{
    return count(adminOverviewNormalizeSlots($timeSlots));
}

function adminOverviewTimeRange(?string $timeSlots): string
{
    $slots = adminOverviewNormalizeSlots($timeSlots);
    if ($slots === []) {
        return 'N/A';
    }

    $start = $slots[0];
    $end = $slots[count($slots) - 1];
    $endHour = DateTimeImmutable::createFromFormat('H:i:s', $end);
    if (!$endHour instanceof DateTimeImmutable) {
        return 'N/A';
    }

    return adminOverviewFormatTo12Hour($start) . ' - ' . adminOverviewFormatTo12Hour($endHour->modify('+1 hour')->format('H:i:s'));
}

function adminOverviewStartDateTime(string $date, ?string $timeSlots): ?DateTimeImmutable
{
    $slots = adminOverviewNormalizeSlots($timeSlots);
    if ($slots === []) {
        return null;
    }

    $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' ' . $slots[0]);
    return $start instanceof DateTimeImmutable ? $start : null;
}

function adminOverviewEndDateTime(string $date, ?string $timeSlots): ?DateTimeImmutable
{
    $slots = adminOverviewNormalizeSlots($timeSlots);
    if ($slots === []) {
        return null;
    }

    $endSlot = $slots[count($slots) - 1];
    $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' ' . $endSlot);
    return $end instanceof DateTimeImmutable ? $end->modify('+1 hour') : null;
}

function adminOverviewDateTimeLabel(?string $value): ?string
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($value))->format('M j, g:i A');
    } catch (Throwable) {
        return null;
    }
}

$ownerId = (int) $_SESSION['user_id'];
$userRole = (string) ($_SESSION['role'] ?? 'admin');
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$now = new DateTimeImmutable('now');
$nowTime = $now->format('H:i:s');

$courtParams = [];
$courtWhereClauses = ["type = 'pickleball'"];
if ($userRole === 'owner') {
    $courtWhereClauses[] = 'owner_id = ?';
    $courtParams[] = $ownerId;
}

$courtSql = sprintf(
    'SELECT id, name, price, member_price, open_time, close_time, owner_id
     FROM courts
     WHERE %s
     ORDER BY name',
    implode(' AND ', $courtWhereClauses)
);
$courtStatement = $pdo->prepare($courtSql);
$courtStatement->execute($courtParams);
$courts = $courtStatement->fetchAll(PDO::FETCH_ASSOC);

$reservationParams = [$today];
$reservationWhereClauses = ['r.date = ?', "c.type = 'pickleball'"];
$reservationJoinSql = "JOIN courts c ON r.court_id = c.id
                       LEFT JOIN reservation_slots rs ON rs.reservation_id = r.id";

if ($userRole === 'owner') {
    $reservationWhereClauses[] = 'c.owner_id = ?';
    $reservationParams[] = $ownerId;
}

$reservationWhereSql = 'WHERE ' . implode(' AND ', $reservationWhereClauses);
$reservationSql = "
    SELECT
        r.*,
        COALESCE(c.name, r.court) AS display_court,
        c.open_time AS court_open_time,
        c.close_time AS court_close_time,
        GROUP_CONCAT(DISTINCT rs.time ORDER BY rs.time) AS time_slots
    FROM reservations r
    {$reservationJoinSql}
    {$reservationWhereSql}
    GROUP BY r.id
    ORDER BY display_court ASC, time_slots ASC, r.created_at ASC
";
$reservationStatement = $pdo->prepare($reservationSql);
$reservationStatement->execute($reservationParams);
$todayReservations = $reservationStatement->fetchAll(PDO::FETCH_ASSOC);

$todayBookingCount = count($todayReservations);
$activeGames = [];
$todayIncome = 0.00;
$pendingIncome = 0.00;
$walkInIncome = 0.00;
$advanceIncome = 0.00;
$dailyHours = 0;
$upcomingToday = [];

foreach ($todayReservations as &$reservation) {
    $hoursPlayed = adminOverviewHoursPlayed($reservation['time_slots'] ?? '');
    $reservation['hours_played'] = $hoursPlayed;
    $reservation['customer_label'] = adminOverviewCustomer($reservation);
    $reservation['time_range'] = adminOverviewTimeRange($reservation['time_slots'] ?? '');
    $reservation['normalized_game_status'] = normalizeGameStatus((string) ($reservation['game_status'] ?? GAME_STATUS_RESERVED));
    $reservation['game_status_label'] = (int) ($reservation['is_admin_set'] ?? 0) === 1
        ? 'Admin Hold'
        : gameStatusLabel((string) $reservation['normalized_game_status']);
    $reservation['game_status_badge_class'] = (int) ($reservation['is_admin_set'] ?? 0) === 1
        ? 'bg-slate-200 text-slate-700'
        : gameStatusBadgeClass((string) $reservation['normalized_game_status']);
    $reservation['game_status_note'] = match ((string) $reservation['normalized_game_status']) {
        GAME_STATUS_CHECKED_IN => ($label = adminOverviewDateTimeLabel($reservation['checked_in_at'] ?? null)) ? 'Checked in ' . $label : 'Awaiting game start',
        GAME_STATUS_IN_PROGRESS => ($label = adminOverviewDateTimeLabel($reservation['in_progress_at'] ?? null)) ? 'Started ' . $label : 'Game in progress',
        GAME_STATUS_COMPLETED => ($label = adminOverviewDateTimeLabel($reservation['completed_at'] ?? null)) ? 'Completed ' . $label : 'Finished for the day',
        default => 'Awaiting check-in',
    };

    $startDateTime = adminOverviewStartDateTime((string) $reservation['date'], $reservation['time_slots'] ?? '');
    $endDateTime = adminOverviewEndDateTime((string) $reservation['date'], $reservation['time_slots'] ?? '');
    $reservation['start_time_label'] = $startDateTime instanceof DateTimeImmutable ? $startDateTime->format('H:i:s') : null;
    $reservation['end_time_label'] = $endDateTime instanceof DateTimeImmutable ? $endDateTime->format('H:i:s') : null;

    $payment = (float) ($reservation['payment'] ?? 0);
    $paymentStatus = strtolower((string) ($reservation['payment_status'] ?? 'pending'));

    if ($paymentStatus === 'paid') {
        $todayIncome += $payment;
    } else {
        $pendingIncome += $payment;
    }

    if (($reservation['booking_source'] ?? 'advance') === 'walk-in') {
        $walkInIncome += $payment;
    } else {
        $advanceIncome += $payment;
    }

    $dailyHours += $hoursPlayed;

    if ((int) ($reservation['is_admin_set'] ?? 0) !== 1
        && $startDateTime instanceof DateTimeImmutable
        && $endDateTime instanceof DateTimeImmutable
    ) {
        if (gameStatusIsActive((string) $reservation['normalized_game_status'])) {
            $activeGames[] = $reservation;
        } elseif ((string) $reservation['normalized_game_status'] !== GAME_STATUS_COMPLETED && $startDateTime > $now) {
            $upcomingToday[] = $reservation;
        }
    }
}
unset($reservation);

usort($activeGames, static function (array $first, array $second): int {
    $statusOrder = [
        GAME_STATUS_IN_PROGRESS => 0,
        GAME_STATUS_CHECKED_IN => 1,
        GAME_STATUS_RESERVED => 2,
        GAME_STATUS_COMPLETED => 3,
    ];

    $firstOrder = $statusOrder[(string) ($first['normalized_game_status'] ?? GAME_STATUS_RESERVED)] ?? 99;
    $secondOrder = $statusOrder[(string) ($second['normalized_game_status'] ?? GAME_STATUS_RESERVED)] ?? 99;

    if ($firstOrder !== $secondOrder) {
        return $firstOrder <=> $secondOrder;
    }

    return strcmp((string) ($first['end_time_label'] ?? ''), (string) ($second['end_time_label'] ?? ''));
});

usort($upcomingToday, static function (array $first, array $second): int {
    return strcmp((string) ($first['start_time_label'] ?? ''), (string) ($second['start_time_label'] ?? ''));
});

$courtSnapshots = [];
$availableCourtCount = 0;
$occupiedCourtCount = 0;

foreach ($courts as $court) {
    $courtReservations = array_values(array_filter(
        $todayReservations,
        static fn(array $reservation): bool => (int) ($reservation['court_id'] ?? 0) === (int) $court['id']
    ));

    $openTime = (string) ($court['open_time'] ?? '08:00:00');
    $closeTime = (string) ($court['close_time'] ?? '22:00:00');
    $openDateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $today . ' ' . $openTime);
    $closeDateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $today . ' ' . $closeTime);
    $openHours = ($openDateTime instanceof DateTimeImmutable && $closeDateTime instanceof DateTimeImmutable)
        ? max(0, (int) (($closeDateTime->getTimestamp() - $openDateTime->getTimestamp()) / 3600))
        : 0;
    $bookedHours = array_reduce(
        $courtReservations,
        static fn(int $sum, array $reservation): int => $sum + (int) ($reservation['hours_played'] ?? 0),
        0
    );

    $activeReservation = null;
    $currentReservedReservation = null;
    $nextReservation = null;
    foreach ($courtReservations as $reservation) {
        if ((int) ($reservation['is_admin_set'] ?? 0) === 1) {
            continue;
        }

        $startDateTime = adminOverviewStartDateTime((string) $reservation['date'], $reservation['time_slots'] ?? '');
        $endDateTime = adminOverviewEndDateTime((string) $reservation['date'], $reservation['time_slots'] ?? '');
        if ($startDateTime instanceof DateTimeImmutable && $endDateTime instanceof DateTimeImmutable) {
            if (gameStatusIsActive((string) ($reservation['normalized_game_status'] ?? GAME_STATUS_RESERVED))) {
                $activeReservation = $reservation;
                break;
            }

            if ($startDateTime <= $now
                && $endDateTime > $now
                && (string) ($reservation['normalized_game_status'] ?? GAME_STATUS_RESERVED) === GAME_STATUS_RESERVED
                && $currentReservedReservation === null
            ) {
                $currentReservedReservation = $reservation;
            }

            if ($startDateTime > $now && $nextReservation === null) {
                $nextReservation = $reservation;
            }
        }
    }

    $statusLabel = 'Available';
    $statusClass = 'bg-emerald-100 text-emerald-700';
    $statusCopy = 'Open and ready for the next booking.';

    if ($activeReservation !== null) {
        $statusLabel = 'Occupied';
        $statusClass = 'bg-amber-100 text-amber-700';
        $statusCopy = sprintf(
            '%s: %s until %s',
            gameStatusLabel((string) ($activeReservation['normalized_game_status'] ?? GAME_STATUS_RESERVED)),
            adminOverviewCustomer($activeReservation),
            adminOverviewFormatTo12Hour((string) ($activeReservation['end_time_label'] ?? $nowTime))
        );
        $occupiedCourtCount++;
    } elseif ($currentReservedReservation !== null) {
        $statusLabel = 'Reserved Now';
        $statusClass = 'bg-blue-100 text-blue-700';
        $statusCopy = sprintf(
            'Waiting for check-in: %s until %s',
            adminOverviewCustomer($currentReservedReservation),
            adminOverviewFormatTo12Hour((string) ($currentReservedReservation['end_time_label'] ?? $nowTime))
        );
    } elseif ($openDateTime instanceof DateTimeImmutable && $now < $openDateTime) {
        $statusLabel = 'Opens Later';
        $statusClass = 'bg-blue-100 text-blue-700';
        $statusCopy = 'Opens at ' . adminOverviewFormatTo12Hour($openTime) . '.';
        $availableCourtCount++;
    } elseif ($closeDateTime instanceof DateTimeImmutable && $now >= $closeDateTime) {
        $statusLabel = 'Closed';
        $statusClass = 'bg-slate-200 text-slate-700';
        $statusCopy = 'Closed for the day.';
    } elseif ($nextReservation !== null) {
        $statusCopy = sprintf(
            'Next: %s at %s',
            adminOverviewCustomer($nextReservation),
            adminOverviewFormatTo12Hour((string) ($nextReservation['start_time_label'] ?? $openTime))
        );
        $availableCourtCount++;
    } else {
        $availableCourtCount++;
    }

    $usagePercent = $openHours > 0 ? min(100, (int) round(($bookedHours / $openHours) * 100)) : 0;

    $courtSnapshots[] = [
        'id' => (int) $court['id'],
        'name' => (string) $court['name'],
        'status_label' => $statusLabel,
        'status_class' => $statusClass,
        'status_copy' => $statusCopy,
        'open_time' => $openTime,
        'close_time' => $closeTime,
        'booked_hours' => $bookedHours,
        'open_hours' => $openHours,
        'usage_percent' => $usagePercent,
        'reservation_count' => count($courtReservations),
    ];
}

$unpaidCount = count(array_filter(
    $todayReservations,
    static fn(array $reservation): bool => strtolower((string) ($reservation['payment_status'] ?? 'pending')) !== 'paid'
));

$lastUpdated = $now->format('M j, Y g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Pickleball Admin - Overview</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="styles/admin-theme.css">
  <script>
    async function updateReservationGameStatus(reservationId, gameStatus, triggerElement = null, fallbackValue = null) {
      if (triggerElement) {
        triggerElement.disabled = true;
      }

      try {
        const response = await fetch("api/update_game_status.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ id: reservationId, game_status: gameStatus })
        });

        const result = await response.json();
        if (!response.ok || !result.success) {
          throw new Error(result.message || "Failed to update game status.");
        }

        window.location.reload();
      } catch (error) {
        if (triggerElement && fallbackValue !== null && triggerElement.tagName === "SELECT") {
          triggerElement.value = fallbackValue;
        }

        if (triggerElement) {
          triggerElement.disabled = false;
        }

        alert(error.message || "Failed to update game status.");
      }
    }

    function handleOverviewStatusChange(selectElement, reservationId) {
      const previousStatus = selectElement.dataset.currentStatus || selectElement.value;
      updateReservationGameStatus(reservationId, selectElement.value, selectElement, previousStatus);
    }
  </script>
</head>
<body class="admin-theme-body admin-frame-body">
  <div class="admin-page-shell">
    <div class="admin-page-header">
      <div class="admin-overline">Operations Overview</div>
      <div class="admin-title">Run today's venue activity from one dashboard</div>
      <div class="admin-copy">Monitor today's bookings, see which courts are live right now, review paid versus pending income, and spot the next court openings without jumping between pages.</div>
    </div>

    <div class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
      <div class="flex flex-wrap gap-3">
        <div class="admin-pill">Today: <?= htmlspecialchars($today) ?></div>
        <div class="admin-pill"><?= htmlspecialchars(ucfirst($userRole)) ?> Access</div>
      </div>
      <div class="text-sm text-slate-500">Last updated <?= htmlspecialchars($lastUpdated) ?></div>
    </div>

    <div class="admin-stat-grid mb-6 md:grid-cols-2 xl:grid-cols-4">
      <div class="admin-stat-card">
        <div class="admin-stat-label">Today's Bookings</div>
        <div class="admin-stat-value"><?= $todayBookingCount ?></div>
        <div class="mt-2 text-sm text-slate-500"><?= $dailyHours ?> reserved hour<?= $dailyHours === 1 ? '' : 's' ?> today</div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Checked-in / Active</div>
        <div class="admin-stat-value"><?= count($activeGames) ?></div>
        <div class="mt-2 text-sm text-slate-500"><?= $occupiedCourtCount ?> court<?= $occupiedCourtCount === 1 ? '' : 's' ?> currently marked live</div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Daily Income</div>
        <div class="admin-stat-value">P<?= number_format($todayIncome, 2) ?></div>
        <div class="mt-2 text-sm text-slate-500">Pending: P<?= number_format($pendingIncome, 2) ?></div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Court Usage</div>
        <div class="admin-stat-value"><?= $availableCourtCount ?> / <?= count($courtSnapshots) ?></div>
        <div class="mt-2 text-sm text-slate-500"><?= $unpaidCount ?> unpaid booking<?= $unpaidCount === 1 ? '' : 's' ?> still open</div>
      </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(320px,0.8fr)]">
      <section class="admin-card">
        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 class="text-xl font-semibold text-slate-800">Today's Bookings</h2>
            <p class="text-sm text-slate-500">Every reservation scheduled for today, including walk-ins and advance bookings.</p>
          </div>
          <div class="admin-pill">Visible records: <?= $todayBookingCount ?></div>
        </div>

        <?php if ($todayReservations === []): ?>
          <div class="rounded-2xl border border-dashed border-slate-300 bg-white/70 px-5 py-8 text-center text-sm text-slate-500">
            No bookings are scheduled for today yet.
          </div>
        <?php else: ?>
          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>Court</th>
                  <th>Customer</th>
                  <th>Time</th>
                  <th>Type</th>
                  <th>Game Status</th>
                  <th>Payment</th>
                  <th>Total</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($todayReservations as $reservation): ?>
                  <?php
                  $sourceLabel = ($reservation['booking_source'] ?? 'advance') === 'walk-in' ? 'Walk-in' : 'Advance';
                  $sourceClass = $sourceLabel === 'Walk-in' ? 'bg-teal-100 text-teal-700' : 'bg-blue-100 text-blue-700';
                  $paymentStatus = strtolower((string) ($reservation['payment_status'] ?? 'pending'));
                  $paymentClass = $paymentStatus === 'paid' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700';
                  $paymentMethod = ucfirst(str_replace('-', ' ', (string) ($reservation['payment_method'] ?? 'n/a')));
                  ?>
                  <tr>
                    <td><?= htmlspecialchars((string) $reservation['display_court']) ?></td>
                    <td>
                      <div class="font-medium text-slate-800"><?= htmlspecialchars((string) $reservation['customer_label']) ?></div>
                      <div class="mt-1 text-sm text-slate-500"><?= htmlspecialchars((string) ($reservation['contact_number'] ?: $reservation['email'] ?: 'No contact on file')) ?></div>
                    </td>
                    <td>
                      <div class="font-medium text-slate-800"><?= htmlspecialchars((string) $reservation['time_range']) ?></div>
                      <div class="mt-1 text-sm text-slate-500"><?= (int) $reservation['hours_played'] ?> hour<?= (int) $reservation['hours_played'] === 1 ? '' : 's' ?></div>
                    </td>
                    <td>
                      <span class="admin-tag <?= $sourceClass ?>"><?= htmlspecialchars($sourceLabel) ?></span>
                    </td>
                    <td>
                      <div class="flex min-w-[11rem] flex-col gap-2">
                        <span class="admin-tag <?= htmlspecialchars((string) $reservation['game_status_badge_class']) ?>"><?= htmlspecialchars((string) $reservation['game_status_label']) ?></span>
                        <?php if ((int) ($reservation['is_admin_set'] ?? 0) !== 1): ?>
                          <select
                            class="admin-select !py-2 !text-sm"
                            data-current-status="<?= htmlspecialchars((string) $reservation['normalized_game_status']) ?>"
                            onchange="handleOverviewStatusChange(this, <?= (int) $reservation['id'] ?>)"
                          >
                            <?php foreach (gameStatusSelectOptions() as $statusValue => $statusLabel): ?>
                              <option value="<?= htmlspecialchars($statusValue) ?>" <?= (string) $reservation['normalized_game_status'] === $statusValue ? 'selected' : '' ?>>
                                <?= htmlspecialchars($statusLabel) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                          <div class="text-xs text-slate-500"><?= htmlspecialchars((string) $reservation['game_status_note']) ?></div>
                        <?php else: ?>
                          <div class="text-xs text-slate-500">Court availability block</div>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <div class="font-medium text-slate-800"><?= htmlspecialchars($paymentMethod) ?></div>
                      <span class="admin-tag mt-2 <?= $paymentClass ?>"><?= htmlspecialchars(ucfirst($paymentStatus)) ?></span>
                    </td>
                    <td class="font-semibold text-slate-800">P<?= number_format((float) ($reservation['payment'] ?? 0), 2) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <div class="space-y-6">
        <section class="admin-card">
          <div class="mb-4">
            <h2 class="text-xl font-semibold text-slate-800">Checked-in / Active Games</h2>
            <p class="mt-1 text-sm text-slate-500">Manual front-desk workflow for reservations that have checked in, started playing, or just finished.</p>
          </div>

          <?php if ($activeGames === []): ?>
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white/70 px-5 py-8 text-center text-sm text-slate-500">
              No reservations are marked checked-in or in progress right now.
            </div>
          <?php else: ?>
            <div class="grid gap-3">
              <?php foreach ($activeGames as $reservation): ?>
                <?php $nextAction = gameStatusNextAction((string) ($reservation['normalized_game_status'] ?? GAME_STATUS_RESERVED)); ?>
                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4">
                  <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                      <div class="text-sm font-semibold uppercase tracking-[0.16em] text-amber-700">Front Desk Workflow</div>
                      <div class="mt-2 text-lg font-semibold text-slate-800"><?= htmlspecialchars((string) $reservation['display_court']) ?></div>
                      <div class="mt-1 text-sm text-slate-600"><?= htmlspecialchars((string) $reservation['customer_label']) ?></div>
                    </div>
                    <div class="flex flex-col items-start gap-2 sm:items-end">
                      <div class="admin-tag <?= htmlspecialchars((string) $reservation['game_status_badge_class']) ?>"><?= htmlspecialchars((string) $reservation['game_status_label']) ?></div>
                      <div class="admin-tag bg-amber-100 text-amber-700"><?= htmlspecialchars((string) $reservation['time_range']) ?></div>
                    </div>
                  </div>
                  <div class="mt-3 flex flex-col gap-3 text-sm text-slate-600 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                      <div>Ends at <?= htmlspecialchars(adminOverviewFormatTo12Hour((string) ($reservation['end_time_label'] ?? $nowTime))) ?></div>
                      <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars((string) $reservation['game_status_note']) ?></div>
                    </div>
                    <?php if (is_array($nextAction)): ?>
                      <button
                        type="button"
                        class="admin-secondary-btn !px-4 !py-2"
                        onclick="updateReservationGameStatus(<?= (int) $reservation['id'] ?>, '<?= htmlspecialchars($nextAction['status']) ?>', this)"
                      >
                        <?= htmlspecialchars($nextAction['label']) ?>
                      </button>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <section class="admin-card">
          <div class="mb-4">
            <h2 class="text-xl font-semibold text-slate-800">Daily Income Snapshot</h2>
            <p class="mt-1 text-sm text-slate-500">Paid totals versus outstanding collections for today.</p>
          </div>

          <div class="admin-stat-grid gap-3 sm:grid-cols-2">
            <div class="admin-stat-card bg-emerald-50/70">
              <div class="admin-stat-label">Paid Today</div>
              <div class="admin-stat-value">P<?= number_format($todayIncome, 2) ?></div>
            </div>
            <div class="admin-stat-card bg-amber-50/70">
              <div class="admin-stat-label">Pending Today</div>
              <div class="admin-stat-value">P<?= number_format($pendingIncome, 2) ?></div>
            </div>
            <div class="admin-stat-card">
              <div class="admin-stat-label">Walk-in Income</div>
              <div class="admin-stat-value">P<?= number_format($walkInIncome, 2) ?></div>
            </div>
            <div class="admin-stat-card">
              <div class="admin-stat-label">Advance Income</div>
              <div class="admin-stat-value">P<?= number_format($advanceIncome, 2) ?></div>
            </div>
          </div>
        </section>
      </div>
    </div>

    <section class="admin-card mt-6">
      <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="text-xl font-semibold text-slate-800">Court Usage Snapshot</h2>
          <p class="text-sm text-slate-500">See which courts are free, occupied, opening later, or already closed for the day.</p>
        </div>
        <div class="admin-pill">Courts tracked: <?= count($courtSnapshots) ?></div>
      </div>

      <div class="grid gap-4 lg:grid-cols-3">
        <?php foreach ($courtSnapshots as $snapshot): ?>
          <div class="rounded-2xl border border-slate-200 bg-white/90 px-5 py-5 shadow-sm">
            <div class="flex items-start justify-between gap-4">
              <div>
                <div class="text-lg font-semibold text-slate-800"><?= htmlspecialchars($snapshot['name']) ?></div>
                <div class="mt-1 text-sm text-slate-500">
                  <?= htmlspecialchars(adminOverviewFormatTo12Hour($snapshot['open_time'])) ?> - <?= htmlspecialchars(adminOverviewFormatTo12Hour($snapshot['close_time'])) ?>
                </div>
              </div>
              <span class="admin-tag <?= htmlspecialchars($snapshot['status_class']) ?>"><?= htmlspecialchars($snapshot['status_label']) ?></span>
            </div>

            <div class="mt-4 text-sm text-slate-600"><?= htmlspecialchars($snapshot['status_copy']) ?></div>

            <div class="mt-4">
              <div class="flex items-center justify-between text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                <span>Usage Today</span>
                <span><?= $snapshot['usage_percent'] ?>%</span>
              </div>
              <div class="mt-2 h-2 rounded-full bg-slate-200">
                <div class="h-2 rounded-full bg-teal-600" style="width: <?= $snapshot['usage_percent'] ?>%"></div>
              </div>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
              <div class="rounded-2xl bg-slate-50 px-4 py-3">
                <div class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Bookings</div>
                <div class="mt-2 text-base font-semibold text-slate-800"><?= $snapshot['reservation_count'] ?></div>
              </div>
              <div class="rounded-2xl bg-slate-50 px-4 py-3">
                <div class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Booked Hours</div>
                <div class="mt-2 text-base font-semibold text-slate-800"><?= $snapshot['booked_hours'] ?> / <?= $snapshot['open_hours'] ?></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="admin-card mt-6">
      <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="text-xl font-semibold text-slate-800">Next Up Today</h2>
          <p class="text-sm text-slate-500">Upcoming bookings still left to start later today.</p>
        </div>
        <div class="admin-pill">Later today: <?= count($upcomingToday) ?></div>
      </div>

      <?php if ($upcomingToday === []): ?>
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/70 px-5 py-8 text-center text-sm text-slate-500">
          No more upcoming bookings for later today.
        </div>
      <?php else: ?>
        <div class="grid gap-3 lg:grid-cols-2">
          <?php foreach ($upcomingToday as $reservation): ?>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
              <div class="flex items-start justify-between gap-4">
                <div>
                  <div class="text-sm font-semibold uppercase tracking-[0.16em] text-teal-700"><?= htmlspecialchars((string) $reservation['display_court']) ?></div>
                  <div class="mt-2 text-lg font-semibold text-slate-800"><?= htmlspecialchars((string) $reservation['customer_label']) ?></div>
                </div>
                <div class="flex flex-col items-end gap-2">
                  <span class="admin-tag bg-blue-100 text-blue-700"><?= htmlspecialchars((string) $reservation['time_range']) ?></span>
                  <span class="admin-tag <?= htmlspecialchars((string) $reservation['game_status_badge_class']) ?>"><?= htmlspecialchars((string) $reservation['game_status_label']) ?></span>
                </div>
              </div>
              <div class="mt-3 text-sm text-slate-600">
                <?= htmlspecialchars(ucfirst((string) ($reservation['booking_source'] ?? 'advance'))) ?> booking | <?= htmlspecialchars(ucfirst((string) ($reservation['payment_status'] ?? 'pending'))) ?> payment
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
</body>
</html>
