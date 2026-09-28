// Run: node tests/js/check_job_check.js js/check_job.js — behavioural check with stub DOM + fetch; exit 1 on FAIL
// Stub DOM i lažni poslužitelj (bez preglednika); odgovori se mogu zadržati (hold) i pustiti ručno.
const fs = require('fs');
const src = fs.readFileSync(process.argv[2], 'utf8');
let failures = 0;
function check(ok, label, detail = '') { console.log((ok ? 'PASS ' : 'FAIL ') + label + (detail ? ': ' + detail : '')); if (!ok) failures++; }
process.on('unhandledRejection', e => { console.log('FAIL unhandled rejection: ' + (e && e.stack || e)); failures++; process.exitCode = 1; });
function classes() {
  const s = new Set();
  return { toggle(c, force) { const on = force === undefined ? !s.has(c) : force; if (on) s.add(c); else s.delete(c); },
    add(c) { s.add(c); }, remove(c) { s.delete(c); }, contains: c => s.has(c) };
}
function el(extra = {}) {
  const h = {};
  const parts = {};
  return Object.assign({ dataset: {}, classList: classes(), style: {}, textContent: '',
    className: '', disabled: false, value: '', checked: false, firstElementChild: { style: {} },
    addEventListener: (ev, fn) => { h[ev] = fn; }, fire: (ev) => h[ev] && h[ev](),
    querySelector: sel => (parts[sel] = parts[sel] || el()), querySelectorAll: () => [] }, extra);
}
const sleep = ms => new Promise(r => setTimeout(r, ms));
async function until(cond, label, ms = 3000) {
  for (let t = 0; t < ms; t += 10) { if (cond()) return; await sleep(10); }
  throw new Error('timeout waiting for ' + label);
}
const LOCATION = ['page', 'original', 'context_before', 'context_after'];
const FIELDS = ['status', 'category', 'original', 'context_before', 'context_after', 'replacement', 'reason', 'formatting', 'related', 'page'];

function makeCard(fid) {
  const card = el({ dataset: { id: fid, status: 'QUERY', category: 'Typo', unit: 'u1', review: 'pending', placed: '1' } });
  const accept = el({ dataset: { review: 'accepted' } }), reject = el({ dataset: { review: 'rejected' } });
  const fields = {};
  ['reason', 'category', 'page'].forEach(f => { fields[f] = el({ tagName: 'INPUT', dataset: { field: f }, value: '' }); });
  fields.status = el({ tagName: 'SELECT', dataset: { field: 'status' }, value: 'QUERY' });
  fields.reason.value = 'x';
  fields.category.value = 'Typo';
  fields.page.value = '3';
  const marker = card.querySelector('.save-state');
  card.querySelectorAll = sel => sel === '.review-btn' ? [accept, reject] : sel === '.finding-field' ? Object.values(fields) : [];
  return { card, accept, reject, fields, marker, reason: fields.reason, part: sel => card.querySelector(sel) };
}

