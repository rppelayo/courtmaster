<?php
declare(strict_types=1);

session_start();
require_once 'includes/db.php';
require_once 'includes/game_status.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') === 'user') {
    header('Location: ../login.html');
    exit;
}

function adminLayoutFormatTo12Hour(string $timeStr): string
{
    [$hour, $minute] = explode(':', $timeStr);
    $numericHour = (int) $hour;
    $ampm = $numericHour >= 12 ? 'PM' : 'AM';
    $hour12 = $numericHour % 12 === 0 ? 12 : $numericHour % 12;

    return $hour12 . ':' . $minute . ' ' . $ampm;
}

function adminLayoutNormalizeSlots(?string $timeSlots): array
{
    if (!is_string($timeSlots) || trim($timeSlots) === '') {
        return [];
    }

    $slots = array_values(array_filter(array_map('trim', explode(',', $timeSlots))));
    sort($slots);
    return $slots;
}

function adminLayoutHoursPlayed(?string $timeSlots): int
{
    return count(adminLayoutNormalizeSlots($timeSlots));
}

function adminLayoutTimeRange(?string $timeSlots): string
{
    $slots = adminLayoutNormalizeSlots($timeSlots);
    if ($slots === []) {
        return 'N/A';
    }

    $start = $slots[0];
    $end = $slots[count($slots) - 1];
    $endHour = DateTimeImmutable::createFromFormat('H:i:s', $end);
    if (!$endHour instanceof DateTimeImmutable) {
        return 'N/A';
    }

    return adminLayoutFormatTo12Hour($start) . ' - ' . adminLayoutFormatTo12Hour($endHour->modify('+1 hour')->format('H:i:s'));
}

function adminLayoutStartDateTime(string $date, ?string $timeSlots): ?DateTimeImmutable
{
    $slots = adminLayoutNormalizeSlots($timeSlots);
    if ($slots === []) {
        return null;
    }

    $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' ' . $slots[0]);
    return $start instanceof DateTimeImmutable ? $start : null;
}

function adminLayoutEndDateTime(string $date, ?string $timeSlots): ?DateTimeImmutable
{
    $slots = adminLayoutNormalizeSlots($timeSlots);
    if ($slots === []) {
        return null;
    }

    $endSlot = $slots[count($slots) - 1];
    $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' ' . $endSlot);
    return $end instanceof DateTimeImmutable ? $end->modify('+1 hour') : null;
}

function adminLayoutCustomer(array $reservation): string
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

