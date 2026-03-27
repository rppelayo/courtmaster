<?php
declare(strict_types=1);

session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') === 'user') {
    header('Location: ../index.html');
    exit;
}

function reportsFormatCurrency(float $value): string
{
    return 'P' . number_format($value, 2);
}

function reportsFormat12Hour(string $timeStr): string
{
    [$hour, $minute] = explode(':', $timeStr);
    $numericHour = (int) $hour;
    $ampm = $numericHour >= 12 ? 'PM' : 'AM';
    $hour12 = $numericHour % 12 === 0 ? 12 : $numericHour % 12;

    return $hour12 . ':' . $minute . ' ' . $ampm;
}

function reportsSanitizePeriod(string $value): string
{
    $allowed = ['today', 'this_month', 'last_30_days', 'year_to_date', 'custom'];
    return in_array($value, $allowed, true) ? $value : 'this_month';
}

function reportsSanitizeGranularity(string $value): string
{
    return in_array($value, ['daily', 'monthly'], true) ? $value : 'daily';
}

function reportsResolveDateRange(string $period, ?string $startDate, ?string $endDate): array
{
    $today = new DateTimeImmutable('today');

    $normalize = static function (?string $value): ?DateTimeImmutable {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', trim($value));
        return $parsed instanceof DateTimeImmutable ? $parsed : null;
    };

    return match ($period) {
        'today' => [$today, $today],
        'last_30_days' => [$today->modify('-29 days'), $today],
        'year_to_date' => [$today->modify('first day of january'), $today],
        'custom' => [
            $normalize($startDate) ?? $today->modify('first day of this month'),
            $normalize($endDate) ?? $today->modify('last day of this month'),
        ],
        default => [$today->modify('first day of this month'), $today->modify('last day of this month')],
    };
}

function reportsDateCount(DateTimeImmutable $startDate, DateTimeImmutable $endDate): int
{
    return ((int) $startDate->diff($endDate)->format('%a')) + 1;
}

function reportsTimelineKeys(DateTimeImmutable $startDate, DateTimeImmutable $endDate, string $granularity): array
{
    $keys = [];

    if ($granularity === 'monthly') {
        $cursor = $startDate->modify('first day of this month');
        $endMarker = $endDate->modify('first day of this month');

        while ($cursor <= $endMarker) {
            $keys[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }

        return $keys;
    }

    $cursor = $startDate;
    while ($cursor <= $endDate) {
        $keys[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }

    return $keys;
}

function reportsSummaryKey(string $date, string $granularity): string
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if (!$parsed instanceof DateTimeImmutable) {
        return $date;
    }

    return $granularity === 'monthly' ? $parsed->format('Y-m') : $parsed->format('Y-m-d');
}

function reportsSummaryLabel(string $key, string $granularity): string
{
    if ($granularity === 'monthly') {
        $parsed = DateTimeImmutable::createFromFormat('Y-m', $key);
        return $parsed instanceof DateTimeImmutable ? $parsed->format('M Y') : $key;
    }

    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $key);
    return $parsed instanceof DateTimeImmutable ? $parsed->format('M j, Y') : $key;
}

function reportsWeekdayLabel(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $parsed instanceof DateTimeImmutable ? $parsed->format('l') : $date;
}

function reportsInitializeSummaryRows(array $timelineKeys, string $granularity): array
{
    $rows = [];

    foreach ($timelineKeys as $key) {
        $rows[$key] = [
            'key' => $key,
            'label' => reportsSummaryLabel($key, $granularity),
            'bookings' => 0,
            'hours' => 0,
            'paid' => 0.0,
            'pending' => 0.0,
            'walk_ins' => 0,
            'advance' => 0,
        ];
    }

    return $rows;
}

$userRole = (string) ($_SESSION['role'] ?? 'admin');
$period = reportsSanitizePeriod((string) ($_GET['period'] ?? 'this_month'));
$granularity = reportsSanitizeGranularity((string) ($_GET['granularity'] ?? 'daily'));
$requestedCourtId = max(0, (int) ($_GET['court_id'] ?? 0));
$export = strtolower((string) ($_GET['export'] ?? '')) === 'csv';

