<?php
session_start();
require_once "includes/db.php";

// Redirect if not admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'user') {
    header("Location: ../index.html");
    exit;
}

$owner_id = $_SESSION['user_id'];
$sport_filter = $_GET['sport'] ?? '';
$params = [];
$where_clauses = [];
$joins = "LEFT JOIN reservation_slots rs ON rs.reservation_id = r.id";

if ($_SESSION['role'] === 'owner') {
    $joins = "JOIN courts c ON r.court_id = c.id
              LEFT JOIN reservation_slots rs ON rs.reservation_id = r.id";
    $where_clauses[] = "c.owner_id = ?";
    $params[] = $owner_id;

    if (!empty($sport_filter)) {
        $where_clauses[] = "c.type = ?";
        $params[] = $sport_filter;
    }
} else {
    // role is admin
    if (!empty($sport_filter)) {
        $joins = "JOIN courts c ON r.court_id = c.id
                  LEFT JOIN reservation_slots rs ON rs.reservation_id = r.id";
        $where_clauses[] = "c.type = ?";
        $params[] = $sport_filter;
    }
    // else: no need to join courts
}

// Build the WHERE clause
$where_sql = '';
if (!empty($where_clauses)) {
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
}

$select_c_type = strpos($joins, 'courts c') !== false ? ", c.type AS sport" : "";

$sql = "
    SELECT r.* $select_c_type, GROUP_CONCAT(DISTINCT rs.time ORDER BY rs.time) AS time_slots
    FROM reservations r
    $joins
    $where_sql
    GROUP BY r.id
    ORDER BY r.created_at;
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);


/* if ($_SESSION['role'] === 'owner') {
    $stmt = $pdo->prepare("
        SELECT r.*, GROUP_CONCAT(DISTINCT rs.time ORDER BY rs.time) AS time_slots
        FROM reservations r
        JOIN courts c ON r.court_id = c.id
        LEFT JOIN reservation_slots rs ON rs.reservation_id = r.id
        WHERE c.owner_id = ?
        GROUP BY r.id
        ORDER BY r.date;
    ");
    $stmt->execute([$owner_id]);
} else {
    $stmt = $pdo->query("
        SELECT r.*, GROUP_CONCAT(DISTINCT rs.time ORDER BY rs.time) AS time_slots
        FROM reservations r
        LEFT JOIN reservation_slots rs ON rs.reservation_id = r.id
        GROUP BY r.id
        ORDER BY r.created_at;
    ");
} */


