<?php
// send_message.php
session_start();
header('Content-Type: application/json; charset=UTF-8');
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['success'=>false,'message'=>'Unauthorized']);
  exit;
}

$sender_id   = (int)($_POST['sender_id']   ?? 0);
$receiver_id = (int)($_POST['receiver_id'] ?? 0);
$project_id  = (int)($_POST['project_id']  ?? 0);

// Basic checks
if ($sender_id !== (int)$_SESSION['user_id']) {
  http_response_code(403);
  echo json_encode(['success'=>false,'message'=>'Forbidden']);
  exit;
}
if ($sender_id <= 0 || $receiver_id <= 0 || $project_id <= 0) {
  echo json_encode(['success'=>false,'message'=>'Invalid parameters']);
  exit;
}

// Auth: sender must be client or hired freelancer of the project, and receiver must be the other
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
  echo json_encode(['success'=>false,'message'=>'Project not found']);
  exit;
}
$stmt->close();

$client_id = (int)$client_id;
$freelancer_id = (int)$freelancer_id;

$validPair = (
  ($sender_id === $client_id   && $receiver_id === $freelancer_id) ||
  ($sender_id === $freelancer_id && $receiver_id === $client_id)
);
if (!$validPair) {
  echo json_encode(['success'=>false,'message'=>'Sender/receiver not part of this project']);
  exit;
}

// Handle images (multipart) + text
$inserted = [];
$now = date('Y-m-d H:i:s');

// Helper to insert one message row
function insert_message($conn, $sender_id, $receiver_id, $project_id, $text, $now) {
  $sql = "INSERT INTO messages (sender_id, receiver_id, project_id, message_text, message_date, `read`)
          VALUES (?, ?, ?, ?, ?, 0)";
  $st = $conn->prepare($sql);
  $st->bind_param("iiiss", $sender_id, $receiver_id, $project_id, $text, $now);
  $ok = $st->execute();
  $id = $ok ? $st->insert_id : 0;
  $st->close();
  return [$ok, $id];
}

// If files were uploaded
$filesHandled = 0;
if (!empty($_FILES) && isset($_FILES['image'])) {
  // Accept multiple
  $files = $_FILES['image'];
  $count = is_array($files['name']) ? count($files['name']) : 1;

  // Ensure directory
  $baseDir = realpath(__DIR__ . '/../uploads');
  if ($baseDir === false) { @mkdir(__DIR__ . '/../uploads', 0775, true); $baseDir = realpath(__DIR__ . '/../uploads'); }
  $chatDir = $baseDir . DIRECTORY_SEPARATOR . 'chat';
  if (!is_dir($chatDir)) @mkdir($chatDir, 0775, true);

  $allowed = ['jpg','jpeg','png','gif','webp'];
  for ($i=0; $i<$count; $i++) {
    $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];
    if ($error !== UPLOAD_ERR_OK) continue;

    $tmp  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
    $name = is_array($files['name'])     ? $files['name'][$i]     : $files['name'];
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) continue;

    // Generate safe unique name
    $new  = 'chat_' . time() . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    $dest = $chatDir . DIRECTORY_SEPARATOR . $new;

    if (!@move_uploaded_file($tmp, $dest)) continue;

    // Relative URL path saved inside message_text
    $rel  = '/uploads/chat/' . $new;
    $text = 'file:' . $rel;
    [$ok, $id] = insert_message($conn, $sender_id, $receiver_id, $project_id, $text, $now);
    if ($ok) { $filesHandled++; $inserted[] = $id; }
  }
}

// Handle plain text
$text = isset($_POST['message_text']) ? trim((string)$_POST['message_text']) : '';
if ($text !== '') {
  // Keep it short-ish to avoid insane payloads
  if (mb_strlen($text) > 4000) $text = mb_substr($text, 0, 4000);
  [$ok, $id] = insert_message($conn, $sender_id, $receiver_id, $project_id, $text, $now);
  if ($ok) $inserted[] = $id;
}

if (empty($inserted) && $filesHandled === 0 && $text === '') {
  echo json_encode(['success'=>false,'message'=>'Nothing to send']);
  exit;
}

echo json_encode(['success'=>true,'inserted'=>$inserted]);
