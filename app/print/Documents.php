<?php
declare(strict_types=1);

/**
 * Central print engine. Each document type is a builder that returns ONE
 * normalized structure; Printer renders it as A4 (with business logo) or
 * thermal (58/80mm). Add a document = add a builder here, nothing else.
 */
final class Documents
{
    /** doc key => [permission(s), builder method] */
    public const TYPES = [
        'receipt'   => [['sell.create', 'sales.view_all'], 'sale'],
        'quote'     => [['quotes.manage'], 'quote'],
        'credit'    => [['sales.return', 'sales.view_all'], 'creditNote'],
        'payment'   => [['debts.collect', 'customers.view'], 'debtPayment'],
        'statement' => [['customers.view'], 'statement'],
        'debtors'   => [['debts.collect', 'customers.view'], 'debtors'],
        'grn'       => [['purchases.manage'], 'grn'],
        'voucher'   => [['suppliers.manage'], 'voucher'],
        'sreturn'   => [['purchases.manage'], 'supplierReturn'],
        'transfer'  => [['stock.transfer', 'stock.view'], 'transfer'],
        'stocktake' => [['stock.take'], 'stockTake'],
        'shift'     => [['shifts.manage', 'shifts.view_all'], 'shift'],
        'mpesa'     => [['mpesa.view', 'sell.create'], 'mpesa'],
        'pricelist' => [['products.view'], 'priceList'],
    ];

    public static function canPrint(string $doc): bool
    {
        return isset(self::TYPES[$doc]) && Auth::canAny(self::TYPES[$doc][0]);
    }

    public static function build(string $doc, string $id, array $q = []): array
    {
        if (!isset(self::TYPES[$doc])) throw new ApiError('Unknown document');
        $m = self::TYPES[$doc][1];
        $d = self::$m($id, $q);
        return $d + [
            'doc' => $doc, 'meta' => [], 'party' => null, 'columns' => [], 'rows' => [], 'totals' => [],
            'payments' => [], 'notes' => null, 'pay_accounts' => false, 'signature' => false, 'status' => null,
            'thermal' => 'items', 'terms' => null,
        ];
    }

    /* -------------------- helpers -------------------- */
    private static function userName(?int $id): string
    {
        if (!$id) return '';
        $u = DB::row('SELECT first_name, last_name FROM users WHERE id=?', [$id]);
        return $u ? full_name($u) : '';
    }

    private static function storeName(?int $id): string
    {
        return $id ? (string)DB::val('SELECT name FROM stores WHERE id=?', [$id]) : '';
    }

    private static function customerParty(?int $id): ?array
    {
        if (!$id) return null;
        $c = DB::row('SELECT * FROM customers WHERE id=?', [$id]);
        if (!$c) return null;
        return ['label' => 'Customer', 'name' => $c['name'], 'lines' => array_values(array_filter([
            $c['contact_person'], $c['address'], $c['phone'], $c['email'], $c['kra_pin'] ? 'PIN: ' . $c['kra_pin'] : null,
        ]))];
    }

    private static function itemCols(bool $discount = true, bool $tax = false): array
    {
        $c = [['key' => 'name', 'label' => 'Description'], ['key' => 'qty', 'label' => 'Qty', 'num' => true], ['key' => 'price', 'label' => 'Unit price', 'num' => true]];
        if ($discount) $c[] = ['key' => 'disc', 'label' => 'Discount', 'num' => true];
        if ($tax) $c[] = ['key' => 'tax', 'label' => 'VAT', 'num' => true];
        $c[] = ['key' => 'total', 'label' => 'Amount', 'num' => true];
        return $c;
    }

