<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') === 'user') {
    header('Location: ../index.html');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/admin_activity.php';
require_once 'includes/membership.php';
require_once 'includes/notifications.php';

function supportValidDate(?string $value): ?string
{
    $candidate = trim((string) $value);
    if ($candidate === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $candidate);
    return $date instanceof DateTimeImmutable ? $date->format('Y-m-d') : null;
}

function supportActionLabel(string $actionType): string
{
    return match ($actionType) {
        'court_created' => 'Court Created',
        'court_updated' => 'Court Updated',
        'court_deleted' => 'Court Deleted',
        'user_updated' => 'User Updated',
        'user_deleted' => 'User Deleted',
        'walk_in_created' => 'Walk-in Created',
        'reservation_updated' => 'Reservation Updated',
        'reservation_deleted' => 'Reservation Deleted',
        'game_status_updated' => 'Game Status Updated',
        'payment_confirmed' => 'Payment Confirmed',
        'activity_exported' => 'Activity Exported',
        'snapshot_exported' => 'Snapshot Exported',
        default => ucwords(str_replace('_', ' ', $actionType)),
    };
}

function supportActionBadgeClass(string $actionType): string
{
    return match ($actionType) {
        'court_created', 'walk_in_created', 'payment_confirmed' => 'bg-emerald-100 text-emerald-700',
        'court_updated', 'user_updated', 'reservation_updated', 'game_status_updated' => 'bg-teal-100 text-teal-700',
        'court_deleted', 'user_deleted', 'reservation_deleted' => 'bg-rose-100 text-rose-700',
        'activity_exported', 'snapshot_exported' => 'bg-amber-100 text-amber-700',
        default => 'bg-slate-100 text-slate-700',
    };
}

function supportBuildActivityFilters(string $dateFrom, string $dateTo, string $actionType, string $search): array
{
    $whereClauses = [];
    $params = [];

    if ($dateFrom !== '') {
        $whereClauses[] = 'created_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo !== '') {
        $endDate = new DateTimeImmutable($dateTo);
        $whereClauses[] = 'created_at < ?';
        $params[] = $endDate->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }

    if ($actionType !== '') {
        $whereClauses[] = 'action_type = ?';
        $params[] = $actionType;
    }

    if ($search !== '') {
        $whereClauses[] = '(actor_name LIKE ? OR description LIKE ? OR subject_type LIKE ? OR CAST(subject_id AS CHAR) LIKE ?)';
        $likeValue = '%' . $search . '%';
        array_push($params, $likeValue, $likeValue, $likeValue, $likeValue);
    }

    $whereSql = $whereClauses === [] ? '' : 'WHERE ' . implode(' AND ', $whereClauses);
    return [$whereSql, $params];
}

function supportQueryAll(PDO $pdo, string $sql, array $params = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function supportQueryScalar(PDO $pdo, string $sql, array $params = []): mixed
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}

function supportExportFilename(string $prefix, string $extension): string
{
    return sprintf(
        '%s-%s.%s',
        $prefix,
        (new DateTimeImmutable('now'))->format('Ymd-His'),
        $extension
    );
}

function supportDownloadCsv(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        exit;
    }

    fputcsv($output, $headers);
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

