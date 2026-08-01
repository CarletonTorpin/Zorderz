(async function () {
  var errEl = document.getElementById('error-message');
  if (!window.ZIC_STRIPE_PK || !window.ZIC_PI_URL) {
    if (errEl) errEl.textContent = 'Payments are not configured yet.';
    return;
  }
  var stripe = Stripe(window.ZIC_STRIPE_PK);
  var resp = await fetch(window.ZIC_PI_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token: window.ZIC_TOKEN })
  });
  var j = await resp.json();
  if (!j.client_secret) {
    if (errEl) errEl.textContent = 'Error: ' + (j.message || 'could not start payment');
    return;
  }
  var elements = stripe.elements({ clientSecret: j.client_secret });
  var pe = elements.create('payment');
  pe.mount('#payment-element');
  document.getElementById('submit').addEventListener('click', async function () {
    var res = await stripe.confirmPayment({
      elements: elements,
      confirmParams: { return_url: window.ZIC_RETURN }
    });
    if (res && res.error && errEl) errEl.textContent = res.error.message;
  });
})();
