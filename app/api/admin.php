<?php
function pw_rules(string $pw): void
{
    if (strlen($pw) < 8) throw new ApiError('Password must be at least 8 characters');
}
function pin_unique(string $pin, int $exceptUser): void
{
    if (!preg_match('/^\d{4,8}$/', $pin)) throw new ApiError('PIN must be 4–8 digits');
    foreach (DB::all('SELECT id, pin_hash FROM users WHERE pin_hash IS NOT NULL AND id<>?', [$exceptUser]) as $u) {
        if (password_verify($pin, $u['pin_hash'])) throw new ApiError('That PIN is already used by someone else — choose another');
    }
}

return [
    /* ---------- Users ---------- */
    'users' => [
        'perm' => 'users.manage',
        'fn' => function (array $in) {
            $rows = DB::all("SELECT u.id, u.first_name, u.last_name, u.username, u.email, u.phone, u.avatar, u.status, u.role_id, u.default_store_id, u.daily_target, u.last_login_at, u.last_seen_at, u.pin_hash IS NOT NULL has_pin,
                r.name role, st.name store, (SELECT GROUP_CONCAT(store_id) FROM user_stores WHERE user_id=u.id) stores
                FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN stores st ON st.id=u.default_store_id ORDER BY u.status, u.first_name");
            return ['rows' => $rows, 'total' => count($rows), 'roles' => DB::all('SELECT id, name FROM roles ORDER BY name'), 'stores' => DB::all('SELECT id, name FROM stores WHERE is_active=1 ORDER BY name')];
        },
    ],
    'user_save' => [
        'perm' => 'users.manage', 'write' => true,
        'fn' => function (array $in) {
            $id = (int)($in['id'] ?? 0);
            $d = [
                'first_name' => trim((string)($in['first_name'] ?? '')), 'last_name' => trim((string)($in['last_name'] ?? '')) ?: null,
                'username' => strtolower(trim((string)($in['username'] ?? ''))), 'email' => trim((string)($in['email'] ?? '')) ?: null,
                'phone' => trim((string)($in['phone'] ?? '')) ?: null, 'role_id' => (int)($in['role_id'] ?? 0),
                'default_store_id' => !empty($in['default_store_id']) ? (int)$in['default_store_id'] : null,
                'daily_target' => round((float)($in['daily_target'] ?? 0), 2), 'status' => ($in['status'] ?? 'active') === 'suspended' ? 'suspended' : 'active',
            ];
            if ($d['first_name'] === '' || !preg_match('/^[a-z0-9._-]{3,40}$/', $d['username'])) throw new ApiError('Name and a username (3+ letters/numbers) are required');
            if (!DB::val('SELECT id FROM roles WHERE id=?', [$d['role_id']])) throw new ApiError('Choose a role');
            if (DB::val('SELECT id FROM users WHERE username=? AND id<>?', [$d['username'], $id])) throw new ApiError('Username already taken');
            if ($id === Auth::id() && $d['status'] === 'suspended') throw new ApiError('You cannot suspend yourself');
            // Never strip the last owner
            $ownerRoles = DB::col("SELECT role_id FROM role_permissions WHERE permission='*'");
            if ($id && in_array(DB::val('SELECT role_id FROM users WHERE id=?', [$id]), $ownerRoles) && !in_array($d['role_id'], $ownerRoles)
                && (int)DB::val('SELECT COUNT(*) FROM users WHERE status="active" AND role_id IN (' . DB::in($ownerRoles) . ')', $ownerRoles) <= 1) {
                throw new ApiError('This is the last owner account. Assign another owner first.');
            }
            $pw = (string)($in['password'] ?? '');
            if (!$id || $pw !== '') { pw_rules($pw); $d['password_hash'] = password_hash($pw, PASSWORD_DEFAULT); }
            if (($pin = (string)($in['pin'] ?? '')) !== '') { pin_unique($pin, $id); $d['pin_hash'] = password_hash($pin, PASSWORD_DEFAULT); }
            if ($id) {
                $old = DB::row('SELECT * FROM users WHERE id=?', [$id]);
                DB::update('users', $d, 'id=?', [$id]);
                Audit::log('user_update', 'user', $id, 'Updated user ' . $d['username'], $old, $d);
            } else {
                $d['created_by'] = Auth::id();
                $id = DB::insert('users', $d);
                Audit::log('user_create', 'user', $id, 'Created user ' . $d['username'] . ' (' . DB::val('SELECT name FROM roles WHERE id=?', [$d['role_id']]) . ')');
            }
            DB::q('DELETE FROM user_stores WHERE user_id=?', [$id]);
            foreach ((array)($in['stores'] ?? []) as $sid) if ((int)$sid) DB::q('INSERT IGNORE INTO user_stores (user_id, store_id) VALUES (?,?)', [$id, (int)$sid]);
            return ['id' => $id];
        },
    ],
    /* ---------- Roles ---------- */
    'roles' => [
        'perm' => 'roles.manage',
        'fn' => function () {
            $roles = DB::all('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id=r.id) users FROM roles r ORDER BY r.id');
            foreach ($roles as &$r) $r['perms'] = DB::col('SELECT permission FROM role_permissions WHERE role_id=?', [$r['id']]);
            return ['roles' => $roles, 'groups' => Perm::GROUPS];
        },
    ],
    'role_save' => [
        'perm' => 'roles.manage', 'write' => true,
        'fn' => function (array $in) {
            $id = (int)($in['id'] ?? 0);
            $name = trim((string)($in['name'] ?? ''));
            if ($name === '') throw new ApiError('Role name is required');
            $d = ['name' => $name, 'description' => trim((string)($in['description'] ?? '')) ?: null,
                'dashboard' => in_array($in['dashboard'] ?? '', ['admin', 'cashier', 'storekeeper', 'accountant'], true) ? $in['dashboard'] : 'cashier',
                'home_page' => in_array($in['home_page'] ?? '', ['dashboard', 'sell', 'sales', 'products', 'stock', 'customers', 'reports'], true) ? $in['home_page'] : 'dashboard'];
            if (DB::val('SELECT id FROM roles WHERE name=? AND id<>?', [$name, $id])) throw new ApiError('A role with that name exists');
            $isOwner = $id && DB::val("SELECT 1 FROM role_permissions WHERE role_id=? AND permission='*'", [$id]);
            $perms = array_values(array_intersect((array)($in['perms'] ?? []), Perm::all()));
            if ($id) {
                $old = DB::col('SELECT permission FROM role_permissions WHERE role_id=?', [$id]);
                DB::update('roles', $d, 'id=?', [$id]);
                if (!$isOwner) {
                    DB::q('DELETE FROM role_permissions WHERE role_id=?', [$id]);
                    foreach ($perms as $p) DB::insert('role_permissions', ['role_id' => $id, 'permission' => $p]);
                }
                Audit::log('role_update', 'role', $id, "Updated role $name", ['perms' => implode(',', $old)], ['perms' => $isOwner ? '*' : implode(',', $perms)]);
            } else {
                $id = DB::insert('roles', $d);
                foreach ($perms as $p) DB::insert('role_permissions', ['role_id' => $id, 'permission' => $p]);
                Audit::log('role_create', 'role', $id, "Created role $name with " . count($perms) . ' permissions');
            }
            return ['id' => $id];
        },
    ],
    'role_delete' => [
        'perm' => 'roles.manage', 'write' => true,
        'fn' => function (array $in) {
            $id = (int)($in['id'] ?? 0);
            if (DB::val('SELECT is_system FROM roles WHERE id=?', [$id])) throw new ApiError('System roles cannot be deleted');
            if (DB::val('SELECT COUNT(*) FROM users WHERE role_id=?', [$id])) throw new ApiError('Move users to another role first');
            $name = DB::val('SELECT name FROM roles WHERE id=?', [$id]);
            DB::q('DELETE FROM roles WHERE id=?', [$id]);
            Audit::log('role_delete', 'role', $id, "Deleted role $name");
            return ['ok' => true];
        },
    ],

    /* ---------- Presence & audit ---------- */
    'team' => [
        'perm' => 'team.view',
        'fn' => function () {
            return DB::all("SELECT u.id, u.first_name, u.last_name, u.username, u.avatar, u.last_seen_at, u.last_page, u.last_ip, u.last_login_at, r.name role,
                TIMESTAMPDIFF(SECOND, u.last_seen_at, NOW()) idle,
                (SELECT COUNT(*) FROM sales s WHERE s.user_id=u.id AND s.sold_at>=CURDATE() AND s.status<>'voided') sales_today,
                (SELECT COALESCE(SUM(total),0) FROM sales s WHERE s.user_id=u.id AND s.sold_at>=CURDATE() AND s.status<>'voided') revenue_today,
                (SELECT a.summary FROM audit_logs a WHERE a.user_id=u.id ORDER BY a.id DESC LIMIT 1) last_action
                FROM users u JOIN roles r ON r.id=u.role_id WHERE u.status='active' ORDER BY (u.last_seen_at IS NULL), u.last_seen_at DESC");
        },
    ],
    'audit' => [
        'perm' => 'audit.view',
        'fn' => function (array $in) {
            $where = ['1=1']; $p = [];
            if (!empty($in['user'])) { $where[] = 'a.user_id=?'; $p[] = (int)$in['user']; }
            if (!empty($in['action'])) { $where[] = 'a.action=?'; $p[] = $in['action']; }
            if (!empty($in['entity'])) { $where[] = 'a.entity=?'; $p[] = $in['entity']; }
            if (!empty($in['from'])) { $where[] = 'a.created_at>=?'; $p[] = $in['from'] . ' 00:00:00'; }
            if (!empty($in['to'])) { $where[] = 'a.created_at<=?'; $p[] = $in['to'] . ' 23:59:59'; }
            if (($q = trim((string)($in['q'] ?? ''))) !== '') { $where[] = '(a.summary LIKE ? OR a.entity_id=?)'; array_push($p, "%$q%", $q); }
            $w = implode(' AND ', $where);
            $per = min(200, (int)($in['per'] ?? 50)); $off = (max(1, (int)($in['page'] ?? 1)) - 1) * $per;
            return [
                'total' => (int)DB::val("SELECT COUNT(*) FROM audit_logs a WHERE $w", $p),
                'rows' => DB::all("SELECT a.*, CONCAT(u.first_name,' ',COALESCE(u.last_name,'')) user FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE $w ORDER BY a.id DESC LIMIT $per OFFSET $off", $p),
            ];
        },
    ],
    'audit_meta' => [
        'perm' => 'audit.view',
        'fn' => fn() => ['actions' => DB::col('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'entities' => DB::col('SELECT DISTINCT entity FROM audit_logs WHERE entity IS NOT NULL ORDER BY entity'),
            'users' => DB::all('SELECT id, CONCAT(first_name," ",COALESCE(last_name,"")) name FROM users ORDER BY first_name')],
    ],

    /* ---------- Settings ---------- */
    'settings_get' => [
        'perm' => 'settings.manage',
        'fn' => function () {
            $all = Settings::all();
            foreach (Settings::SECRET as $k) $all[$k] = $all[$k] !== '' ? '••••••••' : '';
            return $all + ['_timezones' => timezone_identifiers_list(), '_mpesa_callback' => Settings::get('mpesa_callback_base') ? Mpesa::callbackUrl('stk') : '', '_db_time' => DB::val('SELECT NOW()'), '_php_time' => date('Y-m-d H:i:s')];
        },
    ],
    'settings_save' => [
        'perm' => 'settings.manage', 'write' => true,
        'fn' => function (array $in) {
            $changed = [];
            foreach ((array)($in['settings'] ?? []) as $k => $v) {
                if (!array_key_exists($k, Settings::DEFAULTS) || $k === 'business_logo') continue;
                $v = is_bool($v) ? ($v ? '1' : '0') : trim((string)$v);
                if (in_array($k, Settings::SECRET, true) && ($v === '••••••••')) continue;
                if ($k === 'timezone' && !in_array($v, timezone_identifiers_list(), true)) throw new ApiError('Unknown timezone');
                if (Settings::get($k) !== $v) { Settings::set($k, $v); $changed[] = $k; }
            }
            if ($changed) Audit::log('settings_update', 'settings', null, 'Changed: ' . implode(', ', $changed));
            if (in_array('timezone', $changed, true)) { date_default_timezone_set(Settings::get('timezone')); DB::syncTimezone(); }
            return ['changed' => $changed, 'db_time' => DB::val('SELECT NOW()'), 'php_time' => date('Y-m-d H:i:s')];
        },
    ],
    'logo' => [
        'perm' => 'settings.manage', 'write' => true,
        'fn' => function (array $in) {
            $old = Settings::get('business_logo');
            if (!empty($in['remove'])) { Upload::delete($old); Settings::set('business_logo', ''); Audit::log('logo_remove', 'settings', null, 'Removed business logo'); return ['logo' => '']; }
            $path = Upload::image($_FILES['logo'] ?? null, 'branding', 600);
            Upload::delete($old);
            Settings::set('business_logo', $path);
            Audit::log('logo_upload', 'settings', null, 'Uploaded business logo');
            return ['logo' => $path];
        },
    ],
];
