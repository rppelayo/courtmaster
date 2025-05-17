<?php
session_start();

 if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.html");
    exit;
} 

require_once 'includes/db.php';

//$users = $pdo->query("SELECT id, name, full_name, email, contact_number, role FROM users where id !=?")->fetchAll(PDO::FETCH_ASSOC);
$current_admin_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM users WHERE id != ?");
$stmt->execute([$current_admin_id]);
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin - Users</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/js/all.min.js" integrity="sha512-b+nQTCdtTBIRIbraqNEwsjB6UvL3UEMkXnhzd8awtCYh0Kcsjl9uEgwVFVbhoj3uu1DO1ZMacNvLoyJJiNfcvg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
</head>
<body class="bg-gray-100 p-6">
  <h1 class="text-2xl text-orange-600 font-bold mb-4">User Management</h1>

  <table class="min-w-full bg-white shadow rounded">
    <thead>
      <tr class="bg-gray-200 text-left">
        <th class="p-3">Login</th>
        <th class="p-3">Full Name</th>
        <th class="p-3">Email</th>
        <th class="p-3">Contact</th>
        <th class="p-3">Role</th>
        <th class="p-3">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $user): ?>
        <tr class="border-t">
          <td class="p-3"><?= htmlspecialchars($user['name'] ?? '') ?></td>
          <td class="p-3"><?= htmlspecialchars($user['full_name'] ?? '') ?></td>
          <td class="p-3"><?= htmlspecialchars($user['email'] ?? '') ?></td>
          <td class="p-3"><?= htmlspecialchars($user['contact_number'] ?? '') ?></td>
          <td class="p-3"><?= htmlspecialchars($user['role'] ?? '') ?></td>
          <td class="p-3 space-x-2">
            <button onclick='openModal(<?= json_encode($user) ?>, false)' class="text-blue-500"><i class="fas fa-edit"></i></button>
            <button onclick='openModal(<?= json_encode($user) ?>, true)' class="text-green-500"><i class="fas fa-eye"></i></button>
            <button onclick='confirmDelete(<?= $user['id'] ?>)' class="text-red-500"><i class="fas fa-trash"></i></button>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Modal -->
  <div id="user-modal" class="fixed inset-0 flex items-center justify-center bg-black bg-opacity-50 z-50 hidden">
    <div class="bg-white p-6 rounded shadow-lg w-full max-w-md">
      <h2 id="modal-title" class="text-xl text-orange-700  font-semibold mb-4"></h2>
      <form id="user-form" onsubmit="saveUser(event)">
        <input type="hidden" id="user-id" />
        <div class="mb-3">
          <label class="block text-sm">Login</label>
          <input type="text" id="user-name" class="w-full border rounded px-3 py-2" />
        </div>
        <div class="mb-3">
          <label class="block text-sm">Full Name</label>
          <input type="text" id="user-full-name" class="w-full border rounded px-3 py-2" />
        </div>
        <div class="mb-3">
          <label class="block text-sm">Email</label>
          <input type="email" id="user-email" class="w-full border rounded px-3 py-2" />
        </div>
        <div class="mb-3">
          <label class="block text-sm">Contact Number</label>
          <input type="text" id="user-contact" class="w-full border rounded px-3 py-2" />
        </div>
        <div class="mb-3">
          <label class="block text-sm">Role</label>
          <select id="user-role" class="w-full border rounded px-3 py-2">
            <option value="user">User</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <div class="flex justify-end space-x-2">
          <button id="modal_cancel" type="button" onclick="closeModal()" class="px-4 py-2 bg-gray-300 rounded">Cancel</button>
          <button id="modal_submit" type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Save</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function openModal(user, readOnly) {
      document.getElementById('modal-title').textContent = readOnly ? 'View User' : 'Edit User';
      document.getElementById('user-id').value = user.id;
      document.getElementById('user-name').value = user.name;
      document.getElementById('user-full-name').value = user.full_name;
      document.getElementById('user-email').value = user.email;
      document.getElementById('user-contact').value = user.contact_number;
      document.getElementById('user-role').value = user.role;

      ['user-name', 'user-full-name', 'user-email', 'user-contact', 'user-role'].forEach(id => {
        document.getElementById(id).disabled = readOnly;
      }); 

      document.getElementById("modal_submit").style.display =  readOnly ? 'none' : 'block';
      document.getElementById("modal_cancel").textContent =  readOnly ? 'Close' : 'Cancel';

      //document.getElementById('user-form').style.display = readOnly ? 'none' : 'block';
      document.getElementById('user-modal').classList.remove('hidden');
    }

    function closeModal() {
      document.getElementById('user-modal').classList.add('hidden');
    }

    function saveUser(e) {
      e.preventDefault();
      const id = document.getElementById('user-id').value;
      const name = document.getElementById('user-name').value;
      const full_name = document.getElementById('user-full-name').value;
      const email = document.getElementById('user-email').value;
      const contact_number = document.getElementById('user-contact').value;
      const role = document.getElementById('user-role').value;

      fetch('../api/update_user.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, name, full_name, email, contact_number, role })
      })
      .then(res => res.json())
      .then(result => {
        if (result.success) {
          alert('User updated successfully.');
          window.location.reload();
        } else {
          alert('Error: ' + result.message);
        }
      });
    }

    function confirmDelete(userId) {
      if (confirm('Are you sure you want to delete this user?')) {
        fetch('../api/delete_user.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: userId })
        })
        .then(res => res.json())
        .then(result => {
          if (result.success) {
            alert('User deleted successfully.');
            window.location.reload();
          } else {
            alert('Error: ' + result.message);
          }
        });
      }
    }
  </script>
</body>
</html>
