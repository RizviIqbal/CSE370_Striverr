<?php
session_start();
header('Content-Type: application/json');
include('../includes/auth_check.php');
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'freelancer') {
  echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}
$freelancer_id = (int)$_SESSION['user_id'];

/* CSRF */
if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf'])) {
  echo json_encode(['success'=>false,'message'=>'Security check failed']); exit;
}

$project_id   = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$bid_amount   = isset($_POST['bid_amount']) ? (float)$_POST['bid_amount'] : 0;
$cover_letter = isset($_POST['cover_letter']) ? trim($_POST['cover_letter']) : '';

if ($project_id <= 0 || $bid_amount <= 0) {
  echo json_encode(['success'=>false,'message'=>'Invalid input']); exit;
}

/* Check project is posted */
$stmt = $conn->prepare("SELECT status FROM projects WHERE project_id = ?");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$stmt->bind_result($pstatus);
$found = $stmt->fetch();
$stmt->close();

if (!$found || $pstatus !== 'posted') {
  echo json_encode(['success'=>false,'message'=>'Project not available']); exit;
}

/* Upsert application */
try {
  $conn->begin_transaction();

  // does application exist?
  $stmt = $conn->prepare("SELECT application_id FROM applications WHERE project_id = ? AND freelancer_id = ? FOR UPDATE");
  $stmt->bind_param("ii", $project_id, $freelancer_id);
  $stmt->execute();
  $stmt->bind_result($app_id);
  $exists = $stmt->fetch();
  $stmt->close();

  if ($exists) {
    $stmt = $conn->prepare("UPDATE applications SET bid_amount = ?, cover_letter = ?, status = 'pending', updated_at = NOW() WHERE application_id = ?");
    $stmt->bind_param("dsi", $bid_amount, $cover_letter, $app_id);
    $ok = $stmt->execute();
    $stmt->close();
  } else {
    $stmt = $conn->prepare("INSERT INTO applications (project_id, freelancer_id, bid_amount, cover_letter, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
    $stmt->bind_param("iids", $project_id, $freelancer_id, $bid_amount, $cover_letter);
    $ok = $stmt->execute();
    $stmt->close();
  }

  if (!$ok) { $conn->rollback(); echo json_encode(['success'=>false,'message'=>'Save failed']); exit; }

  $conn->commit();

  // Optional: create a notification for client
  // $client_id = ... (select client from project) and insert into notifications if you like.

  echo json_encode(['success'=>true]);
} catch (Throwable $e) {
  if ($conn->errno) $conn->rollback();
  echo json_encode(['success'=>false,'message'=>'Server error']);
}
