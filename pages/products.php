<?php
$cats = DB::all('SELECT id, name FROM categories WHERE is_active=1 ORDER BY name');
$canEdit = Auth::can('products.manage');
$canCost = Auth::can('products.cost');
$stats = DB::row('SELECT COUNT(*) n, SUM(p.track_stock=1 AND COALESCE(s.qty,0)<=0) outs, SUM(p.track_stock=1 AND COALESCE(s.qty,0)>0 AND COALESCE(s.qty,0)<=p.reorder_level) lows, SUM(p.image IS NULL) noimg
    FROM products p LEFT JOIN stock s ON s.product_id=p.id AND s.store_id=? WHERE p.is_active=1', [$storeId]);
$value = $canCost ? (float)DB::val('SELECT COALESCE(SUM(s.qty*p.cost_price),0) FROM stock s JOIN products p ON p.id=s.product_id WHERE s.store_id=? AND s.qty>0', [$storeId]) : null;
?>
<div class="grid g4 mb">
  <div class="card kpi indigo"><span class="kpi-ic"><?= icon('box') ?></span><div class="label">Active products</div><div class="value"><?= number_format((int)$stats['n']) ?></div><div class="small muted"><?= (int)$stats['noimg'] ?> without photo</div></div>
  <div class="card kpi gold"><span class="kpi-ic"><?= icon('alert') ?></span><div class="label">Low stock</div><div class="value"><?= (int)$stats['lows'] ?></div></div>
  <div class="card kpi danger"><span class="kpi-ic"><?= icon('x') ?></span><div class="label">Out of stock</div><div class="value"><?= (int)$stats['outs'] ?></div></div>
  <div class="card kpi green"><span class="kpi-ic"><?= icon('wallet') ?></span><div class="label">Stock value (cost)</div><div class="value"><?= $value === null ? '—' : money($value) ?></div></div>
</div>
<div class="page-head">
  <h2 class="grow">Products</h2>
  <a class="btn btn-ghost" href="print.php?doc=pricelist&id=0&size=a4" target="_blank"><?= icon('printer') ?> Price list</a>
  <?php if ($canEdit): ?>
    <button class="btn" id="importBtn"><?= icon('upload') ?> Import CSV</button>
    <button class="btn btn-primary" id="newBtn"><?= icon('plus') ?> New product</button>
  <?php endif; ?>
</div>
<div class="card">
  <div class="card-head" style="flex-wrap:wrap">
    <div class="search-box grow" style="min-width:200px"><?= icon('search') ?><input type="search" id="q" placeholder="Name, SKU, barcode, brand…"></div>
    <select id="cat" style="max-width:170px"><option value="">All categories</option><?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
    <select id="stk" style="max-width:140px"><option value="">Any stock</option><option value="low">Low stock</option><option value="out">Out of stock</option></select>
    <select id="act" style="max-width:120px"><option value="1">Active</option><option value="0">Inactive</option></select>
  </div>
  <div id="tbl"></div>
</div>
<script>
MF.ready(() => {
  const { $, esc, money, num } = MF;
  const cats = <?= json_encode($cats) ?>, canEdit = <?= $canEdit ? 'true' : 'false' ?>, canCost = <?= $canCost ? 'true' : 'false' ?>;
  const hue = s => { let h = 0; for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) % 360; return h; };
  const cols = [
    { key: 'name', label: 'Product', render: r => `<div class="cell-flex">${r.image ? `<img class="thumb" src="${esc(r.image)}" loading="lazy" alt="">` : `<span class="thumb" style="background:hsl(${hue(r.name)} 55% 48%);color:#fff">${esc(r.name.slice(0, 2).toUpperCase())}</span>`}<div><b>${esc(r.name)}</b>${+r.is_favorite ? ' ⭐' : ''}<div class="tiny muted">${esc([r.sku, r.brand, r.category].filter(Boolean).join(' · '))}</div></div></div>` },
    { key: 'qty', label: 'In stock', num: true, render: r => !+r.track_stock ? '<span class="muted">n/a</span>' : `<span class="badge ${+r.qty <= 0 ? 'badge-danger' : +r.qty <= +r.reorder_level ? 'badge-gold' : 'badge-green'}">${MF.qty(r.qty)} ${esc(r.unit)}</span>` },
  ];
  if (canCost) cols.push({ key: 'cost_price', label: 'Cost', num: true, render: r => money(r.cost_price, false) });
  cols.push({ key: 'retail_price', label: 'Retail', num: true, render: r => `<b>${money(r.retail_price, false)}</b>` + (canCost && +r.cost_price > 0 ? `<div class="tiny ${r.retail_price - r.cost_price < 0 ? 'text-danger' : 'muted'}">${Math.round((r.retail_price - r.cost_price) / r.retail_price * 100)}% margin</div>` : '') });
  cols.push({ key: 'wholesale_price', label: 'Wholesale', num: true, render: r => +r.wholesale_price ? money(r.wholesale_price, false) : '—' });
  cols.push({ key: '_a', label: '', cls: 'actions', render: () => (MF.can('stock.adjust') ? `<button class="btn btn-sm btn-ghost" data-act="adj" title="Adjust stock">${MF.ic('layers', 'sm')}</button>` : '') + `<button class="btn btn-sm btn-ghost" data-act="hist" title="Stock history">${MF.ic('history', 'sm')}</button>` + (canEdit ? `<button class="btn btn-sm btn-ghost" data-act="edit">${MF.ic('edit', 'sm')}</button>` : '') });

  const t = MF.table($('#tbl'), { action: 'products.list', search: $('#q'), columns: cols, emptyIcon: 'box', empty: 'No products yet — add your first one',
    params: () => ({ category: $('#cat').value, stock: $('#stk').value, active: $('#act').value }),
    onRow: canEdit ? r => edit(r.id) : null,
    onAction: (a, r) => { if (a === 'edit') edit(r.id); if (a === 'adj') adjust(r); if (a === 'hist') history(r); } });
  ['#cat', '#stk', '#act'].forEach(s => $(s).onchange = () => t.reload(true));

  /* Resize photos on-device before upload: phone pictures are 3–8MB */
  function shrink(file, max = 800) {
    return new Promise((res, rej) => {
      const img = new Image(); const url = URL.createObjectURL(file);
      img.onload = () => { const s = Math.min(1, max / Math.max(img.width, img.height)); const c = document.createElement('canvas');
        c.width = Math.round(img.width * s); c.height = Math.round(img.height * s); c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url); c.toBlob(b => res(b), 'image/webp', 0.82); };
      img.onerror = rej; img.src = url;
    });
  }

  async function edit(id) {
    const p = id ? await MF.get('products.get', { id }) : { unit: 'pc', track_stock: 1, is_active: 1, tax_rate: <?= json_encode((float)Settings::get('default_tax_rate')) ?>, reorder_level: 5 };
    let blob = null, removeImg = false;
    const catOpts = cats.map(c => `<option value="${c.id}" ${+p.category_id === +c.id ? 'selected' : ''}>${esc(c.name)}</option>`).join('');
    const m = MF.modal({ title: id ? 'Edit product' : 'New product', wide: true, body: `
      <form onsubmit="return false"><div class="row" style="align-items:flex-start;gap:18px">
        <div style="width:150px;flex:none;text-align:center">
          <div id="imgPrev" style="width:150px;height:150px;border-radius:18px;background:var(--surface-3);display:grid;place-items:center;overflow:hidden;border:2px dashed var(--border-2)">${p.image ? `<img src="${esc(p.image)}" style="width:100%;height:100%;object-fit:cover">` : MF.ic('image', 'lg')}</div>
          <label class="btn btn-sm btn-soft mt" style="margin-top:8px">${MF.ic('camera', 'sm')} Photo<input type="file" accept="image/*" capture="environment" id="imgIn" hidden></label>
          <label class="btn btn-sm btn-ghost">${MF.ic('image', 'sm')} Gallery<input type="file" accept="image/*" id="imgIn2" hidden></label>
          ${p.image ? `<button type="button" class="btn btn-sm btn-ghost text-danger" id="imgRm">Remove</button>` : ''}
        </div>
        <div class="form-grid grow">
          <div class="field full"><label>Product name *</label><input name="name" value="${esc(p.name || '')}" required></div>
          <div class="field"><label>Category</label><select name="category_id"><option value="">—</option>${catOpts}</select></div>
          <div class="field"><label>Brand / manufacturer</label><input name="brand" value="${esc(p.brand || '')}"></div>
          <div class="field"><label>SKU / code</label><input name="sku" value="${esc(p.sku || '')}"></div>
          <div class="field"><label>Barcode</label><input name="barcode" value="${esc(p.barcode || '')}" inputmode="numeric"></div>
          <div class="field"><label>Unit</label><input name="unit" value="${esc(p.unit || 'pc')}" list="units"><datalist id="units"><option value="pc"><option value="kg"><option value="g"><option value="litre"><option value="pack"><option value="carton"><option value="box"><option value="dozen"><option value="bale"><option value="metre"></datalist></div>
          <div class="field"><label>VAT rate %</label><input type="number" step="0.01" name="tax_rate" value="${p.tax_rate ?? 0}"></div>
        </div></div>
        <hr><div class="form-grid" style="grid-template-columns:repeat(4,minmax(0,1fr))">
          ${canCost ? `<div class="field"><label>Cost price</label><input type="number" step="0.01" name="cost_price" value="${p.cost_price ?? 0}"></div>` : ''}
          <div class="field"><label>Retail price *</label><input type="number" step="0.01" name="retail_price" value="${p.retail_price ?? ''}" required></div>
          <div class="field"><label>Wholesale price</label><input type="number" step="0.01" name="wholesale_price" value="${p.wholesale_price ?? 0}"></div>
          <div class="field"><label>Minimum price</label><input type="number" step="0.01" name="min_price" value="${p.min_price ?? 0}"></div>
          <div class="field"><label>Re-order level</label><input type="number" step="any" name="reorder_level" value="${p.reorder_level ?? 0}"></div>
          <div class="field"><label>Expiry date</label><input type="date" name="expiry_date" value="${esc(p.expiry_date || '')}"></div>
          ${id ? '' : `<div class="field"><label>Opening stock (this store)</label><input type="number" step="any" name="opening_qty" value="0"></div>`}
          <div class="field"><label>Units per bulk pack</label><input type="number" step="any" name="units_per_parent" value="${p.units_per_parent ?? ''}" placeholder="e.g. 24"></div>
          <div class="field" style="grid-column:span 2"><label>Breaks down from (bulk pack product id)</label><input type="number" name="parent_id" value="${p.parent_id ?? ''}" placeholder="optional"></div>
          <div class="field"><label class="check"><span class="switch"><input type="checkbox" name="track_stock" ${+p.track_stock ? 'checked' : ''}><span></span></span>Track stock</label></div>
          <div class="field"><label class="check"><span class="switch"><input type="checkbox" name="is_favorite" ${+p.is_favorite ? 'checked' : ''}><span></span></span>Quick pick ⭐</label></div>
          <div class="field"><label class="check"><span class="switch"><input type="checkbox" name="is_active" ${+p.is_active ? 'checked' : ''}><span></span></span>Active</label></div>
        </div>
        ${p.stock ? `<div class="small muted">Stock: ${p.stock.map(s => esc(s.store) + ' ' + MF.qty(s.qty)).join(' · ')} · sold ${MF.qty(p.sales30)} in 30 days</div>` : ''}
      </form>`,
      foot: [
        ...(id && canEdit ? [{ label: 'Deactivate', cls: 'btn-ghost text-danger', onClick: async () => { if (!await MF.confirm('Deactivate this product?', { danger: true })) return false; await MF.api('products.delete', { id }); t.reload(); } }] : []),
        { label: 'Cancel', cls: 'btn-ghost' },
        { label: 'Save product', cls: 'btn-primary', onClick: async api => {
          const form = $('form', api.body); if (!form.reportValidity()) return false;
          const fd = new FormData(); const f = MF.formData(form);
          Object.entries(f).forEach(([k, v]) => fd.append(k, v));
          if (id) fd.append('id', id);
          if (blob) fd.append('image', blob, 'photo.webp');
          if (removeImg) fd.append('remove_image', 1);
          await MF.api('products.save', fd);
          MF.store.del('mf-catalog-v2');
          MF.toast('Product saved', 'ok'); t.reload();
        } }],
      onOpen: api => {
        const onPick = async e => { const file = e.target.files[0]; if (!file) return; blob = await shrink(file).catch(() => file);
          $('#imgPrev', api.body).innerHTML = `<img src="${URL.createObjectURL(blob)}" style="width:100%;height:100%;object-fit:cover">`; removeImg = false; };
        $('#imgIn', api.body).onchange = onPick; $('#imgIn2', api.body).onchange = onPick;
        const rm = $('#imgRm', api.body); if (rm) rm.onclick = () => { removeImg = true; blob = null; $('#imgPrev', api.body).innerHTML = MF.ic('image', 'lg'); };
      } });
  }

  function adjust(r) {
    MF.modal({ title: 'Adjust stock · ' + r.name, body: `<p class="muted">Current: <b>${MF.qty(r.qty)} ${esc(r.unit)}</b></p>
      <div class="seg mb" id="mode"><button type="button" class="on" data-m="delta">Add / remove</button><button type="button" data-m="set">Set exact count</button></div>
      <div class="field"><label>Quantity (use minus to remove)</label><input type="number" step="any" name="qty" required></div>
      <div class="field"><label>Reason</label><input name="reason" list="reasons" required><datalist id="reasons"><option value="Damaged"><option value="Expired"><option value="Correction"><option value="Internal use"><option value="Theft / loss"><option value="Found stock"></datalist></div>
      ${MF.can('stock.adjust') ? `<hr><p class="small muted">Bulk pack? <a href="#" id="bd">Break down packs into this unit</a></p>` : ''}`,
      foot: [{ label: 'Cancel', cls: 'btn-ghost' }, { label: 'Save', cls: 'btn-primary', onClick: async api => {
        const f = MF.formData(api.body); const mode = $('#mode .on', api.body).dataset.m;
        const res = await MF.api('stock.adjust', { product_id: r.id, qty: f.qty, reason: f.reason, mode }); MF.toast('New balance ' + MF.qty(res.balance), 'ok'); t.reload();
      } }],
      onOpen: api => {
        api.body.querySelectorAll('#mode button').forEach(b => b.onclick = () => api.body.querySelectorAll('#mode button').forEach(x => x.classList.toggle('on', x === b)));
        const bd = $('#bd', api.body); if (bd) bd.onclick = async e => { e.preventDefault(); const n = await MF.ask('How many bulk packs to break down?', { type: 'number', value: '1' }); if (!n) return; const res = await MF.api('stock.breakdown', { child_id: r.id, packs: n }); MF.toast(`+${MF.qty(res.units)} ${r.unit}`, 'ok'); api.close(); t.reload(); };
      } });
  }

  function history(r) {
    const m = MF.modal({ title: 'Stock history · ' + r.name, wide: true, body: '<div id="mv"></div>', foot: false });
    MF.table($('#mv', m.body), { action: 'products.movements', params: () => ({ id: r.id }), pageSize: 15, columns: [
      { key: 'created_at', label: 'When', render: x => esc(x.created_at.slice(0, 16)) },
      { key: 'type', label: 'Type', render: x => `<span class="badge">${esc(x.type.replace('_', ' '))}</span>` },
      { key: 'store', label: 'Store' }, { key: 'note', label: 'Ref' },
      { key: 'qty', label: 'Change', num: true, render: x => `<b class="${+x.qty < 0 ? 'text-danger' : 'text-green'}">${+x.qty > 0 ? '+' : ''}${MF.qty(x.qty)}</b>` },
      { key: 'balance', label: 'Balance', num: true, render: x => MF.qty(x.balance) }, { key: 'user', label: 'By' },
    ] });
  }

  $('#newBtn') && ($('#newBtn').onclick = () => edit(0));
  $('#importBtn') && ($('#importBtn').onclick = () => MF.modal({ title: 'Import products from CSV', body: `<p class="small">Columns (header row required): <code>name, sku, barcode, category, unit, cost, retail, wholesale, qty, reorder</code>. Existing products are matched by SKU (or name) and updated. <b>qty</b> sets stock in the current store.</p><input type="file" accept=".csv,text/csv" id="csv">`,
    foot: [{ label: 'Cancel', cls: 'btn-ghost' }, { label: 'Import', cls: 'btn-primary', onClick: async api => { const f = $('#csv', api.body).files[0]; if (!f) return false; const fd = new FormData(); fd.append('file', f); const r = await MF.api('products.import', fd); MF.toast(`${r.created} created, ${r.updated} updated`, 'ok'); MF.store.del('mf-catalog-v2'); t.reload(); } }] }));
});
</script>
