/**
 * TS Knowledge Vault — Document Scanner
 *
 * Mobile-friendly document scanner that:
 * 1. Opens camera (rear, high-res) via getUserMedia
 * 2. User captures photos of document pages
 * 3. Client-side image enhancement (Color, B&W Document, Enhanced)
 * 4. Multi-page with thumbnails, reorder, delete
 * 5. Generates multi-page PDF via jsPDF
 * 6. Returns PDF blob for vault upload pipeline
 *
 * Dependencies (lazy-loaded from CDN):
 * - jsPDF (multi-page PDF generation)
 *
 * @package TSKnowledgeVault
 * @since   1.2.0
 */
(function () {
	'use strict';

	// ── CDN URLs for lazy-loaded deps (with fallbacks) ──
	var JSPDF_CDNS = [
		'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
		'https://unpkg.com/jspdf@2.5.1/dist/jspdf.umd.min.js',
		'https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js'
	];

	// ── State ──
	var pages = [];          // Array of { original: canvas, processed: canvas, filter: string }
	var currentFilter = 'document'; // 'color', 'document', 'enhanced'
	var cameraStream = null;
	var videoEl = null;
	var isOpen = false;
	var resolvePromise = null;
	var rejectPromise = null;

	// ── Lazy dependency loader ──
	function loadScript(url) {
		return new Promise(function (resolve, reject) {
			if (document.querySelector('script[src="' + url + '"]')) { resolve(); return; }
			var s = document.createElement('script');
			s.src = url; s.async = true;
			s.onload = resolve;
			s.onerror = function () { reject(new Error('Failed to load ' + url)); };
			document.head.appendChild(s);
		});
	}

	function ensureDeps() {
		if (window.jspdf) return Promise.resolve();

		// Try each CDN in order until one works.
		function tryLoad(urls, idx) {
			if (idx >= urls.length) return Promise.reject(new Error('Failed to load jsPDF from all CDNs'));
			return loadScript(urls[idx]).then(function () {
				// Verify it actually loaded the global.
				if (window.jspdf) return Promise.resolve();
				// Script loaded but no global — try next CDN.
				return tryLoad(urls, idx + 1);
			}).catch(function () {
				return tryLoad(urls, idx + 1);
			});
		}

		return tryLoad(JSPDF_CDNS, 0);
	}

	// ── Camera helpers ──
	function isMobile() {
		return /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
	}

	function startCamera(video) {
		var constraints = {
			video: {
				facingMode: { ideal: 'environment' }, // Rear camera
				width: { ideal: 1920 },
				height: { ideal: 1080 }
			},
			audio: false
		};
		return navigator.mediaDevices.getUserMedia(constraints).then(function (stream) {
			cameraStream = stream;
			video.srcObject = stream;
			return new Promise(function (resolve) {
				video.onloadedmetadata = function () { video.play(); resolve(); };
			});
		});
	}

	function stopCamera() {
		if (cameraStream) {
			cameraStream.getTracks().forEach(function (t) { t.stop(); });
			cameraStream = null;
		}
	}

	function captureFrame(video) {
		var canvas = document.createElement('canvas');
		canvas.width = video.videoWidth;
		canvas.height = video.videoHeight;
		var ctx = canvas.getContext('2d');
		ctx.drawImage(video, 0, 0);
		return canvas;
	}

	// ── Image processing filters ──
	function rotateCanvas(source, degrees) {
		var w = source.width, h = source.height;
		var out = document.createElement('canvas');
		if (degrees === 90 || degrees === -90 || degrees === 270) {
			out.width = h; out.height = w;
		} else {
			out.width = w; out.height = h;
		}
		var ctx = out.getContext('2d');
		ctx.translate(out.width / 2, out.height / 2);
		ctx.rotate(degrees * Math.PI / 180);
		ctx.drawImage(source, -w / 2, -h / 2);
		return out;
	}

	function applyFilter(sourceCanvas, filter) {
		var w = sourceCanvas.width, h = sourceCanvas.height;
		var out = document.createElement('canvas');
		out.width = w; out.height = h;
		var ctx = out.getContext('2d');
		ctx.drawImage(sourceCanvas, 0, 0);

		if (filter === 'color') return out; // No processing

		var imageData = ctx.getImageData(0, 0, w, h);
		var data = imageData.data;

		if (filter === 'document') {
			// B&W document mode: grayscale → adaptive contrast → threshold
			// Step 1: Convert to grayscale
			for (var i = 0; i < data.length; i += 4) {
				var gray = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
				data[i] = data[i + 1] = data[i + 2] = gray;
			}
			// Step 2: Auto-level (stretch histogram)
			var min = 255, max = 0;
			for (var i = 0; i < data.length; i += 4) {
				if (data[i] < min) min = data[i];
				if (data[i] > max) max = data[i];
			}
			var range = max - min || 1;
			for (var i = 0; i < data.length; i += 4) {
				var val = Math.round(((data[i] - min) / range) * 255);
				data[i] = data[i + 1] = data[i + 2] = val;
			}
			// Step 3: Adaptive threshold (block-based)
			ctx.putImageData(imageData, 0, 0);
			imageData = ctx.getImageData(0, 0, w, h);
			data = imageData.data;
			var blockSize = Math.max(15, Math.round(Math.min(w, h) / 30) | 1);
			if (blockSize % 2 === 0) blockSize++;
			var half = Math.floor(blockSize / 2);
			// Build integral image for fast local mean
			var gray2 = new Float32Array(w * h);
			for (var i = 0; i < data.length; i += 4) gray2[i / 4] = data[i];
			var integral = new Float64Array((w + 1) * (h + 1));
			for (var y = 0; y < h; y++) {
				var rowSum = 0;
				for (var x = 0; x < w; x++) {
					rowSum += gray2[y * w + x];
					integral[(y + 1) * (w + 1) + (x + 1)] = integral[y * (w + 1) + (x + 1)] + rowSum;
				}
			}
			for (var y = 0; y < h; y++) {
				for (var x = 0; x < w; x++) {
					var x1 = Math.max(0, x - half), y1 = Math.max(0, y - half);
					var x2 = Math.min(w - 1, x + half), y2 = Math.min(h - 1, y + half);
					var count = (x2 - x1 + 1) * (y2 - y1 + 1);
					var sum = integral[(y2 + 1) * (w + 1) + (x2 + 1)]
					        - integral[y1 * (w + 1) + (x2 + 1)]
					        - integral[(y2 + 1) * (w + 1) + x1]
					        + integral[y1 * (w + 1) + x1];
					var mean = sum / count;
					var val = gray2[y * w + x];
					var pixel = (val > mean - 12) ? 255 : 0; // C = 12 offset
					var idx = (y * w + x) * 4;
					data[idx] = data[idx + 1] = data[idx + 2] = pixel;
				}
			}
		} else if (filter === 'enhanced') {
			// High contrast color: boost saturation + contrast
			for (var i = 0; i < data.length; i += 4) {
				// Increase contrast
				data[i]     = Math.min(255, Math.max(0, ((data[i] - 128) * 1.5) + 128));
				data[i + 1] = Math.min(255, Math.max(0, ((data[i + 1] - 128) * 1.5) + 128));
				data[i + 2] = Math.min(255, Math.max(0, ((data[i + 2] - 128) * 1.5) + 128));
			}
			// Sharpen using unsharp mask approximation
			// (simplified: just boost edges with a convolution-lite approach)
		}

		ctx.putImageData(imageData, 0, 0);
		return out;
	}

	// ── PDF generation ──
	function generatePDF() {
		if (!window.jspdf) return Promise.reject(new Error('jsPDF not loaded'));
		var jsPDF = window.jspdf.jsPDF;

		return new Promise(function (resolve) {
			var pdf = null;

			pages.forEach(function (page, i) {
				var canvas = page.processed;
				var imgData = canvas.toDataURL('image/jpeg', 0.85);

				// Calculate dimensions to fit A4 (210 x 297 mm) with margins
				var margin = 5; // mm
				var pageW = 210 - (margin * 2);
				var pageH = 297 - (margin * 2);
				var imgRatio = canvas.width / canvas.height;
				var fitW, fitH;

				if (imgRatio > (pageW / pageH)) {
					// Wider than tall — fit to width
					fitW = pageW;
					fitH = pageW / imgRatio;
				} else {
					// Taller than wide — fit to height
					fitH = pageH;
					fitW = pageH * imgRatio;
				}

				// Center on page
				var x = margin + (pageW - fitW) / 2;
				var y = margin + (pageH - fitH) / 2;

				if (i === 0) {
					pdf = new jsPDF('p', 'mm', 'a4');
				} else {
					pdf.addPage();
				}
				pdf.addImage(imgData, 'JPEG', x, y, fitW, fitH, '', 'FAST');
			});

			var blob = pdf.output('blob');
			resolve(blob);
		});
	}

	// ── UI Builder ──
	function buildUI() {
		var overlay = document.createElement('div');
		overlay.id = 'zkv-scanner-overlay';
		overlay.className = 'zkv-scanner-overlay';
		overlay.innerHTML =
			'<div class="zkv-scanner-container">' +
				// Header
				'<div class="zkv-scanner-header">' +
					'<button class="zkv-scanner-back" id="zkv-scanner-back"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg></button>' +
					'<span class="zkv-scanner-title">Scan Document</span>' +
					'<span class="zkv-scanner-page-count" id="zkv-scanner-page-count"></span>' +
				'</div>' +
				// Camera view
				'<div class="zkv-scanner-camera" id="zkv-scanner-camera">' +
					'<video id="zkv-scanner-video" playsinline autoplay muted></video>' +
					'<div class="zkv-scanner-guide">' +
						'<div class="zkv-scanner-guide-corners"></div>' +
					'</div>' +
					'<div class="zkv-scanner-camera-hint" id="zkv-scanner-camera-hint">Position document within the frame</div>' +
				'</div>' +
				// File input fallback (hidden)
				'<input type="file" accept="image/*" capture="environment" id="zkv-scanner-file-input" multiple style="display:none;" />' +
				// Controls bar (camera mode)
				'<div class="zkv-scanner-controls" id="zkv-scanner-controls">' +
					'<button class="zkv-scanner-gallery-btn" id="zkv-scanner-gallery-btn" title="Choose from gallery"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></button>' +
					'<button class="zkv-scanner-capture-btn" id="zkv-scanner-capture-btn"><div class="zkv-scanner-shutter"></div></button>' +
					'<button class="zkv-scanner-done-btn" id="zkv-scanner-done-btn" style="visibility:hidden;">Done<span id="zkv-scanner-done-count"></span></button>' +
				'</div>' +
				// Preview / edit view (hidden initially)
				'<div class="zkv-scanner-preview" id="zkv-scanner-preview" style="display:none;">' +
					'<div class="zkv-scanner-preview-image" id="zkv-scanner-preview-image"></div>' +
					'<div class="zkv-scanner-filters">' +
						'<button class="zkv-scanner-rotate-btn" id="zkv-scanner-rotate-left" title="Rotate left"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg></button>' +
						'<button class="zkv-scanner-filter active" data-filter="document">Document</button>' +
						'<button class="zkv-scanner-filter" data-filter="color">Color</button>' +
						'<button class="zkv-scanner-filter" data-filter="enhanced">Enhanced</button>' +
						'<button class="zkv-scanner-rotate-btn" id="zkv-scanner-rotate-right" title="Rotate right"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.13-9.36L23 10"/></svg></button>' +
					'</div>' +
					'<div class="zkv-scanner-preview-actions">' +
						'<button class="zkv-scanner-retake-btn" id="zkv-scanner-retake-btn">Retake</button>' +
						'<button class="zkv-scanner-addpage-btn" id="zkv-scanner-accept-btn"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Add Page</button>' +
						'<button class="zkv-scanner-done-preview-btn" id="zkv-scanner-done-preview-btn">Done</button>' +
					'</div>' +
				'</div>' +
				// Pages review (hidden initially)
				'<div class="zkv-scanner-pages" id="zkv-scanner-pages" style="display:none;">' +
					'<div class="zkv-scanner-pages-grid" id="zkv-scanner-pages-grid"></div>' +
					'<div class="zkv-scanner-pages-actions">' +
						'<button class="zkv-scanner-add-more" id="zkv-scanner-add-more"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Add Page</button>' +
						'<button class="zkv-scanner-save-btn" id="zkv-scanner-save-btn">Create PDF</button>' +
					'</div>' +
				'</div>' +
			'</div>';

		document.body.appendChild(overlay);
		return overlay;
	}

	function updatePageCount() {
		var el = document.getElementById('zkv-scanner-page-count');
		var doneBtn = document.getElementById('zkv-scanner-done-btn');
		var doneCount = document.getElementById('zkv-scanner-done-count');
		if (el) el.textContent = pages.length > 0 ? pages.length + ' page' + (pages.length !== 1 ? 's' : '') : '';
		if (doneBtn) doneBtn.style.visibility = pages.length > 0 ? 'visible' : 'hidden';
		if (doneCount) doneCount.textContent = pages.length > 0 ? ' (' + pages.length + ')' : '';
	}

	function renderPagesGrid() {
		var grid = document.getElementById('zkv-scanner-pages-grid');
		if (!grid) return;
		grid.innerHTML = '';
		pages.forEach(function (page, i) {
			var thumb = document.createElement('div');
			thumb.className = 'zkv-scanner-page-thumb';
			thumb.innerHTML =
				'<div class="zkv-scanner-thumb-img"></div>' +
				'<div class="zkv-scanner-thumb-label">Page ' + (i + 1) + '</div>' +
				'<button class="zkv-scanner-thumb-delete" data-idx="' + i + '"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';
			// Draw thumbnail
			var imgDiv = thumb.querySelector('.zkv-scanner-thumb-img');
			var thumbCanvas = document.createElement('canvas');
			var scale = 120 / page.processed.width;
			thumbCanvas.width = 120;
			thumbCanvas.height = Math.round(page.processed.height * scale);
			thumbCanvas.getContext('2d').drawImage(page.processed, 0, 0, thumbCanvas.width, thumbCanvas.height);
			thumbCanvas.style.width = '100%';
			thumbCanvas.style.height = '100%';
			thumbCanvas.style.objectFit = 'contain';
			imgDiv.appendChild(thumbCanvas);
			grid.appendChild(thumb);

			// Delete handler
			thumb.querySelector('.zkv-scanner-thumb-delete').addEventListener('click', function (e) {
				e.stopPropagation();
				pages.splice(i, 1);
				renderPagesGrid();
				updatePageCount();
				if (pages.length === 0) {
					showView('camera');
				}
			});
		});
	}

	function showView(view) {
		var camera = document.getElementById('zkv-scanner-camera');
		var controls = document.getElementById('zkv-scanner-controls');
		var preview = document.getElementById('zkv-scanner-preview');
		var pagesView = document.getElementById('zkv-scanner-pages');
		var hint = document.getElementById('zkv-scanner-camera-hint');

		camera.style.display = view === 'camera' ? '' : 'none';
		controls.style.display = view === 'camera' ? '' : 'none';
		preview.style.display = view === 'preview' ? '' : 'none';
		pagesView.style.display = view === 'pages' ? '' : 'none';
		if (hint) hint.style.display = view === 'camera' ? '' : 'none';

		if (view === 'pages') {
			renderPagesGrid();
		}
	}

	// ── Preview a captured frame ──
	var previewOriginal = null;

	function showPreview(canvas) {
		previewOriginal = canvas;
		currentFilter = 'document'; // Default to document mode
		var processed = applyFilter(canvas, currentFilter);

		var container = document.getElementById('zkv-scanner-preview-image');
		container.innerHTML = '';
		var displayCanvas = document.createElement('canvas');
		displayCanvas.width = processed.width;
		displayCanvas.height = processed.height;
		displayCanvas.getContext('2d').drawImage(processed, 0, 0);
		displayCanvas.style.maxWidth = '100%';
		displayCanvas.style.maxHeight = '100%';
		displayCanvas.style.objectFit = 'contain';
		container.appendChild(displayCanvas);

		// Set active filter button
		document.querySelectorAll('.zkv-scanner-filter').forEach(function (btn) {
			btn.classList.toggle('active', btn.getAttribute('data-filter') === currentFilter);
		});

		showView('preview');
	}

	function applyCurrentFilter() {
		if (!previewOriginal) return;
		var processed = applyFilter(previewOriginal, currentFilter);
		var container = document.getElementById('zkv-scanner-preview-image');
		container.innerHTML = '';
		var displayCanvas = document.createElement('canvas');
		displayCanvas.width = processed.width;
		displayCanvas.height = processed.height;
		displayCanvas.getContext('2d').drawImage(processed, 0, 0);
		displayCanvas.style.maxWidth = '100%';
		displayCanvas.style.maxHeight = '100%';
		displayCanvas.style.objectFit = 'contain';
		container.appendChild(displayCanvas);
	}

	// ── Wire up events ──
	function wireEvents(overlay) {
		// Back button
		document.getElementById('zkv-scanner-back').addEventListener('click', function () {
			closeScanner(null);
		});

		// Capture button
		document.getElementById('zkv-scanner-capture-btn').addEventListener('click', function () {
			if (!videoEl) return;
			var frame = captureFrame(videoEl);
			showPreview(frame);
		});

		// Gallery button — use file input
		var fileInput = document.getElementById('zkv-scanner-file-input');
		document.getElementById('zkv-scanner-gallery-btn').addEventListener('click', function () {
			fileInput.click();
		});
		fileInput.addEventListener('change', function () {
			if (!fileInput.files || !fileInput.files.length) return;
			// Process each selected file
			Array.from(fileInput.files).forEach(function (file, idx) {
				var reader = new FileReader();
				reader.onload = function (e) {
					var img = new Image();
					img.onload = function () {
						var canvas = document.createElement('canvas');
						canvas.width = img.naturalWidth;
						canvas.height = img.naturalHeight;
						canvas.getContext('2d').drawImage(img, 0, 0);
						if (idx === 0 && fileInput.files.length === 1) {
							// Single image — show preview
							showPreview(canvas);
						} else {
							// Multiple images — add directly as pages
							pages.push({
								original: canvas,
								processed: applyFilter(canvas, currentFilter),
								filter: currentFilter
							});
							updatePageCount();
							if (idx === fileInput.files.length - 1) {
								showView('pages');
							}
						}
					};
					img.src = e.target.result;
				};
				reader.readAsDataURL(file);
			});
			fileInput.value = '';
		});

		// Filter buttons
		document.querySelectorAll('.zkv-scanner-filter').forEach(function (btn) {
			btn.addEventListener('click', function () {
				currentFilter = btn.getAttribute('data-filter');
				document.querySelectorAll('.zkv-scanner-filter').forEach(function (b) {
					b.classList.toggle('active', b === btn);
				});
				applyCurrentFilter();
			});
		});

		// Rotation buttons
		document.getElementById('zkv-scanner-rotate-left').addEventListener('click', function () {
			if (previewOriginal) { previewOriginal = rotateCanvas(previewOriginal, -90); applyCurrentFilter(); }
		});
		document.getElementById('zkv-scanner-rotate-right').addEventListener('click', function () {
			if (previewOriginal) { previewOriginal = rotateCanvas(previewOriginal, 90); applyCurrentFilter(); }
		});

		// Retake
		document.getElementById('zkv-scanner-retake-btn').addEventListener('click', function () {
			previewOriginal = null;
			showView('camera');
		});

		// Accept page
		document.getElementById('zkv-scanner-accept-btn').addEventListener('click', function () {
			if (!previewOriginal) return;
			pages.push({
				original: previewOriginal,
				processed: applyFilter(previewOriginal, currentFilter),
				filter: currentFilter
			});
			previewOriginal = null;
			updatePageCount();
			showView('camera'); // Back to camera for next page
		});

		// Done from preview — accept this page and finish.
		document.getElementById('zkv-scanner-done-preview-btn').addEventListener('click', function () {
			if (!previewOriginal) return;
			var btn = document.getElementById('zkv-scanner-done-preview-btn');
			// Add this page.
			pages.push({
				original: previewOriginal,
				processed: applyFilter(previewOriginal, currentFilter),
				filter: currentFilter
			});
			previewOriginal = null;
			updatePageCount();

			// Generate PDF immediately.
			btn.disabled = true;
			btn.textContent = 'Creating PDF...';
			ensureDeps().then(function () {
				return generatePDF();
			}).then(function (blob) {
				closeScanner(blob);
			}).catch(function (err) {
				alert('PDF generation failed: ' + err.message);
				btn.disabled = false;
				btn.textContent = 'Done';
				showView('pages'); // Fall back to review
			});
		});

		// Done button (from camera view → go to pages review)
		document.getElementById('zkv-scanner-done-btn').addEventListener('click', function () {
			if (pages.length > 0) {
				showView('pages');
			}
		});

		// Add more pages (from review)
		document.getElementById('zkv-scanner-add-more').addEventListener('click', function () {
			showView('camera');
		});

		// Create PDF
		document.getElementById('zkv-scanner-save-btn').addEventListener('click', function () {
			if (pages.length === 0) return;
			var btn = document.getElementById('zkv-scanner-save-btn');
			btn.disabled = true;
			btn.textContent = 'Loading PDF library...';

			// Ensure jsPDF is loaded before generating.
			ensureDeps().then(function () {
				btn.textContent = 'Creating PDF...';
				return generatePDF();
			}).then(function (blob) {
				closeScanner(blob);
			}).catch(function (err) {
				console.error('ZKV Scanner PDF error:', err);
				alert('PDF generation failed: ' + err.message + '\n\nTry again — the library may still be loading.');
				btn.disabled = false;
				btn.textContent = 'Create PDF';
			});
		});

		// Overlay click to close
		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) closeScanner(null);
		});
	}

	function closeScanner(result) {
		stopCamera();
		var overlay = document.getElementById('zkv-scanner-overlay');
		if (overlay) overlay.remove();
		isOpen = false;
		pages = [];
		previewOriginal = null;
		if (result && resolvePromise) {
			resolvePromise(result);
		} else if (rejectPromise) {
			rejectPromise(new Error('Scanner cancelled'));
		}
		resolvePromise = null;
		rejectPromise = null;
	}

	// ── Public API ──
	window.ZKVScanner = {
		/**
		 * Open the document scanner.
		 * @returns {Promise<Blob>} Resolves with PDF blob, rejects if cancelled.
		 */
		open: function () {
			if (isOpen) return Promise.reject(new Error('Scanner already open'));
			isOpen = true;
			pages = [];

			return new Promise(function (resolve, reject) {
				resolvePromise = resolve;
				rejectPromise = reject;

				// Load deps, build UI, start camera
				ensureDeps().then(function () {
					var overlay = buildUI();
					wireEvents(overlay);
					videoEl = document.getElementById('zkv-scanner-video');

					// Try camera, fall back to file-input-only mode
					if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
						startCamera(videoEl).then(function () {
							updatePageCount();
						}).catch(function (err) {
							console.warn('ZKV Scanner: Camera unavailable, using gallery mode.', err.message);
							// Hide camera, show hint to use gallery
							document.getElementById('zkv-scanner-camera').innerHTML =
								'<div class="zkv-scanner-no-camera">' +
									'<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>' +
									'<p>Camera not available</p>' +
									'<p class="zkv-scanner-no-camera-hint">Use the gallery button below to select photos</p>' +
								'</div>';
							updatePageCount();
						});
					} else {
						document.getElementById('zkv-scanner-camera').innerHTML =
							'<div class="zkv-scanner-no-camera">' +
								'<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>' +
								'<p>Camera not supported in this browser</p>' +
								'<p class="zkv-scanner-no-camera-hint">Use the gallery button to select photos</p>' +
							'</div>';
						updatePageCount();
					}
				}).catch(function (err) {
					isOpen = false;
					reject(err);
				});
			});
		},

		isOpen: function () { return isOpen; }
	};
})();