//$reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Admin Reservations – CourtMaster</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/js/all.min.js" integrity="sha512-b+nQTCdtTBIRIbraqNEwsjB6UvL3UEMkXnhzd8awtCYh0Kcsjl9uEgwVFVbhoj3uu1DO1ZMacNvLoyJJiNfcvg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <script>
    async function openEditModal(id) {
      const res = await fetch(`api/get_reservation.php?id=${id}`);
      const data = await res.json();
      if (data && data.success) {
        const reservation = data.reservation;
        document.getElementById('edit-id').value = reservation.id;
        document.getElementById('edit-user').value = reservation.user_id;
        document.getElementById('edit-court').value = reservation.court;
        if(reservation.section_number == "0") {
          document.getElementById('section-container').style.display = "none";
        } else{
          document.getElementById('section-container').style.display = "block";
          document.getElementById('edit-section').value = reservation.section_number;
        }
        
        document.getElementById('edit-date').value = reservation.date;
        document.getElementById('edit-time').value = reservation.time;
        document.getElementById('payment_status_field').value = reservation.payment_status;
        document.getElementById('editModal').classList.remove('hidden');
      }
    }

    function closeEditModal() {
      document.getElementById('editModal').classList.add('hidden');
    }

    async function saveEdit(e) {
      e.preventDefault();
      const payload = {
            id: document.getElementById('edit-id').value,
            court: document.getElementById('edit-court').value,
            date: document.getElementById('edit-date').value,
            time: document.getElementById('edit-time').value,
            payment_status: document.getElementById('payment_status_field').value
        };
      const res = await fetch(`api/update_reservation.php`, {
        method: 'POST',
        headers: {
        'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
      });
      if (res.ok) {
        location.reload();
      } else {
        alert("Failed to update reservation.");
      }
    }
  </script>
</head>
<body class="bg-gray-100 min-h-screen">

<div class="p-6">
<h1 class="text-2xl text-orange-600 font-bold mb-4">All Reservations</h1>
<form method="get" class="mb-4">
  <label for="sport" class="text-sm font-semibold">Filter by Sport:</label>
  <select name="sport" id="sport" onchange="this.form.submit()" class="border px-2 py-1 rounded">
    <option value="">All Sports</option>
    <?php
      $sports = $pdo->query("SELECT DISTINCT type FROM courts ORDER BY type")->fetchAll(PDO::FETCH_COLUMN);
      foreach ($sports as $sport) {
          $selected = ($sport === $sport_filter) ? 'selected' : '';
          echo "<option value=\"$sport\" $selected>" . htmlspecialchars(ucfirst($sport)) . "</option>";
      }
    ?>
  </select>
</form>

    <table class="w-full border border-gray-300">
      <thead class="bg-gray-800 text-white">
        <tr>
          <th class="border px-3 py-2">ID</th>
          <th class="border px-3 py-2">User</th>
          <th class="border px-3 py-2">Court</th>
          <th class="border px-3 py-2">Section</th>
          <th class="border px-3 py-2">Date</th>
          <th class="border px-3 py-2">Time</th>
          <th class="border px-3 py-2">Payment</th>
          <th class="border px-3 py-2">Created At</th>
          <th class="border px-3 py-2">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($reservations as $r): ?>
        <tr class="hover:bg-gray-50">
          <td class="border px-3 py-2"><?= $r['id'] ?></td>
          <td class="border px-3 py-2"><?= htmlspecialchars($r['user_id']) ?></td>
          <td class="border px-3 py-2"><?= htmlspecialchars($r['court']) ?></td>
          <td class="border px-3 py-2"><?= htmlspecialchars($r['section_number'] == 0 ? "All" : $r['section_number']) ?></td>
          <td class="border px-3 py-2"><?= htmlspecialchars($r['date']) ?></td>
          <td class="border px-3 py-2"><?= htmlspecialchars($r['time_slots'] ?? '') ?></td>
          <td class="border px-3 py-2">
            <span class="inline-block px-2 py-1 rounded text-sm 
              <?= $r['payment_status'] === 'paid' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' ?>">
              <?= ucfirst($r['payment_status'] ?? '') ?>
            </span>
          </td>
          <td class="border px-3 py-2 text-sm"><?= $r['created_at'] ?></td>
          <td class="border px-3 py-2 space-x-1 text-sm">
            <button onclick="openEditModal(<?= $r['id'] ?>)" class="text-blue-500"><i class="fas fa-edit"></i></button>
            <form method="post" action="/api/delete_reservation.php" class="inline" onsubmit="return confirm('Delete this reservation?')">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="text-red-500"><i class="fas fa-trash"></i></button>
            </form>
            <?php if ($r['payment_status'] !== 'paid'): ?>
            <form method="post" action="api/confirm_payment.php" class="inline" onsubmit="return confirm('Confirm payment for this reservation?')">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="text-green-500"><i class="fas fa-check"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Edit Modal -->
  <div id="editModal" class="fixed inset-0 flex items-center justify-center bg-black bg-opacity-50 z-50 hidden">
    <div class="bg-white rounded-lg shadow-lg p-6 w-96">
      <h2 class="text-xl text-orange-700 font-bold mb-4">Edit Reservation</h2>
      <form id="edit-form" onsubmit="saveEdit(event)">
        <input type="hidden" name="id" id="edit-id" />

        <div class="mb-3">
          <label class="block text-sm font-medium">User ID</label>
          <input type="text" name="user_id" id="edit-user" class="w-full border px-2 py-1 rounded" readonly>
        </div>

        <div class="mb-3">
          <label class="block text-sm font-medium">Court</label>
          <input type="text" name="court" id="edit-court" class="w-full border px-2 py-1 rounded" readonly>
        </div>

        <div class="mb-3" id="section-container">
          <label class="block text-sm font-medium">Section</label>
          <input type="number" name="section" id="edit-section" class="w-full border px-2 py-1 rounded">
        </div>

        <div class="mb-3">
          <label class="block text-sm font-medium">Date</label>
          <input type="date" name="date" id="edit-date" class="w-full border px-2 py-1 rounded">
        </div>

        <div class="mb-3">
          <label class="block text-sm font-medium">Time</label>
          <input type="time" step="3600" name="time" id="edit-time" class="w-full border px-2 py-1 rounded">
          <input type="text" name="payment_status" id="payment_status_field" class="w-full hidden border px-2 py-1 rounded">
        </div>

        <div class="flex justify-between">
          <button type="button" onclick="closeEditModal()" class="bg-gray-300 px-4 py-2 rounded">Cancel</button>
          <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save</button>
        </div>
      </form>
    </div>
  </div>

</body>
</html>
