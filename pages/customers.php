<?php
$defs = require MF_ROOT . '/app/resources.php';
$def = $defs['customers'];
$clientDef = $def; $clientDef['canEdit'] = Auth::can('customers.manage');
unset($clientDef['table'], $clientDef['search']);
foreach ($clientDef['computed'] as $k => $c) unset($clientDef['computed'][$k]['sql']);
$scripts[] = 'js/resource.js';
$stats = DB::row('SELECT COUNT(*) n, SUM(email IS NOT NULL AND email<>"") with_email, SUM(created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) new30 FROM customers WHERE is_active=1');
$debt = (float)DB::val('SELECT COALESCE(SUM(balance),0) FROM sales WHERE balance>0 AND status<>"voided"');
$active = (int)DB::val('SELECT COUNT(DISTINCT customer_id) FROM sales WHERE sold_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND customer_id IS NOT NULL');
?>
<div class="grid g4 mb">
  <div class="card kpi indigo"><span class="kpi-ic"><?= icon('users') ?></span><div class="label">Customers</div><div class="value"><?= number_format((int)$stats['n']) ?></div><div class="small muted"><?= (int)$stats['new30'] ?> new in 30 days</div></div>
  <div class="card kpi green"><span class="kpi-ic"><?= icon('zap') ?></span><div class="label">Active (30d)</div><div class="value"><?= number_format($active) ?></div></div>
  <div class="card kpi danger"><span class="kpi-ic"><?= icon('wallet') ?></span><div class="label">Owed to you</div><div class="value"><?= money($debt) ?></div><a class="small" href="<?= url('debts') ?>">View debtors →</a></div>
  <div class="card kpi gold"><span class="kpi-ic"><?= icon('mail') ?></span><div class="label">Reachable by email</div><div class="value"><?= number_format((int)$stats['with_email']) ?></div><?php if (Auth::can('marketing.manage')): ?><a class="small" href="<?= url('campaigns') ?>">Start a campaign →</a><?php endif; ?></div>
</div>
<div class="page-head">
  <h2 class="grow">Customers</h2>
  <?php if (Auth::can('customers.manage')): ?><button class="btn btn-primary" id="resNew"><?= icon('plus') ?> New customer</button><?php endif; ?>
</div>
<div class="card">
  <div class="card-head">
    <div class="search-box grow"><?= icon('search') ?><input type="search" id="resSearch" placeholder="Search name, phone, email, KRA PIN…"></div>
    <select id="resActive" style="max-width:150px"><option value="1">Active</option><option value="0">Inactive</option><option value="all">All</option></select>
  </div>
  <div id="resTable"></div>
</div>
<script>MF.ready(() => MF.resourcePage(document.getElementById('resTable'), 'customers', <?= json_encode($clientDef, JSON_HEX_TAG) ?>));</script>
