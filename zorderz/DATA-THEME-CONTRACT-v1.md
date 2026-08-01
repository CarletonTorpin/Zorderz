# Data-Theme Attribute Contract — v1

> **Status:** Stable — plugins depend on this contract
> **Since:** Theme v2.14.3 (4-theme system)
> **Formalized:** Theme v2.20.3
> **Depends on:** None
> **Depended on by:** All plugins with dark-mode support

---

## The Contract

The `<html>` element always has a `data-theme` attribute set to one of four stable values:

| Value       | Meaning                                              |
|-------------|------------------------------------------------------|
| `"light"`   | Light mode (user explicitly selected)                |
| `"dark"`    | Dark mode (user explicitly selected)                 |
| `"system"`  | Follow OS preference (auto light/dark)               |
| `"sunlight"` | High-contrast light mode for outdoor field use      |

These are the **only** values. The attribute is **always present** — it is never absent, empty, or set to any other value.

---

## How the Theme Sets It

```javascript
// app.js — setTheme()
document.documentElement.setAttribute('data-theme', theme);
```

The theme is read from `localStorage` on boot, defaulting to `"system"` for new users. The attribute is set before the first paint via inline `<script>` in `header.php`.

---

## How Plugins Should Use It

### CSS: Targeting specific themes

```css
/* Dark mode — explicit selection */
[data-theme="dark"] .my-plugin-element {
  background: #1a1a1a;
  color: #e0e0e0;
}

/* System mode — follows OS dark preference */
[data-theme="system"] .my-plugin-element {
  /* Default to light styles (no override needed) */
}
@media (prefers-color-scheme: dark) {
  [data-theme="system"] .my-plugin-element {
    background: #1a1a1a;
    color: #e0e0e0;
  }
}

/* Sunlight mode — high contrast for outdoor use */
[data-theme="sunlight"] .my-plugin-element {
  background: #ffffff;
  color: #000000;
}
```

### CSS: Preferred approach — use theme CSS variables

Rather than targeting `data-theme` directly, prefer the theme's CSS custom properties which automatically resolve correctly in all four modes:

```css
.my-plugin-element {
  background: var(--sys-surface);
  color: var(--sys-text);
  border: 1px solid var(--sys-border);
}
```

The `--sys-*` and `--ref-*` tokens are defined in `app.css` with overrides for each `data-theme` value. Using them means your plugin automatically works in all current and future theme modes without any `[data-theme=...]` selectors.

### JavaScript: Reading the current theme

```javascript
const theme = document.documentElement.getAttribute('data-theme');
// Returns: "light" | "dark" | "system" | "sunlight"
```

### JavaScript: Observing theme changes

```javascript
const observer = new MutationObserver(mutations => {
  for (const m of mutations) {
    if (m.attributeName === 'data-theme') {
      const newTheme = document.documentElement.getAttribute('data-theme');
      // React to theme change
    }
  }
});
observer.observe(document.documentElement, { attributes: true });
```

---

## What You Must NOT Do

1. **Do NOT check for attribute absence.** The pre-v2.14.3 pattern `:root:not([data-theme])` as a dark-mode fallback does not work — the attribute is always present. This was the root cause of the Sketch Pad v1.0.2 dark mode bug (white pen on yellow paper when OS was in dark mode with `data-theme="system"`).

   ```css
   /* ❌ WRONG — never matches because data-theme is always set */
   :root:not([data-theme]) .my-element { ... }

   /* ✅ CORRECT — target system mode + OS dark preference */
   @media (prefers-color-scheme: dark) {
     [data-theme="system"] .my-element { ... }
   }
   ```

2. **Do NOT invent new values.** Only the four documented values exist. If you need a plugin-specific visual mode, use a separate attribute on your own container element.

3. **Do NOT remove or clear the attribute.** It must always be present with a valid value.

---

## Available CSS Custom Properties

The theme defines these token families, resolved per-mode:

| Prefix    | Purpose                          | Example                     |
|-----------|----------------------------------|-----------------------------|
| `--sys-*` | Semantic system tokens           | `--sys-surface`, `--sys-text`, `--sys-border` |
| `--ref-*` | Reference/design tokens          | `--ref-space-3`, `--ref-radius-lg` |

### Key tokens for plugin development

> **Updated v2.21.0** with the real values from `style.css` (the prior table held illustrative hexes that did not match the code) and measured WCAG ratios on the **widget surface** (`--sys-surface`), which is what plugin widgets actually sit on.

