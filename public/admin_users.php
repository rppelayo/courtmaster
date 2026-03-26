<?php
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') === 'user') {
    header("Location: ../index.html");
    exit;
}

require_once 'includes/db.php';

$filterRole = $_GET['filter_role'] ?? '';
$currentAdminId = $_SESSION['user_id'];

if ($filterRole !== '') {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id != ? AND role = ?");
    $stmt->execute([$currentAdminId, $filterRole]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id != ?");
    $stmt->execute([$currentAdminId]);
}

$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Pickleball Admin - Users</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/js/all.min.js" integrity="sha512-b+nQTCdtTBIRIbraqNEwsjB6UvL3UEMkXnhzd8awtCYh0Kcsjl9uEgwVFVbhoj3uu1DO1ZMacNvLoyJJiNfcvg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <link rel="stylesheet" href="styles/admin-theme.css">
</head>
<body class="admin-theme-body admin-frame-body">
  <div class="admin-page-shell">
    <div class="admin-page-header">
      <div class="admin-overline">User Management</div>
      <div class="admin-title">Manage accounts and access roles</div>
      <div class="admin-copy">Review customer and staff records, filter by role, and adjust account details from one place.</div>
    </div>

    <form method="get" class="admin-filter-bar mb-5 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
      <div class="w-full max-w-xs">
        <label for="filter_role" class="admin-field-label">Filter by Role</label>
        <select name="filter_role" id="filter_role" class="admin-select" onchange="this.form.submit()">
          <option value="">All Roles</option>
          <option value="owner" <?= ($filterRole === 'owner') ? 'selected' : '' ?>>Owner</option>
          <option value="user" <?= ($filterRole === 'user') ? 'selected' : '' ?>>User</option>
          <option value="subscriber" <?= ($filterRole === 'subscriber') ? 'selected' : '' ?>>Subscriber</option>
        </select>
      </div>

      <div class="admin-pill">Visible accounts: <?= count($users) ?></div>
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
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $user): ?>
            <tr>
              <td><?= htmlspecialchars($user['name'] ?? '') ?></td>
              <td><?= htmlspecialchars($user['full_name'] ?? '') ?></td>
              <td><?= htmlspecialchars($user['email'] ?? '') ?></td>
              <td><?= htmlspecialchars($user['contact_number'] ?? '') ?></td>
              <td>
                <?php
                $role = strtolower((string) ($user['role'] ?? 'user'));
                $roleClass = match ($role) {
                    'admin' => 'bg-teal-100 text-teal-700',
                    'owner' => 'bg-amber-100 text-amber-700',
                    'subscriber' => 'bg-blue-100 text-blue-700',
                    default => 'bg-slate-100 text-slate-700',
                };
                ?>
                <span class="admin-tag <?= $roleClass ?>"><?= htmlspecialchars(ucfirst($role)) ?></span>
              </td>
              <td>
                <div class="flex items-center gap-3">
                  <button onclick='openModal(<?= json_encode($user) ?>, false)' class="admin-action-link" type="button"><i class="fas fa-edit"></i></button>
                  <button onclick='openModal(<?= json_encode($user) ?>, true)' class="admin-action-link" type="button"><i class="fas fa-eye"></i></button>
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
    <div class="admin-modal-panel">
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
            <option value="subscriber">Subscriber</option>
            <option value="owner">Owner</option>
            <option value="admin">Admin</option>
          </select>
        </div>

        <div class="mt-2 flex justify-end gap-3">
          <button id="modal_cancel" type="button" onclick="closeModal()" class="admin-secondary-btn">Cancel</button>
          <button id="modal_submit" type="submit" class="admin-primary-btn">Save</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function openModal(user, readOnly) {
      document.getElementById("modal-title").textContent = readOnly ? "View User" : "Edit User";
      document.getElementById("user-id").value = user.id;
      document.getElementById("user-name").value = user.name;
      document.getElementById("user-full-name").value = user.full_name;
      document.getElementById("user-email").value = user.email;
      document.getElementById("user-contact").value = user.contact_number;
      document.getElementById("user-role").value = user.role;

      ["user-name", "user-full-name", "user-email", "user-contact", "user-role"].forEach((id) => {
        document.getElementById(id).disabled = readOnly;
      });

      document.getElementById("modal_submit").style.display = readOnly ? "none" : "inline-flex";
      document.getElementById("modal_cancel").textContent = readOnly ? "Close" : "Cancel";
      document.getElementById("user-modal").classList.remove("hidden");
    }

    function closeModal() {
      document.getElementById("user-modal").classList.add("hidden");
    }

    function saveUser(event) {
      event.preventDefault();

      const payload = {
        id: document.getElementById("user-id").value,
        name: document.getElementById("user-name").value,
        full_name: document.getElementById("user-full-name").value,
        email: document.getElementById("user-email").value,
        contact_number: document.getElementById("user-contact").value,
        role: document.getElementById("user-role").value
      };

      fetch("api/update_user.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      })
      .then((response) => response.json())
      .then((result) => {
        if (result.success) {
          alert("User updated successfully.");
          window.location.reload();
          return;
        }

        alert("Error: " + result.message);
      });
    }

    function confirmDelete(userId) {
      if (!confirm("Are you sure you want to delete this user?")) {
        return;
      }

      fetch("api/delete_user.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: userId })
      })
      .then((response) => response.json())
      .then((result) => {
        if (result.success) {
          alert("User deleted successfully.");
          window.location.reload();
          return;
        }

        alert("Error: " + result.message);
      });
    }
  </script>
</body>
</html>