[$startDateObject, $endDateObject] = reportsResolveDateRange(
    $period,
    (string) ($_GET['start_date'] ?? ''),
    (string) ($_GET['end_date'] ?? '')
);

if ($startDateObject > $endDateObject) {
    [$startDateObject, $endDateObject] = [$endDateObject, $startDateObject];
}

$startDate = $startDateObject->format('Y-m-d');
$endDate = $endDateObject->format('Y-m-d');
$rangeDays = reportsDateCount($startDateObject, $endDateObject);

$courtWhereClauses = ["type = 'pickleball'"];
$courtParams = [];

$courtSql = sprintf(
    'SELECT id, name, price, member_price, open_time, close_time
     FROM courts
     WHERE %s
     ORDER BY name',
    implode(' AND ', $courtWhereClauses)
);
$courtStatement = $pdo->prepare($courtSql);
$courtStatement->execute($courtParams);
$courts = $courtStatement->fetchAll(PDO::FETCH_ASSOC);

$courtLookup = [];
foreach ($courts as $court) {
    $courtLookup[(int) $court['id']] = $court;
}

$selectedCourtId = array_key_exists($requestedCourtId, $courtLookup) ? $requestedCourtId : 0;

$reservationParams = [$startDate, $endDate];
$reservationWhereClauses = [
    'r.date BETWEEN ? AND ?',
    "COALESCE(c.type, r.sport) = 'pickleball'",
    'COALESCE(r.is_admin_set, 0) = 0',
];

if ($selectedCourtId > 0) {
    $reservationWhereClauses[] = 'r.court_id = ?';
    $reservationParams[] = $selectedCourtId;
}

$reservationWhereSql = 'WHERE ' . implode(' AND ', $reservationWhereClauses);
$reservationQuery = "
    SELECT
        r.id,
        r.date,
        r.court_id,
        COALESCE(c.name, r.court) AS display_court,
        COALESCE(r.booking_source, 'advance') AS booking_source,
        COALESCE(r.payment_status, 'pending') AS payment_status,
        COALESCE(r.payment, 0.00) AS payment,
        COUNT(DISTINCT rs.time) AS hours_played
    FROM reservations r
    LEFT JOIN courts c ON r.court_id = c.id
    LEFT JOIN reservation_slots rs ON rs.reservation_id = r.id
    {$reservationWhereSql}
    GROUP BY r.id
    ORDER BY r.date ASC, display_court ASC, r.id ASC
";
$reservationStatement = $pdo->prepare($reservationQuery);
$reservationStatement->execute($reservationParams);
$reservationRows = $reservationStatement->fetchAll(PDO::FETCH_ASSOC);

$peakHoursQuery = "
    SELECT rs.time, COUNT(*) AS slot_count
    FROM reservations r
    JOIN reservation_slots rs ON rs.reservation_id = r.id
    LEFT JOIN courts c ON r.court_id = c.id
    {$reservationWhereSql}
    GROUP BY rs.time
    ORDER BY slot_count DESC, rs.time ASC
";
$peakHoursStatement = $pdo->prepare($peakHoursQuery);
$peakHoursStatement->execute($reservationParams);
$peakHourRows = $peakHoursStatement->fetchAll(PDO::FETCH_ASSOC);

$summaryRowsByKey = reportsInitializeSummaryRows(
    reportsTimelineKeys($startDateObject, $endDateObject, $granularity),
    $granularity
);

$weekdayOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$weekdaySummary = [];
foreach ($weekdayOrder as $weekday) {
    $weekdaySummary[$weekday] = [
        'label' => $weekday,
        'bookings' => 0,
        'hours' => 0,
        'paid' => 0.0,
    ];
}

$courtPerformance = [];
foreach ($courts as $court) {
    $openDateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startDate . ' ' . (string) ($court['open_time'] ?? '08:00:00'));
    $closeDateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startDate . ' ' . (string) ($court['close_time'] ?? '22:00:00'));
    $openHours = 0;
    if ($openDateTime instanceof DateTimeImmutable && $closeDateTime instanceof DateTimeImmutable) {
        $openHours = max(0, (int) round(($closeDateTime->getTimestamp() - $openDateTime->getTimestamp()) / 3600));
    }

    $courtPerformance[(int) $court['id']] = [
        'id' => (int) $court['id'],
        'name' => (string) $court['name'],
        'bookings' => 0,
        'hours' => 0,
        'paid' => 0.0,
        'pending' => 0.0,
        'open_hours_per_day' => $openHours,
    ];
}

