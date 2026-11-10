<?php
$cat = Reports::catalogue();
$key = $_GET['r'] ?? 'sales_daily';
if (!isset($cat[$key]) || !Auth::can($cat[$key][2])) {
    foreach ($cat as $k => $c) if (Auth::can($c[2])) { $key = $k; break; }
}
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$store = (int)($_GET['store'] ?? 0);
$stores = Auth::stores();
$fmt = $_GET['format'] ?? '';
try { $rep = Reports::run($key, $from, $to, $store); } catch (ApiError $e) { echo '<div class="alert alert-danger">' . e($e->getMessage()) . '</div>'; return; }

$cell = static function (string $label, $v): string {
    if ($v === null || $v === '') return '';
    return match ($label[0]) { '$' => number_format((float)$v, 2), '#' => qty($v), '%' => rtrim(rtrim(number_format((float)$v, 1), '0'), '.') . '%', default => (string)$v };
};
$clean = static fn(string $l) => in_array($l[0], ['$', '#', '%'], true) ? substr($l, 1) : $l;

if ($fmt === 'csv') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $key . '-' . $from . '-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map($clean, array_values($rep['cols'])));
    foreach ($rep['rows'] as $r) fputcsv($out, array_map(static fn($c) => $r[$c] ?? '', array_keys($rep['cols'])));
    Audit::log('report_export', 'report', $key, "Exported {$rep['title']} CSV ($from to $to)");
    exit;
}
if ($fmt === 'print') {
    while (ob_get_level()) ob_end_clean();
    $rows = array_map(static fn($r) => array_combine(array_keys($rep['cols']), array_map(static fn($c, $l) => $cell($l, $r[$c] ?? ''), array_keys($rep['cols']), array_values($rep['cols']))), $rep['rows']);
    $cols = array_map(static fn($c, $l) => ['key' => $c, 'label' => $clean($l), 'num' => in_array($l[0], ['$', '#', '%'], true)], array_keys($rep['cols']), array_values($rep['cols']));
    $totals = [];
    foreach ($rep['totals'] as $c => $v) $totals[] = [$clean($rep['cols'][$c]), $cell($rep['cols'][$c], $v)];
    $d = ['title' => $rep['title'], 'number' => fdate($from) . ' – ' . fdate($to), 'date' => $to, 'status' => null, 'party' => null,
        'meta' => [['Period', fdate($from) . ' to ' . fdate($to)], ['Store', $store ? (string)DB::val('SELECT name FROM stores WHERE id=?', [$store]) : 'All stores'], ['Prepared by', full_name($user)]],
        'columns' => $cols, 'rows' => $rows, 'totals' => $totals, 'payments' => [], 'notes' => null, 'terms' => null, 'pay_accounts' => false, 'signature' => false, 'thermal' => 'table', 'doc' => 'report'];
    echo Printer::render($d, ($_GET['size'] ?? 'a4') === 'thermal' ? 'thermal' : 'a4', ['auto' => true]);
    exit;
}
$groups = [];
foreach ($cat as $k => $c) if (Auth::can($c[2])) $groups[$c[1]][$k] = $c[0];
$q = ['r' => $key, 'from' => $from, 'to' => $to, 'store' => $store];
?>
<div class="grid" style="grid-template-columns:240px minmax(0,1fr);align-items:start" id="repGrid">
  <div class="card" style="position:sticky;top:76px">
    <?php foreach ($groups as $g => $items): ?>
      <div class="nav-group" style="padding:6px 10px"><span class="tiny upper muted strong"><?= e($g) ?></span>
        <?php foreach ($items as $k => $t): ?><a href="<?= url('reports', array_merge($q, ['r' => $k])) ?>" class="btn btn-sm btn-block <?= $k === $key ? 'btn-soft' : 'btn-ghost' ?>" style="justify-content:flex-start;margin:2px 0"><?= e($t) ?></a><?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <form class="card-head" style="flex-wrap:wrap" method="get">
      <input type="hidden" name="p" value="reports"><input type="hidden" name="r" value="<?= e($key) ?>">
      <h3 class="grow"><?= e($rep['title']) ?></h3>
      <select onchange="const t=new Date(),f=x=>x.toISOString().slice(0,10);const r={today:[t,t],week:[new Date(t-6*864e5),t],month:[new Date(t.getFullYear(),t.getMonth(),1),t],lastmonth:[new Date(t.getFullYear(),t.getMonth()-1,1),new Date(t.getFullYear(),t.getMonth(),0)],year:[new Date(t.getFullYear(),0,1),t]}[this.value];if(r){this.form.from.value=f(r[0]);this.form.to.value=f(r[1]);this.form.submit()}" style="max-width:140px">
        <option value="">Quick range…</option><option value="today">Today</option><option value="week">Last 7 days</option><option value="month">This month</option><option value="lastmonth">Last month</option><option value="year">This year</option></select>
      <input type="date" name="from" value="<?= e($from) ?>" style="max-width:150px"><input type="date" name="to" value="<?= e($to) ?>" style="max-width:150px">
      <?php if (count($stores) > 1): ?><select name="store" style="max-width:150px"><option value="0">All stores</option><?php foreach ($stores as $s): ?><option value="<?= $s['id'] ?>" <?= $store === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
      <button class="btn btn-primary">Run</button>
      <a class="btn" href="<?= url('reports', $q + ['format' => 'csv']) ?>"><?= icon('download') ?> CSV</a>
      <a class="btn" target="_blank" href="<?= url('reports', $q + ['format' => 'print']) ?>"><?= icon('printer') ?> A4</a>
      <a class="btn btn-ghost" target="_blank" href="<?= url('reports', $q + ['format' => 'print', 'size' => 'thermal']) ?>"><?= icon('receipt') ?></a>
    </form>
    <div class="table-wrap" style="max-height:70vh"><table class="table">
      <thead><tr><?php foreach ($rep['cols'] as $c => $l): ?><th class="<?= in_array($l[0], ['$', '#', '%'], true) ? 'num' : '' ?>"><?= e($clean($l)) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($rep['rows'] as $r): $strong = $key === 'pnl' && str_contains((string)$r['line'], 'PROFIT'); ?>
        <tr<?= $strong ? ' style="font-weight:800"' : '' ?>><?php foreach ($rep['cols'] as $c => $l): ?><td class="<?= in_array($l[0], ['$', '#', '%'], true) ? 'num' : '' ?> <?= ($l[0] === '$' && (float)($r[$c] ?? 0) < 0) ? 'text-danger' : '' ?>"><?= e($cell($l, $r[$c] ?? '')) ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      <?php if (!$rep['rows']): ?><tr><td colspan="<?= count($rep['cols']) ?>"><div class="empty"><?= icon('chart') ?><div>No data for this period</div></div></td></tr><?php endif; ?>
      </tbody>
      <?php if ($rep['totals'] && $rep['rows']): ?><tfoot><tr><?php $first = true; foreach ($rep['cols'] as $c => $l): ?><td class="<?= isset($rep['totals'][$c]) ? 'num' : '' ?>"><?= $first ? 'Total (' . count($rep['rows']) . ')' : (isset($rep['totals'][$c]) ? e($cell($l, $rep['totals'][$c])) : '') ?></td><?php $first = false; endforeach; ?></tr></tfoot><?php endif; ?>
    </table></div>
  </div>
</div>
<style>@media (max-width:900px){#repGrid{grid-template-columns:1fr!important}#repGrid>.card:first-child{position:static!important;max-height:220px;overflow:auto}}</style>
