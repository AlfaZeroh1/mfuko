<?php
return [
    'list' => [
        'perm' => ['purchases.manage', 'suppliers.manage'],
        'fn' => function (array $in) {
            $where = ['1=1']; $p = [];
            if (!empty($in['supplier'])) { $where[] = 'p.supplier_id=?'; $p[] = (int)$in['supplier']; }
            if (($in['unpaid'] ?? '') === '1') $where[] = 'p.balance>0';
            if (($q = trim((string)($in['q'] ?? ''))) !== '') { $where[] = '(p.grn_no LIKE ? OR p.supplier_invoice LIKE ? OR s.name LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%"); }
            $w = implode(' AND ', $where);
            $per = min(100, (int)($in['per'] ?? 25)); $off = (max(1, (int)($in['page'] ?? 1)) - 1) * $per;
            return [
                'total' => (int)DB::val("SELECT COUNT(*) FROM purchases p JOIN suppliers s ON s.id=p.supplier_id WHERE $w", $p),
                'rows' => DB::all("SELECT p.*, s.name supplier, st.name store FROM purchases p JOIN suppliers s ON s.id=p.supplier_id JOIN stores st ON st.id=p.store_id WHERE $w ORDER BY p.id DESC LIMIT $per OFFSET $off", $p),
                'owed' => (float)DB::val('SELECT COALESCE(SUM(balance),0) FROM purchases'),
            ];
        },
    ],
    'create' => [
        'perm' => 'purchases.manage', 'write' => true,
        'fn' => function (array $in) {
            $supplier = DB::row('SELECT * FROM suppliers WHERE id=?', [(int)($in['supplier_id'] ?? 0)]);
            if (!$supplier) throw new ApiError('Choose a supplier');
            $store = (int)($in['store_id'] ?? Auth::currentStore());
            if (!Auth::canUseStore($store)) throw new ApiError('Not your store');
            $items = array_filter((array)($in['items'] ?? []), static fn($i) => (float)($i['qty'] ?? 0) > 0);
            if (!$items) throw new ApiError('Add items received');
            $updatePrices = !empty($in['update_prices']);
            return DB::tx(function () use ($supplier, $store, $items, $in, $updatePrices) {
                $no = Sequence::next('grn');
                $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['purchase_date'] ?? '')) ? $in['purchase_date'] : date('Y-m-d');
                $id = DB::insert('purchases', ['grn_no' => $no, 'supplier_id' => $supplier['id'], 'store_id' => $store, 'supplier_invoice' => trim((string)($in['supplier_invoice'] ?? '')) ?: null,
                    'purchase_date' => $date, 'notes' => mb_substr((string)($in['notes'] ?? ''), 0, 255) ?: null, 'user_id' => Auth::id()]);
                $sub = 0.0;
                foreach ($items as $i) {
                    $p = DB::row('SELECT * FROM products WHERE id=? FOR UPDATE', [(int)$i['product_id']]);
                    if (!$p) continue;
                    $q = (float)$i['qty']; $bonus = max(0, (float)($i['bonus'] ?? 0)); $cost = round((float)($i['cost'] ?? 0), 2); $disc = round((float)($i['discount'] ?? 0), 2);
                    $total = round($q * $cost - $disc, 2);
                    DB::insert('purchase_items', ['purchase_id' => $id, 'product_id' => $p['id'], 'name' => $p['name'], 'qty' => $q, 'bonus_qty' => $bonus, 'unit_cost' => $cost, 'discount' => $disc, 'total' => $total, 'expiry_date' => !empty($i['expiry']) ? $i['expiry'] : null]);
                    // Weighted average cost across all stores
                    $onHand = max(0, (float)DB::val('SELECT COALESCE(SUM(qty),0) FROM stock WHERE product_id=?', [$p['id']]));
                    $effCost = ($q + $bonus) > 0 ? $total / ($q + $bonus) : $cost;
                    $newCost = ($onHand + $q + $bonus) > 0 ? round(($onHand * (float)$p['cost_price'] + $total) / ($onHand + $q + $bonus), 2) : $effCost;
                    $upd = ['cost_price' => $newCost];
                    if ($updatePrices && !empty($i['retail'])) $upd['retail_price'] = round((float)$i['retail'], 2);
                    if ($updatePrices && !empty($i['wholesale'])) $upd['wholesale_price'] = round((float)$i['wholesale'], 2);
                    if (!empty($i['expiry'])) $upd['expiry_date'] = $i['expiry'];
                    DB::update('products', $upd, 'id=?', [$p['id']]);
                    Stock::move($store, (int)$p['id'], $q + $bonus, 'purchase', 'purchase', $id, $no, $effCost, true);
                    $sub += $total;
                }
                $ship = round((float)($in['shipping'] ?? 0), 2);
                $grand = round($sub + $ship, 2);
                $paid = 0.0;
                foreach ((array)($in['payments'] ?? []) as $pay) {
                    $amt = round((float)($pay['amount'] ?? 0), 2); if ($amt <= 0) continue;
                    $amt = min($amt, round($grand - $paid, 2)); if ($amt <= 0) break;
                    DB::insert('supplier_payments', ['voucher_no' => $no, 'supplier_id' => $supplier['id'], 'purchase_id' => $id, 'method' => in_array($pay['method'] ?? '', SaleService::METHODS, true) ? $pay['method'] : 'cash',
                        'amount' => $amt, 'reference' => trim((string)($pay['reference'] ?? '')) ?: null, 'user_id' => Auth::id()]);
                    $paid += $amt;
                }
                DB::update('purchases', ['subtotal' => round($sub, 2), 'shipping' => $ship, 'total' => $grand, 'paid' => $paid, 'balance' => round($grand - $paid, 2)], 'id=?', [$id]);
                Audit::log('purchase_create', 'purchase', $id, "$no from {$supplier['name']}: " . money($grand) . ($grand - $paid > 0 ? ', owed ' . money($grand - $paid) : ''));
                return ['id' => $id, 'grn_no' => $no];
            });
        },
    ],
    'view' => [
        'perm' => 'purchases.manage',
        'fn' => function (array $in) {
            $id = (int)($in['id'] ?? 0);
            return ['purchase' => DB::row('SELECT p.*, s.name supplier FROM purchases p JOIN suppliers s ON s.id=p.supplier_id WHERE p.id=?', [$id]),
                'items' => DB::all('SELECT * FROM purchase_items WHERE purchase_id=?', [$id])];
        },
    ],
    'return' => [
        'perm' => 'purchases.manage', 'write' => true,
        'fn' => function (array $in) {
            $pid = (int)($in['purchase_id'] ?? 0);
            return DB::tx(function () use ($pid, $in) {
                $pu = DB::row('SELECT * FROM purchases WHERE id=? FOR UPDATE', [$pid]);
                if (!$pu) throw new ApiError('Purchase not found');
                $no = Sequence::next('sreturn');
                $rid = DB::insert('supplier_returns', ['return_no' => $no, 'purchase_id' => $pid, 'supplier_id' => $pu['supplier_id'], 'store_id' => $pu['store_id'], 'reason' => mb_substr((string)($in['reason'] ?? ''), 0, 255) ?: null, 'user_id' => Auth::id()]);
                $total = 0.0;
                foreach ((array)($in['items'] ?? []) as $it) {
                    $q = (float)($it['qty'] ?? 0); if ($q <= 0) continue;
                    $pi = DB::row('SELECT * FROM purchase_items WHERE id=? AND purchase_id=?', [(int)$it['item_id'], $pid]);
                    if (!$pi) continue;
                    if ($q > (float)$pi['qty'] + (float)$pi['bonus_qty'] - (float)$pi['returned_qty'] + 0.0001) throw new ApiError("Too many {$pi['name']}");
                    $lt = round($q * (float)$pi['unit_cost'], 2);
                    DB::insert('supplier_return_items', ['supplier_return_id' => $rid, 'product_id' => $pi['product_id'], 'name' => $pi['name'], 'qty' => $q, 'unit_cost' => $pi['unit_cost'], 'total' => $lt]);
                    DB::q('UPDATE purchase_items SET returned_qty=returned_qty+? WHERE id=?', [$q, $pi['id']]);
                    Stock::move((int)$pu['store_id'], (int)$pi['product_id'], -$q, 'supplier_return', 'supplier_return', $rid, $no, (float)$pi['unit_cost'], false);
                    $total += $lt;
                }
                if ($total <= 0) throw new ApiError('Choose items to return');
                DB::q('UPDATE supplier_returns SET total=? WHERE id=?', [round($total, 2), $rid]);
                // MySQL applies SET left-to-right, so returned_total is already updated here.
                DB::q('UPDATE purchases SET returned_total=returned_total+?, balance=GREATEST(0, total-paid-returned_total) WHERE id=?', [$total, $pid]);
                Audit::log('supplier_return', 'purchase', $pid, "$no: returned " . money($total));
                return ['id' => $rid, 'return_no' => $no];
            });
        },
    ],
    'pay_supplier' => [
        'perm' => 'suppliers.manage', 'write' => true,
        'fn' => function (array $in) {
            $sid = (int)($in['supplier_id'] ?? 0);
            $amount = round((float)($in['amount'] ?? 0), 2);
            if ($amount <= 0) throw new ApiError('Enter an amount');
            return DB::tx(function () use ($sid, $amount, $in) {
                $open = DB::all('SELECT id, balance FROM purchases WHERE supplier_id=? AND balance>0 ORDER BY purchase_date, id FOR UPDATE', [$sid]);
                $owed = array_sum(array_map(static fn($r) => (float)$r['balance'], $open));
                if ($amount > $owed + 0.009) throw new ApiError('That is more than you owe this supplier (' . money($owed) . ')');
                $no = Sequence::next('voucher'); $left = $amount;
                $method = in_array($in['method'] ?? '', SaleService::METHODS, true) ? $in['method'] : 'cash';
                foreach ($open as $o) {
                    if ($left <= 0) break;
                    $take = round(min($left, (float)$o['balance']), 2);
                    DB::insert('supplier_payments', ['voucher_no' => $no, 'supplier_id' => $sid, 'purchase_id' => $o['id'], 'method' => $method, 'amount' => $take, 'reference' => trim((string)($in['reference'] ?? '')) ?: null, 'user_id' => Auth::id()]);
                    DB::q('UPDATE purchases SET paid=paid+?, balance=GREATEST(0, balance-?) WHERE id=?', [$take, $take, $o['id']]);
                    $left = round($left - $take, 2);
                }
                Audit::log('supplier_payment', 'supplier', $sid, "$no: paid " . money($amount) . ' to ' . DB::val('SELECT name FROM suppliers WHERE id=?', [$sid]));
                return ['voucher_no' => $no];
            });
        },
    ],
];
