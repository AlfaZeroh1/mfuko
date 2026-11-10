<?php
declare(strict_types=1);

/**
 * Safaricom Daraja (M-Pesa) integration — ready to switch on.
 *
 *  1. Settings → M-Pesa: consumer key/secret, shortcode, passkey, environment.
 *  2. Set the public callback base URL (https, reachable by Safaricom).
 *  3. Click "Register C2B URLs" once for paybill/till confirmations.
 *
 * Callbacks land in mpesa_callback.php?t=<token>&k=stk|c2b_confirm|c2b_validate
 */
final class Mpesa
{
    public static function enabled(): bool
    {
        return Settings::bool('mpesa_enabled') && Settings::get('mpesa_consumer_key') !== '';
    }

    private static function base(): string
    {
        return Settings::get('mpesa_env') === 'production' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
    }

    public static function callbackUrl(string $kind): string
    {
        $token = Settings::get('mpesa_callback_token');
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            Settings::set('mpesa_callback_token', $token);
        }
        $base = rtrim(Settings::get('mpesa_callback_base'), '/');
        return "$base/mpesa_callback.php?k=$kind&t=$token";
    }

    private static function http(string $method, string $path, ?array $body = null, ?string $token = null, ?string $basic = null): array
    {
        $ch = curl_init(self::base() . $path);
        $headers = ['Content-Type: application/json'];
        if ($token) $headers[] = "Authorization: Bearer $token";
        if ($basic) $headers[] = "Authorization: Basic $basic";
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new ApiError('M-Pesa unreachable: ' . $err, 502);
        $json = json_decode((string)$raw, true) ?: [];
        if ($code >= 400) {
            throw new ApiError('M-Pesa error: ' . ($json['errorMessage'] ?? $json['ResponseDescription'] ?? "HTTP $code"), 502);
        }
        return $json;
    }

    public static function token(): string
    {
        $cached = DB::row('SELECT payload, created_at FROM ai_cache WHERE cache_key="mpesa_token"');
        if ($cached && strtotime($cached['created_at']) > time() - 3300) return $cached['payload'];
        $basic = base64_encode(Settings::get('mpesa_consumer_key') . ':' . Settings::get('mpesa_consumer_secret'));
        $r = self::http('GET', '/oauth/v1/generate?grant_type=client_credentials', null, null, $basic);
        $tok = $r['access_token'] ?? '';
        if (!$tok) throw new ApiError('Could not authenticate with M-Pesa. Check consumer key/secret.', 502);
        DB::q('REPLACE INTO ai_cache (cache_key, payload, created_at) VALUES ("mpesa_token", ?, NOW())', [$tok]);
        return $tok;
    }

    /** Lipa na M-Pesa Online (STK push). Returns mpesa_transactions.id */
    public static function stkPush(string $phone, float $amount, string $accountRef, string $desc = 'Payment'): int
    {
        if (!self::enabled()) throw new ApiError('M-Pesa is not configured. Ask an admin to set it up in Settings → M-Pesa.');
        $phone = normalize_phone($phone);
        if (!preg_match('/^254[17]\d{8}$/', $phone)) throw new ApiError('Enter a valid Safaricom number e.g. 0712 345 678');
        $amount = (int)ceil($amount);
        if ($amount < 1) throw new ApiError('Amount must be at least 1');

        $short = Settings::get('mpesa_shortcode');
        $ts = date('YmdHis');
        $password = base64_encode($short . Settings::get('mpesa_passkey') . $ts);
        $isTill = Settings::get('mpesa_type') === 'till';
        $r = self::http('POST', '/mpesa/stkpush/v1/processrequest', [
            'BusinessShortCode' => $short,
            'Password'          => $password,
            'Timestamp'         => $ts,
            'TransactionType'   => $isTill ? 'CustomerBuyGoodsOnline' : 'CustomerPayBillOnline',
            'Amount'            => $amount,
            'PartyA'            => $phone,
            'PartyB'            => $isTill ? (Settings::get('mpesa_till') ?: $short) : $short,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => self::callbackUrl('stk'),
            'AccountReference'  => substr($accountRef, 0, 12),
            'TransactionDesc'   => substr($desc, 0, 13),
        ], self::token());
        if (($r['ResponseCode'] ?? '') !== '0') throw new ApiError('M-Pesa: ' . ($r['ResponseDescription'] ?? 'request rejected'), 502);

        $id = DB::insert('mpesa_transactions', [
            'type' => 'stk', 'merchant_request_id' => $r['MerchantRequestID'] ?? null, 'checkout_request_id' => $r['CheckoutRequestID'] ?? null,
            'phone' => $phone, 'amount' => $amount, 'account_ref' => $accountRef, 'status' => 'pending', 'initiated_by' => Auth::id() ?: null,
            'raw' => json_encode($r),
        ]);
        Audit::log('mpesa_stk', 'mpesa', $id, "STK push " . money($amount) . " to $phone");
        return $id;
    }

    /** Ask Daraja for the result when a callback is late. */
    public static function stkQuery(int $id): array
    {
        $tx = DB::row('SELECT * FROM mpesa_transactions WHERE id=?', [$id]);
        if (!$tx) throw new ApiError('Transaction not found');
        if ($tx['status'] !== 'pending' || !self::enabled()) return $tx;
        if (strtotime($tx['created_at']) > time() - 20) return $tx; // give the callback a chance first
        $short = Settings::get('mpesa_shortcode');
        $ts = date('YmdHis');
        try {
            $r = self::http('POST', '/mpesa/stkpushquery/v1/query', [
                'BusinessShortCode' => $short,
                'Password'          => base64_encode($short . Settings::get('mpesa_passkey') . $ts),
                'Timestamp'         => $ts,
                'CheckoutRequestID' => $tx['checkout_request_id'],
            ], self::token());
        } catch (ApiError $e) {
            return $tx; // still processing
        }
        if (isset($r['ResultCode'])) {
            $code = (string)$r['ResultCode'];
            if ($code !== '0') {
                DB::q('UPDATE mpesa_transactions SET status=?, result_code=?, result_desc=? WHERE id=? AND status="pending"', [
                    $code === '1032' ? 'cancelled' : 'failed', $code, $r['ResultDesc'] ?? null, $id,
                ]);
            }
            // success without receipt: wait for callback which carries the receipt number
        }
        return DB::row('SELECT * FROM mpesa_transactions WHERE id=?', [$id]);
    }

    public static function handleStkCallback(array $payload): void
    {
        $cb = $payload['Body']['stkCallback'] ?? null;
        if (!$cb) return;
        $meta = [];
        foreach ($cb['CallbackMetadata']['Item'] ?? [] as $item) $meta[$item['Name']] = $item['Value'] ?? null;
        $code = (string)($cb['ResultCode'] ?? '');
        DB::q('UPDATE mpesa_transactions SET status=?, result_code=?, result_desc=?, mpesa_receipt=?, amount=COALESCE(?, amount), raw=? WHERE checkout_request_id=?', [
            $code === '0' ? 'success' : ($code === '1032' ? 'cancelled' : 'failed'),
            $code, $cb['ResultDesc'] ?? null, $meta['MpesaReceiptNumber'] ?? null, $meta['Amount'] ?? null,
            json_encode($payload), $cb['CheckoutRequestID'] ?? '',
        ]);
    }

    /** C2B confirmation: customer paid to till/paybill directly. Stored unmatched until claimed. */
    public static function handleC2B(array $p): void
    {
        $receipt = $p['TransID'] ?? null;
        if (!$receipt) return;
        $name = trim(($p['FirstName'] ?? '') . ' ' . ($p['MiddleName'] ?? '') . ' ' . ($p['LastName'] ?? ''));
        DB::q('INSERT IGNORE INTO mpesa_transactions (type, phone, amount, account_ref, mpesa_receipt, payer_name, status, result_code, raw) VALUES ("c2b",?,?,?,?,?,"success","0",?)', [
            $p['MSISDN'] ?? null, (float)($p['TransAmount'] ?? 0), $p['BillRefNumber'] ?? null, $receipt, $name ?: null, json_encode($p),
        ]);
    }

    public static function registerC2B(): array
    {
        return self::http('POST', '/mpesa/c2b/v1/registerurl', [
            'ShortCode'       => Settings::get('mpesa_shortcode'),
            'ResponseType'    => 'Completed',
            'ConfirmationURL' => self::callbackUrl('c2b_confirm'),
            'ValidationURL'   => self::callbackUrl('c2b_validate'),
        ], self::token());
    }
}
