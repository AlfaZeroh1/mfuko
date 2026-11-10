<?php
declare(strict_types=1);

/**
 * Pricing + checkout. The server is always authoritative: the client sends
 * product ids, quantities and requested prices/discounts; we recompute.
 */
final class SaleService
{
    public const METHODS = ['cash', 'mpesa', 'bank', 'card', 'other'];

    /** Active promotions for today, cached per request. */
    public static function promotions(): array
    {
        static $promos = null;
        if ($promos === null) {
            $promos = DB::all('SELECT * FROM promotions WHERE is_active=1 AND (starts_at IS NULL OR starts_at<=CURDATE()) AND (ends_at IS NULL OR ends_at>=CURDATE())');
        }
        return $promos;
    }

    /** Best automatic promo discount per unit for a product. */
    public static function promoPerUnit(array $product, float $price, string $saleType): array
    {
        $best = 0.0; $name = null;
        foreach (self::promotions() as $pr) {
            if ($pr['sale_type'] !== 'any' && $pr['sale_type'] !== $saleType) continue;
            if ($pr['scope'] === 'category' && (int)$pr['scope_id'] !== (int)$product['category_id']) continue;
            if ($pr['scope'] === 'product' && (int)$pr['scope_id'] !== (int)$product['id']) continue;
            $d = $pr['type'] === 'percent' ? $price * (float)$pr['value'] / 100 : (float)$pr['value'];
            $d = min($d, $price);
            if ($d > $best) { $best = $d; $name = $pr['name']; }
        }
        return [round($best, 2), $name];
    }

    /**
     * Price a cart. $items: [{product_id, qty, unit_price?, discount?}]
     * Returns normalized lines + totals. Throws ApiError on rule violations.
     */
    public static function price(array $items, string $saleType, ?array $customer, ?string $couponCode, float $shipping = 0): array
    {
        if (!$items) throw new ApiError('The cart is empty');
        $saleType = $saleType === 'wholesale' ? 'wholesale' : 'retail';
        if ($customer && $customer['price_tier'] === 'wholesale') $saleType = 'wholesale';
        $ids = array_values(array_unique(array_map(static fn($i) => (int)($i['product_id'] ?? 0), $items)));
        $products = [];
        foreach (DB::all('SELECT * FROM products WHERE id IN (' . DB::in($ids) . ')', $ids) as $p) $products[(int)$p['id']] = $p;

        $taxInclusive = Settings::get('tax_mode') !== 'exclusive';
        $maxDiscPct = (float)Settings::get('max_discount_pct');
        $owner = Auth::isOwner();
        $custPct = $customer ? (float)$customer['discount'] : 0.0;

        $lines = [];
        $subtotal = $discountTotal = $taxTotal = $lineSum = $costTotal = 0.0;
        foreach ($items as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $p = $products[$pid] ?? null;
            if (!$p || !$p['is_active']) throw new ApiError('A product in the cart is no longer available');
            $q = round((float)($it['qty'] ?? 0), 3);
            if ($q <= 0) throw new ApiError("Quantity for {$p['name']} must be more than zero");

            $base = $saleType === 'wholesale' && (float)$p['wholesale_price'] > 0 ? (float)$p['wholesale_price'] : (float)$p['retail_price'];
            $price = $base;
            if (isset($it['unit_price']) && $it['unit_price'] !== '' && abs((float)$it['unit_price'] - $base) > 0.004) {
                if (!Auth::can('sell.price_override')) throw new ApiError("You cannot change the price of {$p['name']}");
                $price = round((float)$it['unit_price'], 2);
                if (!$owner && (float)$p['min_price'] > 0 && $price < (float)$p['min_price']) {
                    throw new ApiError("{$p['name']} cannot be sold below " . money($p['min_price']));
                }
            }
            $gross = round($price * $q, 2);
            [$promoUnit, $promoName] = self::promoPerUnit($p, $price, $saleType);
            $promo = round($promoUnit * $q, 2);
            $manual = round(max(0, (float)($it['discount'] ?? 0)), 2);
            if ($manual > 0) {
                if (!Auth::can('sell.discount')) throw new ApiError('You are not allowed to give discounts');
                if (!$owner && $maxDiscPct > 0 && $manual > $gross * $maxDiscPct / 100 + 0.01) {
                    throw new ApiError("Discount on {$p['name']} exceeds the {$maxDiscPct}% limit");
                }
            }
            $cust = round(($gross - $promo - $manual) * $custPct / 100, 2);
            $disc = min($gross, $promo + $manual + $cust);
            $net = round($gross - $disc, 2);
            $rate = (float)$p['tax_rate'];
            $tax = $rate > 0 ? round($taxInclusive ? $net * $rate / (100 + $rate) : $net * $rate / 100, 2) : 0.0;
            $total = $taxInclusive ? $net : round($net + $tax, 2);

            $lines[] = [
                'product_id' => $pid, 'name' => $p['name'], 'qty' => $q, 'unit_cost' => (float)$p['cost_price'],
                'unit_price' => $price, 'discount' => $disc, 'tax_rate' => $rate, 'tax' => $tax, 'total' => $total,
                'promo' => $promoName, 'track_stock' => (int)$p['track_stock'],
            ];
            $subtotal += $gross; $discountTotal += $disc; $taxTotal += $tax; $lineSum += $total;
            $costTotal += (float)$p['cost_price'] * $q;
        }

        // Coupon on the post-discount amount
        $coupon = null; $couponDisc = 0.0;
        if ($couponCode = trim((string)$couponCode)) {
            $coupon = self::validateCoupon($couponCode, $lineSum, $customer ? (int)$customer['id'] : null);
            $couponDisc = $coupon['type'] === 'percent' ? $lineSum * (float)$coupon['value'] / 100 : (float)$coupon['value'];
            if ($coupon['max_discount'] !== null) $couponDisc = min($couponDisc, (float)$coupon['max_discount']);
            $couponDisc = round(min($couponDisc, $lineSum), 2);
        }
        $shipping = round(max(0, $shipping), 2);
        $total = round($lineSum - $couponDisc + $shipping, 2);

        return [
            'sale_type' => $saleType, 'lines' => $lines,
            'subtotal' => r2($subtotal), 'discount_total' => r2($discountTotal), 'tax_total' => r2($taxTotal),
            'coupon' => $coupon, 'coupon_discount' => $couponDisc, 'shipping' => $shipping,
            'total' => $total, 'cost_total' => r2($costTotal),
        ];
    }

