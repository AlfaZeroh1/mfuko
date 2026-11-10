<?php
declare(strict_types=1);

/** Permission catalogue + default role templates. Single source of truth. */
final class Perm
{
    public const GROUPS = [
        'Selling' => [
            'sell.create'        => 'Make sales',
            'sell.discount'      => 'Give line discounts',
            'sell.price_override'=> 'Change selling price',
            'sell.credit'        => 'Sell on credit (loan)',
            'sell.backdate'      => 'Back-date sales',
            'sales.view_all'     => 'See everyone\'s sales (else own only)',
            'sales.return'       => 'Process returns / credit notes',
            'sales.void'         => 'Void sales',
            'quotes.manage'      => 'Quotations',
            'shifts.manage'      => 'Open / close own register',
            'shifts.view_all'    => 'See all register shifts',
        ],
        'Customers & Debts' => [
            'customers.view'     => 'View customers',
            'customers.manage'   => 'Create / edit customers',
            'debts.collect'      => 'Receive debt payments',
        ],
        'Inventory' => [
            'products.view'      => 'View products',
            'products.manage'    => 'Create / edit products & prices',
            'products.cost'      => 'See cost prices & margins',
            'stock.view'         => 'View stock levels',
            'stock.adjust'       => 'Adjust stock / break down packs',
            'stock.transfer'     => 'Transfer between stores',
            'stock.take'         => 'Stock takes',
        ],
        'Purchasing' => [
            'purchases.manage'   => 'Receive stock (GRN) & supplier returns',
            'suppliers.manage'   => 'Suppliers & supplier payments',
            'expenses.manage'    => 'Record expenses',
        ],
        'Marketing & Comms' => [
            'marketing.manage'   => 'Coupons, promotions & campaigns',
            'comms.send'         => 'Send emails / SMS & debt reminders',
        ],
        'Insight' => [
            'reports.view'       => 'Operational reports',
            'reports.financial'  => 'Financial reports (profit, valuation)',
            'mpesa.view'         => 'M-Pesa transactions',
        ],
        'Administration' => [
            'users.manage'       => 'Manage users',
            'roles.manage'       => 'Manage roles & permissions',
            'team.view'          => 'See who is online',
            'audit.view'         => 'Audit trail',
            'settings.manage'    => 'System settings',
        ],
    ];

    public static function all(): array
    {
        $out = [];
        foreach (self::GROUPS as $perms) $out = array_merge($out, array_keys($perms));
        return $out;
    }

    /** Default roles created by the installer. '*' = everything. */
    public const ROLE_TEMPLATES = [
        'Owner' => [
            'description' => 'Full access to everything',
            'dashboard' => 'admin', 'home_page' => 'dashboard', 'perms' => ['*'],
        ],
        'Manager' => [
            'description' => 'Runs the shop day to day',
            'dashboard' => 'admin', 'home_page' => 'dashboard',
            'perms' => ['sell.create','sell.discount','sell.price_override','sell.credit','sales.view_all','sales.return','sales.void','quotes.manage','shifts.manage','shifts.view_all',
                        'customers.view','customers.manage','debts.collect','products.view','products.manage','products.cost','stock.view','stock.adjust','stock.transfer','stock.take',
                        'purchases.manage','suppliers.manage','expenses.manage','marketing.manage','comms.send','reports.view','reports.financial','mpesa.view','team.view','audit.view'],
        ],
        'Cashier' => [
            'description' => 'Sells at the counter',
            'dashboard' => 'cashier', 'home_page' => 'sell',
            'perms' => ['sell.create','sell.credit','quotes.manage','shifts.manage','customers.view','customers.manage','debts.collect','products.view','stock.view'],
        ],
        'Storekeeper' => [
            'description' => 'Receives and moves stock',
            'dashboard' => 'storekeeper', 'home_page' => 'dashboard',
            'perms' => ['products.view','products.manage','stock.view','stock.adjust','stock.transfer','stock.take','purchases.manage','suppliers.manage','reports.view'],
        ],
        'Accountant' => [
            'description' => 'Money in, money out',
            'dashboard' => 'accountant', 'home_page' => 'dashboard',
            'perms' => ['sales.view_all','customers.view','debts.collect','products.view','products.cost','stock.view','suppliers.manage','expenses.manage','comms.send','reports.view','reports.financial','mpesa.view','shifts.view_all'],
        ],
    ];

    /** Seed / refresh roles. Returns [name => id]. */
    public static function seedRoles(): array
    {
        $ids = [];
        foreach (self::ROLE_TEMPLATES as $name => $t) {
            $id = (int)DB::val('SELECT id FROM roles WHERE name=?', [$name]);
            if (!$id) {
                $id = DB::insert('roles', [
                    'name' => $name, 'description' => $t['description'], 'dashboard' => $t['dashboard'],
                    'home_page' => $t['home_page'], 'is_system' => $name === 'Owner' ? 1 : 0,
                ]);
                foreach ($t['perms'] as $p) DB::insert('role_permissions', ['role_id' => $id, 'permission' => $p]);
            }
            $ids[$name] = $id;
        }
        return $ids;
    }
}
