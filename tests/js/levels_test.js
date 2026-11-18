// web/js/levels.js against tests/cases/levels.json (shared with the PHP parser). node tests/js/levels_test.js
const L = require('../../web/js/levels.js');
const cases = require('../cases/levels.json').cases;
let fail = 0;
for (const c of cases) {
  const r = L.parse(c.in, c.transitional || false), want = c.ok === undefined ? true : c.ok;
  const good = r.ok === want && (!want || (r.text === c.text && r.region === c.region));
  if (!good) { fail++; console.log('FAIL «' + c.in + '»: got', JSON.stringify([r.ok, r.text, r.region, r.errors])); }
}
console.log((cases.length - fail) + '/' + cases.length + ' passed');
process.exit(fail ? 1 : 0);
