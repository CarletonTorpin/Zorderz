/**
 * Zorderz Receipts — behaviour for the public receipt page.
 *
 * Loaded from a file so the page can forbid inline script with its
 * Content-Security-Policy. It:
 *   - opens gallery photos in a lightbox (tap, arrow keys, Escape);
 *   - runs "Print this page" for [data-zrcpt-print] links.
 * Nothing here reads page text into HTML: photo URLs are set as img.src only.
 */
(function () {
  'use strict';
  var d = document;
  var box = null;
  var img = null;
  var list = [];
  var idx = 0;
  var lastFocus = null;

  // A lightbox container the model wrote never opens (its script was removed).
  function hideModelLightbox() {
    var old = d.querySelectorAll('#lightbox, .lightbox');
    for (var i = 0; i < old.length; i++) old[i].style.display = 'none';
  }

  function photos() {
    var out = [];
    var imgs = d.querySelectorAll('.gallery .gallery-item img, [data-zrcpt-lightbox] img');
    for (var i = 0; i < imgs.length; i++) {
      var s = imgs[i].getAttribute('src');
      if (s && out.indexOf(s) < 0) out.push(s);
    }
    return out;
  }

  function makeButton(cls, text, label, pos) {
    var b = d.createElement('button');
    b.type = 'button';
    b.className = cls;
    b.textContent = text;
    b.setAttribute('aria-label', label);
    b.style.cssText = 'position:absolute;' + pos + 'width:44px;height:44px;border:0;border-radius:9999px;' +
      'background:rgba(255,255,255,.16);color:#fff;font:400 30px/44px system-ui,sans-serif;cursor:pointer;padding:0;';
    return b;
  }

  function build() {
    if (box) return;
    box = d.createElement('div');
    box.className = 'zrcpt-lb';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', 'Photo viewer');
    box.setAttribute('aria-hidden', 'true');
    box.style.cssText = 'position:fixed;top:0;right:0;bottom:0;left:0;z-index:2147483600;background:rgba(0,0,0,.92);' +
      'display:none;align-items:center;justify-content:center;';
    img = d.createElement('img');
    img.alt = 'Photo';
    img.style.cssText = 'max-width:94vw;max-height:88vh;object-fit:contain;border-radius:6px;';
    var close = makeButton('zrcpt-lb-close', '×', 'Close', 'top:12px;right:14px;');
    var prev = makeButton('zrcpt-lb-prev', '‹', 'Previous photo', 'left:10px;top:50%;margin-top:-22px;');
    var next = makeButton('zrcpt-lb-next', '›', 'Next photo', 'right:10px;top:50%;margin-top:-22px;');
    close.addEventListener('click', function (e) { e.stopPropagation(); hide(); });
    prev.addEventListener('click', function (e) { e.stopPropagation(); step(-1); });
    next.addEventListener('click', function (e) { e.stopPropagation(); step(1); });
    box.addEventListener('click', function (e) { if (e.target === box) hide(); });
    box.appendChild(img);
    box.appendChild(close);
    box.appendChild(prev);
    box.appendChild(next);
    d.body.appendChild(box);
  }

  function isOpen() {
    return !!box && box.getAttribute('aria-hidden') === 'false';
  }

  function show(at) {
    list = photos();
    if (!list.length) return;
    build();
    idx = ((at % list.length) + list.length) % list.length;
    img.setAttribute('src', list[idx]);
    lastFocus = d.activeElement;
    box.style.display = 'flex';
    box.setAttribute('aria-hidden', 'false');
    var c = box.querySelector('.zrcpt-lb-close');
    if (c && c.focus) c.focus();
  }

  function step(delta) {
    if (!isOpen() || !list.length) return;
    idx = (idx + delta + list.length) % list.length;
    img.setAttribute('src', list[idx]);
  }

  function hide() {
    if (!box) return;
    box.style.display = 'none';
    box.setAttribute('aria-hidden', 'true');
    if (lastFocus && lastFocus.focus) {
      try { lastFocus.focus(); } catch (e) { /* the element may be gone */ }
    }
  }

  d.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    if (t.closest('[data-zrcpt-print]')) {
      e.preventDefault();
      window.print();
      return;
    }
    if (box && box.contains(t)) return;
    var item = t.closest('[data-zrcpt-lightbox], .gallery .gallery-item');
    if (!item) return;
    var photo = item.tagName === 'IMG' ? item : item.querySelector('img');
    if (!photo) return;
    e.preventDefault();
    var at = photos().indexOf(photo.getAttribute('src'));
    show(at < 0 ? 0 : at);
  });

  d.addEventListener('keydown', function (e) {
    if (!isOpen()) return;
    if (e.key === 'Escape') hide();
    else if (e.key === 'ArrowRight') step(1);
    else if (e.key === 'ArrowLeft') step(-1);
  });

  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', hideModelLightbox);
  else hideModelLightbox();
})();