    /* -------------------- builders -------------------- */
    private static function sale(string $id): array
    {
        $s = DB::row('SELECT * FROM sales WHERE id=?', [(int)$id]);
        if (!$s) throw new ApiError('Sale not found', 404);
        if (!Auth::can('sales.view_all') && (int)$s['user_id'] !== Auth::id()) throw new ApiError('Not your sale', 403);
        $items = DB::all('SELECT * FROM sale_items WHERE sale_id=? ORDER BY id', [$s['id']]);
        $hasDisc = false; $rows = [];
        foreach ($items as $i) {
            $hasDisc = $hasDisc || (float)$i['discount'] > 0;
            $rows[] = ['name' => $i['name'], 'qty' => qty($i['qty']), 'price' => money($i['unit_price'], false),
                'disc' => (float)$i['discount'] > 0 ? money($i['discount'], false) : '', 'tax' => money($i['tax'], false), 'total' => money($i['total'], false)];
        }
        $totals = [['Subtotal', money($s['subtotal'], false)]];
        if ((float)$s['discount_total'] > 0) $totals[] = ['Discounts', '−' . money($s['discount_total'], false)];
        if ((float)$s['coupon_discount'] > 0) $totals[] = ['Coupon ' . (DB::val('SELECT code FROM coupons WHERE id=?', [$s['coupon_id']]) ?? ''), '−' . money($s['coupon_discount'], false)];
        if ((float)$s['shipping'] > 0) $totals[] = ['Delivery', money($s['shipping'], false)];
        if ((float)$s['tax_total'] > 0) $totals[] = ['VAT' . (Settings::get('tax_mode') === 'exclusive' ? '' : ' (incl.)'), money($s['tax_total'], false)];
        $totals[] = ['TOTAL', money($s['total']), 'grand' => true];

        $payments = [];
        foreach (DB::all('SELECT p.*, a.name AS acc FROM payments p LEFT JOIN payment_accounts a ON a.id=p.payment_account_id WHERE p.sale_id=? AND p.is_void=0 ORDER BY p.id', [$s['id']]) as $p) {
            $payments[] = [($p['kind'] === 'debt' ? 'Paid later · ' : '') . ($p['acc'] ?: method_label($p['method'])) . ($p['reference'] ? ' · ' . $p['reference'] : ''), money($p['amount'], false)];
        }
        if ((float)$s['change_given'] > 0) $payments[] = ['Change', money($s['change_given'], false)];
        if ((float)$s['returned_total'] > 0) $totals[] = ['Returned', '−' . money($s['returned_total'], false)];
        if ((float)$s['balance'] > 0) $totals[] = ['BALANCE DUE', money($s['balance']), 'grand' => true, 'danger' => true];

        $credit = (float)$s['balance'] > 0;
        $meta = [['Date', fdate($s['sold_at'], true)], ['Store', self::storeName((int)$s['store_id'])]];
        if (Settings::bool('print_show_served_by')) $meta[] = ['Served by', self::userName((int)$s['user_id'])];
        if ($credit && $s['due_date']) $meta[] = ['Due date', fdate($s['due_date'])];
        $meta[] = ['Type', ucfirst($s['sale_type'])];
        return [
            'title' => $credit ? 'Invoice' : 'Receipt', 'number' => $s['invoice_no'], 'date' => $s['sold_at'],
            'status' => $s['status'] === 'voided' ? ['VOID', 'danger'] : ($credit ? [(float)$s['paid'] > 0 ? 'PART PAID' : 'CREDIT', 'gold'] : ['PAID', 'green']),
            'party' => self::customerParty($s['customer_id'] ? (int)$s['customer_id'] : null),
            'meta' => $meta, 'columns' => self::itemCols($hasDisc), 'rows' => $rows, 'totals' => $totals, 'payments' => $payments,
            'notes' => $s['notes'], 'pay_accounts' => $credit, 'signature' => $credit, 'terms' => Settings::get('print_terms'),
            'barcode' => $s['invoice_no'],
        ];
    }

