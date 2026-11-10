<?php
function dash_kpis(?int $userId = null): array
{
    $u = $userId ? ' AND user_id=' . (int)$userId : '';
    $t = DB::row("SELECT COUNT(*) n, COALESCE(SUM(total),0) rev, COALESCE(SUM(total-cost_total),0) gp, COALESCE(SUM(balance),0) cr FROM sales WHERE status<>'voided' AND sold_at>=CURDATE() $u");
    $y = DB::row("SELECT COUNT(*) n, COALESCE(SUM(total),0) rev FROM sales WHERE status<>'voided' AND sold_at>=DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND sold_at<DATE_SUB(NOW(), INTERVAL 1 DAY) $u");
    $coll = (float)DB::val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE is_void=0 AND kind<>'refund' AND paid_at>=CURDATE()" . ($userId ? ' AND received_by=' . (int)$userId : ''));
    return ['revenue' => (float)$t['rev'], 'count' => (int)$t['n'], 'gross_profit' => (float)$t['gp'], 'credit' => (float)$t['cr'],
        'avg' => $t['n'] ? (float)$t['rev'] / (int)$t['n'] : 0, 'collections' => $coll,
        'yesterday_same_time' => (float)$y['rev'], 'yesterday_count' => (int)$y['n']];
}

function dash_mix(string $since, ?int $userId = null): array
{
    return DB::pairs("SELECT method, SUM(amount) FROM payments WHERE is_void=0 AND kind<>'refund' AND paid_at>=?" . ($userId ? ' AND received_by=' . (int)$userId : '') . ' GROUP BY method', [$since]);
}

function dash_online(): array
{
    return DB::all("SELECT u.id, u.first_name, u.last_name, u.avatar, u.last_seen_at, u.last_page, r.name role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.status='active' AND u.last_seen_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) ORDER BY u.last_seen_at DESC");
}

