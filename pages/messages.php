<?php $scripts[] = 'js/widgets.js'; ?>
<div class="page-head">
  <div class="grow"><h2><?= icon('mail') ?> Messages</h2><p class="muted small" style="margin:0">Every email and SMS the system sends: receipts, reminders, campaigns and manual messages.</p></div>
  <button class="btn" id="procBtn"><?= icon('refresh') ?> Send pending / retry failed</button>
  <button class="btn btn-primary" id="newBtn"><?= icon('plus') ?> New message</button>
</div>
<div class="row mb" id="stats"></div>
<div class="card">
  <div class="card-head"><div class="search-box grow"><?= icon('search') ?><input type="search" id="q" placeholder="Recipient or subject…"></div>
    <select id="st" style="max-width:130px"><option value="">Any status</option><option value="queued">Queued</option><option value="sent">Sent</option><option value="failed">Failed</option></select>
    <select id="kd" style="max-width:150px"><option value="">Any type</option><option value="campaign">Campaign</option><option value="debt_reminder">Debt reminder</option><option value="document">Document</option><option value="manual">Manual</option></select></div>
  <div id="tbl"></div>
</div>
<script>
MF.ready(() => {
  const { $, esc } = MF;
  const t = MF.table($('#tbl'), { action: 'comms.outbox', search: $('#q'), params: () => ({ status: $('#st').value, kind: $('#kd').value }), emptyIcon: 'mail', empty: 'No messages yet',
    onLoad: d => { $('#stats').innerHTML = ['queued', 'sent', 'failed'].map(s => `<span class="badge badge-${s === 'sent' ? 'green' : s === 'failed' ? 'danger' : 'gold'}">${s}: ${d.stats[s] || 0}</span>`).join(''); },
    onRow: async r => { const m = await MF.get('comms.outbox_view', { id: r.id }); MF.modal({ title: m.subject || 'SMS', wide: true, body: m.channel === 'email' ? `<iframe style="width:100%;height:460px;border:1px solid var(--border);border-radius:12px;background:#fff" srcdoc="${esc(m.body.startsWith('<!doctype') ? m.body : m.body)}"></iframe>` : `<div class="ai-answer">${esc(m.body)}</div>`, foot: false }); },
    columns: [
      { key: 'created_at', label: 'When', render: r => esc(r.created_at.slice(0, 16)) },
      { key: 'kind', label: 'Type', render: r => `<span class="badge">${esc(r.kind.replace('_', ' '))}</span> ${MF.ic(r.channel === 'sms' ? 'phone' : 'mail', 'sm')}` },
      { key: 'recipient', label: 'To', render: r => `<b>${esc(r.recipient_name || '')}</b><div class="tiny muted">${esc(r.recipient)}</div>` },
      { key: 'subject', label: 'Subject', render: r => esc(r.subject || '—') },
      { key: 'status', label: 'Status', render: r => `<span class="badge badge-${r.status === 'sent' ? 'green' : r.status === 'failed' ? 'danger' : 'gold'}" title="${esc(r.error || '')}">${esc(r.status)}</span>${r.error ? `<div class="tiny text-danger">${esc(r.error)}</div>` : ''}` },
    ] });
  ['#st', '#kd'].forEach(s => $(s).onchange = () => t.reload(true));
  $('#procBtn').onclick = async () => { const r = await MF.api('comms.process', { retry: 1 }); MF.toast(`${r.sent} sent, ${r.failed} failed, ${r.pending} pending`, r.failed ? 'warn' : 'ok'); t.reload(); };
  $('#newBtn').onclick = () => MF.compose({ subject: 'A message from {business}', body: 'Hello,\n\n', onDone: () => t.reload() });
});
</script>
