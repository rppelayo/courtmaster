<?php
session_start();
require_once "includes/db.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') === 'user') {
    header("Location: ../index.html");
    exit;
}

$userType = $_SESSION['role'];
$userId = $_SESSION['user_id'];

if ($userType === 'admin') {
    $stmt = $pdo->query("SELECT * FROM courts ORDER BY name");
} else {
    $stmt = $pdo->prepare("SELECT * FROM courts WHERE owner_id = ? ORDER BY name");
    $stmt->execute([$userId]);
}

$courts = $stmt->fetchAll(PDO::FETCH_ASSOC);
$courtCount = count($courts);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Pickleball Admin - Courts</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/js/all.min.js" integrity="sha512-b+nQTCdtTBIRIbraqNEwsjB6UvL3UEMkXnhzd8awtCYh0Kcsjl9uEgwVFVbhoj3uu1DO1ZMacNvLoyJJiNfcvg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <link rel="stylesheet" href="styles/admin-theme.css">
</head>
<body class="admin-theme-body admin-frame-body">
  <div class="admin-page-shell">
    <div class="admin-page-header">
      <div class="admin-overline">Court Management</div>
      <div class="admin-title">Manage the pickleball courts in your venue</div>
      <div class="admin-copy">Create courts, adjust rates, and update business hours for the fixed pickleball courts used by this venue.</div>
    </div>

    <div class="mb-5 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
      <div class="admin-filter-bar flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <div class="text-sm font-semibold text-slate-800">Pickleball-only court setup</div>
          <p class="mt-1 text-sm text-slate-500">Every court saved here is automatically treated as a pickleball court.</p>
        </div>
        <div class="admin-pill">Venue courts: <?= $courtCount ?></div>
      </div>

      <button onclick="openCourtModal()" class="admin-primary-btn" type="button">
        <i class="fas fa-plus"></i>
        Add New Court
      </button>
    </div>

    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Location</th>
            <th>Rates</th>
            <th>Business Hours</th>
            <th>Image</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($courts as $court): ?>
            <?php
            $openTime = !empty($court['open_time']) ? DateTime::createFromFormat('H:i:s', (string) $court['open_time']) : null;
            $closeTime = !empty($court['close_time']) ? DateTime::createFromFormat('H:i:s', (string) $court['close_time']) : null;
            $openLabel = $openTime instanceof DateTime ? $openTime->format('h:i A') : 'N/A';
            $closeLabel = $closeTime instanceof DateTime ? $closeTime->format('h:i A') : 'N/A';
            ?>
            <tr>
              <td><?= (int) $court['id'] ?></td>
              <td><?= htmlspecialchars((string) $court['name']) ?></td>
              <td><?= htmlspecialchars((string) $court['location']) ?></td>
              <td>
                <div class="font-medium text-slate-800">Regular: P<?= number_format((float) $court['price'], 2) ?></div>
                <div class="mt-1 text-sm text-slate-500">
                  Member:
                  <?php if (!empty($court['member_price'])): ?>
                    P<?= number_format((float) $court['member_price'], 2) ?>
                  <?php else: ?>
                    Not set
                  <?php endif; ?>
                </div>
              </td>
              <td><?= htmlspecialchars($openLabel . ' - ' . $closeLabel) ?></td>
              <td>
                <?php if (!empty($court['image_path'])): ?>
                  <img src="images/courts/<?= htmlspecialchars((string) $court['image_path']) ?>" class="h-12 w-16 rounded-xl object-cover" alt="Court image">
                <?php else: ?>
                  <span class="text-sm text-slate-500">No image</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="flex items-center gap-3">
                  <button class="admin-action-link" onclick='editCourt(<?= json_encode($court) ?>)' type="button"><i class="fas fa-edit"></i></button>
                  <button class="text-red-500 hover:text-red-700" onclick="deleteCourt(<?= (int) $court['id'] ?>)" type="button"><i class="fas fa-trash"></i></button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div id="courtModal" class="admin-modal-backdrop hidden">
    <div class="admin-modal-panel max-w-2xl">
      <div class="flex items-start justify-between gap-4">
        <div>
          <div class="admin-overline">Court Details</div>
          <div id="modalTitle" class="admin-title text-[1.4rem]">Add Court</div>
        </div>
        <button onclick="closeCourtModal()" class="rounded-xl bg-slate-100 px-3 py-2 text-slate-600 hover:bg-slate-200" type="button">
          <i class="fas fa-xmark"></i>
        </button>
      </div>

      <form id="courtForm" enctype="multipart/form-data" class="mt-5 grid gap-4">
        <input type="hidden" id="courtId">
        <input type="hidden" id="courtType" name="type" value="pickleball">

        <div>
          <label for="courtName" class="admin-field-label">Court Name</label>
          <input type="text" id="courtName" name="name" class="admin-input">
        </div>

        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <label for="open_hour" class="admin-field-label">Open At</label>
            <input type="time" id="open_hour" name="open_hour" class="admin-input">
          </div>
          <div>
            <label for="close_hour" class="admin-field-label">Close At</label>
            <input type="time" id="close_hour" name="close_hour" class="admin-input">
          </div>
        </div>

        <div>
          <label for="courtLocation" class="admin-field-label">Location</label>
          <input type="text" id="courtLocation" name="location" class="admin-input">
        </div>

        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <label for="courtPrice" class="admin-field-label">Hourly Rate</label>
            <input type="number" id="courtPrice" name="price" class="admin-input" step="0.01" min="0">
          </div>
          <div>
            <label for="courtMemberPrice" class="admin-field-label">Member Rate</label>
            <input type="number" id="courtMemberPrice" name="member_price" class="admin-input" step="0.01" min="0" placeholder="Optional member rate">
          </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <label class="admin-field-label">Sport</label>
            <div class="admin-input flex items-center justify-between">
              <span class="font-medium text-slate-800">Pickleball</span>
              <span class="admin-tag bg-teal-50 text-teal-700">Fixed</span>
            </div>
          </div>
        </div>

        <div>
          <label for="courtImage" class="admin-field-label">Court Image</label>
          <input type="file" name="image" id="courtImage" class="admin-input">
        </div>

        <div class="mt-2 flex justify-end gap-3">
          <button type="button" onclick="closeCourtModal()" class="admin-secondary-btn">Cancel</button>
          <button type="submit" class="admin-primary-btn">Save Court</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function openCourtModal() {
      document.getElementById("modalTitle").textContent = "Add Court";
      document.getElementById("courtForm").reset();
      document.getElementById("courtId").value = "";
      document.getElementById("courtModal").classList.remove("hidden");
    }

    function closeCourtModal() {
      document.getElementById("courtModal").classList.add("hidden");
    }

    function editCourt(court) {
      document.getElementById("modalTitle").textContent = "Edit Court";
      document.getElementById("courtId").value = court.id;
      document.getElementById("courtName").value = court.name;
      document.getElementById("courtLocation").value = court.location;
      document.getElementById("courtPrice").value = court.price;
      document.getElementById("courtMemberPrice").value = court.member_price || "";
      document.getElementById("courtType").value = "pickleball";
      document.getElementById("open_hour").value = court.open_time;
      document.getElementById("close_hour").value = court.close_time;
      document.getElementById("courtModal").classList.remove("hidden");
    }

    function deleteCourt(id) {
      if (!confirm("Are you sure you want to delete this court?")) {
        return;
      }

      fetch(`/api/delete_court.php?id=${id}`, { method: "POST" })
        .then((response) => response.json())
        .then((data) => {
          if (data.success) {
            location.reload();
            return;
          }

          alert("Error: " + data.message);
        });
    }

    document.getElementById("courtForm").addEventListener("submit", function (event) {
      event.preventDefault();

      const formData = new FormData(this);
      formData.append("id", document.getElementById("courtId").value);

      fetch("api/save_court.php", {
        method: "POST",
        body: formData
      })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          location.reload();
          return;
        }

        alert("Error: " + data.message);
      });
    });
  </script>
</body>
</html>
