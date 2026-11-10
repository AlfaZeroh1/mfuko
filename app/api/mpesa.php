<?php
return [
    'stk_push' => [
        'perm' => ['sell.create', 'debts.collect'], 'write' => true,
        'fn' => function (array $in) {
            $id = Mpesa::stkPush((string)($in['phone'] ?? ''), (float)($in['amount'] ?? 0), (string)($in['reference'] ?? 'POS'), Settings::get('business_name'));
            return ['id' => $id];
        },
    ],
    'status' => [
        'perm' => ['sell.create', 'debts.collect'],
        'fn' => function (array $in) {
            $tx = Mpesa::stkQuery((int)($in['id'] ?? 0));
            return ['status' => $tx['status'], 'mpesa_receipt' => $tx['mpesa_receipt'], 'amount' => (float)$tx['amount'], 'result_desc' => $tx['result_desc']];
        },
    ],
    'list' => [
        'perm' => 'mpesa.view',
        'fn' => function (array $in) {
            $where = ['1=1']; $p = [];
            if (!empty($in['status'])) { $where[] = 'm.status=?'; $p[] = $in['status']; }
            if (($in['unmatched'] ?? '') === '1') $where[] = 'm.payment_id IS NULL AND m.status="success"';
            if ($q = trim((string)($in['q'] ?? ''))) { $where[] = '(m.mpesa_receipt LIKE ? OR m.phone LIKE ? OR m.payer_name LIKE ? OR m.account_ref LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%"); }
            $w = implode(' AND ', $where);
            $per = min(100, max(10, (int)($in['per'] ?? 25))); $off = (max(1, (int)($in['page'] ?? 1)) - 1) * $per;
            return [
                'total' => (int)DB::val("SELECT COUNT(*) FROM mpesa_transactions m WHERE $w", $p),
                'rows' => DB::all("SELECT m.*, s.invoice_no FROM mpesa_transactions m LEFT JOIN sales s ON s.id=m.sale_id WHERE $w ORDER BY m.id DESC LIMIT $per OFFSET $off", $p),
                'unmatched' => (int)DB::val('SELECT COUNT(*) FROM mpesa_transactions WHERE payment_id IS NULL AND status="success"'),
            ];
        },
    ],
    // Attach an unmatched C2B payment to a customer's debt.
    'claim' => [
        'perm' => 'debts.collect', 'write' => true,
        'fn' => function (array $in) {
            $tx = DB::row('SELECT * FROM mpesa_transactions WHERE id=? AND status="success" AND payment_id IS NULL', [(int)($in['id'] ?? 0)]);
            if (!$tx) throw new ApiError('Payment not found or already used');
            $acc = DB::val('SELECT id FROM payment_accounts WHERE method="mpesa" AND is_active=1 ORDER BY sort_order LIMIT 1');
            return SaleService::receiveDebt((int)$in['customer_id'], (float)$tx['amount'], 'mpesa', $acc ? (int)$acc : null, $tx['mpesa_receipt'], null, (int)$tx['id']);
        },
    ],
    'register_c2b' => [
        'perm' => 'settings.manage', 'write' => true,
        'fn' => function () {
            $r = Mpesa::registerC2B();
            Audit::log('mpesa_register', 'settings', null, 'Registered C2B URLs');
            return $r;
        },
    ],
    'test' => [
        'perm' => 'settings.manage', 'write' => true,
        'fn' => function () {
            Mpesa::token();
            return ['ok' => true, 'callback' => Mpesa::callbackUrl('stk')];
        },
    ],
];