async function scenario({ state = 'map_review', affected = 0, confirmAnswer = true, end = '2', delays = {}, fail = [], hold = [],
  cardIds = ['BK-001'], action }) {
  const calls = [], posted = [], events = [], asked = [], held = [];
  const failWhen = typeof fail === 'function' ? fail : (a) => fail.includes(a);
  const ctl = { fail: failWhen, hold };
  const fields = ['id', 'title', 'start', 'end', 'kind'].map(f => el({ tagName: f === 'kind' ? 'SELECT' : 'INPUT', dataset: { field: f },
    value: { id: 'u1', title: 'Ships', start: '1', end, kind: 'chapter' }[f] }));
  const row = el(); row.querySelectorAll = sel => sel === '.map-field' ? fields : [];
  const cards = cardIds.map(makeCard);
  const server = {};
  cardIds.forEach(fid => { server[fid] = { id: fid, status: 'QUERY', category: 'Typo', unit: 'u1', review: 'pending', reason: 'x',
    original: 'teh', page: 3, page_label: 'iii', placed_page: 3, placed: true }; });
  const nodes = { 'check-job': el({ dataset: { id: 'a'.repeat(32), csrf: 't', state, pageWords: '[100,200]',
      prices: '{"input_per_m":2,"output_per_m":8}' } }),
    'status-box': el(), progress: el(), metrics: el(), 'map-save': el(), estimate: el(), uncovered: el(), 'check-book': el(),
    english: el({ value: 'en-GB' }), instructions: el({ value: '' }), 'shared-start': el(), 'shared-end': el(),
    'build-pdf': el(), 'delete-job': el({ dataset: { name: 'Book' } }), 'editor-outputs': el() };
  const all = sel => sel === '.map-row' ? [row] : sel === '.map-field' ? fields : sel === '.finding-card' ? cards.map(c => c.card)
    : sel === '.finding-field' ? cards.flatMap(c => Object.values(c.fields)) : sel === '.review-btn' ? cards.flatMap(c => [c.accept, c.reject]) : [];
  // lokalne varijable zasjenjuju globalne: eval(src) ih koristi, pa kasni timer starog scenarija ne dira novi
  const document = { getElementById: id => nodes[id], createElement: () => el({ innerHTML: '' }), querySelectorAll: all };
  const location = { reload() { events.push('reload'); }, href: '' };
  const confirm = q => { asked.push(q); return confirmAnswer; };
  const alert = () => {};
  // posao krenuo od 'done': prvi save_finding ga (kao poslužitelj — M2) vrati u 'review'
  let leftDone = false;
  const fetch = async (url, opts) => {
    const action = opts.body ? opts.body.get('action') : new URL('http://x/' + url).searchParams.get('action');
    const body = opts.body ? Object.fromEntries(opts.body.entries()) : {};
    const field = FIELDS.find(f => f in body);
    const label = action + (body.review ? ':' + body.review : field ? ':' + field : '');
    calls.push(action);
    events.push('call:' + label);
    if (opts.body) posted.push(body);
    if (delays[action]) await sleep(delays[action]);
    let failIt = ctl.fail(action, body);
    if (ctl.hold.includes(action)) {
      failIt = await new Promise(r => held.push({ label, body, release: (bad = false) => r(bad) })) || failIt;
    }
    events.push('done:' + label);
    if (failIt) return { status: 422, ok: false, json: async () => ({ error: 'Not saved' }) };
    let reply = { ok: true };
    if (action === 'status') reply = { status: { state: leftDone ? 'review' : state },
      metrics: { findings: 1, reviewed: 0, pending: 1, accepted: 0, not_placed: 0 }, units: {} };
    else if (action === 'preview_run') reply = { affected, revision: 'rev1' };
    else if (action === 'save_map') reply = { map: {} };
    else if (action === 'save_finding') {
      const f = server[body.finding];
      const changes = {};
      FIELDS.forEach(k => { if (k in body) changes[k] = k === 'page' ? parseInt(body[k], 10) : String(body[k]).trim(); });
      const moved = LOCATION.some(k => k in changes && changes[k] !== f[k]);
      Object.assign(f, changes);
      if (moved) { delete f.placed; delete f.placed_page; f.page_label = 'p' + f.page; f.crop_stale = true; }
      if ('review' in body) f.review = body.review;
      else if (Object.keys(changes).length && f.review !== 'rejected') f.review = 'edited';
      if (state === 'done') leftDone = true;   // poslužitelj (M2): stvarna izmjena u done vraća posao u review
      reply = { finding: JSON.parse(JSON.stringify(f)) };
    }
    return { status: 200, ok: true, json: async () => reply };
  };
  eval(src);
  await sleep(30);
  const c0 = cards[0];
  const ctx = { nodes, fields, cards, card: c0 && c0.card, accept: c0 && c0.accept, reject: c0 && c0.reject, reason: c0 && c0.reason,
    marker: c0 && c0.marker, held, ctl, events, posted, calls, server,
    waitHeld: n => until(() => held.length >= n, n + ' held requests') };
  await action(ctx);
  await sleep(1000);
  return Object.assign(ctx, { asked, estimate: nodes.estimate.textContent, uncovered: nodes.uncovered.textContent,
    saves: posted.filter(b => b.action === 'save_finding') });
}
const pick = (b) => { const o = {}; Object.keys(b).filter(k => !['action', 'id', 'csrf_token'].includes(k)).forEach(k => { o[k] = b[k]; }); return JSON.stringify(o); };
const order = (events, a, b) => { const i = events.indexOf(a), j = events.indexOf(b); return i >= 0 && j > i; };

