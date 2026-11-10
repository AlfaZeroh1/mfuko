<?php
/** @var array $d @var array $b @var array $accounts @var string $logo @var array $opt */
$tone = ['green' => '#1E8A5A', 'gold' => '#B7790F', 'danger' => '#C2362B', 'indigo' => '#2B1B57'];
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($d['title'] . ' ' . $d['number']) ?></title>
<style>
@page { size: A4; margin: 14mm 12mm; }
* { box-sizing: border-box; }
body { margin: 0; font-family: 'Outfit', 'Segoe UI', Arial, sans-serif; color: #1F1830; font-size: 12.5px; background: #EEE7DA; }
.sheet { width: 210mm; min-height: 297mm; margin: 16px auto; padding: 14mm 12mm; background: #fff; box-shadow: 0 10px 30px rgba(0,0,0,.15); position: relative; }
.kente { height: 6px; background: repeating-linear-gradient(90deg,#C4502A 0 22px,#E3A92B 22px 34px,#1E8A5A 34px 52px,#2B1B57 52px 60px,#E3A92B 60px 66px); margin: -14mm -12mm 10mm; }
.head { display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; }
.biz { display: flex; gap: 14px; align-items: center; }
.biz img { max-height: 64px; max-width: 160px; object-fit: contain; }
.biz h1 { margin: 0; font-size: 20px; color: #2B1B57; }
.biz .tag { color: #C4502A; font-size: 11px; font-style: italic; }
.biz .lines { color: #5a536a; font-size: 11px; line-height: 1.5; }
.doc { text-align: right; }
.doc h2 { margin: 0; font-size: 24px; letter-spacing: .02em; text-transform: uppercase; color: #C4502A; }
.doc .no { font-size: 14px; font-weight: 700; }
.stamp { display: inline-block; margin-top: 6px; padding: 3px 12px; border: 2px solid currentColor; border-radius: 6px; font-weight: 800; letter-spacing: .12em; font-size: 11px; transform: rotate(-3deg); }
.grid { display: flex; gap: 16px; margin: 18px 0 14px; }
.box { flex: 1; border: 1px solid #E8DCC8; border-radius: 10px; padding: 10px 12px; background: #FBF7F0; }
.box .lbl { font-size: 10px; text-transform: uppercase; letter-spacing: .12em; color: #7A7088; font-weight: 700; margin-bottom: 4px; }
.box b { font-size: 14px; }
.meta td { padding: 2px 0; } .meta td:first-child { color: #7A7088; padding-right: 12px; }
table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
.items th { background: #2B1B57; color: #fff; text-align: left; padding: 8px 9px; font-size: 10.5px; text-transform: uppercase; letter-spacing: .08em; }
.items th:first-child { border-radius: 8px 0 0 0; } .items th:last-child { border-radius: 0 8px 0 0; }
.items td { padding: 7px 9px; border-bottom: 1px solid #EFE6D6; vertical-align: top; }
.items tr:nth-child(even) td { background: #FCFAF6; }
.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.bottom { display: flex; gap: 20px; margin-top: 14px; align-items: flex-start; }
.bottom .left { flex: 1; }
.totals { width: 300px; border-collapse: collapse; }
.totals td { padding: 5px 9px; }
.totals .grand td { background: #FBE3D7; font-weight: 800; font-size: 14px; color: #A93D1C; }
.totals .grand.danger td { background: #FADBD7; color: #C2362B; }
.pays { margin-top: 8px; font-size: 11.5px; } .pays td { padding: 2px 9px; }
.payvia { border: 1px dashed #E3A92B; background: #FFFAEB; border-radius: 10px; padding: 10px 12px; margin-top: 10px; }
.payvia b { color: #2B1B57; }
.notes { margin-top: 10px; color: #4A4259; white-space: pre-wrap; }
.sign { display: flex; gap: 40px; margin-top: 40px; } .sign div { flex: 1; border-top: 1px solid #999; padding-top: 4px; color: #7A7088; font-size: 11px; }
.foot { position: absolute; left: 12mm; right: 12mm; bottom: 10mm; display: flex; justify-content: space-between; color: #9a92a8; font-size: 10px; border-top: 1px solid #EFE6D6; padding-top: 6px; }
.toolbar { position: sticky; top: 0; z-index: 2; display: flex; gap: 8px; justify-content: center; padding: 10px; background: #2B1B57; }
.toolbar a, .toolbar button { font: inherit; font-weight: 600; padding: 8px 16px; border-radius: 10px; border: 0; background: #fff; color: #2B1B57; cursor: pointer; text-decoration: none; }
.toolbar .pri { background: #C4502A; color: #fff; }
@media print { body { background: #fff; } .sheet { margin: 0; box-shadow: none; width: auto; min-height: auto; padding: 0; } .kente { margin: 0 0 8mm; } .toolbar { display: none; } .foot { position: fixed; left: 0; right: 0; bottom: 0; } }
@media (max-width: 820px) { .sheet { width: auto; margin: 0; min-height: 0; padding: 18px 14px 60px; } .kente { margin: -18px -14px 16px; } .head, .grid, .bottom { flex-direction: column; } .doc { text-align: left; } .totals { width: 100%; } .items { font-size: 11px; } }
</style></head>
<body>
<?php if (empty($opt['email'])): ?>
<div class="toolbar"><button class="pri" onclick="print()">Print</button><a href="?<?= e(http_build_query(array_merge($_GET, ['size' => 'thermal', 'auto' => 0]))) ?>">Thermal size</a><button onclick="history.length>1?history.back():close()">Close</button></div>
<?php endif; ?>
<div class="sheet">
  <div class="kente"></div>
  <div class="head">
    <div class="biz">
      <?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php endif; ?>
      <div>
        <h1><?= e($b['business_name']) ?></h1>
        <?php if ($b['business_tagline']): ?><div class="tag"><?= e($b['business_tagline']) ?></div><?php endif; ?>
        <div class="lines"><?= e(implode(' · ', array_filter([$b['business_address'], $b['business_town']]))) ?><br><?= e(implode(' · ', array_filter([$b['business_phone'], $b['business_email']]))) ?><?= $b['business_kra_pin'] ? '<br>KRA PIN: ' . e($b['business_kra_pin']) : '' ?></div>
      </div>
    </div>
    <div class="doc">
      <h2><?= e($d['title']) ?></h2>
      <div class="no"><?= e($d['number']) ?></div>
      <?php if ($d['status']): ?><div class="stamp" style="color:<?= $tone[$d['status'][1]] ?? '#2B1B57' ?>"><?= e($d['status'][0]) ?></div><?php endif; ?>
    </div>
  </div>

  <div class="grid">
    <?php if ($d['party']): ?>
    <div class="box"><div class="lbl"><?= e($d['party']['label']) ?></div><b><?= e($d['party']['name']) ?></b><div><?= nl2br(e(implode("\n", $d['party']['lines']))) ?></div></div>
    <?php endif; ?>
    <?php if ($d['meta']): ?>
    <div class="box"><div class="lbl">Details</div><table class="meta"><?php foreach ($d['meta'] as [$k, $v]): ?><tr><td><?= e($k) ?></td><td><b style="font-size:12px"><?= e($v) ?></b></td></tr><?php endforeach; ?></table></div>
    <?php endif; ?>
  </div>

  <?php if ($d['columns']): ?>
  <table class="items">
    <thead><tr><th>#</th><?php foreach ($d['columns'] as $c): ?><th class="<?= !empty($c['num']) ? 'num' : '' ?>"><?= e($c['label']) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($d['rows'] as $i => $r): ?><tr><td style="color:#9a92a8"><?= $i + 1 ?></td><?php foreach ($d['columns'] as $c): ?><td class="<?= !empty($c['num']) ? 'num' : '' ?>"><?= e($r[$c['key']] ?? '') ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    <?php if (!$d['rows']): ?><tr><td colspan="<?= count($d['columns']) + 1 ?>" style="text-align:center;color:#9a92a8">No lines</td></tr><?php endif; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <div class="bottom">
    <div class="left">
      <?php if ($accounts): ?>
      <div class="payvia"><div class="lbl" style="font-size:10px;text-transform:uppercase;letter-spacing:.12em;color:#B7790F;font-weight:700">Pay via</div>
        <?php foreach ($accounts as $a): ?>
          <div><b><?= e($a['name']) ?></b>
          <?php if ($a['account_type'] === 'paybill'): ?> — Paybill <b><?= e($a['account_number']) ?></b><?= $a['account_name'] ? ', Account <b>' . e($a['account_name']) . '</b>' : ', Account: ' . e($d['number']) ?>
          <?php elseif ($a['account_type'] === 'till'): ?> — Buy Goods Till <b><?= e($a['account_number']) ?></b>
          <?php elseif ($a['method'] === 'bank'): ?> — <?= e($a['provider']) ?> A/C <b><?= e($a['account_number']) ?></b><?= $a['account_name'] ? ' (' . e($a['account_name']) . ')' : '' ?><?= $a['branch'] ? ', ' . e($a['branch']) : '' ?>
          <?php else: ?> <?= e($a['account_number']) ?><?php endif; ?></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($d['notes']): ?><div class="notes"><?= e($d['notes']) ?></div><?php endif; ?>
      <?php if ($d['terms']): ?><div class="notes" style="font-size:11px;color:#7A7088"><?= e($d['terms']) ?></div><?php endif; ?>
    </div>
    <div>
      <?php if ($d['totals']): ?>
      <table class="totals"><?php foreach ($d['totals'] as $t): ?><tr class="<?= !empty($t['grand']) ? 'grand' : '' ?> <?= !empty($t['danger']) ? 'danger' : '' ?>"><td><?= e($t[0]) ?></td><td class="num"><?= e($t[1]) ?></td></tr><?php endforeach; ?></table>
      <?php endif; ?>
      <?php if ($d['payments']): ?>
      <table class="pays totals"><tr><td colspan="2" style="font-weight:700;color:#7A7088;text-transform:uppercase;font-size:10px;letter-spacing:.1em">Payments</td></tr>
        <?php foreach ($d['payments'] as [$k, $v]): ?><tr><td><?= e($k) ?></td><td class="num"><?= e($v) ?></td></tr><?php endforeach; ?></table>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($d['signature']): ?><div class="sign"><div>Prepared by</div><div>Received / signed by</div><div>Date & stamp</div></div><?php endif; ?>
  <div class="foot"><span><?= e($b['print_footer']) ?></span><span>Printed <?= e(date('d M Y H:i')) ?> · Mfuko POS</span></div>
</div>
<?php if (!empty($opt['auto'])): ?><script>addEventListener('load', () => setTimeout(() => print(), 250));</script><?php endif; ?>
</body></html>
