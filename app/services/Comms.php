<?php
declare(strict_types=1);

/** Outbox: everything (campaigns, reminders, receipts, manual) is queued then delivered. */
final class Comms
{
    public static function vars(?array $c = null, array $extra = []): array
    {
        $debt = $c ? SaleService::customerDebt((int)$c['id']) : 0.0;
        $oldest = $c ? DB::val('SELECT MIN(sold_at) FROM sales WHERE customer_id=? AND balance>0 AND status<>"voided"', [$c['id']]) : null;
        return array_merge([
            'name' => $c ? (explode(' ', trim($c['name']))[0] ?: $c['name']) : 'there',
            'full_name' => $c['name'] ?? '',
            'business' => Settings::get('business_name'),
            'phone' => Settings::get('business_phone'),
            'balance' => money($debt),
            'days_overdue' => $oldest ? (string)floor((time() - strtotime($oldest)) / 86400) : '0',
            'pay_accounts' => Printer::payAccountsText() ?: Settings::get('business_phone'),
        ], $extra);
    }

    public static function queue(string $channel, string $kind, string $to, ?string $name, ?string $subject, string $body, ?int $customerId = null, ?int $campaignId = null): int
    {
        return DB::insert('outbox', [
            'channel' => $channel, 'kind' => $kind, 'recipient' => $to, 'recipient_name' => $name, 'subject' => $subject, 'body' => $body,
            'customer_id' => $customerId, 'campaign_id' => $campaignId, 'created_by' => Auth::id() ?: null,
        ]);
    }

    /** Plain text → simple HTML paragraphs for email. */
    public static function textToHtml(string $t): string
    {
        $t = e($t);
        $t = preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', $t);
        return '<p>' . str_replace(["\n\n", "\n"], ['</p><p>', '<br>'], $t) . '</p>';
    }

    /** Deliver up to $limit queued messages. Called by cron and after queuing. */
    public static function process(int $limit = 20): array
    {
        $sent = $failed = 0;
        foreach (DB::all('SELECT * FROM outbox WHERE status="queued" AND attempts<3 ORDER BY id LIMIT ' . max(1, $limit)) as $m) {
            DB::q('UPDATE outbox SET attempts=attempts+1 WHERE id=?', [$m['id']]);
            try {
                if ($m['channel'] === 'sms') {
                    Sms::send($m['recipient'], $m['body']);
                } else {
                    $html = str_starts_with(ltrim($m['body']), '<!doctype') ? $m['body'] : Mailer::layout($m['body'], (string)$m['subject']);
                    Mailer::send($m['recipient'], (string)$m['subject'], $html, $m['recipient_name']);
                }
                DB::q('UPDATE outbox SET status="sent", sent_at=NOW(), error=NULL WHERE id=?', [$m['id']]);
                $sent++;
            } catch (Throwable $e) {
                DB::q('UPDATE outbox SET status=IF(attempts>=3,"failed","queued"), error=? WHERE id=?', [mb_substr($e->getMessage(), 0, 255), $m['id']]);
                $failed++;
            }
        }
        return ['sent' => $sent, 'failed' => $failed, 'pending' => (int)DB::val('SELECT COUNT(*) FROM outbox WHERE status="queued"')];
    }

    public static function reminder(array $c): bool
    {
        $vars = self::vars($c);
        $subject = tpl(Settings::get('reminder_subject'), $vars);
        $body = tpl(Settings::get('reminder_body'), $vars);
        if ($c['email']) {
            self::queue('email', 'debt_reminder', $c['email'], $c['name'], $subject, self::textToHtml($body), (int)$c['id']);
        } elseif ($c['phone'] && Sms::enabled()) {
            self::queue('sms', 'debt_reminder', $c['phone'], $c['name'], null, mb_substr("{$vars['business']}: Hi {$vars['name']}, your balance is {$vars['balance']}. Pay via " . str_replace(["\n", '• '], ['; ', ''], $vars['pay_accounts']) . '. Asante!', 0, 320), (int)$c['id']);
        } else {
            return false;
        }
        DB::q('UPDATE customers SET last_reminder_at=NOW() WHERE id=?', [$c['id']]);
        return true;
    }

    /** Customer audiences for campaigns. */
    public static function audience(string $key, ?string $ids, string $channel): array
    {
        $contact = $channel === 'sms' ? 'c.phone IS NOT NULL AND c.phone<>""' : 'c.email IS NOT NULL AND c.email<>""';
        $base = "SELECT c.* FROM customers c WHERE c.is_active=1 AND c.marketing_opt_in=1 AND $contact";
        switch ($key) {
            case 'debtors': return DB::all("SELECT c.* FROM customers c WHERE c.is_active=1 AND $contact AND EXISTS (SELECT 1 FROM sales s WHERE s.customer_id=c.id AND s.balance>0 AND s.status<>'voided')");
            case 'top': return DB::all("$base AND c.id IN (SELECT customer_id FROM (SELECT customer_id, SUM(total) t FROM sales WHERE status<>'voided' AND customer_id IS NOT NULL AND sold_at>=DATE_SUB(NOW(), INTERVAL 90 DAY) GROUP BY customer_id ORDER BY t DESC LIMIT 50) x)");
            case 'inactive': return DB::all("$base AND EXISTS (SELECT 1 FROM sales s WHERE s.customer_id=c.id) AND NOT EXISTS (SELECT 1 FROM sales s WHERE s.customer_id=c.id AND s.sold_at>=DATE_SUB(NOW(), INTERVAL 45 DAY))");
            case 'new': return DB::all("$base AND c.created_at>=DATE_SUB(NOW(), INTERVAL 30 DAY)");
            case 'wholesale': return DB::all("$base AND c.price_tier='wholesale'");
            case 'selected':
                $list = array_values(array_filter(array_map('intval', explode(',', (string)$ids))));
                return $list ? DB::all("SELECT c.* FROM customers c WHERE c.id IN (" . DB::in($list) . ") AND $contact", $list) : [];
            default: return DB::all($base);
        }
    }
}