function supportDownloadJson(string $filename, array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$defaultDateFrom = (new DateTimeImmutable('today'))->modify('-13 days')->format('Y-m-d');
$filterDateFrom = supportValidDate($_GET['date_from'] ?? '') ?? $defaultDateFrom;
$filterDateTo = supportValidDate($_GET['date_to'] ?? '') ?? $today;
$filterAction = trim((string) ($_GET['action'] ?? ''));
$filterSearch = trim((string) ($_GET['search'] ?? ''));
$export = strtolower(trim((string) ($_GET['export'] ?? '')));
$activityTableReady = adminActivityTableExists($pdo);

[$activityWhereSql, $activityParams] = supportBuildActivityFilters(
    $filterDateFrom,
    $filterDateTo,
    $filterAction,
    $filterSearch
);

$actionOptions = [
    'court_created',
    'court_updated',
    'court_deleted',
    'user_updated',
    'user_deleted',
    'walk_in_created',
    'reservation_updated',
    'reservation_deleted',
    'game_status_updated',
    'payment_confirmed',
    'activity_exported',
    'snapshot_exported',
];

if ($export === 'activity-csv') {
    if (!$activityTableReady) {
        http_response_code(503);
        echo 'Activity log table is not available yet. Run the latest schema updates first.';
        exit;
    }

    $activityExportRows = supportQueryAll(
        $pdo,
        "SELECT created_at, actor_name, actor_role, action_type, subject_type, subject_id, description, metadata_json
         FROM admin_activity_logs
         {$activityWhereSql}
         ORDER BY created_at DESC, id DESC",
        $activityParams
    );

    adminActivityLog($pdo, [
        'action_type' => 'activity_exported',
        'subject_type' => 'support',
        'description' => 'Exported activity log CSV',
        'metadata' => [
            'date_from' => $filterDateFrom,
            'date_to' => $filterDateTo,
            'action_type' => $filterAction !== '' ? $filterAction : null,
            'search' => $filterSearch !== '' ? $filterSearch : null,
            'row_count' => count($activityExportRows),
        ],
    ]);

    $csvRows = array_map(
        static function (array $row): array {
            return [
                (string) ($row['created_at'] ?? ''),
                (string) ($row['actor_name'] ?? ''),
                ucfirst((string) ($row['actor_role'] ?? '')),
                supportActionLabel((string) ($row['action_type'] ?? '')),
                ucwords(str_replace('_', ' ', (string) ($row['subject_type'] ?? ''))),
                (string) ($row['subject_id'] ?? ''),
                (string) ($row['description'] ?? ''),
                (string) ($row['metadata_json'] ?? ''),
            ];
        },
        $activityExportRows
    );

    supportDownloadCsv(
        supportExportFilename('pickleball-activity-log', 'csv'),
        ['Date / Time', 'Actor', 'Role', 'Action', 'Subject', 'Subject ID', 'Description', 'Metadata JSON'],
        $csvRows
    );
}

if ($export === 'ops-snapshot') {
    $databaseName = (string) supportQueryScalar($pdo, 'SELECT DATABASE()');
    $courts = supportQueryAll(
        $pdo,
        'SELECT id, name, location, type, price, member_price, open_time, close_time, image_path
         FROM courts
         ORDER BY id ASC'
    );
    $users = supportQueryAll(
        $pdo,
        'SELECT id, name, full_name, email, contact_number, role, membership_status, membership_plan, member_since, membership_expires_at, membership_benefits
         FROM users
         ORDER BY id ASC'
    );
    $reservations = supportQueryAll(
        $pdo,
        'SELECT id, user_id, full_name, contact_number, email, reservation_info, payment_method, payment_status, sport, court, court_id, date, time, hourly_rate, subtotal, discount_type, discount_label, discount_amount, processing_fee, payment, booking_source, game_status, payment_proof_path, created_at
         FROM reservations
         ORDER BY date DESC, id DESC'
    );
    $reservationSlots = supportQueryAll(
        $pdo,
        'SELECT id, reservation_id, time, section_number, created_at
         FROM reservation_slots
         ORDER BY reservation_id ASC, time ASC'
    );
    $reservationGuests = supportQueryAll(
        $pdo,
        'SELECT id, reservation_id, guest_name, guest_contact, payment
         FROM reservation_guests
         ORDER BY reservation_id ASC, id ASC'
    );
    $activityRowsForSnapshot = $activityTableReady
        ? supportQueryAll(
            $pdo,
            'SELECT id, actor_user_id, actor_role, actor_name, action_type, subject_type, subject_id, description, metadata_json, created_at
             FROM admin_activity_logs
             ORDER BY created_at DESC, id DESC
             LIMIT 1000'
        )
        : [];
    $notificationsReady = notificationsTableExists($pdo);
    $notificationRowsForSnapshot = $notificationsReady
        ? supportQueryAll(
            $pdo,
            'SELECT id, user_id, target_role, type, title, message, link_url, is_read, created_at, read_at
             FROM notifications
             ORDER BY created_at DESC, id DESC
             LIMIT 2000'
        )
        : [];

    adminActivityLog($pdo, [
        'action_type' => 'snapshot_exported',
        'subject_type' => 'support',
        'description' => 'Exported operational JSON snapshot',
        'metadata' => [
            'court_count' => count($courts),
            'user_count' => count($users),
            'reservation_count' => count($reservations),
            'activity_included' => $activityTableReady,
            'notifications_included' => $notificationsReady,
        ],
    ]);

    supportDownloadJson(
        supportExportFilename('pickleball-ops-snapshot', 'json'),
        [
            'app' => 'courtmaster-pickleball',
            'database' => $databaseName,
            'exported_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            'generated_by' => [
                'user_id' => (int) ($_SESSION['user_id'] ?? 0),
                'name' => adminActivityActorName(),
                'role' => (string) ($_SESSION['role'] ?? 'admin'),
            ],
            'notes' => [
                'This operational snapshot excludes password hashes and uploaded files.',
                'Use hosting-level SQL backups for full disaster recovery.',
            ],
            'summary' => [
                'courts' => count($courts),
                'users' => count($users),
                'reservations' => count($reservations),
                'reservation_slots' => count($reservationSlots),
                'reservation_guests' => count($reservationGuests),
                'activity_rows' => count($activityRowsForSnapshot),
                'notification_rows' => count($notificationRowsForSnapshot),
            ],
            'courts' => $courts,
            'users' => $users,
            'reservations' => $reservations,
            'reservation_slots' => $reservationSlots,
            'reservation_guests' => $reservationGuests,
            'admin_activity_logs' => $activityRowsForSnapshot,
            'notifications' => $notificationRowsForSnapshot,
        ]
    );
}

