<?php
/** Generic CRUD for app/resources.php definitions. */

function res_def(array $in): array
{
    static $all = null;
    $all ??= require MF_ROOT . '/app/resources.php';
    $key = (string)($in['r'] ?? '');
    if (!isset($all[$key])) throw new ApiError('Unknown resource', 404);
    return [$key, $all[$key]];
}

function res_can(array $def, bool $write): bool
{
    $p = $write ? $def['perm'] : ($def['view_perm'] ?? $def['perm']);
    return Auth::can($p) || Auth::can($def['perm']);
}

function res_clean(array $def, array $in): array
{
    $out = [];
    foreach ($def['fields'] as $name => $f) {
        if (!array_key_exists($name, $in)) {
            if ($f['type'] === 'switch') $out[$name] = 0;
            continue;
        }
        $v = $in[$name];
        if (is_string($v)) $v = trim($v);
        switch ($f['type']) {
            case 'switch': $v = $v ? 1 : 0; break;
            case 'number': case 'money':
                if ($v === '' || $v === null) { $v = !empty($f['nullable']) ? null : 0; break; }
                if (!is_numeric($v)) throw new ApiError("{$f['label']} must be a number");
                $v = $f['type'] === 'money' ? round((float)$v, 2) : $v + 0;
                break;
            case 'ref':
                $v = ($v === '' || $v === null || (int)$v === 0) ? null : (int)$v; break;
            case 'date':
                if ($v === '' || $v === null) { $v = null; break; }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) throw new ApiError("{$f['label']} is not a valid date");
                break;
            case 'email':
                if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) throw new ApiError("{$f['label']} is not a valid email");
                $v = $v === '' ? null : mb_strtolower((string)$v);
                break;
            case 'select':
                if (!array_key_exists((string)$v, $f['options'])) throw new ApiError("Choose a valid {$f['label']}");
                $v = $v === '' ? null : $v;
                break;
            default:
                $v = $v === '' ? null : mb_substr((string)$v, 0, 2000);
                if ($v !== null && !empty($f['upper'])) $v = strtoupper(preg_replace('/\s+/', '', $v));
        }
        if (!empty($f['required']) && ($v === null || $v === '')) throw new ApiError("{$f['label']} is required");
        $out[$name] = $v;
    }
    return $out;
}

