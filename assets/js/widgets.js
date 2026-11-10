/* Shared business widgets: receive debt payment, compose message, reminders. */
(function () {
  'use strict';
  const { $, esc, money, num } = MF;
  let accounts = null;
  async function payAccounts() { if (!accounts) accounts = await MF.get('resource.options', { t: 'payment_accounts' }).catch(() => []); return accounts; }

  /** Receive a payment against a customer's debt (FIFO or one invoice). */
  MF.receivePayment = async function (customer, { saleId = null, amount = null, onDone = null } = {}) {
    const accs = await payAccounts();
    const owed = amount ?? customer.debt;
    MF.modal({
      title: 'Receive payment · ' + customer.name,
      body: `<div class="pay-due" style="display:flex;justify-content:space-between;padding:14px 16px;border-radius:16px;background:linear-gradient(135deg,var(--indigo),#47308f);color:#fff;margin-bottom:14px"><span>${saleId ? 'Invoice balance' : 'Total owed'}</span><b style="font-size:1.6rem">${money(owed)}</b></div>
        <div class="form-grid">
          <div class="field"><label>Amount received</label><input type="number" inputmode="decimal" step="0.01" min="0" name="amount" value="${owed}" required></div>
          <div class="field"><label>Method</label><select name="account">
            <option value="m:cash">Cash</option>
            ${accs.map(a => `<option value="a:${a.id}">${esc(a.name)}</option>`).join('')}
            <option value="m:mpesa">M-Pesa (other)</option><option value="m:bank">Bank (other)</option></select></div>
          <div class="field full"><label>Reference / M-Pesa code</label><input name="reference" style="text-transform:uppercase" placeholder="Optional for cash"></div>
        </div>
        <p class="small muted">${saleId ? 'Applied to this invoice.' : 'Applied to the oldest invoices first.'} A receipt is generated automatically.</p>`,
      foot: [{ label: 'Cancel', cls: 'btn-ghost' }, { label: 'Record payment', cls: 'btn-primary', icon: 'check', onClick: async api => {
        const f = MF.formData(api.body);
        const [k, v] = f.account.split(':');
        const r = await MF.api('customers.pay', { customer_id: customer.id, sale_id: saleId, amount: num(f.amount), method: k === 'm' ? v : 'cash', account_id: k === 'a' ? v : null, reference: f.reference.toUpperCase() });
        MF.toast(`Received ${money(f.amount)} · ${r.receipt_no}`, 'ok');
        MF.modal({ title: 'Payment recorded', body: `<div class="done" style="text-align:center"><h3>${esc(r.receipt_no)}</h3><p class="muted">Remaining balance ${money(r.remaining)}</p></div>`,
          foot: [{ label: 'Close', cls: 'btn-ghost' }, { label: 'Print receipt', cls: 'btn-primary', icon: 'printer', onClick: () => MF.printMenu('payment', r.receipt_no) }] });
        onDone && onDone(r);
      } }],
    });
  };

  /** Compose a one-off email/SMS to a customer (or any address). */
  MF.compose = function ({ customerId = null, to = '', name = '', subject = '', body = '', channel = 'email', onDone = null } = {}) {
    MF.modal({
      title: 'New message', wide: true,
      body: `<div class="form-grid">
        <div class="field"><label>Channel</label><select name="channel"><option value="email">Email</option><option value="sms" ${channel === 'sms' ? 'selected' : ''}>SMS</option></select></div>
        <div class="field"><label>To</label><input name="to" value="${esc(to)}" placeholder="email or phone" required></div>
        <div class="field full" data-subj><label>Subject</label><input name="subject" value="${esc(subject)}"></div>
        <div class="field full"><label>Message</label><textarea name="body" rows="8" required>${esc(body)}</textarea>
          <div class="hint">Placeholders: {name} {business} {balance} {pay_accounts}</div></div></div>`,
      foot: [{ label: 'Cancel', cls: 'btn-ghost' }, { label: 'Send', cls: 'btn-primary', icon: 'send', onClick: async api => {
        const f = MF.formData(api.body);
        await MF.api('comms.send', { ...f, customer_id: customerId, name });
        MF.toast('Message queued and sending…', 'ok'); onDone && onDone();
      } }],
      onOpen: api => {
        const ch = $('[name=channel]', api.body);
        const tog = () => $('[data-subj]', api.body).classList.toggle('hide', ch.value === 'sms');
        ch.onchange = tog; tog();
      },
    });
  };

  /** Send debt reminders to one or many customers. */
  MF.sendReminders = async function (ids, onDone) {
    if (!ids.length) return MF.toast('Select at least one customer', 'warn');
    if (!await MF.confirm(`Send a payment reminder to ${ids.length} customer(s)? Email is used where available, otherwise SMS.`, { ok: 'Send reminders' })) return;
    const r = await MF.api('comms.debt_reminders', { ids });
    MF.toast(`${r.queued} reminder(s) queued${r.skipped ? ', ' + r.skipped + ' without contact' : ''}`, 'ok');
    onDone && onDone(r);
  };
})();