    private static function quote(string $id): array
    {
        $q = DB::row('SELECT * FROM quotations WHERE id=?', [(int)$id]);
        if (!$q) throw new ApiError('Quotation not found', 404);
        $rows = []; $hasDisc = false;
        foreach (DB::all('SELECT * FROM quotation_items WHERE quotation_id=?', [$q['id']]) as $i) {
            $hasDisc = $hasDisc || (float)$i['discount'] > 0;
            $rows[] = ['name' => $i['name'], 'qty' => qty($i['qty']), 'price' => money($i['unit_price'], false), 'disc' => (float)$i['discount'] > 0 ? money($i['discount'], false) : '', 'total' => money($i['total'], false)];
        }
        $totals = [['Subtotal', money($q['subtotal'], false)]];
        if ((float)$q['discount_total'] > 0) $totals[] = ['Discounts', '−' . money($q['discount_total'], false)];
        if ((float)$q['shipping'] > 0) $totals[] = ['Delivery', money($q['shipping'], false)];
        if ((float)$q['tax_total'] > 0) $totals[] = ['VAT', money($q['tax_total'], false)];
        $totals[] = ['TOTAL', money($q['total']), 'grand' => true];
        return [
            'title' => 'Quotation', 'number' => $q['quote_no'], 'date' => $q['created_at'],
            'status' => [strtoupper($q['status']), $q['status'] === 'converted' ? 'green' : 'indigo'],
            'party' => self::customerParty($q['customer_id'] ? (int)$q['customer_id'] : null),
            'meta' => [['Date', fdate($q['created_at'])], ['Valid until', fdate($q['valid_until'])], ['Prepared by', self::userName((int)$q['user_id'])]],
            'columns' => self::itemCols($hasDisc), 'rows' => $rows, 'totals' => $totals, 'notes' => $q['notes'], 'pay_accounts' => true,
            'terms' => 'Prices valid until ' . fdate($q['valid_until']) . '. Subject to stock availability.',
        ];
    }

    private static function creditNote(string $id): array
    {
        $c = DB::row('SELECT cn.*, s.invoice_no FROM credit_notes cn JOIN sales s ON s.id=cn.sale_id WHERE cn.id=?', [(int)$id]);
        if (!$c) throw new ApiError('Credit note not found', 404);
        $rows = array_map(static fn($i) => ['name' => $i['name'], 'qty' => qty($i['qty']), 'price' => money($i['unit_price'], false), 'total' => money($i['total'], false)],
            DB::all('SELECT * FROM credit_note_items WHERE credit_note_id=?', [$c['id']]));
        $totals = [['TOTAL CREDIT', money($c['total']), 'grand' => true]];
        if ((float)$c['applied_to_balance'] > 0) $totals[] = ['Applied to balance', money($c['applied_to_balance'], false)];
        if ((float)$c['refund_amount'] > 0) $totals[] = ['Refunded (' . method_label($c['refund_method']) . ')', money($c['refund_amount'], false)];
        return [
            'title' => 'Credit Note', 'number' => $c['cn_no'], 'date' => $c['created_at'],
            'party' => self::customerParty($c['customer_id'] ? (int)$c['customer_id'] : null),
            'meta' => [['Date', fdate($c['created_at'], true)], ['Against invoice', $c['invoice_no']], ['Processed by', self::userName((int)$c['user_id'])], ['Restocked', $c['restock'] ? 'Yes' : 'No']],
            'columns' => self::itemCols(false), 'rows' => $rows, 'totals' => $totals, 'notes' => $c['reason'] ? 'Reason: ' . $c['reason'] : null, 'signature' => true,
        ];
    }

    private static function debtPayment(string $receiptNo): array
    {
        $ps = DB::all('SELECT p.*, s.invoice_no, s.balance, a.name acc FROM payments p JOIN sales s ON s.id=p.sale_id LEFT JOIN payment_accounts a ON a.id=p.payment_account_id WHERE p.receipt_no=? ORDER BY p.id', [$receiptNo]);
        if (!$ps) throw new ApiError('Receipt not found', 404);
        $first = $ps[0]; $sum = array_sum(array_map(static fn($p) => (float)$p['amount'], $ps));
        $remaining = SaleService::customerDebt((int)$first['customer_id']);
        return [
            'title' => 'Payment Receipt', 'number' => $receiptNo, 'date' => $first['paid_at'], 'status' => ['RECEIVED', 'green'],
            'party' => self::customerParty((int)$first['customer_id']),
            'meta' => [['Date', fdate($first['paid_at'], true)], ['Method', ($first['acc'] ?: method_label($first['method']))], ['Reference', $first['reference'] ?: '—'], ['Received by', self::userName((int)$first['received_by'])]],
            'columns' => [['key' => 'name', 'label' => 'Applied to invoice'], ['key' => 'total', 'label' => 'Amount', 'num' => true]],
            'rows' => array_map(static fn($p) => ['name' => $p['invoice_no'], 'total' => money($p['amount'], false)], $ps),
            'totals' => [['AMOUNT RECEIVED', money($sum), 'grand' => true], ['Outstanding balance', money($remaining, false)]],
            'thermal' => 'items', 'signature' => true,
        ];
    }

