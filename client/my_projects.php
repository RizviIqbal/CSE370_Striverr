<?php
session_start();
include('../includes/auth_check.php');
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php");
    exit();
}

$client_id = (int)$_SESSION['user_id'];

/* Fetch projects with bid counts and hired flag */
$stmt = $conn->prepare("
  SELECT 
    p.project_id,
    p.title,
    p.description,
    p.budget,
    p.deadline,
    p.status,
    p.hired_freelancer_id,
    COALESCE((
      SELECT COUNT(*) FROM applications a 
      WHERE a.project_id = p.project_id
    ), 0) AS bid_count
  FROM projects p
  WHERE p.client_id = ?
  ORDER BY p.created_at DESC
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$result = $stmt->get_result();
$projects = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Quick stats (computed from fetched rows) */
$total = count($projects);
$posted = 0; $active = 0; $completed = 0;
foreach ($projects as $pp) {
  $s = strtolower($pp['status']);
  if ($s === 'posted') $posted++;
  elseif ($s === 'active') $active++;
  elseif ($s === 'submitted' || $s === 'completed') $completed++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Striverr | My Projects</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
    padding:32px;
  }
  header{display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;}
  .brand{font-size:26px; font-weight:700; color:#7dd3ff; letter-spacing:.3px}
  .back{
    display:inline-flex; gap:10px; align-items:center;
    padding:10px 14px; background:#132234; border:1px solid rgba(255,255,255,.06);
    color:#cfeaff; border-radius:12px; text-decoration:none; font-weight:600;
    transition:transform .15s ease, box-shadow .15s ease;
  }
  .back:hover{transform:translateY(-1px); box-shadow:0 10px 24px rgba(0,191,255,.12)}

  /* Quick stats */
  .qstats{
    display:grid; gap:14px; grid-template-columns:repeat(4, minmax(160px, 1fr));
    margin: 6px 0 18px;
  }
  .qcard{
    background:linear-gradient(180deg, var(--panel2), #0b1a26);
    border:1px solid rgba(255,255,255,.06);
    border-radius:14px; padding:16px;
    display:flex; align-items:center; gap:12px;
    box-shadow:var(--glow);
  }
  .qicon{
    width:42px; height:42px; display:grid; place-items:center; border-radius:10px;
    background:#0f2333; color:#7dd3ff; border:1px solid rgba(255,255,255,.06);
  }
  .qtext small{color:var(--muted); display:block; margin-bottom:2px}
  .qtext .num{font-size:20px; font-weight:800}

  .filters{display:flex; gap:12px; flex-wrap:wrap; margin:10px 0 22px;}
  .input, .select{
    background:#0e1a27; border:1px solid #1f3346; color:var(--ink);
    border-radius:12px; padding:12px 14px; outline:none;
  }
  .input{min-width:260px}
  .select{min-width:160px}
  .input:focus, .select:focus{border-color:var(--accent); box-shadow:0 0 0 3px rgba(0,191,255,.15)}

  .grid{display:grid; gap:20px; grid-template-columns:repeat(auto-fit, minmax(290px,1fr));}
  .card{
    background:linear-gradient(180deg, var(--panel), #13202e);
    border:1px solid rgba(255,255,255,.06);
    border-radius:16px; padding:18px; position:relative;
    box-shadow:0 8px 20px rgba(0,0,0,.25);
    transition:transform .18s ease, box-shadow .18s ease;
    display:flex; flex-direction:column; gap:12px;
  }
  .card:hover{ transform:translateY(-4px); box-shadow:0 14px 28px rgba(0,191,255,.22); }
  .status{
    position:absolute; top:14px; right:14px; font-size:12px; font-weight:700;
    padding:6px 10px; border-radius:999px; border:1px solid rgba(255,255,255,.08);
  }
  .status.posted{ color:#7dd3ff; background:#0f2333;}
  .status.active{ color:#00ffc3; background:#0e2a23;}
  .status.submitted, .status.completed{ color:#ffd166; background:#2a1a0e;}
  h3{margin:0 0 2px; font-size:18px}
  .desc{color:#d7ecff; opacity:.9; font-size:13px; line-height:1.5; max-height:66px; overflow:hidden}
  .chips{display:flex; flex-wrap:wrap; gap:8px}
  .chip{background:var(--chip); border:1px solid var(--chipBorder); color:#bfe9ff; font-size:12px; padding:6px 10px; border-radius:14px}
  .row-actions{display:flex; gap:10px; margin-top:auto}
  .btn{
    flex:1; text-align:center; text-decoration:none; font-weight:700; letter-spacing:.2px;
    padding:10px 12px; border-radius:12px; font-size:14px; border:1px solid rgba(255,255,255,.08);
    transition:transform .15s ease, box-shadow .15s ease, background .15s ease;
  }
  .btn.primary{ background:linear-gradient(90deg, var(--mint), var(--accent)); color:#07121c; box-shadow:var(--glow); }
  .btn.primary:hover{ transform:translateY(-2px); }
  .btn.ghost{ background:#102133; color:#cfeaff; }
  .btn.ghost:hover{ background:#0f2031; }
  .empty{
    background:linear-gradient(180deg, var(--panel2), #0b1a26);
    border:1px dashed #2a4b66; border-radius:16px; padding:40px;
    text-align:center; color:var(--muted);
  }

  @media (max-width: 860px){
    .qstats{grid-template-columns:repeat(2, 1fr);}
  }
</style>
</head>
<body>
  <header>
    <div class="brand">My Projects</div>
    <a class="back" href="dashboard.php"><i class="fa fa-arrow-left"></i> Dashboard</a>
  </header>

  <!-- Quick Stats -->
  <div class="qstats">
    <div class="qcard">
      <div class="qicon"><i class="fa fa-layer-group"></i></div>
      <div class="qtext">
        <small>All Projects</small>
        <div class="num" data-count="<?= $total ?>">0</div>
      </div>
    </div>
    <div class="qcard">
      <div class="qicon"><i class="fa fa-bullhorn"></i></div>
      <div class="qtext">
        <small>Posted</small>
        <div class="num" data-count="<?= $posted ?>">0</div>
      </div>
    </div>
    <div class="qcard">
      <div class="qicon"><i class="fa fa-rocket"></i></div>
      <div class="qtext">
        <small>Active</small>
        <div class="num" data-count="<?= $active ?>">0</div>
      </div>
    </div>
    <div class="qcard">
      <div class="qicon"><i class="fa fa-clipboard-check"></i></div>
      <div class="qtext">
        <small>Completed</small>
        <div class="num" data-count="<?= $completed ?>">0</div>
      </div>
    </div>
  </div>

  <!-- Filters -->
  <div class="filters">
    <input class="input" id="searchBox" type="text" placeholder="Search projects…">
    <select class="select" id="statusFilter">
      <option value="">All statuses</option>
      <option value="posted">Posted</option>
      <option value="active">Active</option>
      <option value="submitted">Completed</option>
    </select>
  </div>

  <?php if (empty($projects)): ?>
    <div class="empty">
      <div style="font-size:22px; font-weight:700; margin-bottom:8px;">No projects yet</div>
      <div style="margin-bottom:16px;">Post your first project and start hiring the best talent on Striverr.</div>
      <a class="btn primary" style="display:inline-block; max-width:220px;" href="post_project.php">
        <i class="fa fa-plus-circle"></i> Post Project
      </a>
    </div>
  <?php else: ?>
    <div class="grid" id="grid">
      <?php foreach ($projects as $p): 
        $status = strtolower($p['status']);
        $desc   = trim($p['description'] ?? '');
        $short  = mb_substr($desc, 0, 140) . (mb_strlen($desc) > 140 ? '…' : '');
      ?>
        <div class="card" 
             data-title="<?= htmlspecialchars(mb_strtolower($p['title'])) ?>"
             data-status="<?= htmlspecialchars($status) ?>">
          <span class="status <?= htmlspecialchars($status) ?>"><?= ucfirst($status === 'submitted' ? 'Completed' : $status) ?></span>
          <h3><?= htmlspecialchars($p['title']) ?></h3>
          <div class="chips">
            <span class="chip"><i class="fa fa-sack-dollar"></i> $<?= number_format((float)$p['budget'], 2) ?></span>
            <span class="chip"><i class="fa fa-calendar"></i> <?= $p['deadline'] ? date('M j, Y', strtotime($p['deadline'])) : 'No deadline' ?></span>
            <span class="chip"><i class="fa fa-users"></i> <?= (int)$p['bid_count'] ?> bids</span>
          </div>
          <div class="desc"><?= htmlspecialchars($short) ?></div>

          <div class="row-actions">
            <?php if ($status === 'posted'): ?>
              <a class="btn primary" href="view_applicants.php?project_id=<?= (int)$p['project_id'] ?>">
                <i class="fa fa-eye"></i> View Applicants
              </a>
              <?php if (!empty($p['hired_freelancer_id'])): ?>
                <a class="btn ghost" href="../chat/chat.php?project_id=<?= (int)$p['project_id'] ?>">
                  <i class="fa fa-comments"></i> Chat
                </a>
              <?php endif; ?>

            <?php elseif ($status === 'active'): ?>
              <a class="btn primary" href="../chat/chat.php?project_id=<?= (int)$p['project_id'] ?>">
                <i class="fa fa-comments"></i> Open Chat
              </a>
              <a class="btn ghost" href="active_projects.php">
                <i class="fa fa-rocket"></i> Go to Active
              </a>

            <?php else: /* submitted / completed */ ?>
              <a class="btn ghost" href="active_projects.php">
                <i class="fa fa-clipboard-check"></i> View in Completed
              </a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<script>
  // Animated counters
  const nums = document.querySelectorAll('.qtext .num');
  nums.forEach(n => {
    const end = parseInt(n.dataset.count || '0', 10);
    let cur = 0;
    const step = Math.max(1, Math.round(end / 24));
    const tick = () => {
      cur += step;
      if (cur >= end) { n.textContent = end; return; }
      n.textContent = cur;
      requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  });

  // Client-side search + filter (instant)
  const searchBox = document.getElementById('searchBox');
  const statusFilter = document.getElementById('statusFilter');
  const grid = document.getElementById('grid');

  function applyFilters(){
    if (!grid) return;
    const q = (searchBox.value || '').trim().toLowerCase();
    const s = (statusFilter.value || '').toLowerCase();

    [...grid.querySelectorAll('.card')].forEach(card => {
      const title = card.getAttribute('data-title') || '';
      const status = card.getAttribute('data-status') || '';
      const matchQ = q === '' || title.includes(q);
      const matchS = s === '' || status === s;
      card.style.display = (matchQ && matchS) ? '' : 'none';
    });
  }
  searchBox?.addEventListener('input', applyFilters);
  statusFilter?.addEventListener('change', applyFilters);
</script>
</body>
</html>
