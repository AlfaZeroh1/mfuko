<?php
$scripts[] = 'js/picker.js';
$suppliers = DB::all('SELECT id, name FROM suppliers WHERE is_active=1 ORDER BY name');
$canCost = Auth::can('products.cost');
?>
<div class="page-head">
  <h2 class="grow">Purchases / Goods received</h2>
  <a class="btn" href="<?= url('suppliers') ?>"><?= icon('store') ?> Suppliers</a>
  <button class="btn btn-primary" id="newBtn"><?= icon('plus') ?> Receive stock</button>
</div>
<div class="card mb <?= isset($_GET['new']) ? '' : 'hide' ?>" id="form">
  <div class="card-head"><h3 class="grow">New goods received note (GRN)</h3></div>
  <div class="card-body">
    <div class="form-grid" style="grid-template-columns:repeat(4,minmax(0,1fr))">
      <div class="field"><label>Supplier *</label><select id="sup"><option value="">Choose…</option><?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Supplier invoice no.</label><input id="inv"></div>
      <div class="field"><label>Date</label><input type="date" id="date" value="<?= date('Y-m-d') ?>"></div>
      <div class="field"><label>Carriage inwards</label><input type="number" step="0.01" id="ship" value="0"></div>
    </div>
    <div id="lines"></div>
    <label class="check mt"><input type="checkbox" id="updPrices" checked> Update selling prices from this GRN</label>
    <div class="grid g2 mt">
      <div><label>Paid now</label>
        <div class="row nowrap"><select id="pm" style="max-width:130px"><option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="bank">Bank</option></select><input type="number" step="0.01" id="pa" placeholder="Amount (0 = on credit)"><input id="pr" placeholder="Ref"></div></div>
      <div class="field"><label>Notes</label><input id="notes"></div>
    </div>
    <div class="row mt"><div class="grow"><b id="grand"></b></div><button class="btn btn-primary btn-lg" id="save"><?= icon('check') ?> Save GRN & update stock</button></div>
  </div>
</div>
<div class="card">
  <div class="card-head"><div class="search-box grow"><?= icon('search') ?><input type="search" id="q" placeholder="GRN, invoice, supplier…"></div><label class="check"><input type="checkbox" id="unpaid"> Unpaid only</label><span class="badge badge-danger" id="owed"></span></div>
  <div id="tbl"></div>
</div>
<script>
MF.ready(() => {
  const { $, esc, money, num } = MF;
  const cols = [
    { key: 'qty', label: 'Qty', type: 'qty', def: () => 1 },
    { key: 'bonus', label: 'Bonus', type: 'qty', def: () => 0 },
    { key: 'cost', label: 'Unit cost', type: 'money', def: p => p.cost_price ?? '' },
    { key: 'discount', label: 'Disc.', type: 'money', def: () => 0 },
    { key: 'retail', label: 'New retail', type: 'money', def: p => p.retail_price },
    { key: 'wholesale', label: 'New w/sale', type: 'money', def: p => p.wholesale_price },
    { key: 'expiry', label: 'Expiry', type: 'date', def: () => '' },
  ];
  const ed = MF.lineEditor($('#lines'), { cols, total: l => num(l.qty) * num(l.cost) - num(l.discount), onChange: ls => { $('#grand').textContent = 'Grand total ' + money(ls.reduce((s, l) => s + num(l.qty) * num(l.cost) - num(l.discount), 0) + num($('#ship').value)); } });
  $('#newBtn').onclick = () => $('#form').classList.toggle('hide');
  $('#save').onclick = async e => {
    if (!$('#sup').value) return MF.toast('Choose the supplier', 'warn');
    e.target.classList.add('loading');
    try {
      const r = await MF.api('purchases.create', { supplier_id: $('#sup').value, supplier_invoice: $('#inv').value, purchase_date: $('#date').value, shipping: $('#ship').value,
        notes: $('#notes').value, update_prices: $('#updPrices').checked ? 1 : 0, items: ed.lines.map(l => ({ product_id: l.product_id, qty: l.qty, bonus: l.bonus, cost: l.cost, discount: l.discount, retail: l.retail, wholesale: l.wholesale, expiry: l.expiry })),
        payments: num($('#pa').value) > 0 ? [{ method: $('#pm').value, amount: $('#pa').value, reference: $('#pr').value }] : [] });
      MF.toast(r.grn_no + ' saved — stock updated', 'ok'); ed.clear(); MF.store.del('mf-catalog-v2'); t.reload(); MF.printMenu('grn', r.id);
    } finally { e.target.classList.remove('loading'); }
  };
  const t = MF.table($('#tbl'), { action: 'purchases.list', search: $('#q'), params: () => ({ unpaid: $('#unpaid').checked ? 1 : '' }), emptyIcon: 'truck', empty: 'No purchases yet',
    onLoad: d => $('#owed').textContent = 'We owe ' + money(d.owed),
    columns: [
      { key: 'grn_no', label: 'GRN', render: r => `<b>${esc(r.grn_no)}</b><div class="tiny muted">${esc(r.supplier_invoice || '')}</div>` },
      { key: 'purchase_date', label: 'Date' }, { key: 'supplier', label: 'Supplier' }, { key: 'store', label: 'Store' },
      { key: 'total', label: 'Total', num: true, render: r => money(r.total, false) },
      { key: 'balance', label: 'Owed', num: true, render: r => +r.balance > 0 ? `<b class="text-danger">${money(r.balance, false)}</b>` : '<span class="badge badge-green">paid</span>' },
      { key: '_a', label: '', cls: 'actions', render: () => `<button class="btn btn-sm btn-ghost" data-act="print">${MF.ic('printer', 'sm')}</button><button class="btn btn-sm btn-ghost" data-act="ret" title="Return to supplier">${MF.ic('swap', 'sm')}</button>` },
    ],
    onAction: async (a, r) => {
      if (a === 'print') MF.printMenu('grn', r.id);
      if (a === 'ret') {
        const d = await MF.get('purchases.view', { id: r.id });
        MF.modal({ title: 'Return to supplier · ' + r.grn_no, wide: true, body: `<table class="table"><thead><tr><th>Item</th><th class="num">Received</th><th class="num">Return</th></tr></thead><tbody>${d.items.map(i => `<tr><td>${esc(i.name)}</td><td class="num">${MF.qty(+i.qty + +i.bonus_qty - +i.returned_qty)}</td><td class="num"><input type="number" step="any" min="0" value="0" data-ri="${i.id}" style="max-width:100px"></td></tr>`).join('')}</tbody></table><div class="field mt"><label>Reason</label><input name="reason"></div>`,
          foot: [{ label: 'Cancel', cls: 'btn-ghost' }, { label: 'Create return', cls: 'btn-primary', onClick: async api => {
            const items = [...api.body.querySelectorAll('[data-ri]')].map(i => ({ item_id: +i.dataset.ri, qty: +i.value })).filter(i => i.qty > 0);
            const res = await MF.api('purchases.return', { purchase_id: r.id, items, reason: api.body.querySelector('[name=reason]').value });
            MF.toast(res.return_no + ' created', 'ok'); t.reload(); MF.printMenu('sreturn', res.id);
          } }] });
      }
    } });
  $('#unpaid').onchange = () => t.reload(true);
  $('#ship').oninput = () => ed.lines.length && $('#lines input').dispatchEvent(new Event('input', { bubbles: true }));
});
</script>