    private static function statement(string $id, array $q): array
    {
        $st = CustomerStatement::build((int)$id, $q['from'] ?? null, $q['to'] ?? null);
        $rows = [['date' => fdate($st['from']), 'ref' => '', 'desc' => 'Opening balance', 'debit' => '', 'credit' => '', 'balance' => money($st['opening'], false)]];
        foreach ($st['rows'] as $r) {
            $rows[] = ['date' => fdate($r['date']), 'ref' => $r['ref'], 'desc' => $r['desc'], 'debit' => $r['debit'] ? money($r['debit'], false) : '', 'credit' => $r['credit'] ? money($r['credit'], false) : '', 'balance' => money($r['balance'], false)];
        }
        return [
            'title' => 'Statement of Account', 'number' => fdate($st['from']) . ' – ' . fdate($st['to']), 'date' => date('Y-m-d'),
            'party' => self::customerParty((int)$id),
            'meta' => [['Period', fdate($st['from']) . ' to ' . fdate($st['to'])], ['Credit limit', (float)$st['customer']['credit_limit'] > 0 ? money($st['customer']['credit_limit']) : '—']],
            'columns' => [['key' => 'date', 'label' => 'Date'], ['key' => 'ref', 'label' => 'Ref'], ['key' => 'desc', 'label' => 'Details'], ['key' => 'debit', 'label' => 'Debit', 'num' => true], ['key' => 'credit', 'label' => 'Credit', 'num' => true], ['key' => 'balance', 'label' => 'Balance', 'num' => true]],
            'rows' => $rows, 'totals' => [['Total invoiced', money($st['debit'], false)], ['Total paid', money($st['credit'], false)], ['BALANCE DUE', money($st['closing']), 'grand' => true]],
            'pay_accounts' => $st['closing'] > 0, 'thermal' => 'table',
        ];
    }

    private static function debtors(): array
    {
        $rows = DB::all('SELECT c.name, c.phone, COUNT(s.id) n, MIN(s.sold_at) oldest, SUM(s.balance) debt FROM sales s JOIN customers c ON c.id=s.customer_id WHERE s.balance>0 AND s.status<>"voided" GROUP BY c.id ORDER BY debt DESC');
        $sum = array_sum(array_map(static fn($r) => (float)$r['debt'], $rows));
        return [
            'title' => 'Debtors List', 'number' => fdate(date('Y-m-d')), 'date' => date('Y-m-d'),
            'columns' => [['key' => 'name', 'label' => 'Customer'], ['key' => 'phone', 'label' => 'Phone'], ['key' => 'n', 'label' => 'Invoices', 'num' => true], ['key' => 'oldest', 'label' => 'Oldest'], ['key' => 'total', 'label' => 'Owed', 'num' => true]],
            'rows' => array_map(static fn($r) => ['name' => $r['name'], 'phone' => $r['phone'], 'n' => $r['n'], 'oldest' => fdate($r['oldest']), 'total' => money($r['debt'], false)], $rows),
            'totals' => [['TOTAL RECEIVABLE', money($sum), 'grand' => true]], 'thermal' => 'table',
        ];
    }

