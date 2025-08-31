<?php
session_start();
include('../includes/db_connect.php');

// Ensure user is logged in as client
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
  header("Location: ../auth/login.php");
  exit();
}

$user_id = (int)$_SESSION['user_id'];

// Fetch current user data
$stmt = $conn->prepare("
  SELECT name, email, phone, bio, skills, country, experience_level, 
         COALESCE(NULLIF(profile_image,''),'client.png') AS profile_image
  FROM users WHERE user_id = ? LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($name, $email, $phone, $bio, $skills, $country, $experience_level, $profile_image);
$stmt->fetch();
$stmt->close();

// Helper function
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Striverr · Edit Profile (Client)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    :root {
      --bg: #0b1220;
      --card: #0f182a;
      --text: #eaf2ff;
      --muted: #9fb3c8;
      --line: rgba(255, 255, 255, .08);
      --accent: #00ffc3;
      --accent2: #00bfff;
      --ok: #22c55e;
      --warn: #f59e0b;
    }

    * { box-sizing: border-box; }
    body {
      margin: 0; font-family: Poppins, system-ui, Segoe UI, Arial, sans-serif;
      background: radial-gradient(1200px 600px at 80% -10%, rgba(0, 255, 195, .12), transparent),
        radial-gradient(900px 500px at 10% 10%, rgba(0, 191, 255, .10), transparent),
        linear-gradient(180deg, #0a111e 0%, #0b1220 100%);
      color: var(--text);
    }

    .header {
      position: sticky; top: 0; z-index: 20; backdrop-filter: blur(10px);
      background: linear-gradient(180deg, rgba(11, 18, 32, .7), rgba(11, 18, 32, .35));
      border-bottom: 1px solid var(--line); padding: 12px 18px; display: flex; justify-content: space-between; align-items: center;
    }

    .brand { font-weight: 900; letter-spacing: .4px; display: flex; gap: 10px; align-items: center; }
    .brand i { color: var(--accent); }

    .icon-btn {
      width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center;
      border: 1px solid var(--line); background: rgba(255, 255, 255, .04); color: var(--text); cursor: pointer;
    }

    .wrap {
      max-width: 1000px; margin: 28px auto 60px; padding: 0 16px;
    }

    .grid { display: grid; gap: 18px; grid-template-columns: 3fr 2fr; }

    @media (max-width: 980px) { .grid { grid-template-columns: 1fr; } }

    .card {
      background: linear-gradient(180deg, rgba(255, 255, 255, .06), rgba(255, 255, 255, .03));
      border: 1px solid var(--line); border-radius: 16px; box-shadow: 0 12px 26px rgba(0, 0, 0, .35);
      backdrop-filter: blur(10px);
    }

    .section { padding: 18px; }
    .title { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .title h2 { font-size: 18px; margin: 0; }

    label { display: block; color: #cfe0f5; font-size: 13px; margin-bottom: 6px; }
    .input, textarea, .chips {
      width: 100%; background: #0b1a26; border: 1px solid #1f3346; color: var(--text);
      border-radius: 12px; padding: 12px 14px; outline: none; transition: .15s;
    }

    .input:focus, textarea:focus { border-color: var(--accent2); box-shadow: 0 0 0 3px rgba(0, 191, 255, .15); }

    .chips {
      display: flex; gap: 8px; flex-wrap: wrap; min-height: 48px; align-items: center;
    }

    .chips input {
      flex: 1; min-width: 140px; background: transparent; border: none; outline: none; color: var(--text);
    }

    .chip {
      display: inline-flex; align-items: center; gap: 8px; padding: 8px 10px;
      border-radius: 999px; border: 1px solid var(--line); background: #0d2131;
    }

    .chip button {
      border: none; background: transparent; color: #9bdcff; cursor: pointer;
    }

    .avatarPreview {
      display: flex; gap: 14px; align-items: center;
    }

    .avatarPreview img {
      width: 82px; height: 82px; border-radius: 50%; object-fit: cover;
      border: 2px solid rgba(127, 221, 255, .5);
    }

    .stars { display: flex; gap: 2px; font-size: 18px; color: #ffd166; }
    .small { color: #9fb3c8; font-size: 12px; }

    .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 2px; }
    .btn {
      border: 1px solid var(--line); border-radius: 12px; padding: 12px 16px; cursor: pointer;
      font-weight: 800; letter-spacing: .2px;
    }

    .btn.primary {
      background: linear-gradient(90deg, var(--accent), var(--accent2)); color: #001a1a;
    }

    .btn.ghost { background: #0e1d2d; color: #cfeaff; }

    .notice {
      border: 1px dashed var(--line); border-radius: 12px; padding: 12px;
      color: #cfe0f5; background: rgba(255, 255, 255, .02);
    }

    /* light mode quick */
    body.light {
      --bg: #f7fbff; --card: #ffffff; --text: #0f1722; --muted: #55677a; --line: rgba(15, 23, 42, .08);
    }

    body.light .card { box-shadow: 0 8px 16px rgba(0, 0, 0, .06); }
    body.light .input, body.light textarea, body.light .chips {
      background: #ffffff; border-color: #dbe5ef; color: #0f1722;
    }
    body.light .avatarPreview img { border-color: #00bfff55; }
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
            <label>Experience Level</label>
            <input class="input" type="text" name="experience_level" value="<?= e($experience_level) ?>" maxlength="56">
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
            <img id="avatarImg" src="../includes/images/<?= e($profile_image ?: 'client.png') ?>" alt="Avatar">
            <div style="flex:1">
              <input class="input" type="file" name="profile_image" id="avatarFile" accept="image/*">
              <label class="small" style="display:flex;gap:8px;align-items:center;margin-top:6px">
                <input type="checkbox" name="remove_avatar" value="1"> Use default avatar
              </label>
              <div class="small">JPG/PNG/WEBP · up to 3MB</div>
            </div>
          </div>
        </div>

        <div class="actions" style="margin-top:16px">
          <button class="btn primary" type="submit"><i class="fa fa-floppy-disk"></i> Save Changes</button>
          <a class="btn ghost" href="dashboard.php"><i class="fa fa-arrow-left"></i> Back to Dashboard</a>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
// Theme toggle
const themeBtn=document.getElementById('themeBtn');
themeBtn?.addEventListener('click',()=>{ document.body.classList.toggle('light'); localStorage.setItem('strThemeC.edit', document.body.classList.contains('light') ? 'light' : 'dark'); });
if(localStorage.getItem('strThemeC.edit')==='light') document.body.classList.add('light');

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
    el.className = 'chip';
    el.innerHTML = `${escapeHtml(s)} <button type="button" aria-label="remove">&times;</button>`;
    el.querySelector('button').addEventListener('click', ()=>{ skills = skills.filter(x=>x!==s); sync(); });
    chipsWrap.insertBefore(el, chipInput);
  });
  hidden.value = skills.join(',');
}

function sync(){ skills = skills.slice(0,15); renderChips(); }

function escapeHtml(str){ return String(str).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m])); }

chipInput.addEventListener('keydown', (e)=>{ if(e.key==='Enter'){ e.preventDefault(); const v = chipInput.value.trim(); if(!v) return; if(!skills.includes(v) && skills.length<15){ skills.push(v); chipInput.value=''; sync(); } } });
renderChips();
</script>
</body>
</html>