return [
    'admin' => [
        'perm' => ['reports.view', 'reports.financial'],
        'fn' => function () {
            $series = Insights::dailySeries(30);
            $fc = Insights::forecast(Insights::dailySeries(56));
            $fin = Auth::can('reports.financial');
            $k = dash_kpis();
            if (!$fin) unset($k['gross_profit']);
            return [
                'kpis' => $k + [
                    'receivables' => (float)DB::val("SELECT COALESCE(SUM(balance),0) FROM sales WHERE balance>0 AND status<>'voided'"),
                    'payables' => $fin ? (float)DB::val('SELECT COALESCE(SUM(balance),0) FROM purchases') : null,
                    'month_revenue' => (float)DB::val("SELECT COALESCE(SUM(total),0) FROM sales WHERE status<>'voided' AND sold_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')"),
                    'month_expenses' => $fin ? (float)DB::val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE spent_on>=DATE_FORMAT(CURDATE(),'%Y-%m-01')") : null,
                    'month_gp' => $fin ? (float)DB::val("SELECT COALESCE(SUM(total-cost_total),0) FROM sales WHERE status<>'voided' AND sold_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')") : null,
                ],
                'series' => $series, 'forecast' => $fc,
                'mix' => dash_mix(date('Y-m-d', strtotime('-29 days'))),
                'top' => DB::all("SELECT si.name, SUM(si.qty) qty, SUM(si.total) revenue FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.status<>'voided' AND s.sold_at>=DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY si.product_id, si.name ORDER BY revenue DESC LIMIT 6"),
                'stores' => DB::all("SELECT st.name, COALESCE(SUM(s.total),0) revenue, COUNT(s.id) n FROM stores st LEFT JOIN sales s ON s.store_id=st.id AND s.status<>'voided' AND s.sold_at>=CURDATE() WHERE st.is_active=1 GROUP BY st.id, st.name ORDER BY revenue DESC"),
                'cashiers' => DB::all("SELECT u.first_name name, COUNT(s.id) n, COALESCE(SUM(s.total),0) revenue FROM sales s JOIN users u ON u.id=s.user_id WHERE s.status<>'voided' AND s.sold_at>=CURDATE() GROUP BY u.id, u.first_name ORDER BY revenue DESC LIMIT 6"),
                'heat' => DB::all("SELECT WEEKDAY(sold_at) d, HOUR(sold_at) h, COUNT(*) n FROM sales WHERE status<>'voided' AND sold_at>=DATE_SUB(CURDATE(), INTERVAL 28 DAY) GROUP BY WEEKDAY(sold_at), HOUR(sold_at)"),
                'online' => Auth::can('team.view') ? dash_online() : [],
                'activity' => Auth::can('audit.view') ? DB::all('SELECT a.action, a.summary, a.created_at, u.first_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 8') : [],
                'low' => DB::all('SELECT p.name, COALESCE(s.q,0) qty, p.reorder_level FROM products p LEFT JOIN (SELECT product_id, SUM(qty) q FROM stock GROUP BY product_id) s ON s.product_id=p.id WHERE p.is_active=1 AND p.track_stock=1 AND COALESCE(s.q,0)<=p.reorder_level ORDER BY qty LIMIT 6'),
            ];
        },
    ],
    'insights' => [
        'perm' => ['reports.view', 'reports.financial'],
        'fn' => fn() => ['cards' => Insights::all(), 'ai' => Ai::enabled()],
    ],
    'ask' => [
        'perm' => ['reports.view', 'reports.financial'], 'write' => true,
        'fn' => function (array $in) {
            $q = trim((string)($in['q'] ?? '')) ?: 'Give me the 3 most important things to do this week to grow profit.';
            $key = 'ai_' . md5($q . date('Y-m-d-H'));
            if ($c = DB::val('SELECT payload FROM ai_cache WHERE cache_key=?', [$key])) return ['answer' => $c, 'cached' => true];
            $answer = Ai::ask(mb_substr($q, 0, 500), Insights::snapshot());
            DB::q('REPLACE INTO ai_cache (cache_key, payload) VALUES (?,?)', [$key, $answer]);
            Audit::log('ai_ask', 'insights', null, 'Asked AI: ' . mb_substr($q, 0, 120));
            return ['answer' => $answer];
        },
    ],
    'cashier' => [
        'perm' => null,
        'fn' => function () {
            $uid = Auth::id();
            $u = Auth::user();
            $k = dash_kpis($uid);
            unset($k['gross_profit']);
            $rank = DB::all("SELECT user_id, SUM(total) t FROM sales WHERE status<>'voided' AND sold_at>=CURDATE() GROUP BY user_id ORDER BY t DESC");
            $pos = 0; foreach ($rank as $i => $r) if ((int)$r['user_id'] === $uid) $pos = $i + 1;
            return [
                'kpis' => $k, 'target' => (float)$u['daily_target'], 'rank' => $pos, 'team' => count($rank),
                'mix' => dash_mix(date('Y-m-d'), $uid),
                'recent' => DB::all("SELECT s.id, s.invoice_no, s.total, s.balance, s.sold_at, c.name customer FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.user_id=? ORDER BY s.id DESC LIMIT 8", [$uid]),
                'hourly' => DB::pairs("SELECT HOUR(sold_at), SUM(total) FROM sales WHERE user_id=? AND status<>'voided' AND sold_at>=CURDATE() GROUP BY HOUR(sold_at)", [$uid]),
                'week' => DB::pairs("SELECT DATE(sold_at), SUM(total) FROM sales WHERE user_id=? AND status<>'voided' AND sold_at>=DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(sold_at)", [$uid]),
                'shift' => SaleService::openShift($uid),
                'pending_quotes' => Auth::can('quotes.manage') ? (int)DB::val("SELECT COUNT(*) FROM quotations WHERE status='pending' AND user_id=?", [$uid]) : 0,
                'collected_debt' => (float)DB::val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE kind='debt' AND is_void=0 AND received_by=? AND paid_at>=CURDATE()", [$uid]),
            ];
        },
    ],
    'storekeeper' => [
        'perm' => ['stock.view', 'purchases.manage'],
        'fn' => function () {
            $store = Auth::currentStore();
            return [
                'counts' => DB::row('SELECT COUNT(*) products, SUM(p.track_stock=1 AND COALESCE(s.qty,0)<=0) outs, SUM(p.track_stock=1 AND COALESCE(s.qty,0)>0 AND COALESCE(s.qty,0)<=p.reorder_level) lows FROM products p LEFT JOIN stock s ON s.product_id=p.id AND s.store_id=? WHERE p.is_active=1', [$store]),
                'value' => Auth::can('products.cost') ? (float)DB::val('SELECT COALESCE(SUM(s.qty*p.cost_price),0) FROM stock s JOIN products p ON p.id=s.product_id WHERE s.store_id=? AND s.qty>0', [$store]) : null,
                'low' => DB::all('SELECT p.id, p.name, p.unit, COALESCE(s.qty,0) qty, p.reorder_level FROM products p LEFT JOIN stock s ON s.product_id=p.id AND s.store_id=? WHERE p.is_active=1 AND p.track_stock=1 AND COALESCE(s.qty,0)<=p.reorder_level ORDER BY qty LIMIT 12', [$store]),
                'expiring' => DB::all('SELECT name, expiry_date FROM products WHERE is_active=1 AND expiry_date IS NOT NULL AND expiry_date<=DATE_ADD(CURDATE(), INTERVAL 45 DAY) ORDER BY expiry_date LIMIT 8'),
                'transit' => DB::all("SELECT t.id, t.transfer_no, a.name f, b.name t2, t.created_at FROM transfers t JOIN stores a ON a.id=t.from_store_id JOIN stores b ON b.id=t.to_store_id WHERE t.status='in_transit' ORDER BY t.id DESC LIMIT 8"),
                'purchases' => DB::all('SELECT p.id, p.grn_no, s.name supplier, p.total, p.purchase_date FROM purchases p JOIN suppliers s ON s.id=p.supplier_id ORDER BY p.id DESC LIMIT 6'),
                'movers' => DB::all("SELECT si.name, SUM(si.qty) qty FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.store_id=? AND s.status<>'voided' AND s.sold_at>=DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY si.product_id, si.name ORDER BY qty DESC LIMIT 8", [$store]),
            ];
        },
    ],
    'accountant' => [
        'perm' => ['reports.financial', 'reports.view'],
        'fn' => function () {
            $m = date('Y-m-01');
            $rev = (float)DB::val("SELECT COALESCE(SUM(total),0) FROM sales WHERE status<>'voided' AND sold_at>=?", [$m]);
            $cogs = (float)DB::val("SELECT COALESCE(SUM(cost_total),0) FROM sales WHERE status<>'voided' AND sold_at>=?", [$m]);
            $ret = (float)DB::val('SELECT COALESCE(SUM(total),0) FROM credit_notes WHERE created_at>=?', [$m]);
            $exp = (float)DB::val('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE spent_on>=?', [$m]);
            return [
                'pnl' => ['revenue' => $rev, 'returns' => $ret, 'cogs' => $cogs, 'gross' => $rev - $ret - $cogs, 'expenses' => $exp, 'net' => $rev - $ret - $cogs - $exp],
                'today_mix' => dash_mix(date('Y-m-d')), 'month_mix' => dash_mix($m),
                'aging' => DB::row("SELECT SUM(CASE WHEN DATEDIFF(CURDATE(),sold_at)<=30 THEN balance ELSE 0 END) b0, SUM(CASE WHEN DATEDIFF(CURDATE(),sold_at) BETWEEN 31 AND 60 THEN balance ELSE 0 END) b30, SUM(CASE WHEN DATEDIFF(CURDATE(),sold_at)>60 THEN balance ELSE 0 END) b60 FROM sales WHERE balance>0 AND status<>'voided'"),
                'payables' => (float)DB::val('SELECT COALESCE(SUM(balance),0) FROM purchases'),
                'expenses' => DB::all('SELECT category, SUM(amount) total FROM expenses WHERE spent_on>=? GROUP BY category ORDER BY total DESC', [$m]),
                'unmatched_mpesa' => (int)DB::val("SELECT COUNT(*) FROM mpesa_transactions WHERE status='success' AND payment_id IS NULL"),
                'variances' => DB::all("SELECT sh.id, u.first_name, sh.closed_at, sh.variance FROM shifts sh JOIN users u ON u.id=sh.user_id WHERE sh.closed_at IS NOT NULL AND sh.variance<>0 ORDER BY sh.id DESC LIMIT 6"),
                'daily' => Insights::dailySeries(30),
            ];
        },
    ],
];