$totalBookings = count($reservationRows);
$totalHours = 0;
$paidRevenue = 0.0;
$pendingRevenue = 0.0;
$walkInCount = 0;
$advanceCount = 0;

foreach ($reservationRows as $reservation) {
    $hoursPlayed = (int) ($reservation['hours_played'] ?? 0);
    $payment = (float) ($reservation['payment'] ?? 0.0);
    $paymentStatus = strtolower((string) ($reservation['payment_status'] ?? 'pending'));
    $bookingSource = strtolower((string) ($reservation['booking_source'] ?? 'advance'));
    $summaryKey = reportsSummaryKey((string) $reservation['date'], $granularity);

    if (!array_key_exists($summaryKey, $summaryRowsByKey)) {
        $summaryRowsByKey[$summaryKey] = [
            'key' => $summaryKey,
            'label' => reportsSummaryLabel($summaryKey, $granularity),
            'bookings' => 0,
            'hours' => 0,
            'paid' => 0.0,
            'pending' => 0.0,
            'walk_ins' => 0,
            'advance' => 0,
        ];
    }

    $totalHours += $hoursPlayed;
    $summaryRowsByKey[$summaryKey]['bookings']++;
    $summaryRowsByKey[$summaryKey]['hours'] += $hoursPlayed;

    if ($paymentStatus === 'paid') {
        $paidRevenue += $payment;
        $summaryRowsByKey[$summaryKey]['paid'] += $payment;
    } else {
        $pendingRevenue += $payment;
        $summaryRowsByKey[$summaryKey]['pending'] += $payment;
    }

    if ($bookingSource === 'walk-in') {
        $walkInCount++;
        $summaryRowsByKey[$summaryKey]['walk_ins']++;
    } else {
        $advanceCount++;
        $summaryRowsByKey[$summaryKey]['advance']++;
    }

    $weekday = reportsWeekdayLabel((string) $reservation['date']);
    if (array_key_exists($weekday, $weekdaySummary)) {
        $weekdaySummary[$weekday]['bookings']++;
        $weekdaySummary[$weekday]['hours'] += $hoursPlayed;
        if ($paymentStatus === 'paid') {
            $weekdaySummary[$weekday]['paid'] += $payment;
        }
    }

    $courtId = (int) ($reservation['court_id'] ?? 0);
    if (!array_key_exists($courtId, $courtPerformance)) {
        $courtPerformance[$courtId] = [
            'id' => $courtId,
            'name' => (string) ($reservation['display_court'] ?? 'Court'),
            'bookings' => 0,
            'hours' => 0,
            'paid' => 0.0,
            'pending' => 0.0,
            'open_hours_per_day' => 0,
        ];
    }

    $courtPerformance[$courtId]['bookings']++;
    $courtPerformance[$courtId]['hours'] += $hoursPlayed;
    if ($paymentStatus === 'paid') {
        $courtPerformance[$courtId]['paid'] += $payment;
    } else {
        $courtPerformance[$courtId]['pending'] += $payment;
    }
}

$summaryRows = array_values($summaryRowsByKey);
usort($summaryRows, static fn(array $first, array $second): int => strcmp((string) $first['key'], (string) $second['key']));

$maxRevenueValue = 1.0;
foreach ($summaryRows as $row) {
    $maxRevenueValue = max($maxRevenueValue, (float) $row['paid'] + (float) $row['pending']);
}

foreach ($courtPerformance as &$courtRow) {
    $capacityHours = max(0, $courtRow['open_hours_per_day'] * $rangeDays);
    $courtRow['capacity_hours'] = $capacityHours;
    $courtRow['usage_percent'] = $capacityHours > 0
        ? min(100, (int) round(($courtRow['hours'] / $capacityHours) * 100))
        : 0;
}
unset($courtRow);

