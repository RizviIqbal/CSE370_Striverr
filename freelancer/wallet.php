<?php
// freelancer/wallet.php
session_start();
include('../includes/auth_check.php');
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'freelancer') {
  header("Location: ../auth/login.php"); exit();
}

$freelancer_id = (int)$_SESSION['user_id'];

/* ---------- helpers ---------- */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- totals (using your exact schema) ---------- */
/*
payments:
- payment_id (PK)
- project_id, milestone_id
- client_id, freelancer_id
- amount_cents INT, currency VARCHAR(10), status ENUM('pending','escrowed','released')
- created_at DATETIME, released_at DATETIME NULL
- amount DECIMAL(10,2)
*/
$currency = 'USD';

$SUM = $conn->prepare("
  SELECT
    COALESCE(SUM(CASE WHEN status='pending'  THEN COALESCE(amount, amount) END),0)   AS t_pending,
    COALESCE(SUM(CASE WHEN status='escrowed' THEN COALESCE(amount, amount) END),0)   AS t_escrowed,
    COALESCE(SUM(CASE WHEN status='released' THEN COALESCE(amount, amount) END),0)   AS t_released,
    MAX(NULLIF(currency,'')) AS c_guess
  FROM payments
  WHERE freelancer_id = ?
");
$SUM->bind_param("i", $freelancer_id);
$SUM->execute();
$SUM->bind_result($tPending, $tEscrowed, $tReleased, $cGuess);
$SUM->fetch();
$SUM->close();

$currency = $cGuess ?: 'USD';

/* this month = released in current month based on released_at */
$MONTH = $conn->prepare("
  SELECT COALESCE(SUM(COALESCE(amount, amount)),0)
  FROM payments
  WHERE freelancer_id = ?
    AND status='released'
    AND released_at IS NOT NULL
    AND DATE_FORMAT(released_at,'%Y-%m') = DATE_FORMAT(CURRENT_DATE,'%Y-%m')
");
$MONTH->bind_param("i", $freelancer_id);
$MONTH->execute();
$MONTH->bind_result($thisMonthReleased);
$MONTH->fetch();
$MONTH->close();

$totals = [
  'pending'    => (float)$tPending,
  'escrowed'   => (float)$tEscrowed,
  'released'   => (float)$tReleased,
  'available'  => (float)$tReleased,                      // available = released (mock flow)
  'toBePaid'   => (float)$tPending + (float)$tEscrowed,   // not yet released
  'thisMonth'  => (float)$thisMonthReleased,
];

/* ---------- recent transactions ---------- */
$rows = [];
$TX = $conn->prepare("
  SELECT 
    p.payment_id, p.project_id, p.milestone_id,
    COALESCE(p.amount, p.amount) AS amt,
    COALESCE(NULLIF(p.currency,''), 'USD') AS currency,
    p.status, p.created_at, p.released_at,
    pr.title AS project_title
  FROM payments p
  LEFT JOIN projects pr ON pr.project_id = p.project_id
  WHERE p.freelancer_id = ?
  ORDER BY p.created_at DESC
  LIMIT 100
");
$TX->bind_param("i", $freelancer_id);
$TX->execute();
$rows = $TX->get_result()->fetch_all(MYSQLI_ASSOC);
$TX->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Striverr · Wallet</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --bg:#0b1220; --panel:#101b2d; --panel2:#0d1626; --line:rgba(255,255,255,.08);
  --text:#eaf2ff; --muted:#9fb3c8; --accent:#00ffc3; --accent2:#00bfff;
  --ok:#22c55e; --warn:#f59e0b; --esc:#38bdf8;
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
.header{display:flex;justify-content:space-between;align-items:center;margin:0 auto 16px;max-width:1200px}
.brand{font-weight:800;letter-spacing:.3px;display:flex;align-items:center;gap:10px}
.brand i{color:var(--accent)}
.back{display:inline-flex;gap:10px;align-items:center;padding:10px 14px;background:#0e1727;border:1px solid var(--line);
  color:var(--text);border-radius:12px;text-decoration:none;font-weight:600}
.wrap{max-width:1200px;margin:0 auto;display:grid;gap:18px;grid-template-columns:1.35fr .65fr}
@media (max-width: 1020px){ .wrap{grid-template-columns:1fr} }
.card{background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));border:1px solid var(--line);
  border-radius:16px;box-shadow:0 12px 26px rgba(0,0,0,.35);backdrop-filter: blur(10px)}
.section{padding:18px}
.title{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.title h2{margin:0;font-size:18px}

/* KPIs */
.kpis{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
.kpi{border:1px solid var(--line);border-radius:14px;padding:14px;background:rgba(255,255,255,.02)}
.kpi .label{color:var(--muted);font-size:12px}
.kpi .value{font-weight:900;font-size:22px}
.subkpis{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:10px}
.pill{padding:6px 10px;border-radius:999px;border:1px solid var(--line);font-weight:700;font-size:12px}
.pill.pend{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.35);color:#ffe3b0}
.pill.esc{background:rgba(56,189,248,.12);border-color:rgba(56,189,248,.35);color:#bfe8ff}
.pill.avl{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.35);color:#c8ffd9}

/* Filters */
.filters{display:flex;gap:10px;flex-wrap:wrap;margin-top:6px}
.input,.select{
  background:#0e1727;border:1px solid var(--line);color:var(--text);border-radius:10px;padding:10px 12px;outline:none
}

/* Table-like list */
.table{width:100%;border-collapse:separate;border-spacing:0 10px}
.tr{display:grid;grid-template-columns:90px 1fr 140px 140px 150px;gap:10px;align-items:center;
  background:rgba(255,255,255,.02);border:1px solid var(--line);border-radius:12px;padding:12px}
.th{color:var(--muted);font-size:12px}
.badge{padding:6px 10px;border-radius:999px;font-size:12px;font-weight:800;display:inline-block}
.badge.pending{background:#2a1d06;color:#ffd79a;border:1px solid #3d2a09}
.badge.escrowed{background:#08283c;color:#aee4ff;border:1px solid #0f3f5e}
.badge.released{background:#082b1c;color:#a8ffc9;border:1px solid #0f4b31}
.empty{border:1px dashed var(--line);border-radius:12px;padding:18px;text-align:center;color:var(--muted)}
.small{color:var(--muted);font-size:12px}
.money{font-weight:800}
.right{justify-self:end;text-align:right}
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
          <select id="statusFilter" class="select" aria-label="Filter by status">
            <option value="">All statuses</option>
            <option value="released">Released</option>
            <option value="escrowed">Escrowed</option>
            <option value="pending">Pending</option>
          </select>
          <input id="searchBox" class="input" type="text" placeholder="Search by project title…">
        </div>

        <?php if (empty($rows)): ?>
          <div class="empty" style="margin-top:12px">No payments yet.</div>
        <?php else: ?>
          <div id="list" style="display:grid;gap:10px;margin-top:12px">
            <div class="tr th" style="background:transparent;border:none;padding:0">
              <div>#ID</div>
              <div>Project / Milestone</div>
              <div class="right">Amount</div>
              <div>Status</div>
              <div class="right">Date</div>
            </div>
            <?php foreach ($rows as $r):
              $id     = (int)$r['payment_id'];
              $proj   = $r['project_title'] ?: ('Project #'.(int)$r['project_id']);
              $msid   = (int)$r['milestone_id'];
              $amt    = (float)$r['amt'];
              $cur    = (string)$r['currency'];
              $st     = strtolower((string)$r['status']);
              $dateToShow = $st==='released'
                ? ($r['released_at'] ? date('M j, Y · g:i A', strtotime($r['released_at'])) : date('M j, Y · g:i A', strtotime($r['created_at'])))
                : date('M j, Y · g:i A', strtotime($r['created_at']));
              $badge  = '<span class="badge pending">Pending</span>';
              if ($st==='escrowed') $badge = '<span class="badge escrowed">Escrowed</span>';
              if ($st==='released') $badge = '<span class="badge released">Released</span>';
            ?>
              <div class="tr row" data-status="<?= e($st) ?>" data-title="<?= e(mb_strtolower($proj)) ?>">
                <div>#<?= $id ?></div>
                <div>
                  <div style="font-weight:700"><?= e($proj) ?></div>
                  <div class="small">Milestone #<?= $msid ?></div>
                </div>
                <div class="right money"><?= e($cur) ?> <?= number_format($amt,2) ?></div>
                <div><?= $badge ?></div>
                <div class="right small"><?= e($dateToShow) ?></div>
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
            <div class="label">Available</div>
            <div class="value"><?= e($currency) ?> <?= number_format($totals['available'], 2) ?></div>
            <div class="small">Money released by clients (withdrawable in this mock flow).</div>
          </div>
          <div class="kpi">
            <div class="label">To be paid</div>
            <div class="value"><?= e($currency) ?> <?= number_format($totals['toBePaid'], 2) ?></div>
            <div class="small">Pending + Escrowed (not released yet).</div>
          </div>
        </div>

        <div class="subkpis">
          <div class="pill pend">Pending: <?= e($currency) ?> <?= number_format($totals['pending'],2) ?></div>
          <div class="pill esc">In Escrow: <?= e($currency) ?> <?= number_format($totals['escrowed'],2) ?></div>
          <div class="pill avl">This Month (released): <?= e($currency) ?> <?= number_format($totals['thisMonth'],2) ?></div>
        </div>

        <hr style="border-color:var(--line);margin:14px 0">
        <div class="small" style="opacity:.9">
          <strong>Flow:</strong> <em>Pending</em> → <em>Escrowed</em> (client funds allocated) → <em>Released</em> (shows as Available here).
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
