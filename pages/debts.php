<?php $scripts[] = 'js/widgets.js'; ?>
<div class="grid g4 mb" id="aging">
  <div class="card kpi danger"><div class="label">Total receivable</div><div class="value" data-b="debt">—</div><div class="small muted" data-b="count"></div></div>
  <div class="card kpi green"><div class="label">0–30 days</div><div class="value" data-b="b0">—</div></div>
  <div class="card kpi gold"><div class="label">31–60 days</div><div class="value" data-b="b30">—</div></div>
  <div class="card kpi"><div class="label">61+ days</div><div class="value" data-b="b60">—</div><div class="small text-danger" data-b="b90"></div></div>
</div>
<div class="page-head">
  <h2 class="grow">Debtors</h2>
  <?php if (Auth::can('comms.send')): ?><button class="btn btn-soft" id="remindSel"><?= icon('bell') ?> Remind selected</button><?php endif; ?>
  <a class="btn" href="print.php?doc=debtors&id=0&size=a4" target="_blank"><?= icon('printer') ?> Print list</a>
</div>
<div class="card">
  <div class="card-head"><div class="search-box grow"><?= icon('search') ?><input type="search" id="q" placeholder="Search debtor…"></div></div>
  <div class="table-wrap"><table class="table responsive"><thead><tr><th><input type="checkbox" id="all"></th><th>Customer</th><th class="num">Invoices</th><th>Oldest</th><th class="num">0–30</th><th class="num">31–60</th><th class="num">61–90</th><th class="num">90+</th><th class="num">Total</th><th>Reminded</th><th></th></tr></thead><tbody id="rows"></tbody></table></div>
</div>
<script>
MF.ready(() => {
  const { $, $$, esc, money } = MF;
  let rows = [];
  async function load() {
    const d = await MF.get('customers.debtors', { q: $('#q').value });
    rows = d.rows;
    $('[data-b=debt]').textContent = money(d.totals.debt); $('[data-b=count]').textContent = d.total + ' customers';
    $('[data-b=b0]').textContent = money(d.totals.b0); $('[data-b=b30]').textContent = money(d.totals.b30);
    $('[data-b=b60]').textContent = money(+d.totals.b60 + +d.totals.b90); $('[data-b=b90]').textContent = money(d.totals.b90) + ' over 90 days';
    $('#rows').innerHTML = rows.length ? rows.map((r, i) => {
      const days = Math.floor((Date.now() - new Date(r.oldest.replace(' ', 'T')).getTime()) / 864e5);
      return `<tr><td data-label="Select"><input type="checkbox" data-sel="${r.id}"></td>
      <td data-label="Customer"><a href="${MF.url('customer', { id: r.id })}"><b>${esc(r.name)}</b></a><div class="tiny muted">${esc(r.phone || '')} ${r.email ? '· ' + esc(r.email) : ''}</div></td>
      <td class="num" data-label="Invoices">${r.invoices}</td><td data-label="Oldest"><span class="badge ${days > 60 ? 'badge-danger' : days > 30 ? 'badge-gold' : ''}">${days} days</span></td>
      <td class="num" data-label="0–30">${+r.b0 ? money(r.b0, false) : '—'}</td><td class="num" data-label="31–60">${+r.b30 ? money(r.b30, false) : '—'}</td>
      <td class="num" data-label="61–90">${+r.b60 ? money(r.b60, false) : '—'}</td><td class="num" data-label="90+">${+r.b90 ? `<b class="text-danger">${money(r.b90, false)}</b>` : '—'}</td>
      <td class="num" data-label="Total"><b>${money(r.debt)}</b>${+r.credit_limit > 0 && +r.debt > +r.credit_limit ? '<div class="tiny text-danger">over limit</div>' : ''}</td>
      <td data-label="Reminded" class="small muted">${r.last_reminder_at ? esc(r.last_reminder_at.slice(0, 10)) : 'never'}</td>
      <td class="actions">${MF.can('debts.collect') ? `<button class="btn btn-sm btn-primary" data-pay="${i}">Receive</button>` : ''}${MF.can('comms.send') ? `<button class="btn btn-sm btn-ghost" data-rem="${r.id}" title="Remind">${MF.ic('bell', 'sm')}</button>` : ''}</td></tr>`;
    }).join('') : `<tr><td colspan="11"><div class="empty">${MF.ic('check')}<h3>No debts 🎉</h3><div>Everyone is paid up.</div></div></td></tr>`;
  }
  load();
  $('#q').addEventListener('input', MF.debounce(load, 250));
  $('#all').onchange = e => $$('[data-sel]').forEach(c => c.checked = e.target.checked);
  $('#rows').addEventListener('click', e => {
    const p = e.target.closest('[data-pay]'); if (p) { const r = rows[+p.dataset.pay]; MF.receivePayment({ id: r.id, name: r.name, debt: +r.debt, phone: r.phone }, { onDone: load }); }
    const m = e.target.closest('[data-rem]'); if (m) MF.sendReminders([+m.dataset.rem], load);
  });
  $('#remindSel') && ($('#remindSel').onclick = () => MF.sendReminders($$('[data-sel]:checked').map(c => +c.dataset.sel), load));
});
</script>