usort($peakHourRows, static function (array $first, array $second): int {
    $countCompare = ((int) ($second['slot_count'] ?? 0)) <=> ((int) ($first['slot_count'] ?? 0));
    if ($countCompare !== 0) {
        return $countCompare;
    }

    return strcmp((string) ($first['time'] ?? ''), (string) ($second['time'] ?? ''));
});

$maxPeakSlots = 1;
foreach ($peakHourRows as $row) {
    $maxPeakSlots = max($maxPeakSlots, (int) ($row['slot_count'] ?? 0));
}
$topPeakHours = array_slice($peakHourRows, 0, 8);

$averageBookingValue = $totalBookings > 0 ? ($paidRevenue + $pendingRevenue) / $totalBookings : 0.0;
$walkInShare = $totalBookings > 0 ? (int) round(($walkInCount / $totalBookings) * 100) : 0;

$busiestSummaryRow = null;
foreach ($summaryRows as $row) {
    if ($busiestSummaryRow === null || (int) $row['bookings'] > (int) $busiestSummaryRow['bookings']) {
        $busiestSummaryRow = $row;
    }
}

$busiestCourt = null;
foreach ($courtPerformance as $courtRow) {
    if ($busiestCourt === null || (int) $courtRow['hours'] > (int) $busiestCourt['hours']) {
        $busiestCourt = $courtRow;
    }
}

$busiestWeekday = null;
foreach ($weekdaySummary as $weekdayRow) {
    if ($busiestWeekday === null || (int) $weekdayRow['bookings'] > (int) $busiestWeekday['bookings']) {
        $busiestWeekday = $weekdayRow;
    }
}

$topPeakHour = $topPeakHours[0] ?? null;

if ($export) {
    $fileName = sprintf('pickleball-report-%s-to-%s-%s.csv', $startDate, $endDate, $granularity);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');

    $output = fopen('php://output', 'wb');
    if (is_resource($output)) {
        fputcsv($output, ['Pickleball Court Reports']);
        fputcsv($output, ['Date Range', $startDate . ' to ' . $endDate]);
        fputcsv($output, ['Period', ucfirst(str_replace('_', ' ', $period))]);
        fputcsv($output, ['Granularity', ucfirst($granularity)]);
        fputcsv($output, ['Court Filter', $selectedCourtId > 0 ? (string) ($courtLookup[$selectedCourtId]['name'] ?? 'Selected Court') : 'All Courts']);
        fputcsv($output, []);
        fputcsv($output, ['Total Bookings', $totalBookings]);
        fputcsv($output, ['Reserved Hours', $totalHours]);
        fputcsv($output, ['Paid Revenue', number_format($paidRevenue, 2, '.', '')]);
        fputcsv($output, ['Pending Revenue', number_format($pendingRevenue, 2, '.', '')]);
        fputcsv($output, ['Walk-ins', $walkInCount]);
        fputcsv($output, ['Advance Bookings', $advanceCount]);
        fputcsv($output, []);
        fputcsv($output, [$granularity === 'monthly' ? 'Month' : 'Date', 'Bookings', 'Hours', 'Paid Revenue', 'Pending Revenue', 'Walk-ins', 'Advance']);
        foreach ($summaryRows as $row) {
            fputcsv($output, [
                (string) $row['label'],
                (int) $row['bookings'],
                (int) $row['hours'],
                number_format((float) $row['paid'], 2, '.', ''),
                number_format((float) $row['pending'], 2, '.', ''),
                (int) $row['walk_ins'],
                (int) $row['advance'],
            ]);
        }
        fclose($output);
    }

    exit;
}

