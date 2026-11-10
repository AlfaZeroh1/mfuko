<div class="page-head"><div class="grow"><h2>Roles & permissions</h2><p class="muted small" style="margin:0">Each role chooses its permissions, its dashboard and the page users land on after signing in (cashiers go straight to Sell).</p></div><button class="btn btn-primary" id="newBtn"><?= icon('plus') ?> New role</button></div>
<div class="grid g3" id="roles"></div>
<script>
MF.ready(() => {
  const { $, esc } = MF;
  let data;
  const dashNames = { admin: 'Owner / manager', cashier: 'Cashier', storekeeper: 'Storekeeper', accountant: 'Accountant' };
  async function load() {
    data = await MF.get('admin.roles');
    $('#roles').innerHTML = data.roles.map((r, i) => `<div class="card card-pad"><div class="row"><h3 class="grow">${MF.ic('shield', 'sm')} ${esc(r.name)}</h3><span class="badge">${r.users} users</span></div>
      <p class="muted small">${esc(r.description || '')}</p><div class="small">Dashboard: <b>${dashNames[r.dashboard]}</b> · Home: <b>${esc(r.home_page)}</b></div>
      <div class="small muted mt">${r.perms.includes('*') ? 'All permissions' : r.perms.length + ' permissions'}</div>
      <div class="row mt"><button class="btn btn-sm" data-e="${i}">${MF.ic('edit', 'sm')} Edit</button>${+r.is_system ? '' : `<button class="btn btn-sm btn-ghost text-danger" data-d="${r.id}">${MF.ic('trash', 'sm')}</button>`}</div></div>`).join('');
  }
  function edit(r = { perms: [], dashboard: 'cashier', home_page: 'sell' }) {
    const owner = r.perms.includes('*');
    const groups = Object.entries(data.groups).map(([g, perms]) => `<div class="mb"><div class="row"><b class="grow">${esc(g)}</b><button type="button" class="btn btn-sm btn-ghost" data-all="${esc(g)}">toggle all</button></div>
      <div class="grid g2" data-g="${esc(g)}">${Object.entries(perms).map(([k, l]) => `<label class="check small"><input type="checkbox" name="p" value="${k}" ${owner || r.perms.includes(k) ? 'checked' : ''} ${owner ? 'disabled' : ''}> ${esc(l)}</label>`).join('')}</div></div>`).join('');
    MF.modal({ title: r.id ? 'Edit role' : 'New role', wide: true, body: `<form onsubmit="return false"><div class="form-grid">
      <div class="field"><label>Name</label><input name="name" value="${esc(r.name || '')}" required></div>
      <div class="field"><label>Description</label><input name="description" value="${esc(r.description || '')}"></div>
      <div class="field"><label>Dashboard</label><select name="dashboard">${Object.entries(dashNames).map(([k, v]) => `<option value="${k}" ${r.dashboard === k ? 'selected' : ''}>${v}</option>`).join('')}</select></div>
      <div class="field"><label>Lands on after sign-in</label><select name="home_page">${['dashboard', 'sell', 'sales', 'products', 'stock', 'customers', 'reports'].map(k => `<option ${r.home_page === k ? 'selected' : ''}>${k}</option>`).join('')}</select></div></div>
      ${owner ? '<div class="alert alert-gold">The owner role always has every permission.</div>' : ''}<hr>${groups}</form>`,
      foot: [{ label: 'Cancel', cls: 'btn-ghost' }, { label: 'Save role', cls: 'btn-primary', onClick: async api => {
        const f = $('form', api.body); if (!f.reportValidity()) return false;
        const body = MF.formData(f); body.perms = [...f.querySelectorAll('[name=p]:checked')].map(c => c.value); body.id = r.id || 0;
        await MF.api('admin.role_save', body); MF.toast('Role saved', 'ok'); load();
      } }],
      onOpen: api => api.body.querySelectorAll('[data-all]').forEach(b => b.onclick = () => { const boxes = api.body.querySelectorAll(`[data-g="${b.dataset.all}"] input:not(:disabled)`); const on = ![...boxes].every(x => x.checked); boxes.forEach(x => x.checked = on); }) });
  }
  $('#roles').addEventListener('click', async e => {
    const ed = e.target.closest('[data-e]'); if (ed) edit(data.roles[+ed.dataset.e]);
    const d = e.target.closest('[data-d]'); if (d && await MF.confirm('Delete this role?', { danger: true })) { await MF.api('admin.role_delete', { id: d.dataset.d }); load(); }
  });
  $('#newBtn').onclick = () => edit();
  load();
});
</script>
