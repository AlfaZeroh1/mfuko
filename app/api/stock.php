<?php
return [
    'adjust' => [
        'perm' => 'stock.adjust', 'write' => true,
        'fn' => function (array $in) {
            $store = (int)($in['store_id'] ?? Auth::currentStore());
            if (!Auth::canUseStore($store)) throw new ApiError('Not your store');
            $pid = (int)($in['product_id'] ?? 0);
            $mode = $in['mode'] ?? 'delta';
            $reason = trim((string)($in['reason'] ?? ''));
            if ($reason === '') throw new ApiError('Give a reason (damage, expiry, correction…)');
            return DB::tx(function () use ($store, $pid, $mode, $in, $reason) {
                $cur = Stock::level($store, $pid);
                $delta = $mode === 'set' ? (float)$in['qty'] - $cur : (float)$in['qty'];
                if ($delta == 0) throw new ApiError('No change');
                $cost = (float)DB::val('SELECT cost_price FROM products WHERE id=?', [$pid]);
                $bal = Stock::move($store, $pid, $delta, 'adjustment', 'adjustment', null, $reason, $cost, true);
                Audit::log('stock_adjust', 'product', $pid, sprintf('Adjusted %s by %s → %s (%s)', DB::val('SELECT name FROM products WHERE id=?', [$pid]), qty($delta), qty($bal), $reason));
                return ['balance' => $bal];
            });
        },
    ],
    // Break a bulk pack into units (e.g. 1 carton → 24 pieces)
    'breakdown' => [
        'perm' => 'stock.adjust', 'write' => true,
        'fn' => function (array $in) {
            $store = Auth::currentStore();
            $child = DB::row('SELECT * FROM products WHERE id=?', [(int)($in['child_id'] ?? 0)]);
            if (!$child || !$child['parent_id'] || (float)$child['units_per_parent'] <= 0) throw new ApiError('Set the bulk pack and units-per-pack on the unit product first');
            $parent = DB::row('SELECT * FROM products WHERE id=?', [$child['parent_id']]);
            $n = (float)($in['packs'] ?? 0);
            if ($n <= 0) throw new ApiError('Enter how many packs to break');
            $units = $n * (float)$child['units_per_parent'];
            return DB::tx(function () use ($store, $child, $parent, $n, $units) {
                Stock::move($store, (int)$parent['id'], -$n, 'breakdown_out', 'breakdown', (int)$child['id'], "Into $units {$child['name']}", (float)$parent['cost_price'], false);
                $bal = Stock::move($store, (int)$child['id'], $units, 'breakdown_in', 'breakdown', (int)$parent['id'], "From $n {$parent['name']}", round((float)$parent['cost_price'] / (float)$child['units_per_parent'], 2), true);
                Audit::log('stock_breakdown', 'product', $child['id'], "Broke $n × {$parent['name']} into $units × {$child['name']}");
                return ['units' => $units, 'balance' => $bal];
            });
        },
    ],
    'transfers' => [
        'perm' => ['stock.transfer', 'stock.view'],
        'fn' => function (array $in) {
            $per = min(100, (int)($in['per'] ?? 25)); $off = (max(1, (int)($in['page'] ?? 1)) - 1) * $per;
            return [
                'total' => (int)DB::val('SELECT COUNT(*) FROM transfers'),
                'rows' => DB::all("SELECT t.*, a.name from_store, b.name to_store, (SELECT COUNT(*) FROM transfer_items i WHERE i.transfer_id=t.id) items, u.first_name sender
                    FROM transfers t JOIN stores a ON a.id=t.from_store_id JOIN stores b ON b.id=t.to_store_id LEFT JOIN users u ON u.id=t.created_by ORDER BY t.id DESC LIMIT $per OFFSET $off"),
            ];
        },
    ],
    'transfer_create' => [
        'perm' => 'stock.transfer', 'write' => true,
        'fn' => function (array $in) {
            $from = (int)($in['from_store_id'] ?? 0); $to = (int)($in['to_store_id'] ?? 0);
            if (!$from || !$to || $from === $to) throw new ApiError('Choose two different stores');
            if (!Auth::canUseStore($from)) throw new ApiError('You cannot send from that store');
            $items = array_filter((array)($in['items'] ?? []), static fn($i) => (float)($i['qty'] ?? 0) > 0);
            if (!$items) throw new ApiError('Add items to transfer');
            return DB::tx(function () use ($from, $to, $items, $in) {
                $no = Sequence::next('transfer');
                $id = DB::insert('transfers', ['transfer_no' => $no, 'from_store_id' => $from, 'to_store_id' => $to, 'notes' => mb_substr((string)($in['notes'] ?? ''), 0, 255) ?: null, 'created_by' => Auth::id()]);
                foreach ($items as $i) {
                    $p = DB::row('SELECT id, name, cost_price FROM products WHERE id=?', [(int)$i['product_id']]);
                    if (!$p) continue;
                    DB::insert('transfer_items', ['transfer_id' => $id, 'product_id' => $p['id'], 'name' => $p['name'], 'qty' => (float)$i['qty'], 'unit_cost' => $p['cost_price']]);
                    Stock::move($from, (int)$p['id'], -(float)$i['qty'], 'transfer_out', 'transfer', $id, $no, (float)$p['cost_price'], false);
                }
                if (!empty($in['receive_now'])) self_receive($id);
                Audit::log('transfer_create', 'transfer', $id, "$no: " . count($items) . ' items dispatched');
                return ['id' => $id, 'transfer_no' => $no];
            });
        },
    ],
    'transfer_receive' => [
        'perm' => 'stock.transfer', 'write' => true,
        'fn' => function (array $in) {
            return DB::tx(function () use ($in) { self_receive((int)($in['id'] ?? 0)); return ['ok' => true]; });
        },
    ],
    'stocktake_sheet' => [
        'perm' => 'stock.take',
        'fn' => function (array $in) {
            $store = Auth::currentStore();
            $cat = (int)($in['category'] ?? 0);
            return DB::all('SELECT p.id, p.name, p.sku, p.unit, COALESCE(s.qty,0) qty FROM products p LEFT JOIN stock s ON s.product_id=p.id AND s.store_id=?
                WHERE p.is_active=1 AND p.track_stock=1' . ($cat ? ' AND p.category_id=?' : '') . ' ORDER BY p.name', $cat ? [$store, $cat] : [$store]);
        },
    ],
    'stocktake_post' => [
        'perm' => 'stock.take', 'write' => true,
        'fn' => function (array $in) {
            $store = Auth::currentStore();
            $counts = array_filter((array)($in['counts'] ?? []), static fn($c) => isset($c['counted']) && $c['counted'] !== '' && $c['counted'] !== null);
            if (!$counts) throw new ApiError('Enter at least one counted quantity');
            return DB::tx(function () use ($store, $counts, $in) {
                $no = Sequence::next('stocktake');
                $id = DB::insert('stock_takes', ['take_no' => $no, 'store_id' => $store, 'notes' => mb_substr((string)($in['notes'] ?? ''), 0, 255) ?: null, 'user_id' => Auth::id()]);
                $value = 0.0; $changed = 0;
                foreach ($counts as $c) {
                    $p = DB::row('SELECT id, name, cost_price FROM products WHERE id=?', [(int)$c['product_id']]);
                    if (!$p) continue;
                    $sys = (float)(DB::val('SELECT qty FROM stock WHERE store_id=? AND product_id=? FOR UPDATE', [$store, $p['id']]) ?? 0);
                    $cnt = (float)$c['counted'];
                    $var = round($cnt - $sys, 3);
                    DB::insert('stock_take_items', ['stock_take_id' => $id, 'product_id' => $p['id'], 'name' => $p['name'], 'system_qty' => $sys, 'counted_qty' => $cnt, 'variance' => $var, 'unit_cost' => $p['cost_price']]);
                    if ($var != 0) { Stock::move($store, (int)$p['id'], $var, 'stock_take', 'stock_take', $id, $no, (float)$p['cost_price'], true); $changed++; }
                    $value += $var * (float)$p['cost_price'];
                }
                DB::q('UPDATE stock_takes SET variance_value=? WHERE id=?', [round($value, 2), $id]);
                Audit::log('stock_take', 'stock_take', $id, "$no: " . count($counts) . " counted, $changed adjusted, variance " . money($value));
                return ['id' => $id, 'take_no' => $no, 'variance_value' => round($value, 2)];
            });
        },
    ],
    'stocktakes' => [
        'perm' => 'stock.take',
        'fn' => function (array $in) {
            $per = min(100, (int)($in['per'] ?? 25)); $off = (max(1, (int)($in['page'] ?? 1)) - 1) * $per;
            return ['total' => (int)DB::val('SELECT COUNT(*) FROM stock_takes'),
                'rows' => DB::all("SELECT t.*, s.name store, u.first_name user, (SELECT COUNT(*) FROM stock_take_items i WHERE i.stock_take_id=t.id) items FROM stock_takes t JOIN stores s ON s.id=t.store_id LEFT JOIN users u ON u.id=t.user_id ORDER BY t.id DESC LIMIT $per OFFSET $off")];
        },
    ],
];

function self_receive(int $id): void
{
    $t = DB::row('SELECT * FROM transfers WHERE id=? FOR UPDATE', [$id]);
    if (!$t || $t['status'] !== 'in_transit') throw new ApiError('Transfer not found or already received');
    if (!Auth::canUseStore((int)$t['to_store_id'])) throw new ApiError('Only staff of the receiving store can receive');
    foreach (DB::all('SELECT * FROM transfer_items WHERE transfer_id=?', [$id]) as $i) {
        Stock::move((int)$t['to_store_id'], (int)$i['product_id'], (float)$i['qty'], 'transfer_in', 'transfer', $id, $t['transfer_no'], (float)$i['unit_cost'], true);
    }
    DB::q('UPDATE transfers SET status="received", received_by=?, received_at=NOW() WHERE id=?', [Auth::id(), $id]);
    Audit::log('transfer_receive', 'transfer', $id, "Received {$t['transfer_no']}");
}
