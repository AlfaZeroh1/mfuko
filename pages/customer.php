<?php
$id = (int)($_GET['id'] ?? 0);
$c = DB::row('SELECT * FROM customers WHERE id=?', [$id]);
if (!$c) { echo '<div class="empty">' . icon('user') . '<h2>Customer not found</h2></div>'; return; }
$defs = require MF_ROOT . '/app/resources.php';
$def = $defs['customers']; unset($def['table'], $def['search'], $def['computed']);
$scripts[] = 'js/resource.js';
$scripts[] = 'js/widgets.js';
?>
<div class="page-head">
  <a class="btn btn-ghost btn-icon" href="<?= url('customers') ?>"><?= icon('arrow-left') ?></a>
  <?= avatar(['first_name' => $c['name']], 'md') ?>
  <div class="grow"><h2><?= e($c['name']) ?></h2><div class="muted small"><?= e($c['phone'] ?: 'No phone') ?> · <?= e($c['email'] ?: 'No email') ?><?= $c['kra_pin'] ? ' · PIN ' . e($c['kra_pin']) : '' ?></div></div>
  <div class="row">
    <?php if (Auth::can('sell.create')): ?><a class="btn btn-gold" href="<?= url('sell') ?>" id="sellTo"><?= icon('cart') ?> Sell</a><?php endif; ?>
    <?php if (Auth::can('debts.collect')): ?><button class="btn btn-primary" id="payBtn"><?= icon('wallet') ?> Receive payment</button><?php endif; ?>
    <?php if (Auth::can('comms.send')): ?><button class="btn" id="msgBtn"><?= icon('mail') ?> Message</button><?php endif; ?>
    <button class="btn" id="stmtBtn"><?= icon('printer') ?> Statement</button>
    <?php if (Auth::can('customers.manage')): ?><button class="btn btn-ghost btn-icon" id="editBtn" title="Edit"><?= icon('edit') ?></button><?php endif; ?>
  </div>
</div>

<div class="grid g4 mb" id="kpis">
  <div class="card kpi danger"><div class="label">Owes</div><div class="value" data-k="debt">—</div><div class="small muted" data-k="overdue"></div></div>
  <div class="card kpi green"><div class="label">Lifetime spend</div><div class="value" data-k="spent">—</div></div>
  <div class="card kpi indigo"><div class="label">Visits</div><div class="value" data-k="visits">—</div><div class="small muted" data-k="last"></div></div>
  <div class="card kpi gold"><div class="label">Avg basket</div><div class="value" data-k="avg">—</div><div class="small muted">Limit <?= (float)$c['credit_limit'] > 0 ? money($c['credit_limit']) : 'none' ?></div></div>
</div>

<div class="grid g-2-1">
  <div class="stack">
    <div class="card">
      <div class="card-head"><h3 class="grow">Unpaid invoices</h3><?php if (Auth::can('comms.send')): ?><button class="btn btn-sm btn-soft" id="remindBtn"><?= icon('bell', 'sm') ?> Send reminder</button><?php endif; ?></div>
      <div class="table-wrap"><table class="table responsive"><thead><tr><th>Invoice</th><th>Date</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Balance</th><th></th></tr></thead><tbody id="openRows"></tbody></table></div>
    </div>
    <div class="card">
      <div class="card-head"><h3 class="grow">Statement</h3>
        <input type="date" id="sFrom" value="<?= date('Y-m-d', strtotime('-90 days')) ?>" style="max-width:150px"><input type="date" id="sTo" value="<?= date('Y-m-d') ?>" style="max-width:150px">
      </div>
      <div id="stmt"></div>
    </div>
    <div class="card"><div class="card-head"><h3>All sales</h3></div><div id="salesTbl"></div></div>
  </div>
  <div class="stack">
    <div class="card"><div class="card-head"><h3>Favourite products</h3></div><ul class="list" id="topList"></ul></div>
    <div class="card"><div class="card-head"><h3>Messages</h3></div><ul class="list" id="msgList"></ul></div>
    <div class="card card-pad small">
      <div class="row"><span class="muted grow">Price tier</span><b><?= e(ucfirst($c['price_tier'])) ?></b></div>
      <div class="row"><span class="muted grow">Auto discount</span><b><?= (float)$c['discount'] ?>%</b></div>
      <div class="row"><span class="muted grow">Credit days</span><b><?= (int)$c['credit_days'] ?></b></div>
      <div class="row"><span class="muted grow">Offers</span><b><?= $c['marketing_opt_in'] ? 'Opted in' : 'Opted out' ?></b></div>
      <div class="row"><span class="muted grow">Last reminder</span><b><?= e($c['last_reminder_at'] ? time_ago($c['last_reminder_at']) : 'never') ?></b></div>
      <?php if ($c['notes']): ?><hr><p><?= nl2br(e($c['notes'])) ?></p><?php endif; ?>
    </div>
  </div>
