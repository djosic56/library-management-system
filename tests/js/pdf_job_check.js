// Run: node tests/js/pdf_job_check.js js/pdf_job.js  — behavioural check with stub DOM + fetch
// Behavioural check of js/pdf_job.js with a stub DOM + fetch (no browser).
const fs = require('fs');
const src = fs.readFileSync(process.argv[2], 'utf8');
function el(extra = {}) {
  const h = {};
  return Object.assign({ dataset: {}, classList: { toggle() {}, add() {}, remove() {} }, style: {}, textContent: '',
    className: '', disabled: false, firstElementChild: { style: {} },
    addEventListener: (ev, fn) => { h[ev] = fn; }, fire: (ev) => h[ev] && h[ev](), querySelector: () => el(),
    querySelectorAll: () => [] }, extra);
}
async function scenario(name, { saveFails = false, networkErrors = 0, click = true }) {
  const calls = [];
  const textarea = el({ tagName: 'TEXTAREA', type: 'textarea', value: '', dataset: { field: 'description' } });
  const card = el({ dataset: { xref: '7', attention: '1', decorative: '0' } });
  card.querySelectorAll = () => [textarea];
  const nodes = { 'pdf-job': el({ dataset: { id: 'a'.repeat(32), csrf: 't', state: 'review' } }),
    'status-box': el(), progress: el(), 'result-box': el(), 'make-pdf': el(), 'delete-job': el(), 'doc-save': el() };
  global.document = { getElementById: id => nodes[id], createElement: () => el({ innerHTML: '' }),
    querySelectorAll: sel => sel === '.image-card' ? [card] : [] };
  global.location = { reload() {}, href: '' };
  global.confirm = () => true; global.alert = () => {};
  let netErr = networkErrors;
  global.fetch = async (url, opts) => {
    const action = opts.body ? opts.body.get('action') : new URL('http://x/' + url).searchParams.get('action');
    calls.push(action);
    if (action === 'status' && netErr-- > 0) throw new TypeError('Failed to fetch');
    const body = action === 'status' ? { status: { state: 'review' }, summary: null }
      : action === 'save_image' && saveFails ? { error: 'PDF se upravo izrađuje' } : { ok: true, image: {} };
    return { status: action === 'save_image' && saveFails ? 422 : 200, ok: !(action === 'save_image' && saveFails),
      json: async () => body };
  };
  const timers = []; const realSet = setTimeout;
  global.setTimeout = (fn, ms) => { const t = realSet(fn, ms === 10000 ? 20 : ms); timers.push(ms); return t; };
  eval(src);
  await new Promise(r => realSet(r, 50));
  if (click) { textarea.value = 'last edit'; textarea.fire('input');
  await nodes['make-pdf'].fire('click'); }                            // … and clicked within 800 ms
  await new Promise(r => realSet(r, 120));
  return { calls, timers, status: nodes['status-box'].textContent };
}
(async () => {
  let r = await scenario('flush', {});
  const i = r.calls.indexOf('save_image'), j = r.calls.indexOf('start_phase2');
  console.log((i >= 0 && j > i ? 'PASS' : 'FAIL') + ' last edit saved before phase 2:', r.calls.join(','));
  r = await scenario('savefail', { saveFails: true });
  console.log((!r.calls.includes('start_phase2') ? 'PASS' : 'FAIL') + ' failed save blocks phase 2:', r.calls.join(','));
  r = await scenario('net', { networkErrors: 1, click: false });
  const statuses = r.calls.filter(c => c === 'status').length;
  console.log((statuses >= 2 ? 'PASS' : 'FAIL') + ' poll retries after network error:', statuses, 'status calls');
})();
