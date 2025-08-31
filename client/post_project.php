<?php
session_start();
include('../includes/auth_check.php');
include('../includes/db_connect.php');

if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
  header("Location: ../auth/login.php");
  exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf'])) {
    http_response_code(403);
    exit('Invalid CSRF token.');
  }

  $client_id   = (int)$_SESSION['user_id'];
  $title       = trim($_POST['title'] ?? '');
  $description = trim($_POST['description'] ?? '');
  $budget      = (float)($_POST['budget'] ?? 0);
  $deadline    = trim($_POST['deadline'] ?? '');
  $tags        = trim($_POST['tags'] ?? '');

  // sane limits
  $title       = mb_substr($title, 0, 120);
  $description = mb_substr($description, 0, 4000);

  $errors = [];
  if (empty($title)) $errors[] = "Project title is required.";
  if (empty($description)) $errors[] = "Description is required.";
  if ($budget <= 0 || !is_finite($budget)) $errors[] = "Please set a valid budget.";
  if (empty($deadline)) {
    $errors[] = "Deadline is required.";
  } elseif (strtotime($deadline) < strtotime('today')) {
    $errors[] = "Deadline can't be in the past.";
  }

  // tags: up to 6
  if (!empty($tags)) {
    $t = array_filter(array_map('trim', explode(',', $tags)));
    $tags = implode(',', array_slice($t, 0, 6));
  }

  if (!$errors) {
    $stmt = $conn->prepare("
      INSERT INTO projects (title, description, budget, deadline, tags, client_id, status)
      VALUES (?, ?, ?, ?, ?, ?, 'posted')
    ");
    $stmt->bind_param("ssdssi", $title, $description, $budget, $deadline, $tags, $client_id);
    if ($stmt->execute()) {
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // rotate CSRF
      $flash = ['type' => 'success', 'message' => 'Your project has been posted!'];
    } else {
      $flash = ['type' => 'error', 'message' => 'Failed to post project. Please try again.'];
    }
    $stmt->close();
  } else {
    $flash = ['type' => 'error', 'message' => implode("\n", $errors)];
  }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Striverr | Post a New Project</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
<style>
  :root{
    --bg:#0f1722; --panel:#1b2736; --panel-2:#12202e; --ink:#eaf2ff; --muted:#9db1c7;
    --accent:#00bfff; --mint:#00ffc3; --danger:#ff6b6b; --chip:#1d3346; --chipText:#bfe9ff;
    --glow:0 10px 30px rgba(0,191,255,.25);
  }
  *{box-sizing:border-box}
  body{
    margin:0; font-family:Poppins,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial;
    background:
      radial-gradient(1200px 800px at -10% -20%, #12202e 0%, transparent 60%),
      radial-gradient(900px 600px at 120% 10%, #112437 0%, transparent 60%),
      linear-gradient(160deg, #0c1320, #0f1722 60%, #0b1420);
    color:var(--ink); min-height:100vh; padding:32px;
  }
  .wrap{max-width:1200px; margin:0 auto; display:grid; gap:24px; grid-template-columns:1.1fr .9fr;}
  .panel{background:linear-gradient(180deg, var(--panel) 0%, #13202e 100%); border:1px solid rgba(255,255,255,.06); border-radius:18px; padding:28px; box-shadow:var(--glow);}
  header .brand{font-weight:700; color:#7dd3ff; letter-spacing:.5px}
  header .back{display:inline-flex; gap:10px; align-items:center; padding:10px 14px; background:#132234; border:1px solid rgba(255,255,255,.06);
    color:#cfeaff; border-radius:12px; text-decoration:none; font-weight:600; transition:transform .15s ease, box-shadow .15s ease;}
  header .back:hover{transform:translateY(-1px); box-shadow:0 10px 24px rgba(0,191,255,.12)}
  .title{font-size:32px; font-weight:700; margin:12px 0 6px}
  .hint{color:var(--muted); font-size:14px}

  label{display:block; font-weight:600; margin:18px 0 10px}
  .row{position:relative}
  input[type=text], input[type=date], input[type=number], textarea{
    width:100%; background:#0e1a27; border:1px solid #1f3346; color:var(--ink); border-radius:12px; padding:14px 14px;
    outline:none; transition:border-color .15s ease, box-shadow .15s ease;
  }
  input:focus, textarea:focus{border-color:var(--accent); box-shadow:0 0 0 3px rgba(0,191,255,.15)}
  textarea{min-height:150px; resize:vertical}
  .count{position:absolute; right:8px; bottom:-18px; font-size:12px; color:var(--muted)}
  .rowbar{display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:10px}
  .ghostBtn{background:#112033; color:#cfeaff; border:1px solid #25435a; border-radius:12px; padding:10px 12px; cursor:pointer;}
  .ghostBtn:hover{background:#0e1d2e}

  .rangeWrap{display:flex; gap:14px; align-items:center}
  .rangeWrap input[type=range]{flex:1; accent-color:var(--mint)}
  .budgetBox{min-width:96px; text-align:center; background:#0b1c2a; border:1px solid #1b3244; padding:10px 12px; border-radius:10px}
  .budgetBox span{font-weight:700}
  .tip{color:var(--muted); font-size:12px; margin-top:8px}

  /* tags */
  .tagsInput{display:flex; gap:8px; flex-wrap:wrap; background:#0b1a28; border:1px solid #1b3144; padding:10px; border-radius:12px}
  .tagsInput input{background:transparent; border:none; outline:none; padding:8px; min-width:150px; color:var(--ink)}
  .chip{background:var(--chip); color:var(--chipText); padding:6px 10px; border-radius:16px; display:inline-flex; align-items:center; gap:8px; font-size:13px; border:1px solid #2a4b66;}
  .chip button{background:transparent; border:none; color:#8fd4ff; cursor:pointer; font-size:14px; line-height:1;}
  .suggest{margin-top:10px; display:flex; flex-wrap:wrap; gap:8px}
  .suggest .sugg{background:#0b1a28; border:1px dashed #204058; color:#9ed6ff; padding:6px 10px; border-radius:14px; cursor:pointer; font-size:12px}
  .warn{color:var(--danger); font-size:12px; margin-top:6px}

  .actions{margin-top:24px; display:flex; gap:12px; align-items:center; flex-wrap:wrap}
  .btn{background:linear-gradient(90deg, var(--mint), var(--accent)); color:#07121c; font-weight:800; border:none; border-radius:14px; padding:14px 18px; cursor:pointer; letter-spacing:.3px; box-shadow:0 10px 20px rgba(0,255,195,.15); transition:transform .15s ease, box-shadow .15s ease;}
  .btn:hover{transform:translateY(-1px); box-shadow:0 14px 26px rgba(0,255,195,.22)}
  .ghost{background:#112033; color:#cfeaff; border:1px solid #25435a}

  /* Preview */
  .previewHead{display:flex; justify-content:space-between; align-items:center; margin-bottom:10px}
  .badge{background:#0e2534; color:#9be7ff; padding:6px 10px; border-radius:999px; font-size:12px; border:1px solid #22506b}
  .card{background:linear-gradient(180deg, var(--panel-2), #0b1a26); border:1px solid rgba(255,255,255,.06); border-radius:16px; padding:18px; margin-top:10px;}
  .card h3{margin:0 0 8px; font-size:18px}
  .meta{display:flex; gap:10px; flex-wrap:wrap; color:#cde8ff; font-size:12px}
  .meta .pill{background:#0b1a28; border:1px solid #22445c; padding:6px 10px; border-radius:14px}
  .pvTags{display:flex; flex-wrap:wrap; gap:6px; margin-top:10px}
  .pvTags .pill{background:#0d2232; border:1px solid #1f4259; color:#9ddcff}
  .emptyPreview{opacity:.7; text-align:center; padding:40px 10px; color:var(--muted)}
</style>
</head>
<body>
  <div class="wrap">
    <section class="panel">
      <header style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px">
        <div class="brand">Striverr</div>
        <a class="back" href="dashboard.php"><i class="fa fa-arrow-left"></i> Dashboard</a>
      </header>

      <div class="title">Post a New Project</div>
      <div class="hint">Be specific and concise. Great briefs attract better freelancers.</div>

      <form id="postForm" method="POST" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

        <!-- Quick templates (optional, no DB needed) -->
        <div class="rowbar">
          <button type="button" class="ghostBtn" id="tplLanding"><i class="fa fa-bolt"></i> Landing Page Template</button>
          <button type="button" class="ghostBtn" id="tplApi"><i class="fa fa-plug"></i> API Integration Template</button>
        </div>

        <!-- Title -->
        <label for="title"><i class="fa fa-heading"></i> Project Title</label>
        <div class="row">
          <input type="text" name="title" id="title" maxlength="120" placeholder="e.g. Build a modern landing page" required>
          <div class="count"><span id="titleCount">0</span>/120</div>
        </div>

        <!-- Description -->
        <label for="description"><i class="fa fa-list-check"></i> Description</label>
        <div class="row">
          <textarea name="description" id="description" maxlength="4000" placeholder="Describe scope, deliverables, tech stack, and any constraints..." required></textarea>
          <div class="count"><span id="descCount">0</span>/4000</div>
        </div>

        <!-- Budget -->
        <label><i class="fa fa-sack-dollar"></i> Budget ($)</label>
        <div class="rangeWrap">
          <input type="range" id="budgetRange" min="50" max="10000" step="50" value="500">
          <div class="budgetBox"><span id="budgetVal">500</span></div>
          <input type="hidden" name="budget" id="budgetHidden" value="500">
        </div>
        <div class="tip" id="budgetTip">Tip: Keep it realistic to attract the right talent.</div>

        <!-- Deadline -->
        <label for="deadline"><i class="fa fa-calendar"></i> Deadline</label>
        <div class="row">
          <input type="date" name="deadline" id="deadline" min="<?php echo date('Y-m-d'); ?>" required>
          <div class="count" id="daysLeftTip" style="right:auto;left:0;bottom:-22px">Pick a date — we’ll show how many days from now.</div>
        </div>

        <!-- Tags -->
        <label><i class="fa fa-tags"></i> Tags (max 6)</label>
        <div class="tagsInput" id="tagsInput">
          <input type="text" id="tagEntry" placeholder="Press Enter to add tags" autocomplete="off">
        </div>
        <input type="hidden" name="tags" id="tagsHidden">
        <div class="suggest" id="suggest"></div>
        <div class="warn" id="tagWarn" style="display:none">You can add up to 6 tags.</div>

        <div class="actions">
          <button class="btn" type="submit" title="Cmd/Ctrl + Enter"><i class="fa fa-paper-plane"></i> Post Project</button>
          <button type="button" class="btn ghost" id="clearBtn">Clear</button>
        </div>
      </form>
    </section>

    <!-- PREVIEW -->
    <aside class="panel">
      <div class="previewHead">
        <div class="brand" style="opacity:.85">Live Preview</div>
        <div class="badge" id="previewBadge">Draft</div>
      </div>

      <div id="preview" class="card emptyPreview">
        Start typing your project… 👇
      </div>

      <div class="card" style="margin-top:16px">
        <div class="hint">
          A crisp, outcome‑focused brief attracts the right freelancers faster.
          Include examples, APIs, design palettes, and expected hand‑off format.
          Don’t forget timezone, milestones, and revision expectations.
        </div>
      </div>
    </aside>
  </div>

<script>
  // FLASH from PHP
  <?php if ($flash): ?>
    document.addEventListener('DOMContentLoaded', () => {
      const type = <?= json_encode($flash['type']) ?>;
      const msg  = <?= json_encode($flash['message']) ?>;
      if (type === 'success') {
        Swal.fire({
          icon: 'success', title: 'Project Posted!', text: msg,
          confirmButtonText: 'Go to My Projects', confirmButtonColor: '#00ffc3',
          background: '#0f1722', color: '#eaf2ff'
        }).then(() => { window.location.href = 'my_projects.php'; });

        const end = Date.now() + 1200;
        (function frame(){
          confetti({ particleCount: 4, angle: 60, spread: 55, origin: {x: 0}, colors:['#00ffc3','#00bfff'] });
          confetti({ particleCount: 4, angle: 120, spread: 55, origin: {x: 1}, colors:['#00ffc3','#00bfff'] });
          if (Date.now() < end) requestAnimationFrame(frame);
        })();
      } else {
        Swal.fire({ icon:'error', title: 'Please check', text: msg, background:'#0f1722', color:'#eaf2ff' });
      }
    });
  <?php endif; ?>

  // Helpers
  const $ = s => document.querySelector(s);
  const escapeHtml = str => String(str).replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&gt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));
  // fix: > mapping
  (function(){ const map = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}; window.escapeHtml = s => String(s).replace(/[&<>"']/g, c => map[c]); })();

  // Fields
  const titleEl = $('#title');
  const descEl  = $('#description');
  const range   = $('#budgetRange');
  const bVal    = $('#budgetVal');
  const bHidden = $('#budgetHidden');
  const ddl     = $('#deadline');
  const tagsWrap= $('#tagsInput');
  const tagEntry= $('#tagEntry');
  const tagsHidden = $('#tagsHidden');
  const tagWarn    = $('#tagWarn');
  const suggestEl  = $('#suggest');
  const titleCount = $('#titleCount');
  const descCount  = $('#descCount');
  const daysTip    = $('#daysLeftTip');
  const previewBox = $('#preview');
  const previewBadge = $('#previewBadge');

  // Counters
  function updateCounts(){
    titleCount.textContent = (titleEl.value||'').length;
    descCount.textContent  = (descEl.value||'').length;
  }
  ['input','change'].forEach(ev => {
    titleEl.addEventListener(ev, updateCounts);
    descEl.addEventListener(ev, updateCounts);
  });

  // Budget
  function budgetTier(v){ if (v < 300) return ['Starter', '#00ffc3']; if (v < 1500) return ['Standard', '#00bfff']; return ['Premium', '#ffd166']; }
  function updateBudget(){
    const v = parseInt(range.value||0,10);
    bVal.textContent = v;
    bHidden.value = v;
    const [tier, color] = budgetTier(v);
    previewBadge.textContent = tier;
    previewBadge.style.borderColor = color;
    previewBadge.style.color = '#dff6ff';
    previewBadge.style.background = 'rgba(0,0,0,.15)';
    renderPreview();
  }
  range.addEventListener('input', updateBudget);

  // Deadline -> days left
  function updateDays(){
    if(!ddl.value){ daysTip.textContent = 'Pick a date — we’ll show how many days from now.'; renderPreview(); return; }
    const target = new Date(ddl.value + 'T00:00:00');
    const today  = new Date(); today.setHours(0,0,0,0);
    const diff = Math.round((target - today) / (1000*60*60*24));
    const mood = diff < 7 ? '⚠️ Tight' : diff < 14 ? '⏳ Moderate' : '✅ Comfortable';
    daysTip.textContent = `Deadline in ${diff} day(s). ${mood}.`;
    renderPreview();
  }
  ddl.addEventListener('change', updateDays);

  // Tags
  const popularTags = ['PHP','Laravel','JavaScript','React','Node.js','MySQL','REST API','UI/UX','Tailwind','Bootstrap','Figma','WordPress','Vue','Python','Django'];
  let tags = [];
  function setTags(arr){
    tags = arr.slice(0,6);
    tagsHidden.value = tags.join(',');
    [...tagsWrap.querySelectorAll('.chip')].forEach(n=>n.remove());
    tags.forEach(t=>{
      const chip = document.createElement('span');
      chip.className='chip';
      chip.innerHTML = `${escapeHtml(t)} <button type="button" aria-label="remove">&times;</button>`;
      chip.querySelector('button').addEventListener('click', ()=>{ setTags(tags.filter(x=>x!==t)); renderPreview(); });
      tagsWrap.insertBefore(chip, tagEntry);
    });
    tagWarn.style.display = tags.length >= 6 ? 'block' : 'none';
  }
  function addTag(t){ t = t.trim(); if(!t || tags.includes(t) || tags.length>=6) return; setTags([...tags, t]); }
  tagEntry.addEventListener('keydown', e=>{ if(e.key === 'Enter'){ e.preventDefault(); addTag(tagEntry.value); tagEntry.value=''; renderPreview(); }});
  // suggestions
  popularTags.forEach(t=>{ const el = document.createElement('button'); el.type='button'; el.className='sugg'; el.textContent=t; el.addEventListener('click', ()=>{ addTag(t); renderPreview(); }); suggestEl.appendChild(el); });

  // Templates (no DB needed)
  const tplLanding = document.getElementById('tplLanding');
  const tplApi = document.getElementById('tplApi');
  tplLanding.addEventListener('click', ()=>{
    titleEl.value = 'Design & build a modern, responsive landing page';
    descEl.value = `Goal:
• Convert paid ad traffic into trials/leads
Deliverables:
• Responsive sections (Hero, Social proof, Features, Pricing, FAQ, CTA)
• Basic animations and micro‑interactions
• GA4 + meta pixels
Tech:
• Tailwind / Vanilla JS (no heavy frameworks)
Handoff:
• Clean file structure, notes, and quick Loom walkthrough`;
    setTags(['UI/UX','Tailwind','JavaScript','GA4']); updateCounts(); renderPreview();
  });
  tplApi.addEventListener('click', ()=>{
    titleEl.value = 'Integrate 3rd‑party REST API into existing PHP/Laravel app';
    descEl.value = `Scope:
• Authenticate with provider & sync resources every 15 min (queued job)
• Expose lightweight endpoints for the frontend
• Handle errors, retries, and logging
Tech:
• PHP 8+, Laravel, MySQL
• REST, OAuth2
Deliverables:
• PR with tests, config docs, and deployment notes`;
    setTags(['Laravel','PHP','MySQL','REST API','Queue']); updateCounts(); renderPreview();
  });

  // Live Preview
  function renderPreview(){
    const t = titleEl.value.trim();
    const d = descEl.value.trim();
    const v = parseInt(range.value || 0, 10);
    const date = ddl.value ? new Date(ddl.value+'T00:00:00') : null;
    const today= new Date(); today.setHours(0,0,0,0);
    const diff = date ? Math.round((date - today)/(1000*60*60*24)) : null;

    if(!t && !d && tags.length===0 && !date){
      previewBox.classList.add('emptyPreview');
      previewBox.innerHTML = "Start typing your project… 👇";
      return;
    }
    previewBox.classList.remove('emptyPreview');
    previewBox.innerHTML = `
      <h3>${escapeHtml(t || 'Untitled Project')}</h3>
      <div class="meta" style="margin-bottom:8px">
        <span class="pill"><i class="fa fa-sack-dollar"></i> $${v}</span>
        ${diff!==null ? `<span class="pill"><i class="fa fa-clock"></i> ${diff} day(s) left</span>` : ``}
      </div>
      <div style="white-space:pre-wrap; color:#d7ecff">${escapeHtml(d || 'Add a great description to attract talent.')}</div>
      ${tags.length? `<div class="pvTags">${tags.map(tt=>`<span class="pill">${escapeHtml(tt)}</span>`).join('')}</div>`:''}
    `;
  }

  // Initial hooks (this was the culprit for “preview not working”)
  document.addEventListener('DOMContentLoaded', ()=>{
    updateCounts();
    updateBudget();
    updateDays();
    renderPreview();
  });
  // realtime updates
  [titleEl, descEl, range, ddl].forEach(el => el.addEventListener('input', renderPreview));

  // Clear
  document.getElementById('clearBtn').addEventListener('click', ()=>{
    titleEl.value=''; descEl.value=''; range.value=500; bHidden.value=500; bVal.textContent='500';
    ddl.value=''; setTags([]); tagEntry.value='';
    updateCounts(); updateBudget(); updateDays(); renderPreview();
  });

  // Submit validation
  const form = document.getElementById('postForm');
  form.addEventListener('submit', (e)=>{
    const required = [[titleEl,'Project title is required'], [descEl,'Description is required'], [ddl,'Deadline is required']];
    for (const [el,msg] of required){
      if(!el.value.trim()){
        e.preventDefault(); el.focus();
        Swal.fire({icon:'warning', title:'Hold on', text:msg, background:'#0f1722', color:'#eaf2ff'});
        return;
      }
    }
    if (parseFloat(range.value) <= 0){
      e.preventDefault();
      Swal.fire({icon:'warning', title:'Invalid budget', text:'Please set a valid budget.', background:'#0f1722', color:'#eaf2ff'});
      return;
    }
    // pack tags
    tagsHidden.value = tags.join(',');
  });

  // hotkey
  document.addEventListener('keydown', (e)=>{
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') form.requestSubmit();
  });
</script>
</body>
</html>
