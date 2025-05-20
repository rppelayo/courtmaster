<?php
session_start();
require_once "includes/db.php";

// Redirect if not admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
  header("Location: index.html");
  exit();
}

$stmt = $pdo->query("SELECT * FROM courts");
$courts = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin - Courts</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/js/all.min.js" integrity="sha512-b+nQTCdtTBIRIbraqNEwsjB6UvL3UEMkXnhzd8awtCYh0Kcsjl9uEgwVFVbhoj3uu1DO1ZMacNvLoyJJiNfcvg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
</head>
<body class="bg-gray-100">
  <div class="p-6">
    <h1 class="text-2xl text-orange-600 font-bold mb-4">Court Management</h1>

    <button onclick="openCourtModal()" class="bg-orange-600 text-white px-4 py-2 mb-4 rounded">Add New Court</button>

    <table class="min-w-full bg-white border">
      <thead class="bg-gray-800 text-white">
        <tr>
          <th class="px-4 py-2">ID</th>
          <th class="px-4 py-2">Name</th>
          <th class="px-4 py-2">Location</th>
          <th class="px-4 py-2">Price</th>
          <th class="px-4 py-2">Sport</th>
          <th class="px-4 py-2">Business Hours</th>
          <th class="px-4 py-2">Image</th>
          <th class="px-4 py-2">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($courts as $court): ?>
        <?php $open_time = DateTime::createFromFormat('H:i',$court['open_time'] ); ?>
        <?php 
          if($open_time != null) {
            $open_time_12 = $open_time -> format('h:i a');
          }else{
            $open_time_12 = "00:00 AM";
          }  
          ?>
        <?php $close_time = DateTime::createFromFormat('H:i',$court['close_time']); ?>
        <?php
          if($close_time != null) {
             $close_time_12 = $close_time -> format('h:i a'); 
          }else{
            $close_time_12 = "00:00 PM";
          } 
         ?>
        <tr class="border-t">
          <td class="px-4 py-2 text-center"><?= $court['id'] ?></td>
          <td class="px-4 py-2 text-center"><?= htmlspecialchars($court['name']) ?></td>
          <td class="px-4 py-2 text-center"><?= htmlspecialchars($court['location']) ?></td>
          <td class="px-4 py-2 text-center">P<?= number_format($court['price'], 2) ?></td>
          <td class="px-4 py-2 text-center"><?= htmlspecialchars($court['type'] ?? '') ?></td>
          <td class="px-4 py-2 text-center"><?= htmlspecialchars($open_time_12 ?? '') . '-' .htmlspecialchars($close_time_12 ?? '');  ?></td>
          <td class="px-4 py-2 text-center">
            <?php if ($court['image_path']): ?>
              <img src="images/courts/<?= htmlspecialchars($court['image_path']) ?>" class="h-12 rounded" alt="Court image">
            <?php else: ?>
              No image
            <?php endif; ?>
          </td>
          <td class="px-4 py-2 space-x-2 text-center">
            <button class="text-blue-600" onclick="editCourt(<?= htmlspecialchars(json_encode($court)) ?>)"><i class="fas fa-edit"></i></button>
            <button class="text-red-600" onclick="deleteCourt(<?= $court['id'] ?>)"><i class="fas fa-trash"></i></button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Modal (basic skeleton, JS will fill in details) -->
  <div id="courtModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex justify-center items-center">
    <div class="bg-white p-6 rounded w-full max-w-lg">
      <h2 class="text-xl font-semibold mb-4" id="modalTitle">Add Court</h2>
      <form id="courtForm" enctype="multipart/form-data">
        <input type="hidden" id="courtId">
        <div class="mb-2">
          <label>Name</label>
          <input type="text" id="courtName" name="name" class="w-full border px-2 py-1 rounded">
        </div>
        <div class="mb-2">
          <div class="flex flex-col">
            <label>Open At:</label>
            <input type="time" id="open_hour" name="open_hour" class="w-full border px-2 py-1 rounded">
            <label>Close At:</label>
            <input type="time" id="close_hour" name="close_hour" class="w-full border px-2 py-1 rounded">
          </div>
        </div>
        <div class="mb-2">
          <label>Location</label>
          <input type="text" id="courtLocation" name="location" class="w-full border px-2 py-1 rounded">
        </div>
        <div class="mb-2">
          <label>Price</label>
          <input type="number" id="courtPrice" name="price" class="w-full border px-2 py-1 rounded">
        </div>
        <div class="mb-2">
        <label for="courtType" class="block font-medium mb-1">Sport</label>
        <select id="courtType" name="type" class="w-full border px-2 py-1 rounded" required>
            <option value="" disabled selected>Select sport</option>
            <option value="basketball">Basketball/Volleyball</option>
            <option value="tennis">Tennis</option>
            <option value="swimming">Swimming</option>
            <option value="badminton">Badminton</option>
        </select>
        </div>
        <div class="mb-2">
          <label>Image</label>
          <input type="file" name="image" id="courtImage">
        </div>
        <div class="flex justify-end space-x-2 mt-4">
          <button type="button" onclick="closeCourtModal()" class="px-4 py-2 bg-gray-400 text-white rounded">Cancel</button>
          <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Save</button>
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
      document.getElementById("courtType").value = court.type || "";
      document.getElementById("courtModal").classList.remove("hidden");
      document.getElementById("open_hour").value = court.open_time;
      document.getElementById("close_hour").value = court.close_time;
    }

    function deleteCourt(id) {
      if (confirm("Are you sure you want to delete this court?")) {
        fetch(`/api/delete_court.php?id=${id}`, { method: 'POST' })
          .then(res => res.json())
          .then(data => {
            if (data.success) location.reload();
            else alert("Error: " + data.message);
          });
      }
    }

    document.getElementById("courtForm").addEventListener("submit", function(e) {
      e.preventDefault();
      const formData = new FormData(this);
      formData.append("id", document.getElementById("courtId").value);

      fetch("api/save_court.php", {
        method: "POST",
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) location.reload();
        else alert("Error: " + data.message);
      });
    });
  </script>
</body>
</html>