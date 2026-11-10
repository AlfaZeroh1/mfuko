<?php
/** @var string $resourceKey */
$defs = require MF_ROOT . '/app/resources.php';
$def = $defs[$resourceKey];
$canEdit = Auth::can($def['perm']);
$scripts[] = 'js/resource.js';
$clientDef = $def;
$clientDef['canEdit'] = $canEdit;
unset($clientDef['table'], $clientDef['search']);
foreach ($clientDef['computed'] ?? [] as $k => $c) unset($clientDef['computed'][$k]['sql']);
?>
<div class="page-head">
  <div class="grow">
    <h2><?= icon($def['icon']) ?> <?= e($page['title']) ?></h2>
    <?php if (!empty($def['intro'])): ?><p class="muted small" style="margin:4px 0 0;max-width:80ch"><?= e($def['intro']) ?></p><?php endif; ?>
  </div>
  <?php if ($canEdit): ?><button class="btn btn-primary" id="resNew"><?= icon('plus') ?> New <?= e(strtolower($def['singular'])) ?></button><?php endif; ?>
</div>
<div class="card">
  <div class="card-head">
    <div class="search-box grow"><?= icon('search') ?><input type="search" id="resSearch" placeholder="Search…"></div>
    <?php if ($resourceKey === 'expenses'): ?>
      <input type="date" id="resFrom" value="<?= date('Y-m-01') ?>" style="max-width:160px"><input type="date" id="resTo" value="<?= date('Y-m-d') ?>" style="max-width:160px">
    <?php endif; ?>
    <?php if (isset($def['fields']['is_active'])): ?>
      <select id="resActive" style="max-width:150px"><option value="1">Active</option><option value="0">Inactive</option><option value="all">All</option></select>
    <?php endif; ?>
  </div>
  <div id="resTable"></div>
</div>
<script>
MF.ready(() => MF.resourcePage(document.getElementById('resTable'), <?= json_encode($resourceKey) ?>, <?= json_encode($clientDef, JSON_HEX_TAG) ?>));
</script>
