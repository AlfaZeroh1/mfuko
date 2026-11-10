<?php $id = (int)($_GET['id'] ?? 0); $scripts[] = 'js/widgets.js'; ?>
<div class="page-head">
  <a class="btn btn-ghost btn-icon" href="<?= url('sales') ?>"><?= icon('arrow-left') ?></a>
  <div class="grow"><h2 id="title">Sale</h2><div class="muted small" id="sub"></div></div>
  <div class="row" id="acts"></div>
</div>
<div class="grid g-2-1">
  <div class="stack">
    <div class="card"><div class="card-head"><h3>Items</h3></div><div class="table-wrap"><table class="table responsive"><thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Discount</th><th class="num">Total</th><th class="num">Returned</th></tr></thead><tbody id="items"></tbody><tfoot id="tf"></tfoot></table></div></div>
    <div class="card"><div class="card-head"><h3>Payments</h3></div><ul class="list" id="pays"></ul></div>
  </div>
  <div class="stack">
    <div class="card card-pad" id="summary"></div>
    <div class="card"><div class="card-head"><h3>Returns</h3></div><ul class="list" id="cns"></ul></div>
    <div class="card" id="histCard"><div class="card-head"><h3>History</h3></div><ul class="list" id="hist"></ul></div>
  </div>
</div>
<script>
MF.ready(async () => {
  const { $, esc, money } = MF;
  const id = <?= $id ?>;
  let d;
  async function load() {
    d = await MF.get('sales.view', { id });
    const s = d.sale;
    $('#title').textContent = s.invoice_no;
    $('#sub').textContent = `${s.sold_at} · ${s.store} · ${s.cashier}`;
    $('#items').innerHTML = d.items.map(i => `<tr><td data-label="Item"><b>${esc(i.name)}</b></td><td class="num" data-label="Qty">${MF.qty(i.qty)}</td><td class="num" data-label="Price">${money(i.unit_price, false)}</td><td class="num" data-label="Discount">${+i.discount ? money(i.discount, false) : '—'}</td><td class="num" data-label="Total">${money(i.total, false)}</td><td class="num" data-label="Returned">${+i.returned_qty ? MF.qty(i.returned_qty) : '—'}</td></tr>`).join('');
    $('#tf').innerHTML = `<tr><td colspan="4">Total</td><td class="num">${money(s.total, false)}</td><td></td></tr>`;
    $('#pays').innerHTML = d.payments.map(p => `<li><div class="meta"><b>${esc(p.account || p.method)} ${p.reference ? '· ' + esc(p.reference) : ''}</b><span>${esc(p.paid_at)} · ${esc(p.kind)}${p.receipt_no ? ' · ' + esc(p.receipt_no) : ''}</span></div><b class="${p.is_void ? 'muted' : p.kind === 'refund' ? 'text-danger' : ''}" style="${p.is_void ? 'text-decoration:line-through' : ''}">${p.kind === 'refund' ? '−' : ''}${money(p.amount, false)}</b></li>`).join('') || '<li class="muted">No payments — sold on credit</li>';
    const tone = s.status === 'voided' ? 'danger' : s.payment_status === 'paid' ? 'green' : 'gold';
    $('#summary').innerHTML = `<div class="row"><span class="badge badge-${tone}">${esc(s.status === 'completed' ? s.payment_status : s.status)}</span><span class="badge">${esc(s.sale_type)}</span></div>
      <h3 class="mt">${esc(s.customer || 'Walk-in customer')}</h3>${s.customer_phone ? `<div class="muted small">${esc(s.customer_phone)}</div>` : ''}
      <hr><div class="row"><span class="grow muted">Subtotal</span>${money(s.subtotal)}</div>
      ${+s.discount_total ? `<div class="row"><span class="grow muted">Discounts</span>−${money(s.discount_total)}</div>` : ''}
      ${+s.coupon_discount ? `<div class="row"><span class="grow muted">Coupon</span>−${money(s.coupon_discount)}</div>` : ''}
      ${+s.tax_total ? `<div class="row"><span class="grow muted">VAT</span>${money(s.tax_total)}</div>` : ''}
      <div class="row"><b class="grow">Total</b><b>${money(s.total)}</b></div>
      <div class="row"><span class="grow muted">Paid</span>${money(s.paid)}</div>
      ${+s.returned_total ? `<div class="row"><span class="grow muted">Returned</span>${money(s.returned_total)}</div>` : ''}
      <div class="row"><b class="grow">Balance</b><b class="${+s.balance ? 'text-danger' : 'text-green'}">${money(s.balance)}</b></div>
      ${s.cost_total !== undefined ? `<div class="row small"><span class="grow muted">Gross profit</span>${money(s.total - s.cost_total)}</div>` : ''}
      ${s.notes ? `<hr><p>${esc(s.notes)}</p>` : ''}${s.void_reason ? `<div class="alert alert-danger">Voided: ${esc(s.void_reason)}</div>` : ''}`;
    $('#cns').innerHTML = d.credit_notes.map(c => `<li><div class="meta"><b>${esc(c.cn_no)}</b><span>${esc(c.created_at)}${+c.refund_amount ? ' · refunded ' + money(c.refund_amount) + ' ' + esc(c.refund_method) : ''}</span></div><b>${money(c.total, false)}</b><button class="btn btn-sm btn-ghost" onclick="MF.printMenu('credit', ${c.id})">${MF.ic('printer', 'sm')}</button></li>`).join('') || '<li class="muted">No returns</li>';
    $('#histCard').classList.toggle('hide', !d.history.length);
    $('#hist').innerHTML = d.history.map(h => `<li><div class="meta"><b>${esc(h.summary)}</b><span>${esc(h.first_name || '')} · ${esc(h.created_at)}</span></div></li>`).join('');
    const acts = [`<button class="btn" data-a="print">${MF.ic('printer')} Print</button>`];
    if (MF.can('sell.create')) acts.push(`<a class="btn" href="${MF.url('sell', { again: id })}">${MF.ic('refresh')} Sell again</a>`);
    if (+s.balance > 0 && s.customer_id && MF.can('debts.collect')) acts.push(`<button class="btn btn-primary" data-a="pay">${MF.ic('wallet')} Receive payment</button>`);
    if (s.status !== 'voided' && s.status !== 'returned' && MF.can('sales.return')) acts.push(`<button class="btn" data-a="return">${MF.ic('swap')} Return items</button>`);
    if (s.status === 'completed' && MF.can('sales.void')) acts.push(`<button class="btn btn-ghost text-danger" data-a="void">${MF.ic('x')} Void</button>`);
    $('#acts').innerHTML = acts.join('');
  }
  await load();
  $('#acts').addEventListener('click', async e => {
    const a = e.target.closest('[data-a]')?.dataset.a; if (!a) return;
    const s = d.sale;
    if (a === 'print') MF.printMenu('receipt', id);
    if (a === 'pay') MF.receivePayment({ id: s.customer_id, name: s.customer, debt: +s.balance }, { saleId: id, amount: +s.balance, onDone: load });
    if (a === 'void') {
      const reason = await MF.ask('Reason for voiding ' + s.invoice_no, { title: 'Void sale', ok: 'Void sale' });
      if (reason) { await MF.api('sales.void', { id, reason }); MF.toast('Sale voided — stock restored', 'ok'); load(); }
    }
    if (a === 'return') {
      const rows = d.items.filter(i => +i.qty > +i.returned_qty);
      MF.modal({ title: 'Return items · ' + s.invoice_no, wide: true,
        body: `<table class="table"><thead><tr><th>Item</th><th class="num">Sold</th><th class="num">Returnable</th><th class="num">Return qty</th></tr></thead><tbody>
          ${rows.map(i => `<tr><td>${esc(i.name)}</td><td class="num">${MF.qty(i.qty)}</td><td class="num">${MF.qty(i.qty - i.returned_qty)}</td><td class="num"><input type="number" step="any" min="0" max="${i.qty - i.returned_qty}" value="0" data-ret="${i.id}" style="max-width:100px"></td></tr>`).join('')}</tbody></table>
          <div class="form-grid mt">
            <div class="field"><label>Refund method</label><select name="refund_method"><option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="bank">Bank</option><option value="none">No refund (store credit)</option></select><div class="hint">Any unpaid balance is reduced first.</div></div>
            <div class="field"><label class="check" style="margin-top:28px"><input type="checkbox" name="restock" checked> Put items back in stock</label></div>
            <div class="field full"><label>Reason</label><input name="reason" placeholder="Damaged, wrong size…"></div></div>`,
        foot: [{ label: 'Cancel', cls: 'btn-ghost' }, { label: 'Create credit note', cls: 'btn-primary', onClick: async api => {
          const items = [...api.body.querySelectorAll('[data-ret]')].map(i => ({ sale_item_id: +i.dataset.ret, qty: +i.value })).filter(i => i.qty > 0);
          const f = MF.formData(api.body);
          const r = await MF.api('sales.return', { sale_id: id, items, refund_method: f.refund_method, restock: f.restock, reason: f.reason });
          MF.toast(`${r.cn_no} created${r.refund ? ' · refund ' + money(r.refund) : ''}`, 'ok'); load(); MF.printMenu('credit', r.id);
        } }] });
    }
  });
});
</script>
