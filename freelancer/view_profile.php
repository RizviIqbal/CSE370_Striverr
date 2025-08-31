<?php
session_start();
include('../includes/db_connect.php');

/* ---------- Resolve which freelancer to show ---------- */
$freelancer_id = null;
if (isset($_GET['id']) && ctype_digit($_GET['id'])) {
  $freelancer_id = (int)$_GET['id'];
} elseif (isset($_SESSION['user_id']) && (($_SESSION['role'] ?? '') === 'freelancer')) {
  $freelancer_id = (int)$_SESSION['user_id'];
}
if (!$freelancer_id) { http_response_code(400); die('Invalid Freelancer ID.'); }

/* ---------- Fetch profile ---------- */
$Q = $conn->prepare("
  SELECT user_id, name, email, phone, bio, skills, country, experience_level,
         COALESCE(NULLIF(profile_image,''),'freelancer.png') AS profile_image
  FROM users
  WHERE user_id = ? AND role='freelancer'
  LIMIT 1
");
$Q->bind_param("i", $freelancer_id);
$Q->execute();
$Q->bind_result($uid, $name, $email, $phone, $bio, $skills, $country, $level, $avatar);
$found = $Q->fetch();
$Q->close();
if (!$found) { die('Freelancer not found.'); }

/* ---------- Stats from projects ---------- */
$active = $completed = 0;
$S = $conn->prepare("SELECT COUNT(*) FROM projects WHERE hired_freelancer_id=? AND status IN('active','in_progress')");
$S->bind_param("i",$freelancer_id); $S->execute(); $S->bind_result($active); $S->fetch(); $S->close();

$S = $conn->prepare("SELECT COUNT(*) FROM projects WHERE hired_freelancer_id=? AND status IN('submitted','completed','done')");
$S->bind_param("i",$freelancer_id); $S->execute(); $S->bind_result($completed); $S->fetch(); $S->close();

/* ---------- Rating (users columns if exist; fallback to reviews) ---------- */
$haveAvg = $conn->query("SHOW COLUMNS FROM users LIKE 'average_rating'")->num_rows > 0;
$haveCnt = $conn->query("SHOW COLUMNS FROM users LIKE 'rating_count'")->num_rows > 0;

$avg_rating = 0.0; $rating_count = 0;
if ($haveAvg && $haveCnt) {
  $R = $conn->prepare("SELECT COALESCE(average_rating,0), COALESCE(rating_count,0) FROM users WHERE user_id=?");
  $R->bind_param("i",$freelancer_id);
  $R->execute(); $R->bind_result($avg_rating,$rating_count); $R->fetch(); $R->close();
} else if ($conn->query("SHOW TABLES LIKE 'reviews'")->num_rows > 0) {
  // detect rating column too (some use 'stars')
  $rc = $conn->query("SHOW COLUMNS FROM reviews LIKE 'rating'")->num_rows ? 'rating' :
        ($conn->query("SHOW COLUMNS FROM reviews LIKE 'stars'")->num_rows ? 'stars' : null);
  if ($rc) {
    $rfk = $conn->query("SHOW COLUMNS FROM reviews LIKE 'freelancer_id'")->num_rows ? 'freelancer_id' :
           ($conn->query("SHOW COLUMNS FROM reviews LIKE 'reviewee_id'")->num_rows ? 'reviewee_id' : null);
    if ($rfk) {
      $R = $conn->prepare("SELECT COALESCE(AVG($rc),0), COUNT(*) FROM reviews WHERE $rfk=?");
      $R->bind_param("i",$freelancer_id);
      $R->execute(); $R->bind_result($avg_rating,$rating_count); $R->fetch(); $R->close();
      $avg_rating = round((float)$avg_rating,2);
    }
  }
}

/* ---------- Recent projects (as hired freelancer) ---------- */
$recent_projects = [];
$PR = $conn->prepare("
  SELECT project_id, title, status, budget, COALESCE(updated_at, created_at) t
  FROM projects
  WHERE hired_freelancer_id=? 
  ORDER BY t DESC
  LIMIT 6
");
$PR->bind_param("i",$freelancer_id);
$PR->execute();
$recent_projects = $PR->get_result()->fetch_all(MYSQLI_ASSOC);
$PR->close();

/* ---------- Recent reviews (schema-aware) ---------- */
$recent_reviews = [];
if ($conn->query("SHOW TABLES LIKE 'reviews'")->num_rows > 0) {
  // collect columns
  $cols = [];
  if ($rc = $conn->query("SHOW COLUMNS FROM reviews")) {
    while ($row = $rc->fetch_assoc()) $cols[] = $row['Field'];
    $rc->close();
  }
  $has = fn($c)=>in_array($c,$cols,true);

  // map likely column names
  $colFreelancer = $has('freelancer_id') ? 'freelancer_id' : ($has('reviewee_id') ? 'reviewee_id' : null);
  $colClient     = $has('client_id') ? 'client_id' : ($has('reviewer_id') ? 'reviewer_id' : null);
  $colRating     = $has('rating') ? 'rating' : ($has('stars') ? 'stars' : null);
  $colComment    = $has('comment') ? 'comment' :
                   ($has('comments') ? 'comments' :
                   ($has('review_text') ? 'review_text' :
                   ($has('feedback') ? 'feedback' : null)));
  $colCreated    = $has('created_at') ? 'created_at' :
                   ($has('review_date') ? 'review_date' :
                   ($has('date') ? 'date' : null));

  if ($colFreelancer && $colRating) {
    $select = "r.`$colRating` AS rating";
    if ($colComment) $select .= ", r.`$colComment` AS comment";
    else $select .= ", '' AS comment";
    if ($colCreated) $select .= ", r.`$colCreated` AS created_at";
    else $select .= ", NOW() AS created_at";
    if ($colClient) $select .= ", u.name AS client_name";
    else $select .= ", 'Client' AS client_name";

    $sql = "SELECT $select FROM reviews r ";
    if ($colClient) $sql .= "LEFT JOIN users u ON u.user_id = r.`$colClient` ";
    $sql .= "WHERE r.`$colFreelancer` = ? ORDER BY ".($colCreated ? "r.`$colCreated`" : "created_at")." DESC LIMIT 6";

    $REV = $conn->prepare($sql);
    $REV->bind_param("i",$freelancer_id);
    $REV->execute();
    $recent_reviews = $REV->get_result()->fetch_all(MYSQLI_ASSOC);
    $REV->close();
  }
}

/* ---------- Helpers ---------- */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function orDash($v){ return trim((string)$v) !== '' ? e($v) : '<span class="muted">Not provided</span>'; }
function starsHtml($avg){
  $avg = (float)$avg;
  $full = floor($avg);
  $half = ($avg - $full) >= 0.5 ? 1 : 0;
  $out = '';
  for ($i=1; $i<=5; $i++){
    if ($i <= $full) $out .= '<i class="fa fa-star"></i>';
    elseif ($half && $i === $full+1) $out .= '<i class="fa fa-star-half-stroke"></i>';
    else $out .= '<i class="fa-regular fa-star"></i>';
  }
  return $out;
}

/* ---------- Viewer context ---------- */
$viewer_id   = (int)($_SESSION['user_id'] ?? 0);
$viewer_role = (string)($_SESSION['role'] ?? '');
$viewing_self = ($viewer_id === $freelancer_id && $viewer_role === 'freelancer');

/* Back link */
$backHref = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : ($viewer_role==='client' ? '../client/dashboard.php' : '../freelancer/dashboard.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Striverr | <?= e($name) ?> — Freelancer Profile</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --bg:#0b1220; --card:#10192b; --text:#eaf2ff; --muted:#9bb0c3; --line:rgba(255,255,255,.08);
  --accent:#00ffc3; --accent2:#00bfff; --ring:#27ffd7;
}
*{box-sizing:border-box}
body{
  margin:0; font-family:Poppins,system-ui,Segoe UI,Arial,sans-serif;
  background:
    radial-gradient(1200px 600px at 80% -10%, rgba(0,255,195,.12), transparent),
    radial-gradient(900px 500px at 10% 10%, rgba(0,191,255,.10), transparent),
    linear-gradient(180deg,#0a111e 0%, #0b1220 100%);
  color:var(--text);
}

/* topbar */
.topbar{position:sticky;top:0;z-index:30;backdrop-filter:blur(8px);
  background:linear-gradient(180deg, rgba(11,18,32,.8), rgba(11,18,32,.4));
  border-bottom:1px solid var(--line); padding:12px 18px; display:flex; align-items:center; justify-content:space-between}
.topbar a{color:var(--text); text-decoration:none; font-weight:700}
.brand{font-weight:900; letter-spacing:.35px; display:flex; gap:8px; align-items:center}
.brand i{color:var(--accent)}
.icon-btn{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;border:1px solid var(--line);
  background:rgba(255,255,255,.04);color:var(--text);cursor:pointer}

/* hero glow */
.hero{position:relative;height:220px;overflow:hidden;border-bottom:1px solid var(--line)}
.blob{position:absolute;filter:blur(60px);opacity:.55;mix-blend:screen;border-radius:50%;animation:float 18s ease-in-out infinite}
.blob.a{left:5%;top:-40px;width:380px;height:380px;background:linear-gradient(135deg,#00ffc3,transparent)}
.blob.b{right:8%;top:-80px;width:460px;height:460px;background:linear-gradient(135deg,#00bfff,transparent);animation-delay:-4s}
@keyframes float{0%,100%{transform:translateY(0) translateX(0)}50%{transform:translateY(18px) translateX(8px)}}

.wrap{max-width:1100px;margin:-90px auto 70px;padding:0 16px}
.grid{display:grid;grid-template-columns:1.4fr .9fr;gap:22px}
@media (max-width: 1024px){.grid{grid-template-columns:1fr}}

.card{background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));
  border:1px solid var(--line); border-radius:18px; box-shadow:0 12px 28px rgba(0,0,0,.35); backdrop-filter:blur(10px)}
.section{padding:18px}
.title{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.title h2{font-size:18px;margin:0}

/* header */
.header{display:flex;gap:16px;align-items:center}
.avatar{width:92px;height:92px;border-radius:50%;object-fit:cover;border:3px solid var(--ring);box-shadow:0 0 0 6px rgba(0,255,195,.08)}
.hname{font-size:28px;font-weight:800;line-height:1.05}
.badge{display:inline-flex;gap:6px;align-items:center;font-size:12px;font-weight:800;padding:6px 10px;border-radius:999px;background:linear-gradient(90deg,var(--accent),var(--accent2));color:#001a1a}
.meta{display:flex;gap:14px;flex-wrap:wrap;color:var(--muted);font-size:14px;margin-top:4px}
.meta i{color:var(--accent)}

/* stars */
.stars{display:flex;gap:2px;color:#ffd166}
.small{color:var(--muted);font-size:12px}

/* buttons */
.btn{display:inline-flex;align-items:center;gap:10px;padding:10px 14px;border-radius:12px;font-weight:800;text-decoration:none;border:1px solid var(--line);color:var(--text);background:rgba(255,255,255,.04);transition:.15s}
.btn:hover{transform:translateY(-1px);border-color:rgba(255,255,255,.25)}
.btn.primary{background:linear-gradient(90deg,var(--accent),var(--accent2));color:#001a1a;border:none}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}

/* sections */
.block{background:rgba(0,0,0,.16);border:1px solid var(--line);border-radius:14px;padding:16px}
.skills{display:flex;gap:8px;flex-wrap:wrap}
.chip{padding:6px 10px;border-radius:999px;background:rgba(0,255,195,.12);border:1px solid rgba(0,255,195,.25);color:#a8fff1;font-weight:700;font-size:12px}
.muted{color:var(--muted)}
.statgrid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.stat{background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.02));border:1px solid var(--line);border-radius:12px;padding:12px;text-align:center}
.stat .num{font-size:26px;font-weight:900;background:linear-gradient(180deg,#fff,#cfe6ff);-webkit-background-clip:text;-webkit-text-fill-color:transparent}

/* lists */
.list{display:grid;gap:10px}
.row{display:grid;grid-template-columns:1fr 130px 120px;gap:10px;align-items:center;padding:12px;border:1px solid var(--line);border-radius:12px;background:rgba(255,255,255,.02)}
.pill{padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.06);border:1px solid var(--line);font-size:12px}
.review{border:1px solid var(--line);border-radius:12px;padding:12px;background:rgba(255,255,255,.02)}
.revtop{display:flex;justify-content:space-between;align-items:center}
.revstars{color:#ffd166}
.copybtn{display:inline-flex;align-items:center;gap:10px;width:100%;justify-content:center;border:1px dashed var(--line);border-radius:12px;padding:10px;color:var(--text);text-decoration:none}
.copybtn:hover{border-color:var(--accent2)}

/* Light mode */
body.light{--bg:#f7fbff; --card:#ffffff; --text:#0f1722; --muted:#5d6f84; --line:rgba(15,23,42,.10)}
body.light .card{box-shadow:0 8px 16px rgba(0,0,0,.08)}
body.light .row{background:#fff}
</style>
</head>
<body>

<div class="topbar">
  <a href="<?= e(!empty($_SERVER['HTTP_REFERER'])?$_SERVER['HTTP_REFERER']:('../'.($viewing_self?'freelancer':'client').'/dashboard.php')) ?>"><i class="fa fa-arrow-left"></i> Back</a>
  <div class="brand"><i class="fa fa-bolt"></i> Striverr</div>
  <button class="icon-btn" id="themeBtn" title="Toggle theme"><i class="fa fa-moon"></i></button>
</div>

<div class="hero">
  <div class="blob a"></div>
  <div class="blob b"></div>
</div>

<div class="wrap">
  <div class="grid">
    <!-- LEFT: profile body -->
    <div class="card">
      <div class="section">
        <div class="header">
          <img class="avatar" src="../includes/images/<?= e($avatar ?: 'freelancer.png') ?>" alt="Avatar">
          <div style="flex:1">
            <div class="hname"><?= e($name) ?></div>

            <div style="display:flex;gap:8px;align-items:center;margin:6px 0 2px">
              <div class="stars" aria-label="Rating"><?= starsHtml($avg_rating) ?></div>
              <div class="small"><?= number_format((float)$avg_rating,2) ?> · <?= (int)$rating_count ?> review<?= ((int)$rating_count===1?'':'s') ?></div>
            </div>

            <div class="meta">
              <span><i class="fa fa-location-dot"></i> <?= orDash($country) ?></span>
              <span><i class="fa fa-certificate"></i> <?= orDash($level) ?></span>
              <span class="badge"><i class="fa fa-user-tie"></i> Freelancer</span>
            </div>

            <div class="actions">
              <?php if ($viewing_self): ?>
                <a class="btn primary" href="edit_profile.php"><i class="fa fa-pen"></i> Edit Profile</a>
              <?php elseif (($viewer_role === 'client') && $viewer_id): ?>
                <a class="btn primary" href="../client/my_projects.php?invite=<?= (int)$freelancer_id ?>"><i class="fa fa-paper-plane"></i> Invite to Project</a>
                <!-- Messaging is per-project in your app; link to a chooser or a specific project if known -->
                <a class="btn" href="../client/my_projects.php"><i class="fa fa-comments"></i> Message</a>
                <a class="btn" id="copyEmail"><i class="fa fa-copy"></i> Copy Email</a>
              <?php else: ?>
                <a class="btn" id="copyEmail"><i class="fa fa-copy"></i> Copy Email</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="section block">
        <div class="title"><h2>About</h2></div>
        <div><?= orDash($bio) ?></div>
      </div>

      <div class="section block">
        <div class="title"><h2>Skills</h2></div>
        <div class="skills">
          <?php
            $chips = array_filter(array_map('trim', explode(',', (string)$skills)));
            if (!$chips) echo '<span class="muted">Not provided</span>';
            foreach ($chips as $c) echo '<span class="chip">'.e($c).'</span>';
          ?>
        </div>
      </div>

      <div class="section block">
        <div class="title"><h2>Recent Projects</h2></div>
        <div class="list">
          <?php if (empty($recent_projects)): ?>
            <div class="muted">No recent projects yet.</div>
          <?php else:
            foreach ($recent_projects as $p):
              $status = strtolower((string)$p['status']);
              $chip = '<span class="pill">'.e($status).'</span>';
              if (in_array($status,['active','in_progress'])) $chip = '<span class="pill" style="background:rgba(34,197,94,.15);border-color:rgba(34,197,94,.35);color:#bfffd2">active</span>';
              if (in_array($status,['submitted','completed','done'])) $chip = '<span class="pill" style="background:rgba(0,191,255,.12);border-color:rgba(0,191,255,.3);color:#cfefff">completed</span>';
          ?>
            <div class="row">
              <div style="min-width:0">
                <div style="font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($p['title']) ?></div>
                <div class="small">#<?= (int)$p['project_id'] ?></div>
              </div>
              <div class="small">$<?= number_format((float)$p['budget'],2) ?></div>
              <div style="text-align:right"><?= $chip ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <?php if (!empty($recent_reviews)): ?>
      <div class="section block">
        <div class="title"><h2>Recent Reviews</h2></div>
        <div class="list">
          <?php foreach ($recent_reviews as $r):
            $rt = (int)$r['rating'];
            $rstars = str_repeat('★', max(0,$rt)) . str_repeat('☆', 5 - max(0,$rt));
            $cmt = trim((string)($r['comment'] ?? ''));
          ?>
            <div class="review">
              <div class="revtop">
                <div class="small" style="font-weight:800"><?= e($r['client_name'] ?? 'Client') ?></div>
                <div class="revstars"><?= e($rstars) ?></div>
              </div>
              <?php if ($cmt !== ''): ?>
                <div style="margin-top:6px"><?= nl2br(e($cmt)) ?></div>
              <?php endif; ?>
              <div class="small" style="margin-top:6px;opacity:.75"><?= e(date('M j, Y · g:i A', strtotime($r['created_at']))) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- RIGHT: sidebar -->
    <div class="card">
      <div class="section">
        <div class="title"><h2>Profile Stats</h2></div>
        <div class="statgrid">
          <div class="stat"><div class="num"><?= (int)$completed ?></div><div class="small">Completed</div></div>
          <div class="stat"><div class="num"><?= (int)$active ?></div><div class="small">Active</div></div>
        </div>
      </div>

      <div class="section">
        <div class="title"><h2>Contact</h2></div>
        <div class="small" style="display:flex;gap:10px;align-items:center;margin-bottom:6px"><i class="fa fa-envelope"></i> <?= orDash($email) ?></div>
        <div class="small" style="display:flex;gap:10px;align-items:center"><i class="fa fa-phone"></i> <?= orDash($phone) ?></div>
        <a class="copybtn" id="copyEmail2" style="margin-top:10px"><i class="fa fa-copy"></i> Copy Email</a>
        <div class="small" style="opacity:.8;margin-top:6px">Tip: thoughtful intros = faster replies.</div>
      </div>
    </div>
  </div>
</div>

<script>
// Theme
const themeBtn=document.getElementById('themeBtn');
themeBtn?.addEventListener('click',()=>{document.body.classList.toggle('light');localStorage.setItem('strTheme.profile',document.body.classList.contains('light')?'light':'dark');});
if(localStorage.getItem('strTheme.profile')==='light')document.body.classList.add('light');

// Copy email buttons
const emailVal = <?= json_encode($email) ?>;
function bindCopy(id){
  const el=document.getElementById(id);
  el?.addEventListener('click', (e)=>{ e.preventDefault(); if(!emailVal) return;
    navigator.clipboard.writeText(emailVal).then(()=>{
      el.innerHTML='<i class="fa fa-check"></i> Copied!';
      setTimeout(()=>el.innerHTML='<i class="fa fa-copy"></i> Copy Email', 1400);
    });
  });
}
bindCopy('copyEmail');
bindCopy('copyEmail2');
</script>
</body>
</html>
