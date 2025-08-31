<?php
session_start();
include('../includes/auth_check.php');
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'client') {
  header("Location: ../auth/login.php"); exit();
}

$client_id = (int)$_SESSION['user_id'];

/* ------------ Helpers ------------ */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function table_has(mysqli $c, $table){ return $c->query("SHOW TABLES LIKE '". $c->real_escape_string($table) ."'")->num_rows > 0; }

/* ------------ Profile ------------ */
$client_name = 'Client';
$profile_image = 'default.png';
if ($st = $conn->prepare("SELECT name, COALESCE(NULLIF(profile_image,''),'default.png') FROM users WHERE user_id = ? LIMIT 1")){
  $st->bind_param("i",$client_id); $st->execute(); $st->bind_result($client_name,$profile_image); $st->fetch(); $st->close();
}

/* ------------ Stats ------------ */
function countByStatus($conn, $client_id, $status){
  if ($st = $conn->prepare("SELECT COUNT(*) FROM projects WHERE client_id=? AND status=?")){
    $st->bind_param("is",$client_id,$status); $st->execute(); $st->bind_result($c); $st->fetch(); $st->close(); return (int)$c;
  }
  return 0;
}
$posted    = countByStatus($conn,$client_id,'posted');
$active    = countByStatus($conn,$client_id,'active') + countByStatus($conn,$client_id,'in_progress');
$completed = countByStatus($conn,$client_id,'submitted');

/* Hire rate */
$client_hired_count = 0; $client_total_projects = 0;
if ($st = $conn->prepare("SELECT COUNT(*) FROM projects WHERE client_id=? AND hired_freelancer_id IS NOT NULL")){$st->bind_param("i",$client_id);$st->execute();$st->bind_result($client_hired_count);$st->fetch();$st->close();}
if ($st = $conn->prepare("SELECT COUNT(*) FROM projects WHERE client_id=?")){$st->bind_param("i",$client_id);$st->execute();$st->bind_result($client_total_projects);$st->fetch();$st->close();}
$client_hire_rate = $client_total_projects ? round($client_hired_count*100/$client_total_projects) : 0;

/* ------------ Wallet (from payments) ------------ */
/* payments: payment_id, project_id, milestone_id, client_id, freelancer_id, currency, status, created_at, released_at, amount */
$escrow_total = 0.0; $paid_total = 0.0; $funded_total = 0.0;
if (table_has($conn,'payments')){
  if ($st = $conn->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN status='escrowed' THEN amount END),0) escrowed,
      COALESCE(SUM(CASE WHEN status='released' THEN amount END),0) paid,
      COALESCE(SUM(CASE WHEN status IN ('escrowed','released') THEN amount END),0) funded
    FROM payments WHERE client_id=?
  ")){
    $st->bind_param("i",$client_id); $st->execute(); $st->bind_result($escrow_total,$paid_total,$funded_total); $st->fetch(); $st->close();
  }
}