function adminLayoutStatusConfig(string $statusKey): array
{
    return match ($statusKey) {
        'checked_in' => [
            'tile_class' => 'border-blue-200 bg-gradient-to-br from-blue-50 via-white to-blue-50/70',
            'badge_class' => 'bg-blue-100 text-blue-700',
            'accent_class' => 'bg-blue-500',
        ],
        'in_progress' => [
            'tile_class' => 'border-amber-200 bg-gradient-to-br from-amber-50 via-white to-amber-50/70',
            'badge_class' => 'bg-amber-100 text-amber-700',
            'accent_class' => 'bg-amber-500',
        ],
        'reserved' => [
            'tile_class' => 'border-sky-200 bg-gradient-to-br from-sky-50 via-white to-sky-50/70',
            'badge_class' => 'bg-sky-100 text-sky-700',
            'accent_class' => 'bg-sky-500',
        ],
        'admin_hold' => [
            'tile_class' => 'border-slate-300 bg-gradient-to-br from-slate-100 via-white to-slate-50',
            'badge_class' => 'bg-slate-200 text-slate-700',
            'accent_class' => 'bg-slate-500',
        ],
        'completed' => [
            'tile_class' => 'border-emerald-200 bg-gradient-to-br from-emerald-50 via-white to-emerald-50/70',
            'badge_class' => 'bg-emerald-100 text-emerald-700',
            'accent_class' => 'bg-emerald-500',
        ],
        'closed' => [
            'tile_class' => 'border-slate-300 bg-gradient-to-br from-slate-100 via-white to-slate-50',
            'badge_class' => 'bg-slate-200 text-slate-700',
            'accent_class' => 'bg-slate-500',
        ],
        'opens_later' => [
            'tile_class' => 'border-violet-200 bg-gradient-to-br from-violet-50 via-white to-violet-50/70',
            'badge_class' => 'bg-violet-100 text-violet-700',
            'accent_class' => 'bg-violet-500',
        ],
        default => [
            'tile_class' => 'border-emerald-200 bg-gradient-to-br from-emerald-50 via-white to-emerald-50/70',
            'badge_class' => 'bg-emerald-100 text-emerald-700',
            'accent_class' => 'bg-emerald-500',
        ],
    };
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$now = new DateTimeImmutable('now');
$nowTime = $now->format('H:i:s');

$courtStatement = $pdo->query(
    "SELECT id, name, location, price, open_time, close_time, layout_row, layout_column, image_path
     FROM courts
     WHERE type = 'pickleball'
     ORDER BY COALESCE(layout_row, 9999), COALESCE(layout_column, 9999), name"
);
$courts = $courtStatement->fetchAll(PDO::FETCH_ASSOC);

$reservationStatement = $pdo->prepare(
    "SELECT
        r.*,
        COALESCE(c.name, r.court) AS display_court,
        GROUP_CONCAT(DISTINCT rs.time ORDER BY rs.time) AS time_slots
     FROM reservations r
     JOIN courts c ON r.court_id = c.id
     LEFT JOIN reservation_slots rs ON rs.reservation_id = r.id
     WHERE r.date = ?
       AND c.type = 'pickleball'
     GROUP BY r.id
     ORDER BY COALESCE(c.name, r.court) ASC, time_slots ASC, r.created_at ASC"
);
$reservationStatement->execute([$today]);
$todayReservations = $reservationStatement->fetchAll(PDO::FETCH_ASSOC);

$reservationsByCourt = [];
foreach ($todayReservations as $reservation) {
    $courtId = (int) ($reservation['court_id'] ?? 0);
    if ($courtId <= 0) {
        continue;
    }

    $reservation['hours_played'] = adminLayoutHoursPlayed($reservation['time_slots'] ?? '');
    $reservation['time_range'] = adminLayoutTimeRange($reservation['time_slots'] ?? '');
    $reservation['customer_label'] = adminLayoutCustomer($reservation);
    $reservation['normalized_game_status'] = normalizeGameStatus((string) ($reservation['game_status'] ?? GAME_STATUS_RESERVED));
    $reservation['start_at'] = adminLayoutStartDateTime((string) $reservation['date'], $reservation['time_slots'] ?? '');
    $reservation['end_at'] = adminLayoutEndDateTime((string) $reservation['date'], $reservation['time_slots'] ?? '');
    $reservationsByCourt[$courtId][] = $reservation;
}

foreach ($reservationsByCourt as &$reservationGroup) {
    usort($reservationGroup, static function (array $first, array $second): int {
        $firstStart = $first['start_at'] instanceof DateTimeImmutable ? $first['start_at']->getTimestamp() : PHP_INT_MAX;
        $secondStart = $second['start_at'] instanceof DateTimeImmutable ? $second['start_at']->getTimestamp() : PHP_INT_MAX;
        return $firstStart <=> $secondStart;
    });
}
unset($reservationGroup);

$courtTiles = [];
$layoutColumns = 1;
$activeCount = 0;
$reservedCount = 0;
$availableCount = 0;

foreach ($courts as $index => $court) {
    $courtId = (int) ($court['id'] ?? 0);
    $layoutRow = max(1, (int) ($court['layout_row'] ?? 1));
    $layoutColumn = max(1, (int) ($court['layout_column'] ?? ($index + 1)));
    $layoutColumns = max($layoutColumns, $layoutColumn);
    $courtReservations = $reservationsByCourt[$courtId] ?? [];

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
    $currentAdminHoldReservation = null;
    $nextReservation = null;
    $lastCompletedReservation = null;

    foreach ($courtReservations as $reservation) {
        $startAt = $reservation['start_at'] instanceof DateTimeImmutable ? $reservation['start_at'] : null;
        $endAt = $reservation['end_at'] instanceof DateTimeImmutable ? $reservation['end_at'] : null;
        if (!$startAt || !$endAt) {
            continue;
        }

        $isAdminSet = (int) ($reservation['is_admin_set'] ?? 0) === 1;
        $status = (string) ($reservation['normalized_game_status'] ?? GAME_STATUS_RESERVED);

        if ($isAdminSet && $startAt <= $now && $endAt > $now && $currentAdminHoldReservation === null) {
            $currentAdminHoldReservation = $reservation;
            continue;
        }

        if (gameStatusIsActive($status)) {
            $activeReservation = $reservation;
            break;
        }

        if ($status === GAME_STATUS_COMPLETED) {
            $lastCompletedReservation = $reservation;
        }

        if (!$isAdminSet
            && $startAt <= $now
            && $endAt > $now
            && $status === GAME_STATUS_RESERVED
            && $currentReservedReservation === null
        ) {
            $currentReservedReservation = $reservation;
        }

        if ($startAt > $now && $nextReservation === null) {
            $nextReservation = $reservation;
        }
    }

    $statusKey = 'available';
    $statusLabel = 'Available';
    $statusCopy = 'Open and ready for the next players.';
    $secondaryCopy = 'No active booking on the floor right now.';

    if ($activeReservation !== null) {
        $statusKey = (string) ($activeReservation['normalized_game_status'] ?? GAME_STATUS_IN_PROGRESS);
        $statusLabel = gameStatusLabel($statusKey);
        $statusCopy = $activeReservation['customer_label'] . ' • ' . $activeReservation['time_range'];
        $secondaryCopy = 'Live court status from today\'s reservation flow.';
        $activeCount++;
    } elseif ($currentAdminHoldReservation !== null) {
        $statusKey = 'admin_hold';
        $statusLabel = 'Admin Hold';
        $statusCopy = 'Held by staff • ' . $currentAdminHoldReservation['time_range'];
        $secondaryCopy = 'This court is currently blocked from regular booking.';
        $reservedCount++;
    } elseif ($currentReservedReservation !== null) {
        $statusKey = 'reserved';
        $statusLabel = 'Reserved';
        $statusCopy = $currentReservedReservation['customer_label'] . ' • ' . $currentReservedReservation['time_range'];
        $secondaryCopy = 'Reserved now and waiting for check-in.';
        $reservedCount++;
    } elseif ($openDateTime instanceof DateTimeImmutable && $now < $openDateTime) {
        $statusKey = 'opens_later';
        $statusLabel = 'Opens Later';
        $statusCopy = 'Opens at ' . adminLayoutFormatTo12Hour($openTime);
        $secondaryCopy = 'No booking can start before court opening time.';
        $availableCount++;
    } elseif ($closeDateTime instanceof DateTimeImmutable && $now >= $closeDateTime) {
        $statusKey = 'closed';
        $statusLabel = 'Closed';
        $statusCopy = 'Closed for the day';
        $secondaryCopy = 'Court hours ended at ' . adminLayoutFormatTo12Hour($closeTime) . '.';
    } elseif ($lastCompletedReservation !== null && $nextReservation === null) {
        $statusKey = 'completed';
        $statusLabel = 'Completed';
        $statusCopy = $lastCompletedReservation['customer_label'] . ' finished earlier';
        $secondaryCopy = 'Court is free again after the completed session.';
        $availableCount++;
    } elseif ($nextReservation !== null) {
        $statusKey = 'available';
        $statusLabel = 'Available';
        $statusCopy = 'Free until ' . adminLayoutFormatTo12Hour($nextReservation['start_at']->format('H:i:s'));
        $secondaryCopy = 'Next booking: ' . $nextReservation['customer_label'] . ' • ' . $nextReservation['time_range'];
        $availableCount++;
    } else {
        $availableCount++;
    }

    $usagePercent = $openHours > 0 ? min(100, (int) round(($bookedHours / $openHours) * 100)) : 0;
    $statusVisual = adminLayoutStatusConfig($statusKey);

    $courtTiles[] = [
        'id' => $courtId,
        'name' => (string) ($court['name'] ?? 'Court'),
        'location' => trim((string) ($court['location'] ?? '')),
        'open_time' => $openTime,
        'close_time' => $closeTime,
        'layout_row' => $layoutRow,
        'layout_column' => $layoutColumn,
        'status_label' => $statusLabel,
        'status_copy' => $statusCopy,
        'secondary_copy' => $secondaryCopy,
        'status_key' => $statusKey,
        'status_visual' => $statusVisual,
        'reservation_count' => count($courtReservations),
        'booked_hours' => $bookedHours,
        'open_hours' => $openHours,
        'usage_percent' => $usagePercent,
        'next_time_range' => $nextReservation['time_range'] ?? null,
        'image_path' => trim((string) ($court['image_path'] ?? '')),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Pickleball Admin - Court Layout</title>
  <link rel="stylesheet" href="styles/admin-theme.css">
  <style>
    .court-layout-grid {
      display: grid;
      gap: 16px;
    }

    .court-layout-tile {
      position: relative;
      overflow: hidden;
      border-width: 1px;
      border-style: solid;
      border-radius: 24px;
      min-width: 0;
      box-shadow: var(--admin-shadow-soft);
    }

    .court-layout-accent {
      position: absolute;
      inset: 0 auto 0 0;
      width: 7px;
      border-radius: 24px 0 0 24px;
    }

    .court-layout-image {
      width: 100%;
      height: 108px;
      object-fit: cover;
      border-radius: 18px;
    }

    .court-layout-placeholder {
      height: 108px;
      border: 1px dashed rgba(15, 118, 110, 0.24);
      border-radius: 18px;
      background: linear-gradient(180deg, #fbfffd 0%, #eef7f4 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--admin-muted);
      font-size: 0.84rem;
      text-align: center;
      padding: 0 16px;
    }

    .court-layout-meta {
      display: grid;
      gap: 10px;
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .court-layout-meta-card {
      border: 1px solid rgba(207, 224, 216, 0.9);
      border-radius: 18px;
      background: rgba(255, 255, 255, 0.88);
      padding: 12px 14px;
    }

    @media (max-width: 900px) {
      .court-layout-grid {
        grid-template-columns: 1fr !important;
      }

      .court-layout-tile {
        grid-column: auto !important;
        grid-row: auto !important;
      }
    }
  </style>
</head>
<body class="admin-theme-body admin-frame-body">
  <div class="admin-page-shell">
    <div class="admin-page-header">
      <div class="admin-overline">Court Layout</div>
      <div class="admin-title">View the venue like the real court floor</div>
      <div class="admin-copy">
        This layout uses each court's row and column from Court Management so staff can see adjacent courts in the same arrangement they see inside the venue.
      </div>
    </div>

    <div class="admin-stat-grid mb-5 md:grid-cols-4">
      <div class="admin-stat-card">
        <div class="admin-stat-label">Total Courts</div>
        <div class="admin-stat-value"><?= count($courtTiles) ?></div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Active Now</div>
        <div class="admin-stat-value"><?= $activeCount ?></div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Reserved / Held</div>
        <div class="admin-stat-value"><?= $reservedCount ?></div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Available</div>
        <div class="admin-stat-value"><?= $availableCount ?></div>
      </div>
    </div>

    <div class="admin-filter-bar mb-5 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
      <div>
        <div class="text-sm font-semibold text-slate-800">Today's venue map</div>
        <p class="mt-1 text-sm text-slate-500">Statuses reflect today's reservations, check-ins, and in-progress games.</p>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <span class="admin-tag bg-emerald-100 text-emerald-700">Available</span>
        <span class="admin-tag bg-sky-100 text-sky-700">Reserved</span>
        <span class="admin-tag bg-blue-100 text-blue-700">Checked-in</span>
        <span class="admin-tag bg-amber-100 text-amber-700">In Progress</span>
        <span class="admin-tag bg-slate-200 text-slate-700">Hold / Closed</span>
        <button type="button" class="admin-secondary-btn" onclick="openAdminPage('admin_courts.php')">Manage Court Positions</button>
        <button type="button" class="admin-primary-btn" onclick="window.location.reload()">Refresh Layout</button>
      </div>
    </div>

    <?php if ($courtTiles === []): ?>
      <div class="admin-card text-center">
        <div class="text-lg font-semibold text-slate-800">No courts found yet.</div>
        <p class="mt-2 text-sm text-slate-500">Create your venue courts first, then come back here to view the live floor layout.</p>
      </div>
    <?php else: ?>
      <div class="admin-card">
        <div class="mb-5 flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
          <div>
            <div class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">Venue Map</div>
            <div class="mt-1 text-lg font-semibold text-slate-800">Courts arranged by their real position</div>
          </div>
          <div class="text-sm text-slate-500">Adjust row and column in the Courts page whenever the layout changes.</div>
        </div>

        <div class="court-layout-grid" style="grid-template-columns: repeat(<?= max(1, $layoutColumns) ?>, minmax(0, 1fr));">
          <?php foreach ($courtTiles as $tile): ?>
            <section
              class="court-layout-tile <?= htmlspecialchars((string) $tile['status_visual']['tile_class']) ?>"
              style="grid-column: <?= (int) $tile['layout_column'] ?>; grid-row: <?= (int) $tile['layout_row'] ?>;"
            >
              <div class="court-layout-accent <?= htmlspecialchars((string) $tile['status_visual']['accent_class']) ?>"></div>
              <div class="p-5 pl-6">
                <div class="flex flex-col gap-4">
                  <?php if ($tile['image_path'] !== ''): ?>
                    <img
                      src="images/courts/<?= htmlspecialchars($tile['image_path']) ?>"
                      alt="<?= htmlspecialchars($tile['name']) ?> image"
                      class="court-layout-image"
                    />
                  <?php else: ?>
                    <div class="court-layout-placeholder">Add a court image in Court Management to show a venue-specific preview here.</div>
                  <?php endif; ?>

                  <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                      <div class="text-xl font-semibold text-slate-800"><?= htmlspecialchars($tile['name']) ?></div>
                      <div class="mt-1 text-sm text-slate-500">
                        <?= htmlspecialchars($tile['location'] !== '' ? $tile['location'] : 'Venue court') ?>
                      </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                      <span class="admin-tag <?= htmlspecialchars((string) $tile['status_visual']['badge_class']) ?>"><?= htmlspecialchars($tile['status_label']) ?></span>
                      <span class="admin-tag bg-white text-slate-600">Row <?= (int) $tile['layout_row'] ?> · Col <?= (int) $tile['layout_column'] ?></span>
                    </div>
                  </div>

                  <div>
                    <div class="text-base font-semibold text-slate-800"><?= htmlspecialchars($tile['status_copy']) ?></div>
                    <div class="mt-2 text-sm text-slate-500"><?= htmlspecialchars($tile['secondary_copy']) ?></div>
                  </div>

                  <div class="court-layout-meta">
                    <div class="court-layout-meta-card">
                      <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Court Hours</div>
                      <div class="mt-2 text-sm font-semibold text-slate-800">
                        <?= htmlspecialchars(adminLayoutFormatTo12Hour((string) $tile['open_time'])) ?> - <?= htmlspecialchars(adminLayoutFormatTo12Hour((string) $tile['close_time'])) ?>
                      </div>
                    </div>
                    <div class="court-layout-meta-card">
                      <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Today</div>
                      <div class="mt-2 text-sm font-semibold text-slate-800">
                        <?= (int) $tile['reservation_count'] ?> booking<?= (int) $tile['reservation_count'] === 1 ? '' : 's' ?> · <?= (int) $tile['booked_hours'] ?>h booked
                      </div>
                    </div>
                    <div class="court-layout-meta-card">
                      <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Usage</div>
                      <div class="mt-2 text-sm font-semibold text-slate-800"><?= (int) $tile['usage_percent'] ?>% of daily court hours</div>
                    </div>
                    <div class="court-layout-meta-card">
                      <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Next Slot</div>
                      <div class="mt-2 text-sm font-semibold text-slate-800">
                        <?= htmlspecialchars($tile['next_time_range'] !== null ? (string) $tile['next_time_range'] : 'Open right now') ?>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </section>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <script>
    function openAdminPage(page) {
      if (window.parent && typeof window.parent.loadPage === "function") {
        window.parent.loadPage(page);
        return;
      }

      window.location.href = page;
    }
  </script>
</body>
</html>
