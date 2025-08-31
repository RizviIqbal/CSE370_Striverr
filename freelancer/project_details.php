<?php
session_start();
include('../includes/auth_check.php');
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'freelancer') {
  header("Location: ../auth/login.php"); exit();
}

/* Accept project_id from GET or POST (fallback for AJAX posts) */
$project_id = null;
if (isset($_GET['project_id']) && ctype_digit($_GET['project_id'])) {
  $project_id = (int)$_GET['project_id'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['project_id']) && ctype_digit($_POST['project_id'])) {
  $project_id = (int)$_POST['project_id'];
}

if ($project_id === null) {
  http_response_code(400); die("Invalid project.");
}

$freelancer_id = (int)$_SESSION['user_id'];

/* ---------------- Utilities for flexible schemas ---------------- */
function table_cols(mysqli $c, string $table): array {
  $cols = [];
  if ($r = $c->query("SHOW COLUMNS FROM `$table`")) {
    while ($row = $r->fetch_assoc()) $cols[] = $row['Field'];
    $r->close();
  }
  return $cols;
}
function has_col(array $cols, string $name): bool { return in_array($name, $cols, true); }
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------------- Project access guard ----------------
   Allow viewing if:
   - this freelancer is hired on the project
   - OR the project is still posted (browsing allowed)
   - OR this freelancer has an application on it
-------------------------------------------------------- */
$sql = "
  SELECT p.project_id, p.title, p.description, p.budget, p.deadline, p.status, p.created_at,
         p.client_id, p.hired_freelancer_id,
         c.name AS client_name, c.email AS client_email,
         COALESCE(NULLIF(c.profile_image,''),'client.png') AS client_image
  FROM projects p
  JOIN users c ON c.user_id = p.client_id
  WHERE p.project_id = ?
    AND (
      p.hired_freelancer_id = ?
      OR p.status='posted'
      OR EXISTS (
        SELECT 1 FROM applications a
        WHERE a.project_id = p.project_id AND a.freelancer_id = ?
      )
    )
  LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $project_id, $freelancer_id, $freelancer_id);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$project) { http_response_code(404); die("Unauthorized or project not found."); }

$isHired = ((int)$project['hired_freelancer_id'] === $freelancer_id);

/* ---------------- Milestones (flex columns) ---------------- */
$msCols = table_cols($conn, 'milestones');
$msId   = has_col($msCols,'milestone_id') ? 'milestone_id' : (has_col($msCols,'id') ? 'id' : null);
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

/* ---------------- Submissions (flex) ---------------- */
$submissionsTable = 'milestone_submissions';
$subCols = table_cols($conn, $submissionsTable);
$subId   = has_col($subCols,'id') ? 'id' : (has_col($subCols,'submission_id') ? 'submission_id' : null);
$subMsFk = has_col($subCols,'milestone_id') ? 'milestone_id' : null;
$subPjFk = has_col($subCols,'project_id') ? 'project_id' : null;
$subFile = has_col($subCols,'file_path') ? 'file_path' : (has_col($subCols,'file') ? 'file' : (has_col($subCols,'attachment') ? 'attachment' : null));
$subNote = has_col($subCols,'note') ? 'note' : (has_col($subCols,'message') ? 'message' : null);
$subAt   = has_col($subCols,'created_at') ? 'created_at' : (has_col($subCols,'submitted_at') ? 'submitted_at' : null);

$submissionsByMs = [];
if ($subId && $subFile && ($subMsFk || $subPjFk)) {
  $pick = ["s.`$subId` AS sub_id", "s.`$subFile` AS sub_file"];
  if ($subNote) $pick[] = "s.`$subNote` AS sub_note";
  if ($subAt)   $pick[] = "s.`$subAt` AS sub_at";
  if ($subMsFk) $pick[] = "s.`$subMsFk` AS ms_fk";
  if ($subPjFk) $pick[] = "s.`$subPjFk` AS pj_fk";

  $sqlS = "SELECT ".implode(',', $pick)." FROM `$submissionsTable` s WHERE ";
  if ($subPjFk) $sqlS .= "s.`$subPjFk` = ?";
  else          $sqlS .= "s.`$subMsFk` IN (SELECT $msId FROM milestones WHERE project_id = ?)";

  $S = $conn->prepare($sqlS);
  $S->bind_param("i", $project_id);
  $S->execute();
  $resS = $S->get_result();
  while ($row = $resS->fetch_assoc()) {
    $msKey = $subMsFk ? (int)$row['ms_fk'] : null;
    if ($msKey === null) continue; // group by milestone
    $submissionsByMs[$msKey][] = $row;
  }
  $S->close();
}

