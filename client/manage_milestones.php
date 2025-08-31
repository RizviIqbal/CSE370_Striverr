<?php
session_start();
include('../includes/auth_check.php');
include('../includes/db_connect.php');

if (!isset($_GET['project_id'])) {
    echo "<script>alert('Project ID missing!'); window.location.href='dashboard.php';</script>";
    exit;
}

$project_id = intval($_GET['project_id']);

// Handle add milestone
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_milestone'])) {
    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $amount = floatval($_POST['amount']);
    $deadline = $_POST['deadline'];

    $stmt = $conn->prepare("INSERT INTO milestones (project_id, title, description, amount, deadline, status) VALUES (?, ?, ?, ?, ?, 'pending')");
    $stmt->bind_param("issds", $project_id, $title, $description, $amount, $deadline);
    $stmt->execute();
}

// Handle edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id'])) {
    $id = intval($_POST['edit_id']);
    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $amount = floatval($_POST['amount']);
    $deadline = $_POST['deadline'];

    $stmt = $conn->prepare("UPDATE milestones SET title=?, description=?, amount=?, deadline=? WHERE milestone_id=? AND project_id=?");
    $stmt->bind_param("ssdssi", $title, $description, $amount, $deadline, $id, $project_id);
    $stmt->execute();
}

// Approve/Reject milestone
if (isset($_GET['approve']) || isset($_GET['reject'])) {
    $milestone_id = isset($_GET['approve']) ? intval($_GET['approve']) : intval($_GET['reject']);
    $status = isset($_GET['approve']) ? 'approved' : 'rejected';
    mysqli_query($conn, "UPDATE milestones SET status='$status' WHERE milestone_id=$milestone_id AND project_id=$project_id");
    header("Location: manage_milestones.php?project_id=$project_id");
    exit;
}

// Delete milestone
if (isset($_GET['delete'])) {
    $milestone_id = intval($_GET['delete']);
    mysqli_query($conn, "DELETE FROM milestones WHERE milestone_id=$milestone_id AND project_id=$project_id");
    header("Location: manage_milestones.php?project_id=$project_id");
    exit;
}

// Get milestones
$milestones_result = mysqli_query($conn, "SELECT * FROM milestones WHERE project_id = $project_id ORDER BY created_at DESC");
$milestones = mysqli_fetch_all($milestones_result, MYSQLI_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Milestones | Striverr</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    body {
      font-family: 'Poppins', sans-serif;
      background: linear-gradient(-45deg, #0f2027, #203a43, #2c5364);
      background-size: 400% 400%;
      animation: gradient 15s ease infinite;
      color: white;
      padding: 40px 20px;
    }
    @keyframes gradient {
      0% {background-position: 0% 50%;}
      50% {background-position: 100% 50%;}
      100% {background-position: 0% 50%;}
    }
    .container {
      max-width: 950px;
      margin: auto;
      background: rgba(255,255,255,0.07);
      border-radius: 16px;
      padding: 30px;
    }
    .btn-glow {
      background: linear-gradient(to right, #00ffc3, #00aaff);
      color: black;
      font-weight: bold;
      border: none;
      box-shadow: 0 0 10px rgba(0,255,255,0.3);
    }
    .btn-glow:hover {
      transform: scale(1.05);
      box-shadow: 0 0 20px rgba(0,255,255,0.5);
    }
    .status {
      font-weight: 600;
      padding: 4px 12px;
      border-radius: 20px;
      display: inline-block;
    }
    .status.pending { background-color: orange; color: black; }
    .status.approved { background-color: #00ffcc; color: black; }
    .status.rejected { background-color: #ff4d4d; color: white; }
    .milestone-box {
      background: rgba(255,255,255,0.04);
      border: 1px solid rgba(0,255,195,0.15);
      border-radius: 12px;
      padding: 20px;
      margin-bottom: 20px;
    }
  </style>
</head>
<body>
<div class="container">
  <h2 class="text-center mb-4">📌 Manage Milestones</h2>
  <form method="POST" class="mb-4">
    <div class="row g-3">
      <div class="col-md-4">
        <input type="text" name="title" class="form-control" placeholder="Title" required>
      </div>
      <div class="col-md-3">
        <input type="number" name="amount" class="form-control" placeholder="Amount" required>
      </div>
      <div class="col-md-3">
        <input type="date" name="deadline" class="form-control" required>
      </div>
      <div class="col-md-12">
        <textarea name="description" class="form-control" placeholder="Description" required></textarea>
      </div>
      <div class="col-md-12 text-end">
        <button type="submit" name="add_milestone" class="btn btn-glow">➕ Add Milestone</button>
      </div>
    </div>
  </form>

  <?php foreach ($milestones as $m): ?>
    <div class="milestone-box">
      <form method="POST" class="mb-2">
        <input type="hidden" name="edit_id" value="<?= $m['milestone_id'] ?>">
        <div class="row g-2">
          <div class="col-md-3">
            <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($m['title']) ?>" required>
          </div>
          <div class="col-md-2">
            <input type="number" name="amount" class="form-control" value="<?= $m['amount'] ?>" required>
          </div>
          <div class="col-md-2">
            <input type="date" name="deadline" class="form-control" value="<?= date('Y-m-d', strtotime($m['deadline'])) ?>" required>
          </div>
          <div class="col-md-4">
            <textarea name="description" class="form-control" required><?= htmlspecialchars($m['description']) ?></textarea>
          </div>
          <div class="col-md-1 d-grid">
            <button class="btn btn-sm btn-outline-info">💾</button>
          </div>
        </div>
      </form>

      <div class="mt-2">
        <span class="status <?= $m['status'] ?>">Status: <?= ucfirst($m['status']) ?></span>
        <?php if ($m['status'] === 'pending'): ?>
          <a href="?project_id=<?= $project_id ?>&approve=<?= $m['milestone_id'] ?>" class="btn btn-success btn-sm ms-2">✅ Approve</a>
          <a href="?project_id=<?= $project_id ?>&reject=<?= $m['milestone_id'] ?>" class="btn btn-warning btn-sm">❌ Reject</a>
        <?php endif; ?>
        <a href="?project_id=<?= $project_id ?>&delete=<?= $m['milestone_id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure to delete?')">🗑 Delete</a>

        <?php
        $s = $conn->query("SELECT * FROM submissions WHERE milestone_id = {$m['milestone_id']} ORDER BY submission_date DESC LIMIT 1")->fetch_assoc();
        if ($s): ?>
          <div class="mt-3">
            <strong>📎 Latest Submission:</strong> <?= htmlspecialchars($s['comments']) ?><br>
            <a href="../uploads/<?= $s['work_file'] ?>" class="btn btn-sm btn-outline-light mt-1" target="_blank">⬇ Download</a>
            <span class="badge bg-<?= $s['status'] === 'accepted' ? 'success' : ($s['status'] === 'rejected' ? 'danger' : 'secondary') ?> ms-2"><?= ucfirst($s['status']) ?></span>
          </div>
        <?php else: ?>
          <p class="mt-2">🕓 Waiting for submission...</p>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="text-center mt-4">
    <a href="active_projects.php" class="btn btn-outline-light">🔙 Back to Projects</a>
  </div>
</div>
</body>
</html>
