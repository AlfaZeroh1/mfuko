/* Product line picker for back-office documents (GRN, transfers, stock take). */
(function () {
  'use strict';
  const { $, esc, money, num } = MF;
  /**
   * MF.lineEditor(root, { cols: [{key,label,type:'qty'|'money'|'date'|'text', def(p)}], store, total(line) })
   * returns { lines, clear() }
   */
  MF.lineEditor = function (root, cfg) {
    const lines = [];
    root.innerHTML = `<div class="search-box" style="position:relative">${MF.ic('search')}<input type="search" placeholder="Search product to add…" data-ps autocomplete="off">
      <div class="cust-results hide" data-pr style="position:absolute;left:0;right:0;top:calc(100% + 4px);z-index:30;background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow-lg);max-height:300px;overflow:auto"></div></div>
      <div class="table-wrap mt"><table class="table"><thead><tr><th>Product</th>${cfg.cols.map(c => `<th class="${c.type === 'text' || c.type === 'date' ? '' : 'num'}">${esc(c.label)}</th>`).join('')}${cfg.total ? '<th class="num">Total</th>' : ''}<th></th></tr></thead><tbody data-lines></tbody>${cfg.total ? '<tfoot data-foot></tfoot>' : ''}</table></div>`;
    const ps = $('[data-ps]', root), pr = $('[data-pr]', root), tb = $('[data-lines]', root), tf = $('[data-foot]', root);
    let results = [];
    const search = MF.debounce(async () => {
      const q = ps.value.trim(); if (!q) { pr.classList.add('hide'); return; }
      const d = await MF.get('products.list', { q, per: 12, store: cfg.store ? cfg.store() : '' }, { quiet: true }).catch(() => ({ rows: [] }));
      results = d.rows;
      pr.innerHTML = results.map((p, i) => `<button type="button" data-i="${i}" style="display:flex;width:100%;gap:10px;padding:9px 12px;border:0;background:none;font:inherit;color:var(--text);text-align:left;cursor:pointer;border-bottom:1px solid var(--border)"><span class="grow"><b>${esc(p.name)}</b><small class="muted" style="display:block">${esc(p.sku || '')} · stock ${MF.qty(p.qty)}</small></span></button>`).join('') || '<div class="muted" style="padding:10px">No match</div>';
      pr.classList.remove('hide');
    }, 200);
    ps.addEventListener('input', search);
    ps.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); if (results[0]) add(results[0]); } });
    pr.addEventListener('mousedown', e => e.preventDefault());
    pr.addEventListener('click', e => { const b = e.target.closest('[data-i]'); if (b) add(results[+b.dataset.i]); });
    ps.addEventListener('blur', () => setTimeout(() => pr.classList.add('hide'), 150));
    function add(p) {
      if (lines.some(l => l.product_id === p.id)) { MF.toast('Already added', 'warn'); }
      else { const l = { product_id: p.id, name: p.name, unit: p.unit, stock: +p.qty, p }; cfg.cols.forEach(c => l[c.key] = c.def ? c.def(p) : ''); lines.push(l); }
      ps.value = ''; pr.classList.add('hide'); draw(); ps.focus();
    }
    function draw() {
      tb.innerHTML = lines.map((l, i) => `<tr data-r="${i}"><td><b>${esc(l.name)}</b><div class="tiny muted">stock ${MF.qty(l.stock)} ${esc(l.unit || '')}</div></td>
        ${cfg.cols.map(c => `<td class="${c.type === 'text' || c.type === 'date' ? '' : 'num'}"><input data-k="${c.key}" type="${c.type === 'date' ? 'date' : c.type === 'text' ? 'text' : 'number'}" step="any" value="${esc(l[c.key])}" style="max-width:${c.type === 'date' ? 150 : c.type === 'text' ? 200 : 110}px;${c.type === 'text' || c.type === 'date' ? '' : 'text-align:right'}"></td>`).join('')}
        ${cfg.total ? `<td class="num" data-t>${money(cfg.total(l), false)}</td>` : ''}<td><button class="btn btn-sm btn-ghost btn-icon" data-x>${MF.ic('x', 'sm')}</button></td></tr>`).join('')
        || `<tr><td colspan="${cfg.cols.length + 3}" class="muted" style="text-align:center;padding:24px">Search above to add products</td></tr>`;
      foot();
    }
    function foot() { if (tf) tf.innerHTML = `<tr><td colspan="${cfg.cols.length + 1}">Total</td><td class="num">${money(lines.reduce((s, l) => s + cfg.total(l), 0))}</td><td></td></tr>`; cfg.onChange && cfg.onChange(lines); }
    tb.addEventListener('input', e => {
      const tr = e.target.closest('[data-r]'); if (!tr || !e.target.dataset.k) return;
      const l = lines[+tr.dataset.r]; l[e.target.dataset.k] = e.target.value;
      if (cfg.total) $('[data-t]', tr).textContent = money(cfg.total(l), false);
      foot();
    });
    tb.addEventListener('click', e => { if (e.target.closest('[data-x]')) { lines.splice(+e.target.closest('[data-r]').dataset.r, 1); draw(); } });
    draw();
    return { lines, clear() { lines.length = 0; draw(); }, add };
  };
})();
