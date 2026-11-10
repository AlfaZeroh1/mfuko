<?php
/**
 * Page registry: route key => meta.
 *  perm: string|array (any-of) | null (any signed-in user)
 *  group: sidebar section ('' = top) | null (hidden from menu)
 *  resource: renders the generic CRUD page for app/resources.php[key]
 */
return [
    'dashboard'   => ['title' => 'Dashboard',        'icon' => 'home',      'perm' => null,                                   'group' => ''],
    'sell'        => ['title' => 'Sell',             'icon' => 'cart',      'perm' => 'sell.create',                          'group' => '', 'layout' => 'pos'],

    'sales'       => ['title' => 'Sales',            'icon' => 'receipt',   'perm' => ['sell.create', 'sales.view_all'],      'group' => 'Sales'],
    'sale'        => ['title' => 'Sale',             'icon' => 'receipt',   'perm' => ['sell.create', 'sales.view_all'],      'group' => null],
    'quotations'  => ['title' => 'Quotations',       'icon' => 'file',      'perm' => 'quotes.manage',                        'group' => 'Sales'],
    'shifts'      => ['title' => 'Register shifts',  'icon' => 'clock',     'perm' => ['shifts.manage', 'shifts.view_all'],   'group' => 'Sales'],
    'mpesa'       => ['title' => 'M-Pesa',           'icon' => 'phone',     'perm' => 'mpesa.view',                           'group' => 'Sales'],

    'customers'   => ['title' => 'Customers',        'icon' => 'users',     'perm' => 'customers.view',                       'group' => 'Customers'],
    'customer'    => ['title' => 'Customer',         'icon' => 'user',      'perm' => 'customers.view',                       'group' => null],
    'debts'       => ['title' => 'Debts & credit',   'icon' => 'wallet',    'perm' => ['debts.collect', 'customers.view'],    'group' => 'Customers'],

    'products'    => ['title' => 'Products',         'icon' => 'box',       'perm' => 'products.view',                        'group' => 'Inventory'],
    'categories'  => ['title' => 'Categories',       'icon' => 'layers',    'perm' => 'products.manage',                      'group' => 'Inventory', 'resource' => 'categories'],
    'stock'       => ['title' => 'Stock levels',     'icon' => 'boxes',     'perm' => 'stock.view',                           'group' => 'Inventory'],
    'transfers'   => ['title' => 'Transfers',        'icon' => 'swap',      'perm' => 'stock.transfer',                       'group' => 'Inventory'],
    'stocktake'   => ['title' => 'Stock take',       'icon' => 'clipboard', 'perm' => 'stock.take',                           'group' => 'Inventory'],

    'purchases'   => ['title' => 'Purchases (GRN)',  'icon' => 'truck',     'perm' => 'purchases.manage',                     'group' => 'Purchasing'],
    'suppliers'   => ['title' => 'Suppliers',        'icon' => 'store',     'perm' => 'suppliers.manage',                     'group' => 'Purchasing'],
    'expenses'    => ['title' => 'Expenses',         'icon' => 'cash',      'perm' => 'expenses.manage',                      'group' => 'Purchasing', 'resource' => 'expenses'],

    'campaigns'   => ['title' => 'Campaigns',        'icon' => 'megaphone', 'perm' => 'marketing.manage',                     'group' => 'Marketing'],
    'coupons'     => ['title' => 'Coupons',          'icon' => 'ticket',    'perm' => 'marketing.manage',                     'group' => 'Marketing', 'resource' => 'coupons'],
    'promotions'  => ['title' => 'Promotions',       'icon' => 'tag',       'perm' => 'marketing.manage',                     'group' => 'Marketing', 'resource' => 'promotions'],
    'messages'    => ['title' => 'Messages',         'icon' => 'mail',      'perm' => 'comms.send',                           'group' => 'Marketing'],

    'reports'     => ['title' => 'Reports',          'icon' => 'chart',     'perm' => ['reports.view', 'reports.financial'],  'group' => 'Insights'],
    'insights'    => ['title' => 'AI insights',      'icon' => 'sparkles',  'perm' => ['reports.view', 'reports.financial'],  'group' => 'Insights'],

    'users'       => ['title' => 'Users',            'icon' => 'user',      'perm' => 'users.manage',                         'group' => 'Admin'],
    'roles'       => ['title' => 'Roles',            'icon' => 'shield',    'perm' => 'roles.manage',                         'group' => 'Admin'],
    'team'        => ['title' => 'Who\'s online',    'icon' => 'zap',       'perm' => 'team.view',                            'group' => 'Admin'],
    'audit'       => ['title' => 'Audit trail',      'icon' => 'history',   'perm' => 'audit.view',                           'group' => 'Admin'],
    'stores'      => ['title' => 'Stores / branches','icon' => 'store',     'perm' => 'settings.manage',                      'group' => 'Admin', 'resource' => 'stores'],
    'payment-accounts' => ['title' => 'Payment options', 'icon' => 'card',  'perm' => 'settings.manage',                      'group' => 'Admin', 'resource' => 'payment_accounts'],
    'settings'    => ['title' => 'Settings',         'icon' => 'settings',  'perm' => 'settings.manage',                      'group' => 'Admin'],

    'profile'     => ['title' => 'My account',       'icon' => 'user',      'perm' => null,                                   'group' => null],
];
