<?php
/** @var array $page @var string $content @var string $p @var array $pages */
$user = Auth::user();
$layout = $page['layout'] ?? 'app';
$stores = Auth::stores();
$storeId = $storeId ?? Auth::currentStore();
$bizName = Settings::get('business_name');
$theme = $user['theme'] ?? 'auto';
if ($theme === 'auto') $theme = Settings::get('default_theme', 'auto');
$scripts = $scripts ?? [];
$cdn = $cdn ?? [];

// Sidebar groups
$nav = [];
foreach ($pages as $key => $pg) {
    if (($pg['group'] ?? null) === null || $key === 'sell') continue;
    $perm = $pg['perm'];
    if ($perm !== null && !(is_array($perm) ? Auth::canAny($perm) : Auth::can($perm))) continue;
    $nav[$pg['group']][$key] = $pg;
}
$canSell = Auth::can('sell.create');
$jsIcons = icon_paths(); // ~8KB, cached with the page; lets JS draw the same icon set
$mfConfig = [
    'csrf'     => csrf_token(),
    'currency' => Settings::get('currency_symbol'),
    'tz'       => date_default_timezone_get(),
    'tzOffset' => (int)date('Z'),
    'serverNow'=> time() * 1000,
    'storeId'  => $storeId,
    'user'     => ['id' => (int)$user['id'], 'name' => full_name($user), 'role' => $user['role_name']],
    'perms'    => Auth::permissions(),
    'page'     => $p,
    'icons'    => $jsIcons,
    'biz'      => $bizName,
];
?><!doctype html>
<html lang="en" data-theme="<?= e($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#2B1B57">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title><?= e($page['title'] ?? 'Mfuko POS') ?> · <?= e($bizName) ?></title>
<link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
<link rel="apple-touch-icon" href="assets/img/icon-180.png">
<link rel="manifest" href="manifest.webmanifest">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/mfuko.css') ?>">
<?php if ($layout === 'pos'): ?><link rel="stylesheet" href="<?= asset('css/sell.css') ?>"><?php endif; ?>
<script>
(function(){var t=document.documentElement,p=t.dataset.theme;try{p=localStorage.getItem('mf-theme')||p}catch(e){}
if(p==='auto'||!p){p=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}t.dataset.theme=p;})();
window.MF = <?= json_encode($mfConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="<?= asset('js/mfuko.js') ?>"></script>
<?php foreach ($cdn as $src): ?><script src="<?= e($src) ?>" defer></script><?php endforeach; ?>
</head>
<body class="layout-<?= e($layout) ?> page-<?= e($p) ?>">
<div class="app">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="<?= url($user['home_page']) ?>">
      <img src="assets/img/logo.svg" alt="">
      <div><b>Mfuko</b><small>POS</small></div>
    </a>
    <?php if (count($stores) > 1): ?>
    <div class="store-switch">
      <select id="storeSwitch" aria-label="Active store">
        <?php foreach ($stores as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $storeId ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <nav class="nav">
      <a href="<?= url('dashboard') ?>" class="<?= $p === 'dashboard' ? 'active' : '' ?>"><?= icon('home') ?>Dashboard</a>
      <?php if ($canSell): ?><a href="<?= url('sell') ?>" class="sell-link <?= $p === 'sell' ? 'active' : '' ?>"><?= icon('cart') ?>Sell<kbd>F2</kbd></a><?php endif; ?>
      <?php foreach ($nav as $group => $items): if ($group === '') continue; ?>
        <div class="nav-group"><span><?= e($group) ?></span>
          <?php foreach ($items as $key => $it): ?>
            <a href="<?= url($key) ?>" class="<?= $p === $key ? 'active' : '' ?>"><?= icon($it['icon']) ?><?= e($it['title']) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">Mfuko POS v<?= MF_VERSION ?> · <?= e($bizName) ?></div>
  </aside>
  <div class="scrim" data-close-nav></div>

  <div class="main">
    <header class="topbar">
      <button class="icon-btn" id="navToggle" aria-label="Menu"><?= icon('menu') ?></button>
      <a class="mobile-brand" href="<?= url($user['home_page']) ?>"><img src="assets/img/logo.svg" alt="Mfuko"></a>
      <h1><?= e($page['title'] ?? '') ?></h1>
      <div class="spacer"></div>
      <span class="net-pill"><?= icon('wifi-off', 'sm') ?>Offline</span>
      <span class="queue-pill hide" id="queuePill"><?= icon('refresh', 'sm') ?><span></span></span>
      <div class="clock" title="<?= e(date_default_timezone_get()) ?>">
        <svg viewBox="0 0 40 40" id="navClock" aria-label="Clock">
          <circle class="face" cx="20" cy="20" r="19"/><circle class="ring" cx="20" cy="20" r="17.5"/>
          <g id="clockTicks"></g>
          <line class="h" x1="20" y1="20" x2="20" y2="11"/>
          <line class="m" x1="20" y1="20" x2="20" y2="7"/>
          <line class="s" x1="20" y1="23" x2="20" y2="5"/>
          <circle class="pin" cx="20" cy="20" r="2"/>
        </svg>
        <div class="clock-text"><b id="clockTime">--:--</b><span id="clockDate"></span></div>
      </div>
      <button class="icon-btn hide-sm" id="themeToggle" aria-label="Toggle theme"><?= icon('moon') ?></button>
      <div class="dropdown" id="userMenu">
        <button class="user-chip" data-toggle-dropdown>
          <?= avatar($user) ?>
          <span class="who"><b><?= e($user['first_name']) ?></b><span><?= e($user['role_name']) ?></span></span>
        </button>
        <div class="dropdown-menu">
          <div style="padding:8px 10px 10px" class="small"><b><?= e(full_name($user)) ?></b><br><span class="muted">@<?= e($user['username']) ?> · <?= e($user['role_name']) ?></span></div>
          <a href="<?= url('profile') ?>"><?= icon('user') ?>My account</a>
          <button type="button" data-theme-set="light"><?= icon('sun') ?>Light theme</button>
          <button type="button" data-theme-set="dark"><?= icon('moon') ?>Dark theme</button>
          <hr style="margin:6px 0">
          <a href="<?= url('logout') ?>"><?= icon('logout') ?>Sign out</a>
        </div>
      </div>
    </header>

    <main class="content">
      <?= $content ?>
    </main>
  </div>
</div>

<nav class="bottom-nav">
  <a href="<?= url('dashboard') ?>" class="<?= $p === 'dashboard' ? 'active' : '' ?>"><?= icon('home') ?>Home</a>
  <a href="<?= url('sales') ?>" class="<?= $p === 'sales' ? 'active' : '' ?>"><?= icon('receipt') ?>Sales</a>
  <?php if ($canSell): ?><a href="<?= url('sell') ?>" class="sell-fab"><span><?= icon('cart') ?></span></a><?php else: ?><a href="<?= url('stock') ?>"><?= icon('boxes') ?>Stock</a><?php endif; ?>
  <a href="<?= url(Auth::can('customers.view') ? 'customers' : 'profile') ?>" class="<?= $p === 'customers' ? 'active' : '' ?>"><?= icon('users') ?>Customers</a>
  <button type="button" id="moreNav"><?= icon('grid') ?>Menu</button>
</nav>

<div class="toasts" id="toasts"></div>
<?php foreach ($scripts as $s): ?><script src="<?= asset($s) ?>"></script><?php endforeach; ?>
</body>
</html>
