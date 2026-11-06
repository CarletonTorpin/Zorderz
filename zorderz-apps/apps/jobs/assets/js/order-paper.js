/**
 * Zorderz Jobs - a material order printed on the supplier's own form.
 *
 * The server decides what goes where (ZJOB_Order_Paper::layout()); this file only draws it onto the blank with pdf-lib,
 * which is loaded the first time somebody fills a form. The blank comes through the same checked endpoint and is
 * compared with the hash its map was measured on before anything is drawn. The spreadsheet is made on the server and
 * is a plain download link.
 *
 * window.ZJOBOrderPaper = { pdf( orderId ) -> Promise<{ url, name, problems }>, xlsxUrl( orderId ), fill( PDFLib, bytes, layout ) }
 *
 * @package Zorderz\Jobs
 */
(function () {
	'use strict';

	function cfg() { return window.zjobProjects || {}; }

	function post(action, data) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', cfg().nonce || '');
		Object.keys(data || {}).forEach(function (k) { body.append(k, data[k] == null ? '' : data[k]); });
		return fetch(cfg().ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); });
	}

	function getUrl(op, orderId) {
		return String(cfg().ajaxurl || '') + '?action=zjob_order_paper&op=' + encodeURIComponent(op) +
			'&order_id=' + encodeURIComponent(orderId) + '&nonce=' + encodeURIComponent(cfg().nonce || '');
	}

	/* Latin-1 only (the standard fonts): smart quotes and the like become plain; anything else unprintable becomes "?". */
	function plain(s) {
		return String(s == null ? '' : s).replace(/[‘’′]/g, "'").replace(/[“”″]/g, '"')
			.replace(/[–—]/g, '-').replace(/×/g, 'x').replace(/·/g, '-').replace(/…/g, '...')
			.replace(/[^\x20-\x7E -ÿ\n]/g, '?');
	}

	function wrap(text, font, size, width) {
		var out = [];
		String(text).split('\n').forEach(function (para) {
			var line = '';
			para.split(/\s+/).forEach(function (word) {
				var tryLine = line ? line + ' ' + word : word;
				if (font.widthOfTextAtSize(tryLine, size) <= width || !line) { line = tryLine; } else { out.push(line); line = word; }
			});
			out.push(line);
		});
		return out;
	}

	/* One piece of text in its box. Boxes are top-left points; pdf-lib's y runs up from the bottom. Text shrinks to fit
	   (never below 5pt, then it is cut); notes wrap. */
	function drawText(PDFLib, page, H, t, font, base) {
		var b = t.box, pad = 2, w = Math.max(4, b[2] - b[0] - 2 * pad), hgt = b[3] - b[1];
		var text = plain(t.text);
		var color = t.mark === 'draft' ? PDFLib.rgb(0.75, 0.1, 0.1) : PDFLib.rgb(0, 0, 0.35);
		if (t.wrap) {
			var size = Math.min(base, 10), lines = [];
			for (; size >= 5; size -= 0.5) {
				lines = wrap(text, font, size, w);
				if (lines.length * size * 1.2 <= hgt - pad) { break; }
			}
			var maxLines = Math.max(1, Math.floor((hgt - pad) / (size * 1.2)));
			lines.slice(0, maxLines).forEach(function (ln, i) {
				page.drawText(ln, { x: b[0] + pad, y: H - b[1] - pad - size * (i + 1) * 1.2 + size * 0.2, size: size, font: font, color: color });
			});
			return;
		}
		text = text.replace(/\s*\n\s*/g, ' ');
		var sz = Math.min(t.mark === 'draft' ? 11 : base, Math.max(5, hgt - 3));
		while (sz > 5 && font.widthOfTextAtSize(text, sz) > w) { sz -= 0.25; }
		while (text.length > 1 && font.widthOfTextAtSize(text, sz) > w) { text = text.slice(0, -1); }
		var tw = font.widthOfTextAtSize(text, sz);
		var x = t.align === 'center' ? b[0] + (b[2] - b[0] - tw) / 2 : b[0] + pad;
		var y = H - b[3] + (hgt - sz * 0.72) / 2;
		page.drawText(text, { x: x, y: y, size: sz, font: font, color: color });
	}

	/* Draw a layout onto a blank: one copy of the blank's first page per page of the layout. Returns the PDF's bytes. */
	function fill(PDFLib, bytes, layout) {
		return PDFLib.PDFDocument.load(bytes).then(function (blank) {
			return PDFLib.PDFDocument.create().then(function (doc) {
				var n = Math.max(1, (layout.pages || []).length);
				var idx = [];
				for (var i = 0; i < n; i++) { idx.push(0); }
				return doc.copyPages(blank, idx).then(function (pages) {
					return Promise.all([doc.embedFont(PDFLib.StandardFonts.Helvetica), doc.embedFont(PDFLib.StandardFonts.HelveticaBold)]).then(function (fonts) {
						pages.forEach(function (page, pi) {
							doc.addPage(page);
							var H = page.getHeight();
							((layout.pages || [])[pi] || []).forEach(function (t) {
								drawText(PDFLib, page, H, t, t.mark === 'draft' || t.text === 'X' ? fonts[1] : fonts[0], layout.size || 9);
							});
						});
						doc.setTitle(layout.name || 'Order');
						doc.setProducer('Zorderz');
						return doc.save();
					});
				});
			});
		});
	}

	var libPromise = null;
	function loadLib() {
		if (window.PDFLib) { return Promise.resolve(window.PDFLib); }
		if (libPromise) { return libPromise; }
		libPromise = new Promise(function (ok, no) {
			var s = document.createElement('script');
			s.src = cfg().pdfLib || '';
			s.onload = function () { window.PDFLib ? ok(window.PDFLib) : no(new Error('The PDF tool did not load.')); };
			s.onerror = function () { libPromise = null; no(new Error('The PDF tool could not be loaded. Check the connection and try again.')); };
			document.head.appendChild(s);
		});
		return libPromise;
	}

	function hex(buf) {
		return Array.prototype.map.call(new Uint8Array(buf), function (b) { return (b < 16 ? '0' : '') + b.toString(16); }).join('');
	}

	function pdf(orderId) {
		return post('zjob_order_paper', { op: 'fill', order_id: orderId }).then(function (res) {
			if (!res || !res.success) { throw new Error((res && res.data && res.data.message) || 'The form could not be filled.'); }
			var layout = res.data;
			return Promise.all([loadLib(), fetch(getUrl('blank', orderId), { credentials: 'same-origin' }).then(function (r) {
				if (!r.ok) { throw new Error('The blank form could not be loaded.'); }
				return r.arrayBuffer();
			})]).then(function (got) {
				var lib = got[0], bytes = got[1];
				var check = (window.crypto && window.crypto.subtle && window.crypto.subtle.digest)
					? window.crypto.subtle.digest('SHA-256', bytes).then(function (h) {
						if (hex(h) !== String(layout.sha256 || '')) { throw new Error('The blank form is not the one its positions were measured on. It was not filled.'); }
					})
					: Promise.resolve();
				return check.then(function () { return fill(lib, bytes, layout); });
			}).then(function (out) {
				var url = URL.createObjectURL(new Blob([out], { type: 'application/pdf' }));
				return { url: url, name: (layout.name || 'order') + '.pdf', problems: layout.problems || [] };
			});
		});
	}

	window.ZJOBOrderPaper = { pdf: pdf, xlsxUrl: function (id) { return getUrl('xlsx', id); }, fill: fill };
})();