| Token            | Light (`#fff` surface) | Dark/System (`#1E293B` surface) | Sunlight (`#fff` surface) |
|------------------|------------------------|----------------------------------|---------------------------|
| `--sys-surface`  | `#FFFFFF`              | `#1E293B` (gray-800)             | `#FFFFFF`                 |
| `--sys-surface-raised` | `#F8FAFC`        | `#334155` (gray-700)             | `#F1F5F9`                 |
| `--sys-text`     | `#0F172A` (≈18:1)      | `#F1F5F9` (≈14:1)                | `#000000` (21:1)          |
| `--sys-text-sec` | `#475569` (7.58:1)     | `#CBD5E1` (9.85:1) **↑v2.21**    | `#000000` (21:1)          |
| `--sys-text-ter` | `#475569` (7.58:1) **↑v2.21** | `#94A3B8` (5.71:1) **↑v2.21** | `#1a1a1a` (≈19:1)     |
| `--sys-border`   | `#E2E8F0`              | `#334155`                        | `#000000`                 |
| `--sys-brand`    | `#2C5F8A`              | `#4796F7`                        | `#000000`                 |

**v2.21.0 contrast retune (why these changed):** the secondary/tertiary text tokens were previously tuned for contrast against the *page background*, but plugin widgets render on the lighter `--sys-surface`. On dark mode, the old `--sys-text-ter` (gray-500) was only **3.07:1** on the widget surface — a WCAG failure, and the source of the "gray text on dark-gray" field report. Values were raised so **every** `--sys-text-*` token clears AA (≥4.5:1) on `--sys-surface`, with secondary text at AAA. Light-mode tertiary was raised so it also passes on the `--sys-bg` header zone (gray-100), not only on white.

---

## Widget Body Readability Contract (v2.21.0)

Because the theme cannot reach inside a plugin's widget markup, **plugins are responsible for honoring these minimums** — and the theme guarantees the tokens that make them achievable.

1. **Body text** in a widget MUST use `≥ --ref-font-sm` (18px on phones as of v2.21.0) and SHOULD use `--ref-font-base` (20px on phones). Never render body copy below 16px on mobile.
2. **Secondary text** MUST use `--sys-text-sec` (now guaranteed ≥4.5:1 on `--sys-surface`) — never a hardcoded gray (`#999`, `#aaa`, etc.). Hardcoded grays were the cause of the dark-mode low-contrast reports.
3. **Section headers inside a widget** (e.g. a Leads "All Leads" / "Generate Leads" header) MUST use `--sys-text` (primary). Do **not** use a faint accent color on a dark surface — that renders nearly invisible. If you want an accent, pair it with sufficient weight/size and verify ≥4.5:1.
4. **Verify in light AND dark.** A color that passes in one mode can fail in the other. Use the table above; when in doubt, measure against `--sys-surface`.

---

## Logo Variant Contract (v2.21.0)

The bottom-nav logo and login logo are **theme-aware** and require BOTH variants to be uploaded in the Customizer:

| Theme mod        | Must contain                         | Used in                       |
|------------------|--------------------------------------|-------------------------------|
| `zdz_logo_light`  | a **dark-inked** wordmark (dark text)| light mode **and** sunlight   |
| `zdz_logo_dark`   | a **light-inked** wordmark (light text) | dark mode                  |
| `zdz_logo_vertical` | optional vertical lockup           | ≥820px sidebar                |

**The rule:** light/sunlight modes need a *dark-inked* logo; dark mode needs a *light-inked* logo. If only one variant is uploaded, the theme falls back to it for both modes (`light || dark` / `dark || light`), which can render a **low-contrast or invisible wordmark** — e.g. a light-inked logo shown on the near-white light-mode nav bar (the v2.21.0 field report). Always upload both. A defensive neutral chip is painted behind the nav logo in light/sunlight as a backstop, but it is **not** a substitute for the correct asset.

---

## Available CSS Custom Properties

`--sys-surface` **must always resolve to an opaque color** in all modes. Plugins use it for sticky header backgrounds (see WIDGET-OVERFLOW-CONTRACT-v1.md). A semi-transparent or `inherit` value would cause content to peek through behind sticky elements.

---

## Testing Checklist

After any theme-related CSS change in a plugin:

1. Switch to each of the 4 themes in Settings → verify colors render correctly
2. Set theme to `system` → toggle OS dark mode → verify plugin responds
3. Set theme to `sunlight` → verify high-contrast rendering (text readable in direct sunlight)
4. Verify no hardcoded colors remain — all colors should come from `--sys-*` tokens or `[data-theme]` selectors

---

*— End of Data-Theme Attribute Contract v1 —*
