<?php
session_start();
include('../includes/db_connect.php');

if (!isset($_SESSION['user_id']) || !isset($_GET['project_id'])) {
  die("Access Denied");
}

$user_id    = (int)$_SESSION['user_id'];
$project_id = (int)$_GET['project_id'];

/* Fetch project + ensure participant */
$sql = "
  SELECT 
    p.client_id, p.hired_freelancer_id, p.title,
    c.name  AS client_name,
    f.name  AS freelancer_name,
    COALESCE(NULLIF(c.profile_image,''),'client.png')      AS client_avatar,
    COALESCE(NULLIF(f.profile_image,''),'freelancer.png')  AS freelancer_avatar
  FROM projects p
  JOIN users c ON c.user_id = p.client_id
  LEFT JOIN users f ON f.user_id = p.hired_freelancer_id
  WHERE p.project_id = ?
  LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $project_id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) die("Project not found");
$stmt->bind_result($client_id, $freelancer_id, $project_title, $client_name, $freelancer_name, $client_avatar, $freelancer_avatar);
$stmt->fetch();
$stmt->close();

/* Authorization: only client or the hired freelancer can access */
if ($user_id !== (int)$client_id && $user_id !== (int)$freelancer_id) {
  die("Unauthorized access");
}

/* Figure out roles, peer, avatars */
$isClient       = ($user_id === (int)$client_id);
$your_role      = $isClient ? 'Client'     : 'Freelancer';
$receiver_id    = $isClient ? (int)$freelancer_id : (int)$client_id;
$receiver_name  = $isClient ? ($freelancer_name ?: 'Freelancer') : $client_name;
$receiver_role  = $isClient ? 'Freelancer' : 'Client';

/* Fetch my avatar */
$me = $conn->prepare("SELECT role, COALESCE(NULLIF(profile_image,''),'') AS img FROM users WHERE user_id = ?");
$me->bind_param("i", $user_id);
$me->execute();
$me->bind_result($my_role, $my_img);
$me->fetch();
$me->close();
if ($my_img === '' || $my_img === null) {
  $my_img = ($my_role === 'client') ? 'client.png' : (($my_role === 'freelancer') ? 'freelancer.png' : 'default.png');
}

/* Peer avatar chosen above from project join */
$peer_avatar = $isClient ? ($freelancer_avatar ?: 'freelancer.png') : ($client_avatar ?: 'client.png');

