# Widget Overflow Contract — v1

> **Status:** Stable — plugins depend on this contract
> **Since:** Theme v2.20.1
> **Formalized:** Theme v2.20.3
> **Depends on:** None
> **Depended on by:** All inline_widget plugins

---

## The Contract

`.dash-widget-body` uses `overflow: clip` — NOT `hidden`, NOT `auto`, NOT `scroll`.

```css
.dash-widget-body {
  overflow: clip;
}
```

This is a **hard contract**. Changing this property breaks plugin sticky positioning.

---

## Why This Matters

`overflow: clip` and `overflow: hidden` look identical visually — both clip content that exceeds the container. But they behave differently for layout:

| Property         | Creates scroll container? | `position: sticky` works inside? |
|------------------|--------------------------|----------------------------------|
| `overflow: clip`   | NO                       | YES — sticky binds to page scroll |
| `overflow: hidden` | YES                      | NO — sticky binds to the hidden scroll container |
| `overflow: auto`   | YES                      | NO — same problem as hidden |
| `overflow: scroll`  | YES                      | NO — same problem |

When a plugin places `position: sticky` on an element inside `.dash-widget-body` (like a save button at the bottom or a section header), it needs to stick relative to the **page-level scroll**, not a nested scroll container. `overflow: clip` is the only overflow value that clips visual overflow without creating a scrolling context.

---

## Plugins That Depend on This

### zdz-satisfaction-surveys v2.9.3
```css
.dash-widget-body .zdz-sw-section-header {
  position: sticky;
  top: calc(var(--dash-top-h) + var(--dash-sticky-h));
}
```
The batch history header sticks below the dashboard top bar. If `.dash-widget-body` used `overflow: hidden`, this header would stick at the top of an invisible scroll container and never actually appear sticky to the user.

### zdz-sketch-pad v1.0.3
```css
.zsp-w-actions {
  position: sticky;
  bottom: 0;
}
```
The save action bar sticks to the bottom of the viewport. Same dependency — needs page-level scroll binding.

### Future inline_widget plugins
Any plugin registered with `bridge_type: 'inline_widget'` renders inside `.dash-widget-body`. If that plugin uses `position: sticky` on any element, it depends on this contract.

---

## What You Must NOT Do

1. **Do NOT change `overflow: clip` to `overflow: hidden`** on `.dash-widget-body`. It looks the same but breaks sticky positioning in all plugins.

2. **Do NOT add `overflow-y: auto` or `max-height`** to `.dash-widget-body`. This was the original pre-v2.20.1 behavior (`max-height: 65vh; overflow-y: auto`) that created double-scroll traps and broke sticky elements. It was removed in v2.20.1 for exactly this reason.

3. **Do NOT add `contain: layout`** to `.dash-widget-body` or its parent. CSS containment creates a new formatting context that traps sticky positioning.

---

## What You CAN Do

- Add `overflow: clip` to **child** containers within `.dash-widget-body` if a specific widget needs visual clipping. The contract only applies to `.dash-widget-body` itself.
- Use `overflow-clip-margin` to adjust how far clipping extends beyond the border box.
- Add `isolation: isolate` to widget containers (this creates a stacking context for z-index but does NOT affect scroll containers).

---

## Testing Checklist

After any CSS change to `.dash-widget-body` or its ancestors:

1. Open Satisfaction Surveys widget → scroll the page → batch history header should stick below the top bar
2. Open Sketch Pad widget → draw something → save button should be visible without scrolling inside the widget
3. On mobile (<820px) → verify no double-scroll (page scrolls as one document, no nested scrollbars inside widgets)

---

*— End of Widget Overflow Contract v1 —*
