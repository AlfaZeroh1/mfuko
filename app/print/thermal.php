<?php
/** @var array $d @var array $b @var array $accounts @var array $opt  — no logo on thermal by design */
$w = (int)$b['thermal_width'] === 58 ? 58 : 80;
$px = $w === 58 ? '48mm' : '72mm';
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($d['title'] . ' ' . $d['number']) ?></title>
<style>
@page { size: <?= $w ?>mm auto; margin: 2mm; }
* { box-sizing: border-box; }
body { margin: 0; background: #ddd; font-family: 'Courier New', ui-monospace, monospace; color: #000; font-size: <?= $w === 58 ? '10.5px' : '12px' ?>; }
.roll { width: <?= $px ?>; margin: 12px auto; background: #fff; padding: 8px 6px 14px; }
.c { text-align: center; } .r { text-align: right; } .b { font-weight: 700; }
h1 { font-size: 1.35em; margin: 0; } h2 { font-size: 1.15em; margin: 6px 0 0; letter-spacing: .1em; }
.hr { border-top: 1px dashed #000; margin: 6px 0; }
.row { display: flex; justify-content: space-between; gap: 6px; }
.row span:last-child { text-align: right; white-space: nowrap; }
.it { margin: 3px 0; } .it .n { font-weight: 700; }
.grand { font-size: 1.25em; font-weight: 800; }
table { width: 100%; border-collapse: collapse; font-size: .92em; } td, th { padding: 1px 0; text-align: left; vertical-align: top; } th { border-bottom: 1px solid #000; }
.toolbar { display: flex; gap: 6px; justify-content: center; padding: 8px; background: #2B1B57; position: sticky; top: 0; font-family: sans-serif; }
.toolbar a, .toolbar button { font: inherit; font-weight: 600; padding: 7px 12px; border-radius: 8px; border: 0; background: #fff; color: #2B1B57; text-decoration: none; cursor: pointer; }
.toolbar .pri { background: #C4502A; color: #fff; }
@media print { body { background: #fff; } .roll { margin: 0; width: auto; padding: 0; } .toolbar { display: none; } }
</style></head>
<body>
<?php if (empty($opt['frame'])): ?><div class="toolbar"><button class="pri" onclick="print()">Print</button><a href="?<?= e(http_build_query(array_merge($_GET, ['size' => 'a4', 'auto' => 0]))) ?>">A4</a><button onclick="history.length>1?history.back():close()">Close</button></div><?php endif; ?>
<div class="roll">
  <div class="c">
    <h1><?= e($b['business_name']) ?></h1>
    <?php if ($b['business_tagline']): ?><div><?= e($b['business_tagline']) ?></div><?php endif; ?>
    <div><?= e(implode(', ', array_filter([$b['business_address'], $b['business_town']]))) ?></div>
    <?php if ($b['business_phone']): ?><div>Tel: <?= e($b['business_phone']) ?></div><?php endif; ?>
    <?php if ($b['business_kra_pin']): ?><div>PIN: <?= e($b['business_kra_pin']) ?></div><?php endif; ?>
    <h2><?= e(strtoupper($d['title'])) ?><?= $d['status'] ? ' · ' . e($d['status'][0]) : '' ?></h2>
    <div class="b"><?= e($d['number']) ?></div>
  </div>
  <div class="hr"></div>
  <?php foreach ($d['meta'] as [$k, $v]): ?><div class="row"><span><?= e($k) ?></span><span><?= e($v) ?></span></div><?php endforeach; ?>
  <?php if ($d['party']): ?><div class="row"><span><?= e($d['party']['label']) ?></span><span class="b"><?= e($d['party']['name']) ?></span></div><?php endif; ?>
  <div class="hr"></div>

  <?php if ($d['thermal'] === 'table'): ?>
    <table><thead><tr><?php foreach ($d['columns'] as $c): ?><th class="<?= !empty($c['num']) ? 'r' : '' ?>"><?= e($c['label']) ?></th><?php endforeach; ?></tr></thead>
    <tbody><?php foreach ($d['rows'] as $r): ?><tr><?php foreach ($d['columns'] as $c): ?><td class="<?= !empty($c['num']) ? 'r' : '' ?>"><?= e($r[$c['key']] ?? '') ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table>
  <?php else: ?>
    <?php foreach ($d['rows'] as $r): ?>
      <div class="it">
        <div class="n"><?= e($r['name'] ?? '') ?></div>
        <div class="row"><span><?= isset($r['qty']) ? e($r['qty']) . ' x ' . e($r['price'] ?? '') : '' ?><?= !empty($r['disc']) ? ' -' . e($r['disc']) : '' ?></span><span><?= e($r['total'] ?? '') ?></span></div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  <div class="hr"></div>
  <?php foreach ($d['totals'] as $t): ?><div class="row <?= !empty($t['grand']) ? 'grand' : '' ?>"><span><?= e($t[0]) ?></span><span><?= e($t[1]) ?></span></div><?php endforeach; ?>
  <?php if ($d['payments']): ?><div class="hr"></div><?php foreach ($d['payments'] as [$k, $v]): ?><div class="row"><span><?= e($k) ?></span><span><?= e($v) ?></span></div><?php endforeach; ?><?php endif; ?>
  <?php if ($accounts): ?><div class="hr"></div><div class="b">PAY VIA</div>
    <?php foreach ($accounts as $a): ?><div><?= e($a['name']) ?><?= $a['account_type'] === 'paybill' ? ': PB ' . e($a['account_number']) . ' Acc ' . e($a['account_name'] ?: $d['number']) : ($a['account_number'] ? ': ' . e($a['account_number']) : '') ?></div><?php endforeach; ?>
  <?php endif; ?>
  <?php if ($d['notes']): ?><div class="hr"></div><div><?= nl2br(e($d['notes'])) ?></div><?php endif; ?>
  <?php if ($d['signature']): ?><div style="margin-top:18px">Sign: ____________________</div><?php endif; ?>
  <div class="hr"></div>
  <div class="c"><?= nl2br(e($b['print_footer'])) ?></div>
  <div class="c" style="font-size:.85em;margin-top:4px"><?= e(date('d/m/Y H:i')) ?> · Mfuko POS</div>
</div>
<?php if (!empty($opt['auto'])): ?><script>addEventListener('load', () => setTimeout(() => print(), 200));</script><?php endif; ?>
</body></html>
