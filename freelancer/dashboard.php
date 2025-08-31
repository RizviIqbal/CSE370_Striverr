<?php
session_start();
include('../includes/auth_check.php');
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'freelancer') {
  header("Location: ../auth/login.php"); exit();
}

$freelancer_id = (int)$_SESSION['user_id'];

/* ----------------- Helpers ----------------- */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function table_has(mysqli $c, $table){ return $c->query("SHOW TABLES LIKE '". $c->real_escape_string($table) ."'")->num_rows > 0; }
function col_has(mysqli $c, $table, $col){ return $c->query("SHOW COLUMNS FROM `$table` LIKE '". $c->real_escape_string($col) ."'")->num_rows > 0; }

/* ----------------- Profile ----------------- */
$freelancer_name = 'Freelancer';
$profile_image   = 'freelancer.png';
$skills          = '';
if ($st = $conn->prepare("SELECT name, COALESCE(NULLIF(profile_image,''),'freelancer.png'), COALESCE(skills,'') FROM users WHERE user_id=? LIMIT 1")){
  $st->bind_param("i",$freelancer_id); $st->execute(); $st->bind_result($freelancer_name,$profile_image,$skills); $st->fetch(); $st->close();
}

/* ----------------- Core counters ----------------- */
// 1) Available (posted) projects
$available = (int)($conn->query("SELECT COUNT(*) c FROM projects WHERE status='posted'")->fetch_assoc()['c'] ?? 0);

// 2) Active projects this freelancer is hired on
$active_projects = 0;
if ($st = $conn->prepare("SELECT COUNT(*) FROM projects WHERE hired_freelancer_id=? AND status IN ('active','in_progress')")){
  $st->bind_param("i",$freelancer_id); $st->execute(); $st->bind_result($active_projects); $st->fetch(); $st->close();
}

