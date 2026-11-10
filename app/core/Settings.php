<?php
declare(strict_types=1);

/** Key/value business settings with sensible defaults. One query per request. */
final class Settings
{
    private static array $cache = [];

    public const DEFAULTS = [
        // Business profile
        'business_name'      => 'My Business',
        'business_tagline'   => '',
        'business_address'   => '',
        'business_town'      => 'Nairobi',
        'business_phone'     => '',
        'business_email'     => '',
        'business_kra_pin'   => '',
        'business_logo'      => '',
        // Regional
        'timezone'           => 'Africa/Nairobi',
        'currency'           => 'KES',
        'currency_symbol'    => 'KSh',
        'date_format'        => 'd M Y',
        // Sales behaviour
        'tax_mode'           => 'inclusive',   // inclusive | exclusive
        'default_tax_rate'   => '16',
        'allow_negative_stock' => '0',
        'require_shift'      => '0',
        'max_discount_pct'   => '10',
        'enforce_credit_limit' => '1',
        'default_sale_type'  => 'retail',
        'auto_print'         => '1',
        'quote_valid_days'   => '14',
        'sell_show_images'   => '1',
        // Printing
        'print_receipt_size' => 'thermal',     // default size for sale receipts
        'print_default_size' => 'a4',          // every other document
        'thermal_width'      => '80',          // 58 | 80 (mm)
        'print_footer'       => 'Thank you for shopping with us!',
        'print_terms'        => 'Goods once sold are not returnable without a receipt.',
        'print_show_pay_accounts' => '1',
        'print_show_served_by'    => '1',
        // M-Pesa (Daraja)
        'mpesa_enabled'      => '0',
        'mpesa_env'          => 'sandbox',
        'mpesa_type'         => 'paybill',     // paybill | till
        'mpesa_shortcode'    => '174379',
        'mpesa_till'         => '',
        'mpesa_passkey'      => '',
        'mpesa_consumer_key' => '',
        'mpesa_consumer_secret' => '',
        'mpesa_callback_base'=> '',
        'mpesa_callback_token' => '',
        // Email
        'mail_driver'        => 'smtp',        // smtp | mail
        'smtp_host'          => '',
        'smtp_port'          => '587',
        'smtp_secure'        => 'tls',         // tls | ssl | none
        'smtp_user'          => '',
        'smtp_pass'          => '',
        'mail_from'          => '',
        'mail_from_name'     => '',
        // SMS (Africa's Talking boilerplate)
        'sms_enabled'        => '0',
        'sms_username'       => 'sandbox',
        'sms_api_key'        => '',
        'sms_sender_id'      => '',
        // Debt reminders
        'reminder_auto'      => '0',
        'reminder_every_days'=> '7',
        'reminder_subject'   => 'Friendly reminder: balance of {balance} with {business}',
        'reminder_body'      => "Hello {name},\n\nThis is a friendly reminder that your account with {business} has an outstanding balance of {balance}. The oldest invoice is {days_overdue} days old.\n\nYou can pay via:\n{pay_accounts}\n\nThank you for your continued support.\n{business}",
        // AI
        'ai_provider'        => 'none',        // none | anthropic | openai
        'ai_api_key'         => '',
        'ai_model'           => '',
        // Appearance
        'default_theme'      => 'auto',
    ];

    public static function load(): void
    {
        try {
            self::$cache = DB::pairs('SELECT `key`, `value` FROM settings');
        } catch (Throwable $e) {
            self::$cache = [];
        }
    }

    public static function get(string $key, ?string $default = null): string
    {
        if (array_key_exists($key, self::$cache) && self::$cache[$key] !== null) return (string)self::$cache[$key];
        return (string)($default ?? self::DEFAULTS[$key] ?? '');
    }

    public static function bool(string $key): bool
    {
        return self::get($key) === '1';
    }

    public static function set(string $key, ?string $value): void
    {
        DB::q('INSERT INTO settings (`key`,`value`,updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=NOW()', [$key, $value]);
        self::$cache[$key] = $value;
    }

    public static function all(): array
    {
        return array_merge(self::DEFAULTS, array_filter(self::$cache, static fn($v) => $v !== null));
    }

    /** Settings that must never reach the browser. */
    public const SECRET = ['mpesa_passkey', 'mpesa_consumer_secret', 'smtp_pass', 'sms_api_key', 'ai_api_key', 'mpesa_callback_token'];
}
