<?php
// auth/register.php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';

// ---------- CSRF ----------
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function json_out($arr) {
  header('Content-Type: application/json');
  echo json_encode($arr); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    json_out(['ok'=>false,'error'=>'Security token invalid. Please refresh and try again.']);
  }

  // --------- Inputs ---------
  $name  = trim($_POST['name'] ?? '');
  $email = strtolower(trim($_POST['email'] ?? ''));
  $password = $_POST['password'] ?? '';
  $role = $_POST['role'] ?? '';
  $phone = trim($_POST['phone'] ?? '');
  $country = trim($_POST['country'] ?? '');
  $experience_level = trim($_POST['experience_level'] ?? '');

  // --------- Validation ---------
  if (mb_strlen($name) < 2) json_out(['ok'=>false,'error'=>'Please enter your full name.']);
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['ok'=>false,'error'=>'Enter a valid email address.']);
  if (strlen($password) < 8) json_out(['ok'=>false,'error'=>'Password must be at least 8 characters.']);
  if (!in_array($role, ['freelancer','client'], true)) json_out(['ok'=>false,'error'=>'Choose a valid role.']);

  if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $phone)) {
    json_out(['ok'=>false,'error'=>'Phone format looks invalid.']);
  }
  if ($experience_level !== '' && !in_array($experience_level, ['Beginner','Intermediate','Expert'], true)) {
    $experience_level = '';
  }

  // --------- Duplicate email check ---------
  $chk = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
  $chk->bind_param("s", $email);
  $chk->execute(); $chk->store_result();
  if ($chk->num_rows > 0) { $chk->close(); json_out(['ok'=>false,'error'=>'This email is already registered. Try signing in.']); }
  $chk->close();

  // --------- Profile image (optional) — working pattern ---------
  // Save to ../includes/images and store *filename only* in DB.
  $profile_filename = null;
  if (!empty($_FILES['profile_image']['name'])) {
    if (!isset($_FILES['profile_image']['error']) || is_array($_FILES['profile_image']['error'])) {
      json_out(['ok'=>false,'error'=>'Invalid image upload parameters.']);
    }
    if ($_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
      json_out(['ok'=>false,'error'=>'Image upload failed (code '.$_FILES['profile_image']['error'].').']);
    }
    $maxBytes = 2 * 1024 * 1024;
    if ($_FILES['profile_image']['size'] > $maxBytes) {
      json_out(['ok'=>false,'error'=>'Image too large. Max 2MB.']);
    }
    $ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) {
      json_out(['ok'=>false,'error'=>'Allowed types: JPG, PNG, WEBP, GIF']);
    }
    // Ensure folder exists
    $includesDir = realpath(__DIR__ . '/../includes') ?: (__DIR__ . '/../includes');
    $imagesDirFs = $includesDir . '/images';
    if (!is_dir($imagesDirFs) && !@mkdir($imagesDirFs, 0775, true)) {
      json_out(['ok'=>false,'error'=>'Cannot create includes/images directory.']);
    }
    if (!is_writable($imagesDirFs)) {
      json_out(['ok'=>false,'error'=>'includes/images is not writable by PHP.']);
    }
    $profile_filename = 'u_'.time().'_'.bin2hex(random_bytes(3)).'.'.$ext;
    $destFs = $imagesDirFs . '/' . $profile_filename;

    if (!is_uploaded_file($_FILES['profile_image']['tmp_name'])) {
      json_out(['ok'=>false,'error'=>'Security check failed: temp file not found.']);
    }
    if (!move_uploaded_file($_FILES['profile_image']['tmp_name'], $destFs)) {
      json_out(['ok'=>false,'error'=>'Could not save image to disk. Check folder permissions.']);
    }
  }

  // --------- Insert ---------
  $hash = password_hash($password, PASSWORD_DEFAULT);

  $sql = "INSERT INTO users
    (name, email, password, role, phone, country, experience_level, profile_image, registration_date, created_at, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 'active')";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ssssssss", $name, $email, $hash, $role, $phone, $country, $experience_level, $profile_filename);

  if (!$stmt->execute()) {
    $err = $conn->error ?: 'Registration failed. Please try again.';
    $stmt->close(); json_out(['ok'=>false,'error'=>$err]);
  }
  $newId = $stmt->insert_id;
  $stmt->close();

  // --------- Auto-login & redirect ---------
  $_SESSION['user_id'] = $newId;
  $_SESSION['role'] = $role;
  $_SESSION['name'] = $name;
  $_SESSION['email'] = $email;

  $_SESSION['profile_image'] = $profile_filename ? "includes/images/$profile_filename" : null;

  $redirect = ($role === 'client')
    ? '../client/dashboard.php'
    : '../freelancer/dashboard.php';

  json_out(['ok'=>true,'redirect'=>$redirect]);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Create account | Striverr</title>

  <!-- SweetAlert2 + Confetti + Particles + Lottie -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.12.4/dist/sweetalert2.all.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/lottie-web@5.12.2/build/player/lottie.min.js"></script>

  <style>
    :root{
      --card-bg: rgba(12,16,28,0.55);
      --card-border: rgba(118,108,241,0.35);
      --txt:#d7defb; --muted:#93a0c2; --accent:#7a5bff; --accent2:#00d4ff; --error:#ff6b6b;
      --g1:#8b5cf6; --g2:#00d4ff; --g3:#5ef0d6;
    }
    *{box-sizing:border-box}
    html,body{min-height:100%;margin:0;background:#030611;color:var(--txt);font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
    body{overflow:auto} /* scrollable */
    .bg-wash:before,.bg-wash:after{content:"";position:fixed;inset:-20vmax;filter:blur(120px);opacity:.35;z-index:0;
      background:radial-gradient(40vmax 40vmax at 20% 10%, #5b67ff 0%, transparent 60%),
                 radial-gradient(40vmax 40vmax at 90% 90%, #00eaff 0%, transparent 60%),
                 radial-gradient(30vmax 30vmax at 10% 90%, #ff5ad6 0%, transparent 60%);
      pointer-events:none;
    }
    #particles-js{position:fixed;inset:0;z-index:0;opacity:.55;pointer-events:none}
    .shell{position:relative;z-index:1;max-width:1100px;margin:48px auto 60px;padding:0 20px}
    .card{
      display:grid;grid-template-columns:1.1fr .9fr;gap:0;
      background:linear-gradient(180deg,rgba(255,255,255,.03),rgba(255,255,255,.01)),var(--card-bg);
      border:1px solid var(--card-border); border-radius:24px;
      backdrop-filter: blur(18px) saturate(140%); box-shadow:0 10px 40px rgba(0,0,0,.55), inset 0 0 0 1px rgba(255,255,255,.04);
      overflow:hidden;
    }
    .left,.right{padding:44px 44px}
    .left{border-right:1px solid rgba(255,255,255,.06)}
    h1{margin:0 0 12px;font-size:34px;letter-spacing:.2px}
    .sub{color:var(--muted);font-size:16px;line-height:1.55;margin-bottom:20px}
    .field{margin:18px 0 4px}
    .label{font-size:14px;color:#9bb0da;margin-bottom:10px}
    .input{
      width:100%;height:54px;padding:0 16px;border-radius:14px;background:rgba(255,255,255,.03);
      border:1px solid rgba(255,255,255,.08);color:var(--txt);outline:none;font-size:15px;
      transition:border-color .2s, box-shadow .2s;
    }
    .input:focus{border-color:#7c6df8; box-shadow:0 0 0 3px rgba(124,109,248,.18)}
    .row{display:flex;gap:14px;flex-wrap:wrap}
    .row .field{flex:1;min-width:240px}
    .pw-wrap{position:relative}
    .pw-toggle{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:transparent;border:0;color:#b7c3e6;font-size:13px;cursor:pointer}
    .hint{font-size:13px;color:#8ea0c7;margin-top:8px}
    .error{color:var(--error);font-size:14px;margin-top:10px;display:none}
    .role-wrap{display:flex;gap:12px;margin-top:8px}
    .role-btn{flex:1;height:48px;border-radius:14px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.03);color:#cfe1ff;cursor:pointer;font-weight:700;font-size:14px}
    .role-btn.active{border-color:#7a5bff;box-shadow:0 0 0 3px rgba(122,91,255,.18) inset}
    .cta{margin-top:20px;height:56px;width:100%;border:0;border-radius:16px;cursor:pointer;font-weight:800;font-size:16px;
      background:linear-gradient(90deg,var(--accent),var(--accent2));color:#031022;box-shadow:0 8px 30px rgba(0,212,255,.25)}
    .terms{display:flex;align-items:center;gap:10px;margin-top:16px;font-size:14px;color:#9bb0da}
    .img-up{display:flex;gap:14px;align-items:center}
    .avatar{width:54px;height:54px;border-radius:50%;object-fit:cover;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.03)}
    .right h3{margin:8px 0 16px;font-size:26px}
    .bul{display:grid;gap:16px;margin-top:18px}
    .bul div{display:flex;gap:10px;align-items:flex-start;color:#a9b7dc;font-size:15px}
    .dot{width:8px;height:8px;background:#6ee7ff;border-radius:50%;margin-top:6px;box-shadow:0 0 12px #6ee7ff}
    @media (max-width: 980px){ .card{grid-template-columns:1fr} .right{display:none} }

    /* ===== Striverr animated logo (same as login) ===== */
    .brandRow{display:flex;align-items:center;gap:12px;margin-bottom:16px}
    .brandName{font-weight:900;font-size:22px;letter-spacing:.35px}
    .logoS{width:48px;height:48px;display:block}
    #spath{ --len: 1000; stroke-dasharray: var(--len); stroke-dashoffset: var(--len);
      animation: drawS 3.2s ease-in-out infinite, glowS 1.6s ease-in-out infinite; }
    @keyframes drawS{ 0%{stroke-dashoffset:var(--len)} 50%{stroke-dashoffset:0} 100%{stroke-dashoffset:var(--len)} }
    @keyframes glowS{
      0%,100%{ filter:drop-shadow(0 0 0 rgba(139,92,246,0)) drop-shadow(0 0 0 rgba(0,212,255,0)); stroke-width:12.5; }
      50%{ filter:drop-shadow(0 0 12px rgba(139,92,246,.55)) drop-shadow(0 0 20px rgba(0,212,255,.45)); stroke-width:13.5; }
    }
    #svgg stop:first-child{ animation:hueA 6s linear infinite; }
    #svgg stop:last-child { animation:hueB 6s linear infinite reverse; }
    @keyframes hueA{ 0%{ stop-color:#8b5cf6 } 50%{ stop-color:#6a8bff } 100%{ stop-color:#8b5cf6 } }
    @keyframes hueB{ 0%{ stop-color:#5ef0d6 } 50%{ stop-color:#00d4ff } 100%{ stop-color:#5ef0d6 } }
    @media (prefers-reduced-motion:reduce){ #spath,#svgg stop{ animation:none!important } }
  </style>
</head>
<body>
<div id="particles-js"></div>
<div class="bg-wash"></div>

<div class="shell">
  <div class="card" id="card">
    <div class="left">
      <div class="brandRow">
        <!-- Animated Striverr S -->
        <svg class="logoS" viewBox="0 0 200 200" aria-hidden="true">
          <defs>
            <linearGradient id="svgg" x1="0%" y1="0%" x2="100%" y2="100%">
              <stop offset="0%" stop-color="#8b5cf6"/>
              <stop offset="50%" stop-color="#00d4ff"/>
              <stop offset="100%" stop-color="#5ef0d6"/>
            </linearGradient>
          </defs>
          <path id="spath" pathLength="1000"
                d="M52 54 Q82 24, 112 50 T170 54 Q140 86, 112 112 T54 170 Q26 140, 56 112 T112 50"
                fill="none" stroke="url(#svgg)" stroke-width="13" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <div class="brandName">Striverr</div>

        <div style="margin-left:auto;font-size:12.5px;color:#93a0c2;border:1px solid rgba(255,255,255,.08);padding:6px 10px;border-radius:999px">
          Create account
        </div>
      </div>

      <h1>Join the marketplace of the future.</h1>
      <p class="sub">Hire faster. Earn smarter. Experience a premium freelance platform built for 2025.</p>

      <!-- enctype for file upload -->
      <form id="regForm" autocomplete="on" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

        <div class="row">
          <div class="field">
            <div class="label">Full name</div>
            <input class="input" name="name" placeholder="e.g., Ayesha Rahman" required />
          </div>

          <div class="field">
            <div class="label">Email address</div>
            <input class="input" type="email" name="email" placeholder="you@example.com" required />
          </div>
        </div>

        <div class="row">
          <div class="field pw-wrap">
            <div class="label">Password</div>
            <input class="input" id="pw" type="password" name="password" placeholder="At least 8 characters" minlength="8" required />
            <button type="button" class="pw-toggle" id="pwToggle">Show</button>
            <div class="hint" id="pwStrength">Password strength: —</div>
          </div>

          <div class="field">
            <div class="label">Role</div>
            <div class="role-wrap">
              <button type="button" class="role-btn active" data-role="freelancer">Freelancer</button>
              <button type="button" class="role-btn" data-role="client">Client</button>
            </div>
            <input type="hidden" name="role" id="role" value="freelancer" />
          </div>
        </div>

        <div class="row">
          <div class="field">
            <div class="label">Phone (optional)</div>
            <input class="input" name="phone" placeholder="+8801XXXXXXXXX" />
          </div>
          <div class="field">
            <div class="label">Country (optional)</div>
            <input class="input" name="country" placeholder="Bangladesh" />
          </div>
          <div class="field">
            <div class="label">Experience level (optional)</div>
            <select class="input" name="experience_level" style="appearance:none">
              <option value="">Select…</option>
              <option>Beginner</option>
              <option>Intermediate</option>
              <option>Expert</option>
            </select>
          </div>
        </div>

        <!-- Profile image upload -->
        <div class="field">
          <div class="label">Profile picture (optional)</div>
          <div class="img-up">
            <img id="avatarPreview" class="avatar" src="" alt="" style="display:none" />
            <input class="input" type="file" name="profile_image" id="profile_image" accept="image/*" />
          </div>
          <div class="hint">JPG/PNG/WEBP/GIF • max 2MB. A friendly face increases trust & hire rate.</div>
        </div>

        <label class="terms">
          <input type="checkbox" id="agree" /> I agree to the <a href="#" style="color:#cfe1ff">Terms</a> and <a href="#" style="color:#cfe1ff">Privacy Policy</a>
        </label>

        <button class="cta" type="submit">Create account</button>
        <div class="error" id="err"></div>
        <div class="hint" style="margin-top:14px">Already have an account? <a href="./login.php" style="color:#cfe1ff">Sign in</a></div>
      </form>

      <div id="successAnim" style="width:120px;height:120px;margin:18px auto 0;display:none"></div>
    </div>

    <div class="right">
      <h3>Why Striverr?</h3>
      <div class="bul">
        <div><span class="dot"></span><span><b>Trust-first profiles</b><br/>Profile photos & verified details help clients hire with confidence.</span></div>
        <div><span class="dot"></span><span><b>One‑click hire</b><br/>Hire freelancers with one click after going through their profile.</span></div>
        <div><span class="dot"></span><span><b>Secure auth</b><br/>Fully secured and safe used by verified users only.</span></div>
      </div>
    </div>
  </div>
</div>

<script>
  // Particles
  particlesJS("particles-js", {
    particles:{ number:{ value:54, density:{ enable:true, value_area:900 }},
      color:{ value:"#7aa0ff" }, shape:{ type:"circle" },
      opacity:{ value:.35, random:true }, size:{ value:2.6, random:true },
      line_linked:{ enable:true, distance:150, opacity:.18 }, move:{ enable:true, speed:1 }
    }
  });

  // Role toggle
  const roleBtns = document.querySelectorAll('.role-btn');
  const roleInput = document.getElementById('role');
  roleBtns.forEach(btn=>{
    btn.addEventListener('click',()=>{
      roleBtns.forEach(b=>b.classList.remove('active'));
      btn.classList.add('active');
      roleInput.value = btn.dataset.role;
    });
  });

  // Password toggle + strength
  const pw = document.getElementById('pw');
  const pwToggle = document.getElementById('pwToggle');
  const pwStrength = document.getElementById('pwStrength');
  pwToggle.addEventListener('click',()=>{ pw.type = pw.type === 'password' ? 'text' : 'password'; pwToggle.textContent = pw.type==='password'?'Show':'Hide'; });
  pw.addEventListener('input',()=>{
    const v = pw.value;
    let score=0; if (v.length>=8) score++; if (/[A-Z]/.test(v)&&/[a-z]/.test(v)) score++; if (/\d/.test(v)) score++; if (/[^A-Za-z0-9]/.test(v)) score++;
    const map=['—','Weak','Okay','Good','Strong']; pwStrength.textContent='Password strength: '+map[score];
  });

  // Image preview
  const fileInput = document.getElementById('profile_image');
  const avatar = document.getElementById('avatarPreview');
  fileInput.addEventListener('change',()=>{
    const f = fileInput.files[0];
    if (!f) { avatar.style.display='none'; return; }
    if (!f.type.startsWith('image/')) { Swal.fire('Invalid file','Please choose an image.','error'); fileInput.value=''; return; }
    const url = URL.createObjectURL(f);
    avatar.src = url; avatar.style.display='block';
  });

  // Submit
  const form = document.getElementById('regForm');
  const err = document.getElementById('err');
  const agree = document.getElementById('agree');
  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    err.style.display='none'; err.textContent='';
    if (!agree.checked){ err.textContent='Please agree to the Terms & Privacy Policy.'; err.style.display='block'; return; }

    try{
      const res = await fetch('', { method:'POST', body: new FormData(form) });
      const data = await res.json();
      if (!data.ok){
        err.textContent = data.error || 'Something went wrong.';
        err.style.display='block';
        Swal.fire({icon:'error', title:'Oops', text: err.textContent}); return;
      }
      confetti({ particleCount: 140, spread: 70, origin: { y: 0.6 } });
      const anim = lottie.loadAnimation({
        container: document.getElementById('successAnim'),
        renderer:'svg', loop:false, autoplay:true,
        path:'https://assets1.lottiefiles.com/packages/lf20_pqnfmone.json'
      });
      document.getElementById('successAnim').style.display='block';
      await Swal.fire({title:'Welcome!', text:'Your account is ready.', icon:'success', confirmButtonText:'Continue',
        background:'rgba(6,10,20,.96)', color:'#e8efff'});
      window.location.href = data.redirect || '../';
    }catch(ex){
      err.textContent='Network error. Please try again.'; err.style.display='block';
      Swal.fire({icon:'error', title:'Network error', text:'Please try again.'});
    }
  });
</script>
</body>
</html>
