<div class="page-head"><div class="grow"><h2><?= icon('history') ?> Audit trail</h2><p class="muted small" style="margin:0">Every sign-in, sale, void, price change, payment, print and setting change — who, what, when and from where.</p></div></div>
<div class="card">
  <div class="card-head" style="flex-wrap:wrap">
    <div class="search-box grow" style="min-width:200px"><?= icon('search') ?><input type="search" id="q" placeholder="Search summary or record id…"></div>
    <select id="user" style="max-width:160px"><option value="">All users</option></select>
    <select id="action" style="max-width:170px"><option value="">All actions</option></select>
    <select id="entity" style="max-width:150px"><option value="">All records</option></select>
    <input type="date" id="from" style="max-width:150px"><input type="date" id="to" style="max-width:150px">
  </div>
  <div id="tbl"></div>
</div>
<script>
MF.ready(async () => {
  const { $, esc } = MF;
  const meta = await MF.get('admin.audit_meta');
  $('#user').insertAdjacentHTML('beforeend', meta.users.map(u => `<option value="${u.id}">${esc(u.name)}</option>`).join(''));
  $('#action').insertAdjacentHTML('beforeend', meta.actions.map(a => `<option>${esc(a)}</option>`).join(''));
  $('#entity').insertAdjacentHTML('beforeend', meta.entities.map(a => `<option>${esc(a)}</option>`).join(''));
  const qs = new URLSearchParams(location.search);
  if (qs.get('user')) $('#user').value = qs.get('user'); if (qs.get('action')) $('#action').value = qs.get('action');
  const tone = a => /void|delete|fail|deactivate|remove/.test(a) ? 'danger' : /create|login|payment/.test(a) ? 'green' : /update|adjust|settings/.test(a) ? 'gold' : '';
  const t = MF.table($('#tbl'), { action: 'admin.audit', search: $('#q'), pageSize: 50, emptyIcon: 'history',
    params: () => ({ user: $('#user').value, action: $('#action').value, entity: $('#entity').value, from: $('#from').value, to: $('#to').value }),
    onRow: r => (r.old_values || r.new_values) && MF.modal({ title: r.summary || r.action, wide: true, body: `<div class="grid g2"><div><b>Before</b><pre class="ai-answer mono">${esc(JSON.stringify(JSON.parse(r.old_values || 'null'), null, 2))}</pre></div><div><b>After</b><pre class="ai-answer mono">${esc(JSON.stringify(JSON.parse(r.new_values || 'null'), null, 2))}</pre></div></div><p class="small muted">${esc(r.ip)} · ${esc(r.user_agent || '')}</p>`, foot: false }),
    columns: [
      { key: 'created_at', label: 'When', render: r => `<span class="mono small">${esc(r.created_at)}</span>` },
      { key: 'user', label: 'User', render: r => esc(r.user || 'system') },
      { key: 'action', label: 'Action', render: r => `<span class="badge badge-${tone(r.action)}">${esc(r.action)}</span>` },
      { key: 'entity', label: 'Record', render: r => r.entity ? `${esc(r.entity)} <span class="muted">#${esc(r.entity_id || '')}</span>` : '' },
      { key: 'summary', label: 'Details', render: r => esc(r.summary || '') + (r.old_values || r.new_values ? ' ' + MF.ic('eye', 'sm') : '') },
      { key: 'ip', label: 'IP', render: r => `<span class="tiny muted">${esc(r.ip || '')}</span>` },
    ] });
  ['#user', '#action', '#entity', '#from', '#to'].forEach(s => $(s).onchange = () => t.reload(true));
});
</script>
