<?php
declare(strict_types=1);

/**
 * Report catalogue. Each report = SQL + columns; the same definition powers
 * the screen table, CSV export and the A4 print (via the print engine look).
 * Placeholders: :from :to (dates) :store (0 = all)
 */
final class Reports
{
    public static function catalogue(): array
    {
        return [
            'sales_daily' => ['Sales by day', 'Sales', 'reports.view',
                "SELECT DATE(s.sold_at) day, COUNT(*) sales, SUM(s.subtotal) gross, SUM(s.discount_total+s.coupon_discount) discounts, SUM(s.total) total, SUM(s.paid) paid, SUM(s.balance) credit
                 FROM sales s WHERE s.status<>'voided' AND s.sold_at BETWEEN :from AND :to AND (:store=0 OR s.store_id=:store) GROUP BY DATE(s.sold_at) ORDER BY day",
                ['day' => 'Date', 'sales' => '#', 'gross' => '$Gross', 'discounts' => '$Discounts', 'total' => '$Net sales', 'paid' => '$Paid', 'credit' => '$On credit']],
            'sales_cashier' => ['Sales by cashier', 'Sales', 'reports.view',
                "SELECT CONCAT(u.first_name,' ',COALESCE(u.last_name,'')) cashier, COUNT(s.id) sales, SUM(s.total) total, AVG(s.total) avg, SUM(s.discount_total) discounts, SUM(s.status='voided') voids
                 FROM sales s JOIN users u ON u.id=s.user_id WHERE s.sold_at BETWEEN :from AND :to AND (:store=0 OR s.store_id=:store) GROUP BY u.id, cashier ORDER BY total DESC",
                ['cashier' => 'Cashier', 'sales' => '#', 'total' => '$Total', 'avg' => '$Avg sale', 'discounts' => '$Discounts', 'voids' => 'Voids']],
            'payments' => ['Collections by method', 'Sales', 'reports.view',
                "SELECT p.method, COALESCE(a.name,'—') account, p.kind, COUNT(*) n, SUM(p.amount) amount FROM payments p LEFT JOIN payment_accounts a ON a.id=p.payment_account_id
                 WHERE p.is_void=0 AND p.paid_at BETWEEN :from AND :to AND (:store=0 OR p.store_id=:store) GROUP BY p.method, account, p.kind ORDER BY amount DESC",
                ['method' => 'Method', 'account' => 'Account', 'kind' => 'Type', 'n' => '#', 'amount' => '$Amount']],
            'payments_detail' => ['Payments register', 'Sales', 'reports.view',
                "SELECT p.paid_at, COALESCE(p.receipt_no, s.invoice_no) ref, c.name customer, p.method, p.reference, p.kind, p.amount FROM payments p LEFT JOIN sales s ON s.id=p.sale_id LEFT JOIN customers c ON c.id=p.customer_id
                 WHERE p.is_void=0 AND p.paid_at BETWEEN :from AND :to AND (:store=0 OR p.store_id=:store) ORDER BY p.paid_at",
                ['paid_at' => 'When', 'ref' => 'Ref', 'customer' => 'Customer', 'method' => 'Method', 'reference' => 'Code', 'kind' => 'Type', 'amount' => '$Amount']],
            'product_performance' => ['Product performance & margins', 'Products', 'reports.financial',
                "SELECT si.name product, SUM(si.qty) qty, SUM(si.total) revenue, SUM(si.unit_cost*si.qty) cost, SUM(si.total-si.unit_cost*si.qty) profit,
                    ROUND(SUM(si.total-si.unit_cost*si.qty)/NULLIF(SUM(si.total),0)*100,1) margin
                 FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.status<>'voided' AND s.sold_at BETWEEN :from AND :to AND (:store=0 OR s.store_id=:store) GROUP BY si.product_id, si.name ORDER BY revenue DESC",
                ['product' => 'Product', 'qty' => '#Qty', 'revenue' => '$Revenue', 'cost' => '$Cost', 'profit' => '$Profit', 'margin' => '%Margin']],
            'category_sales' => ['Sales by category', 'Products', 'reports.view',
                "SELECT COALESCE(c.name,'Uncategorised') category, SUM(si.qty) qty, SUM(si.total) revenue FROM sale_items si JOIN sales s ON s.id=si.sale_id JOIN products p ON p.id=si.product_id LEFT JOIN categories c ON c.id=p.category_id
                 WHERE s.status<>'voided' AND s.sold_at BETWEEN :from AND :to AND (:store=0 OR s.store_id=:store) GROUP BY category ORDER BY revenue DESC",
                ['category' => 'Category', 'qty' => '#Qty', 'revenue' => '$Revenue']],
            'dead_stock' => ['Dead stock (no sales in period)', 'Inventory', 'reports.view',
                "SELECT p.name product, COALESCE(c.name,'') category, SUM(st.qty) qty, SUM(st.qty)*p.cost_price value,
                    (SELECT MAX(s.sold_at) FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE si.product_id=p.id) last_sold
                 FROM products p JOIN stock st ON st.product_id=p.id LEFT JOIN categories c ON c.id=p.category_id
                 WHERE p.is_active=1 AND st.qty>0 AND (:store=0 OR st.store_id=:store)
                   AND NOT EXISTS (SELECT 1 FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE si.product_id=p.id AND s.sold_at BETWEEN :from AND :to)
                 GROUP BY p.id, p.name, category, p.cost_price ORDER BY value DESC",
                ['product' => 'Product', 'category' => 'Category', 'qty' => '#Qty', 'value' => '$Value at cost', 'last_sold' => 'Last sold']],
            'valuation' => ['Stock valuation', 'Inventory', 'reports.financial',
                "SELECT p.name product, COALESCE(c.name,'') category, SUM(st.qty) qty, p.cost_price cost, SUM(st.qty)*p.cost_price value, SUM(st.qty)*p.retail_price retail_value
                 FROM products p JOIN stock st ON st.product_id=p.id LEFT JOIN categories c ON c.id=p.category_id WHERE p.is_active=1 AND (:store=0 OR st.store_id=:store) AND :from<=:to
                 GROUP BY p.id, p.name, category, p.cost_price, p.retail_price HAVING qty<>0 ORDER BY value DESC",
                ['product' => 'Product', 'category' => 'Category', 'qty' => '#Qty', 'cost' => '$Unit cost', 'value' => '$Cost value', 'retail_value' => '$Retail value']],
            'movements' => ['Stock movements', 'Inventory', 'stock.view',
                "SELECT m.created_at, p.name product, st.name store, m.type, m.qty, m.balance, m.note, u.first_name user FROM stock_movements m JOIN products p ON p.id=m.product_id JOIN stores st ON st.id=m.store_id LEFT JOIN users u ON u.id=m.user_id
                 WHERE m.created_at BETWEEN :from AND :to AND (:store=0 OR m.store_id=:store) ORDER BY m.id DESC LIMIT 5000",
                ['created_at' => 'When', 'product' => 'Product', 'store' => 'Store', 'type' => 'Type', 'qty' => '#Change', 'balance' => '#Balance', 'note' => 'Ref', 'user' => 'By']],
            'returns' => ['Returns / credit notes', 'Sales', 'reports.view',
                "SELECT cn.created_at, cn.cn_no, s.invoice_no, c.name customer, cn.reason, cn.refund_method, cn.total FROM credit_notes cn JOIN sales s ON s.id=cn.sale_id LEFT JOIN customers c ON c.id=cn.customer_id
                 WHERE cn.created_at BETWEEN :from AND :to AND (:store=0 OR cn.store_id=:store) ORDER BY cn.id DESC",
                ['created_at' => 'When', 'cn_no' => 'Credit note', 'invoice_no' => 'Invoice', 'customer' => 'Customer', 'reason' => 'Reason', 'refund_method' => 'Refund', 'total' => '$Total']],
            'purchases' => ['Purchases by supplier', 'Purchasing', 'reports.view',
                "SELECT su.name supplier, COUNT(p.id) grns, SUM(p.total) total, SUM(p.paid) paid, SUM(p.balance) owed FROM purchases p JOIN suppliers su ON su.id=p.supplier_id
                 WHERE p.purchase_date BETWEEN :from AND :to AND (:store=0 OR p.store_id=:store) GROUP BY su.id, su.name ORDER BY total DESC",
                ['supplier' => 'Supplier', 'grns' => '#', 'total' => '$Total', 'paid' => '$Paid', 'owed' => '$Owed']],
            'expenses' => ['Expenses by category', 'Finance', 'reports.financial',
                "SELECT e.category, COUNT(*) n, SUM(e.amount) total FROM expenses e WHERE e.spent_on BETWEEN :from AND :to AND (:store=0 OR e.store_id=:store) GROUP BY e.category ORDER BY total DESC",
                ['category' => 'Category', 'n' => '#', 'total' => '$Total']],
            'pnl' => ['Profit & loss', 'Finance', 'reports.financial', null, ['line' => 'Line', 'amount' => '$Amount']],
            'vat' => ['VAT summary', 'Finance', 'reports.financial',
                "SELECT si.tax_rate rate, SUM(si.total) sales, SUM(si.tax) vat FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.status<>'voided' AND s.sold_at BETWEEN :from AND :to AND (:store=0 OR s.store_id=:store) GROUP BY si.tax_rate ORDER BY rate",
                ['rate' => '%Rate', 'sales' => '$Sales', 'vat' => '$VAT']],
            'customers_top' => ['Top customers', 'Customers', 'reports.view',
                "SELECT c.name customer, c.phone, COUNT(s.id) visits, SUM(s.total) spent, SUM(s.balance) owes, MAX(s.sold_at) last_visit FROM sales s JOIN customers c ON c.id=s.customer_id
                 WHERE s.status<>'voided' AND s.sold_at BETWEEN :from AND :to AND (:store=0 OR s.store_id=:store) GROUP BY c.id, c.name, c.phone ORDER BY spent DESC LIMIT 200",
                ['customer' => 'Customer', 'phone' => 'Phone', 'visits' => '#Visits', 'spent' => '$Spent', 'owes' => '$Owes', 'last_visit' => 'Last visit']],
            'coupons' => ['Coupon redemptions', 'Marketing', 'reports.view',
                "SELECT cp.code, cp.description, COUNT(s.id) uses, SUM(s.coupon_discount) discount, SUM(s.total) sales FROM sales s JOIN coupons cp ON cp.id=s.coupon_id
                 WHERE s.status<>'voided' AND s.sold_at BETWEEN :from AND :to AND (:store=0 OR s.store_id=:store) GROUP BY cp.id, cp.code, cp.description ORDER BY uses DESC",
                ['code' => 'Code', 'description' => 'Description', 'uses' => '#Uses', 'discount' => '$Discount given', 'sales' => '$Sales driven']],
        ];
    }

