<?php
function sales_scope(array &$where, array &$p): void
{
    if (!Auth::can('sales.view_all')) { $where[] = 's.user_id=?'; $p[] = Auth::id(); }
}

return [
    'list' => [
        'perm' => ['sell.create', 'sales.view_all'],
        'fn' => function (array $in) {
            $where = ['1=1']; $p = [];
            sales_scope($where, $p);
            if (!empty($in['from'])) { $where[] = 's.sold_at>=?'; $p[] = $in['from'] . ' 00:00:00'; }
            if (!empty($in['to'])) { $where[] = 's.sold_at<=?'; $p[] = $in['to'] . ' 23:59:59'; }
            if (!empty($in['store'])) { $where[] = 's.store_id=?'; $p[] = (int)$in['store']; }
            if (!empty($in['user'])) { $where[] = 's.user_id=?'; $p[] = (int)$in['user']; }
            if (!empty($in['status'])) {
                if (in_array($in['status'], ['paid', 'partial', 'credit'], true)) { $where[] = 's.payment_status=? AND s.status<>"voided"'; $p[] = $in['status']; }
                else { $where[] = 's.status=?'; $p[] = $in['status']; }
            }
            if (!empty($in['method'])) { $where[] = 'EXISTS (SELECT 1 FROM payments x WHERE x.sale_id=s.id AND x.method=? AND x.is_void=0)'; $p[] = $in['method']; }
            if (($q = trim((string)($in['q'] ?? ''))) !== '') { $where[] = '(s.invoice_no LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%"); }
            $w = implode(' AND ', $where);
            $per = min(200, max(5, (int)($in['per'] ?? 25))); $off = (max(1, (int)($in['page'] ?? 1)) - 1) * $per;
            $rows = DB::all("SELECT s.id, s.invoice_no, s.sold_at, s.total, s.paid, s.balance, s.status, s.payment_status, s.sale_type,
                    c.name customer, CONCAT(u.first_name,' ',COALESCE(u.last_name,'')) cashier, st.name store,
                    (SELECT GROUP_CONCAT(DISTINCT x.method) FROM payments x WHERE x.sale_id=s.id AND x.is_void=0) methods
                FROM sales s LEFT JOIN customers c ON c.id=s.customer_id LEFT JOIN users u ON u.id=s.user_id LEFT JOIN stores st ON st.id=s.store_id
                WHERE $w ORDER BY s.id DESC LIMIT $per OFFSET $off", $p);
            $agg = DB::row("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN s.status<>'voided' THEN s.total END),0) total, COALESCE(SUM(CASE WHEN s.status<>'voided' THEN s.balance END),0) balance
                FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE $w", $p);
            return ['rows' => $rows, 'total' => (int)$agg['n'], 'sum' => (float)$agg['total'], 'balance' => (float)$agg['balance']];
        },
    ],
    'view' => [
        'perm' => ['sell.create', 'sales.view_all'],
        'fn' => function (array $in) {
            $s = DB::row('SELECT s.*, c.name customer, c.phone customer_phone, c.email customer_email, CONCAT(u.first_name," ",COALESCE(u.last_name,"")) cashier, st.name store
                FROM sales s LEFT JOIN customers c ON c.id=s.customer_id LEFT JOIN users u ON u.id=s.user_id LEFT JOIN stores st ON st.id=s.store_id WHERE s.id=?', [(int)($in['id'] ?? 0)]);
            if (!$s) throw new ApiError('Sale not found', 404);
            if (!Auth::can('sales.view_all') && (int)$s['user_id'] !== Auth::id()) throw new ApiError('Not your sale', 403);
            if (!Auth::can('products.cost')) unset($s['cost_total']);
            return [
                'sale' => $s,
                'items' => DB::all('SELECT id, product_id, name, qty, unit_price, discount, tax, total, returned_qty' . (Auth::can('products.cost') ? ', unit_cost' : '') . ' FROM sale_items WHERE sale_id=?', [$s['id']]),
                'payments' => DB::all('SELECT p.id, p.receipt_no, p.kind, p.method, p.amount, p.reference, p.paid_at, p.is_void, a.name account FROM payments p LEFT JOIN payment_accounts a ON a.id=p.payment_account_id WHERE p.sale_id=? ORDER BY p.id', [$s['id']]),
                'credit_notes' => DB::all('SELECT id, cn_no, total, refund_amount, refund_method, created_at FROM credit_notes WHERE sale_id=?', [$s['id']]),
                'history' => Auth::can('audit.view') ? DB::all('SELECT a.action, a.summary, a.created_at, u.first_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE a.entity="sale" AND a.entity_id=? ORDER BY a.id', [(string)$s['id']]) : [],
            ];
        },
    ],
    'void' => [
        'perm' => 'sales.void', 'write' => true,
        'fn' => function (array $in) {
            $id = (int)($in['id'] ?? 0);
            $reason = trim((string)($in['reason'] ?? ''));
            if (mb_strlen($reason) < 3) throw new ApiError('Give a reason for voiding');
            return DB::tx(function () use ($id, $reason) {
                $s = DB::row('SELECT * FROM sales WHERE id=? FOR UPDATE', [$id]);
                if (!$s) throw new ApiError('Sale not found');
                if ($s['status'] === 'voided') throw new ApiError('Already voided');
                if ((float)$s['returned_total'] > 0) throw new ApiError('This sale has returns; void is not possible. Use returns instead.');
                foreach (DB::all('SELECT si.product_id, si.qty, si.unit_cost, p.track_stock FROM sale_items si JOIN products p ON p.id=si.product_id WHERE si.sale_id=?', [$id]) as $i) {
                    if ($i['track_stock']) Stock::move((int)$s['store_id'], (int)$i['product_id'], (float)$i['qty'], 'void', 'sale', $id, 'Void ' . $s['invoice_no'], (float)$i['unit_cost'], true);
                }
                DB::q('UPDATE payments SET is_void=1 WHERE sale_id=?', [$id]);
                DB::q('UPDATE mpesa_transactions SET sale_id=NULL, payment_id=NULL WHERE sale_id=?', [$id]);
                if ($s['coupon_id']) DB::q('UPDATE coupons SET used_count=GREATEST(used_count-1,0) WHERE id=?', [$s['coupon_id']]);
                DB::q('UPDATE sales SET status="voided", balance=0, void_reason=?, voided_by=? WHERE id=?', [$reason, Auth::id(), $id]);
                Audit::log('sale_void', 'sale', $id, "Voided {$s['invoice_no']} (" . money($s['total']) . "): $reason");
                return ['ok' => true];
            });
        },
    ],
    // Returns → credit note. items: [{sale_item_id, qty}]
    'return' => [
        'perm' => 'sales.return', 'write' => true,
        'fn' => function (array $in) {
            $saleId = (int)($in['sale_id'] ?? 0);
            $restock = !empty($in['restock']);
            $refundMethod = in_array($in['refund_method'] ?? 'none', ['cash', 'mpesa', 'bank', 'card', 'other', 'none'], true) ? $in['refund_method'] : 'none';
            return DB::tx(function () use ($saleId, $in, $restock, $refundMethod) {
                $s = DB::row('SELECT * FROM sales WHERE id=? FOR UPDATE', [$saleId]);
                if (!$s || $s['status'] === 'voided') throw new ApiError('Sale not found or voided');
                $lines = [];
                $total = 0.0;
                foreach ((array)($in['items'] ?? []) as $it) {
                    $q = round((float)($it['qty'] ?? 0), 3);
                    if ($q <= 0) continue;
                    $si = DB::row('SELECT * FROM sale_items WHERE id=? AND sale_id=?', [(int)$it['sale_item_id'], $saleId]);
                    if (!$si) continue;
                    if ($q > (float)$si['qty'] - (float)$si['returned_qty'] + 0.0001) throw new ApiError("Cannot return more {$si['name']} than was sold");
                    $unit = (float)$si['total'] / max(0.001, (float)$si['qty']);
                    $lt = round($unit * $q, 2);
                    $lines[] = [$si, $q, $unit, $lt];
                    $total += $lt;
                }
                if (!$lines) throw new ApiError('Choose items and quantities to return');
                $total = round($total, 2);
                $applied = round(min((float)$s['balance'], $total), 2);
                $refund = round($total - $applied, 2);
                if ($refund > 0 && $refundMethod === 'none') $refundMethod = 'cash';
                $no = Sequence::next('credit');
                $cnId = DB::insert('credit_notes', [
                    'cn_no' => $no, 'sale_id' => $saleId, 'store_id' => $s['store_id'], 'customer_id' => $s['customer_id'], 'total' => $total,
                    'applied_to_balance' => $applied, 'refund_amount' => $refund, 'refund_method' => $refund > 0 ? $refundMethod : 'none',
                    'restock' => $restock ? 1 : 0, 'reason' => mb_substr(trim((string)($in['reason'] ?? '')), 0, 255) ?: null, 'user_id' => Auth::id(),
                ]);
                foreach ($lines as [$si, $q, $unit, $lt]) {
                    DB::insert('credit_note_items', ['credit_note_id' => $cnId, 'sale_item_id' => $si['id'], 'product_id' => $si['product_id'], 'name' => $si['name'], 'qty' => $q, 'unit_price' => round($unit, 2), 'total' => $lt]);
                    DB::q('UPDATE sale_items SET returned_qty=returned_qty+? WHERE id=?', [$q, $si['id']]);
                    if ($restock) Stock::move((int)$s['store_id'], (int)$si['product_id'], $q, 'return', 'credit_note', $cnId, $no, (float)$si['unit_cost'], true);
                }
                if ($refund > 0) {
                    DB::insert('payments', ['kind' => 'refund', 'sale_id' => $saleId, 'customer_id' => $s['customer_id'], 'method' => $refundMethod, 'amount' => $refund,
                        'reference' => $no, 'store_id' => $s['store_id'], 'received_by' => Auth::id(), 'notes' => 'Refund']);
                }
                $returned = round((float)$s['returned_total'] + $total, 2);
                $fully = (float)DB::val('SELECT SUM(qty-returned_qty) FROM sale_items WHERE sale_id=?', [$saleId]) <= 0.0001;
                DB::q('UPDATE sales SET returned_total=?, status=? WHERE id=?', [$returned, $fully ? 'returned' : 'partially_returned', $saleId]);
                SaleService::refreshBalance($saleId);
                Audit::log('sale_return', 'sale', $saleId, "$no for {$s['invoice_no']}: " . money($total) . ($refund > 0 ? ', refunded ' . money($refund) . ' ' . $refundMethod : ''));
                return ['id' => $cnId, 'cn_no' => $no, 'total' => $total, 'refund' => $refund];
            });
        },
    ],
    'quotes' => [
        'perm' => 'quotes.manage',
        'fn' => function (array $in) {
            $where = ['1=1']; $p = [];
            if (!empty($in['status'])) { $where[] = 'q.status=?'; $p[] = $in['status']; }
            if (($s = trim((string)($in['q'] ?? ''))) !== '') { $where[] = '(q.quote_no LIKE ? OR c.name LIKE ?)'; array_push($p, "%$s%", "%$s%"); }
            $w = implode(' AND ', $where);
            $per = min(100, (int)($in['per'] ?? 25)); $off = (max(1, (int)($in['page'] ?? 1)) - 1) * $per;
            return [
                'total' => (int)DB::val("SELECT COUNT(*) FROM quotations q LEFT JOIN customers c ON c.id=q.customer_id WHERE $w", $p),
                'rows' => DB::all("SELECT q.*, c.name customer, s.invoice_no FROM quotations q LEFT JOIN customers c ON c.id=q.customer_id LEFT JOIN sales s ON s.id=q.sale_id WHERE $w ORDER BY q.id DESC LIMIT $per OFFSET $off", $p),
            ];
        },
    ],
    'quote_cancel' => [
        'perm' => 'quotes.manage', 'write' => true,
        'fn' => function (array $in) {
            $id = (int)($in['id'] ?? 0);
            DB::q('UPDATE quotations SET status="cancelled" WHERE id=? AND status="pending"', [$id]);
            Audit::log('quote_cancel', 'quotation', $id, 'Cancelled quotation');
            return ['ok' => true];
        },
    ],
    'shifts' => [
        'perm' => ['shifts.manage', 'shifts.view_all'],
        'fn' => function (array $in) {
            $where = Auth::can('shifts.view_all') ? '1=1' : 'sh.user_id=' . Auth::id();
            $per = min(100, (int)($in['per'] ?? 25)); $off = (max(1, (int)($in['page'] ?? 1)) - 1) * $per;
            $rows = DB::all("SELECT sh.*, CONCAT(u.first_name,' ',COALESCE(u.last_name,'')) cashier, st.name store FROM shifts sh JOIN users u ON u.id=sh.user_id JOIN stores st ON st.id=sh.store_id WHERE $where ORDER BY sh.id DESC LIMIT $per OFFSET $off");
            $mine = SaleService::openShift(Auth::id());
            return ['rows' => $rows, 'total' => (int)DB::val("SELECT COUNT(*) FROM shifts sh WHERE $where"), 'open' => $mine, 'open_summary' => $mine ? ShiftService::summary($mine) : null];
        },
    ],
    'shift_open' => [
        'perm' => 'shifts.manage', 'write' => true,
        'fn' => function (array $in) {
            if (SaleService::openShift(Auth::id())) throw new ApiError('You already have an open shift');
            $id = DB::insert('shifts', ['user_id' => Auth::id(), 'store_id' => Auth::currentStore(), 'opening_float' => round((float)($in['float'] ?? 0), 2)]);
            Audit::log('shift_open', 'shift', $id, 'Opened register with float ' . money($in['float'] ?? 0));
            return ['id' => $id];
        },
    ],
    'shift_close' => [
        'perm' => 'shifts.manage', 'write' => true,
        'fn' => function (array $in) {
            $s = SaleService::openShift(Auth::id());
            if (!$s) throw new ApiError('No open shift');
            $sum = ShiftService::summary($s);
            $counted = round((float)($in['counted'] ?? 0), 2);
            $var = round($counted - $sum['expected_cash'], 2);
            DB::q('UPDATE shifts SET closed_at=NOW(), expected_cash=?, counted_cash=?, variance=?, notes=? WHERE id=?', [$sum['expected_cash'], $counted, $var, mb_substr((string)($in['notes'] ?? ''), 0, 255) ?: null, $s['id']]);
            Audit::log('shift_close', 'shift', $s['id'], 'Closed register. Expected ' . money($sum['expected_cash']) . ', counted ' . money($counted) . ', variance ' . money($var));
            return ['id' => (int)$s['id'], 'variance' => $var, 'expected' => $sum['expected_cash']];
        },
    ],
];