(async () => {
  // --- mapa, procjena, pokretanje provjere ---
  let r = await scenario({ action: async ({ nodes, fields }) => {
    fields[1].value = 'Ships and Harbours'; fields[1].fire('input');
    fields[1].value = 'typed without an event';   // vrijednost se snima u trenutku događaja
    await nodes['check-book'].fire('click'); } });
  const s = r.calls.indexOf('save_map'), p = r.calls.indexOf('preview_run'), st = r.calls.indexOf('start_run');
  check(s >= 0 && s < p && p < st, 'map edit saved before the check starts', r.calls.join(','));
  const mapBody = r.posted.find(b => b.action === 'save_map') || {};
  const units = JSON.parse(mapBody.units || '[]');
  check(units.length === 1 && units[0].title === 'Ships and Harbours' && units[0].start === 1 && units[0].end === 2 && units[0].id === 'u1'
    && mapBody.english === 'en-GB', 'save_map sends the map and settings captured at the edit', mapBody.units);
  check(r.calls.filter(c => c === 'save_map').length === 1, 'one save_map for one edit', r.calls.join(','));
  check((r.posted.find(b => b.action === 'start_run') || {}).revision === 'rev1', 'start_run sends the previewed revision');
  check(/300 words/.test(r.estimate), 'estimate counts words of the map', r.estimate);

  r = await scenario({ affected: 5, confirmAnswer: false, action: async ({ nodes }) => { await nodes['check-book'].fire('click'); } });
  check(!r.calls.includes('start_run') && r.asked.some(q => q.includes('5 findings')), 'affected findings are confirmed before a new check', r.calls.join(','));
  check(r.nodes['check-book'].disabled === false && r.fields.every(f => !f.disabled), 'cancelled check re-enables the map and the button');

  r = await scenario({ end: '1', confirmAnswer: false, action: async ({ nodes }) => { await nodes['check-book'].fire('click'); } });
  check(/2/.test(r.uncovered) && !r.calls.includes('start_run') && r.asked.some(q => q.includes('not in any unit')), 'uncovered pages are shown and confirmed', r.uncovered);
  check(!r.calls.includes('preview_run') && r.nodes['check-book'].disabled === false && r.fields.every(f => !f.disabled),
    'cancelling at uncovered pages re-enables controls', r.calls.join(','));

  // --- Build čeka spremanja ---
  let lockedDuring = null;
  r = await scenario({ state: 'review', delays: { save_finding: 300 }, action: async ({ nodes, accept, reason }) => {
    accept.fire('click'); await sleep(100);
    const clicked = nodes['build-pdf'].fire('click'); await sleep(50);
    lockedDuring = reason.disabled && accept.disabled && nodes['build-pdf'].disabled && nodes['delete-job'].disabled;
    await clicked; } });
  const saved = r.events.indexOf('done:save_finding:accepted'), build = r.events.indexOf('call:start_build');
  check(saved >= 0 && build > saved, 'build waits for a save that is still in flight', r.events.join(','));
  check(lockedDuring === true, 'fields and competing actions are locked while waiting for saves');

  r = await scenario({ state: 'review', fail: ['save_finding'], action: async ({ nodes, accept }) => { accept.fire('click'); await sleep(900); await nodes['build-pdf'].fire('click'); } });
  check(!r.calls.includes('start_build'), 'a failed save blocks the build', r.calls.join(','));
  check(r.marker.textContent === 'Not saved' && /danger/.test(r.marker.className), 'the failed save stays visible', r.marker.textContent);
  check(r.nodes['build-pdf'].disabled === false && r.reason.disabled === false && r.accept.disabled === false,
    'controls are re-enabled after a blocked build');
  check(/NOT saved/.test(r.nodes['status-box'].textContent), 'blocked build explains why', r.nodes['status-box'].textContent);

  r = await scenario({ state: 'review', fail: ['start_build'], action: async ({ nodes }) => { await nodes['build-pdf'].fire('click'); } });
  check(r.calls.includes('start_build') && r.nodes['build-pdf'].disabled === false && r.reason.disabled === false,
    'a phase that does not start re-enables controls', r.calls.join(','));

  // --- redoslijed namjera za jedan nalaz ---
  r = await scenario({ state: 'review', delays: { save_finding: 200 }, action: async ({ reason, reject }) => { reason.value = 'better'; reason.fire('input'); await sleep(10); reject.fire('click'); } });
  let seq = r.events.filter(e => e.includes('save_finding')).join(',');
  check(seq === 'call:save_finding:reason,done:save_finding:reason,call:save_finding:rejected,done:save_finding:rejected',
    'edit and reject of one finding are sent one after the other', seq);
  check(r.saves.map(pick).join('|') === '{"finding":"BK-001","reason":"better"}|{"finding":"BK-001","review":"rejected"}',
    'edit then reject: payloads in intent order', r.saves.map(pick).join('|'));
  check(r.card.dataset.review === 'rejected' && r.marker.textContent === 'saved', 'edit then reject: card ends rejected and saved',
    r.card.dataset.review + ' / ' + r.marker.textContent);

  const markers = [];
  r = await scenario({ state: 'review', hold: ['save_finding'], action: async ({ reason, reject, accept, held, waitHeld, marker, calls }) => {
    reject.fire('click'); await waitHeld(1);
    reason.value = 'fixed'; reason.fire('input'); accept.fire('click'); await sleep(50);
    markers.push(calls.filter(c => c === 'save_finding').length);   // drugi zahtjev ne ide dok prvi ne odgovori
    held[0].release(); await waitHeld(2); markers.push(marker.textContent);
    held[1].release(); await waitHeld(3); markers.push(marker.textContent);
    held[2].release(); } });
  check(r.saves.map(pick).join('|') === '{"finding":"BK-001","review":"rejected"}|{"finding":"BK-001","reason":"fixed"}|{"finding":"BK-001","review":"accepted"}',
    'reject, edit, accept: payloads in intent order', r.saves.map(pick).join('|'));
  check(markers[0] === 1, 'requests of one finding go strictly one after another', 'in flight: ' + markers[0]);
  check(markers[1] !== 'saved' && markers[2] !== 'saved', 'no "saved" while later saves of the finding are in flight', markers.slice(1).join(','));
  check(r.card.dataset.review === 'accepted' && r.server['BK-001'].review === 'accepted' && r.marker.textContent === 'saved',
    'reject, edit, accept: final state accepted', r.card.dataset.review + ' / ' + r.server['BK-001'].review);

  r = await scenario({ state: 'review', action: async ({ reason }) => {
    reason.value = 'first'; reason.fire('input'); await sleep(100);
    reason.value = 'second'; reason.fire('input'); reason.value = 'typed later, not an input event'; } });
  check(r.saves.map(pick).join('|') === '{"finding":"BK-001","reason":"second"}', 'two quick edits send the last edited value once',
    r.saves.map(pick).join('|'));

  r = await scenario({ state: 'review', action: async ({ reason, cards, reject }) => {
    reason.value = 'a'; reason.fire('input');
    cards[0].fields.category.value = ' Grammar '; cards[0].fields.category.fire('input');
    reason.value = 'b'; reason.fire('input');       // ponovno uređeno polje dobiva novi redni broj
    reject.fire('click'); } });
  check(r.saves.map(pick).join('|') === '{"finding":"BK-001","category":" Grammar "}|{"finding":"BK-001","reason":"b"}|{"finding":"BK-001","review":"rejected"}',
    're-edited field is sent after the later edit, before the reject', r.saves.map(pick).join('|'));
  check(r.card.dataset.category === 'Grammar' && r.cards[0].part('.f-category').textContent === 'Grammar'
    && r.cards[0].part('.f-reason').textContent === 'b' && r.card.dataset.review === 'rejected',
    'card is synced from the saved finding (category, texts, review)', r.card.dataset.category + ' / ' + r.cards[0].part('.f-category').textContent);

  r = await scenario({ state: 'review', action: async ({ cards }) => { cards[0].fields.page.value = '5'; cards[0].fields.page.fire('input'); } });
  check(r.cards[0].part('.crop').classList.contains('d-none') && !r.cards[0].part('.crop-stale').classList.contains('d-none')
    && r.card.dataset.placed === '1' && r.cards[0].part('.f-page-label').textContent === 'p5' && r.cards[0].part('.f-pdf-page').textContent === '5',
    'a moved finding shows "Location changed" instead of the old crop', r.cards[0].part('.f-page-label').textContent);

  // --- pokušaj uređivanja tijekom flush-a ---
  let during = null;
  r = await scenario({ state: 'review', hold: ['save_finding'], action: async ({ nodes, reason, accept, held, waitHeld }) => {
    reason.value = 'x1'; reason.fire('input');
    const clicked = nodes['build-pdf'].fire('click'); await waitHeld(1);
    during = reason.disabled && accept.disabled;
    reason.value = 'sneaky'; reason.fire('input'); accept.fire('click'); await sleep(900);
    held[0].release(); await clicked; } });
  check(during === true, 'fields are locked during the flush');
  check(r.saves.map(pick).join('|') === '{"finding":"BK-001","reason":"x1"}' && order(r.events, 'done:save_finding:reason', 'call:start_build'),
    'an edit attempted during the flush is not sent; build follows the save', r.events.join(','));

  // --- greška jedne grupe uz uspjeh druge; greška ostaje do uspjeha tog polja ---
  const snap = {};
  r = await scenario({ state: 'review', cardIds: ['BK-001', 'BK-002'],
    fail: (a, b) => a === 'save_finding' && b.finding === 'BK-001' && 'reason' in b,
    action: async ({ nodes, cards, ctl, calls }) => {
      const [a, b] = cards;
      a.reason.value = 'bad'; a.reason.fire('input'); b.reason.value = 'good'; b.reason.fire('input');
      await nodes['build-pdf'].fire('click');
      snap.first = { build: calls.includes('start_build'), a: a.marker.textContent, b: b.marker.textContent, btn: nodes['build-pdf'].disabled };
      a.fields.category.value = 'Style'; a.fields.category.fire('input'); await sleep(900);
      snap.afterOther = a.marker.textContent;
      await nodes['build-pdf'].fire('click');
      snap.second = calls.includes('start_build');
      ctl.fail = () => false;
      a.reason.value = 'fixed'; a.reason.fire('input');
      await nodes['build-pdf'].fire('click'); } });
  check(snap.first.build === false && snap.first.a === 'Not saved' && snap.first.b === 'saved' && snap.first.btn === false,
    'one group failing blocks the build while the other shows saved', JSON.stringify(snap.first));
  check(snap.afterOther === 'Not saved' && snap.second === false, 'the error stays after another field of the finding saves',
    snap.afterOther + ' / build=' + snap.second);
  check(r.calls.includes('start_build') && r.cards[0].marker.textContent === 'saved', 'saving that field again clears the error and allows the build',
    r.calls.join(','));

  // --- spremanje u stanju done koje posao vrati u review (M1): urednički izlazi nestaju bez ručnog reloada ---
  r = await scenario({ state: 'done', action: async ({ accept }) => { accept.fire('click'); } });
  check(r.nodes['editor-outputs'].classList.contains('d-none'), 'editor PDF/report links are hidden once the job leaves done',
    r.events.join(','));
  check(r.nodes['check-job'].dataset.state === 'review', 'the page state is updated to review', r.nodes['check-job'].dataset.state);
  check(/Review the findings/.test(r.nodes['status-box'].textContent), 'the status box shows the review message',
    r.nodes['status-box'].textContent);

  // --- brisanje: lokalna spremanja završe prije, ništa ne ide poslije ---
  r = await scenario({ state: 'review', hold: ['delete'], action: async ({ nodes, reason, accept, waitHeld, held }) => {
    reason.value = 'last'; reason.fire('input');
    const clicked = nodes['delete-job'].fire('click'); await waitHeld(1);
    reason.value = 'late'; reason.fire('input'); accept.fire('click'); await sleep(900);
    held[0].release(); await clicked; } });
  const del = r.events.indexOf('call:delete');
  check(del >= 0 && order(r.events, 'done:save_finding:reason', 'call:delete') && !r.events.slice(del).some(e => e.startsWith('call:save_')),
    'delete finishes local saves and nothing is sent after it', r.events.join(','));
  check(r.saves.map(pick).join('|') === '{"finding":"BK-001","reason":"last"}', 'delete: only the edit made before it was saved', r.saves.map(pick).join('|'));

  process.exitCode = failures ? 1 : 0;
  console.log(failures ? failures + ' FAILED' : 'all passed');
})().catch(e => { console.log('FAIL unexpected error: ' + (e && e.stack || e)); process.exitCode = 1; });
