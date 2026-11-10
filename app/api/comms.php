<?php
const AUDIENCES = ['all' => 'All opted-in customers', 'top' => 'Top 50 spenders (90 days)', 'inactive' => 'Lapsed: no visit in 45 days', 'new' => 'New this month', 'wholesale' => 'Wholesale customers', 'debtors' => 'Customers with a balance', 'selected' => 'Hand-picked customer IDs'];

return [
    'send' => [
        'perm' => 'comms.send', 'write' => true,
        'fn' => function (array $in) {
            $ch = ($in['channel'] ?? 'email') === 'sms' ? 'sms' : 'email';
            $to = trim((string)($in['to'] ?? ''));
            if ($ch === 'email' && !filter_var($to, FILTER_VALIDATE_EMAIL)) throw new ApiError('Enter a valid email address');
            if ($ch === 'sms' && strlen(normalize_phone($to)) < 11) throw new ApiError('Enter a valid phone number');
            $c = !empty($in['customer_id']) ? DB::row('SELECT * FROM customers WHERE id=?', [(int)$in['customer_id']]) : null;
            $vars = Comms::vars($c);
            $body = tpl((string)($in['body'] ?? ''), $vars);
            if (trim($body) === '') throw new ApiError('Write a message');
            $id = Comms::queue($ch, 'manual', $to, $in['name'] ?? ($c['name'] ?? null), $ch === 'email' ? tpl((string)($in['subject'] ?? ''), $vars) ?: 'Message from ' . $vars['business'] : null,
                $ch === 'email' ? Comms::textToHtml($body) : $body, $c ? (int)$c['id'] : null);
            Audit::log('message_send', 'outbox', $id, "Manual $ch to $to");
            return Comms::process(5);
        },
    ],
    'email_document' => [
        'perm' => null, 'write' => true,
        'fn' => function (array $in) {
            if (!Auth::can('comms.send') && !Auth::can('sell.create')) throw new ApiError('No access', 403);
            $doc = (string)($in['doc'] ?? '');
            if (!Documents::canPrint($doc)) throw new ApiError('You cannot send this document', 403);
            $to = trim((string)($in['to'] ?? ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new ApiError('Enter a valid email address');
            $d = Documents::build($doc, (string)($in['id'] ?? ''), $in);
            $html = Printer::render($d, 'a4', ['email' => true]);
            $id = Comms::queue('email', 'document', $to, null, "{$d['title']} {$d['number']} — " . Settings::get('business_name'), $html);
            Audit::log('document_email', $doc, $in['id'] ?? null, "Emailed {$d['title']} {$d['number']} to $to");
            return Comms::process(3);
        },
    ],
    'debt_reminders' => [
        'perm' => 'comms.send', 'write' => true,
        'fn' => function (array $in) {
            $ids = array_values(array_filter(array_map('intval', (array)($in['ids'] ?? []))));
            if (!$ids) throw new ApiError('No customers selected');
            $queued = $skipped = 0;
            foreach (DB::all('SELECT * FROM customers WHERE id IN (' . DB::in($ids) . ')', $ids) as $c) {
                if (SaleService::customerDebt((int)$c['id']) <= 0) { $skipped++; continue; }
                Comms::reminder($c) ? $queued++ : $skipped++;
            }
            Audit::log('debt_reminders', 'customer', null, "Queued $queued debt reminders");
            Comms::process(10);
            return ['queued' => $queued, 'skipped' => $skipped];
        },
    ],
    'outbox' => [
        'perm' => 'comms.send',
        'fn' => function (array $in) {
            $where = ['1=1']; $p = [];
            if (!empty($in['status'])) { $where[] = 'o.status=?'; $p[] = $in['status']; }
            if (!empty($in['kind'])) { $where[] = 'o.kind=?'; $p[] = $in['kind']; }
            if (($q = trim((string)($in['q'] ?? ''))) !== '') { $where[] = '(o.recipient LIKE ? OR o.subject LIKE ? OR o.recipient_name LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%"); }
            $w = implode(' AND ', $where);
            $per = min(100, (int)($in['per'] ?? 25)); $off = (max(1, (int)($in['page'] ?? 1)) - 1) * $per;
            return ['total' => (int)DB::val("SELECT COUNT(*) FROM outbox o WHERE $w", $p),
                'rows' => DB::all("SELECT o.id, o.channel, o.kind, o.recipient, o.recipient_name, o.subject, o.status, o.error, o.created_at, o.sent_at FROM outbox o WHERE $w ORDER BY o.id DESC LIMIT $per OFFSET $off", $p),
                'stats' => DB::pairs('SELECT status, COUNT(*) FROM outbox GROUP BY status')];
        },
    ],
    'outbox_view' => [
        'perm' => 'comms.send',
        'fn' => fn(array $in) => DB::row('SELECT * FROM outbox WHERE id=?', [(int)($in['id'] ?? 0)]),
    ],
    'process' => [
        'perm' => 'comms.send', 'write' => true,
        'fn' => function (array $in) {
            if (!empty($in['retry'])) DB::q('UPDATE outbox SET status="queued", attempts=0 WHERE status="failed"');
            return Comms::process(25);
        },
    ],
    'test_email' => [
        'perm' => 'settings.manage', 'write' => true,
        'fn' => function (array $in) {
            $to = trim((string)($in['to'] ?? ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new ApiError('Enter a valid email');
            try { Mailer::send($to, 'Mfuko POS test email', Mailer::layout('<p>🎉 Your email settings work. Receipts, reminders and campaigns can now be delivered.</p>')); }
            catch (Throwable $e) { throw new ApiError($e->getMessage()); }
            return ['ok' => true];
        },
    ],

    /* ---------------- Campaigns ---------------- */
    'campaigns' => [
        'perm' => 'marketing.manage',
        'fn' => function (array $in) {
            $per = min(100, (int)($in['per'] ?? 25)); $off = (max(1, (int)($in['page'] ?? 1)) - 1) * $per;
            $rows = DB::all("SELECT c.*, cp.code coupon, (SELECT COUNT(*) FROM outbox o WHERE o.campaign_id=c.id AND o.status='sent') sent,
                (SELECT COUNT(*) FROM sales s WHERE s.coupon_id=c.coupon_id AND c.coupon_id IS NOT NULL) redemptions
                FROM campaigns c LEFT JOIN coupons cp ON cp.id=c.coupon_id ORDER BY c.id DESC LIMIT $per OFFSET $off");
            return ['rows' => $rows, 'total' => (int)DB::val('SELECT COUNT(*) FROM campaigns'), 'audiences' => AUDIENCES];
        },
    ],
    'campaign_save' => [
        'perm' => 'marketing.manage', 'write' => true,
        'fn' => function (array $in) {
            $d = [
                'name' => trim((string)($in['name'] ?? '')), 'channel' => ($in['channel'] ?? 'email') === 'sms' ? 'sms' : 'email',
                'subject' => trim((string)($in['subject'] ?? '')) ?: null, 'body' => (string)($in['body'] ?? ''),
                'audience' => array_key_exists($in['audience'] ?? '', AUDIENCES) ? $in['audience'] : 'all',
                'audience_ids' => trim((string)($in['audience_ids'] ?? '')) ?: null, 'coupon_id' => !empty($in['coupon_id']) ? (int)$in['coupon_id'] : null,
            ];
            if ($d['name'] === '' || trim($d['body']) === '') throw new ApiError('Name and message are required');
            // Optionally create a coupon on the fly
            if (!empty($in['new_coupon_code'])) {
                $code = strtoupper(preg_replace('/\s+/', '', (string)$in['new_coupon_code']));
                if (DB::val('SELECT id FROM coupons WHERE code=?', [$code])) throw new ApiError('Coupon code exists');
                $d['coupon_id'] = DB::insert('coupons', ['code' => $code, 'description' => $d['name'], 'type' => ($in['new_coupon_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent',
                    'value' => (float)($in['new_coupon_value'] ?? 10), 'ends_at' => !empty($in['new_coupon_ends']) ? $in['new_coupon_ends'] : null]);
            }
            $id = (int)($in['id'] ?? 0);
            if ($id) { DB::update('campaigns', $d, 'id=? AND status="draft"', [$id]); }
            else { $d['created_by'] = Auth::id(); $id = DB::insert('campaigns', $d); }
            Audit::log('campaign_save', 'campaign', $id, 'Saved campaign ' . $d['name']);
            $count = count(Comms::audience($d['audience'], $d['audience_ids'], $d['channel']));
            return ['id' => $id, 'recipients' => $count];
        },
    ],
    'campaign_preview' => [
        'perm' => 'marketing.manage',
        'fn' => function (array $in) {
            $c = DB::row('SELECT * FROM campaigns WHERE id=?', [(int)($in['id'] ?? 0)]);
            if (!$c) throw new ApiError('Not found');
            $aud = Comms::audience($c['audience'], $c['audience_ids'], $c['channel']);
            $coupon = $c['coupon_id'] ? DB::row('SELECT * FROM coupons WHERE id=?', [$c['coupon_id']]) : null;
            $vars = Comms::vars($aud[0] ?? null, ['coupon' => $coupon['code'] ?? '', 'offer' => $coupon ? ($coupon['type'] === 'percent' ? (float)$coupon['value'] . '% off' : money($coupon['value']) . ' off') : '']);
            $body = tpl($c['body'], $vars);
            return ['recipients' => count($aud), 'sample' => array_slice(array_map(static fn($x) => $x['name'], $aud), 0, 8),
                'subject' => tpl((string)$c['subject'], $vars), 'html' => $c['channel'] === 'email' ? Mailer::layout(Comms::textToHtml($body)) : nl2br(e($body))];
        },
    ],
    'campaign_send' => [
        'perm' => 'marketing.manage', 'write' => true,
        'fn' => function (array $in) {
            $c = DB::row('SELECT * FROM campaigns WHERE id=? AND status="draft"', [(int)($in['id'] ?? 0)]);
            if (!$c) throw new ApiError('Campaign not found or already sent');
            if ($c['channel'] === 'sms' && !Sms::enabled()) throw new ApiError('SMS is not configured (Settings → SMS)');
            $coupon = $c['coupon_id'] ? DB::row('SELECT * FROM coupons WHERE id=?', [$c['coupon_id']]) : null;
            $n = 0;
            foreach (Comms::audience($c['audience'], $c['audience_ids'], $c['channel']) as $cust) {
                $vars = Comms::vars($cust, ['coupon' => $coupon['code'] ?? '', 'offer' => $coupon ? ($coupon['type'] === 'percent' ? (float)$coupon['value'] . '% off' : money($coupon['value']) . ' off') : '']);
                $body = tpl($c['body'], $vars);
                if ($c['channel'] === 'email') Comms::queue('email', 'campaign', $cust['email'], $cust['name'], tpl((string)$c['subject'], $vars), Comms::textToHtml($body), (int)$cust['id'], (int)$c['id']);
                else Comms::queue('sms', 'campaign', $cust['phone'], $cust['name'], null, mb_substr($body, 0, 459), (int)$cust['id'], (int)$c['id']);
                $n++;
            }
            DB::q('UPDATE campaigns SET status="queued", recipients=?, queued_at=NOW() WHERE id=?', [$n, $c['id']]);
            Audit::log('campaign_send', 'campaign', $c['id'], "Queued campaign {$c['name']} to $n customers");
            return ['queued' => $n] + Comms::process(20);
        },
    ],
];
