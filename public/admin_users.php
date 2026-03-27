<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') === 'user') {
    header("Location: ../login.html");
    exit;
}

require_once 'includes/db.php';
require_once 'includes/membership.php';

$filterRole = trim((string) ($_GET['filter_role'] ?? ''));
$filterMembership = membershipNormalizeStatus((string) ($_GET['filter_membership'] ?? ''));
$applyMembershipFilter = isset($_GET['filter_membership']) && trim((string) $_GET['filter_membership']) !== '';
$currentAdminId = (int) $_SESSION['user_id'];

$statement = $pdo->prepare("SELECT * FROM users WHERE id != ? ORDER BY full_name ASC, name ASC");
$statement->execute([$currentAdminId]);
$allUsers = $statement->fetchAll(PDO::FETCH_ASSOC);

$summaryCounts = [
    'total' => count($allUsers),
    MEMBERSHIP_STATUS_ACTIVE => 0,
    MEMBERSHIP_STATUS_EXPIRED => 0,
    MEMBERSHIP_STATUS_SUSPENDED => 0,
    MEMBERSHIP_STATUS_INACTIVE => 0,
];

foreach ($allUsers as $user) {
    $effectiveStatus = membershipResolveStatus($user);
    $summaryCounts[$effectiveStatus]++;
}