/* ------------ Recent projects ------------ */
$recent = [];
if ($st = $conn->prepare("
  SELECT project_id, title, COALESCE(status,'') status, COALESCE(budget,0) budget, deadline,
         COALESCE(tags,'') tags, COALESCE(updated_at, created_at) t
  FROM projects WHERE client_id=? ORDER BY t DESC LIMIT 6
")){
  $st->bind_param("i",$client_id); $st->execute(); $recent = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
}

/* ------------ Notifications ------------ */
$notifications = []; $unread = 0;
if ($st = $conn->prepare("
  SELECT notification_id, message, COALESCE(link,'') link, COALESCE(is_read,0) is_read, COALESCE(created_at, NOW()) created_at
  FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 10
")){
  $st->bind_param("i",$client_id); $st->execute(); $notifications = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
  foreach($notifications as $n){ if(((int)$n['is_read'])===0) $unread++; }
}

/* ------------ Command palette search list ------------ */
$searchRows = [];
if ($st = $conn->prepare("SELECT project_id, title, COALESCE(status,'') status FROM projects WHERE client_id=? ORDER BY created_at DESC LIMIT 200")){
  $st->bind_param("i",$client_id); $st->execute(); $searchRows = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
}

/* ------------ Platform pulse ------------ */
$tot_projects = (int)($conn->query("SELECT COUNT(*) c FROM projects")->fetch_assoc()['c'] ?? 0);
$tot_completed = (int)($conn->query("SELECT COUNT(*) c FROM projects WHERE status='submitted'")->fetch_assoc()['c'] ?? 0);
$tot_freelancers = (int)($conn->query("SELECT COUNT(*) c FROM users WHERE role='freelancer'")->fetch_assoc()['c'] ?? 0);
$tot_clients = (int)($conn->query("SELECT COUNT(*) c FROM users WHERE role='client'")->fetch_assoc()['c'] ?? 0);

$avg_hire_days = 0.0;
if ($q = $conn->query("SELECT AVG(TIMESTAMPDIFF(DAY, created_at, COALESCE(updated_at, created_at))) d FROM projects WHERE hired_freelancer_id IS NOT NULL")){
  $row = $q->fetch_assoc(); $avg_hire_days = (float)($row['d'] ?? 0); $avg_hire_days = $avg_hire_days>0?round($avg_hire_days,1):0.0;
}

/* ------------ Badges ------------ */
$badges = [];
if ($client_total_projects >= 10) $badges[] = ['title'=>'Power Poster','desc'=>'10+ briefs posted','icon'=>'fa-bolt'];
if ($client_hire_rate >= 70)     $badges[] = ['title'=>'Decisive','desc'=>'70%+ hire rate','icon'=>'fa-chess-queen'];
if ($completed >= 5)             $badges[] = ['title'=>'Closer','desc'=>'5+ projects completed','icon'=>'fa-flag-checkered'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Striverr · Client Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --bg:#0b1220; --card:#0f182a; --text:#eaf2ff; --muted:#9fb3c8; --line:rgba(255,255,255,.08);
  --accent:#00ffc3; --accent2:#00bfff; --chip:#18283c; --ok:#22c55e; --warn:#f59e0b;
}
body{
  margin:0; font-family:Poppins,system-ui,Segoe UI,Arial,sans-serif; color:var(--text);
  background:
    radial-gradient(1200px 600px at 80% -10%, rgba(0,255,195,.12), transparent),
    radial-gradient(900px 500px at 10% 10%, rgba(0,191,255,.10), transparent),
    linear-gradient(180deg,#0a111e 0%, #0b1220 100%);
}
body.light{
  --bg:#f6f9ff; --card:#ffffff; --text:#0d1b2a; --muted:#51657b; --line:rgba(0,0,0,.08);
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
.brand{display:flex;align-items:center;gap:10px;font-weight:800;letter-spacing:.4px}
.brandName{font-size:18px}
.header-right{display:flex;align-items:center;gap:12px}
.icon-btn{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;border:1px solid var(--line);
  background:rgba(255,255,255,.04);color:var(--text);cursor:pointer;transition:.2s}
body.light .icon-btn{background:#fff}
.icon-btn:hover{transform: translateY(-1px); border-color:rgba(255,255,255,.25)}
.badge{position:absolute;top:-6px;right:-6px;background:#ff4d6d;color:#fff;font-size:11px;font-weight:700;padding:2px 7px;border-radius:999px;border:2px solid var(--bg)}
.avatar{width:42px;height:42px;border-radius:50%;object-fit:cover;border:2px solid var(--accent)}
.logout{color:var(--accent2);font-weight:700;text-decoration:none;padding:8px 12px;border-radius:10px;border:1px solid var(--line)}

/* Animated Striverr logo */
.logoS{width:32px;height:32px;display:block}
#spath{ --len: 1000; stroke-dasharray: var(--len); stroke-dashoffset: var(--len);
  animation: drawS 3.2s ease-in-out infinite, glowS 1.6s ease-in-out infinite; }
@keyframes drawS{ 0%{stroke-dashoffset:var(--len)} 50%{stroke-dashoffset:0} 100%{stroke-dashoffset:var(--len)} }
@keyframes glowS{
  0%,100%{ filter:drop-shadow(0 0 0 rgba(139,92,246,0)) drop-shadow(0 0 0 rgba(0,212,255,0)); stroke-width:3; }
  50%{ filter:drop-shadow(0 0 8px rgba(139,92,246,.55)) drop-shadow(0 0 12px rgba(0,212,255,.45)); stroke-width:3.4; }
}

/* Hero */
.hero{position:relative;height:600px;overflow:hidden;display:grid;place-items:center;text-align:center;border-bottom:1px solid var(--line)}
.hero video,.hero .poster{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.hero::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg, rgba(0,0,0,.2), rgba(0,0,0,.65))}
.hero-inner{position:relative;z-index:2;padding:0 16px}
.hero h1{font-size:clamp(22px,3.4vw,40px);margin:0 0 6px}
.hero p{color:#cfe0f5;margin:0 0 12px}
.hero-controls{position:absolute;right:14px;bottom:14px;z-index:3;display:flex;gap:8px}
.hbtn{border:1px solid rgba(255,255,255,.35);background:rgba(0,0,0,.35);color:#fff;backdrop-filter:blur(6px);
  padding:8px 12px;border-radius:10px;font-size:13px;cursor:pointer}
body.light .hbtn{background:rgba(255,255,255,.7);color:#0d1b2a}

/* Main */
.wrap{max-width:1200px;margin:26px auto 90px;padding:0 16px}
.grid{display:grid;gap:18px;grid-template-columns:2fr 1fr}
.card{background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));border:1px solid var(--line);
  border-radius:16px;box-shadow:0 12px 26px rgba(0,0,0,.35);backdrop-filter: blur(10px)}
body.light .card{background:#fff}
.section{padding:18px}
.title{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.title h2{font-size:18px;margin:0}

/* Wallet strip */
.wallet-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:8px}
.wtile{padding:14px;border:1px solid var(--line);border-radius:14px;background:rgba(255,255,255,.02)}
.wtile .amt{font-size:22px;font-weight:900;color:#98ffdf}
.wtile .lbl{color:var(--muted);font-size:12px}

/* Wallet actions */
.wallet-actions{display:flex;gap:10px;margin:-2px 0 18px 0;flex-wrap:wrap}
.wallet-btn{display:flex;align-items:center;gap:10px;border:1px solid var(--line);
  background:rgba(255,255,255,.02); padding:10px 12px; border-radius:12px; text-decoration:none; color:var(--text)}
.wallet-btn:hover{transform:translateY(-1px);border-color:rgba(255,255,255,.25)}
.wallet-btn i{width:32px;height:32px;border-radius:10px;display:grid;place-items:center;
  background:linear-gradient(135deg,var(--accent),var(--accent2));color:#001a1a}
.wallet-btn small{display:block;color:var(--muted);line-height:1}

/* Counters */
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.stat{padding:16px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,.02);
  display:flex;flex-direction:column;gap:6px;position:relative;overflow:hidden}
.stat::before{content:"";position:absolute;inset:-30% -30% auto auto;width:120px;height:120px;border-radius:50%;
  background:radial-gradient(closest-side, rgba(0,255,195,.25), transparent);transform:translate(30px,-30px)}
.stat .num{font-size:28px;font-weight:800;color:#fff}
body.light .stat .num{color:#0d1b2a}
.stat .lbl{color:var(--muted);font-weight:600}

/* Hire Rate radial gauge */
.gauge{display:flex;align-items:center;gap:14px;margin-top:14px}
.ring{--val:0; width:64px;height:64px;border-radius:50%;display:grid;place-items:center;
  background:
    conic-gradient(var(--accent) calc(var(--val)*1%), rgba(255,255,255,.08) 0),
    radial-gradient(circle 30px, var(--card) 98%, transparent 100%);
  border:1px solid var(--line)}
.ring span{font-size:14px;font-weight:800}

/* Actions */
.actions{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}
.action{border:1px dashed var(--line);padding:14px;border-radius:12px;text-decoration:none;color:var(--text);
  display:flex;gap:10px;align-items:center;transition:.2s;background:rgba(255,255,255,.02)}
body.light .action{background:#fff}
.action:hover{transform:translateY(-2px);border-color:rgba(255,255,255,.25)}
.action i{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#001a1a}
.action .small{color:var(--muted)}

/* Recent */
.list{display:grid;gap:10px}
.row{display:grid;grid-template-columns:1fr 120px 130px 130px;gap:10px;align-items:center;padding:12px;border:1px solid var(--line);border-radius:12px;background:rgba(255,255,255,.02)}
.row .small, .small{color:var(--muted)}
.row div:first-child{min-width:0}
.row div:first-child > div{overflow-wrap:anywhere}
body.light .row{background:#fff}
.chip{padding:6px 10px;border-radius:999px;background:var(--chip);color:#cfe8ff;border:1px solid var(--line);font-size:12px;font-weight:700}
.chip.ok{background:rgba(34,197,94,.15);border-color:rgba(34,197,94,.35);color:#bbf7d0}
.chip.warn{background:rgba(245,158,11,.15);border-color:rgba(245,158,11,.35);color:#fde68a}
.tags{display:flex;gap:6px;flex-wrap:wrap}

/* Sidebar blocks */
.block+.block{border-top:1px solid var(--line)}
.kbd{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;padding:2px 6px;border-radius:6px;border:1px solid var(--line);color:#d2e8ff}
body.light .kbd{color:#0d1b2a;border-color:#dbe6f3}

/* Platform Pulse */
.pulse{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:10px}
.pulse .p{padding:16px;border:1px solid var(--line);border-radius:14px;background:rgba(255,255,255,.02)}
body.light .pulse .p{background:#fff}
.p .big{font-weight:900;font-size:24px}
.p .sub{color:var(--muted);font-size:12px}

/* Badges */
.badges{display:flex;gap:8px;flex-wrap:wrap}
.bdg{display:flex;gap:8px;align-items:center;border:1px solid var(--line);background:rgba(255,255,255,.03);padding:8px 10px;border-radius:999px}
.bdg i{color:var(--accent)}

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

@media (max-width: 1100px){
  .actions{grid-template-columns: repeat(3,1fr)}
  .wallet-strip{grid-template-columns:1fr}
  .wallet-actions{gap:8px}
}
@media (max-width: 980px){
  .grid{grid-template-columns:1fr}
  .actions{grid-template-columns: repeat(2,1fr)}
  .row{grid-template-columns: 1fr 100px 110px 110px}
  .pulse{grid-template-columns:repeat(2,1fr)}
}
</style>
</head>
<body>

<!-- Header -->
<div class="header">
  <div class="brand" aria-label="Striverr">
    <!-- Animated Striverr SVG logo -->
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
    <button class="icon-btn" id="themeBtn" title="Toggle theme" aria-label="Toggle theme"><i class="fa fa-circle-half-stroke"></i></button>

    <!-- Notifications -->
    <div style="position:relative">
      <button class="icon-btn" id="bellBtn" title="Notifications" aria-haspopup="true" aria-expanded="false" aria-controls="notifDD">
        <i class="fa fa-bell"></i>
        <?php if($unread>0): ?><span class="badge" id="notifBadge"><?= (int)$unread ?></span><?php endif; ?>
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

    <img class="avatar" src="../includes/images/<?= e($profile_image) ?>" alt="Profile avatar">
    <div class="small" style="text-align:right"><div style="font-weight:800"><?= e($client_name) ?></div><div style="color:#a8ffc9">Client</div></div>
    <a class="logout" href="../auth/logout.php">Logout</a>
  </div>
</div>

<!-- Cinematic Hero -->
<section class="hero" aria-label="Intro">
  <img class="poster" src="../includes/images/client_bg.png" alt="">
  <video id="heroVideo" autoplay muted loop playsinline preload="auto" poster="../includes/images/client_bg.png">
    <source src="../includes/videos/striverr_hero.webm" type="video/webm">
    <source src="../includes/videos/striverr_hero.mp4" type="video/mp4">
  </video>
  <div class="hero-inner">
    <h1>Post smarter, hire faster — the Striverr way.</h1>
    <p>Clarity, speed, and trust for every project you run.</p>
  </div>
  <div class="hero-controls">
    <button class="hbtn" id="playBtn" aria-label="Pause video">Pause</button>
  </div>
</section>

<div class="wrap">
  <div class="grid">
    <!-- MAIN -->
    <div class="card">
      <div class="section">
        <div class="title"><h2>Wallet</h2><a class="small" href="wallet.php" style="text-decoration:none;color:var(--accent2)">Open wallet →</a></div>
        <div class="wallet-strip">
          <div class="wtile">
            <div class="amt">$<?= number_format((float)$escrow_total,2) ?></div>
            <div class="lbl">In Escrow (funds reserved for milestones)</div>
          </div>
          <div class="wtile">
            <div class="amt">$<?= number_format((float)$paid_total,2) ?></div>
            <div class="lbl">Paid Out (released to freelancers)</div>
          </div>
          <div class="wtile">
            <div class="amt">$<?= number_format((float)$funded_total,2) ?></div>
            <div class="lbl">Total Funded (escrowed + paid)</div>
          </div>
        </div>

        <!-- Wallet quick actions -->
        <div class="wallet-actions" role="group" aria-label="Wallet quick actions">
          <a class="wallet-btn" href="wallet.php?action=add" title="Add funds to your wallet">
            <i class="fa fa-circle-plus"></i>
            <div><strong>Add Funds</strong><small>Instantly fund your next project</small></div>
          </a>
          <a class="wallet-btn" href="wallet.php?action=history" title="See transactions & escrow">
            <i class="fa fa-clock-rotate-left"></i>
            <div><strong>History</strong><small>All payments & refunds</small></div>
          </a>
          <a class="wallet-btn" href="my_projects.php" title="Assign funds to milestones">
            <i class="fa fa-diagram-project"></i>
            <div><strong>Assign Escrow</strong><small>Fund milestones safely</small></div>
          </a>
        </div>
      </div>

      <div class="section">
        <div class="title"><h2>Overview</h2></div>
        <div class="stats">
          <div class="stat"><div class="num" data-count="<?= (int)$posted ?>">0</div><div class="lbl">Posted</div></div>
          <div class="stat"><div class="num" data-count="<?= (int)$active ?>">0</div><div class="lbl">Active</div></div>
          <div class="stat"><div class="num" data-count="<?= (int)$completed ?>">0</div><div class="lbl">Completed</div></div>
        </div>

        <!-- Hire rate gauge -->
        <div class="gauge">
          <div class="ring" id="ring" style="--val:<?= max(0,min(100,$client_hire_rate)) ?>;">
            <span><?= (int)$client_hire_rate ?>%</span>
          </div>
          <div class="small">Your hire rate shows decisiveness. Clear briefs → faster matches.</div>
        </div>

        <?php if(!empty($badges)): ?>
          <div class="badges" style="margin-top:12px">
            <?php foreach($badges as $b): ?>
              <div class="bdg"><i class="fa <?= e($b['icon']) ?>"></i> <strong><?= e($b['title']) ?></strong> <span class="small">· <?= e($b['desc']) ?></span></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="section">
        <div class="title">
          <h2>Quick Actions</h2>
          <div class="small">Shortcuts: <span class="kbd">P</span> <span class="kbd">M</span> <span class="kbd">A</span> <span class="kbd">V</span> <span class="kbd">E</span></div>
        </div>
        <div class="actions">
          <a class="action" href="post_project.php" title="Post a new project"><i class="fa fa-plus"></i><div><div style="font-weight:700">Post Project</div><div class="small">Create a clear brief in minutes</div></div></a>
          <a class="action" href="my_projects.php" title="Manage your posted projects"><i class="fa fa-list"></i><div><div style="font-weight:700">My Projects</div><div class="small">Edit scope, budgets & tags</div></div></a>
          <a class="action" href="active_projects.php" title="Track ongoing work"><i class="fa fa-bolt"></i><div><div style="font-weight:700">Active</div><div class="small">Milestones, chat & files</div></div></a>
          <a class="action" href="view_profile.php" title="See your public profile"><i class="fa fa-user"></i><div><div style="font-weight:700">View Profile</div><div class="small">How freelancers see you</div></div></a>
          <a class="action" href="edit_profile.php" title="Update your profile"><i class="fa fa-pen"></i><div><div style="font-weight:700">Edit Profile</div><div class="small">Details, company, avatar</div></div></a>
        </div>
      </div>

      <div class="section">
        <div class="title">
          <h2>Recent Projects</h2>
          <a class="small" href="my_projects.php" style="text-decoration:none;color:var(--accent2)">View all →</a>
        </div>
        <div class="list">
          <?php if(empty($recent)): ?>
            <div class="kempty">No projects yet. <a href="post_project.php" style="color:var(--accent2);text-decoration:none">Post your first →</a></div>
          <?php else: foreach($recent as $p):
            $chip = '<span class="chip">'.e($p['status']).'</span>';
            if($p['status']==='active' || $p['status']==='in_progress')  $chip = '<span class="chip ok">'.e($p['status']).'</span>';
            if($p['status']==='posted')  $chip = '<span class="chip warn">posted</span>';
          ?>
            <div class="row">
              <div>
                <div style="font-weight:700"><?= e($p['title']) ?></div>
                <div class="tags small">
                  <?php foreach(array_slice(array_filter(array_map('trim', explode(',', (string)$p['tags']))),0,5) as $tg): ?>
                    <span class="chip"><?= e($tg) ?></span>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="small">$<?= number_format((float)$p['budget'],2) ?></div>
              <div class="small"><?= !empty($p['deadline']) ? e(date('M j, Y', strtotime($p['deadline']))) : '—' ?></div>
              <div style="text-align:right"><?= $chip ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <!-- SIDEBAR -->
    <div class="card">
      <div class="section block">
        <div class="title"><h2>Platform Pulse</h2></div>
        <div class="pulse">
          <div class="p"><div class="big"><?= number_format($tot_projects) ?></div><div class="sub">Total Projects</div></div>
          <div class="p"><div class="big"><?= number_format($tot_completed) ?></div><div class="sub">Completed</div></div>
          <div class="p"><div class="big"><?= number_format($tot_freelancers) ?></div><div class="sub">Freelancers</div></div>
          <div class="p"><div class="big"><?= $avg_hire_days ?>d</div><div class="sub">Avg Time to Hire</div></div>
        </div>
        <div class="small" style="margin-top:8px;opacity:.85">Your hire rate: <strong><?= $client_hire_rate ?>%</strong></div>
      </div>

      <div class="section block">
        <div class="title"><h2>Search</h2><span class="small"><span class="kbd">Ctrl/⌘</span>+<span class="kbd">K</span></span></div>
        <div class="small">Find a project instantly.</div>
        <button class="action" style="margin-top:10px" id="openK"><i class="fa fa-magnifying-glass"></i><div><div style="font-weight:700">Open Command Palette</div><div class="small">Search or jump</div></div></button>
      </div>

      <div class="section block">
        <div class="title"><h2>Voices from Striverr</h2></div>
        <div class="pulse" style="grid-template-columns:1fr">
          <div class="p">
            <div>“We went from idea to shipped MVP in 9 days. Striverr freelancers understood the brief instantly.”</div>
            <div class="small" style="margin-top:6px">— Rizvi, Fintech Founder ★★★★★</div>
          </div>
          <div class="p">
            <div>“Milestones + payments kept everything clean. Best experience I’ve had hiring freelancers.”</div>
            <div class="small" style="margin-top:6px">— Sumit, E‑commerce ★★★★☆</div>
          </div>
          <div class="p">
            <div>“The command palette and project tools are addictive. It just feels… fast.”</div>
            <div class="small" style="margin-top:6px">— Pritom, Agency Owner ★★★★★</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- WHY STRIVERR -->
  <div class="card" style="margin-top:18px">
    <div class="section">
      <div class="title"><h2>Why choose Striverr?</h2></div>
      <div class="pulse">
        <div class="p">
          <div class="big"><?= number_format($tot_projects) ?>+</div>
          <div class="sub">Projects shipped with clarity</div>
        </div>
        <div class="p">
          <div class="big"><?= max(95, min(99, $client_hire_rate + 10)) ?>%</div>
          <div class="sub">Clients report faster hiring</div>
        </div>
        <div class="p">
          <div class="big"><?= number_format($tot_freelancers) ?></div>
          <div class="sub">Verified freelancers</div>
        </div>
        <div class="p">
          <div class="big"><?= $avg_hire_days ?> days</div>
          <div class="sub">Average time to hire</div>
        </div>
      </div>

      <div class="actions" style="margin-top:14px">
        <div class="action" style="cursor:default"><i class="fa fa-shield"></i><div><div style="font-weight:700">Trust & Privacy</div><div class="small">Secure payments, clear review trails</div></div></div>
        <div class="action" style="cursor:default"><i class="fa fa-diagram-project"></i><div><div style="font-weight:700">Milestone‑First</div><div class="small">Break down scope to reduce risk</div></div></div>
        <div class="action" style="cursor:default"><i class="fa fa-rocket"></i><div><div style="font-weight:700">Speed by Design</div><div class="small">Shortcuts, palette, fast actions</div></div></div>
        <div class="action" style="cursor:default"><i class="fa fa-star"></i><div><div style="font-weight:700">Transparent Reviews</div><div class="small">Signal quality on both sides</div></div></div>
        <div class="action" style="cursor:default"><i class="fa fa-mobile-screen"></i><div><div style="font-weight:700">Everywhere</div><div class="small">Delightful on desktop & mobile</div></div></div>
      </div>
    </div>
  </div>
</div>

<!-- Command Palette -->
<div class="kbar" id="kbar" aria-hidden="true">
  <div class="kbox" role="dialog" aria-modal="true" aria-label="Command palette">
    <div class="ksearch"><input id="kinput" type="text" placeholder="Search your projects by title..."></div>
    <div class="klist" id="klist"></div>
  </div>
</div>

<script>
// Theme
const themeBtn=document.getElementById('themeBtn');
themeBtn?.addEventListener('click',()=>{
  document.body.classList.toggle('light');
  localStorage.setItem('strTheme',document.body.classList.contains('light')?'light':'dark');
});
if(localStorage.getItem('strTheme')==='light') document.body.classList.add('light');

// Notifs
const bellBtn=document.getElementById('bellBtn'),dd=document.getElementById('notifDD'),badge=document.getElementById('notifBadge');
bellBtn?.addEventListener('click',e=>{e.stopPropagation();const open=dd.classList.toggle('show');bellBtn.setAttribute('aria-expanded',open?'true':'false');});
document.addEventListener('click',e=>{if(dd && !dd.contains(e.target) && !bellBtn.contains(e.target)) dd.classList.remove('show');});
document.getElementById('markAll')?.addEventListener('click',async()=>{
  try{
    const r=await fetch('notifications_mark_all.php',{method:'POST'});
    const d=await r.json();
    if(d.success){
      dd.querySelectorAll('.notif.unread').forEach(n=>n.classList.remove('unread'));
      badge?.remove();
      document.getElementById('markAll')?.remove();
    }
  }catch(e){}
});

// Hero video
const vid=document.getElementById('heroVideo'),playBtn=document.getElementById('playBtn'),muteBtn=document.getElementById('muteBtn');
playBtn?.addEventListener('click',()=>{if(vid.paused){vid.play();playBtn.textContent='Pause';}else{vid.pause();playBtn.textContent='Play';}});
muteBtn?.addEventListener('click',()=>{vid.muted=!vid.muted;muteBtn.textContent=vid.muted?'Unmute':'Mute';});

// Counters
document.querySelectorAll('.num[data-count]').forEach(el=>{
  const end=parseInt(el.dataset.count||'0',10);const t0=performance.now();const dur=900;
  const step=t=>{const p=Math.min(1,(t-t0)/dur);el.textContent=Math.round(end*p);if(p<1)requestAnimationFrame(step);};
  requestAnimationFrame(step);
});

// Gauge animate
(function(){
  const ring=document.getElementById('ring');
  if(!ring) return;
  const target=parseFloat(getComputedStyle(ring).getPropertyValue('--val'))||0;
  let v=0;
  const anim=()=>{ v+= (target - v) * 0.12; if(Math.abs(target-v)<0.1) v=target;
    ring.style.setProperty('--val', v);
    if(v!==target) requestAnimationFrame(anim);
  };
  anim();
})();

// Shortcuts
const go=p=>location.href=p;
document.addEventListener('keydown',e=>{
  if(['INPUT','TEXTAREA'].includes(e.target.tagName)) return;
  const k=e.key.toLowerCase();
  if(k==='p') go('post_project.php');
  else if(k==='m') go('my_projects.php');
  else if(k==='a') go('active_projects.php');
  else if(k==='v') go('view_profile.php');
  else if(k==='e') go('edit_profile.php');
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
