<div class="page-head">
  <div class="grow"><h2>Quotations</h2><p class="muted small" style="margin:0">Create quotes from the Sell screen (menu → Save as quotation). Convert a quote to a sale in one tap.</p></div>
  <a class="btn btn-primary" href="<?= url('sell') ?>"><?= icon('plus') ?> New quotation</a>
</div>
<div class="card">
  <div class="card-head"><div class="search-box grow"><?= icon('search') ?><input type="search" id="q" placeholder="Quote no or customer…"></div>
    <select id="st" style="max-width:150px"><option value="">All</option><option value="pending" selected>Pending</option><option value="converted">Converted</option><option value="cancelled">Cancelled</option></select></div>
  <div id="tbl"></div>
</div>
<script>
MF.ready(() => {
  const { $, esc, money } = MF;
  const t = MF.table($('#tbl'), { action: 'sales.quotes', search: $('#q'), params: () => ({ status: $('#st').value }), emptyIcon: 'file', empty: 'No quotations',
    columns: [
      { key: 'quote_no', label: 'Quote', render: r => `<b>${esc(r.quote_no)}</b>` },
      { key: 'created_at', label: 'Date', render: r => esc(r.created_at.slice(0, 10)) },
      { key: 'customer', label: 'Customer', render: r => esc(r.customer || '—') },
      { key: 'valid_until', label: 'Valid until', render: r => { const exp = r.valid_until && r.valid_until < MF.today() && r.status === 'pending'; return `<span class="${exp ? 'text-danger' : ''}">${esc(r.valid_until || '')}</span>`; } },
      { key: 'status', label: 'Status', render: r => `<span class="badge badge-${r.status === 'converted' ? 'green' : r.status === 'cancelled' ? 'danger' : 'indigo'}">${esc(r.status)}</span>${r.invoice_no ? ' <span class="tiny">' + esc(r.invoice_no) + '</span>' : ''}` },
      { key: 'total', label: 'Total', num: true, render: r => money(r.total, false) },
      { key: '_a', label: '', cls: 'actions', render: r => `<button class="btn btn-sm btn-ghost" data-act="print">${MF.ic('printer', 'sm')}</button>` + (r.status === 'pending' ? `<a class="btn btn-sm btn-primary" href="${MF.url('sell', { quote: r.id })}">Convert</a><button class="btn btn-sm btn-ghost" data-act="cancel">${MF.ic('x', 'sm')}</button>` : '') },
    ],
    onAction: async (a, r) => {
      if (a === 'print') MF.printMenu('quote', r.id);
      if (a === 'cancel' && await MF.confirm('Cancel quotation ' + r.quote_no + '?', { danger: true })) { await MF.api('sales.quote_cancel', { id: r.id }); t.reload(); }
    } });
  $('#st').onchange = () => t.reload(true);
});
</script>
