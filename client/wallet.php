<?php
session_start();
include('../includes/auth_check.php');
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'client') {
  header("Location: ../auth/login.php"); exit();
}

$client_id = (int)$_SESSION['user_id'];

/* ---------------- Helpers ---------------- */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------------- Totals ---------------- */
$currency = 'USD';

/*
 Schema we support (per your screenshot):
  payment_id, project_id, milestone_id, client_id, freelancer_id,
  amount_cents, currency, status ENUM('pending','escrowed','released'),
  created_at, released_at, amount
*/
$Q = $conn->prepare("
  SELECT
    COALESCE(SUM(CASE WHEN status='pending'  THEN COALESCE(amount, amount) END),0)  AS t_pending,
    COALESCE(SUM(CASE WHEN status='escrowed' THEN COALESCE(amount, amount) END),0)  AS t_escrowed,
    COALESCE(SUM(CASE WHEN status='released' THEN COALESCE(amount, amount) END),0)  AS t_released,
    COUNT(*) AS t_count,
    MAX(NULLIF(currency,'')) AS ccy
  FROM payments
  WHERE client_id = ?
");
$Q->bind_param("i", $client_id);
$Q->execute();
$Q->bind_result($tPending,$tEscrowed,$tReleased,$tCount,$ccy);
$Q->fetch(); $Q->close();

$currency = $ccy ?: 'USD';

/* This month released (client spend this month) */
$M = $conn->prepare("
  SELECT COALESCE(SUM(COALESCE(amount, amount/100)),0)
  FROM payments
  WHERE client_id = ?
    AND status='released'
    AND created_at >= DATE_FORMAT(CURRENT_DATE,'%Y-%m-01')
");
$M->bind_param("i",$client_id);
$M->execute(); $M->bind_result($thisMonthReleased); $M->fetch(); $M->close();

$totals = [
  'pending'   => (float)$tPending,
  'escrowed'  => (float)$tEscrowed,
  'released'  => (float)$tReleased,
  'thisMonth' => (float)$thisMonthReleased,
  'count'     => (int)$tCount
];

/* ---------------- Transactions (last 80) ---------------- */
$rows = [];
$L = $conn->prepare("
  SELECT
    p.payment_id, p.project_id, p.milestone_id,
    p.client_id, p.freelancer_id,
    COALESCE(p.amount, p.amount/100) AS amt,
    COALESCE(NULLIF(p.currency,''), 'USD') AS currency,
    p.status, p.created_at, p.released_at,
    pr.title AS project_title,
    u.name   AS freelancer_name
  FROM payments p
  LEFT JOIN projects pr ON pr.project_id = p.project_id
  LEFT JOIN users u ON u.user_id = p.freelancer_id
  WHERE p.client_id = ?
  ORDER BY p.created_at DESC
  LIMIT 80
");
$L->bind_param("i", $client_id);
$L->execute();
$rows = $L->get_result()->fetch_all(MYSQLI_ASSOC);
$L->close();

/* ---------------- Profile (avatar/name for header) ---------------- */
$client_name = 'Client';
$profile_image = 'default.png';
if ($st = $conn->prepare("SELECT name, COALESCE(NULLIF(profile_image,''),'default.png') FROM users WHERE user_id = ? LIMIT 1")){
  $st->bind_param("i",$client_id); $st->execute(); $st->bind_result($client_name,$profile_image); $st->fetch(); $st->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Striverr · Client Wallet</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --bg:#0b1220; --panel:#101b2d; --panel2:#0d1626; --line:rgba(255,255,255,.10);
  --text:#eaf2ff; --muted:#b6c7da; --accent:#00ffc3; --accent2:#00bfff;
  --ok:#22c55e; --warn:#f59e0b; --bad:#ef4444;
}
*{box-sizing:border-box}
body{
  margin:0; font-family:Poppins,system-ui,Segoe UI,Arial,sans-serif; color:var(--text);
  background:
    radial-gradient(1200px 600px at 80% -10%, rgba(0,255,195,.12), transparent),
    radial-gradient(900px 500px at 10% 10%, rgba(0,191,255,.10), transparent),
    linear-gradient(180deg,#0a111e 0%, #0b1220 100%);
  min-height:100vh; padding:26px;
}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;max-width:1200px;margin-left:auto;margin-right:auto}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;letter-spacing:.3px}
.brand i{color:var(--accent)}
.back{display:inline-flex;gap:10px;align-items:center;padding:10px 14px;background:#0e1727;border:1px solid var(--line);color:var(--text);
  border-radius:12px;text-decoration:none;font-weight:600}
.wrap{max-width:1200px;margin:0 auto;display:grid;gap:18px;grid-template-columns:1.3fr .7fr}
@media (max-width: 1020px){ .wrap{grid-template-columns:1fr} }
.card{background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));border:1px solid var(--line);
  border-radius:16px;box-shadow:0 12px 26px rgba(0,0,0,.35);backdrop-filter: blur(10px)}
