<?php
// api/logout.php
session_start();

// Destroy all session data
$_SESSION = [];
session_destroy();

// Send a generic success response
http_response_code(200);
echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
?>
