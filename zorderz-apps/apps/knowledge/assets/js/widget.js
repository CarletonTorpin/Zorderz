(function () {
	'use strict';
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else { boot(); }

	function boot() {
		var vault = document.getElementById('zkv-vault');
		if (!vault || typeof window.zkvData === 'undefined') return;
		vault.style.display = '';

		var D = window.zkvData;
		var MAX_BATCH = 10;
		var state = {
			searchTimer: null, page: 1, total: 0,
			mode: 'file',           // 'file' or 'paste'
			selectedFiles: [],      // array of File objects (1 = single mode, 2+ = batch)
			tempKey: '',            // for single-file preanalyze
			dupOverride: false,
			batchMode: false
		};

		// ── DOM refs ──
		var searchInput  = document.getElementById('zkv-search-input');
		var docList      = document.getElementById('zkv-doc-list');
		var loading      = document.getElementById('zkv-loading');
		var statusBar    = document.getElementById('zkv-status');
		var filtersEl    = document.getElementById('zkv-filters');
		var uploadBtn    = document.getElementById('zkv-upload-btn');
		var uploadModal  = document.getElementById('zkv-upload-modal');
		var fileInput    = document.getElementById('zkv-file-input');
		var dropzone     = document.getElementById('zkv-dropzone');
		var filePreview  = document.getElementById('zkv-file-preview');
		var fileName     = document.getElementById('zkv-file-name');
		var submitBtn    = document.getElementById('zkv-submit-upload');
		var processingEl = document.getElementById('zkv-processing');
		var processingMsg= document.getElementById('zkv-processing-msg');
		var titleInput   = document.getElementById('zkv-title');
		var catSelect    = document.getElementById('zkv-category');
		var descInput    = document.getElementById('zkv-description');
		var contextInput = document.getElementById('zkv-user-context');
		var detailModal  = document.getElementById('zkv-detail-modal');
		var detailTitle  = document.getElementById('zkv-detail-title');
		var detailBody   = document.getElementById('zkv-detail-body');
		var fileModeEl   = document.getElementById('zkv-file-mode');
		var pasteModeEl  = document.getElementById('zkv-paste-mode');
		var pasteInput   = document.getElementById('zkv-paste-input');
		var modeFileBtn  = document.getElementById('zkv-mode-file');
		var modePasteBtn = document.getElementById('zkv-mode-paste');
		var dupWarning   = document.getElementById('zkv-duplicate-warning');
		var dupMsg       = document.getElementById('zkv-duplicate-msg');
		var dupDismiss   = document.getElementById('zkv-duplicate-dismiss');
		var batchList    = document.getElementById('zkv-batch-list');
		var batchItems   = document.getElementById('zkv-batch-items');
		var batchCount   = document.getElementById('zkv-batch-count');
		var batchClear   = document.getElementById('zkv-batch-clear');
		var modalTitle   = document.getElementById('zkv-modal-title');
		var titleGroup   = titleInput ? titleInput.closest('.zkv-form-group') : null;
		var descGroup    = descInput ? descInput.closest('.zkv-form-group') : null;
		// Keep private - chosen before the file, so nothing is read by the AI first.
		var privBox      = document.getElementById('zkv-private');
		var privPick     = document.getElementById('zkv-private-pick');
		var privWarn     = document.getElementById('zkv-private-warn');
		function isPriv() { return !!(privBox && privBox.checked && (!privPick || privPick.style.display !== 'none')); }
		if (privBox) privBox.addEventListener('change', function () {
			// A file the AI already read for a title suggestion: say so (it can be picked again).
			if (privWarn) privWarn.style.display = (privBox.checked && state.tempKey && state.preAI) ? '' : 'none';
			if (processingMsg && privBox.checked) { /* nothing to undo: the next pick is not sent to the AI */ }
		});

		// ── Helpers ──
		function ajax(action, data) {
			var fd = new FormData();
			fd.append('action', action);
			fd.append('nonce', D.nonce);
			if (data) Object.keys(data).forEach(function(k) { fd.append(k, data[k]); });
			return fetch(D.ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' }).then(function(r){return r.json();});
		}
		function esc(s) { if(!s)return''; var d=document.createElement('div'); d.textContent=String(s); return d.innerHTML; }
		function fmtDate(s) { if(!s)return''; var d=new Date(s.replace(' ','T')); var m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']; return m[d.getMonth()]+' '+d.getDate()+', '+d.getFullYear(); }
		function fmtSize(b) { b=parseInt(b)||0; if(b<1024)return b+' B'; if(b<1048576)return Math.round(b/1024)+' KB'; return (b/1048576).toFixed(1)+' MB'; }
		function mimeIcon(m) { if(!m)return'file-text'; if(m.indexOf('pdf')!==-1)return'file-type'; if(m.indexOf('image')!==-1)return'image'; if(m.indexOf('sheet')!==-1||m.indexOf('excel')!==-1)return'table'; return'file-text'; }
		function icons() { if(typeof window.refreshIcons==='function'){window.refreshIcons();}else if(window.lucide&&typeof window.lucide.createIcons==='function'){window.lucide.createIcons();} }

		// ── Mode toggle ──
		var modeScanBtn  = document.getElementById('zkv-mode-scan');
		var allModeBtns  = [modeFileBtn, modeScanBtn, modePasteBtn].filter(Boolean);

		function setMode(mode) {
			state.mode = mode;
			allModeBtns.forEach(function(b){ b.classList.remove('active'); });
			if (mode === 'file')  { modeFileBtn.classList.add('active'); fileModeEl.style.display=''; pasteModeEl.style.display='none'; }
			if (mode === 'paste') { modePasteBtn.classList.add('active'); fileModeEl.style.display='none'; pasteModeEl.style.display=''; }
			if (mode === 'scan')  { if(modeScanBtn) modeScanBtn.classList.add('active'); }
			updateSubmitState();
		}

		modeFileBtn.addEventListener('click', function() { setMode('file'); });
		modePasteBtn.addEventListener('click', function() { setMode('paste'); });
		if (modeScanBtn) {
			modeScanBtn.addEventListener('click', function() {
				// Open the scanner — it returns a PDF blob
				if (typeof window.ZKVScanner === 'undefined') { alert('Scanner not loaded yet.'); return; }
				setMode('scan');
				uploadModal.style.display = 'none'; // Hide upload modal while scanning

				window.ZKVScanner.open().then(function(pdfBlob) {
					// Scanner returned a PDF — create a File object and feed it into the upload pipeline
					var filename = 'scan-' + new Date().toISOString().slice(0,10) + '.pdf';
					var file = new File([pdfBlob], filename, { type: 'application/pdf' });

					// Switch back to file mode with this scanned PDF
					setMode('file');
					uploadModal.style.display = ''; // Re-show upload modal
					state.selectedFiles = [file];
					state.batchMode = false;
					batchList.style.display = 'none';
					if(titleGroup) titleGroup.style.display = '';
					if(descGroup) descGroup.style.display = '';
					if(modalTitle) modalTitle.textContent = 'Upload Scanned Document';

					// Run preanalyze on the scanned PDF (AI vision will read it)
					pickSingleFile(file);
				}).catch(function() {
					// User cancelled — go back to upload modal
					setMode('file');
					uploadModal.style.display = '';
				});
			});
		}

		if (pasteInput) pasteInput.addEventListener('input', function() { updateSubmitState(); });

		function updateSubmitState() {
			if (state.mode === 'paste') {
				submitBtn.disabled = !(pasteInput && pasteInput.value.trim().length > 10);
				submitBtn.innerHTML = '<i data-lucide="sparkles"></i> Upload &amp; Index';
			} else if (state.batchMode) {
				submitBtn.disabled = state.selectedFiles.length === 0;
				submitBtn.innerHTML = '<i data-lucide="sparkles"></i> Upload ' + state.selectedFiles.length + ' File' + (state.selectedFiles.length !== 1 ? 's' : '');
			} else {
				submitBtn.disabled = state.selectedFiles.length === 0 && !state.tempKey;
				submitBtn.innerHTML = '<i data-lucide="sparkles"></i> Upload &amp; Index';
			}
			icons();
		}

		// ── Duplicate warning ──
		if (dupDismiss) dupDismiss.addEventListener('click', function() { state.dupOverride = true; dupWarning.style.display = 'none'; });

		// ── Load categories ──
		ajax('zkv_get_categories').then(function(res) {
			if(!res.success) return;
			state.cats = res.data || [];   // for Rename / re-file in the detail view
			var html='<button class="zkv-filter-pill active" data-category="">All</button>';
			var opts='<option value="">General</option>';
			(res.data||[]).forEach(function(c) {
				html+='<button class="zkv-filter-pill" data-category="'+esc(c.slug)+'">'+esc(c.label)+'</button>';
				opts+='<option value="'+esc(c.slug)+'">'+esc(c.label)+'</option>';
			});
			filtersEl.innerHTML=html; catSelect.innerHTML=opts;
			// Add pricing filter pill (6B) — only for admins
			if (D.isAdmin) {
				var pricingPill = document.createElement('button');
				pricingPill.className = 'zkv-filter-pill';
				pricingPill.dataset.category = '__pricing';
				pricingPill.textContent = '💲 Pricing';
				filtersEl.appendChild(pricingPill);
			}
			filtersEl.querySelectorAll('.zkv-filter-pill').forEach(function(b){
				b.addEventListener('click',function(){ filtersEl.querySelectorAll('.zkv-filter-pill').forEach(function(x){x.classList.remove('active');}); b.classList.add('active'); state.currentCat=b.getAttribute('data-category'); loadDocs(); });
			});
		});

		// ── Load & render documents ──
		function loadDocs() {
			loading.style.display='';
			docList.querySelectorAll('.zkv-doc-card,.zkv-empty').forEach(function(el){el.remove();});
			var q = (searchInput.value||'').trim();
			if (q) {
				ajax('zkv_search',{query:q,category:state.currentCat||''}).then(function(res){
					loading.style.display='none'; renderDocs(res.success?res.data:[], true);
				});
			} else {
				ajax('zkv_list_documents',{page:state.page,per_page:20,category:state.currentCat||''}).then(function(res){
					loading.style.display='none'; if(!res.success) return;
					state.total=res.data.total; renderDocs(res.data.documents, false);
				});
			}
		}

		function renderDocs(docs, isSearch) {
			if(!docs||docs.length===0) {
				docList.innerHTML='<div class="zkv-empty">'+(isSearch?'No documents match.':'No documents yet. Tap Upload to add one!')+'</div>';
				statusBar.textContent=''; return;
			}
			var html='';
			docs.forEach(function(d){
				var id=d.document_id||d.id;
				var syn=d.synopsis||''; if(syn.length>120)syn=syn.substring(0,120)+'...';
				var badge='';
				if(d.status==='pending'||d.status==='processing') badge='<span class="zkv-badge zkv-badge-pending">Processing</span>';
				if(d.status==='encrypting') badge='<span class="zkv-badge zkv-badge-pending">Encrypting</span>';
				if(d.status==='failed') badge='<span class="zkv-badge zkv-badge-failed">Failed</span>';
				if(String(d.is_private)==='1') badge+=' <span class="zkv-badge zkv-badge-private" title="Never sent to AI; stored encrypted">Private</span>';
				html+='<div class="zkv-doc-card" data-id="'+id+'">'
					+'<div class="zkv-doc-icon"><i data-lucide="'+mimeIcon(d.mime_type)+'"></i></div>'
					+'<div class="zkv-doc-info">'
					+'<div class="zkv-doc-title">'+esc(d.title)+' '+badge+'</div>'
					+(syn?'<div class="zkv-doc-synopsis">'+esc(syn)+'</div>':'')
					+'<div class="zkv-doc-meta"><span>'+esc(d.uploader_name||'')+'</span><span>'+fmtDate(d.created_at)+'</span><span>'+fmtSize(d.file_size)+'</span></div>'
					+'</div>'
					+'<div class="zkv-doc-actions">'
					+'<a class="zkv-btn-icon" href="'+esc(d.file_url)+'" target="_blank" title="Open" onclick="event.stopPropagation()"><i data-lucide="external-link"></i></a>'
					+'</div></div>';
			});
			docList.insertAdjacentHTML('beforeend',html);
			statusBar.textContent=isSearch?docs.length+' result'+(docs.length!==1?'s':''):'Showing '+docs.length+' of '+state.total;
			icons();
			docList.querySelectorAll('.zkv-doc-card').forEach(function(card){
				card.addEventListener('click',function(){ openDetail(parseInt(card.getAttribute('data-id'))); });
			});
		}

		function openDetail(id) {
			detailTitle.textContent='Loading...';
			detailBody.innerHTML='<div class="zkv-loading"><div class="zkv-spinner"></div></div>';
			detailModal.style.display='';
			ajax('zkv_get_document',{document_id:id}).then(function(res){
				if(!res.success){detailBody.innerHTML='<p>Could not load.</p>';return;}
				var d=res.data;
				detailTitle.textContent=d.title;
				var facts=''; try{var fa=JSON.parse(d.key_facts||'[]');if(fa.length)facts='<div class="zkv-detail-section"><h4>Key Facts</h4><ul>'+fa.map(function(f){return'<li>'+esc(f)+'</li>';}).join('')+'</ul></div>';}catch(e){}
				var ents=''; try{var eo=JSON.parse(d.key_entities||'{}');var ep=[];Object.keys(eo).forEach(function(k){if(Array.isArray(eo[k])&&eo[k].length)ep.push('<strong>'+esc(k)+':</strong> '+eo[k].map(esc).join(', '));});if(ep.length)ents='<div class="zkv-detail-section"><h4>Entities</h4><p>'+ep.join('<br>')+'</p></div>';}catch(e){}
				var canDel=D.isAdmin||(d.uploaded_by==D.userId);
				var ctxHtml = '';
				if (d.user_context && d.user_context.trim()) {
					ctxHtml = '<div class="zkv-detail-context" id="zkv-detail-ctx-display"><div class="zkv-detail-context-label">Context <button class="zkv-btn-text" id="zkv-edit-ctx-btn" style="font-size:11px;margin-left:8px;">Edit</button></div>' + esc(d.user_context) + '</div>';
				} else {
					ctxHtml = '<div class="zkv-detail-context" id="zkv-detail-ctx-display" style="opacity:.5;"><div class="zkv-detail-context-label">Context <button class="zkv-btn-text" id="zkv-edit-ctx-btn" style="font-size:11px;margin-left:8px;">Add</button></div><em>No context added</em></div>';
				}
				// Hidden edit form
				ctxHtml += '<div id="zkv-detail-ctx-edit" style="display:none;margin:12px 0;">'
					+ '<textarea id="zkv-ctx-edit-input" rows="3" style="width:100%;padding:8px;border:1px dashed var(--sys-brand,#2C5F8A);border-radius:6px;background:rgba(44,95,138,.04);font-size:13px;resize:vertical;">' + esc(d.user_context || '') + '</textarea>'
					+ '<div style="display:flex;gap:8px;margin-top:6px;">'
					+ '<button class="zkv-btn zkv-btn-primary" id="zkv-ctx-save-btn" style="font-size:12px;padding:6px 14px;">Save Context</button>'
					+ '<button class="zkv-btn-text" id="zkv-ctx-cancel-btn" style="font-size:12px;">Cancel</button>'
					+ '</div></div>';
				// Rename / re-file - the uploader or an admin. What is saved here is theirs: a re-index keeps it.
				var renameHtml = canDel ? '<div class="zkv-detail-row" id="zkv-rename-row"><button class="zkv-btn-text" id="zkv-rename-btn" style="font-size:12px;">Rename or change category</button></div>'
					+ '<div id="zkv-rename-edit" style="display:none;margin:12px 0;">'
					+ '<label style="display:block;font-size:12px;">Title<input type="text" id="zkv-rename-title" maxlength="200" value="'+esc(d.title||'')+'" style="width:100%;box-sizing:border-box;padding:8px;margin-top:4px;font-size:14px;"></label>'
					+ '<label style="display:block;font-size:12px;margin-top:8px;">Category<select id="zkv-rename-cat" style="width:100%;padding:8px;margin-top:4px;font-size:14px;"><option value="">General</option>'
					+ (state.cats||[]).map(function(c){ return '<option value="'+esc(c.slug)+'"'+(String(c.id)===String(d.category_id)?' selected':'')+'>'+esc(c.label)+'</option>'; }).join('')
					+ '</select></label>'
					+ '<div style="display:flex;gap:8px;margin-top:8px;align-items:center;"><button class="zkv-btn zkv-btn-primary" id="zkv-rename-save" style="font-size:12px;padding:6px 14px;">Save</button>'
					+ '<button class="zkv-btn-text" id="zkv-rename-cancel" style="font-size:12px;">Cancel</button><span id="zkv-rename-msg" style="font-size:12px;"></span></div>'
					+ '<p style="font-size:11px;opacity:.7;margin:6px 0 0;">Kept as you set it, even when the document is re-indexed. Its link does not change.</p></div>' : '';
				var isPrivDoc = String(d.is_private)==='1';
				detailBody.innerHTML='<div class="zkv-detail-section"><p>'+(isPrivDoc?'Private documents are not summarized.':esc(d.synopsis||'No summary.'))+'</p></div>'
					+renameHtml
					+ctxHtml
					+'<div class="zkv-detail-meta"><span><strong>Type:</strong> '+esc(d.document_type||'general')+'</span><span><strong>By:</strong> '+esc(d.uploader_name)+'</span><span><strong>Date:</strong> '+fmtDate(d.created_at)+'</span><span><strong>Size:</strong> '+fmtSize(d.file_size)+'</span></div>'
					+ents+facts
					+'<div class="zkv-detail-actions"><a class="zkv-btn zkv-btn-primary" href="'+esc(d.file_url)+'" target="_blank"><i data-lucide="external-link"></i> Open File</a>'
					+((D.isAdmin&&!isPrivDoc)?'<button class="zkv-btn zkv-btn-secondary" id="zkv-reindex-btn" data-id="'+d.id+'"><i data-lucide="refresh-cw"></i> Re-index</button>':'')
					+(canDel?'<button class="zkv-btn zkv-btn-danger" id="zkv-del-btn" data-id="'+d.id+'"><i data-lucide="trash-2"></i> Delete</button>':'')
					+'</div>'
					+((D.isAdmin&&!d.is_transcript&&!isPrivDoc)?'<div class="zkv-detail-row zkv-pricing-toggle"><label class="zkv-toggle-label"><span class="zkv-toggle-icon">'+(d.is_pricing_authority?'✓':'○')+'</span> <strong>Pricing Authority</strong> <button class="zkv-btn zkv-btn-sm '+(d.is_pricing_authority?'zkv-btn-active':'zkv-btn-secondary')+'" id="zkv-pricing-toggle-btn" data-doc-id="'+d.id+'" data-current="'+(d.is_pricing_authority?'1':'0')+'">'+(d.is_pricing_authority?'Designated ✓':'Not Designated')+'</button></label><span class="zkv-hint">Pricing authority documents are used by the Commission Calculator for cost-of-goods context.</span></div>':'')
					+(D.isAdmin?'<div class="zkv-detail-row"><span id="zkv-chunk-status" style="font-size:12px;color:#888;"></span></div>':'');
				// v1.5.0: Private-transcript banner + party share controls.
				if (d.is_transcript) { renderTranscriptDetail(d); }
				renderPrivateAndShare(d);
				icons();
				// Auto-check chunks for PDFs and trigger browser extraction if needed
				if (D.isAdmin && d.mime_type === 'application/pdf' && !isPrivDoc) {
					var chunkEl = document.getElementById('zkv-chunk-status');
					if (chunkEl && window._zkvCheckExtract) {
						chunkEl.textContent = 'Checking content chunks...';
						window._zkvCheckExtract(d.id, chunkEl);
					}
				}
				var reindex=document.getElementById('zkv-reindex-btn');
				if(reindex) reindex.addEventListener('click',function(){reindex.disabled=true;reindex.textContent='Re-indexing...';ajax('zkv_reindex',{document_id:d.id}).then(function(r){if(r.success){reindex.textContent='Checking chunks...';if(window._zkvCheckExtract)window._zkvCheckExtract(d.id,reindex);else{openDetail(d.id);loadDocs();}}else{alert('Re-index failed: '+(r.data||''));reindex.textContent='Re-index';reindex.disabled=false;}});});
				var del=document.getElementById('zkv-del-btn');
				if(del) del.addEventListener('click',function(){if(!confirm('Delete this document?'))return;ajax('zkv_delete_document',{document_id:d.id}).then(function(r){if(r.success){detailModal.style.display='none';loadDocs();}});});
				// Rename / re-file handlers (no browser dialogs).
				var rnBtn = document.getElementById('zkv-rename-btn'), rnEdit = document.getElementById('zkv-rename-edit');
				if (rnBtn) rnBtn.addEventListener('click', function(){ document.getElementById('zkv-rename-row').style.display='none'; rnEdit.style.display=''; document.getElementById('zkv-rename-title').focus(); });
				var rnCancel = document.getElementById('zkv-rename-cancel');
				if (rnCancel) rnCancel.addEventListener('click', function(){ rnEdit.style.display='none'; document.getElementById('zkv-rename-row').style.display=''; });
				var rnSave = document.getElementById('zkv-rename-save');
				if (rnSave) rnSave.addEventListener('click', function(){
					var t = document.getElementById('zkv-rename-title').value.trim(), msg = document.getElementById('zkv-rename-msg');
					if (!t) { msg.textContent='A title cannot be blank.'; return; }
					rnSave.disabled=true; msg.textContent='Saving...';
					ajax('zkv_update_details',{document_id:d.id,title:t,category:document.getElementById('zkv-rename-cat').value}).then(function(r){
						if (r.success) { openDetail(d.id); loadDocs(); }
						else { msg.textContent = (r.data||'That could not be saved.'); rnSave.disabled=false; }
					});
				});
				// Context edit handlers.
				var editCtxBtn = document.getElementById('zkv-edit-ctx-btn');
				var ctxDisplay = document.getElementById('zkv-detail-ctx-display');
				var ctxEdit    = document.getElementById('zkv-detail-ctx-edit');
				var ctxInput   = document.getElementById('zkv-ctx-edit-input');
				var ctxSave    = document.getElementById('zkv-ctx-save-btn');
				var ctxCancel  = document.getElementById('zkv-ctx-cancel-btn');
				if (editCtxBtn) editCtxBtn.addEventListener('click', function() {
					ctxDisplay.style.display='none'; ctxEdit.style.display='';
					ctxInput.focus();
				});
				if (ctxCancel) ctxCancel.addEventListener('click', function() {
					ctxDisplay.style.display=''; ctxEdit.style.display='none';
				});
				if (ctxSave) ctxSave.addEventListener('click', function() {
					ctxSave.disabled=true; ctxSave.textContent='Saving...';
					ajax('zkv_update_context',{document_id:d.id,user_context:ctxInput.value}).then(function(r) {
						if(r.success){ openDetail(d.id); } // Refresh detail
						else { alert('Save failed: '+(r.data||'')); ctxSave.disabled=false; ctxSave.textContent='Save Context'; }
					});
				});
				// Pricing toggle click handler (6B)
				var pricingBtn = document.getElementById('zkv-pricing-toggle-btn');
				if (pricingBtn) pricingBtn.addEventListener('click', function() {
					var btn = pricingBtn;
					var docId = btn.dataset.docId;
					btn.disabled = true;
					btn.textContent = 'Saving...';
					ajax('zkv_toggle_pricing',{document_id:docId}).then(function(resp) {
						if (resp.success) {
							var isNowPricing = resp.data.is_pricing_authority;
							btn.textContent = isNowPricing ? 'Designated ✓' : 'Not Designated';
							btn.className = 'zkv-btn zkv-btn-sm ' + (isNowPricing ? 'zkv-btn-active' : 'zkv-btn-secondary');
							btn.dataset.current = isNowPricing ? '1' : '0';
						} else {
							btn.textContent = 'Error';
						}
						btn.disabled = false;
					}).catch(function() { btn.textContent = 'Error'; btn.disabled = false; });
				});
			});
		}

		// ══════════════════════════════════════════════════════════
		//  Keep private, and Share outside. No browser dialogs: every
		//  question is asked in the panel. The server re-checks every rule.
		// ══════════════════════════════════════════════════════════
		function untilText(l) {
			if (l.state === 'stopped') return 'stopped';
			var utc = String(l.expires_at || '').replace(' ', 'T') + 'Z'; // stored in UTC
			if (l.state === 'expired') return 'expired ' + fmtDate(utc);
			return 'until ' + fmtDate(utc);
		}
		function linksHtml(d) {
			var ls = d.links || [];
			if (!ls.length) return '<p class="zkv-hint">No outside links yet.</p>';
			return ls.map(function (l) {
				return '<div class="zkv-out-link" data-id="' + l.id + '"><span class="zkv-out-who">' + esc(l.recipient || 'No name given') + '</span>'
					+ '<span class="zkv-out-meta">by ' + esc(l.by) + ' &middot; ' + esc(untilText(l)) + ' &middot; opened ' + (l.visits + l.downloads) + '&times;</span>'
					+ (l.state === 'live' && l.can_stop ? '<button class="zkv-btn-text zkv-out-stop" data-id="' + l.id + '">Stop sharing</button>' : '')
					+ '</div>';
			}).join('');
		}
		function renderPrivateAndShare(d) {
			var priv = String(d.is_private) === '1', html = '';
			if (priv) {
				html += '<div class="zkv-detail-section zkv-private-note"><strong>Private.</strong> Never sent to any AI service; stored encrypted. Found by its title and Context only.'
					+ (d.key_state === 'locked' ? ' <em>Locked: an administrator must enter the recovery code in Knowledge settings before it opens.</em>' : '') + '</div>';
			}
			if (String(d.can_set_private) === '1') {
				html += '<div class="zkv-detail-row" id="zkv-priv-row"><button class="zkv-btn-text" id="zkv-priv-ask">' + (priv ? 'Stop keeping private' : 'Keep private') + '</button></div>'
					+ '<div id="zkv-priv-confirm" class="zkv-inline-confirm" style="display:none;"><p>' + (priv
						? 'Stop keeping it private? It will be sent to the AI service to be indexed, and stored as an ordinary file.'
						: 'Keep it private? Its file is encrypted, its summary and searchable text are deleted, and the chat no longer sees it. It is found by its title and Context only.')
					+ '</p><button class="zkv-btn zkv-btn-primary" id="zkv-priv-yes">' + (priv ? 'Stop keeping private' : 'Keep private') + '</button> '
					+ '<button class="zkv-btn-text" id="zkv-priv-no">Cancel</button> <span class="zkv-hint" id="zkv-priv-msg"></span></div>';
			}
			var canOut = String(d.can_share_out) === '1';
			if (canOut || (d.links && d.links.length)) {
				var days = (d.link_days && d.link_days.length) ? d.link_days : [14, 30];
				html += '<div class="zkv-detail-section zkv-out-section"><h4>Share outside</h4>'
					+ '<p class="zkv-hint">A link for someone outside the company. It stops working after the days you choose, or when you press Stop sharing.</p>';
				if (canOut) {
					html += '<div class="zkv-out-form"><input type="text" id="zkv-out-who" maxlength="190" placeholder="Who it\'s for (only staff see this)" />'
						+ '<select id="zkv-out-days">' + days.map(function (n, i) { return '<option value="' + n + '"' + (i === 0 ? ' selected' : '') + '>' + n + ' days</option>'; }).join('') + '</select>'
						+ '<button class="zkv-btn zkv-btn-primary" id="zkv-out-make">Make link</button></div>'
						+ '<div id="zkv-out-result" class="zkv-out-result" style="display:none;"><input type="text" id="zkv-out-url" readonly />'
						+ '<button class="zkv-btn zkv-btn-secondary" id="zkv-out-copy">Copy</button><span class="zkv-hint" id="zkv-out-until"></span></div>';
				}
				html += '<span class="zkv-hint" id="zkv-out-msg"></span><div id="zkv-out-list">' + linksHtml(d) + '</div></div>';
			}
			if (!html) return;
			detailBody.insertAdjacentHTML('beforeend', html);
			var ask = document.getElementById('zkv-priv-ask'), conf = document.getElementById('zkv-priv-confirm');
			if (ask) ask.addEventListener('click', function () { conf.style.display = ''; ask.style.display = 'none'; });
			var no = document.getElementById('zkv-priv-no');
			if (no) no.addEventListener('click', function () { conf.style.display = 'none'; ask.style.display = ''; });
			var yes = document.getElementById('zkv-priv-yes');
			if (yes) yes.addEventListener('click', function () {
				var msg = document.getElementById('zkv-priv-msg');
				yes.disabled = true; msg.textContent = priv ? 'Decrypting...' : 'Encrypting...';
				ajax('zkv_set_private', { document_id: d.id, private: priv ? '0' : '1' }).then(function (r) {
					if (r.success) { openDetail(d.id); loadDocs(); }
					else { msg.textContent = r.data || 'That did not work.'; yes.disabled = false; }
				}).catch(function () { msg.textContent = 'That did not work.'; yes.disabled = false; });
			});
			function refreshLinks() {
				ajax('zkv_get_document', { document_id: d.id }).then(function (r) {
					if (r.success) { var el = document.getElementById('zkv-out-list'); if (el) { el.innerHTML = linksHtml(r.data); wireStops(); } }
				});
			}
			function wireStops() {
				detailBody.querySelectorAll('.zkv-out-stop').forEach(function (b) {
					b.addEventListener('click', function () {
						b.disabled = true; b.textContent = 'Stopping...';
						ajax('zkv_link_revoke', { link_id: b.getAttribute('data-id') }).then(function (r) {
							var m = document.getElementById('zkv-out-msg');
							if (r.success) { if (m) m.textContent = 'Stopped. The link no longer works.'; refreshLinks(); }
							else { if (m) m.textContent = r.data || 'Could not stop it.'; b.disabled = false; b.textContent = 'Stop sharing'; }
						});
					});
				});
			}
			wireStops();
			var make = document.getElementById('zkv-out-make');
			if (make) make.addEventListener('click', function () {
				var m = document.getElementById('zkv-out-msg');
				make.disabled = true; m.textContent = 'Making the link...';
				ajax('zkv_link_create', { document_id: d.id, recipient: document.getElementById('zkv-out-who').value, days: document.getElementById('zkv-out-days').value }).then(function (r) {
					make.disabled = false;
					if (!r.success) { m.textContent = r.data || 'Could not make a link.'; return; }
					m.textContent = 'Copy it now - for safety it is shown only this once.';
					document.getElementById('zkv-out-result').style.display = '';
					document.getElementById('zkv-out-url').value = r.data.url;
					document.getElementById('zkv-out-until').textContent = 'Works until ' + fmtDate(r.data.expires_at.replace(' ', 'T') + 'Z');
					refreshLinks();
				}).catch(function () { make.disabled = false; m.textContent = 'Could not make a link.'; });
			});
			var copy = document.getElementById('zkv-out-copy');
			if (copy) copy.addEventListener('click', function () {
				var u = document.getElementById('zkv-out-url');
				function done() { copy.textContent = 'Copied'; setTimeout(function () { copy.textContent = 'Copy'; }, 1500); }
				if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(u.value).then(done, function () { u.select(); }); }
				else { u.focus(); u.select(); }
			});
		}

		// The recovery code (WordPress administrators), and a nudge until it is written down.
		var privAdmin = document.getElementById('zkv-private-admin');
		function loadPrivateAdmin() {
			if (!privAdmin) return;
			if (D.wpAdmin !== '1') { privAdmin.style.display = 'none'; return; }
			ajax('zkv_private_status').then(function (r) {
				if (!r.success) { privAdmin.style.display = 'none'; return; }
				var st = r.data, el = document.getElementById('zkv-private-state');
				var show = document.getElementById('zkv-private-show'), unlock = document.getElementById('zkv-private-unlock-box');
				show.style.display = 'none'; unlock.style.display = 'none';
				if (st.state === 'none') el.textContent = 'No Private documents yet. The key is made with the first one.';
				else if (st.state === 'unavailable') el.textContent = 'This server cannot encrypt (PHP sodium is missing), so documents cannot be made Private.';
				else if (st.state === 'locked') { el.textContent = 'Locked: the site\'s security keys changed. Enter the recovery code to open Private documents again.'; unlock.style.display = ''; }
				else { el.innerHTML = esc(st.count + ' Private document' + (st.count === 1 ? '' : 's') + '. Key ready. ') + (st.code_saved ? 'Recovery code written down.' : '<strong>Write down the recovery code.</strong>'); show.style.display = ''; }
			});
		}
		if (privAdmin) {
			var pShow = document.getElementById('zkv-private-show');
			if (pShow) pShow.addEventListener('click', function () {
				pShow.disabled = true;
				ajax('zkv_private_code').then(function (r) {
					pShow.disabled = false;
					if (!r.success) { document.getElementById('zkv-private-state').textContent = r.data || 'Could not show it.'; return; }
					document.getElementById('zkv-private-code').textContent = r.data.code;
					document.getElementById('zkv-private-code-box').style.display = ''; pShow.style.display = 'none';
				});
			});
			var pSaved = document.getElementById('zkv-private-saved');
			if (pSaved) pSaved.addEventListener('click', function () {
				ajax('zkv_private_saved').then(function () {
					document.getElementById('zkv-private-code').textContent = '';
					document.getElementById('zkv-private-code-box').style.display = 'none';
					var nag = document.getElementById('zkv-private-nag'); if (nag) nag.remove();
					loadPrivateAdmin();
				});
			});
			var pUnlock = document.getElementById('zkv-private-unlock');
			if (pUnlock) pUnlock.addEventListener('click', function () {
				var msg = document.getElementById('zkv-private-unlock-msg');
				pUnlock.disabled = true; msg.textContent = 'Checking...';
				ajax('zkv_private_unlock', { code: document.getElementById('zkv-private-unlock-code').value }).then(function (r) {
					pUnlock.disabled = false;
					if (r.success) { msg.textContent = 'Unlocked.'; document.getElementById('zkv-private-unlock-code').value = ''; loadPrivateAdmin(); }
					else msg.textContent = r.data || 'That code did not work.';
				});
			});
			if (D.wpAdmin === '1') {
				ajax('zkv_private_status').then(function (r) {
					if (!r.success || r.data.state !== 'ready' || r.data.code_saved || !r.data.count) return;
					var nag = document.createElement('div');
					nag.id = 'zkv-private-nag'; nag.className = 'zkv-private-nag';
					nag.textContent = 'Write down the recovery code for Private documents: Settings (the gear), then Show the recovery code.';
					var bar = vault.querySelector('.zkv-search-bar'); if (bar) bar.parentNode.insertBefore(nag, bar);
				});
			}
		}

		// ── Search ──
		searchInput.addEventListener('input',function(){clearTimeout(state.searchTimer);state.searchTimer=setTimeout(loadDocs,300);});

		// ── Upload modal ──
		uploadBtn.addEventListener('click',function(){resetForm();uploadModal.style.display='';});
		document.getElementById('zkv-modal-close').addEventListener('click',function(){discardTemp();uploadModal.style.display='none';});
		document.getElementById('zkv-cancel-upload').addEventListener('click',function(){discardTemp();uploadModal.style.display='none';});
		uploadModal.addEventListener('click',function(e){if(e.target===uploadModal){discardTemp();uploadModal.style.display='none';}});
		document.getElementById('zkv-detail-close').addEventListener('click',function(){detailModal.style.display='none';});
		detailModal.addEventListener('click',function(e){if(e.target===detailModal)detailModal.style.display='none';});

		// ── Settings modal ──
		var settingsModal = document.getElementById('zkv-settings-modal');
		var settingsBtn   = document.getElementById('zkv-settings-btn');
		if (settingsBtn && settingsModal) {
			settingsBtn.addEventListener('click', function() {
				settingsModal.style.display = ''; icons();
				loadPrivateAdmin();
				ajax('zkv_get_settings').then(function(res) {
					if (!res.success) return; var s = res.data;
					document.getElementById('zkv-key-status').textContent = s.managed
						? ('Poe: ' + (s.api_key_set ? 'configured' : 'not set')) // managed on Zorderz → Credentials
						: s.api_key_set
						? 'Current: ' + s.api_key_masked + ' (source: ' + s.key_source + ')' : 'No API key configured';
					var modelSel = document.getElementById('zkv-settings-model');
					// A select (the business's roster) or a text box (no roster): '' is the platform model.
					if (modelSel) { modelSel.value = s.ai_model || ''; }
					var usedEl = document.getElementById('zkv-model-used');
					if (usedEl) { usedEl.textContent = 'In use: ' + (s.ai_model_used || 'none configured'); }
				});
			});
			document.getElementById('zkv-settings-close').addEventListener('click', function() { settingsModal.style.display = 'none'; });
			document.getElementById('zkv-settings-cancel').addEventListener('click', function() { settingsModal.style.display = 'none'; });
			settingsModal.addEventListener('click', function(e) { if(e.target===settingsModal) settingsModal.style.display='none'; });
			document.getElementById('zkv-settings-save').addEventListener('click', function() {
				var keyInput = document.getElementById('zkv-settings-apikey');
				var modelSel = document.getElementById('zkv-settings-model');
				var saveBtn  = document.getElementById('zkv-settings-save');
				saveBtn.disabled = true; saveBtn.textContent = 'Saving & testing...';
				var payload = { api_key: keyInput ? keyInput.value : '', ai_model: modelSel.value };
				if (document.getElementById('zkv-settings-managed-poe')) payload['zdz_cred_managed[poe]'] = '1';
				ajax('zkv_save_settings', payload).then(function(res) {
					saveBtn.disabled = false; saveBtn.textContent = 'Save Settings';
					if (res.success) {
						var status = document.getElementById('zkv-key-status');
						if (res.data.test_result && res.data.test_result.indexOf('Error') === -1) {
							status.textContent = 'Saved & verified — AI is working.'; status.style.color = '#059669';
						} else {
							status.textContent = 'Saved, but test failed: ' + (res.data.test_result || 'No response'); status.style.color = '#DC2626';
						}
						if (keyInput) keyInput.value = '';
					} else { alert('Save failed: ' + (res.data || '')); }
				});
			});
			var reindexAllBtn = document.getElementById('zkv-reindex-all-btn');
			if (reindexAllBtn) reindexAllBtn.addEventListener('click', function() {
				if (!confirm('Re-index all documents? Each document takes 10-30 seconds.')) return;
				reindexAllBtn.disabled = true;
				// First get all document IDs, then process one at a time.
				ajax('zkv_list_documents',{per_page:50}).then(function(res) {
					if (!res.success || !res.data.documents) { reindexAllBtn.disabled = false; return; }
					var docs = res.data.documents;
					var total = docs.length, done = 0, failed = 0;
					function nextDoc() {
						if (done + failed >= total) {
							reindexAllBtn.disabled = false;
							reindexAllBtn.innerHTML = '<i data-lucide="refresh-cw"></i> Re-index All Documents'; icons();
							alert('Done! ' + done + ' re-indexed, ' + failed + ' failed.');
							loadDocs();
							return;
						}
						var doc = docs[done + failed];
						var id = doc.document_id || doc.id;
						reindexAllBtn.textContent = 'Re-indexing ' + (done+failed+1) + '/' + total + ': ' + (doc.title||'').substring(0,25) + '...';
						ajax('zkv_reindex',{document_id:id}).then(function(r) {
							if (r.success) done++; else failed++;
							nextDoc();
						}).catch(function(){ failed++; nextDoc(); });
					}
					nextDoc();
				});
			});
		}

		// ── File picking (supports single + batch) ──
		dropzone.addEventListener('click',function(){fileInput.click();});
		dropzone.addEventListener('dragover',function(e){e.preventDefault();e.stopPropagation();dropzone.classList.add('zkv-dragover');});
		dropzone.addEventListener('dragleave',function(){dropzone.classList.remove('zkv-dragover');});
		dropzone.addEventListener('drop',function(e){
			e.preventDefault();e.stopPropagation();dropzone.classList.remove('zkv-dragover');
			console.log('ZKV drop: '+e.dataTransfer.files.length+' files');
			if(e.dataTransfer.files.length) pickFiles(e.dataTransfer.files);
		});
		// Also handle drops on the modal body (larger target).
		var modalBody = uploadModal.querySelector('.zkv-modal-body');
		if(modalBody){
			modalBody.addEventListener('dragover',function(e){e.preventDefault();e.stopPropagation();});
			modalBody.addEventListener('drop',function(e){
				e.preventDefault();e.stopPropagation();
				console.log('ZKV modal-body drop: '+e.dataTransfer.files.length+' files');
				if(e.dataTransfer.files.length) pickFiles(e.dataTransfer.files);
			});
		}
		fileInput.addEventListener('change',function(){
			console.log('ZKV file input change: '+fileInput.files.length+' files');
			if(fileInput.files.length) pickFiles(fileInput.files);
		});
		document.getElementById('zkv-file-remove').addEventListener('click',function(e){
			e.stopPropagation(); clearFileSelection();
		});
		if(batchClear) batchClear.addEventListener('click',function(){ clearFileSelection(); });

		// The AI step's copy of a file that will not be uploaded is removed now, not left in the Vault folder.
		function discardTemp() {
			if (!state.tempKey) return;
			var k = state.tempKey; state.tempKey = '';
			ajax('zkv_discard_temp', { temp_key: k }).catch(function () {});
		}
		function clearFileSelection(){
			discardTemp();
			state.selectedFiles=[]; state.tempKey=''; state.batchMode=false;
			filePreview.style.display='none'; batchList.style.display='none';
			dropzone.style.display=''; dupWarning.style.display='none';
			if(titleGroup) titleGroup.style.display='';
			if(descGroup) descGroup.style.display='';
			fileInput.value=''; updateSubmitState();
		}

		function pickFiles(fileList) {
			var files = [];
			console.log('ZKV pickFiles called with '+fileList.length+' files');
			for(var i=0; i<fileList.length && i<MAX_BATCH; i++){
				if(fileList[i].size > D.maxUpload){
					alert(fileList[i].name+' is too large. Max '+Math.round(D.maxUpload/(1024*1024))+' MB.');
					continue;
				}
				files.push(fileList[i]);
			}
			if(files.length === 0) return;
			console.log('ZKV: '+files.length+' files passed validation, batchMode='+(files.length>1));

			state.selectedFiles = files;
			state.dupOverride = false;
			dupWarning.style.display = 'none';

			if(files.length === 1){
				// ── Single file: use preanalyze flow ──
				state.batchMode = false;
				batchList.style.display = 'none';
				if(titleGroup) titleGroup.style.display = '';
				if(descGroup) descGroup.style.display = '';
				if(modalTitle) modalTitle.textContent = 'Upload Document';
				pickSingleFile(files[0]);
			} else {
				// ── Batch: show file list, hide title/desc (AI does each one) ──
				state.batchMode = true;
				filePreview.style.display = 'none';
				dropzone.style.display = 'none';
				if(titleGroup) titleGroup.style.display = 'none';
				if(descGroup) descGroup.style.display = 'none';
				if(modalTitle) modalTitle.textContent = 'Upload Documents';
				renderBatchList();
				updateSubmitState();
			}
		}

		function renderBatchList(){
			batchCount.textContent = state.selectedFiles.length + ' file' + (state.selectedFiles.length!==1?'s':'') + ' selected';
			var html = '';
			state.selectedFiles.forEach(function(f,i){
				html += '<div class="zkv-batch-item" data-idx="'+i+'">'
					+'<i data-lucide="file-text"></i>'
					+'<span class="zkv-batch-name">'+esc(f.name)+'</span>'
					+'<span class="zkv-batch-size">'+fmtSize(f.size)+'</span>'
					+'<span class="zkv-batch-status" id="zkv-batch-status-'+i+'"></span>'
					+'<button class="zkv-batch-remove" data-idx="'+i+'" title="Remove"><i data-lucide="x"></i></button>'
					+'</div>';
			});
			batchItems.innerHTML = html;
			batchList.style.display = '';
			icons();

			// Remove buttons.
			batchItems.querySelectorAll('.zkv-batch-remove').forEach(function(btn){
				btn.addEventListener('click',function(e){
					e.stopPropagation();
					var idx = parseInt(btn.getAttribute('data-idx'));
					state.selectedFiles.splice(idx,1);
					if(state.selectedFiles.length === 0){ clearFileSelection(); }
					else if(state.selectedFiles.length === 1){
						// Switch back to single mode.
						state.batchMode = false;
						batchList.style.display = 'none';
						if(titleGroup) titleGroup.style.display = '';
						if(descGroup) descGroup.style.display = '';
						pickSingleFile(state.selectedFiles[0]);
					} else { renderBatchList(); }
					updateSubmitState();
				});
			});
		}

		// ── Single file: preanalyze with AI ──
		function pickSingleFile(f){
			fileName.textContent=f.name+' ('+fmtSize(f.size)+')';
			filePreview.style.display='';dropzone.style.display='none';
			icons();

			discardTemp(); // a file picked before this one, read by the AI step, goes now
			var priv = isPriv();
			titleInput.value=''; catSelect.value=''; descInput.value='';
			state.sugTitle=''; state.sugCat='';   // what the machine put in the boxes (kept only if a person changes it)
			state.preAI = !priv;                 // did the AI read this file?
			if (privWarn) privWarn.style.display = 'none';
			// Keep private - nothing is sent before Upload: the title comes from the file name, here.
			if (priv) {
				var pn = f.name.replace(/\.[^.]+$/, '').replace(/[-_.]+/g, ' ').trim();
				titleInput.value = pn.charAt(0).toUpperCase() + pn.slice(1);
				state.sugTitle = titleInput.value; state.sugCat = catSelect.value; state.tempKey = '';
				submitBtn.disabled = false; updateSubmitState();
				return;
			}
			processingEl.style.display='';
			processingMsg.textContent = 'Reading document with AI...';
			submitBtn.disabled=true;

			var fd=new FormData();
			fd.append('action','zkv_preanalyze');
			fd.append('nonce',D.nonce);
			fd.append('file',f);

			fetch(D.ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
			.then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
			.then(function(res){
				processingEl.style.display='none';
				if(res.success && res.data){
					state.tempKey = res.data.temp_key || '';
					// Keep private ticked while the AI was reading it - say so.
					if (privWarn && isPriv()) privWarn.style.display = '';
					var s = res.data.suggestions || {};
					if(s.title) titleInput.value = s.title;
					if(s.category){ for(var i=0;i<catSelect.options.length;i++){if(catSelect.options[i].value===s.category){catSelect.selectedIndex=i;break;}} }
					state.sugTitle = titleInput.value; state.sugCat = catSelect.value;
					if(s.description) descInput.value = s.description;
					if(res.data.duplicate){
						dupMsg.textContent='This file already exists as "'+res.data.duplicate.title+'". Upload anyway?';
						dupWarning.style.display=''; icons();
					}
				} else {
					var errMsg = (res.data && typeof res.data === 'string') ? res.data : 'Unknown error';
					processingMsg.textContent='AI pre-fill failed: '+errMsg;
					processingEl.style.display=''; setTimeout(function(){processingEl.style.display='none';},4000);
					var n=f.name.replace(/\.[^.]+$/,'').replace(/[-_.]/g,' ');
					titleInput.value=n.charAt(0).toUpperCase()+n.slice(1); state.tempKey='';
					state.sugTitle = titleInput.value; state.sugCat = catSelect.value;
				}
				submitBtn.disabled=false; updateSubmitState();
			})
			.catch(function(e){
				processingEl.style.display='';
				processingMsg.textContent='AI pre-fill failed: '+e.message;
				processingEl.style.display=''; setTimeout(function(){processingEl.style.display='none';},4000);
				var n=f.name.replace(/\.[^.]+$/,'').replace(/[-_.]/g,' ');
				titleInput.value=n.charAt(0).toUpperCase()+n.slice(1); state.tempKey='';
				state.sugTitle = titleInput.value; state.sugCat = catSelect.value;
				submitBtn.disabled=false; updateSubmitState();
			});
		}

		// ── Submit dispatcher ──
		submitBtn.addEventListener('click',function(){
			if(state.mode === 'paste') submitPasteText();
			else if(state.batchMode) submitBatch();
			else submitSingleFile();
		});

		// ── Single file submit ──
		function submitSingleFile() {
			if(state.selectedFiles.length===0 && !state.tempKey) return;
			submitBtn.disabled=true;
			processingEl.style.display=''; processingMsg.textContent='Saving document...';

			var fd=new FormData();
			fd.append('action','zkv_upload_document');
			fd.append('nonce',D.nonce);
			fd.append('title',titleInput.value);
			fd.append('category',catSelect.value);
			// A title or category the person changed is theirs; one the machine suggested may still be improved.
			fd.append('title_edited', titleInput.value.trim() !== String(state.sugTitle||'').trim() ? '1' : '0');
			fd.append('category_edited', catSelect.value !== String(state.sugCat||'') ? '1' : '0');
			fd.append('description',descInput.value);
			fd.append('user_context',contextInput?contextInput.value:'');
			fd.append('visibility',document.querySelector('input[name="zkv-visibility"]:checked').value);
			if(isPriv()) fd.append('private','1');
			if(state.tempKey) fd.append('temp_key',state.tempKey);
			else fd.append('file',state.selectedFiles[0]);

			fetch(D.ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
			.then(function(r){return r.json();})
			.then(function(res){
				processingEl.style.display='none';
				if(res.success){ uploadModal.style.display='none'; loadDocs(); }
				else { alert('Upload failed: '+(res.data||'Unknown error')); submitBtn.disabled=false; }
			})
			.catch(function(e){ processingEl.style.display='none'; alert('Error: '+e.message); submitBtn.disabled=false; });
		}

		// ── Batch submit: upload files sequentially ──
		function submitBatch() {
			var files = state.selectedFiles.slice(); // copy
			var total = files.length;
			var done = 0;
			var failed = 0;
			var category = catSelect.value;
			var visibility = document.querySelector('input[name="zkv-visibility"]:checked').value;
			var priv = isPriv();

			console.log('ZKV submitBatch: uploading '+total+' files');
			submitBtn.disabled = true;
			processingEl.style.display = '';
			processingMsg.textContent = 'Uploading documents (0 of '+total+')...';

			function uploadNext() {
				if(done + failed >= total){
					console.log('ZKV batch complete: '+done+' done, '+failed+' failed');
					processingEl.style.display = 'none';
					uploadModal.style.display = 'none';
					loadDocs();
					if(failed > 0) alert(done+' uploaded, '+failed+' failed.');
					return;
				}

				var idx = done + failed;
				var f = files[idx];
				console.log('ZKV batch uploading '+(idx+1)+'/'+total+': '+f.name);
				processingMsg.textContent = 'Uploading documents ('+(idx+1)+' of '+total+'): '+f.name;

				// Update batch item visual.
				var itemEl = batchItems.querySelector('[data-idx="'+idx+'"]');
				if(itemEl) itemEl.classList.add('uploading');
				var statusEl = document.getElementById('zkv-batch-status-'+idx);

				var fd = new FormData();
				fd.append('action','zkv_upload_document');
				fd.append('nonce',D.nonce);
				fd.append('title',''); // AI will generate
				fd.append('category',category);
				fd.append('description','');
				fd.append('user_context',contextInput?contextInput.value:'');
				fd.append('visibility',visibility);
				if(priv) fd.append('private','1');
				fd.append('file',f);

				fetch(D.ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
				.then(function(r){return r.json();})
				.then(function(res){
					if(itemEl) itemEl.classList.remove('uploading');
					if(res.success){
						done++;
						if(itemEl) itemEl.classList.add('done');
						if(statusEl) { statusEl.textContent='✓'; statusEl.style.color='#059669'; }
					} else {
						failed++;
						if(itemEl) itemEl.classList.add('error');
						if(statusEl) { statusEl.textContent='✗'; statusEl.style.color='#DC2626'; }
					}
					uploadNext();
				})
				.catch(function(){
					failed++;
					if(itemEl){ itemEl.classList.remove('uploading'); itemEl.classList.add('error'); }
					if(statusEl) { statusEl.textContent='✗'; statusEl.style.color='#DC2626'; }
					uploadNext();
				});
			}

			uploadNext();
		}

		// ── Paste text submit ──
		function submitPasteText() {
			var text = pasteInput ? pasteInput.value.trim() : '';
			if(text.length < 10){ alert('Please paste some text first.'); return; }
			submitBtn.disabled=true;
			processingEl.style.display=''; processingMsg.textContent= isPriv() ? 'Saving privately...' : 'Analyzing text with AI...';

			var fd=new FormData();
			fd.append('action','zkv_paste_text');
			if(isPriv()) fd.append('private','1');
			fd.append('nonce',D.nonce);
			fd.append('text',text);
			fd.append('title',titleInput.value);
			fd.append('category',catSelect.value);
			fd.append('description',descInput.value);
			fd.append('user_context',contextInput?contextInput.value:'');
			fd.append('visibility',document.querySelector('input[name="zkv-visibility"]:checked').value);

			fetch(D.ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
			.then(function(r){return r.json();})
			.then(function(res){
				processingEl.style.display='none';
				if(res.success){ uploadModal.style.display='none'; loadDocs(); }
				else { alert('Save failed: '+(res.data||'Unknown error')); submitBtn.disabled=false; }
			})
			.catch(function(e){ processingEl.style.display='none'; alert('Error: '+e.message); submitBtn.disabled=false; });
		}

		// ══════════════════════════════════════════════════════════
		//  v1.5.0 — PRIVATE TRANSCRIPTS (party banner, sharing, admin queue)
		//  Everything here is UX only; the server re-checks every authority.
		// ══════════════════════════════════════════════════════════

		var rosterCache = null;
		function loadRoster() {
			if (rosterCache) return Promise.resolve(rosterCache);
			return ajax('zkv_vault_users').then(function(r){
				rosterCache = (r.success && r.data && r.data.users) ? r.data.users : [];
				return rosterCache;
			});
		}
		function parseRanges(str) {
			var out = [];
			String(str||'').split(',').forEach(function(part){
				part = part.trim(); if (!part) return;
				var m = part.match(/^(\d+)\s*-\s*(\d+)$/);
				if (m) { out.push([parseInt(m[1],10), parseInt(m[2],10)]); return; }
				var n = part.match(/^(\d+)$/);
				if (n) { out.push([parseInt(n[1],10), parseInt(n[1],10)]); }
			});
			return out;
		}
		function fmtExpiry(s){ return s ? ('expires '+fmtDate(s)) : 'no expiry'; }

		function renderTranscriptDetail(d) {
			var banner;
			if (d.viewer_is_party) {
				banner = '<div class="zkv-transcript-banner zkv-tb-party"><i data-lucide="lock"></i> <div><strong>Private transcript.</strong> Only its named speakers can see it — you\'re a party'
					+ (d.my_label ? ' as &ldquo;'+esc(d.my_label)+'&rdquo;' : '') + '.'
					+ (d.parties && d.parties.length ? '<br><span class="zkv-tb-parties">Parties: '
						+ d.parties.map(function(p){ return esc(p.name)+(p.speaker_label?' ('+esc(p.speaker_label)+')':''); }).join(' · ')
						+ '</span>' : '')
					+ '</div></div>';
			} else {
				banner = '<div class="zkv-transcript-banner zkv-tb-shared"><i data-lucide="eye"></i> <div><strong>Shared with you by '
					+ esc(d.shared_by_name||'a party') + '</strong> · view only · '
					+ (d.share_expires_at ? 'expires '+fmtDate(d.share_expires_at) : 'no expiry')
					+ '.<br><span class="zkv-tb-parties">You can read it here; it never appears in your assistant, and you can\'t share it onward.</span></div></div>';
			}
			detailBody.insertAdjacentHTML('afterbegin', banner);

			// v1.5.2 (KV2): the uploader confirms WHO may see this transcript before
			// anyone else is granted. Shown only while pending and only to the uploader.
			if (d.transcript_status === 'pending_confirm' && d.viewer_is_uploader) {
				var pc = document.createElement('div');
				pc.className = 'zkv-transcript-banner zkv-tb-confirm';
				pc.innerHTML = '<div style="flex:1;">'
					+ '<strong>Who may see this transcript?</strong>'
					+ '<div class="zkv-tb-parties" style="margin-top:2px;">It stays private to you until you confirm.</div>'
					+ '<div id="zkv-pc-list" style="margin-top:8px;">Loading detected names…</div></div>';
				detailBody.insertBefore(pc, detailBody.firstChild);
				ajax('zkv_transcript_detected', {document_id:d.id}).then(function(r){
					var box = document.getElementById('zkv-pc-list');
					if (!box) return;
					var matched = (r.success && r.data && r.data.matched) ? r.data.matched : [];
					var unmatched = (r.success && r.data && r.data.unmatched) ? r.data.unmatched : [];
					var tail = unmatched.length ? '<div class="zkv-share-empty">Not recognized as staff: '+unmatched.map(esc).join(', ')+'</div>' : '';
					if (!matched.length) {
						box.innerHTML = '<div class="zkv-share-empty">No named people were recognized.</div>' + tail
							+ '<div style="margin-top:8px;"><button class="zkv-btn zkv-btn-secondary" id="zkv-pc-confirm">Keep private to me</button></div>';
					} else {
						box.innerHTML = matched.map(function(m){
							return '<label style="display:block;margin:4px 0;"><input type="checkbox" class="zkv-pc-cb" value="'+m.user_id+'" checked /> '
								+ esc(m.name) + (m.speaker_label?' <span class="zkv-tb-parties">('+esc(m.speaker_label)+')</span>':'') + '</label>';
						}).join('') + tail
						+ '<div style="margin-top:8px;"><button class="zkv-btn zkv-btn-primary" id="zkv-pc-confirm">Confirm who can see it</button></div>';
					}
					var cbtn = document.getElementById('zkv-pc-confirm');
					if (cbtn) cbtn.addEventListener('click', function(){
						var ids = Array.prototype.slice.call(document.querySelectorAll('.zkv-pc-cb:checked')).map(function(c){return parseInt(c.value,10);});
						cbtn.disabled = true; cbtn.textContent = 'Confirming…';
						ajax('zkv_transcript_confirm_parties', {document_id:d.id, user_ids: JSON.stringify(ids)}).then(function(rr){
							alert(rr.success ? ((rr.data && rr.data.message)||'Confirmed.') : (rr.data||'Confirm failed.'));
							openDetail(d.id);
						});
					});
				});
			}

			if (!d.viewer_is_party) return; // Sharees get the banner only.

			// ── Party tools: Shared-with list + Share form ──
			var sharesHtml = '<div class="zkv-detail-section zkv-share-section"><h4>Sharing</h4><div id="zkv-share-list">';
			if (d.shares && d.shares.length) {
				d.shares.forEach(function(s){
					sharesHtml += '<div class="zkv-share-row" data-share-id="'+s.id+'">'
						+ '<span>'+esc(s.with_name)+' · '+(s.scope==='whole'?'whole transcript':'excerpt')+' · '+fmtExpiry(s.expires_at)+'</span>'
						+ '<button class="zkv-btn-text zkv-share-revoke" data-share-id="'+s.id+'">Revoke</button></div>';
				});
			} else {
				sharesHtml += '<div class="zkv-share-empty">Not shared with anyone.</div>';
			}
			sharesHtml += '</div>'
				+ '<button class="zkv-btn zkv-btn-secondary" id="zkv-share-open"><i data-lucide="share-2"></i> Share…</button>'
				+ '<div id="zkv-share-form" style="display:none;margin-top:10px;">'
				+ '<div class="zkv-form-group"><label>Share with</label><select id="zkv-share-with"><option value="">Loading people…</option></select></div>'
				+ '<div class="zkv-form-group"><label>What</label><div class="zkv-radio-group">'
				+ '<label><input type="radio" name="zkv-share-scope" value="whole" checked /> Whole transcript (view only)</label>'
				+ '<label><input type="radio" name="zkv-share-scope" value="excerpt" /> Excerpt (only chosen lines)</label></div></div>'
				+ '<div id="zkv-share-excerpt-tools" style="display:none;">'
				+ '<div class="zkv-form-group"><label>Mode</label><div class="zkv-radio-group">'
				+ '<label><input type="radio" name="zkv-share-mode" value="select" checked /> Share ONLY these lines</label>'
				+ '<label><input type="radio" name="zkv-share-mode" value="redact" /> Share everything EXCEPT these lines</label></div></div>'
				+ '<div class="zkv-form-group"><label>Line ranges <span class="zkv-label-hint">— e.g. 12-40, 55, 60-70</span></label>'
				+ '<input type="text" id="zkv-share-ranges" placeholder="12-40, 55" />'
				+ '<button class="zkv-btn-text" id="zkv-share-showlines">Show numbered lines</button></div>'
				+ '<div id="zkv-share-lines" class="zkv-share-lines" style="display:none;"></div>'
				+ '<div class="zkv-form-group"><button class="zkv-btn-text" id="zkv-share-preview-btn">Preview what they\'ll see</button>'
				+ '<div id="zkv-share-preview" class="zkv-share-lines" style="display:none;"></div></div>'
				+ '</div>'
				+ '<div class="zkv-form-group"><label>Expires</label><select id="zkv-share-expiry">'
				+ '<option value="0">Never (until revoked)</option><option value="7">In 7 days</option>'
				+ '<option value="30">In 30 days</option><option value="90">In 90 days</option></select></div>'
				+ '<div style="display:flex;gap:8px;">'
				+ '<button class="zkv-btn zkv-btn-primary" id="zkv-share-create">Share</button>'
				+ '<button class="zkv-btn zkv-btn-cancel" id="zkv-share-cancel">Cancel</button></div>'
				+ '<span class="zkv-hint">View only. They can\'t re-share it, and it never enters their assistant. You can revoke any time.</span>'
				+ '</div></div>';
			detailBody.insertAdjacentHTML('beforeend', sharesHtml);

			var linesCache = null;
			function loadLines() {
				if (linesCache) return Promise.resolve(linesCache);
				return ajax('zkv_transcript_lines',{document_id:d.id}).then(function(r){
					linesCache = (r.success && r.data && r.data.lines) ? r.data.lines : [];
					return linesCache;
				});
			}
			function lineHtml(rows) {
				return rows.map(function(l){
					return '<div class="zkv-line"><span class="zkv-line-no">'+l.line_no+'</span> '
						+ (l.speaker?'<strong>'+esc(l.speaker)+':</strong> ':'') + esc(l.line_text)+'</div>';
				}).join('');
			}

			detailBody.querySelectorAll('.zkv-share-revoke').forEach(function(btn){
				btn.addEventListener('click', function(){
					if (!confirm('Revoke this share? Their access ends immediately.')) return;
					ajax('zkv_share_revoke',{share_id:btn.getAttribute('data-share-id')}).then(function(r){
						if (r.success) { openDetail(d.id); } else { alert(r.data||'Revoke failed.'); }
					});
				});
			});

			var shareOpen = document.getElementById('zkv-share-open');
			var shareForm = document.getElementById('zkv-share-form');
			if (shareOpen) shareOpen.addEventListener('click', function(){
				shareForm.style.display = shareForm.style.display==='none' ? '' : 'none';
				loadRoster().then(function(users){
					var sel = document.getElementById('zkv-share-with');
					var partyIds = (d.parties||[]).map(function(p){return p.user_id;});
					var opts = '<option value="">Choose a person…</option>';
					users.forEach(function(u){
						if (u.id == D.userId || partyIds.indexOf(u.id)!==-1) return;
						opts += '<option value="'+u.id+'">'+esc(u.name)+'</option>';
					});
					sel.innerHTML = opts;
				});
			});
			document.querySelectorAll('input[name="zkv-share-scope"]').forEach(function(r){
				r.addEventListener('change', function(){
					document.getElementById('zkv-share-excerpt-tools').style.display =
						(document.querySelector('input[name="zkv-share-scope"]:checked').value==='excerpt') ? '' : 'none';
				});
			});
			var showLinesBtn = document.getElementById('zkv-share-showlines');
			if (showLinesBtn) showLinesBtn.addEventListener('click', function(){
				var box = document.getElementById('zkv-share-lines');
				if (box.style.display!=='none') { box.style.display='none'; return; }
				box.style.display=''; box.innerHTML='Loading…';
				loadLines().then(function(rows){ box.innerHTML = rows.length ? lineHtml(rows) : 'No line rendition available.'; });
			});
			var previewBtn = document.getElementById('zkv-share-preview-btn');
			if (previewBtn) previewBtn.addEventListener('click', function(){
				var box = document.getElementById('zkv-share-preview');
				var ranges = parseRanges(document.getElementById('zkv-share-ranges').value);
				var mode = document.querySelector('input[name="zkv-share-mode"]:checked').value;
				if (!ranges.length) { alert('Enter line ranges first.'); return; }
				box.style.display=''; box.innerHTML='Building preview…';
				loadLines().then(function(rows){
					var inSel = {};
					ranges.forEach(function(r){ for(var n=r[0];n<=r[1];n++) inSel[n]=true; });
					var kept = rows.filter(function(l){
						var s = !!inSel[l.line_no];
						return mode==='select' ? s : !s;
					});
					box.innerHTML = kept.length
						? lineHtml(kept) + '<div class="zkv-share-empty">'+(rows.length-kept.length)+' line(s) will NOT exist in their copy.</div>'
						: 'Nothing would be shared — adjust the ranges.';
				});
			});
			var shareCancel = document.getElementById('zkv-share-cancel');
			if (shareCancel) shareCancel.addEventListener('click', function(){ shareForm.style.display='none'; });
			var shareCreate = document.getElementById('zkv-share-create');
			if (shareCreate) shareCreate.addEventListener('click', function(){
				var withId = document.getElementById('zkv-share-with').value;
				if (!withId) { alert('Choose a person to share with.'); return; }
				var scope = document.querySelector('input[name="zkv-share-scope"]:checked').value;
				var payload = {
					document_id: d.id,
					with_user_id: withId,
					scope: scope,
					expires_days: document.getElementById('zkv-share-expiry').value
				};
				if (scope==='excerpt') {
					var ranges = parseRanges(document.getElementById('zkv-share-ranges').value);
					if (!ranges.length) { alert('Enter the line ranges to share (e.g. 12-40).'); return; }
					payload.mode = document.querySelector('input[name="zkv-share-mode"]:checked').value;
					payload.ranges = JSON.stringify(ranges);
				}
				shareCreate.disabled = true; shareCreate.textContent = 'Sharing…';
				ajax('zkv_share_create', payload).then(function(r){
					shareCreate.disabled = false; shareCreate.textContent = 'Share';
					if (r.success) {
						var msg = (r.data && r.data.message) || 'Shared.';
						if (r.data && r.data.excerpt_url) { msg += '\n\nExcerpt link (for them): ' + r.data.excerpt_url; }
						if (r.data && r.data.whole_url) { msg += '\n\nDirect link (for them): ' + r.data.whole_url; }
						alert(msg);
						openDetail(d.id);
					} else { alert(r.data || 'Share failed.'); }
				});
			});
		}

		// ── Upload modal: transcript hint on the visibility radios ──
		document.querySelectorAll('input[name="zkv-visibility"]').forEach(function(r){
			r.addEventListener('change', function(){
				var hint = document.getElementById('zkv-transcript-hint');
				var isTr = document.querySelector('input[name="zkv-visibility"]:checked').value==='transcript_private';
				if (hint) hint.style.display = isTr ? '' : 'none';
				// A transcript cannot also be Private yet.
				if (privPick) { privPick.style.display = isTr ? 'none' : ''; if (isTr && privBox) privBox.checked = false; }
			});
		});

		// ── Admin queue (suggested + latent transcripts) ──
		function buildQueueModal() {
			var overlay = document.createElement('div');
			overlay.className = 'zkv-modal-overlay';
			overlay.id = 'zkv-tq-modal';
			overlay.style.display = 'none';
			overlay.innerHTML = '<div class="zkv-modal zkv-modal-detail">'
				+ '<div class="zkv-modal-header"><span>Transcript Queue</span>'
				+ '<button class="zkv-modal-close" id="zkv-tq-close"><i data-lucide="x"></i></button></div>'
				+ '<div class="zkv-modal-body" id="zkv-tq-body"></div></div>';
			vault.appendChild(overlay);
			document.getElementById('zkv-tq-close').addEventListener('click', function(){ overlay.style.display='none'; });
			return overlay;
		}
		function openQueue() {
			var overlay = document.getElementById('zkv-tq-modal') || buildQueueModal();
			var body = document.getElementById('zkv-tq-body');
			overlay.style.display='';
			body.innerHTML = '<div class="zkv-loading"><div class="zkv-spinner"></div></div>';
			Promise.all([ajax('zkv_transcript_queue'), loadRoster()]).then(function(rs){
				var res = rs[0], users = rs[1];
				if (!res.success) { body.innerHTML='<p>Could not load the queue.</p>'; return; }
				var items = (res.data && res.data.items) || [];
				if (!items.length) { body.innerHTML='<div class="zkv-empty">Nothing waiting. Suggested and latent transcripts appear here.</div>'; return; }
				var userOpts = '<option value="">Bind to…</option>' + users.map(function(u){
					return '<option value="'+u.id+'">'+esc(u.name)+'</option>';
				}).join('');
				var html = '<p class="zkv-hint" style="display:block;margin-bottom:10px;">Suggested = the AI thinks this is a transcript; it stays a normal visible document until you confirm. Latent = private, but no speakers matched a staff account — bind the confirmed person to give them (and only them) access. You never see the transcript body here.</p>';
				items.forEach(function(it){
					var name = it.is_private ? it.original_name : (it.title || it.original_name);
					var chip = it.status==='suggested'
						? '<span class="zkv-badge zkv-badge-pending">Suggested</span>'
						: '<span class="zkv-badge zkv-badge-failed">'+esc(it.status.charAt(0).toUpperCase()+it.status.slice(1))+'</span>';
					html += '<div class="zkv-tq-item" data-id="'+it.id+'">'
						+ '<div class="zkv-tq-head"><strong>'+esc(name)+'</strong> '+chip
						+ '<div class="zkv-doc-meta"><span>'+esc(it.uploader)+'</span><span>'+fmtDate(it.created_at)+'</span><span>'+esc(it.source_type)+'</span>'
						+ (it.is_private ? '<span>'+it.parties+' parties</span>' : '') + '</div></div>';
					if (it.status==='suggested') {
						html += '<div class="zkv-tq-actions">'
							+ '<button class="zkv-btn zkv-btn-primary zkv-tq-confirm" data-id="'+it.id+'">Confirm — make private</button>'
							+ '<button class="zkv-btn zkv-btn-secondary zkv-tq-reject" data-id="'+it.id+'">Not a transcript</button></div>';
					} else {
						if (it.unmatched_labels && it.unmatched_labels.length) {
							it.unmatched_labels.forEach(function(lab){
								html += '<div class="zkv-tq-bind" data-id="'+it.id+'" data-label="'+esc(lab)+'">'
									+ '<span class="zkv-tq-label">&ldquo;'+esc(lab)+'&rdquo;</span>'
									+ '<button class="zkv-btn-text zkv-tq-ctx" data-id="'+it.id+'" data-label="'+esc(lab)+'">±1 line context</button>'
									+ '<select class="zkv-tq-user">'+userOpts+'</select>'
									+ '<button class="zkv-btn zkv-btn-sm zkv-btn-secondary zkv-tq-dobind" data-id="'+it.id+'" data-label="'+esc(lab)+'">Bind</button>'
									+ '<div class="zkv-tq-ctxbox" style="display:none;"></div></div>';
							});
						} else {
							html += '<div class="zkv-share-empty">No speaker labels detected — bind manually below.</div>'
								+ '<div class="zkv-tq-bind" data-id="'+it.id+'" data-label="">'
								+ '<span class="zkv-tq-label">(no label)</span>'
								+ '<select class="zkv-tq-user">'+userOpts+'</select>'
								+ '<button class="zkv-btn zkv-btn-sm zkv-btn-secondary zkv-tq-dobind" data-id="'+it.id+'" data-label="">Bind</button></div>';
						}
						html += '<div class="zkv-tq-actions">'
							+ '<button class="zkv-btn zkv-btn-secondary zkv-tq-reject" data-id="'+it.id+'">Not a transcript</button>'
							+ '<button class="zkv-btn zkv-btn-danger zkv-tq-del" data-id="'+it.id+'">Delete</button></div>';
					}
					html += '</div>';
				});
				body.innerHTML = html;
				icons();

				body.querySelectorAll('.zkv-tq-confirm').forEach(function(b){
					b.addEventListener('click', function(){
						b.disabled=true; b.textContent='Confirming…';
						ajax('zkv_transcript_confirm',{document_id:b.getAttribute('data-id')}).then(function(r){
							alert(r.success ? (r.data.message||'Confirmed.') : (r.data||'Failed.'));
							openQueue(); loadDocs();
						});
					});
				});
				body.querySelectorAll('.zkv-tq-reject').forEach(function(b){
					b.addEventListener('click', function(){
						if (!confirm('Mark as NOT a transcript? It becomes / stays a normal document.')) return;
						ajax('zkv_transcript_reject',{document_id:b.getAttribute('data-id')}).then(function(){ openQueue(); loadDocs(); });
					});
				});
				body.querySelectorAll('.zkv-tq-del').forEach(function(b){
					b.addEventListener('click', function(){
						if (!confirm('Delete this document entirely?')) return;
						ajax('zkv_delete_document',{document_id:b.getAttribute('data-id')}).then(function(){ openQueue(); loadDocs(); });
					});
				});
				body.querySelectorAll('.zkv-tq-ctx').forEach(function(b){
					b.addEventListener('click', function(){
						var wrap = b.closest('.zkv-tq-bind');
						var box = wrap.querySelector('.zkv-tq-ctxbox');
						if (box.style.display!=='none') { box.style.display='none'; return; }
						box.style.display=''; box.innerHTML='Loading…';
						ajax('zkv_transcript_context',{document_id:b.getAttribute('data-id'),label:b.getAttribute('data-label')}).then(function(r){
							if (!r.success) { box.innerHTML='Could not load.'; return; }
							var sn = (r.data && r.data.snippets) || [];
							box.innerHTML = sn.length ? sn.map(function(s){
								return '<div class="zkv-line">'+(s.prev?'<div class="zkv-tq-dim">'+esc(s.prev)+'</div>':'')
									+ '<div><strong>'+esc(s.line)+'</strong></div>'
									+ (s.next?'<div class="zkv-tq-dim">'+esc(s.next)+'</div>':'')+'</div>';
							}).join('<hr style="opacity:.2">') : 'No occurrences found.';
						});
					});
				});
				body.querySelectorAll('.zkv-tq-dobind').forEach(function(b){
					b.addEventListener('click', function(){
						var wrap = b.closest('.zkv-tq-bind');
						var uid = wrap.querySelector('.zkv-tq-user').value;
						if (!uid) { alert('Choose who this speaker is.'); return; }
						if (!confirm('Bind this speaker to the selected person? They gain full access to this transcript.')) return;
						b.disabled=true;
						ajax('zkv_transcript_bind',{document_id:b.getAttribute('data-id'),label:b.getAttribute('data-label'),user_id:uid}).then(function(r){
							alert(r.success ? (r.data.message||'Bound.') : (r.data||'Bind failed.'));
							openQueue();
						});
					});
				});
			});
		}
		if (D.isAdmin) {
			var hdrActions = vault.querySelector('.zkv-header-actions');
			if (hdrActions) {
				var tqBtn = document.createElement('button');
				tqBtn.className = 'zkv-btn-icon';
				tqBtn.id = 'zkv-tq-btn';
				tqBtn.title = 'Transcript queue';
				tqBtn.innerHTML = '<i data-lucide="mic"></i>';
				hdrActions.insertBefore(tqBtn, hdrActions.firstChild);
				tqBtn.addEventListener('click', openQueue);
				icons();
			}
		}

		// ── Reset ──
		function resetForm(){
			state.selectedFiles=[]; state.tempKey=''; state.dupOverride=false;
			state.mode='file'; state.batchMode=false;
			fileInput.value=''; titleInput.value=''; descInput.value=''; catSelect.value='';
			if(contextInput) contextInput.value='';
			if(pasteInput) pasteInput.value='';
			filePreview.style.display='none'; dropzone.style.display='';
			batchList.style.display='none';
			submitBtn.disabled=true; processingEl.style.display='none';
			dupWarning.style.display='none';
			if(titleGroup) titleGroup.style.display='';
			if(descGroup) descGroup.style.display='';
			if(modalTitle) modalTitle.textContent='Upload Document';
			modeFileBtn.classList.add('active');
			if(modeScanBtn) modeScanBtn.classList.remove('active');
			modePasteBtn.classList.remove('active');
			fileModeEl.style.display=''; pasteModeEl.style.display='none';
			submitBtn.innerHTML='<i data-lucide="sparkles"></i> Upload &amp; Index';
			var r=document.querySelector('input[name="zkv-visibility"][value="all_employees"]');
			if(r) r.checked=true;
			var th=document.getElementById('zkv-transcript-hint');
			if(th) th.style.display='none';
			if(privBox) privBox.checked=false; if(privPick) privPick.style.display=''; if(privWarn) privWarn.style.display='none';
			state.preAI=false;
			icons();
		}

		loadDocs(); icons();

		// ── v1.3.3: Browser-side PDF text extraction ──
		// When exec(pdftotext) is disabled (WP Engine), the server can't extract
		// text from PDFs. This uses PDF.js in the browser to extract text and
		// sends it to the server for chunk storage. Fully automatic.
		// 1.10.2: the vendored pdf.js ES module (shipped in the bundle), loaded with a dynamic
		// import(). Previously pdf.js 3.11.174 (CVE-2024-4367) was fetched from a CDN.
		var pdfjsLib = null;
		function ensurePdfJs(cb) {
			if (pdfjsLib) { cb(); return; }
			if (!D.pdfLib) { console.error('ZKV: PDF.js is not available'); return; }
			import(D.pdfLib).then(function(lib) {
				if (D.pdfWorker) { lib.GlobalWorkerOptions.workerSrc = D.pdfWorker; }
				pdfjsLib = lib;
				cb();
			}, function() { console.error('ZKV: Failed to load PDF.js'); });
		}

		function browserExtractChunks(docId, statusEl) {
			if (statusEl) statusEl.textContent = 'Loading PDF for text extraction...';
			// Get the download URL for this document
			var fd = new FormData();
			fd.append('action','zkv_download');
			fd.append('nonce',D.nonce);
			fd.append('document_id',docId);
			// Fetch the PDF as arraybuffer
			fetch(D.ajaxUrl, {method:'POST', body:fd, credentials:'same-origin'})
			.then(function(r) {
				if (!r.ok) throw new Error('Download failed');
				return r.arrayBuffer();
			})
			.then(function(buf) {
				if (statusEl) statusEl.textContent = 'Extracting text from PDF...';
				return pdfjsLib.getDocument({data:buf, isEvalSupported:false}).promise;
			})
			.then(function(pdf) {
				var pages = [];
				var total = pdf.numPages;
				function extractPage(n) {
					if (n > total) {
						// All pages extracted — send text to server
						var fullText = pages.join('\n\n');
						if (statusEl) statusEl.textContent = 'Sending ' + fullText.length + ' chars to server...';
						return ajax('zkv_browser_chunks', {
							document_id: docId,
							extracted_text: fullText
						}).then(function(res) {
							if (res.success) {
								if (statusEl) statusEl.textContent = '✅ ' + (res.data.chunk_count||0) + ' chunks created';
								setTimeout(function() { if(statusEl) statusEl.textContent=''; }, 3000);
							} else {
								if (statusEl) statusEl.textContent = '⚠️ ' + (res.data||'Chunk creation failed');
							}
						});
					}
					if (statusEl && n % 10 === 0) statusEl.textContent = 'Extracting page ' + n + ' of ' + total + '...';
					return pdf.getPage(n).then(function(page) {
						return page.getTextContent();
					}).then(function(tc) {
						pages.push(tc.items.map(function(i){return i.str;}).join(' '));
						return extractPage(n + 1);
					});
				}
				return extractPage(1);
			})
			.catch(function(e) {
				console.error('ZKV browser extract error:', e);
				if (statusEl) statusEl.textContent = '⚠️ PDF extraction failed: ' + e.message;
			});
		}

		// Check chunks after reindex or detail view — auto-extract if needed
		function checkAndExtract(docId, statusEl) {
			ajax('zkv_check_chunks', {document_id: docId}).then(function(res) {
				if (res.success && res.data.needs_browser_extract) {
					if (statusEl) statusEl.textContent = 'No text chunks — starting browser extraction...';
					ensurePdfJs(function() {
						browserExtractChunks(docId, statusEl);
					});
				} else if (res.success && res.data.chunk_count > 0) {
					if (statusEl) statusEl.textContent = '✅ ' + res.data.chunk_count + ' content chunks';
				} else if (res.success) {
					if (statusEl) statusEl.textContent = 'No chunks (not a PDF)';
				}
			});
		}

		// Expose for use in detail view and reindex handlers
		window._zkvCheckExtract = checkAndExtract;
	}
})();