    private static function grn(string $id): array
    {
        $p = DB::row('SELECT p.*, s.name supplier, s.phone, s.email, s.kra_pin FROM purchases p JOIN suppliers s ON s.id=p.supplier_id WHERE p.id=?', [(int)$id]);
        if (!$p) throw new ApiError('Purchase not found', 404);
        $rows = array_map(static fn($i) => ['name' => $i['name'] . ((float)$i['bonus_qty'] > 0 ? ' (+' . qty($i['bonus_qty']) . ' bonus)' : ''), 'qty' => qty($i['qty']), 'price' => money($i['unit_cost'], false), 'disc' => (float)$i['discount'] > 0 ? money($i['discount'], false) : '', 'total' => money($i['total'], false)],
            DB::all('SELECT * FROM purchase_items WHERE purchase_id=?', [$p['id']]));
        $pays = array_map(static fn($x) => [method_label($x['method']) . ($x['reference'] ? ' · ' . $x['reference'] : ''), money($x['amount'], false)], DB::all('SELECT * FROM supplier_payments WHERE purchase_id=?', [$p['id']]));
        $totals = [['Subtotal', money($p['subtotal'], false)]];
        if ((float)$p['shipping'] > 0) $totals[] = ['Carriage inwards', money($p['shipping'], false)];
        $totals[] = ['TOTAL', money($p['total']), 'grand' => true];
        if ((float)$p['balance'] > 0) $totals[] = ['Balance owed', money($p['balance']), 'danger' => true];
        return [
            'title' => 'Goods Received Note', 'number' => $p['grn_no'], 'date' => $p['purchase_date'],
            'party' => ['label' => 'Supplier', 'name' => $p['supplier'], 'lines' => array_values(array_filter([$p['phone'], $p['email'], $p['kra_pin'] ? 'PIN: ' . $p['kra_pin'] : null]))],
            'meta' => [['Date', fdate($p['purchase_date'])], ['Supplier invoice', $p['supplier_invoice'] ?: '—'], ['Store', self::storeName((int)$p['store_id'])], ['Received by', self::userName((int)$p['user_id'])]],
            'columns' => self::itemCols(true), 'rows' => $rows, 'totals' => $totals, 'payments' => $pays, 'notes' => $p['notes'], 'signature' => true,
        ];
    }

    private static function voucher(string $no): array
    {
        $ps = DB::all('SELECT sp.*, s.name supplier, p.grn_no FROM supplier_payments sp JOIN suppliers s ON s.id=sp.supplier_id LEFT JOIN purchases p ON p.id=sp.purchase_id WHERE sp.voucher_no=? OR sp.id=? ORDER BY sp.id', [$no, (int)$no]);
        if (!$ps) throw new ApiError('Voucher not found', 404);
        $f = $ps[0];
        return [
            'title' => 'Payment Voucher', 'number' => $f['voucher_no'] ?: ('PV-' . $f['id']), 'date' => $f['paid_at'],
            'party' => ['label' => 'Paid to', 'name' => $f['supplier'], 'lines' => []],
            'meta' => [['Date', fdate($f['paid_at'], true)], ['Method', method_label($f['method'])], ['Reference', $f['reference'] ?: '—'], ['Prepared by', self::userName((int)$f['user_id'])]],
            'columns' => [['key' => 'name', 'label' => 'For GRN'], ['key' => 'total', 'label' => 'Amount', 'num' => true]],
            'rows' => array_map(static fn($p) => ['name' => $p['grn_no'] ?: 'On account', 'total' => money($p['amount'], false)], $ps),
            'totals' => [['TOTAL PAID', money(array_sum(array_column($ps, 'amount'))), 'grand' => true]], 'signature' => true,
        ];
    }

    private static function supplierReturn(string $id): array
    {
        $r = DB::row('SELECT r.*, s.name supplier, p.grn_no FROM supplier_returns r JOIN suppliers s ON s.id=r.supplier_id JOIN purchases p ON p.id=r.purchase_id WHERE r.id=?', [(int)$id]);
        if (!$r) throw new ApiError('Return not found', 404);
        return [
            'title' => 'Supplier Return (Debit Note)', 'number' => $r['return_no'], 'date' => $r['created_at'],
            'party' => ['label' => 'Supplier', 'name' => $r['supplier'], 'lines' => []],
            'meta' => [['Date', fdate($r['created_at'])], ['Against GRN', $r['grn_no']], ['Store', self::storeName((int)$r['store_id'])]],
            'columns' => self::itemCols(false),
            'rows' => array_map(static fn($i) => ['name' => $i['name'], 'qty' => qty($i['qty']), 'price' => money($i['unit_cost'], false), 'total' => money($i['total'], false)], DB::all('SELECT * FROM supplier_return_items WHERE supplier_return_id=?', [$r['id']])),
            'totals' => [['TOTAL', money($r['total']), 'grand' => true]], 'notes' => $r['reason'], 'signature' => true,
        ];
    }

