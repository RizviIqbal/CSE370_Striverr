<?php
session_start();
header('Content-Type: application/json');
include('../includes/auth_check.php');
include('../includes/db_connect.php');
include('../includes/notify.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'freelancer') {
  echo json_encode(['success'=>false, 'message'=>'Unauthorized']); exit;
}

$freelancer_id = (int)$_SESSION['user_id'];
$milestone_id  = (int)($_POST['milestone_id'] ?? 0);
$note          = trim($_POST['note'] ?? '');
$link          = trim($_POST['link'] ?? '');

if ($milestone_id <= 0) {
  echo json_encode(['success'=>false, 'message'=>'Missing milestone']); exit;
}

// verify ownership of milestone via project
$can = $conn->prepare("
  SELECT p.project_id, p.client_id, p.hired_freelancer_id
  FROM milestones m JOIN projects p ON m.project_id = p.project_id
  WHERE m.milestone_id = ?
");
$can->bind_param("i", $milestone_id);
$can->execute();
$can->bind_result($project_id, $client_id, $hired_freelancer_id);
$can->fetch();
$can->close();

if ((int)$hired_freelancer_id !== $freelancer_id) {
  echo json_encode(['success'=>false, 'message'=>'Not allowed']); exit;
}

// create a submission row
$stmt = $conn->prepare("INSERT INTO milestone_submissions (milestone_id, submitted_by, note, link, created_at) VALUES (?, ?, ?, ?, NOW())");
$stmt->bind_param("iiss", $milestone_id, $freelancer_id, $note, $link);
$ok = $stmt->execute();
$submission_id = $stmt->insert_id;
$stmt->close();

if (!$ok) { echo json_encode(['success'=>false, 'message'=>'Failed to create submission']); exit; }

// files (multiple)
$upload_dir = "../uploads/milestones/";
if (!is_dir($upload_dir)) { @mkdir($upload_dir, 0777, true); }

if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
  $allowed = ['zip','rar','7z','pdf','doc','docx','ppt','pptx','png','jpg','jpeg','gif','mp4','mov','txt','csv','xlsx'];
  for ($i=0; $i<count($_FILES['files']['name']); $i++) {
    if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
    $tmp  = $_FILES['files']['tmp_name'][$i];
    $name = $_FILES['files']['name'][$i];
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) continue;

    $safe = 'msub_' . $milestone_id . '_' . time() . '_' . $i . '.' . $ext;
    if (move_uploaded_file($tmp, $upload_dir . $safe)) {
      $fs = $conn->prepare("INSERT INTO milestone_submission_files (submission_id, file_path, file_name, uploaded_at) VALUES (?, ?, ?, NOW())");
      $fs->bind_param("iss", $submission_id, $safe, $name);
      $fs->execute();
      $fs->close();
    }
  }
}

// update milestone status to submitted
$upd = $conn->prepare("UPDATE milestones SET status='submitted', submitted_at=NOW() WHERE milestone_id=?");
$upd->bind_param("i", $milestone_id);
$upd->execute();
$upd->close();

// notify client
notify($conn, (int)$client_id, "New submission on a milestone", "../client/project_details.php?id=".$project_id);

echo json_encode(['success'=>true, 'message'=>'Submission sent to client.']);
