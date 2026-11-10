<?php
declare(strict_types=1);

final class ShiftService
{
    public static function summary(array $s): array
    {
        $end = $s['closed_at'] ?: date('Y-m-d H:i:s');
        $byMethod = DB::pairs('SELECT method, SUM(amount) FROM payments WHERE received_by=? AND is_void=0 AND kind<>"refund" AND paid_at BETWEEN ? AND ? GROUP BY method', [$s['user_id'], $s['opened_at'], $end]);
        $sales = DB::row('SELECT COUNT(*) n, COALESCE(SUM(total),0) t, COALESCE(SUM(balance),0) cr FROM sales WHERE user_id=? AND status<>"voided" AND sold_at BETWEEN ? AND ?', [$s['user_id'], $s['opened_at'], $end]);
        $cashExp = (float)DB::val('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id=? AND method="cash" AND created_at BETWEEN ? AND ?', [$s['user_id'], $s['opened_at'], $end]);
        $cashRef = (float)DB::val('SELECT COALESCE(SUM(refund_amount),0) FROM credit_notes WHERE user_id=? AND refund_method="cash" AND created_at BETWEEN ? AND ?', [$s['user_id'], $s['opened_at'], $end]);
        $cash = (float)($byMethod['cash'] ?? 0);
        return [
            'by_method' => array_map('floatval', $byMethod), 'sales_count' => (int)$sales['n'], 'sales_total' => (float)$sales['t'], 'credit' => (float)$sales['cr'],
            'cash_expenses' => $cashExp, 'cash_refunds' => $cashRef,
            'expected_cash' => round((float)$s['opening_float'] + $cash - $cashExp - $cashRef, 2),
        ];
    }
}
