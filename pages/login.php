<?php
$biz = Settings::get('business_name');
$logo = Settings::get('business_logo');
?><!doctype html>
<html lang="en" data-theme="<?= e(Settings::get('default_theme', 'auto')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#050508">
<title>Sign in · <?= e($biz) ?> · Mfuko POS</title>
<link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
<link rel="apple-touch-icon" href="assets/img/icon-180.png">
<link rel="manifest" href="manifest.webmanifest">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/mfuko.css') ?>">
<script>
(function(){var t=document.documentElement,p=t.dataset.theme;try{p=localStorage.getItem('mf-theme')||p}catch(e){}
if(p==='auto'||!p){p=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}t.dataset.theme=p;
var m=document.querySelector('meta[name=theme-color]');if(m)m.content=p==='dark'?'#050508':'#163B23';})();
window.MF = {csrf: <?= json_encode(csrf_token()) ?>, icons: {}};
</script>
<script src="<?= asset('js/mfuko.js') ?>"></script>
</head>
<body>
<div class="auth-page">
  <section class="auth-art">
    <div class="sun"></div>
    <a class="brand" href="#" style="padding:0">
      <img src="assets/img/logo.svg" alt="" style="width:46px;height:46px">
      <div><b style="font-size:1.6rem">Mfuko</b><small>POS</small></div>
    </a>
    <div>
      <h1>Sell fast. <em>Grow</em> your mfuko.</h1>
      <p style="max-width:42ch;color:rgba(255,255,255,.8)">Point of sale built for African business — M-Pesa, credit, multi-store and smart insights, right from your phone.</p>
    </div>
    <div class="small" style="color:rgba(255,255,255,.6)">&copy; <?= date('Y') ?> Mfuko POS · <?= e(date_default_timezone_get()) ?></div>
  </section>

  <section class="auth-form">
    <div class="auth-card">
      <div class="brand">
        <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="" style="width:46px;height:46px;object-fit:contain;border-radius:10px"><?php else: ?><img src="assets/img/logo.svg" alt=""><?php endif; ?>
        <div><b><?= e($biz) ?></b><small style="color:var(--muted);letter-spacing:.1em">POWERED BY MFUKO POS</small></div>
      </div>
      <h2>Karibu tena 👋</h2>
      <p class="muted">Sign in to start selling.</p>

      <div class="seg" style="margin:6px 0 18px" id="modeSeg">
        <button type="button" class="on" data-mode="pw">Password</button>
        <button type="button" data-mode="pin">Quick PIN</button>
      </div>

      <form id="pwForm" autocomplete="on">
        <div class="field"><label for="u">Username or email</label><input id="u" name="username" type="text" autocomplete="username" autocapitalize="none" required autofocus></div>
        <div class="field"><label for="pw">Password</label><input id="pw" name="password" type="password" autocomplete="current-password" required></div>
        <button class="btn btn-primary btn-lg btn-block" type="submit">Sign in</button>
      </form>

      <form id="pinForm" class="hide">
        <div class="pin-dots" id="pinDots"><i></i><i></i><i></i><i></i></div>
        <div class="pinpad" id="pinpad">
          <?php foreach ([1,2,3,4,5,6,7,8,9] as $n): ?><button type="button" data-k="<?= $n ?>"><?= $n ?></button><?php endforeach; ?>
          <button type="button" data-k="clear">C</button><button type="button" data-k="0">0</button><button type="button" data-k="ok" style="background:var(--primary);color:#fff">✓</button>
        </div>
      </form>
      <div class="toasts" id="toasts"></div>
    </div>
  </section>
</div>
<script>
(function(){
  const {$, $$} = MF;
  const after = <?= json_encode($_SESSION['after_login'] ?? '') ?>;
  async function go(payload, btn){
    btn && btn.classList.add('loading');
    try { const d = await MF.api('auth.login', payload); location.href = after && after.includes('index.php') ? after : d.redirect; }
    catch(e){ if (payload.pin) { pin=''; dots(); } }
    finally { btn && btn.classList.remove('loading'); }
  }
  $('#pwForm').addEventListener('submit', e => { e.preventDefault(); go({username: $('#u').value, password: $('#pw').value}, e.submitter); });
  $$('#modeSeg button').forEach(b => b.onclick = () => {
    $$('#modeSeg button').forEach(x => x.classList.toggle('on', x === b));
    $('#pwForm').classList.toggle('hide', b.dataset.mode !== 'pw');
    $('#pinForm').classList.toggle('hide', b.dataset.mode !== 'pin');
  });
  let pin = '';
  const dots = () => { const n = Math.max(4, pin.length); $('#pinDots').innerHTML = Array.from({length:n}, (_, i) => `<i class="${i < pin.length ? 'on' : ''}"></i>`).join(''); };
  $('#pinpad').addEventListener('click', e => {
    const k = e.target.closest('[data-k]')?.dataset.k; if (!k) return;
    if (k === 'clear') pin = ''; else if (k === 'ok') { if (pin.length >= 4) go({pin}); return; } else if (pin.length < 8) pin += k;
    dots();
  });
  document.addEventListener('keydown', e => {
    if ($('#pinForm').classList.contains('hide')) return;
    if (/^\d$/.test(e.key) && pin.length < 8) { pin += e.key; dots(); }
    if (e.key === 'Backspace') { pin = pin.slice(0, -1); dots(); }
    if (e.key === 'Enter' && pin.length >= 4) go({pin});
  });
})();
</script>
</body>
</html>
