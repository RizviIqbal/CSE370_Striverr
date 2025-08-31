<?php

session_start();
header('Content-Type: application/json');

include('../includes/auth_check.php');
include('../includes/db_connect.php');

$out = fn($ok, $msg, $extra = []) =>
  die(json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra), JSON_UNESCAPED_UNICODE));

// ---------- Auth + role ----------
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    $out(false, 'Unauthorized.');
}

$client_id = (int)$_SESSION['user_id'];

// ---------- CSRF ----------
if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf'])) {
    $out(false, 'Security check failed.');
}

// ---------- Inputs ----------
$project_id    = isset($_POST['project_id'])    ? (int)$_POST['project_id']    : 0;
$freelancer_id = isset($_POST['freelancer_id']) ? (int)$_POST['freelancer_id'] : 0;

if ($project_id <= 0 || $freelancer_id <= 0) {
    $out(false, 'Missing project or freelancer ID.');
}

// ---------- Transaction + row locks ----------
try {
    $conn->begin_transaction();

    // Lock project row (ownership + status/hired info)
    $stmt = $conn->prepare("SELECT client_id, status, hired_freelancer_id FROM projects WHERE project_id = ? FOR UPDATE");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $stmt->bind_result($owner_id, $proj_status, $already_hired_id);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        $conn->rollback();
        $out(false, 'Project not found.');
    }
    if ((int)$owner_id !== $client_id) {
        $conn->rollback();
        $out(false, 'Unauthorized access to this project.');
    }
    if (!empty($already_hired_id)) {
        $conn->rollback();
        $out(false, 'A freelancer is already hired for this project.');
    }

    // Verify the freelancer actually applied to this project (and lock that application row)
    $stmt = $conn->prepare("SELECT status FROM applications WHERE project_id = ? AND freelancer_id = ? FOR UPDATE");
    $stmt->bind_param("ii", $project_id, $freelancer_id);
    $stmt->execute();
    $stmt->bind_result($app_status);
    $applied = $stmt->fetch();
    $stmt->close();

    if (!$applied) {
        $conn->rollback();
        $out(false, 'This freelancer has not applied to the project.');
    }

    // Accept chosen freelancer
    $stmt = $conn->prepare("UPDATE applications SET status = 'accepted' WHERE project_id = ? AND freelancer_id = ?");
    $stmt->bind_param("ii", $project_id, $freelancer_id);
    $stmt->execute();
    if ($stmt->affected_rows === 0 && $app_status !== 'accepted') {
        $stmt->close();
        $conn->rollback();
        $out(false, 'Failed to accept the selected freelancer.');
    }
    $stmt->close();

    // Reject all other applicants for the same project
    $stmt = $conn->prepare("UPDATE applications SET status = 'rejected' WHERE project_id = ? AND freelancer_id <> ?");
    $stmt->bind_param("ii", $project_id, $freelancer_id);
    $stmt->execute();
    $stmt->close();

    // Update project
    $stmt = $conn->prepare("UPDATE projects SET hired_freelancer_id = ?, status = 'active', updated_at = NOW() WHERE project_id = ?");
    $stmt->bind_param("ii", $freelancer_id, $project_id);
    $stmt->execute();
    if ($stmt->affected_rows === 0) {
        $stmt->close();
        $conn->rollback();
        $out(false, 'Failed to activate the project.');
    }
    $stmt->close();
    // Notify the hired freelancer
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, message, link) 
        VALUES (?, ?, ?)
    ");
    $msg = "Congratulations! You have been hired for project #{$project_id}.";
    $link = "freelancer/active_projects.php";
    $stmt->bind_param("iss", $freelancer_id, $msg, $link);
    $stmt->execute();
    $stmt->close();

    // Notify the client (optional)
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, message, link) 
        VALUES (?, ?, ?)
    ");
    $msg = "You have successfully hired a freelancer for project #{$project_id}.";
    $link = "client/active_projects.php";
    $stmt->bind_param("iss", $client_id, $msg, $link);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    // Success
    $out(true, 'Freelancer hired successfully!', [
        'hiredFreelancerId' => $freelancer_id
    ]);

} catch (Throwable $e) {
    if ($conn->errno) {
        $conn->rollback();
    }
    // Log $e->getMessage() if you have a logger
    $out(false, 'Server error. Please try again.');
}
