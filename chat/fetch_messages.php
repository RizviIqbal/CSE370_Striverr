<?php
// fetch_messages.php
session_start();
header('Content-Type: application/json; charset=UTF-8');
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode([]);
  exit;
}

$user_id    = (int)($_POST['user_id']    ?? 0);
$project_id = (int)($_POST['project_id'] ?? 0);

if ($user_id !== (int)$_SESSION['user_id'] || $project_id <= 0) {
  http_response_code(403);
  echo json_encode([]);
  exit;
}

// Verify access to project
$stmt = $conn->prepare("
  SELECT client_id, hired_freelancer_id
  FROM projects
  WHERE project_id = ?
  LIMIT 1
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$stmt->bind_result($client_id, $freelancer_id);
if (!$stmt->fetch()) {
  $stmt->close();
  echo json_encode([]);
  exit;
}
$stmt->close();

$client_id = (int)$client_id;
$freelancer_id = (int)$freelancer_id;

if ($user_id !== $client_id && $user_id !== $freelancer_id) {
  // Not a participant
  echo json_encode([]);
  exit;
}

// Mark messages to me as read
$upd = $conn->prepare("UPDATE messages SET `read`=1 WHERE project_id=? AND receiver_id=? AND `read`=0");
$upd->bind_param("ii", $project_id, $user_id);
$upd->execute();
$upd->close();

// Fetch last 500 messages (oldest->newest)
$q = $conn->prepare("
  SELECT message_id, sender_id, receiver_id, message_text, message_date, `read`
  FROM messages
  WHERE project_id = ?
  ORDER BY message_date ASC, message_id ASC
  LIMIT 500
");
$q->bind_param("i", $project_id);
$q->execute();
$res = $q->get_result();

$out = [];
while ($row = $res->fetch_assoc()) {
  // Ensure types are consistent for JS
  $out[] = [
    'message_id'   => (int)$row['message_id'],
    'sender_id'    => (int)$row['sender_id'],
    'receiver_id'  => (int)$row['receiver_id'],
    'message_text' => (string)$row['message_text'],
    'message_date' => $row['message_date'],   // ISO-like string
    'read'         => (int)$row['read'],
  ];
}
$q->close();

echo json_encode($out);
