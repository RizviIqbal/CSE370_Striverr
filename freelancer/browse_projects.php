<?php
session_start();
include('../includes/auth_check.php');
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'freelancer') {
  header("Location: ../auth/login.php"); exit();
}

$freelancer_id = (int)$_SESSION['user_id'];

/* =========
   APPLY API
   ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='apply') {
  header('Content-Type: application/json');

  $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
  $bid_amount = isset($_POST['bid_amount']) ? (float)$_POST['bid_amount'] : 0;
  $note       = trim($_POST['note'] ?? '');

  if ($project_id <= 0 || $bid_amount <= 0) {
    echo json_encode(['success'=>false,'message'=>'Invalid input']); exit;
  }

  // Verify project exists & posted
  $vp = $conn->prepare("SELECT project_id FROM projects WHERE project_id=? AND status='posted'");
  $vp->bind_param("i", $project_id);
  $vp->execute();
  $vp_res = $vp->get_result()->fetch_assoc();
  $vp->close();
  if (!$vp_res) {
    echo json_encode(['success'=>false,'message'=>'Project not available']); exit;
  }

  // prevent duplicate application
  $du = $conn->prepare("SELECT 1 FROM applications WHERE project_id=? AND freelancer_id=?");
  $du->bind_param("ii", $project_id, $freelancer_id);
  $du->execute();
  $du_res = $du->get_result()->fetch_assoc();
  $du->close();
  if ($du_res) {
    echo json_encode(['success'=>false,'message'=>'You already applied to this project']); exit;
  }

  // detect columns for applications
  $appCols = [];
  if ($r = $conn->query("SHOW COLUMNS FROM applications")) {
    while ($row = $r->fetch_assoc()) $appCols[] = $row['Field'];
    $r->close();
  }
  $has = fn($c)=>in_array($c, $appCols, true);

  // Build insert safely
  $cols = ['project_id','freelancer_id','status'];
  $vals = ['?','?','"pending"'];
  $types = 'ii'; $bind = [$project_id, $freelancer_id];

  if ($has('bid_amount')) { $cols[]='bid_amount'; $vals[]='?'; $types.='d'; $bind[]=$bid_amount; }
  if ($has('note'))       { $cols[]='note';       $vals[]='?'; $types.='s'; $bind[]=$note; }
  if ($has('cover_letter')){ $cols[]='cover_letter'; $vals[]='?'; $types.='s'; $bind[]=$note; } // reuse note as CL if present
  if ($has('created_at')) { $cols[]='created_at'; $vals[]='NOW()'; }

  $sql = "INSERT INTO applications (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
  $st = $conn->prepare($sql);
  if ($types) $st->bind_param($types, ...$bind);
  $ok = $st->execute();
  $st->close();

  echo json_encode(['success'=>$ok, 'message'=>$ok?'Applied successfully!':'Failed to apply']);
  exit;
}

/* ==========
   FILTERING
   ========== */
$q         = trim($_GET['q'] ?? '');
$tagStr    = trim($_GET['tags'] ?? '');
$minBudget = isset($_GET['min']) && $_GET['min'] !== '' ? (float)$_GET['min'] : null;
$maxBudget = isset($_GET['max']) && $_GET['max'] !== '' ? (float)$_GET['max'] : null;
$sort      = $_GET['sort'] ?? 'new'; // new | budget | deadline
$page      = max(1, (int)($_GET['page'] ?? 1));
$limit     = 12;
$offset    = ($page - 1) * $limit;

$where = ["p.status='posted'"];
$params = [];
$types  = '';

if ($q !== '') {
  $where[] = "(p.title LIKE CONCAT('%',?,'%') OR p.description LIKE CONCAT('%',?,'%'))";
  $types .= 'ss'; $params[]=$q; $params[]=$q;
}

$tags = [];
if ($tagStr !== '') {
  $tags = array_filter(array_map('trim', explode(',', $tagStr)));
  foreach ($tags as $t) {
    $where[] = "FIND_IN_SET(?, p.tags) > 0";
    $types .= 's'; $params[] = $t;
  }
}