    /** @return array{title:string, cols:array, rows:array, totals:array} */
    public static function run(string $key, string $from, string $to, int $store): array
    {
        $cat = self::catalogue();
        if (!isset($cat[$key])) throw new ApiError('Unknown report');
        [$title, , $perm, $sql, $cols] = $cat[$key];
        if (!Auth::can($perm)) throw new ApiError('No access to this report', 403);
        $fromDt = $from . ' 00:00:00'; $toDt = $to . ' 23:59:59';
        if ($key === 'pnl') {
            $p = [$fromDt, $toDt, $store, $store];
            $rev = (float)DB::val("SELECT COALESCE(SUM(total),0) FROM sales WHERE status<>'voided' AND sold_at BETWEEN ? AND ? AND (?=0 OR store_id=?)", $p);
            $cogs = (float)DB::val("SELECT COALESCE(SUM(cost_total),0) FROM sales WHERE status<>'voided' AND sold_at BETWEEN ? AND ? AND (?=0 OR store_id=?)", $p);
            $ret = (float)DB::val('SELECT COALESCE(SUM(total),0) FROM credit_notes WHERE created_at BETWEEN ? AND ? AND (?=0 OR store_id=?)', $p);
            $retCost = (float)DB::val('SELECT COALESCE(SUM(ci.qty*si.unit_cost),0) FROM credit_note_items ci JOIN sale_items si ON si.id=ci.sale_item_id JOIN credit_notes cn ON cn.id=ci.credit_note_id WHERE cn.restock=1 AND cn.created_at BETWEEN ? AND ? AND (?=0 OR cn.store_id=?)', $p);
            $exp = DB::pairs('SELECT category, SUM(amount) FROM expenses WHERE spent_on BETWEEN ? AND ? AND (?=0 OR store_id=?) GROUP BY category', [$from, $to, $store, $store]);
            $gross = $rev - $ret - ($cogs - $retCost);
            $rows = [['line' => 'Sales revenue', 'amount' => $rev], ['line' => 'Less: returns', 'amount' => -$ret], ['line' => 'Less: cost of goods sold', 'amount' => -($cogs - $retCost)], ['line' => 'GROSS PROFIT', 'amount' => $gross]];
            foreach ($exp as $k => $v) $rows[] = ['line' => 'Expense: ' . $k, 'amount' => -(float)$v];
            $rows[] = ['line' => 'NET PROFIT', 'amount' => $gross - array_sum(array_map('floatval', $exp))];
            return ['title' => $title, 'cols' => $cols, 'rows' => $rows, 'totals' => []];
        }
        // Expand named params to positional (native prepares can't reuse names)
        $params = [];
        $sql = preg_replace_callback('/:(from|to|store)\b/', static function ($m) use (&$params, $fromDt, $toDt, $store, $from, $to, $key) {
            $dateOnly = in_array($key, ['purchases', 'expenses'], true);
            $params[] = $m[1] === 'store' ? $store : ($m[1] === 'from' ? ($dateOnly ? $from : $fromDt) : ($dateOnly ? $to : $toDt));
            return '?';
        }, $sql);
        $rows = DB::all($sql, $params);
        $totals = [];
        foreach ($cols as $c => $label) {
            if ($label[0] === '$' || ($label[0] === '#' && !in_array($c, ['balance'], true))) $totals[$c] = array_sum(array_map(static fn($r) => (float)$r[$c], $rows));
        }
        return ['title' => $title, 'cols' => $cols, 'rows' => $rows, 'totals' => $totals];
    }
}