$users = array_values(array_filter($allUsers, static function (array $user) use ($filterRole, $applyMembershipFilter, $filterMembership): bool {
    if ($filterRole !== '' && strtolower((string) ($user['role'] ?? 'user')) !== strtolower($filterRole)) {
        return false;
    }

    if ($applyMembershipFilter && membershipResolveStatus($user) !== $filterMembership) {
        return false;
    }

    return true;
}));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pickleball Admin - Users</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/js/all.min.js" integrity="sha512-b+nQTCdtTBIRIbraqNEwsjB6UvL3UEMkXnhzd8awtCYh0Kcsjl9uEgwVFVbhoj3uu1DO1ZMacNvLoyJJiNfcvg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <link rel="stylesheet" href="styles/admin-theme.css">
</head>
<body class="admin-theme-body admin-frame-body">
  <div class="admin-page-shell">
    <div class="admin-page-header">
      <div class="admin-overline">Users & Memberships</div>
      <div class="admin-title">Manage player records and membership lifecycle</div>
      <div class="admin-copy">Review account access, activate or suspend memberships, track expiry dates, and maintain the benefit notes shown to the player dashboard.</div>
    </div>

    <div class="admin-stat-grid mb-5 md:grid-cols-2 xl:grid-cols-5">
      <div class="admin-stat-card">
        <div class="admin-stat-label">Visible Accounts</div>
        <div class="admin-stat-value"><?= count($users) ?></div>
      </div>
      <div class="admin-stat-card bg-emerald-50/70">
        <div class="admin-stat-label">Active Members</div>
        <div class="admin-stat-value"><?= (int) ($summaryCounts[MEMBERSHIP_STATUS_ACTIVE] ?? 0) ?></div>
      </div>
      <div class="admin-stat-card bg-amber-50/70">
        <div class="admin-stat-label">Expired</div>
        <div class="admin-stat-value"><?= (int) ($summaryCounts[MEMBERSHIP_STATUS_EXPIRED] ?? 0) ?></div>
      </div>
      <div class="admin-stat-card bg-rose-50/70">
        <div class="admin-stat-label">Suspended</div>
        <div class="admin-stat-value"><?= (int) ($summaryCounts[MEMBERSHIP_STATUS_SUSPENDED] ?? 0) ?></div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Inactive</div>
        <div class="admin-stat-value"><?= (int) ($summaryCounts[MEMBERSHIP_STATUS_INACTIVE] ?? 0) ?></div>
      </div>
    </div>

    <form method="get" class="admin-filter-bar mb-5 grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto]">
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <div>
          <label for="filter_role" class="admin-field-label">Filter by Role</label>
          <select name="filter_role" id="filter_role" class="admin-select" onchange="this.form.submit()">
            <option value="">All Roles</option>
            <option value="user" <?= $filterRole === 'user' ? 'selected' : '' ?>>User</option>
            <option value="admin" <?= $filterRole === 'admin' ? 'selected' : '' ?>>Admin</option>
          </select>
        </div>

        <div>
          <label for="filter_membership" class="admin-field-label">Membership Status</label>
          <select name="filter_membership" id="filter_membership" class="admin-select" onchange="this.form.submit()">
            <option value="">All Memberships</option>
            <?php foreach (membershipStatusOptions() as $statusValue => $statusLabel): ?>
              <option value="<?= htmlspecialchars($statusValue) ?>" <?= $applyMembershipFilter && $filterMembership === $statusValue ? 'selected' : '' ?>>
                <?= htmlspecialchars($statusLabel) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="flex items-end">
          <?php if ($filterRole !== '' || $applyMembershipFilter): ?>
            <a href="admin_users.php" class="admin-secondary-btn">Clear Filters</a>
          <?php else: ?>
            <div class="admin-pill">All players and staff</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="flex items-end justify-end">
        <div class="admin-pill">Visible accounts: <?= count($users) ?></div>
      </div>
    </form>

    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Login</th>
            <th>Full Name</th>
            <th>Email</th>
            <th>Contact</th>
            <th>Role</th>
            <th>Membership</th>
            <th>Plan</th>
            <th>Expiry</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $user): ?>
            <?php
            $role = strtolower((string) ($user['role'] ?? 'user'));
            $effectiveMembershipStatus = membershipResolveStatus($user);
            $roleClass = match ($role) {
                'admin' => 'bg-teal-100 text-teal-700',
                default => 'bg-slate-100 text-slate-700',
            };
            ?>
            <tr>
              <td><?= htmlspecialchars((string) ($user['name'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string) ($user['full_name'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string) ($user['email'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string) ($user['contact_number'] ?? '')) ?></td>
              <td>
                <span class="admin-tag <?= $roleClass ?>"><?= htmlspecialchars(ucfirst($role)) ?></span>
              </td>
              <td>
                <div class="flex flex-col gap-2">
                  <span class="admin-tag <?= htmlspecialchars(membershipStatusBadgeClass($effectiveMembershipStatus)) ?>">
                    <?= htmlspecialchars(membershipStatusLabel($effectiveMembershipStatus)) ?>
                  </span>
                </div>
              </td>
              <td><?= htmlspecialchars(membershipPlanLabel((string) ($user['membership_plan'] ?? ''))) ?></td>
              <td><?= htmlspecialchars((string) ($user['membership_expires_at'] ?? '') !== '' ? membershipFormatDate((string) $user['membership_expires_at']) : 'Open-ended') ?></td>
              <td>
                <div class="flex items-center gap-3">
                  <button onclick='openModal(<?= json_encode($user, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>, false)' class="admin-action-link" type="button"><i class="fas fa-edit"></i></button>
                  <button onclick='openModal(<?= json_encode($user, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>, true)' class="admin-action-link" type="button"><i class="fas fa-eye"></i></button>
                  <button onclick='confirmDelete(<?= (int) $user['id'] ?>)' class="text-red-500 hover:text-red-700" type="button"><i class="fas fa-trash"></i></button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div id="user-modal" class="admin-modal-backdrop hidden">
    <div class="admin-modal-panel max-w-3xl">
      <div class="flex items-start justify-between gap-4">
        <div>
          <div class="admin-overline">User Profile</div>
          <div id="modal-title" class="admin-title text-[1.4rem]">Edit User</div>
        </div>
        <button onclick="closeModal()" class="rounded-xl bg-slate-100 px-3 py-2 text-slate-600 hover:bg-slate-200" type="button">
          <i class="fas fa-xmark"></i>
        </button>
      </div>

      <form id="user-form" class="mt-5 grid gap-4" onsubmit="saveUser(event)">
        <input type="hidden" id="user-id" />

        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <label class="admin-field-label" for="user-name">Login</label>
            <input type="text" id="user-name" class="admin-input" />
          </div>

          <div>
            <label class="admin-field-label" for="user-full-name">Full Name</label>
            <input type="text" id="user-full-name" class="admin-input" />
          </div>

          <div>
            <label class="admin-field-label" for="user-email">Email</label>
            <input type="email" id="user-email" class="admin-input" />
          </div>

          <div>
            <label class="admin-field-label" for="user-contact">Contact Number</label>
            <input type="text" id="user-contact" class="admin-input" />
          </div>

          <div>
            <label class="admin-field-label" for="user-role">Role</label>
            <select id="user-role" class="admin-select">
              <option value="user">User</option>
              <option value="admin">Admin</option>
            </select>
          </div>

          <div>
            <label class="admin-field-label" for="user-membership-status">Membership Status</label>
            <select id="user-membership-status" class="admin-select">
              <?php foreach (membershipStatusOptions() as $statusValue => $statusLabel): ?>
                <option value="<?= htmlspecialchars($statusValue) ?>"><?= htmlspecialchars($statusLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="admin-field-label" for="user-membership-plan">Membership Plan</label>
            <input type="text" id="user-membership-plan" class="admin-input" placeholder="Example: Monthly Member" />
          </div>

          <div>
            <label class="admin-field-label" for="user-member-since">Member Since</label>
            <input type="date" id="user-member-since" class="admin-input" />
          </div>

          <div>
            <label class="admin-field-label" for="user-membership-expires">Membership Expires</label>
            <input type="date" id="user-membership-expires" class="admin-input" />
          </div>
        </div>

        <div>
          <label class="admin-field-label" for="user-membership-benefits">Benefits / Perks</label>
          <textarea id="user-membership-benefits" rows="5" class="admin-textarea" placeholder="One benefit per line. Example:
Member court rate on weekdays
Reduced processing fee
Priority booking during events"></textarea>
        </div>

        <div class="mt-2 flex justify-end gap-3">
          <button id="modal_cancel" type="button" onclick="closeModal()" class="admin-secondary-btn">Cancel</button>
          <button id="modal_submit" type="submit" class="admin-primary-btn">Save</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    const membershipStatusFieldIds = [
      "user-name",
      "user-full-name",
      "user-email",
      "user-contact",
      "user-role",
      "user-membership-status",
      "user-membership-plan",
      "user-member-since",
      "user-membership-expires",
      "user-membership-benefits"
    ];

    function openModal(user, readOnly) {
      document.getElementById("modal-title").textContent = readOnly ? "View User" : "Edit User";
      document.getElementById("user-id").value = user.id || "";
      document.getElementById("user-name").value = user.name || "";
      document.getElementById("user-full-name").value = user.full_name || "";
      document.getElementById("user-email").value = user.email || "";
      document.getElementById("user-contact").value = user.contact_number || "";
      document.getElementById("user-role").value = user.role || "user";
      document.getElementById("user-membership-status").value = user.membership_status || "inactive";
      document.getElementById("user-membership-plan").value = user.membership_plan || "";
      document.getElementById("user-member-since").value = user.member_since || "";
      document.getElementById("user-membership-expires").value = user.membership_expires_at || "";
      document.getElementById("user-membership-benefits").value = user.membership_benefits || "";

      membershipStatusFieldIds.forEach((id) => {
        document.getElementById(id).disabled = readOnly;
      });

      document.getElementById("modal_submit").style.display = readOnly ? "none" : "inline-flex";
      document.getElementById("modal_cancel").textContent = readOnly ? "Close" : "Cancel";
      document.getElementById("user-modal").classList.remove("hidden");
    }

    function closeModal() {
      document.getElementById("user-modal").classList.add("hidden");
    }

    async function saveUser(event) {
      event.preventDefault();

      const payload = {
        id: document.getElementById("user-id").value,
        name: document.getElementById("user-name").value,
        full_name: document.getElementById("user-full-name").value,
        email: document.getElementById("user-email").value,
        contact_number: document.getElementById("user-contact").value,
        role: document.getElementById("user-role").value,
        membership_status: document.getElementById("user-membership-status").value,
        membership_plan: document.getElementById("user-membership-plan").value,
        member_since: document.getElementById("user-member-since").value,
        membership_expires_at: document.getElementById("user-membership-expires").value,
        membership_benefits: document.getElementById("user-membership-benefits").value
      };

      const response = await fetch("api/update_user.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      });
      const result = await response.json();

      if (result.success) {
        alert("User updated successfully.");
        window.location.reload();
        return;
      }

      alert("Error: " + (result.message || "Failed to update user."));
    }

    async function confirmDelete(userId) {
      if (!confirm("Are you sure you want to delete this user?")) {
        return;
      }

      const response = await fetch("api/delete_user.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: userId })
      });
      const result = await response.json();

      if (result.success) {
        alert("User deleted successfully.");
        window.location.reload();
        return;
      }

      alert("Error: " + (result.message || "Failed to delete user."));
    }
  </script>
</body>
</html>