if ($minBudget !== null) { $where[] = "p.budget >= ?"; $types.='d'; $params[]=$minBudget; }
if ($maxBudget !== null) { $where[] = "p.budget <= ?"; $types.='d'; $params[]=$maxBudget; }

$order = "p.created_at DESC";
if ($sort === 'budget')   $order = "p.budget DESC";
if ($sort === 'deadline') $order = "p.deadline ASC";

/* Count total */
$count_sql = "SELECT COUNT(*) AS c FROM projects p WHERE ".implode(' AND ', $where);
$cs = $conn->prepare($count_sql);
if ($types) $cs->bind_param($types, ...$params);
$cs->execute();
$total = (int)$cs->get_result()->fetch_assoc()['c'];
$cs->close();

$pages = max(1, (int)ceil($total / $limit));

/* Fetch projects */
$sql = "
  SELECT p.project_id, p.title, p.description, p.budget, p.deadline, p.tags,
         u.name AS client_name,
         COALESCE(NULLIF(u.profile_image,''),'client.png') AS client_image
  FROM projects p
  JOIN users u ON u.user_id = p.client_id
  WHERE ".implode(' AND ', $where)."
  ORDER BY $order
  LIMIT ? OFFSET ?
";
$ts = $types.'ii'; $pr = $params;
$pr[] = $limit; $pr[] = $offset;

