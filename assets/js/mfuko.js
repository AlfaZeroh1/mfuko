/* Mfuko POS — core client (no dependencies). Loaded in <head>. */
(function () {
  'use strict';
  const MF = window.MF = window.MF || {};
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  MF.$ = $; MF.$$ = $$;

  MF.ready = fn => document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn);
  MF.can = p => (MF.perms || []).includes(p);
  MF.esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  MF.num = v => { const n = parseFloat(String(v ?? '').replace(/,/g, '')); return isFinite(n) ? n : 0; };
  MF.r2 = n => Math.round((+n + Number.EPSILON) * 100) / 100;
  const nf = new Intl.NumberFormat('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  MF.money = (n, sym = true) => (sym ? (MF.currency || 'KSh') + ' ' : '') + nf.format(+n || 0);
  MF.qty = n => { n = +n || 0; return Number.isInteger(n) ? String(n) : String(+n.toFixed(3)); };
  MF.ic = (name, cls = '') => `<svg class="ic ${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${(MF.icons || {})[name] || ''}</svg>`;
  MF.debounce = (fn, ms = 200) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
  MF.uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'x' + Date.now().toString(36) + Math.random().toString(36).slice(2));
  MF.store = {
    get(k, d = null) { try { const v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* quota / private mode */ } },
    del(k) { try { localStorage.removeItem(k); } catch (e) {} },
  };
  MF.today = () => MF.nowParts().date;
  MF.url = (p, q = {}) => 'index.php?' + new URLSearchParams({ p, ...q }).toString();

  /* ---------- API ---------- */
  MF.api = async function (action, data = null, opts = {}) {
    const isForm = data instanceof FormData;
    const method = opts.method || (data ? 'POST' : 'GET');
    let url = 'api.php?a=' + encodeURIComponent(action);
    const init = { method, headers: { 'X-CSRF-Token': MF.csrf, 'Accept': 'application/json' }, credentials: 'same-origin' };
    if (method === 'GET' && data) url += '&' + new URLSearchParams(data).toString();
    else if (data) {
      if (isForm) init.body = data;
      else { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(data); }
    }
    let res, json;
    try {
      res = await fetch(url, init);
      json = await res.json();
    } catch (e) {
      const err = new Error(navigator.onLine ? 'Server did not respond properly' : 'You are offline');
      err.offline = !navigator.onLine || !res;
      if (!opts.quiet) MF.toast(err.message, 'err');
      throw err;
    }
    if (res.status === 401) { location.href = MF.url('login'); throw new Error('auth'); }
    if (!json.ok) {
      const err = new Error(json.error || 'Request failed');
      err.status = res.status;
      if (!opts.quiet) MF.toast(err.message, 'err');
      throw err;
    }
    return json.data;
  };
  MF.get = (action, params, opts) => MF.api(action, params || {}, { ...opts, method: 'GET' });

  /* ---------- Toasts ---------- */
  MF.toast = function (msg, type = '') {
    const box = $('#toasts'); if (!box) return alert(msg);
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.innerHTML = MF.ic(type === 'err' ? 'alert' : type === 'ok' ? 'check' : 'info') + '<span>' + MF.esc(msg) + '</span>';
    box.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(() => el.remove(), 300); }, type === 'err' ? 5000 : 2600);
  };

  /* ---------- Modals ---------- */
  MF.modal = function ({ title = '', body = '', foot = null, wide = false, onOpen = null, onClose = null, dismissable = true } = {}) {
    const d = document.createElement('dialog');
    d.className = 'modal' + (wide ? ' wide' : '');
    d.innerHTML = `<div class="modal-head"><h3>${MF.esc(title)}</h3><button class="icon-btn" data-x aria-label="Close">${MF.ic('x')}</button></div>
      <div class="modal-body"></div>${foot === false ? '' : '<div class="modal-foot"></div>'}`;
    const bodyEl = $('.modal-body', d);
    if (typeof body === 'string') bodyEl.innerHTML = body; else if (body) bodyEl.appendChild(body);
    const footEl = $('.modal-foot', d);
    const api = { el: d, body: bodyEl, foot: footEl, close: () => { d.close(); } };
    if (footEl) {
      const buttons = foot || [{ label: 'Close', cls: 'btn' }];
      buttons.forEach(b => {
        const btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'btn ' + (b.cls || ''); btn.innerHTML = (b.icon ? MF.ic(b.icon) : '') + MF.esc(b.label);
        btn.onclick = async () => {
          if (!b.onClick) return api.close();
          btn.classList.add('loading');
          try { const r = await b.onClick(api, btn); if (r !== false) api.close(); } catch (e) { /* toast already shown */ }
          finally { btn.classList.remove('loading'); }
        };
        footEl.appendChild(btn);
      });
    }
    $('[data-x]', d).onclick = () => api.close();
    d.addEventListener('cancel', e => { if (!dismissable) e.preventDefault(); });
    d.addEventListener('close', () => { onClose && onClose(api); d.remove(); });
    d.addEventListener('click', e => { if (e.target === d && dismissable) api.close(); });
    document.body.appendChild(d);
    d.showModal();
    onOpen && onOpen(api);
    const first = $('input:not([type=hidden]):not([readonly]), select, textarea', bodyEl);
    if (first && matchMedia('(min-width: 900px)').matches) setTimeout(() => first.focus(), 30);
    return api;
  };

  MF.confirm = (message, { title = 'Please confirm', ok = 'Yes, continue', danger = false } = {}) => new Promise(resolve => {
    let done = false;
    MF.modal({
      title, body: `<p>${MF.esc(message)}</p>`,
      foot: [
        { label: 'Cancel', cls: 'btn-ghost', onClick: () => { done = true; resolve(false); } },
        { label: ok, cls: danger ? 'btn-danger' : 'btn-primary', onClick: () => { done = true; resolve(true); } },
      ],
      onClose: () => { if (!done) resolve(false); },
    });
  });

  MF.ask = (label, { title = '', value = '', type = 'text', placeholder = '', ok = 'OK' } = {}) => new Promise(resolve => {
    let done = false;
    MF.modal({
      title: title || label,
      body: `<div class="field"><label>${MF.esc(label)}</label><input type="${type}" value="${MF.esc(value)}" placeholder="${MF.esc(placeholder)}" data-ask></div>`,
      foot: [
        { label: 'Cancel', cls: 'btn-ghost', onClick: () => { done = true; resolve(null); } },
        { label: ok, cls: 'btn-primary', onClick: api => { done = true; resolve($('[data-ask]', api.el).value); } },
      ],
      onOpen: api => { const i = $('[data-ask]', api.el); i.focus(); i.addEventListener('keydown', e => { if (e.key === 'Enter') { done = true; resolve(i.value); api.close(); } }); },
      onClose: () => { if (!done) resolve(null); },
    });
  });

  /* ---------- Forms ---------- */
  MF.formData = function (form) {
    const o = {};
    $$('input, select, textarea', form).forEach(el => {
      if (!el.name || el.disabled) return;
      if (el.type === 'checkbox') { o[el.name] = el.checked ? 1 : 0; return; }
      if (el.type === 'radio') { if (el.checked) o[el.name] = el.value; return; }
      if (el.type === 'file') return;
      if (el.multiple) { o[el.name] = Array.from(el.selectedOptions).map(x => x.value); return; }
      o[el.name] = el.value;
    });
    return o;
  };
  MF.fill = function (form, data) {
    Object.entries(data || {}).forEach(([k, v]) => {
      const el = form.querySelector(`[name="${k}"]`); if (!el) return;
      if (el.type === 'checkbox') el.checked = !!+v; else el.value = v ?? '';
    });
  };

  /* ---------- Server-paginated table ---------- */
  MF.table = function (root, cfg) {
    const state = { page: 1, q: '', total: 0, rows: [] };
    const pageSize = cfg.pageSize || 25;
    root.innerHTML = `<div class="table-wrap"><table class="table responsive"><thead><tr>${cfg.columns.map(c => `<th class="${c.num ? 'num' : ''}">${MF.esc(c.label)}</th>`).join('')}</tr></thead><tbody></tbody>${cfg.footer ? '<tfoot></tfoot>' : ''}</table></div><div class="pager"></div>`;
    const tbody = $('tbody', root), pager = $('.pager', root), tfoot = $('tfoot', root);
    async function load() {
      tbody.innerHTML = `<tr><td colspan="${cfg.columns.length}"><div class="skeleton" style="height:18px"></div></td></tr>`;
      const params = { page: state.page, per: pageSize, q: state.q, ...(cfg.params ? cfg.params() : {}) };
      let data;
      try { data = await MF.get(cfg.action, params); } catch (e) { tbody.innerHTML = ''; return; }
      state.rows = data.rows; state.total = data.total;
      if (!data.rows.length) {
        tbody.innerHTML = `<tr><td colspan="${cfg.columns.length}"><div class="empty">${MF.ic(cfg.emptyIcon || 'box')}<div>${MF.esc(cfg.empty || 'Nothing here yet')}</div></div></td></tr>`;
      } else {
        tbody.innerHTML = data.rows.map((r, i) => `<tr data-i="${i}" class="${cfg.onRow ? 'clickable' : ''}">${cfg.columns.map(c => {
          const v = c.render ? c.render(r) : MF.esc(r[c.key]);
          return `<td data-label="${MF.esc(c.label)}" class="${c.num ? 'num' : ''} ${c.cls || ''}">${v ?? ''}</td>`;
        }).join('')}</tr>`).join('');
      }
      if (tfoot && cfg.footer) tfoot.innerHTML = cfg.footer(data) || '';
      const pages = Math.max(1, Math.ceil(state.total / pageSize));
      pager.innerHTML = `<span>${state.total.toLocaleString()} records</span>
        <button class="btn btn-sm" ${state.page <= 1 ? 'disabled' : ''} data-pg="-1">‹ Prev</button>
        <span>${state.page} / ${pages}</span>
        <button class="btn btn-sm" ${state.page >= pages ? 'disabled' : ''} data-pg="1">Next ›</button>`;
      cfg.onLoad && cfg.onLoad(data);
    }
    pager.addEventListener('click', e => { const b = e.target.closest('[data-pg]'); if (!b) return; state.page += +b.dataset.pg; load(); });
    tbody.addEventListener('click', e => {
      const act = e.target.closest('[data-act]');
      const tr = e.target.closest('tr[data-i]'); if (!tr) return;
      const row = state.rows[+tr.dataset.i];
      if (act && cfg.onAction) { e.stopPropagation(); cfg.onAction(act.dataset.act, row, act); return; }
      if (cfg.onRow && !e.target.closest('a,button,input')) cfg.onRow(row);
    });
    if (cfg.search) {
      cfg.search.addEventListener('input', MF.debounce(() => { state.q = cfg.search.value.trim(); state.page = 1; load(); }, 250));
    }
    load();
    return { reload: (resetPage) => { if (resetPage) state.page = 1; return load(); }, state };
  };

  /* ---------- Printing ---------- */
  MF.print = function (doc, id, size = '', extra = {}) {
    const q = new URLSearchParams({ doc, id, ...(size ? { size } : {}), auto: 1, ...extra });
    const w = window.open('print.php?' + q.toString(), '_blank');
    if (!w) location.href = 'print.php?' + q.toString();
  };
  MF.printMenu = function (doc, id, extra = {}) {
    MF.modal({
      title: 'Print / share',
      body: `<div class="grid g2">
        <button class="btn btn-lg" data-sz="thermal">${MF.ic('receipt')} Thermal receipt</button>
        <button class="btn btn-lg" data-sz="a4">${MF.ic('file')} A4 document</button></div>
        <div class="row mt"><input type="email" placeholder="Email to… (optional)" id="pmEmail" class="grow"><button class="btn btn-soft" id="pmSend">${MF.ic('send')} Email</button></div>`,
      foot: false,
      onOpen: api => {
        $$('[data-sz]', api.el).forEach(b => b.onclick = () => { MF.print(doc, id, b.dataset.sz, extra); api.close(); });
        $('#pmSend', api.el).onclick = async () => {
          const to = $('#pmEmail', api.el).value.trim(); if (!to) return MF.toast('Enter an email address', 'warn');
          await MF.api('comms.email_document', { doc, id, to, ...extra }); MF.toast('Queued for email', 'ok'); api.close();
        };
      },
    });
  };

  /* ---------- Time / analogue clock (business timezone) ---------- */
  const drift = (MF.serverNow || Date.now()) - Date.now();
  const fmt = new Intl.DateTimeFormat('en-GB', { timeZone: MF.tz || 'Africa/Nairobi', hour12: false, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', weekday: 'short' });
  MF.nowParts = function () {
    const p = {}; fmt.formatToParts(new Date(Date.now() + drift)).forEach(x => p[x.type] = x.value);
    return { h: +p.hour % 24, m: +p.minute, s: +p.second, wd: p.weekday, date: `${p.year}-${p.month}-${p.day}`, label: `${p.weekday} ${p.day}/${p.month}` };
  };
  function initClock() {
    const svg = $('#navClock'); if (!svg) return;
    const ticks = $('#clockTicks', svg);
    let t = '';
    for (let i = 0; i < 12; i++) {
      const a = i * 30 * Math.PI / 180, r1 = i % 3 === 0 ? 12.5 : 14, r2 = 15.5;
      t += `<line class="tick ${i % 3 === 0 ? 'major' : ''}" x1="${20 + r1 * Math.sin(a)}" y1="${20 - r1 * Math.cos(a)}" x2="${20 + r2 * Math.sin(a)}" y2="${20 - r2 * Math.cos(a)}"/>`;
    }
    ticks.innerHTML = t;
    const H = $('.h', svg), M = $('.m', svg), S = $('.s', svg), T = $('#clockTime'), D = $('#clockDate');
    function tick() {
      const n = MF.nowParts();
      H.setAttribute('transform', `rotate(${(n.h % 12) * 30 + n.m * 0.5} 20 20)`);
      M.setAttribute('transform', `rotate(${n.m * 6 + n.s * 0.1} 20 20)`);
      S.setAttribute('transform', `rotate(${n.s * 6} 20 20)`);
      if (T) T.textContent = String(n.h).padStart(2, '0') + ':' + String(n.m).padStart(2, '0');
      if (D) D.textContent = n.label;
    }
    tick();
    setTimeout(() => { tick(); setInterval(tick, 1000); }, 1000 - (Date.now() % 1000));
  }

  /* ---------- Offline sale queue (sync from any page) ---------- */
  MF.queue = {
    all: () => MF.store.get('mf-queue', []),
    add(item) { const q = MF.queue.all(); q.push(item); MF.store.set('mf-queue', q); MF.queue.badge(); },
    badge() {
      const n = MF.queue.all().length, pill = $('#queuePill'); if (!pill) return;
      pill.classList.toggle('hide', !n); $('span', pill).textContent = n + ' to sync';
    },
    async sync() {
      if (!navigator.onLine || MF.queue.busy) return;
      let q = MF.queue.all(); if (!q.length) return;
      MF.queue.busy = true;
      try {
        for (const item of q.slice()) {
          try {
            await MF.api('sell.checkout', item, { quiet: true });
            q = MF.queue.all().filter(x => x.client_ref !== item.client_ref); MF.store.set('mf-queue', q);
          } catch (e) {
            if (e.offline) break;
            item._error = e.message; MF.store.set('mf-queue', MF.queue.all().map(x => x.client_ref === item.client_ref ? item : x));
            MF.toast('Queued sale could not sync: ' + e.message, 'err');
          }
        }
      } finally { MF.queue.busy = false; MF.queue.badge(); }
    },
  };

  /* ---------- Shell behaviour ---------- */
  function setTheme(t) {
    document.documentElement.dataset.theme = t;
    MF.store.set('mf-theme', t);
    MF.api('profile.theme', { theme: t }, { quiet: true }).catch(() => {});
  }

  MF.ready(() => {
    initClock();
    const body = document.body;
    const toggleNav = open => body.classList.toggle('nav-open', open);
    $('#navToggle')?.addEventListener('click', () => toggleNav(!body.classList.contains('nav-open')));
    $('#moreNav')?.addEventListener('click', () => toggleNav(true));
    $('[data-close-nav]')?.addEventListener('click', () => toggleNav(false));
    $('#themeToggle')?.addEventListener('click', () => setTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark'));
    $$('[data-theme-set]').forEach(b => b.addEventListener('click', () => setTheme(b.dataset.themeSet)));
    document.addEventListener('click', e => {
      const t = e.target.closest('[data-toggle-dropdown]');
      $$('.dropdown.open').forEach(d => { if (!t || d !== t.parentElement) d.classList.remove('open'); });
      if (t) t.parentElement.classList.toggle('open');
    });
    $('#storeSwitch')?.addEventListener('change', async e => {
      await MF.api('session.store', { store_id: e.target.value });
      location.reload();
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'F2' && MF.can('sell.create')) { e.preventDefault(); location.href = MF.url('sell'); }
    });
    const signedIn = !!MF.user;
    const net = () => { body.classList.toggle('offline', !navigator.onLine); if (navigator.onLine && signedIn) MF.queue.sync(); };
    addEventListener('online', net); addEventListener('offline', net); net();
    MF.queue.badge();
    if (signedIn) setInterval(() => { if (navigator.onLine && !document.hidden) MF.api('session.ping', { page: MF.page }, { quiet: true }).catch(() => {}); }, 60000);
    if ('serviceWorker' in navigator && location.protocol !== 'file:') navigator.serviceWorker.register('sw.js').catch(() => {});
  });
})();
