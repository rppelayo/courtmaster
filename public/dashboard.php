<?php
declare(strict_types=1);

session_start();
require_once 'includes/db.php';
require_once 'includes/membership.php';
require_once 'includes/notifications.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.html');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$user = membershipFetchUser($pdo, $userId);

if (!is_array($user)) {
    header('Location: index.html');
    exit;
}

$role = (string) ($user['role'] ?? ($_SESSION['role'] ?? 'user'));
$email = (string) ($user['email'] ?? ($_SESSION['email'] ?? ''));
$displayName = trim((string) ($user['full_name'] ?? ''));
if ($displayName === '') {
    $displayName = trim((string) ($user['name'] ?? ($_SESSION['user_name'] ?? '')));
}

if ($displayName === '' && $email !== '') {
    $displayName = explode('@', $email)[0];
}

if ($displayName === '') {
    $displayName = 'Player';
}

$contactNumber = trim((string) ($user['contact_number'] ?? ''));
$membershipStatus = membershipResolveStatus($user);
$isMember = membershipIsActive($user);
$membershipStatusLabel = membershipStatusLabel($membershipStatus);
$membershipStatusBadgeClass = membershipStatusBadgeClass($membershipStatus);
$membershipPlan = trim((string) ($user['membership_plan'] ?? ''));
$membershipPlanLabel = membershipPlanLabel($membershipPlan);
$memberSinceLabel = membershipFormatDate((string) ($user['member_since'] ?? ''));
$membershipExpiryRaw = trim((string) ($user['membership_expires_at'] ?? ''));
$membershipExpiryLabel = $membershipExpiryRaw !== '' ? membershipFormatDate($membershipExpiryRaw) : 'Open-ended';
$membershipBenefits = membershipBenefitLines((string) ($user['membership_benefits'] ?? ''), $isMember);
$playerNotifications = notificationsFetchVisible($pdo, $userId, $role, 6);
$playerUnreadNotifications = notificationsUnreadCount($pdo, $userId, $role);

function dashboardNotificationBadgeClass(string $type): string
{
    return match ($type) {
        'reservation_confirmed' => 'pill-success',
        'payment_confirmed' => 'pill',
        default => 'pill-muted',
    };
}

