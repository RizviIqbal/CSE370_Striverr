<?php
session_start();
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'freelancer') {
  header("Location: ../auth/login.php"); exit();
}

$user_id = (int)$_SESSION['user_id'];

/* Pull profile */
$stmt = $conn->prepare("
  SELECT name, email, phone, bio, skills, country, experience_level,
         COALESCE(NULLIF(profile_image,''),'freelancer.png') AS profile_image
  FROM users WHERE user_id = ? LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($name, $email, $phone, $bio, $skills, $country, $experience, $profile_image);
$stmt->fetch();
$stmt->close();

/* Rating: use users columns if present, otherwise compute from reviews */
$haveAvg = $conn->query("SHOW COLUMNS FROM users LIKE 'average_rating'")->num_rows > 0;
$haveCnt = $conn->query("SHOW COLUMNS FROM users LIKE 'rating_count'")->num_rows > 0;

$avg_rating = 0.0;
$rating_count = 0;

if ($haveAvg && $haveCnt) {
  $q = $conn->prepare("SELECT COALESCE(average_rating,0), COALESCE(rating_count,0) FROM users WHERE user_id=?");
  $q->bind_param("i",$user_id);
  $q->execute();
  $q->bind_result($avg_rating, $rating_count);
  $q->fetch();
  $q->close();
} else {
  if ($conn->query("SHOW TABLES LIKE 'reviews'")->num_rows > 0) {
    $r = $conn->prepare("SELECT COALESCE(AVG(rating),0), COUNT(*) FROM reviews WHERE freelancer_id=?");
    $r->bind_param("i",$user_id);
    $r->execute();
    $r->bind_result($avg_rating, $rating_count);
    $r->fetch();
    $r->close();
    $avg_rating = round((float)$avg_rating, 2);
  }
}

/* helper */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* Flash */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Striverr · Edit Profile (Freelancer)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{
  --bg:#0b1220; --card:#0f182a; --text:#eaf2ff; --muted:#9fb3c8; --line:rgba(255,255,255,.08);
  --accent:#00ffc3; --accent2:#00bfff; --ok:#22c55e; --warn:#f59e0b;
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
.header{position:sticky;top:0;z-index:20;backdrop-filter: blur(10px);
  background:linear-gradient(180deg, rgba(11,18,32,.7), rgba(11,18,32,.35));
  border-bottom:1px solid var(--line); padding:12px 18px; display:flex; justify-content:space-between; align-items:center}
.brand{font-weight:900; letter-spacing:.4px; display:flex; gap:10px; align-items:center}
.brand i{color:var(--accent)}
.icon-btn{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;border:1px solid var(--line);
  background:rgba(255,255,255,.04);color:var(--text);cursor:pointer;}
.wrap{max-width:1000px; margin:28px auto 60px; padding:0 16px}
.grid{display:grid; gap:18px; grid-template-columns: 3fr 2fr}
@media (max-width: 980px){ .grid{grid-template-columns:1fr} }
.card{background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));border:1px solid var(--line);
  border-radius:16px; box-shadow:0 12px 26px rgba(0,0,0,.35); backdrop-filter: blur(10px)}
