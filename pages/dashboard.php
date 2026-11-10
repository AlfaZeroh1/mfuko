<?php
$type = $user['dashboard'];
if ($type === 'admin' && !Auth::canAny(['reports.view', 'reports.financial'])) $type = 'cashier';
if ($type === 'accountant' && !Auth::canAny(['reports.view', 'reports.financial'])) $type = 'cashier';
if ($type === 'storekeeper' && !Auth::canAny(['stock.view', 'purchases.manage'])) $type = 'cashier';
if (isset($_GET['view']) && in_array($_GET['view'], ['admin', 'cashier', 'storekeeper', 'accountant'], true) && Auth::isOwner()) $type = $_GET['view'];
$cdn[] = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js';
$scripts[] = 'js/dashboard.js';
$hour = (int)date('G');
$greet = $hour < 12 ? 'Habari ya asubuhi' : ($hour < 17 ? 'Habari ya mchana' : 'Habari ya jioni');
?>
<div class="hero mb">
  <div class="row">
    <div class="grow">
      <div class="muted small"><?= e(date('l, j F Y')) ?> · <?= e(DB::val('SELECT name FROM stores WHERE id=?', [$storeId]) ?? '') ?></div>
      <h2 style="margin:4px 0"><?= e($greet) ?>, <?= e($user['first_name']) ?> 👋</h2>
      <div class="muted" id="heroLine">Loading today’s numbers…</div>
    </div>
    <?php if (Auth::can('sell.create')): ?><a class="btn btn-gold btn-lg" href="<?= url('sell') ?>"><?= icon('cart') ?> Start selling</a><?php endif; ?>
    <?php if (Auth::isOwner()): ?>
      <div class="dropdown"><button class="btn btn-ghost" style="color:#fff" data-toggle-dropdown><?= icon('grid') ?> View as</button>
        <div class="dropdown-menu"><?php foreach (['admin' => 'Owner / manager', 'cashier' => 'Cashier', 'storekeeper' => 'Storekeeper', 'accountant' => 'Accountant'] as $k => $l): ?><a href="<?= url('dashboard', ['view' => $k]) ?>"><?= e($l) ?></a><?php endforeach; ?></div></div>
    <?php endif; ?>
  </div>
</div>
<div id="dash" data-type="<?= e($type) ?>"><div class="grid g4"><?php for ($i = 0; $i < 4; $i++): ?><div class="card kpi"><div class="skeleton" style="height:60px"></div></div><?php endfor; ?></div></div>
