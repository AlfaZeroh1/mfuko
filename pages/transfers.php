<?php
$scripts[] = 'js/picker.js';
$all = DB::all('SELECT id, name FROM stores WHERE is_active=1 ORDER BY name');
$mine = Auth::stores();
?>
<div class="page-head"><h2 class="grow">Stock transfers</h2><button class="btn btn-primary" id="newBtn"><?= icon('plus') ?> New transfer</button></div>
<div class="card mb hide" id="form">
  <div class="card-head"><h3 class="grow">New transfer / delivery note</h3></div>
  <div class="card-body">
    <div class="form-grid">
      <div class="field"><label>From store</label><select id="from"><?php foreach ($mine as $s): ?><option value="<?= $s['id'] ?>" <?= (int)$s['id'] === $storeId ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>To store</label><select id="to"><?php foreach ($all as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field full"><label>Notes / driver / vehicle</label><input id="notes"></div>
    </div>
    <div id="lines"></div>
    <div class="row mt"><label class="check grow"><input type="checkbox" id="recv"> Mark as received immediately</label><button class="btn btn-primary" id="save"><?= icon('send') ?> Dispatch</button></div>
  </div>
</div>
<div class="card"><div id="tbl"></div></div>
<?php if (count($all) < 2): ?><div class="alert alert-gold mt"><?= icon('info') ?><span>You only have one store. Add branches in Admin → Stores / branches to transfer stock.</span></div><?php endif; ?>
<script>
MF.ready(() => {
  const { $, esc } = MF;
  const ed = MF.lineEditor($('#lines'), { store: () => $('#from').value, cols: [{ key: 'qty', label: 'Qty', type: 'qty', def: () => 1 }] });
  $('#newBtn').onclick = () => $('#form').classList.toggle('hide');
  const t = MF.table($('#tbl'), { action: 'stock.transfers', emptyIcon: 'swap', empty: 'No transfers yet', columns: [
    { key: 'transfer_no', label: 'No.', render: r => `<b>${esc(r.transfer_no)}</b>` },
    { key: 'created_at', label: 'Date', render: r => esc(r.created_at.slice(0, 16)) },
    { key: 'from_store', label: 'From → To', render: r => `${esc(r.from_store)} → ${esc(r.to_store)}` },
    { key: 'items', label: 'Lines', num: true },
    { key: 'status', label: 'Status', render: r => `<span class="badge badge-${r.status === 'received' ? 'green' : 'gold'}">${esc(r.status.replace('_', ' '))}</span>` },
    { key: '_a', label: '', cls: 'actions', render: r => `<button class="btn btn-sm btn-ghost" data-act="print">${MF.ic('printer', 'sm')}</button>` + (r.status === 'in_transit' ? `<button class="btn btn-sm btn-green" data-act="recv">Receive</button>` : '') },
  ], onAction: async (a, r) => {
    if (a === 'print') MF.printMenu('transfer', r.id);
    if (a === 'recv' && await MF.confirm(`Confirm all items of ${r.transfer_no} arrived at ${r.to_store}?`)) { await MF.api('stock.transfer_receive', { id: r.id }); MF.toast('Received', 'ok'); t.reload(); }
  } });
  $('#save').onclick = async e => {
    e.target.classList.add('loading');
    try {
      const r = await MF.api('stock.transfer_create', { from_store_id: $('#from').value, to_store_id: $('#to').value, notes: $('#notes').value, receive_now: $('#recv').checked ? 1 : 0, items: ed.lines.map(l => ({ product_id: l.product_id, qty: l.qty })) });
      MF.toast(r.transfer_no + ' dispatched', 'ok'); ed.clear(); t.reload(); MF.printMenu('transfer', r.id);
    } finally { e.target.classList.remove('loading'); }
  };
});
</script>
