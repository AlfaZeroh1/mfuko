<?php
/**
 * Background worker. Add to crontab (every minute):
 *   * * * * * php /path/to/mfuko-pos/tools/cron.php >> /path/to/mfuko-pos/storage/logs/cron.log 2>&1
 * - delivers queued email/SMS
 * - automatic debt reminders (Settings → Debt reminders), once per day at 09:00
 * - expires stale pending M-Pesa STK requests
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

$lock = fopen(MF_ROOT . '/storage/cron.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit(0);

$r = Comms::process(50);
if ($r['sent'] || $r['failed']) echo date('c') . " outbox sent={$r['sent']} failed={$r['failed']} pending={$r['pending']}\n";

DB::q('UPDATE mpesa_transactions SET status="failed", result_desc="Timed out" WHERE status="pending" AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)');

if (Settings::bool('reminder_auto') && date('H:i') >= '09:00' && Settings::get('last_auto_reminder') !== date('Y-m-d')) {
    Settings::set('last_auto_reminder', date('Y-m-d'));
    $days = max(1, (int)Settings::get('reminder_every_days'));
    $n = 0;
    foreach (DB::all("SELECT DISTINCT c.* FROM customers c JOIN sales s ON s.customer_id=c.id
        WHERE c.is_active=1 AND s.balance>0 AND s.status<>'voided' AND s.due_date < CURDATE()
          AND (c.last_reminder_at IS NULL OR c.last_reminder_at < DATE_SUB(NOW(), INTERVAL ? DAY))", [$days]) as $c) {
        if (Comms::reminder($c)) $n++;
    }
    Audit::log('auto_reminders', 'customer', null, "Cron queued $n automatic debt reminders", null, null, 0);
    echo date('c') . " queued $n debt reminders\n";
}
