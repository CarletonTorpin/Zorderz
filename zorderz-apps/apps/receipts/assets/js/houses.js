/**
 * Zorderz Receipts - "Which house?" in the photo picker.
 *
 * The server places every photo against this customer's house (ZRCPT_Houses) and sends, per set, a status, how many
 * photos need a person, and the houses to choose from (a letter and a street line; never a coordinate). This module
 * draws that under each set: a one-tap "This house / House B / Neither" filing for a set that needs a person, and an
 * "Include anyway" choice for a set taken away from the address. The server checks every photo again at generate.
 *
 * window.ZRCPTHouses = { setHtml( s ), wire( el, reload ), vouched() }
 *
 * @package Zorderz\Receipts
 */
(function () {
	'use strict';

	var vouch = {};

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function needing(s) {
		return (s.photos || []).filter(function (p) {
			return p.place && ['which_house', 'near_other', 'job_unverified'].indexOf(p.place.status) !== -1;
		}).map(function (p) { return p.place_media_id; }).filter(Boolean);
	}

	function setHtml(s) {
		if (!s || !s.house_status) { return ''; }
		var out = '';
		if (s.house_status === 'which_house' && (s.houses || []).length) {
			var ids = needing(s);
			var why = (s.photos || []).map(function (p) { return p.place && p.place.why; }).filter(Boolean)[0] || '';
			out += '<div class="zrcpt-wh" data-wh-sid="' + esc(s.id) + '"><strong>Which house? ' + esc(s.needs_count) + ' photo(s) could be of another house.</strong>' +
				(why ? '<div class="zrcpt-wh-why">' + esc(why) + '</div>' : '') + '<div class="zrcpt-wh-btns">' +
				(s.houses || []).map(function (h) {
					return '<button type="button" class="zrcpt-wh-btn" data-wh-file="' + esc(h.key) + '" data-wh-label="' + esc(h.label) + '" data-wh-addr="' + esc(h.address) +
						'" data-wh-ids="' + esc(ids.join(',')) + '">' + esc((h.is_target ? 'This house' : 'House ' + h.letter) + (h.address ? ': ' + h.address : '')) + '</button>';
				}).join('') +
				'<button type="button" class="zrcpt-wh-btn" data-wh-file="none" data-wh-label="" data-wh-addr="" data-wh-ids="' + esc(ids.join(',')) + '">Neither</button></div></div>';
		} else if (s.house_status === 'off_site') {
			var off = (s.photos || []).filter(function (p) { return p.place && p.place.status === 'off_site'; }).map(function (p) { return p.place_media_id; });
			var on = off.length && off.every(function (m) { return vouch[m]; });
			out += '<div class="zrcpt-wh"><label><input type="checkbox" data-wh-vouch="' + esc(off.join(',')) + '"' + (on ? ' checked' : '') + '> ' +
				'Include anyway: these were taken away from the address, and no other house with work that day is near.</label></div>';
		} else if (s.house_status === 'filed_other') {
			out += '<div class="zrcpt-wh zrcpt-wh-note">Filed to another house.</div>';
		}
		return out;
	}

	function post(data) {
		var cfg = window.zrcptWidgetData || {};
		var fd = new FormData();
		fd.append('action', 'zrcpt_file_photos');
		fd.append('nonce', cfg.nonce || '');
		Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(cfg.ajaxurl || '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
	}

	function wire(el, reload) {
		if (!el || el._whWired) { return; }
		el._whWired = true;
		el.addEventListener('click', function (e) {
			var b = e.target.closest('[data-wh-file]');
			if (!b) { return; }
			e.stopPropagation();
			e.preventDefault();
			var ids = String(b.getAttribute('data-wh-ids') || '').split(',').filter(Boolean).map(Number);
			if (!ids.length) { return; }
			b.disabled = true;
			post({ media_ids: JSON.stringify(ids), house_key: b.getAttribute('data-wh-file'), house_label: b.getAttribute('data-wh-label') || '', house_address: b.getAttribute('data-wh-addr') || '' })
				.then(function (res) {
					if (!res || !res.success) {
						b.disabled = false;
						window.alert((res && res.data && res.data.message) || 'That choice could not be saved. Try again.');
						return;
					}
					if (typeof reload === 'function') { reload(); }
				}).catch(function () { b.disabled = false; });
		});
		el.addEventListener('change', function (e) {
			var c = e.target.closest('[data-wh-vouch]');
			if (!c) { return; }
			e.stopPropagation();
			String(c.getAttribute('data-wh-vouch') || '').split(',').filter(Boolean).forEach(function (m) {
				if (c.checked) { vouch[m] = true; } else { delete vouch[m]; }
			});
		});
	}

	window.ZRCPTHouses = {
		setHtml: setHtml,
		wire: wire,
		vouched: function () { return Object.keys(vouch).map(Number); },
		reset: function () { vouch = {}; }
	};
})();
