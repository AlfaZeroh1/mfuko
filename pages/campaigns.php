<?php
$coupons = DB::all('SELECT id, code, type, value FROM coupons WHERE is_active=1 ORDER BY id DESC');
$prefill = $_GET['audience'] ?? '';
?>
<div class="page-head">
  <div class="grow"><h2><?= icon('megaphone') ?> Campaigns</h2><p class="muted small" style="margin:0">Email or SMS offers to customer segments. Attach a coupon and track redemptions at the till.</p></div>
  <a class="btn" href="<?= url('coupons') ?>"><?= icon('ticket') ?> Coupons</a>
  <button class="btn btn-primary" id="newBtn"><?= icon('plus') ?> New campaign</button>
</div>
<div class="card"><div id="tbl"></div></div>
<script>
MF.ready(() => {
  const { $, esc, money } = MF;
  const coupons = <?= json_encode($coupons) ?>;
  let audiences = {};
  const templates = {
    offer: { subject: '{offer} just for you, {name} 🎁', body: 'Hello {name},\n\nThank you for shopping with {business}. As a valued customer, enjoy **{offer}** on your next visit.\n\nUse code: **{coupon}** at the counter.\n\nKaribu tena!\n{business} · {phone}' },
    winback: { subject: 'We miss you at {business}', body: 'Hi {name},\n\nIt has been a while! Come see what is new at {business}. Show this message and use code **{coupon}** for {offer}.\n\nSee you soon,\n{business}' },
    news: { subject: 'New stock has arrived at {business}', body: 'Hello {name},\n\nFresh stock just landed. Pass by or call us on {phone} to reserve yours.\n\n{business}' },
  };
  const t = MF.table($('#tbl'), { action: 'comms.campaigns', emptyIcon: 'megaphone', empty: 'No campaigns yet — create your first one',
    onLoad: d => { audiences = d.audiences; },
    columns: [
      { key: 'name', label: 'Campaign', render: r => `<b>${esc(r.name)}</b><div class="tiny muted">${esc(r.subject || '')}</div>` },
      { key: 'channel', label: 'Channel', render: r => `<span class="badge badge-${r.channel === 'sms' ? 'info' : 'indigo'}">${esc(r.channel)}</span>` },
      { key: 'audience', label: 'Audience', render: r => esc(audiences[r.audience] || r.audience) },
      { key: 'coupon', label: 'Coupon', render: r => r.coupon ? `<code>${esc(r.coupon)}</code>` : '—' },
      { key: 'status', label: 'Status', render: r => `<span class="badge badge-${r.status === 'draft' ? 'gold' : 'green'}">${esc(r.status)}</span>` },
      { key: 'sent', label: 'Delivered', num: true, render: r => `${r.sent} / ${r.recipients}` },
      { key: 'redemptions', label: 'Redeemed', num: true, render: r => r.coupon ? `<b>${r.redemptions}</b>` : '—' },
      { key: '_a', label: '', cls: 'actions', render: r => `<button class="btn btn-sm btn-ghost" data-act="prev">${MF.ic('eye', 'sm')}</button>` + (r.status === 'draft' ? `<button class="btn btn-sm btn-ghost" data-act="edit">${MF.ic('edit', 'sm')}</button><button class="btn btn-sm btn-primary" data-act="send">${MF.ic('send', 'sm')} Send</button>` : '') },
    ],
    onAction: (a, r) => { if (a === 'prev') preview(r.id); if (a === 'edit') edit(r); if (a === 'send') send(r); } });

  function edit(c = {}) {
    const audOpts = Object.entries(audiences).map(([k, v]) => `<option value="${k}" ${(c.audience || '<?= e($prefill) ?>' || 'all') === k ? 'selected' : ''}>${esc(v)}</option>`).join('');
    MF.modal({ title: c.id ? 'Edit campaign' : 'New campaign', wide: true, body: `<form class="form-grid" onsubmit="return false">
      <div class="field"><label>Name</label><input name="name" value="${esc(c.name || '')}" required placeholder="December festive offer"></div>
      <div class="field"><label>Channel</label><select name="channel"><option value="email">Email</option><option value="sms" ${c.channel === 'sms' ? 'selected' : ''}>SMS</option></select></div>
      <div class="field"><label>Audience</label><select name="audience">${audOpts}</select></div>
      <div class="field"><label>Customer IDs (for hand-picked)</label><input name="audience_ids" value="${esc(c.audience_ids || '')}" placeholder="12,45,78"></div>
      <div class="field"><label>Coupon</label><select name="coupon_id"><option value="">None</option>${coupons.map(x => `<option value="${x.id}" ${+c.coupon_id === +x.id ? 'selected' : ''}>${esc(x.code)} (${x.type === 'percent' ? +x.value + '%' : money(x.value)})</option>`).join('')}<option value="new">+ Create new coupon…</option></select></div>
      <div class="field hide" data-nc><label>New coupon code / value</label><div class="input-group"><input name="new_coupon_code" placeholder="XMAS15" style="text-transform:uppercase"><input name="new_coupon_value" type="number" value="10" style="max-width:80px"><select name="new_coupon_type" style="max-width:90px"><option value="percent">%</option><option value="fixed">KSh</option></select></div></div>
      <div class="field full"><label>Template</label><div class="row">${Object.keys(templates).map(k => `<button type="button" class="btn btn-sm" data-tpl="${k}">${k}</button>`).join('')}</div></div>
      <div class="field full" data-subj><label>Subject</label><input name="subject" value="${esc(c.subject || '')}"></div>
      <div class="field full"><label>Message</label><textarea name="body" rows="9" required>${esc(c.body || '')}</textarea><div class="hint">Placeholders: {name} {business} {phone} {coupon} {offer} {balance} {pay_accounts} · **bold**</div></div></form>`,
      foot: [{ label: 'Cancel', cls: 'btn-ghost' }, { label: 'Save draft', cls: 'btn-primary', onClick: async api => {
        const f = MF.formData($('form', api.body)); if (f.coupon_id === 'new') f.coupon_id = ''; else { delete f.new_coupon_code; }
        const r = await MF.api('comms.campaign_save', { ...f, id: c.id || 0 }); MF.toast(`Saved · ${r.recipients} recipients`, 'ok'); t.reload(); preview(r.id);
      } }],
      onOpen: api => {
        const f = $('form', api.body);
        f.coupon_id.onchange = () => $('[data-nc]', api.body).classList.toggle('hide', f.coupon_id.value !== 'new');
        const ch = () => $('[data-subj]', api.body).classList.toggle('hide', f.channel.value === 'sms'); f.channel.onchange = ch; ch();
        api.body.querySelectorAll('[data-tpl]').forEach(b => b.onclick = () => { const tp = templates[b.dataset.tpl]; f.subject.value = tp.subject; f.body.value = f.channel.value === 'sms' ? tp.body.replace(/\*\*/g, '').replace(/\n+/g, ' ') : tp.body; });
      } });
  }
  async function preview(id) {
    const p = await MF.get('comms.campaign_preview', { id });
    MF.modal({ title: 'Preview', wide: true, body: `<p><b>${p.recipients}</b> recipients${p.sample.length ? ': ' + p.sample.map(esc).join(', ') + (p.recipients > p.sample.length ? '…' : '') : ''}</p>
      ${p.subject ? `<p class="small muted">Subject: <b>${esc(p.subject)}</b></p>` : ''}<iframe style="width:100%;height:440px;border:1px solid var(--border);border-radius:12px;background:#fff" srcdoc="${esc(p.html)}"></iframe>`, foot: false });
  }
  async function send(c) {
    if (!await MF.confirm(`Send "${c.name}" now? Messages are queued and delivered in the background.`, { ok: 'Send campaign' })) return;
    const r = await MF.api('comms.campaign_send', { id: c.id }); MF.toast(`${r.queued} queued · ${r.sent} sent so far`, 'ok'); t.reload();
  }
  $('#newBtn').onclick = () => edit();
  <?php if ($prefill): ?>setTimeout(() => edit({ audience: <?= json_encode($prefill) ?> }), 400);<?php endif; ?>
});
</script>