/* ---------------- Payments (flex) ---------------- */
$payCols   = table_cols($conn, 'payments');
$payId     = has_col($payCols,'payment_id') ? 'payment_id' : (has_col($payCols,'id') ? 'id' : null);
$payPj     = has_col($payCols,'project_id') ? 'project_id' : null;
$payMs     = has_col($payCols,'milestone_id') ? 'milestone_id' : null;
$payPayer  = has_col($payCols,'payer_id') ? 'payer_id' : null;   // client
$payPayee  = has_col($payCols,'payee_id') ? 'payee_id' : null;   // freelancer
$payAmount = has_col($payCols,'amount') ? 'amount' : null;
$payStatus = has_col($payCols,'status') ? 'status' : null;
$payMethod = has_col($payCols,'method') ? 'method' : null;
$payTxn    = has_col($payCols,'txn_ref') ? 'txn_ref' : null;
$payAt     = has_col($payCols,'created_at') ? 'created_at' : null;
$paidAt    = has_col($payCols,'paid_at') ? 'paid_at' : null;

/* Map payments by milestone for quick status lookup */
$paymentsByMs = [];
if ($payId && $payMs && $payStatus) {
  $pick = ["p.`$payId` AS pid", "p.`$payMs` AS mid", "p.`$payStatus` AS pstatus"];
  if ($payAmount) $pick[] = "p.`$payAmount` AS pamount";
  if ($payMethod) $pick[] = "p.`$payMethod` AS pmethod";
  if ($payTxn)    $pick[] = "p.`$payTxn` AS ptxn";
  if ($paidAt)    $pick[] = "p.`$paidAt` AS ppaid_at";

  $paySql = "SELECT ".implode(',', $pick)." FROM payments p WHERE ";
  if ($payPj) $paySql .= "p.`$payPj` = ?";
  else        $paySql .= "p.`$payMs` IN (SELECT $msId FROM milestones WHERE project_id = ?)";

  $PS = $conn->prepare($paySql);
  $PS->bind_param("i", $project_id);
  $PS->execute();
  $rP = $PS->get_result();
  while ($row = $rP->fetch_assoc()) {
    $mid  = (int)$row['mid'];
    $paymentsByMs[$mid][] = $row;
  }
  $PS->close();
}

