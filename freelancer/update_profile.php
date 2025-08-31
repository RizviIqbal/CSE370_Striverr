<?php
/* freelancer/update_profile.php */
session_start();
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'freelancer') {
  header("Location: ../auth/login.php"); exit();
}

$user_id = (int)$_SESSION['user_id'];

function cap($s, $len){ return mb_substr(trim((string)$s), 0, $len); }
function norm_csv_skills($csv, $max=15){
  $arr = array_filter(array_map('trim', explode(',', (string)$csv)));
  $arr = array_slice(array_unique($arr), 0, $max);
  $arr = array_map(function($t){
    $t = mb_substr($t, 0, 32);
    return preg_replace('~[^ \p{L}\p{N}\+\#\.\-\/]~u', '', $t);
  }, $arr);
  $arr = array_filter($arr);
  return implode(',', $arr);
}

/* fetch current avatar */
$cur = $conn->prepare("SELECT profile_image FROM users WHERE user_id=? LIMIT 1");
$cur->bind_param("i", $user_id);
$cur->execute();
$cur->bind_result($current_image);
$cur->fetch();
$cur->close();
$current_image = $current_image ?: 'freelancer.png';

/* collect input */
$name  = cap($_POST['name'] ?? '', 80);
$email = cap($_POST['email'] ?? '', 120); // read-only in UI but bound for safety
$phone = cap($_POST['phone'] ?? '', 30);
$bio   = cap($_POST['bio'] ?? '', 600);
$country = cap($_POST['country'] ?? '', 56);
$experience = cap($_POST['experience_level'] ?? '', 56);
$skillsCsv  = norm_csv_skills($_POST['skills'] ?? '');
$remove_avatar = isset($_POST['remove_avatar']) && $_POST['remove_avatar'] === '1';

$new_password = $_POST['password'] ?? '';
$hash = null;
if ($new_password !== '') {
  if (mb_strlen($new_password) < 8) {
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Password must be at least 8 characters.'];
    header("Location: edit_profile.php"); exit();
  }
  $hash = password_hash($new_password, PASSWORD_DEFAULT);
}

/* avatar upload */
$final_image = $current_image;
$images_dir  = __DIR__ . '/../includes/images';
if (!is_dir($images_dir)) @mkdir($images_dir, 0775, true);

if ($remove_avatar) {
  $final_image = 'freelancer.png';
} elseif (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
  if ($_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Image upload failed.'];
    header("Location: edit_profile.php"); exit();
  }
  if ((int)$_FILES['profile_image']['size'] > 3*1024*1024) {
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Image must be ≤ 3MB.'];
    header("Location: edit_profile.php"); exit();
  }
  $tmp = $_FILES['profile_image']['tmp_name'];
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime  = $finfo->file($tmp) ?: '';
  $map   = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
  if (!isset($map[$mime])) {
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Only JPG, PNG, or WEBP allowed.'];
    header("Location: edit_profile.php"); exit();
  }
  $newFile  = 'avatar_'.$user_id.'_'.bin2hex(random_bytes(6)).'.'.$map[$mime];
  $dest     = $images_dir . DIRECTORY_SEPARATOR . $newFile;
  if (!move_uploaded_file($tmp, $dest)) {
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Could not save uploaded file.'];
    header("Location: edit_profile.php"); exit();
  }
  $final_image = $newFile;

  if ($current_image && $current_image !== 'freelancer.png') {
    $old = $images_dir . DIRECTORY_SEPARATOR . basename($current_image);
    if (is_file($old)) @unlink($old);
  }
}

/* update */
if ($hash) {
  $sql = "UPDATE users SET
            name=?, phone=?, bio=?, skills=?, country=?, experience_level=?, profile_image=?, password=?
          WHERE user_id=? AND email=?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ssssssssis", $name,$phone,$bio,$skillsCsv,$country,$experience,$final_image,$hash,$user_id,$email);
} else {
  $sql = "UPDATE users SET
            name=?, phone=?, bio=?, skills=?, country=?, experience_level=?, profile_image=?
          WHERE user_id=? AND email=?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("sssssssis", $name,$phone,$bio,$skillsCsv,$country,$experience,$final_image,$user_id,$email);
}

$ok = $stmt->execute();
$stmt->close();

/* OPTIONAL: keep users.average_rating/users.rating_count synced if those columns exist
   (handy after you inserted a new review somewhere else). */
$haveAvg = $conn->query("SHOW COLUMNS FROM users LIKE 'average_rating'")->num_rows > 0;
$haveCnt = $conn->query("SHOW COLUMNS FROM users LIKE 'rating_count'")->num_rows > 0;
if ($ok && $haveAvg && $haveCnt && $conn->query("SHOW TABLES LIKE 'reviews'")->num_rows > 0){
  $r = $conn->prepare("SELECT COALESCE(AVG(rating),0), COUNT(*) FROM reviews WHERE freelancer_id=?");
  $r->bind_param("i",$user_id);
  $r->execute();
  $r->bind_result($avg, $cnt);
  $r->fetch(); $r->close();
  $avg = round((float)$avg, 2);
  $u = $conn->prepare("UPDATE users SET average_rating=?, rating_count=? WHERE user_id=?");
  $u->bind_param("dii", $avg, $cnt, $user_id);
  $u->execute(); $u->close();
}

$_SESSION['flash'] = $ok
  ? ['type'=>'success','msg'=>'Profile updated successfully.']
  : ['type'=>'error','msg'=>'Could not update your profile. Please try again.'];

header("Location: edit_profile.php");
exit;
