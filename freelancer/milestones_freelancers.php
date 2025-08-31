<?php
session_start();
include('../includes/auth_check.php');
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'freelancer') {
  header("Location: ../auth/login.php");
  exit;
}

$freelancer_id = (int)$_SESSION['user_id'];

/* ============
   FLEX HELPERS
   ============ */
function tableHasCol($conn, $table, $col) {
  $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
  return $res && $res->num_rows > 0;
}
function pickCol($conn, $table, $candidates, $fallback = null) {
  foreach ($candidates as $c) if (tableHasCol($conn, $table, $c)) return $c;
  return $fallback;
}

/* Milestone schema detection */
$msIdCol   = pickCol($conn, 'milestones', ['milestone_id','id'], 'id');
$msTitle   = pickCol($conn, 'milestones', ['title','name'], 'title');
$msDesc    = pickCol($conn, 'milestones', ['description','details','desc'], null);
$msAmount  = pickCol($conn, 'milestones', ['amount','price','value'], null);
$msDue     = pickCol($conn, 'milestones', ['due_date','deadline'], null);
$msStatus  = pickCol($conn, 'milestones', ['status'], null);
$msProjId  = pickCol($conn, 'milestones', ['project_id'], 'project_id');

/* Submissions schema detection (multiple submissions) */
$subTableExists = $conn->query("SHOW TABLES LIKE 'submissions'")->num_rows > 0;
$subId     = $subTableExists ? pickCol($conn, 'submissions', ['submission_id','id'], 'id') : null;
$subMsId   = $subTableExists ? pickCol($conn, 'submissions', ['milestone_id'], 'milestone_id') : null;
$subFile   = $subTableExists ? pickCol($conn, 'submissions', ['file_path','submission_file','work_file','file'], null) : null;
$subNote   = $subTableExists ? pickCol($conn, 'submissions', ['note','comments','comment','message','submission_note'], null) : null;
$subDate   = $subTableExists ? pickCol($conn, 'submissions', ['created_at','submission_date','submitted_at','date'], null) : null;
$subStatus = $subTableExists ? pickCol($conn, 'submissions', ['status'], null) : null;

/* ============================
   AJAX: submit work (upload)
   ============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='submit_work') {
  header('Content-Type: application/json');

  if (!$subTableExists || !$subMsId || !$subFile) {
    echo json_encode(['success'=>false,'message'=>"Submissions table/columns not found."]);
    exit;
  }

  $mid = (int)($_POST['milestone_id'] ?? 0);
  $note = trim($_POST['note'] ?? '');

  // Verify milestone belongs to a project where this freelancer is hired
  $verify = $conn->prepare("
    SELECT 1
    FROM milestones m
    JOIN projects p ON p.project_id = m.$msProjId
    WHERE m.$msIdCol = ? AND p.hired_freelancer_id = ?
    LIMIT 1
  ");
  $verify->bind_param("ii", $mid, $freelancer_id);
  $verify->execute();
  $okOwn = $verify->get_result()->num_rows > 0;
  $verify->close();

  if (!$okOwn) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized milestone.']);
    exit;
  }

  if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success'=>false,'message'=>'File upload failed.']);
    exit;
  }

  $uploadDir = "../uploads/";
  if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);

  $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
  $allowed = ['zip','rar','7z','pdf','doc','docx','ppt','pptx','png','jpg','jpeg','gif','mp4','webm','txt','csv','xlsx','json','md'];
  if (!in_array($ext, $allowed, true)) {
    echo json_encode(['success'=>false,'message'=>'File type not allowed.']);
    exit;
  }

  $newName = "sub_".time()."_".bin2hex(random_bytes(4)).".$ext";
  $dest = $uploadDir.$newName;
  if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
    echo json_encode(['success'=>false,'message'=>'Could not save file.']);
    exit;
  }

  // Build INSERT
  $cols = [$subMsId, $subFile];
  $vals = "?, ?";
  $types = "is";
  $bind = [$mid, $newName];

  if ($subNote)   { $cols[] = $subNote;   $vals .= ", ?"; $types.="s"; $bind[] = $note; }
  if ($subStatus) { $cols[] = $subStatus; $vals .= ", ?"; $types.="s"; $bind[] = 'in_review'; }
  if ($subDate)   { $cols[] = $subDate;   $vals .= ", NOW()"; }

  $sql = "INSERT INTO submissions (".implode(',', $cols).") VALUES ($vals)";
  $ins = $conn->prepare($sql);
  $ins->bind_param($types, ...$bind);
  $ok = $ins->execute();
  $ins->close();

  echo json_encode(['success'=>$ok, 'message'=>$ok?'Submitted for review.':'DB error while saving submission.']);
  exit;
}

/* ======================================================
   Fetch milestones across hired projects for freelancer
   ====================================================== */

