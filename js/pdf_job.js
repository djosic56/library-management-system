// pdf_job.js - spremanje opisa, praćenje obrade, filteri (PDF pristupačnost)
(function () {
	const root = document.getElementById('pdf-job');
	const id = root.dataset.id;
	const csrf = root.dataset.csrf;
	const RUNNING = ['queued', 'phase1', 'phase2'];
	const LABELS = { queued: 'Čeka na red…', phase1: 'AI opisuje slike…', review: 'Pregledaj opise, pa „Napravi PDF“.',
		phase2: 'Izrađujem PDF i provjeravam…', done: 'Obrada završena.', failed: 'Greška u obradi.' };

	async function api(params, method = 'POST') {
		let url = 'pdf_api.php', opts = { method, credentials: 'same-origin' };
		if (method === 'POST') {
			const body = new FormData();
			Object.entries({ ...params, id, csrf_token: csrf }).forEach(([k, v]) => body.append(k, v));
			opts.body = body;
		} else {
			url += '?' + new URLSearchParams({ ...params, id });
		}
		const res = await fetch(url, opts);
		let data;
		try { data = await res.json(); } catch (e) { data = null; }
		if (res.status === 401 || data === null) {
			throw new Error('Prijava je istekla — prijavi se ponovno (izmjena NIJE spremljena)');
		}
		if (!res.ok) throw new Error(data.error || 'Greška');
		return data;
	}

	// --- spremanje (debounce 800 ms, indikator po kartici) ---
	const timers = {};
	function schedule(key, marker, fn) {
		marker.textContent = '…';
		marker.className = 'save-state text-muted ms-auto';
		clearTimeout(timers[key]);
		timers[key] = setTimeout(async () => {
			try { await fn(); marker.textContent = 'spremljeno'; marker.className = 'save-state text-success ms-auto'; }
			catch (e) { marker.textContent = e.message; marker.className = 'save-state text-danger ms-auto'; }
		}, 800);
	}

	document.querySelectorAll('.image-card').forEach(card => {
		const xref = card.dataset.xref;
		const marker = card.querySelector('.save-state');
		card.querySelectorAll('.img-field').forEach(el => {
			const ev = el.tagName === 'TEXTAREA' ? 'input' : 'change';
			el.addEventListener(ev, () => {
				const value = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
				if (el.dataset.field === 'decorative') card.dataset.decorative = el.checked ? '1' : '0';
				schedule('img' + xref + el.dataset.field, marker,
					() => api({ action: 'save_image', xref, [el.dataset.field]: value }));
			});
		});
	});

	const docMarker = document.getElementById('doc-save');
	document.querySelectorAll('.doc-field').forEach(el => el.addEventListener('input', () =>
		schedule('doc', docMarker, () => api({ action: 'save_document',
			title: document.getElementById('doc-title').value, lang: document.getElementById('doc-lang').value }))));

	// --- filteri (zadano: Treba pažnju) ---
	function applyFilter(filter) {
		document.querySelectorAll('[data-filter]').forEach(b => b.classList.toggle('active', b.dataset.filter === filter));
		document.querySelectorAll('.image-card').forEach(card => {
			const show = filter === 'all' || (filter === 'attention' && card.dataset.attention === '1')
				|| (filter === 'decorative' && card.dataset.decorative === '1');
			card.classList.toggle('d-none', !show);
		});
	}
	document.querySelectorAll('[data-filter]').forEach(b => b.addEventListener('click', () => applyFilter(b.dataset.filter)));
	applyFilter('attention');

	// --- status i rezultat ---
	const statusBox = document.getElementById('status-box');
	const progress = document.getElementById('progress');
	const resultBox = document.getElementById('result-box');
	const makePdf = document.getElementById('make-pdf');
	const del = document.getElementById('delete-job');

	function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

	function render(status, summary) {
		const state = status.state;
		statusBox.textContent = (LABELS[state] || state) + (status.message ? ' ' + status.message : '');
		statusBox.className = 'alert ' + (state === 'failed' ? 'alert-danger' : RUNNING.includes(state) ? 'alert-info' : 'alert-secondary');
		const p = status.progress;
		progress.classList.toggle('d-none', !(state === 'phase1' && p && p.total));
		if (p && p.total) progress.firstElementChild.style.width = Math.round(100 * p.done / p.total) + '%';
		makePdf.disabled = RUNNING.includes(state);
		del.disabled = RUNNING.includes(state);

		if (state === 'done') {
			let verdict;
			if (status.pdfua_ok === true) verdict = '<div class="alert alert-success">PDF/UA-1 prolazi ✔</div>';
			else if (status.pdfua_ok === false) verdict = '<div class="alert alert-warning">Obrada završena — PDF/UA provjera nije prošla:<ul>'
				+ (status.pdfua_failed || []).map(f => '<li><b>' + esc(f[0]) + '</b> (' + esc(String(f[1])) + '×) ' + esc(f[2]) + '</li>').join('') + '</ul></div>';
			else verdict = '<div class="alert alert-secondary">PDF/UA provjera nije izvršena.</div>';
			const q = new URLSearchParams({ action: 'download', id });
			resultBox.innerHTML = verdict
				+ '<a class="btn btn-primary me-2" href="pdf_api.php?' + q + '&kind=output"><i class="bi bi-file-earmark-pdf"></i> Preuzmi PDF</a>'
				+ '<a class="btn btn-outline-primary" href="pdf_api.php?' + q + '&kind=report"><i class="bi bi-file-text"></i> Izvještaj</a>';
			resultBox.classList.remove('d-none');
		} else {
			resultBox.classList.add('d-none');
		}
	}

	let wasRunning = RUNNING.includes(root.dataset.state);
	async function poll() {
		try {
			const data = await api({ action: 'status' }, 'GET');
			const running = RUNNING.includes(data.status.state);
			if (wasRunning && !running && data.status.state === 'review') {
				location.reload();   // faza 1 gotova — učitaj kartice
				return;
			}
			wasRunning = running;
			render(data.status, data.summary);
			if (running) setTimeout(poll, 3000);
		} catch (e) {
			statusBox.textContent = e.message;
			statusBox.className = 'alert alert-danger';
		}
	}
	poll();

	makePdf.addEventListener('click', async () => {
		makePdf.disabled = true;
		try { await api({ action: 'start_phase2' }); wasRunning = true; poll(); }
		catch (e) { statusBox.textContent = e.message; statusBox.className = 'alert alert-danger'; makePdf.disabled = false; }
	});

	del.addEventListener('click', async () => {
		if (!confirm('Obrisati posao „' + del.dataset.name + '“ i sve njegove datoteke? Ovo se ne može poništiti.')) return;
		try { await api({ action: 'delete' }); location.href = 'pdf.php'; }
		catch (e) { alert(e.message); }
	});
})();
