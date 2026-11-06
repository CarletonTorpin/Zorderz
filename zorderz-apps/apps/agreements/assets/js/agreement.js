/*
 * The agreement page's only script (the CSP allows nothing else).
 * Every step works without it: the server checks each one. This only makes the page easier on a phone:
 *   - a button with data-needs="id id" stays off until each named box is ticked, each named name field has a name, and
 *     a named code field has its 6 digits;
 *   - after the PDF is opened (the link, or coming back to the tab), the consent box opens (the server still refuses
 *     consent unless it sent this session the PDF);
 *   - the one typed name shows at each signature as it is typed; the code box keeps digits only;
 *   - the cancel-by countdown counts down;
 *   - a form is sent once (its buttons turn off on submit).
 * No requests of its own (no fetch): forms post to the page and the page answers.
 */
(function () {
  'use strict';
  function $(id) { return document.getElementById(id); }
  function each(sel, fn) { Array.prototype.forEach.call(document.querySelectorAll(sel), fn); }
  var letters = /[A-Za-zÀ-ɏ].*[A-Za-zÀ-ɏ]/;

  function filled(id) {
    var i = $(id);
    if (!i || i.disabled) { return false; }
    if (i.type === 'checkbox') { return i.checked; }
    if (i.getAttribute('inputmode') === 'numeric') { return /^\d{6}$/.test(i.value.trim()); }
    return letters.test(i.value.trim());
  }
  function gate() {
    each('[data-needs]', function (b) {
      var ids = (b.getAttribute('data-needs') || '').split(/\s+/).filter(Boolean);
      b.disabled = !ids.every(filled);
    });
  }

  // 1. the PDF preview opened: open the consent box (optimistic; the server decides)
  var opened = false;
  function afterPdf() {
    if (opened) { return; }
    opened = true;
    var b = $('consentBox');
    if (b) { b.disabled = false; }
    var done = $('pdfDoneJs');
    if (done) { done.hidden = false; }
    gate();
  }
  var pdf = $('pdfBtn');
  if (pdf && $('consentBox') && $('consentBox').disabled) {
    pdf.addEventListener('click', function () {
      window.setTimeout(afterPdf, 1500);
      document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') { afterPdf(); } });
    });
  }

  // the one typed name, shown at each signature as it is typed
  each('input[name="sig"]', function (i) {
    var show = function () { each('[data-mirror="' + i.id + '"]', function (o) { o.textContent = i.value; }); };
    i.addEventListener('input', show);
    show();
  });

  // the code: digits only
  each('input[autocomplete="one-time-code"]', function (i) {
    i.addEventListener('input', function () {
      var d = i.value.replace(/\D/g, '').slice(0, 6);
      if (d !== i.value) { i.value = d; }
    });
  });

  // every input re-checks the buttons
  each('input', function (i) { i.addEventListener('input', gate); i.addEventListener('change', gate); });
  gate();

  // a form is sent once
  each('form', function (f) {
    f.addEventListener('submit', function () {
      window.setTimeout(function () { each('button[type="submit"]', function (b) { b.disabled = true; }); }, 0);
    });
  });

  // the countdown to local midnight on the cancel-by day
  var cd = $('countdown');
  if (cd) {
    var end = parseInt(cd.getAttribute('data-end'), 10) * 1000;
    var tick = function () {
      var s = Math.max(0, Math.floor((end - Date.now()) / 1000));
      var d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600);
      cd.textContent = d + (d === 1 ? ' day' : ' days') + ' and ' + h + (h === 1 ? ' hour' : ' hours') + ' left.';
    };
    if (end > 0) { tick(); window.setInterval(tick, 60000); }
  }
})();
