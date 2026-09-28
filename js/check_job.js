// check_job.js - mapa knjige, praćenje obrade i pregled nalaza (Check)
(function () {
	const root = document.getElementById('check-job');
	const id = root.dataset.id;
	const csrf = root.dataset.csrf;
	const RUNNING = ['queued', 'check_map', 'check_run', 'check_build'];
	const LABELS = { queued: 'Waiting in queue…', check_map: 'Mapping the book…',
		map_review: 'Check the chapter map, then click “Check the book”.', check_run: 'Checking the chapters…',
		review: 'Review the findings, then click “Build PDF for the editor”.', check_build: 'Building the PDF…',
		done: 'Finished.', failed: 'Processing error.' };

	async function api(params, method = 'POST') {
		let url = 'check_api.php', opts = { method, credentials: 'same-origin' };
		if (method === 'POST') {
			const body = new FormData();
			Object.entries({ ...params, id, csrf_token: csrf }).forEach(([k, v]) => body.append(k, v));
			opts.body = body;
		} else {
			url += '?' + new URLSearchParams({ ...params, id });
		}
		const res = await fetch(url, opts);   // mrežna greška → TypeError (poll ponavlja)
		let data;
		try { data = await res.json(); } catch (e) { data = null; }
		if (res.status === 401) {
			const err = new Error('Your session has expired — please log in again (the change was NOT saved)');
			err.auth = true;
			throw err;
		}
		if (data === null) throw new Error('Server error (HTTP ' + res.status + ') — the change was NOT saved');
		if (!res.ok) throw new Error(data.error || 'Error');
		return data;
	}

	const statusBox = document.getElementById('status-box');
	function showError(e) { statusBox.textContent = e.message; statusBox.className = 'alert alert-danger'; }

	// --- spremanje ---
	// Svaka izmjena u trenutku događaja snimi vrijednosti i dobije rastući redni broj (seq). Grupa = jedan nalaz / mapa;
	// zahtjevi grupe idu strogo jedan za drugim, a kad bilo koji krene, kreću i SVI zakazani iste grupe po seq-u —
	// ranija izmjena nikad ne ide iza kasnije (npr. uređeno polje prije Accept/Reject). Ponovno uređeno polje
	// dobiva novi seq. Neuspjelo spremanje ostaje u `failed` dok isto polje ne uspije.
	const pending = {};   // key → {key, group, fn, seq, timer} — zakazano, još nije poslano
	const queues = {};    // group → zadnji promise u redu grupe
	const busy = {};      // group → broj poslanih zahtjeva bez odgovora
	const failed = {};    // key → {group, message}
	const markers = {};   // group → element .save-state
	const onSaved = {};   // group → fn(odgovor) kad je grupa mirna
	const lastSaved = {}; // group → zadnji uspješan odgovor
	let seq = 0;
	let started = 0;      // broj pokrenutih spremanja (flushSaves zna je li se u međuvremenu pojavilo novo)
	let locked = false;   // tijekom flush/faze/brisanja izmjene se ne primaju
	const groupPending = group => Object.values(pending).some(p => p.group === group);

	const markerBase = new Map();   // marker → izvorne klase (npr. „save-state ms-auto“)
	function showMarker(group) {
		const m = markers[group];
		if (!m) return;
		if (!markerBase.has(m)) markerBase.set(m, m.className || 'save-state');
		const set = (text, cls) => { m.textContent = text; m.className = markerBase.get(m) + ' ' + cls; };
		const err = Object.values(failed).find(f => f.group === group);
		if (err) set(err.message, 'text-danger');                        // greška ostaje dok to polje ne uspije
		else if (groupPending(group) || busy[group]) set('…', 'text-muted');
		else set('saved', 'text-success');                              // tek kad ništa nije zakazano ni u tijeku
	}
	function schedule(key, group, fn, delay = 800) {
		if (locked) return;
		if (pending[key]) clearTimeout(pending[key].timer);
		const entry = { key, group, fn, seq: ++seq };
		entry.timer = setTimeout(() => dispatch(group), delay);
		pending[key] = entry;
		showMarker(group);
	}
	// pošalji sva zakazana spremanja (jedne grupe ili svih), starija namjera prva
	function dispatch(group) {
		Object.values(pending).filter(p => group === undefined || p.group === group).sort((a, b) => a.seq - b.seq)
			.forEach(p => send(p).catch(() => {}));
	}
	function settled(group) {
		busy[group]--;
		if (!groupPending(group) && !busy[group] && lastSaved[group] && onSaved[group]) {
			const data = lastSaved[group];
			delete lastSaved[group];
			onSaved[group](data);
		}
		showMarker(group);
	}
	function send(entry) {
		clearTimeout(entry.timer);
		delete pending[entry.key];
		started++;
		const group = entry.group;
		busy[group] = (busy[group] || 0) + 1;
		const job = (queues[group] || Promise.resolve()).catch(() => {}).then(entry.fn).then(data => {
			delete failed[entry.key];
			lastSaved[group] = data;
			settled(group);
		}, e => {
			failed[entry.key] = { group, message: e.message };
			settled(group);
			throw e;
		});
		queues[group] = job;
		return job;
	}
	async function flushSaves() {
		for (;;) {   // čekaj dok ništa nije ni zakazano ni u tijeku
			dispatch();
			const before = started;
			await Promise.allSettled(Object.values(queues));
			if (!Object.keys(pending).length && started === before) break;
		}
		const errors = Object.values(failed);
		if (errors.length) throw new Error('Some changes were NOT saved (' + errors[0].message + ') — fix them before continuing');
	}
	// dok se čeka na spremanja i pokretanje faze: sva polja i akcije koje mijenjaju posao su onemogućeni
	const LOCK_IDS = ['shared-start', 'shared-end', 'english', 'instructions', 'add-unit', 'check-book', 'reopen-map', 'retry-map',
		'build-pdf', 'bulk-accept', 'delete-job'];
	const lockedEls = new Map();   // element → disabled prije zaključavanja
	function setLocked(on) {
		locked = on;
		if (!on) {
			lockedEls.forEach((was, el) => { el.disabled = was; });
			lockedEls.clear();
			return;
		}
		const els = [];
		['.map-field', '.remove-unit', '.finding-field', '.review-btn'].forEach(sel => document.querySelectorAll(sel).forEach(el => els.push(el)));
		LOCK_IDS.forEach(k => { const el = document.getElementById(k); if (el) els.push(el); });
		els.forEach(el => { if (!lockedEls.has(el)) lockedEls.set(el, el.disabled); el.disabled = true; });
	}

	// --- mapa ---
	const INTS = ['start', 'end', 'bibliography_start', 'bibliography_end'];
	const pageWords = JSON.parse(root.dataset.pageWords || '[]');
	const prices = JSON.parse(root.dataset.prices || '{}');
	function collectMap() {
		const units = [];
		document.querySelectorAll('.map-row').forEach(row => {
			const u = {};
			row.querySelectorAll('.map-field').forEach(el => {
				const k = el.dataset.field;
				u[k] = INTS.includes(k) ? (el.value === '' ? null : parseInt(el.value, 10)) : el.value;
			});
			units.push(u);
		});
		return units;
	}
	function uncoveredRanges(units) {
		const covered = new Array(pageWords.length + 1).fill(false);
		units.forEach(u => { if (u.start && u.end) for (let p = u.start; p <= u.end && p <= pageWords.length; p++) covered[p] = true; });
		const ranges = [];
		for (let p = 1; p <= pageWords.length; p++) {
			if (covered[p]) continue;
			const last = ranges[ranges.length - 1];
			if (last && last[1] === p - 1) last[1] = p; else ranges.push([p, p]);
		}
		return ranges.map(([a, b]) => a === b ? String(a) : a + '–' + b);
	}
	function updateMapInfo() {
		const units = collectMap();
		let words = 0;
		units.forEach(u => {
			if (u.kind === 'skip' || !u.start || !u.end) return;
			for (let p = u.start; p <= u.end; p++) words += pageWords[p - 1] || 0;
		});
		// ~1.35 tokena po riječi, +30 % za bibliografiju, kontekst i upute; izlaz ~20 % ulaza
		const cost = (words * 1.35 * 1.3 * (prices.input_per_m || 0) + words * 0.2 * (prices.output_per_m || 0)) / 1e6;
		const box = document.getElementById('estimate');
		if (box) box.textContent = '≈ ' + words + ' words · estimated $' + (cost * 0.7).toFixed(2) + '–$' + (cost * 1.3).toFixed(2);
		const gaps = uncoveredRanges(units);
		const info = document.getElementById('uncovered');
		if (info) info.textContent = gaps.length ? 'PDF pages not in any unit (not checked): ' + gaps.join(', ') : '';
		return gaps;
	}
	markers.map = document.getElementById('map-save');
	function mapParams(units) {
		const value = k => { const el = document.getElementById(k); return el ? el.value : ''; };
		return { action: 'save_map', units: JSON.stringify(units), shared_start: value('shared-start'),
			shared_end: value('shared-end'), english: value('english'), instructions: value('instructions') };
	}
	function saveMap() {
		if (locked) return;
		const params = mapParams(collectMap());   // mapa i postavke u trenutku izmjene
		schedule('map', 'map', () => api(params));
		updateMapInfo();
	}
	if (root.dataset.state === 'map_review') {
		document.querySelectorAll('.map-row').forEach(row => row.querySelectorAll('.map-field').forEach(el =>
			el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', saveMap)));
		['shared-start', 'shared-end', 'english', 'instructions'].forEach(k => {
			const el = document.getElementById(k);
			if (el) el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', saveMap);
		});
	}
	updateMapInfo();

	// zaključaj, pričekaj spremanja, izvrši akciju; ako ne uspije, otključaj
	async function lockedAction(fn) {
		setLocked(true);
		try { await flushSaves(); await fn(); }
		catch (e) { showError(e); setLocked(false); }
	}

	// dodavanje / uklanjanje cjeline: spremi pa učitaj stranicu (poslužitelj dodjeljuje ID)
	function saveUnitsAndReload(units) {
		return lockedAction(async () => { await api(mapParams(units)); location.reload(); });
	}
	const addUnit = document.getElementById('add-unit');
	if (addUnit) addUnit.addEventListener('click', () => {
		if (locked) return;
		const units = collectMap();
		const gaps = uncoveredRanges(units);
		if (!gaps.length) { alert('All pages are in a unit — shorten a unit first to free pages for the new one.'); return; }
		const first = parseInt(gaps[0], 10);
		const last = gaps[0].includes('–') ? parseInt(gaps[0].split('–')[1], 10) : first;
		units.push({ id: '', title: 'New unit', author: '', start: first, end: last, language: 'en', kind: 'chapter',
			notes: 'none', bibliography_start: null, bibliography_end: null });
		return saveUnitsAndReload(units);
	});
	document.querySelectorAll('.remove-unit').forEach(btn => btn.addEventListener('click', () => {
		if (locked) return;
		const units = collectMap();
		if (units.length <= 1) { alert('The map needs at least one unit.'); return; }
		if (!confirm('Remove unit “' + units[+btn.dataset.index].title + '” from the map? Its pages will not be checked.')) return;
		units.splice(+btn.dataset.index, 1);
		return saveUnitsAndReload(units);
	}));

	let wasRunning = RUNNING.includes(root.dataset.state);
	const checkBook = document.getElementById('check-book');
	if (checkBook) checkBook.addEventListener('click', async () => {
		if (locked) return;
		setLocked(true);
		try {
			const gaps = updateMapInfo();
			if (gaps.length && !confirm('PDF pages ' + gaps.join(', ') + ' are not in any unit and will not be checked. Continue?')) {
				setLocked(false);
				return;
			}
			await flushSaves();
			const preview = await api({ action: 'preview_run' }, 'GET');
			if (preview.affected > 0 && !confirm(preview.affected + ' findings from the previous check (including reviewed ones) '
				+ 'belong to units whose map or settings changed and will be deleted. Continue?')) {
				setLocked(false);
				return;
			}
			await api({ action: 'start_run', revision: preview.revision });
			wasRunning = true;
			poll();
		} catch (e) { showError(e); setLocked(false); }
	});

	function phaseButton(elementId, action) {
		const button = document.getElementById(elementId);
		if (!button) return;
		button.addEventListener('click', () => {
			if (locked) return;
			return lockedAction(async () => { await api({ action }); location.reload(); });
		});
	}
	phaseButton('reopen-map', 'reopen_map');
	phaseButton('retry-map', 'retry_map');
	phaseButton('build-pdf', 'start_build');

	// --- nalazi ---
	const FINDING_TEXTS = ['status', 'category', 'original', 'replacement', 'reason', 'related', 'page_label'];
	// kartica prema spremljenom nalazu s poslužitelja (review, status, kategorija, tekstovi, pozicija, filteri)
	function syncCard(card, f) {
		const d = card.dataset;
		d.review = f.review || 'pending';
		d.status = f.status;
		d.category = f.category;
		d.placed = f.placed === false ? '0' : '1';
		FINDING_TEXTS.forEach(k => {
			const el = card.querySelector('.f-' + k.replace('_', '-'));
			if (el) el.textContent = f[k] == null ? '' : String(f[k]);
		});
		['replacement', 'related'].forEach(k => {
			const row = card.querySelector('.f-' + k + '-row');
			if (row) row.classList.toggle('d-none', !f[k]);
		});
		const pdfPage = card.querySelector('.f-pdf-page');
		if (pdfPage) pdfPage.textContent = String(f.placed_page == null ? f.page : f.placed_page);
		const unplaced = card.querySelector('.f-unplaced');
		if (unplaced) unplaced.classList.toggle('d-none', f.placed !== false);
		if (f.crop_stale) {
			const img = card.querySelector('.crop');
			if (img) img.classList.add('d-none');
			const note = card.querySelector('.crop-stale');
			if (note) note.classList.remove('d-none');
		}
		const active = document.activeElement;
		card.querySelectorAll('.finding-field').forEach(el => {
			if (el !== active && f[el.dataset.field] !== undefined) el.value = f[el.dataset.field] == null ? '' : String(f[el.dataset.field]);
		});
		card.querySelectorAll('.review-btn').forEach(b => b.classList.toggle('active', b.dataset.review === d.review));
	}
	document.querySelectorAll('.finding-card').forEach(card => {
		const fid = card.dataset.id;
		const group = 'f:' + fid;
		markers[group] = card.querySelector('.save-state');
		onSaved[group] = data => { if (data && data.finding) syncCard(card, data.finding); applyFilter(); };
		card.querySelectorAll('.review-btn').forEach(btn => btn.addEventListener('click', () => {
			if (locked) return;
			const review = btn.dataset.review;
			card.dataset.review = review;
			// odmah: zakazane izmjene ovog nalaza idu prije odluke (isti red, manji seq)
			schedule(group + ':review', group, () => api({ action: 'save_finding', finding: fid, review }), 0);
			applyFilter();
		}));
		card.querySelectorAll('.finding-field').forEach(el => el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', () => {
			if (locked) return;
			const field = el.dataset.field, value = el.value;   // vrijednost u trenutku izmjene
			if (card.dataset.review !== 'rejected') card.dataset.review = 'edited';
			schedule(group + ':' + field, group, () => api({ action: 'save_finding', finding: fid, [field]: value }));
		}));
	});

	function applyFilter() {
		const v = k => { const el = document.getElementById(k); return el ? (el.type === 'checkbox' ? el.checked : el.value) : ''; };
		const active = document.activeElement;
		let shown = 0;
		document.querySelectorAll('.finding-card').forEach(card => {
			const d = card.dataset;
			const show = (!v('filter-review') || d.review === v('filter-review')) && (!v('filter-status') || d.status === v('filter-status'))
				&& (!v('filter-category') || d.category === v('filter-category')) && (!v('filter-unit') || d.unit === v('filter-unit'))
				&& (!v('filter-unplaced') || d.placed === '0');
			// kartica u kojoj korisnik upravo tipka ne nestaje ispod njega
			const keep = !!(active && card.contains && card.contains(active));
			card.classList.toggle('d-none', !show && !keep);
			if (show || keep) shown++;
		});
		const count = document.getElementById('shown-count');
		if (count) count.textContent = shown + ' shown';
	}
	['filter-review', 'filter-status', 'filter-category', 'filter-unit', 'filter-unplaced'].forEach(k => {
		const el = document.getElementById(k);
		if (el) el.addEventListener('change', applyFilter);
	});
	applyFilter();

	const bulk = document.getElementById('bulk-accept');
	if (bulk) bulk.addEventListener('click', () => {
		if (locked) return;
		const category = document.getElementById('filter-category').value;
		if (!category) { alert('Choose a category in the filter first.'); return; }
		if (!confirm('Accept all pending findings in “' + category + '”?')) return;
		return lockedAction(async () => { await api({ action: 'bulk_review', category, review: 'accepted' }); location.reload(); });
	});

	// brisanje: nove izmjene se ne primaju, već zakazana/aktivna spremanja završe PRIJE brisanja — ništa ne kasni za njim
	const del = document.getElementById('delete-job');
	if (del) del.addEventListener('click', async () => {
		if (locked) return;
		if (!confirm('Delete check “' + (del.dataset.name || '') + '” and all its files? This cannot be undone.')) return;
		setLocked(true);
		try {
			await flushSaves().catch(() => {});   // neuspjela spremanja nisu važna za posao koji se briše
			await api({ action: 'delete' });
			location.href = 'check.php';
		} catch (e) { alert(e.message); setLocked(false); }
	});

	// --- status ---
	const progress = document.getElementById('progress');
	const metricsBox = document.getElementById('metrics');
	function render(status, metrics) {
		const state = status.state;
		statusBox.textContent = (LABELS[state] || state) + (status.message ? ' ' + status.message : '');
		statusBox.className = 'alert ' + (state === 'failed' ? 'alert-danger' : RUNNING.includes(state) ? 'alert-info' : 'alert-secondary');
		const p = status.progress;
		const showBar = state === 'check_run' && p && p.total;
		progress.classList.toggle('d-none', !showBar);
		if (showBar) progress.firstElementChild.style.width = Math.round(100 * p.done / p.total) + '%';
		if (metricsBox && metrics && metrics.findings) {
			metricsBox.textContent = metrics.findings + ' findings · ' + metrics.reviewed + ' reviewed · ' + metrics.pending
				+ ' pending · ' + metrics.accepted + ' accepted · ' + metrics.not_placed + ' not placed'
				+ (metrics.failed_units ? ' · ' + metrics.failed_units + ' failed units' : '');
		}
	}
	async function poll() {
		try {
			const data = await api({ action: 'status' }, 'GET');
			const running = RUNNING.includes(data.status.state);
			if (wasRunning && !running) { location.reload(); return; }   // faza gotova — učitaj stranicu
			wasRunning = running;
			render(data.status, data.metrics);
			if (running) setTimeout(poll, 3000);
		} catch (e) {
			statusBox.textContent = e.auth ? e.message : e.message + ' — retrying…';
			statusBox.className = 'alert alert-danger';
			if (!e.auth) setTimeout(poll, 10000);
		}
	}
	poll();
})();
