<div class="page-head"><h2 class="grow">Register shifts</h2></div>
<div class="card card-pad mb" id="mine"></div>
<div class="card"><div class="card-head"><h3>History</h3></div><div id="tbl"></div></div>
<script>
MF.ready(() => {
  const { $, esc, money } = MF;
  const t = MF.table($('#tbl'), { action: 'sales.shifts', emptyIcon: 'clock', empty: 'No shifts yet',
    onLoad: d => {
      const box = $('#mine');
      if (!MF.can('shifts.manage')) { box.classList.add('hide'); return; }
      if (d.open) {
        const s = d.open_summary;
        box.innerHTML = `<div class="row"><div class="grow"><span class="badge badge-green">${MF.ic('dot', 'sm')} Open since ${esc(d.open.opened_at.slice(11, 16))}</span>
          <h3 class="mt">Expected cash in drawer: ${money(s.expected_cash)}</h3>
          <div class="muted small">${s.sales_count} sales · ${money(s.sales_total)} · float ${money(d.open.opening_float)} · ${Object.entries(s.by_method).map(([m, v]) => m + ' ' + money(v, false)).join(' · ')}</div></div>
          <button class="btn" data-x="x">${MF.ic('printer')} X-report</button><button class="btn btn-primary" data-x="close">${MF.ic('lock')} Close shift</button></div>`;
      } else {
        box.innerHTML = `<div class="row"><div class="grow"><h3>Your register is closed</h3><div class="muted small">Open a shift with your starting float to track cash accurately.</div></div><button class="btn btn-primary" data-x="open">${MF.ic('zap')} Open shift</button></div>`;
      }
      box.onclick = async e => {
        const x = e.target.closest('[data-x]')?.dataset.x; if (!x) return;
        if (x === 'open') { const f = await MF.ask('Opening float (cash in drawer)', { type: 'number', value: '0', title: 'Open shift' }); if (f !== null) { await MF.api('sales.shift_open', { float: f }); t.reload(); } }
        if (x === 'x') MF.print('shift', d.open.id, 'thermal');
        if (x === 'close') MF.modal({ title: 'Close shift', body: `<p>Count the cash in the drawer. Expected: <b>${money(d.open_summary.expected_cash)}</b></p><div class="field"><label>Counted cash</label><input type="number" step="0.01" name="counted" required></div><div class="field"><label>Notes</label><input name="notes"></div>`,
          foot: [{ label: 'Cancel', cls: 'btn-ghost' }, { label: 'Close & print Z-report', cls: 'btn-primary', onClick: async api => { const f = MF.formData(api.body); const r = await MF.api('sales.shift_close', f); MF.toast('Shift closed. Variance ' + money(r.variance), r.variance < 0 ? 'warn' : 'ok'); MF.print('shift', r.id, 'thermal'); t.reload(); } }] });
      };
    },
    columns: [
      { key: 'cashier', label: 'Cashier', render: r => `<b>${esc(r.cashier)}</b><div class="tiny muted">${esc(r.store)}</div>` },
      { key: 'opened_at', label: 'Opened', render: r => esc(r.opened_at.slice(0, 16)) },
      { key: 'closed_at', label: 'Closed', render: r => r.closed_at ? esc(r.closed_at.slice(0, 16)) : '<span class="badge badge-green">open</span>' },
      { key: 'opening_float', label: 'Float', num: true, render: r => money(r.opening_float, false) },
      { key: 'expected_cash', label: 'Expected', num: true, render: r => r.expected_cash !== null ? money(r.expected_cash, false) : '—' },
      { key: 'counted_cash', label: 'Counted', num: true, render: r => r.counted_cash !== null ? money(r.counted_cash, false) : '—' },
      { key: 'variance', label: 'Variance', num: true, render: r => r.variance === null ? '—' : `<b class="${+r.variance < 0 ? 'text-danger' : 'text-green'}">${money(r.variance, false)}</b>` },
      { key: '_p', label: '', cls: 'actions', render: () => `<button class="btn btn-sm btn-ghost" data-act="p">${MF.ic('printer', 'sm')}</button>` },
    ],
    onAction: (a, r) => MF.printMenu('shift', r.id) });
});
</script>
