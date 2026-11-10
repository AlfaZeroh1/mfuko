<?php
declare(strict_types=1);

/** Renders a normalized document (see Documents) as A4 or thermal HTML. */
final class Printer
{
    public static function payAccounts(): array
    {
        return DB::all('SELECT * FROM payment_accounts WHERE is_active=1 AND show_on_documents=1 AND method<>"cash" ORDER BY sort_order, id');
    }

    public static function payAccountsText(): string
    {
        $out = [];
        foreach (self::payAccounts() as $a) {
            $line = $a['name'];
            if ($a['account_type'] === 'paybill') $line = 'M-Pesa Paybill ' . $a['account_number'] . ($a['account_name'] ? ', Acc: ' . $a['account_name'] : '');
            elseif ($a['account_type'] === 'till') $line = 'M-Pesa Till (Buy Goods) ' . $a['account_number'];
            elseif ($a['method'] === 'bank') $line = trim(($a['provider'] ?: $a['name']) . ' A/C ' . $a['account_number'] . ($a['account_name'] ? ' (' . $a['account_name'] . ')' : '') . ($a['branch'] ? ', ' . $a['branch'] : ''));
            elseif ($a['account_number']) $line .= ': ' . $a['account_number'];
            $out[] = '• ' . $line;
        }
        return implode("\n", $out);
    }

    public static function render(array $d, string $size, array $opt = []): string
    {
        $size = $size === 'thermal' ? 'thermal' : 'a4';
        $b = Settings::all();
        $accounts = ($d['pay_accounts'] && Settings::bool('print_show_pay_accounts')) ? self::payAccounts() : [];
        $logo = '';
        if ($size === 'a4' && $b['business_logo']) {
            $logo = !empty($opt['email']) ? rtrim((string)Env::get('APP_URL', ''), '/') . '/' . $b['business_logo'] : $b['business_logo'];
        }
        ob_start();
        require MF_ROOT . '/app/print/' . ($size === 'thermal' ? 'thermal.php' : 'a4.php');
        return (string)ob_get_clean();
    }
}