$databaseName = (string) supportQueryScalar($pdo, 'SELECT DATABASE()');
$courtCount = (int) supportQueryScalar($pdo, 'SELECT COUNT(*) FROM courts');
$userCount = (int) supportQueryScalar($pdo, 'SELECT COUNT(*) FROM users');
$activeMemberCount = (int) supportQueryScalar(
    $pdo,
    "SELECT COUNT(*)
     FROM users
     WHERE membership_status = ?
       AND (membership_expires_at IS NULL OR membership_expires_at = '' OR membership_expires_at >= CURDATE())",
    [MEMBERSHIP_STATUS_ACTIVE]
);
$reservationCount = (int) supportQueryScalar($pdo, 'SELECT COUNT(*) FROM reservations');
$todayReservationCount = (int) supportQueryScalar(
    $pdo,
    'SELECT COUNT(*) FROM reservations WHERE date = ?',
    [$today]
);

$activitySummary = [
    'total_entries' => 0,
    'unique_actors' => 0,
    'export_events' => 0,
    'latest_activity' => null,
    'last_export' => null,
];
$activityRows = [];

if ($activityTableReady) {
    $summaryRow = supportQueryAll(
        $pdo,
        "SELECT
            COUNT(*) AS total_entries,
            COUNT(DISTINCT CONCAT(COALESCE(actor_user_id, 0), ':', actor_name)) AS unique_actors,
            SUM(CASE WHEN action_type IN ('activity_exported', 'snapshot_exported') THEN 1 ELSE 0 END) AS export_events,
            MAX(created_at) AS latest_activity
         FROM admin_activity_logs
         {$activityWhereSql}",
        $activityParams
    )[0] ?? [];

    $latestExportRow = supportQueryAll(
        $pdo,
        "SELECT created_at, actor_name
         FROM admin_activity_logs
         WHERE action_type IN ('activity_exported', 'snapshot_exported')
         ORDER BY created_at DESC, id DESC
         LIMIT 1"
    )[0] ?? null;

    $activitySummary = [
        'total_entries' => (int) ($summaryRow['total_entries'] ?? 0),
        'unique_actors' => (int) ($summaryRow['unique_actors'] ?? 0),
        'export_events' => (int) ($summaryRow['export_events'] ?? 0),
        'latest_activity' => $summaryRow['latest_activity'] ?? null,
        'last_export' => is_array($latestExportRow) ? $latestExportRow : null,
    ];

    $activityRows = supportQueryAll(
        $pdo,
        "SELECT id, actor_name, actor_role, action_type, subject_type, subject_id, description, metadata_json, created_at
         FROM admin_activity_logs
         {$activityWhereSql}
         ORDER BY created_at DESC, id DESC
         LIMIT 120",
        $activityParams
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pickleball Admin - Support Tools</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/js/all.min.js" integrity="sha512-b+nQTCdtTBIRIbraqNEwsjB6UvL3UEMkXnhzd8awtCYh0Kcsjl9uEgwVFVbhoj3uu1DO1ZMacNvLoyJJiNfcvg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <link rel="stylesheet" href="styles/admin-theme.css">
</head>
<body class="admin-theme-body admin-frame-body">
  <div class="admin-page-shell">
    <div class="admin-page-header">
      <div class="admin-overline">Support Tools</div>
      <div class="admin-title">Track staff actions and export operational backups</div>
      <div class="admin-copy">Use this page to review recent admin changes, download an audit-ready activity log, and export a lightweight JSON snapshot of the venue data before major updates.</div>
    </div>

    <div class="admin-stat-grid mb-5 md:grid-cols-2 xl:grid-cols-4">
      <div class="admin-stat-card">
        <div class="admin-stat-label">Database</div>
        <div class="admin-stat-value"><?= htmlspecialchars($databaseName) ?></div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Tracked Actions</div>
        <div class="admin-stat-value"><?= $activityTableReady ? number_format((int) $activitySummary['total_entries']) : 'Pending' ?></div>
      </div>
      <div class="admin-stat-card bg-emerald-50/70">
        <div class="admin-stat-label">Today’s Reservations</div>
        <div class="admin-stat-value"><?= number_format($todayReservationCount) ?></div>
      </div>
      <div class="admin-stat-card bg-amber-50/70">
        <div class="admin-stat-label">Active Members</div>
        <div class="admin-stat-value"><?= number_format($activeMemberCount) ?></div>
      </div>
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1.7fr)_minmax(320px,1fr)]">
      <section class="admin-card">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
          <div>
            <div class="admin-overline">Activity Log</div>
            <div class="text-2xl font-semibold text-slate-900">Recent staff-side changes</div>
            <p class="mt-2 text-sm text-slate-500">The log captures court updates, user edits, reservation changes, walk-ins, payment confirmations, and support exports.</p>
          </div>
          <div class="admin-pill"><?= $activityTableReady ? 'Live Logging Enabled' : 'Run Schema Update' ?></div>
        </div>

        <?php if (!$activityTableReady): ?>
          <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-800">
            The <code>admin_activity_logs</code> table is not available yet. Run the latest schema update on this environment before using audit exports.
          </div>
        <?php else: ?>
          <form method="get" class="admin-filter-bar mt-5 grid gap-4 lg:grid-cols-[repeat(4,minmax(0,1fr))_auto]">
            <div>
              <label for="date_from" class="admin-field-label">From</label>
              <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>" class="admin-input">
            </div>
            <div>
              <label for="date_to" class="admin-field-label">To</label>
              <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>" class="admin-input">
            </div>
            <div>
              <label for="action" class="admin-field-label">Action</label>
              <select id="action" name="action" class="admin-select">
                <option value="">All actions</option>
                <?php foreach ($actionOptions as $actionOption): ?>
                  <option value="<?= htmlspecialchars($actionOption) ?>" <?= $filterAction === $actionOption ? 'selected' : '' ?>>
                    <?= htmlspecialchars(supportActionLabel($actionOption)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="search" class="admin-field-label">Search</label>
              <input type="text" id="search" name="search" value="<?= htmlspecialchars($filterSearch) ?>" class="admin-input" placeholder="Actor, reservation ID, or note">
            </div>
            <div class="flex items-end gap-3">
              <button type="submit" class="admin-primary-btn">
                <i class="fas fa-magnifying-glass"></i>
                Apply
              </button>
              <a href="admin_support.php" class="admin-secondary-btn">Reset</a>
            </div>
          </form>

          <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
              <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Visible Events</div>
              <div class="mt-2 text-2xl font-semibold text-slate-900"><?= number_format((int) $activitySummary['total_entries']) ?></div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
              <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Unique Actors</div>
              <div class="mt-2 text-2xl font-semibold text-slate-900"><?= number_format((int) $activitySummary['unique_actors']) ?></div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
              <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Exports Logged</div>
              <div class="mt-2 text-2xl font-semibold text-slate-900"><?= number_format((int) $activitySummary['export_events']) ?></div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
              <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Latest Activity</div>
              <div class="mt-2 text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($activitySummary['latest_activity'] ?? 'No activity yet')) ?></div>
            </div>
          </div>

          <div class="admin-table-wrap mt-5">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Actor</th>
                  <th>Action</th>
                  <th>Subject</th>
                  <th>Description</th>
                  <th>Metadata</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($activityRows === []): ?>
                  <tr>
                    <td colspan="6" class="text-center text-sm text-slate-500">No activity matched the current filters.</td>
                  </tr>
                <?php endif; ?>
                <?php foreach ($activityRows as $row): ?>
                  <?php
                  $metadataJson = trim((string) ($row['metadata_json'] ?? ''));
                  $decodedMetadata = $metadataJson !== '' ? json_decode($metadataJson, true) : null;
                  ?>
                  <tr>
                    <td class="whitespace-nowrap text-sm text-slate-600"><?= htmlspecialchars((string) ($row['created_at'] ?? '')) ?></td>
                    <td>
                      <div class="font-medium text-slate-900"><?= htmlspecialchars((string) ($row['actor_name'] ?? 'System')) ?></div>
                      <div class="text-xs uppercase tracking-[0.14em] text-slate-500"><?= htmlspecialchars((string) ($row['actor_role'] ?? 'admin')) ?></div>
                    </td>
                    <td>
                      <span class="admin-tag <?= htmlspecialchars(supportActionBadgeClass((string) ($row['action_type'] ?? ''))) ?>">
                        <?= htmlspecialchars(supportActionLabel((string) ($row['action_type'] ?? ''))) ?>
                      </span>
                    </td>
                    <td class="text-sm text-slate-600">
                      <div><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($row['subject_type'] ?? '')))) ?></div>
                      <div class="text-xs text-slate-400">#<?= htmlspecialchars((string) ($row['subject_id'] ?? 'n/a')) ?></div>
                    </td>
                    <td class="text-sm text-slate-700"><?= htmlspecialchars((string) ($row['description'] ?? '')) ?></td>
                    <td class="text-sm text-slate-600">
                      <?php if (is_array($decodedMetadata) && $decodedMetadata !== []): ?>
                        <details>
                          <summary class="cursor-pointer text-sm font-medium text-teal-700">View details</summary>
                          <pre class="mt-3 overflow-x-auto rounded-2xl bg-slate-950/95 p-3 text-xs text-slate-100"><?= htmlspecialchars(json_encode($decodedMetadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                        </details>
                      <?php else: ?>
                        <span class="text-slate-400">No extra metadata</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <section class="grid gap-5">
        <div class="admin-card">
          <div class="admin-overline">Exports</div>
          <div class="mt-1 text-2xl font-semibold text-slate-900">Backup and shareable downloads</div>
          <p class="mt-2 text-sm text-slate-500">Use reports for financial CSVs, and use these tools for audit or operational support snapshots.</p>

          <div class="mt-5 grid gap-4">
            <a
              href="admin_support.php?<?= htmlspecialchars(http_build_query([
                  'date_from' => $filterDateFrom,
                  'date_to' => $filterDateTo,
                  'action' => $filterAction,
                  'search' => $filterSearch,
                  'export' => 'activity-csv',
              ])) ?>"
              class="admin-primary-btn w-full"
            >
              <i class="fas fa-file-export"></i>
              Download Activity CSV
            </a>
            <a href="admin_support.php?export=ops-snapshot" class="admin-secondary-btn w-full">
              <i class="fas fa-database"></i>
              Download JSON Snapshot
            </a>
          </div>

          <div class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-600">
            <div class="font-semibold text-slate-900">Snapshot contents</div>
            <p class="mt-2">Courts, users, memberships, reservations, time slots, guest rows, and the latest activity log entries. Password hashes and uploaded files are intentionally excluded.</p>
          </div>

          <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-600">
            <div class="font-semibold text-slate-900">Last export</div>
            <?php if (is_array($activitySummary['last_export'] ?? null)): ?>
              <p class="mt-2"><?= htmlspecialchars((string) (($activitySummary['last_export']['actor_name'] ?? 'System'))) ?> on <?= htmlspecialchars((string) (($activitySummary['last_export']['created_at'] ?? ''))) ?></p>
            <?php else: ?>
              <p class="mt-2">No exports logged yet on this environment.</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="admin-card">
          <div class="admin-overline">Environment Snapshot</div>
          <div class="mt-1 text-2xl font-semibold text-slate-900">Quick health check</div>
          <div class="mt-5 space-y-3 text-sm text-slate-600">
            <div class="flex items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
              <span>Total courts</span>
              <strong class="text-slate-900"><?= number_format($courtCount) ?></strong>
            </div>
            <div class="flex items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
              <span>Total users</span>
              <strong class="text-slate-900"><?= number_format($userCount) ?></strong>
            </div>
            <div class="flex items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
              <span>Total reservations</span>
              <strong class="text-slate-900"><?= number_format($reservationCount) ?></strong>
            </div>
            <div class="flex items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
              <span>Activity table</span>
              <strong class="<?= $activityTableReady ? 'text-emerald-700' : 'text-amber-700' ?>"><?= $activityTableReady ? 'Ready' : 'Pending' ?></strong>
            </div>
          </div>
        </div>

        <div class="admin-card">
          <div class="admin-overline">Support Notes</div>
          <div class="mt-1 text-2xl font-semibold text-slate-900">Recommended workflow</div>
          <ol class="mt-5 space-y-3 text-sm text-slate-600">
            <li>1. Download the JSON snapshot before major court, pricing, or user changes.</li>
            <li>2. Use the activity log export when you need a clean trail of staff-side edits.</li>
            <li>3. Keep hosting-level SQL backups enabled for full disaster recovery, since uploaded proofs and password hashes stay outside the app snapshot.</li>
          </ol>
        </div>
      </section>
    </div>
  </div>
</body>
</html>
