<?php
session_start();
include('../includes/auth_check.php');
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php");
    exit();
}

$client_id = (int)$_SESSION['user_id'];

/* Fetch ACTIVE projects with hired freelancer + milestone progress */
$stmt = $conn->prepare("
  SELECT 
    p.project_id,
    p.title,
    p.description,
    p.budget,
    p.deadline,
    p.status,
    p.hired_freelancer_id,
    u.name       AS freelancer_name,
    COALESCE(NULLIF(u.profile_image,''), 'freelancer.png') AS freelancer_image,
    /* milestones: total + completed */
    COALESCE((SELECT COUNT(*) FROM milestones m WHERE m.project_id = p.project_id),0) AS total_ms,
    COALESCE((SELECT COUNT(*) FROM milestones m 
              WHERE m.project_id = p.project_id 
                AND (m.status IN ('completed','released','paid','done','approved'))),0) AS done_ms
  FROM projects p
  JOIN users u ON u.user_id = p.hired_freelancer_id
  WHERE p.client_id = ? AND p.status = 'active'
  ORDER BY p.created_at DESC
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Striverr | Active Projects</title>
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

  /* Filters */
  .filters{display:flex; gap:12px; flex-wrap:wrap; margin:10px 0 22px;}
  .input, .select{
    background:#0e1a27; border:1px solid #1f3346; color:var(--ink);
    border-radius:12px; padding:12px 14px; outline:none;
  }
  .input{min-width:260px}
  .select{min-width:160px}
  .input:focus, .select:focus{border-color:var(--accent); box-shadow:0 0 0 3px rgba(0,191,255,.15)}

  /* Cards */
  .grid{display:grid; gap:20px; grid-template-columns:repeat(auto-fit, minmax(320px,1fr));}
  .card{
    background:linear-gradient(180deg, var(--panel), #13202e);
    border:1px solid rgba(255,255,255,.06);
    border-radius:16px; padding:18px; position:relative;
    box-shadow:0 8px 20px rgba(0,0,0,.25);
    transition:transform .18s ease, box-shadow .18s ease;
    display:flex; flex-direction:column; gap:14px;
    cursor: pointer; /* whole card clickable */
  }
  .card:hover{ transform:translateY(-4px); box-shadow:0 14px 28px rgba(0,191,255,.22); }

  .top{
    display:flex; align-items:center; gap:12px;
  }
  .avatar{
    width:46px; height:46px; border-radius:50%; object-fit:cover;
    border:2px solid rgba(127, 221, 255, .5);
  }
  .top .who{display:flex; flex-direction:column}
  .who small{color:var(--muted); font-size:12px}

  .chips{display:flex; flex-wrap:wrap; gap:8px}
  .chip{background:var(--chip); border:1px solid var(--chipBorder); color:#bfe9ff; font-size:12px; padding:6px 10px; border-radius:14px}
  .desc{color:#d7ecff; opacity:.9; font-size:13px; line-height:1.5; max-height:72px; overflow:hidden}

  /* Progress */
  .progress{
    background:#0c1a29; border:1px solid #1e3950; border-radius:12px; overflow:hidden;
    position:relative; height:12px;
  }
  .bar{
    position:absolute; left:0; top:0; bottom:0; width:0%;
    background:linear-gradient(90deg, var(--mint), var(--accent));
    box-shadow:0 0 20px rgba(0,255,195,.25) inset;
    transition:width .4s ease;
  }
  .pmeta{display:flex; justify-content:space-between; font-size:12px; color:var(--muted); margin-top:4px}

  .row-actions{display:flex; gap:10px; margin-top:auto}
  .btn{
    flex:1; text-align:center; text-decoration:none; font-weight:700; letter-spacing:.2px;
    padding:10px 12px; border-radius:12px; font-size:14px; border:1px solid rgba(255,255,255,.08);
    transition:transform .15s ease, box-shadow .15s ease, background .15s ease;
    cursor: pointer;
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
</style>
</head>
<body>
  <header>
    <div class="brand">Active Projects</div>
    <a class="back" href="dashboard.php"><i class="fa fa-arrow-left"></i> Dashboard</a>
  </header>

  <!-- Filters -->
  <div class="filters">
    <input class="input" id="searchBox" type="text" placeholder="Search by title or freelancer…">
    <select class="select" id="deadlineFilter">
      <option value="">Any deadline</option>
      <option value="7">Due in ≤ 7 days</option>
      <option value="14">Due in ≤ 14 days</option>
      <option value="30">Due in ≤ 30 days</option>
    </select>
  </div>

  <?php if (empty($rows)): ?>
    <div class="empty">
      <div style="font-size:22px; font-weight:700; margin-bottom:8px;">No active projects yet</div>
      <div style="margin-bottom:16px;">Hire a freelancer from your posted projects to kick things off.</div>
      <a class="btn primary" style="display:inline-block; max-width:220px;" href="my_projects.php">
        <i class="fa fa-layer-group"></i> Go to My Projects
      </a>
    </div>
  <?php else: ?>
    <div class="grid" id="grid">
      <?php foreach ($rows as $p):
        $desc  = trim($p['description'] ?? '');
        $short = mb_substr($desc, 0, 150) . (mb_strlen($desc) > 150 ? '…' : '');
        $total = (int)$p['total_ms'];
        $done  = (int)$p['done_ms'];
        $pct   = $total > 0 ? round(($done / $total) * 100) : 0;
        $deadlineStr = $p['deadline'] ? date('M j, Y', strtotime($p['deadline'])) : 'No deadline';
        $projectUrl = "project_details.php?project_id=" . (int)$p['project_id'];
      ?>
      <div class="card"
           data-title="<?= htmlspecialchars(mb_strtolower($p['title'].' '.$p['freelancer_name'])) ?>"
           data-deadline="<?= htmlspecialchars($p['deadline'] ?: '') ?>"
           data-href="<?= htmlspecialchars($projectUrl) ?>">
        <div class="top">
          <img class="avatar" src="../includes/images/<?= htmlspecialchars($p['freelancer_image']) ?>" alt="">
          <div class="who">
            <strong><?= htmlspecialchars($p['freelancer_name']) ?></strong>
            <small>Hired Freelancer</small>
          </div>
        </div>

        <h3 style="margin:4px 0 0"><?= htmlspecialchars($p['title']) ?></h3>

        <div class="chips">
          <span class="chip"><i class="fa fa-sack-dollar"></i> $<?= number_format((float)$p['budget'], 2) ?></span>
          <span class="chip"><i class="fa fa-calendar"></i> <?= $deadlineStr ?></span>
          <span class="chip"><i class="fa fa-diagram-project"></i> <?= $total ?> milestones</span>
        </div>

        <div class="desc"><?= htmlspecialchars($short) ?></div>

        <div>
          <div class="progress"><div class="bar" style="width: <?= $pct ?>%"></div></div>
          <div class="pmeta">
            <span><?= $done ?>/<?= $total ?> completed</span>
            <span><?= $pct ?>%</span>
          </div>
        </div>

        <div class="row-actions">
          <a class="btn primary js-stop" href="<?= $projectUrl ?>">
            <i class="fa fa-wrench"></i> Manage Project
          </a>
          <a class="btn ghost js-stop" href="../chat/chat.php?user_id=<?= (int)$p['hired_freelancer_id'] ?>&project_id=<?= (int)$p['project_id'] ?>">
            <i class="fa fa-comments"></i> Open Chat
          </a>
          <a class="btn ghost js-stop" href="view_freelancer_profile.php?id=<?= (int)$p['hired_freelancer_id'] ?>">
            <i class="fa fa-user"></i> View Freelancer
          </a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<script>
  // Card click -> project details
  document.querySelectorAll('.card').forEach(card => {
    card.addEventListener('click', (e) => {
      // If a button/link inside was clicked, don't navigate the card
      if (e.target.closest('.js-stop')) return;
      const href = card.getAttribute('data-href');
      if (href) window.location.href = href;
    });
  });

  // Filters: search (title + freelancer), deadline (within N days)
  const searchBox = document.getElementById('searchBox');
  const ddlFilter = document.getElementById('deadlineFilter');
  const grid = document.getElementById('grid');

  function daysUntil(dateStr){
    if(!dateStr) return Infinity;
    const target = new Date(dateStr + 'T00:00:00');
    const today = new Date(); today.setHours(0,0,0,0);
    return Math.round((target - today) / (1000*60*60*24));
  }

  function applyFilters(){
    if (!grid) return;
    const q = (searchBox.value || '').trim().toLowerCase();
    const ddl = parseInt(ddlFilter.value || '0', 10);

    [...grid.querySelectorAll('.card')].forEach(card => {
      const hay = card.getAttribute('data-title') || '';
      const dateStr = card.getAttribute('data-deadline') || '';
      const within = daysUntil(dateStr);

      const matchQ = q === '' || hay.includes(q);
      const matchD = ddl === 0 || within <= ddl;

      card.style.display = (matchQ && matchD) ? '' : 'none';
    });
  }

  searchBox?.addEventListener('input', applyFilters);
  ddlFilter?.addEventListener('change', applyFilters);
</script>
</body>
</html>
