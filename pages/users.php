<div class="page-head"><h2 class="grow">Users</h2><button class="btn btn-primary" id="newBtn"><?= icon('plus') ?> New user</button></div>
<div class="card"><div class="table-wrap"><table class="table responsive"><thead><tr><th>User</th><th>Role</th><th>Store</th><th>Status</th><th>Last seen</th><th class="num">Daily target</th><th></th></tr></thead><tbody id="rows"></tbody></table></div></div>
<script>
MF.ready(() => {
  const { $, esc, money } = MF;
  let data;
  const ago = s => { if (!s) return 'never'; const d = (Date.now() - new Date(s.replace(' ', 'T')).getTime()) / 1000; return d < 120 ? '<span class="badge badge-green">online</span>' : d < 3600 ? Math.round(d / 60) + 'm ago' : d < 86400 ? Math.round(d / 3600) + 'h ago' : s.slice(0, 10); };
  async function load() {
    data = await MF.get('admin.users');
    $('#rows').innerHTML = data.rows.map((u, i) => `<tr><td data-label="User"><div class="cell-flex">${u.avatar ? `<img class="avatar" src="${esc(u.avatar)}">` : `<span class="avatar">${esc(u.first_name[0])}</span>`}<div><b>${esc(u.first_name + ' ' + (u.last_name || ''))}</b><div class="tiny muted">@${esc(u.username)} ${u.has_pin ? '· PIN' : ''} ${u.email ? '· ' + esc(u.email) : ''}</div></div></div></td>
      <td data-label="Role"><span class="badge badge-indigo">${esc(u.role)}</span></td><td data-label="Store">${esc(u.store || '—')}</td>
      <td data-label="Status"><span class="badge badge-${u.status === 'active' ? 'green' : 'danger'}">${esc(u.status)}</span></td><td data-label="Last seen">${ago(u.last_seen_at)}</td>
      <td class="num" data-label="Target">${+u.daily_target ? money(u.daily_target, false) : '—'}</td><td class="actions"><button class="btn btn-sm btn-ghost" data-e="${i}">${MF.ic('edit', 'sm')}</button></td></tr>`).join('');
  }
  function edit(u = {}) {
    const st = (u.stores || '').split(',');
    MF.modal({ title: u.id ? 'Edit user' : 'New user', wide: true, body: `<form class="form-grid" onsubmit="return false">
      <div class="field"><label>First name *</label><input name="first_name" value="${esc(u.first_name || '')}" required></div>
      <div class="field"><label>Last name</label><input name="last_name" value="${esc(u.last_name || '')}"></div>
      <div class="field"><label>Username *</label><input name="username" value="${esc(u.username || '')}" autocapitalize="none" required></div>
      <div class="field"><label>Role *</label><select name="role_id">${data.roles.map(r => `<option value="${r.id}" ${+u.role_id === +r.id ? 'selected' : ''}>${esc(r.name)}</option>`).join('')}</select></div>
      <div class="field"><label>Email</label><input name="email" type="email" value="${esc(u.email || '')}"></div>
      <div class="field"><label>Phone</label><input name="phone" value="${esc(u.phone || '')}"></div>
      <div class="field"><label>${u.id ? 'New password (leave blank to keep)' : 'Password *'}</label><input name="password" type="password" autocomplete="new-password" ${u.id ? '' : 'required'}></div>
      <div class="field"><label>Quick PIN (4–8 digits, optional)</label><input name="pin" inputmode="numeric" maxlength="8" placeholder="${u.has_pin ? 'PIN set — type to change' : ''}"></div>
      <div class="field"><label>Default store</label><select name="default_store_id"><option value="">—</option>${data.stores.map(s => `<option value="${s.id}" ${+u.default_store_id === +s.id ? 'selected' : ''}>${esc(s.name)}</option>`).join('')}</select></div>
      <div class="field"><label>Daily sales target</label><input name="daily_target" type="number" step="0.01" value="${u.daily_target || 0}"></div>
      <div class="field full"><label>Can work in stores</label><div class="row">${data.stores.map(s => `<label class="check"><input type="checkbox" data-store="${s.id}" ${st.includes(String(s.id)) ? 'checked' : ''}> ${esc(s.name)}</label>`).join('')}</div></div>
      <div class="field"><label>Status</label><select name="status"><option value="active">Active</option><option value="suspended" ${u.status === 'suspended' ? 'selected' : ''}>Suspended (signs out immediately)</option></select></div></form>`,
      foot: [{ label: 'Cancel', cls: 'btn-ghost' }, { label: 'Save user', cls: 'btn-primary', onClick: async api => {
        const f = $('form', api.body); if (!f.reportValidity()) return false;
        const body = MF.formData(f); body.id = u.id || 0; body.stores = [...api.body.querySelectorAll('[data-store]:checked')].map(c => c.dataset.store);
        await MF.api('admin.user_save', body); MF.toast('User saved', 'ok'); load();
      } }] });
  }
  $('#rows').addEventListener('click', e => { const b = e.target.closest('[data-e]'); if (b) edit(data.rows[+b.dataset.e]); });
  $('#newBtn').onclick = () => edit();
  load();
});
</script>
