<?php
session_start();
include('../includes/auth_check.php');
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php");
    exit();
}

if (!isset($_GET['project_id']) || !is_numeric($_GET['project_id'])) {
    echo "<script>alert('Invalid or missing project ID!'); window.location.href='dashboard.php';</script>";
    exit();
}

$project_id = (int)$_GET['project_id'];
$client_id  = (int)$_SESSION['user_id'];

/* ---------- Ownership + Project meta ---------- */
$meta = $conn->prepare("SELECT title, client_id, hired_freelancer_id FROM projects WHERE project_id = ?");
$meta->bind_param("i", $project_id);
$meta->execute();
$meta->bind_result($project_title, $owner_id, $hired_freelancer_id);
$found = $meta->fetch();
$meta->close();

if (!$found || $owner_id !== $client_id) {
    echo "<script>alert('Unauthorized access!'); window.location.href='dashboard.php';</script>";
    exit();
}

/* ---------- Applicants for this project ---------- */
$q = $conn->prepare("
    SELECT 
        u.user_id AS freelancer_id,
        u.name,
        u.country,
        u.experience_level,
        u.skills,
        u.bio,
        u.profile_image,
        a.bid_amount,
        a.status AS application_status,
        a.applied_at
    FROM applications a
    JOIN users u ON u.user_id = a.freelancer_id
    WHERE a.project_id = ?
    ORDER BY a.applied_at DESC
");
$q->bind_param("i", $project_id);
$q->execute();
$res = $q->get_result();
$applicants = $res->fetch_all(MYSQLI_ASSOC);
$q->close();

/* ---------- CSRF token for hire action ---------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(20));
}
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Applicants · <?= htmlspecialchars($project_title) ?> | Striverr</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
<style>
  :root{
    --bg:#0f2027;
    --bg2:#203a43;
    --bg3:#2c5364;
    --card:rgba(255,255,255,.06);
    --glass:blur(16px);
    --accent:#00bfff;
    --mint:#00ffc3;
    --text:#eaf7ff;
    --muted:#a7c1cc;
    --chip:#102a33;
  }
  *{box-sizing:border-box}
  body{
    margin:0;
    font-family:'Poppins',sans-serif;
    color:var(--text);
    background:linear-gradient(135deg,var(--bg),var(--bg2),var(--bg3));
    min-height:100vh;
    padding:28px;
  }
  .wrap{
    max-width:1200px;
    margin:0 auto;
  }
  header.nav{
    display:flex;justify-content:space-between;align-items:center;
    margin-bottom:18px;
  }
  .brand{font-weight:700;font-size:22px;letter-spacing:.3px}
  .brand span{color:var(--accent)}
  .crumbs{
    display:flex;gap:10px;align-items:center;color:var(--muted);font-size:14px
  }
  .crumbs a{color:var(--text);text-decoration:none;opacity:.85}
  .shell{
    display:grid;grid-template-columns:1fr;gap:18px
  }
  @media(min-width:980px){ .shell{grid-template-columns:1fr 320px} }
  .panel{
    background:var(--card);backdrop-filter:var(--glass);border-radius:16px;
    box-shadow:0 10px 30px rgba(0,0,0,.25);padding:22px;
  }
  .panel h1{
    margin:4px 0 16px;font-size:22px;font-weight:700;
  }
  .meta{
    display:flex;justify-content:space-between;align-items:center;
    gap:10px;margin-bottom:18px
  }
  .btn{
    display:inline-flex;align-items:center;gap:8px;border:none;
    padding:10px 16px;border-radius:12px;cursor:pointer;font-weight:600;
    text-decoration:none;
  }
  .btn-outline{background:transparent;color:var(--text);border:1px solid rgba(255,255,255,.2)}
  .btn-mint{background:linear-gradient(90deg,var(--mint),#00d8e0);color:#001;}
  .btn-blue{background:linear-gradient(90deg,var(--accent),#508CFE);color:white}
  .grid{
    display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:16px
  }
  .card{
    background:rgba(255,255,255,.05);border-radius:16px;padding:18px;position:relative;
    transition:.25s;box-shadow:0 6px 18px rgba(0,0,0,.2)
  }
  .card:hover{transform:translateY(-3px)}
  .avatar{width:64px;height:64px;border-radius:50%;object-fit:cover;border:3px solid var(--mint)}
  .top{
    display:flex;align-items:center;gap:12px;margin-bottom:8px
  }
  .name{font-weight:700}
  .sub{font-size:12px;color:var(--muted)}
  .bio{font-size:13px;color:var(--muted);min-height:40px;margin:6px 0 12px}
  .chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px}
  .chip{background:var(--chip);color:#7fe9ff;border:1px solid rgba(255,255,255,.08);padding:6px 8px;border-radius:12px;font-size:11px}
  .row{display:flex;justify-content:space-between;align-items:center;margin:8px 0}
  .price{font-weight:700}
  .applied{font-size:12px;color:var(--muted)}
  .actions{display:flex;gap:8px;flex-wrap:wrap}
  .badge-hired{
    position:absolute;top:12px;right:12px;background:gold;color:#111;font-weight:700;
    padding:6px 10px;border-radius:999px;font-size:12px;box-shadow:0 0 0 2px rgba(0,0,0,.2) inset;
  }
  .disabled{opacity:.55;pointer-events:none}
  /* side help */
  .side .tip{
    background:rgba(255,255,255,.04);border-radius:14px;padding:16px;margin-bottom:14px
  }
  .controls{
    display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 16px
  }
  .input{
    background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.15);
    color:var(--text);padding:10px 12px;border-radius:10px;outline:none
  }
  .input::placeholder{color:#9ec2cc}
  .select{appearance:none}
  .empty{
    text-align:center;padding:48px 18px;color:var(--muted)
  }
  .hint{font-size:12px;color:var(--muted);margin-top:6px}
</style>
</head>
<body>
<div class="wrap">

  <header class="nav">
    <div class="brand">Stri<span>verr</span></div>
    <div class="crumbs">
      <a class="btn btn-outline" href="dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
      <a class="btn btn-outline" href="project_details.php?id=<?= $project_id ?>"><i class="fa fa-diagram-project"></i> Project</a>
    </div>
  </header>

  <div class="shell">
    <section class="panel">
      <div class="meta">
        <h1>Applicants · <?= htmlspecialchars($project_title) ?></h1>
        <div class="controls">
          <input id="search" class="input" placeholder="Search name or skill…" />
          <select id="sort" class="input select">
            <option value="recent">Sort: Most recent</option>
            <option value="bid_asc">Lowest bid</option>
            <option value="bid_desc">Highest bid</option>
            <option value="name">Name A–Z</option>
          </select>
        </div>
      </div>

      <?php if (count($applicants) === 0): ?>
        <div class="empty">
          <div style="font-size:48px;margin-bottom:10px;">🕵️‍♂️</div>
          <h3>No applicants yet</h3>
          <p class="hint">Share the project link or give it some time — great talent is on the way.</p>
        </div>
      <?php else: ?>
        <div id="cards" class="grid">
          <?php foreach ($applicants as $a):
            $fid        = (int)$a['freelancer_id'];
            $name       = $a['name'] ?? 'Unknown';
            $skills     = array_filter(array_map('trim', explode(',', (string)$a['skills'])));
            $img        = $a['profile_image'] ?: 'freelancer.png';
            $bid        = $a['bid_amount'];
            $applied_at = $a['applied_at'];
            $status     = $a['application_status'];
            $country    = $a['country'] ?: '—';
            $level      = $a['experience_level'] ?: '—';
            $isHired    = ($hired_freelancer_id && $fid === (int)$hired_freelancer_id) || $status === 'accepted';
          ?>
          <div class="card"
               data-name="<?= htmlspecialchars(mb_strtolower($name)) ?>"
               data-skills="<?= htmlspecialchars(mb_strtolower(implode(' ', $skills))) ?>"
               data-bid="<?= htmlspecialchars($bid ?? 0) ?>"
               data-date="<?= htmlspecialchars(strtotime($applied_at ?? 'now')) ?>"
               id="card-<?= $fid ?>">
            <?php if ($isHired): ?><div class="badge-hired">Hired</div><?php endif; ?>
            <div class="top">
              <img class="avatar" src="../includes/images/<?= htmlspecialchars($img) ?>" onerror="this.src='../includes/images/freelancer.png'">
              <div>
                <div class="name"><?= htmlspecialchars($name) ?></div>
                <div class="sub"><i class="fa fa-location-dot"></i> <?= htmlspecialchars($country) ?> &nbsp; • &nbsp;<i class="fa fa-certificate"></i> <?= htmlspecialchars($level) ?></div>
              </div>
            </div>

            <div class="chips">
              <?php if ($skills): foreach ($skills as $s): ?>
                <span class="chip"><?= htmlspecialchars($s) ?></span>
              <?php endforeach; else: ?>
                <span class="chip">No skills listed</span>
              <?php endif; ?>
            </div>

            <div class="row">
              <div class="price"><?= $bid !== null ? ('$' . number_format((float)$bid, 2)) : '—' ?></div>
              <div class="applied"><i class="fa fa-clock"></i> <?= $applied_at ? date('M j, Y g:i A', strtotime($applied_at)) : '—' ?></div>
            </div>

            <div class="actions">
              <a class="btn btn-blue" href="view_freelancer_profile.php?id=<?= $fid ?>&project_id=<?= $project_id ?>">
                <i class="fa fa-user"></i> Profile
              </a>

              <?php if ($isHired): ?>
                <button class="btn btn-mint disabled"><i class="fa fa-badge-check"></i> Hired</button>
              <?php elseif ($hired_freelancer_id): ?>
                <button class="btn btn-mint disabled"><i class="fa fa-lock"></i> Hire disabled</button>
              <?php else: ?>
                <button class="btn btn-mint"
                        onclick="hireFreelancer(<?= $fid ?>, <?= $project_id ?>, this)">
                  <i class="fa fa-handshake"></i> Hire
                </button>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <aside class="panel side">
      <div class="tip">
        <h3 style="margin:0 0 6px">How hiring works</h3>
        <p class="hint">Hiring will mark this project as <b>active</b> and notify the freelancer. Other applicants are automatically set to <em>rejected</em>.</p>
      </div>
      <div class="tip">
        <h3 style="margin:0 0 6px">Shortlisting</h3>
        <p class="hint">Use the search and sort tools above to quickly shortlist by <b>skills</b> and <b>bid</b>.</p>
      </div>
      <a class="btn btn-outline" href="project_details.php?id=<?= $project_id ?>"><i class="fa fa-diagram-project"></i> Project details</a>
      <a class="btn btn-outline" href="dashboard.php"><i class="fa fa-home"></i> Back to Dashboard</a>
    </aside>
  </div>
</div>

<script>
  const CSRF = <?= json_encode($csrf) ?>;
  const hasHired = <?= $hired_freelancer_id ? 'true' : 'false' ?>;

  /* ---------- Search + Sort ---------- */
  const cardsWrap = document.getElementById('cards');
  const search = document.getElementById('search');
  const sort = document.getElementById('sort');

  function applyFilterSort() {
    if (!cardsWrap) return;

    const term = (search.value || '').trim().toLowerCase();
    const items = [...cardsWrap.children];

    // filter
    items.forEach(card => {
      const name = card.dataset.name || '';
      const skills = card.dataset.skills || '';
      const hit = name.includes(term) || skills.includes(term);
      card.style.display = hit ? '' : 'none';
    });

    // sort
    const key = sort.value;
    items.sort((a,b) => {
      if (key === 'bid_asc') return (+a.dataset.bid) - (+b.dataset.bid);
      if (key === 'bid_desc') return (+b.dataset.bid) - (+a.dataset.bid);
      if (key === 'name') return (a.dataset.name > b.dataset.name) ? 1 : -1;
      // recent
      return (+b.dataset.date) - (+a.dataset.date);
    });
    items.forEach(el => cardsWrap.appendChild(el));
  }
  search?.addEventListener('input', applyFilterSort);
  sort?.addEventListener('change', applyFilterSort);
  applyFilterSort();

  /* ---------- Hire flow ---------- */
  function hireFreelancer(freelancerId, projectId, btnEl){
    if (hasHired) return;

    Swal.fire({
      title: 'Confirm hire?',
      text: 'This will activate the project with this freelancer.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#00ffc3',
      cancelButtonColor: '#334',
      confirmButtonText: 'Yes, hire'
    }).then(result => {
      if (!result.isConfirmed) return;

      btnEl.disabled = true;

      fetch('hire_freelancer.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `freelancer_id=${encodeURIComponent(freelancerId)}&project_id=${encodeURIComponent(projectId)}&csrf=${encodeURIComponent(CSRF)}`
      })
      .then(r => r.json())
      .then(data => {
        if (!data || !data.success) {
          btnEl.disabled = false;
          Swal.fire('Oops', data?.message || 'Could not hire. Try again.', 'error');
          return;
        }

        // confetti 🎉
        confetti({ particleCount: 130, spread: 80, origin: { y: .7 } });

        // mark UI
        document.querySelectorAll('.card .btn-mint').forEach(b => { b.classList.add('disabled'); b.disabled = true; });
        const card = document.getElementById(`card-${freelancerId}`);
        if (card && !card.querySelector('.badge-hired')) {
          const badge = document.createElement('div');
          badge.className = 'badge-hired';
          badge.textContent = 'Hired';
          card.appendChild(badge);
        }

        Swal.fire({
          toast:true, position:'top-end', icon:'success',
          title: data.message || 'Hired successfully!',
          showConfirmButton:false, timer:2200
        });

        setTimeout(() => window.location.href = 'active_projects.php', 2200);
      })
      .catch(() => {
        btnEl.disabled = false;
        Swal.fire('Server error', 'Please try again.', 'error');
      });
    });
  }
</script>
</body>
</html>
