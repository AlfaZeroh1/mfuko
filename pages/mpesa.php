<?php $scripts[] = 'js/widgets.js'; ?>
<div class="page-head">
  <div class="grow"><h2><?= icon('phone') ?> M-Pesa transactions</h2>
    <p class="muted small" style="margin:0"><?= Mpesa::enabled() ? 'Daraja is connected (' . e(Settings::get('mpesa_env')) . ').' : 'Daraja is not configured yet — codes entered manually at checkout still work. Set it up in Settings → M-Pesa.' ?></p></div>
</div>
<div class="card">
  <div class="card-head"><div class="search-box grow"><?= icon('search') ?><input type="search" id="q" placeholder="Receipt code, phone, name…"></div>
    <select id="flt" style="max-width:190px"><option value="">All</option><option value="unmatched">Unmatched (not on a sale)</option><option value="pending">Pending</option><option value="failed">Failed</option></select></div>
  <div id="tbl"></div>
</div>
<script>
MF.ready(() => {
  const { $, esc, money } = MF;
  const t = MF.table($('#tbl'), { action: 'mpesa.list', search: $('#q'), emptyIcon: 'phone', empty: 'No M-Pesa transactions yet',
    params: () => { const f = $('#flt').value; return f === 'unmatched' ? { unmatched: 1 } : { status: f }; },
    columns: [
      { key: 'mpesa_receipt', label: 'Code', render: r => `<b class="mono">${esc(r.mpesa_receipt || '—')}</b><div class="tiny muted">${esc(r.type)}</div>` },
      { key: 'created_at', label: 'Time', render: r => esc(r.created_at.slice(0, 16)) },
      { key: 'payer', label: 'Payer', render: r => esc(r.payer_name || '') + `<div class="tiny muted">${esc(r.phone || '')}</div>` },
      { key: 'status', label: 'Status', render: r => `<span class="badge badge-${r.status === 'success' ? 'green' : r.status === 'pending' ? 'gold' : 'danger'}">${esc(r.status)}</span>` },
      { key: 'invoice_no', label: 'Linked to', render: r => r.invoice_no ? `<a href="${MF.url('sale', { id: r.sale_id })}">${esc(r.invoice_no)}</a>` : r.payment_id ? 'debt payment' : (r.status === 'success' ? '<span class="badge badge-gold">unmatched</span>' : '—') },
      { key: 'amount', label: 'Amount', num: true, render: r => `<b>${money(r.amount, false)}</b>` },
      { key: '_a', label: '', cls: 'actions', render: r => `<button class="btn btn-sm btn-ghost" data-act="print">${MF.ic('printer', 'sm')}</button>` + (r.status === 'success' && !r.payment_id && MF.can('debts.collect') ? `<button class="btn btn-sm btn-soft" data-act="claim">Apply to debt</button>` : '') },
    ],
    onAction: async (a, r) => {
      if (a === 'print') MF.printMenu('mpesa', r.id);
      if (a === 'claim') {
        const q = await MF.ask('Customer name or phone to apply ' + money(r.amount) + ' to', { value: r.payer_name || r.phone || '' });
        if (!q) return;
        const found = await MF.get('customers.search', { q });
        if (!found.length) return MF.toast('No customer found', 'warn');
        const c = found[0];
        if (!await MF.confirm(`Apply ${r.mpesa_receipt} (${money(r.amount)}) to ${c.name}'s debt of ${money(c.debt)}?`)) return;
        const res = await MF.api('mpesa.claim', { id: r.id, customer_id: c.id }); MF.toast('Applied · ' + res.receipt_no, 'ok'); t.reload();
      }
    } });
  $('#flt').onchange = () => t.reload(true);
});
</script>