.section{padding:18px}
.title{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.title h2{margin:0;font-size:18px}

/* KPIs */
.kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.kpi{border:1px solid var(--line);border-radius:14px;padding:14px;background:rgba(255,255,255,.02)}
.kpi .label{color:var(--muted);font-size:12px}
.kpi .value{font-weight:900;font-size:22px}
.pill{padding:6px 10px;border-radius:999px;border:1px solid var(--line);font-weight:700}
.pill.pend{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.35);color:#ffedc7}
.pill.esc{background:rgba(0,191,255,.12);border-color:rgba(0,191,255,.35);color:#aee4ff}
.pill.rel{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.35);color:#c8ffd9}

/* Filters */
.filters{display:flex;gap:10px;flex-wrap:wrap;margin-top:6px}
.input,.select{background:#0e1727;border:1px solid var(--line);color:var(--text);border-radius:10px;padding:10px 12px;outline:none}

/* Table */
.table{width:100%;border-collapse:separate;border-spacing:0 10px}
.th{color:var(--muted);font-size:12px}
.tr{display:grid;grid-template-columns:80px 1fr 180px 130px 140px;gap:10px;align-items:center;background:rgba(255,255,255,.02);
  border:1px solid var(--line);border-radius:12px;padding:12px}
.badge{padding:6px 10px;border-radius:999px;font-size:12px;font-weight:800;display:inline-block}
.badge.pending{background:#2a1d06;color:#ffd79a;border:1px solid #3d2a09}
.badge.escrowed{background:#08283c;color:#aee4ff;border:1px solid #0f3f5e}
.badge.released{background:#082b1c;color:#a8ffc9;border:1px solid #0f4b31}
.small{color:var(--muted);font-size:12px}
.right{text-align:right}
.money{font-weight:800}
.avatar{width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid rgba(127,221,255,.5)}
</style>
</head>
<body>
  <div class="header">
    <div class="brand"><i class="fa fa-wallet"></i> Striverr Wallet</div>
    <a class="back" href="dashboard.php"><i class="fa fa-arrow-left"></i> Dashboard</a>
  </div>

  <div class="wrap">
    <!-- LEFT: Transactions -->
    <div class="card">
      <div class="section">
        <div class="title"><h2>Transactions</h2></div>
        <div class="filters">
          <select id="statusFilter" class="select">
            <option value="">All statuses</option>
            <option value="released">Released</option>
            <option value="escrowed">Escrowed</option>
            <option value="pending">Pending</option>
          </select>
          <input id="searchBox" class="input" type="text" placeholder="Search by project title…">
        </div>

        <?php if (empty($rows)): ?>
          <div class="section small" style="opacity:.9">No payments yet.</div>
        <?php else: ?>
          <div id="list" style="display:grid;gap:10px;margin-top:12px">
            <div class="tr th" style="background:transparent;border:none;padding:0">
              <div>#ID</div><div>Project / Freelancer</div><div class="right">Amount</div><div>Status</div><div class="right">Date</div>
            </div>
            <?php foreach ($rows as $r):
              $id  = (int)$r['payment_id'];
              $ttl = (string)($r['project_title'] ?? ('Project #'.(int)$r['project_id']));
              $amt = (float)$r['amt'];
              $cur = (string)$r['currency'];
              $st  = strtolower((string)$r['status']);
              $dt  = $r['created_at'] ? date('M j, Y · g:i A', strtotime($r['created_at'])) : '—';
              $freelancer = $r['freelancer_name'] ? '· '. $r['freelancer_name'] : '';

              $badge = '<span class="badge pending">Pending</span>';
              if ($st==='escrowed') $badge = '<span class="badge escrowed">Escrowed</span>';
              if ($st==='released') $badge = '<span class="badge released">Released</span>';
            ?>
              <div class="tr row" data-status="<?= e($st) ?>" data-title="<?= e(mb_strtolower($ttl)) ?>">
                <div>#<?= $id ?></div>
                <div>
                  <div style="font-weight:700"><?= e($ttl) ?></div>
                  <div class="small">Milestone #<?= (int)$r['milestone_id'] ?> <?= e($freelancer) ?></div>
                </div>
                <div class="right money"><?= e($cur) ?> <?= number_format($amt, 2) ?></div>
                <div><?= $badge ?></div>
                <div class="right small"><?= e($dt) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- RIGHT: Summary -->
    <div class="card">
      <div class="section">
        <div class="title"><h2>Overview</h2></div>
        <div class="kpis">
          <div class="kpi">
            <div class="label">Pending (not yet escrowed)</div>
            <div class="value"><?= e($currency) ?> <?= number_format($totals['pending'], 2) ?></div>
          </div>
          <div class="kpi">
            <div class="label">In Escrow</div>
            <div class="value"><?= e($currency) ?> <?= number_format($totals['escrowed'], 2) ?></div>
          </div>
          <div class="kpi">
            <div class="label">This Month · Released</div>
            <div class="value"><?= e($currency) ?> <?= number_format($totals['thisMonth'], 2) ?></div>
          </div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
          <span class="pill pend">Pending: <?= number_format($totals['pending'],2) ?></span>
          <span class="pill esc">Escrowed: <?= number_format($totals['escrowed'],2) ?></span>
          <span class="pill rel">Lifetime Released: <?= number_format($totals['released'],2) ?></span>
        </div>

        <div class="small" style="margin-top:10px;opacity:.9">Transactions: <strong><?= (int)$totals['count'] ?></strong></div>

        <hr style="border-color:var(--line);margin:14px 0">
        <div class="small" style="opacity:.85">
          <strong>Flow:</strong> <em>Pending</em> (created) → <em>Escrowed</em> (funds allocated) → <em>Released</em> (paid out to freelancer).
          Manage each milestone’s payments inside its project page.
        </div>
      </div>

      <div class="section" style="border-top:1px solid var(--line)">
        <div class="title"><h2>Shortcuts</h2></div>
        <div style="display:grid;gap:10px">
          <a class="back" style="text-decoration:none" href="active_projects.php"><i class="fa fa-diagram-project"></i> Active Projects</a>
          <a class="back" style="text-decoration:none" href="my_projects.php"><i class="fa fa-list"></i> My Projects</a>
        </div>
      </div>
    </div>
  </div>

<script>
const list = document.getElementById('list');
const searchBox = document.getElementById('searchBox');
const statusFilter = document.getElementById('statusFilter');

function applyFilters(){
  if(!list) return;
  const q = (searchBox.value || '').trim().toLowerCase();
  const s = (statusFilter.value || '').toLowerCase();

  [...list.querySelectorAll('.row')].forEach(row=>{
    const st = (row.getAttribute('data-status') || '').toLowerCase();
    const title = (row.getAttribute('data-title') || '');
    const okS = s === '' || st === s;
    const okQ = q === '' || title.includes(q);
    row.style.display = (okS && okQ) ? '' : 'none';
  });
}
searchBox?.addEventListener('input', applyFilters);
statusFilter?.addEventListener('change', applyFilters);
</script>
</body>
</html>
