/* Chip pickers (docs/plans/tags-and-chips.md, A1–A4). Plain JS, no library.
   Markup (from nl_chip() in web/operation_pages.php):
     <span class="chipf" data-list="procedure.technology" data-multi="1" data-na="1"><input type="hidden" name=".." value=".."></span>
   The hidden input is the form field: a code, or a JSON list when data-multi. The lists come from
   <script id="nl-lists"> ({list: [[code, label_el, label_en, parent, extra, retired], …]}) and the signed-in surgeon's
   most used values from <script id="nl-freq"> ({list: {code: count}}).
   Matching ignores case and Greek accents, on code, Greek and English label. ↑↓ move, Enter picks, Backspace in an
   empty box removes the last chip, Esc closes. Tab is never taken over. Changing a value fires "change" on the hidden input. */
(function () {
  'use strict';
  var LISTS = {}, FREQ = {};
  function load() {
    var a = document.getElementById('nl-lists'), b = document.getElementById('nl-freq');
    if (a) LISTS = JSON.parse(a.textContent || '{}');
    if (b) FREQ = JSON.parse(b.textContent || '{}');
  }
  function norm(s) { return String(s || '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().replace(/_/g, ' '); }
  function entries(list) { return LISTS[list] || []; }
  function find(list, code) {
    if (code === 'n/a') return ['n/a', 'n/a', 'does not apply', null, null, 0];
    var e = entries(list);
    for (var i = 0; i < e.length; i++) if (e[i][0] === code) return e[i];
    return null;
  }
  function label(list, code) { var e = find(list, code); return e ? (e[1] || e[2] || e[0].replace(/_/g, ' ')) : code; }

  function values(f) {
    var v = f.input.value.trim();
    if (!f.multi) return v ? [v] : [];
    if (!v) return [];
    try { var a = JSON.parse(v); return Array.isArray(a) ? a : []; } catch (e) { return v.split(',').map(function (x) { return x.trim(); }).filter(Boolean); }
  }
  function setValues(f, vals) {
    f.input.value = f.multi ? (vals.length ? JSON.stringify(vals) : '') : (vals[0] || '');
    render(f);
    f.input.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function render(f) {
    f.box.querySelectorAll('.chip').forEach(function (c) { c.remove(); });
    values(f).forEach(function (code) {
      var e = find(f.list, code), c = document.createElement('span');
      c.className = 'chip' + (e && e[5] ? ' chip-retired' : '') + (e ? '' : ' chip-unknown');
      c.dataset.family = f.list.split('.')[f.list.indexOf('field.') === 0 ? 1 : 0];
      c.title = e ? (e[2] || e[0]) + (e[5] ? ' (retired)' : '') : 'not in the list';
      c.appendChild(document.createTextNode(label(f.list, code)));
      var x = document.createElement('button');
      x.type = 'button'; x.className = 'chip-x'; x.setAttribute('aria-label', 'remove ' + label(f.list, code)); x.textContent = '×';
      x.addEventListener('click', function () { setValues(f, values(f).filter(function (v) { return v !== code; })); f.text.focus(); });
      c.appendChild(x);
      f.box.insertBefore(c, f.text);
    });
    f.text.placeholder = values(f).length && !f.multi ? '' : (f.el.dataset.ph || (f.multi ? 'add…' : 'choose…'));
    f.text.hidden = !f.multi && values(f).length > 0 && document.activeElement !== f.text;
  }

  function options(f, q) {
    var have = values(f), qn = norm(q), freq = FREQ[f.list] || {}, out = [];
    var pool = entries(f.list).filter(function (e) { return !e[5]; });
    if (f.na) pool = pool.concat([['n/a', 'n/a', 'does not apply', null, null, 0]]);
    pool.forEach(function (e, i) {
      if (have.indexOf(e[0]) >= 0) return;
      if (qn && [e[0], e[1], e[2]].map(norm).join(' ').indexOf(qn) < 0) return;
      out.push({ e: e, n: freq[e[0]] || 0, i: i });
    });
    out.sort(function (a, b) { return (b.n - a.n) || (a.i - b.i); });   // the surgeon's frequent values first, then list order
    return out.slice(0, 40);
  }

  function open(f) {
    var q = f.text.value, opts = options(f, q);
    f.menu.innerHTML = '';
    f.active = 0;
    opts.forEach(function (o, k) {
      var li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.dataset.code = o.e[0];
      var main = document.createElement('span'); main.textContent = o.e[1] || o.e[2] || o.e[0].replace(/_/g, ' ');
      li.appendChild(main);
      var sub = document.createElement('span'); sub.className = 'opt-sub';
      sub.textContent = (o.e[1] && o.e[2] ? o.e[2] : '') + (o.n ? '  ·  ' + o.n + '×' : '');
      li.appendChild(sub);
      li.addEventListener('mousedown', function (ev) { ev.preventDefault(); pick(f, o.e[0]); });
      f.menu.appendChild(li);
    });
    if (!opts.length) { var li = document.createElement('li'); li.className = 'opt-none'; li.textContent = 'no match in the list'; f.menu.appendChild(li); }
    f.menu.hidden = false;
    highlight(f);
  }
  function close(f) { f.menu.hidden = true; }
  function highlight(f) {
    var lis = f.menu.querySelectorAll('li[data-code]');
    lis.forEach(function (li, k) { li.classList.toggle('active', k === f.active); });
    if (lis[f.active]) lis[f.active].scrollIntoView({ block: 'nearest' });
  }
  function pick(f, code) {
    var v = f.multi ? values(f).concat([code]) : [code];
    f.text.value = '';
    setValues(f, v);
    if (f.multi) open(f); else { close(f); f.text.hidden = true; }
  }

  function init(el) {
    if (el.dataset.ready) return;
    el.dataset.ready = '1';
    var f = { el: el, list: el.dataset.list, multi: el.dataset.multi === '1', na: el.dataset.na === '1', input: el.querySelector('input[type=hidden]') };
    f.box = document.createElement('span'); f.box.className = 'chip-box';
    f.text = document.createElement('input'); f.text.type = 'text'; f.text.className = 'chip-text'; f.text.autocomplete = 'off';
    f.text.setAttribute('role', 'combobox'); f.text.setAttribute('aria-autocomplete', 'list');
    f.menu = document.createElement('ul'); f.menu.className = 'chip-menu'; f.menu.hidden = true; f.menu.setAttribute('role', 'listbox');
    f.box.appendChild(f.text);
    el.appendChild(f.box); el.appendChild(f.menu);
    el._chip = f;
    f.box.addEventListener('click', function (ev) { if (ev.target === f.box || ev.target.classList.contains('chip')) { f.text.hidden = false; f.text.focus(); } });
    f.text.addEventListener('focus', function () { open(f); });
    f.text.addEventListener('input', function () { open(f); });
    f.text.addEventListener('blur', function () { setTimeout(function () { close(f); f.text.value = ''; render(f); }, 120); });
    f.text.addEventListener('keydown', function (ev) {
      var n = f.menu.querySelectorAll('li[data-code]').length;
      if (ev.key === 'ArrowDown') { ev.preventDefault(); if (f.menu.hidden) open(f); else { f.active = Math.min(n - 1, f.active + 1); highlight(f); } }
      else if (ev.key === 'ArrowUp') { ev.preventDefault(); f.active = Math.max(0, f.active - 1); highlight(f); }
      else if (ev.key === 'Enter') {
        ev.preventDefault();
        var li = f.menu.querySelectorAll('li[data-code]')[f.active];
        if (!f.menu.hidden && li) pick(f, li.dataset.code);
      }
      else if (ev.key === 'Escape') { close(f); }
      else if (ev.key === 'Backspace' && f.text.value === '' && values(f).length) { setValues(f, values(f).slice(0, -1)); }
    });
    render(f);
  }

  window.NLChips = {
    load: load, init: init, label: label, find: find, entries: entries,
    initAll: function (root) { (root || document).querySelectorAll('.chipf').forEach(init); },
    /** Point a picker at another list (e.g. the cranial route list); drops values not in it. */
    setList: function (el, list) {
      var f = el._chip; if (!f || f.list === list) return;
      f.list = list; el.dataset.list = list;
      var keep = values(f).filter(function (c) { return c === 'n/a' || find(list, c); });
      setValues(f, keep);
    },
    value: function (el) { return el._chip ? values(el._chip) : []; },
    set: function (el, vals) { if (el._chip) setValues(el._chip, vals); }
  };
  document.addEventListener('DOMContentLoaded', function () { load(); window.NLChips.initAll(); });
})();