</div>
<script>
MF.ready(async () => {
  const { $, esc, money } = MF;
  const id = <?= $id ?>, def = <?= json_encode($def + ['canEdit' => Auth::can('customers.manage')], JSON_HEX_TAG) ?>;
  let prof;
  async function load() {
    prof = await MF.get('customers.profile', { id });
    const s = prof.stats;
    $('[data-k=debt]').textContent = money(s.debt);
    $('[data-k=overdue]').textContent = s.days_overdue ? `oldest ${s.days_overdue} days` : 'all clear';
    $('[data-k=spent]').textContent = money(s.spent);
    $('[data-k=visits]').textContent = s.visits;
    $('[data-k=last]').textContent = s.last_visit ? 'last ' + s.last_visit.slice(0, 10) : '';
    $('[data-k=avg]').textContent = money(s.avg_basket);
    $('#openRows').innerHTML = prof.open.length ? prof.open.map(o => `<tr><td data-label="Invoice"><a href="${MF.url('sale', { id: o.id })}">${esc(o.invoice_no)}</a></td><td data-label="Date">${esc(o.sold_at.slice(0, 10))}</td>
      <td class="num" data-label="Total">${money(o.total, false)}</td><td class="num" data-label="Paid">${money(o.paid, false)}</td><td class="num" data-label="Balance"><b class="text-danger">${money(o.balance, false)}</b></td>
      <td class="actions">${MF.can('debts.collect') ? `<button class="btn btn-sm" data-pay="${o.id}" data-bal="${o.balance}">Pay</button>` : ''}</td></tr>`).join('')
      : `<tr><td colspan="6"><div class="empty">${MF.ic('check')}<div>No outstanding invoices</div></div></td></tr>`;
    $('#topList').innerHTML = prof.top.map(t => `<li><div class="meta"><b>${esc(t.name)}</b><span>${MF.qty(t.qty)} bought</span></div><b>${money(t.total, false)}</b></li>`).join('') || '<li class="muted">No purchases yet</li>';
    $('#msgList').innerHTML = prof.messages.map(m => `<li><div class="meta"><b>${esc(m.subject || m.kind)}</b><span>${esc(m.channel)} · ${esc(m.created_at)}</span></div>${m.status === 'sent' ? '<span class="badge badge-green">sent</span>' : m.status === 'failed' ? '<span class="badge badge-danger">failed</span>' : '<span class="badge">queued</span>'}</li>`).join('') || '<li class="muted">No messages yet</li>';
  }
  async function stmt() {
    const d = await MF.get('customers.statement', { id, from: $('#sFrom').value, to: $('#sTo').value });
    $('#stmt').innerHTML = `<div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>Ref</th><th>Details</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead><tbody>
      <tr><td colspan="5" class="muted">Opening balance</td><td class="num">${money(d.opening, false)}</td></tr>
      ${d.rows.map(r => `<tr><td>${esc(r.date.slice(0, 10))}</td><td>${esc(r.ref || '')}</td><td>${esc(r.desc)}</td><td class="num">${r.debit ? money(r.debit, false) : ''}</td><td class="num">${r.credit ? money(r.credit, false) : ''}</td><td class="num">${money(r.balance, false)}</td></tr>`).join('')}
      </tbody><tfoot><tr><td colspan="3">Closing balance</td><td class="num">${money(d.debit, false)}</td><td class="num">${money(d.credit, false)}</td><td class="num">${money(d.closing, false)}</td></tr></tfoot></table></div>`;
  }
  await load(); stmt();
  $('#sFrom').onchange = stmt; $('#sTo').onchange = stmt;
  MF.table($('#salesTbl'), { action: 'customers.sales', params: () => ({ id }), pageSize: 10, columns: [
    { key: 'invoice_no', label: 'Invoice', render: r => `<a href="${MF.url('sale', { id: r.id })}">${esc(r.invoice_no)}</a>` },
    { key: 'sold_at', label: 'Date', render: r => esc(r.sold_at.slice(0, 16)) },
    { key: 'status', label: 'Status', render: r => `<span class="badge ${r.status === 'voided' ? 'badge-danger' : r.payment_status === 'paid' ? 'badge-green' : 'badge-gold'}">${esc(r.status === 'completed' ? r.payment_status : r.status)}</span>` },
    { key: 'total', label: 'Total', num: true, render: r => money(r.total, false) },
    { key: 'balance', label: 'Balance', num: true, render: r => +r.balance > 0 ? `<b class="text-danger">${money(r.balance, false)}</b>` : '—' },
  ] });
  const cust = () => ({ id, name: prof.customer.name, debt: +prof.stats.debt, phone: prof.customer.phone });
  $('#payBtn') && ($('#payBtn').onclick = () => MF.receivePayment(cust(), { onDone: () => { load(); stmt(); } }));
  document.addEventListener('click', e => { const b = e.target.closest('[data-pay]'); if (b) MF.receivePayment(cust(), { saleId: +b.dataset.pay, amount: +b.dataset.bal, onDone: () => { load(); stmt(); } }); });
  $('#msgBtn') && ($('#msgBtn').onclick = () => MF.compose({ customerId: id, to: prof.customer.email || prof.customer.phone || '', name: prof.customer.name, channel: prof.customer.email ? 'email' : 'sms', subject: 'A note from {business}', body: 'Hello {name},\n\n' }));
  $('#remindBtn') && ($('#remindBtn').onclick = () => MF.sendReminders([id], load));
  $('#stmtBtn').onclick = () => MF.printMenu('statement', id, { from: $('#sFrom').value, to: $('#sTo').value });
  $('#editBtn') && ($('#editBtn').onclick = () => MF.resourceForm('customers', def, prof.customer, () => location.reload()));
  const sellTo = $('#sellTo'); if (sellTo) sellTo.onclick = () => { MF.store.set('mf-draft', { cart: [], customer: { ...cust(), price_tier: prof.customer.price_tier, credit_limit: +prof.customer.credit_limit, discount: +prof.customer.discount }, saleType: prof.customer.price_tier }); };
});
</script>
