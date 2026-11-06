/* Route Planner — planned routes and the survey drive. Vanilla JS; Leaflet is Doorhangers' own copy, the walks are Doorhangers' planWalks (walks.js), the
 * scoring is assets/plan.js.
 *
 *   PLAN: where, which day, how many, how long -> the ranker ranks, Doorhangers cuts, the planner scores; pick, edit
 *     block by block, or draw; Send to Doorhangers shows Doorhangers' own preview, and Make the route is the press.
 *   The survey. Two ways to drive, chosen by the site (Zorderz -> Route Planner, "record"):
 *   RECORDED: Start -> GPS (watchPosition) and the microphone recording like a voice memo
 *     (MediaRecorder), in parts: Pause, or the phone taking the microphone away, ends a part; Resume starts the next.
 *     Each part knows the moment it started, and its pieces are kept on the phone (IndexedDB) as they come, so a
 *     closed tab loses nothing. At End the parts go up in 1 MB pieces (resumable); the transcription service the
 *     platform connects transcribes them, its model reads them with the streets the car was on ("scratch that" taken
 *     as far back as the words meant), and the notes land on the drive by time. The same upload can serve a native
 *     recorder later.
 *   LIVE: the browser's SpeechRecognition, one note per sentence, placed where the speaking started.
 *   A voice-memo file recorded during a drive can be added afterwards: its start time is read from the file and
 *   nudged by the person, then it goes the same way as a recording.
 *   Nothing is confirmed here without a person. While the car moves (Doorhangers' rule: ZDH_PACE) the panel is
 *   voice-only: two 96 px buttons (pause / end), no typing, no small targets. Read-back is off by default.
 *   Gate codes are sealed on the server the moment they arrive and open only for the admin tier or the walker assigned
 *   a route there that day; this page never keeps one (a revealed code is shown for 30 s, then gone). */
