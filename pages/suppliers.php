<?php
$defs = require MF_ROOT . '/app/resources.php';
$def = $defs['suppliers'];
$clientDef = $def; $clientDef['canEdit'] = Auth::can('suppliers.manage');
unset($clientDef['table'], $clientDef['search']);
foreach ($clientDef['computed'] as $k => $c) unset($clientDef['computed'][$k]['sql']);
$scripts[] = 'js/resource.js';
?>
<div class="page-head">
  <h2 class="grow">Suppliers</h2>
  <button class="btn" id="payBtn"><?= icon('wallet') ?> Pay supplier</button>
  <button class="btn btn-primary" id="resNew"><?= icon('plus') ?> New supplier</button>
</div>
<div class="card">
  <div class="card-head"><div class="search-box grow"><?= icon('search') ?><input type="search" id="resSearch" placeholder="Search suppliers…"></div>
    <select id="resActive" style="max-width:150px"><option value="1">Active</option><option value="0">Inactive</option><option value="all">All</option></select></div>
  <div id="resTable"></div>
</div>
<script>
MF.ready(() => {
  const { $, esc, money } = MF;
  const def = <?= json_encode($clientDef, JSON_HEX_TAG) ?>;
  const t = MF.resourcePage($('#resTable'), 'suppliers', def);
  $('#payBtn').onclick = async () => {
    const sups = await MF.get('resource.list', { r: 'suppliers', per: 200 });
    const owing = sups.rows.filter(s => +s.owed > 0);
    if (!owing.length) return MF.toast('You owe no supplier anything 🎉', 'ok');
    MF.modal({ title: 'Pay supplier', body: `<div class="field"><label>Supplier</label><select name="supplier_id">${owing.map(s => `<option value="${s.id}" data-o="${s.owed}">${esc(s.name)} — owe ${money(s.owed)}</option>`).join('')}</select></div>
      <div class="form-grid"><div class="field"><label>Amount</label><input type="number" step="0.01" name="amount" value="${owing[0].owed}"></div>
      <div class="field"><label>Method</label><select name="method"><option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="bank">Bank</option></select></div>
      <div class="field full"><label>Reference</label><input name="reference"></div></div><p class="small muted">Applied to the oldest unpaid GRNs first.</p>`,
      foot: [{ label: 'Cancel', cls: 'btn-ghost' }, { label: 'Record payment', cls: 'btn-primary', onClick: async api => {
        const r = await MF.api('purchases.pay_supplier', MF.formData(api.body)); MF.toast('Voucher ' + r.voucher_no, 'ok'); t.reload(); MF.printMenu('voucher', r.voucher_no);
      } }],
      onOpen: api => { const s = api.body.querySelector('[name=supplier_id]'); s.onchange = () => api.body.querySelector('[name=amount]').value = s.selectedOptions[0].dataset.o; } });
  };
});
</script>
