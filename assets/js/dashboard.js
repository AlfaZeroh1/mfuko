/* Role-based dashboards */
(function () {
  'use strict';
  const { $, esc, money } = MF;
  const css = v => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
  const methodName = { cash: 'Cash', mpesa: 'M-Pesa', bank: 'Bank', card: 'Card', other: 'Other' };
  const methodColor = () => ({ cash: css('--gold'), mpesa: css('--green'), bank: css('--indigo'), card: css('--info'), other: css('--muted') });
  const pct = (a, b) => b > 0 ? Math.round((a - b) / b * 100) : null;
  const delta = (a, b, label = 'vs yesterday') => { const p = pct(a, b); return p === null ? '' : `<div class="delta ${p >= 0 ? 'up' : 'down'}">${MF.ic(p >= 0 ? 'trend-up' : 'trend-down', 'sm')} ${Math.abs(p)}% ${label}</div>`; };
  const kpi = (tone, icon, label, value, extra = '') => `<div class="card kpi ${tone}"><span class="kpi-ic">${MF.ic(icon)}</span><div class="label">${esc(label)}</div><div class="value">${value}</div>${extra}</div>`;
  const short = n => n >= 1e6 ? (n / 1e6).toFixed(1) + 'M' : n >= 1e3 ? (n / 1e3).toFixed(1) + 'K' : Math.round(n);

  function chartDefaults() {
    if (!window.Chart) return false;
    Chart.defaults.font.family = "'Plus Jakarta Sans', system-ui, sans-serif";
    Chart.defaults.color = css('--muted');
    Chart.defaults.borderColor = css('--border');
    return true;
  }
  function mixChart(el, mix) {
    if (!chartDefaults() || !el) return;
    const keys = Object.keys(mix || {}); const cols = methodColor();
    if (!keys.length) { el.parentElement.innerHTML = '<div class="empty small">No payments yet</div>'; return; }
    new Chart(el, { type: 'doughnut', data: { labels: keys.map(k => methodName[k] || k), datasets: [{ data: keys.map(k => +mix[k]), backgroundColor: keys.map(k => cols[k] || css('--primary')), borderWidth: 0 }] },
      options: { cutout: '68%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true } }, tooltip: { callbacks: { label: c => ' ' + c.label + ': ' + money(c.raw) } } } } });
  }
  function trendChart(el, series, forecast) {
    if (!chartDefaults() || !el) return;
    const labels = Object.keys(series).concat(Object.keys(forecast || {}));
    const actual = Object.values(series).concat(Object.keys(forecast || {}).map(() => null));
    const fc = Object.keys(series).map((k, i, a) => i === a.length - 1 ? series[k] : null).concat(Object.values(forecast || {}));
    const ctx = el.getContext('2d'); const g = ctx.createLinearGradient(0, 0, 0, 260);
    g.addColorStop(0, css('--primary') + '55'); g.addColorStop(1, css('--primary') + '00');
    new Chart(el, { type: 'line', data: { labels: labels.map(d => d.slice(5)), datasets: [
      { label: 'Revenue', data: actual, borderColor: css('--primary'), backgroundColor: g, fill: true, tension: .35, pointRadius: 0, borderWidth: 2.5 },
      { label: 'AI forecast', data: fc, borderColor: css('--accent'), borderDash: [6, 5], pointRadius: 0, tension: .35, borderWidth: 2, fill: false },
    ] }, options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { labels: { boxWidth: 10, usePointStyle: true } }, tooltip: { callbacks: { label: c => c.raw === null ? '' : ' ' + c.dataset.label + ': ' + money(c.raw) } } },
      scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } }, y: { ticks: { callback: v => short(v) } } } } });
  }
  function barChart(el, labels, data, color) {
    if (!chartDefaults() || !el) return;
    new Chart(el, { type: 'bar', data: { labels, datasets: [{ data, backgroundColor: color || css('--primary'), borderRadius: 6 }] },
      options: { maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ' ' + money(c.raw) } } }, scales: { x: { grid: { display: false } }, y: { ticks: { callback: v => short(v) } } } } });
  }
  function heatmap(el, rows) {
    const max = Math.max(1, ...rows.map(r => +r.n)); const grid = {};
    rows.forEach(r => grid[r.d + '-' + r.h] = +r.n);
    const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    let h = '<div class="heat"><span></span>' + Array.from({ length: 24 }, (_, i) => `<span style="text-align:center">${i % 3 === 0 ? i : ''}</span>`).join('');
    days.forEach((d, di) => { h += `<span>${d}</span>`; for (let hr = 0; hr < 24; hr++) { const v = grid[di + '-' + hr] || 0; h += `<i title="${d} ${hr}:00 · ${v} sales" style="opacity:${v ? 0.12 + 0.88 * v / max : 0.05}"></i>`; } });
    el.innerHTML = h + '</div>';
  }
  function insightsHtml(cards) {
    return cards.map(c => `<div class="insight ${c.tone}"><span class="ins-ic">${MF.ic(c.icon)}</span><div class="grow"><b>${esc(c.title)}</b><p>${esc(c.body)}</p>${c.action ? `<a class="btn btn-sm btn-soft" href="${esc(c.action.url)}">${esc(c.action.label)} →</a>` : ''}</div></div>`).join('');
  }
  async function loadInsights(box, askBox) {
    try {
      const d = await MF.get('dashboard.insights');
      box.innerHTML = insightsHtml(d.cards);
      if (askBox) askBox.classList.toggle('hide', !d.ai);
    } catch (e) { box.innerHTML = '<div class="empty small">Insights unavailable</div>'; }
  }
  function wireAsk(root) {
    const f = $('#askForm', root); if (!f) return;
    f.addEventListener('submit', async e => {
      e.preventDefault(); const out = $('#askOut', root); const btn = $('button', f);
      btn.classList.add('loading'); out.classList.remove('hide'); out.textContent = 'Thinking about your numbers…';
      try { const r = await MF.api('dashboard.ask', { q: $('input', f).value }); out.textContent = r.answer; } catch (err) { out.textContent = err.message; } finally { btn.classList.remove('loading'); }
    });
    root.querySelectorAll('[data-q]').forEach(b => b.onclick = () => { $('input', f).value = b.dataset.q; f.requestSubmit(); });
  }
  const askCard = `<div class="card ai-glow hide" id="askCard"><div class="card-head">${MF.ic('sparkles')}<h3 class="grow">Ask Mfuko AI</h3></div><div class="card-body">
      <form id="askForm" class="row nowrap"><input class="grow" placeholder="e.g. Which products should I restock first?"><button class="btn btn-indigo">${MF.ic('send', 'sm')}</button></form>
      <div class="row mt">${['How can I increase profit this week?', 'Which customers should I follow up?', 'What should I put on promotion?'].map(q => `<button class="btn btn-sm btn-ghost" data-q="${q}">${q}</button>`).join('')}</div>
      <div class="ai-answer mt hide" id="askOut"></div></div></div>`;

  /* ---------------- Admin / manager ---------------- */
  async function admin(root) {
    const d = await MF.get('dashboard.admin');
    const k = d.kpis;
    $('#heroLine').textContent = `${k.count} sales today · ${money(k.revenue)} · ${money(k.collections)} collected`;
    root.innerHTML = `
      <div class="grid g4 mb">
        ${kpi('', 'cart', 'Sales today', money(k.revenue), delta(k.revenue, k.yesterday_same_time))}
        ${kpi('green', 'trend-up', k.gross_profit !== undefined ? 'Gross profit today' : 'Transactions', k.gross_profit !== undefined ? money(k.gross_profit) : k.count, k.gross_profit !== undefined && k.revenue ? `<div class="small muted">${Math.round(k.gross_profit / k.revenue * 100)}% margin · ${k.count} sales</div>` : '')}
        ${kpi('gold', 'receipt', 'Average basket', money(k.avg), `<div class="small muted">${money(k.collections)} collected today</div>`)}
        ${kpi('danger', 'wallet', 'Owed by customers', money(k.receivables), k.payables !== null ? `<div class="small muted">You owe suppliers ${money(k.payables)}</div>` : '')}
      </div>
      <div class="grid g-2-1 mb">
        <div class="card"><div class="card-head"><h3 class="grow">Revenue · last 30 days + 7-day forecast</h3><span class="badge badge-gold">${MF.ic('sparkles', 'sm')} forecast ${money(Object.values(d.forecast || {}).reduce((a, b) => a + b, 0))}</span></div><div class="card-body" style="height:280px"><canvas id="cTrend"></canvas></div></div>
        <div class="card"><div class="card-head"><h3>Payment mix · 30 days</h3></div><div class="card-body" style="height:280px"><canvas id="cMix"></canvas></div></div>
      </div>
      ${k.month_gp !== null ? `<div class="grid g4 mb">
        ${kpi('indigo', 'chart', 'Revenue this month', money(k.month_revenue))}
        ${kpi('green', 'trend-up', 'Gross profit this month', money(k.month_gp))}
        ${kpi('gold', 'cash', 'Expenses this month', money(k.month_expenses))}
        ${kpi(k.month_gp - k.month_expenses >= 0 ? 'green' : 'danger', 'wallet', 'Net (est.)', money(k.month_gp - k.month_expenses))}</div>` : ''}
      <div class="grid g-2-1 mb">
        <div class="card"><div class="card-head">${MF.ic('sparkles')}<h3 class="grow">AI insights</h3><a class="btn btn-sm btn-ghost" href="${MF.url('insights')}">All →</a></div><div id="ins"><div class="card-body"><div class="skeleton" style="height:80px"></div></div></div></div>
        <div class="stack">${askCard}
          <div class="card"><div class="card-head">${MF.ic('zap')}<h3 class="grow">Online now</h3><span class="badge badge-green">${d.online.length}</span></div><ul class="list">${d.online.map(u => `<li><span class="avatar-wrap">${u.avatar ? `<img class="avatar" src="${esc(u.avatar)}">` : `<span class="avatar">${esc((u.first_name || '?')[0])}</span>`}<i class="presence on"></i></span><div class="meta"><b>${esc(u.first_name + ' ' + (u.last_name || ''))}</b><span>${esc(u.role)} · on ${esc(u.last_page || '—')}</span></div></li>`).join('') || '<li class="muted">Only you</li>'}</ul></div>
        </div>
      </div>
      <div class="grid g3 mb">
        <div class="card"><div class="card-head"><h3>Top products · 7 days</h3></div><ul class="list">${d.top.map((t, i) => `<li><b class="text-gold">${i + 1}</b><div class="meta"><b>${esc(t.name)}</b><span>${MF.qty(t.qty)} sold</span></div><b>${money(t.revenue, false)}</b></li>`).join('') || '<li class="muted">No sales yet</li>'}</ul></div>
        <div class="card"><div class="card-head"><h3>Team today</h3></div><ul class="list">${d.cashiers.map(c => `<li><div class="meta"><b>${esc(c.name)}</b><span>${c.n} sales</span></div><b>${money(c.revenue, false)}</b></li>`).join('') || '<li class="muted">No sales yet today</li>'}</ul>
          ${d.stores.length > 1 ? `<div class="card-head"><h3>Branches today</h3></div><ul class="list">${d.stores.map(s => `<li><div class="meta"><b>${esc(s.name)}</b><span>${s.n} sales</span></div><b>${money(s.revenue, false)}</b></li>`).join('')}</ul>` : ''}</div>
        <div class="card"><div class="card-head"><h3 class="grow">Low stock</h3><a class="btn btn-sm btn-ghost" href="${MF.url('stock', { f: 'low' })}">Reorder →</a></div><ul class="list">${d.low.map(l => `<li><div class="meta"><b>${esc(l.name)}</b><span>reorder at ${MF.qty(l.reorder_level)}</span></div><span class="badge ${+l.qty <= 0 ? 'badge-danger' : 'badge-gold'}">${MF.qty(l.qty)}</span></li>`).join('') || '<li class="muted">All stocked 👍</li>'}</ul></div>
      </div>
      <div class="grid g2">
        <div class="card"><div class="card-head"><h3>Busy hours · last 4 weeks</h3></div><div class="card-body" id="heat"></div></div>
        <div class="card"><div class="card-head"><h3 class="grow">Recent activity</h3>${MF.can('audit.view') ? `<a class="btn btn-sm btn-ghost" href="${MF.url('audit')}">Audit trail →</a>` : ''}</div><ul class="list">${d.activity.map(a => `<li><div class="meta"><b>${esc(a.summary || a.action)}</b><span>${esc(a.first_name || 'system')} · ${esc(a.created_at.slice(11, 16))}</span></div></li>`).join('') || '<li class="muted">No activity</li>'}</ul></div>
      </div>`;
    trendChart($('#cTrend'), d.series, d.forecast);
    mixChart($('#cMix'), d.mix);
    heatmap($('#heat'), d.heat);
    loadInsights($('#ins'), $('#askCard'));
    wireAsk(root);
  }

  /* ---------------- Cashier / seller ---------------- */
  async function cashier(root) {
    const d = await MF.get('dashboard.cashier');
    const k = d.kpis, pctT = d.target > 0 ? Math.min(100, Math.round(k.revenue / d.target * 100)) : null;
    $('#heroLine').textContent = d.target > 0 ? `${pctT}% of your ${money(d.target)} target today${d.rank ? ' · #' + d.rank + ' of ' + d.team + ' on the team' : ''}` : `${k.count} sales today${d.rank ? ' · #' + d.rank + ' of ' + d.team : ''}`;
    root.innerHTML = `
      <div class="grid g4 mb">
        ${kpi('', 'cart', 'My sales today', money(k.revenue), delta(k.revenue, k.yesterday_same_time))}
        ${kpi('indigo', 'receipt', 'Transactions', k.count, `<div class="small muted">avg ${money(k.avg)}</div>`)}
        ${kpi('green', 'cash', 'Collected', money(k.collections), d.collected_debt ? `<div class="small muted">${money(d.collected_debt)} from debts</div>` : '')}
        ${kpi('gold', 'star', 'Target', pctT === null ? '—' : pctT + '%', pctT === null ? '<div class="small muted">No target set</div>' : `<div class="progress mt"><i style="width:${pctT}%"></i></div>`)}
      </div>
      <div class="grid g-2-1 mb">
        <div class="card"><div class="card-head"><h3 class="grow">My recent sales</h3><a class="btn btn-sm btn-ghost" href="${MF.url('sales')}">All →</a></div>
          <ul class="list">${d.recent.map(s => `<li><div class="meta"><b>${esc(s.invoice_no)} · ${esc(s.customer || 'Walk-in')}</b><span>${esc(s.sold_at.slice(0, 16))}${+s.balance ? ' · <span class="text-danger">owes ' + money(s.balance) + '</span>' : ''}</span></div><b>${money(s.total, false)}</b><button class="btn btn-sm btn-ghost" onclick="MF.printMenu('receipt', ${s.id})">${MF.ic('printer', 'sm')}</button></li>`).join('') || '<li class="muted">No sales yet — go make your first one!</li>'}</ul></div>
        <div class="stack">
          <div class="card card-pad"><div class="row"><div class="grow"><b>Register</b><div class="small muted">${d.shift ? 'Open since ' + esc(d.shift.opened_at.slice(11, 16)) : 'Closed'}</div></div><a class="btn btn-sm ${d.shift ? '' : 'btn-primary'}" href="${MF.url('shifts')}">${d.shift ? 'Manage' : 'Open shift'}</a></div></div>
          ${d.pending_quotes ? `<div class="card card-pad"><div class="row"><div class="grow"><b>${d.pending_quotes} pending quotations</b><div class="small muted">Follow up to convert them</div></div><a class="btn btn-sm" href="${MF.url('quotations')}">Open</a></div></div>` : ''}
          <div class="card"><div class="card-head"><h3>My payment mix today</h3></div><div class="card-body" style="height:220px"><canvas id="cMix"></canvas></div></div>
        </div>
      </div>
      <div class="card"><div class="card-head"><h3>My sales by hour today</h3></div><div class="card-body" style="height:220px"><canvas id="cHour"></canvas></div></div>`;
    mixChart($('#cMix'), d.mix);
    const hours = Array.from({ length: 15 }, (_, i) => i + 7);
    barChart($('#cHour'), hours.map(h => h + ':00'), hours.map(h => +(d.hourly[h] || 0)));
  }

  /* ---------------- Storekeeper ---------------- */
  async function storekeeper(root) {
    const d = await MF.get('dashboard.storekeeper');
    $('#heroLine').textContent = `${d.counts.lows || 0} low · ${d.counts.outs || 0} out of stock · ${d.transit.length} transfers in transit`;
    root.innerHTML = `
      <div class="grid g4 mb">
        ${kpi('indigo', 'box', 'Products', d.counts.products)}
        ${kpi('gold', 'alert', 'Low stock', d.counts.lows || 0)}
        ${kpi('danger', 'x', 'Out of stock', d.counts.outs || 0)}
        ${kpi('green', 'wallet', 'Stock value', d.value === null ? '—' : money(d.value))}
      </div>
      <div class="row mb">${MF.can('purchases.manage') ? `<a class="btn btn-primary" href="${MF.url('purchases', { new: 1 })}">${MF.ic('truck')} Receive stock</a>` : ''}${MF.can('stock.transfer') ? `<a class="btn" href="${MF.url('transfers')}">${MF.ic('swap')} Transfer</a>` : ''}${MF.can('stock.take') ? `<a class="btn" href="${MF.url('stocktake')}">${MF.ic('clipboard')} Stock take</a>` : ''}</div>
      <div class="grid g3">
        <div class="card"><div class="card-head"><h3 class="grow">Reorder now</h3><a class="btn btn-sm btn-ghost" href="${MF.url('stock', { f: 'low' })}">All →</a></div><ul class="list">${d.low.map(l => `<li><div class="meta"><b>${esc(l.name)}</b><span>min ${MF.qty(l.reorder_level)} ${esc(l.unit)}</span></div><span class="badge ${+l.qty <= 0 ? 'badge-danger' : 'badge-gold'}">${MF.qty(l.qty)}</span></li>`).join('') || '<li class="muted">Nothing to reorder</li>'}</ul></div>
        <div class="card"><div class="card-head"><h3>Fast movers · 7 days</h3></div><ul class="list">${d.movers.map(m => `<li><div class="meta"><b>${esc(m.name)}</b></div><b>${MF.qty(m.qty)}</b></li>`).join('') || '<li class="muted">No sales yet</li>'}</ul></div>
        <div class="stack">
          <div class="card"><div class="card-head"><h3>In transit</h3></div><ul class="list">${d.transit.map(t => `<li><div class="meta"><b>${esc(t.transfer_no)}</b><span>${esc(t.f)} → ${esc(t.t2)}</span></div></li>`).join('') || '<li class="muted">None</li>'}</ul></div>
          <div class="card"><div class="card-head"><h3>Expiring soon</h3></div><ul class="list">${d.expiring.map(x => `<li><div class="meta"><b>${esc(x.name)}</b></div><span class="badge badge-danger">${esc(x.expiry_date)}</span></li>`).join('') || '<li class="muted">Nothing expiring</li>'}</ul></div>
        </div>
      </div>
      <div class="card mt"><div class="card-head"><h3>Recent deliveries</h3></div><ul class="list">${d.purchases.map(p => `<li><div class="meta"><b>${esc(p.grn_no)} · ${esc(p.supplier)}</b><span>${esc(p.purchase_date)}</span></div><b>${money(p.total, false)}</b></li>`).join('') || '<li class="muted">No purchases yet</li>'}</ul></div>`;
  }

  /* ---------------- Accountant ---------------- */
  async function accountant(root) {
    const d = await MF.get('dashboard.accountant');
    const p = d.pnl, a = d.aging || {};
    $('#heroLine').textContent = `Month to date net ${money(p.net)} · ${d.unmatched_mpesa} unmatched M-Pesa payments`;
    root.innerHTML = `
      <div class="grid g4 mb">
        ${kpi('', 'chart', 'Revenue (MTD)', money(p.revenue), p.returns ? `<div class="small muted">returns ${money(p.returns)}</div>` : '')}
        ${kpi('green', 'trend-up', 'Gross profit', money(p.gross), p.revenue ? `<div class="small muted">${Math.round(p.gross / p.revenue * 100)}% margin</div>` : '')}
        ${kpi('gold', 'cash', 'Expenses', money(p.expenses))}
        ${kpi(p.net >= 0 ? 'green' : 'danger', 'wallet', 'Net profit (est.)', money(p.net))}
      </div>
      <div class="grid g-2-1 mb">
        <div class="card"><div class="card-head"><h3>Daily revenue · 30 days</h3></div><div class="card-body" style="height:260px"><canvas id="cTrend"></canvas></div></div>
        <div class="card"><div class="card-head"><h3>Collections this month</h3></div><div class="card-body" style="height:260px"><canvas id="cMix"></canvas></div></div>
      </div>
      <div class="grid g3">
        <div class="card"><div class="card-head"><h3 class="grow">Receivables ageing</h3><a class="btn btn-sm btn-ghost" href="${MF.url('debts')}">Debtors →</a></div><div class="card-body">
          <div class="row"><span class="grow">0–30 days</span><b>${money(a.b0 || 0)}</b></div><div class="row"><span class="grow">31–60 days</span><b class="text-gold">${money(a.b30 || 0)}</b></div><div class="row"><span class="grow">60+ days</span><b class="text-danger">${money(a.b60 || 0)}</b></div>
          <hr><div class="row"><span class="grow">Payables (suppliers)</span><b>${money(d.payables)}</b></div></div></div>
        <div class="card"><div class="card-head"><h3>Expenses by category</h3></div><ul class="list">${d.expenses.map(e => `<li><div class="meta"><b>${esc(e.category)}</b></div><b>${money(e.total, false)}</b></li>`).join('') || '<li class="muted">No expenses this month</li>'}</ul></div>
        <div class="card"><div class="card-head"><h3>Till variances</h3></div><ul class="list">${d.variances.map(v => `<li><div class="meta"><b>${esc(v.first_name)}</b><span>${esc((v.closed_at || '').slice(0, 16))}</span></div><b class="${+v.variance < 0 ? 'text-danger' : 'text-green'}">${money(v.variance, false)}</b></li>`).join('') || '<li class="muted">All tills balanced</li>'}</ul>
          ${d.unmatched_mpesa ? `<div class="card-foot"><a href="${MF.url('mpesa')}">${d.unmatched_mpesa} unmatched M-Pesa payments →</a></div>` : ''}</div>
      </div>`;
    trendChart($('#cTrend'), d.daily, {});
    mixChart($('#cMix'), d.month_mix);
  }

  MF.ready(() => {
    const root = $('#dash'); if (!root) return;
    ({ admin, cashier, storekeeper, accountant }[root.dataset.type] || cashier)(root).catch(e => { root.innerHTML = `<div class="empty">${MF.ic('alert')}<div>${esc(e.message)}</div></div>`; });
  });
  MF.dash = { insightsHtml, wireAsk, askCard };
})();