$Q = $conn->prepare("
  SELECT p.project_id, p.title AS project_title
  FROM projects p
  WHERE p.hired_freelancer_id = ? AND p.status IN ('active','submitted','in_progress')
  ORDER BY p.created_at DESC
");
$Q->bind_param("i", $freelancer_id);
$Q->execute();
$projects = $Q->get_result()->fetch_all(MYSQLI_ASSOC);
$Q->close();

if (!$projects) {
  // graceful empty
  $projects = [];
}

// Build milestones query
$selects = [
  "m.$msIdCol   AS mid",
  "m.$msProjId  AS project_id",
  "m.$msTitle   AS mtitle"
];
if ($msDesc)   $selects[] = "m.$msDesc    AS mdesc";
if ($msAmount) $selects[] = "m.$msAmount  AS mamount";
if ($msDue)    $selects[] = "m.$msDue     AS mdue";
if ($msStatus) $selects[] = "m.$msStatus  AS mstatus";

$sqlMs = "
  SELECT ".implode(',', $selects).",
         p.title AS project_title
  FROM milestones m
  JOIN projects p ON p.project_id = m.$msProjId
  WHERE p.hired_freelancer_id = ?
  ORDER BY " . ($msDue ? "m.$msDue" : "m.$msIdCol") . " ASC
";
$M = $conn->prepare($sqlMs);
$M->bind_param("i", $freelancer_id);
$M->execute();
$milestones = $M->get_result()->fetch_all(MYSQLI_ASSOC);
$M->close();

/* Fetch latest submission per milestone (if table exists) */
$latestByMs = [];
if ($subTableExists && $milestones) {
  $ids = array_column($milestones, 'mid');
  if ($ids) {
    // Build an IN (...) safely
    $place = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $sCols = ["s.$subMsId AS milestone_id"];
    if ($subFile)   $sCols[] = "s.$subFile   AS file_path";
    if ($subNote)   $sCols[] = "s.$subNote   AS note";
    if ($subStatus) $sCols[] = "s.$subStatus AS status";
    if ($subDate)   $sCols[] = "s.$subDate   AS created_at";

    $sqlSub = "
      SELECT ".implode(',', $sCols)."
      FROM submissions s
      WHERE s.$subMsId IN ($place)
      ORDER BY ".($subDate ? "s.$subDate" : "s.$subId")." DESC
    ";

    $S = $conn->prepare($sqlSub);
    $S->bind_param($types, ...$ids);
    $S->execute();
    $res = $S->get_result();
    while ($row = $res->fetch_assoc()) {
      $msid = (int)$row['milestone_id'];
      if (!isset($latestByMs[$msid])) {
        $latestByMs[$msid] = $row; // first row is latest due to ORDER BY DESC
      }
    }
    $S->close();
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Striverr | Your Milestones</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
<style>
  :root{
    --bg:#0f1722; --panel:#1b2736; --panel2:#12202e; --ink:#eaf2ff; --muted:#9db1c7;
    --accent:#00bfff; --mint:#00ffc3; --chip:#0b1a28; --chipBorder:#1f4259; --glow:0 10px 30px rgba(0,191,255,.25);
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
  header{display:flex; justify-content:space-between; align-items:center; margin:0 auto 18px; max-width:1200px}
  .brand{font-size:26px; font-weight:700; color:#7dd3ff; letter-spacing:.3px}
  .back{
    display:inline-flex; gap:10px; align-items:center;
    padding:10px 14px; background:#132234; border:1px solid rgba(255,255,255,.06);
    color:#cfeaff; border-radius:12px; text-decoration:none; font-weight:600;
    transition:transform .15s ease, box-shadow .15s ease;
  }
  .back:hover{transform:translateY(-1px); box-shadow:0 10px 24px rgba(0,191,255,.12)}

  .wrap{max-width:1200px; margin:0 auto;}
  .filters{display:flex; gap:10px; flex-wrap:wrap; margin:6px 0 18px}
  .input, .select{
    background:#0e1a27; border:1px solid #1f3346; color:var(--ink);
    border-radius:12px; padding:12px 14px; outline:none;
  }
  .input{min-width:260px}
  .select{min-width:160px}
  .input:focus, .select:focus{border-color:var(--accent); box-shadow:0 0 0 3px rgba(0,191,255,.15)}

  .grid{display:grid; gap:16px; grid-template-columns:repeat(auto-fit,minmax(320px,1fr))}
  .card{
    background:linear-gradient(180deg, var(--panel), #13202e);
    border:1px solid rgba(255,255,255,.06);
    border-radius:16px; padding:18px; position:relative;
    box-shadow:0 8px 20px rgba(0,0,0,.25);
    transition:transform .18s ease, box-shadow .18s ease;
    display:flex; flex-direction:column; gap:12px;
  }
  .card:hover{ transform:translateY(-3px); box-shadow:0 14px 28px rgba(0,191,255,.22); }

  .top{display:flex; justify-content:space-between; gap:12px}
  .ptitle{font-weight:700}
  .chips{display:flex; flex-wrap:wrap; gap:8px}
  .chip{background:var(--chip); border:1px solid var(--chipBorder); color:#bfe9ff; font-size:12px; padding:6px 10px; border-radius:14px}
  .muted{color:var(--muted); font-size:12px}
  .desc{color:#d7ecff; opacity:.95; font-size:13px}

  .status{padding:6px 10px; border-radius:999px; font-weight:800; font-size:11px; border:1px solid rgba(255,255,255,.08)}
  .st-pending{background:#7dd3ff22} .st-in_review{background:#ffd16633} .st-revision_requested{background:#ffd16655}
  .st-approved{background:#98ffdf88} .st-rejected{background:#ffb3c188}

  .submitBox{background:#0e1a27; border:1px solid #1f3346; padding:12px; border-radius:12px}
  .submitBox label{font-size:12px; color:#9db1c7}
  .submitBox input[type=file], .submitBox textarea{
    width:100%; background:#0b1a26; border:1px solid #1f3346; color:#eaf2ff; border-radius:10px; padding:10px 12px; outline:none; margin-top:6px;
  }
  .row-actions{display:flex; gap:10px; flex-wrap:wrap}
  .btn{
    text-decoration:none; text-align:center; padding:10px 12px; border-radius:12px; cursor:pointer;
    border:1px solid rgba(255,255,255,.08); font-weight:800; letter-spacing:.2px;
    transition:transform .15s ease, box-shadow .15s ease, background .15s ease;
  }
  .btn.primary{ background:linear-gradient(90deg, var(--mint), var(--accent)); color:#07121c; box-shadow:var(--glow); }
  .btn.ghost{ background:#102133; color:#cfeaff; }
  .btn.ghost:hover{ background:#0f2031; }

  .empty{
    background:linear-gradient(180deg, var(--panel2), #0b1a26);
    border:1px dashed #2a4b66; border-radius:16px; padding:40px;
    text-align:center; color:var(--muted); margin-top:10px;
  }
</style>
</head>
<body>
  <header>
    <div class="brand">Striverr — Your Milestones</div>
    <a class="back" href="dashboard.php"><i class="fa fa-arrow-left"></i> Dashboard</a>
  </header>

  <div class="wrap">
    <div class="filters">
      <input class="input" id="searchBox" type="text" placeholder="Search by project or milestone…">
      <select class="select" id="statusFilter">
        <option value="">Any status</option>
        <option value="pending">Pending</option>
        <option value="in_review">In Review</option>
        <option value="revision_requested">Revision Requested</option>
        <option value="approved">Approved</option>
        <option value="rejected">Rejected</option>
      </select>
      <select class="select" id="dueFilter">
        <option value="">Any due</option>
        <option value="7">Due in ≤ 7 days</option>
        <option value="14">Due in ≤ 14 days</option>
        <option value="30">Due in ≤ 30 days</option>
      </select>
    </div>

    <?php if (empty($milestones)): ?>
      <div class="empty">
        <div style="font-size:20px; font-weight:700; margin-bottom:6px;">No milestones yet</div>
        <div>Once a client adds milestones to your active projects, they’ll show up here.</div>
      </div>
    <?php else: ?>
      <div class="grid" id="grid">
        <?php foreach ($milestones as $m):
          $mid   = (int)$m['mid'];
          $title = $m['mtitle'] ?? 'Milestone';
          $desc  = $m['mdesc'] ?? '';
          $amt   = isset($m['mamount']) ? (float)$m['mamount'] : null;
          $due   = $m['mdue'] ?? '';
          $status= strtolower($m['mstatus'] ?? 'pending');
          $projT = $m['project_title'] ?? 'Project';

          $sub   = $latestByMs[$mid] ?? null;
          $subStatus = $sub ? strtolower($sub['status'] ?? '') : '';
          $fileLink  = $sub && !empty($sub['file_path']) ? "../uploads/".htmlspecialchars($sub['file_path']) : '';
          $noteText  = $sub['note'] ?? '';
          $when      = $sub['created_at'] ?? '';
        ?>
          <div class="card"
               data-title="<?= htmlspecialchars(mb_strtolower($projT.' '.$title)) ?>"
               data-status="<?= htmlspecialchars($status) ?>"
               data-due="<?= htmlspecialchars($due) ?>">
            <div class="top">
              <div>
                <div class="ptitle"><?= htmlspecialchars($projT) ?></div>
                <div class="muted"><?= htmlspecialchars($title) ?></div>
              </div>
              <div class="status st-<?= htmlspecialchars($status) ?>"><?= strtoupper($status) ?></div>
            </div>

            <div class="chips">
              <?php if ($amt !== null): ?><span class="chip"><i class="fa fa-sack-dollar"></i> $<?= number_format($amt,2) ?></span><?php endif; ?>
              <?php if ($due): ?><span class="chip"><i class="fa fa-calendar"></i> <?= htmlspecialchars(date('M j, Y', strtotime($due))) ?></span><?php endif; ?>
            </div>

            <?php if ($desc): ?>
              <div class="desc"><?= nl2br(htmlspecialchars($desc)) ?></div>
            <?php endif; ?>

            <?php if ($sub): ?>
              <div class="submitBox">
                <label>Latest Submission</label>
                <div class="muted" style="margin:6px 0">
                  Status: <strong><?= strtoupper($subStatus ?: '—') ?></strong>
                  <?php if ($when): ?> • <?= htmlspecialchars(date('M j, Y g:i A', strtotime($when))) ?><?php endif; ?>
                </div>
                <?php if ($noteText): ?>
                  <div style="color:#d7ecff; white-space:pre-wrap"><?= htmlspecialchars($noteText) ?></div>
                <?php endif; ?>
                <?php if ($fileLink): ?>
                  <div class="row-actions" style="margin-top:8px">
                    <a class="btn ghost" href="<?= $fileLink ?>" target="_blank"><i class="fa fa-file-arrow-down"></i> View / Download</a>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <?php
              // Can submit? Allow when pending or revision_requested or in_review (if you want re-upload)
              $canSubmit = in_array($status, ['pending','revision_requested','in_review','rejected']);
            ?>
            <?php if ($canSubmit): ?>
              <form class="submitBox" style="margin-top:8px" onsubmit="return submitWork(event, <?= $mid ?>)">
                <label>Submit Work (file + note)</label>
                <input type="file" name="file" required>
                <textarea name="note" rows="3" placeholder="Add a note for the client…"></textarea>
                <div class="row-actions" style="margin-top:10px">
                  <button class="btn primary" type="submit"><i class="fa fa-paper-plane"></i> Send for Review</button>
                </div>
              </form>
            <?php else: ?>
              <div class="muted">Submissions closed for this milestone.</div>
            <?php endif; ?>

          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

<script>
function toast(icon, title){
  Swal.fire({toast:true, position:'top-end', icon, title, showConfirmButton:false, timer:2200});
}

async function submitWork(e, milestoneId){
  e.preventDefault();
  const form = e.currentTarget;
  const file = form.querySelector('input[type=file]').files[0];
  const note = form.querySelector('textarea').value.trim();

  if (!file) { toast('warning','Please select a file'); return false; }

  const fd = new FormData();
  fd.append('action','submit_work');
  fd.append('milestone_id', milestoneId);
  fd.append('file', file);
  fd.append('note', note);

  try {
    const res = await fetch(location.href, { method:'POST', body: fd });
    const data = await res.json();
    if (data.success){
      confetti({particleCount:80, spread:75, origin:{y:.6}});
      toast('success', data.message || 'Submitted');
      setTimeout(()=>location.reload(), 900);
    } else {
      toast('error', data.message || 'Failed to submit');
    }
  } catch (err) {
    toast('error','Network error');
  }
  return false;
}

// Filters
const searchBox = document.getElementById('searchBox');
const statusFilter = document.getElementById('statusFilter');
const dueFilter = document.getElementById('dueFilter');
const grid = document.getElementById('grid');

function daysUntil(dateStr){
  if(!dateStr) return Infinity;
  const target = new Date(dateStr + 'T00:00:00');
  const today = new Date(); today.setHours(0,0,0,0);
  return Math.round((target - today) / (1000*60*60*24));
}
function applyFilters(){
  if (!grid) return;
  const q = (searchBox.value || '').toLowerCase().trim();
  const st = (statusFilter.value || '').toLowerCase();
  const ddl = parseInt(dueFilter.value || '0', 10);

  [...grid.querySelectorAll('.card')].forEach(card => {
    const hay = (card.getAttribute('data-title') || '').toLowerCase();
    const cs  = (card.getAttribute('data-status') || '').toLowerCase();
    const due = card.getAttribute('data-due') || '';
    const within = daysUntil(due);

    const matchQ = q === '' || hay.includes(q);
    const matchS = st === '' || cs === st;
    const matchD = ddl === 0 || within <= ddl;

    card.style.display = (matchQ && matchS && matchD) ? '' : 'none';
  });
}
searchBox?.addEventListener('input', applyFilters);
statusFilter?.addEventListener('change', applyFilters);
dueFilter?.addEventListener('change', applyFilters);
</script>
</body>
</html>
