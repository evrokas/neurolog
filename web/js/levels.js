/* Spinal levels as the group writes them. Same parser as web/levels.php (tests/cases/levels.json tests both).
   NLLevels.parse('Ο4/5') -> {ok:true, items:[['L4','L5','segment']], text:'L4-L5', region:'lumbar', errors:[]} */
(function (root) {
  'use strict';
  var VERT = ['C0','C1','C2','C3','C4','C5','C6','C7','T1','T2','T3','T4','T5','T6','T7','T8','T9','T10','T11','T12',
              'L1','L2','L3','L4','L5','L6','S1','S2','S3','S4','S5'];
  var MAX = { C: 7, T: 12, L: 5, S: 5 };
  var REGIONS = [['cervical','C2','C7'],['thoracic','T1','T12'],['lumbar','L1','L5'],['sacral_coccygeal','S1','S5'],
                 ['craniocervical','C0','C2'],['cervicothoracic','C7','T2'],['thoracolumbar','T11','L2'],['lumbosacral','L5','S1']];
  var MAP = { 'Α':'C','Θ':'T','Ο':'L','Ι':'S','Ά':'C','Ό':'L','Ί':'S','A':'C','O':'L','I':'S','–':'-','—':'-','\\':'/' };

  function index(v, tr) {
    if (v === 'L6' && !tr) return null;
    var i = VERT.indexOf(v);
    if (i < 0) return null;
    if (!tr && i > VERT.indexOf('L6')) i--;
    return i;
  }

  function region(items, tr) {
    var lo = Infinity, hi = -1;
    items.forEach(function (it) { lo = Math.min(lo, index(it[0], tr)); hi = Math.max(hi, index(it[1], tr)); });
    for (var k = 0; k < REGIONS.length; k++) {
      var f = index(REGIONS[k][1], tr), t = index(REGIONS[k][2] === 'L5' && tr ? 'L6' : REGIONS[k][2], tr);
      if (lo >= f && hi <= t) return REGIONS[k][0];
    }
    return null;
  }

  function parse(text, tr) {
    tr = !!tr;
    var out = { ok: false, items: [], text: '', region: null, errors: [] };
    var s = String(text || '').trim().toUpperCase();
    if (s === '') { out.errors.push('no level given'); return out; }
    s = s.replace(/(^|[^\p{L}])(?:ΚΑΙ|KAI|AND)(?![\p{L}])/gu, '$1,');
    s = s.replace(/[ΑΘΟΙΆΌΊAOI–—\\]/g, function (c) { return MAP[c]; });
    var parts = s.split(/[,;+&]|\s+(?=[CTLS]\d)/u), prevLetter = null;
    partLoop:
    for (var p = 0; p < parts.length; p++) {
      var part = parts[p].replace(/\s+/gu, '');
      if (part === '') continue;
      if (!/^([CTLS]?\d{1,2})((?:[\/-][CTLS]?\d{1,2})*)$/u.test(part)) { out.errors.push('«' + part + '» is not a level'); continue; }
      var tok = [], re = /([\/-]?)([CTLS]?)(\d{1,2})/gu, m;
      while ((m = re.exec(part))) tok.push(m);
      if (tok.length === 1 && tok[0][2] === '') { out.errors.push('«' + part + '»: a number alone is not a level'); continue; }
      var verts = [], seps = [], letter = null;
      for (var k = 0; k < tok.length; k++) {
        var L = tok[k][2] !== '' ? tok[k][2] : (letter !== null ? letter : prevLetter);
        if (L === null) { out.errors.push('«' + part + '»: which region (Α Θ Ο Ι)?'); continue partLoop; }
        var n = parseInt(tok[k][3], 10);
        if (n < (L === 'C' ? 0 : 1) || n > MAX[L] + (L === 'L' && tr ? 1 : 0)) {
          out.errors.push(L + n + ' does not exist' + (L === 'L' && n === 6 ? ' (tick «transitional vertebra» for L6)' : ''));
          continue partLoop;
        }
        verts.push(L + n); if (k > 0) seps.push(tok[k][1]); letter = L;
      }
      prevLetter = letter;
      if (verts.length === 1) { out.items.push([verts[0], verts[0], 'vertebra']); continue; }
      var idx = verts.map(function (v) { return index(v, tr); });
      for (k = 1; k < idx.length; k++) {
        if (idx[k] <= idx[k - 1]) { out.errors.push('«' + part + '»: levels go from top to bottom (' + verts[k - 1] + ' before ' + verts[k] + ')'); continue partLoop; }
      }
      if (verts.length === 2 && idx[1] - idx[0] > 1) {
        if (seps[0] === '/') { out.errors.push('«' + part + '»: ' + verts[0] + '/' + verts[1] + ' are not neighbours (use a dash for a range)'); continue; }
        out.items.push([verts[0], verts[1], 'range']); continue;
      }
      var segs = [];
      for (k = 1; k < verts.length; k++) {
        if (idx[k] - idx[k - 1] !== 1) { out.errors.push('«' + part + '»: ' + verts[k - 1] + ' and ' + verts[k] + ' are not neighbours'); continue partLoop; }
        segs.push([verts[k - 1], verts[k], 'segment']);
      }
      out.items = out.items.concat(segs);
    }
    if (out.errors.length || !out.items.length) { if (!out.errors.length) out.errors.push('no level given'); out.items = []; return out; }
    out.ok = true;
    out.text = out.items.map(function (it) { return it[0] === it[1] ? it[0] : it[0] + '-' + it[1]; }).join(', ');
    out.region = region(out.items, tr);
    return out;
  }

  var api = { parse: parse, region: region, VERTEBRAE: VERT };
  if (typeof module !== 'undefined' && module.exports) module.exports = api; else root.NLLevels = api;
})(this);
