/* Mfuko POS — Sell screen. Speed first: catalogue cached on device, instant
   local search, minimal DOM work, offline queue for flaky connections. */
(function () {
  'use strict';
  const { $, $$, esc, money, num, r2 } = MF;
  const CAT_KEY = 'mf-catalog-v2';
  const store = +$('#pos').dataset.store;

  const S = {
    products: new Map(), stock: {}, cats: [], accounts: [], promos: [], cfg: {},
    cart: [], customer: null, saleType: 'retail', coupon: '', couponDisc: 0, shipping: 0, notes: '', soldAt: '',
    quotationId: null, cat: 'all', q: '', view: MF.store.get('mf-view', 'grid'), freq: MF.store.get('mf-freq', {}),
  };

  /* ---------------- Catalogue ---------------- */
  function applyProducts(list) {
    list.forEach(p => { if (p.act) S.products.set(p.id, p); else S.products.delete(p.id); });
  }
  async function loadCatalog(force = false) {
    const cache = force ? null : MF.store.get(CAT_KEY);
    if (cache && cache.store === store) {
      applyProducts(cache.products); S.cats = cache.categories || [];
      S.stock = cache.stock || {};
      renderCats(); renderGrid();
    }
    try {
      const d = await MF.get('sell.catalog', { since: cache && cache.store === store ? cache.now : 0 }, { quiet: !!cache });
      if (d.full) S.products.clear();
      applyProducts(d.products); S.stock = d.stock; S.cats = d.categories;
      MF.store.set(CAT_KEY, { store, now: d.now, products: [...S.products.values()], stock: d.stock, categories: d.categories });
      renderCats(); renderGrid(); renderCart();
    } catch (e) { if (!cache) $('#grid').innerHTML = '<div class="empty">' + MF.ic('wifi-off') + '<div>Could not load products</div></div>'; }
  }
  function saveStockCache() {
    const c = MF.store.get(CAT_KEY); if (!c) return;
    c.stock = S.stock; MF.store.set(CAT_KEY, c);
  }

  /* ---------------- Pricing (mirrors SaleService::price) ---------------- */
  function basePrice(p) {
    const type = S.customer && S.customer.price_tier === 'wholesale' ? 'wholesale' : S.saleType;
    return type === 'wholesale' && p.wp > 0 ? p.wp : p.rp;
  }
  function promoUnit(p, price) {
    const type = S.customer && S.customer.price_tier === 'wholesale' ? 'wholesale' : S.saleType;
    let best = 0, name = null;
    S.promos.forEach(pr => {
      if (pr.sale_type !== 'any' && pr.sale_type !== type) return;
      if (pr.scope === 'category' && +pr.scope_id !== +p.cat) return;
      if (pr.scope === 'product' && +pr.scope_id !== +p.id) return;
      let d = pr.type === 'percent' ? price * pr.value / 100 : +pr.value;
      d = Math.min(d, price);
      if (d > best) { best = d; name = pr.name; }
    });
    return [r2(best), name];
  }
  function priceLine(l) {
    const p = S.products.get(l.pid); if (!p) return null;
    const price = l.price != null ? l.price : basePrice(p);
    const gross = r2(price * l.qty);
    const [pu, promo] = promoUnit(p, price);
    const promoAmt = r2(pu * l.qty);
    const custPct = S.customer ? +S.customer.discount || 0 : 0;
    const cust = r2((gross - promoAmt - (l.disc || 0)) * custPct / 100);
    const disc = Math.min(gross, promoAmt + (l.disc || 0) + cust);
    const net = r2(gross - disc);
    const incl = S.cfg.tax_mode !== 'exclusive';
    const tax = p.tr > 0 ? r2(incl ? net * p.tr / (100 + p.tr) : net * p.tr / 100) : 0;
    return { p, price, gross, disc, promo, tax, total: incl ? net : r2(net + tax) };
  }
  function totals() {
    let sub = 0, disc = 0, tax = 0, lines = 0, items = 0;
    S.cart.forEach(l => { const x = priceLine(l); if (!x) return; sub += x.gross; disc += x.disc; tax += x.tax; lines += x.total; items += l.qty; });
    const total = r2(lines - S.couponDisc + (+S.shipping || 0));
    return { sub: r2(sub), disc: r2(disc), tax: r2(tax), lines: r2(lines), total: Math.max(0, total), items };
  }

  /* ---------------- Rendering ---------------- */
  function renderCats() {
    const box = $('#cats');
    const chips = [`<button class="chip ${S.cat === 'all' ? 'on' : ''}" data-cat="all">All</button>`,
      `<button class="chip ${S.cat === 'fav' ? 'on' : ''}" data-cat="fav">★ Popular</button>`]
      .concat(S.cats.map(c => `<button class="chip ${S.cat == c.id ? 'on' : ''}" data-cat="${c.id}"><i style="background:${esc(c.color || '#C4502A')}"></i>${esc(c.name)}</button>`));
    box.innerHTML = chips.join('');
  }
  const hue = s => { let h = 0; for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) % 360; return h; };
  function matches(p, terms) {
    if (!terms.length) return true;
    const hay = (p.name + ' ' + (p.sku || '') + ' ' + (p.barcode || '')).toLowerCase();
    return terms.every(t => hay.includes(t));
  }
  function filtered() {
    const terms = S.q.toLowerCase().split(/\s+/).filter(Boolean);
    let list = [...S.products.values()].filter(p => matches(p, terms));
    if (S.cat === 'fav') list = list.filter(p => p.fav || S.freq[p.id]);
    else if (S.cat !== 'all') list = list.filter(p => +p.cat === +S.cat);
    list.sort((a, b) => (b.fav - a.fav) || ((S.freq[b.id] || 0) - (S.freq[a.id] || 0)) || a.name.localeCompare(b.name));
    return list;
  }
  let renderLimit = 120;
  function renderGrid() {
    const grid = $('#grid');
    grid.className = 'pos-grid ' + (S.view === 'list' ? 'list' : '') + (S.cfg.show_images === false ? ' noimg' : '');
    const list = filtered();
    if (!list.length) { grid.innerHTML = `<div class="empty" style="grid-column:1/-1">${MF.ic('search')}<div>No products match “${esc(S.q)}”</div></div>`; return; }
    const inCart = {}; S.cart.forEach(l => inCart[l.pid] = (inCart[l.pid] || 0) + l.qty);
    grid.innerHTML = list.slice(0, renderLimit).map(p => {
      const st = S.stock[p.id] ?? 0;
      const stCls = !p.ts ? '' : st <= 0 ? 'out' : st <= 5 ? 'low' : '';
      const [pu] = promoUnit(p, basePrice(p));
      return `<button class="tile ${pu > 0 ? 'promo' : ''}" data-pid="${p.id}">
        <div class="ph">${p.image ? `<img src="${esc(p.image)}" alt="" loading="lazy" decoding="async">` : `<span class="ini" style="--h:${hue(p.name)}">${esc(p.name.slice(0, 2).toUpperCase())}</span>`}</div>
        ${p.ts ? `<span class="st ${stCls}">${MF.qty(st)} ${esc(p.unit || '')}</span>` : ''}
        ${inCart[p.id] ? `<span class="incart">${MF.qty(inCart[p.id])}</span>` : ''}
        <div class="tb"><span class="nm">${esc(p.name)}</span><span class="pr">${money(basePrice(p) - pu)}</span></div></button>`;
    }).join('') + (list.length > renderLimit ? `<button class="btn" style="grid-column:1/-1" data-more>Show more (${list.length - renderLimit})</button>` : '');
  }

  function renderCart() {
    const box = $('#lines');
    if (!S.cart.length) {
      box.innerHTML = `<div class="cart-empty">${MF.ic('cart')}<p>Tap a product to start a sale</p><p class="tiny">Tip: type or scan, press Enter · F9 to charge</p></div>`;
    } else {
      box.innerHTML = S.cart.map((l, i) => {
        const x = priceLine(l); if (!x) return '';
        const sub = [`${money(x.price)} × ${MF.qty(l.qty)}`];
        if (x.promo) sub.push(`<span class="promo">${esc(x.promo)}</span>`);
        if (x.disc > 0) sub.push(`−${money(x.disc, false)}`);
        const st = S.stock[l.pid] ?? 0;
        if (x.p.ts && l.qty > st) sub.push(`<span class="text-danger">only ${MF.qty(st)} in stock</span>`);
        return `<div class="line" data-i="${i}">
          <div><div class="ln-name" data-edit>${esc(x.p.name)}</div><div class="ln-sub">${sub.join(' · ')}</div></div>
          <div class="ln-total">${money(x.total, false)}</div>
          <div class="stepper"><button data-dec aria-label="Less">${MF.ic('minus', 'sm')}</button><input inputmode="decimal" value="${MF.qty(l.qty)}" data-qty><button data-inc aria-label="More">${MF.ic('plus', 'sm')}</button></div>
          <div style="text-align:right"><button class="btn btn-sm btn-ghost btn-icon" data-rm aria-label="Remove">${MF.ic('trash', 'sm')}</button></div></div>`;
      }).join('');
    }
    const t = totals();
    $('#totals').innerHTML = S.cart.length ? `
      <div><span>Subtotal</span><span>${money(t.sub)}</span></div>
      ${t.disc > 0 ? `<div class="text-green"><span>Discounts</span><span>−${money(t.disc, false)}</span></div>` : ''}
      ${S.couponDisc > 0 ? `<div class="text-green"><span>Coupon ${esc(S.coupon)}</span><span>−${money(S.couponDisc, false)}</span></div>` : ''}
      ${+S.shipping > 0 ? `<div><span>Delivery</span><span>${money(S.shipping, false)}</span></div>` : ''}
      ${t.tax > 0 ? `<div class="muted small"><span>VAT ${S.cfg.tax_mode === 'exclusive' ? '' : '(incl.)'}</span><span>${money(t.tax, false)}</span></div>` : ''}
      <div class="grand"><span>Total</span><span>${money(t.total)}</span></div>` : '';
    $('#payBtn').disabled = !S.cart.length;
    $('#payBtn span').textContent = S.quotationId ? 'Charge quote' : 'Charge';
    $('#payAmt').textContent = S.cart.length ? money(t.total) : '';
    $('#barCount').textContent = MF.qty(t.items);
    $('#barTotal').textContent = money(t.total);
    $('#cartBar').classList.toggle('empty', !S.cart.length);
    saveDraft();
  }

  /* ---------------- Cart ops ---------------- */
  function add(pid, qty = 1) {
    const p = S.products.get(pid); if (!p) return;
    const ex = S.cart.find(l => l.pid === pid && l.price == null && !l.disc);
    if (ex) ex.qty = r2(ex.qty + qty); else S.cart.push({ pid, qty, price: null, disc: 0 });
    if (p.ts && !S.cfg.allow_negative && ((S.stock[pid] ?? 0) < (ex ? ex.qty : qty))) MF.toast(`${p.name}: only ${MF.qty(S.stock[pid] ?? 0)} in stock`, 'warn');
    if (navigator.vibrate) navigator.vibrate(12);
    const bar = $('#cartBar'); bar.classList.remove('bump'); void bar.offsetWidth; bar.classList.add('bump');
    S.couponDisc = 0; renderCart(); renderGrid();
  }
  function clearCart() {
    S.cart = []; S.customer = null; S.coupon = ''; S.couponDisc = 0; S.shipping = 0; S.notes = ''; S.quotationId = null; S.soldAt = '';
    setCustomer(null); renderCart(); renderGrid();
  }
  function saveDraft() { MF.store.set('mf-draft', { cart: S.cart, customer: S.customer, saleType: S.saleType, quotationId: S.quotationId }); }

  function editLine(i) {
    const l = S.cart[i], x = priceLine(l); if (!x) return;
    const canPrice = MF.can('sell.price_override'), canDisc = MF.can('sell.discount');
    MF.modal({
      title: x.p.name,
      body: `<div class="form-grid">
        <div class="field"><label>Quantity (${esc(x.p.unit || 'pc')})</label><input type="number" inputmode="decimal" step="any" min="0" name="qty" value="${MF.qty(l.qty)}"></div>
        <div class="field"><label>Unit price</label><input type="number" inputmode="decimal" step="0.01" name="price" value="${x.price}" ${canPrice ? '' : 'readonly'}>${x.p.mp > 0 ? `<div class="hint">Minimum ${money(x.p.mp)}</div>` : ''}</div>
        <div class="field full"><label>Line discount (amount)</label><div class="input-group"><input type="number" inputmode="decimal" step="0.01" min="0" name="disc" value="${l.disc || 0}" ${canDisc ? '' : 'readonly'}><button class="btn" type="button" data-pct>%</button></div>
        <div class="hint">${canDisc ? `Max ${S.cfg.max_discount_pct}% of the line` : 'You are not allowed to give discounts'}</div></div></div>`,
      foot: [
        { label: 'Remove', cls: 'btn-ghost text-danger', onClick: () => { S.cart.splice(i, 1); renderCart(); renderGrid(); } },
        { label: 'Update', cls: 'btn-primary', onClick: api => {
          const f = MF.formData(api.body);
          const q = num(f.qty); if (q <= 0) { S.cart.splice(i, 1); } else {
            l.qty = q;
            const pr = num(f.price); l.price = canPrice && Math.abs(pr - basePrice(x.p)) > 0.004 ? pr : null;
            l.disc = canDisc ? Math.max(0, num(f.disc)) : 0;
          }
          S.couponDisc = 0; renderCart(); renderGrid();
        } },
      ],
      onOpen: api => {
        $('[data-pct]', api.el).onclick = async () => {
          const pct = await MF.ask('Discount percent', { type: 'number', value: '5' }); if (pct == null) return;
          const gross = num($('[name=qty]', api.el).value) * num($('[name=price]', api.el).value);
          $('[name=disc]', api.el).value = r2(gross * num(pct) / 100);
        };
      },
    });
  }

  /* ---------------- Customers ---------------- */
  function setCustomer(c) {
    S.customer = c;
    const pick = $('#custPick'), q = $('#custQ');
    pick.classList.toggle('has', !!c); q.value = c ? c.name : '';
    $('#custClear').classList.toggle('hide', !c);
    const badge = $('#custDebt');
    if (c && +c.debt > 0) { badge.textContent = 'Owes ' + money(c.debt); badge.classList.remove('hide'); } else badge.classList.add('hide');
    if (c && c.price_tier === 'wholesale') setType('wholesale', true);
    S.couponDisc = 0; renderCart(); renderGrid();
  }
  const custSearch = MF.debounce(async () => {
    const q = $('#custQ').value.trim(), box = $('#custResults');
    let rows;
    if (!q) rows = S.recent || [];
    else { try { rows = await MF.get('customers.search', { q }, { quiet: true }); } catch (e) { rows = []; } }
    box.innerHTML = rows.map((c, i) => `<button type="button" data-ci="${i}"><span class="avatar" style="--h:${hue(c.name)}">${esc(c.name.slice(0, 2).toUpperCase())}</span><span class="grow">${esc(c.name)}<small>${esc(c.phone || '')}${+c.debt > 0 ? ' · owes ' + money(c.debt) : ''}</small></span></button>`).join('')
      + (MF.can('customers.manage') ? `<button type="button" data-new>${MF.ic('plus')}<span>Add new customer${q ? ' “' + esc(q) + '”' : ''}</span></button>` : '');
    box._rows = rows; box.classList.remove('hide');
  }, 180);
  function quickAddCustomer(name) {
    MF.modal({
      title: 'New customer',
      body: `<div class="field"><label>Name</label><input name="name" value="${esc(/^[\d+ ]+$/.test(name) ? '' : name)}" required></div>
        <div class="field"><label>Phone</label><input name="phone" type="tel" inputmode="tel" value="${esc(/^[\d+ ]+$/.test(name) ? name : '')}" placeholder="07…"></div>
        <div class="field"><label>Email (for receipts & offers)</label><input name="email" type="email"></div>`,
      foot: [{ label: 'Cancel', cls: 'btn-ghost' }, { label: 'Save & use', cls: 'btn-primary', onClick: async api => {
        const c = await MF.api('customers.quick_add', MF.formData(api.body)); setCustomer(c); MF.toast('Customer added', 'ok');
      } }],
    });
  }

  function setType(t, silent) {
    S.saleType = t;
    $$('#typeSeg button').forEach(b => b.classList.toggle('on', b.dataset.type === t));
    if (!silent) { S.couponDisc = 0; renderCart(); renderGrid(); }
  }

  /* ---------------- Payment sheet ---------------- */
  function accountOptions(sel) {
    const opts = [`<option value="cash" ${sel === 'cash' ? 'selected' : ''}>Cash</option>`];
    S.accounts.filter(a => a.method !== 'cash').forEach(a => {
      const lbl = `${a.method === 'mpesa' ? 'M-Pesa' : a.method === 'bank' ? 'Bank' : a.method.toUpperCase()} · ${a.name}${a.account_number ? ' (' + a.account_number + ')' : ''}`;
      opts.push(`<option value="${a.id}" ${String(sel) === String(a.id) ? 'selected' : ''}>${esc(lbl)}</option>`);
    });
    if (!S.accounts.some(a => a.method === 'mpesa')) opts.push(`<option value="mpesa" ${sel === 'mpesa' ? 'selected' : ''}>M-Pesa</option>`);
    if (!S.accounts.some(a => a.method === 'bank')) opts.push(`<option value="bank" ${sel === 'bank' ? 'selected' : ''}>Bank</option>`);
    return opts.join('');
  }
  function rowMeta(v) {
    if (v === 'cash' || v === 'mpesa' || v === 'bank') return { method: v, account_id: null, acc: null };
    const acc = S.accounts.find(a => String(a.id) === String(v));
    return acc ? { method: acc.method, account_id: acc.id, acc } : { method: 'cash', account_id: null, acc: null };
  }

  function openPay() {
    if (!S.cart.length || document.querySelector('dialog[open]')) return;
    const tpl = $('#payTpl').content.cloneNode(true);
    let rows = [{ sel: 'cash', amount: '', reference: '', mpesa_tx_id: null }];
    const m = MF.modal({ title: S.quotationId ? 'Charge quotation' : 'Take payment', body: tpl, foot: [
      { label: 'Cancel', cls: 'btn-ghost' },
      { label: 'Complete sale', cls: 'btn-primary btn-lg', icon: 'check', onClick: () => complete(rows, m) },
    ] });
    const el = m.body;
    const due = () => totals().total;
    const cashAcc = S.accounts.find(a => a.method === 'cash');
    const quick = [{ k: 'cash', label: 'Cash exact', icon: 'cash', sel: cashAcc ? String(cashAcc.id) : 'cash' }];
    S.accounts.filter(a => a.method === 'mpesa').slice(0, 2).forEach(a => quick.push({ k: 'm' + a.id, label: a.name, icon: 'phone', sel: String(a.id) }));
    if (!S.accounts.some(a => a.method === 'mpesa')) quick.push({ k: 'mpesa', label: 'M-Pesa', icon: 'phone', sel: 'mpesa' });
    const bank = S.accounts.find(a => a.method === 'bank'); quick.push({ k: 'bank', label: bank ? bank.name : 'Bank', icon: 'bank', sel: bank ? String(bank.id) : 'bank' });
    if (MF.can('sell.credit')) quick.push({ k: 'credit', label: 'On credit', icon: 'wallet', sel: null });
    $('[data-quick]', el).innerHTML = quick.slice(0, 6).map(q => `<button type="button" data-q="${q.k}">${MF.ic(q.icon)}<span>${esc(q.label)}</span></button>`).join('');
    $('[data-quick]', el).addEventListener('click', e => {
      const b = e.target.closest('[data-q]'); if (!b) return;
      const q = quick.find(x => x.k === b.dataset.q);
      rows = q.sel ? [{ sel: q.sel, amount: String(due()), reference: '', mpesa_tx_id: null }] : [{ sel: 'cash', amount: '', reference: '', mpesa_tx_id: null }];
      if (!q.sel && !S.customer) MF.toast('Select the customer who is taking the credit', 'warn');
      drawRows(); if (q.sel && rowMeta(q.sel).method !== 'cash') $('.pay-row [data-ref]', el)?.focus();
    });

    function drawRows() {
      $('[data-rows]', el).innerHTML = rows.map((r, i) => {
        const meta = rowMeta(r.sel);
        const isCash = meta.method === 'cash';
        const stk = S.cfg.mpesa && meta.method === 'mpesa' && (!meta.acc || +meta.acc.stk);
        const d = due();
        const tenders = isCash ? [...new Set([d, Math.ceil(d / 50) * 50, Math.ceil(d / 100) * 100, Math.ceil(d / 500) * 500, Math.ceil(d / 1000) * 1000])].filter(v => v >= d).slice(0, 4) : [];
        return `<div class="pay-row" data-r="${i}">
          <select data-sel>${accountOptions(r.sel)}</select>
          <input class="amt" type="number" inputmode="decimal" step="0.01" min="0" placeholder="0.00" value="${esc(r.amount)}" data-amt ${r.mpesa_tx_id ? 'readonly' : ''}>
          <button type="button" class="btn btn-sm btn-ghost btn-icon" data-del ${rows.length < 2 ? 'disabled' : ''}>${MF.ic('x', 'sm')}</button>
          ${isCash ? `<div class="tender">${tenders.map(v => `<button type="button" data-tender="${v}">${money(v, false)}</button>`).join('')}</div>`
            : `<input class="ref" type="text" placeholder="${meta.method === 'mpesa' ? 'M-Pesa code e.g. SGH7XK2L9P' : 'Reference / slip no.'}" value="${esc(r.reference)}" data-ref ${r.mpesa_tx_id ? 'readonly' : ''} style="text-transform:uppercase">
               ${stk && !r.mpesa_tx_id ? `<div class="stk"><input type="tel" inputmode="tel" placeholder="Customer phone 07…" value="${esc(r.phone || (S.customer && S.customer.phone) || '')}" data-phone><button type="button" class="btn btn-green" data-stk>${MF.ic('send', 'sm')} Send STK</button></div>` : ''}
               ${r.mpesa_tx_id ? `<div class="stk badge badge-green">${MF.ic('check', 'sm')} Confirmed ${esc(r.reference)}</div>` : ''}`}
        </div>`;
      }).join('');
      recalc();
    }
    function recalc() {
      const d = due();
      let paidCash = 0, paidOther = 0;
      rows.forEach(r => { const a = num(r.amount); if (rowMeta(r.sel).method === 'cash') paidCash += a; else paidOther += a; });
      const paid = paidCash + paidOther;
      const change = Math.max(0, r2(paid - d));
      const bal = Math.max(0, r2(d - paid));
      $('[data-due]', el).textContent = money(d);
      $('[data-paid]', el).textContent = money(Math.min(paid, d));
      const sum = $('[data-bal]', el).parentElement;
      sum.className = change > 0 ? 'change' : bal > 0 ? 'owing' : '';
      $('[data-ballabel]', el).textContent = change > 0 ? 'Change' : bal > 0 ? 'Balance (credit)' : 'Balance';
      $('[data-bal]', el).textContent = money(change > 0 ? change : bal);
      const cr = $('[data-credit]', el);
      if (bal > 0 && paid >= 0) {
        cr.classList.remove('hide');
        cr.innerHTML = S.customer ? `${MF.ic('wallet')}<span><b>${money(bal)}</b> will be recorded as credit for <b>${esc(S.customer.name)}</b>.${+S.customer.credit_limit > 0 ? ` Limit ${money(S.customer.credit_limit)}, owes ${money(S.customer.debt || 0)}.` : ''}</span>`
          : `${MF.ic('alert')}<span>Select a customer to sell the remaining <b>${money(bal)}</b> on credit.</span>`;
      } else cr.classList.add('hide');
      if (paidOther > d + 0.009) { cr.classList.remove('hide'); cr.innerHTML = MF.ic('alert') + '<span>Only cash can be more than the total (change).</span>'; }
    }
    el.addEventListener('input', e => {
      const row = e.target.closest('[data-r]'); if (!row) return; const r = rows[+row.dataset.r];
      if (e.target.matches('[data-amt]')) { r.amount = e.target.value; recalc(); }
      if (e.target.matches('[data-ref]')) r.reference = e.target.value.toUpperCase();
      if (e.target.matches('[data-phone]')) r.phone = e.target.value;
    });
    el.addEventListener('change', e => {
      if (!e.target.matches('[data-sel]')) return;
      const r = rows[+e.target.closest('[data-r]').dataset.r]; r.sel = e.target.value; r.mpesa_tx_id = null; drawRows();
    });
    el.addEventListener('click', async e => {
      const row = e.target.closest('[data-r]');
      if (e.target.closest('[data-del]')) { rows.splice(+row.dataset.r, 1); drawRows(); return; }
      const t = e.target.closest('[data-tender]'); if (t) { rows[+row.dataset.r].amount = t.dataset.tender; drawRows(); return; }
      const stk = e.target.closest('[data-stk]');
      if (stk) {
        const r = rows[+row.dataset.r]; const amt = num(r.amount) || due(); r.amount = String(amt);
        stk.classList.add('loading');
        try { await stkPush(r, amt, rowMeta(r.sel).account_id); drawRows(); } finally { stk.classList.remove('loading'); }
      }
    });
    $('[data-addrow]', el).onclick = () => {
      const paid = rows.reduce((s, r) => s + num(r.amount), 0);
      rows.push({ sel: S.accounts.find(a => a.method === 'mpesa') ? String(S.accounts.find(a => a.method === 'mpesa').id) : 'mpesa', amount: String(Math.max(0, r2(due() - paid))), reference: '', mpesa_tx_id: null });
      drawRows();
    };
    const coupon = $('[data-coupon]', el); coupon.value = S.coupon;
    $('[data-ship]', el).value = S.shipping || 0;
    $('[data-notes]', el).value = S.notes;
    $('[data-applycoupon]', el).onclick = async () => {
      S.coupon = coupon.value.trim().toUpperCase();
      if (!S.coupon) { S.couponDisc = 0; renderCart(); recalc(); return; }
      try {
        const r = await MF.api('sell.price', payload());
        S.couponDisc = r.coupon_discount; MF.toast(`Coupon applied: −${money(r.coupon_discount)}`, 'ok');
      } catch (e) { S.coupon = ''; S.couponDisc = 0; }
      renderCart(); drawRows();
    };
    $('[data-ship]', el).addEventListener('input', e => { S.shipping = num(e.target.value); renderCart(); recalc(); });
    $('[data-notes]', el).addEventListener('input', e => { S.notes = e.target.value; });
    const sa = $('[data-soldat]', el); if (sa) sa.addEventListener('input', e => { S.soldAt = e.target.value; });
    drawRows();
  }

  async function stkPush(r, amount, accountId) {
    const phone = (r.phone || '').trim(); if (!phone) { MF.toast('Enter the customer phone number', 'warn'); return; }
    const d = await MF.api('mpesa.stk_push', { phone, amount, account_id: accountId, reference: 'POS' });
    MF.toast('Prompt sent. Ask the customer to enter their M-Pesa PIN…');
    const started = Date.now();
    while (Date.now() - started < 120000) {
      await new Promise(res => setTimeout(res, 3000));
      const st = await MF.get('mpesa.status', { id: d.id }, { quiet: true }).catch(() => null);
      if (!st) continue;
      if (st.status === 'success') { r.mpesa_tx_id = d.id; r.reference = st.mpesa_receipt; r.amount = String(st.amount); MF.toast('M-Pesa received: ' + st.mpesa_receipt, 'ok'); return; }
      if (st.status === 'failed' || st.status === 'cancelled') { MF.toast('M-Pesa: ' + (st.result_desc || st.status), 'err'); return; }
    }
    MF.toast('No confirmation yet. Enter the code manually if the customer paid.', 'warn');
  }

  function payload() {
    return {
      store_id: store, customer_id: S.customer ? S.customer.id : null, sale_type: S.saleType, coupon: S.coupon || null,
      shipping: S.shipping || 0, notes: S.notes, quotation_id: S.quotationId, sold_at: S.soldAt || null,
      items: S.cart.map(l => ({ product_id: l.pid, qty: l.qty, unit_price: l.price, discount: l.disc || 0 })),
    };
  }

  async function complete(rows, modal) {
    const t = totals();
    const pays = rows.filter(r => num(r.amount) > 0).map(r => {
      const meta = rowMeta(r.sel);
      return { method: meta.method, account_id: meta.account_id, amount: num(r.amount), reference: r.reference || null, mpesa_tx_id: r.mpesa_tx_id };
    });
    const paid = pays.reduce((s, p) => s + p.amount, 0);
    if (paid + 0.009 < t.total && !S.customer) { MF.toast('Payment is short. Add a customer to sell the balance on credit.', 'err'); return false; }
    const body = { ...payload(), payments: pays, client_ref: MF.uuid() };
    let res;
    try {
      res = await MF.api('sell.checkout', body, { quiet: true });
    } catch (e) {
      if (e.offline) {
        MF.queue.add(body);
        finish({ offline: true, total: t.total, change: Math.max(0, r2(paid - t.total)) }, modal);
        return;
      }
      MF.toast(e.message, 'err'); return false;
    }
    Object.entries(res.stock || {}).forEach(([pid, q]) => { S.stock[pid] = q; });
    saveStockCache();
    finish(res, modal);
  }

  function finish(res, payModal) {
    S.cart.forEach(l => { S.freq[l.pid] = (S.freq[l.pid] || 0) + 1; });
    MF.store.set('mf-freq', S.freq);
    const cust = S.customer;
    payModal.close();
    clearCart();
    MF.store.del('mf-draft');
    document.body.classList.remove('cart-open');
    const size = S.cfg.receipt_size || 'thermal';
    const m = MF.modal({
      title: res.offline ? 'Saved offline' : 'Sale complete',
      body: `<div class="done"><div class="tick">${MF.ic(res.offline ? 'wifi-off' : 'check')}</div>
        ${res.offline ? '<p>No connection — the sale is stored on this device and will sync automatically.</p>' : `<h3>${esc(res.invoice_no)}</h3>`}
        <div class="muted">Total ${money(res.total)}${res.balance > 0 ? ' · credit ' + money(res.balance) : ''}</div>
        ${res.change > 0 ? `<div class="muted mt">Change due</div><div class="chg">${money(res.change)}</div>` : ''}
        ${res.offline ? '' : `<div class="acts">
          <button class="btn btn-lg" data-pr="thermal">${MF.ic('receipt')} Receipt</button>
          <button class="btn btn-lg" data-pr="a4">${MF.ic('file')} A4 invoice</button>
          <a class="btn" target="_blank" rel="noopener" href="https://wa.me/${cust && cust.phone ? String(cust.phone).replace(/\D/g, '').replace(/^0/, '254') : ''}?text=${encodeURIComponent(`${MF.biz}: Receipt ${res.invoice_no}\nTotal ${money(res.total)}${res.balance > 0 ? '\nBalance ' + money(res.balance) : ''}\nAsante!`)}">${MF.ic('send')} WhatsApp</a>
          <button class="btn" data-share>${MF.ic('mail')} Email</button></div>`}
        <button class="btn btn-primary btn-lg btn-block mt" data-new>${MF.ic('plus')} New sale</button></div>`,
      foot: false,
      onOpen: api => {
        $$('[data-pr]', api.el).forEach(b => b.onclick = () => MF.print('receipt', res.id, b.dataset.pr));
        const sh = $('[data-share]', api.el); if (sh) sh.onclick = () => MF.printMenu('receipt', res.id);
        $('[data-new]', api.el).onclick = () => api.close();
        setTimeout(() => $('[data-new]', api.el).focus(), 50);
      },
      onClose: () => { renderGrid(); if (matchMedia('(min-width: 900px)').matches) $('#q').focus(); },
    });
    if (!res.offline && S.cfg.auto_print && matchMedia('(min-width: 900px)').matches) printFrame('receipt', res.id, size);
  }

  /* Silent print via hidden iframe (desktop counters). */
  function printFrame(doc, id, size) {
    let f = $('#printFrame');
    if (!f) { f = document.createElement('iframe'); f.id = 'printFrame'; f.style.cssText = 'position:fixed;width:0;height:0;border:0;right:0;bottom:0'; document.body.appendChild(f); }
    f.src = `print.php?doc=${doc}&id=${id}&size=${size}&auto=1&frame=1`;
  }

  /* ---------------- Hold / resume ---------------- */
  function held() { return MF.store.get('mf-held', []); }
  function updateHeld() { $('#heldCount').textContent = held().length; }
  function holdSale() {
    if (!S.cart.length) return MF.toast('Cart is empty', 'warn');
    const h = held(); h.unshift({ id: Date.now(), at: new Date().toISOString(), cart: S.cart, customer: S.customer, saleType: S.saleType, total: totals().total });
    MF.store.set('mf-held', h.slice(0, 20)); clearCart(); updateHeld(); MF.toast('Sale held', 'ok');
  }
  function showHeld() {
    const h = held();
    MF.modal({
      title: 'Held sales',
      body: h.length ? `<ul class="list">${h.map((x, i) => `<li><div class="meta"><b>${esc(x.customer ? x.customer.name : 'Walk-in')} · ${money(x.total)}</b><span>${x.cart.length} lines · ${new Date(x.at).toLocaleTimeString()}</span></div>
        <button class="btn btn-sm btn-primary" data-resume="${i}">Resume</button><button class="btn btn-sm btn-ghost btn-icon" data-drop="${i}">${MF.ic('trash', 'sm')}</button></li>`).join('')}</ul>` : '<div class="empty">No held sales</div>',
      foot: false,
      onOpen: api => api.body.addEventListener('click', e => {
        const r = e.target.closest('[data-resume]'), d = e.target.closest('[data-drop]');
        const list = held();
        if (r) {
          if (S.cart.length) holdSale();
          const x = list.splice(+r.dataset.resume, 1)[0]; MF.store.set('mf-held', list);
          S.cart = x.cart; setType(x.saleType, true); setCustomer(x.customer); updateHeld(); api.close();
        }
        if (d) { list.splice(+d.dataset.drop, 1); MF.store.set('mf-held', list); updateHeld(); api.close(); showHeld(); }
      }),
    });
  }

  async function saveQuote() {
    if (!S.cart.length) return MF.toast('Cart is empty', 'warn');
    const r = await MF.api('sell.quote', payload());
    MF.toast('Quotation ' + r.quote_no + ' saved', 'ok');
    clearCart();
    MF.printMenu('quote', r.id);
  }

  /* ---------------- Camera barcode scanning ---------------- */
  async function scan() {
    let stream, stop = false;
    const m = MF.modal({ title: 'Scan barcode', body: '<div class="scanner"><video playsinline muted></video><p class="muted small mt">Point the camera at the barcode</p></div>', foot: false,
      onClose: () => { stop = true; stream && stream.getTracks().forEach(t => t.stop()); } });
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
      const v = $('video', m.el); v.srcObject = stream; await v.play();
      const det = new window.BarcodeDetector();
      while (!stop) {
        const codes = await det.detect(v).catch(() => []);
        if (codes.length) {
          const code = codes[0].rawValue;
          const p = [...S.products.values()].find(x => x.barcode === code || x.sku === code);
          if (p) { add(p.id); MF.toast('Added ' + p.name, 'ok'); } else MF.toast('No product with code ' + code, 'warn');
          await new Promise(r => setTimeout(r, 1200));
        }
        await new Promise(r => setTimeout(r, 180));
      }
    } catch (e) { MF.toast('Camera unavailable: ' + e.message, 'err'); m.close(); }
  }

  /* ---------------- Wire up ---------------- */
  MF.ready(async () => {
    if ('BarcodeDetector' in window && navigator.mediaDevices) $('#scanBtn').classList.remove('hide');
    const draft = MF.store.get('mf-draft');
    loadCatalog().then(() => {
      if (draft && draft.cart && draft.cart.length && !S.cart.length) {
        S.cart = draft.cart.filter(l => S.products.has(l.pid)); S.quotationId = draft.quotationId || null;
        setType(draft.saleType || 'retail', true); setCustomer(draft.customer || null);
      }
    });
    try {
      const b = await MF.get('sell.bootstrap');
      S.accounts = b.accounts; S.promos = b.promotions; S.cfg = b.settings; S.recent = b.recent_customers;
      if (!draft || !draft.saleType) setType(S.cfg.default_sale_type || 'retail', true);
      if (S.cfg.require_shift && !b.shift) MF.toast('Open your register shift before selling (Sales → Register shifts)', 'warn');
      renderGrid(); renderCart();
    } catch (e) { /* offline: use defaults */ }

    const params = new URLSearchParams(location.search);
    if (params.get('quote') || params.get('again')) {
      const d = await MF.get('sell.load', params.get('quote') ? { quote: params.get('quote') } : { sale: params.get('again') });
      if (d.status && d.status !== 'pending') MF.toast('This quotation is already ' + d.status, 'warn');
      const wait = () => S.products.size ? Promise.resolve() : new Promise(r => setTimeout(() => r(wait()), 200));
      await wait();
      S.cart = d.items.filter(i => S.products.has(+i.product_id)).map(i => ({ pid: +i.product_id, qty: +i.qty, price: null, disc: +i.discount || 0 }));
      S.quotationId = d.quotation_id || null; setType(d.sale_type, true); setCustomer(d.customer);
      history.replaceState(null, '', MF.url('sell'));
    }
    updateHeld();

    const q = $('#q');
    q.addEventListener('input', MF.debounce(() => { S.q = q.value.trim(); renderLimit = 120; renderGrid(); }, 60));
    q.addEventListener('keydown', e => {
      if (e.key === 'Escape') { q.value = ''; S.q = ''; renderGrid(); }
      if (e.key !== 'Enter') return;
      e.preventDefault();
      const v = q.value.trim(); if (!v) return;
      const exact = [...S.products.values()].find(p => p.barcode === v || (p.sku && p.sku.toLowerCase() === v.toLowerCase()));
      const list = exact ? [exact] : filtered();
      if (list.length >= 1) { add(list[0].id); q.value = ''; S.q = ''; renderGrid(); }
      else MF.toast('No product found for “' + v + '”', 'warn');
    });
    $('#grid').addEventListener('click', e => {
      if (e.target.closest('[data-more]')) { renderLimit += 200; renderGrid(); return; }
      const t = e.target.closest('[data-pid]'); if (t) add(+t.dataset.pid);
    });
    $('#cats').addEventListener('click', e => { const c = e.target.closest('[data-cat]'); if (!c) return; S.cat = c.dataset.cat; renderCats(); renderGrid(); });
    $$('#viewSeg button').forEach(b => { b.classList.toggle('on', b.dataset.view === S.view); b.onclick = () => { S.view = b.dataset.view; MF.store.set('mf-view', S.view); $$('#viewSeg button').forEach(x => x.classList.toggle('on', x === b)); renderGrid(); }; });
    $('#lines').addEventListener('click', e => {
      const line = e.target.closest('[data-i]'); if (!line) return; const i = +line.dataset.i; const l = S.cart[i];
      if (e.target.closest('[data-inc]')) { l.qty = r2(l.qty + 1); }
      else if (e.target.closest('[data-dec]')) { l.qty = r2(l.qty - 1); if (l.qty <= 0) S.cart.splice(i, 1); }
      else if (e.target.closest('[data-rm]')) { S.cart.splice(i, 1); }
      else if (e.target.closest('[data-edit]')) { editLine(i); return; }
      else return;
      S.couponDisc = 0; renderCart(); renderGrid();
    });
    $('#lines').addEventListener('change', e => {
      if (!e.target.matches('[data-qty]')) return;
      const i = +e.target.closest('[data-i]').dataset.i; const v = num(e.target.value);
      if (v <= 0) S.cart.splice(i, 1); else S.cart[i].qty = v;
      S.couponDisc = 0; renderCart(); renderGrid();
    });
    $$('#typeSeg button').forEach(b => b.onclick = () => setType(b.dataset.type));
    $('#payBtn').onclick = openPay;
    $('#cartBar').onclick = () => document.body.classList.add('cart-open');
    $('#cartClose').onclick = () => document.body.classList.remove('cart-open');
    $('#holdBtn').onclick = holdSale; $('#heldBtn').onclick = showHeld;
    $('#quoteBtn') && ($('#quoteBtn').onclick = saveQuote);
    $('#clearBtn').onclick = async () => { if (!S.cart.length || await MF.confirm('Clear the cart?', { danger: true, ok: 'Clear' })) clearCart(); };
    $('#reloadCat').onclick = () => { MF.store.del(CAT_KEY); loadCatalog(true); MF.toast('Refreshing products…'); };
    $('#scanBtn').onclick = scan;

    const cq = $('#custQ');
    cq.addEventListener('focus', () => { if (!S.customer) custSearch(); });
    cq.addEventListener('input', () => { if (S.customer) { S.customer = null; $('#custPick').classList.remove('has'); $('#custClear').classList.add('hide'); $('#custDebt').classList.add('hide'); } custSearch(); });
    $('#custResults').addEventListener('mousedown', e => e.preventDefault());
    $('#custResults').addEventListener('click', e => {
      const box = $('#custResults');
      const ci = e.target.closest('[data-ci]'); if (ci) { setCustomer(box._rows[+ci.dataset.ci]); box.classList.add('hide'); cq.blur(); }
      if (e.target.closest('[data-new]')) { box.classList.add('hide'); quickAddCustomer(cq.value.trim()); }
    });
    cq.addEventListener('blur', () => setTimeout(() => $('#custResults').classList.add('hide'), 150));
    $('#custClear').onclick = () => setCustomer(null);

    document.addEventListener('keydown', e => {
      if (document.querySelector('dialog[open]')) return;
      if (e.key === '/' && document.activeElement !== q) { e.preventDefault(); q.focus(); }
      if (e.key === 'F9' || (e.key === 'Enter' && (e.ctrlKey || e.metaKey))) { e.preventDefault(); openPay(); }
    });
    if (matchMedia('(min-width: 900px)').matches) q.focus();
    renderCart();
  });
})();