return [
    'list' => [
        'perm' => null,
        'fn' => function (array $in) {
            [$key, $def] = res_def($in);
            if (!res_can($def, false)) throw new ApiError('No access', 403);
            $t = $def['table'];
            $select = ['t.*'];
            foreach ($def['computed'] ?? [] as $c => $cd) $select[] = "{$cd['sql']} AS `$c`";
            $where = ['1=1']; $p = [];
            if (($q = trim((string)($in['q'] ?? ''))) !== '') {
                $or = [];
                foreach ($def['search'] as $col) { $or[] = "t.`$col` LIKE ?"; $p[] = "%$q%"; }
                $where[] = '(' . implode(' OR ', $or) . ')';
            }
            if (isset($def['fields']['is_active']) && ($in['active'] ?? '1') !== 'all') { $where[] = 't.is_active=?'; $p[] = (int)($in['active'] ?? 1); }
            if (!empty($in['from']) && $key === 'expenses') { $where[] = 't.spent_on>=?'; $p[] = $in['from']; }
            if (!empty($in['to']) && $key === 'expenses') { $where[] = 't.spent_on<=?'; $p[] = $in['to']; }
            $w = implode(' AND ', $where);
            $per = min(200, max(5, (int)($in['per'] ?? 25)));
            $off = (max(1, (int)($in['page'] ?? 1)) - 1) * $per;
            $rows = DB::all("SELECT " . implode(',', $select) . " FROM `$t` t WHERE $w ORDER BY {$def['order']} LIMIT $per OFFSET $off", $p);
            $out = ['rows' => $rows, 'total' => (int)DB::val("SELECT COUNT(*) FROM `$t` t WHERE $w", $p)];
            if (!empty($def['sum'])) $out['sum'] = (float)DB::val("SELECT COALESCE(SUM(t.`{$def['sum']}`),0) FROM `$t` t WHERE $w", $p);
            return $out;
        },
    ],
    'get' => [
        'perm' => null,
        'fn' => function (array $in) {
            [, $def] = res_def($in);
            if (!res_can($def, false)) throw new ApiError('No access', 403);
            $row = DB::row("SELECT * FROM `{$def['table']}` WHERE id=?", [(int)($in['id'] ?? 0)]);
            if (!$row) throw new ApiError('Not found', 404);
            return $row;
        },
    ],
    'save' => [
        'perm' => null, 'write' => true,
        'fn' => function (array $in) {
            [$key, $def] = res_def($in);
            if (!res_can($def, true)) throw new ApiError('No access', 403);
            $data = res_clean($def, (array)($in['data'] ?? []));
            $t = $def['table'];
            $id = (int)($in['id'] ?? 0);
            if ($key === 'coupons' && DB::val('SELECT id FROM coupons WHERE code=? AND id<>?', [$data['code'] ?? '', $id])) throw new ApiError('That coupon code already exists');
            if ($id) {
                $old = DB::row("SELECT * FROM `$t` WHERE id=?", [$id]);
                if (!$old) throw new ApiError('Not found', 404);
                DB::update($t, $data, 'id=?', [$id]);
                Audit::log('update', $key, $id, "Updated {$def['singular']} " . ($data['name'] ?? $data['code'] ?? $data['category'] ?? "#$id"), $old, $data);
            } else {
                if (!empty($def['stamp_user'])) $data[$def['stamp_user']] = Auth::id();
                if ($key === 'customers') $data['created_by'] = Auth::id();
                $id = DB::insert($t, $data);
                Audit::log('create', $key, $id, "Created {$def['singular']} " . ($data['name'] ?? $data['code'] ?? $data['category'] ?? "#$id"), null, $data);
            }
            return DB::row("SELECT * FROM `$t` WHERE id=?", [$id]);
        },
    ],
    'delete' => [
        'perm' => null, 'write' => true,
        'fn' => function (array $in) {
            [$key, $def] = res_def($in);
            if (!res_can($def, true)) throw new ApiError('No access', 403);
            $id = (int)($in['id'] ?? 0);
            $old = DB::row("SELECT * FROM `{$def['table']}` WHERE id=?", [$id]);
            if (!$old) throw new ApiError('Not found', 404);
            if (($def['delete'] ?? 'soft') === 'soft') {
                DB::q("UPDATE `{$def['table']}` SET is_active=0 WHERE id=?", [$id]);
                Audit::log('deactivate', $key, $id, "Deactivated {$def['singular']} " . ($old['name'] ?? "#$id"), $old, null);
            } else {
                DB::q("DELETE FROM `{$def['table']}` WHERE id=?", [$id]);
                Audit::log('delete', $key, $id, "Deleted {$def['singular']} " . ($old['name'] ?? $old['code'] ?? "#$id"), $old, null);
            }
            return ['id' => $id];
        },
    ],
    'options' => [
        'perm' => null,
        'fn' => function (array $in) {
            $map = [
                'stores' => 'SELECT id, name FROM stores WHERE is_active=1 ORDER BY name',
                'payment_accounts' => 'SELECT id, CONCAT(name, IFNULL(CONCAT(" · ", account_number), "")) AS name FROM payment_accounts WHERE is_active=1 ORDER BY sort_order',
                'customers' => 'SELECT id, name FROM customers WHERE is_active=1 ORDER BY name LIMIT 1000',
                'categories' => 'SELECT id, name FROM categories WHERE is_active=1 ORDER BY name',
                'suppliers' => 'SELECT id, name FROM suppliers WHERE is_active=1 ORDER BY name',
                'roles' => 'SELECT id, name FROM roles ORDER BY name',
            ];
            $t = (string)($in['t'] ?? '');
            if (!isset($map[$t])) throw new ApiError('Unknown list');
            return DB::all($map[$t]);
        },
    ],
];
