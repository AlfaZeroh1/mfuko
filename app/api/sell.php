<?php
/** Sell screen endpoints — kept lean; the catalogue is cached on the device. */
return [
    // Products changed since ?since (unix ts) + full stock map for the store.
    'catalog' => [
        'perm' => 'sell.create',
        'fn' => function (array $in) {
            $since = (int)($in['since'] ?? 0);
            $storeId = Auth::currentStore();
            $sql = 'SELECT p.id, p.name, p.sku, p.barcode, p.category_id AS cat, p.unit, p.image, p.retail_price AS rp, p.wholesale_price AS wp,
                           p.min_price AS mp, p.tax_rate AS tr, p.is_favorite AS fav, p.track_stock AS ts, p.is_active AS act, UNIX_TIMESTAMP(p.updated_at) AS u
                      FROM products p' . ($since ? ' WHERE p.updated_at >= FROM_UNIXTIME(?)' : ' WHERE p.is_active=1') . ' ORDER BY p.name';
            $products = DB::all($sql, $since ? [$since - 1] : []);
            foreach ($products as &$p) {
                foreach (['rp', 'wp', 'mp', 'tr'] as $k) $p[$k] = (float)$p[$k];
                $p['id'] = (int)$p['id']; $p['cat'] = $p['cat'] === null ? null : (int)$p['cat'];
            }
            unset($p);
            $stock = [];
            foreach (DB::all('SELECT product_id, qty FROM stock WHERE store_id=?', [$storeId]) as $s) $stock[(int)$s['product_id']] = (float)$s['qty'];
            return [
                'now' => time(), 'full' => !$since, 'store' => $storeId, 'products' => $products, 'stock' => $stock,
                'categories' => DB::all('SELECT id, name, color FROM categories WHERE is_active=1 ORDER BY name'),
            ];
        },
    ],

    // Everything else the till needs, loaded once per page view.
    'bootstrap' => [
        'perm' => 'sell.create',
        'fn' => function (array $in) {
            $u = Auth::user();
            return [
                'store' => Auth::currentStore(),
                'accounts' => DB::all('SELECT id, method, name, provider, account_type, account_number, requires_reference AS ref, stk_enabled AS stk FROM payment_accounts WHERE is_active=1 ORDER BY sort_order, id'),
                'promotions' => DB::all('SELECT id, name, type, value, scope, scope_id, sale_type FROM promotions WHERE is_active=1 AND (starts_at IS NULL OR starts_at<=CURDATE()) AND (ends_at IS NULL OR ends_at>=CURDATE())'),
                'settings' => [
                    'tax_mode' => Settings::get('tax_mode'), 'max_discount_pct' => (float)Settings::get('max_discount_pct'),
                    'allow_negative' => Settings::bool('allow_negative_stock'), 'show_images' => Settings::bool('sell_show_images'),
                    'default_sale_type' => Settings::get('default_sale_type'), 'auto_print' => Settings::bool('auto_print'),
                    'receipt_size' => Settings::get('print_receipt_size'), 'mpesa' => Settings::bool('mpesa_enabled'),
                    'require_shift' => Settings::bool('require_shift'),
                ],
                'shift' => SaleService::openShift((int)$u['id']),
                'recent_customers' => DB::all('SELECT c.id, c.name, c.phone, c.price_tier, c.credit_limit, c.discount FROM customers c
                    JOIN (SELECT customer_id, MAX(id) mx FROM sales WHERE customer_id IS NOT NULL GROUP BY customer_id ORDER BY mx DESC LIMIT 12) r ON r.customer_id=c.id
                    WHERE c.is_active=1 ORDER BY r.mx DESC'),
            ];
        },
    ],

    // Server-side price preview (coupon validation, promos, customer discount).
    'price' => [
        'perm' => 'sell.create', 'write' => true,
        'fn' => function (array $in) {
            $customer = !empty($in['customer_id']) ? DB::row('SELECT * FROM customers WHERE id=?', [(int)$in['customer_id']]) : null;
            $r = SaleService::price((array)($in['items'] ?? []), (string)($in['sale_type'] ?? 'retail'), $customer, $in['coupon'] ?? null, (float)($in['shipping'] ?? 0));
            unset($r['cost_total']);
            foreach ($r['lines'] as &$l) unset($l['unit_cost']);
            if ($r['coupon']) $r['coupon'] = ['code' => $r['coupon']['code'], 'description' => $r['coupon']['description']];
            return $r;
        },
    ],

    'checkout' => [
        'perm' => 'sell.create', 'write' => true,
        'fn' => fn(array $in) => SaleService::checkout($in),
    ],

    'quote' => [
        'perm' => 'quotes.manage', 'write' => true,
        'fn' => function (array $in) {
            $customer = !empty($in['customer_id']) ? DB::row('SELECT * FROM customers WHERE id=?', [(int)$in['customer_id']]) : null;
            $r = SaleService::price((array)($in['items'] ?? []), (string)($in['sale_type'] ?? 'retail'), $customer, null, (float)($in['shipping'] ?? 0));
            return DB::tx(function () use ($r, $in, $customer) {
                $no = Sequence::next('quote');
                $id = DB::insert('quotations', [
                    'quote_no' => $no, 'store_id' => Auth::currentStore(), 'customer_id' => $customer['id'] ?? null, 'user_id' => Auth::id(),
                    'sale_type' => $r['sale_type'], 'subtotal' => $r['subtotal'], 'discount_total' => $r['discount_total'], 'tax_total' => $r['tax_total'],
                    'shipping' => $r['shipping'], 'total' => $r['total'],
                    'valid_until' => date('Y-m-d', strtotime('+' . (int)Settings::get('quote_valid_days') . ' days')),
                    'notes' => mb_substr(trim((string)($in['notes'] ?? '')), 0, 255) ?: null,
                ]);
                foreach ($r['lines'] as $l) {
                    DB::insert('quotation_items', ['quotation_id' => $id, 'product_id' => $l['product_id'], 'name' => $l['name'], 'qty' => $l['qty'],
                        'unit_price' => $l['unit_price'], 'discount' => $l['discount'], 'tax_rate' => $l['tax_rate'], 'tax' => $l['tax'], 'total' => $l['total']]);
                }
                Audit::log('quote_create', 'quotation', $id, "$no · " . money($r['total']));
                return ['id' => $id, 'quote_no' => $no, 'total' => $r['total']];
            });
        },
    ],

    // Load a quotation (or a past sale for "sell again") into the cart.
    'load' => [
        'perm' => 'sell.create',
        'fn' => function (array $in) {
            if (!empty($in['quote'])) {
                $q = DB::row('SELECT * FROM quotations WHERE id=?', [(int)$in['quote']]);
                if (!$q) throw new ApiError('Quotation not found');
                $items = DB::all('SELECT product_id, qty, unit_price, discount FROM quotation_items WHERE quotation_id=?', [$q['id']]);
                return ['quotation_id' => (int)$q['id'], 'customer' => $q['customer_id'] ? DB::row('SELECT id, name, phone, price_tier, credit_limit, discount FROM customers WHERE id=?', [$q['customer_id']]) : null,
                    'sale_type' => $q['sale_type'], 'items' => $items, 'status' => $q['status']];
            }
            $s = DB::row('SELECT * FROM sales WHERE id=?', [(int)($in['sale'] ?? 0)]);
            if (!$s) throw new ApiError('Sale not found');
            return ['customer' => $s['customer_id'] ? DB::row('SELECT id, name, phone, price_tier, credit_limit, discount FROM customers WHERE id=?', [$s['customer_id']]) : null,
                'sale_type' => $s['sale_type'], 'items' => DB::all('SELECT product_id, qty FROM sale_items WHERE sale_id=?', [$s['id']])];
        },
    ],
];
