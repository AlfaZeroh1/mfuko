<?php
const PRODUCT_FIELDS = ['sku', 'barcode', 'name', 'category_id', 'brand', 'unit', 'cost_price', 'retail_price', 'wholesale_price', 'min_price', 'tax_rate',
    'reorder_level', 'max_level', 'parent_id', 'units_per_parent', 'track_stock', 'is_favorite', 'is_active', 'expiry_date', 'description'];

return [
    'list' => [
        'perm' => 'products.view',
        'fn' => function (array $in) {
            $store = !empty($in['store']) ? (int)$in['store'] : Auth::currentStore();
            $where = ['1=1']; $p = [$store];
            $where[] = 'p.is_active=?'; $p[] = ($in['active'] ?? '1') === '0' ? 0 : 1;
            if (!empty($in['category'])) { $where[] = 'p.category_id=?'; $p[] = (int)$in['category']; }
            if (($q = trim((string)($in['q'] ?? ''))) !== '') { $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode=? OR p.brand LIKE ?)'; array_push($p, "%$q%", "%$q%", $q, "%$q%"); }
            if (($in['stock'] ?? '') === 'low') $where[] = 'p.track_stock=1 AND COALESCE(s.qty,0) <= p.reorder_level';
            if (($in['stock'] ?? '') === 'out') $where[] = 'p.track_stock=1 AND COALESCE(s.qty,0) <= 0';
            $w = implode(' AND ', $where);
            $per = min(200, max(5, (int)($in['per'] ?? 25))); $off = (max(1, (int)($in['page'] ?? 1)) - 1) * $per;
            $cost = Auth::can('products.cost') ? 'p.cost_price,' : '';
            $rows = DB::all("SELECT p.id, p.sku, p.barcode, p.name, p.brand, p.unit, p.image, $cost p.retail_price, p.wholesale_price, p.reorder_level, p.track_stock, p.is_favorite, p.expiry_date,
                    c.name category, COALESCE(s.qty,0) qty
                FROM products p LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN stock s ON s.product_id=p.id AND s.store_id=?
                WHERE $w ORDER BY p.name LIMIT $per OFFSET $off", $p);
            return ['rows' => $rows, 'total' => (int)DB::val("SELECT COUNT(*) FROM products p LEFT JOIN stock s ON s.product_id=p.id AND s.store_id=? WHERE $w", $p)];
        },
    ],
    'get' => [
        'perm' => 'products.view',
        'fn' => function (array $in) {
            $p = DB::row('SELECT * FROM products WHERE id=?', [(int)($in['id'] ?? 0)]);
            if (!$p) throw new ApiError('Product not found', 404);
            if (!Auth::can('products.cost')) unset($p['cost_price']);
            $p['stock'] = DB::all('SELECT st.name store, COALESCE(s.qty,0) qty FROM stores st LEFT JOIN stock s ON s.store_id=st.id AND s.product_id=? WHERE st.is_active=1', [$p['id']]);
            $p['sales30'] = (float)DB::val('SELECT COALESCE(SUM(si.qty),0) FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE si.product_id=? AND s.status<>"voided" AND s.sold_at>=DATE_SUB(NOW(), INTERVAL 30 DAY)', [$p['id']]);
            return $p;
        },
    ],
    'save' => [
        'perm' => 'products.manage', 'write' => true,
        'fn' => function (array $in) {
            $id = (int)($in['id'] ?? 0);
            $d = [];
            foreach (PRODUCT_FIELDS as $f) {
                if (!array_key_exists($f, $in)) continue;
                $v = is_string($in[$f]) ? trim($in[$f]) : $in[$f];
                $d[$f] = $v === '' ? null : $v;
            }
            foreach (['track_stock', 'is_favorite', 'is_active'] as $b) if (array_key_exists($b, $in)) $d[$b] = !empty($in[$b]) && $in[$b] !== '0' ? 1 : 0;
            foreach (['cost_price', 'retail_price', 'wholesale_price', 'min_price', 'tax_rate', 'reorder_level'] as $n) if (array_key_exists($n, $d)) $d[$n] = round((float)$d[$n], 3);
            if (empty($d['name']) && !$id) throw new ApiError('Product name is required');
            if (isset($d['unit']) && $d['unit'] === null) $d['unit'] = 'pc';
            if (!Auth::can('products.cost')) unset($d['cost_price']);
            if (!empty($d['sku']) && DB::val('SELECT id FROM products WHERE sku=? AND id<>?', [$d['sku'], $id])) throw new ApiError('Another product uses that SKU');
            if (!empty($d['parent_id']) && (int)$d['parent_id'] === $id) throw new ApiError('A product cannot break down from itself');

            if (!empty($_FILES['image']) || !empty($in['image_data'])) {
                $d['image'] = Upload::image($_FILES['image'] ?? $in['image_data'], 'products', 600);
            }
            if (!empty($in['remove_image'])) $d['image'] = null;

            if ($id) {
                $old = DB::row('SELECT * FROM products WHERE id=?', [$id]);
                if (!$old) throw new ApiError('Product not found');
                if (array_key_exists('image', $d) && $old['image'] && $old['image'] !== $d['image']) Upload::delete($old['image']);
                DB::update('products', $d, 'id=?', [$id]);
                Audit::log('product_update', 'product', $id, 'Updated ' . ($d['name'] ?? $old['name']), $old, $d);
            } else {
                $d['created_by'] = Auth::id();
                $id = DB::insert('products', $d);
                $opening = (float)($in['opening_qty'] ?? 0);
                if ($opening != 0) Stock::move(Auth::currentStore(), $id, $opening, 'opening', 'product', $id, 'Opening stock', (float)($d['cost_price'] ?? 0), true);
                Audit::log('product_create', 'product', $id, 'Created ' . $d['name'], null, $d);
            }
            return DB::row('SELECT * FROM products WHERE id=?', [$id]);
        },
    ],
    'delete' => [
        'perm' => 'products.manage', 'write' => true,
        'fn' => function (array $in) {
            $id = (int)($in['id'] ?? 0);
            DB::q('UPDATE products SET is_active=0 WHERE id=?', [$id]);
            Audit::log('product_deactivate', 'product', $id, 'Deactivated ' . DB::val('SELECT name FROM products WHERE id=?', [$id]));
            return ['ok' => true];
        },
    ],
    // Bulk import: CSV with header row name,sku,barcode,category,unit,cost,retail,wholesale,qty,reorder
    'import' => [
        'perm' => 'products.manage', 'write' => true,
        'fn' => function (array $in) {
            if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) throw new ApiError('Upload a CSV file');
            $fh = fopen($_FILES['file']['tmp_name'], 'r');
            $head = array_map(static fn($h) => strtolower(trim((string)$h)), fgetcsv($fh) ?: []);
            if (!in_array('name', $head, true)) throw new ApiError('CSV must have a "name" column');
            $store = Auth::currentStore();
            $created = $updated = 0;
            DB::tx(function () use ($fh, $head, $store, &$created, &$updated) {
                $cats = array_change_key_case(DB::pairs('SELECT name, id FROM categories'), CASE_LOWER);
                while (($row = fgetcsv($fh)) !== false) {
                    $r = array_combine($head, array_pad(array_slice($row, 0, count($head)), count($head), ''));
                    $name = trim((string)$r['name']); if ($name === '') continue;
                    $catId = null;
                    if (!empty($r['category'])) {
                        $cn = strtolower(trim($r['category']));
                        $catId = $cats[$cn] ?? ($cats[$cn] = DB::insert('categories', ['name' => trim($r['category'])]));
                    }
                    $d = array_filter([
                        'name' => $name, 'sku' => trim($r['sku'] ?? '') ?: null, 'barcode' => trim($r['barcode'] ?? '') ?: null, 'category_id' => $catId,
                        'unit' => trim($r['unit'] ?? '') ?: null, 'cost_price' => $r['cost'] ?? null, 'retail_price' => $r['retail'] ?? null,
                        'wholesale_price' => $r['wholesale'] ?? null, 'reorder_level' => $r['reorder'] ?? null,
                    ], static fn($v) => $v !== null && $v !== '');
                    $id = $d['sku'] ?? null ? DB::val('SELECT id FROM products WHERE sku=?', [$d['sku']]) : DB::val('SELECT id FROM products WHERE name=?', [$name]);
                    if ($id) { DB::update('products', $d, 'id=?', [$id]); $updated++; }
                    else { $d['created_by'] = Auth::id(); $id = DB::insert('products', $d); $created++; }
                    if (isset($r['qty']) && $r['qty'] !== '') {
                        $diff = (float)$r['qty'] - Stock::level($store, (int)$id);
                        if ($diff != 0) Stock::move($store, (int)$id, $diff, 'opening', 'import', null, 'CSV import', (float)($d['cost_price'] ?? 0), true);
                    }
                }
            });
            Audit::log('product_import', 'product', null, "CSV import: $created created, $updated updated");
            return ['created' => $created, 'updated' => $updated];
        },
    ],
    'movements' => [
        'perm' => 'stock.view',
        'fn' => function (array $in) {
            $pid = (int)($in['id'] ?? 0);
            $per = min(100, (int)($in['per'] ?? 25)); $off = (max(1, (int)($in['page'] ?? 1)) - 1) * $per;
            return [
                'total' => (int)DB::val('SELECT COUNT(*) FROM stock_movements WHERE product_id=?', [$pid]),
                'rows' => DB::all("SELECT m.*, st.name store, u.first_name user FROM stock_movements m JOIN stores st ON st.id=m.store_id LEFT JOIN users u ON u.id=m.user_id WHERE m.product_id=? ORDER BY m.id DESC LIMIT $per OFFSET $off", [$pid]),
            ];
        },
    ],
];
