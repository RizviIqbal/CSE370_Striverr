<?php
session_start();
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'client') {
  header("Location: ../auth/login.php"); exit();
}

$client_id     = (int)$_SESSION['user_id'];
$freelancer_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
$project_id    = isset($_GET['project_id']) && is_numeric($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

if ($freelancer_id <= 0) { die("Invalid freelancer ID."); }

/* ---------- Helpers ---------- */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function showOrDefault($v){ return ($v !== null && trim((string)$v) !== '') ? e($v) : '<span class="muted">Not provided</span>'; }
function table_has(mysqli $c, $table){
  $r = $c->query("SHOW TABLES LIKE '". $c->real_escape_string($table) ."'");
  return $r && $r->num_rows > 0;
}
function cols(mysqli $c,$t){
  $h=[]; if($r=$c->query("SHOW COLUMNS FROM `$t`")){ while($row=$r->fetch_assoc()) $h[$row['Field']]=1; $r->close(); }
  return $h;
}

/* ---------- Freelancer core profile ---------- */
$stmt = $conn->prepare("
  SELECT name, email, phone, bio, skills, country, experience_level, COALESCE(NULLIF(profile_image,''),'freelancer.png')
  FROM users WHERE user_id=? AND role='freelancer' LIMIT 1
");
$stmt->bind_param("i", $freelancer_id);
$stmt->execute();
$stmt->bind_result($name, $email, $phone, $bio, $skills, $country, $experience_level, $profile_image);
$found = $stmt->fetch();
$stmt->close();
if (!$found) { die("Freelancer not found."); }

/* ---------- Ratings (optional reviews table) ---------- */
$rating_avg = null; $rating_count = 0;
if (table_has($conn,'reviews')) {
  $R = $conn->prepare("SELECT AVG(rating), COUNT(*) FROM reviews WHERE freelancer_id=?");
  $R->bind_param("i",$freelancer_id);
  $R->execute(); $R->bind_result($avg,$cnt); $R->fetch(); $R->close();
  if ($cnt) { $rating_avg = round((float)$avg, 1); $rating_count = (int)$cnt; }
}

/* ---------- Current project context (optional) ---------- */
$can_hire = false; $already_hired = false; $project_title = ''; $project_status = '';
if ($project_id > 0 && table_has($conn,'projects')) {
  $P = $conn->prepare("SELECT title, status, hired_freelancer_id FROM projects WHERE project_id=? AND client_id=? LIMIT 1");
  $P->bind_param("ii",$project_id,$client_id);
  $P->execute(); $P->bind_result($project_title,$project_status,$hired_id);
  if ($P->fetch()) {
    $already_hired = (int)$hired_id > 0;
    $can_hire = !$already_hired; // simple rule: can hire if no one hired yet
  }
  $P->close();
}

/* ---------- POST: Hire action (only if allowed) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hire' && $project_id > 0 && $can_hire) {
  // defensive: confirm columns exist
  $pc = cols($conn,'projects');
  if (!isset($pc['hired_freelancer_id'])) { die("Projects table must include 'hired_freelancer_id'."); }

  $H = $conn->prepare("UPDATE projects SET hired_freelancer_id=?, status=IFNULL(status,'active') WHERE project_id=? AND client_id=? AND (hired_freelancer_id IS NULL OR hired_freelancer_id=0)");
  $H->bind_param("iii",$freelancer_id,$project_id,$client_id);
  $ok = $H->execute(); $H->close();

  // light feedback via query param
  header("Location: view_freelancer.php?id=".$freelancer_id."&project_id=".$project_id."&notice=".($ok?'hired_ok':'hired_fail'));
  exit();
}

/* ---------- Safe skills tags ---------- */
$skillTags = array_filter(array_map('trim', explode(',', (string)$skills)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Striverr · Freelancer</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --bg:#0b1220; --panel:#0f182a; --panel2:#0e1526; --line:rgba(255,255,255,.10);
  --text:#eaf2ff; --muted:#9fb3c8; --accent:#00ffc3; --accent2:#00bfff; --chip:#183048; --ok:#22c55e;
}
*{box-sizing:border-box}
body{margin:0;background:
  radial-gradient(1000px 600px at 80% -10%, rgba(0,255,195,.12), transparent),
  radial-gradient(700px 500px at 10% 10%, rgba(0,191,255,.10), transparent),
  linear-gradient(180deg,#0a111e, #0b1220);
font-family:Poppins,system-ui,Segoe UI,Arial;color:var(--text)}
.wrap{max-width:1100px;margin:28px auto;padding:0 20px;display:grid;grid-template-columns: 2fr 1fr;gap:18px}
@media (max-width: 980px){ .wrap{grid-template-columns:1fr} }
.card{background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));border:1px solid var(--line);
  border-radius:18px;box-shadow:0 14px 30px rgba(0,0,0,.35);backdrop-filter: blur(10px)}
.section{padding:18px}
.header{display:flex;justify-content:space-between;align-items:center}
.btn{display:inline-flex;gap:10px;align-items:center;padding:10px 14px;border-radius:12px;border:1px solid var(--line);
  background:rgba(255,255,255,.04);color:var(--text);text-decoration:none;font-weight:700}
.btn:hover{transform:translateY(-1px)}
.btn.primary{background:linear-gradient(90deg,var(--accent),var(--accent2));color:#062018;border:none;box-shadow:0 10px 26px rgba(0,191,255,.2)}
.btn.ghost{background:#0e1727}
.badge{padding:6px 10px;border-radius:999px;border:1px solid var(--line);font-weight:700;font-size:12px;background:#0d2134}
.meta{display:flex;gap:12px;flex-wrap:wrap;margin-top:10px}
.meta .chip{padding:6px 10px;border-radius:999px;background:var(--chip);border:1px solid var(--line);font-size:12px;font-weight:700;color:#d6ecff}
.h{display:flex;gap:16px;align-items:center}
.avatar{width:90px;height:90px;border-radius:50%;object-fit:cover;border:2px solid var(--accent)}
.title{font-size:22px;font-weight:800;margin:0}
.muted{color:var(--muted)}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.block{border-top:1px solid var(--line);margin-top:12px;padding-top:12px}
.rating{display:flex;align-items:center;gap:8px}
.star{color:#ffd166}
.notice{margin:0 auto 16px;max-width:1100px;padding:12px 14px;border-radius:12px;border:1px solid var(--line);background:#0e1f2f;color:#bfe1ff}
</style>
</head>
<body>

<?php if(isset($_GET['notice']) && $_GET['notice']==='hired_ok'): ?>
  <div class="notice"><i class="fa fa-check-circle" style="color:var(--ok)"></i> Hired successfully. You can chat from the project page.</div>
<?php elseif(isset($_GET['notice']) && $_GET['notice']==='hired_fail'): ?>
  <div class="notice"><i class="fa fa-triangle-exclamation" style="color:#f59e0b"></i> Couldn’t hire. The project may already have someone hired.</div>
<?php endif; ?>

<div class="wrap">
  <!-- LEFT: Profile -->
  <div class="card">
    <div class="section">
      <div class="header">
        <a class="btn" href="<?= $project_id ? 'view_applicants.php?project_id='.$project_id : 'dashboard.php' ?>"><i class="fa fa-arrow-left"></i> Back</a>
        <span class="badge">Freelancer</span>
      </div>

      <div class="h" style="margin-top:12px">
        <img class="avatar" src="../includes/images/<?= e($profile_image ?: 'freelancer.png') ?>" alt="Profile">
        <div>
          <div class="title"><?= e($name) ?></div>
          <div class="meta" style="margin-top:6px">
            <span class="chip"><i class="fa fa-location-dot"></i>&nbsp;<?= showOrDefault($country) ?></span>
            <span class="chip"><i class="fa fa-id-badge"></i>&nbsp;<?= showOrDefault($experience_level) ?></span>
            <?php if($rating_avg !== null): ?>
              <span class="chip">
                <span class="rating"><i class="fa fa-star star"></i> <?= number_format($rating_avg,1) ?> · <?= (int)$rating_count ?> review<?= $rating_count==1?'':'s' ?></span>
              </span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if (!empty($skillTags)): ?>
        <div class="block">
          <div style="font-weight:700;margin-bottom:6px">Skills</div>
          <div class="meta">
            <?php foreach(array_slice($skillTags,0,20) as $sk): ?>
              <span class="chip"><?= e($sk) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="block">
        <div style="font-weight:700;margin-bottom:6px">Bio</div>
        <div class="muted" style="line-height:1.7"><?= showOrDefault($bio) ?></div>
      </div>
    </div>
  </div>

  <!-- RIGHT: Actions / Contact -->
  <div class="card">
    <div class="section">
      <div style="font-weight:800;font-size:18px;margin-bottom:8px">Contact & Actions</div>

      <div class="grid2">
        <div><div class="muted">Email</div><div style="font-weight:700"><?= showOrDefault($email) ?></div></div>
        <div><div class="muted">Phone</div><div style="font-weight:700"><?= showOrDefault($phone) ?></div></div>
      </div>

      <div class="block" style="display:flex;gap:10px;flex-wrap:wrap">
        <?php if ($project_id): ?>
          <a class="btn ghost" href="../chat/chat.php?project_id=<?= $project_id ?>"><i class="fa fa-comments"></i> Message</a>
        <?php endif; ?>

        <?php if ($project_id && $already_hired): ?>
          <div class="badge" style="background:#0b2d1e;color:#bff8d8;border-color:#2a6c4d"><i class="fa fa-check"></i> Already hired</div>
        <?php endif; ?>
      </div>


      <?php if ($project_id && $project_title): ?>
        <div class="block">
          <div class="muted">Project Context</div>
          <div style="font-weight:700"><?= e($project_title) ?></div>
          <div class="muted" style="margin-top:4px">Status: <?= e($project_status ?: '—') ?></div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
