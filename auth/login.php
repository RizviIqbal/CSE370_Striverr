<?php
// auth/login.php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';

// Already logged in? Go home.
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
  $r = $_SESSION['role'] === 'client' ? '../client/dashboard.php' : '../freelancer/dashboard.php';
  header("Location: $r"); exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf_token = $_SESSION['csrf_token'];

function json_out($a){ header('Content-Type: application/json'); echo json_encode($a); exit; }

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    json_out(['ok'=>false,'error'=>'Invalid session. Refresh and try again.']);
  }
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['password'] ?? '';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['ok'=>false,'error'=>'Enter a valid email.']);
  if ($pass==='') json_out(['ok'=>false,'error'=>'Password required.']);

  // Grab by email
  $stmt = $conn->prepare("SELECT user_id, password, role, name, profile_image FROM users WHERE email=? LIMIT 1");
  $stmt->bind_param("s", $email);
  $stmt->execute(); $stmt->store_result();
  if ($stmt->num_rows===0) { $stmt->close(); json_out(['ok'=>false,'error'=>'No account found with that email.']); }
  $stmt->bind_result($uid,$hash,$role,$name,$pi); $stmt->fetch(); $stmt->close();

  if (!password_verify($pass, $hash)) {
    json_out(['ok'=>false,'error'=>'Incorrect password.']);
  }

  // Login
  $_SESSION['user_id']=(int)$uid;
  $_SESSION['role']=$role;
  $_SESSION['name']=$name;
  $_SESSION['email']=$email;


  $avatar = $pi ?: null;
  if ($avatar) {
    if (preg_match('~^https?://|^/|uploads/|includes/~i', $avatar)) {
      $_SESSION['profile_image'] = $avatar; // path already
    } else {
      $_SESSION['profile_image'] = "includes/images/$avatar"; // filename style
    }
  } else {
    $_SESSION['profile_image'] = null;
  }

  $redirect = $role==='client' ? '../client/dashboard.php' : '../freelancer/dashboard.php';
  json_out(['ok'=>true,'redirect'=>$redirect,'role'=>$role,'name'=>$name]);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Log in | Striverr</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- SweetAlert2 + Confetti + Particles + Lottie -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.12.4/dist/sweetalert2.all.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/lottie-web@5.12.2/build/player/lottie.min.js"></script>

  <style>
    :root{
      --bg:#050a14; --txt:#e9f1ff; --muted:#9fb3cf; --err:#ff6b6b;
      --g1:#8b5cf6; --g2:#00d4ff; --g3:#5ef0d6;
      --glass: rgba(14,18,32,.55); --line: rgba(255,255,255,.10);
    }
    *{box-sizing:border-box}
    html,body{
      margin:0;min-height:100%;
      background:
        radial-gradient(1600px 900px at 85% -10%, rgba(139,92,246,.20), transparent 60%),
        radial-gradient(1200px 700px at -10% 20%, rgba(0,212,255,.18), transparent 60%),
        linear-gradient(180deg,#040916 0%, #0a1020 100%);
      color:var(--txt);
      font-family:Inter,system-ui,Segoe UI,Roboto,Arial;
    }
    body{overflow:auto}

    /* Constellations */
    #particles-js{position:fixed;inset:0;z-index:0;opacity:.48;pointer-events:none}

    /* Royal orbs to fill space */
    .orb{position:fixed;filter:blur(100px);opacity:.35;z-index:0;pointer-events:none}
    .orb.o1{width:680px;height:680px;left:-160px;bottom:-160px;background:radial-gradient(circle at 30% 30%, #00eaff 0%, transparent 60%)}
    .orb.o2{width:620px;height:620px;right:-180px;top:-180px;background:radial-gradient(circle at 60% 40%, #8b5cf6 0%, transparent 60%)}
    .orb.o3{width:540px;height:540px;left:40%;top:-220px;background:radial-gradient(circle at 50% 50%, #5ef0d6 0%, transparent 60%)}

    /* Shell: wide two-panel layout */
    .shell{
      position:relative;z-index:1;max-width:1180px;margin:60px auto; padding:0 22px;
      display:grid; grid-template-columns:1.2fr .8fr; gap:24px; align-items:stretch;
    }
    @media (max-width: 980px){ .shell{grid-template-columns:1fr; margin:32px auto} }

    /* Panels */
    .panel{
      background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03)), var(--glass);
      border:1px solid var(--line); border-radius:26px;
      backdrop-filter: blur(16px) saturate(140%);
      box-shadow:0 16px 60px rgba(0,0,0,.6), inset 0 0 0 1px rgba(255,255,255,.04);
      position:relative; overflow:hidden;
    }

    /* Hero panel (brand) */
    .hero{padding:46px 42px; display:flex; flex-direction:column; justify-content:center}
    .brandRow{display:flex; align-items:center; gap:16px; margin-bottom:8px}
    .logoBig{width:92px;height:92px; display:block}
    .brandName{font-weight:900; font-size:34px; letter-spacing:.4px}
    .tag{color:#cfe1ff; margin-top:8px; font-size:16px}
    .points{margin-top:24px; display:grid; gap:14px}
    .point{display:flex; gap:10px; align-items:flex-start; color:#adc2e3; font-size:15px}
    .bdot{width:9px;height:9px;border-radius:50%;background:#6ee7ff;box-shadow:0 0 14px #6ee7ff; margin-top:6px}
    .shimmer{position:absolute; inset:0; pointer-events:none; mix-blend-mode:screen; opacity:.18;
      background: radial-gradient(200px 120px at 15% 20%, rgba(255,255,255,.22), transparent),
                  radial-gradient(260px 140px at 85% 70%, rgba(255,255,255,.14), transparent 45%);
    }

    /* Login panel (form) */
    .auth{padding:42px 38px}
    .field{margin:16px 0}
    .label{font-size:13.5px;color:#9bb0da;margin-bottom:8px}
    .input{
      width:100%; height:56px; padding:0 16px; border-radius:16px; background:rgba(255,255,255,.04);
      border:1px solid rgba(255,255,255,.09); color:var(--txt); outline:none; font-size:16px;
      transition:border-color .2s, box-shadow .2s;
    }
    .input:focus{border-color:#7a5bff99; box-shadow:0 0 0 3px rgba(122,91,255,.18)}
    .pw-wrap{position:relative}
    .pw-toggle{position:absolute; right:14px; top:50%; transform:translateY(-50%); background:transparent; border:0; color:#b7c3e6; font-size:13.5px; cursor:pointer}

    .cta{
      height:58px; width:100%; border:0; border-radius:18px; cursor:pointer; font-weight:900; font-size:17px;
      background:linear-gradient(90deg,var(--g1),var(--g2)); color:#031022; box-shadow:0 14px 40px rgba(0,212,255,.25); margin-top:10px;
    }
    .error{color:var(--err); font-size:14px; margin-top:14px; display:none; text-align:center}
    .link{display:block; margin-top:14px; text-align:center; color:#cfe1ff; text-decoration:none; font-size:14px}
    .subtle{color:var(--muted); font-size:12.5px; text-align:center; margin-top:10px}

    /* =====================  Infinite Striverr "S"  ===================== */
    #spath{
      /* We’ll set pathLength=1000 on the SVG path, so use that here */
      --len: 1000;
      stroke-dasharray: var(--len);
      stroke-dashoffset: var(--len);
      /* Draw/erase loop and neon pulse */
      animation: drawS 3.2s ease-in-out infinite, glowS 1.6s ease-in-out infinite;
    }
    @keyframes drawS{
      0%   { stroke-dashoffset: var(--len); }
      50%  { stroke-dashoffset: 0;          }
      100% { stroke-dashoffset: var(--len); }
    }
    @keyframes glowS{
      0%,100%{
        filter: drop-shadow(0 0 0 rgba(139,92,246,0.0))
                drop-shadow(0 0 0 rgba(0,212,255,0.0));
        stroke-width: 13.5;
      }
      50%{
        filter: drop-shadow(0 0 14px rgba(139,92,246,0.55))
                drop-shadow(0 0 24px rgba(0,212,255,0.45));
        stroke-width: 14.5;
      }
    }
    /* Gentle gradient shimmer in the logo */
    #svgg stop:first-child{ animation: hueA 6s linear infinite; }
    #svgg stop:last-child { animation: hueB 6s linear infinite reverse; }
    /* Reduced motion support */
    @media (prefers-reduced-motion: reduce){
      #spath, #svgg stop{ animation: none !important; }
    }
  </style>
</head>
<body>
<div id="particles-js"></div>
<div class="orb o1"></div><div class="orb o2"></div><div class="orb o3"></div>

<div class="shell">
  <!-- LEFT: Grand hero / brand -->
  <section class="panel hero">
    <div class="shimmer"></div>
    <div class="brandRow">
      <!-- Big animated Striverr SVG -->
      <svg class="logoBig" viewBox="0 0 200 200" aria-hidden="true">
        <defs>
          <linearGradient id="svgg" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#8b5cf6"/>
            <stop offset="50%" stop-color="#00d4ff"/>
            <stop offset="100%" stop-color="#5ef0d6"/>
          </linearGradient>
        </defs>
        <!-- Abstract S ribbon | pathLength lets us animate with CSS only -->
        <path id="spath" pathLength="1000"
              d="M52 54 Q82 24, 112 50 T170 54 Q140 86, 112 112 T54 170 Q26 140, 56 112 T112 50"
              fill="none" stroke="url(#svgg)" stroke-width="14" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <div class="brandName">Striverr</div>
    </div>
    <div class="tag">Grand. Royal. Exotic. The fastest way to hire and get hired.</div>

    <div class="points">
      <div class="point"><div class="bdot"></div><div>Milestone‑first workflow with escrowed payments.</div></div>
      <div class="point"><div class="bdot"></div><div>Lux UI, instant confirmations, confetti when it matters.</div></div>
      <div class="point"><div class="bdot"></div><div>Secure by default: hashing, CSRF, 2FA‑ready.</div></div>
    </div>
  </section>

  <!-- RIGHT: Large login form -->
  <section class="panel auth" id="card">
    <form id="loginForm" autocomplete="on">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

      <div class="field">
        <div class="label">Email</div>
        <input class="input" type="email" name="email" placeholder="you@example.com" required />
      </div>

      <div class="field pw-wrap">
        <div class="label">Password</div>
        <input class="input" id="pw" type="password" name="password" placeholder="Your password" required />
        <button type="button" class="pw-toggle" id="pwToggle">Show</button>
      </div>

      <button class="cta" type="submit">Log in</button>
      <div class="error" id="err"></div>
      <a class="link" href="register.php">Don’t have an account? Sign up</a>
      <div class="subtle">Secure by default • CSRF protected • Encrypted at rest</div>
    </form>
    <div id="successAnim" style="width:140px;height:140px;margin:18px auto 0;display:none"></div>
  </section>
</div>

<script>
  // Constellations
  particlesJS("particles-js", {
    particles:{ number:{ value:70, density:{ enable:true, value_area:1000 } },
      color:{ value:"#7aa0ff" }, shape:{ type:"circle" },
      opacity:{ value:.35, random:true }, size:{ value:2.6, random:true },
      line_linked:{ enable:true, distance:160, opacity:.18 }, move:{ enable:true, speed:1.1 }
    }
  });

  // Password toggle
  document.getElementById('pwToggle').addEventListener('click', (ev)=>{
    const pw = document.getElementById('pw');
    pw.type = pw.type === 'password' ? 'text' : 'password';
    ev.currentTarget.textContent = pw.type==='password' ? 'Show' : 'Hide';
  });

  // Submit
  const form = document.getElementById('loginForm');
  const err = document.getElementById('err');
  form.addEventListener('submit', async (e)=>{
    e.preventDefault(); err.style.display='none'; err.textContent='';
    try{
      const res = await fetch('', { method:'POST', body:new FormData(form) });
      const data = await res.json();
      if (!data.ok){
        err.textContent = data.error || 'Something went wrong.'; err.style.display='block';
        Swal.fire({icon:'error', title:'Oops', text: err.textContent}); return;
      }
      confetti({ particleCount: 160, spread: 70, origin: { y: 0.6 } });
      const anim = lottie.loadAnimation({
        container: document.getElementById('successAnim'),
        renderer: 'svg', loop:false, autoplay:true,
        path: 'https://assets1.lottiefiles.com/packages/lf20_pqnfmone.json'
      });
      document.getElementById('successAnim').style.display='block';

      await Swal.fire({
        title: `Welcome back${data.name ? ', ' + data.name.split(' ')[0] : ''}!`,
        text: (data.role==='client'?'Jump into your projects.':'Time to ship something great.'),
        icon: 'success', confirmButtonText:'Continue',
        background:'rgba(6,10,20,.96)', color:'#e8efff'
      });

      window.location.href = data.redirect || '../';
    }catch(ex){
      err.textContent='Network error. Please try again.'; err.style.display='block';
      Swal.fire({icon:'error', title:'Network error', text:'Please try again.'});
    }
  });
</script>
</body>
</html>
