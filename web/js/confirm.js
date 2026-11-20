/* Every deletion is acknowledged before it reaches the database (loaded on every page, config `foot_script`).
   - A form or link with the `confirmation` attribute (the framework's admin lists, e.g. Admin › Users, and NeuroLog's
     own delete forms) asks first; the attribute's value is the question, else one is built from the table row.
   - The operation form (#op-form, data-saved = rows stored per section) asks before a save that would delete
     saved diagnosis, procedure, implant or supplier rows. */
(function () {
  'use strict';

  function question(el) {
    var q = el.getAttribute('confirmation');
    if (q) return q;
    var row = el.closest('tr'), cell = row && row.querySelector('td');
    var name = cell ? cell.textContent.trim() : '';
    if (row) {   // the framework lists start with the id; the name is the next cell
      var cells = row.querySelectorAll('td');
      if (cells.length > 2 && /^\d+$/.test(name)) name = cells[1].textContent.trim();
    }
    return (name ? 'Delete «' + name + '»?' : 'Delete this?') + ' This cannot be undone.';
  }

  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (f.hasAttribute && f.hasAttribute('confirmation') && !window.confirm(question(f))) { ev.preventDefault(); ev.stopImmediatePropagation(); }
  }, true);
  document.addEventListener('click', function (ev) {
    var a = ev.target.closest && ev.target.closest('a[confirmation]');
    if (a && !window.confirm(question(a))) { ev.preventDefault(); ev.stopImmediatePropagation(); }
  }, true);

  // the operation form: rows removed from a saved operation are deleted when it is saved
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (f.id !== 'op-form' || !f.dataset.saved) return;
    var saved; try { saved = JSON.parse(f.dataset.saved); } catch (e) { return; }
    var filled = function (sel, field) {
      var n = 0;
      f.querySelectorAll(sel).forEach(function (r) { var i = r.querySelector('[name$="[' + field + ']"]'); if (i && i.value.trim() !== '') n++; });
      return n;
    };
    var now = { diagnosis: filled('.dx-row', 'diagnosis_code'), procedure: filled('.pr-row', 'procedure_code'),
                implant: filled('.im-row', 'implant_category'), supplier: filled('.su-row', 'company_ref') };
    var plural = { diagnosis: 'diagnoses', procedure: 'procedures', implant: 'implant rows', supplier: 'supplier rows' };
    var lost = [];
    Object.keys(now).forEach(function (k) {
      var d = (saved[k] || 0) - now[k];
      if (d > 0) lost.push(d + ' ' + (d === 1 ? k + (k === 'implant' || k === 'supplier' ? ' row' : '') : plural[k]));
    });
    if (lost.length && !window.confirm('Saving deletes ' + lost.join(', ') + ' saved on this operation. This cannot be undone. Save?')) {
      ev.preventDefault(); ev.stopImmediatePropagation();
    }
  }, true);
})();
