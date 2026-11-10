/* Generic resource list + form (driven by app/resources.php). */
(function () {
  'use strict';
  const { $, esc, money } = MF;
  const refCache = {};
  async function refOptions(t) { if (!refCache[t]) refCache[t] = MF.get('resource.options', { t }); return refCache[t]; }

  function fieldHtml(name, f, val) {
    const req = f.required ? 'required' : '';
    const ph = f.placeholder ? `placeholder="${esc(f.placeholder)}"` : '';
    const hint = f.hint ? `<div class="hint">${esc(f.hint)}</div>` : '';
    const wrap = (inner) => `<div class="field ${f.full ? 'full' : ''}"><label>${esc(f.label)}${f.required ? ' *' : ''}</label>${inner}${hint}</div>`;
    switch (f.type) {
      case 'textarea': return wrap(`<textarea name="${name}" ${ph}>${esc(val)}</textarea>`);
      case 'switch': return `<div class="field ${f.full ? 'full' : ''}"><label class="check"><span class="switch"><input type="checkbox" name="${name}" ${+val ? 'checked' : ''}><span></span></span>${esc(f.label)}</label>${hint}</div>`;
      case 'select': return wrap(`<select name="${name}" ${req}>${Object.entries(f.options).map(([k, v]) => `<option value="${esc(k)}" ${String(val) === k ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select>`);
      case 'ref': return wrap(`<select name="${name}" data-ref="${f.ref}" data-val="${esc(val)}"><option value="">${f.optional ? '— none —' : 'Choose…'}</option></select>`);
      case 'money': case 'number': return wrap(`<input type="number" step="any" inputmode="decimal" name="${name}" value="${esc(val)}" ${req} ${ph}>`);
      case 'color': return wrap(`<input type="color" name="${name}" value="${esc(val || '#C4502A')}">`);
      default: {
        const list = f.list ? `list="dl-${name}"` : '';
        const dl = f.list ? `<datalist id="dl-${name}">${f.list.map(x => `<option value="${esc(x)}">`).join('')}</datalist>` : '';
        return wrap(`<input type="${f.type === 'tel' ? 'tel' : f.type === 'email' ? 'email' : f.type === 'date' ? 'date' : 'text'}" name="${name}" value="${esc(val)}" ${req} ${ph} ${list} ${f.upper ? 'style="text-transform:uppercase"' : ''}>${dl}`);
      }
    }
  }

  function defaultValue(f) {
    if (f.default === '@today') return MF.today();
    if (f.default === '@store') return MF.storeId;
    return f.default ?? '';
  }

  /** Open a create/edit modal. Resolves with saved row. */
  MF.resourceForm = function (key, def, row, onSaved) {
    const editing = !!(row && row.id);
    const vals = {};
    Object.entries(def.fields).forEach(([n, f]) => { vals[n] = editing ? row[n] : defaultValue(f); });
    const body = `<form class="form-grid" onsubmit="return false">${Object.entries(def.fields).map(([n, f]) => fieldHtml(n, f, vals[n] ?? '')).join('')}</form>`;
    MF.modal({
      title: (editing ? 'Edit ' : 'New ') + def.singular.toLowerCase(), body, wide: Object.keys(def.fields).length > 8,
      foot: [{ label: 'Cancel', cls: 'btn-ghost' }, { label: editing ? 'Save changes' : 'Create', cls: 'btn-primary', onClick: async api => {
        const form = $('form', api.body);
        if (!form.reportValidity()) return false;
        const saved = await MF.api('resource.save', { r: key, id: editing ? row.id : 0, data: MF.formData(form) });
        MF.toast(def.singular + ' saved', 'ok'); onSaved && onSaved(saved);
      } }],
      onOpen: async api => {
        for (const sel of api.body.querySelectorAll('[data-ref]')) {
          const opts = await refOptions(sel.dataset.ref);
          sel.insertAdjacentHTML('beforeend', opts.map(o => `<option value="${o.id}" ${String(o.id) === sel.dataset.val ? 'selected' : ''}>${esc(o.name)}</option>`).join(''));
        }
      },
    });
  };

  MF.resourceCell = function (def, col, r) {
    const f = def.fields[col], c = (def.computed || {})[col];
    const v = r[col];
    if (col === 'is_active' || col === 'show_on_documents') return +v ? MF.ic('check', 'sm text-green') : '<span class="muted">—</span>';
    if (c && c.money) return +v > 0 ? `<b class="${col === 'debt' ? 'text-danger' : ''}">${money(v)}</b>` : '<span class="muted">—</span>';
    if (c && c.date) return v ? esc(String(v).slice(0, 10)) : '<span class="muted">never</span>';
    if (f && f.type === 'select') return esc(f.options[v] ?? v ?? '');
    if (f && f.type === 'money') return money(v);
    if (f && f.type === 'color') return `<span class="badge" style="background:${esc(v)};color:#fff">${esc(v)}</span>`;
    if (col === 'name' && def.link) return `<a href="${MF.url(def.link, { id: r.id })}"><b>${esc(v)}</b></a>`;
    return esc(v ?? '');
  };

  /** Mount a full list page. */
  MF.resourcePage = function (root, key, def) {
    const cols = def.columns.map(c => ({
      key: c, label: (def.fields[c] || (def.computed || {})[c] || { label: c.replace(/_/g, ' ') }).label,
      num: !!((def.fields[c] && ['money', 'number'].includes(def.fields[c].type)) || (def.computed && def.computed[c] && (def.computed[c].num || def.computed[c].money))),
      render: r => MF.resourceCell(def, c, r),
    }));
    if (def.canEdit) cols.push({ key: '_a', label: '', cls: 'actions', render: () => `<button class="btn btn-sm btn-ghost" data-act="edit">${MF.ic('edit', 'sm')}</button><button class="btn btn-sm btn-ghost" data-act="del">${MF.ic('trash', 'sm')}</button>` });
    const active = $('#resActive', root.parentElement);
    const t = MF.table(root, {
      action: 'resource.list', columns: cols, search: $('#resSearch'), emptyIcon: def.icon, empty: 'No ' + def.singular.toLowerCase() + ' records yet',
      params: () => ({ r: key, active: active ? active.value : '1', from: $('#resFrom')?.value || '', to: $('#resTo')?.value || '' }),
      footer: def.sum ? d => `<tr><td colspan="${cols.length - 2}">Total for filter</td><td class="num">${money(d.sum)}</td><td></td></tr>` : null,
      onRow: def.link ? r => location.href = MF.url(def.link, { id: r.id }) : (def.canEdit ? r => MF.resourceForm(key, def, r, () => t.reload()) : null),
      onAction: async (act, r) => {
        if (act === 'edit') MF.resourceForm(key, def, r, () => t.reload());
        if (act === 'del' && await MF.confirm(def.delete === 'soft' ? 'Deactivate this record? It will be hidden but history is kept.' : 'Delete this record permanently?', { danger: true, ok: def.delete === 'soft' ? 'Deactivate' : 'Delete' })) {
          await MF.api('resource.delete', { r: key, id: r.id }); MF.toast('Done', 'ok'); t.reload();
        }
      },
    });
    active && active.addEventListener('change', () => t.reload(true));
    ['#resFrom', '#resTo'].forEach(s => $(s)?.addEventListener('change', () => t.reload(true)));
    $('#resNew')?.addEventListener('click', () => MF.resourceForm(key, def, null, () => t.reload()));
    return t;
  };
})();
