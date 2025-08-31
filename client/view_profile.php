<?php
session_start();
include('../includes/auth_check.php');
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php");
    exit();
}

$client_id = (int)$_SESSION['user_id'];

/* -------- Profile -------- */
$u = $conn->prepare("
  SELECT 
    name,
    email,
    COALESCE(NULLIF(country,''),'')         AS location,
    COALESCE(NULLIF(bio,''),'')              AS bio,
    COALESCE(NULLIF(profile_image,''),'default.png') AS profile_image
  FROM users
  WHERE user_id = ? AND role = 'client'
  LIMIT 1
");
$u->bind_param("i", $client_id);
$u->execute();
$u->bind_result($name, $email, $location, $bio, $profile_image);
$found = $u->fetch();
$u->close();
if (!$found) { die("Profile not found."); }

/* -------- Stats: counts -------- */
function countByStatus($conn, $client_id, $status) {
  $s = $conn->prepare("SELECT COUNT(*) FROM projects WHERE client_id = ? AND status = ?");
  $s->bind_param("is", $client_id, $status);
  $s->execute(); $s->bind_result($c); $s->fetch(); $s->close();
  return (int)$c;
}
$posted    = countByStatus($conn, $client_id, 'posted');
$active    = countByStatus($conn, $client_id, 'active');
$completed = countByStatus($conn, $client_id, 'submitted'); // adjust if your "done" status differs

/* -------- Stats: aggregates (real fields only) -------- */
$avg_budget = 0.0;
$s = $conn->prepare("SELECT ROUND(AVG(budget),2) FROM projects WHERE client_id = ?");
$s->bind_param("i", $client_id);
$s->execute(); $s->bind_result($avg_budget); $s->fetch(); $s->close();
$avg_budget = $avg_budget ?: 0.0;

$avg_days = 0;
$s = $conn->prepare("
  SELECT ROUND(AVG(DATEDIFF(deadline, created_at))) 
  FROM projects 
  WHERE client_id = ? AND deadline IS NOT NULL AND created_at IS NOT NULL
");
$s->bind_param("i", $client_id);
$s->execute(); $s->bind_result($avg_days); $s->fetch(); $s->close();
$avg_days = (int)($avg_days ?: 0);

/* -------- Top tags across all projects -------- */
$all_tags = '';
$tg = $conn->prepare("SELECT GROUP_CONCAT(tags SEPARATOR ',') FROM projects WHERE client_id = ? AND tags IS NOT NULL AND tags <> ''");
$tg->bind_param("i", $client_id);
$tg->execute(); $tg->bind_result($all_tags); $tg->fetch(); $tg->close();

$top_tags = [];
if ($all_tags) {
  $parts = array_map('trim', explode(',', $all_tags));
  $freq = [];
  foreach ($parts as $p) {
    if ($p === '') continue;
    $key = mb_strtolower($p); // normalize
    $freq[$key] = ($freq[$key] ?? 0) + 1;
  }
  arsort($freq);
  $top_tags = array_slice(array_keys($freq), 0, 8);
}

/* -------- Recent projects -------- */
$recent = [];
$r = $conn->prepare("
  SELECT project_id, title, status, created_at, budget
  FROM projects
  WHERE client_id = ?
  ORDER BY created_at DESC
  LIMIT 5
");
$r->bind_param("i", $client_id);
$r->execute();
$recent = $r->get_result()->fetch_all(MYSQLI_ASSOC);
$r->close();

/* -------- Computed helpers -------- */
$hire_rate = $posted > 0 ? round(($completed / $posted) * 100) : 0;

function showOrDefault($v) {
  $v = trim((string)$v);
  return $v !== '' ? htmlspecialchars($v) : '<span style="opacity:.65">Not provided</span>';
}
function statusColor($s) {
  $s = strtolower($s);
  if ($s === 'active') return '#00ffc3';
  if ($s === 'posted') return '#7dd3ff';
  if ($s === 'submitted' || $s === 'completed') return '#ffd166';
  return '#b0c4de';
}

/* Profile completeness (location + bio + custom avatar) */
$filled = 0; $den = 3;
if ($location !== '') $filled++;
if ($bio !== '') $filled++;
if ($profile_image && $profile_image !== 'default.png') $filled++;
$complete_pct = (int)round(($filled/$den)*100);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Striverr | My Profile</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  :root{
    --bg:#0f1722; --panel:#1b2736; --panel2:#12202e; --ink:#eaf2ff; --muted:#9db1c7;
    --accent:#00bfff; --mint:#00ffc3; --glow:0 10px 30px rgba(0,191,255,.25);
    --chip:#0b1a28; --chipB:#1f4259;
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
  header{display:flex; justify-content:space-between; align-items:center; margin:0 auto 16px; max-width:1100px}
  .brand{font-weight:700; color:#7dd3ff; letter-spacing:.4px; font-size:22px}
  .back{
    display:inline-flex; gap:10px; align-items:center;
    padding:10px 14px; background:#132234; border:1px solid rgba(255,255,255,.06);
    color:#cfeaff; border-radius:12px; text-decoration:none; font-weight:600;
    transition:transform .15s ease, box-shadow .15s ease;
  }
  .back:hover{transform:translateY(-1px); box-shadow:0 10px 24px rgba(0,191,255,.12)}

  .wrap{max-width:1100px; margin:0 auto; display:grid; gap:24px; grid-template-columns: 1fr 1.2fr;}
  @media (max-width: 980px){ .wrap{grid-template-columns:1fr;} }

  .panel{
    background:linear-gradient(180deg, var(--panel), #13202e);
    border:1px solid rgba(255,255,255,.06);
    border-radius:18px; padding:24px;
    box-shadow:var(--glow);
  }

  /* LEFT: Profile Card */
  .profileCard{display:flex; flex-direction:column; align-items:center; text-align:center; gap:10px; position:relative;}
  .avatar{
    width:120px; height:120px; border-radius:50%; object-fit:cover;
    border:3px solid rgba(127, 221, 255, .5); box-shadow:0 8px 24px rgba(0,0,0,.25);
  }
  .copy{
    position:absolute; right:18px; top:18px;
    background:#0f2031; border:1px solid #25435a; color:#bfe9ff; 
    padding:8px 10px; border-radius:10px; cursor:pointer; font-size:12px;
  }
  .copy:hover{ background:#0d1b2a; }
  .roleTag{
    display:inline-block; background:linear-gradient(90deg, var(--mint), var(--accent));
    color:#07121c; font-weight:800; padding:6px 12px; border-radius:999px; font-size:12px; margin-top:4px;
  }
  .infoList{margin-top:14px; width:100%}
  .row{display:flex; gap:10px; align-items:center; padding:10px 12px; border-radius:12px; background:#0e1a27; border:1px solid #1f3346; margin-bottom:10px}
  .row i{color:#7dd3ff; width:18px; text-align:center}
  .row span{font-size:14px}

  .stats{
    margin-top:16px; display:grid; gap:12px; grid-template-columns: repeat(3, 1fr);
  }
  .stat{
    background:#0b1a26; border:1px solid rgba(255,255,255,.06); border-radius:14px;
    padding:16px; text-align:center;
  }
  .stat h3{margin:0; font-size:22px; color:#7dd3ff}
  .stat p{margin:4px 0 0; font-size:12px; color:var(--muted)}

  .meterWrap{margin-top:14px}
  .meterTitle{display:flex; justify-content:space-between; font-size:12px; color:var(--muted); margin-bottom:6px}
  .meter{height:10px; border-radius:999px; background:#0c1a29; border:1px solid #1e3950; position:relative; overflow:hidden}
  .meter > div{
    position:absolute; inset:0; width:0%;
    background:linear-gradient(90deg, var(--mint), var(--accent));
    box-shadow:0 0 20px rgba(0,255,195,.25) inset; transition:width .5s ease;
  }

  /* RIGHT: About + Insights + Recent */
  .title{font-size:22px; font-weight:700; margin-bottom:8px}
  .muted{color:var(--muted); font-size:13px}
  .about{
    background:linear-gradient(180deg, var(--panel2), #0b1a26);
    border:1px solid rgba(255,255,255,.06); border-radius:16px;
    padding:18px; margin-top:12px; line-height:1.7; color:#d7ecff;
  }
  .actions{display:flex; gap:12px; margin:14px 0 4px; flex-wrap:wrap}
  .btn{
    text-decoration:none; text-align:center; padding:12px 14px; border-radius:12px;
    border:1px solid rgba(255,255,255,.08); font-weight:800; letter-spacing:.2px;
    transition:transform .15s ease, box-shadow .15s ease, background .15s ease;
  }
  .btn.primary{ background:linear-gradient(90deg, var(--mint), var(--accent)); color:#07121c; box-shadow:var(--glow); }
  .btn.primary:hover{ transform:translateY(-2px); }
  .btn.ghost{ background:#102133; color:#cfeaff; }
  .btn.ghost:hover{ background:#0f2031; }

  .insights{display:grid; gap:12px; grid-template-columns:repeat(3, 1fr); margin-top:10px}
  .insight{
    background:#0b1a26; border:1px solid rgba(255,255,255,.06); border-radius:14px;
    padding:12px 14px;
  }
  .insight .k{font-size:20px; font-weight:700; color:#7dd3ff}
  .insight .l{font-size:12px; color:var(--muted)}

  .section{margin-top:18px}
  .chips{display:flex; flex-wrap:wrap; gap:8px}
  .chip{background:var(--chip); border:1px solid var(--chipB); color:#bfe9ff; padding:6px 10px; border-radius:14px; font-size:12px}

  .recent{display:grid; gap:10px}
  .item{
    background:#0e1a27; border:1px solid #1f3346; border-radius:12px; padding:12px 14px;
    display:flex; justify-content:space-between; align-items:center; gap:10px;
  }
  .item .left{display:flex; flex-direction:column}
  .badge{
    display:inline-block; padding:5px 10px; border-radius:999px; font-size:11px; font-weight:800;
    border:1px solid rgba(255,255,255,.1);
  }
</style>
</head>
<body>
  <header>
    <div class="brand">Striverr</div>
    <a class="back" href="dashboard.php"><i class="fa fa-arrow-left"></i> Dashboard</a>
  </header>

  <div class="wrap">
    <!-- LEFT: Profile -->
    <section class="panel profileCard">
      <button class="copy" id="copyEmail" title="Copy email"><i class="fa fa-copy"></i> Copy</button>
      <img class="avatar" src="../includes/images/<?= htmlspecialchars($profile_image) ?>" alt="Profile">
      <div style="font-size:20px; font-weight:700;"><?= htmlspecialchars($name) ?></div>
      <div class="roleTag">Client</div>

      <div class="infoList">
        <div class="row"><i class="fa fa-envelope"></i><span><?= htmlspecialchars($email) ?></span></div>
        <div class="row"><i class="fa fa-location-dot"></i><span><?= showOrDefault($location) ?></span></div>
      </div>

      <div class="stats">
        <div class="stat"><h3><?= $posted ?></h3><p>Posted</p></div>
        <div class="stat"><h3><?= $active ?></h3><p>Active</p></div>
        <div class="stat"><h3><?= $completed ?></h3><p>Completed</p></div>
      </div>

      <div class="meterWrap">
        <div class="meterTitle"><span style="color:var(--muted)">Profile completeness</span><span><?= $complete_pct ?>%</span></div>
        <div class="meter"><div style="width: <?= $complete_pct ?>%"></div></div>
      </div>
    </section>

    <!-- RIGHT: About + Insights + Recent -->
    <section class="panel">
      <div class="title">About</div>
      <div class="about">
        <?= $bio !== '' ? nl2br(htmlspecialchars($bio)) : '<span style="opacity:.65">No bio added yet.</span>' ?>
      </div>

      <div class="actions">
        <a class="btn primary" href="post_project.php"><i class="fa fa-plus-circle"></i> Post Project</a>
        <a class="btn ghost" href="my_projects.php"><i class="fa fa-layer-group"></i> View Posted</a>
        <a class="btn ghost" href="active_projects.php"><i class="fa fa-briefcase"></i> Active</a>
        <a class="btn ghost" href="edit_profile.php"><i class="fa fa-user-pen"></i> Edit Profile</a>
      </div>

      <div class="insights">
        <div class="insight">
          <div class="k"><?= $hire_rate ?>%</div>
          <div class="l">Hire Rate</div>
        </div>
        <div class="insight">
          <div class="k">$<?= number_format((float)$avg_budget, 2) ?></div>
          <div class="l">Avg Budget</div>
        </div>
        <div class="insight">
          <div class="k"><?= $avg_days ?>d</div>
          <div class="l">Avg Duration</div>
        </div>
      </div>

      <div class="section">
        <div class="title" style="font-size:18px;">Top Tags</div>
        <div class="chips">
          <?php if (empty($top_tags)): ?>
            <span style="opacity:.65">No tags yet. Add tags when posting projects for better matching.</span>
          <?php else: ?>
            <?php foreach ($top_tags as $t): ?>
              <span class="chip"><?= htmlspecialchars($t) ?></span>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="section">
        <div class="title" style="font-size:18px;">Recent Projects</div>
        <div class="recent">
          <?php if (empty($recent)): ?>
            <div class="muted">No recent projects. Post one to get started.</div>
          <?php else: ?>
            <?php foreach ($recent as $rp): ?>
              <div class="item">
                <div class="left">
                  <strong style="margin-bottom:4px"><?= htmlspecialchars($rp['title']) ?></strong>
                  <span class="muted"><?= date('M j, Y g:i A', strtotime($rp['created_at'])) ?> • $<?= number_format((float)$rp['budget'], 2) ?></span>
                </div>
                <span class="badge" style="color:#07121c;background:<?= statusColor($rp['status']) ?>"><?= strtoupper($rp['status']) ?></span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </div>

<script>
  // Copy email UX
  document.getElementById('copyEmail')?.addEventListener('click', () => {
    const txt = <?= json_encode($email) ?>;
    navigator.clipboard.writeText(txt).then(()=>{
      const btn = document.getElementById('copyEmail');
      const old = btn.innerHTML;
      btn.innerHTML = '<i class="fa fa-check"></i> Copied';
      setTimeout(()=>btn.innerHTML = old, 1200);
    });
  });
</script>
</body>
</html>
