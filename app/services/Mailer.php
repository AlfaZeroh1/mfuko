<?php
declare(strict_types=1);

/** Dependency-free SMTP client (STARTTLS / SSL / AUTH LOGIN) with mail() fallback. */
final class Mailer
{
    public static function send(string $to, string $subject, string $html, ?string $toName = null): void
    {
        $from = Settings::get('mail_from') ?: Settings::get('business_email');
        $fromName = Settings::get('mail_from_name') ?: Settings::get('business_name');
        if (!$from) throw new RuntimeException('Set a "From" email address in Settings → Email');
        $boundary = 'mf' . bin2hex(random_bytes(8));
        $text = trim(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div|\/tr|\/h\d)[^>]*>/i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $enc = static fn($s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
        $headers = [
            'From: ' . $enc($fromName) . " <$from>",
            'To: ' . ($toName ? $enc($toName) . " <$to>" : $to),
            'Subject: ' . $enc($subject),
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . (explode('@', $from)[1] ?? 'mfuko.local') . '>',
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"$boundary\"",
        ];
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
            . "--$boundary--\r\n";

        if (Settings::get('mail_driver') === 'mail' || Settings::get('smtp_host') === '') {
            $h = array_values(array_filter($headers, static fn($x) => !str_starts_with($x, 'To:') && !str_starts_with($x, 'Subject:')));
            if (!mail($to, $enc($subject), $body, implode("\r\n", $h))) throw new RuntimeException('mail() failed');
            return;
        }
        self::smtp($from, $to, implode("\r\n", $headers) . "\r\n\r\n" . $body);
    }

    private static function smtp(string $from, string $to, string $data): void
    {
        $host = Settings::get('smtp_host'); $port = (int)Settings::get('smtp_port'); $secure = Settings::get('smtp_secure');
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]));
        if (!$fp) throw new RuntimeException("SMTP connect failed: $errstr");
        stream_set_timeout($fp, 20);
        $read = static function () use ($fp): string {
            $out = '';
            while (($line = fgets($fp, 515)) !== false) { $out .= $line; if (isset($line[3]) && $line[3] === ' ') break; }
            return $out;
        };
        $cmd = static function (string $c, array $ok) use ($fp, $read): string {
            if ($c !== '') fwrite($fp, $c . "\r\n");
            $r = $read();
            if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new RuntimeException('SMTP: ' . trim($r));
            return $r;
        };
        $ehlo = 'EHLO ' . (gethostname() ?: 'mfuko');
        $cmd('', [220]);
        $cmd($ehlo, [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('STARTTLS failed');
            $cmd($ehlo, [250]);
        }
        if (Settings::get('smtp_user') !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode(Settings::get('smtp_user')), [334]);
            $cmd(base64_encode(Settings::get('smtp_pass')), [235]);
        }
        $cmd("MAIL FROM:<$from>", [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);
        $cmd(preg_replace('/^\./m', '..', $data) . "\r\n.", [250]);
        $cmd('QUIT', [221]);
        fclose($fp);
    }

    /** Wrap content in the branded email layout (business logo, kente band). */
    public static function layout(string $inner, string $preheader = ''): string
    {
        $b = Settings::all();
        $base = rtrim((string)Env::get('APP_URL', ''), '/');
        $logo = $b['business_logo'] && $base ? '<img src="' . e($base . '/' . $b['business_logo']) . '" alt="" style="max-height:56px;max-width:180px">' : '';
        return '<!doctype html><html><body style="margin:0;background:#F3EADB;font-family:Segoe UI,Arial,sans-serif;color:#1F1830">'
            . '<span style="display:none">' . e($preheader) . '</span>'
            . '<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
            . '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:14px;overflow:hidden">'
            . '<tr><td style="height:6px;background:linear-gradient(90deg,#C4502A,#E3A92B,#1E8A5A,#2B1B57)"></td></tr>'
            . '<tr><td style="padding:22px 26px 6px">' . $logo . '<div style="font-size:20px;font-weight:700;color:#2B1B57">' . e($b['business_name']) . '</div></td></tr>'
            . '<tr><td style="padding:10px 26px 26px;font-size:15px;line-height:1.6">' . $inner . '</td></tr>'
            . '<tr><td style="padding:14px 26px;background:#FBF7F0;font-size:12px;color:#7A7088">' . e(implode(' · ', array_filter([$b['business_address'], $b['business_town'], $b['business_phone']]))) . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
