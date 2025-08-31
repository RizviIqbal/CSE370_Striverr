<?php
session_start();
include('../includes/auth_check.php');
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'freelancer') {
  http_response_code(403); exit('Forbidden');
}
$freelancer_id = (int)$_SESSION['user_id'];
$submission_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($submission_id <= 0) { http_response_code(400); exit('Bad request'); }

function cols(mysqli $c, $t){ $a=[]; if($r=$c->query("SHOW COLUMNS FROM `$t`")){while($x=$r->fetch_assoc()) $a[]=$x['Field']; $r->close();} return $a; }

$subsTable = 'milestone_submissions';
$sc = cols($conn, $subsTable);
$has = fn($c)=>in_array($c, $sc, true);

$idCol  = $has('id') ? 'id' : ($has('submission_id') ? 'submission_id' : null);
$fileCol= $has('file_path') ? 'file_path' : ($has('file') ? 'file' : ($has('attachment') ? 'attachment' : null));
$msFK  = $has('milestone_id') ? 'milestone_id' : null;
$projFK= $has('project_id')   ? 'project_id'   : null;

if (!$idCol || !$fileCol) { http_response_code(500); exit('Submission schema missing id/file column'); }

$q = "
  SELECT s.`$fileCol` AS path
  FROM `$subsTable` s
  ".($msFK ? "JOIN milestones m ON m.`$msFK` = s.`$msFK`" : "")."
  ".($projFK ? "JOIN projects p  ON p.project_id = s.`$projFK`" : "JOIN projects p ON p.project_id = m.project_id")."
  WHERE s.`$idCol` = ? AND p.hired_freelancer_id = ?
  LIMIT 1
";
$stmt = $conn->prepare($q);
$stmt->bind_param("ii", $submission_id, $freelancer_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) { http_response_code(404); exit('Not found'); }

$rel = $row['path'];
$base = realpath(__DIR__ . '/../uploads');
$file = realpath($base . '/' . $rel);

if (!$file || !str_starts_with($file, $base) || !is_file($file)) {
  http_response_code(404); exit('File missing');
}

$mime = mime_content_type($file) ?: 'application/octet-stream';
$fname = basename($file);

header('Content-Type: '.$mime);
header('Content-Length: '.filesize($file));
header('Content-Disposition: attachment; filename="'.$fname.'"');
header('X-Content-Type-Options: nosniff');
readfile($file);
exit;
