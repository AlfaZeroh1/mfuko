<?php
$stores = DB::all('SELECT id, name FROM stores WHERE is_active=1 ORDER BY name');
$cols = [];
foreach ($stores as $s) $cols[] = 'SUM(CASE WHEN s.store_id=' . (int)$s['id'] . ' THEN s.qty ELSE 0 END) AS `s' . (int)$s['id'] . '`';
$q = trim((string)($_GET['q'] ?? ''));
$filter = $_GET['f'] ?? '';
$where = 'p.is_active=1 AND p.track_stock=1';
$params = [];
if ($q !== '') { $where .= ' AND (p.name LIKE ? OR p.sku LIKE ?)'; $params = ["%$q%", "%$q%"]; }
$having = $filter === 'low' ? 'HAVING total <= MAX(p.reorder_level)' : ($filter === 'out' ? 'HAVING total <= 0' : ($filter === 'expiring' ? '' : ''));
if ($filter === 'expiring') $where .= ' AND p.expiry_date IS NOT NULL AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)';
$rows = DB::all('SELECT p.id, p.name, p.sku, p.unit, p.reorder_level, p.expiry_date, p.cost_price, ' . ($cols ? implode(',', $cols) . ',' : '') . ' COALESCE(SUM(s.qty),0) total,
    (SELECT COALESCE(SUM(si.qty),0) FROM sale_items si JOIN sales sa ON sa.id=si.sale_id WHERE si.product_id=p.id AND sa.status<>"voided" AND sa.sold_at>=DATE_SUB(NOW(), INTERVAL 30 DAY)) sold30
    FROM products p LEFT JOIN stock s ON s.product_id=p.id WHERE ' . $where . ' GROUP BY p.id ' . $having . ' ORDER BY p.name LIMIT 500', $params);
$canCost = Auth::can('products.cost');
?>
<div class="page-head">
  <h2 class="grow">Stock levels</h2>
  <?php if (Auth::can('stock.transfer')): ?><a class="btn" href="<?= url('transfers') ?>"><?= icon('swap') ?> Transfer</a><?php endif; ?>
  <?php if (Auth::can('stock.take')): ?><a class="btn" href="<?= url('stocktake') ?>"><?= icon('clipboard') ?> Stock take</a><?php endif; ?>
  <?php if (Auth::can('purchases.manage')): ?><a class="btn btn-primary" href="<?= url('purchases', ['new' => 1]) ?>"><?= icon('truck') ?> Receive stock</a><?php endif; ?>
</div>
<form class="card mb" method="get">
  <div class="card-head" style="flex-wrap:wrap">
    <input type="hidden" name="p" value="stock">
    <div class="search-box grow"><?= icon('search') ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search products…"></div>
    <div class="seg">
      <?php foreach (['' => 'All', 'low' => 'Low / reorder', 'out' => 'Out of stock', 'expiring' => 'Expiring ≤60d'] as $k => $l): ?>
        <a class="btn btn-sm <?= $filter === $k ? 'btn-primary' : 'btn-ghost' ?>" href="<?= url('stock', ['f' => $k, 'q' => $q]) ?>"><?= e($l) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</form>
<div class="card">
  <div class="table-wrap"><table class="table responsive">
    <thead><tr><th>Product</th><?php foreach ($stores as $s): ?><th class="num"><?= e($s['name']) ?></th><?php endforeach; ?><th class="num">Total</th><th class="num">Sold 30d</th><th class="num">Days cover</th><th class="num">Suggest order</th><?php if ($canCost): ?><th class="num">Value</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $daily = (float)$r['sold30'] / 30; $cover = $daily > 0 ? (float)$r['total'] / $daily : null;
        $suggest = $daily > 0 ? max(0, ceil($daily * 30 - (float)$r['total'])) : (((float)$r['total'] <= (float)$r['reorder_level']) ? max(1, (float)$r['reorder_level'] * 2 - (float)$r['total']) : 0); ?>
      <tr>
        <td data-label="Product"><b><?= e($r['name']) ?></b><div class="tiny muted"><?= e($r['sku']) ?><?= $r['expiry_date'] ? ' · expires ' . e($r['expiry_date']) : '' ?></div></td>
        <?php foreach ($stores as $s): $v = (float)$r['s' . $s['id']]; ?><td class="num" data-label="<?= e($s['name']) ?>"><?= $v <= 0 ? '<span class="text-danger">' . qty($v) . '</span>' : qty($v) ?></td><?php endforeach; ?>
        <td class="num" data-label="Total"><b class="<?= (float)$r['total'] <= (float)$r['reorder_level'] ? 'text-danger' : '' ?>"><?= qty($r['total']) ?></b> <span class="tiny muted"><?= e($r['unit']) ?></span></td>
        <td class="num" data-label="Sold 30d"><?= qty($r['sold30']) ?></td>
        <td class="num" data-label="Days cover"><?= $cover === null ? '<span class="muted">—</span>' : ($cover < 7 ? '<span class="badge badge-danger">' . round($cover) . 'd</span>' : ($cover < 14 ? '<span class="badge badge-gold">' . round($cover) . 'd</span>' : round($cover) . 'd')) ?></td>
        <td class="num" data-label="Suggest"><?= $suggest > 0 ? '<b>' . qty($suggest) . '</b>' : '—' ?></td>
        <?php if ($canCost): ?><td class="num" data-label="Value"><?= money((float)$r['total'] * (float)$r['cost_price'], false) ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="10"><div class="empty"><?= icon('boxes') ?><div>Nothing to show</div></div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <div class="card-foot small muted">Days cover = stock ÷ average daily sales (last 30 days). Suggested order brings you to ~30 days of stock.</div>
</div>
