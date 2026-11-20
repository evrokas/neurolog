/* Operation form (M2): rows, levels, conditional fields, qualifiers, construct, patient search.
   Uses NLChips (chips.js) and NLLevels (levels.js). The server validates everything again; this only helps typing. */
(function () {
  'use strict';
  var form, C, L, IM = { spec: {}, companies: {}, supplies: {}, products: {}, from_technology: {} }, dismissed = {}, offering = false;

  function procMeta(code) {
    var e = C.find('procedure', code);
    return e ? { domain: e[3], extra: e[4] || {} } : null;
  }

  // ---------- rows ----------
  function nextIndex(container) {
    var max = -1;
    container.querySelectorAll('.op-row').forEach(function (r) { var n = parseInt(r.dataset.row, 10); if (n > max) max = n; });
    return max + 1;
  }
  function addRow(btn, noFocus) {
    var tpl = document.getElementById(btn.dataset.tpl), into = document.getElementById(btn.dataset.into);
    var i = nextIndex(into);
    var html = tpl.innerHTML.replace(/__I__/g, String(i));
    var tmp = document.createElement('div'); tmp.innerHTML = html;
    var row = tmp.firstElementChild;
    into.appendChild(row);
    setupRow(row);
    refresh();
    if (noFocus) return;
    var first = row.querySelector('.chip-text'); if (first) first.focus();
  }
  function removeRow(row) {
    var container = row.parentNode;
    if (row.classList.contains('su-row')) { var k = suKind(row); if (k) dismissed[k] = 1; }
    if (row.classList.contains('im-row') || row.classList.contains('su-row')) { row.remove(); refresh(); return; }
    if (container.querySelectorAll('.op-row').length <= 1) { row.querySelectorAll('input[type=text], input[type=hidden]').forEach(function (i) { i.value = ''; });
      row.querySelectorAll('.chipf').forEach(function (c) { C.set(c, []); }); return; }
    row.remove();
    refresh();
  }

  function setupRow(row) {
    C.initAll(row);
    row.querySelector('.row-remove').addEventListener('click', function () { removeRow(row); });
    var acc = row.querySelector('.row-accept'); if (acc) acc.addEventListener('click', function () { setProposed(row, false); });
    row.querySelectorAll('.levels-input').forEach(setupLevels);
    if (row.classList.contains('pr-row')) {
      var proc = row.querySelector('.chipf[data-list="procedure"] input');
      proc.addEventListener('change', function () { procChanged(row); refresh(); });
      row.querySelectorAll('.chipf[data-list="procedure.trigeminal.technique"] input').forEach(function (i) { i.addEventListener('change', function () { procChanged(row); }); });
      row.querySelector('.levels-grid-btn').addEventListener('click', function (ev) { openGrid(row.querySelector('.levels-input'), ev.target); });
      procChanged(row);
    } else if (row.classList.contains('im-row')) {
      row.querySelector('.chipf[data-list="field.implant.category"] input').addEventListener('change', function () { implantParams(row); productList(row); });
      var co = row.querySelector('.im-company');
      co.addEventListener('change', function () { productList(row); });
      companyFilter(co, 'implants');
      productList(row);
      implantParams(row);
    } else if (row.classList.contains('su-row')) {
      var sco = row.querySelector('.su-company');
      row.querySelector('.chipf[data-list="field.supply.kind"] input').addEventListener('change', function () { companyFilter(sco, suKind(row)); productList(row); });
      sco.addEventListener('change', function () { productList(row); });
      companyFilter(sco, suKind(row));
      productList(row);
    } else {
      row.querySelector('.chipf[data-list="diagnosis"] input').addEventListener('change', function () { qualifiers(row); refresh(); });
      qualifiers(row);
    }
  }

  function show(row, cls, on) { row.querySelectorAll('.' + cls).forEach(function (el) { el.hidden = !on; }); }

  function procChanged(row) {
    var code = C.value(row.querySelector('.chipf[data-list="procedure"]'))[0] || '';
    var m = procMeta(code), kind = m ? (m.extra.level_kind || '') : '';
    var dom = m ? m.domain : '';
    show(row, 'when-levels', !m || kind !== '');
    show(row, 'when-location', !m || kind === '');
    show(row, 'when-product', code === 'intradiscal_hydrogel');
    show(row, 'when-trigeminal', code === 'trigeminal_percutaneous');
    var tech = C.value(row.querySelector('.chipf[data-list="procedure.trigeminal.technique"]'))[0] || '';
    show(row, 'when-rf', code === 'rf_neurotomy' || (code === 'trigeminal_percutaneous' && tech === 'radiofrequency') || row.querySelector('input[name$="[rf_protocol]"]').value !== '');
    var route = row.querySelector('.chipf[data-list^="procedure.approach_route"]'), comp = row.querySelector('.chipf[data-list^="procedure.compartment"]');
    C.setList(route, dom === 'cranial' ? 'procedure.approach_route.cranial' : 'procedure.approach_route.spinal');
    C.setList(comp, dom === 'cranial' ? 'procedure.compartment.cranial' : 'procedure.compartment.spinal');
    var act = row.querySelector('.chipf[data-list="procedure.action"]');
    if (act) act.dataset.ph = m && m.extra.action ? 'default: ' + C.label('procedure.action', m.extra.action) : 'default of the procedure';
  }

  // ---------- diagnosis qualifiers ----------
  function qualifiers(row) {
    var box = row.querySelector('.qualifiers'), code = C.value(row.querySelector('.chipf[data-list="diagnosis"]'))[0] || '';
    var prev = {};
    try { prev = JSON.parse(box.dataset.values || '{}'); } catch (e) {}
    box.querySelectorAll('.chipf').forEach(function (c) { var n = c.dataset.q; prev[n] = c._chip ? C.value(c) : prev[n]; });
    var errs = {}; try { errs = JSON.parse(box.dataset.errors || '{}'); } catch (e) {}
    box.innerHTML = '';
    if (!code) return;
    var prefix = 'diagnosis.qualifiers.' + code + '.', multi = ['branches_affected', 'prior_treatments'];
    Object.keys(window.NL_LIST_NAMES).forEach(function (list) {
      if (list.indexOf(prefix) !== 0) return;
      var name = list.slice(prefix.length), isMulti = multi.indexOf(name) >= 0;
      var lab = document.createElement('label'); lab.textContent = name.replace(/_/g, ' ') + ' ';
      var sp = document.createElement('span'); sp.className = 'chipf'; sp.dataset.list = list; sp.dataset.q = name;
      if (isMulti) sp.dataset.multi = '1';
      var inp = document.createElement('input'); inp.type = 'hidden'; inp.name = box.dataset.name + '[' + name + ']';
      var v = prev[name];
      inp.value = Array.isArray(v) ? (isMulti ? (v.length ? JSON.stringify(v) : '') : (v[0] || '')) : (v || '');
      sp.appendChild(inp); lab.appendChild(sp);
      var key = Object.keys(errs).filter(function (k) { return k.slice(-name.length - 2) === '.q.' + name || k.split('.q.')[1] === name; })[0];
      if (key) { var er = document.createElement('span'); er.className = 'field-error'; er.textContent = errs[key]; lab.appendChild(er); }
      box.appendChild(lab);
      C.init(sp);
    });
  }

  // ---------- implants (M4): the parameters of the chosen category (vocab/implants.yaml) ----------
  function implantParams(row) {
    var box = row.querySelector('.im-params'), cat = C.value(row.querySelector('.chipf[data-list="field.implant.category"]'))[0] || '';
    var prev = {};
    try { prev = JSON.parse(box.dataset.values || '{}'); } catch (e) {}
    box.querySelectorAll('[data-p]').forEach(function (el) {
      var n = el.dataset.p;
      prev[n] = el.classList.contains('chipf') ? (C.value(el)[0] || '') : el.value;
    });
    box.dataset.values = JSON.stringify(prev);
    var errs = {}; try { errs = JSON.parse(box.dataset.errors || '{}'); } catch (e) {}
    box.innerHTML = '';
    var spec = IM.spec[cat] || {};
    Object.keys(spec).forEach(function (name) {
      var ps = spec[name], lab = document.createElement('label'), el;
      var nm = box.dataset.name + '[' + name + ']', v = prev[name];
      if (Array.isArray(v)) v = v.join(' ');
      if (v === undefined || v === null) v = '';
      lab.appendChild(document.createTextNode((ps.label || name.replace(/_(mm|ml|deg)$/, '').replace(/_/g, ' ')) + (ps.unit ? ' (' + ps.unit + ')' : '') + ' '));
      if (ps.type === 'code') {
        el = document.createElement('span'); el.className = 'chipf'; el.dataset.list = 'field.implant.' + ps.list;
        var h = document.createElement('input'); h.type = 'hidden'; h.name = nm; h.value = String(v); el.appendChild(h);
      } else if (ps.type === 'bool') {
        el = document.createElement('select'); el.name = nm;
        [['', '—'], ['1', 'yes'], ['0', 'no']].forEach(function (o) { var op = document.createElement('option'); op.value = o[0]; op.textContent = o[1]; if (String(v) === o[0]) op.selected = true; el.appendChild(op); });
      } else {
        el = document.createElement('input'); el.type = 'text'; el.name = nm; el.value = String(v); el.autocomplete = 'off';
        if (ps.type === 'num' || ps.type === 'int') { el.inputMode = ps.type === 'num' ? 'decimal' : 'numeric'; el.className = 'num-input'; el.placeholder = ps.min + '–' + ps.max; }
        if (ps.type === 'grades') { el.placeholder = 'A A B …'; el.className = 'mono'; }
        if (ps.type === 'text' && ps.max) el.maxLength = ps.max;
      }
      el.dataset.p = name;
      if (ps.required || ps.block) lab.classList.add('req');
      lab.appendChild(el);
      var e = errs[box.dataset.name.replace(/^im\[(\w+)\]\[p\]$/, 'im.$1.p.') + name];
      if (e) { var er = document.createElement('span'); er.className = 'field-error'; er.textContent = e; lab.appendChild(er); }
      box.appendChild(lab);
      if (el.classList.contains('chipf')) C.init(el);
    });
  }
  // products used before with this company: for the row's implant category or supply kind first, then the rest
  // (one company may supply several product lines of one category, e.g. two PLIF cage systems)
  function productList(row) {
    var su = row.classList.contains('su-row');
    var co = row.querySelector(su ? '.su-company' : '.im-company').value, dl = row.querySelector('datalist');
    var kind = su ? suKind(row) : (C.value(row.querySelector('.chipf[data-list="field.implant.category"]'))[0] || '');
    var by = IM.products[co] || {}, seen = {}, list = (by[kind] || []).slice();
    Object.keys(by).forEach(function (k) { if (k !== kind) list = list.concat(by[k]); });
    dl.innerHTML = '';
    list.forEach(function (p) { if (seen[p]) return; seen[p] = 1; var o = document.createElement('option'); o.value = p; dl.appendChild(o); });
  }
  function suKind(row) { return C.value(row.querySelector('.chipf[data-list="field.supply.kind"]'))[0] || ''; }
  // offer only the companies that supply this kind (a company with nothing ticked supplies everything); keep the chosen one
  function companyFilter(sel, kind) {
    Array.prototype.forEach.call(sel.options, function (o) {
      if (!o.value) return;
      var tags = IM.supplies[o.value] || [];
      o.hidden = !!kind && tags.length > 0 && tags.indexOf(kind) < 0 && o.value !== sel.value;
    });
  }
  // a supplier row for each technology ticked (procedure rows), neuromonitoring and adjuncts, unless one is there or was removed
  function offerSuppliers() {
    var into = document.getElementById('su-rows'); if (!into || offering) return;
    var want = {}, ft = IM.from_technology || {};
    form.querySelectorAll('.pr-row .chipf[data-list="procedure.technology"]').forEach(function (c) { C.value(c).forEach(function (t) { if (ft[t]) want[ft[t]] = 1; }); });
    var nm = form.querySelector('.chipf[data-list="field.operation.neuromonitoring"]');
    if (nm && C.value(nm).some(function (v) { return v !== 'none'; })) want.neuromonitoring = 1;
    var adj = form.querySelector('.chipf[data-list="field.operation.adjuncts"]');
    if (adj) C.value(adj).forEach(function (t) { if (ft[t]) want[ft[t]] = 1; });
    into.querySelectorAll('.su-row').forEach(function (r) { delete want[suKind(r)]; });
    offering = true;
    Object.keys(want).forEach(function (k) {
      if (dismissed[k]) return;
      addRow(document.querySelector('.row-add[data-tpl="su-tpl"]'), true);
      var rows = into.querySelectorAll('.su-row'), row = rows[rows.length - 1];
      fill(row, 'supply_kind', k);
    });
    offering = false;
  }

  // ---------- levels ----------
  function setupLevels(inp) {
    var out = inp.parentNode.querySelector('.levels-out'), tr = inp.parentNode.querySelector('.transitional');
    function upd() {
      out.innerHTML = '';
      if (!inp.value.trim()) return;
      var r = L.parse(inp.value, tr && tr.checked);
      if (!r.ok) { out.className = 'levels-out bad'; out.textContent = r.errors.join('; '); return; }
      out.className = 'levels-out';
      r.items.forEach(function (it) { var c = document.createElement('span'); c.className = 'chip chip-level'; c.textContent = it[0] === it[1] ? it[0] : it[0] + '-' + it[1]; out.appendChild(c); });
      if (r.region) { var g = document.createElement('span'); g.className = 'muted small'; g.textContent = ' ' + r.region.replace(/_/g, ' '); out.appendChild(g); }
    }
    inp.addEventListener('input', upd);
    if (tr) tr.addEventListener('change', upd);
    upd();
  }

  // the level grid (ZPMS): segments by region, several at once; writes standard text into the levels box
  var grid = null;
  function openGrid(inp, anchor) {
    var pop = document.querySelector('.levels-grid-pop');
    var tr = inp.parentNode.querySelector('.transitional'), trans = tr && tr.checked;
    var cur = L.parse(inp.value, trans), chosen = {};
    if (cur.ok) cur.items.forEach(function (it) { if (it[2] === 'segment') chosen[it[0] + '-' + it[1]] = 1; });
    var V = L.VERTEBRAE.filter(function (v) { return v !== 'C0' && (trans || v !== 'L6'); });
    pop.innerHTML = '';
    ['C', 'T', 'L', 'S'].forEach(function (reg) {
      var line = document.createElement('div'); line.className = 'grid-line';
      var h = document.createElement('span'); h.className = 'grid-reg'; h.textContent = { C: 'Α', T: 'Θ', L: 'Ο', S: 'Ι' }[reg]; line.appendChild(h);
      for (var k = 0; k < V.length - 1; k++) {
        if (V[k][0] !== reg || ['S2', 'S3', 'S4', 'S5'].indexOf(V[k + 1]) >= 0) continue;
        var seg = V[k] + '-' + V[k + 1], b = document.createElement('button');
        b.type = 'button'; b.className = 'grid-seg' + (chosen[seg] ? ' on' : ''); b.textContent = seg; b.dataset.seg = seg;
        b.addEventListener('click', function () { this.classList.toggle('on'); });
        line.appendChild(b);
      }
      pop.appendChild(line);
    });
    var act = document.createElement('div'); act.className = 'grid-actions';
    var ok = document.createElement('button'); ok.type = 'button'; ok.className = 'btn primary small-btn'; ok.textContent = 'OK';
    var no = document.createElement('button'); no.type = 'button'; no.className = 'btn small-btn'; no.textContent = 'Cancel';
    ok.addEventListener('click', function () {
      var segs = [].map.call(pop.querySelectorAll('.grid-seg.on'), function (b) { return b.dataset.seg; });
      var keep = cur.ok ? cur.items.filter(function (it) { return it[2] !== 'segment'; }).map(function (it) { return it[0] === it[1] ? it[0] : it[0] + '-' + it[1]; }) : [];
      inp.value = keep.concat(segs).join(', ');
      inp.dispatchEvent(new Event('input')); pop.hidden = true; inp.focus();
    });
    no.addEventListener('click', function () { pop.hidden = true; inp.focus(); });
    act.appendChild(ok); act.appendChild(no); pop.appendChild(act);
    var r = anchor.getBoundingClientRect();
    pop.style.top = (window.scrollY + r.bottom + 4) + 'px';
    pop.style.left = Math.max(8, Math.min(window.scrollX + r.left, window.scrollX + document.documentElement.clientWidth - 420)) + 'px';
    pop.hidden = false;
    var first = pop.querySelector('.grid-seg'); if (first) first.focus();
    pop.onkeydown = function (ev) { if (ev.key === 'Escape') { pop.hidden = true; inp.focus(); } if (ev.key === 'Enter' && ev.target.classList.contains('grid-seg')) { ev.preventDefault(); ok.click(); } };
  }

  // ---------- numbering, "treats" choices, construct ----------
  function refresh() {
    var dxRows = form.querySelectorAll('.dx-row'), names = [];
    dxRows.forEach(function (r, k) {
      r.querySelector('.row-no').textContent = String(k + 1);
      var code = C.value(r.querySelector('.chipf[data-list="diagnosis"]'))[0];
      names.push([r.dataset.row, (k + 1) + '. ' + (code ? C.label('diagnosis', code) : '…')]);
    });
    var codes = [];
    form.querySelectorAll('.pr-row').forEach(function (r, k) {
      r.querySelector('.row-no').textContent = String(k + 1);
      var c = C.value(r.querySelector('.chipf[data-list="procedure"]'))[0]; if (c) codes.push(c);
      var sel = r.querySelector('select.treats'), cur = sel.value || sel.dataset.value || '';
      sel.innerHTML = '<option value="">—</option>';
      names.forEach(function (n) { var o = document.createElement('option'); o.value = n[0]; o.textContent = n[1]; if (n[0] === cur) o.selected = true; sel.appendChild(o); });
      if (!sel.value && names.length === 1) sel.value = names[0][0];
      sel.dataset.value = sel.value;
    });
    var prNames = [];
    form.querySelectorAll('.pr-row').forEach(function (r, k) {
      var c = C.value(r.querySelector('.chipf[data-list="procedure"]'))[0];
      prNames.push([r.dataset.row, (k + 1) + '. ' + (c ? C.label('procedure', c) : '…')]);
    });
    form.querySelectorAll('.su-row').forEach(function (r, k) {
      r.querySelector('.row-no').textContent = String(k + 1);
      var sel = r.querySelector('select.su-for'), cur = sel.value || sel.dataset.value || '';
      sel.innerHTML = '<option value="">—</option>';
      prNames.forEach(function (n) { var o = document.createElement('option'); o.value = n[0]; o.textContent = n[1]; if (n[0] === cur) o.selected = true; sel.appendChild(o); });
      sel.dataset.value = sel.value;
    });
    offerSuppliers();
    form.querySelectorAll('.im-row').forEach(function (r, k) {
      r.querySelector('.row-no').textContent = String(k + 1);
      var sel = r.querySelector('select.im-for'), cur = sel.value || sel.dataset.value || '';
      sel.innerHTML = '<option value="">—</option>';
      prNames.forEach(function (n) { var o = document.createElement('option'); o.value = n[0]; o.textContent = n[1]; if (n[0] === cur) o.selected = true; sel.appendChild(o); });
      // screws belong to the screw fixation row, a cage to the interbody fusion (ACDF for an ACDF cage): link them
      // when nothing is chosen yet, and copy that row's levels into an empty levels box
      var cat = C.value(r.querySelector('.chipf[data-list="field.implant.category"]'))[0] || '';
      var want = { pedicle_screw: 'pedicle_screw_fixation', lateral_mass_screw: 'pedicle_screw_fixation', cage_PLIF_TLIF: 'interbody_fusion',
                   cage_ALIF_LLIF: 'interbody_fusion', cage_ACDF: 'acdf', vertebral_augmentation_cement: 'vertebral_augmentation' }[cat];
      if (!sel.value && want) {
        form.querySelectorAll('.pr-row').forEach(function (pr) {
          if (sel.value || (C.value(pr.querySelector('.chipf[data-list="procedure"]'))[0] || '') !== want) return;
          sel.value = pr.dataset.row;
          var li = r.querySelector('.levels-input'), pl = pr.querySelector('.levels-input');
          if (li && pl && !li.value.trim() && pl.value.trim()) { li.value = pl.value; li.dispatchEvent(new Event('input')); }
        });
      }
      sel.dataset.value = sel.value;
    });
    var cage = codes.indexOf('interbody_fusion') >= 0, screws = codes.indexOf('pedicle_screw_fixation') >= 0;
    var cons = form.querySelector('.construct');
    if (cons) cons.textContent = 'construct: ' + (cage && screws ? 'cage + screws' : cage ? 'cage only' : screws ? 'screws only' : 'none');
  }

  // ---------- patient search ----------
  function setupPatient() {
    var box = form.querySelector('.patient-pick'); if (!box) return;
    var q = box.querySelector('.patient-search'), list = box.querySelector('.patient-results'), ref = box.querySelector('input[name=patient_ref]');
    var chosen = box.querySelector('.patient-chosen'), np = box.querySelector('.new-patient'), timer = null;
    function choose(p) {
      ref.value = p ? p.id : '';
      chosen.innerHTML = '';
      if (p) {
        var c = document.createElement('span'); c.className = 'chip chip-patient';
        c.textContent = p.name + ' · ' + p.nl_id + (p.birth_date ? ' · ' + p.birth_date : '');
        var x = document.createElement('button'); x.type = 'button'; x.className = 'chip-x'; x.textContent = '×'; x.setAttribute('aria-label', 'remove');
        x.addEventListener('click', function () { choose(null); q.focus(); });
        c.appendChild(x); chosen.appendChild(c);
      }
      np.hidden = !!p; q.parentNode.hidden = !!p; list.innerHTML = '';
    }
    var x0 = chosen.querySelector('.chip-x');
    if (x0) x0.addEventListener('click', function () { choose(null); });
    np.hidden = !!ref.value; q.parentNode.hidden = !!ref.value;
    q.addEventListener('input', function () {
      clearTimeout(timer);
      var term = q.value.trim();
      if (term.length < 2) { list.innerHTML = ''; return; }
      timer = setTimeout(function () {
        fetch(box.dataset.url + '?q=' + encodeURIComponent(term), { credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : { matches: [] }; })
          .then(function (d) {
            list.innerHTML = '';
            (d.matches || []).forEach(function (p) {
              var li = document.createElement('li'), b = document.createElement('button');
              b.type = 'button'; b.textContent = p.name + ' · ' + p.nl_id + (p.birth_date ? ' · ' + p.birth_date : '');
              b.addEventListener('click', function () { choose(p); });
              li.appendChild(b); list.appendChild(li);
            });
            if (!(d.matches || []).length) { var li = document.createElement('li'); li.className = 'muted small'; li.textContent = 'no patient found — fill in a new one below'; list.appendChild(li); }
          }).catch(function () {});
      }, 250);
    });
  }

  // ---------- quick line and tags (docs/plans/tags-and-chips.md B1–B4): proposed rows, never confirmed here ----------
  function setProposed(row, on) {
    row.classList.toggle('is-proposed', on);
    row.querySelector('input.proposed').value = on ? '1' : '';
  }
  function emptyRow(containerId, tplId, isEmpty) {
    var rows = document.getElementById(containerId).querySelectorAll('.op-row');
    for (var k = 0; k < rows.length; k++) if (isEmpty(rows[k])) return rows[k];
    var btn = document.querySelector('.row-add[data-tpl="' + tplId + '"]'); addRow(btn, true);
    rows = document.getElementById(containerId).querySelectorAll('.op-row');
    return rows[rows.length - 1];
  }
  function chipOf(row, name) { return row.querySelector('.chipf:has(input[name$="[' + name + ']"])'); }
  function fill(row, name, v) { var c = chipOf(row, name); if (c && v !== null && v !== undefined && v !== '' && !(Array.isArray(v) && !v.length)) C.set(c, Array.isArray(v) ? v : [v]); }
  function applyParse(d, box) {
    // rows proposed earlier and not yet accepted are replaced
    form.querySelectorAll('.op-row.is-proposed').forEach(function (r) { if (r.parentNode.querySelectorAll('.op-row').length > 1) r.remove(); else { setProposed(r, false); r.querySelectorAll('.chipf').forEach(function (c) { C.set(c, []); }); r.querySelectorAll('.levels-input').forEach(function (i) { i.value = ''; i.dispatchEvent(new Event('input')); }); } });
    (d.diagnoses || []).forEach(function (x) {
      var row = emptyRow('dx-rows', 'dx-tpl', function (r) { return !C.value(r.querySelector('.chipf[data-list="diagnosis"]')).length; });
      fill(row, 'diagnosis_code', x.diagnosis_code); qualifiers(row);
      fill(row, 'clinical_syndrome', x.clinical_syndrome);
      var li = row.querySelector('.levels-input'); if (x.levels) { li.value = x.levels; li.dispatchEvent(new Event('input')); }
      fill(row, 'lesion_side', x.lesion_side);
      setProposed(row, true);
    });
    (d.procedures || []).forEach(function (x) {
      var row = emptyRow('pr-rows', 'pr-tpl', function (r) { return !C.value(r.querySelector('.chipf[data-list="procedure"]')).length; });
      fill(row, 'procedure_code', x.procedure_code); procChanged(row);
      var li = row.querySelector('.levels-input'); if (x.levels) { li.value = x.levels; li.dispatchEvent(new Event('input')); }
      ['lesion_side', 'approach_type', 'approach_route', 'product', 'trigeminal_technique', 'branches_treated'].forEach(function (n) { fill(row, n, x[n]); });
      procChanged(row);
      setProposed(row, true);
    });
    (d.implants || []).forEach(function (x) {
      addRow(document.querySelector('.row-add[data-tpl="im-tpl"]'), true);
      var rows = document.getElementById('im-rows').querySelectorAll('.im-row'), row = rows[rows.length - 1];
      row.querySelector('.im-params').dataset.values = JSON.stringify(x.params || {});   // before the category: it draws the fields
      fill(row, 'implant_category', x.implant_category);
      if (x.company_ref) { row.querySelector('.im-company').value = String(x.company_ref); productList(row); }
      if (x.implant_count) row.querySelector('.im-count').value = String(x.implant_count);
      var li = row.querySelector('.levels-input'); if (x.levels) { li.value = x.levels; li.dispatchEvent(new Event('input')); }
      fill(row, 'side', x.side);
      setProposed(row, true);
    });
    refresh();
    box.innerHTML = '';
    // suppliers from @προμ (or a drain or sealant under @υλ): fill an empty row of the same kind, else add one
    (d.supplies || []).forEach(function (x) {
      var into = document.getElementById('su-rows'), row = null;
      into.querySelectorAll('.su-row').forEach(function (r) { if (!row && suKind(r) === x.supply_kind && !r.querySelector('.su-company').value) row = r; });
      if (!row) { addRow(document.querySelector('.row-add[data-tpl="su-tpl"]'), true); var all = into.querySelectorAll('.su-row'); row = all[all.length - 1]; }
      fill(row, 'supply_kind', x.supply_kind);
      if (x.company_ref) row.querySelector('.su-company').value = String(x.company_ref);
      productList(row);
    });
    var n = (d.procedures || []).length + (d.implants || []).length + (d.supplies || []).length, m = (d.diagnoses || []).length;
    var p = document.createElement('p');
    p.textContent = n || m ? ('Proposed: ' + n + ' procedure, implant or supplier row' + (n === 1 ? '' : 's') + ', ' + m + ' diagnos' + (m === 1 ? 'is' : 'es') + '. Check them, add the technology, then accept.') : 'Nothing recognised.';
    box.appendChild(p);
    if (n || m) {
      var all = document.createElement('button'); all.type = 'button'; all.className = 'btn small-btn'; all.textContent = '✓ Accept all';
      all.addEventListener('click', function () { form.querySelectorAll('.op-row.is-proposed').forEach(function (r) { setProposed(r, false); }); });
      box.appendChild(all);
    }
    if ((d.unplaced || []).length) { var u = document.createElement('p'); u.className = 'muted small'; u.textContent = 'Not read: ' + d.unplaced.join(' · '); box.appendChild(u); }
    box.hidden = false;
  }
  function runParse(text, box) {
    var tok = document.querySelector('meta[name="csrf-token"]'), fd = new FormData(); fd.append('text', text);
    fetch(document.querySelector('.quick-line').dataset.url, { method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-CSRF-Token': tok ? tok.content : '' } })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) { applyParse(d, box); })
      .catch(function () { box.textContent = 'Could not read the line (signed out?).'; box.hidden = false; });
  }
  function setupQuick() {
    var q = form.querySelector('.quick-input'), box = form.querySelector('.quick-result');
    if (!q) return;
    form.querySelector('.quick-go').addEventListener('click', function () { if (q.value.trim()) runParse(q.value, box); });
    q.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); if (q.value.trim()) runParse(q.value, box); } });
    var ns = form.querySelector('.note-suggest'), note = form.querySelector('.note-input');
    if (ns) ns.addEventListener('click', function () { if (note.value.trim()) { runParse(note.value, box); box.scrollIntoView({ block: 'center' }); } });
  }

  // ---------- presets (A7): add the preset's rows; levels and side stay to be filled ----------
  function setupPresets() {
    var src = document.getElementById('nl-presets'), presets = [];
    try { presets = JSON.parse(src ? src.textContent : '[]'); } catch (e) {}
    form.querySelectorAll('.preset-chip').forEach(function (b) {
      b.addEventListener('click', function () {
        var ps = presets.filter(function (x) { return String(x.id) === b.dataset.preset; })[0]; if (!ps) return;
        var first = null, rows = Array.isArray(ps.rows) ? ps.rows : (ps.rows.procedures || []);
        (Array.isArray(ps.rows) ? [] : (ps.rows.implants || [])).forEach(function (x) {
          addRow(document.querySelector('.row-add[data-tpl="im-tpl"]'), true);
          var all = document.getElementById('im-rows').querySelectorAll('.im-row'), row = all[all.length - 1];
          row.querySelector('.im-params').dataset.values = JSON.stringify(x.params || {});
          fill(row, 'implant_category', x.implant_category);
          if (x.company_ref) { row.querySelector('.im-company').value = String(x.company_ref); productList(row); }
          if (x.product) row.querySelector('.im-product').value = x.product;
        });
        rows.forEach(function (x) {
          var row = emptyRow('pr-rows', 'pr-tpl', function (r) { return !C.value(r.querySelector('.chipf[data-list="procedure"]')).length; });
          fill(row, 'procedure_code', x.procedure_code); procChanged(row);
          Object.keys(x).forEach(function (k) { if (k !== 'procedure_code') fill(row, k, x[k]); });
          procChanged(row);
          if (!first) first = row;
        });
        var ids = form.querySelector('input[name=preset_ids]');
        ids.value = ids.value ? ids.value + ',' + ps.id : String(ps.id);
        refresh();
        if (first) { var li = first.querySelector('.levels-input'); if (li && li.offsetParent) li.focus(); }
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    // the filter form and the patient form use the chips and the level preview only
    var ff = document.getElementById('filter-form');
    if (ff) {
      ff.querySelectorAll('.levels-input').forEach(function (i) { C = window.NLChips; L = window.NLLevels; setupLevels(i); });
      ff.addEventListener('submit', function () { ff.querySelectorAll('input, select').forEach(function (i) { if (!i.value) i.disabled = true; }); });
    }
    // complication and follow-up forms: chips only
    document.querySelectorAll('.chip-form').forEach(function (f) { window.NLChips.initAll(f); var e = f.parentNode.querySelector('.field-error'); if (e) e.scrollIntoView({ block: 'center' }); });
    form = document.getElementById('op-form'); if (!form) return;
    C = window.NLChips; L = window.NLLevels;
    try { IM = JSON.parse(document.getElementById('nl-implants').textContent); } catch (e) {}
    window.NL_LIST_NAMES = {};
    try { Object.keys(JSON.parse(document.getElementById('nl-lists').textContent)).forEach(function (k) { window.NL_LIST_NAMES[k] = 1; }); } catch (e) {}
    form.querySelectorAll('.op-row').forEach(setupRow);
    document.querySelectorAll('.row-add').forEach(function (b) { b.addEventListener('click', function () { addRow(b); }); });
    form.addEventListener('change', refresh);
    setupPatient();
    setupQuick();
    setupPresets();
    refresh();
    var firstErr = form.querySelector('.field-error'); if (firstErr) firstErr.scrollIntoView({ block: 'center' });
  });
})();