$selectedCourtName = $selectedCourtId > 0 ? (string) ($courtLookup[$selectedCourtId]['name'] ?? 'Selected Court') : 'All Courts';
$periodLabel = ucfirst(str_replace('_', ' ', $period));
$lastUpdated = (new DateTimeImmutable('now'))->format('M j, Y g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Pickleball Admin - Reports</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/js/all.min.js" integrity="sha512-b+nQTCdtTBIRIbraqNEwsjB6UvL3UEMkXnhzd8awtCYh0Kcsjl9uEgwVFVbhoj3uu1DO1ZMacNvLoyJJiNfcvg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <link rel="stylesheet" href="styles/admin-theme.css">
</head>
<body class="admin-theme-body admin-frame-body">
  <div class="admin-page-shell">
    <div class="admin-page-header">
      <div class="admin-overline">Reports & Analytics</div>
      <div class="admin-title">Track income, usage, and booking trends</div>
      <div class="admin-copy">Review daily and monthly performance, compare court usage, spot peak hours, and export the current report window for sharing with the client or front desk team.</div>
    </div>

    <form method="get" class="admin-filter-bar mb-6 grid gap-4 xl:grid-cols-[minmax(0,1fr)_auto]">
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
        <div>
          <label for="period" class="admin-field-label">Period</label>
          <select name="period" id="period" class="admin-select">
            <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>Today</option>
            <option value="this_month" <?= $period === 'this_month' ? 'selected' : '' ?>>This Month</option>
            <option value="last_30_days" <?= $period === 'last_30_days' ? 'selected' : '' ?>>Last 30 Days</option>
            <option value="year_to_date" <?= $period === 'year_to_date' ? 'selected' : '' ?>>Year to Date</option>
            <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>Custom Range</option>
          </select>
        </div>

        <div>
          <label for="granularity" class="admin-field-label">Granularity</label>
          <select name="granularity" id="granularity" class="admin-select">
            <option value="daily" <?= $granularity === 'daily' ? 'selected' : '' ?>>Daily</option>
            <option value="monthly" <?= $granularity === 'monthly' ? 'selected' : '' ?>>Monthly</option>
          </select>
        </div>

        <div>
          <label for="start_date" class="admin-field-label">Start Date</label>
          <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($startDate) ?>" class="admin-input">
        </div>

        <div>
          <label for="end_date" class="admin-field-label">End Date</label>
          <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($endDate) ?>" class="admin-input">
        </div>

        <div>
          <label for="court_id" class="admin-field-label">Court</label>
          <select name="court_id" id="court_id" class="admin-select">
            <option value="0">All Courts</option>
            <?php foreach ($courts as $court): ?>
              <option value="<?= (int) $court['id'] ?>" <?= $selectedCourtId === (int) $court['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars((string) $court['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-end">
        <button type="submit" class="admin-primary-btn whitespace-nowrap">
          <i class="fas fa-filter"></i>
          Apply Report
        </button>
        <a href="?<?= htmlspecialchars(http_build_query([
            'period' => $period,
            'granularity' => $granularity,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'court_id' => $selectedCourtId,
            'export' => 'csv',
        ])) ?>" class="admin-secondary-btn whitespace-nowrap">
          <i class="fas fa-file-export"></i>
          Export CSV
        </a>
      </div>
    </form>

    <div class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
      <div class="flex flex-wrap gap-3">
        <div class="admin-pill"><?= htmlspecialchars($periodLabel) ?></div>
        <div class="admin-pill"><?= htmlspecialchars($selectedCourtName) ?></div>
        <div class="admin-pill"><?= htmlspecialchars($startDate) ?> to <?= htmlspecialchars($endDate) ?></div>
      </div>
      <div class="text-sm text-slate-500">Last updated <?= htmlspecialchars($lastUpdated) ?></div>
    </div>

    <div class="admin-stat-grid mb-6 md:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-6">
      <div class="admin-stat-card">
        <div class="admin-stat-label">Total Bookings</div>
        <div class="admin-stat-value"><?= $totalBookings ?></div>
        <div class="mt-2 text-sm text-slate-500"><?= $walkInCount ?> walk-in<?= $walkInCount === 1 ? '' : 's' ?> in this window</div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Reserved Hours</div>
        <div class="admin-stat-value"><?= $totalHours ?></div>
        <div class="mt-2 text-sm text-slate-500"><?= $advanceCount ?> advance booking<?= $advanceCount === 1 ? '' : 's' ?></div>
      </div>
      <div class="admin-stat-card bg-emerald-50/70">
        <div class="admin-stat-label">Paid Revenue</div>
        <div class="admin-stat-value"><?= htmlspecialchars(reportsFormatCurrency($paidRevenue)) ?></div>
        <div class="mt-2 text-sm text-slate-500">Collected within the selected range</div>
      </div>
      <div class="admin-stat-card bg-amber-50/70">
        <div class="admin-stat-label">Pending Revenue</div>
        <div class="admin-stat-value"><?= htmlspecialchars(reportsFormatCurrency($pendingRevenue)) ?></div>
        <div class="mt-2 text-sm text-slate-500">Still awaiting payment</div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Walk-in Share</div>
        <div class="admin-stat-value"><?= $walkInShare ?>%</div>
        <div class="mt-2 text-sm text-slate-500"><?= $walkInCount ?> of <?= $totalBookings ?> bookings</div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Average Ticket</div>
        <div class="admin-stat-value"><?= htmlspecialchars(reportsFormatCurrency($averageBookingValue)) ?></div>
        <div class="mt-2 text-sm text-slate-500">Average booked value per reservation</div>
      </div>
    </div>

    <div class="admin-stat-grid mb-6 md:grid-cols-2 xl:grid-cols-4">
      <div class="admin-stat-card">
        <div class="admin-stat-label">Peak Hour</div>
        <div class="admin-stat-value">
          <?= $topPeakHour !== null ? htmlspecialchars(reportsFormat12Hour((string) $topPeakHour['time'])) : 'N/A' ?>
        </div>
        <div class="mt-2 text-sm text-slate-500">
          <?= $topPeakHour !== null ? (int) $topPeakHour['slot_count'] . ' reserved slot' . ((int) $topPeakHour['slot_count'] === 1 ? '' : 's') : 'No slot data yet' ?>
        </div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Busiest <?= $granularity === 'monthly' ? 'Month' : 'Day' ?></div>
        <div class="admin-stat-value"><?= htmlspecialchars((string) ($busiestSummaryRow['label'] ?? 'N/A')) ?></div>
        <div class="mt-2 text-sm text-slate-500">
          <?= $busiestSummaryRow !== null ? (int) $busiestSummaryRow['bookings'] . ' bookings' : 'No activity yet' ?>
        </div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Top Court</div>
        <div class="admin-stat-value"><?= htmlspecialchars((string) ($busiestCourt['name'] ?? 'N/A')) ?></div>
        <div class="mt-2 text-sm text-slate-500">
          <?= $busiestCourt !== null ? (int) $busiestCourt['hours'] . ' booked hour' . ((int) $busiestCourt['hours'] === 1 ? '' : 's') : 'No usage yet' ?>
        </div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Busiest Weekday</div>
        <div class="admin-stat-value"><?= htmlspecialchars((string) ($busiestWeekday['label'] ?? 'N/A')) ?></div>
        <div class="mt-2 text-sm text-slate-500">
          <?= $busiestWeekday !== null ? (int) $busiestWeekday['bookings'] . ' bookings' : 'No weekday trend yet' ?>
        </div>
      </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.25fr)_minmax(320px,0.75fr)]">
      <section class="admin-card">
        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 class="text-xl font-semibold text-slate-800">Income Over Time</h2>
            <p class="text-sm text-slate-500">Revenue trend for the current report window, grouped by <?= $granularity === 'monthly' ? 'month' : 'day' ?>.</p>
          </div>
          <div class="admin-pill"><?= htmlspecialchars(ucfirst($granularity)) ?> View</div>
        </div>

        <?php if ($summaryRows === []): ?>
          <div class="rounded-2xl border border-dashed border-slate-300 bg-white/70 px-5 py-8 text-center text-sm text-slate-500">
            No report data is available for this date range.
          </div>
        <?php else: ?>
          <div class="space-y-4">
            <?php foreach ($summaryRows as $row): ?>
              <?php
              $totalRowRevenue = (float) $row['paid'] + (float) $row['pending'];
              $barWidth = max(6, (int) round(($totalRowRevenue / $maxRevenueValue) * 100));
              $paidWidth = $totalRowRevenue > 0 ? (int) round(((float) $row['paid'] / $totalRowRevenue) * 100) : 0;
              ?>
              <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                  <div>
                    <div class="text-sm font-semibold uppercase tracking-[0.16em] text-teal-700"><?= htmlspecialchars((string) $row['label']) ?></div>
                    <div class="mt-1 text-sm text-slate-500"><?= (int) $row['bookings'] ?> bookings | <?= (int) $row['hours'] ?> hours</div>
                  </div>
                  <div class="text-sm font-semibold text-slate-700"><?= htmlspecialchars(reportsFormatCurrency($totalRowRevenue)) ?></div>
                </div>

                <div class="mt-3 h-3 rounded-full bg-slate-200">
                  <div class="flex h-3 overflow-hidden rounded-full" style="width: <?= $barWidth ?>%">
                    <div class="h-3 bg-emerald-500" style="width: <?= $paidWidth ?>%"></div>
                    <div class="h-3 flex-1 bg-amber-400"></div>
                  </div>
                </div>

                <div class="mt-3 grid gap-3 text-sm text-slate-600 sm:grid-cols-4">
                  <div>Paid: <?= htmlspecialchars(reportsFormatCurrency((float) $row['paid'])) ?></div>
                  <div>Pending: <?= htmlspecialchars(reportsFormatCurrency((float) $row['pending'])) ?></div>
                  <div>Walk-ins: <?= (int) $row['walk_ins'] ?></div>
                  <div>Advance: <?= (int) $row['advance'] ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <div class="space-y-6">
        <section class="admin-card">
          <div class="mb-4">
            <h2 class="text-xl font-semibold text-slate-800">Peak Booking Hours</h2>
            <p class="mt-1 text-sm text-slate-500">Most-booked time slots across the selected report range.</p>
          </div>

          <?php if ($topPeakHours === []): ?>
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white/70 px-5 py-8 text-center text-sm text-slate-500">
              No slot data is available for this range yet.
            </div>
          <?php else: ?>
            <div class="space-y-3">
              <?php foreach ($topPeakHours as $peakHour): ?>
                <?php $width = max(8, (int) round((((int) $peakHour['slot_count']) / $maxPeakSlots) * 100)); ?>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                  <div class="flex items-center justify-between gap-4">
                    <div>
                      <div class="text-sm font-semibold text-slate-800"><?= htmlspecialchars(reportsFormat12Hour((string) $peakHour['time'])) ?></div>
                      <div class="mt-1 text-sm text-slate-500"><?= (int) $peakHour['slot_count'] ?> reserved slot<?= (int) $peakHour['slot_count'] === 1 ? '' : 's' ?></div>
                    </div>
                    <div class="text-sm font-semibold text-teal-700"><?= (int) $peakHour['slot_count'] ?></div>
                  </div>
                  <div class="mt-3 h-2 rounded-full bg-slate-200">
                    <div class="h-2 rounded-full bg-teal-600" style="width: <?= $width ?>%"></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <section class="admin-card">
          <div class="mb-4">
            <h2 class="text-xl font-semibold text-slate-800">Weekday Trends</h2>
            <p class="mt-1 text-sm text-slate-500">See which weekdays bring the highest booking activity.</p>
          </div>

          <div class="space-y-3">
            <?php
            $weekdayMaxBookings = 1;
            foreach ($weekdaySummary as $weekdayRow) {
                $weekdayMaxBookings = max($weekdayMaxBookings, (int) $weekdayRow['bookings']);
            }
            ?>
            <?php foreach ($weekdaySummary as $weekdayRow): ?>
              <?php $weekdayWidth = max(6, (int) round((((int) $weekdayRow['bookings']) / $weekdayMaxBookings) * 100)); ?>
              <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                <div class="flex items-center justify-between gap-4">
                  <div>
                    <div class="text-sm font-semibold text-slate-800"><?= htmlspecialchars((string) $weekdayRow['label']) ?></div>
                    <div class="mt-1 text-sm text-slate-500"><?= (int) $weekdayRow['hours'] ?> booked hour<?= (int) $weekdayRow['hours'] === 1 ? '' : 's' ?></div>
                  </div>
                  <div class="text-sm font-semibold text-slate-700"><?= (int) $weekdayRow['bookings'] ?> bookings</div>
                </div>
                <div class="mt-3 h-2 rounded-full bg-slate-200">
                  <div class="h-2 rounded-full bg-amber-400" style="width: <?= $weekdayWidth ?>%"></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      </div>
    </div>

    <section class="admin-card mt-6">
      <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="text-xl font-semibold text-slate-800">Court Usage Trends</h2>
          <p class="text-sm text-slate-500">Compare bookings, usage, and revenue by court across the selected report range.</p>
        </div>
        <div class="admin-pill">Courts tracked: <?= count($courtPerformance) ?></div>
      </div>

      <?php if ($courtPerformance === []): ?>
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/70 px-5 py-8 text-center text-sm text-slate-500">
          No courts are available for this report.
        </div>
      <?php else: ?>
        <div class="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
          <?php foreach ($courtPerformance as $courtRow): ?>
            <div class="rounded-2xl border border-slate-200 bg-white/90 px-5 py-5 shadow-sm">
              <div class="flex items-start justify-between gap-4">
                <div>
                  <div class="text-lg font-semibold text-slate-800"><?= htmlspecialchars((string) $courtRow['name']) ?></div>
                  <div class="mt-1 text-sm text-slate-500"><?= $rangeDays ?> day<?= $rangeDays === 1 ? '' : 's' ?> in report window</div>
                </div>
                <span class="admin-tag bg-teal-100 text-teal-700"><?= $courtRow['usage_percent'] ?>% used</span>
              </div>

              <div class="mt-4 h-2 rounded-full bg-slate-200">
                <div class="h-2 rounded-full bg-teal-600" style="width: <?= $courtRow['usage_percent'] ?>%"></div>
              </div>

              <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="rounded-2xl bg-slate-50 px-4 py-3">
                  <div class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Bookings</div>
                  <div class="mt-2 text-base font-semibold text-slate-800"><?= (int) $courtRow['bookings'] ?></div>
                </div>
                <div class="rounded-2xl bg-slate-50 px-4 py-3">
                  <div class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Hours</div>
                  <div class="mt-2 text-base font-semibold text-slate-800"><?= (int) $courtRow['hours'] ?> / <?= (int) $courtRow['capacity_hours'] ?></div>
                </div>
                <div class="rounded-2xl bg-slate-50 px-4 py-3">
                  <div class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Paid Revenue</div>
                  <div class="mt-2 text-base font-semibold text-slate-800"><?= htmlspecialchars(reportsFormatCurrency((float) $courtRow['paid'])) ?></div>
                </div>
                <div class="rounded-2xl bg-slate-50 px-4 py-3">
                  <div class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Pending</div>
                  <div class="mt-2 text-base font-semibold text-slate-800"><?= htmlspecialchars(reportsFormatCurrency((float) $courtRow['pending'])) ?></div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="admin-card mt-6">
      <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="text-xl font-semibold text-slate-800"><?= $granularity === 'monthly' ? 'Monthly' : 'Daily' ?> Summary</h2>
          <p class="text-sm text-slate-500">Export-ready report rows for the current date window and court filter.</p>
        </div>
        <div class="admin-pill">Rows: <?= count($summaryRows) ?></div>
      </div>

      <?php if ($summaryRows === []): ?>
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/70 px-5 py-8 text-center text-sm text-slate-500">
          No summary rows are available for this report window.
        </div>
      <?php else: ?>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr>
                <th><?= $granularity === 'monthly' ? 'Month' : 'Date' ?></th>
                <th>Bookings</th>
                <th>Hours</th>
                <th>Paid</th>
                <th>Pending</th>
                <th>Walk-ins</th>
                <th>Advance</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($summaryRows as $row): ?>
                <tr>
                  <td><?= htmlspecialchars((string) $row['label']) ?></td>
                  <td><?= (int) $row['bookings'] ?></td>
                  <td><?= (int) $row['hours'] ?></td>
                  <td><?= htmlspecialchars(reportsFormatCurrency((float) $row['paid'])) ?></td>
                  <td><?= htmlspecialchars(reportsFormatCurrency((float) $row['pending'])) ?></td>
                  <td><?= (int) $row['walk_ins'] ?></td>
                  <td><?= (int) $row['advance'] ?></td>
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
