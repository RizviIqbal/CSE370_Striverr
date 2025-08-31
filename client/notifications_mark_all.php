<?php
session_start();
header('Content-Type: application/json');
include('../includes/auth_check.php');
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['success' => false]); exit;
}
$user_id = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
$stmt->bind_param("i", $user_id);
$ok = $stmt->execute();
$stmt->close();

echo json_encode(['success' => (bool)$ok]);