.section{padding:18px}
.title{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.title h2{font-size:18px;margin:0}

label{display:block;color:#cfe0f5;font-size:13px;margin-bottom:6px}
.input, textarea, .chips{
  width:100%; background:#0b1a26; border:1px solid #1f3346; color:var(--text);
  border-radius:12px; padding:12px 14px; outline:none; transition:.15s;
}
.input:focus, textarea:focus{border-color:var(--accent2); box-shadow:0 0 0 3px rgba(0,191,255,.15)}
.row2{display:grid; gap:12px; grid-template-columns:1fr 1fr}
.row3{display:grid; gap:12px; grid-template-columns:1fr 1fr 1fr}
textarea{min-height:120px; resize:vertical}

.chips{display:flex;gap:8px;flex-wrap:wrap;min-height:48px;align-items:center}
.chips input{flex:1;min-width:140px;background:transparent;border:none;outline:none;color:var(--text)}
.chip{display:inline-flex;align-items:center;gap:8px;padding:8px 10px;border-radius:999px;border:1px solid var(--line);background:#0d2131}
.chip button{border:none;background:transparent;color:#9bdcff;cursor:pointer}

.pill{padding:6px 10px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.03)}
.avatarPreview{display:flex; gap:14px; align-items:center}
.avatarPreview img{width:82px;height:82px;border-radius:50%;object-fit:cover;border:2px solid rgba(127,221,255,.5)}
.stars{display:flex;gap:2px; font-size:18px;color:#ffd166}
.small{color:#9fb3c8; font-size:12px}

.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:2px}
.btn{border:1px solid var(--line); border-radius:12px; padding:12px 16px; cursor:pointer; font-weight:800; letter-spacing:.2px}
.btn.primary{background:linear-gradient(90deg, var(--accent), var(--accent2)); color:#001a1a}
.btn.ghost{background:#0e1d2d; color:#cfeaff}
.notice{border:1px dashed var(--line); border-radius:12px; padding:12px; color:#cfe0f5; background:rgba(255,255,255,.02)}
/* light mode quick */
body.light{--bg:#f7fbff; --card:#ffffff; --text:#0f1722; --muted:#55677a; --line:rgba(15,23,42,.08);}
body.light .card{box-shadow: 0 8px 16px rgba(0,0,0,.06)}
body.light .input, body.light textarea, body.light .chips{background:#ffffff;border-color:#dbe5ef;color:#0f1722}
body.light .avatarPreview img{border-color:#00bfff55}
</style>
</head>
<body>
  <div class="header">
    <div class="brand"><i class="fa fa-wand-magic-sparkles"></i> Striverr</div>
    <button class="icon-btn" id="themeBtn" title="Toggle theme"><i class="fa fa-moon"></i></button>
  </div>

  <div class="wrap">
    <div class="grid">
      <!-- FORM -->
      <form class="card" method="POST" action="update_profile.php" enctype="multipart/form-data">
        <div class="section">
          <div class="title"><h2>Edit Profile</h2></div>

          <div class="row2">
            <div>
              <label>Full Name</label>
              <input class="input" type="text" name="name" value="<?= e($name) ?>" maxlength="80" required>
            </div>
            <div>
              <label>Email (read‑only)</label>
              <input class="input" type="email" value="<?= e($email) ?>" disabled>
              <input type="hidden" name="email" value="<?= e($email) ?>">
            </div>
          </div>

          <div class="row3" style="margin-top:12px">
            <div>
              <label>Phone</label>
              <input class="input" type="text" name="phone" value="<?= e($phone) ?>" maxlength="30">
            </div>
            <div>
              <label>Country</label>
              <input class="input" type="text" name="country" value="<?= e($country) ?>" maxlength="56">
            </div>
            <div>
              <label>Experience Level / Tag</label>
              <input class="input" type="text" name="experience_level" value="<?= e($experience) ?>" maxlength="56">
            </div>
          </div>

          <div style="margin-top:12px">
            <label>Bio</label>
            <textarea name="bio" class="input" maxlength="600" placeholder="Tell clients how you work, your superpowers, and what success looks like."><?= e($bio) ?></textarea>
          </div>

          <!-- Skills chips -->
          <div style="margin-top:12px">
            <label>Skills (hit Enter to add · max 15)</label>
            <div class="chips" id="chips">
              <!-- chips injected -->
              <input id="chipInput" type="text" placeholder="e.g. PHP, React, MySQL">
            </div>
            <input type="hidden" name="skills" id="skillsHidden" value="<?= e($skills) ?>">
            <div class="small" style="margin-top:6px">Tip: Short, searchable tags help you get found.</div>
          </div>

          <!-- Avatar -->
          <div style="margin-top:14px">
            <label>Profile Image</label>
            <div class="avatarPreview">
              <img id="avatarImg" src="../includes/images/<?= e($profile_image ?: 'freelancer.png') ?>" alt="Avatar">
              <div style="flex:1">
                <input class="input" type="file" name="profile_image" id="avatarFile" accept="image/*">
                <label class="small" style="display:flex;gap:8px;align-items:center;margin-top:6px">
                  <input type="checkbox" name="remove_avatar" value="1"> Use default avatar
                </label>
                <div class="small">JPG/PNG/WEBP · up to 3MB</div>
              </div>
            </div>
          </div>

          <!-- Password -->
          <div style="margin-top:14px">
            <label>New Password (optional)</label>
            <input class="input" type="password" name="password" id="pwd" placeholder="••••••••" autocomplete="new-password">
            <div id="meter" class="small" style="margin-top:6px; color:#9fb3c8">Strength: —</div>
          </div>

          <div class="actions" style="margin-top:16px">
            <button class="btn primary" type="submit"><i class="fa fa-floppy-disk"></i> Save Changes</button>
            <a class="btn ghost" href="dashboard.php"><i class="fa fa-arrow-left"></i> Back to Dashboard</a>
          </div>
        </div>
      </form>

      <!-- SIDEBAR -->
      <div class="card">
        <div class="section">
          <div class="title"><h2>Your Rating</h2></div>
          <div class="stars" aria-label="Average rating">
            <?php
              $full = floor($avg_rating);
              $half = ($avg_rating - $full) >= 0.5 ? 1 : 0;
              for ($i=1; $i<=5; $i++){
                if ($i <= $full) echo '<i class="fa fa-star"></i>';
                elseif ($half && $i === $full+1) echo '<i class="fa fa-star-half-stroke"></i>';
                else echo '<i class="fa-regular fa-star"></i>';
              }
            ?>
          </div>
          <div class="small" style="margin-top:4px">
            <?= number_format($avg_rating,2) ?> · <?= (int)$rating_count ?> review<?= ((int)$rating_count===1?'':'s') ?>
          </div>

          <div class="notice" style="margin-top:12px">
            Ratings are earned from client reviews on completed milestones & projects. Keep your response time tight and deliver crisp hand‑offs to grow this fast.
          </div>

          <div style="margin-top:18px">
            <div class="title"><h2>Profile Tips</h2></div>
            <ul class="small" style="line-height:1.7;padding-left:18px;margin:0">
              <li>Lead with outcomes in your bio (“I help X do Y using Z”).</li>
              <li>Curate 8–12 relevant skills; keep them concise.</li>
              <li>Use a clear headshot with good lighting (no filters).</li>
              <li>Mention your timezone and preferred tools.</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>

<script>
// Theme
const themeBtn=document.getElementById('themeBtn');
themeBtn?.addEventListener('click',()=>{document.body.classList.toggle('light');localStorage.setItem('strThemeF.edit',document.body.classList.contains('light')?'light':'dark');});
if(localStorage.getItem('strThemeF.edit')==='light')document.body.classList.add('light');

// Flash (SweetAlert)
<?php if ($flash): ?>
  document.addEventListener('DOMContentLoaded', ()=>{
    Swal.fire({
      icon: <?= json_encode($flash['type'] ?? 'info') ?>,
      title: <?= json_encode($flash['msg'] ?? '') ?>,
      confirmButtonColor: '#00ffc3',
      background: getComputedStyle(document.body).getPropertyValue('--card'),
      color: getComputedStyle(document.body).getPropertyValue('--text')
    });
  });
<?php endif; ?>

// Avatar live preview
const file = document.getElementById('avatarFile');
const img  = document.getElementById('avatarImg');
file?.addEventListener('change',()=>{
  const f = file.files?.[0]; if(!f) return;
  const url = URL.createObjectURL(f); img.src=url;
});

// Skills chips
const initialSkills = (document.getElementById('skillsHidden').value || '').split(',').map(s=>s.trim()).filter(Boolean);
const chipsWrap = document.getElementById('chips');
const chipInput = document.getElementById('chipInput');
const hidden    = document.getElementById('skillsHidden');
let skills = [...new Set(initialSkills)].slice(0,15);

function renderChips(){
  [...chipsWrap.querySelectorAll('.chip')].forEach(c=>c.remove());
  skills.forEach(s=>{
    const el = document.createElement('span');
    el.className='chip';
    el.innerHTML = `${escapeHtml(s)} <button type="button" aria-label="remove">&times;</button>`;
    el.querySelector('button').addEventListener('click', ()=>{ skills = skills.filter(x=>x!==s); sync(); });
    chipsWrap.insertBefore(el, chipInput);
  });
  hidden.value = skills.join(',');
}
function sync(){ skills = skills.slice(0,15); renderChips(); }
function escapeHtml(str){ return String(str).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m])); }
chipInput.addEventListener('keydown', (e)=>{
  if(e.key==='Enter'){
    e.preventDefault();
    const v = chipInput.value.trim();
    if(!v) return;
    if(!skills.includes(v) && skills.length<15){ skills.push(v); chipInput.value=''; sync(); }
  }
});
renderChips();

// Password strength
const pwd = document.getElementById('pwd');
const meter = document.getElementById('meter');
pwd?.addEventListener('input', ()=>{
  const v = pwd.value || '';
  let score = 0;
  if (v.length >= 8) score++;
  if (/[A-Z]/.test(v)) score++;
  if (/[a-z]/.test(v)) score++;
  if (/\d/.test(v)) score++;
  if (/[^A-Za-z0-9]/.test(v)) score++;
  const labels = ['Very weak','Weak','Okay','Good','Strong','Elite'];
  meter.textContent = 'Strength: ' + labels[Math.min(score,5)];
});
</script>
</body>
</html>
