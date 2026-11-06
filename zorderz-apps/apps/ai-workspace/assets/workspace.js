/* Ai Workspace: a short thread with the business's assistant, in its card or on its admin screen. Plain DOM, no
   libraries. Every word from the server goes in as text (textContent), never as HTML. Who may see or do what is
   decided on the server; this file draws what it is sent. Starts when its root exists: at once, on the theme's
   `zdz_widgets_rendered`, or at DOMContentLoaded. */
(function () {
	'use strict';
	var C = window.ZAW_CONFIG || {};
	var app = null;
	var state = { conv: '', turns: [], live: null, timer: null, hubTimer: null, sending: false };

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text !== undefined && text !== null) { n.textContent = String(text); }
		return n;
	}

	function url(path) {
		var root = String(C.root || '');
		// a site on plain links serves the API at ?rest_route=/…, so the route's own query joins with & (one ? only)
		if (/[?&]rest_route=/.test(root)) { path = path.replace('?', '&'); }
		return root + path;
	}

	function api(path, opts) {
		opts = opts || {};
		var init = { method: opts.method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce } };
		if (opts.body) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
		return fetch(url(path), init).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				if (!r.ok) { var e = new Error((j && j.message) || ('HTTP ' + r.status)); e.data = j; throw e; }
				return j;
			});
		});
	}

	function keep(conv) {
		try { window.localStorage.setItem('zaw_conv', conv || ''); } catch (e) { /* a private window: fine */ }
	}

	function money(v, est) {
		if (v === null || v === undefined) { return 'cost not kept (usage records declined)'; }
		return (est ? 'about ' : '') + '$' + Number(v).toFixed(4);
	}

	function stateWord(t) {
		var w = { sending: 'sending', queued: 'waiting for the hub', pulled: 'the hub has it', running: 'working',
			cancelling: 'stopping', done: 'done', error: 'stopped with an error', cancelled: 'stopped',
			failed: 'failed', expired: 'too late: not sent', lost: 'no answer from the hub', refused: 'not sent' };
		return w[t.state] || t.state;
	}

	/* ---------------------------------------------------------------- the switch (no grant yet) */
	function renderGate() {
		app.textContent = '';
		if (!C.ready) {
			app.appendChild(el('div', 'zaw-box zaw-warn', 'Not ready: the Ai Workspace needs ' + C.missing + ' first.'));
			return;
		}
		if (C.taken) {
			app.appendChild(el('p', 'zaw-box', 'The Ai Workspace is already on for as many people as the settings allow. Someone who holds it turns it off first.'));
			return;
		}
		if (!C.owner) {
			app.appendChild(el('p', 'zaw-box', 'Only an owner-level account can turn the Ai Workspace on, for itself.'));
			return;
		}
		var box = el('div', 'zaw-box');
		box.appendChild(el('p', '', 'The Ai Workspace is off for your account. Turning it on gives it to you.'));
		var b = el('button', 'button button-primary zaw-btn', 'Turn the Ai Workspace on for me');
		b.type = 'button';
		b.addEventListener('click', function () {
			b.disabled = true;
			api('grant', { method: 'POST', body: { on: true } }).then(function (r) {
				C.granted = !!r.on; start(true);
			}).catch(function (e) { b.disabled = false; box.appendChild(el('p', 'zaw-err', e.message)); });
		});
		box.appendChild(b);
		app.appendChild(box);
	}

	/* ---------------------------------------------------------------- the page */
	var thread, input, sendBtn, stopBtn, convSel, moneyLine, runnerLine, msgLine;

	function layout() {
		app.textContent = '';
		var cols = el('div', 'zaw-cols');
		var main = el('div', 'zaw-main');
		var side = el('div', 'zaw-side');
		var bar = el('div', 'zaw-bar');
		convSel = el('select', 'zaw-conv');
		convSel.setAttribute('aria-label', 'Conversation');
		convSel.addEventListener('change', function () { setConv(convSel.value); });
		var newBtn = el('button', 'button zaw-btn', 'New conversation');
		newBtn.type = 'button';
		newBtn.addEventListener('click', function () { setConv(''); });
		bar.appendChild(convSel); bar.appendChild(newBtn);
		main.appendChild(bar);
		thread = el('div', 'zaw-thread');
		main.appendChild(thread);
		var form = el('form', 'zaw-form');
		input = el('textarea', 'zaw-input');
		input.rows = 3;
		input.placeholder = 'Ask the assistant…';
		input.setAttribute('aria-label', 'Message');
		sendBtn = el('button', 'button button-primary zaw-btn', 'Send');
		sendBtn.type = 'submit';
		stopBtn = el('button', 'button zaw-btn zaw-stop', 'Stop');
		stopBtn.type = 'button';
		stopBtn.hidden = true;
		stopBtn.addEventListener('click', stop);
		msgLine = el('p', 'zaw-msg');
		msgLine.setAttribute('role', 'status');
		form.appendChild(input);
		var acts = el('div', 'zaw-acts');
		acts.appendChild(sendBtn); acts.appendChild(stopBtn);
		form.appendChild(acts);
		form.appendChild(msgLine);
		form.addEventListener('submit', function (ev) { ev.preventDefault(); send(); });
		input.addEventListener('keydown', function (ev) {
			if (ev.key === 'Enter' && (ev.metaKey || ev.ctrlKey)) { ev.preventDefault(); send(); }
		});
		main.appendChild(form);
		side.appendChild(el('h2', 'zaw-side-h', 'The hub'));
		runnerLine = el('p', 'zaw-mute', 'Runner on the hub: checking…');
		moneyLine = el('p', 'zaw-mute', '');
		side.appendChild(runnerLine); side.appendChild(moneyLine);
		if (C.cap) { side.appendChild(el('p', 'zaw-mute', 'Each answer is held to $' + Number(C.cap).toFixed(2) + '.')); }
		var off = el('button', 'button-link zaw-off', 'Turn the Ai Workspace off for me');
		off.type = 'button';
		off.addEventListener('click', function () {
			if (!off.getAttribute('data-sure')) {          // a second press, no dialog
				off.setAttribute('data-sure', '1');
				off.textContent = 'Press again to turn it off for your account';
				setTimeout(function () { off.removeAttribute('data-sure'); off.textContent = 'Turn the Ai Workspace off for me'; }, 5000);
				return;
			}
			api('grant', { method: 'POST', body: { on: false } }).then(function () {
				C.granted = false; C.taken = false; clearInterval(state.hubTimer); clearTimeout(state.timer); renderGate();
			}).catch(function (e) { msgLine.textContent = e.message; });
		});
		side.appendChild(off);
		cols.appendChild(main); cols.appendChild(side);
		app.appendChild(cols);
	}

	function setConv(key) {
		state.conv = key || '';
		keep(state.conv);
		state.turns = [];
		loadThread();
	}

	function loadConvs() {
		return api('thread').then(function (r) {
			convSel.textContent = '';
			var o = el('option', '', 'New conversation'); o.value = ''; convSel.appendChild(o);
			(r.conversations || []).forEach(function (c) {
				var n = Number(c.turns) || 0;
				var x = el('option', '', 'Conversation of ' + c.updated + ' UTC (' + n + ' turn' + (n === 1 ? '' : 's') + ')');
				x.value = c.conv_key;
				convSel.appendChild(x);
			});
			convSel.value = state.conv;
			if (convSel.value !== state.conv) { state.conv = ''; convSel.value = ''; }
		});
	}

	function loadThread() {
		if (!state.conv) { state.turns = []; draw(); return Promise.resolve(); }
		return api('thread?conv=' + encodeURIComponent(state.conv)).then(function (r) {
			state.turns = r.turns || [];
			draw();
			var live = state.turns.filter(function (t) { return !t.final; })[0];
			if (live) { follow(live.turn_id); }
		});
	}

	function draw() {
		thread.textContent = '';
		if (!state.turns.length) {
			thread.appendChild(el('p', 'zaw-mute', 'No messages yet. Each message is one turn; the assistant remembers the conversation on the hub, so you can answer a plan with "go".'));
		}
		state.turns.forEach(function (t) {
			var card = el('div', 'zaw-turn zaw-' + t.state);
			card.appendChild(el('div', 'zaw-q', t.purged ? '(older than 7 days: the words were cleared)' : t.question));
			if (t.progress && t.progress.length) {
				var det = el('details', 'zaw-prog');
				det.open = !t.final;
				det.appendChild(el('summary', '', t.final ? 'What it did (' + t.progress.length + ')' : 'Working: ' + t.progress[t.progress.length - 1].t));
				var ul = el('ul');
				t.progress.forEach(function (p) { ul.appendChild(el('li', p.k === 'note' ? 'zaw-note' : '', p.t)); });
				det.appendChild(ul);
				card.appendChild(det);
			}
			if (t.answer) { card.appendChild(el('div', 'zaw-a', t.answer)); }
			var meta = stateWord(t);
			if (t.final) {
				meta += ' · ' + money(t.cost, t.est);
				if (t.model) { meta += ' · ' + t.model + (t.fallback ? ' (the main model was busy)' : ''); }
				if (t.error) { meta += ' · ' + t.error; }
			}
			card.appendChild(el('div', 'zaw-meta', meta));
			thread.appendChild(card);
		});
		var live = state.turns.some(function (t) { return !t.final; });
		stopBtn.hidden = !live;
		sendBtn.disabled = live || state.sending;
		thread.scrollTop = thread.scrollHeight;
	}

	function upsert(t) {
		if (!t || !t.turn_id) { return; }
		for (var i = 0; i < state.turns.length; i++) {
			if (state.turns[i].turn_id === t.turn_id) { state.turns[i] = t; draw(); return; }
		}
		state.turns.push(t); draw();
	}

	function follow(id) {
		clearTimeout(state.timer);
		state.live = id;
		var tick = function () {
			api('turn/' + id).then(function (t) {
				upsert(t);
				if (!t.final) { state.timer = setTimeout(tick, 2500); } else { state.live = null; loadConvs(); }
			}).catch(function (e) {
				msgLine.textContent = e.message;
				state.timer = setTimeout(tick, 8000);
			});
		};
		state.timer = setTimeout(tick, 1500);
	}

	function send() {
		var text = (input.value || '').trim();
		if (!text || state.sending) { return; }
		state.sending = true; sendBtn.disabled = true; msgLine.textContent = '';
		api('turn', { method: 'POST', body: { text: text, conv: state.conv } }).then(function (t) {
			input.value = '';
			if (t.conv !== state.conv) { state.conv = t.conv; keep(t.conv); }
			if (t.runner === 'down') { msgLine.textContent = 'Sent. The assistant\'s runner on the hub is not running right now: the turn waits up to 4 minutes for it.'; }
			upsert(t);
			follow(t.turn_id);
			loadConvs();
		}).catch(function (e) {
			msgLine.textContent = e.message;
		}).then(function () { state.sending = false; draw(); });
	}

	function stop() {
		if (!state.live) { return; }
		stopBtn.disabled = true;
		api('turn/' + state.live + '/cancel', { method: 'POST' }).then(function (t) { upsert(t); })
			.catch(function (e) { msgLine.textContent = e.message; })
			.then(function () { stopBtn.disabled = false; });
	}

	/* ---------------------------------------------------------------- the side panel */
	function hubTick() {
		api('hub').then(function (r) {
			if (!r.ok) { runnerLine.textContent = r.say || 'The hub did not answer.'; moneyLine.textContent = ''; return; }
			runnerLine.textContent = 'Runner on the hub: ' + (r.runner === 'up' ? 'running' : 'NOT running (turns wait for it)') + '.';
			var m = r.money || {};
			var line = 'The assistant this month: $' + Number(m.engine_usd || 0).toFixed(2);
			if (m.cap_usd !== null && m.cap_usd !== undefined) { line += ' of the hub\'s $' + Number(m.cap_usd).toFixed(0); }
			moneyLine.textContent = line + '.';
		}).catch(function (e) { runnerLine.textContent = e.message; });
	}

	function start(again) {
		app = document.getElementById('zaw-app');
		if (!app || (app.getAttribute('data-zaw-init') && !again)) { return; }
		app.setAttribute('data-zaw-init', '1');
		try { state.conv = window.localStorage.getItem('zaw_conv') || ''; } catch (e) { state.conv = ''; }
		if (!C.ready || !C.granted) { renderGate(); return; }
		layout();
		loadConvs().then(loadThread).catch(function (e) { msgLine.textContent = e.message; });
		hubTick();
		clearInterval(state.hubTimer);
		state.hubTimer = setInterval(function () { if (!document.hidden) { hubTick(); } }, 15000);
	}

	if (document.getElementById('zaw-app')) { start(); }
	document.addEventListener('zdz_widgets_rendered', function () { start(); });
	document.addEventListener('DOMContentLoaded', function () { start(); });
}());
