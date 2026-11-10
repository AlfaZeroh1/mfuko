<?php
/**
 * Mfuko POS — migrate a client from the legacy POS (v1, "pos2") to Mfuko POS v2.
 *
 *   php tools/migrate_legacy.php --legacy-db=pos [--legacy-host=127.0.0.1] [--legacy-port=3306]
 *        [--legacy-user=root] [--legacy-pass=secret] [--legacy-path=/var/www/pos2]
 *        [--fresh --yes] [--dry-run] [--copy-logo]
 *
 * Target = the database in config/.env (run install.php first, OR pass --fresh --yes to
 * create/replace the schema in that database). Legacy credentials can also be read from
 * <legacy-path>/.env (DB_HOST, DB_NAME, DB_USER, DB_PASS).
 *
 * - IDs are preserved, so invoice numbers, customers and products keep their identity.
 * - Passwords carry over (v1 used bcrypt): users sign in with the same password.
 * - Idempotent with --fresh: re-run as many times as needed while demoing.
 * - Ends with a verification report comparing counts and money totals.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require dirname(__DIR__) . '/app/bootstrap.php';
require MF_ROOT . '/app/Installer.php';

$opt = getopt('', ['legacy-db:', 'legacy-host:', 'legacy-port:', 'legacy-user:', 'legacy-pass:', 'legacy-path:', 'fresh', 'yes', 'dry-run', 'copy-logo', 'help']);
if (isset($opt['help'])) { echo preg_replace('/^ \* ?/m', '', (string)substr((string)file_get_contents(__FILE__), 6, 1100)); exit(0); }

$legacyEnv = [];
if (!empty($opt['legacy-path']) && is_file($opt['legacy-path'] . '/.env')) {
    foreach (file($opt['legacy-path'] . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        if (str_contains($l, '=') && $l[0] !== '#') { [$k, $v] = explode('=', $l, 2); $legacyEnv[trim($k)] = trim($v); }
    }
}
$L = [
    'host' => $opt['legacy-host'] ?? $legacyEnv['DB_HOST'] ?? '127.0.0.1',
    'port' => $opt['legacy-port'] ?? '3306',
    'db'   => $opt['legacy-db'] ?? $legacyEnv['DB_NAME'] ?? null,
    'user' => $opt['legacy-user'] ?? $legacyEnv['DB_USER'] ?? 'root',
    'pass' => $opt['legacy-pass'] ?? $legacyEnv['DB_PASS'] ?? '',
];
if (!$L['db']) { fwrite(STDERR, "Missing --legacy-db (or --legacy-path with a .env)\n"); exit(1); }
$dry = isset($opt['dry-run']);

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function step(string $s): void { fwrite(STDOUT, "\n\033[1;33m▸ $s\033[0m\n"); }

$legacy = DB::connect("mysql:host={$L['host']};port={$L['port']};dbname={$L['db']};charset=utf8mb4", $L['user'], $L['pass']);
$legacy->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
$T = DB::pdo();
out("Legacy: {$L['user']}@{$L['host']}/{$L['db']}   →   Target: " . Env::get('DB_NAME') . ($dry ? '   (DRY RUN — nothing will be written)' : ''));

$legacyTables = $legacy->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$has = static fn(string $t) => in_array($t, $legacyTables, true);
$rows = static function (string $sql) use ($legacy): array { return $legacy->query($sql)->fetchAll(PDO::FETCH_ASSOC); };
foreach (['auth_users', 'inv_products', 'sls_sales'] as $must) if (!$has($must)) { fwrite(STDERR, "Legacy database is missing `$must` — is this the right database?\n"); exit(1); }

/* ---------------- Target schema ---------------- */
$targetTables = $T->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (isset($opt['fresh'])) {
    if (!isset($opt['yes'])) { fwrite(STDERR, "--fresh drops ALL Mfuko tables in " . Env::get('DB_NAME') . ". Add --yes to confirm.\n"); exit(1); }
    if (!$dry) {
        step('Recreating schema');
        $T->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($targetTables as $t) $T->exec("DROP TABLE IF EXISTS `$t`");
        $T->exec('SET FOREIGN_KEY_CHECKS=1');
        Installer::schema($T);
        Settings::load();
    }
} elseif (!in_array('users', $targetTables, true)) {
    if (!$dry) { step('Creating schema'); Installer::schema($T); Settings::load(); }
} elseif ((int)DB::val('SELECT COUNT(*) FROM sales') > 0) {
    fwrite(STDERR, "Target already has sales. Use --fresh --yes to replace, or point config/.env at an empty database.\n"); exit(1);
}

$stats = [];
$uniq = static function (string $no) { static $seen = []; $k = $no; $i = 1; while (isset($seen[$k])) $k = $no . '-' . (++$i); $seen[$k] = true; return $k; };
$put = static function (string $table, array $data) use ($dry, &$stats): void {
    $stats[$table] = ($stats[$table] ?? 0) + 1;
    if (!$dry) DB::insert($table, $data);
};
$dt = static fn($v) => ($v && $v !== '0000-00-00 00:00:00' && $v !== '0000-00-00') ? $v : null;
$method = static function (?string $s): string {
    $s = strtolower((string)$s);
    if (str_contains($s, 'mpesa') || str_contains($s, 'm-pesa') || str_contains($s, 'm pesa')) return 'mpesa';
    if (preg_match('/bank|cheque|check|eft|rtgs|transfer/', $s)) return 'bank';
    if (str_contains($s, 'card') || str_contains($s, 'visa')) return 'card';
    if ($s === '' || str_contains($s, 'cash')) return 'cash';
    return 'other';
};

