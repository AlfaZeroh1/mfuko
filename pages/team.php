<div class="page-head"><div class="grow"><h2><?= icon('zap') ?> Who's online</h2><p class="muted small" style="margin:0">Live presence — updates every 30 seconds. Green = active in the last 2 minutes.</p></div><span class="badge badge-green" id="cnt"></span></div>
<div class="grid g3" id="team"></div>
<script>
MF.ready(() => {
  const { $, esc, money } = MF;
  async function load() {
    const rows = await MF.get('admin.team', null, { quiet: true }).catch(() => null); if (!rows) return;
    const state = u => u.idle === null ? 'off' : u.idle < 120 ? 'on' : u.idle < 900 ? 'away' : 'off';
    $('#cnt').textContent = rows.filter(u => state(u) === 'on').length + ' online';
    $('#team').innerHTML = rows.map(u => { const s = state(u); return `<div class="card card-pad" style="${s === 'off' ? 'opacity:.65' : ''}">
      <div class="row nowrap"><span class="avatar-wrap">${u.avatar ? `<img class="avatar md" src="${esc(u.avatar)}">` : `<span class="avatar md">${esc(u.first_name[0])}</span>`}<i class="presence ${s}"></i></span>
      <div class="grow"><b>${esc(u.first_name + ' ' + (u.last_name || ''))}</b><div class="tiny muted">${esc(u.role)} · @${esc(u.username)}</div></div>
      <span class="badge ${s === 'on' ? 'badge-green' : s === 'away' ? 'badge-gold' : ''}">${s === 'on' ? 'online' : s === 'away' ? 'away' : 'offline'}</span></div>
      <div class="small mt">${s !== 'off' ? `On <b>${esc(u.last_page || '—')}</b>` : 'Last seen ' + esc(u.last_seen_at || 'never')}</div>
      <div class="small muted">${u.sales_today} sales · ${money(u.revenue_today)} today</div>
      ${u.last_action ? `<div class="tiny muted mt">Last: ${esc(u.last_action)}</div>` : ''}
      <div class="tiny muted">IP ${esc(u.last_ip || '—')}</div>
      ${MF.can('audit.view') ? `<a class="btn btn-sm btn-ghost mt" href="${MF.url('audit', { user: u.id })}">${MF.ic('history', 'sm')} Activity</a>` : ''}</div>`; }).join('');
  }
  load(); setInterval(() => { if (!document.hidden) load(); }, 30000);
});
</script>
