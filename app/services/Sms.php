<?php
declare(strict_types=1);

/** SMS via Africa's Talking (boilerplate — set username/API key in Settings → SMS). */
final class Sms
{
    public static function enabled(): bool
    {
        return Settings::bool('sms_enabled') && Settings::get('sms_api_key') !== '';
    }

    public static function send(string $phone, string $message): void
    {
        if (!self::enabled()) throw new RuntimeException('SMS is not configured');
        $user = Settings::get('sms_username');
        $url = $user === 'sandbox' ? 'https://api.sandbox.africastalking.com/version1/messaging' : 'https://api.africastalking.com/version1/messaging';
        $fields = ['username' => $user, 'to' => '+' . normalize_phone($phone), 'message' => $message];
        if (Settings::get('sms_sender_id') !== '') $fields['from'] = Settings::get('sms_sender_id');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['apiKey: ' . Settings::get('sms_api_key'), 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        ]);
        $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $j = json_decode((string)$raw, true);
        $status = $j['SMSMessageData']['Recipients'][0]['status'] ?? null;
        if ($code >= 400 || ($status && $status !== 'Success')) throw new RuntimeException('SMS failed: ' . ($status ?? "HTTP $code"));
    }
}