/* ---------------- POST: submit file / request payout ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  $action = $_POST['action'] ?? '';

  // Submit work
  if ($action === 'submit_work') {
    if (!$isHired) { echo json_encode(['success'=>false,'message'=>'Only hired freelancer can submit.']); exit; }
    if (!$subId || !$subFile || !$subMsFk) { echo json_encode(['success'=>false,'message'=>'Submissions table missing required columns.']); exit; }

    $mid  = (int)($_POST['milestone_id'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    if ($mid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid milestone']); exit; }

    // Ensure milestone in project
    $chk = $conn->prepare("SELECT $msId FROM milestones WHERE $msId=? AND project_id=?");
    $chk->bind_param("ii", $mid, $project_id);
    $chk->execute(); $okOwn = $chk->get_result()->fetch_assoc(); $chk->close();
    if (!$okOwn) { echo json_encode(['success'=>false,'message'=>'Milestone not found in this project']); exit; }

    $rel = '';
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
      $tmp = $_FILES['file']['tmp_name'];
      $name= $_FILES['file']['name'];
      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      $allowed = ['jpg','jpeg','png','gif','webp','pdf','zip','rar','7z','doc','docx','ppt','pptx','xls','xlsx','txt','md'];
      if (!in_array($ext, $allowed, true)) { echo json_encode(['success'=>false,'message'=>'Unsupported file type']); exit; }
      if (!is_dir("../uploads")) @mkdir("../uploads",0775,true);
      $new = 'msub_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
      $dest = "../uploads/".$new;
      if (!@move_uploaded_file($tmp,$dest)) { echo json_encode(['success'=>false,'message'=>'Failed to store file']); exit; }
      $rel = $new;
    }

    // Insert
    $cols=[]; $vals=[]; $types=''; $bind=[];
    $cols[]=$subMsFk; $vals[]='?'; $types.='i'; $bind[]=$mid;
    if ($subPjFk){ $cols[]=$subPjFk; $vals[]='?'; $types.='i'; $bind[]=$project_id; }
    if ($subNote){ $cols[]=$subNote;  $vals[]='?'; $types.='s'; $bind[]=$note; }
    if ($subFile){ $cols[]=$subFile;  $vals[]='?'; $types.='s'; $bind[]=$rel; }
    if ($subAt)  { $cols[]=$subAt;    $vals[]='NOW()'; }

    $sqlI = "INSERT INTO `$submissionsTable` (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
    $ins  = $conn->prepare($sqlI);
    if ($types) $ins->bind_param($types, ...$bind);
    $ok = $ins->execute();
    $ins->close();

    echo json_encode(['success'=>$ok,'message'=>$ok?'Submitted':'Failed']);
    exit;
  }

  // Request payout (mock): create a payments row for each APPROVED & UNPAID milestone
  if ($action === 'request_payout') {
    if (!$isHired) { echo json_encode(['success'=>false,'message'=>'Only hired freelancer can request payout']); exit; }
    if (!($payId && $payMs && $payStatus && $payAmount)) {
      echo json_encode(['success'=>false,'message'=>"Payments table missing columns (need id/payment_id, milestone_id, status, amount)."]); exit;
    }

    $reqMs = array_map('intval', $_POST['milestones'] ?? []);
    if (!$reqMs) { echo json_encode(['success'=>false,'message'=>'Nothing selected']); exit; }

    $created = 0;
    foreach ($reqMs as $mid) {
      // Pull milestone info
      $M = $conn->prepare("SELECT $msId, COALESCE(amount,0) amt, COALESCE(status,'') st FROM milestones WHERE $msId=? AND project_id=?");
      $M->bind_param("ii", $mid, $project_id);
      $M->execute(); $info = $M->get_result()->fetch_assoc(); $M->close();
      if (!$info) continue;

      $amt = (float)$info['amt'];
      $st  = (string)$info['st'];
      if (!in_array($st, ['approved','released','complete','completed','done'], true)) {
        // Only allow if approved-ish
        continue;
      }

      // Already paid/processing?
      if ($payPj) {
        $C = $conn->prepare("SELECT COUNT(*) c FROM payments WHERE `$payMs`=? AND `$payPj`=? AND `$payStatus` IN ('requested','processing','released','paid')");
        $C->bind_param("ii", $mid, $project_id);
      } else {
        $C = $conn->prepare("SELECT COUNT(*) c FROM payments WHERE `$payMs`=? AND `$payStatus` IN ('requested','processing','released','paid')");
        $C->bind_param("i", $mid);
      }
      $C->execute(); $C->bind_result($cnt); $C->fetch(); $C->close();
      if ($cnt > 0) continue;

      // Insert mock request
      $cols=[]; $vals=[]; $types=''; $bind=[];
      $cols[]=$payMs;     $vals[]='?'; $types.='i'; $bind[]=$mid;
      if ($payPj){ $cols[]=$payPj;     $vals[]='?'; $types.='i'; $bind[]=$project_id; }
      if ($payPayer){ $cols[]=$payPayer; $vals[]='?'; $types.='i'; $bind[]=(int)$project['client_id']; }
      if ($payPayee){ $cols[]=$payPayee; $vals[]='?'; $types.='i'; $bind[]=$freelancer_id; }
      if ($payAmount){ $cols[]=$payAmount; $vals[]='?'; $types.='d'; $bind[]=$amt; }
      if ($payStatus){ $cols[]=$payStatus; $vals[]="'requested'"; }
      if ($payMethod){ $cols[]=$payMethod; $vals[]="'mock'"; }
      if ($payAt){ $cols[]=$payAt; $vals[]='NOW()'; }

      $sqlPay = "INSERT INTO payments (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
      $ins = $conn->prepare($sqlPay);
      if ($types) $ins->bind_param($types, ...$bind);
      if ($ins->execute()) $created++;
      $ins->close();
    }

    if ($created > 0) echo json_encode(['success'=>true,'message'=>"Requested payout for $created milestone(s)."]);
    else echo json_encode(['success'=>false,'message'=>'No eligible milestones found or already in payment flow.']);
    exit;
  }

  echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Striverr | Project Details (Freelancer)</title>
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
  .panel{
    background:linear-gradient(180deg, var(--panel), #13202e);
    border:1px solid rgba(255,255,255,.06);
    border-radius:18px; padding:22px; box-shadow:var(--glow);
  }
  .title{font-size:22px; font-weight:700}
  .muted{color:var(--muted); font-size:13px}
  .head{display:flex; justify-content:space-between; align-items:start; gap:18px; margin-bottom:6px;}
  .status{display:inline-block; padding:6px 12px; border-radius:999px; font-weight:800; font-size:12px; color:#07121c; background:#7dd3ff;}
  .meta{display:flex; gap:12px; flex-wrap:wrap; margin-top:8px; color:#bfe9ff; font-size:12px}
  .meta .pill{background:#0b1a26; border:1px solid rgba(255,255,255,.08); padding:6px 10px; border-radius:14px}

  .client{display:flex; gap:12px; align-items:center; margin-top:14px; background:#0e1a27; border:1px solid #1f3346; padding:12px; border-radius:12px;}
  .client img{width:44px; height:44px; border-radius:50%; object-fit:cover; border:2px solid rgba(127,221,255,.5)}
  .btn{text-decoration:none; text-align:center; padding:10px 12px; border-radius:12px; cursor:pointer; border:1px solid rgba(255,255,255,.08); font-weight:800; letter-spacing:.2px; transition:transform .15s ease, box-shadow .15s ease, background .15s ease;}
  .btn.primary{ background:linear-gradient(90deg, var(--mint), var(--accent)); color:#07121c; box-shadow:var(--glow); }
  .btn.ghost{ background:#102133; color:#cfeaff; }
  .btn.ghost:hover{ background:#0f2031; }

  .milestone{background:#0e1a27; border:1px solid #1f3346; border-radius:14px; padding:14px; margin-bottom:12px}
  .mhead{display:flex; justify-content:space-between; align-items:center; gap:12px}
  .mstat{font-size:11px; padding:5px 9px; border-radius:999px; border:1px solid rgba(255,255,255,.1)}
  .mini{color:#9db1c7; font-size:12px}

  .submitWrap{margin-top:12px; background:#0b1a26; border:1px dashed #2a4258; border-radius:12px; padding:12px}
  .submitWrap input[type=file], .submitWrap textarea{width:100%; background:#0b1a26; border:1px solid #1f3346; color:#eaf2ff; border-radius:10px; padding:10px 12px; outline:none}
  .submitWrap textarea{margin-top:8px; min-height:90px}

  .subList{margin-top:10px}
  .subItem{background:#0b1a26;border:1px solid #1f3346;border-radius:10px;padding:10px;margin-bottom:8px}

  .paychip{padding:4px 10px;border-radius:999px;border:1px solid rgba(255,255,255,.1);font-size:11px}
  .pay-pending{background:#1a2433;color:#cfeaff}
  .pay-req{background:#2a2440;color:#d9d1ff}
  .pay-proc{background:#2a3522;color:#dbffdc}
  .pay-paid{background:#163325;color:#caffd7;border-color:#2b6b4d}
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
          <div class="title"><?= e($project['title']) ?></div>
          <div class="meta">
            <span class="pill"><i class="fa fa-sack-dollar"></i> $<?= number_format((float)$project['budget'],2) ?></span>
            <?php if(!empty($project['deadline'])): ?>
              <span class="pill"><i class="fa fa-calendar"></i> <?= e(date('M j, Y', strtotime($project['deadline']))) ?></span>
            <?php endif; ?>
            <span class="pill"><i class="fa fa-clock"></i> Started <?= e(date('M j, Y', strtotime($project['created_at']))) ?></span>
          </div>
        </div>
        <div><span class="status"><?= strtoupper($project['status']) ?></span></div>
      </div>

      <div style="margin-top:12px; line-height:1.7; color:#d7ecff">
        <?= nl2br(e($project['description'])) ?>
      </div>

      <div class="client">
        <img src="../includes/images/<?= e($project['client_image']) ?>" alt="Client">
        <div style="flex:1">
          <div style="font-weight:700"><?= e($project['client_name']) ?></div>
          <div class="muted"><?= e($project['client_email']) ?></div>
        </div>
        <a class="btn ghost" href="../chat/chat.php?project_id=<?= (int)$project['project_id'] ?>"><i class="fa fa-comments"></i> Chat</a>
      </div>

      <hr style="border-color:#1c2b3b; margin:18px 0">

      <div class="title">Milestones</div>
      <div class="muted" style="margin-bottom:10px">Submit your work per milestone. Once approved by client, request payout.</div>

      <?php if (empty($milestones)): ?>
        <div class="muted" style="opacity:.8">No milestones yet.</div>
      <?php else: ?>
        <?php foreach ($milestones as $m):
          $mid = (int)$m[$msId];
          $st  = $m['status'] ?? 'pending';
          $amt = isset($m['amount']) ? (float)$m['amount'] : 0;
          $due = isset($m['due_date']) && $m['due_date'] ? date('M j, Y', strtotime($m['due_date'])) : null;
          $desc= $m['description'] ?? '';

          $subs = $submissionsByMs[$mid] ?? [];
          $pays = $paymentsByMs[$mid] ?? [];

          // Payment state summary
          $pstate = 'pending';
          if (!empty($pays)) {
            $states = array_map(fn($x)=>strtolower((string)$x['pstatus']), $pays);
            if     (array_intersect($states, ['paid','released'])) $pstate = 'paid';
            elseif (in_array('processing',$states,true)) $pstate = 'processing';
            elseif (in_array('requested',$states,true))  $pstate = 'requested';
          }
          $payChip = '<span class="paychip pay-pending">Payment: Pending</span>';
          if ($pstate==='requested')  $payChip = '<span class="paychip pay-req">Payment: Requested</span>';
          if ($pstate==='processing') $payChip = '<span class="paychip pay-proc">Payment: Processing</span>';
          if ($pstate==='paid')       $payChip = '<span class="paychip pay-paid">Payment: Paid</span>';

          $approvish = in_array($st, ['approved','released','complete','completed','done'], true);
          $canRequestThis = ($approvish && $pstate==='pending' && $amt>0);
        ?>
          <div class="milestone" id="ms-<?= $mid ?>">
            <div class="mhead">
              <div>
                <strong><?= e($m['title'] ?? 'Milestone') ?></strong>
                <div class="mini">
                  <?php if ($amt): ?> $<?= number_format($amt,2) ?> · <?php endif; ?>
                  <?php if ($due): ?> Due <?= e($due) ?> <?php endif; ?>
                </div>
              </div>
              <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap">
                <span class="mstat" style="background:<?= $st==='approved'?'#98ffdf':($st==='rejected'?'#ffb3c1':($st==='revision_requested'?'#ffd16633':'#7dd3ff33')) ?>; color:#cfeaff">
                  <?= strtoupper($st) ?>
                </span>
                <?= $payChip ?>
                <?php if ($canRequestThis): ?>
                  <label class="mini" style="display:inline-flex; gap:6px; align-items:center; cursor:pointer">
                    <input type="checkbox" name="milestones[]" value="<?= $mid ?>"> include in payout
                  </label>
                <?php endif; ?>
              </div>
            </div>

            <?php if ($desc): ?>
              <div class="mini" style="margin-top:6px; color:#d7ecff"><?= nl2br(e($desc)) ?></div>
            <?php endif; ?>

            <!-- Submit work (only if hired) -->
            <?php if ($isHired): ?>
              <div class="submitWrap">
                <form class="submitForm" data-mid="<?= $mid ?>" enctype="multipart/form-data">
                  <input type="hidden" name="action" value="submit_work">
                  <input type="hidden" name="project_id" value="<?= (int)$project['project_id'] ?>"><!-- critical -->
                  <input type="hidden" name="milestone_id" value="<?= $mid ?>">
                  <label class="mini">Upload file (optional)</label>
                  <input type="file" name="file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.zip,.rar,.7z,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.md">
                  <label class="mini" style="margin-top:8px">Note (optional)</label>
                  <textarea name="note" placeholder="Describe what you delivered, links, instructions…"></textarea>
                  <div style="display:flex; gap:10px; margin-top:10px">
                    <button class="btn primary" type="submit"><i class="fa fa-paper-plane"></i> Submit</button>
                  </div>
                </form>
              </div>
            <?php endif; ?>

            <?php if (!empty($subs)): ?>
              <div class="subList">
                <div class="mini" style="opacity:.8; margin-bottom:6px"><i class="fa fa-clock-rotate-left"></i> Previous submissions</div>
                <?php foreach ($subs as $s):
                  $file = $s['sub_file'] ?? '';
                  $note = $s['sub_note'] ?? '';
                  $sat  = $s['sub_at']   ?? '';
                  $sid  = (int)$s['sub_id'];
                  $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                  $previewable = in_array($ext,['jpg','jpeg','png','gif','webp','pdf']);
                ?>
                  <div class="subItem">
                    <?php if ($note): ?>
                      <div style="color:#d7ecff; margin-bottom:6px"><?= nl2br(e($note)) ?></div>
                    <?php endif; ?>
                    <div style="display:flex; gap:8px; flex-wrap:wrap">
                      <?php if ($file): ?>
                        <?php if ($previewable): ?>
                          <a class="btn ghost" href="../uploads/<?= e($file) ?>" target="_blank" rel="noopener"><i class="fa fa-eye"></i> View</a>
                        <?php endif; ?>
                        <a class="btn ghost" href="download_submission.php?id=<?= $sid ?>"><i class="fa fa-download"></i> Download</a>
                      <?php else: ?>
                        <span class="muted">No file</span>
                      <?php endif; ?>
                    </div>
                    <?php if ($sat): ?>
                      <div class="mini" style="opacity:.7; margin-top:6px">Submitted: <?= e(date('M j, Y g:i A', strtotime($sat))) ?></div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <!-- Bulk payout (mock) — OUTSIDE any form -->
        <div id="payoutBox" style="display:flex; gap:10px; flex-wrap:wrap; margin-top:14px">
          <button class="btn primary" type="button" id="requestPayoutBtn"><i class="fa fa-hand-holding-dollar"></i> Request Payout (selected)</button>
        </div>
      <?php endif; ?>
    </section>

    <!-- RIGHT -->
    <aside>
      <div class="panel">
        <div class="title" style="font-size:18px">Project Summary</div>
        <div class="muted">Client</div>
        <div style="display:flex; gap:10px; align-items:center; margin-top:8px">
          <img src="../includes/images/<?= e($project['client_image']) ?>" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(127,221,255,.5)">
          <div>
            <div style="font-weight:700"><?= e($project['client_name']) ?></div>
            <div class="muted" style="font-size:12px"><?= e($project['client_email']) ?></div>
          </div>
        </div>

        <div class="muted" style="margin-top:12px">Budget</div>
        <div style="font-weight:700; color:#7dd3ff">$<?= number_format((float)$project['budget'],2) ?></div>

        <?php if(!empty($project['deadline'])): ?>
          <div class="muted" style="margin-top:12px">Deadline</div>
          <div style="font-weight:700; color:#7dd3ff"><?= e(date('M j, Y', strtotime($project['deadline']))) ?></div>
        <?php endif; ?>
      </div>

      <!-- Payments summary -->
      <div class="panel" style="margin-top:14px">
        <div class="title" style="font-size:18px">Payments</div>
        <?php
          // compute totals
          $totalAmt = 0; $approvedAmt = 0; $paidAmt = 0; $requestedCnt=0; $processingCnt=0; $paidCnt=0;
          foreach ($milestones as $m) {
            $mid = (int)$m[$msId];
            $amt = isset($m['amount']) ? (float)$m['amount'] : 0;
            $st  = $m['status'] ?? 'pending';
            $totalAmt += $amt;
            if (in_array($st, ['approved','released','complete','completed','done'],true)) $approvedAmt += $amt;

            $pays = $paymentsByMs[$mid] ?? [];
            if (!empty($pays)) {
              $states = array_map(fn($x)=>strtolower((string)$x['pstatus']), $pays);
              if (array_intersect($states, ['paid','released'])) { $paidAmt += $amt; $paidCnt++; }
              elseif (in_array('processing',$states,true)) $processingCnt++;
              elseif (in_array('requested',$states,true))  $requestedCnt++;
            }
          }
        ?>
        <div class="muted" style="margin-top:6px">Project total</div>
        <div style="font-weight:700">$<?= number_format($totalAmt,2) ?></div>
        <div class="muted" style="margin-top:6px">Approved (eligible)</div>
        <div style="font-weight:700">$<?= number_format($approvedAmt,2) ?></div>
        <div class="muted" style="margin-top:6px">Paid</div>
        <div style="font-weight:700;color:#a8ffc9">$<?= number_format($paidAmt,2) ?></div>

        <div class="muted" style="margin-top:8px">Status</div>
        <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:6px">
          <span class="paychip pay-req">Requested: <?= (int)$requestedCnt ?></span>
          <span class="paychip pay-proc">Processing: <?= (int)$processingCnt ?></span>
          <span class="paychip pay-paid">Paid: <?= (int)$paidCnt ?></span>
        </div>

        <div class="muted" style="margin-top:10px">Payout method</div>
        <div style="font-weight:700">Mock · Bank Transfer</div>
        <div class="muted" style="font-size:12px;margin-top:6px;opacity:.85">Client marks as paid on their side in the mock flow. You can “request payout” for approved milestones above.</div>
      </div>
    </aside>
  </div>

<script>
function toast(icon, title){
  Swal.fire({toast:true, position:'top-end', icon, title, showConfirmButton:false, timer:2200});
}

// Submit files per milestone
document.querySelectorAll('.submitForm').forEach(form=>{
  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const fd = new FormData(form);
    // action + project_id + milestone_id are in the form
    try{
      const res = await fetch(location.href, { method:'POST', body: fd });
      const data = await res.json();
      if (data.success){
        confetti({particleCount:80, spread:70, origin:{y:.6}});
        toast('success','Submitted!');
        setTimeout(()=>location.reload(), 800);
      } else {
        toast('error', data.message || 'Failed to submit');
      }
    }catch(err){
      toast('error', 'Network error');
    }
  });
});

// Request payout (mock)
document.getElementById('requestPayoutBtn')?.addEventListener('click', async ()=>{
  const checked = [...document.querySelectorAll('input[name="milestones[]"]:checked')].map(i=>i.value);
  if (!checked.length){ toast('info','Select approved & unpaid milestones first'); return; }

  const ok = await Swal.fire({
    title:'Request payout?',
    text:`This sends a mock payout request to the client for ${checked.length} milestone(s).`,
    icon:'question', showCancelButton:true, confirmButtonText:'Request', confirmButtonColor:'#00ffc3'
  });
  if (!ok.isConfirmed) return;

  const body = new URLSearchParams();
  body.set('action', 'request_payout');
  body.set('project_id', '<?= (int)$project['project_id'] ?>'); // critical
  checked.forEach(v => body.append('milestones[]', v));

  try{
    const res = await fetch(location.href, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: body.toString()
    });
    const data = await res.json();
    if (data.success){
      confetti({particleCount:90, spread:80, origin:{y:.6}});
      toast('success', data.message || 'Requested');
      setTimeout(()=>location.reload(), 900);
    } else {
      toast('error', data.message || 'Failed');
    }
  }catch(e){
    toast('error','Network error');
  }
});
</script>
</body>
</html>
