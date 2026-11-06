/*
 * Settings, Agreements: the Key panel.
 *   Make a key: 32 random bytes from crypto.getRandomValues, here in the administrator's browser, shown once as base64.
 *   Nothing is sent until the key is pasted back exactly; only then is the hidden field filled and Save turned on.
 *   Enter a key: a saved key pasted in (a restore after the site's secret keys change).
 * The key is never written to the console, to storage, or to the address bar, and the fields are cleared on leaving.
 */
(function () {
  'use strict';
  function $(id) { return document.getElementById(id); }
  var make = $('zagrMake'), enter = $('zagrEnter'), save = $('zagrSave'), value = $('zagrValue');
  if (!make || !enter || !save || !value) { return; }
  var made = $('zagrMade'), back = $('zagrBack'), paste = $('zagrPaste');
  var madeBox = $('zagrMadeBox'), enterBox = $('zagrEnterBox');
  var mode = '';

  function clean(s) { return String(s || '').replace(/\s+/g, ''); }
  function b64(bytes) { var s = ''; for (var i = 0; i < bytes.length; i++) { s += String.fromCharCode(bytes[i]); } return window.btoa(s); }
  function validKey(s) {
    s = clean(s).replace(/-/g, '+').replace(/_/g, '/');
    if (!/^[A-Za-z0-9+/]+={0,2}$/.test(s)) { return false; }
    try { return window.atob(s).length === 32; } catch (e) { return false; }
  }
  function update() {
    value.value = '';
    save.disabled = true;
    if (mode === 'make' && made.value !== '' && clean(back.value) === clean(made.value)) {
      value.value = clean(made.value);
      save.disabled = false;
    } else if (mode === 'enter' && validKey(paste.value)) {
      value.value = clean(paste.value);
      save.disabled = false;
    }
  }

  make.addEventListener('click', function () {
    var bytes = new Uint8Array(32);
    window.crypto.getRandomValues(bytes);
    made.value = b64(bytes);
    for (var i = 0; i < bytes.length; i++) { bytes[i] = 0; }
    back.value = '';
    paste.value = '';
    mode = 'make';
    madeBox.hidden = false;
    enterBox.hidden = true;
    made.focus();
    made.select();
    update();
  });
  enter.addEventListener('click', function () {
    made.value = '';
    back.value = '';
    mode = 'enter';
    madeBox.hidden = true;
    enterBox.hidden = false;
    paste.focus();
    update();
  });
  back.addEventListener('input', update);
  paste.addEventListener('input', update);
  save.form.addEventListener('submit', function (ev) {
    if (save.disabled || value.value === '') { ev.preventDefault(); return; }
    made.value = '';
    back.value = '';
    paste.value = '';
  });
  window.addEventListener('pagehide', function () { made.value = ''; back.value = ''; paste.value = ''; value.value = ''; });
})();