if (!$dry) { $T->exec('SET FOREIGN_KEY_CHECKS=0'); $T->beginTransaction(); }
try {
    /* ---------------- Settings from app_config.json ---------------- */
    step('Business settings');
    $cfg = [];
    if (!empty($opt['legacy-path']) && is_file($opt['legacy-path'] . '/app_config.json')) {
        $cfg = json_decode((string)file_get_contents($opt['legacy-path'] . '/app_config.json'), true)['company'] ?? [];
    }
    $biz = array_filter([
        'business_name' => $cfg['name'] ?? null, 'business_address' => $cfg['address'] ?? null, 'business_town' => $cfg['town'] ?? null,
        'business_phone' => $cfg['tel'] ?? null, 'business_tagline' => $cfg['slogan'] ?? null, 'business_kra_pin' => $cfg['kra_pin'] ?? null,
        'timezone' => 'Africa/Nairobi',
    ]);
    if (!$dry) { Installer::seed($biz, []); DB::q('DELETE FROM payment_accounts'); DB::q('DELETE FROM categories'); DB::q('DELETE FROM stores'); DB::q('DELETE FROM role_permissions'); DB::q('DELETE FROM roles'); }
    out('  ' . ($cfg ? 'Imported company profile: ' . ($cfg['name'] ?? '') : 'No app_config.json found (pass --legacy-path to import company details)'));

    // Payment options: cash + mpesa (+ legacy "pay via" text) + bank
    $put('payment_accounts', ['method' => 'cash', 'name' => 'Cash', 'show_on_documents' => 0, 'sort_order' => 0]);
    $payVia = trim(($cfg['pay_via'] ?? '') . ' ' . ($cfg['pay_acc'] ?? ''));
    preg_match('/(\d{5,7})/', (string)($cfg['pay_via'] ?? ''), $pb);
    $put('payment_accounts', ['method' => 'mpesa', 'name' => $payVia ?: 'M-Pesa', 'provider' => 'Safaricom', 'account_type' => stripos($payVia, 'till') !== false ? 'till' : (stripos($payVia, 'paybill') !== false ? 'paybill' : 'till'),
        'account_number' => $pb[1] ?? null, 'account_name' => trim(preg_replace('/^account\s*:?/i', '', (string)($cfg['pay_acc'] ?? ''))) ?: null, 'requires_reference' => 1, 'stk_enabled' => 1, 'sort_order' => 1]);
    $put('payment_accounts', ['method' => 'bank', 'name' => 'Bank transfer', 'account_type' => 'current', 'requires_reference' => 1, 'sort_order' => 2]);

    /* ---------------- Stores ---------------- */
    step('Stores');
    $stores = $has('inv_stores') ? $rows('SELECT * FROM inv_stores') : [];
    if (!$stores) $stores = [['id' => 1, 'name' => 'Main Store', 'remarks' => null, 'status' => 1, 'createdon' => null]];
    foreach ($stores as $s) $put('stores', ['id' => $s['id'], 'name' => $s['name'] ?: 'Store ' . $s['id'], 'address' => $s['remarks'] ?: null, 'is_active' => (int)($s['status'] ?? 1) === 1 ? 1 : 0, 'created_at' => $dt($s['createdon'] ?? null) ?? date('Y-m-d H:i:s')]);
    $firstStore = (int)$stores[0]['id'];
    out('  ' . count($stores) . ' stores');

    /* ---------------- Roles from levels + rules ---------------- */
    step('Roles & permissions');
    $permMap = [
        'sell' => ['sell.create', 'sell.credit', 'shifts.manage', 'products.view', 'stock.view', 'customers.view'],
        'retrieve sale' => [], 'credit note' => ['sales.return'], 'retrieve credit note' => ['sales.return'],
        'customers' => ['customers.view', 'customers.manage'], 'customer remmitance' => ['debts.collect', 'customers.view'], 'customer remittance' => ['debts.collect', 'customers.view'],
        'quotation' => ['quotes.manage'], 'retrieve quotation' => ['quotes.manage'],
        'transfers' => ['stock.transfer', 'stock.view'], 'delivery note' => ['stock.transfer'],
        'departments' => ['products.manage', 'products.view'], 'products' => ['products.view', 'products.manage'], 'change inventory' => ['products.manage', 'stock.adjust'],
        'correct product prices' => ['products.manage', 'products.cost'], 'stores' => ['stock.view'], 'stock take' => ['stock.take', 'stock.view'], 'stock take report' => ['stock.take', 'reports.view'],
        'break down product' => ['stock.adjust'], 'users' => ['users.manage', 'team.view'], 'user stores' => ['users.manage'], 'levels' => ['roles.manage'], 'roles' => ['roles.manage'], 'rules' => ['roles.manage'],
        'out of stock' => ['stock.view', 'reports.view'], 'stock' => ['stock.view', 'reports.view'], 'stock movement' => ['stock.view', 'reports.view'],
        'sales report' => ['reports.view', 'sales.view_all'], 'customer statement' => ['reports.view', 'customers.view'], 'credit note report' => ['reports.view'], 'payments report' => ['reports.view', 'reports.financial'],
        'purchase report' => ['reports.view'], 'app configuration' => ['settings.manage', 'audit.view'],
        'purchase' => ['purchases.manage'], 'retrieve purchase' => ['purchases.manage'], 'supplier credit note' => ['purchases.manage'], 'retrieve supplier credit note' => ['purchases.manage'],
        'suppliers' => ['suppliers.manage'], 'supplier payment' => ['suppliers.manage'],
    ];
    $legacyPerms = $has('auth_roles') ? array_column($rows('SELECT id, role FROM auth_roles'), 'role', 'id') : [];
    $levels = $has('auth_levels') ? $rows('SELECT * FROM auth_levels') : [];
    $roleByName = [];
    foreach ($levels as $lv) {
        $name = trim((string)$lv['level']) ?: 'Level ' . $lv['id'];
        $isAdmin = (bool)preg_match('/admin|owner|director|boss/i', $name);
        $perms = [];
        if (!$isAdmin && $has('auth_rules')) {
            foreach ($rows('SELECT auth_role_id FROM auth_rules WHERE auth_level_id=' . (int)$lv['id']) as $r) {
                foreach ($permMap[strtolower(trim((string)($legacyPerms[$r['auth_role_id']] ?? '')))] ?? [] as $p) $perms[$p] = true;
            }
        }
        $perms = $isAdmin ? ['*'] : array_keys($perms);
        $sellerOnly = !$isAdmin && in_array('sell.create', $perms, true) && !in_array('reports.view', $perms, true);
        $dash = $isAdmin || in_array('reports.view', $perms, true) ? 'admin' : (in_array('purchases.manage', $perms, true) || in_array('stock.take', $perms, true) ? 'storekeeper' : 'cashier');
        $put('roles', ['id' => $lv['id'], 'name' => $name, 'description' => 'Migrated from legacy level', 'dashboard' => $dash, 'home_page' => $sellerOnly ? 'sell' : 'dashboard', 'is_system' => $isAdmin ? 1 : 0]);
        foreach ($perms as $p) $put('role_permissions', ['role_id' => $lv['id'], 'permission' => $p]);
        $roleByName[strtolower($name)] = (int)$lv['id'];
        out(sprintf('  %-18s → %s, %s', $name, $isAdmin ? 'ALL permissions' : count($perms) . ' permissions', $dash . ' dashboard'));
    }
    if (!$dry) {
        $ids = Perm::seedRoles(); // adds Owner/Manager/Cashier/... templates next to migrated roles
        foreach ($ids as $n => $id) $roleByName[strtolower($n)] = $roleByName[strtolower($n)] ?? $id;
    }
    $fallbackRole = $roleByName['cashier'] ?? ($roleByName ? reset($roleByName) : 1);

    /* ---------------- Users ---------------- */
    step('Users');
    $seenUser = [];
    foreach ($rows('SELECT * FROM auth_users') as $u) {
        $uname = strtolower(trim((string)$u['username'])) ?: 'user' . $u['id'];
        if (isset($seenUser[$uname])) $uname .= $u['id'];
        $seenUser[$uname] = true;
        $lvl = strtolower(trim((string)($u['level'] ?? '')));
        $role = $roleByName[$lvl] ?? (is_numeric($lvl) && in_array((int)$lvl, $roleByName, true) ? (int)$lvl : $fallbackRole);
        $put('users', [
            'id' => $u['id'], 'role_id' => $role, 'first_name' => $u['firstname'] ?: $uname, 'last_name' => $u['othernames'] ?: null, 'username' => $uname,
            'email' => filter_var($u['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $u['email'] : null, 'phone' => $u['tel'] ?: null, 'id_no' => ($u['idno'] ?? 0) ? (string)$u['idno'] : null,
            'password_hash' => $u['password'], 'default_store_id' => $firstStore, 'status' => (int)$u['status'] === 1 ? 'active' : 'suspended',
            'last_login_at' => $dt($u['lastlogin'] ?? null), 'created_at' => $dt($u['createdon'] ?? null) ?? date('Y-m-d H:i:s'),
        ]);
    }
    if ($has('auth_user_stores')) foreach ($rows('SELECT DISTINCT userid, storeid FROM auth_user_stores') as $us) {
        $stats['user_stores'] = ($stats['user_stores'] ?? 0) + 1;
        if (!$dry) DB::q('INSERT IGNORE INTO user_stores (user_id, store_id) VALUES (?,?)', [$us['userid'], $us['storeid']]);
    }
    out('  ' . ($stats['users'] ?? 0) . ' users (passwords preserved). Legacy PIN logins used the password — users can set a new quick PIN under My account.');

    /* ---------------- Catalogue ---------------- */
    step('Categories & products');
    if ($has('inv_departments')) foreach ($rows('SELECT * FROM inv_departments') as $d) {
        $put('categories', ['id' => $d['id'], 'name' => $d['department'] ?: 'Department ' . $d['id'], 'code' => $d['code'] ?: null, 'description' => $d['description'] ?: null]);
    }
    $skus = []; $productName = []; $productCost = [];
    foreach ($rows('SELECT * FROM inv_products') as $p) {
        $code = trim((string)$p['code']);
        $sku = ($code === '' || $code === '0' || isset($skus[strtolower($code)])) ? null : $code;
        if ($sku) $skus[strtolower($sku)] = true;
        $tax = trim((string)$p['applicabletax']);
        $rate = is_numeric($tax) ? (float)$tax : (preg_match('/^(v|vat|a|s|std)/i', $tax) ? 16.0 : 0.0);
        if ($rate > 0 && $rate < 1) $rate *= 100;
        $productName[$p['id']] = $p['name']; $productCost[$p['id']] = (float)$p['cost_price'];
        $put('products', [
            'id' => $p['id'], 'sku' => $sku, 'barcode' => ($code !== '' && $code !== '0' && ctype_digit($code) && strlen($code) >= 8) ? $code : null,
            'name' => $p['name'], 'category_id' => $p['inv_department_id'] ?: null, 'brand' => $p['manufacturer'] ?: null, 'unit' => $p['package'] ?: 'pc',
            'cost_price' => (float)$p['cost_price'], 'retail_price' => (float)$p['retail_price'], 'wholesale_price' => (float)$p['wholesale_price'],
            'tax_rate' => $rate, 'reorder_level' => (float)$p['reorderlevel'], 'max_level' => $p['maxreorderlevel'] !== null ? (float)$p['maxreorderlevel'] : null,
            'is_active' => (int)$p['status'] === 1 ? 1 : 0, 'expiry_date' => $dt($p['expirydate']) ? substr($p['expirydate'], 0, 10) : null,
            'created_by' => $p['createdby'] ?: null, 'created_at' => $dt($p['createdon']) ?? date('Y-m-d H:i:s'),
        ]);
    }
    out('  ' . ($stats['categories'] ?? 0) . ' categories, ' . ($stats['products'] ?? 0) . ' products');

    step('Stock levels & movement history');
    if ($has('inv_store_products')) foreach ($rows('SELECT inv_store_id s, inv_product_id p, SUM(quantity) q FROM inv_store_products WHERE inv_store_id IS NOT NULL AND inv_product_id IS NOT NULL GROUP BY inv_store_id, inv_product_id') as $s) {
        if (!isset($productName[$s['p']])) continue;
        $put('stock', ['store_id' => $s['s'], 'product_id' => $s['p'], 'qty' => (float)$s['q']]);
    }
    if ($has('stock_track')) foreach ($rows('SELECT * FROM stock_track WHERE product_id IS NOT NULL ORDER BY id') as $m) {
        $t = strtolower((string)$m['transaction_type']); $q = (float)$m['quantity'];
        $type = str_contains($t, 'sale') ? 'sale' : (str_contains($t, 'supplier') ? 'supplier_return' : (str_contains($t, 'purchase') ? 'purchase'
            : (str_contains($t, 'transfer') ? ($q >= 0 ? 'transfer_in' : 'transfer_out') : (str_contains($t, 'take') ? 'stock_take'
            : (str_contains($t, 'break') ? ($q >= 0 ? 'breakdown_in' : 'breakdown_out') : (str_contains($t, 'credit') || str_contains($t, 'return') ? 'return' : 'adjustment'))))));
        $put('stock_movements', ['store_id' => $m['store_id'] ?: $firstStore, 'product_id' => $m['product_id'], 'type' => $type, 'qty' => $q, 'balance' => (float)$m['stock_left'],
            'note' => 'Legacy: ' . $m['transaction_type'], 'user_id' => $m['createdby'] ?: null, 'created_at' => $dt($m['transaction_date']) ?? $dt($m['createdon']) ?? date('Y-m-d H:i:s')]);
    }
    out('  ' . ($stats['stock'] ?? 0) . ' stock rows, ' . ($stats['stock_movements'] ?? 0) . ' movements');

    /* ---------------- Customers ---------------- */
    step('Customers');
    $custDays = [];
    if ($has('sls_customers')) foreach ($rows('SELECT * FROM sls_customers') as $c) {
        $custDays[$c['id']] = (int)($c['creditdays'] ?: 30);
        $put('customers', [
            'id' => $c['id'], 'name' => $c['name'] ?: 'Customer ' . $c['id'], 'phone' => $c['tel'] ?: ($c['contactphone'] ?: null),
            'email' => filter_var($c['email'] ?? '', FILTER_VALIDATE_EMAIL) ? strtolower($c['email']) : null, 'address' => $c['address'] ?: null,
            'contact_person' => $c['contactname'] ?: null, 'credit_limit' => (float)($c['creditlimit'] ?? 0), 'credit_days' => $custDays[$c['id']],
            'discount' => (float)$c['discount'], 'is_active' => (int)$c['status'] === 1 ? 1 : 0,
            'last_reminder_at' => $dt($c['lastreminder'] ?? null), 'created_by' => $c['createdby'] ?: null, 'created_at' => $dt($c['createdon']) ?? date('Y-m-d H:i:s'),
        ]);
    }
    out('  ' . ($stats['customers'] ?? 0) . ' customers');

    /* ---------------- Sales, items, payments, returns ---------------- */
    step('Sales history');
    $details = [];
    foreach ($rows('SELECT * FROM sls_sale_details ORDER BY id') as $d) $details[$d['sls_sale_id']][] = $d;
    $pays = [];
    if ($has('sls_payments')) foreach ($rows('SELECT * FROM sls_payments ORDER BY id') as $p) $pays[$p['sale_id']][] = $p;
    $cns = []; $cnDet = []; $returnedByDetail = [];
    if ($has('sls_credit_notes')) {
        foreach ($rows('SELECT * FROM sls_credit_notes ORDER BY id') as $c) $cns[$c['sls_sale_id']][] = $c;
        foreach ($rows('SELECT * FROM sls_credit_note_details') as $d) { $cnDet[$d['sls_credit_note_id']][] = $d; $returnedByDetail[$d['sls_sale_detail_id']] = ($returnedByDetail[$d['sls_sale_detail_id']] ?? 0) + (float)$d['quantity']; }
    }
    $detailById = [];
    foreach ($details as $list) foreach ($list as $d) $detailById[$d['id']] = $d;

    $acc = ['cash' => 1, 'mpesa' => 2, 'bank' => 3];
    $legacyTotal = 0.0; $newBalance = 0.0; $maxInvoice = 0; $maxCn = 0;
    foreach ($rows('SELECT * FROM sls_sales ORDER BY id') as $s) {
        $legacyTotal += (float)$s['sale_total'];
        $maxInvoice = max($maxInvoice, (int)$s['invoice_no']);
        $items = $details[$s['id']] ?? [];
        $sub = 0.0; $lineSum = 0.0; $cost = 0.0; $tax = 0.0; $returnedQty = 0.0; $soldQty = 0.0;
        foreach ($items as $d) { $sub += (float)$d['price'] * (float)$d['quantity']; $lineSum += (float)$d['total']; $cost += (float)$d['cost_price'] * (float)$d['quantity']; $tax += (float)$d['tax']; $soldQty += (float)$d['quantity']; $returnedQty += $returnedByDetail[$d['id']] ?? 0; }
        $total = round((float)$s['sale_total'], 2);
        $time = $dt($s['created_on']) ? substr($s['created_on'], 11, 8) : '12:00:00';
        $soldAt = substr((string)$s['date_of_sale'], 0, 10) . ' ' . $time;

        // Payments
        $paid = 0.0; $payRows = [];
        foreach ($pays[$s['id']] ?? [] as $p) {
            $m = $method($p['paid_through']);
            $isDebt = $s['sold_through'] === 'Credit' || substr((string)$p['paid_on'], 0, 10) > substr((string)$s['date_of_sale'], 0, 10);
            $payRows[] = ['receipt_no' => $isDebt ? 'RCP-L' . $p['id'] : null, 'kind' => $isDebt ? 'debt' : 'sale', 'sale_id' => $s['id'], 'customer_id' => $s['sls_customer_id'] ?: null,
                'payment_account_id' => $acc[$m] ?? null, 'method' => $m, 'amount' => round((float)$p['amount'], 2), 'reference' => $p['ref_no'] ?: null, 'store_id' => $s['inv_store_id'],
                'received_by' => $p['created_by'] ?: null, 'paid_at' => ($dt($p['created_on']) && substr($p['created_on'], 0, 10) === substr((string)$p['paid_on'], 0, 10)) ? $p['created_on'] : substr((string)$p['paid_on'], 0, 10) . ' ' . $time];
            $paid += (float)$p['amount'];
        }
        if (!$payRows && in_array($s['sold_through'], ['Cash', 'Mpesa'], true)) {
            $m = $method($s['sold_through']);
            $payRows[] = ['kind' => 'sale', 'sale_id' => $s['id'], 'customer_id' => $s['sls_customer_id'] ?: null, 'payment_account_id' => $acc[$m], 'method' => $m, 'amount' => $total,
                'store_id' => $s['inv_store_id'], 'received_by' => $s['created_by'] ?: null, 'paid_at' => $soldAt, 'notes' => 'Migrated (implied full payment)'];
            $paid = $total;
        }
        // Credit notes
        $retTotal = 0.0; $cnRows = [];
        foreach ($cns[$s['id']] ?? [] as $c) {
            $maxCn = max($maxCn, (int)$c['documentno']);
            $lines = []; $cnTotal = 0.0;
            foreach ($cnDet[$c['id']] ?? [] as $cd) {
                $sd = $detailById[$cd['sls_sale_detail_id']] ?? null; if (!$sd) continue;
                $unit = (float)$sd['quantity'] > 0 ? (float)$sd['total'] / (float)$sd['quantity'] : (float)$sd['price'];
                $lt = round($unit * (float)$cd['quantity'], 2); $cnTotal += $lt;
                $lines[] = ['sale_item_id' => $sd['id'], 'product_id' => $sd['inv_product_id'], 'name' => $productName[$sd['inv_product_id']] ?? 'Item', 'qty' => (float)$cd['quantity'], 'unit_price' => round($unit, 2), 'total' => $lt];
            }
            $cnRows[] = [$c, $lines, round($cnTotal, 2)];
            $retTotal += $cnTotal;
        }
        $owing = max(0, round($total - $paid, 2));
        $balance = max(0, round($owing - $retTotal, 2));
        $newBalance += $balance;
        $status = $retTotal > 0 ? ($returnedQty >= $soldQty - 0.0001 ? 'returned' : 'partially_returned') : 'completed';
        $put('sales', [
            'id' => $s['id'], 'invoice_no' => $uniq((string)$s['invoice_no']), 'store_id' => $s['inv_store_id'] ?: $firstStore, 'customer_id' => $s['sls_customer_id'] ?: null,
            'user_id' => $s['created_by'] ?: null, 'sale_type' => strtolower((string)$s['sale_type']) === 'wholesale' ? 'wholesale' : 'retail',
            'subtotal' => round($sub, 2), 'discount_total' => max(0, round($sub - $lineSum, 2)), 'tax_total' => round($tax, 2), 'shipping' => round((float)$s['carriage_outwards'], 2),
            'total' => $total, 'cost_total' => round($cost, 2), 'paid' => round(min($paid, $total), 2), 'returned_total' => round($retTotal, 2), 'balance' => $balance,
            'payment_status' => $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'credit'), 'status' => $status,
            'due_date' => $balance > 0 ? date('Y-m-d', strtotime(substr($soldAt, 0, 10) . ' +' . ($custDays[$s['sls_customer_id']] ?? 30) . ' days')) : null,
            'notes' => $s['remarks'] ? mb_substr($s['remarks'], 0, 255) : null, 'sold_at' => $soldAt, 'created_at' => $dt($s['created_on']) ?? $soldAt,
        ]);
        foreach ($items as $d) {
            $put('sale_items', ['id' => $d['id'], 'sale_id' => $s['id'], 'product_id' => $d['inv_product_id'], 'name' => $productName[$d['inv_product_id']] ?? 'Item #' . $d['inv_product_id'],
                'qty' => (float)$d['quantity'], 'unit_cost' => (float)$d['cost_price'], 'unit_price' => (float)$d['price'], 'discount' => (float)$d['discount'], 'tax' => (float)$d['tax'],
                'total' => (float)$d['total'], 'returned_qty' => $returnedByDetail[$d['id']] ?? 0]);
        }
        foreach ($payRows as $pr) $put('payments', $pr);
        $leftToApply = $owing;
        foreach ($cnRows as [$c, $lines, $cnTotal]) {
            $applied = round(min($leftToApply, $cnTotal), 2); $leftToApply -= $applied; $refund = round($cnTotal - $applied, 2);
            $put('credit_notes', ['id' => $c['id'], 'cn_no' => $uniq('CN-' . $c['documentno']), 'sale_id' => $s['id'], 'store_id' => $s['inv_store_id'] ?: $firstStore, 'customer_id' => $s['sls_customer_id'] ?: null,
                'total' => $cnTotal, 'applied_to_balance' => $applied, 'refund_amount' => $refund, 'refund_method' => $refund > 0 ? 'cash' : 'none', 'restock' => 1,
                'reason' => $c['reason'] ? mb_substr($c['reason'], 0, 255) : null, 'user_id' => $c['handledby'] ?: ($c['createdby'] ?: null), 'created_at' => $dt($c['createdon']) ?? substr((string)$c['return_date'], 0, 10) . ' 12:00:00']);
            foreach ($lines as $ln) $put('credit_note_items', $ln + ['credit_note_id' => $c['id']]);
        }
    }
    out(sprintf('  %d sales, %d items, %d payments, %d credit notes', $stats['sales'] ?? 0, $stats['sale_items'] ?? 0, $stats['payments'] ?? 0, $stats['credit_notes'] ?? 0));

    /* ---------------- Quotations ---------------- */
    $maxQuote = 0;
    if ($has('sls_quotations')) {
        step('Quotations');
        $qd = [];
        foreach ($rows('SELECT * FROM sls_quotation_details') as $d) $qd[$d['sls_quotation_id']][] = $d;
        foreach ($rows('SELECT * FROM sls_quotations') as $q) {
            $maxQuote = max($maxQuote, (int)$q['quote_no']);
            $sub = 0.0; foreach ($qd[$q['id']] ?? [] as $d) $sub += (float)$d['price'] * (float)$d['quantity'];
            $put('quotations', ['id' => $q['id'], 'quote_no' => $uniq('QT-' . $q['quote_no']), 'store_id' => $firstStore, 'customer_id' => $q['sls_customer_id'] ?: null, 'user_id' => $q['created_by'] ?: null,
                'sale_type' => strtolower((string)$q['sale_type']) === 'wholesale' ? 'wholesale' : 'retail', 'subtotal' => round($sub, 2), 'total' => (float)$q['quote_total'],
                'discount_total' => max(0, round($sub - (float)$q['quote_total'], 2)), 'status' => strtolower((string)$q['status']) ?: 'pending', 'sale_id' => $q['sls_sale_id'] ?: null,
                'valid_until' => date('Y-m-d', strtotime(substr((string)$q['quote_date'], 0, 10) . ' +14 days')), 'notes' => $q['remarks'] ? mb_substr($q['remarks'], 0, 255) : null,
                'created_at' => $dt($q['created_on']) ?? substr((string)$q['quote_date'], 0, 10) . ' 12:00:00']);
            foreach ($qd[$q['id']] ?? [] as $d) $put('quotation_items', ['quotation_id' => $q['id'], 'product_id' => $d['inv_product_id'], 'name' => $productName[$d['inv_product_id']] ?? 'Item',
                'qty' => (float)$d['quantity'], 'unit_price' => (float)$d['price'], 'discount' => (float)$d['discount'], 'tax' => (float)$d['tax'], 'total' => (float)$d['total']]);
        }
        out('  ' . ($stats['quotations'] ?? 0) . ' quotations');
    }

    /* ---------------- Suppliers & purchases ---------------- */
    $legacyPurch = 0.0;
    if ($has('scm_suppliers')) {
        step('Suppliers, purchases & supplier payments');
        foreach ($rows('SELECT * FROM scm_suppliers') as $s) {
            $put('suppliers', ['id' => $s['id'], 'code' => $s['code'] ?: null, 'name' => $s['name'], 'contact_person' => $s['contact'] ?: null, 'phone' => $s['cellphone'] ?: ($s['tel'] ?: null),
                'email' => filter_var($s['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $s['email'] : null, 'address' => $s['physical_address'] ?: null]);
        }
        $pdets = []; $spays = []; $sret = [];
        if ($has('scm_purchase_details')) foreach ($rows('SELECT * FROM scm_purchase_details') as $d) $pdets[$d['scm_purchase_id']][] = $d;
        if ($has('scm_supplier_payments')) foreach ($rows('SELECT * FROM scm_supplier_payments') as $p) $spays[$p['scm_purchase_id']][] = $p;
        if ($has('scm_supplier_credit_notes')) foreach ($rows('SELECT * FROM scm_supplier_credit_notes') as $c) $sret[$c['scm_purchase_id']][] = $c;
        $srDet = [];
        if ($has('scm_supplier_credit_note_details')) foreach ($rows('SELECT * FROM scm_supplier_credit_note_details') as $d) $srDet[$d['scm_supplier_credit_note_id']][] = $d;
        foreach ($has('scm_purchases') ? $rows('SELECT * FROM scm_purchases ORDER BY id') : [] as $p) {
            $sub = 0.0; $tax = 0.0;
            foreach ($pdets[$p['id']] ?? [] as $d) { $sub += (float)$d['quantity'] * (float)$d['costprice'] - (float)$d['discount'] + (float)$d['tax']; $tax += (float)$d['tax']; }
            $total = round($sub + (float)$p['carriage_inwards'], 2); $legacyPurch += $total;
            $paid = 0.0; foreach ($spays[$p['id']] ?? [] as $sp) $paid += (float)$sp['amount'];
            $impliedPay = !($spays[$p['id']] ?? []) && !preg_match('/credit/i', (string)$p['bought_through']);
            if ($impliedPay) $paid = $total;
            $ret = 0.0; foreach ($sret[$p['id']] ?? [] as $c) $ret += (float)$c['total_amount'];
            $put('purchases', ['id' => $p['id'], 'grn_no' => 'GRN-' . str_pad((string)$p['id'], 4, '0', STR_PAD_LEFT), 'supplier_id' => $p['scm_suppliers_id'], 'store_id' => $p['inv_store_id'] ?: $firstStore,
                'supplier_invoice' => $p['receipt_no'] ?: null, 'purchase_date' => substr((string)$p['bought_on'], 0, 10), 'subtotal' => round($sub, 2), 'tax_total' => round($tax, 2),
                'shipping' => (float)$p['carriage_inwards'], 'total' => $total, 'paid' => round(min($paid, $total), 2), 'returned_total' => round($ret, 2),
                'balance' => max(0, round($total - $paid - $ret, 2)), 'notes' => $p['remarks'] ? mb_substr($p['remarks'], 0, 255) : null, 'user_id' => $p['createdby'] ?: null, 'created_at' => $dt($p['createdon']) ?? date('Y-m-d H:i:s')]);
            foreach ($pdets[$p['id']] ?? [] as $d) {
                $put('purchase_items', ['id' => $d['id'], 'purchase_id' => $p['id'], 'product_id' => $d['inv_products_id'], 'name' => $productName[$d['inv_products_id']] ?? 'Item', 'qty' => (float)$d['quantity'],
                    'bonus_qty' => (float)$d['bonus'], 'unit_cost' => (float)$d['costprice'], 'discount' => (float)$d['discount'], 'tax' => (float)$d['tax'],
                    'total' => round((float)$d['quantity'] * (float)$d['costprice'] - (float)$d['discount'] + (float)$d['tax'], 2), 'expiry_date' => $dt($d['expiry_date']) ? substr($d['expiry_date'], 0, 10) : null]);
            }
            foreach ($spays[$p['id']] ?? [] as $sp) $put('supplier_payments', ['id' => $sp['id'], 'voucher_no' => 'PV-L' . $sp['id'], 'supplier_id' => $sp['scm_supplier_id'], 'purchase_id' => $p['id'],
                'method' => $method($sp['payment_method']), 'amount' => (float)$sp['amount'], 'reference' => $sp['reference_no'] ?: null, 'user_id' => $sp['createdby'] ?: null, 'paid_at' => $sp['createdon']]);
            if ($impliedPay && $total > 0) $put('supplier_payments', ['voucher_no' => 'PV-L0-' . $p['id'], 'supplier_id' => $p['scm_suppliers_id'], 'purchase_id' => $p['id'], 'method' => $method($p['bought_through']),
                'amount' => $total, 'reference' => 'Migrated (implied)', 'user_id' => $p['createdby'] ?: null, 'paid_at' => substr((string)$p['bought_on'], 0, 10) . ' 12:00:00']);
            foreach ($sret[$p['id']] ?? [] as $c) {
                $put('supplier_returns', ['id' => $c['id'], 'return_no' => $uniq($c['cn_number'] ?: 'SR-L' . $c['id']), 'purchase_id' => $p['id'], 'supplier_id' => $c['scm_supplier_id'], 'store_id' => $c['inv_store_id'] ?: $firstStore,
                    'total' => (float)$c['total_amount'], 'reason' => $c['remarks'] ? mb_substr($c['remarks'], 0, 255) : null, 'user_id' => $c['createdby'] ?: null, 'created_at' => $c['createdon']]);
                foreach ($srDet[$c['id']] ?? [] as $d) $put('supplier_return_items', ['supplier_return_id' => $c['id'], 'product_id' => $d['inv_product_id'], 'name' => $productName[$d['inv_product_id']] ?? 'Item',
                    'qty' => (float)$d['quantity'], 'unit_cost' => (float)$d['unit_cost'], 'total' => (float)$d['total_cost']]);
            }
        }
        out(sprintf('  %d suppliers, %d purchases, %d supplier payments, %d returns', $stats['suppliers'] ?? 0, $stats['purchases'] ?? 0, $stats['supplier_payments'] ?? 0, $stats['supplier_returns'] ?? 0));
    }

    /* ---------------- Transfers & stock takes ---------------- */
    if ($has('inv_store_transfers')) {
        step('Transfers & stock takes');
        $td = [];
        foreach ($has('inv_store_transfer_details') ? $rows('SELECT * FROM inv_store_transfer_details') : [] as $d) $td[$d['inv_store_transfer_id']][] = $d;
        foreach ($rows('SELECT * FROM inv_store_transfers') as $t) {
            $put('transfers', ['id' => $t['id'], 'transfer_no' => 'TR-' . $t['documentno'] . '-' . $t['id'], 'from_store_id' => $t['from_store_id'], 'to_store_id' => $t['to_store_id'], 'status' => 'received',
                'created_by' => $t['createdby'], 'received_by' => $t['createdby'], 'created_at' => $dt($t['createdon']) ?? $t['transfer_date'] . ' 12:00:00', 'received_at' => $dt($t['createdon'])]);
            foreach ($td[$t['id']] ?? [] as $d) $put('transfer_items', ['transfer_id' => $t['id'], 'product_id' => $d['inv_product_id'], 'name' => $productName[$d['inv_product_id']] ?? 'Item', 'qty' => (float)$d['quantity'], 'unit_cost' => (float)$d['costprice']]);
        }
    }
    if ($has('inv_stock_take')) {
        $groups = [];
        foreach ($rows('SELECT * FROM inv_stock_take ORDER BY id') as $r) $groups[$r['store_id'] . '|' . $r['stock_date']][] = $r;
        $n = 0;
        foreach ($groups as $key => $list) {
            $n++; [$sid, $date] = explode('|', $key);
            $value = 0.0; foreach ($list as $r) $value += (float)$r['variance'] * ($productCost[$r['product_id']] ?? 0);
            if (!$dry) $tid = DB::insert('stock_takes', ['take_no' => 'ST-L' . str_pad((string)$n, 4, '0', STR_PAD_LEFT), 'store_id' => $sid, 'variance_value' => round($value, 2), 'user_id' => $list[0]['createdby'] ?: null, 'created_at' => $list[0]['createdon'] ?? $date . ' 12:00:00']);
            $stats['stock_takes'] = ($stats['stock_takes'] ?? 0) + 1;
            foreach ($list as $r) $put('stock_take_items', ['stock_take_id' => $tid ?? 0, 'product_id' => $r['product_id'], 'name' => $productName[$r['product_id']] ?? 'Item', 'system_qty' => (float)$r['previous_stock'],
                'counted_qty' => (float)$r['quantity'], 'variance' => (float)$r['variance'], 'unit_cost' => $productCost[$r['product_id']] ?? 0]);
        }
        out('  ' . ($stats['transfers'] ?? 0) . ' transfers, ' . ($stats['stock_takes'] ?? 0) . ' stock takes');
    }

    /* ---------------- Number sequences continue where v1 stopped ---------------- */
    step('Document numbering');
    if (!$dry) {
        Sequence::seed();
        DB::q('UPDATE sequences SET prefix="", next_no=?, pad=0 WHERE `key`="invoice"', [max(1001, $maxInvoice + 1)]);
        DB::q('UPDATE sequences SET next_no=? WHERE `key`="credit"', [$maxCn + 1]);
        DB::q('UPDATE sequences SET next_no=? WHERE `key`="quote"', [$maxQuote + 1]);
        DB::q('UPDATE sequences SET next_no=? WHERE `key`="grn"', [(int)DB::val('SELECT COALESCE(MAX(id),0) FROM purchases') + 1]);
        DB::q('UPDATE sequences SET next_no=? WHERE `key`="transfer"', [(int)DB::val('SELECT COALESCE(MAX(id),0) FROM transfers') + 1]);
        Settings::set('migrated_from', 'legacy:' . $L['db'] . ' @ ' . date('Y-m-d H:i'));
    }
    out('  Next invoice number: ' . max(1001, $maxInvoice + 1) . ' (continues the legacy sequence)');

    if (!empty($opt['copy-logo']) && !empty($opt['legacy-path']) && is_file($opt['legacy-path'] . '/logo.webp') && !$dry) {
        @mkdir(MF_ROOT . '/uploads/branding', 0775, true);
        copy($opt['legacy-path'] . '/logo.webp', MF_ROOT . '/uploads/branding/legacy-logo.webp');
        Settings::set('business_logo', 'uploads/branding/legacy-logo.webp');
        out('  Copied legacy logo');
    }

    if (!$dry) { $T->commit(); $T->exec('SET FOREIGN_KEY_CHECKS=1'); }
} catch (Throwable $e) {
    if (!$dry && $T->inTransaction()) $T->rollBack();
    fwrite(STDERR, "\n\033[1;31m✗ Migration failed and was rolled back:\033[0m " . $e->getMessage() . "\n  at " . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}

/* ---------------- Verification ---------------- */
step('Verification');
$cmp = [
    ['Users', 'SELECT COUNT(*) FROM auth_users', 'SELECT COUNT(*) FROM users WHERE id IN (SELECT id FROM users)'],
    ['Products', 'SELECT COUNT(*) FROM inv_products', 'SELECT COUNT(*) FROM products'],
    ['Customers', $has('sls_customers') ? 'SELECT COUNT(*) FROM sls_customers' : null, 'SELECT COUNT(*) FROM customers'],
    ['Sales', 'SELECT COUNT(*) FROM sls_sales', 'SELECT COUNT(*) FROM sales'],
    ['Sale lines', 'SELECT COUNT(*) FROM sls_sale_details', 'SELECT COUNT(*) FROM sale_items'],
    ['Sales value', 'SELECT ROUND(SUM(sale_total),2) FROM sls_sales', 'SELECT ROUND(SUM(total),2) FROM sales'],
    ['Stock units', $has('inv_store_products') ? 'SELECT ROUND(SUM(quantity),3) FROM inv_store_products' : null, 'SELECT ROUND(SUM(qty),3) FROM stock'],
    ['Purchases value', null, 'SELECT ROUND(SUM(total),2) FROM purchases'],
];
printf("  %-16s %16s %16s\n", 'Check', 'Legacy', 'Mfuko');
$ok = true;
foreach ($cmp as [$label, $lq, $nq]) {
    $lv = $lq ? $legacy->query($lq)->fetchColumn() : ($label === 'Purchases value' ? round($legacyPurch, 2) : '—');
    $nv = $dry ? '(dry run)' : DB::val($nq);
    $match = $dry || $lv === '—' || abs((float)$lv - (float)$nv) < 0.05;
    $ok = $ok && $match;
    printf("  %-16s %16s %16s  %s\n", $label, (string)$lv, (string)$nv, $match ? "\033[32m✓\033[0m" : "\033[31m≠\033[0m");
}
if (!$dry) printf("  %-16s %16s %16s\n", 'Receivables', '', money(DB::val('SELECT SUM(balance) FROM sales')));
out('');
out($ok ? "\033[1;32m✓ Migration complete.\033[0m Users can sign in with their existing usernames and passwords." : "\033[1;33m! Completed with differences above — review before going live.\033[0m");
out('Next: set M-Pesa/email in Settings, add payment options, upload the business logo, and set up tools/cron.php.');
