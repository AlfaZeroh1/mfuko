<?php
$users = Auth::can('sales.view_all') ? DB::all('SELECT id, CONCAT(first_name," ",COALESCE(last_name,"")) n FROM users ORDER BY first_name') : [];
$stores = Auth::stores();
?>
<div class="page-head">
  <h2 class="grow">Sales</h2>
  <?php if (Auth::can('sell.create')): ?><a class="btn btn-gold" href="<?= url('sell') ?>"><?= icon('cart') ?> New sale</a><?php endif; ?>
</div>
<div class="grid g3 mb">
  <div class="card kpi green"><div class="label">Sales in filter</div><div class="value" id="kSum">—</div><div class="small muted" id="kCount"></div></div>
  <div class="card kpi danger"><div class="label">Unpaid balance</div><div class="value" id="kBal">—</div></div>
  <div class="card kpi indigo"><div class="label">Average sale</div><div class="value" id="kAvg">—</div></div>
</div>
<div class="card">
  <div class="card-head" style="flex-wrap:wrap">
    <div class="search-box grow" style="min-width:200px"><?= icon('search') ?><input type="search" id="q" placeholder="Invoice no, customer, phone…"></div>
    <input type="date" id="from" value="<?= date('Y-m-d', strtotime('-6 days')) ?>" style="max-width:150px">
    <input type="date" id="to" value="<?= date('Y-m-d') ?>" style="max-width:150px">
    <select id="status" style="max-width:140px"><option value="">All statuses</option><option value="paid">Paid</option><option value="partial">Part paid</option><option value="credit">Credit</option><option value="voided">Voided</option><option value="partially_returned">Returns</option></select>
    <select id="method" style="max-width:130px"><option value="">Any method</option><option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="bank">Bank</option><option value="card">Card</option></select>
    <?php if (count($stores) > 1): ?><select id="store" style="max-width:140px"><option value="">All stores</option><?php foreach ($stores as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
    <?php if ($users): ?><select id="user" style="max-width:150px"><option value="">All cashiers</option><?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>"><?= e($u['n']) ?></option><?php endforeach; ?></select><?php endif; ?>
  </div>
  <div id="tbl"></div>
</div>
<script>
MF.ready(() => {
  const { $, esc, money } = MF;
  const tone = r => r.status === 'voided' ? 'danger' : r.payment_status === 'paid' ? 'green' : r.payment_status === 'partial' ? 'gold' : 'primary';
  const mIcon = { cash: 'cash', mpesa: 'phone', bank: 'bank', card: 'card', other: 'wallet' };
  const t = MF.table($('#tbl'), {
    action: 'sales.list', search: $('#q'), emptyIcon: 'receipt', empty: 'No sales in this period',
    params: () => ({ from: $('#from').value, to: $('#to').value, status: $('#status').value, method: $('#method').value, store: $('#store')?.value || '', user: $('#user')?.value || '' }),
    onRow: r => location.href = MF.url('sale', { id: r.id }),
    onLoad: d => { $('#kSum').textContent = money(d.sum); $('#kCount').textContent = d.total + ' sales'; $('#kBal').textContent = money(d.balance); $('#kAvg').textContent = money(d.total ? d.sum / d.total : 0); },
    columns: [
      { key: 'invoice_no', label: 'Invoice', render: r => `<b>${esc(r.invoice_no)}</b><div class="tiny muted">${esc(r.sale_type)}</div>` },
      { key: 'sold_at', label: 'Date', render: r => esc(r.sold_at.slice(0, 16)) },
      { key: 'customer', label: 'Customer', render: r => esc(r.customer || 'Walk-in') },
      { key: 'cashier', label: 'Cashier', render: r => esc(r.cashier || '') + (r.store ? `<div class="tiny muted">${esc(r.store)}</div>` : '') },
      { key: 'methods', label: 'Paid via', render: r => (r.methods || '').split(',').filter(Boolean).map(m => `<span title="${m}">${MF.ic(mIcon[m] || 'wallet', 'sm')}</span>`).join(' ') || '<span class="muted">—</span>' },
      { key: 'status', label: 'Status', render: r => `<span class="badge badge-${tone(r)}">${esc(r.status === 'completed' ? r.payment_status : r.status.replace('_', ' '))}</span>` },
      { key: 'total', label: 'Total', num: true, render: r => `<b>${money(r.total, false)}</b>` },
      { key: 'balance', label: 'Balance', num: true, render: r => +r.balance > 0 ? `<span class="text-danger">${money(r.balance, false)}</span>` : '—' },
      { key: '_p', label: '', cls: 'actions', render: () => `<button class="btn btn-sm btn-ghost" data-act="print" title="Print">${MF.ic('printer', 'sm')}</button>` },
    ],
    onAction: (a, r) => { if (a === 'print') MF.printMenu('receipt', r.id); },
  });
  ['#from', '#to', '#status', '#method', '#store', '#user'].forEach(s => $(s)?.addEventListener('change', () => t.reload(true)));
});
</script>
