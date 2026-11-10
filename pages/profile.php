<?php $u = DB::row('SELECT u.*, r.name role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [Auth::id()]); ?>
<div class="grid g-2-1">
  <div class="stack">
    <div class="card card-pad">
      <div class="row" style="gap:18px">
        <div style="text-align:center"><div id="av"><?= avatar($u, 'lg') ?></div>
          <label class="btn btn-sm btn-soft mt" style="margin-top:8px"><?= icon('camera', 'sm') ?> Change<input type="file" accept="image/*" id="avIn" hidden></label>
          <?php if ($u['avatar']): ?><button class="btn btn-sm btn-ghost" id="avRm">Remove</button><?php endif; ?></div>
        <div class="grow"><h2 style="margin:0"><?= e(full_name($u)) ?></h2><div class="muted">@<?= e($u['username']) ?> · <?= e($u['role']) ?></div>
          <div class="small muted mt">Member since <?= e(fdate($u['created_at'])) ?> · last sign-in <?= e(time_ago($u['last_login_at'])) ?></div></div>
      </div>
      <hr>
      <form id="pf" class="form-grid" onsubmit="return false">
        <div class="field"><label>First name</label><input name="first_name" value="<?= e($u['first_name']) ?>" required></div>
        <div class="field"><label>Last name</label><input name="last_name" value="<?= e($u['last_name']) ?>"></div>
        <div class="field"><label>Email</label><input type="email" name="email" value="<?= e($u['email']) ?>"></div>
        <div class="field"><label>Phone</label><input name="phone" value="<?= e($u['phone']) ?>"></div>
        <div class="full"><button class="btn btn-primary" id="saveP"><?= icon('check') ?> Save profile</button></div>
      </form>
    </div>
    <div class="card"><div class="card-head"><h3>My recent activity</h3></div><div id="act"></div></div>
  </div>
  <div class="stack">
    <div class="card card-pad"><h3><?= icon('lock', 'sm') ?> Password</h3>
      <form id="pw" onsubmit="return false"><div class="field"><label>Current password</label><input type="password" name="current" autocomplete="current-password" required></div>
      <div class="field"><label>New password (8+ characters)</label><input type="password" name="new" autocomplete="new-password" minlength="8" required></div><button class="btn">Change password</button></form></div>
    <div class="card card-pad"><h3><?= icon('key', 'sm') ?> Quick PIN</h3><p class="small muted">Sign in at the counter with a 4–8 digit PIN. <?= $u['pin_hash'] ? '<b class="text-green">PIN is set.</b>' : 'No PIN set.' ?></p>
      <form id="pin" class="row nowrap" onsubmit="return false"><input name="pin" inputmode="numeric" maxlength="8" placeholder="New PIN"><button class="btn">Save</button></form>
      <?php if ($u['pin_hash']): ?><button class="btn btn-sm btn-ghost mt" id="pinRm">Remove PIN</button><?php endif; ?></div>
    <div class="card card-pad"><h3>Appearance</h3><div class="seg"><button data-theme-set="light">Light</button><button data-theme-set="dark">Dark</button></div></div>
  </div>
</div>
<script>
MF.ready(() => {
  const { $ } = MF;
  $('#saveP').onclick = async () => { if (!$('#pf').reportValidity()) return; await MF.api('profile.update', MF.formData($('#pf'))); MF.toast('Profile saved', 'ok'); };
  $('#pw').onsubmit = async () => { await MF.api('profile.password', MF.formData($('#pw'))); MF.toast('Password changed', 'ok'); $('#pw').reset(); };
  $('#pin').onsubmit = async () => { await MF.api('profile.pin', MF.formData($('#pin'))); MF.toast('PIN saved', 'ok'); location.reload(); };
  $('#pinRm') && ($('#pinRm').onclick = async () => { await MF.api('profile.pin', { pin: '' }); location.reload(); });
  $('#avIn').onchange = async e => { const f = e.target.files[0]; if (!f) return; const fd = new FormData(); fd.append('avatar', f); await MF.api('profile.avatar', fd); location.reload(); };
  $('#avRm') && ($('#avRm').onclick = async () => { await MF.api('profile.avatar', { remove: 1 }); location.reload(); });
  MF.table($('#act'), { action: 'profile.activity', pageSize: 15, columns: [
    { key: 'created_at', label: 'When', render: r => `<span class="small mono">${MF.esc(r.created_at)}</span>` },
    { key: 'summary', label: 'What', render: r => MF.esc(r.summary || r.action) },
    { key: 'ip', label: 'From', render: r => `<span class="tiny muted">${MF.esc(r.ip)}</span>` } ] });
});
</script>