/* Helper */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Chat · <?= e($project_title) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
<style>
:root{
  --bg:#0b1220; --panel:#0f182a; --ink:#eaf2ff; --muted:#9fb3c8; --line:rgba(255,255,255,.08);
  --mine:#00bfff; --mint:#00ffc3; --bubble:#152235; --peer:#1a2940; --card:rgba(255,255,255,.04);
}
*{box-sizing:border-box}
body{
  margin:0; font-family:Poppins,system-ui,Segoe UI,Arial,sans-serif; background:
    radial-gradient(1200px 600px at 80% -10%, rgba(0,255,195,.12), transparent),
    radial-gradient(900px 500px at 10% 10%, rgba(0,191,255,.10), transparent),
    linear-gradient(180deg,#0a111e 0%, #0b1220 100%);
  color:var(--ink); height:100vh; display:grid; grid-template-rows:auto 1fr auto;
}

/* Header */
.head{
  display:flex; align-items:center; gap:12px; padding:12px 16px; border-bottom:1px solid var(--line);
  backdrop-filter:blur(10px); background:linear-gradient(180deg, rgba(11,18,32,.75), rgba(11,18,32,.35));
}
.back{appearance:none; border:1px solid var(--line); background:var(--card); color:var(--ink);
  padding:8px 12px; border-radius:10px; cursor:pointer}
.peer{
  display:flex; align-items:center; gap:10px; flex:1; min-width:0;
}
.peer .ava{width:36px; height:36px; border-radius:50%; object-fit:cover; border:2px solid rgba(127,221,255,.5)}
.peer .name{font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis}
.peer .small{font-size:12px; color:var(--muted)}

/* Chat area */
.chat{
  overflow-y:auto; padding:16px; display:flex; flex-direction:column; gap:8px;
}
.day{
  align-self:center; font-size:12px; color:var(--muted); padding:6px 10px; border:1px solid var(--line);
  border-radius:999px; background:var(--card); margin:8px 0 2px;
}
.row{
  display:flex; gap:8px; align-items:flex-end; max-width:100%;
}
.row.mine{justify-content:flex-end}
.row .ava{width:28px;height:28px;border-radius:50%;object-fit:cover;border:2px solid rgba(127,221,255,.35)}
.bubble{
  max-width:min(72%, 680px); background:var(--bubble); padding:10px 12px; border-radius:14px; position:relative;
  border:1px solid var(--line);
}
.mine .bubble{ background:linear-gradient(180deg, rgba(0,191,255,.15), rgba(255,255,255,.04)); border-color:rgba(0,191,255,.25) }
.meta{
  display:flex; gap:6px; align-items:center; margin-top:6px; color:var(--muted); font-size:12px;
}
.checks{font-size:12px}
.checks.read{color:#58ccff}

/* text + media */
.msg-text{white-space:pre-wrap; word-wrap:break-word; line-height:1.5}
.msg-text a{color:#9bd7ff; text-decoration:none}
.msg-text a:hover{text-decoration:underline}
.gallery{display:grid; gap:6px; margin-top:6px; grid-template-columns:repeat(auto-fill, minmax(140px,1fr))}
.gallery img{
  width:100%; height:140px; object-fit:cover; border-radius:10px; border:1px solid var(--line); background:#0b1220;
  cursor:pointer; transition:.15s transform ease;
}
.gallery img:hover{ transform:scale(1.02) }

/* Composer */
.composer{
  display:flex; gap:10px; padding:12px; border-top:1px solid var(--line); background:rgba(255,255,255,.03);
  align-items:flex-end; flex-wrap:wrap;
}
.tools{display:flex; gap:8px; align-items:center}
.icon-btn{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;border:1px solid var(--line);background:var(--card);color:var(--ink);cursor:pointer}
#messageInput{
  flex:1; min-height:44px; max-height:160px; overflow:auto;
  padding:10px 12px; border:1px solid var(--line); border-radius:12px; background:#0f1a2a; color:var(--ink);
}
.send{
  appearance:none; border:none; background:linear-gradient(90deg, var(--mint), var(--mine)); color:#04131c;
  font-weight:800; padding:10px 16px; border-radius:12px; cursor:pointer;
}

/* Emoji menu */
.emojis{position:absolute; bottom:70px; left:12px; background:#0f182a; border:1px solid var(--line); border-radius:12px; padding:8px; display:none; gap:6px; flex-wrap:wrap; width:240px}
.emojis span{cursor:pointer; font-size:18px}

/* Attach preview */
.preview{
  display:none; width:100%; padding:10px 12px; gap:8px; overflow:auto; background:rgba(255,255,255,.03); border-top:1px solid var(--line);
}
.preview .chip{
  position:relative; border:1px solid var(--line); background:#0f182a; color:#cfe0f5; padding:8px 10px; border-radius:10px; display:flex; align-items:center; gap:8px;
}
.preview .chip img{width:26px;height:26px;object-fit:cover;border-radius:6px;border:1px solid var(--line)}
.preview .chip .x{position:absolute; top:-8px; right:-8px; background:#19263a; border:1px solid var(--line); width:22px;height:22px;border-radius:50%; display:grid; place-items:center; cursor:pointer}

@media (max-width: 760px){
  .bubble{max-width:85%}
}
</style>
</head>
<body>

<!-- Header -->
<div class="head">
  <button class="back" onclick="history.back()"><i class="fa fa-arrow-left"></i></button>
  <div class="peer">
    <img class="ava" src="../includes/images/<?= e($peer_avatar) ?>" onerror="this.src='../includes/images/default.png'">
    <div style="min-width:0">
      <div class="name"><?= e($receiver_name) ?> <span class="small">· <?= e($receiver_role) ?></span></div>
      <div class="small" title="<?= e($project_title) ?>">Project: <?= e($project_title) ?></div>
    </div>
  </div>
</div>

<!-- Messages -->
<div id="chat" class="chat" aria-live="polite"></div>

<!-- Image preview queue -->
<div id="preview" class="preview"></div>

<!-- Composer -->
<form id="composer" class="composer">
  <div class="tools" style="position:relative">
    <button type="button" class="icon-btn" id="emojiBtn" title="Emoji"><i class="fa fa-face-smile"></i></button>
    <div id="emojiBox" class="emojis" role="menu" aria-label="Emoji picker">
      <span>😀</span><span>😂</span><span>🥳</span><span>😎</span><span>🔥</span><span>💯</span><span>🚀</span><span>✨</span>
      <span>👍</span><span>👏</span><span>🙏</span><span>❤️</span><span>🎯</span><span>🧠</span><span>📎</span>
    </div>

    <input type="file" id="imageInput" accept="image/*" multiple hidden>
    <button type="button" class="icon-btn" id="attachBtn" title="Attach images"><i class="fa fa-paperclip"></i></button>
  </div>

  <textarea id="messageInput" rows="1" placeholder="Type a message…"></textarea>
  <button class="send" id="sendBtn" type="submit"><i class="fa fa-paper-plane"></i> Send</button>
</form>

<script>
/* Server-side context */
const USER_ID      = <?= (int)$user_id ?>;
const RECEIVER_ID  = <?= (int)$receiver_id ?>;
const PROJECT_ID   = <?= (int)$project_id ?>;
const MY_AVATAR    = <?= json_encode($my_img) ?>;
const PEER_AVATAR  = <?= json_encode($peer_avatar) ?>;

/* DOM */
const chatEl    = document.getElementById('chat');
const msgInput  = document.getElementById('messageInput');
const form      = document.getElementById('composer');
const emojiBtn  = document.getElementById('emojiBtn');
const emojiBox  = document.getElementById('emojiBox');
const attachBtn = document.getElementById('attachBtn');
const imageIn   = document.getElementById('imageInput');
const preview   = document.getElementById('preview');

let polling     = null;
let lastRenderedIds = []; // for minimal DOM churn
let queueFiles  = [];     // selected images to upload

/* Emoji picker */
emojiBtn.addEventListener('click', (e)=> {
  e.stopPropagation();
  emojiBox.style.display = emojiBox.style.display === 'flex' ? 'none' : 'flex';
});
document.addEventListener('click', (e)=>{
  if (!emojiBox.contains(e.target) && e.target !== emojiBtn) emojiBox.style.display = 'none';
});
[...emojiBox.querySelectorAll('span')].forEach(s=>{
  s.addEventListener('click', ()=>{
    msgInput.value += s.textContent;
    msgInput.focus();
    emojiBox.style.display = 'none';
  });
});

/* Attach images */
attachBtn.addEventListener('click', ()=> imageIn.click());
imageIn.addEventListener('change', ()=>{
  if (!imageIn.files || !imageIn.files.length) return;
  for (const f of imageIn.files) {
    if (!f.type.startsWith('image/')) continue;
    queueFiles.push(f);
  }
  renderPreviewQueue();
  imageIn.value = '';
});
function renderPreviewQueue(){
  if (!queueFiles.length){ preview.style.display='none'; preview.innerHTML=''; return; }
  preview.style.display='flex';
  preview.innerHTML = '';
  queueFiles.forEach((f, idx)=>{
    const url = URL.createObjectURL(f);
    const chip = document.createElement('div');
    chip.className = 'chip';
    chip.innerHTML = `<img src="${url}"><span>${f.name}</span><span class="x" title="Remove">&times;</span>`;
    chip.querySelector('.x').addEventListener('click', ()=>{
      queueFiles.splice(idx,1);
      renderPreviewQueue();
    });
    preview.appendChild(chip);
  });
}

/* Auto-resize message box */
msgInput.addEventListener('input', ()=>{
  msgInput.style.height = 'auto';
  msgInput.style.height = Math.min(160, msgInput.scrollHeight) + 'px';
});

/* Send message (text + images) */
form.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const text = msgInput.value.trim();

  // 1) send images first (one by one)
  for (const file of queueFiles){
    const fd = new FormData();
    fd.append('sender_id',   USER_ID);
    fd.append('receiver_id', RECEIVER_ID);
    fd.append('project_id',  PROJECT_ID);
    fd.append('message_text',''); // server will fill with file:URL or similar
    fd.append('image', file);     // IMPORTANT: your send_message.php should save & set message_text to "file:/uploads/chat/..."
    await fetch('send_message.php', { method:'POST', body: fd }).catch(()=>{});
  }
  queueFiles = [];
  renderPreviewQueue();

  // 2) send text message if any
  if (text.length){
    const enc = new URLSearchParams();
    enc.append('sender_id',   String(USER_ID));
    enc.append('receiver_id', String(RECEIVER_ID));
    enc.append('project_id',  String(PROJECT_ID));
    enc.append('message_text', text);
    await fetch('send_message.php', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: enc.toString()
    }).catch(()=>{});
  }

  msgInput.value = '';
  msgInput.style.height = '44px';
  setTimeout(loadMessages, 80);
});

/* Fetch + render */
function autoLink(text){
  // Very light autolink
  const urlRe = /((https?:\/\/|www\.)[^\s<]+)/gi;
  return text.replace(urlRe, (m)=>{
    const href = m.startsWith('http') ? m : 'http://' + m;
    return `<a href="${href}" target="_blank" rel="noopener">${m}</a>`;
  });
}
function groupByDay(messages){
  const map = new Map();
  messages.forEach(m=>{
    const d = new Date(m.message_date);
    const key = d.toDateString();
    if (!map.has(key)) map.set(key, []);
    map.get(key).push(m);
  });
  return map;
}
function isImageMessage(content){
  // Convention: send_message.php writes message_text like "file:/uploads/chat/xxx.png" for images
  if (!content) return false;
  if (content.startsWith('file:')) return true;
  const lc = content.toLowerCase();
  return lc.match(/\.(png|jpg|jpeg|gif|webp)$/);
}
function extractImageList(content){
  // support multiple lines "file:/uploads/chat/a.png\nfile:/uploads/chat/b.jpg"
  const lines = String(content).split(/\r?\n/).map(s=>s.trim()).filter(Boolean);
  const out = [];
  for (const ln of lines){
    if (ln.startsWith('file:')) out.push(ln.slice(5));
    else if (ln.match(/^\/?uploads\/chat\/.+\.(png|jpe?g|gif|webp)$/i)) out.push(ln.startsWith('/')? ln : '/'+ln);
  }
  return out.length ? out : null;
}

async function loadMessages(){
  try{
    const enc = new URLSearchParams();
    enc.append('project_id', String(PROJECT_ID));
    enc.append('user_id',    String(USER_ID));
    const res = await fetch('fetch_messages.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: enc.toString()
    });
    const data = await res.json();

    // If nothing changed, skip re-render
    const ids = data.map(m=>m.message_id).join(',');
    if (ids === lastRenderedIds.join(',')) return;
    lastRenderedIds = data.map(m=>m.message_id);

    chatEl.innerHTML = '';
    const byDay = groupByDay(data);
    [...byDay.keys()].forEach(day=>{
      // Day divider
      const dayEl = document.createElement('div');
      dayEl.className = 'day';
      dayEl.textContent = day;
      chatEl.appendChild(dayEl);

      // Messages of that day
      byDay.get(day).forEach(m=>{
        const mine = (Number(m.sender_id) === USER_ID);

        const row  = document.createElement('div');
        row.className = 'row' + (mine ? ' mine' : '');
        const ava = document.createElement('img');
        ava.className = 'ava';
        ava.src = '../includes/images/' + (mine ? MY_AVATAR : PEER_AVATAR);
        ava.onerror = ()=>{ ava.onerror=null; ava.src='../includes/images/default.png'; };
        if (!mine) row.appendChild(ava);

        const bubble = document.createElement('div');
        bubble.className = 'bubble';

        const content = document.createElement('div');
        content.className = 'msg-text';

        const msg = String(m.message_text || '');
        const imgs = isImageMessage(msg) ? extractImageList(msg) : null;

        if (imgs && imgs.length){
          // Image gallery bubble
          const gallery = document.createElement('div');
          gallery.className = 'gallery';
          imgs.forEach(src=>{
            const img = document.createElement('img');
            img.src = src.startsWith('http') ? src : ('..' + (src.startsWith('/')? src : '/'+src));
            img.loading = 'lazy';
            img.onclick = ()=> window.open(img.src, '_blank', 'noopener');
            gallery.appendChild(img);
          });
          bubble.appendChild(gallery);
        }

        // text, if any non "file:" text present
        const textOnly = imgs ? msg.split('\n').filter(x=>!x.trim().startsWith('file:')).join('\n').trim() : msg;
        if (textOnly){
          content.innerHTML = autoLink(textOnly);
          bubble.appendChild(content);
        }

        // meta
        const meta = document.createElement('div');
        meta.className = 'meta';
        const dt = new Date(m.message_date);
        const ts = dt.toLocaleString();
        const checks = document.createElement('span');
        checks.className = 'checks' + ((mine && Number(m.read) === 1) ? ' read' : '');
        checks.innerHTML = mine ? (Number(m.read) === 1 ? '<i class="fa fa-check-double"></i>' : '<i class="fa fa-check-double" style="opacity:.5"></i>') : '';
        const timeEl = document.createElement('span');
        timeEl.textContent = ts;
        meta.appendChild(timeEl);
        meta.appendChild(checks);

        bubble.appendChild(meta);
        row.appendChild(bubble);
        if (mine) row.appendChild(ava);
        chatEl.appendChild(row);
      });
    });

    chatEl.scrollTop = chatEl.scrollHeight;
  }catch(e){
    // ignore transient errors
  }
}

// Start polling
loadMessages();
clearInterval(polling);
polling = setInterval(loadMessages, 2000);
</script>
</body>
</html>
