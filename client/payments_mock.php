<?php
// client/payments_mock.php
session_start();
include('../includes/auth_check.php');
include('../includes/db_connect.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'client') {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

$client_id    = (int)$_SESSION['user_id'];
$action       = $_POST['action'] ?? '';
$project_id   = (int)($_POST['project_id'] ?? 0);
$milestone_id = (int)($_POST['milestone_id'] ?? 0);

if ($project_id <= 0 || $milestone_id <= 0) {
    echo json_encode(['success'=>false,'message'=>'Bad ids']);
    exit;
}

/* ---------- Guard: project owner + milestone belongs to that project ---------- */
$chk = $conn->prepare("
    SELECT p.client_id, p.hired_freelancer_id AS freelancer_id,
           COALESCE(m.amount,0) AS amt
    FROM projects p
    JOIN milestones m ON m.project_id = p.project_id
    WHERE m.milestone_id=? AND m.project_id=? AND p.project_id=?
    LIMIT 1
");
$chk->bind_param("iii", $milestone_id, $project_id, $project_id);
$chk->execute();
$meta = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$meta || (int)$meta['client_id'] !== $client_id) {
    echo json_encode(['success'=>false,'message'=>'Forbidden']);
    exit;
}

$freelancer_id = (int)$meta['freelancer_id'];
if ($freelancer_id <= 0) {
    echo json_encode(['success'=>false,'message'=>'No freelancer hired for this project']);
    exit;
}

$amount = (float)$meta['amt'];

/* ---------- helpers ---------- */
function fetchRow(mysqli $conn, int $project_id, int $milestone_id){
    $q = $conn->prepare("
        SELECT payment_id, project_id, milestone_id, client_id, freelancer_id,
               amount, currency, status, created_at, released_at
        FROM payments
        WHERE project_id=? AND milestone_id=?
        LIMIT 1
    ");
    $q->bind_param("ii", $project_id, $milestone_id);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    return $row ?: null;
}

function ok($row){
    echo json_encode(['success'=>true,'data'=>$row]);
    exit;
}

function fail($msg){
    echo json_encode(['success'=>false,'message'=>$msg]);
    exit;
}

/* ---------- ensure a payments row exists or create it ---------- */
function ensurePaymentRow(mysqli $conn, int $project_id, int $milestone_id, int $client_id, int $freelancer_id, float $amount, string $status='pending'){
    $row = fetchRow($conn, $project_id, $milestone_id);
    if ($row) {
        $u = $conn->prepare("
            UPDATE payments
               SET amount=?, currency='USD', client_id=?, freelancer_id=?
             WHERE project_id=? AND milestone_id=?
             LIMIT 1
        ");
        $u->bind_param("diiii", $amount, $client_id, $freelancer_id, $project_id, $milestone_id);
        $u->execute(); $u->close();
        return fetchRow($conn, $project_id, $milestone_id);
    } else {
        $ins = $conn->prepare("
            INSERT INTO payments (project_id, milestone_id, client_id, freelancer_id,
                                  amount, currency, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'USD', ?, NOW())
        ");
        $ins->bind_param("iiiids", $project_id, $milestone_id, $client_id, $freelancer_id, $amount, $status);
        $ok = $ins->execute();
        $ins->close();
        if (!$ok) fail('Could not create payment row');
        return fetchRow($conn, $project_id, $milestone_id);
    }
}

/* ---------- Actions ---------- */
switch($action) {
    case 'ensure_row':
        ok(ensurePaymentRow($conn, $project_id, $milestone_id, $client_id, $freelancer_id, $amount));
        break;

    case 'fund_escrow':
        $row = ensurePaymentRow($conn, $project_id, $milestone_id, $client_id, $freelancer_id, $amount);
        if (in_array($row['status'], ['escrowed','released'])) ok($row);

        $u = $conn->prepare("
            UPDATE payments SET status='escrowed'
            WHERE project_id=? AND milestone_id=? AND status='pending'
            LIMIT 1
        ");
        $u->bind_param("ii", $project_id, $milestone_id);
        $u->execute(); $u->close();
        ok(fetchRow($conn, $project_id, $milestone_id));
        break;

    case 'release':
        $approved = $conn->prepare("
            SELECT 1 FROM milestones
            WHERE milestone_id=? AND project_id=? AND status IN ('approved','completed','done','released')
            LIMIT 1
        ");
        $approved->bind_param("ii", $milestone_id, $project_id);
        $approved->execute();
        $okApproved = (bool)$approved->get_result()->fetch_row();
        $approved->close();
        if (!$okApproved) fail('Approve milestone first');

        $row = ensurePaymentRow($conn, $project_id, $milestone_id, $client_id, $freelancer_id, $amount, 'escrowed');
        if ($row['status'] === 'released') ok($row);

        $u = $conn->prepare("
            UPDATE payments
               SET status='released', released_at=NOW(), amount=?, currency='USD'
             WHERE project_id=? AND milestone_id=? AND status='escrowed'
             LIMIT 1
        ");
        $u->bind_param("dii", $amount, $project_id, $milestone_id);
        $u->execute(); $u->close();

        ok(fetchRow($conn, $project_id, $milestone_id));
        break;

    default:
        fail('Unknown action');
}
