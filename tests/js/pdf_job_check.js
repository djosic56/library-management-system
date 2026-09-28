// Run: node tests/js/pdf_job_check.js js/pdf_job.js  — behavioural check with stub DOM + fetch; exit 1 on any FAIL
// Behavioural check of js/pdf_job.js with a stub DOM + fetch (no browser).
const fs = require('fs');
const src = fs.readFileSync(process.argv[2], 'utf8');
let failures = 0;
function check(ok, label, detail) { console.log((ok ? 'PASS ' : 'FAIL ') + label + ': ' + detail); if (!ok) failures++; }
function el(extra = {}) {
  const h = {};
  return Object.assign({ dataset: {}, classList: { toggle() {}, add() {}, remove() {} }, style: {}, textContent: '',
    className: '', disabled: false, firstElementChild: { style: {} },
    addEventListener: (ev, fn) => { h[ev] = fn; }, fire: (ev) => h[ev] && h[ev](), querySelector: () => el(),
    querySelectorAll: () => [] }, extra);
}
const realSet = setTimeout;
const sleep = ms => new Promise(r => realSet(r, ms));
async function scenario(name, { failActions = [], networkErrors = 0, click = true, title = 'A Title', confirmAnswer = true,
  titleSource = 'user', delays = {}, clickDelay = 0, act = null }) {
  const calls = [], events = [], posted = [];
  const textarea = el({ tagName: 'TEXTAREA', type: 'textarea', value: '', dataset: { field: 'description' } });
  const card = el({ dataset: { xref: '7', attention: '1', decorative: '0' } });
  card.querySelectorAll = () => [textarea];
  const docTitle = el({ value: title, dataset: {} });
  const nodes = { 'pdf-job': el({ dataset: { id: 'a'.repeat(32), csrf: 't', state: 'review', titleSource } }),
    'status-box': el(), progress: el(), 'result-box': el(), 'make-pdf': el(), 'delete-job': el(), 'doc-save': el(),
    'doc-title': docTitle, 'doc-lang': el({ value: 'en' }) };
  global.document = { getElementById: id => nodes[id], createElement: () => el({ innerHTML: '' }),
    querySelectorAll: sel => sel === '.image-card' ? [card] : sel === '.doc-field' ? [docTitle] : [] };
  global.location = { reload() {}, href: '' };
  const asked = []; global.confirm = (q) => { asked.push(q); return confirmAnswer; }; global.alert = () => {};
  let netErr = networkErrors;
  global.fetch = async (url, opts) => {
    const action = opts.body ? opts.body.get('action') : new URL('http://x/' + url).searchParams.get('action');
    const body = opts.body ? Object.fromEntries(opts.body.entries()) : {};
    calls.push(action);
    events.push('call:' + action);
    if (opts.body) posted.push(body);
    if (action === 'status' && netErr-- > 0) throw new TypeError('Failed to fetch');
    if (delays[action]) await sleep(delays[action]);
    events.push('done:' + action);
    const fails = failActions.includes(action);
    const reply = action === 'status' ? { status: { state: 'review' }, summary: null }
      : fails ? { error: 'Not saved' } : { ok: true, image: {} };
    return { status: fails ? 422 : 200, ok: !fails, json: async () => reply };
  };
  global.setTimeout = (fn, ms) => realSet(fn, ms === 10000 ? 20 : ms);
  eval(src);
  await sleep(50);
  const ctx = { nodes, textarea, docTitle, calls, events };
  if (act) {
    await act(ctx);
  } else if (click) {
    textarea.value = 'last edit'; textarea.fire('input');             // edited …
    await sleep(clickDelay);
    await nodes['make-pdf'].fire('click');                            // … and clicked
  }
  await sleep(1200);
  global.setTimeout = realSet;
  return { calls, events, posted, asked, status: nodes['status-box'].textContent, textarea };
}
const order = (events, a, b) => { const i = events.indexOf(a), j = events.indexOf(b); return i >= 0 && j > i; };
(async () => {
  let r = await scenario('flush', {});
  check(order(r.events, 'done:save_image', 'call:start_phase2'), 'last edit saved before phase 2', r.events.join(','));
  r = await scenario('savefail', { failActions: ['save_image'] });
  check(!r.calls.includes('start_phase2'), 'failed save blocks phase 2', r.calls.join(','));
  r = await scenario('net', { networkErrors: 1, click: false });
  const statuses = r.calls.filter(c => c === 'status').length;
  check(statuses >= 2, 'poll retries after network error', statuses + ' status calls');
  r = await scenario('notitle', { title: '', confirmAnswer: false });
  check(!r.calls.includes('start_phase2') && r.asked.length === 1, 'empty title asks and can cancel', r.calls.join(','));
  r = await scenario('suggested', { title: 'Contents', titleSource: 'title_page', confirmAnswer: false });
  check(!r.calls.includes('start_phase2') && r.asked.length === 1 && r.asked[0].includes('Contents'), 'suggested title must be confirmed', r.calls.join(','));
  r = await scenario('title', { title: 'Ritual in the Roman World' });
  check(r.calls.includes('start_phase2') && r.asked.length === 0, 'title present builds without asking', r.calls.join(','));

  // spremanje koje je već u tijeku (debounce prošao, odgovor još nije stigao) mora završiti prije faze 2
  r = await scenario('inflight', { delays: { save_image: 300 }, clickDelay: 850 });
  check(order(r.events, 'done:save_image', 'call:start_phase2'), 'build waits for a save that is still in flight', r.events.join(','));

  // spremanje koje je ranije propalo (a nije ponovljeno) blokira fazu 2 i kad klik dođe kasnije
  r = await scenario('earlierfail', { failActions: ['save_image'], act: async ({ textarea, nodes }) => {
    textarea.value = 'x'; textarea.fire('input'); await sleep(900); await nodes['make-pdf'].fire('click'); } });
  check(!r.calls.includes('start_phase2'), 'an earlier failed save blocks phase 2', r.calls.join(','));

  // dvije brze izmjene istog polja: šalje se zadnja vrijednost, snimljena u trenutku izmjene
  r = await scenario('twoedits', { act: async ({ textarea }) => {
    textarea.value = 'first'; textarea.fire('input'); await sleep(100);
    textarea.value = 'second'; textarea.fire('input'); textarea.value = 'typed later, not an input event'; } });
  const saves = r.posted.filter(b => b.action === 'save_image').map(b => b.description);
  check(saves.join('|') === 'second', 'quick edits send the last edited value once', saves.join('|'));

  // tijekom čekanja na spremanja polja su zaključana, nakon neuspjeha ponovno otključana
  let disabledDuring = null;
  r = await scenario('lock', { delays: { save_image: 300 }, failActions: ['save_image'], act: async ({ textarea, nodes }) => {
    textarea.value = 'y'; textarea.fire('input');
    const clicked = nodes['make-pdf'].fire('click'); await sleep(100); disabledDuring = textarea.disabled; await clicked; } });
  check(disabledDuring === true && r.textarea.disabled === false, 'fields are locked while saving before build, unlocked after failure',
    'during=' + disabledDuring + ' after=' + r.textarea.disabled);

  // greška jedne grupe (naslov) blokira fazu 2 i kad druga grupa (slika) uspije
  r = await scenario('groups', { failActions: ['save_document'], act: async ({ textarea, docTitle, nodes }) => {
    textarea.value = 'ok'; textarea.fire('input'); docTitle.value = 'New title'; docTitle.fire('input');
    await nodes['make-pdf'].fire('click'); } });
  check(r.calls.includes('save_image') && !r.calls.includes('start_phase2'), 'a failed group blocks phase 2 even if another saved', r.calls.join(','));

  process.exitCode = failures ? 1 : 0;
})().catch(e => { console.error('FAIL unexpected error:', e); process.exitCode = 1; });