$stmt = $conn->prepare($sql);
$stmt->bind_param($ts, ...$pr);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Previously applied set (to disable Apply) */
$applied = [];
$ap = $conn->prepare("SELECT project_id FROM applications WHERE freelancer_id=?");
$ap->bind_param("i", $freelancer_id);
$ap->execute();
$ap_res = $ap->get_result();
while ($r = $ap_res->fetch_assoc()) $applied[(int)$r['project_id']] = true;
$ap->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Striverr | Browse Projects</title>
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
    min-height:100vh;
    padding:28px;
  }
  header{display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;}
  .brand{font-size:26px; font-weight:700; color:#7dd3ff}
  .back{
    display:inline-flex; gap:10px; align-items:center;
    padding:10px 14px; background:#132234; border:1px solid rgba(255,255,255,.06);
    color:#cfeaff; border-radius:12px; text-decoration:none; font-weight:600;
    transition:transform .15s ease, box-shadow .15s ease;
  }
  .back:hover{transform:translateY(-1px); box-shadow:0 10px 24px rgba(0,191,255,.12)}

  /* Filters panel */
  .filters{
    background:linear-gradient(180deg, var(--panel), #13202e);
    border:1px solid rgba(255,255,255,.06); border-radius:16px; padding:16px;
    display:grid; gap:12px; grid-template-columns: 1fr 1fr 1fr auto;
    align-items:end; margin-bottom:18px;
  }
  @media (max-width: 980px){ .filters{grid-template-columns:1fr 1fr; } }
  .field label{display:block; font-size:12px; color:var(--muted); margin-bottom:6px}
  .input, .select{
    width:100%; background:#0e1a27; border:1px solid #1f3346; color:var(--ink);
    border-radius:12px; padding:12px 14px; outline:none;
  }
  .input:focus, .select:focus{border-color:var(--accent); box-shadow:0 0 0 3px rgba(0,191,255,.15)}
  .btn{
    background:linear-gradient(90deg, var(--mint), var(--accent)); color:#07121c; font-weight:800; border:none;
    border-radius:14px; padding:12px 16px; cursor:pointer; letter-spacing:.3px; box-shadow:var(--glow);
  }

  /* Grid */
  .grid{display:grid; gap:18px; grid-template-columns:repeat(auto-fit, minmax(320px,1fr));}
  .card{
    background:linear-gradient(180deg, var(--panel), #13202e);
    border:1px solid rgba(255,255,255,.06); border-radius:16px; padding:18px;
    box-shadow:0 8px 20px rgba(0,0,0,.25); display:flex; flex-direction:column; gap:12px;
    transition:transform .18s ease, box-shadow .18s ease;
  }
  .card:hover{ transform:translateY(-4px); box-shadow:0 14px 28px rgba(0,191,255,.22); }
  .top{display:flex; align-items:center; gap:12px}
  .avatar{
    width:46px; height:46px; border-radius:50%; object-fit:cover; border:2px solid rgba(127,221,255,.5)
  }
  .title{font-weight:700; font-size:18px}
  .desc{color:#d7ecff; opacity:.9; font-size:13px; line-height:1.6; max-height:78px; overflow:hidden}
  .chips{display:flex; flex-wrap:wrap; gap:8px}
  .chip{background:var(--chip); border:1px solid var(--chipBorder); color:#bfe9ff; font-size:12px; padding:6px 10px; border-radius:14px}
  .row-actions{display:flex; gap:10px; margin-top:auto}
  .btnGhost{
    flex:1; text-align:center; text-decoration:none; font-weight:700; letter-spacing:.2px; color:#cfeaff;
    background:#102133; border:1px solid rgba(255,255,255,.08); padding:10px 12px; border-radius:12px;
  }
  .btnPrimary{
    flex:1; text-align:center; text-decoration:none; font-weight:800; letter-spacing:.2px; color:#07121c;
    background:linear-gradient(90deg, var(--mint), var(--accent)); border:1px solid rgba(255,255,255,.08);
    padding:10px 12px; border-radius:12px; box-shadow:var(--glow);
  }
  .disabled{opacity:.6; pointer-events:none}

  .pagination{display:flex; justify-content:center; gap:10px; margin:22px 0 6px}
  .page{
    background:#0e1a27; border:1px solid #1f3346; color:#eaf2ff; padding:8px 12px; border-radius:10px; text-decoration:none;
  }
  .page.active{background:linear-gradient(90deg, var(--mint), var(--accent)); color:#07121c; font-weight:800; border:none}
  .empty{
    background:linear-gradient(180deg, var(--panel2), #0b1a26);
    border:1px dashed #2a4b66; border-radius:16px; padding:40px; text-align:center; color:var(--muted);
  }
</style>
</head>
<body>
  <header>
    <div class="brand">Browse Projects</div>
    <a class="back" href="dashboard.php"><i class="fa fa-arrow-left"></i> Dashboard</a>
  </header>

  <form class="filters" method="GET">
    <div class="field">
      <label>Search</label>
      <input class="input" type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Title or description…">
    </div>
    <div class="field">
      <label>Tags (comma)</label>
      <input class="input" type="text" name="tags" value="<?= htmlspecialchars($tagStr) ?>" placeholder="e.g. PHP, React">
    </div>
    <div class="field" style="display:flex; gap:8px">
      <div style="flex:1">
        <label>Min Budget</label>
        <input class="input" type="number" name="min" value="<?= htmlspecialchars($_GET['min'] ?? '') ?>" min="0">
      </div>
      <div style="flex:1">
        <label>Max Budget</label>
        <input class="input" type="number" name="max" value="<?= htmlspecialchars($_GET['max'] ?? '') ?>" min="0">
      </div>
    </div>
    <div class="field" style="display:flex; gap:8px; align-items:end">
      <div style="flex:1">
        <label>Sort</label>
        <select class="select" name="sort">
          <option value="new" <?= $sort==='new'?'selected':'' ?>>Newest</option>
          <option value="budget" <?= $sort==='budget'?'selected':'' ?>>Budget (High→Low)</option>
          <option value="deadline" <?= $sort==='deadline'?'selected':'' ?>>Deadline (Soonest)</option>
        </select>
      </div>
      <button class="btn" type="submit"><i class="fa fa-filter"></i> Apply</button>
    </div>
  </form>

  <?php if (empty($rows)): ?>
    <div class="empty">
      <div style="font-size:22px; font-weight:700; margin-bottom:8px;">No projects found</div>
      <div>Try widening your filters or changing keywords.</div>
    </div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($rows as $p): 
        $desc  = trim($p['description'] ?? '');
        $short = mb_substr($desc, 0, 220) . (mb_strlen($desc) > 220 ? '…' : '');
        $deadlineStr = $p['deadline'] ? date('M j, Y', strtotime($p['deadline'])) : 'Flexible';
        $tagPills = array_filter(array_map('trim', explode(',', $p['tags'] ?? '')));
        $isApplied = isset($applied[(int)$p['project_id']]);
      ?>
      <div class="card">
        <div class="top">
          <img class="avatar" src="../includes/images/<?= htmlspecialchars($p['client_image']) ?>" alt="">
          <div>
            <div class="title"><?= htmlspecialchars($p['title']) ?></div>
            <div style="color:#9db1c7; font-size:12px">by <?= htmlspecialchars($p['client_name']) ?></div>
          </div>
        </div>

        <div class="chips">
          <span class="chip"><i class="fa fa-sack-dollar"></i> $<?= number_format((float)$p['budget'], 2) ?></span>
          <span class="chip"><i class="fa fa-calendar"></i> <?= $deadlineStr ?></span>
        </div>

        <div class="desc"><?= htmlspecialchars($short) ?></div>

        <?php if (!empty($tagPills)): ?>
          <div class="chips">
            <?php foreach ($tagPills as $tg): ?>
              <span class="chip"><?= htmlspecialchars($tg) ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="row-actions">
          <a class="btnGhost" href="project_details.php?project_id=<?= (int)$p['project_id'] ?>">

            <i class="fa fa-folder-open"></i> View Details
          </a>
          <button 
            class="btnPrimary <?= $isApplied ? 'disabled' : '' ?>" 
            <?= $isApplied ? 'disabled' : '' ?>
            onclick="applyToProject(<?= (int)$p['project_id'] ?>, <?= (float)$p['budget'] ?>)">
            <?= $isApplied ? '<i class="fa fa-check"></i> Applied' : '<i class="fa fa-paper-plane"></i> Apply' ?>
          </button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
      <div class="pagination">
        <?php for ($i=1; $i<=$pages; $i++): 
          $link = htmlspecialchars($_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($_GET,['page'=>$i])));
        ?>
          <a class="page <?= $i===$page?'active':'' ?>" href="<?= $link ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

<script>
async function applyToProject(projectId, suggestedBudget){
  const {value: formValues} = await Swal.fire({
    title: 'Submit your application',
    html:
      '<div style="text-align:left">'+
      '<label style="font-size:12px;color:#9db1c7">Bid Amount (USD)</label>'+
      `<input id="bid_amount" type="number" min="1" value="${Math.max(1, Math.round(suggestedBudget||100))}" class="swal2-input" style="width:100%" />`+
      '<label style="font-size:12px;color:#9db1c7">Short Note (optional)</label>'+
      '<textarea id="note" class="swal2-textarea" placeholder="Add a brief note for the client"></textarea>'+
      '</div>',
    focusConfirm: false,
    showCancelButton: true,
    confirmButtonText: 'Apply',
    confirmButtonColor: '#00ffc3',
    background: '#0f1722', color: '#eaf2ff',
    preConfirm: () => {
      const bid = parseFloat(document.getElementById('bid_amount').value || '0');
      const note = document.getElementById('note').value || '';
      if (!bid || bid <= 0) {
        Swal.showValidationMessage('Please enter a valid bid amount');
        return false;
      }
      return { bid, note };
    }
  });

  if (!formValues) return;
  const params = new URLSearchParams();
  params.append('action','apply');
  params.append('project_id', projectId);
  params.append('bid_amount', formValues.bid);
  params.append('note', formValues.note);

  try {
    const res = await fetch(location.href, {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: params.toString()
    });
    const data = await res.json();
    if (data.success) {
      confetti({particleCount:80, spread:70, origin:{y:.6}});
      Swal.fire({toast:true, position:'top-end', icon:'success', title:data.message, showConfirmButton:false, timer:2200});
      setTimeout(()=>location.reload(), 900);
    } else {
      Swal.fire({icon:'error', title:'Oops', text:data.message, background:'#0f1722', color:'#eaf2ff'});
    }
  } catch (e) {
    Swal.fire({icon:'error', title:'Server error', text:'Please try again later.', background:'#0f1722', color:'#eaf2ff'});
  }
}
</script>
</body>
</html>
