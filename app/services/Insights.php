<?php
declare(strict_types=1);

/**
 * Business intelligence computed from your own data (no external calls):
 * forecasting, stock-out prediction, dead stock, margin leaks, churn,
 * basket pairs, staff anomalies, peak hours. Optionally narrated by an LLM (Ai).
 */
final class Insights
{
    /** Daily revenue for the last $days days (zero-filled). */
    public static function dailySeries(int $days = 56, ?int $storeId = null): array
    {
        $p = [$days - 1];
        $w = '';
        if ($storeId) { $w = ' AND store_id=?'; $p[] = $storeId; }
        $raw = DB::pairs("SELECT DATE(sold_at) d, SUM(total) FROM sales WHERE status<>'voided' AND sold_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY) $w GROUP BY DATE(sold_at)", $p);
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $out[$d] = (float)($raw[$d] ?? 0);
        }
        return $out;
    }

    /** 7-day forecast: weekday seasonality × recent trend (robust for small shops). */
    public static function forecast(array $series, int $ahead = 7): array
    {
        $vals = array_values($series); $dates = array_keys($series);
        $n = count($vals);
        if ($n < 14 || array_sum($vals) <= 0) return [];
        $byDow = array_fill(0, 7, []);
        foreach ($dates as $i => $d) $byDow[(int)date('w', strtotime($d))][] = $vals[$i];
        $overall = array_sum($vals) / $n ?: 1;
        $season = [];
        foreach ($byDow as $k => $list) $season[$k] = $list ? (array_sum($list) / count($list)) / $overall : 1;
        $recent = array_slice($vals, -14);
        $prior = array_slice($vals, -28, 14);
        $trend = ($prior && array_sum($prior) > 0) ? max(0.6, min(1.6, array_sum($recent) / array_sum($prior))) : 1;
        $base = array_sum($recent) / 14;
        $out = [];
        for ($i = 1; $i <= $ahead; $i++) {
            $d = date('Y-m-d', strtotime("+$i days"));
            $out[$d] = round($base * $season[(int)date('w', strtotime($d))] * (1 + ($trend - 1) / 2), 2);
        }
        return $out;
    }

    public static function all(): array
    {
        $cards = [];
        $money = static fn($v) => money($v);
        $series = self::dailySeries(56);
        $vals = array_values($series);

        // 1. Today vs typical same weekday
        $today = end($vals);
        $sameDow = [];
        foreach ([7, 14, 21, 28] as $k) if (isset($vals[count($vals) - 1 - $k])) $sameDow[] = $vals[count($vals) - 1 - $k];
        $typical = $sameDow ? array_sum($sameDow) / count($sameDow) : 0;
        $hourShare = max(0.1, min(1, ((int)date('G') - 7) / 12));
        if ($typical > 0) {
            $pace = $today / ($typical * $hourShare);
            if ($pace < 0.75 && (int)date('G') >= 11) $cards[] = ['bad', 'trend-down', 'Slow day so far', sprintf('Today is at %s, about %d%% behind a typical %s at this hour. Consider a flash promo or calling regulars.', $money($today), round((1 - $pace) * 100), date('l')), ['Create promotion', url('promotions')]];
            elseif ($pace > 1.25) $cards[] = ['good', 'trend-up', 'Busy day!', sprintf('Today is running %d%% ahead of a typical %s. Keep the till stocked with change and watch fast movers.', round(($pace - 1) * 100), date('l')), null];
        }

        // 2. Week over week
        $thisWeek = array_sum(array_slice($vals, -7));
        $lastWeek = array_sum(array_slice($vals, -14, 7));
        if ($lastWeek > 0) {
            $ch = ($thisWeek - $lastWeek) / $lastWeek * 100;
            if (abs($ch) >= 10) $cards[] = [$ch > 0 ? 'good' : 'warn', $ch > 0 ? 'trend-up' : 'trend-down', sprintf('Revenue %s %d%% week-on-week', $ch > 0 ? 'up' : 'down', abs(round($ch))), sprintf('Last 7 days %s vs %s the week before.', $money($thisWeek), $money($lastWeek)), ['Sales report', url('reports', ['r' => 'sales_daily'])]];
        }

        // 3. Forecast
        $fc = self::forecast($series);
        if ($fc) $cards[] = ['info', 'sparkles', 'Next 7 days forecast: ' . $money(array_sum($fc)), 'Based on your weekday pattern and recent trend. Best day ahead: ' . date('l', strtotime((string)array_search(max($fc), $fc, true))) . '.', null];

        // 4. Stock-out prediction
        $risk = DB::all("SELECT p.id, p.name, p.unit, COALESCE(st.q,0) qty, x.sold/30 daily
            FROM products p JOIN (SELECT si.product_id, SUM(si.qty) sold FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.status<>'voided' AND s.sold_at>=DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY si.product_id) x ON x.product_id=p.id
            LEFT JOIN (SELECT product_id, SUM(qty) q FROM stock GROUP BY product_id) st ON st.product_id=p.id
            WHERE p.is_active=1 AND p.track_stock=1 AND x.sold>0 HAVING qty/daily < 7 ORDER BY qty/daily LIMIT 6");
        if ($risk) {
            $names = array_map(static fn($r) => sprintf('%s (~%s days, order %s)', $r['name'], max(0, (int)floor((float)$r['qty'] / max(0.01, (float)$r['daily']))), qty(ceil((float)$r['daily'] * 30 - (float)$r['qty']))), $risk);
            $cards[] = ['bad', 'alert', count($risk) . ' fast movers will run out within a week', implode('; ', $names) . '.', ['See reorder list', url('stock', ['f' => 'low'])]];
        }

        // 5. Dead stock
        if (Auth::can('products.cost')) {
            $dead = DB::row("SELECT COUNT(*) n, COALESCE(SUM(st.qty*p.cost_price),0) v FROM products p JOIN (SELECT product_id, SUM(qty) qty FROM stock GROUP BY product_id) st ON st.product_id=p.id
                WHERE p.is_active=1 AND st.qty>0 AND p.created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)
                AND NOT EXISTS (SELECT 1 FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE si.product_id=p.id AND s.sold_at>=DATE_SUB(NOW(), INTERVAL 60 DAY))");
            if ((int)$dead['n'] > 0 && (float)$dead['v'] > 0) $cards[] = ['warn', 'box', sprintf('%d products have not sold in 60 days', $dead['n']), sprintf('%s of cash is tied up in slow stock. A clearance promotion or bundle could free it.', $money($dead['v'])), ['Dead stock report', url('reports', ['r' => 'dead_stock'])]];

            // 6. Selling below cost
            $leak = DB::row("SELECT COUNT(DISTINCT si.product_id) n, COALESCE(SUM(si.unit_cost*si.qty - si.total),0) loss FROM sale_items si JOIN sales s ON s.id=si.sale_id
                WHERE s.status<>'voided' AND s.sold_at>=DATE_SUB(NOW(), INTERVAL 30 DAY) AND si.total < si.unit_cost*si.qty - 0.01");
            if ((int)$leak['n'] > 0) $cards[] = ['bad', 'percent', sprintf('%d products sold below cost this month', $leak['n']), sprintf('Margin leak of %s. Check prices, discounts and minimum prices.', $money($leak['loss'])), ['Margin report', url('reports', ['r' => 'product_performance'])]];
        }

        // 7. Debts
        $overdue = DB::row("SELECT COUNT(DISTINCT s.customer_id) n, COALESCE(SUM(s.balance),0) v FROM sales s WHERE s.balance>0 AND s.status<>'voided' AND s.due_date < CURDATE()");
        if ((float)$overdue['v'] > 0) $cards[] = ['warn', 'wallet', sprintf('%s overdue from %d customers', $money($overdue['v']), $overdue['n']), 'Send friendly reminders with your M-Pesa paybill and bank details in one click.', ['Debtors', url('debts')]];

        // 8. Churn: regulars gone quiet
        $lapsed = (int)DB::val("SELECT COUNT(*) FROM (SELECT customer_id, COUNT(*) n, MAX(sold_at) last FROM sales WHERE customer_id IS NOT NULL AND status<>'voided' GROUP BY customer_id HAVING n>=3 AND last < DATE_SUB(NOW(), INTERVAL 30 DAY) AND last >= DATE_SUB(NOW(), INTERVAL 180 DAY)) x");
        if ($lapsed > 0) $cards[] = ['info', 'users', "$lapsed regular customers have gone quiet", 'They bought 3+ times before but not in the last 30 days. A win-back offer often brings 1 in 5 back.', ['Start win-back campaign', url('campaigns', ['audience' => 'inactive'])]];

        // 9. Frequently bought together
        $pair = DB::row("SELECT a.name a, b.name b, COUNT(*) n FROM sale_items a JOIN sale_items b ON a.sale_id=b.sale_id AND a.product_id<b.product_id
            JOIN sales s ON s.id=a.sale_id WHERE s.sold_at>=DATE_SUB(NOW(), INTERVAL 60 DAY) AND s.status<>'voided' GROUP BY a.product_id, b.product_id, a.name, b.name HAVING n>=3 ORDER BY n DESC LIMIT 1");
        if ($pair) $cards[] = ['info', 'layers', 'Bundle idea', sprintf('“%s” and “%s” were bought together %d times in 60 days. Place them side by side or offer a combo price.', $pair['a'], $pair['b'], $pair['n']), ['Create promotion', url('promotions')]];

        // 10. Peak hour
        $peak = DB::row("SELECT DAYNAME(sold_at) d, HOUR(sold_at) h, COUNT(*) n FROM sales WHERE sold_at>=DATE_SUB(NOW(), INTERVAL 30 DAY) AND status<>'voided' GROUP BY DAYNAME(sold_at), HOUR(sold_at) ORDER BY n DESC LIMIT 1");
        if ($peak && (int)$peak['n'] >= 5) $cards[] = ['info', 'clock', sprintf('Peak time: %s %02d:00–%02d:00', $peak['d'], $peak['h'], (int)$peak['h'] + 1), 'Schedule your strongest cashiers and restock shelves before this window.', null];

        // 11. Staff anomalies: voids and discounts
        $staff = DB::row("SELECT u.first_name n, COUNT(*) c FROM sales s JOIN users u ON u.id=s.voided_by WHERE s.status='voided' AND s.updated_at>=DATE_SUB(NOW(), INTERVAL 14 DAY) GROUP BY u.id ORDER BY c DESC LIMIT 1");
        if ($staff && (int)$staff['c'] >= 3) $cards[] = ['warn', 'shield', 'Unusual voids', sprintf('%s voided %d sales in 14 days. Review the audit trail.', $staff['n'], $staff['c']), ['Audit trail', url('audit', ['action' => 'sale_void'])]];
        $short = DB::row("SELECT COUNT(*) n, COALESCE(SUM(variance),0) v FROM shifts WHERE closed_at>=DATE_SUB(NOW(), INTERVAL 14 DAY) AND variance < -50");
        if ((int)$short['n'] > 0) $cards[] = ['bad', 'cash', sprintf('%d till shortages in 14 days', $short['n']), 'Total cash variance ' . $money($short['v']) . '. Check register shifts.', ['Shifts', url('shifts')]];

        // 12. Payment mix
        $mix = DB::pairs("SELECT method, SUM(amount) FROM payments WHERE is_void=0 AND kind<>'refund' AND paid_at>=DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY method");
        $sum = array_sum($mix);
        if ($sum > 0 && !empty($mix['mpesa']) && $mix['mpesa'] / $sum > 0.5 && !Mpesa::enabled()) {
            $cards[] = ['info', 'phone', round($mix['mpesa'] / $sum * 100) . '% of your money comes via M-Pesa', 'Switch on the Daraja integration so payments confirm automatically — no more typing codes or fake SMS risk.', ['M-Pesa settings', url('settings', ['tab' => 'mpesa'])]];
        }
        if (!$cards) $cards[] = ['good', 'check', 'All looks healthy', 'No issues detected. Keep selling — insights get smarter as more sales are recorded.', null];

        return array_map(static fn($c) => ['tone' => $c[0], 'icon' => $c[1], 'title' => $c[2], 'body' => $c[3], 'action' => $c[4] ? ['label' => $c[4][0], 'url' => $c[4][1]] : null], $cards);
    }

    /** Compact numeric snapshot for the LLM. */
    public static function snapshot(): array
    {
        $series = self::dailySeries(28);
        return [
            'business' => Settings::get('business_name'), 'currency' => Settings::get('currency'), 'today' => date('Y-m-d l'),
            'revenue_last_28_days_by_day' => $series,
            'top_products_30d' => DB::all("SELECT si.name, ROUND(SUM(si.qty),1) qty, ROUND(SUM(si.total)) revenue, ROUND(SUM(si.total - si.unit_cost*si.qty)) profit FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.status<>'voided' AND s.sold_at>=DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY si.product_id, si.name ORDER BY revenue DESC LIMIT 15"),
            'payment_mix_30d' => DB::pairs("SELECT method, ROUND(SUM(amount)) FROM payments WHERE is_void=0 AND kind<>'refund' AND paid_at>=DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY method"),
            'receivables' => (float)DB::val("SELECT COALESCE(SUM(balance),0) FROM sales WHERE balance>0 AND status<>'voided'"),
            'expenses_30d' => DB::pairs("SELECT category, ROUND(SUM(amount)) FROM expenses WHERE spent_on>=DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY category"),
            'low_stock' => DB::col("SELECT p.name FROM products p LEFT JOIN (SELECT product_id, SUM(qty) q FROM stock GROUP BY product_id) s ON s.product_id=p.id WHERE p.is_active=1 AND p.track_stock=1 AND COALESCE(s.q,0)<=p.reorder_level LIMIT 20"),
            'rule_based_insights' => array_map(static fn($c) => $c['title'] . ': ' . $c['body'], self::all()),
        ];
    }
}