    public static function validateCoupon(string $code, float $amount, ?int $customerId): array
    {
        $c = DB::row('SELECT * FROM coupons WHERE code=?', [strtoupper($code)]);
        if (!$c || !$c['is_active']) throw new ApiError('Coupon code not recognised');
        $today = date('Y-m-d');
        if ($c['starts_at'] && $c['starts_at'] > $today) throw new ApiError('This coupon is not active yet');
        if ($c['ends_at'] && $c['ends_at'] < $today) throw new ApiError('This coupon has expired');
        if ($c['max_uses'] !== null && (int)$c['used_count'] >= (int)$c['max_uses']) throw new ApiError('This coupon has been fully used');
        if ($c['customer_id'] && (int)$c['customer_id'] !== (int)$customerId) throw new ApiError('This coupon belongs to another customer');
        if ($amount + 0.001 < (float)$c['min_spend']) throw new ApiError('Coupon needs a minimum spend of ' . money($c['min_spend']));
        return $c;
    }

    public static function openShift(int $userId): ?array
    {
        return DB::row('SELECT * FROM shifts WHERE user_id=? AND closed_at IS NULL ORDER BY id DESC LIMIT 1', [$userId]);
    }

    public static function customerDebt(int $customerId): float
    {
        return (float)DB::val('SELECT COALESCE(SUM(balance),0) FROM sales WHERE customer_id=? AND status<>"voided"', [$customerId]);
    }