// 3) Ongoing milestones (pending/in_review/revision_requested)
$ongoing_ms = 0;
if (table_has($conn,'milestones') && col_has($conn,'milestones','status')){
  if ($st = $conn->prepare("
    SELECT COUNT(*) 
    FROM milestones m 
    JOIN projects p ON p.project_id = m.project_id
    WHERE p.hired_freelancer_id = ? AND m.status IN ('pending','in_review','revision_requested')
  ")){
    $st->bind_param("i",$freelancer_id); $st->execute(); $st->bind_result($ongoing_ms); $st->fetch(); $st->close();
  }
}

/* ----------------- Earnings (payments fixed) ----------------- */
$payments_on = table_has($conn,'payments');
$earned_total = 0.0;      // fully released to freelancer
$pending_escrow = 0.0;    // held/escrow/pending for this freelancer
$pay_currency = 'USD';
$earn_series = [];        // last 6 months released totals [YYYY-MM => amount]

if ($payments_on){
  $hasAmount = col_has($conn,'payments','amount');
  $amtExpr   = $hasAmount ? "amount" : ($hasCents ? "amount" : "0.0");
  // main sums
  $sql = "
    SELECT
      COALESCE(SUM(CASE WHEN status IN ('released') THEN $amtExpr END),0) AS earned,
      COALESCE(SUM(CASE WHEN status IN ('escrowed','pending') THEN $amtExpr END),0) AS pending,
      COALESCE(MAX(currency),'USD') AS cur
    FROM payments
    WHERE freelancer_id = ?
  ";
  if ($st = $conn->prepare($sql)){
    $st->bind_param("i",$freelancer_id);
    $st->execute(); $st->bind_result($earned_total,$pending_escrow,$pay_currency); $st->fetch(); $st->close();
  }

  // last 6 months (released only)
  if (col_has($conn,'payments','created_at')){
    $series = [];
    if ($st = $conn->prepare("
      SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COALESCE(SUM($amtExpr),0) amt
      FROM payments
      WHERE freelancer_id=? AND status='released'
      GROUP BY ym ORDER BY ym DESC LIMIT 6
    ")){
      $st->bind_param("i",$freelancer_id); $st->execute();
      $res = $st->get_result();
      while($r = $res->fetch_assoc()){ $series[$r['ym']] = (float)$r['amt']; }
      $st->close();
    }
    // ensure chronological order (old→new) with zero fills
    $now = new DateTime('first day of this month');
    for ($i=5;$i>=0;$i--){
      $k = (clone $now)->modify("-$i months")->format('Y-m');
      $earn_series[$k] = $series[$k] ?? 0.0;
    }
  }
}

/* ----------------- Recent activity (last 6 milestones) ----------------- */
$recent = [];
if (table_has($conn,'milestones') && col_has($conn,'milestones','project_id')){
  $hasSubmittedAt = col_has($conn,'milestones','submitted_at');
  $orderCol = $hasSubmittedAt ? "m.submitted_at" : "m.milestone_id";
  $sql = "
    SELECT m.title AS mtitle, COALESCE(m.status,'') status, 
           p.title AS ptitle, p.project_id,
           ".($hasSubmittedAt ? "m.submitted_at" : "NULL")." AS submitted_at
    FROM milestones m
    JOIN projects p ON p.project_id = m.project_id
    WHERE p.hired_freelancer_id = {$freelancer_id}
    ORDER BY $orderCol DESC
    LIMIT 6
  ";
  if ($q = $conn->query($sql)) $recent = $q->fetch_all(MYSQLI_ASSOC);
}

/* ----------------- Notifications (latest 10) ----------------- */
$notifications = []; $unread = 0;
if ($st = $conn->prepare("
  SELECT notification_id, message, COALESCE(link,'') link, COALESCE(is_read,0) is_read, COALESCE(created_at,NOW()) created_at
  FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 10
")){
  $st->bind_param("i",$freelancer_id); $st->execute(); $notifications = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
  foreach($notifications as $n){ if(((int)$n['is_read'])===0) $unread++; }
}

/* ----------------- Command palette data ----------------- */
$searchRows = [];
if ($st = $conn->prepare("
  SELECT p.project_id, p.title, COALESCE(p.status,'') status
  FROM projects p
  WHERE p.status IN ('posted','active','in_progress')
    AND (p.hired_freelancer_id = ? OR p.status = 'posted')
  ORDER BY p.created_at DESC
  LIMIT 300
")){
  $st->bind_param("i",$freelancer_id); $st->execute(); $searchRows = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>Striverr · Freelancer Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
<style>
:root{
  --bg:#0b1220; --card:#0f182a; --text:#eaf2ff; --muted:#9fb3c8; --line:rgba(255,255,255,.08);
  --accent:#00ffc3; --accent2:#00bfff; --chip:#18283c; --ok:#22c55e; --warn:#f59e0b;
  --g1:#8b5cf6; --g2:#00d4ff; --g3:#5ef0d6;
}
body{
  margin:0; font-family:Poppins,system-ui,Segoe UI,Arial,sans-serif; color:var(--text);
  background:
    radial-gradient(1200px 600px at 80% -10%, rgba(0,255,195,.12), transparent),
    radial-gradient(900px 500px at 10% 10%, rgba(0,191,255,.10), transparent),
    linear-gradient(180deg,#0a111e 0%, #0b1220 100%);
}
body.light{
  --bg:#f7fbff; --card:#ffffff; --text:#0d1b2a; --muted:#51657b; --line:rgba(0,0,0,.08);
  --accent:#0bd9b7; --accent2:#0aa5ff; --chip:#eef6ff; --ok:#16a34a; --warn:#d97706;
  background:linear-gradient(180deg,#f7fbff,#f3f7fc);
  color:var(--text);
}
*{box-sizing:border-box}
a{color:inherit}

/* Header */
.header{position:sticky;top:0;z-index:30;backdrop-filter: blur(10px);
  background:linear-gradient(180deg, rgba(11,18,32,.78), rgba(11,18,32,.35));
  border-bottom:1px solid var(--line); padding:10px 16px; display:flex; align-items:center; justify-content:space-between}
body.light .header{background:linear-gradient(180deg, rgba(255,255,255,.9), rgba(255,255,255,.65))}
.header-right{display:flex;align-items:center;gap:12px}
.icon-btn{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;border:1px solid var(--line);
  background:rgba(255,255,255,.04);color:var(--text);cursor:pointer;transition:.2s}
body.light .icon-btn{background:#fff}
.icon-btn:hover{transform: translateY(-1px); border-color:rgba(255,255,255,.25)}
.badge{position:absolute;top:-6px;right:-6px;background:#ff4d6d;color:#fff;font-size:11px;font-weight:700;padding:2px 7px;border-radius:999px;border:2px solid var(--bg)}
.avatar{width:42px;height:42px;border-radius:50%;object-fit:cover;border:2px solid var(--accent)}
.logout{color:var(--accent2);font-weight:700;text-decoration:none;padding:8px 12px;border-radius:10px;border:1px solid var(--line)}

/* Brand with animated S */
.brand{display:flex;align-items:center;gap:10px;font-weight:800;letter-spacing:.4px}
.brandName{font-size:18px}
.logoS{width:32px;height:32px;display:block}
#spath{ --len: 1000; stroke-dasharray: var(--len); stroke-dashoffset: var(--len);
  animation: drawS 3.2s ease-in-out infinite, glowS 1.6s ease-in-out infinite; }
@keyframes drawS{ 0%{stroke-dashoffset:var(--len)} 50%{stroke-dashoffset:0} 100%{stroke-dashoffset:var(--len)} }
@keyframes glowS{
  0%,100%{ filter:drop-shadow(0 0 0 rgba(139,92,246,0)) drop-shadow(0 0 0 rgba(0,212,255,0)); stroke-width:3; }
  50%{ filter:drop-shadow(0 0 8px rgba(139,92,246,.55)) drop-shadow(0 0 12px rgba(0,212,255,.45)); stroke-width:3.4; }
}
#svgg stop:first-child{ animation:hueA 6s linear infinite; }
#svgg stop:last-child { animation:hueB 6s linear infinite reverse; }
@keyframes hueA{ 0%{ stop-color:#8b5cf6 } 50%{ stop-color:#6a8bff } 100%{ stop-color:#8b5cf6 } }
@keyframes hueB{ 0%{ stop-color:#5ef0d6 } 50%{ stop-color:#00d4ff } 100%{ stop-color:#5ef0d6 } }

/* Dropdown */
.dropdown{position:absolute;right:12px;top:62px;width:380px;background:var(--card);border:1px solid var(--line);border-radius:14px;
  box-shadow:0 16px 40px rgba(0,0,0,.35);display:none;overflow:hidden}
body.light .dropdown{background:#fff}
.dropdown.show{display:block}
.drop-head{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-bottom:1px solid var(--line);background:rgba(255,255,255,.03)}
.drop-head .mark{border:none;background:transparent;color:var(--accent2);font-weight:700;cursor:pointer}
.drop-list{max-height:360px;overflow:auto}
.notif{display:grid;grid-template-columns:16px 1fr;gap:10px;padding:12px 14px;text-decoration:none;color:var(--text);border-bottom:1px solid var(--line)}
.dot{width:10px;height:10px;border-radius:50%;background:#5c708a;place-self:center}
.notif.unread .dot{background:#22c55e}
.time{color:var(--muted);font-size:12px;margin-top:2px}

/* Hero */
.hero{position:relative;height:600px;overflow:hidden;display:grid;place-items:center;text-align:center;border-bottom:1px solid var(--line)}
.hero video,.hero .poster{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.hero::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg, rgba(0,0,0,.2), rgba(0,0,0,.65))}
.hero-inner{position:relative;z-index:2;padding:0 16px}
.hero h1{font-size:clamp(22px,3.2vw,38px);margin:0 0 6px}
.hero p{color:#cfe0f5;margin:0 0 12px}
.hero-controls{position:absolute;right:14px;bottom:14px;z-index:3;display:flex;gap:8px}
.hbtn{border:1px solid rgba(255,255,255,.35);background:rgba(0,0,0,.35);color:#fff;backdrop-filter:blur(6px);
  padding:8px 12px;border-radius:10px;font-size:13px;cursor:pointer}
body.light .hbtn{background:rgba(255,255,255,.8);color:#0d1b2a}

/* Main */
.wrap{max-width:1200px;margin:26px auto 70px;padding:0 16px}
.grid{display:grid;gap:18px;grid-template-columns:2fr 1fr}
.card{background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));border:1px solid var(--line);
  border-radius:16px;box-shadow:0 12px 26px rgba(0,0,0,.35);backdrop-filter: blur(10px)}
body.light .card{background:#fff}
.section{padding:18px}
.title{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.title h2{font-size:18px;margin:0}

/* Counters */
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.stat{padding:16px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,.02);
  display:flex;flex-direction:column;gap:6px;position:relative;overflow:hidden}
.stat::before{content:"";position:absolute;inset:-30% -30% auto auto;width:120px;height:120px;border-radius:50%;
  background:radial-gradient(closest-side, rgba(0,255,195,.25), transparent);transform:translate(30px,-30px)}
.stat .num{font-size:28px;font-weight:800;color:#fff}
body.light .stat .num{color:#0d1b2a}
.stat .lbl{color:var(--muted);font-weight:600}

/* Actions */
.actions{display:grid;grid-template-columns:repeat(6,1fr);gap:10px}
.action{border:1px dashed var(--line);padding:14px;border-radius:12px;text-decoration:none;color:var(--text);
  display:flex;gap:10px;align-items:center;transition:.2s;background:rgba(255,255,255,.02)}
body.light .action{background:#fff}
.action:hover{transform:translateY(-2px);border-color:rgba(255,255,255,.25)}
.action i{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#001a1a}

/* Recent activity */
.list{display:grid;gap:10px}
.row{display:grid;grid-template-columns:1fr 140px 110px;gap:10px;align-items:center;padding:12px;border:1px solid var(--line);border-radius:12px;background:rgba(231,119,15,.02)}
body.light .row{background:#fff}
.pill{padding:6px 10px;border-radius:999px;background:#fef3c7;color:#0c2a3f;border:1px solid var(--line);font-weight:700;font-size:12px}


/* Earnings card */
.earnTop{display:flex;align-items:center;justify-content:space-between;gap:12px}
.spark{display:grid;grid-auto-flow:column;gap:6px;align-items:end;height:60px}
.bar{width:16px;background:linear-gradient(180deg,var(--g2),var(--g3));border-radius:6px 6px 4px 4px;}

/* Sidebar blocks */
.block+.block{border-top:1px solid var(--line)}
.kbd{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;padding:2px 6px;border-radius:6px;border:1px solid var(--line);color:#d2e8ff}
body.light .kbd{color:#0d1b2a;border-color:#dbe6f3}

/* Command Palette */
.kbar{position:fixed;inset:0;display:none;z-index:50;background:rgba(0,0,0,.55)}
.kbar.show{display:block}
.kbox{position:absolute;left:50%;top:18%;transform:translateX(-50%);width:min(720px,90vw);background:var(--card);border:1px solid var(--line);border-radius:14px;box-shadow:0 20px 50px rgba(0,0,0,.45)}
body.light .kbox{background:#fff}
.ksearch{padding:12px 14px;border-bottom:1px solid var(--line)}
.ksearch input{width:100%;background:rgba(255,255,255,.06);color:var(--text);border:1px solid var(--line);border-radius:10px;padding:10px 12px}
body.light .ksearch input{background:#fff;color:#0d1b2a}
.klist{max-height:50vh;overflow:auto}
.kitem{padding:12px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:10px;cursor:pointer}
.kitem:hover{background:rgba(255,255,255,.04)}
.kempty{padding:18px;color:var(--muted);text-align:center}

@media (max-width:1200px){ .actions{grid-template-columns:repeat(3,1fr)} }
@media (max-width:980px){
  .grid{grid-template-columns:1fr}
  .actions{grid-template-columns:repeat(2,1fr)}
  .row{grid-template-columns:1fr 120px 100px}
}
</style>
</head>
<body>

<!-- Header -->
<div class="header">
  <div class="brand">
    <!-- Animated Striverr S -->
    <svg class="logoS" viewBox="0 0 200 200" aria-hidden="true">
      <defs>
        <linearGradient id="svgg" x1="0%" y1="0%" x2="100%" y2="100%">
          <stop offset="0%" stop-color="#8b5cf6"/>
          <stop offset="50%" stop-color="#00d4ff"/>
          <stop offset="100%" stop-color="#5ef0d6"/>
        </linearGradient>
      </defs>
      <path id="spath" pathLength="1000"
        d="M52 54 Q82 24, 112 50 T170 54 Q140 86, 112 112 T54 170 Q26 140, 56 112 T112 50"
        fill="none" stroke="url(#svgg)" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <div class="brandName">Striverr</div>
  </div>

  <div class="header-right">
    <span class="small" style="opacity:.85">Press <span class="kbd">Ctrl</span>/<span class="kbd">⌘</span> + <span class="kbd">K</span></span>
    <button class="icon-btn" id="themeBtn" title="Toggle theme"><i class="fa fa-circle-half-stroke"></i></button>

    <div style="position:relative">
      <button class="icon-btn" id="bellBtn" title="Notifications" aria-haspopup="true" aria-expanded="false">
        <i class="fa fa-bell"></i>
        <?php if($unread>0): ?><span class="badge" id="notifBadge"><?= $unread ?></span><?php endif; ?>
      </button>
      <div class="dropdown" id="notifDD" role="menu" aria-label="Notifications">
        <div class="drop-head">
          <strong>Notifications</strong>
          <?php if($unread>0): ?><button class="mark" id="markAll">Mark all as read</button><?php endif; ?>
        </div>
        <div class="drop-list">
          <?php if(empty($notifications)): ?>
            <div class="kempty">You’re all caught up 🎉</div>
          <?php else: foreach($notifications as $n): ?>
            <a class="notif <?= (int)$n['is_read']===0?'unread':'' ?>" href="<?= e($n['link'] ?: '#') ?>">
              <div class="dot"></div>
              <div>
                <div><?= e($n['message']) ?></div>
                <div class="time"><?= e(date('M j, Y · g:i A', strtotime($n['created_at']))) ?></div>
              </div>
            </a>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <img class="avatar" src="../includes/images/<?= e($profile_image) ?>" alt="Profile">
    <div class="small" style="text-align:right"><div style="font-weight:800"><?= e($freelancer_name) ?></div><div style="color:#a8ffc9">Freelancer</div></div>
    <a class="logout" href="../auth/logout.php">Logout</a>
  </div>
</div>

<!-- Hero -->
<section class="hero">
  <img class="poster" src="../includes/images/freelancer_bg.png" alt="">
  <video id="heroVideo" autoplay muted loop playsinline preload="auto" poster="../includes/images/freelancer_bg.png">
    <source src="../includes/videos/striverr_hero.webm" type="video/webm">
    <source src="../includes/videos/striverr_hero.mp4" type="video/mp4">
  </video>
  <div class="hero-inner">
    <h1>Build. Ship. Shine. Your freelance HQ is here.</h1>
    <p>Browse premium projects, track milestones, and get paid faster — the Striverr way.</p>
  </div>
  <div class="hero-controls">
    <button class="hbtn" id="playBtn">Pause</button>
  </div>
</section>

<div class="wrap">
  <div class="grid">
    <!-- MAIN -->
    <div class="card">
      <div class="section">
        <div class="title"><h2>Overview</h2></div>
        <div class="stats">
          <div class="stat"><div class="num" data-count="<?= (int)$available ?>">0</div><div class="lbl">Available</div></div>
          <div class="stat"><div class="num" data-count="<?= (int)$active_projects ?>">0</div><div class="lbl">Active</div></div>
          <div class="stat"><div class="num" data-count="<?= (int)$ongoing_ms ?>">0</div><div class="lbl">Ongoing Milestones</div></div>
        </div>
      </div>

      <div class="section">
        <div class="title">
          <h2>Quick Actions</h2>
          <div class="small">Shortcuts: <span class="kbd">B</span> <span class="kbd">A</span> <span class="kbd">M</span> <span class="kbd">V</span> <span class="kbd">E</span></div>
        </div>
        <div class="actions">
          <a class="action" href="browse_projects.php" title="Browse (B)"><i class="fa fa-magnifying-glass"></i><div><div style="font-weight:700">Browse</div><div class="small">Find work that fits</div></div></a>
          <a class="action" href="active_projects.php" title="Active (A)"><i class="fa fa-diagram-project"></i><div><div style="font-weight:700">Active</div><div class="small">Deliverables & chat</div></div></a>
          <a class="action" href="milestones_freelancers.php" title="Milestones (M)"><i class="fa fa-clipboard-list"></i><div><div style="font-weight:700">Milestones</div><div class="small">Submit & track</div></div></a>
          <a class="action" href="view_profile.php?self=1" title="View Profile (V)"><i class="fa fa-user"></i><div><div style="font-weight:700">View Profile</div><div class="small">How clients see you</div></div></a>
          <a class="action" href="edit_profile.php" title="Edit Profile (E)"><i class="fa fa-pen"></i><div><div style="font-weight:700">Edit Profile</div><div class="small">Bio, skills, avatar</div></div></a>
          
        </div>
      </div>

      <div class="section">
        <div class="title">
          <h2>Recent Activity</h2>
          <a class="small" href="active_projects.php" style="text-decoration:none;color:var(--accent2)">Open active →</a>
        </div>
        <div class="list">
          <?php if(empty($recent)): ?>
            <div class="kempty">No milestone updates yet.</div>
          <?php else: foreach($recent as $r):
            $status = strtoupper((string)($r['status'] ?? ''));
            $ts = !empty($r['submitted_at']) ? date('M j, Y · g:i A', strtotime($r['submitted_at'])) : '—';
          ?>
            <div class="row">
              <div>
                <div style="font-weight:700"><?= e($r['ptitle']) ?></div>
                <div class="small"><?= e($r['mtitle']) ?></div>
              </div>
              <div class="small"><?= e($ts) ?></div>
              <div style="text-align:right"><span class="pill"><?= e($status) ?></span></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <!-- SIDEBAR -->
    <div class="card">
      <div class="section block">
        <div class="title"><h2>Earnings</h2></div>
        <div class="earnTop">
          <div>
            <div style="font-size:24px;font-weight:900;color:#98ffdf">
              <?= e($pay_currency) ?> <?= number_format((float)$earned_total, 2) ?>
            </div>
            <div class="small">Released / Paid</div>
            <?php if($payments_on): ?>
              <div class="small" style="margin-top:6px;color:#cfe0f5">
                In Escrow: <strong><?= e($pay_currency) ?> <?= number_format((float)$pending_escrow,2) ?></strong>
              </div>
            <?php endif; ?>
          </div>
          <a class="action" href="../freelancer/wallet.php" style="gap:8px;max-width:48%;justify-content:center">
            <i class="fa fa-wallet"></i><div>Wallet</div>
          </a>
        </div>

        <?php if(!empty($earn_series)): 
          $max = max($earn_series) ?: 1; ?>
          <div class="small" style="margin-top:10px;margin-bottom:6px">Last 6 months (released)</div>
          <div class="spark">
            <?php foreach($earn_series as $ym=>$amt):
              $h = max(6, round(($amt/$max)*60)); ?>
              <div class="bar" title="<?= e($ym.' · '.$pay_currency.' '.number_format($amt,2)) ?>" style="height:<?= $h ?>px"></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="section block">
        <div class="title"><h2>Suggested Tags</h2></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px">
          <?php
            $tagList = array_slice(array_filter(array_map('trim', explode(',', (string)$skills))), 0, 10);
            if (!$tagList) $tagList = ['PHP','JavaScript','MySQL','API','UI/UX','React','Laravel','Bootstrap','WordPress','Figma'];
            foreach ($tagList as $t): ?>
              <a style="border:1px solid var(--line);border-radius:12px;background:rgba(255,255,255,.02);padding:10px;text-align:center;text-decoration:none;color:inherit"
                 href="browse_projects.php?q=<?= urlencode($t) ?>">#<?= e($t) ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="section block">
        <div class="title"><h2>Command Palette</h2><span class="small"><span class="kbd">Ctrl/⌘</span>+<span class="kbd">K</span></span></div>
        <div class="small">Search & jump to projects instantly.</div>
        <button class="action" style="margin-top:10px" id="openK"><i class="fa fa-magnifying-glass"></i><div><div style="font-weight:700">Open</div><div class="small">Find a project</div></div></button>
      </div>
    </div>
  </div>

  <!-- WHY Freelancers Love Striverr -->
  <div class="card" style="margin-top:18px">
    <div class="section">
      <div class="title"><h2>Why Freelancers Love Striverr</h2></div>
      <div class="stats" style="grid-template-columns:repeat(3,1fr);margin-top:6px">
        <div class="stat"><div class="num">Fast</div><div class="lbl">Premium gigs land on your desk</div></div>
        <div class="stat"><div class="num">Safe</div><div class="lbl">Clear milestones & payouts</div></div>
        <div class="stat"><div class="num">Modern</div><div class="lbl">Speedy UX & power tools</div></div>
      </div>
      <div class="actions" style="margin-top:14px;grid-template-columns:repeat(6,1fr)">
        <div class="action" style="cursor:default"><i class="fa fa-shield"></i><div><div style="font-weight:700">Trust & Privacy</div><div class="small">Secure deals & audit trails</div></div></div>
        <div class="action" style="cursor:default"><i class="fa fa-bolt"></i><div><div style="font-weight:700">Speed by Design</div><div class="small">Shortcuts & palette</div></div></div>
        <div class="action" style="cursor:default"><i class="fa fa-diagram-project"></i><div><div style="font-weight:700">Milestone‑first</div><div class="small">Clarity from day zero</div></div></div>
        <div class="action" style="cursor:default"><i class="fa fa-star"></i><div><div style="font-weight:700">Reputation</div><div class="small">Transparent reviews</div></div></div>
        <div class="action" style="cursor:default"><i class="fa fa-mobile-screen"></i><div><div style="font-weight:700">Everywhere</div><div class="small">Great on mobile</div></div></div>
        <div class="action" style="cursor:default"><i class="fa fa-rocket"></i><div><div style="font-weight:700">Grow</div><div class="small">Find better clients</div></div></div>
      </div>
    </div>
  </div>
</div>

<!-- Command Palette -->
<div class="kbar" id="kbar" aria-hidden="true">
  <div class="kbox" role="dialog" aria-modal="true" aria-label="Command palette">
    <div class="ksearch"><input id="kinput" type="text" placeholder="Search projects by title… (active or posted)"></div>
    <div class="klist" id="klist"></div>
  </div>
</div>

<script>
// Theme
const themeBtn=document.getElementById('themeBtn');
themeBtn?.addEventListener('click',()=>{document.body.classList.toggle('light');localStorage.setItem('strThemeF',document.body.classList.contains('light')?'light':'dark');});
if(localStorage.getItem('strThemeF')==='light')document.body.classList.add('light');

// Notifs
const bellBtn=document.getElementById('bellBtn');const dd=document.getElementById('notifDD');const badge=document.getElementById('notifBadge');
bellBtn?.addEventListener('click',e=>{e.stopPropagation();const open=dd.classList.toggle('show');bellBtn.setAttribute('aria-expanded',open?'true':'false');});
document.addEventListener('click',e=>{if(dd && !dd.contains(e.target)&&!bellBtn.contains(e.target))dd.classList.remove('show');});
document.getElementById('markAll')?.addEventListener('click',async()=>{try{const r=await fetch('../client/notifications_mark_all.php',{method:'POST'});const d=await r.json();if(d.success){dd.querySelectorAll('.notif.unread').forEach(n=>n.classList.remove('unread'));badge?.remove();document.getElementById('markAll')?.remove();}}catch(e){}});

// Hero video
const vid=document.getElementById('heroVideo'),playBtn=document.getElementById('playBtn'),muteBtn=document.getElementById('muteBtn');
playBtn?.addEventListener('click',()=>{if(vid.paused){vid.play();playBtn.textContent='Pause';}else{vid.pause();playBtn.textContent='Play';}});
muteBtn?.addEventListener('click',()=>{vid.muted=!vid.muted;muteBtn.textContent=vid.muted?'Unmute':'Mute';});

// Animated counters
document.querySelectorAll('.num[data-count]').forEach(el=>{
  const end=parseInt(el.dataset.count||'0',10);const t0=performance.now();const dur=900;
  const step=t=>{const p=Math.min(1,(t-t0)/dur);el.textContent=Math.round(end*p);if(p<1)requestAnimationFrame(step);};
  requestAnimationFrame(step);
});

// Shortcuts
const go=p=>location.href=p;
document.addEventListener('keydown',e=>{
  if(['INPUT','TEXTAREA'].includes(e.target.tagName))return;
  const k=e.key.toLowerCase();
  if(k==='b')go('browse_projects.php');
  else if(k==='a')go('active_projects.php');
  else if(k==='m')go('milestones_freelancers.php');
  else if(k==='v')go('view_profile.php?self=1');
  else if(k==='e')go('edit_profile.php');
  if((e.ctrlKey||e.metaKey)&&k==='k'){e.preventDefault();openK();}
});

// Command palette
const kbar=document.getElementById('kbar'),kinput=document.getElementById('kinput'),klist=document.getElementById('klist');
const openK=()=>{kbar.classList.add('show');kbar.setAttribute('aria-hidden','false');kinput.value='';renderK('');kinput.focus();};
const closeK=()=>{kbar.classList.remove('show');kbar.setAttribute('aria-hidden','true');};
document.getElementById('openK')?.addEventListener('click',openK);
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&kbar.classList.contains('show'))closeK();});
kbar?.addEventListener('click',e=>{if(e.target===kbar)closeK();});
const items=<?php echo json_encode($searchRows, JSON_UNESCAPED_UNICODE); ?>;
function esc(s){return String(s).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
function renderK(q){
  const term=q.trim().toLowerCase();
  const matched=(items||[]).filter(i=>String(i.title||'').toLowerCase().includes(term));
  if(!matched.length){klist.innerHTML='<div class="kempty">No results.</div>';return;}
  klist.innerHTML=matched.map(i=>`
    <div class="kitem" data-id="${i.project_id}">
      <i class="fa fa-briefcase" style="color:var(--accent2)"></i>
      <div>${esc(i.title)}</div>
      <div style="margin-left:auto" class="small">${esc(i.status || '')}</div>
    </div>`).join('');
  klist.querySelectorAll('.kitem').forEach(el=>el.addEventListener('click',()=>location.href='project_details.php?project_id='+el.dataset.id));
}
kinput?.addEventListener('input',()=>renderK(kinput.value));
</script>
</body>
</html>