(function () {
	'use strict';
	var PENDING_MODE = null, PENDING_OPTS = {};
	/* {mode: 'plan', zip} from Doorhangers' "Plan with Route Planner", {when: 'saturday'} from the ask bar's "plan
	   routes for Saturday", {mode: 'survey'} from "Drive-by survey". No mode: the Plan tab (the survey when one runs). */
	document.addEventListener('zdz_app_launch', function (e) {
		var d = e && e.detail;
		if (d && d.appId === 'route-planner') {
			var o = (d.options && typeof d.options === 'object') ? d.options : {};
			PENDING_OPTS = o; PENDING_MODE = o.mode === 'survey' || o.mode === 'plan' ? o.mode : (o.when || o.zip || o.area ? 'plan' : 'default');
			if (window.__zrpOpen) { window.__zrpOpen(PENDING_MODE, o); }
		}
	});

	function start() {
		var root = document.getElementById('zrp-app');
		if (!root || root.getAttribute('data-zrp-init')) { return; }
		root.setAttribute('data-zrp-init', '1');
		// a card drawn again (the dashboard re-rendered) attaches to the one running app, so a survey's
		// GPS, microphone and timers are never left running behind a second copy
		if (window.__zrpAttach) { window.__zrpAttach(root); return; }
		var C = window.ZRP_CONFIG || {};
		/* Where a map opens when nothing on it says where to look: the business's map centre, else null (a world view). */
		function homePoint() { var c = C.mapCenter && C.mapCenter.point; return (c && c.length === 2 && isFinite(+c[0]) && isFinite(+c[1])) ? [+c[0], +c[1]] : null; }
		var SR = window.SpeechRecognition || window.webkitSpeechRecognition || null;
		var MR = window.MediaRecorder || null;
		var PALETTE = ['#E69F00', '#009E73', '#0072B2', '#CC79A7', '#56B4E9', '#7F5F00', '#5D3A9B', '#3B6E00'];
		var SCOPE_WORDS = { here: 'a home here', house: 'this house', side: 'this side', block: 'the whole block', street: 'the whole street', neighborhood: 'the whole neighborhood', segment: 'this block' };
		var SCOPES = ['here', 'house', 'side', 'block', 'street', 'neighborhood'];
		var QKEY = 'zrp_queue_' + (C.userId || 0);
		var CHUNK = 1048576;
		var S = { tab: 'survey', planCfg: null, pmap: null, vocab: [], pending: [], retracted: [], drives: [], audio: [], codes: [], codesReady: 0, record: false, reads: 0, readback: false, snap: 1, booted: false, err: '', safety: false, survey: null, map: null, mapLayer: null, filter: {}, memo: null, uploading: false, showTaken: false };

		/* ------------------------------------------------------------ utils */
		function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
		function store(k, v) { try { if (v == null) { localStorage.removeItem(k); } else { localStorage.setItem(k, v); } } catch (e) {} }
		function recall(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
		function toast(msg, ms) {
			var k = window.ZDZ && window.ZDZ.ui;
			if (k && typeof k.toast === 'function') { try { k.toast(msg); return; } catch (e) {} }
			var t = document.createElement('div'); t.className = 'zrp-toast'; t.setAttribute('role', 'status'); t.textContent = msg; document.body.appendChild(t);
			setTimeout(function () { t.remove(); }, ms || 3500);
		}
		var nonceTried = 0;
		/* a tab left open overnight has an expired REST nonce; ask WordPress for a fresh one once, then
		   retry. Still refused = signed out: said on the page, the notes stay on the phone. */
		function freshNonce() {
			if (!C.ajax || Date.now() - nonceTried < 60000) { return Promise.reject(new Error('signed out')); }
			nonceTried = Date.now();
			return fetch(C.ajax + '?action=rest-nonce', { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (t) {
				t = String(t || '').trim(); if (!/^[a-f0-9]{6,20}$/i.test(t)) { throw new Error('signed out'); } C.nonce = t; return t;
			});
		}
		function api(path, opts, again) {
			opts = opts || {};
			return apiOnce(path, opts).then(null, function (e) {
				if (!again && (e.status === 401 || e.status === 403) && /nonce|cookie/i.test(e.code || '')) { return freshNonce().then(function () { return api(path, opts, true); }); }
				throw e;
			});
		}
		function apiOnce(path, opts) {
			var raw = opts.raw != null;
			return fetch((C.rest || '/wp-json/zorderz/v1/route-planner/') + path, {
				method: opts.method || 'GET', credentials: 'same-origin',
				headers: { 'Content-Type': raw ? 'application/octet-stream' : 'application/json', 'X-WP-Nonce': C.nonce || '' },
				body: raw ? opts.raw : (opts.body ? JSON.stringify(opts.body) : undefined)
			}).then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) { var e = new Error((j && j.message) || ('HTTP ' + r.status)); e.status = r.status; e.code = (j && j.code) || ''; throw e; } return j; }); });
		}
		function distM(a, b) { var x = (b[1] - a[1]) * Math.cos((a[0] + b[0]) / 2 * Math.PI / 180) * 111320, y = (b[0] - a[0]) * 110540; return Math.sqrt(x * x + y * y); }
		function bearing(a, b) { var cos = Math.cos(a[0] * Math.PI / 180); return (Math.atan2((b[1] - a[1]) * cos, b[0] - a[0]) * 180 / Math.PI + 360) % 360; }
		function vocabOf(slug) { for (var i = 0; i < S.vocab.length; i++) { if (S.vocab[i].slug === slug) { return S.vocab[i]; } } return null; }
		function colorOf(slug) { for (var i = 0; i < S.vocab.length; i++) { if (S.vocab[i].slug === slug) { return PALETTE[i % PALETTE.length]; } } return '#6B7280'; }
		function labelOf(slug) { var v = vocabOf(slug); return v ? v.label : slug; }
		function hasFlag(slug, f) { var v = vocabOf(slug); return !!(v && v.flags && v.flags.indexOf(f) !== -1); }
		function describe(n) {
			var tags = (n.status === 'confirmed' ? n.tags : n.suggested) || [];
			var what = tags.length ? tags.map(labelOf).join(', ') : 'Noted (no tag heard)';
			var where = n.snapped ? n.street : 'street not known yet';
			return what + ' · ' + (SCOPE_WORDS[n.scope] || 'a home here') + (n.house ? ' (' + n.house + ')' : '') + ' · ' + where;
		}
		function cid() { return 'n' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8); }
		/* what was heard is shown on the screen; a code in it never is (any form of "gate", "code",
		   "lockbox", "keypad": its digits and spoken numbers become dots) */
		var NUMW = '(?:zero|oh|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety|pound|star)';
		function maskCode(t) {
			t = String(t == null ? '' : t);
			if (!/(gate|gait|code|lock ?box|key ?pad|combo|keybox)/i.test(t)) { return t; }
			return t.replace(/\d/g, '•').replace(new RegExp('\\b' + NUMW + '(?:[\\s,.-]+' + NUMW + ')+\\b', 'gi'), '••••');
		}
		function heardLine(text, more) { return '“' + maskCode(text) + (more ? '…' : '') + '”'; }
		function clock(ms) { try { return new Date(ms).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' }); } catch (e) { return String(new Date(ms)); } }
		function mmss(s) { s = Math.max(0, Math.round(s)); return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); }

		/* ------------------------------------------------------------ the queue (no signal), live mode */
		/* an outbox, not an archive -- a note still on the phone after 14 days (spoken words, perhaps a
		   code the server would have sealed) is let go */
		function queue() { try { var q = JSON.parse(recall(QKEY) || '[]'); if (!Array.isArray(q)) { return []; } var cut = Date.now() - 14 * 86400000, k = q.filter(function (x) { return !x.said_at_ms || x.said_at_ms > cut; }); if (k.length !== q.length) { saveQueue(k); } return k; } catch (e) { return []; } }
		var QMAX = 1000; // notes waiting on this phone: past this the phone refuses a new one out loud, never drops an old one
		function saveQueue(q) { store(QKEY, q.length ? JSON.stringify(q) : null); }
		var flushing = false, inflight = null, scratched = {}, signedOut = false;
		function flushQueue() {
			if (flushing) { return Promise.resolve(); }
			var q = queue(); if (!q.length) { return Promise.resolve(); }
			flushing = true;
			var item = q[0];
			inflight = item.client_id;
			return api('notes', { method: 'POST', body: item }).then(function (n) {
				saveQueue(queue().filter(function (x) { return x.client_id !== item.client_id; }));
				inflight = null; signedOut = false;
				if (scratched[item.client_id]) {
					// "scratch that" while it was on its way: it landed, so it is taken back now (an Undo on Review), never read back
					delete scratched[item.client_id];
					if (n && n.id) { api('notes/' + n.id, { method: 'POST', body: { op: 'retract' } }).then(function (r) { S.retracted.unshift(r); paintBadge(); }, function () { if (n.status === 'pending') { S.pending.unshift(n); paintBadge(); } }); }
				} else {
					onNoteSaved(n);
				}
				flushing = false;
				return flushQueue();
			}, function (e) {
				flushing = false; inflight = null;
				if (e.status === 401 || e.status === 403 || /signed out/.test(e.message)) {
					signedOut = true;
					panelSay(null, 'Signed out: ' + queue().length + ' note(s) wait on this phone. Sign in again and they will go.');
					paintBadge();
					return;
				}
				if (e.status && e.status >= 400 && e.status < 500 && e.status !== 429) {
					saveQueue(queue().filter(function (x) { return x.client_id !== item.client_id; })); // the server refused it for good
					panelSay('', 'Not noted: ' + e.message);
				} else if (S.survey) {
					panelSay(null, 'Saved on the phone; it goes when there is signal (' + queue().length + ' waiting).');
				}
			});
		}
		window.addEventListener('online', function () { flushQueue(); uploadAll(); });
		setInterval(function () { if (queue().length && !signedOut) { flushQueue(); } }, 20000);

		function onNoteSaved(n) {
			if (!n || !n.id) { return; }
			if (n.status === 'pending' && !S.pending.some(function (p) { return p.id === n.id; })) { S.pending.unshift(n); }
			if (n.status === 'retracted') { S.retracted.unshift(n); }
			if (S.survey && n.drive_id === S.survey.id) {
				if (n.status === 'pending') { S.survey.lastId = n.id; S.survey.count++; }
				var line = n.status === 'retracted' ? 'Taken back (Undo on Review).' : describe(n) + (n.negated && n.negated.length ? ' (not: ' + n.negated.map(labelOf).join(', ') + ')' : '') + (n.removed ? ' · took back “' + n.removed + '”' : '') + (n.code ? ' · code sealed' : '');
				panelSay(null, line);
				if (n.status === 'pending') { speak(((n.suggested || []).map(labelOf).join(', ') || 'Noted') + ', ' + (SCOPE_WORDS[n.scope] || 'a home here') + (n.snapped ? ', ' + n.street : '')); }
			}
			paintBadge();
		}

		/* ------------------------------------------------------------ speech out (read-back: off unless the site turns it on) */
		function speak(text) {
			var sv = S.survey;
			if (!S.readback || !window.speechSynthesis || !window.SpeechSynthesisUtterance || !text) { return; }
			try {
				var u = new SpeechSynthesisUtterance(text);
				u.rate = 1.05;
				// cancel() ends the utterance it interrupts with onerror; only the LATEST utterance may give
				// the microphone back, or the phone hears its own read-back
				var tok = sv ? (sv.speakTok = (sv.speakTok || 0) + 1) : 0;
				if (sv) { sv.speaking = true; try { if (sv.rec) { sv.rec.abort(); } } catch (e) {} } // the phone must not hear itself
				u.onend = u.onerror = function () { var cur = S.survey; if (cur && cur === sv && cur.speakTok === tok) { cur.speaking = false; listen(); } };
				window.speechSynthesis.cancel();
				window.speechSynthesis.speak(u);
			} catch (e) { if (sv) { sv.speaking = false; } }
		}

		/* ------------------------------------------------------------ commands (live mode) */
		var COMMANDS = [
			['end', /^(?:stop|end|finish) (?:the )?(?:survey|drive|driving)(?: survey)?$/],
			['scratch', /^(?:scratch that|delete that|undo(?: that)?|cancel that|never ?mind|forget that|strike that)$/],
			['keep', /^(?:keep (?:that|it)|confirm(?: that| it)?|yes,? keep (?:it|that)|save that)$/],
			['pause', /^(?:pause(?: notes| listening)?|stop listening|mute)$/],
			['resume', /^(?:resume(?: notes| listening)?|start listening|unmute|listen again)$/]
		];
		function command(text) {
			var t = String(text).toLowerCase().replace(/[^a-z' ]+/g, ' ').replace(/\s+/g, ' ').trim();
			for (var i = 0; i < COMMANDS.length; i++) { if (COMMANDS[i][1].test(t)) { return COMMANDS[i][0]; } }
			return null;
		}
		function runCommand(c) {
			var sv = S.survey; if (!sv) { return; }
			if (c === 'end') { endSurvey(); return; }
			if (c === 'pause') { pauseSurvey(true); speak('Paused. Say resume to go on.'); return; }
			if (c === 'resume') { pauseSurvey(false); speak('Listening.'); return; }
			if (c === 'scratch' || c === 'keep') {
				var q = queue();
				var open = q.filter(function (x) { return x.drive_id === sv.id && !x.retracted && !scratched[x.client_id]; });
				if (c === 'scratch' && open.length) {
					var last = open[open.length - 1]; // a second "scratch that" takes back the one before
					// never dropped without a trace -- it still goes, as words taken back, with an Undo on Review
					if (last.client_id === inflight) { scratched[last.client_id] = true; } else { last.retracted = 1; saveQueue(q); }
					panelSay('', 'Took back the last note (Undo on Review).'); speak('Taken back.'); return;
				}
				if (c === 'keep' && q.some(function (x) { return x.drive_id === sv.id; })) { speak('The last note has not gone yet. Keep it on the Review list.'); return; } // never confirm the one before
				if (!sv.lastId) { speak('There is no note to ' + (c === 'keep' ? 'keep' : 'take back') + ' yet.'); return; }
				var id = sv.lastId;
				api('notes/' + id, { method: 'POST', body: { op: c === 'keep' ? 'confirm' : 'retract' } }).then(function (n) {
					S.pending = S.pending.filter(function (p) { return p.id !== id; });
					if (c === 'scratch') { S.retracted.unshift(n); }
					paintBadge();
					panelSay('', c === 'keep' ? 'Kept: ' + describe(n) : 'Took back the last note (Undo on Review).');
					speak(c === 'keep' ? 'Kept.' : 'Taken back.');
					if (c === 'scratch') { sv.lastId = null; sv.count = Math.max(0, sv.count - 1); }
				}, function (e) { panelSay('', 'Could not ' + (c === 'keep' ? 'keep' : 'take back') + ' it: ' + e.message); });
			}
		}

		/* ------------------------------------------------------------ the phone's store for recordings (IndexedDB) */
		var IDB = null;
		function idb() {
			if (IDB) { return IDB; }
			IDB = new Promise(function (res, rej) {
				if (!window.indexedDB) { rej(new Error('no IndexedDB')); return; }
				var r = indexedDB.open('zrp-audio-' + (C.userId || 0), 1);
				r.onupgradeneeded = function () {
					var db = r.result;
					if (!db.objectStoreNames.contains('parts')) { db.createObjectStore('parts', { keyPath: 'k' }); }
					if (!db.objectStoreNames.contains('pieces')) { var p = db.createObjectStore('pieces', { keyPath: ['k', 'seq'] }); p.createIndex('k', 'k'); }
				};
				r.onsuccess = function () { res(r.result); };
				r.onerror = function () { rej(r.error || new Error('IndexedDB')); };
			});
			IDB.catch(function () { IDB = null; });
			return IDB;
		}
		function tx(stores, mode, fn) {
			return idb().then(function (db) {
				return new Promise(function (res, rej) {
					var t = db.transaction(stores, mode), out;
					t.oncomplete = function () { res(out); };
					t.onerror = t.onabort = function () { rej(t.error || new Error('IndexedDB')); };
					out = fn(t);
				});
			});
		}
		function putPart(p) { return tx(['parts'], 'readwrite', function (t) { t.objectStore('parts').put(p); }); }
		function putPiece(k, seq, blob) { return tx(['pieces'], 'readwrite', function (t) { t.objectStore('pieces').put({ k: k, seq: seq, blob: blob }); }); }
		function allParts() {
			return idb().then(function (db) { return new Promise(function (res, rej) { var r = db.transaction('parts').objectStore('parts').getAll(); r.onsuccess = function () { res(r.result || []); }; r.onerror = function () { rej(r.error); }; }); }, function () { return []; });
		}
		function piecesOf(k) {
			return idb().then(function (db) { return new Promise(function (res, rej) { var r = db.transaction('pieces').objectStore('pieces').index('k').getAll(k); r.onsuccess = function () { res((r.result || []).sort(function (a, b) { return a.seq - b.seq; })); }; r.onerror = function () { rej(r.error); }; }); });
		}
		/* only the pieces that were sent */
		function dropPart(k, seqs) {
			return piecesOf(k).then(function (ps) {
				var gone = seqs ? ps.filter(function (p) { return seqs.indexOf(p.seq) !== -1; }) : ps, rest = ps.length - gone.length;
				return tx(['parts', 'pieces'], 'readwrite', function (t) { if (!rest) { t.objectStore('parts').delete(k); } gone.forEach(function (p) { t.objectStore('pieces').delete([k, p.seq]); }); });
			});
		}

		/* ------------------------------------------------------------ recording (a part at a time) */
		function pickMime() {
			var c = ['audio/webm;codecs=opus', 'audio/mp4;codecs=mp4a.40.2', 'audio/mp4', 'audio/webm', 'audio/aac'];
			for (var i = 0; i < c.length; i++) { try { if (MR && MR.isTypeSupported && MR.isTypeSupported(c[i])) { return c[i]; } } catch (e) {} }
			return '';
		}
		function canRecord() { return !!(S.record && MR && navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.indexedDB); }
		function recStart() {
			var sv = S.survey; if (!sv || sv.mode !== 'record' || sv.paused || sv.part) { return; }
			var part = { n: sv.parts++, k: sv.id + '-' + (sv.parts - 1) + '-rec', seq: 0, started: 0, mime: '', recorder: null, stream: null, closing: false };
			sv.part = part;
			navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true } }).then(function (stream) {
				if (S.survey !== sv || sv.part !== part || sv.paused) { stream.getTracks().forEach(function (t) { t.stop(); }); return; }
				var mime = pickMime(), rec;
				try { rec = new MR(stream, mime ? { mimeType: mime, audioBitsPerSecond: 32000 } : { audioBitsPerSecond: 32000 }); } catch (e) { rec = new MR(stream); }
				part.recorder = rec; part.stream = stream; part.mime = rec.mimeType || mime || 'audio/webm';
				level(stream);
				rec.onstart = function () {
					part.started = Date.now();
					part.mime = rec.mimeType || part.mime; // Safari may name its type only once it has started
					sv.micTries = 0;
					putPart({ k: part.k, drive_id: sv.id, part: part.n, kind: 'rec', mime: part.mime, started_ms: part.started, offset_ms: 0, closed: false });
					sv.micErr = ''; paintPanel();
				};
				rec.ondataavailable = function (ev) {
					if (ev.data && ev.data.size) { var seq = part.seq++; putPiece(part.k, seq, ev.data).then(null, function () { sv.micErr = 'The phone is full: the recording could not be kept.'; paintPanel(); }); }
				};
				rec.onstop = function () {
					try { stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
					if (part.started) { putPart({ k: part.k, drive_id: sv.id, part: part.n, kind: 'rec', mime: part.mime, started_ms: part.started, offset_ms: 0, closed: true }); }
					if (sv.part === part) { sv.part = null; }
					stopLevel();
					// the phone took the microphone away (a call, the screen locked): start the next part when it can
					if (S.survey === sv && !sv.paused && !part.closing) { setTimeout(function () { if (!document.hidden) { recStart(); } }, 800); }
					paintPanel();
				};
				rec.onerror = function () { sv.micErr = 'The recording stopped; it starts again by itself.'; paintPanel(); };
				rec.start(5000); // a piece every 5 s, each kept on the phone as it comes
			}, function (e) {
				if (sv.part === part) { sv.part = null; }
				var denied = e && (e.name === 'NotAllowedError' || e.name === 'SecurityError');
				if (denied) { sv.micBlocked = true; sv.micErr = 'The microphone is blocked. Allow it for this site.'; paintPanel(); return; }
				// busy after a call, or not ready yet: try again, a little later each time
				sv.micTries = (sv.micTries || 0) + 1;
				sv.micErr = 'The microphone is busy (' + ((e && e.name) || 'error') + '); trying again…';
				paintPanel();
				setTimeout(function () { if (S.survey === sv && !sv.paused && !sv.part && !document.hidden) { recStart(); } }, Math.min(30000, 1500 * sv.micTries));
			});
		}
		function recStop() {
			var sv = S.survey, part = sv && sv.part; if (!part) { return Promise.resolve(); }
			part.closing = true;
			sv.part = null; // Resume while this one is still finishing starts the next part at once
			return new Promise(function (res) {
				var r = part.recorder;
				if (!r || r.state === 'inactive') { try { if (part.stream) { part.stream.getTracks().forEach(function (t) { t.stop(); }); } } catch (e) {} res(); return; }
				var old = r.onstop;
				r.onstop = function () { old && old(); setTimeout(res, 300); }; // the last piece is written first
				try { r.stop(); } catch (e) { res(); }
			});
		}
		document.addEventListener('visibilitychange', function () {
			var sv = S.survey;
			if (!sv || sv.mode !== 'record') { return; }
			if (document.hidden) { try { if (sv.part && sv.part.recorder && sv.part.recorder.state === 'recording') { sv.part.recorder.requestData(); } } catch (e) {} }
			else if (!sv.part && !sv.paused && !sv.micBlocked) { recStart(); }
		});
		/* the browser lets the screen lock go whenever the page is hidden; ask again on return */
		document.addEventListener('visibilitychange', function () {
			var sv = S.survey;
			if (!sv || document.hidden) { return; }
			try { if (navigator.wakeLock && navigator.wakeLock.request && (!sv.wake || sv.wake.released)) { navigator.wakeLock.request('screen').then(function (w) { if (S.survey === sv) { sv.wake = w; } }, function () {}); } } catch (e) {}
		});
		/* a dot that moves with the voice: the driver can see the phone is hearing them */
		var LV = null;
		function level(stream) {
			stopLevel();
			try {
				var AC = window.AudioContext || window.webkitAudioContext; if (!AC) { return; }
				var ac = S.ac || new AC(); S.ac = null;
				if (ac.state === 'suspended' && ac.resume) { ac.resume(); }
				var src = ac.createMediaStreamSource(stream), an = ac.createAnalyser(); an.fftSize = 512; src.connect(an);
				var buf = new Uint8Array(an.fftSize);
				LV = { ac: ac, t: setInterval(function () {
					an.getByteTimeDomainData(buf); var m = 0; for (var i = 0; i < buf.length; i++) { m = Math.max(m, Math.abs(buf[i] - 128)); }
					var d = root.querySelector('#zrp-state .zrp-dot'); if (d) { d.style.transform = 'scale(' + (1 + Math.min(1.2, m / 40)).toFixed(2) + ')'; }
				}, 150) };
			} catch (e) { LV = null; }
		}
		function stopLevel() { if (LV) { clearInterval(LV.t); try { LV.ac.close(); } catch (e) {} LV = null; } }

		/* ------------------------------------------------------------ upload (resumable, a part at a time) */
		function uploadPart(p, given) {
			return (given ? Promise.resolve(given) : piecesOf(p.k)).then(function (ps) {
				if (!ps.length) { return dropPart(p.k); }
				var seqs = ps.map(function (x) { return x.seq; });
				var blob = new Blob(ps.map(function (x) { return x.blob; }), { type: p.mime });
				return api('audio', { method: 'POST', body: { drive_id: p.drive_id, part: p.part, kind: p.kind, mime: p.mime, started_ms: p.started_ms, offset_ms: p.offset_ms || 0, bytes: blob.size } }).then(function (row) {
					function next(r) {
						if (r.status !== 'uploading') { return r; }
						if (r.got >= blob.size) { return api('audio/' + r.id + '/done', { method: 'POST' }); }
						var piece = blob.slice(r.got, Math.min(blob.size, r.got + CHUNK));
						paintUploads(p, r.got, blob.size);
						return api('audio/' + r.id + '/chunk?seq=' + r.chunks, { method: 'POST', raw: piece }).then(next, function (e) {
							if (e.status === 409) { return api('audio', { method: 'POST', body: { drive_id: p.drive_id, part: p.part, kind: p.kind, mime: p.mime, started_ms: p.started_ms, offset_ms: p.offset_ms || 0, bytes: blob.size } }).then(next); }
							throw e;
						});
					}
					return next(row);
				}).then(function (r) {
					upsertAudio(r);
					return given ? null : dropPart(p.k, seqs); // on the site, sealed: the pieces it was sent go
				}, function (e) {
					if (e.status && e.status >= 400 && e.status < 500 && e.status !== 409 && e.status !== 429 && e.status !== 401 && e.status !== 403) {
						toast('A recording was refused: ' + e.message); return given ? null : dropPart(p.k, seqs);
					}
					throw e;
				});
			});
		}
		/* one uploader across every tab of this person (navigator.locks); a part goes only once it is
		   closed (or abandoned: started over 6 hours ago and never closed, a tab that died); one stuck part does not hold
		   up the ones behind it */
		function uploadAll() {
			if (S.uploading) { return Promise.resolve(); }
			S.uploading = true;
			var run = function () {
				return allParts().then(function (parts) {
					var stale = Date.now() - 6 * 3600000;
					var todo = parts.filter(function (p) { return p.closed || (p.started_ms && p.started_ms < stale && p.kind === 'rec'); });
					var errs = [];
					var chain = Promise.resolve();
					todo.forEach(function (p) {
						chain = chain.then(function () { return uploadPart(p); }).then(null, function (e) {
							errs.push(e);
							p.tries = (p.tries || 0) + 1; p.lastErr = e.message;
							return putPart(p).then(null, function () {});
						});
					});
					return chain.then(function () { if (errs.length) { throw errs[0]; } });
				});
			};
			var locked = navigator.locks && navigator.locks.request ? navigator.locks.request('zrp-upload-' + (C.userId || 0), { ifAvailable: true }, function (lock) { return lock ? run() : null; }) : run();
			return Promise.resolve(locked).then(function () { S.uploading = false; S.upMsg = ''; refreshAudio(); if (S.tab === 'review' || S.tab === 'survey') { paintRecs(); } }, function (e) {
				S.uploading = false; S.upMsg = 'Recordings wait on this phone (' + e.message + '); they go when there is signal.'; paintRecs();
			});
		}
		setInterval(function () { if (!S.survey && navigator.onLine !== false) { uploadAll(); } }, 120000);
		function paintUploads(p, got, size) { S.upMsg = 'Sending the recording: ' + Math.round(100 * got / Math.max(1, size)) + '%'; paintRecs(); }
		function upsertAudio(r) {
			if (!r || !r.id) { return; }
			var i = S.audio.findIndex(function (a) { return a.id === r.id; });
			if (i === -1) { S.audio.unshift(r); } else { S.audio[i] = r; }
		}
		var audioTimer = null;
		function refreshAudio() {
			clearTimeout(audioTimer);
			if (document.hidden) { audioTimer = setTimeout(refreshAudio, 60000); return; }
			return api('audio').then(function (r) {
				var before = S.audio.filter(function (a) { return a.status === 'done'; }).length;
				S.audio = r.audio || [];
				var after = S.audio.filter(function (a) { return a.status === 'done'; }).length;
				if (after > before) { reloadPending(); }
				paintRecs();
				if (S.audio.some(function (a) { return ['ready', 'submitted', 'reading'].indexOf(a.status) !== -1; })) { audioTimer = setTimeout(refreshAudio, 60000); }
			}, function () { audioTimer = setTimeout(refreshAudio, 120000); });
		}
		function reloadPending() {
			api('boot').then(function (b) { S.pending = b.pending || []; S.retracted = b.retracted || []; S.drives = b.drives || []; paintBadge(); if (!S.survey || !moving()) { if (S.tab === 'review') { render(); } } });
		}

		/* ------------------------------------------------------------ a Voice Memos file: its start from the file itself */
		function mp4Created(file) {
			// the mp4 'mvhd' box: creation time in seconds since 1904 (Voice Memos and most phones write it, in UTC)
			return new Promise(function (res) {
				var pos = 0, guard = 0;
				function rd(a, b) { return (file.slice(a, b).arrayBuffer ? file.slice(a, b).arrayBuffer() : new Response(file.slice(a, b)).arrayBuffer()); }
				function step() {
					if (pos + 8 > file.size || guard++ > 64) { res(null); return; }
					rd(pos, pos + 16).then(function (b) {
						if (b.byteLength < 8) { res(null); return; }
						var d = new DataView(b), size = d.getUint32(0), type = String.fromCharCode(d.getUint8(4), d.getUint8(5), d.getUint8(6), d.getUint8(7)), hdr = 8;
						if (size === 1 && b.byteLength >= 16) { size = d.getUint32(8) * 4294967296 + d.getUint32(12); hdr = 16; } else if (size === 0) { size = file.size - pos; }
						if (type === 'moov') {
							return rd(pos + hdr, Math.min(pos + size, pos + hdr + 65536)).then(function (m) {
								try {
								var v = new DataView(m), q = 0;
								while (q + 8 <= m.byteLength) {
									var s = v.getUint32(q), t = String.fromCharCode(v.getUint8(q + 4), v.getUint8(q + 5), v.getUint8(q + 6), v.getUint8(q + 7));
									if (t === 'mvhd') {
										var ver = v.getUint8(q + 8), secs = ver === 1 ? v.getUint32(q + 12) * 4294967296 + v.getUint32(q + 16) : v.getUint32(q + 12);
										res(secs > 2082844800 ? (secs - 2082844800) * 1000 : null); return;
									}
									if (s < 8) { break; } q += s;
								}
								} catch (e) { /* a cut-off file */ }
								res(null);
							}, function () { res(null); });
						}
						if (size < 8) { res(null); return; }
						pos += size; step();
					}, function () { res(null); });
				}
				step();
			});
		}
		function durationOf(file) {
			return new Promise(function (res) {
				try {
					var a = document.createElement('audio'), url = URL.createObjectURL(file), done = false;
					var fin = function (v) { if (!done) { done = true; URL.revokeObjectURL(url); res(v); } };
					a.preload = 'metadata'; a.onloadedmetadata = function () { fin(isFinite(a.duration) ? a.duration : null); }; a.onerror = function () { fin(null); };
					setTimeout(function () { fin(null); }, 8000); a.src = url;
				} catch (e) { res(null); }
			});
		}
		function driveSpan(d) {
			var s = Date.parse(String(d.started_at).replace(' ', 'T') + 'Z'), e = d.ended_at ? Date.parse(String(d.ended_at).replace(' ', 'T') + 'Z') : s + 4 * 3600000;
			return [s, e];
		}
		function pickMemo(file, drive) {
			S.memo = { file: file, drive: drive, base: 0, nudge: 0, from: '', dur: null };
			Promise.all([mp4Created(file), durationOf(file)]).then(function (r) {
				var created = r[0], dur = r[1], span = driveSpan(drive), m = S.memo; if (!m || m.file !== file) { return; }
				m.dur = dur;
				var near = function (t) { return t && t > span[0] - 2 * 3600000 && t < span[1] + 2 * 3600000; };
				if (near(created)) { m.base = created; m.from = 'from the file'; }
				else if (dur && near(file.lastModified - dur * 1000)) { m.base = file.lastModified - Math.round(dur * 1000); m.from = 'from when the file was saved'; }
				else { m.base = span[0]; m.from = 'the drive\'s start (the file did not say)'; }
				render();
			});
			render();
		}
		function sendMemo() {
			var m = S.memo; if (!m || !m.base) { return; }
			// one stable part number per memo of a drive: the next one not yet used here or on the site
			allParts().then(function (parts) {
				var used = S.audio.filter(function (a) { return a.kind === 'memo' && a.drive_id === m.drive.id; }).map(function (a) { return a.part; })
					.concat(parts.filter(function (p) { return p.kind === 'memo' && p.drive_id === m.drive.id; }).map(function (p) { return p.part; }));
				var n = used.length ? Math.max.apply(null, used) + 1 : 0;
				var p = { k: m.drive.id + '-' + n + '-memo', drive_id: m.drive.id, part: n, kind: 'memo', mime: m.file.type || 'audio/mp4', started_ms: m.base, offset_ms: m.nudge, closed: true };
				S.memo = null; render();
				return putPart(p).then(function () { return putPiece(p.k, 0, m.file); }).then(function () { toast('Sending the voice memo…'); return uploadAll(); }, function () {
					// no room on the phone for a copy: straight from the file
					return uploadPart(p, [{ blob: m.file, seq: 0 }]).then(function () { refreshAudio(); }, function (e) { toast('The voice memo could not be sent: ' + e.message); });
				});
			});
		}

		/* ------------------------------------------------------------ the survey */
		/* Voice-only unless the car is KNOWN to be stopped: Doorhangers' rule says "walk", the last fix is
		   good (within its accuracy limit) and recent, and there have been 12 s of good fixes in a row. Loose GPS in a car,
		   the first seconds of a drive, GPS refused, or no rule at all: voice-only, the safe side. */
		function moving() {
			var sv = S.survey; if (!sv) { return false; }
			if (!sv.pace) { return true; }
			var L = sv.pace.limits || { accM: 15 }, g = sv.lastGood, now = Date.now();
			if (!g || !sv.last || sv.last.acc > L.accM || now - g.t > 15000 || g.t - sv.goodSince < 12000) { return true; }
			return sv.pace.state() === 'drive';
		}
		function startSurvey() {
			if (recall('zrp_safety_ok') !== '1') { S.safety = true; render(); return; }
			if (!navigator.geolocation) { toast('This phone gives no position, so notes cannot be placed.'); return; }
			// iOS starts an AudioContext only inside a tap: made here, used by the level dot once the microphone is on
			try { var AC = window.AudioContext || window.webkitAudioContext; if (AC && canRecord() && !S.ac) { S.ac = new AC(); } } catch (e) { S.ac = null; }
			api('drives', { method: 'POST' }).then(function (d) {
				var P = window.ZDH_PACE;
				S.survey = { id: d.id, mode: canRecord() ? 'record' : 'live', started: Date.now(), watch: null, rec: null, paused: false, speaking: false, micBlocked: false, micErr: '', last: null, prev: null, lastGood: null, goodSince: 0, speakTok: 0, fails: 0, lastErr: '',
					atSpeech: null, track: [], count: 0, lastId: null, heard: '', noted: '', wake: null, part: null, parts: 0,
					pace: P && typeof P.detector === 'function' ? P.detector(C.pace || {}) : null, wasMoving: null, timer: null };
				S.survey.noted = S.survey.mode === 'record' ? 'Say what you see: “every house on this street has a pool”. It is read after the drive.' : 'Say what you see: “every house on this street has a pool”.';
				S.tab = 'survey';
				S.survey.watch = navigator.geolocation.watchPosition(onFix, function (e) { panelSay(null, 'GPS: ' + (e && e.message ? e.message : 'no position')); }, { enableHighAccuracy: true, maximumAge: 2000, timeout: 30000 });
				S.survey.timer = setInterval(function () { sendTrack(); paintPanel(); }, 60000);
				try { if (navigator.wakeLock && navigator.wakeLock.request) { navigator.wakeLock.request('screen').then(function (w) { if (S.survey) { S.survey.wake = w; } }, function () {}); } } catch (e) {}
				render();
				if (S.survey.mode === 'record') { recStart(); } else { listen(); }
			}, function (e) { toast('The survey could not start: ' + e.message); });
		}
		function pauseSurvey(on) {
			var sv = S.survey; if (!sv) { return; }
			sv.paused = !!on;
			if (sv.mode === 'record') { if (on) { recStop(); } else { sv.micBlocked = false; recStart(); } }
			else if (!on && sv.micStopped) { sv.micStopped = false; sv.fails = 0; listen(); }
			paintPanel();
		}
		/* every minute while driving: the points so far (put back if they did not go) */
		function sendTrack() {
			var sv = S.survey; if (!sv || !sv.track.length) { return; }
			var id = sv.id, pts = sv.track.splice(0);
			api('drives/' + id, { method: 'POST', body: { track: pts } }).then(null, function () { if (S.survey && S.survey.id === id) { S.survey.track = pts.concat(S.survey.track); } });
		}
		function endSurvey() {
			var sv = S.survey; if (!sv) { return; }
			var stopped = sv.mode === 'record' ? recStop() : Promise.resolve();
			var wasMoving = moving();
			S.survey = null;
			S.ended = wasMoving; // ended while moving -- no Review controls until the driver says they have stopped
			try { if (sv.rec) { sv.rec.onend = null; sv.rec.abort(); } } catch (e) {}
			try { navigator.geolocation.clearWatch(sv.watch); } catch (e) {}
			clearInterval(sv.timer);
			try { if (sv.wake) { sv.wake.release(); } } catch (e) {}
			stopLevel();
			var pts = sv.track.splice(0);
			api('drives/' + sv.id, { method: 'POST', body: { track: pts, end: true } }).then(function (r) {
				toast('Survey ended: ' + ((r.distance_m || 0) / 1609.34).toFixed(1) + ' mi' + (sv.mode === 'record' ? '. Sending the recording to be read…' : ', ' + (r.note_count || 0) + ' notes.'));
				var have = S.drives.some(function (d) { return d.id === sv.id; });
				if (!have) { S.drives.unshift({ id: sv.id, started_at: new Date(sv.started).toISOString().slice(0, 19).replace('T', ' '), ended_at: new Date().toISOString().slice(0, 19).replace('T', ' '), note_count: r.note_count, distance_m: r.distance_m }); }
			}, function () { toast('Survey ended. The last stretch of the drive could not be saved.'); }).then(function () { return stopped; }).then(function () { if (sv.mode === 'record') { uploadAll(); } });
			flushQueue();
			S.tab = wasMoving ? 'survey' : (S.pending.length || sv.mode === 'record' ? 'review' : 'survey');
			try { if (window.speechSynthesis) { window.speechSynthesis.cancel(); } } catch (e) {}
			render();
		}
		function onFix(pos) {
			var sv = S.survey; if (!sv || !pos || !pos.coords) { return; }
			var c = pos.coords, t = pos.timestamp || Date.now();
			var fix = { lat: c.latitude, lng: c.longitude, acc: c.accuracy || 0, speed: (c.speed != null && isFinite(c.speed) && c.speed >= 0) ? c.speed : null, heading: null, t: t };
			// heading: the phone's own while moving, else the bearing of the last 15 m or more
			if (c.heading != null && isFinite(c.heading) && fix.speed != null && fix.speed > 1) { fix.heading = c.heading; }
			else if (sv.prev && distM([sv.prev.lat, sv.prev.lng], [fix.lat, fix.lng]) >= 15) { fix.heading = bearing([sv.prev.lat, sv.prev.lng], [fix.lat, fix.lng]); }
			else if (sv.last) { fix.heading = sv.last.heading; }
			if (!sv.prev || distM([sv.prev.lat, sv.prev.lng], [fix.lat, fix.lng]) >= 15) { sv.prev = fix; }
			sv.last = fix;
			var accL = (sv.pace && sv.pace.limits && sv.pace.limits.accM) || 15;
			if (fix.acc <= accL) { if (!sv.lastGood || t - sv.lastGood.t > 45000) { sv.goodSince = t; } sv.lastGood = fix; } else { sv.goodSince = t; } // a loose fix breaks the run
			if (fix.acc <= 50) { sv.track.push([fix.lat, fix.lng, Math.round(t / 1000)]); }
			if (sv.pace) { sv.pace.update(fix.lat, fix.lng, t, fix.acc, fix.speed); }
			var mv = moving();
			if (mv && S.tab !== 'survey') { S.tab = 'survey'; sv.wasMoving = mv; render(); return; } // any tab, back to voice-only
			if (mv !== sv.wasMoving) { sv.wasMoving = mv; render(); }
			else { paintPanel(); }
		}

		/* ------------------------------------------------------------ listening (live mode) */
		function listen() {
			var sv = S.survey; if (!sv || sv.mode !== 'live' || !SR || sv.micBlocked || sv.micStopped || sv.speaking || document.hidden) { return; }
			if (sv.rec && sv.recOn) { return; }
			var rec = sv.rec || new SR();
			if (!sv.rec) {
				rec.lang = 'en-US'; rec.continuous = true; rec.interimResults = true; rec.maxAlternatives = 1;
				rec.onstart = function () { sv.recOn = true; paintPanel(); };
				/* the position for a sentence: the first sign of it (speech start, or its first partial words); a sentence
				   that was a command or said while paused clears it too (Chrome sends speechstart once a
				   session, so a stale position was carried to the next note) */
				var mark = function () { if (sv.last && !sv.atSpeech) { sv.atSpeech = { lat: sv.last.lat, lng: sv.last.lng, acc: sv.last.acc, speed: sv.last.speed, heading: sv.last.heading, t: Date.now() }; } };
				rec.onspeechstart = mark;
				rec.onresult = function (ev) {
					sv.fails = 0; sv.lastErr = '';
					for (var i = ev.resultIndex; i < ev.results.length; i++) {
						var r = ev.results[i], text = (r[0] && r[0].transcript || '').trim();
						if (!text) { continue; }
						mark();
						if (r.isFinal) { heardFinal(text); } else { panelSay(heardLine(text, true), null); }
					}
				};
				rec.onerror = function (e) {
					sv.lastErr = (e && e.error) || 'error';
					if (sv.lastErr === 'not-allowed' || sv.lastErr === 'service-not-allowed') {
						sv.micBlocked = true; panelSay('', 'The microphone is blocked. Allow it for this site, or type notes when parked.'); paintPanel();
					}
				};
				/* iOS and Chrome end recognition on their own: carry on, but back off on errors (a "network"
				   or "audio-capture" error restarted every 300 ms forever); after 8 failures in a row, stop and say so */
				rec.onend = function () {
					sv.recOn = false;
					if (S.survey !== sv || sv.speaking || sv.micBlocked) { paintPanel(); return; }
					var benign = !sv.lastErr || sv.lastErr === 'no-speech' || sv.lastErr === 'aborted';
					sv.fails = benign ? 0 : sv.fails + 1;
					if (sv.fails >= 8) { sv.micStopped = true; panelSay('', sv.lastErr === 'network' ? 'Listening needs signal. Tap Resume to try again.' : 'The microphone keeps stopping (' + sv.lastErr + '). Tap Resume to try again.'); paintPanel(); return; }
					paintPanel();
					setTimeout(listen, benign ? 300 : Math.min(30000, 300 * Math.pow(2, sv.fails)));
					sv.lastErr = '';
				};
				sv.rec = rec;
			}
			try { rec.start(); } catch (e) { /* already started */ }
		}
		document.addEventListener('visibilitychange', function () { if (!document.hidden) { listen(); } });
		function heardFinal(text) {
			var sv = S.survey; if (!sv) { return; }
			var c = command(text);
			if (c) { sv.atSpeech = null; panelSay(heardLine(text), null); runCommand(c); return; }
			if (sv.paused) { sv.atSpeech = null; panelSay(heardLine(text), 'Paused: say “resume” to note again.'); return; }
			addNote(text, false);
		}
		function addNote(text, typed) {
			var sv = S.survey, at = (sv && sv.atSpeech && !typed) ? sv.atSpeech : (sv && sv.last) || null;
			if (sv) { sv.atSpeech = null; }
			if (!at) { panelSay(heardLine(text), 'No GPS position yet: say it again in a moment.'); speak('No position yet. Say it again.'); return; }
			if (queue().length >= QMAX) { panelSay(heardLine(text), 'This phone is holding ' + QMAX + ' notes waiting for signal: not noted.'); speak('Not noted. The phone is full.'); return; }
			var item = { client_id: cid(), drive_id: sv ? sv.id : 0, lat: at.lat, lng: at.lng, acc: Math.round(at.acc || 0), speed: at.speed, heading: at.heading, said: text, said_at_ms: at.t || Date.now(), typed: typed ? 1 : 0 };
			var q = queue(); q.push(item); saveQueue(q);
			panelSay(heardLine(text), 'Noting…'); // a code is not shown on the screen
			flushQueue();
		}

		/* ------------------------------------------------------------ painting */
		function badge() { var n = S.pending.length + queue().length; return n ? '<span class="zrp-badge" aria-label="' + n + ' to review">' + n + '</span>' : ''; }
		function paintBadge() { var b = root.querySelector('[data-tab="review"]'); if (b) { b.innerHTML = 'Review' + badge(); } }
		function panelSay(heard, noted) {
			var sv = S.survey; if (!sv) { return; }
			if (heard !== null && heard !== undefined) { sv.heard = heard; }
			if (noted !== null && noted !== undefined) { sv.noted = noted; }
			paintPanel();
		}
		function paintPanel() {
			var sv = S.survey; if (!sv) { return; }
			var st = root.querySelector('#zrp-state'), he = root.querySelector('#zrp-heard'), no = root.querySelector('#zrp-noted'), me = root.querySelector('#zrp-meta'), pb = root.querySelector('#zrp-pause');
			if (!st) { return; }
			var secs = Math.round((Date.now() - sv.started) / 1000), q = queue().length, label, live;
			if (sv.mode === 'record') {
				live = !!(sv.part && sv.part.started && !sv.paused);
				label = sv.micBlocked ? 'Microphone blocked' : sv.paused ? 'Paused (not recording)' : live ? 'Recording' : 'Starting the microphone…';
				he.textContent = sv.micErr || (live ? 'Speak your notes as you drive.' : '');
			} else {
				live = SR && sv.recOn && !sv.paused && !sv.micBlocked;
				label = sv.micBlocked ? 'Microphone blocked' : sv.micStopped ? 'Not listening' : !SR ? 'This browser cannot listen' : sv.paused ? 'Paused' : sv.speaking ? 'Reading back…' : live ? 'Listening' : 'Starting the microphone…';
				he.textContent = sv.heard || '';
			}
			st.innerHTML = '<span class="zrp-dot' + (live ? ' is-live' : '') + '" aria-hidden="true"></span><span>' + esc(label) + '</span>';
			no.textContent = sv.noted || '';
			me.textContent = (sv.mode === 'record' ? 'Part ' + Math.max(1, sv.parts) : sv.count + ' note' + (sv.count === 1 ? '' : 's')) + ' · ' + Math.floor(secs / 60) + ' min' + (sv.last ? ' · GPS ±' + Math.round(sv.last.acc) + ' m' : ' · waiting for GPS') + (q ? ' · ' + q + ' waiting for signal' : '');
			var off = sv.paused || sv.micStopped;
			if (pb) { pb.textContent = off ? 'Resume' : 'Pause'; pb.classList.toggle('is-on', !!off); pb.setAttribute('aria-pressed', off ? 'true' : 'false'); }
		}
		function render() {
			var tabs = (S.planCfg ? [['plan', 'Plan']] : []).concat([['survey', 'Survey'], ['review', 'Review' + badge()], ['map', 'Map']]);
			if (S.codesReady) { tabs.push(['codes', 'Codes']); }
			root.innerHTML = '<div class="zrp-head"><h2 class="zrp-title">Route Planner</h2><p class="zrp-sub">' + (S.planCfg ? 'Plan door-hanger routes, or drive a neighborhood and say what you see.' : 'Drive a neighborhood and say what you see.') + '</p>' +
				'<div class="zrp-tabs" role="tablist">' + tabs.map(function (t) { return '<button type="button" class="zrp-tab" role="tab" data-tab="' + t[0] + '" aria-selected="' + (S.tab === t[0]) + '">' + t[1] + '</button>'; }).join('') + '</div></div>' +
				'<div class="zrp-main" id="zrp-main"></div><div class="zrp-sr" aria-live="polite" id="zrp-live"></div>';
			root.querySelectorAll('.zrp-tab').forEach(function (b) { b.addEventListener('click', function () { if (S.ended && b.getAttribute('data-tab') !== 'survey') { toast('Tap “I have pulled over” first.'); return; } if (S.survey && moving() && b.getAttribute('data-tab') !== 'survey') { toast('Voice only while driving. Pull over to use the other tabs.'); return; } S.tab = b.getAttribute('data-tab'); render(); }); });
			if (S.tab !== 'map' && S.map) { try { S.map.remove(); } catch (e) {} S.map = null; }
			if (S.pmap) { try { S.pmap.remove(); } catch (e) {} S.pmap = null; if (S.plan) { S.plan.layers = null; } } // the Plan tab builds its map again
			var main = root.querySelector('#zrp-main');
			if (!S.booted) { main.innerHTML = S.err ? '<div class="zrp-card"><p>' + esc(S.err) + '</p></div>' : '<div class="zrp-card"><p class="zrp-help">Loading…</p></div>'; return; }
			if (S.tab === 'plan' && S.planCfg) { renderPlan(main); }
			else if (S.tab === 'review') { renderReview(main); }
			else if (S.tab === 'map') { renderMap(main); }
			else if (S.tab === 'codes') { renderCodes(main); }
			else { renderSurvey(main); }
		}
		function recsHtml() {
			var mine = S.audio.slice(0, 8);
			if (!mine.length && !S.upMsg && !S.uploading) { return '<div id="zrp-recs"></div>'; }
			return '<div class="zrp-card" id="zrp-recs"><h2>Recordings</h2>' + (S.uploading && !S.upMsg ? '<p class="zrp-help">Sending…</p>' : '') + (S.upMsg ? '<p class="zrp-help">' + esc(S.upMsg) + '</p>' : '') +
				(S.reads ? '' : '<p class="zrp-help">Reading recordings needs a transcription service connected to the site; they wait on the site until then.</p>') +
				mine.map(function (a) {
					return '<p class="zrp-rec" data-id="' + (+a.id) + '"><strong>' + esc(a.kind === 'memo' ? 'Voice memo' : 'Drive recording, part ' + ((+a.part) + 1)) + '</strong> · ' + esc(String(a.at).slice(0, 16)) + ' UTC · ' + esc(a.says) +
						(a.status === 'done' ? ' · ' + (+a.notes) + ' note' + (a.notes === 1 ? '' : 's') + (a.how === 'rules' ? ' (read by rules: the reader\'s model was not available)' : '') : '') +
						(a.error && a.status !== 'done' ? '<br><small>' + esc(a.error) + '</small>' : '') +
						(a.status === 'failed' ? ' <button type="button" class="zrp-chip" data-retry="' + (+a.id) + '">Try again</button>' : '') + '</p>';
				}).join('') + '</div>';
		}
		function paintRecs() {
			var el = root.querySelector('#zrp-recs');
			if (!el || (S.survey && moving())) { return; }
			var tmp = document.createElement('div'); tmp.innerHTML = recsHtml();
			if (tmp.firstChild) { el.replaceWith(tmp.firstChild); wireRecs(); }
		}
		function wireRecs() {
			root.querySelectorAll('[data-retry]').forEach(function (b) { b.addEventListener('click', function () { b.disabled = true; api('audio/' + b.getAttribute('data-retry'), { method: 'POST', body: { op: 'retry' } }).then(function (r) { upsertAudio(r); refreshAudio(); }, function (e) { b.disabled = false; toast(e.message); }); }); });
		}
		function memoHtml() {
			var m = S.memo;
			var drives = S.drives.filter(function (d) { return d.status !== 'running'; }).slice(0, 8);
			if (!m) {
				if (!drives.length) { return ''; }
				return '<div class="zrp-card"><h2>Add a voice memo</h2><p class="zrp-help">Recorded the drive with Voice Memos instead? Pick the drive, then the file: it is placed on the drive by time.</p>' +
					'<div class="zrp-row"><label class="zrp-sr" for="zrp-memo-drive">Drive</label><select class="zrp-scope" id="zrp-memo-drive">' + drives.map(function (d) { return '<option value="' + (+d.id) + '">' + esc(String(d.started_at).slice(0, 16)) + ' UTC · ' + ((+d.distance_m || 0) / 1609.34).toFixed(1) + ' mi</option>'; }).join('') + '</select>' +
					'<label class="zrp-btn zrp-btn-quiet" for="zrp-memo-file">Choose the file</label><input class="zrp-sr" id="zrp-memo-file" type="file" accept="audio/*,.m4a,.mp3,.wav,.aac,.caf"></div></div>';
			}
			var span = driveSpan(m.drive);
			return '<div class="zrp-card"><h2>Voice memo</h2><p>' + esc(m.file.name) + (m.dur ? ' · ' + mmss(m.dur) : '') + '</p>' +
				(m.base ? '<p>Starts at <strong>' + esc(clock(m.base + m.nudge)) + '</strong> <small>(' + esc(m.from) + (m.nudge ? ', moved ' + (m.nudge > 0 ? '+' : '−') + mmss(Math.abs(m.nudge) / 1000) : '') + ')</small>. The drive began ' + esc(clock(span[0])) + '.</p>' +
					'<p class="zrp-help">Check the start before sending: it decides which street each sentence lands on.</p>' +
					'<div class="zrp-row">' + [[-60000, '−1 min'], [-10000, '−10 s'], [10000, '+10 s'], [60000, '+1 min']].map(function (x) { return '<button type="button" class="zrp-chip" data-nudge="' + x[0] + '">' + x[1] + '</button>'; }).join('') +
					'<button type="button" class="zrp-chip" data-nudge="start">Drive\'s start</button></div>' +
					'<div class="zrp-row" style="margin-top:.6rem"><button type="button" class="zrp-btn zrp-btn-primary" id="zrp-memo-send">Send to be read</button><button type="button" class="zrp-btn zrp-btn-quiet" id="zrp-memo-cancel">Cancel</button></div>'
					: '<p class="zrp-help">Reading the file…</p>') + '</div>';
		}
		function wireMemo(main) {
			var f = main.querySelector('#zrp-memo-file');
			if (f) { f.addEventListener('change', function () { var file = f.files && f.files[0], id = +main.querySelector('#zrp-memo-drive').value, d = S.drives.filter(function (x) { return x.id === id; })[0]; if (file && d) { pickMemo(file, d); } }); }
			main.querySelectorAll('[data-nudge]').forEach(function (b) { b.addEventListener('click', function () { var v = b.getAttribute('data-nudge'), m = S.memo; if (!m) { return; } if (v === 'start') { m.nudge = driveSpan(m.drive)[0] - m.base; } else { m.nudge += +v; } render(); }); });
			var s = main.querySelector('#zrp-memo-send'); if (s) { s.addEventListener('click', sendMemo); }
			var c = main.querySelector('#zrp-memo-cancel'); if (c) { c.addEventListener('click', function () { S.memo = null; render(); }); }
		}
		function renderSurvey(main) {
			var sv = S.survey;
			var rec = canRecord();
			if (S.safety && !sv) {
				main.innerHTML = '<div class="zrp-card zrp-safety" role="alertdialog" aria-labelledby="zrp-safety-h"><h2 id="zrp-safety-h">Before you drive</h2>' +
					'<p><strong>Only speak while the car moves. Never type or tap</strong> (the two big buttons are the only ones). The screen goes voice-only when you are moving.</p><ul>' +
					'<li>Say what you see: “every house on this street has a pool”, “this side is gated”, “this house has a no soliciting sign”.</li>' +
					(rec ? '<li>The phone records the drive like a voice memo; it is read after the drive. Say “scratch that” to take back what you just said.</li>'
						: '<li>Say “scratch that” to take back the last note, “keep that” to confirm it, “pause listening”, “resume”, “end survey”.</li>') +
					'<li>A gate code: “the gate code is 1 2 3 4”. It is sealed at once and never shown on a note.</li>' +
					'<li>Describe homes, never people: no names, plates or faces.</li></ul>' +
					'<div class="zrp-row"><button type="button" class="zrp-btn zrp-btn-primary" id="zrp-safety-ok">I understand — start</button><button type="button" class="zrp-btn zrp-btn-quiet" id="zrp-safety-no">Not now</button></div></div>';
				main.querySelector('#zrp-safety-ok').addEventListener('click', function () { store('zrp_safety_ok', '1'); S.safety = false; startSurvey(); });
				main.querySelector('#zrp-safety-no').addEventListener('click', function () { S.safety = false; render(); });
				return;
			}
			if (!sv && S.ended) {
				main.innerHTML = '<div class="zrp-drive zdz-pair" role="region" aria-label="Drive ended"><div class="zrp-drive-state"><span>Drive ended</span></div>' +
					'<p class="zrp-noted">' + (S.upMsg ? esc(S.upMsg) : 'The recording is being sent to be read.') + ' Review your notes once you have pulled over.</p>' +
					'<div class="zrp-drive-btns" style="grid-template-columns:1fr"><button type="button" class="zrp-big" id="zrp-parked">I have pulled over</button></div></div>';
				main.querySelector('#zrp-parked').addEventListener('click', function () { S.ended = false; S.tab = 'review'; render(); });
				return;
			}
			if (!sv) {
				main.innerHTML = '<div class="zrp-card"><h2>Survey drive</h2><p>Drive through a neighborhood and say what the homes have: the features your survey listens for (Zorderz → Route Planner), a gated HOA, no-soliciting signs. Each note lands on the street you said it on, ready for planning door-hanger routes.</p>' +
					(rec ? '<p class="zrp-help">The phone records the drive; the transcription service reads it afterwards and places every sentence on the street you were on.</p>'
						: (SR ? '' : '<p class="zrp-help"><strong>This browser cannot listen</strong> (Safari on an iPhone or Chrome can). You can still type notes when parked.</p>')) +
					(S.snap ? '' : '<p class="zrp-help">Streets come from Doorhangers. Until it is on, notes are kept where they were said and placed on their street later.</p>') +
					'<button type="button" class="zrp-btn zrp-btn-primary zrp-btn-start" id="zrp-start">Start survey drive</button></div>' +
					recsHtml() + memoHtml() +
					(S.drives.length ? '<div class="zrp-card"><h2>Recent drives</h2>' + S.drives.slice(0, 5).map(function (d) { return '<p class="zrp-help">' + esc(String(d.started_at).slice(0, 16).replace('T', ' ')) + ' UTC · ' + (+d.note_count || 0) + ' notes · ' + ((+d.distance_m || 0) / 1609.34).toFixed(1) + ' mi</p>'; }).join('') + '</div>' : '');
				main.querySelector('#zrp-start').addEventListener('click', startSurvey);
				wireRecs(); wireMemo(main);
				return;
			}
			var mv = moving();
			main.innerHTML = '<div class="zrp-drive zdz-pair" role="region" aria-label="Survey drive">' +
				'<div class="zrp-drive-state" id="zrp-state"></div>' +
				'<p class="zrp-heard" id="zrp-heard" aria-live="polite"></p><p class="zrp-noted" id="zrp-noted" aria-live="polite"></p><p class="zrp-drive-meta" id="zrp-meta"></p>' +
				'<div class="zrp-drive-btns"><button type="button" class="zrp-big" id="zrp-pause" aria-pressed="false">Pause</button><button type="button" class="zrp-big" id="zrp-end">End survey</button></div>' +
				(mv ? '<p class="zrp-voice-only">Moving — voice only. Just speak.</p>' : '<p class="zrp-says">' + (sv.mode === 'record' ? 'Say “scratch that” to take back what you just said. Pause stops the recording.' : 'Say “scratch that”, “keep that”, “pause listening”, “resume” or “end survey”.') + '</p>') + '</div>' +
				(mv || sv.mode === 'record' ? '' : '<div class="zrp-card"><h2>Parked</h2><p class="zrp-help">You can type a note while the car is stopped.</p><form class="zrp-row" id="zrp-typeform"><input class="zrp-type" id="zrp-type" type="text" maxlength="300" placeholder="e.g. this side has turf" aria-label="Type a note"><button class="zrp-btn" type="submit">Note it</button></form></div>');
			main.querySelector('#zrp-pause').addEventListener('click', function () { var v = S.survey; if (!v) { return; } if (v.mode === 'record') { pauseSurvey(!v.paused); } else { runCommand(v.paused || v.micStopped ? 'resume' : 'pause'); } });
			main.querySelector('#zrp-end').addEventListener('click', endSurvey);
			var tf = main.querySelector('#zrp-typeform');
			if (tf) { tf.addEventListener('submit', function (e) { e.preventDefault(); if (moving()) { toast('Voice only while driving.'); return; } var i = main.querySelector('#zrp-type'), v = i.value.trim(); if (v) { addNote(v, true); i.value = ''; } }); }
			paintPanel();
		}
		function renderReview(main) {
			if (S.survey && moving()) { main.innerHTML = '<div class="zrp-pull">Pull over to review your notes. While the car moves the Route Planner only listens.</div>'; return; }
			var q = queue();
			var html = '<div class="zrp-card"><h2>Review</h2><p class="zrp-help">Tags count only once you confirm them. Tap a tag to keep or drop it.</p></div>';
			html += recsHtml();
			if (q.length) { html += '<div class="zrp-card"><p>' + q.length + ' note' + (q.length === 1 ? '' : 's') + ' waiting on this phone for signal.</p><button type="button" class="zrp-btn" id="zrp-flush">Send now</button></div>'; }
			if (!S.pending.length) { html += '<p class="zrp-empty">Nothing to review.</p>'; }
			html += S.pending.map(function (n) {
				var chosen = n._pick || n.suggested || [];
				var chips = S.vocab.filter(function (v) { return chosen.indexOf(v.slug) !== -1 || (n._more); }).map(function (v) {
					return '<button type="button" class="zrp-chip" data-tag="' + esc(v.slug) + '" aria-pressed="' + (chosen.indexOf(v.slug) !== -1) + '">' + esc(v.label) + (v.product_label ? ' <small>' + esc(v.product_label) + '</small>' : '') + '</button>';
				}).join('');
				// a tag flagged "ask" leaves the kind open: an unsaid one asks
				// a tag flagged "ask" names its own choices (the vocabulary row's choice slugs): the person says which
				var askTag = chosen.filter(function (t) { return hasFlag(t, 'ask'); })[0], askV = askTag ? vocabOf(askTag) : null;
				var askChoices = askV && askV.choices ? askV.choices.filter(function (c) { return !!vocabOf(c); }) : [];
				var ask = askChoices.length ? '<div class="zrp-ask" role="group" aria-label="Which kind?"><span>Which kind?</span> ' + askChoices.map(function (c) { return '<button type="button" class="zrp-chip" data-swap="' + esc(c) + '">' + esc(vocabOf(c).label) + '</button>'; }).join(' ') + '</div>' : '';
				var gated = chosen.filter(function (t) { return hasFlag(t, 'code'); }).length && S.codesReady && !/code sealed/.test(n.said) ? '<form class="zrp-row zrp-codeform" autocomplete="off"><label class="zrp-sr" for="zrp-code-' + (+n.id) + '">Gate code</label><input class="zrp-type zrp-secret" id="zrp-code-' + (+n.id) + '" type="text" inputmode="text" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" maxlength="20" placeholder="Gate code, if you know it"><button type="submit" class="zrp-btn zrp-btn-quiet">Seal it</button></form>' : '';
				var scopes = SCOPES.map(function (s) { return '<option value="' + s + '"' + (n.scope === s ? ' selected' : '') + '>' + esc(SCOPE_WORDS[s]) + '</option>'; }).join('');
				if (SCOPES.indexOf(n.scope) === -1) { scopes = '<option value="' + esc(n.scope) + '" selected>' + esc(SCOPE_WORDS[n.scope] || n.scope) + '</option>' + scopes; }
				return '<div class="zrp-note" data-id="' + n.id + '"><p class="zrp-note-said">“' + esc(n.said) + '”' + (n.source === 'audio' ? ' <small class="zrp-src">from the recording</small>' : '') + '</p>' +
					'<p class="zrp-note-where' + (n.snapped ? '' : ' is-unknown') + '">' + (n.snapped ? esc(n.street) + (n.house ? ' · ' + esc(n.house) : '') + (n.zip ? ' · ' + esc(n.zip) : '') : 'Street not known yet') + ' · ' + esc(String(n.at).slice(0, 16)) + ' UTC</p>' +
					'<div class="zrp-chips">' + (chips || '<span class="zrp-help">No tag heard.</span>') + (n._more ? '' : ' <button type="button" class="zrp-chip" data-more="1">+ Tag</button>') + '</div>' + ask + gated +
					'<div class="zrp-row"><label class="zrp-sr" for="zrp-sc-' + n.id + '">Scope</label><select class="zrp-scope" id="zrp-sc-' + n.id + '">' + scopes + '</select>' +
					'<button type="button" class="zrp-btn zrp-btn-primary" data-act="confirm">Confirm</button><button type="button" class="zrp-btn zrp-btn-quiet" data-act="discard">Discard</button></div></div>';
			}).join('');
			if (S.retracted.length) {
				html += '<div class="zrp-card"><button type="button" class="zrp-btn zrp-btn-quiet" id="zrp-taken" aria-expanded="' + S.showTaken + '">Taken back (' + S.retracted.length + ')</button>' +
					(S.showTaken ? '<p class="zrp-help">Words a “scratch that” took back. Undo puts one back on the list; they go by themselves after 30 days.</p>' + S.retracted.map(function (n) {
						return '<p class="zrp-taken" data-id="' + n.id + '">“' + esc(n.said) + '”' + (n.why ? ' <small>' + esc(n.why) + '</small>' : '') + ' <button type="button" class="zrp-chip" data-undo="' + n.id + '">Undo</button></p>';
					}).join('') : '') + '</div>';
			}
			main.innerHTML = html;
			wireRecs();
			var fl = main.querySelector('#zrp-flush'); if (fl) { fl.addEventListener('click', function () { flushQueue().then(function () { render(); }); }); }
			var tk = main.querySelector('#zrp-taken'); if (tk) { tk.addEventListener('click', function () { S.showTaken = !S.showTaken; render(); }); }
			main.querySelectorAll('[data-undo]').forEach(function (b) {
				b.addEventListener('click', function () {
					var id = +b.getAttribute('data-undo'); b.disabled = true;
					api('notes/' + id, { method: 'POST', body: { op: 'restore' } }).then(function (n) { S.retracted = S.retracted.filter(function (x) { return x.id !== id; }); S.pending.unshift(n); toast('Put back.'); render(); }, function (e) { b.disabled = false; toast(e.message); });
				});
			});
			main.querySelectorAll('.zrp-note').forEach(function (el) {
				var id = +el.getAttribute('data-id'), n = S.pending.filter(function (p) { return p.id === id; })[0];
				if (!n) { return; }
				el.querySelectorAll('.zrp-chip[data-tag], .zrp-chip[data-more]').forEach(function (b) {
					b.addEventListener('click', function () {
						if (b.getAttribute('data-more')) { n._more = true; n._pick = (n._pick || (n.suggested || []).slice()); render(); return; }
						var t = b.getAttribute('data-tag'), pick = n._pick || (n.suggested || []).slice(), i = pick.indexOf(t);
						if (i === -1) { pick.push(t); } else { pick.splice(i, 1); }
						n._pick = pick; b.setAttribute('aria-pressed', i === -1 ? 'true' : 'false');
						if (hasFlag(t, 'ask') || hasFlag(t, 'code')) { render(); }
					});
				});
				el.querySelectorAll('[data-swap]').forEach(function (b) {
					b.addEventListener('click', function () {
						var pick = (n._pick || (n.suggested || []).slice()).filter(function (t) { return !hasFlag(t, 'ask'); });
						if (pick.indexOf(b.getAttribute('data-swap')) === -1) { pick.push(b.getAttribute('data-swap')); }
						n._pick = pick; render();
					});
				});
				var cf = el.querySelector('.zrp-codeform');
				if (cf) {
					cf.addEventListener('submit', function (e) {
						e.preventDefault(); var inp = cf.querySelector('input'), v = inp.value.trim(); if (!v) { return; }
						inp.value = ''; // never kept on the page
						api('codes', { method: 'POST', body: { code: v, kind: 'gate', note_id: n.id, label: n.street || '' } }).then(function (c) { S.codes.unshift(c); n.said = n.said + ' [gate code sealed]'; toast('Sealed. It opens for the administrators and for whoever walks a route there that day.'); render(); }, function (er) { toast('Not sealed: ' + er.message); });
					});
				}
				el.querySelectorAll('[data-act]').forEach(function (b) {
					b.addEventListener('click', function () {
						var act = b.getAttribute('data-act'), body = { op: act, scope: el.querySelector('.zrp-scope').value };
						if (act === 'confirm') { body.tags = n._pick || n.suggested || []; }
						b.disabled = true;
						api('notes/' + id, { method: 'POST', body: body }).then(function (r) {
							S.pending = S.pending.filter(function (p) { return p.id !== id; });
							toast(act === 'confirm' ? 'Kept: ' + describe(r) : 'Discarded.');
							render();
						}, function (e) { b.disabled = false; toast('Not saved: ' + e.message); });
					});
				});
			});
		}
		function renderCodes(main) {
			if (S.survey && moving()) { main.innerHTML = '<div class="zrp-pull">Pull over to see gate codes.</div>'; return; }
			main.innerHTML = '<div class="zrp-card"><h2>Gate codes</h2><p class="zrp-help">Codes said on a drive or typed on Review are sealed on the site. One opens for the administrators, and for whoever is walking a Doorhangers route through that street that day. Every opening is written down. A code is never printed on an invoice, an estimate or the website.</p></div>' +
				'<div id="zrp-codes"><p class="zrp-help">Loading…</p></div>';
			api('codes').then(function (r) {
				S.codes = r.codes || [];
				var box = main.querySelector('#zrp-codes'); if (!box) { return; }
				box.innerHTML = S.codes.length ? S.codes.map(function (c) {
					return '<div class="zrp-note" data-id="' + (+c.id) + '"><p class="zrp-note-said">' + esc(c.label || c.street || 'Gate') + ' <small>' + esc(c.kind) + ' code</small></p>' +
						'<p class="zrp-note-where">' + esc(c.street || 'Street not known') + (c.zip ? ' · ' + esc(c.zip) : '') + ' · added ' + esc(c.added) + (c.by ? ' by ' + esc(c.by) : '') + (c.opened != null ? ' · opened ' + (+c.opened) + '×' : '') + '</p>' +
						'<p class="zrp-code-shown" aria-live="polite"></p><div class="zrp-row">' + (c.may_open ? '<button type="button" class="zrp-btn" data-reveal="' + (+c.id) + '">Show code</button>' : '<span class="zrp-help">Opens on the day you walk a route here.</span>') +
						(C.isAdmin ? ' <button type="button" class="zrp-btn zrp-btn-quiet" data-del="' + (+c.id) + '">Delete</button>' : '') + '</div></div>';
				}).join('') : '<p class="zrp-empty">No codes on file.</p>';
				box.querySelectorAll('[data-reveal]').forEach(function (b) {
					b.addEventListener('click', function () {
						var id = b.getAttribute('data-reveal'), out = b.closest('.zrp-note').querySelector('.zrp-code-shown');
						b.disabled = true;
						api('codes/' + id + '/reveal', { method: 'POST' }).then(function (r) {
							out.textContent = r.code; out.classList.add('is-on');
							setTimeout(function () { out.textContent = ''; out.classList.remove('is-on'); b.disabled = false; }, 30000); // shown for 30 s, never kept
						}, function (e) { b.disabled = false; toast(e.message); });
					});
				});
				box.querySelectorAll('[data-del]').forEach(function (b) {
					b.addEventListener('click', function () { b.disabled = true; api('codes/' + b.getAttribute('data-del') + '/delete', { method: 'POST' }).then(function () { render(); }, function (e) { b.disabled = false; toast(e.message); }); });
				});
			}, function (e) { var box = main.querySelector('#zrp-codes'); if (box) { box.innerHTML = '<p>' + esc('The codes could not be loaded: ' + e.message) + '</p>'; } });
		}
		function renderMap(main) {
			if (!window.L || !C.map) { main.innerHTML = '<div class="zrp-card"><p>The map needs Doorhangers (it carries the map). Notes are still kept and listed under Review.</p></div>'; return; }
			main.innerHTML = '<div class="zrp-card"><h2>Notes on the map</h2><div id="zrp-map" class="zrp-map" role="region" aria-label="Map of survey notes"></div><div class="zrp-legend" id="zrp-legend"></div>' +
				'<p class="zrp-help" style="margin-top:.5rem">Filled: confirmed (everyone\'s). Hollow: yours, not confirmed yet.</p></div>';
			if (S.map) { try { S.map.remove(); } catch (e) {} S.map = null; }
			if (C.leafletImages) { L.Icon.Default.imagePath = C.leafletImages; }
			var first = S.pending.filter(function (p) { return p.lat != null; })[0];
			var start = (S.survey && S.survey.last) ? [S.survey.last.lat, S.survey.last.lng] : (first ? [first.lat, first.lng] : homePoint());
			S.map = L.map(main.querySelector('#zrp-map'), { zoomControl: true }).setView(start || [20, 0], start ? 15 : 2);
			L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(S.map);
			S.mapLayer = L.layerGroup().addTo(S.map);
			var leg = main.querySelector('#zrp-legend');
			leg.innerHTML = S.vocab.map(function (v) { return '<button type="button" class="zrp-chip" data-tag="' + esc(v.slug) + '" aria-pressed="' + (S.filter[v.slug] === false ? 'false' : 'true') + '"><span class="zrp-swatch" style="background:' + colorOf(v.slug) + '"></span>' + esc(v.label) + '</button>'; }).join('');
			leg.querySelectorAll('.zrp-chip').forEach(function (b) { b.addEventListener('click', function () { var t = b.getAttribute('data-tag'); S.filter[t] = S.filter[t] === false; b.setAttribute('aria-pressed', S.filter[t] === false ? 'false' : 'true'); loadMapNotes(); }); });
			var tmr = null;
			S.map.on('moveend', function () { clearTimeout(tmr); tmr = setTimeout(loadMapNotes, 300); });
			loadMapNotes();
		}
		function loadMapNotes() {
			if (!S.map) { return; }
			var b = S.map.getBounds(), box = [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()].map(function (x) { return x.toFixed(5); }).join(',');
			api('notes?status=confirmed&box=' + box).then(function (r) {
				if (!S.map) { return; }
				S.mapLayer.clearLayers();
				var all = (r.notes || []).concat(S.pending.filter(function (p) { return p.lat != null; }));
				all.forEach(function (n) {
					if (n.lat == null || n.lng == null) { return; }
					var tags = (n.status === 'confirmed' ? n.tags : n.suggested) || [];
					var shown = tags.filter(function (t) { return S.filter[t] !== false; });
					if (tags.length && !shown.length) { return; }
					var col = shown.length ? colorOf(shown[0]) : '#6B7280', conf = n.status === 'confirmed';
					var m = L.circleMarker([n.lat, n.lng], { radius: conf ? 8 : 7, color: col, weight: 3, fillColor: col, fillOpacity: conf ? 0.85 : 0.1 });
					m.bindPopup('<strong>' + esc(tags.map(labelOf).join(', ') || 'No tag') + '</strong><br>' + esc((n.street || 'Street not known yet') + ' · ' + (SCOPE_WORDS[n.scope] || '')) + '<br>“' + esc(n.said) + '”<br><small>' + esc(String(n.at).slice(0, 10)) + (conf ? '' : ' · not confirmed') + '</small>');
					S.mapLayer.addLayer(m);
				});
			}, function (e) { toast('The notes could not be loaded: ' + e.message); });
		}

		/* ============================================================ Plan N routes
		   The form (where, which day, how many, how long, from what hour) -> POST plan (the ranker's ranking and the
		   forecast) -> POST plan/area for each neighborhood in rank order until there is enough to choose from ->
		   ZRP_PLAN.buildRoutes (Doorhangers' planWalks, scored here) -> a map with the routes in their colors and a card
		   each: Pick, Edit blocks, or Draw my own -> Send to Doorhangers (Doorhangers' preview) -> Make the route (the
		   press: Doorhangers writes it; the server logs who pressed). The map is built once per visit and painted over,
		   so a pick or an edit never moves it. */
		var P = window.ZRP_PLAN || null;
		var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
		function ymd(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
		function dayAfter(s, n) { var d = new Date(s + 'T12:00:00'); d.setDate(d.getDate() + n); return ymd(d); }
		function today() { return (S.planCfg && S.planCfg.today) || ymd(new Date(Date.now())); }
		function dayText(s) { try { return new Date(s + 'T12:00:00').toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' }); } catch (e) { return s; } }
		function hourText(h) { h = +h; return (h % 12 || 12) + (h < 12 ? ' am' : ' pm'); }
		function winText(w) { var p = String(w || '').split('-'); return p.length === 2 ? hourText(p[0]) + '–' + hourText(p[1]) : ''; }
		/* "Saturday", "tomorrow", "today" (the ask bar's period words) -> a date; anything else: tomorrow */
		function whenToDate(w) {
			var t = String(w || '').toLowerCase(), base = today();
			if (t === 'today') { return base; }
			if (t === 'tomorrow' || !t) { return dayAfter(base, 1); }
			for (var i = 0; i < 7; i++) { var dn = DAYS[i].toLowerCase(); if (t === dn || t === dn.slice(0, 3) || t === dn + 's') { var d = new Date(base + 'T12:00:00'), add = (i - d.getDay() + 7) % 7; return dayAfter(base, add); } }
			return dayAfter(base, 1);
		}
		S.plan = { req: { area: recall('zrp_plan_area') || '', date: '', count: 3, hours: 2, start: 10 }, stage: 'form', busy: '', err: '', notes: [], res: null, areas: [], routes: [], sel: null, edit: false, draw: null, send: null, sends: null, layers: null };
		function pr() { return S.plan; }
		function areaRow(a) { return a && a.row; }
		function routeTitle(r) {
			var names = r.streets.slice(0, 2).map(function (s) { return s.name; });
			return (names.join(' & ') || 'Route') + (r.city ? ', ' + r.city : '') + (r.kind === 'drawn' ? ' (drawn)' : r.kind === 'edited' ? ' (edited)' : '');
		}
		function pct(v, unit) { v = +v; if (!isFinite(v)) { return ''; } return unit === '%' ? Math.round(v <= 1 ? v * 100 : v) + '%' : String(Math.round(v * 10) / 10) + (unit ? ' ' + unit : ''); }
		function profileText(area) {
			var row = areaRow(area), res = pr().res; if (!row) { return 'Drawn on the map: no neighborhood profile from the ranker.'; }
			var bits = [(+row.homes || 0) + ' homes'], ing = (res && res.ingredients) || {};
			['owners', 'older_homes', 'single_family'].forEach(function (k) { var v = row.ingredients && row.ingredients[k]; if (v && v.value != null) { bits.push((ing[k] && ing[k].label ? ing[k].label : k.replace('_', ' ')) + ' ' + pct(v.value, (ing[k] && ing[k].unit) || '%')); } });
			bits.push(row.customers ? row.customers + ' past customer' + (row.customers === 1 ? '' : 's') + ' nearby' : 'no past customers nearby');
			bits.push(row.last_hung_days == null ? 'never hung' : 'last hung ' + row.last_hung_days + ' days ago');
			return bits.join(' · ');
		}
		function vocabProduct(slug) { var v = vocabOf(slug); return v && v.product_label ? v.product_label : ''; }
		function surveyText(r, area) {
			if (area.tags === null) { return 'The survey\'s notes could not be read just now.'; }
			if (!r.tagged.length) { return 'No survey notes on these streets yet.'; }
			return r.tagged.slice(0, 4).map(function (t) { var pl = vocabProduct(t.slug); return t.label + ' on ' + t.houses + ' houses' + (pl ? ' (' + pl + ')' : ''); }).join(' · ');
		}
		function skippedText(r, area) {
			var p = P.prepare(area), bits = [];
			if (r.nsSkipped) { bits.push(r.nsSkipped + ' No-Solicit house' + (r.nsSkipped === 1 ? '' : 's')); }
			Object.keys(p.hungStreets).forEach(function (s) { var h = p.hungStreets[s]; bits.push(s + ' (' + (h.status === 'done' ? 'hung ' + dayText(h.date) : 'already planned') + ')'); });
			return bits.length ? 'Left out: ' + bits.join(' · ') : '';
		}
		// the two winds named as the settings name them: "steady wind 8 mph, gusts to 24 mph"
		function wxFig(wx) {
			return 'rain ' + (wx.rain_pct != null ? wx.rain_pct + '%' : '?') + (wx.rain_in ? ', ' + wx.rain_in + ' in' : '') + ' · steady wind ' + (wx.wind_mph != null ? wx.wind_mph + ' mph' : '?') + (wx.gust_mph != null ? ', gusts to ' + wx.gust_mph + ' mph' : '');
		}
		function wxLine(wx, withDay) {
			if (!wx) { return ''; }
			var when = (withDay ? dayText(wx.date) + ' · ' : '') + winText(wx.window);
			if (wx.status === 'ok' || wx.status === 'flagged') { return when + ': ' + wxFig(wx); }
			return wx.message || '';
		}
		function mphText(n) { return String(Math.round(Number(n) * 10) / 10); }
		function wxBanner() {
			var res = pr().res; if (!res) { return ''; }
			var wx = res.forecast, gusty = wx.status === 'ok' && !!wx.gusty, lim = res.limits || {};
			var cls = gusty ? 'gust' : (wx.status === 'ok' ? 'ok' : (wx.status === 'flagged' ? 'flag' : 'none'));
			var head = gusty ? 'Good for hanging, but gusty' : (wx.status === 'ok' ? 'Good for hanging' : (wx.status === 'flagged' ? 'Not a hanging day' : (wx.status === 'none' ? 'No forecast yet' : 'No forecast')));
			var html = '<div class="zrp-wx zrp-wx-' + cls + ' zdz-pair" role="status"><p><strong>' + esc(head) + '</strong> · ' + esc(dayText(wx.date)) + ', ' + esc(winText(wx.window)) + '</p>';
			if (wx.status === 'ok' || wx.status === 'flagged') { html += '<p>' + esc(wxLine(wx, false).replace(/^[^:]*: /, '')) + '</p>'; }
			if (wx.reasons && wx.reasons.length) { html += '<p>' + esc(wx.reasons.join(' · ')) + '</p>'; }
			if (wx.warnings && wx.warnings.length) { html += '<p>' + esc(wx.warnings.join(' · ')) + '</p>'; }
			if (wx.status === 'flagged') {
				html += wx.suggest ? '<p><button type="button" class="zrp-btn zrp-btn-primary" data-day="' + esc(wx.suggest.date) + '">Plan ' + esc(dayText(wx.suggest.date)) + ' instead</button> <small>' + esc(wxFig(wx.suggest) + (wx.suggest.gusty ? ' · gusty' : '')) + '</small></p>'
					: '<p>No good day within a week either side in the forecast.</p>';
			} else if (wx.status !== 'ok') { html += '<p>' + esc(wx.message || '') + '</p>'; }
			var gl = Number(lim.gust_mph) || 0, gf = Number(lim.gust_flag) || 1.5;
			html += '<p class="zrp-help">Limits: rain chance over ' + esc(lim.rain_pct) + '%, over ' + esc(lim.rain_in) + ' in of rain, or steady wind over ' + esc(lim.wind_mph) + ' mph in the walking window.' +
				(gl > 0 ? ' Gusts over ' + esc(mphText(gl)) + ' mph lower a route\'s score; over ' + esc(mphText(gl * gf)) + ' mph, not a hanging day.' : '') + '</p></div>';
			return html;
		}
		function formHtml() {
			var q = pr().req, cfg = S.planCfg || {}, t = today(), max = dayAfter(t, 60);
			var opt = function (vals, cur, fmt) { return vals.map(function (v) { return '<option value="' + v + '"' + (String(v) === String(cur) ? ' selected' : '') + '>' + esc(fmt(v)) + '</option>'; }).join(''); };
			return '<div class="zrp-card"><h2>Plan door-hanger routes</h2>' +
				'<p class="zrp-help">The ranker ranks the neighborhoods, Doorhangers cuts them into walks, and the survey notes and the forecast decide which walks come first.</p>' +
				(cfg.rank ? '' : '<p class="zrp-help"><strong>Ranking needs a neighborhood ranker connected to the site.</strong> You can still draw a route.</p>') +
				'<form class="zrp-planform" id="zrp-planform" novalidate>' +
				'<label class="zrp-field zrp-field-wide" for="zrp-p-area">Where<input class="zrp-type" id="zrp-p-area" type="text" maxlength="80" autocomplete="off" value="' + esc(q.area) + '" placeholder="A ZIP, a neighborhood, or an address"></label>' +
				'<label class="zrp-field" for="zrp-p-date">Day<input class="zrp-type" id="zrp-p-date" type="date" min="' + t + '" max="' + max + '" value="' + esc(q.date || dayAfter(t, 1)) + '"></label>' +
				'<label class="zrp-field" for="zrp-p-count">Routes<select class="zrp-scope" id="zrp-p-count">' + opt([1, 2, 3, 4, 5], q.count, String) + '</select></label>' +
				'<label class="zrp-field" for="zrp-p-hours">Walking<select class="zrp-scope" id="zrp-p-hours">' + opt([1, 1.5, 2, 3, 4], q.hours, function (v) { return v + ' h'; }) + '</select></label>' +
				'<label class="zrp-field" for="zrp-p-start">From<select class="zrp-scope" id="zrp-p-start">' + opt([8, 9, 10, 11, 12, 13, 14, 15, 16], q.start, hourText) + '</select></label>' +
				'<div class="zrp-row zrp-field-wide"><button type="submit" class="zrp-btn zrp-btn-primary" id="zrp-p-go">Plan routes</button>' +
				(window.L && C.map ? '<button type="button" class="zrp-btn zrp-btn-quiet" id="zrp-p-draw">Draw my own</button>' : '') + '</div></form></div>';
		}
		function readForm(main) {
			var q = pr().req, g = function (id) { var el = main.querySelector('#' + id); return el ? el.value : ''; };
			q.area = g('zrp-p-area').trim(); q.date = g('zrp-p-date') || dayAfter(today(), 1); q.count = +g('zrp-p-count') || 3; q.hours = +g('zrp-p-hours') || 2; q.start = +g('zrp-p-start') || 10;
			store('zrp_plan_area', q.area || null);
		}
		function renderPlan(main) {
			if (S.survey && moving()) { main.innerHTML = '<div class="zrp-pull">Pull over to plan routes. While the car moves the Route Planner only listens.</div>'; return; }
			main.innerHTML = formHtml() + '<div id="zrp-pbody"></div>' +
				(window.L && C.map ? '<div class="zrp-card zrp-pmapcard" id="zrp-pmapcard"' + (pr().routes.length || pr().draw ? '' : ' hidden') + '><div id="zrp-pdraw"></div><div id="zrp-pmap" class="zrp-map zrp-pmap" role="region" aria-label="Map of the planned routes"></div></div>' : '') +
				'<div id="zrp-proutes" class="zrp-routes"></div><div id="zrp-psends"></div>';
			var f = main.querySelector('#zrp-planform');
			f.addEventListener('submit', function (e) { e.preventDefault(); readForm(main); runPlan(); });
			var d = main.querySelector('#zrp-p-draw'); if (d) { d.addEventListener('click', function () { readForm(main); startDraw(); }); }
			if (S.pmap) { try { S.pmap.remove(); } catch (e) {} S.pmap = null; pr().layers = null; }
			pr().fitted = false; pr().focused = null;
			paintPlan();
			loadSends();
		}
		function paintPlan() {
			var body = root.querySelector('#zrp-pbody'); if (!body) { return; }
			var p = pr(), html = '';
			if (p.stage === 'busy') { html += '<div class="zrp-card" role="status"><p class="zrp-help"><span class="zdz-loading-spinner" aria-hidden="true"></span> ' + esc(p.busy) + '</p></div>'; }
			if (p.err) { html += '<div class="zrp-card zrp-perr" role="alert"><p>' + esc(p.err) + '</p></div>'; }
			html += wxBanner();
			if (p.notes.length) { html += '<div class="zrp-card"><p class="zrp-help">' + p.notes.map(esc).join('<br>') + '</p></div>'; }
			if (p.stage === 'ready' && !p.routes.length && !p.draw) { html += '<div class="zrp-card"><p>No walkable route came out of these neighborhoods (too few mapped houses once the hung streets and No-Solicit houses are left out). Try another area or draw one.</p></div>'; }
			body.innerHTML = html;
			body.querySelectorAll('[data-day]').forEach(function (b) { b.addEventListener('click', function () { var q = pr().req; q.date = b.getAttribute('data-day'); var di = root.querySelector('#zrp-p-date'); if (di) { di.value = q.date; } runPlan(); }); });
			paintRoutes();
			paintMap();
		}
		function routeCard(r, i) {
			var p = pr(), area = p.areas[r.area], picked = p.sel === i, wxs = '';
			// every card says its weather, the steady wind and the gusts each named; a gusty one warns.
			//
			if (r.wx) {
				var differs = p.res && p.res.forecast && (r.wx.status !== p.res.forecast.status || r.wx.date !== p.res.forecast.date || !!r.wx.gusty !== !!p.res.forecast.gusty);
				var wtx = (r.wx.status === 'ok' || r.wx.status === 'flagged') ? (differs ? wxLine(r.wx, true) : wxFig(r.wx)) : (differs ? wxLine(r.wx, true) : '');
				if (wtx) { wxs += '<p class="zrp-rline zrp-rwx"><span class="zrp-rlabel">Weather</span> ' + esc(wtx) + '</p>'; }
				if (r.gusty) { wxs += '<p class="zrp-rline zrp-rgust zdz-pair"><strong>Gusty:</strong> hangers may blow off doors</p>'; }
			}
			var sk = skippedText(r, area);
			var html = '<article class="zrp-route' + (picked ? ' is-picked' : '') + '" data-r="' + i + '" style="--rc:' + r.color + '" aria-label="Route ' + r.n + '">' +
				'<header class="zrp-rhead"><span class="zrp-rnum zdz-pair" style="background:' + r.color + ';color:' + (r.ink || '#000000') + '">' + r.n + '</span><h3>' + esc(routeTitle(r)) + '</h3><span class="zrp-score" title="How well it fits, out of 100">' + r.score + '</span></header>' +
				'<p class="zrp-rfacts"><strong>' + r.houses + ' houses</strong> · about ' + (r.est_min >= 90 ? (Math.round(r.est_min / 30) / 2) + ' h' : r.est_min + ' min') + ' · ' + (r.length_m / 1609.34).toFixed(1) + ' mi · ' + r.streets.length + ' street' + (r.streets.length === 1 ? '' : 's') + '</p>' +
				'<p class="zrp-rline"><span class="zrp-rlabel">Neighborhood</span> ' + esc(profileText(area)) + '</p>' +
				'<p class="zrp-rline"><span class="zrp-rlabel">Survey</span> ' + esc(surveyText(r, area)) + '</p>' +
				(sk ? '<p class="zrp-rline"><span class="zrp-rlabel">Skipped</span> ' + esc(sk.replace(/^Left out: /, '')) + '</p>' : '') + wxs +
				'<p class="zrp-help">' + esc(r.streets.map(function (s) { return s.name + ' (' + s.count + ')'; }).join(', ')) + '</p>' +
				'<div class="zrp-row"><button type="button" class="zrp-btn' + (picked ? ' zrp-btn-primary' : '') + '" data-pick="' + i + '" aria-pressed="' + picked + '">' + (picked ? 'Picked' : 'Pick') + '</button>' +
				(r.sent ? '<span class="zrp-help">Sent to Doorhangers</span>' : '<button type="button" class="zrp-btn zrp-btn-quiet" data-edit="' + i + '" aria-expanded="' + (picked && p.edit) + '">' + (picked && p.edit ? 'Done editing' : 'Edit blocks') + '</button>') + '</div>';
			if (picked && p.edit && !r.sent) { html += blocksHtml(r); }
			if (picked) { html += sendHtml(r); }
			return html + '</article>';
		}
		function blocksHtml(r) {
			var bl = P.nearBlocks(r, pr().areas);
			return '<div class="zrp-blocks" role="group" aria-label="Blocks in this route"><p class="zrp-help">Tap a block to take it out or put it in (on the map too). The walking order is worked out again each time.</p>' +
				bl.map(function (b) { return '<button type="button" class="zrp-chip" data-block="' + esc(b.id) + '" aria-pressed="' + b.on + '">' + esc(b.name) + (b.low != null ? ' ' + b.low + '–' + b.high : '') + ' <small>' + b.houses + '</small></button>'; }).join('') + '</div>';
		}
		function sendHtml(r) {
			var cfg = S.planCfg || {}, s = pr().send || {};
			if (!cfg.send) { return '<p class="zrp-help zrp-send">Sending a route needs Doorhangers on your account.</p>'; }
			if (r.sent) { // a route sent stays sent -- one press, one Doorhangers route
				return '<div class="zrp-send zrp-sent zdz-pair" role="status"><p><strong>' + esc(r.sent.message || ('Route #' + r.sent.id + ' is planned in Doorhangers.')) + '</strong></p>' +
					'<div class="zrp-row"><button type="button" class="zrp-btn zrp-btn-primary" id="zrp-open-dh">Open Doorhangers</button></div></div>';
			}
			if (!r.houses) { return '<p class="zrp-help zrp-send">This route has no houses. Put a block back in.</p>'; }
			if (s.step === 'done' && s.result) {
				return '<div class="zrp-send zrp-sent zdz-pair" role="status"><p><strong>' + esc(s.result.message || ('Route #' + s.result.id + ' is planned in Doorhangers.')) + '</strong></p>' +
					'<div class="zrp-row"><button type="button" class="zrp-btn zrp-btn-primary" id="zrp-open-dh">Open Doorhangers</button></div></div>';
			}
			if (s.step === 'preview' && s.preview) {
				var sm = s.preview.summary || {};
				return '<div class="zrp-send zrp-preview" role="group" aria-label="Check the route before it is made"><p><strong>Doorhangers will plan:</strong> ' + esc(sm.name || '') + '</p>' +
					'<p>' + esc((sm.houses || 0) + ' houses on ' + (sm.streets || []).join(', ')) + (sm.partial && sm.partial.length ? ' <small>(part of ' + esc(sm.partial.join(', ')) + ')</small>' : '') + '</p>' +
					'<p>' + esc('For ' + (sm.for && sm.for.is_you ? 'you' : ((sm.for && sm.for.name) || 'you')) + (sm.due_text ? ', by ' + sm.due_text : '') + (sm.hours_text ? ', ' + sm.hours_text : '') + (sm.walks > 1 ? ', ' + sm.walks + ' walks' : '')) + '</p>' +
					((sm.lookup || []).length ? '<ul class="zrp-help">' + sm.lookup.map(function (l) { return '<li>' + esc(l) + '</li>'; }).join('') + '</ul>' : '') +
					'<div class="zrp-row"><button type="button" class="zrp-btn zrp-btn-primary" id="zrp-make"' + (s.busy ? ' disabled' : '') + '>Make the route</button><button type="button" class="zrp-btn zrp-btn-quiet" id="zrp-unsend">Cancel</button></div>' +
					(s.err ? '<p class="zrp-perr" role="alert">' + esc(s.err) + '</p>' : '') + '</div>';
			}
			var people = cfg.people || [];
			return '<div class="zrp-send">' +
				(people.length ? '<label class="zrp-field" for="zrp-for">Who walks it<select class="zrp-scope" id="zrp-for"><option value="">Me</option>' + people.filter(function (x) { return String(x.id) !== String(C.userId); }).map(function (x) { return '<option value="' + esc(x.name) + '"' + (s.for === x.name ? ' selected' : '') + '>' + esc(x.name) + '</option>'; }).join('') + '</select></label>' : '') +
				'<label class="zrp-field zrp-field-wide" for="zrp-snote">Note for the walker<input class="zrp-type" id="zrp-snote" type="text" maxlength="200" value="' + esc(s.notes || '') + '" placeholder="optional"></label>' +
				'<div class="zrp-row"><button type="button" class="zrp-btn zrp-btn-primary" id="zrp-send"' + (s.busy ? ' disabled' : '') + '>Send to Doorhangers</button></div>' +
				(s.err ? '<p class="zrp-perr" role="alert">' + esc(s.err) + '</p>' : '') + '</div>';
		}
		function paintRoutes() {
			var box = root.querySelector('#zrp-proutes'); if (!box) { return; }
			var p = pr();
			box.innerHTML = p.routes.map(routeCard).join('');
			box.querySelectorAll('[data-pick]').forEach(function (b) { b.addEventListener('click', function () { var i = +b.getAttribute('data-pick'); if (p.sel !== i) { p.sel = i; p.edit = false; p.send = null; } paintRoutes(); paintMap(); }); });
			box.querySelectorAll('[data-edit]').forEach(function (b) { b.addEventListener('click', function () { var i = +b.getAttribute('data-edit'); if (p.sel !== i) { p.sel = i; p.send = null; p.edit = true; } else { p.edit = !p.edit; } paintRoutes(); paintMap(); }); });
			box.querySelectorAll('[data-block]').forEach(function (b) { b.addEventListener('click', function () { toggle(b.getAttribute('data-block')); }); });
			var sd = box.querySelector('#zrp-send'); if (sd) { sd.addEventListener('click', function () { var s = p.send = p.send || {}; var fo = box.querySelector('#zrp-for'), no = box.querySelector('#zrp-snote'); s.for = fo ? fo.value : ''; s.notes = no ? no.value : ''; sendRoute(); }); }
			var mk = box.querySelector('#zrp-make'); if (mk) { mk.addEventListener('click', makeRoute); }
			var un = box.querySelector('#zrp-unsend'); if (un) { un.addEventListener('click', function () { p.send = { for: (p.send || {}).for, notes: (p.send || {}).notes }; paintRoutes(); }); }
			var od = box.querySelector('#zrp-open-dh'); if (od) { od.addEventListener('click', function () { var rr = p.routes[p.sel], id = (rr && rr.sent && rr.sent.id) || (p.send && p.send.result && p.send.result.id); if (window.Bridge && typeof window.Bridge.loadApp === 'function') { window.Bridge.loadApp('doorhangers', id ? { drop_id: id } : {}); } else { toast('Open Doorhangers from the launcher.'); } }); }
		}
		function toggle(segId) {
			var p = pr(), r = p.routes[p.sel]; if (!r) { return; }
			if (r.sent) { toast('This route is already in Doorhangers. Edit it there.'); return; }
			P.toggleBlock(r, p.areas, segId, { perHour: (p.res && p.res.per_hour) || (C.perHour ? +C.perHour : 40) }, P.takenBy(p.routes, r));
			p.send = null;
			paintRoutes(); paintMap();
		}
		/* ---------------------------------------------- the map (Doorhangers' Leaflet; painted over, never rebuilt) */
		function ensureMap() {
			var el = root.querySelector('#zrp-pmap'); if (!el || !window.L) { return null; }
			var card = root.querySelector('#zrp-pmapcard'); if (card) { card.hidden = false; }
			if (S.pmap && S.pmap.getContainer() === el) { return S.pmap; }
			if (C.leafletImages) { L.Icon.Default.imagePath = C.leafletImages; }
			var c = (pr().res && pr().res.rows && pr().res.rows[0]) ? [pr().res.rows[0].lat, pr().res.rows[0].lon] : homePoint();
			S.pmap = L.map(el, { zoomControl: true }).setView(c || [20, 0], c ? 16 : 2);
			el.__zrpMap = S.pmap; // the suites reach the map through its element
			L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(S.pmap);
			pr().layers = { routes: L.layerGroup().addTo(S.pmap), blocks: L.layerGroup().addTo(S.pmap), draw: L.layerGroup().addTo(S.pmap) };
			wireDraw(S.pmap);
			pr().fitted = false;
			if (pr().draw) { paintStrokes(); setDrawing(pr().draw.on); } // a map built again keeps the drawing (a tab switch)
			return S.pmap;
		}
		function paintMap() {
			var p = pr(); if (!p.routes.length && !p.draw) { var card = root.querySelector('#zrp-pmapcard'); if (card) { card.hidden = true; } return; }
			var map = ensureMap(); if (!map) { return; }
			var Ly = p.layers; Ly.routes.clearLayers(); Ly.blocks.clearLayers();
			var all = [];
			p.routes.forEach(function (r, i) {
				if (!r.path.length) { return; }
				var on = p.sel === null || p.sel === i;
				var line = L.polyline(r.path, { color: r.color, weight: p.sel === i ? 6 : 4, opacity: on ? 0.95 : 0.35, lineJoin: 'round' });
				line.on('click', function () { p.sel = i; p.edit = false; p.send = null; paintRoutes(); paintMap(); });
				Ly.routes.addLayer(line);
				Ly.routes.addLayer(L.circleMarker(r.path[0], { radius: 9, color: '#fff', weight: 2, fillColor: r.color, fillOpacity: 1 }).bindTooltip('Route ' + r.n + ' starts here'));
				all = all.concat(r.path);
			});
			if (p.edit && p.routes[p.sel]) {
				var r = p.routes[p.sel];
				P.nearBlocks(r, p.areas).forEach(function (b) {
					var seg = L.polyline(b.path, { color: b.on ? r.color : '#6B7280', weight: 12, opacity: b.on ? 0.55 : 0.4, dashArray: b.on ? null : '6 8' });
					seg.on('click', function () { toggle(b.id); });
					seg.bindTooltip(esc(b.name + (b.low != null ? ' ' + b.low + '–' + b.high : '') + ': ' + (b.on ? 'tap to take out' : 'tap to put in')));
					Ly.blocks.addLayer(seg);
				});
			}
			// all the routes at first; a route picked (or just drawn) brings the map to it, once per pick. No animation: a
			// theme rule that turns transitions off leaves Leaflet waiting for a transitionend that never comes
			var focus = p.sel !== null && p.routes[p.sel] && p.routes[p.sel].path.length ? p.sel : null;
			if (focus !== null && p.focused !== focus) { try { map.fitBounds(L.latLngBounds(p.routes[focus].path).pad(0.25), { maxZoom: 18, animate: false }); p.focused = focus; p.fitted = true; } catch (e) {} }
			else if (all.length && !p.fitted) { try { map.fitBounds(L.latLngBounds(all).pad(0.15), { animate: false }); p.fitted = true; } catch (e) {} }
			if (focus === null) { p.focused = null; }
		}
		/* ---------------------------------------------- Draw my own (Doorhangers' own way: Move map / Draw route, its stroke color) */
		/* every way out of drawing comes through here, so the map never stays frozen */
		function endDraw() {
			var p = pr(), map = S.pmap;
			if (map) { map.dragging.enable(); map.touchZoom.enable(); map.doubleClickZoom.enable(); map.scrollWheelZoom.enable(); var c = map.getContainer(); c.classList.remove('zrp-drawing'); c.style.touchAction = ''; }
			p.draw = null; paintStrokes(); paintDraw();
		}
		function startDraw() {
			var p = pr(); p.draw = { on: true, strokes: [] }; p.sel = null; p.edit = false; p.send = null;
			var map = ensureMap(); if (!map) { toast('Drawing needs the map (Doorhangers carries it).'); p.draw = null; return; }
			paintDraw(); setDrawing(true); paintRoutes(); paintMap();
		}
		function paintDraw() {
			var box = root.querySelector('#zrp-pdraw'), p = pr(); if (!box) { return; }
			if (!p.draw) { box.innerHTML = ''; return; }
			box.innerHTML = '<div class="zrp-modeseg" role="group" aria-label="Map mode"><button type="button" id="zrp-d-pan" aria-pressed="' + !p.draw.on + '">Move map</button><button type="button" id="zrp-d-draw" aria-pressed="' + p.draw.on + '">Draw route</button></div>' +
				'<p class="zrp-help" id="zrp-d-hint">' + (p.draw.on ? '<strong>Drawing:</strong> drag along the streets you\'ll walk (lift between streets) or circle a block.' : '<strong>Moving the map:</strong> drag and zoom to the block, then tap <strong>Draw route</strong>.') + '</p>' +
				'<div class="zrp-row"><button type="button" class="zrp-btn zrp-btn-primary" id="zrp-d-use"' + (p.draw.strokes.length ? '' : ' disabled') + '>Use my route</button><button type="button" class="zrp-btn zrp-btn-quiet" id="zrp-d-undo"' + (p.draw.strokes.length ? '' : ' disabled') + '>Undo</button><button type="button" class="zrp-btn zrp-btn-quiet" id="zrp-d-cancel">Cancel</button></div>';
			box.querySelector('#zrp-d-pan').addEventListener('click', function () { setDrawing(false); });
			box.querySelector('#zrp-d-draw').addEventListener('click', function () { setDrawing(true); });
			box.querySelector('#zrp-d-undo').addEventListener('click', function () { if (p.draw) { p.draw.strokes.pop(); } paintStrokes(); paintDraw(); });
			box.querySelector('#zrp-d-cancel').addEventListener('click', function () { endDraw(); paintMap(); });
			box.querySelector('#zrp-d-use').addEventListener('click', useDrawing);
		}
		function setDrawing(on) {
			var p = pr(), map = S.pmap; if (!p.draw || !map) { return; }
			p.draw.on = on;
			var c = map.getContainer();
			if (on) { map.dragging.disable(); map.touchZoom.disable(); map.doubleClickZoom.disable(); map.scrollWheelZoom.disable(); c.classList.add('zrp-drawing'); c.style.touchAction = 'none'; }
			else { map.dragging.enable(); map.touchZoom.enable(); map.doubleClickZoom.enable(); map.scrollWheelZoom.enable(); c.classList.remove('zrp-drawing'); c.style.touchAction = ''; }
			paintDraw();
		}
		function paintStrokes() {
			var p = pr(); if (!p.layers) { return; } p.layers.draw.clearLayers();
			((p.draw && p.draw.strokes) || []).forEach(function (s) { p.layers.draw.addLayer(s.type === 'loop' ? L.polygon(s.points, { color: '#F2812A', weight: 4, fillOpacity: 0.12 }) : L.polyline(s.points, { color: '#F2812A', weight: 8, opacity: 0.75, lineCap: 'round', lineJoin: 'round' })); });
		}
		function wireDraw(map) {
			var box = map.getContainer(), cur = null, curLayer = null;
			function ll(ev) { var r = box.getBoundingClientRect(); return map.containerPointToLatLng([ev.clientX - r.left, ev.clientY - r.top]); }
			box.addEventListener('pointerdown', function (ev) {
				var p = pr(); if (!p.draw || !p.draw.on || ev.button > 0 || cur) { return; } // one finger draws
				ev.preventDefault(); try { box.setPointerCapture(ev.pointerId); } catch (e) {}
				var q = ll(ev); cur = [[q.lat, q.lng]];
				curLayer = L.polyline(cur, { color: '#F2812A', weight: 8, opacity: 0.75, lineCap: 'round' }).addTo(map);
			});
			box.addEventListener('pointermove', function (ev) {
				if (!cur) { return; } ev.preventDefault();
				var q = ll(ev), last = cur[cur.length - 1]; if (P.distM(last, [q.lat, q.lng]) < 2) { return; }
				cur.push([q.lat, q.lng]); curLayer.setLatLngs(cur);
			});
			function finish() {
				if (!cur) { return; }
				var pts = cur; cur = null; if (curLayer) { curLayer.remove(); curLayer = null; }
				var s = P.strokeOf(pts), p = pr();
				if (pts.length < 3 || s.length < 15 || !p.draw) { return; }
				p.draw.strokes.push(s); paintStrokes(); paintDraw();
			}
			box.addEventListener('pointerup', finish);
			box.addEventListener('pointercancel', finish);
		}
		function strokesBox(strokes, padM) {
			var la = [], ln = []; strokes.forEach(function (s) { s.points.forEach(function (q) { la.push(q[0]); ln.push(q[1]); }); });
			var lat = (Math.min.apply(null, la) + Math.max.apply(null, la)) / 2, py = padM / 110540, px = padM / (111320 * Math.cos(lat * Math.PI / 180));
			return { xmin: Math.min.apply(null, ln) - px, ymin: Math.min.apply(null, la) - py, xmax: Math.max.apply(null, ln) + px, ymax: Math.max.apply(null, la) + py };
		}
		function useDrawing() {
			var p = pr(), strokes = p.draw && p.draw.strokes; if (!strokes || !strokes.length) { return; }
			var ctx = { perHour: (p.res && p.res.per_hour) || (S.planCfg && S.planCfg.per_hour) || (C.perHour ? +C.perHour : 40) }, sb = strokesBox(strokes, 0), token = p.token;
			// a neighborhood already read is used only when its box holds the WHOLE drawing; otherwise the
			// the map is read under the drawing (one house in a padded edge is not a reason to cut the rest off)
			var best = -1;
			p.areas.forEach(function (a, ai) { var b = a && !a.error && a._box; if (best === -1 && b && b.xmin <= sb.xmin && b.ymin <= sb.ymin && b.xmax >= sb.xmax && b.ymax >= sb.ymax) { best = ai; } });
			var done = function (ai) {
				var r = P.drawnRoute(p.areas, ai, strokes, ctx, P.takenBy(p.routes, null));
				if (!r.keys.length) { toast('No mapped houses along that drawing that another route does not already have. Draw along the houses.'); return; }
				r.n = p.routes.length + 1; r.color = P.COLORS[(p.routes.length) % P.COLORS.length]; r.ink = P.INK[r.color];
				r.req = { area: p.req.area, date: p.req.date, start: p.req.start, hours: p.req.hours };
				p.routes.push(r); p.sel = p.routes.length - 1; p.edit = false; p.send = null;
				endDraw(); p.fitted = false; paintRoutes(); paintMap();
				var card = root.querySelector('.zrp-route[data-r="' + p.sel + '"]'); if (card && card.scrollIntoView) { try { card.scrollIntoView({ block: 'nearest' }); } catch (e) {} }
			};
			if (best !== -1) { done(best); return; }
			var btn = root.querySelector('#zrp-d-use'); if (btn) { btn.disabled = true; btn.textContent = 'Finding the houses…'; }
			var box = strokesBox(strokes, 60);
			api('plan/area', { method: 'POST', body: { envelope: box } }).then(function (a) {
				if (p.token !== token || !p.draw) { return; } // re-planned or cancelled meanwhile
				a.row = null; a._box = box; a.drawn = true;
				a.wx = (p.res && p.res.forecast && p.res.forecast.date === p.req.date) ? p.res.forecast : { status: 'none', date: p.req.date, window: P.hoursWindow(p.req.start, p.req.hours), message: 'Plan routes for this day to see its forecast.' };
				(a.notes || []).forEach(function (n) { if (p.notes.indexOf(n) === -1) { p.notes.push(n); } });
				p.areas.push(a); done(p.areas.length - 1);
			}, function (e) { toast(e.message); paintDraw(); });
		}
		/* ---------------------------------------------- planning */
		function runPlan() {
			var p = pr(), q = p.req;
			if (!q.area) { p.err = 'Where? Give a ZIP, a neighborhood or town, or an address.'; paintPlan(); return; }
			if (p.draw) { endDraw(); }
			p.stage = 'busy'; p.busy = 'Asking for the best neighborhoods near ' + q.area + '…'; p.err = ''; p.notes = []; p.res = null; p.areas = []; p.routes = []; p.sel = null; p.edit = false; p.send = null; p.fitted = false;
			paintPlan();
			var token = p.token = {};
			api('plan', { method: 'POST', body: { area: q.area, date: q.date, count: q.count, hours: q.hours, start: q.start } }).then(function (res) {
				if (p.token !== token) { return; }
				p.res = res;
				return loadAreas(token, 0);
			}).then(function () {
				if (p.token !== token || !p.res) { return; }
				build();
			}, function (e) {
				if (p.token !== token) { return; }
				p.stage = 'form'; p.err = e.message; paintPlan();
			});
		}
		/* neighborhoods in rank order until there are enough houses for N walks (and one spare neighborhood) */
		function loadAreas(token, i) {
			var p = pr(), rows = p.res.rows, q = p.req, size = Math.round(q.hours * ((p.res && p.res.per_hour) || (C.perHour ? +C.perHour : 40)));
			var have = p.areas.filter(function (a) { return a && !a.error; });
			var houses = have.reduce(function (n, a) { return n + P.prepare(a).usable.length; }, 0);
			if (i >= rows.length || (have.length >= Math.min(rows.length, q.count + 1) && houses >= size * q.count * 1.3) || i >= 8) { return Promise.resolve(); }
			var row = rows[i];
			p.busy = 'Reading the map\'s houses: ' + (row.name || ('neighborhood ' + (i + 1))) + ' (' + (i + 1) + ' of ' + Math.min(rows.length, Math.max(q.count + 1, i + 1)) + ')…'; paintPlan();
			var body = row.envelope ? { envelope: row.envelope } : { streets: row.streets, zip: row.zip };
			return api('plan/area', { method: 'POST', body: body }).then(function (a) {
				if (p.token !== token) { return; }
				a.row = row; a.wx = p.res.forecasts[row.wx] || p.res.forecast; a._box = row.envelope || null;
				(a.notes || []).forEach(function (n) { if (p.notes.indexOf(n) === -1) { p.notes.push(n); } });
				if (!a.holder) { var m = 'Doorhangers is not on your account: houses are shown without numbers or No-Solicit signs, and routes cannot be sent.'; if (p.notes.indexOf(m) === -1) { p.notes.push(m); } }
				p.areas.push(a);
			}, function (e) {
				if (p.token !== token) { return; }
				p.areas.push({ row: row, error: e.message, houses: [], segments: [] });
				p.notes.push((row.name || 'A neighborhood') + ': ' + e.message);
			}).then(function () { if (p.token === token) { return loadAreas(token, i + 1); } });
		}
		function build() {
			var p = pr(), q = p.req;
			p.busy = 'Cutting the walks…'; paintPlan();
			var start = p.res.request && p.res.request.point ? p.res.request.point : null;
			var out = P.buildRoutes(p.areas.map(function (a) { return a.drawn ? null : a; }), { count: q.count, minutes: q.hours * 60, perHour: p.res.per_hour || (C.perHour ? +C.perHour : 40), maxStreets: (S.planCfg && S.planCfg.max && S.planCfg.max.streets) || 12, start: start });
			var rq = p.res.request; out.routes.forEach(function (r) { r.req = { area: rq.area, date: rq.date, start: rq.start, hours: rq.hours }; });
			p.routes = out.routes; p.stage = 'ready'; p.busy = '';
			if (rq.place) { p.notes.unshift('Planned near ' + rq.place + ' (found on the address map).'); }
			if (out.routes.length && out.routes.length < q.count) { p.notes.push('Only ' + out.routes.length + ' distinct route' + (out.routes.length === 1 ? '' : 's') + ' fit here (no two share a house). Draw another, or try a wider area.'); }
			var card = root.querySelector('#zrp-pmapcard'); if (card) { card.hidden = !out.routes.length; }
			paintPlan();
		}
		/* ---------------------------------------------- Send to Doorhangers: its preview, then the press */
		function sendRoute() {
			var p = pr(), r = p.routes[p.sel], s = p.send = p.send || {}; if (!r) { return; }
			if (r.sent) { return; }
			var body = P.sendBody(r, r.req || p.req); body.for = s.for || ''; body.notes = s.notes || ''; // the day it was planned for
			s.busy = true; s.err = ''; paintRoutes();
			api('plan/send', { method: 'POST', body: body }).then(function (pv) { s.busy = false; s.step = 'preview'; s.preview = pv; paintRoutes(); }, function (e) { s.busy = false; s.err = e.message; paintRoutes(); });
		}
		function makeRoute() {
			var p = pr(), s = p.send; if (!s || !s.preview) { return; }
			s.busy = true; s.err = ''; paintRoutes();
			var r = p.routes[p.sel];
			api('plan/create', { method: 'POST', body: { token: s.preview.token } }).then(function (res) { s.busy = false; s.step = 'done'; s.result = res; if (r) { r.sent = res; } toast(res.message || 'Planned in Doorhangers.'); paintRoutes(); loadSends(); }, function (e) { s.busy = false; s.err = e.message; paintRoutes(); });
		}
		function loadSends() {
			if (!S.planCfg || !S.planCfg.send) { return; }
			api('plan/sends').then(function (r) {
				var box = root.querySelector('#zrp-psends'); if (!box) { return; }
				var ss = (r.sends || []).slice(0, 6);
				box.innerHTML = ss.length ? '<div class="zrp-card"><h2>Sent to Doorhangers</h2>' + ss.map(function (x) { return '<p class="zrp-help">' + esc(String(x.at).slice(0, 16)) + ' UTC · ' + esc(x.name) + ' · ' + (+x.houses) + ' houses' + (x.date ? ' · ' + esc(dayText(x.date)) : '') + (x.by ? ' · sent by ' + esc(x.by) : '') + (x.for && x.for !== x.by ? ' for ' + esc(x.for) : '') + '</p>'; }).join('') + '</div>' : '';
			}, function () {});
		}
		/* a launch's options (Doorhangers' button, the ask bar, later the chat's "Open in Route Planner"; NOTE-102626):
		   area | zip, date | when, count, go. The server re-checks all of it. */
		function applyLaunch(o) {
			o = o || {}; var q = pr().req;
			if (o.zip && /^\d{5}$/.test(String(o.zip))) { q.area = String(o.zip); }
			if (o.area && typeof o.area === 'string') { q.area = o.area.slice(0, 80); }
			if (o.date && /^\d{4}-\d{2}-\d{2}$/.test(String(o.date))) { q.date = String(o.date); } else if (o.when) { q.date = whenToDate(o.when); }
			if (o.count && +o.count >= 1 && +o.count <= 5) { q.count = Math.round(+o.count); }
			return !!(o.go && q.area);
		}
		function openPlan(o) {
			var go = applyLaunch(o);
			if (S.survey && moving()) { return; }
			S.tab = 'plan'; render();
			if (go && S.planCfg) { runPlan(); }
		}

		window.__zrpOpen = function (mode, o) {
			if (mode === 'survey' || S.survey) { if (!S.survey) { S.tab = 'survey'; render(); } return; }
			if (S.planCfg) { openPlan(o); }
		};
		window.__zrpAttach = function (r) { root = r; if (S.map) { try { S.map.remove(); } catch (e) {} S.map = null; } render(); };
		window.addEventListener('pagehide', function () { var sv = S.survey; try { if (sv && sv.part && sv.part.recorder && sv.part.recorder.state === 'recording') { sv.part.recorder.requestData(); } } catch (e) {} });
		render();
		api('boot').then(function (b) {
			S.booted = true; S.vocab = b.vocab || []; S.pending = b.pending || []; S.retracted = b.retracted || []; S.drives = b.drives || []; S.audio = b.audio || [];
			S.readback = !!b.readback; S.snap = b.snap ? 1 : 0; S.record = !!b.record; S.reads = b.reads ? 1 : 0; S.codesReady = b.codes ? 1 : 0;
			S.planCfg = b.plan && P ? b.plan : null;
			if (S.planCfg) { P.configure({ negative: S.planCfg.negative || [] }); }
			if (PENDING_MODE === 'survey' || !S.planCfg) { S.tab = 'survey'; }
			else { S.tab = 'plan'; var goNow = applyLaunch(PENDING_OPTS); }
			render(); if (S.planCfg && S.tab === 'plan' && goNow) { runPlan(); } flushQueue(); uploadAll();
			if (S.audio.some(function (a) { return ['ready', 'submitted', 'reading'].indexOf(a.status) !== -1; })) { refreshAudio(); }
		}, function (e) { S.err = 'The Route Planner could not load: ' + e.message; render(); });
	}
	if (document.getElementById('zrp-app')) { start(); }
	document.addEventListener('zdz_widgets_rendered', start);
	document.addEventListener('DOMContentLoaded', start);
})();
