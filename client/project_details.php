<?php
session_start();
include('../includes/auth_check.php');
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
  header("Location: ../auth/login.php"); exit();
}
if (!isset($_GET['project_id']) || !is_numeric($_GET['project_id'])) {
  die("Invalid project.");
}

$client_id  = (int)$_SESSION['user_id'];
$project_id = (int)$_GET['project_id'];

/* =============== Guard: project owner =============== */
$P = $conn->prepare("
  SELECT p.project_id, p.title, p.description, p.budget, p.deadline, p.status, p.created_at,
         p.client_id, p.hired_freelancer_id,
         u.name AS freelancer_name, u.email AS freelancer_email,
         COALESCE(NULLIF(u.profile_image,''),'freelancer.png') AS freelancer_image
  FROM projects p
  LEFT JOIN users u ON u.user_id = p.hired_freelancer_id
  WHERE p.project_id = ? AND p.client_id = ?
  LIMIT 1
");
$P->bind_param("ii", $project_id, $client_id);
$P->execute();
$project = $P->get_result()->fetch_assoc();
$P->close();

if (!$project) { die("Unauthorized or project not found."); }

/* =============== Helpers (flexible schema) =============== */
function table_cols(mysqli $c, string $table): array {
  $cols = [];
  if ($r = $c->query("SHOW COLUMNS FROM `$table`")) {
    while ($row = $r->fetch_assoc()) $cols[] = $row['Field'];
    $r->close();
  }
  return $cols;
}
function has_col(array $cols, string $name): bool { return in_array($name, $cols, true); }

/* =============== Milestones =============== */
$msCols = table_cols($conn, 'milestones');
$msId   = has_col($msCols, 'milestone_id') ? 'milestone_id' : (has_col($msCols, 'id') ? 'id' : null);
if (!$msId) die("Milestones table must have a primary key (id or milestone_id).");

$msPull = [$msId, 'project_id', 'title', 'status'];
foreach (['amount','due_date','description','submitted_at','submission_note','submission_file'] as $c) {
  if (has_col($msCols, $c)) $msPull[] = $c;
}
$msFieldList = implode(',', array_map(fn($f)=>"`$f`", $msPull));

$MS = $conn->prepare("SELECT $msFieldList FROM milestones WHERE project_id = ? ORDER BY $msId ASC");
$MS->bind_param("i", $project_id);
$MS->execute();
$milestones = $MS->get_result()->fetch_all(MYSQLI_ASSOC);
$MS->close();

/* =============== Submissions (milestone_submissions) =============== */
$submissionsTable = 'milestone_submissions';
$subCols = table_cols($conn, $submissionsTable);
$subId   = has_col($subCols, 'id') ? 'id' : (has_col($subCols, 'submission_id') ? 'submission_id' : null);
$subMsFk = has_col($subCols, 'milestone_id') ? 'milestone_id' : null;
$subPjFk = has_col($subCols, 'project_id')   ? 'project_id'   : null;
$subFile = has_col($subCols, 'file_path') ? 'file_path' : (has_col($subCols,'file') ? 'file' : (has_col($subCols,'attachment') ? 'attachment' : null));
$subNote = has_col($subCols, 'note') ? 'note' : (has_col($subCols, 'message') ? 'message' : null);
$subAt   = has_col($subCols, 'created_at') ? 'created_at' : (has_col($subCols, 'submitted_at') ? 'submitted_at' : null);

$submissionsByMs = [];
if ($subId && $subFile && ($subMsFk || $subPjFk)) {
  $select = ["s.`$subId` AS sub_id", "s.`$subFile` AS sub_file"];
  if ($subNote) $select[] = "s.`$subNote` AS sub_note";
  if ($subAt)   $select[] = "s.`$subAt` AS sub_at";
  if ($subMsFk) $select[] = "s.`$subMsFk` AS ms_fk";
  if ($subPjFk) $select[] = "s.`$subPjFk` AS pj_fk";

  $sqlS = "SELECT ".implode(',', $select)." FROM `$submissionsTable` s WHERE ";
  if ($subPjFk) {
    $sqlS .= "s.`$subPjFk` = ?";
  } else {
    $sqlS .= "s.`$subMsFk` IN (SELECT $msId FROM milestones WHERE project_id = ?)";
  }
  $S = $conn->prepare($sqlS);
  $S->bind_param("i", $project_id);
  $S->execute();
  $resS = $S->get_result();
  while($row = $resS->fetch_assoc()) {
    $msKey = $subMsFk ? (int)$row['ms_fk'] : null;
    if ($msKey === null) continue;
    $submissionsByMs[$msKey][] = $row;
  }
  $S->close();
}

/* =============== Payments (mock escrow) =============== */
$payments = [];
$payQ = $conn->prepare("SELECT milestone_id, status FROM payments WHERE project_id=?");
$payQ->bind_param("i", $project_id);
$payQ->execute();
$resP = $payQ->get_result();
while($row = $resP->fetch_assoc()) { $payments[(int)$row['milestone_id']] = $row['status']; }
$payQ->close();

/* =============== Review gating =============== */
$freelancerId = (int)($project['hired_freelancer_id'] ?? 0);
$projectComplete = in_array($project['status'], ['submitted','completed','done'], true);

$alreadyReviewed = false;
if ($freelancerId > 0 && $projectComplete && $conn->query("SHOW TABLES LIKE 'reviews'")->num_rows > 0) {
  $chk = $conn->prepare("SELECT 1 FROM reviews WHERE client_id=? AND freelancer_id=? AND project_id=? LIMIT 1");
  $chk->bind_param("iii", $client_id, $freelancerId, $project_id);
  $chk->execute();
  $alreadyReviewed = (bool)$chk->get_result()->fetch_row();
  $chk->close();
}

/* =============== AJAX Actions =============== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  $action = $_POST['action'] ?? '';

  // Add milestone
  if ($action === 'add_milestone') {
    $title = trim($_POST['title'] ?? '');
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $due = trim($_POST['due_date'] ?? '');
    $desc = trim($_POST['description'] ?? '');

    if ($title === '') { echo json_encode(['success'=>false,'message'=>'Title is required']); exit; }

    $cols = ['project_id','title','status'];
    $vals = ['?','?','"pending"'];
    $types = 'is'; $binds = [$project_id, $title];

    if (has_col($msCols,'amount'))      { $cols[]='amount';      $vals[]='?'; $types.='d'; $binds[]=$amount; }
    if (has_col($msCols,'due_date'))    { $cols[]='due_date';    $vals[]='?'; $types.='s'; $binds[]=$due; }
    if (has_col($msCols,'description')) { $cols[]='description'; $vals[]='?'; $types.='s'; $binds[]=$desc; }

    $sql = "INSERT INTO milestones (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$binds);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success'=>$ok,'message'=>$ok?'Milestone added':'Failed to add milestone']);
    exit;
  }

  // Update milestone status (only makes sense if there’s at least one submission)
  if ($action === 'milestone_status') {
    if (!has_col($msCols,'status')) { echo json_encode(['success'=>false,'message'=>"Milestones table missing 'status' column"]); exit; }
    $mid = (int)($_POST['milestone_id'] ?? 0);
    $new = $_POST['new_status'] ?? '';
    $allowed = ['approved','rejected','revision_requested','pending','in_review'];
    if (!in_array($new, $allowed, true)) { echo json_encode(['success'=>false,'message'=>'Invalid status']); exit; }

    // ensure at least one submission exists before allowing moderation
    $hasSub = false;
    if ($subId && $subMsFk) {
      $CHK = $conn->prepare("SELECT 1 FROM `$submissionsTable` WHERE `$subMsFk`=? LIMIT 1");
      $CHK->bind_param("i", $mid);
      $CHK->execute();
      $hasSub = (bool)$CHK->get_result()->fetch_row();
      $CHK->close();
    }
    if (!$hasSub) { echo json_encode(['success'=>false,'message'=>'No submission yet for this milestone']); exit; }

    $stmt = $conn->prepare("UPDATE milestones SET status=? WHERE $msId=? AND project_id=?");
    $stmt->bind_param("sii", $new, $mid, $project_id);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode(['success'=>$ok,'message'=>$ok?'Status updated':'Failed to update status']);
    exit;
  }

  // Mark project completed -> 'submitted'
  if ($action === 'mark_completed') {
    $stmt = $conn->prepare("UPDATE projects SET status='submitted' WHERE project_id=? AND client_id=?");
    $stmt->bind_param("ii", $project_id, $client_id);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success'=>$ok,'message'=>$ok?'Project marked as completed':'Failed to update project']);
    exit;
  }

  // Leave review (create or update, and sync freelancer aggregates)
  if ($action === 'leave_review') {
    $tableExists = $conn->query("SHOW TABLES LIKE 'reviews'")->num_rows > 0;
    if (!$tableExists) { echo json_encode(['success'=>false,'message'=>"Table 'reviews' not found"]); exit; }

    // allowed only after project completion
    if (!$projectComplete) { echo json_encode(['success'=>false,'message'=>'Project not completed yet']); exit; }

    $rating  = max(1, min(5, (int)($_POST['rating'] ?? 5)));
    $comment = trim($_POST['comment'] ?? '');
    $freelancer_id = (int)($project['hired_freelancer_id'] ?? 0);
    if ($freelancer_id <= 0) { echo json_encode(['success'=>false,'message'=>'No hired freelancer on this project']); exit; }

    // reviews columns minimum
    $cols = [];
    $rs = $conn->query("SHOW COLUMNS FROM reviews");
    while ($row = $rs->fetch_assoc()) $cols[$row['Field']] = true;
    $rs->close();
    foreach (['client_id','freelancer_id','project_id','rating','comments','created_at'] as $c) {
      if (!isset($cols[$c])) { echo json_encode(['success'=>false,'message'=>"Missing column '$c' in reviews"]); exit; }
    }

    $conn->begin_transaction();
    try {
      // create or update single review from this client for this project
      $old_id = $old_rating = null;
      $chk = $conn->prepare("SELECT review_id, rating FROM reviews WHERE client_id=? AND freelancer_id=? AND project_id=? LIMIT 1");
      $chk->bind_param("iii", $client_id, $freelancer_id, $project_id);
      $chk->execute();
      $chk->bind_result($old_id, $old_rating);
      $hasOld = $chk->fetch();
      $chk->close();

      if ($hasOld) {
        $up = $conn->prepare("UPDATE reviews SET rating=?, comments=?, created_at=NOW() WHERE review_id=?");
        $up->bind_param("isi", $rating, $comment, $old_id);
        if (!$up->execute()) throw new Exception("Failed to update review");
        $up->close();

        $diff = (int)$rating - (int)$old_rating;
        if ($diff !== 0) {
          $agg = $conn->prepare("UPDATE users SET rating_sum = rating_sum + ? WHERE user_id = ?");
          $agg->bind_param("ii", $diff, $freelancer_id);
          if (!$agg->execute()) throw new Exception("Failed to update aggregates");
          $agg->close();
        }
      } else {
        $ins = $conn->prepare("
          INSERT INTO reviews (client_id, freelancer_id, project_id, rating, comments, created_at)
          VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $ins->bind_param("iiiis", $client_id, $freelancer_id, $project_id, $rating, $comment);
        if (!$ins->execute()) throw new Exception("Failed to insert review");
        $ins->close();

        $agg = $conn->prepare("UPDATE users SET rating_count = rating_count + 1, rating_sum = rating_sum + ? WHERE user_id = ?");
        $agg->bind_param("ii", $rating, $freelancer_id);
        if (!$agg->execute()) throw new Exception("Failed to update aggregates");
        $agg->close();
      }

      $conn->commit();
      echo json_encode(['success'=>true,'message'=>'Review saved']);
    } catch (Exception $ex) {
      $conn->rollback();
      echo json_encode(['success'=>false,'message'=>$ex->getMessage() ?: 'Review failed']);
    }
    exit;
  }

  echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Striverr | Project Details</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
<style>
  :root{
    --bg:#0f1722; --panel:#1b2736; --panel2:#12202e; --ink:#eaf2ff; --muted:#9db1c7;
    --accent:#00bfff; --mint:#00ffc3; --glow:0 10px 30px rgba(0,191,255,.25);
  }
  *{box-sizing:border-box}
  body{
    margin:0; font-family:Poppins,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial;
    background:
      radial-gradient(1200px 800px at -10% -20%, #12202e 0%, transparent 60%),
      radial-gradient(900px 600px at 120% 10%, #112437 0%, transparent 60%),
      linear-gradient(160deg, #0c1320, #0f1722 60%, #0b1420);
    color:var(--ink);
    min-height:100vh; padding:28px;
  }
  header{display:flex; justify-content:space-between; align-items:center; margin:0 auto 16px; max-width:1200px}
  .brand{font-weight:700; color:#7dd3ff; letter-spacing:.4px; font-size:22px}
  .back{
    display:inline-flex; gap:10px; align-items:center;
    padding:10px 14px; background:#132234; border:1px solid rgba(255,255,255,.06);
    color:#cfeaff; border-radius:12px; text-decoration:none; font-weight:600;
    transition:transform .15s ease, box-shadow .15s ease;
  }
  .back:hover{transform:translateY(-1px); box-shadow:0 10px 24px rgba(0,191,255,.12)}
  .wrap{max-width:1200px; margin:0 auto; display:grid; gap:24px; grid-template-columns: 1.3fr .7fr;}
  @media (max-width: 1024px){ .wrap{grid-template-columns:1fr;} }
  .panel{background:linear-gradient(180deg, var(--panel), #13202e); border:1px solid rgba(255,255,255,.06); border-radius:18px; padding:22px; box-shadow:var(--glow)}
  .title{font-size:22px; font-weight:700}
  .muted{color:var(--muted); font-size:13px}
  .head{display:flex; justify-content:space-between; align-items:start; gap:18px; margin-bottom:6px;}
  .status{display:inline-block; padding:6px 12px; border-radius:999px; font-weight:800; font-size:12px; color:#07121c; background:#7dd3ff;}
  .meta{display:flex; gap:12px; flex-wrap:wrap; margin-top:8px; color:#bfe9ff; font-size:12px}
  .meta .pill{background:#0b1a26; border:1px solid rgba(255,255,255,.08); padding:6px 10px; border-radius:14px}
  .freelancer{display:flex; gap:12px; align-items:center; margin-top:14px; background:#0e1a27; border:1px solid #1f3346; padding:12px; border-radius:12px;}
  .freelancer img{width:44px; height:44px; border-radius:50%; object-fit:cover; border:2px solid rgba(127,221,255,.5)}
  .btn{text-decoration:none; text-align:center; padding:10px 12px; border-radius:12px; cursor:pointer; border:1px solid rgba(255,255,255,.08); font-weight:800; letter-spacing:.2px; transition:transform .15s ease, box-shadow .15s ease, background .15s ease;}
  .btn.primary{ background:linear-gradient(90deg, var(--mint), var(--accent)); color:#07121c; box-shadow:var(--glow); }
  .btn.ghost{ background:#102133; color:#cfeaff; }
  .btn.ghost:hover{ background:#0f2031; }
  .milestone{background:#0e1a27; border:1px solid #1f3346; border-radius:14px; padding:14px; margin-bottom:12px}
  .mhead{display:flex; justify-content:space-between; align-items:center; gap:12px}
  .mstat{font-size:11px; padding:5px 9px; border-radius:999px; border:1px solid rgba(255,255,255,.1)}
  .mini{color:#9db1c7; font-size:12px}
  .mcta{display:flex; gap:8px; flex-wrap:wrap; margin-top:10px}
  .mcta .miniBtn{padding:8px 10px; border-radius:10px; border:1px solid rgba(255,255,255,.08); background:#102133; color:#cfeaff; cursor:pointer}
  .mcta .miniBtn.approve{background:linear-gradient(90deg, #98ffdf, #7dd3ff); color:#07121c; font-weight:800}
  .mcta .miniBtn.reject{background:#2a1720; border-color:#4d2230; color:#ffc9d4}
  .mcta .miniBtn.rev{background:#1b2131; border-color:#2b3b53; color:#bfe1ff}
  .formGrid{display:grid; gap:10px; grid-template-columns:1fr 1fr}
  .formGrid textarea{grid-column: 1 / -1}
  .field input, .field textarea{width:100%; background:#0b1a26; border:1px solid #1f3346; color:#eaf2ff; border-radius:10px; padding:10px 12px; outline:none}
  .field label{display:block; font-size:12px; color:#9db1c7; margin-bottom:6px}
  .sideCard{background:linear-gradient(180deg, var(--panel2), #0b1a26); border:1px solid rgba(255,255,255,.06); border-radius:16px; padding:16px; margin-bottom:12px}
</style>
</head>
<body>
  <header>
    <div class="brand">Striverr</div>
    <a class="back" href="active_projects.php"><i class="fa fa-arrow-left"></i> Active Projects</a>
  </header>

  <div class="wrap">
    <!-- LEFT -->
    <section class="panel">
      <div class="head">
        <div>
          <div class="title"><?= htmlspecialchars($project['title']) ?></div>
          <div class="meta">
            <span class="pill"><i class="fa fa-sack-dollar"></i> $<?= number_format((float)$project['budget'],2) ?></span>
            <?php if(!empty($project['deadline'])): ?>
              <span class="pill"><i class="fa fa-calendar"></i> <?= htmlspecialchars(date('M j, Y', strtotime($project['deadline']))) ?></span>
            <?php endif; ?>
            <span class="pill"><i class="fa fa-clock"></i> Started <?= htmlspecialchars(date('M j, Y', strtotime($project['created_at']))) ?></span>
          </div>
        </div>
        <div><span class="status"><?= strtoupper($project['status']) ?></span></div>
      </div>

      <div style="margin-top:12px; line-height:1.7; color:#d7ecff">
        <?= nl2br(htmlspecialchars($project['description'])) ?>
      </div>

      <?php if ($project['hired_freelancer_id']): ?>
      <div class="freelancer">
        <img src="../includes/images/<?= htmlspecialchars($project['freelancer_image']) ?>" alt="Freelancer">
        <div style="flex:1">
          <div style="font-weight:700"><?= htmlspecialchars($project['freelancer_name']) ?></div>
          <div class="muted"><?= htmlspecialchars($project['freelancer_email']) ?></div>
        </div>
        <a class="btn ghost" href="../chat/chat.php?project_id=<?= $project_id ?>"><i class="fa fa-comments"></i> Chat</a>
      </div>
      <?php endif; ?>

      <div style="display:flex; align-items:center; gap:10px; margin-top:14px">
        <?php if (!$projectComplete): ?>
          <a class="btn primary" href="javascript:void(0)" id="markDoneBtn"><i class="fa fa-flag-checkered"></i> Mark Completed</a>
        <?php endif; ?>

        <?php if ($projectComplete && $freelancerId > 0 && !$alreadyReviewed): ?>
          <a class="btn ghost" href="javascript:void(0)" id="leaveReviewBtn"><i class="fa fa-star"></i> Leave Review</a>
        <?php elseif ($projectComplete && $alreadyReviewed): ?>
          <span class="muted" style="font-weight:600">Review submitted ✔</span>
        <?php endif; ?>
      </div>

      <hr style="border-color:#1c2b3b; margin:18px 0">

      <div class="title">Milestones</div>
      <div class="muted" style="margin-bottom:10px">Review delivered work, approve, request changes, or reject. Fund and release mock escrow when ready.</div>

      <?php if (empty($milestones)): ?>
        <div class="muted" style="opacity:.8">No milestones yet.</div>
      <?php else: ?>
        <?php foreach ($milestones as $m):
          $mid = (int)$m[$msId];
          $st  = $m['status'] ?? 'pending';
          $amt = isset($m['amount']) ? (float)$m['amount'] : 0;
          $due = isset($m['due_date']) && $m['due_date'] ? date('M j, Y', strtotime($m['due_date'])) : null;
          $desc= $m['description'] ?? '';

          $subs = $submissionsByMs[$mid] ?? []; // submissions for this milestone
          $hasSubmission = !empty($subs);
          $payStatus = $payments[$mid] ?? 'pending'; // pending | escrowed | released
        ?>
          <div class="milestone" id="ms-<?= $mid ?>">
            <div class="mhead">
              <div>
                <strong><?= htmlspecialchars($m['title'] ?? 'Milestone') ?></strong>
                <div class="mini">
                  <?php if ($amt): ?> $<?= number_format($amt,2) ?> · <?php endif; ?>
                  <?php if ($due): ?> Due <?= htmlspecialchars($due) ?> <?php endif; ?>
                </div>
              </div>
              <div class="mstat" style="background:<?= $st==='approved'?'#98ffdf':($st==='rejected'?'#ffb3c1':($st==='revision_requested'?'#ffd16633':'#7dd3ff33')) ?>; color:#cfeaff">
                <?= strtoupper($st) ?>
              </div>
            </div>

            <?php if ($desc): ?>
              <div class="mini" style="margin-top:6px; color:#d7ecff"><?= nl2br(htmlspecialchars($desc)) ?></div>
            <?php endif; ?>

            <?php if (!empty($subs)): ?>
              <div class="mini" style="margin-top:12px">
                <div style="opacity:.8; margin-bottom:6px"><i class="fa fa-upload"></i> Submissions</div>
                <?php foreach ($subs as $s):
                  $file = $s['sub_file'] ?? '';
                  $note = $s['sub_note'] ?? '';
                  $sat  = $s['sub_at']   ?? '';
                  $sid  = (int)$s['sub_id'];
                  $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                  $previewable = in_array($ext, ['jpg','jpeg','png','gif','webp','pdf']);
                ?>
                  <div style="background:#0b1a26;border:1px solid #1f3346;border-radius:10px;padding:10px;margin-bottom:8px">
                    <?php if ($note): ?>
                      <div style="color:#d7ecff; margin-bottom:6px"><?= nl2br(htmlspecialchars($note)) ?></div>
                    <?php endif; ?>
                    <div style="display:flex; gap:8px; flex-wrap:wrap">
                      <?php if ($file): ?>
                        <?php if ($previewable): ?>
                          <a class="btn ghost" href="../uploads/<?= htmlspecialchars($file) ?>" target="_blank" rel="noopener">
                            <i class="fa fa-eye"></i> View
                          </a>
                        <?php endif; ?>
                        <a class="btn ghost" href="download_submission.php?id=<?= $sid ?>">
                          <i class="fa fa-download"></i> Download
                        </a>
                      <?php else: ?>
                        <span class="muted">No file</span>
                      <?php endif; ?>
                    </div>
                    <?php if ($sat): ?>
                      <div class="mini" style="opacity:.7; margin-top:6px">
                        Submitted: <?= htmlspecialchars(date('M j, Y g:i A', strtotime($sat))) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if ($hasSubmission): ?>
              <div class="mcta" style="align-items:center; gap:8px; flex-wrap:wrap">
                <!-- Moderation -->
                <button class="miniBtn approve" onclick="updateMilestoneStatus(<?= $mid ?>, 'approved')"><i class="fa fa-check"></i> Approve</button>
                <button class="miniBtn rev" onclick="updateMilestoneStatus(<?= $mid ?>, 'revision_requested')"><i class="fa fa-rotate"></i> Request Revision</button>
                <button class="miniBtn reject" onclick="updateMilestoneStatus(<?= $mid ?>, 'rejected')"><i class="fa fa-xmark"></i> Reject</button>

                <!-- Payments (mock) -->
                <span class="mini" style="margin-left:8px; opacity:.85"><i class="fa fa-wallet"></i> Payment:</span>
                <span id="pay-pill-<?= $mid ?>" class="mini" style="padding:4px 8px;border-radius:999px;border:1px solid #2b3b53;">
                  <?= htmlspecialchars(strtoupper($payStatus)) ?>
                </span>

                <div>
                  <?php if ($payStatus === 'pending'): ?>
                    <button class="miniBtn" onclick="fundEscrow(<?= $mid ?>)"><i class="fa fa-lock"></i> Fund Escrow</button>
                  <?php endif; ?>

                  <?php if ($payStatus === 'escrowed'): ?>
                    <button class="miniBtn approve" onclick="releasePayment(<?= $mid ?>)"><i class="fa fa-unlock"></i> Release</button>
                  <?php endif; ?>

                  <?php if ($payStatus === 'released'): ?>
                    <span class="mini" style="color:#98ffdf;font-weight:700"><i class="fa fa-check-circle"></i> Released</span>
                  <?php endif; ?>
                </div>
              </div>
            <?php else: ?>
              <div class="mini" style="margin-top:8px; opacity:.8">Waiting for freelancer submission…</div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <!-- RIGHT -->
    <aside>
      <div class="sideCard">
        <div class="title" style="font-size:18px">Add Milestone</div>
        <div class="muted" style="margin-bottom:8px">Break the work into clear deliverables.</div>
        <div class="formGrid">
          <div class="field">
            <label>Title</label>
            <input type="text" id="m_title" placeholder="e.g. Homepage UI">
          </div>
          <div class="field">
            <label>Amount (USD)</label>
            <input type="number" id="m_amount" placeholder="e.g. 300">
          </div>
          <div class="field">
            <label>Due date</label>
            <input type="date" id="m_due" min="<?= date('Y-m-d') ?>">
          </div>
          <div class="field" style="grid-column:1/-1">
            <label>Description (optional)</label>
            <textarea id="m_desc" rows="3" placeholder="Expectations, scope, handoff…"></textarea>
          </div>
        </div>
        <div class="actions" style="margin-top:10px">
          <a class="btn primary" href="javascript:void(0)" id="addMilestoneBtn"><i class="fa fa-plus"></i> Add Milestone</a>
        </div>
      </div>

      <div class="sideCard">
        <div class="title" style="font-size:18px">Project Summary</div>
        <div class="muted">Freelancer</div>
        <?php if ($project['hired_freelancer_id']): ?>
          <div style="display:flex; gap:10px; align-items:center; margin-top:8px">
            <img src="../includes/images/<?= htmlspecialchars($project['freelancer_image']) ?>" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(127,221,255,.5)">
            <div>
              <div style="font-weight:700"><?= htmlspecialchars($project['freelancer_name']) ?></div>
              <div class="muted" style="font-size:12px"><?= htmlspecialchars($project['freelancer_email']) ?></div>
            </div>
          </div>
        <?php else: ?>
          <div class="muted" style="margin-top:8px">No freelancer hired yet.</div>
        <?php endif; ?>

        <div class="muted" style="margin-top:12px">Budget</div>
        <div style="font-weight:700; color:#7dd3ff">$<?= number_format((float)$project['budget'],2) ?></div>

        <?php if(!empty($project['deadline'])): ?>
          <div class="muted" style="margin-top:12px">Deadline</div>
          <div style="font-weight:700; color:#7dd3ff"><?= htmlspecialchars(date('M j, Y', strtotime($project['deadline']))) ?></div>
        <?php endif; ?>
      </div>
    </aside>
  </div>

<script>
const projectId = <?= (int)$project_id ?>;

function toast(icon, title){
  Swal.fire({toast:true, position:'top-end', icon, title, showConfirmButton:false, timer:2200});
}
async function postForm(data){
  const res = await fetch(location.href, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams(data).toString()
  });
  return await res.json();
}

// Add milestone
document.getElementById('addMilestoneBtn')?.addEventListener('click', async ()=>{
  const title = document.getElementById('m_title').value.trim();
  const amount= document.getElementById('m_amount').value;
  const due   = document.getElementById('m_due').value;
  const desc  = document.getElementById('m_desc').value;
  if (!title){ toast('warning','Title is required'); return; }
  const r = await postForm({ action:'add_milestone', title, amount, due_date: due, description: desc });
  if (r.success){
    confetti({particleCount:70, spread:70, origin:{y:.6}});
    toast('success','Milestone added');
    setTimeout(()=>location.reload(), 800);
  } else { toast('error', r.message || 'Failed'); }
});

// Update milestone status
async function updateMilestoneStatus(mid, newStatus){
  const confirm = await Swal.fire({
    title: 'Are you sure?',
    text: `Set milestone to "${newStatus.replace('_',' ')}"?`,
    icon: 'question', showCancelButton:true, confirmButtonText:'Yes', confirmButtonColor:'#00ffc3'
  });
  if (!confirm.isConfirmed) return;
  const r = await postForm({ action:'milestone_status', milestone_id: mid, new_status: newStatus });
  if (r.success){
    toast('success','Updated');
    setTimeout(()=>location.reload(), 600);
  } else {
    toast('error', r.message || 'Failed');
  }
}

// Mark project completed
document.getElementById('markDoneBtn')?.addEventListener('click', async ()=>{
  const ok = await Swal.fire({
    title:'Mark project completed?',
    text:'This will move it to submitted/completed state.',
    icon:'question', showCancelButton:true, confirmButtonText:'Yes', confirmButtonColor:'#00ffc3'
  });
  if (!ok.isConfirmed) return;
  const r = await postForm({ action:'mark_completed' });
  if (r.success){
    confetti({particleCount:100, spread:80, origin:{y:.6}});
    toast('success','Project marked completed');
    setTimeout(()=>location.reload(), 800);
  } else {
    toast('error', r.message || 'Failed');
  }
});

// Leave review
document.getElementById('leaveReviewBtn')?.addEventListener('click', async ()=>{
  const { value: formValues } = await Swal.fire({
    title: 'Leave a review',
    html:
      '<div style="text-align:left">'+
      '<label style="font-size:12px;color:#9db1c7">Rating (1-5)</label>'+
      '<input id="rv_rating" type="number" min="1" max="5" value="5" class="swal2-input" style="width:100%">'+
      '<label style="font-size:12px;color:#9db1c7">Comment</label>'+
      '<textarea id="rv_comment" class="swal2-textarea" placeholder="How was the collaboration?"></textarea>'+
      '</div>',
    focusConfirm: false, showCancelButton: true, confirmButtonText:'Submit', confirmButtonColor:'#00ffc3',
    preConfirm: () => {
      const rating  = document.getElementById('rv_rating').value;
      const comment = document.getElementById('rv_comment').value;
      return {rating, comment};
    }
  });
  if (!formValues) return;
  const r = await postForm({ action:'leave_review', rating: formValues.rating, comment: formValues.comment });
  if (r.success){ toast('success','Review submitted'); }
  else { toast('error', r.message || 'Failed'); }
});

// Payments (mock)
async function payCall(action, mid){
  // ensure a payments row exists
  await fetch('payments_mock.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams({ action:'ensure_row', project_id: projectId, milestone_id: mid })
  });

  const r = await fetch('payments_mock.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams({ action, project_id: projectId, milestone_id: mid })
  });
  return await r.json();
}
async function fundEscrow(mid){
  const ok = await Swal.fire({title:'Fund escrow for this milestone?', icon:'question', showCancelButton:true, confirmButtonText:'Yes', confirmButtonColor:'#00ffc3'});
  if(!ok.isConfirmed) return;
  const r = await payCall('fund_escrow', mid);
  if(r.success){ toast('success','Escrow funded'); setTimeout(()=>location.reload(), 600); }
  else { toast('error', r.message || 'Failed'); }
}
async function releasePayment(mid){
  const ok = await Swal.fire({title:'Release payment?', text:'Make sure the milestone is approved.', icon:'question', showCancelButton:true, confirmButtonText:'Release', confirmButtonColor:'#00ffc3'});
  if(!ok.isConfirmed) return;
  const r = await payCall('release', mid);
  if(r.success){ toast('success','Payment released'); setTimeout(()=>location.reload(), 600); }
  else { toast('error', r.message || 'Failed'); }
}
</script>
</body>
</html>
