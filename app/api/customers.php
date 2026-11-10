<?php
return [
    'search' => [
        'perm' => ['sell.create', 'customers.view'],
        'fn' => function (array $in) {
            $q = trim((string)($in['q'] ?? ''));
            if ($q === '') return [];
            $digits = preg_replace('/\D/', '', $q);
            $rows = DB::all(
                'SELECT c.id, c.name, c.phone, c.email, c.price_tier, c.credit_limit, c.discount,
                        (SELECT COALESCE(SUM(s.balance),0) FROM sales s WHERE s.customer_id=c.id AND s.status<>"voided") AS debt
                   FROM customers c
                  WHERE c.is_active=1 AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)
                  ORDER BY c.name LIMIT 15',
                ["%$q%", $digits !== '' ? '%' . substr($digits, -9) . '%' : "%$q%", "%$q%"]
            );
            foreach ($rows as &$r) { $r['debt'] = (float)$r['debt']; $r['credit_limit'] = (float)$r['credit_limit']; $r['discount'] = (float)$r['discount']; }
            return $rows;
        },
    ],
    'quick_add' => [
        'perm' => 'customers.manage', 'write' => true,
        'fn' => function (array $in) {
            $name = trim((string)($in['name'] ?? ''));
            if ($name === '') throw new ApiError('Name is required');
            $phone = trim((string)($in['phone'] ?? '')) ?: null;
            $email = trim((string)($in['email'] ?? '')) ?: null;
            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError('Email is not valid');
            if ($phone && ($dup = DB::row('SELECT id, name FROM customers WHERE phone=? AND is_active=1', [$phone]))) {
                throw new ApiError("{$dup['name']} already uses that phone number");
            }
            $id = DB::insert('customers', ['name' => $name, 'phone' => $phone, 'email' => $email, 'created_by' => Auth::id()]);
            Audit::log('create', 'customers', $id, "Created customer $name (from till)");
            return DB::row('SELECT id, name, phone, email, price_tier, credit_limit, discount, 0 AS debt FROM customers WHERE id=?', [$id]);
        },
    ],
    'profile' => [
        'perm' => 'customers.view',
        'fn' => function (array $in) {
            $id = (int)($in['id'] ?? 0);
            $c = DB::row('SELECT * FROM customers WHERE id=?', [$id]);
            if (!$c) throw new ApiError('Customer not found', 404);
            $stats = DB::row('SELECT COUNT(*) visits, COALESCE(SUM(total),0) spent, COALESCE(SUM(balance),0) debt, MAX(sold_at) last_visit, MIN(sold_at) first_visit,
                                     COALESCE(AVG(total),0) avg_basket
                                FROM sales WHERE customer_id=? AND status<>"voided"', [$id]);
            $oldest = DB::val('SELECT MIN(sold_at) FROM sales WHERE customer_id=? AND balance>0 AND status<>"voided"', [$id]);
            return [
                'customer' => $c,
                'stats' => $stats + ['days_overdue' => $oldest ? (int)floor((time() - strtotime($oldest)) / 86400) : 0],
                'open' => DB::all('SELECT id, invoice_no, sold_at, total, paid, balance, due_date FROM sales WHERE customer_id=? AND balance>0 AND status<>"voided" ORDER BY sold_at', [$id]),
                'top' => DB::all('SELECT si.name, SUM(si.qty) qty, SUM(si.total) total FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.customer_id=? AND s.status<>"voided" GROUP BY si.product_id, si.name ORDER BY total DESC LIMIT 6', [$id]),
                'messages' => DB::all('SELECT id, channel, kind, subject, status, created_at FROM outbox WHERE customer_id=? ORDER BY id DESC LIMIT 10', [$id]),
            ];
        },
    ],
    // Chronological statement: invoices (debit), payments & returns (credit).
    'statement' => [
        'perm' => 'customers.view',
        'fn' => function (array $in) {
            return CustomerStatement::build((int)($in['id'] ?? 0), $in['from'] ?? null, $in['to'] ?? null);
        },
    ],
    'sales' => [
        'perm' => 'customers.view',
        'fn' => function (array $in) {
            $id = (int)($in['id'] ?? 0);
            $per = min(100, (int)($in['per'] ?? 25)); $off = (max(1, (int)($in['page'] ?? 1)) - 1) * $per;
            return [
                'total' => (int)DB::val('SELECT COUNT(*) FROM sales WHERE customer_id=?', [$id]),
                'rows' => DB::all("SELECT id, invoice_no, sold_at, total, paid, balance, status, payment_status FROM sales WHERE customer_id=? ORDER BY id DESC LIMIT $per OFFSET $off", [$id]),
            ];
        },
    ],
    'pay' => [
        'perm' => 'debts.collect', 'write' => true,
        'fn' => function (array $in) {
            return SaleService::receiveDebt(
                (int)($in['customer_id'] ?? 0), (float)($in['amount'] ?? 0), (string)($in['method'] ?? 'cash'),
                !empty($in['account_id']) ? (int)$in['account_id'] : null, trim((string)($in['reference'] ?? '')) ?: null,
                !empty($in['sale_id']) ? (int)$in['sale_id'] : null, !empty($in['mpesa_tx_id']) ? (int)$in['mpesa_tx_id'] : null
            );
        },
    ],
    // Receivables with ageing buckets
    'debtors' => [
        'perm' => ['debts.collect', 'customers.view'],
        'fn' => function (array $in) {
            $q = trim((string)($in['q'] ?? ''));
            $p = []; $w = '';
            if ($q !== '') { $w = ' AND (c.name LIKE ? OR c.phone LIKE ?)'; $p = ["%$q%", "%$q%"]; }
            $rows = DB::all("SELECT c.id, c.name, c.phone, c.email, c.credit_limit, c.last_reminder_at,
                    SUM(s.balance) debt, COUNT(s.id) invoices, MIN(s.sold_at) oldest,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), s.sold_at) <= 30 THEN s.balance ELSE 0 END) b0,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), s.sold_at) BETWEEN 31 AND 60 THEN s.balance ELSE 0 END) b30,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), s.sold_at) BETWEEN 61 AND 90 THEN s.balance ELSE 0 END) b60,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), s.sold_at) > 90 THEN s.balance ELSE 0 END) b90
                  FROM sales s JOIN customers c ON c.id=s.customer_id
                 WHERE s.balance>0 AND s.status<>'voided' $w
                 GROUP BY c.id ORDER BY debt DESC", $p);
            $tot = ['debt' => 0, 'b0' => 0, 'b30' => 0, 'b60' => 0, 'b90' => 0];
            foreach ($rows as $r) foreach ($tot as $k => $_) $tot[$k] += (float)$r[$k];
            return ['rows' => $rows, 'total' => count($rows), 'totals' => $tot];
        },
    ],
];
