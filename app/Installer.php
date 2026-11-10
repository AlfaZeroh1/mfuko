<?php
declare(strict_types=1);

/** Shared by install.php (web) and tools/migrate_legacy.php (CLI). */
final class Installer
{
    public static function schema(PDO $pdo): void
    {
        $sql = (string)file_get_contents(MF_ROOT . '/sql/schema.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) $pdo->exec($stmt);
    }

    /** Defaults for a brand-new business. */
    public static function seed(array $biz, array $admin): void
    {
        foreach ($biz as $k => $v) if (array_key_exists($k, Settings::DEFAULTS)) Settings::set($k, (string)$v);
        Settings::set('mpesa_callback_token', bin2hex(random_bytes(16)));
        Sequence::seed();
        $roles = Perm::seedRoles();
        $storeId = (int)DB::val('SELECT id FROM stores ORDER BY id LIMIT 1') ?: DB::insert('stores', ['name' => $biz['store_name'] ?? 'Main Store', 'code' => 'MAIN']);
        if (!DB::val('SELECT COUNT(*) FROM payment_accounts')) {
            DB::insert('payment_accounts', ['method' => 'cash', 'name' => 'Cash', 'show_on_documents' => 0, 'sort_order' => 0]);
            DB::insert('payment_accounts', ['method' => 'mpesa', 'name' => 'M-Pesa', 'provider' => 'Safaricom', 'account_type' => 'till', 'requires_reference' => 1, 'stk_enabled' => 1, 'sort_order' => 1]);
            DB::insert('payment_accounts', ['method' => 'bank', 'name' => 'Bank transfer', 'account_type' => 'current', 'requires_reference' => 1, 'sort_order' => 2]);
        }
        if (!DB::val('SELECT COUNT(*) FROM categories')) DB::insert('categories', ['name' => 'General', 'color' => '#C4502A']);
        if ($admin) {
            DB::insert('users', [
                'role_id' => $roles['Owner'], 'first_name' => $admin['first_name'], 'last_name' => $admin['last_name'] ?? null,
                'username' => strtolower($admin['username']), 'email' => $admin['email'] ?? null,
                'password_hash' => password_hash($admin['password'], PASSWORD_DEFAULT), 'default_store_id' => $storeId,
            ]);
        }
    }
}
