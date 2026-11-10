<?php $cats = DB::all('SELECT id, name FROM categories WHERE is_active=1 ORDER BY name'); ?>
<div class="page-head"><h2 class="grow">Stock take</h2></div>
<div class="card mb">
  <div class="card-head" style="flex-wrap:wrap">
    <h3 class="grow">Count sheet · <?= e(DB::val('SELECT name FROM stores WHERE id=?', [$storeId])) ?></h3>
    <select id="cat" style="max-width:200px"><option value="">All categories</option><?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
    <button class="btn" id="load"><?= icon('clipboard') ?> Load sheet</button>
    <a class="btn btn-ghost" href="#" id="blind" title="Hide system quantities while counting">Blind count</a>
  </div>
  <div class="card-head"><div class="search-box grow"><?= icon('search') ?><input type="search" id="f" placeholder="Filter sheet…"></div><span class="muted small" id="prog"></span></div>
  <div class="table-wrap" style="max-height:60vh"><table class="table"><thead><tr><th>Product</th><th class="num sys">System</th><th class="num">Counted</th><th class="num sys">Variance</th></tr></thead><tbody id="sheet"><tr><td colspan="4" class="muted" style="text-align:center;padding:24px">Load a sheet to start counting. Leave a row blank to skip it.</td></tr></tbody></table></div>
  <div class="card-foot row"><input id="notes" class="grow" placeholder="Notes"><button class="btn btn-primary" id="post"><?= icon('check') ?> Post stock take</button></div>
</div>
<div class="card"><div class="card-head"><h3>Previous stock takes</h3></div><div id="tbl"></div></div>
<script>
MF.ready(() => {
  const { $, $$, esc, money } = MF;
  let rows = [];
  const draft = MF.store.get('mf-stocktake', {});
  function prog() { const n = $$('[data-c]').filter(i => i.value !== '').length; $('#prog').textContent = `${n} / ${rows.length} counted`; }
  $('#load').onclick = async () => {
    rows = await MF.get('stock.stocktake_sheet', { category: $('#cat').value });
    $('#sheet').innerHTML = rows.map(r => `<tr data-n="${esc(r.name.toLowerCase())} ${esc((r.sku || '').toLowerCase())}"><td><b>${esc(r.name)}</b><div class="tiny muted">${esc(r.sku || '')} ${esc(r.unit)}</div></td><td class="num sys">${MF.qty(r.qty)}</td>
      <td class="num"><input type="number" step="any" inputmode="decimal" data-c="${r.id}" data-s="${r.qty}" value="${draft[r.id] ?? ''}" style="max-width:110px;text-align:right"></td><td class="num sys" data-v="${r.id}"></td></tr>`).join('');
    $$('[data-c]').forEach(upd); prog();
  };
  function upd(i) { const v = $(`[data-v="${i.dataset.c}"]`); if (i.value === '') { v.textContent = ''; return; } const d = +i.value - +i.dataset.s; v.innerHTML = `<b class="${d < 0 ? 'text-danger' : d > 0 ? 'text-green' : 'muted'}">${d > 0 ? '+' : ''}${MF.qty(d)}</b>`; }
  $('#sheet').addEventListener('input', e => { if (!e.target.dataset.c) return; upd(e.target); draft[e.target.dataset.c] = e.target.value; MF.store.set('mf-stocktake', draft); prog(); });
  $('#f').addEventListener('input', () => { const q = $('#f').value.toLowerCase(); $$('#sheet tr').forEach(tr => tr.style.display = !q || (tr.dataset.n || '').includes(q) ? '' : 'none'); });
  $('#blind').onclick = e => { e.preventDefault(); $$('.sys').forEach(x => x.classList.toggle('hide')); };
  $('#post').onclick = async () => {
    const counts = $$('[data-c]').filter(i => i.value !== '').map(i => ({ product_id: +i.dataset.c, counted: i.value }));
    if (!counts.length) return MF.toast('Enter counts first', 'warn');
    if (!await MF.confirm(`Post ${counts.length} counts? Stock will be adjusted to the counted quantities.`)) return;
    const r = await MF.api('stock.stocktake_post', { counts, notes: $('#notes').value });
    MF.store.del('mf-stocktake'); MF.toast(`${r.take_no} posted · variance ${money(r.variance_value)}`, 'ok'); MF.printMenu('stocktake', r.id); t.reload(); $('#load').click();
  };
  const t = MF.table($('#tbl'), { action: 'stock.stocktakes', emptyIcon: 'clipboard', columns: [
    { key: 'take_no', label: 'No.', render: r => `<b>${esc(r.take_no)}</b>` }, { key: 'created_at', label: 'Date', render: r => esc(r.created_at.slice(0, 16)) },
    { key: 'store', label: 'Store' }, { key: 'user', label: 'By' }, { key: 'items', label: 'Lines', num: true },
    { key: 'variance_value', label: 'Variance', num: true, render: r => `<b class="${+r.variance_value < 0 ? 'text-danger' : 'text-green'}">${money(r.variance_value, false)}</b>` },
    { key: '_p', label: '', cls: 'actions', render: () => `<button class="btn btn-sm btn-ghost" data-act="p">${MF.ic('printer', 'sm')}</button>` },
  ], onAction: (a, r) => MF.printMenu('stocktake', r.id) });
});
</script>