$upcomingReminderStatement = $pdo->prepare(
    "SELECT
        r.id,
        COALESCE(c.name, r.court) AS court,
        r.date,
        COALESCE(r.payment_status, 'pending') AS payment_status,
        GROUP_CONCAT(DISTINCT rs.time ORDER BY rs.time) AS time_slots
     FROM reservations r
     LEFT JOIN courts c ON r.court_id = c.id
     LEFT JOIN reservation_slots rs ON rs.reservation_id = r.id
     WHERE r.user_id = ?
       AND r.date >= CURDATE()
     GROUP BY r.id
     ORDER BY r.date ASC, time_slots ASC, r.id ASC
     LIMIT 6"
);
$upcomingReminderStatement->execute([$userId]);
$upcomingReminderRows = [];
$dashboardNow = new DateTimeImmutable('now');
foreach ($upcomingReminderStatement->fetchAll(PDO::FETCH_ASSOC) as $reminderRow) {
    $timeSlots = array_values(array_filter(array_map('trim', explode(',', (string) ($reminderRow['time_slots'] ?? '')))));
    if ($timeSlots === []) {
        continue;
    }

    $startTime = notificationsNormalizeTime((string) $timeSlots[0]);
    if ($startTime === '') {
        continue;
    }

    $startDateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $reminderRow['date'] . ' ' . $startTime);
    if (!$startDateTime instanceof DateTimeImmutable || $startDateTime < $dashboardNow) {
        continue;
    }

    $hoursUntil = (int) floor(($startDateTime->getTimestamp() - $dashboardNow->getTimestamp()) / 3600);
    $upcomingReminderRows[] = [
        'court' => (string) ($reminderRow['court'] ?? 'Court'),
        'date' => (string) ($reminderRow['date'] ?? ''),
        'time_range' => notificationsBuildTimeRange($timeSlots),
        'start_at' => $startDateTime,
        'hours_until' => $hoursUntil,
        'payment_status' => (string) ($reminderRow['payment_status'] ?? 'pending'),
    ];
    if (count($upcomingReminderRows) >= 3) {
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Pickleball Player Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/js/all.min.js" integrity="sha512-b+nQTCdtTBIRIbraqNEwsjB6UvL3UEMkXnhzd8awtCYh0Kcsjl9uEgwVFVbhoj3uu1DO1ZMacNvLoyJJiNfcvg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <style>
    :root {
      --page-bg: #f4f8f3;
      --surface: rgba(255, 255, 255, 0.92);
      --border: #cfe0d8;
      --text: #173630;
      --muted: #607a72;
      --primary: #0f766e;
      --primary-strong: #0b5f58;
      --danger: #dc2626;
      --danger-soft: #fef2f2;
      --success-soft: #ecfdf5;
      --shadow: 0 24px 60px rgba(23, 54, 48, 0.12);
    }

    body {
      min-height: 100vh;
      background:
        radial-gradient(circle at top left, rgba(15, 118, 110, 0.14), transparent 28%),
        linear-gradient(180deg, #fbfdf9 0%, var(--page-bg) 100%);
      color: var(--text);
    }

    .page-shell {
      max-width: 1180px;
      margin: 0 auto;
      padding: 32px 16px 48px;
    }

    .glass-card {
      background: var(--surface);
      border: 1px solid rgba(207, 224, 216, 0.92);
      box-shadow: var(--shadow);
      backdrop-filter: blur(10px);
    }

    .pill {
      border: 1px solid rgba(15, 118, 110, 0.16);
      background: #f7fcfa;
      color: var(--primary);
    }

    .pill-muted {
      border-color: rgba(207, 224, 216, 0.92);
      background: #ffffff;
      color: var(--muted);
    }

    .pill-success {
      border-color: rgba(16, 185, 129, 0.18);
      background: var(--success-soft);
      color: #047857;
    }

    .primary-button {
      background: var(--primary);
      color: #ffffff;
      transition: background 0.2s ease, transform 0.2s ease;
    }

    .primary-button:hover {
      background: var(--primary-strong);
      transform: translateY(-1px);
    }

    .secondary-button {
      background: #eef5f2;
      border: 1px solid var(--border);
      color: var(--text);
      transition: background 0.2s ease, transform 0.2s ease;
    }

    .secondary-button:hover {
      background: #e5f0ec;
      transform: translateY(-1px);
    }

    .danger-button {
      background: var(--danger-soft);
      border: 1px solid rgba(220, 38, 38, 0.12);
      color: var(--danger);
    }

    .danger-button:hover {
      background: #fee2e2;
      transform: translateY(-1px);
    }

    .stat-card,
    .reservation-card {
      border: 1px solid rgba(207, 224, 216, 0.92);
      background: linear-gradient(180deg, #ffffff 0%, #f7fbf9 100%);
    }

    .reservation-card:hover {
      border-color: rgba(15, 118, 110, 0.34);
      box-shadow: 0 16px 32px rgba(23, 54, 48, 0.08);
      transform: translateY(-1px);
    }

    .reservation-cover {
      background:
        linear-gradient(145deg, rgba(15, 118, 110, 0.92), rgba(42, 157, 143, 0.82)),
        linear-gradient(180deg, rgba(255, 255, 255, 0.16), transparent);
    }

    .detail-row {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      border-bottom: 1px solid rgba(226, 232, 240, 0.8);
      padding: 10px 0;
      font-size: 0.95rem;
    }

    .detail-row:last-child {
      border-bottom: 0;
      padding-bottom: 0;
    }

    .detail-label {
      color: var(--muted);
    }

    .detail-value {
      text-align: right;
      font-weight: 600;
      color: var(--text);
    }

    .modal-backdrop {
      background: rgba(15, 23, 42, 0.58);
      backdrop-filter: blur(4px);
    }

    .search-input {
      border: 1px solid var(--border);
      background: #fcfefd;
      color: var(--text);
    }

    .search-input::placeholder {
      color: #7d958e;
    }
  </style>
</head>
<body>
  <div class="page-shell">
    <header class="glass-card rounded-[30px] px-6 py-6 md:px-8">
      <div class="flex flex-col gap-6 xl:flex-row xl:items-start xl:justify-between">
        <div class="max-w-3xl">
          <div class="inline-flex items-center rounded-full px-4 py-2 text-sm font-semibold uppercase tracking-[0.18em] pill">
            Player Portal
          </div>
          <h1 class="mt-5 text-3xl font-bold text-slate-800 md:text-4xl">Pickleball reservations at a glance</h1>
          <p class="mt-3 max-w-2xl text-base text-slate-500">
            Welcome back, <?= htmlspecialchars($displayName) ?>. Book a court, review your upcoming games, and keep an eye on your member perks from one clean dashboard.
          </p>
          <div class="mt-5 flex flex-wrap items-center gap-3">
            <span class="rounded-full px-4 py-2 text-sm font-semibold <?= htmlspecialchars($membershipStatusBadgeClass) ?>">
              <?= htmlspecialchars($membershipStatusLabel) ?> Membership
            </span>
            <span class="pill-muted rounded-full px-4 py-2 text-sm">
              <?= htmlspecialchars($email) ?>
            </span>
            <?php if ($contactNumber !== ''): ?>
              <span class="pill-muted rounded-full px-4 py-2 text-sm">
                <?= htmlspecialchars($contactNumber) ?>
              </span>
            <?php endif; ?>
          </div>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row xl:flex-col">
          <a href="reserve.html" class="primary-button inline-flex items-center justify-center gap-3 rounded-2xl px-5 py-3 text-sm font-semibold">
            <i class="fas fa-calendar-check"></i>
            Book a Court
          </a>
          <button type="button" onclick="loadReservations()" class="secondary-button inline-flex items-center justify-center gap-3 rounded-2xl px-5 py-3 text-sm font-semibold">
            <i class="fas fa-rotate-right"></i>
            Refresh
          </button>
          <button type="button" onclick="logout()" class="secondary-button inline-flex items-center justify-center gap-3 rounded-2xl px-5 py-3 text-sm font-semibold">
            <i class="fas fa-sign-out-alt"></i>
            Logout
          </button>
        </div>
      </div>
    </header>

    <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      <div class="stat-card rounded-[24px] px-5 py-5">
        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Membership</div>
        <div id="stat-membership" class="mt-3 text-2xl font-bold text-slate-800"><?= htmlspecialchars($isMember ? $membershipPlanLabel : $membershipStatusLabel) ?></div>
        <p class="mt-2 text-sm text-slate-500">
          <?= htmlspecialchars($isMember ? 'Member pricing and perks are active on eligible bookings.' : 'Membership can be activated by the venue when you enroll.') ?>
        </p>
      </div>

      <div class="stat-card rounded-[24px] px-5 py-5">
        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Upcoming Bookings</div>
        <div id="stat-upcoming-count" class="mt-3 text-2xl font-bold text-slate-800">0</div>
        <p class="mt-2 text-sm text-slate-500">Future reservations in your account.</p>
      </div>

      <div class="stat-card rounded-[24px] px-5 py-5">
        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Reserved Hours</div>
        <div id="stat-total-hours" class="mt-3 text-2xl font-bold text-slate-800">0</div>
        <p class="mt-2 text-sm text-slate-500">Total hours across the current reservation list.</p>
      </div>

      <div class="stat-card rounded-[24px] px-5 py-5">
        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Next Booking</div>
        <div id="stat-next-booking" class="mt-3 text-lg font-bold text-slate-800">No upcoming booking</div>
        <p id="stat-next-subtitle" class="mt-2 text-sm text-slate-500">Book a court to see it here.</p>
      </div>
    </section>

    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.6fr)_360px]">
      <section class="glass-card rounded-[30px] px-5 py-5 md:px-6">
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <div class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">My Reservations</div>
            <h2 class="mt-1 text-2xl font-bold text-slate-800">Upcoming games and booking history</h2>
            <p class="mt-2 text-sm text-slate-500">
              Search by court, date, payment method, or booking source. Open any reservation for more detail or cancel it if your plans changed.
            </p>
          </div>
          <div class="w-full md:max-w-sm">
            <label for="reservation-search" class="sr-only">Search reservations</label>
            <input
              id="reservation-search"
              type="text"
              class="search-input w-full rounded-2xl px-4 py-3 text-sm"
              placeholder="Search your reservations"
              oninput="filterReservations()"
            />
          </div>
        </div>

        <div id="reservation-list" class="mt-6 grid gap-4">
          <div class="reservation-card rounded-[24px] px-5 py-5 text-sm text-slate-500">Loading reservations...</div>
        </div>
      </section>

      <aside class="space-y-6">
        <section class="glass-card rounded-[30px] px-5 py-5">
          <div class="flex items-center justify-between gap-3">
            <div>
              <div class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">Notifications</div>
              <div class="mt-1 text-lg font-semibold text-slate-800">Booking updates</div>
            </div>
            <span class="<?= $playerUnreadNotifications > 0 ? 'rounded-full border border-amber-200 bg-amber-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em] text-amber-800 shadow-sm' : 'pill-muted rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em]' ?>">
              <?= (int) $playerUnreadNotifications ?> unread
            </span>
          </div>

          <?php if ($playerUnreadNotifications > 0): ?>
            <form method="post" action="api/mark_notifications_read.php" class="mt-4">
              <input type="hidden" name="scope" value="all">
              <input type="hidden" name="next" value="../dashboard.php">
              <button type="submit" class="secondary-button inline-flex items-center gap-2 rounded-2xl px-4 py-3 text-sm font-semibold">
                <i class="fas fa-check-double"></i>
                Mark all read
              </button>
            </form>
          <?php endif; ?>

          <div class="mt-4 space-y-3">
            <?php if ($playerNotifications === []): ?>
              <div class="rounded-2xl bg-slate-50 px-4 py-4 text-sm text-slate-500">
                New booking and payment updates will appear here.
              </div>
            <?php else: ?>
              <?php foreach ($playerNotifications as $notification): ?>
                <div class="rounded-2xl border <?= (int) ($notification['is_read'] ?? 0) === 1 ? 'border-slate-200 bg-slate-50' : 'border-amber-300 border-l-4 border-l-amber-500 bg-gradient-to-br from-amber-50 via-white to-amber-100/70 shadow-[0_12px_28px_rgba(245,158,11,0.14)] ring-1 ring-amber-200/70' ?> px-4 py-4">
                  <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                      <div class="flex flex-wrap items-center gap-2">
                        <span class="<?= htmlspecialchars(dashboardNotificationBadgeClass((string) ($notification['type'] ?? 'general'))) ?> rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em]">
                          <?= htmlspecialchars(str_replace('_', ' ', (string) ($notification['type'] ?? 'update'))) ?>
                        </span>
                        <?php if ((int) ($notification['is_read'] ?? 0) === 0): ?>
                          <span class="inline-flex items-center gap-2 rounded-full bg-amber-500 px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em] text-white shadow-sm">
                            <span class="inline-flex h-2.5 w-2.5 rounded-full bg-white animate-pulse"></span>
                            Unread
                          </span>
                        <?php endif; ?>
                      </div>
                      <div class="mt-3 text-base font-semibold <?= (int) ($notification['is_read'] ?? 0) === 1 ? 'text-slate-800' : 'text-amber-900' ?>"><?= htmlspecialchars((string) ($notification['title'] ?? 'Notification')) ?></div>
                      <p class="mt-2 text-sm text-slate-600"><?= htmlspecialchars((string) ($notification['message'] ?? '')) ?></p>
                      <div class="mt-3 text-xs uppercase tracking-[0.14em] <?= (int) ($notification['is_read'] ?? 0) === 1 ? 'text-slate-400' : 'text-amber-700' ?>"><?= htmlspecialchars(notificationsTimeAgo((string) ($notification['created_at'] ?? ''))) ?></div>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-2">
                      <?php if (!empty($notification['link_url'])): ?>
                        <a href="<?= htmlspecialchars((string) $notification['link_url']) ?>" class="secondary-button rounded-xl px-3 py-2 text-xs font-semibold">
                          Open
                        </a>
                      <?php endif; ?>
                      <?php if ((int) ($notification['is_read'] ?? 0) === 0): ?>
                        <form method="post" action="api/mark_notifications_read.php">
                          <input type="hidden" name="notification_id" value="<?= (int) ($notification['id'] ?? 0) ?>">
                          <input type="hidden" name="next" value="../dashboard.php">
                          <button type="submit" class="text-xs font-semibold text-teal-700 hover:text-teal-800">Mark read</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>

        <section class="glass-card rounded-[30px] px-5 py-5">
          <div class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">Upcoming Reminders</div>
          <div class="mt-4 space-y-3 text-sm text-slate-600">
            <?php if ($upcomingReminderRows === []): ?>
              <div class="rounded-2xl bg-slate-50 px-4 py-4">
                No upcoming reservations need your attention right now.
              </div>
            <?php else: ?>
              <?php foreach ($upcomingReminderRows as $reminder): ?>
                <div class="rounded-2xl bg-slate-50 px-4 py-4">
                  <div class="flex items-start justify-between gap-3">
                    <div>
                      <div class="text-base font-semibold text-slate-800"><?= htmlspecialchars($reminder['court']) ?></div>
                      <div class="mt-1 text-sm text-slate-500"><?= htmlspecialchars(membershipFormatDate($reminder['date'])) ?> • <?= htmlspecialchars($reminder['time_range']) ?></div>
                    </div>
                    <span class="pill-muted rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em]">
                      <?= $reminder['hours_until'] <= 0 ? 'Soon' : htmlspecialchars((string) $reminder['hours_until']) . 'h' ?>
                    </span>
                  </div>
                  <div class="mt-3 text-xs uppercase tracking-[0.14em] text-slate-400">
                    <?= htmlspecialchars(ucfirst((string) $reminder['payment_status'])) ?> payment status
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>

        <section class="glass-card rounded-[30px] px-5 py-5">
          <div class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">Quick Actions</div>
          <div class="mt-4 grid gap-3">
            <a href="reserve.html" class="primary-button inline-flex items-center justify-center gap-3 rounded-2xl px-4 py-3 text-sm font-semibold">
              <i class="fas fa-plus-circle"></i>
              New Reservation
            </a>
            <button type="button" onclick="scrollToReservations()" class="secondary-button inline-flex items-center justify-center gap-3 rounded-2xl px-4 py-3 text-sm font-semibold">
              <i class="fas fa-list-ul"></i>
              Review My Bookings
            </button>
          </div>
        </section>

        <section class="glass-card rounded-[30px] px-5 py-5">
          <div class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">Account Snapshot</div>
          <div class="mt-4 space-y-3">
            <div class="rounded-2xl bg-slate-50 px-4 py-4">
              <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Name</div>
              <div class="mt-2 text-base font-semibold text-slate-800"><?= htmlspecialchars($displayName) ?></div>
            </div>
            <div class="rounded-2xl bg-slate-50 px-4 py-4">
              <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Role</div>
              <div class="mt-2 text-base font-semibold text-slate-800"><?= htmlspecialchars(ucfirst($role)) ?></div>
            </div>
            <div class="rounded-2xl bg-slate-50 px-4 py-4">
              <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Membership Status</div>
              <div class="mt-2 text-base font-semibold text-slate-800"><?= htmlspecialchars($membershipStatusLabel) ?></div>
            </div>
            <div class="rounded-2xl bg-slate-50 px-4 py-4">
              <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Membership Plan</div>
              <div class="mt-2 text-base font-semibold text-slate-800"><?= htmlspecialchars($membershipPlanLabel) ?></div>
            </div>
            <div class="rounded-2xl bg-slate-50 px-4 py-4">
              <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Member Since</div>
              <div class="mt-2 text-base font-semibold text-slate-800"><?= htmlspecialchars($memberSinceLabel) ?></div>
            </div>
            <div class="rounded-2xl bg-slate-50 px-4 py-4">
              <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Expiry</div>
              <div class="mt-2 text-base font-semibold text-slate-800"><?= htmlspecialchars($membershipExpiryLabel) ?></div>
            </div>
            <div class="rounded-2xl bg-slate-50 px-4 py-4">
              <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Contact</div>
              <div class="mt-2 text-base font-semibold text-slate-800"><?= htmlspecialchars($contactNumber !== '' ? $contactNumber : 'Not set') ?></div>
            </div>
          </div>
        </section>

        <section class="glass-card rounded-[30px] px-5 py-5">
          <div class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700"><?= htmlspecialchars($isMember ? 'Member Benefits' : 'Membership Notes') ?></div>
          <div class="mt-4 space-y-3 text-sm text-slate-600">
            <?php if ($isMember): ?>
              <?php foreach ($membershipBenefits as $benefit): ?>
                <div class="<?= $benefit === $membershipBenefits[0] ? 'pill-success' : 'rounded-2xl bg-slate-50' ?> rounded-2xl px-4 py-4">
                  <?= htmlspecialchars($benefit) ?>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="rounded-2xl bg-slate-50 px-4 py-4">
                Membership is currently <?= htmlspecialchars(strtolower($membershipStatusLabel)) ?> on this account.
              </div>
              <div class="rounded-2xl bg-slate-50 px-4 py-4">
                Ask the front desk if you want member access, plan details, or renewal help added later.
              </div>
            <?php endif; ?>
          </div>
        </section>
      </aside>
    </div>
  </div>

  <div id="reservation-modal" class="modal-backdrop fixed inset-0 z-50 hidden items-center justify-center px-4">
    <div class="glass-card w-full max-w-lg rounded-[28px] px-6 py-6">
      <div class="flex items-start justify-between gap-4">
        <div>
          <div class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">Reservation Details</div>
          <h3 id="modal-title" class="mt-2 text-2xl font-bold text-slate-800">Court Reservation</h3>
        </div>
        <button type="button" onclick="closeModal()" class="secondary-button rounded-xl px-3 py-2">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <div id="modal-body" class="mt-5"></div>
    </div>
  </div>

  <script>
    const reservationList = document.getElementById("reservation-list");
    const reservationModal = document.getElementById("reservation-modal");
    const numberFormatter = new Intl.NumberFormat("en-PH", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
    let allReservations = [];

    document.addEventListener("DOMContentLoaded", function () {
      reservationList.addEventListener("click", handleReservationListClick);
      reservationModal.addEventListener("click", function (event) {
        if (event.target === reservationModal) {
          closeModal();
        }
      });

      document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
          closeModal();
        }
      });

      loadReservations();
    });

    function logout() {
      fetch("api/logout.php", { method: "POST" }).finally(function () {
        window.location.href = "index.html";
      });
    }

    function scrollToReservations() {
      const searchInput = document.getElementById("reservation-search");
      searchInput.scrollIntoView({
        behavior: "smooth",
        block: "center"
      });
      searchInput.focus();
    }

    function escapeHtml(value) {
      return String(value ?? "").replace(/[&<>'\"]/g, function (character) {
        return {
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          "'": "&#39;",
          "\"": "&quot;"
        }[character];
      });
    }

    function formatCurrency(value) {
      return `P${numberFormatter.format(Number(value) || 0)}`;
    }

    function formatDisplayDate(dateStr) {
      if (!dateStr) {
        return "No date";
      }

      const parsedDate = new Date(`${dateStr}T00:00:00`);
      if (Number.isNaN(parsedDate.getTime())) {
        return dateStr;
      }

      return parsedDate.toLocaleDateString(undefined, {
        weekday: "short",
        month: "short",
        day: "numeric",
        year: "numeric"
      });
    }

    function normalizeTimeValue(timeStr) {
      if (!timeStr) {
        return "";
      }

      const parts = String(timeStr).trim().split(":");
      if (parts.length < 2) {
        return "";
      }

      const hour = Number(parts[0]);
      const minute = Number(parts[1]);
      const second = parts.length > 2 ? Number(parts[2]) : 0;
      if ([hour, minute, second].some(function (part) { return Number.isNaN(part); })) {
        return "";
      }

      return `${hour.toString().padStart(2, "0")}:${minute.toString().padStart(2, "0")}:${second.toString().padStart(2, "0")}`;
    }

    function addOneHour(timeStr) {
      const normalized = normalizeTimeValue(timeStr);
      if (!normalized) {
        return "";
      }

      const [hour, minute] = normalized.split(":").map(Number);
      const nextHour = hour + 1;
      return `${nextHour.toString().padStart(2, "0")}:${minute.toString().padStart(2, "0")}:00`;
    }

    function formatTo12Hour(timeStr) {
      const normalized = normalizeTimeValue(timeStr);
      if (!normalized) {
        return "N/A";
      }

      const [hour, minute] = normalized.split(":").map(Number);
      const suffix = hour >= 12 ? "PM" : "AM";
      const hour12 = hour % 12 || 12;
      return `${hour12}:${minute.toString().padStart(2, "0")} ${suffix}`;
    }

    function buildTimeRange(timeValue) {
      const slots = String(timeValue || "")
        .split(",")
        .map(function (slot) { return normalizeTimeValue(slot); })
        .filter(Boolean)
        .sort();

      if (slots.length === 0) {
        return "Time unavailable";
      }

      const start = slots[0];
      const end = addOneHour(slots[slots.length - 1]);
      return `${formatTo12Hour(start)} - ${formatTo12Hour(end)}`;
    }

    function reservationHours(reservation) {
      return String(reservation.time || "")
        .split(",")
        .map(function (slot) { return normalizeTimeValue(slot); })
        .filter(Boolean)
        .length;
    }

    function reservationStartDate(reservation) {
      const firstSlot = String(reservation.time || "")
        .split(",")
        .map(function (slot) { return normalizeTimeValue(slot); })
        .filter(Boolean)
        .sort()[0];

      if (!reservation.date || !firstSlot) {
        return null;
      }

      const startDate = new Date(`${reservation.date}T${firstSlot}`);
      return Number.isNaN(startDate.getTime()) ? null : startDate;
    }

    function getPaymentMethodLabel(method) {
      if (!method) {
        return "Not set";
      }

      if (method === "gcash-maya") {
        return "GCash / Maya";
      }

      return method
        .split("-")
        .map(function (part) {
          return part.charAt(0).toUpperCase() + part.slice(1);
        })
        .join(" ");
    }

    function getBookingSourceLabel(source) {
      return source === "walk-in" ? "Walk-in" : "Advance";
    }

    function getStatusBadgeClass(status) {
      const normalized = String(status || "pending").toLowerCase();
      return normalized === "paid" ? "pill-success" : "pill-muted";
    }

    async function loadReservations() {
      reservationList.innerHTML = '<div class="reservation-card rounded-[24px] px-5 py-5 text-sm text-slate-500">Loading reservations...</div>';

      try {
        const response = await fetch("api/my_reservations.php", {
          headers: { Accept: "application/json" }
        });
        const result = await response.json();

        if (!response.ok || !Array.isArray(result)) {
          throw new Error("Unable to load reservations.");
        }

        allReservations = result.slice().sort(function (first, second) {
          const firstStart = reservationStartDate(first);
          const secondStart = reservationStartDate(second);
          const firstTime = firstStart ? firstStart.getTime() : Number.MAX_SAFE_INTEGER;
          const secondTime = secondStart ? secondStart.getTime() : Number.MAX_SAFE_INTEGER;
          return firstTime - secondTime;
        });

        updateDashboardStats(allReservations);
        renderReservations(allReservations);
      } catch (error) {
        console.error(error);
        reservationList.innerHTML = '<div class="reservation-card rounded-[24px] px-5 py-5 text-sm text-red-600">We could not load your reservations right now. Please try refreshing the page.</div>';
      }
    }

    function updateDashboardStats(reservations) {
      const now = new Date();
      const upcomingReservations = reservations.filter(function (reservation) {
        const startDate = reservationStartDate(reservation);
        return startDate instanceof Date && startDate >= now;
      });
      const totalHours = reservations.reduce(function (sum, reservation) {
        return sum + reservationHours(reservation);
      }, 0);

      document.getElementById("stat-upcoming-count").textContent = String(upcomingReservations.length);
      document.getElementById("stat-total-hours").textContent = `${totalHours} hour${totalHours === 1 ? "" : "s"}`;

      if (upcomingReservations.length === 0) {
        document.getElementById("stat-next-booking").textContent = "No upcoming booking";
        document.getElementById("stat-next-subtitle").textContent = "Book a court to see it here.";
        return;
      }

      const nextReservation = upcomingReservations[0];
      document.getElementById("stat-next-booking").textContent = nextReservation.court || "Reserved court";
      document.getElementById("stat-next-subtitle").textContent = `${formatDisplayDate(nextReservation.date)} | ${buildTimeRange(nextReservation.time)}`;
    }

    function filterReservations() {
      const searchValue = document.getElementById("reservation-search").value.trim().toLowerCase();
      if (searchValue === "") {
        renderReservations(allReservations);
        return;
      }

      const filtered = allReservations.filter(function (reservation) {
        return [
          reservation.court,
          reservation.date,
          reservation.payment_method,
          reservation.payment_status,
          reservation.booking_source,
          reservation.discount_label
        ].some(function (value) {
          return String(value || "").toLowerCase().includes(searchValue);
        });
      });

      renderReservations(filtered);
    }

    function renderReservations(reservations) {
      if (!Array.isArray(reservations) || reservations.length === 0) {
        reservationList.innerHTML = `
          <div class="reservation-card rounded-[24px] px-6 py-8 text-center">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-teal-50 text-teal-700">
              <i class="fas fa-calendar-plus text-xl"></i>
            </div>
            <h3 class="mt-4 text-lg font-semibold text-slate-800">No reservations found</h3>
            <p class="mt-2 text-sm text-slate-500">Start with a fresh booking and your upcoming games will appear here.</p>
            <a href="reserve.html" class="primary-button mt-5 inline-flex items-center gap-2 rounded-2xl px-4 py-3 text-sm font-semibold">
              <i class="fas fa-plus-circle"></i>
              Reserve a Court
            </a>
          </div>
        `;
        return;
      }

      reservationList.innerHTML = reservations.map(function (reservation) {
        const statusClass = getStatusBadgeClass(reservation.payment_status);
        const hours = reservationHours(reservation);
        const discountMarkup = Number(reservation.discount_amount || 0) > 0
          ? `<span class="pill-success rounded-full px-3 py-1 text-xs font-semibold">${escapeHtml(reservation.discount_label || "Discount")} saved ${escapeHtml(formatCurrency(reservation.discount_amount))}</span>`
          : "";

        return `
          <article class="reservation-card rounded-[26px] overflow-hidden">
            <div class="grid lg:grid-cols-[160px_minmax(0,1fr)]">
              <div class="reservation-cover flex items-end px-5 py-5 text-white">
                <div>
                  <div class="text-xs font-semibold uppercase tracking-[0.2em] text-white/75">${escapeHtml(getBookingSourceLabel(reservation.booking_source))}</div>
                  <div class="mt-2 text-2xl font-bold">${escapeHtml(reservation.court || "Court")}</div>
                  <div class="mt-2 text-sm text-white/80">${escapeHtml(formatDisplayDate(reservation.date))}</div>
                </div>
              </div>
              <div class="px-5 py-5">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                  <div>
                    <div class="flex flex-wrap items-center gap-2">
                      <span class="pill rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em]">Pickleball</span>
                      <span class="${statusClass} rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em]">${escapeHtml(reservation.payment_status || "pending")}</span>
                      ${discountMarkup}
                    </div>
                    <div class="mt-4 text-lg font-semibold text-slate-800">${escapeHtml(buildTimeRange(reservation.time))}</div>
                    <p class="mt-2 text-sm text-slate-500">
                      ${escapeHtml(String(hours))} hour${hours === 1 ? "" : "s"} reserved | ${escapeHtml(getPaymentMethodLabel(reservation.payment_method))} | ${escapeHtml(formatCurrency(reservation.payment || 0))}
                    </p>
                  </div>
                  <div class="flex flex-col gap-3 sm:flex-row">
                    <button type="button" class="secondary-button rounded-2xl px-4 py-3 text-sm font-semibold" data-action="view" data-id="${escapeHtml(reservation.id)}">
                      <i class="fas fa-eye mr-2"></i>
                      View
                    </button>
                    <button type="button" class="danger-button rounded-2xl px-4 py-3 text-sm font-semibold" data-action="cancel" data-id="${escapeHtml(reservation.id)}">
                      <i class="fas fa-trash-alt mr-2"></i>
                      Cancel
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </article>
        `;
      }).join("");
    }

    function handleReservationListClick(event) {
      const actionButton = event.target.closest("[data-action]");
      if (!actionButton) {
        return;
      }

      const reservationId = Number(actionButton.dataset.id || 0);
      if (!reservationId) {
        return;
      }

      if (actionButton.dataset.action === "view") {
        showReservationModal(reservationId);
        return;
      }

      if (actionButton.dataset.action === "cancel") {
        cancelReservation(reservationId);
      }
    }

    function showReservationModal(reservationId) {
      const reservation = allReservations.find(function (item) {
        return Number(item.id) === reservationId;
      });

      if (!reservation) {
        return;
      }

      document.getElementById("modal-title").textContent = reservation.court || "Court Reservation";
      document.getElementById("modal-body").innerHTML = `
        <div class="space-y-1">
          <div class="pill rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em] inline-flex">
            ${escapeHtml(getBookingSourceLabel(reservation.booking_source))}
          </div>
          <div class="mt-3 text-sm text-slate-500">Reservation #${escapeHtml(reservation.id)}</div>
        </div>
        <div class="mt-5">
          <div class="detail-row">
            <span class="detail-label">Date</span>
            <span class="detail-value">${escapeHtml(formatDisplayDate(reservation.date))}</span>
          </div>
          <div class="detail-row">
            <span class="detail-label">Time</span>
            <span class="detail-value">${escapeHtml(buildTimeRange(reservation.time))}</span>
          </div>
          <div class="detail-row">
            <span class="detail-label">Duration</span>
            <span class="detail-value">${escapeHtml(String(reservationHours(reservation)))} hour${reservationHours(reservation) === 1 ? "" : "s"}</span>
          </div>
          <div class="detail-row">
            <span class="detail-label">Payment Method</span>
            <span class="detail-value">${escapeHtml(getPaymentMethodLabel(reservation.payment_method))}</span>
          </div>
          <div class="detail-row">
            <span class="detail-label">Payment Status</span>
            <span class="detail-value">${escapeHtml(String(reservation.payment_status || "pending"))}</span>
          </div>
          <div class="detail-row">
            <span class="detail-label">Subtotal</span>
            <span class="detail-value">${escapeHtml(formatCurrency(reservation.subtotal || 0))}</span>
          </div>
          <div class="detail-row">
            <span class="detail-label">Discount</span>
            <span class="detail-value">${Number(reservation.discount_amount || 0) > 0 ? `${escapeHtml(reservation.discount_label || "Discount")} (-${escapeHtml(formatCurrency(reservation.discount_amount))})` : "None"}</span>
          </div>
          <div class="detail-row">
            <span class="detail-label">Total Paid / Due</span>
            <span class="detail-value">${escapeHtml(formatCurrency(reservation.payment || 0))}</span>
          </div>
        </div>
      `;

      reservationModal.classList.remove("hidden");
      reservationModal.classList.add("flex");
    }

    function closeModal() {
      reservationModal.classList.add("hidden");
      reservationModal.classList.remove("flex");
    }

    async function cancelReservation(reservationId) {
      if (!confirm("Cancel this reservation?")) {
        return;
      }

      try {
        const response = await fetch("api/cancel_reservation.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ reservation_id: reservationId })
        });
        const result = await response.json();

        if (!response.ok || !result.success) {
          throw new Error(result.message || "Unable to cancel reservation.");
        }

        closeModal();
        loadReservations();
      } catch (error) {
        alert(error.message || "Unable to cancel reservation.");
      }
    }
  </script>
</body>
</html>
