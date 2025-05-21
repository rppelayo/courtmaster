<!-- chatbox.php -->
 <?php
    $is_admin = $_SESSION['role'] === 'admin'; 
    $user_id = $_SESSION['user_id'];
?>
<div id="chat-icon" style="position:fixed; bottom:20px; right:20px; background:#007bff; padding:10px; border-radius:50%; color:#fff; cursor:pointer;">
    💬
</div>


<div id="chat-box" style="display:none; position:fixed; bottom:80px; right:20px; width:300px; height:400px; background:#fff; border:1px solid #ccc; box-shadow:0 0 10px #aaa; z-index:9999;">
    <div style="background:#007bff; color:white; padding:10px;">Support Chat</div>
    <?php if($is_admin) { ?>
    <select id="user-selector" style="width: 100%;">
        <option disabled selected>Select user...</option>
    </select>
    <?php } ?>
    <div id="chat-messages" style="height:300px; overflow-y:auto; padding:10px;"></div>
    <input type="text" id="chat-input" placeholder="Type message..." style="width:100%; padding:10px; box-sizing:border-box;">
    
</div>
<script>
    const IS_ADMIN = <?= json_encode($is_admin) ?>;
    const USER_ID = <?= json_encode($user_id) ?>;
</script>
<script>

document.getElementById("chat-icon").onclick = function() {
    const box = document.getElementById("chat-box");
    box.style.display = box.style.display === "none" ? "block" : "none";
};

document.getElementById("chat-input").addEventListener("keypress", function(e) {
    if (e.key === "Enter" && this.value.trim() !== "") {
        const message = this.value.trim();
        this.value = "";

        const payload = { message: message };

        if (IS_ADMIN) {
            // Admin must select a user to chat with
            const selectedUserId = window.selectedUserId; // Ensure this is set elsewhere
            if (!selectedUserId) {
                alert("Please select a user to chat with.");
                return;
            }
            payload.receiver_id = selectedUserId;
        }

        fetch("/api/send_message.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        }).then(() => loadMessages());
    }
});

 window.addEventListener('DOMContentLoaded', () => {
      if (IS_ADMIN) {
        fetch('/api/get_users.php')
            .then(res => res.json())
            .then(users => {
                const selector = document.getElementById("user-selector");
                users.forEach(user => {
                    const option = document.createElement("option");
                    option.value = user.id;
                    option.textContent = user.name;
                    selector.appendChild(option);
                });

                selector.onchange = function() {
                    window.selectedUserId = this.value;
                    loadMessages(); // optionally reload messages for selected user
                };
            })
            .catch(err => {
                console.error("Failed to load users:", err);
        });
    }
});

function loadMessages() {
    fetch("/api/get_messages.php")
        .then(res => res.json())
        .then(data => {
            const chat = document.getElementById("chat-messages");
            chat.innerHTML = "";
            data.forEach(msg => {
                chat.innerHTML += `<div><strong>${msg.sender}</strong> to <strong>${msg.receiver}</strong>: ${msg.message}</div>`;
            });
            chat.scrollTop = chat.scrollHeight;
        });
}

setInterval(loadMessages, 5000);
loadMessages();
</script>