    private static function transfer(string $id): array
    {
        $t = DB::row('SELECT * FROM transfers WHERE id=?', [(int)$id]);
        if (!$t) throw new ApiError('Transfer not found', 404);
        $items = DB::all('SELECT * FROM transfer_items WHERE transfer_id=?', [$t['id']]);
        $val = 0.0;
        foreach ($items as $i) $val += (float)$i['qty'] * (float)$i['unit_cost'];
        return [
            'title' => 'Delivery Note / Transfer', 'number' => $t['transfer_no'], 'date' => $t['created_at'],
            'status' => [strtoupper(str_replace('_', ' ', $t['status'])), $t['status'] === 'received' ? 'green' : 'gold'],
            'meta' => [['From', self::storeName((int)$t['from_store_id'])], ['To', self::storeName((int)$t['to_store_id'])], ['Dispatched by', self::userName((int)$t['created_by'])], ['Received by', self::userName($t['received_by'] ? (int)$t['received_by'] : null) ?: '—']],
            'columns' => [['key' => 'name', 'label' => 'Item'], ['key' => 'qty', 'label' => 'Qty', 'num' => true], ['key' => 'check', 'label' => 'Received ✓']],
            'rows' => array_map(static fn($i) => ['name' => $i['name'], 'qty' => qty($i['qty']), 'check' => ''], $items),
            'totals' => Auth::can('products.cost') ? [['Value at cost', money($val), 'grand' => true]] : [], 'notes' => $t['notes'], 'signature' => true,
        ];
    }

    private static function stockTake(string $id): array
    {
        $t = DB::row('SELECT * FROM stock_takes WHERE id=?', [(int)$id]);
        if (!$t) throw new ApiError('Stock take not found', 404);
        $rows = array_map(static fn($i) => ['name' => $i['name'], 'sys' => qty($i['system_qty']), 'cnt' => qty($i['counted_qty']), 'var' => ((float)$i['variance'] > 0 ? '+' : '') . qty($i['variance']), 'val' => money((float)$i['variance'] * (float)$i['unit_cost'], false)],
            DB::all('SELECT * FROM stock_take_items WHERE stock_take_id=? ORDER BY name', [$t['id']]));
        return [
            'title' => 'Stock Take Report', 'number' => $t['take_no'], 'date' => $t['created_at'],
            'meta' => [['Store', self::storeName((int)$t['store_id'])], ['Counted by', self::userName((int)$t['user_id'])], ['Date', fdate($t['created_at'], true)]],
            'columns' => [['key' => 'name', 'label' => 'Item'], ['key' => 'sys', 'label' => 'System', 'num' => true], ['key' => 'cnt', 'label' => 'Counted', 'num' => true], ['key' => 'var', 'label' => 'Variance', 'num' => true], ['key' => 'val', 'label' => 'Value', 'num' => true]],
            'rows' => $rows, 'totals' => [['VARIANCE VALUE', money($t['variance_value']), 'grand' => true]], 'notes' => $t['notes'], 'thermal' => 'table', 'signature' => true,
        ];
    }

