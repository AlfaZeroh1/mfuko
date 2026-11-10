<?php
declare(strict_types=1);

final class CustomerStatement
{
    public static function build(int $customerId, ?string $from = null, ?string $to = null): array
    {
        $c = DB::row('SELECT * FROM customers WHERE id=?', [$customerId]);
        if (!$c) throw new ApiError('Customer not found', 404);
        $from = $from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : date('Y-m-d', strtotime('-90 days'));
        $to = $to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : date('Y-m-d');
        $toEnd = $to . ' 23:59:59';

        $debitBefore = (float)DB::val('SELECT COALESCE(SUM(total),0) FROM sales WHERE customer_id=? AND status<>"voided" AND sold_at<?', [$customerId, $from]);
        $creditBefore = (float)DB::val('SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN sales s ON s.id=p.sale_id WHERE s.customer_id=? AND p.is_void=0 AND p.kind<>"refund" AND s.status<>"voided" AND p.paid_at<?', [$customerId, $from]);
        $returnsBefore = (float)DB::val('SELECT COALESCE(SUM(cn.applied_to_balance),0) FROM credit_notes cn JOIN sales s ON s.id=cn.sale_id WHERE s.customer_id=? AND cn.created_at<?', [$customerId, $from]);
        $opening = round($debitBefore - $creditBefore - $returnsBefore, 2);

        $rows = [];
        foreach (DB::all('SELECT id, invoice_no, sold_at, total FROM sales WHERE customer_id=? AND status<>"voided" AND sold_at BETWEEN ? AND ?', [$customerId, $from, $toEnd]) as $s) {
            $rows[] = ['date' => $s['sold_at'], 'ref' => $s['invoice_no'], 'desc' => 'Invoice', 'debit' => (float)$s['total'], 'credit' => 0.0];
        }
        foreach (DB::all('SELECT p.paid_at, p.amount, p.method, p.reference, p.receipt_no, p.kind, s.invoice_no FROM payments p JOIN sales s ON s.id=p.sale_id WHERE s.customer_id=? AND p.is_void=0 AND p.kind<>"refund" AND s.status<>"voided" AND p.paid_at BETWEEN ? AND ?', [$customerId, $from, $toEnd]) as $p) {
            $rows[] = ['date' => $p['paid_at'], 'ref' => $p['receipt_no'] ?: $p['invoice_no'], 'desc' => ($p['kind'] === 'debt' ? 'Payment' : 'Paid at sale') . ' · ' . method_label($p['method']) . ($p['reference'] ? ' ' . $p['reference'] : '') . ' → ' . $p['invoice_no'], 'debit' => 0.0, 'credit' => (float)$p['amount']];
        }
        foreach (DB::all('SELECT cn.created_at, cn.cn_no, cn.applied_to_balance, s.invoice_no FROM credit_notes cn JOIN sales s ON s.id=cn.sale_id WHERE s.customer_id=? AND cn.applied_to_balance>0 AND cn.created_at BETWEEN ? AND ?', [$customerId, $from, $toEnd]) as $r) {
            $rows[] = ['date' => $r['created_at'], 'ref' => $r['cn_no'], 'desc' => 'Return / credit note → ' . $r['invoice_no'], 'debit' => 0.0, 'credit' => (float)$r['applied_to_balance']];
        }
        usort($rows, static fn($a, $b) => strcmp($a['date'], $b['date']) ?: ($b['debit'] <=> $a['debit']));
        $bal = $opening;
        foreach ($rows as &$r) { $bal = round($bal + $r['debit'] - $r['credit'], 2); $r['balance'] = $bal; }
        unset($r);
        return ['customer' => $c, 'from' => $from, 'to' => $to, 'opening' => $opening, 'rows' => $rows, 'closing' => $bal,
            'debit' => array_sum(array_column($rows, 'debit')), 'credit' => array_sum(array_column($rows, 'credit'))];
    }
}
