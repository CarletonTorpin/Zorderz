# No-Submit-Shift Contract (v1)

> **Status:** Stable (new in Theme v2.21.0)
> **Depends on:** `--sys-*` tokens (DATA-THEME-CONTRACT), `.dash-widget-body { overflow: clip }` (WIDGET-OVERFLOW-CONTRACT)
> **Depended on by (should adopt):** TSSV (Surveys), TSL (Leads), TSCC (Commissions), TSEC (Estimates): any widget with an action button that triggers a server round-trip.

---

## The problem this fixes

When a user taps an action button inside a dashboard widget ("Send to CRM", "Run New Batch", "Parse Estimate", a survey action) and the widget **re-renders / reflows / scrolls**, the user loses their place and can't tell what happened or what's next. This is a Cumulative Layout Shift (CLS) failure and was a repeated field complaint.

**Desired behavior:** the user **stays on the button**, the layout **does not move**, an inline **"working… / updating"** indicator appears in a space that was already reserved, and the result is **patched in place**.

---

## The rules (CLS-correct by construction)

1. **Keep the button's box dimensions while busy.** Do not let the label text change resize the button. Swap to a spinner *inside the same box*.
2. **Reserve the status region.** The status slot must occupy its space **even when empty**, so revealing a message never pushes anything. Never inject a status node *above* existing content without reserved space.
3. **Animate with `opacity`/`transform` only**: never `width`, `height`, `top`, or `left`.
4. **Patch results in place on success**: do not re-render or replace the whole widget, and do not scroll the viewport.
5. **Announce to assistive tech**: set `aria-busy="true"` on the busy button and use a visually-hidden `aria-live="polite"` region for the status text.

(These mirror the CLS guidance in web.dev / Core Web Vitals: reserve space for post-interaction content, transform/opacity for motion, never inject above existing content.)

---

## Shared affordances the theme provides

These ship in `app.css` (v2.21.0). Plugins opt in by toggling the classes.

### `.zdz-btn-busy`: the busy button state

Add it to your existing button (works on `.btn`, `.btn-brand`, `.btn-outline`, `.btn-sm`, or your own button). It:

- keeps the button's exact dimensions,
- hides the label (`color:transparent` + children `visibility:hidden`),
- centers a spinner via `::after`,
- disables pointer events.

```js
btn.classList.add('zdz-btn-busy');
btn.setAttribute('aria-busy', 'true');
// ...await the request...
btn.classList.remove('zdz-btn-busy');
btn.removeAttribute('aria-busy');
```

The spinner inks itself from the button's intended text color: `--sys-text-brand` for filled buttons (default) and `--sys-text` for `.btn-outline`. If your button is light-on-light or needs the text ink, add `.zdz-btn-busy-ink-text`.

### `.zdz-inline-status`: the reserved status slot

Place an **always-present** status element directly above or below the action button. It has a reserved `min-height`, an opaque `--sys-surface` background, and fades in via opacity.

```html
<div class="zdz-inline-status" id="cc-status" role="status" aria-live="polite"></div>
<button class="btn btn-brand" id="cc-run">Run New Batch</button>
```

```js
function showStatus(el, msg, kind /* '', 'success', 'error' */) {
  el.className = 'zdz-inline-status is-visible' + (kind ? ' is-' + kind : '');
  el.innerHTML = (kind ? '' : '<span class="zdz-inline-spinner"></span>') + msg;
}
function clearStatus(el) { el.className = 'zdz-inline-status'; el.textContent = ''; }

// usage
showStatus(statusEl, 'Working… this will update in a moment');   // spinner + text
// on success:
showStatus(statusEl, 'Sent to CRM ✓', 'success');                // green, no spinner
// on error:
showStatus(statusEl, 'Could not send, tap to retry', 'error');  // red
```

Modifiers: `.is-visible` (fades in), `.is-success` (green text), `.is-error` (red text), `.zdz-inline-spinner` (a small inline spinner element). Sunlight mode gets a 2px black border automatically.

---

## Reference pattern (full handler)

```js
async function onSubmit(btn, statusEl, doRequest) {
  // 1. Busy the button in place: no reflow.
  btn.classList.add('zdz-btn-busy');
  btn.setAttribute('aria-busy', 'true');
  showStatus(statusEl, 'Working…');           // appears in reserved space

  try {
    const result = await doRequest();          // your AJAX/REST call
    // 2. Patch the result IN PLACE: do not re-render the whole widget.
    applyResultInPlace(result);
    showStatus(statusEl, 'Done ✓', 'success');
    setTimeout(() => clearStatus(statusEl), 3000);
  } catch (e) {
    showStatus(statusEl, 'Something went wrong, tap to retry', 'error');
  } finally {
    btn.classList.remove('zdz-btn-busy');
    btn.removeAttribute('aria-busy');
  }
}
```

---

## What NOT to do

- ❌ `widget.innerHTML = newHtml` on success (full re-render = guaranteed shift).
- ❌ `element.scrollIntoView()` after submit (yanks the user away).
- ❌ Showing a toast *and* collapsing/expanding the form (double shift).
- ❌ Inserting a status banner above the form with no reserved space (pushes everything down).
- ❌ Animating the button's `height`/`width` between states.

---

## Testing checklist

1. Tap the action button → the button stays exactly where it is, becomes a spinner of the same size.
2. The "working…" message appears **without** anything else moving.
3. On success, the result appears **without** a scroll jump or full re-render.
4. Verify in all four theme modes (light, dark, system, sunlight).
5. With VoiceOver/TalkBack on, the busy state and status are announced.
6. Measure CLS (Chrome DevTools Performance → Layout Shift) across the action: should be ~0.

---

*End of No-Submit-Shift Contract v1*