    private static function shift(string $id): array
    {
        $s = DB::row('SELECT * FROM shifts WHERE id=?', [(int)$id]);
        if (!$s) throw new ApiError('Shift not found', 404);
        if (!Auth::can('shifts.view_all') && (int)$s['user_id'] !== Auth::id()) throw new ApiError('Not your shift', 403);
        $sum = ShiftService::summary($s);
        $rows = [];
        foreach ($sum['by_method'] as $m => $v) $rows[] = ['name' => method_label($m), 'total' => money($v, false)];
        return [
            'title' => $s['closed_at'] ? 'Z-Report (Shift close)' : 'X-Report (Shift so far)', 'number' => 'SHIFT-' . $s['id'], 'date' => $s['opened_at'],
            'meta' => [['Cashier', self::userName((int)$s['user_id'])], ['Store', self::storeName((int)$s['store_id'])], ['Opened', fdate($s['opened_at'], true)], ['Closed', $s['closed_at'] ? fdate($s['closed_at'], true) : 'open']],
            'columns' => [['key' => 'name', 'label' => 'Collections by method'], ['key' => 'total', 'label' => 'Amount', 'num' => true]],
            'rows' => $rows,
            'totals' => array_values(array_filter([
                ['Sales count', (string)$sum['sales_count']], ['Gross sales', money($sum['sales_total'], false)], ['Credit given', money($sum['credit'], false)],
                ['Opening float', money($s['opening_float'], false)], ['Cash expenses', '−' . money($sum['cash_expenses'], false)], ['Cash refunds', '−' . money($sum['cash_refunds'], false)],
                ['EXPECTED CASH', money($sum['expected_cash']), 'grand' => true],
                $s['closed_at'] ? ['Counted cash', money($s['counted_cash'], false)] : null,
                $s['closed_at'] ? ['VARIANCE', money($s['variance']), 'grand' => true, 'danger' => (float)$s['variance'] < 0] : null,
            ])),
            'notes' => $s['notes'], 'signature' => true,
        ];
    }

    private static function mpesa(string $id): array
    {
        $t = DB::row('SELECT m.*, s.invoice_no FROM mpesa_transactions m LEFT JOIN sales s ON s.id=m.sale_id WHERE m.id=?', [(int)$id]);
        if (!$t) throw new ApiError('Transaction not found', 404);
        return [
            'title' => 'M-Pesa Payment Confirmation', 'number' => $t['mpesa_receipt'] ?: ('TX-' . $t['id']), 'date' => $t['created_at'],
            'status' => [strtoupper($t['status']), $t['status'] === 'success' ? 'green' : ($t['status'] === 'pending' ? 'gold' : 'danger')],
            'meta' => array_values(array_filter([
                ['Date', fdate($t['created_at'], true)], ['Type', $t['type'] === 'stk' ? 'STK push (Lipa na M-Pesa)' : 'Paybill / Till (C2B)'],
                ['Phone', $t['phone'] ? '***' . substr((string)$t['phone'], -4) : '—'], $t['payer_name'] ? ['Payer', $t['payer_name']] : null,
                ['Account ref', $t['account_ref'] ?: '—'], $t['invoice_no'] ? ['Invoice', $t['invoice_no']] : null,
            ])),
            'columns' => [['key' => 'name', 'label' => 'Detail'], ['key' => 'total', 'label' => 'Amount', 'num' => true]],
            'rows' => [['name' => 'M-Pesa ' . ($t['mpesa_receipt'] ?: ''), 'total' => money($t['amount'], false)]],
            'totals' => [['AMOUNT', money($t['amount']), 'grand' => true]], 'notes' => $t['result_desc'],
        ];
    }

    private static function priceList(string $id, array $q): array
    {
        $cat = (int)($q['category'] ?? 0);
        $rows = DB::all('SELECT p.name, p.sku, p.unit, p.retail_price, p.wholesale_price, c.name cat FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.is_active=1' . ($cat ? ' AND p.category_id=?' : '') . ' ORDER BY c.name, p.name', $cat ? [$cat] : []);
        return [
            'title' => 'Price List', 'number' => fdate(date('Y-m-d')), 'date' => date('Y-m-d'),
            'columns' => [['key' => 'name', 'label' => 'Product'], ['key' => 'sku', 'label' => 'Code'], ['key' => 'cat', 'label' => 'Category'], ['key' => 'rp', 'label' => 'Retail', 'num' => true], ['key' => 'wp', 'label' => 'Wholesale', 'num' => true]],
            'rows' => array_map(static fn($r) => ['name' => $r['name'] . ' (' . $r['unit'] . ')', 'sku' => $r['sku'], 'cat' => $r['cat'], 'rp' => money($r['retail_price'], false), 'wp' => (float)$r['wholesale_price'] > 0 ? money($r['wholesale_price'], false) : '—'], $rows),
            'thermal' => 'table', 'pay_accounts' => true,
        ];
    }
}
