<?php
// includes/notify.php
function notify($conn, int $user_id, string $message, ?string $link = null) {
  $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, link, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
  $stmt->bind_param("iss", $user_id, $message, $link);
  $stmt->execute();
  $stmt->close();
}