    /** Full checkout. Idempotent on client_ref (offline queue retries). */
    public static function checkout(array $in): array
    {
        $clientRef = substr(preg_replace('/[^A-Za-z0-9\-]/', '', (string)($in['client_ref'] ?? '')), 0, 40) ?: null;
        if ($clientRef && ($existing = DB::row('SELECT id, invoice_no, total, paid, balance, change_given FROM sales WHERE client_ref=?', [$clientRef]))) {
            return self::summary((int)$existing['id']) + ['duplicate' => true];
        }

        $storeId = (int)($in['store_id'] ?? Auth::currentStore());
        if (!Auth::canUseStore($storeId)) throw new ApiError('You cannot sell from this store');
        $user = Auth::user();
        $shift = self::openShift((int)$user['id']);
        if (Settings::bool('require_shift') && !$shift) throw new ApiError('Open your register (shift) before selling');

        $customer = null;
        if (!empty($in['customer_id'])) {
            $customer = DB::row('SELECT * FROM customers WHERE id=? AND is_active=1', [(int)$in['customer_id']]);
            if (!$customer) throw new ApiError('Customer not found');
        }

        $priced = self::price((array)($in['items'] ?? []), (string)($in['sale_type'] ?? 'retail'), $customer, $in['coupon'] ?? null, (float)($in['shipping'] ?? 0));
        $total = $priced['total'];

        // ---- Payments: split across methods; cash may be over-tendered ----
        $payments = [];
        $nonCash = 0.0; $cashTendered = 0.0;
        foreach ((array)($in['payments'] ?? []) as $pay) {
            $amt = round((float)($pay['amount'] ?? 0), 2);
            if ($amt <= 0) continue;
            $method = in_array($pay['method'] ?? '', self::METHODS, true) ? $pay['method'] : 'cash';
            $acc = !empty($pay['account_id']) ? DB::row('SELECT * FROM payment_accounts WHERE id=? AND is_active=1', [(int)$pay['account_id']]) : null;
            if ($acc) $method = $acc['method'];
            $ref = trim((string)($pay['reference'] ?? ''));
            if ($acc && $acc['requires_reference'] && $ref === '' && empty($pay['mpesa_tx_id'])) {
                throw new ApiError("Enter the reference/transaction code for {$acc['name']}");
            }
            $payments[] = ['method' => $method, 'account_id' => $acc ? (int)$acc['id'] : null, 'amount' => $amt, 'reference' => $ref ?: null, 'mpesa_tx_id' => !empty($pay['mpesa_tx_id']) ? (int)$pay['mpesa_tx_id'] : null];
            if ($method === 'cash') $cashTendered += $amt; else $nonCash += $amt;
        }
        if ($nonCash > $total + 0.009) throw new ApiError('Non-cash payments are more than the total. Only cash can give change.');
        $cashApplied = min($cashTendered, max(0, $total - $nonCash));
        $change = round($cashTendered - $cashApplied, 2);
        $paid = round($nonCash + $cashApplied, 2);
        $balance = round($total - $paid, 2);

        if ($balance > 0.009) {
            if (!$customer) throw new ApiError('Choose a customer to sell on credit');
            if (!Auth::can('sell.credit')) throw new ApiError('You are not allowed to sell on credit');
            $limit = (float)$customer['credit_limit'];
            if ($limit > 0 && Settings::bool('enforce_credit_limit') && !Auth::isOwner()) {
                $debt = self::customerDebt((int)$customer['id']);
                if ($debt + $balance > $limit + 0.009) {
                    throw new ApiError(sprintf('Credit limit exceeded: owes %s, limit %s', money($debt), money($limit)));
                }
            }
        } else {
            $balance = 0.0;
        }

        $soldAt = date('Y-m-d H:i:s');
        if (!empty($in['sold_at']) && Auth::can('sell.backdate')) {
            $ts = strtotime((string)$in['sold_at']);
            if ($ts && $ts <= time()) $soldAt = date('Y-m-d H:i:s', $ts);
        }

        return DB::tx(function () use ($in, $priced, $payments, $customer, $storeId, $user, $shift, $clientRef, $total, $paid, $balance, $change, $cashApplied, $cashTendered, $soldAt) {
            $invoice = Sequence::next('invoice');
            $saleId = DB::insert('sales', [
                'invoice_no' => $invoice, 'client_ref' => $clientRef, 'store_id' => $storeId,
                'customer_id' => $customer['id'] ?? null, 'user_id' => $user['id'], 'shift_id' => $shift['id'] ?? null,
                'quotation_id' => !empty($in['quotation_id']) ? (int)$in['quotation_id'] : null,
                'sale_type' => $priced['sale_type'], 'subtotal' => $priced['subtotal'], 'discount_total' => $priced['discount_total'],
                'coupon_id' => $priced['coupon']['id'] ?? null, 'coupon_discount' => $priced['coupon_discount'],
                'tax_total' => $priced['tax_total'], 'shipping' => $priced['shipping'], 'total' => $total,
                'cost_total' => $priced['cost_total'], 'paid' => $paid, 'balance' => $balance, 'change_given' => $change,
                'payment_status' => $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'credit'),
                'due_date' => $balance > 0 ? date('Y-m-d', strtotime('+' . (int)($customer['credit_days'] ?? 30) . ' days')) : null,
                'notes' => mb_substr(trim((string)($in['notes'] ?? '')), 0, 255) ?: null,
                'sold_at' => $soldAt,
            ]);

            $stockOut = [];
            foreach ($priced['lines'] as $l) {
                DB::insert('sale_items', [
                    'sale_id' => $saleId, 'product_id' => $l['product_id'], 'name' => $l['name'], 'qty' => $l['qty'],
                    'unit_cost' => $l['unit_cost'], 'unit_price' => $l['unit_price'], 'discount' => $l['discount'],
                    'tax_rate' => $l['tax_rate'], 'tax' => $l['tax'], 'total' => $l['total'],
                ]);
                if ($l['track_stock']) {
                    $stockOut[$l['product_id']] = Stock::move($storeId, $l['product_id'], -$l['qty'], 'sale', 'sale', $saleId, $invoice, $l['unit_cost']);
                }
            }

            // Cash rows store the applied amount (net of change).
            $cashLeft = $cashApplied;
            foreach ($payments as $pay) {
                $amount = $pay['amount'];
                if ($pay['method'] === 'cash') { $amount = min($amount, $cashLeft); $cashLeft -= $amount; if ($amount <= 0) continue; }
                $payId = DB::insert('payments', [
                    'kind' => 'sale', 'sale_id' => $saleId, 'customer_id' => $customer['id'] ?? null,
                    'payment_account_id' => $pay['account_id'], 'method' => $pay['method'], 'amount' => $amount,
                    'reference' => $pay['reference'], 'mpesa_tx_id' => $pay['mpesa_tx_id'], 'store_id' => $storeId,
                    'shift_id' => $shift['id'] ?? null, 'received_by' => $user['id'], 'paid_at' => $soldAt,
                ]);
                if ($pay['mpesa_tx_id']) {
                    $tx = DB::row('SELECT * FROM mpesa_transactions WHERE id=? FOR UPDATE', [$pay['mpesa_tx_id']]);
                    if (!$tx || $tx['status'] !== 'success') throw new ApiError('That M-Pesa payment is not confirmed yet');
                    if ($tx['payment_id']) throw new ApiError('That M-Pesa payment was already used on another sale');
                    DB::q('UPDATE mpesa_transactions SET sale_id=?, payment_id=? WHERE id=?', [$saleId, $payId, $tx['id']]);
                    DB::q('UPDATE payments SET reference=? WHERE id=?', [$tx['mpesa_receipt'], $payId]);
                }
            }

            if ($priced['coupon']) DB::q('UPDATE coupons SET used_count=used_count+1 WHERE id=?', [$priced['coupon']['id']]);
            if (!empty($in['quotation_id'])) {
                DB::q('UPDATE quotations SET status="converted", sale_id=? WHERE id=? AND status="pending"', [$saleId, (int)$in['quotation_id']]);
            }

            Audit::log('sale_create', 'sale', $saleId, sprintf('%s · %s · %d items%s', $invoice, money($total), count($priced['lines']), $balance > 0 ? ' · credit ' . money($balance) : ''));

            return self::summary($saleId) + ['stock' => $stockOut, 'cash_tendered' => $cashTendered];
        });
    }

    public static function summary(int $saleId): array
    {
        $s = DB::row('SELECT id, invoice_no, total, paid, balance, change_given, customer_id, sold_at FROM sales WHERE id=?', [$saleId]);
        return [
            'id' => (int)$s['id'], 'invoice_no' => $s['invoice_no'], 'total' => (float)$s['total'], 'paid' => (float)$s['paid'],
            'balance' => (float)$s['balance'], 'change' => (float)$s['change_given'], 'sold_at' => $s['sold_at'],
        ];
    }

    /** Recalculate paid/balance/payment_status of a sale from its ledger. */
    public static function refreshBalance(int $saleId): void
    {
        $s = DB::row('SELECT total, returned_total FROM sales WHERE id=? FOR UPDATE', [$saleId]);
        if (!$s) return;
        $paid = (float)DB::val('SELECT COALESCE(SUM(amount),0) FROM payments WHERE sale_id=? AND is_void=0 AND kind<>"refund"', [$saleId]);
        $applied = (float)DB::val('SELECT COALESCE(SUM(applied_to_balance),0) FROM credit_notes WHERE sale_id=?', [$saleId]);
        $balance = max(0, round((float)$s['total'] - $paid - $applied, 2));
        DB::q('UPDATE sales SET paid=?, balance=?, payment_status=? WHERE id=?', [
            round($paid, 2), $balance, $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'credit'), $saleId,
        ]);
    }

    /** Debt repayment: allocate FIFO across the customer's open invoices. */
    public static function receiveDebt(int $customerId, float $amount, string $method, ?int $accountId, ?string $reference, ?int $saleId = null, ?int $mpesaTxId = null): array
    {
        $amount = round($amount, 2);
        if ($amount <= 0) throw new ApiError('Enter an amount');
        return DB::tx(function () use ($customerId, $amount, $method, $accountId, $reference, $saleId, $mpesaTxId) {
            $sql = 'SELECT id, invoice_no, balance FROM sales WHERE customer_id=? AND balance>0 AND status<>"voided"' . ($saleId ? ' AND id=?' : '') . ' ORDER BY sold_at, id FOR UPDATE';
            $open = DB::all($sql, $saleId ? [$customerId, $saleId] : [$customerId]);
            $debt = array_sum(array_map(static fn($r) => (float)$r['balance'], $open));
            if ($debt <= 0) throw new ApiError('This customer has no outstanding balance');
            if ($amount > $debt + 0.009) throw new ApiError('Amount is more than the outstanding balance of ' . money($debt));
            if ($accountId) {
                $acc = DB::row('SELECT method FROM payment_accounts WHERE id=?', [$accountId]);
                if ($acc) $method = $acc['method'];
            }
            if (!in_array($method, self::METHODS, true)) $method = 'cash';
            $receipt = Sequence::next('receipt');
            $shift = self::openShift(Auth::id());
            $left = $amount; $alloc = [];
            foreach ($open as $o) {
                if ($left <= 0) break;
                $take = round(min($left, (float)$o['balance']), 2);
                $pid = DB::insert('payments', [
                    'receipt_no' => $receipt, 'kind' => 'debt', 'sale_id' => $o['id'], 'customer_id' => $customerId,
                    'payment_account_id' => $accountId, 'method' => $method, 'amount' => $take, 'reference' => $reference,
                    'mpesa_tx_id' => $mpesaTxId, 'store_id' => Auth::currentStore() ?: null, 'shift_id' => $shift['id'] ?? null,
                    'received_by' => Auth::id(),
                ]);
                if ($mpesaTxId && !$alloc) DB::q('UPDATE mpesa_transactions SET payment_id=? WHERE id=?', [$pid, $mpesaTxId]);
                self::refreshBalance((int)$o['id']);
                $alloc[] = ['invoice_no' => $o['invoice_no'], 'amount' => $take];
                $left = round($left - $take, 2);
            }
            $name = DB::val('SELECT name FROM customers WHERE id=?', [$customerId]);
            Audit::log('debt_payment', 'customer', $customerId, "$receipt · " . money($amount) . " from $name via " . method_label($method));
            return ['receipt_no' => $receipt, 'allocations' => $alloc, 'remaining' => round($debt - $amount, 2)];
        });
    }
}
