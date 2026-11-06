# Kit of Parts Contract — v1

> **Layer:** Core.
> **Files:** `assets/js/zdz-kit.js` (`window.ZDZ.ui`), `assets/css/zdz-kit.css`, `inc/class-zdz-app-groups.php`,
> `inc/class-zdz-person-color.php`.

Without a shared kit each app grows its own spinner, toast, buttons and way of asking the server for data, so no
two apps behave alike and every fix has to be made a dozen times. The theme provides one copy of each. An app that
uses them looks and behaves like the rest without trying.

## Where it loads

- **The app page:** after `bridge.js` (`zdz-kit-js`, `zdz-kit-css`).
- **An app's full view** (its wp-admin page opened inside the app, `?zdz_mobile=1`): the same two files, plus
  the few `zdzData` values the kit reads. There is no shell there, so the kit handles Back for its own sheets.
- **Anywhere else in wp-admin:** not loaded. Guard with `if (window.ZDZ && ZDZ.ui)` if your code runs there too.

## 1. Colors mean something

| Meaning | Color | Icon / text on it |
|---|---|---|
| Sales (default: Chat, Estimates, Leads, Dot Plot) | `#E69F00` orange | dark `#1A1A1A` |
| Field (default: Jobs, Prep, Stock, Receipts) | `#009E73` green | dark |
| Team (default: Scheduler, Messages, Email) | `#56B4E9` sky blue | dark |
| Office (default: Commission, Surveys, Knowledge, Invoices) | `#0072B2` blue | white |
| Tools (default: Camera, Media, Sketch, Game) | `#CC79A7` pink | dark |
| **Needs you** (reserved, never a group) | `#D55E00` vermilion | white |

- **The theme decides an app's color, not the app.** An app's `cc` and `cat` are kept as `cc_plugin`
  and `cat_plugin` and otherwise ignored. **Which group an app sits in, and what the groups are called, is the
  business's choice** (org.yml `app_groups`); the colours are the platform's.
- **A new app** is placed from the category it declares (`sales`, `insight` → Sales; `field`, `ops` → Field;
  `team`, `personal` → Team; `admin`, `finance`, `service`, `office` → Office; anything else → Tools). The
  filter `zdz_app_group( $group, $app_id, $declared_cat )` places it explicitly.
- **In CSS:** `var(--zdz-grp-sales)`, `var(--zdz-grp-sales-ink)` … and `var(--zdz-needs-you)`, printed inline from
  `ZDZ_App_Groups`, so there is one definition.
- **In JS:** `ZDZ.ui.groups()` → `[{id, label, color, ink}, …]`.
- **Don't** use vermilion for anything except "this needs you". Don't color an app's own header or chrome with
  a different hex; take its group's token.

## 2. One badge per person (shape = privilege, a quiet tone)

The more sides a shape has, the more privileges it stands for. A person's badge is their initials on a **shape that comes from their highest role**, in
one of **nine quiet tones** (blue, a blue-leaning teal and gray, three steps of lightness each). Vermilion
stays reserved for "needs you".

| Shape | Who |
|---|---|
| upright triangle | Salesperson, Field Tech, Shop Foreman (and anyone else with initials) |
| square | Operator |
| pentagon | Administrator |
| hexagon | Owner |
| circle | WordPress Administrator |
| plain gray, no shape | a shared device (never a tone) |

An admin can choose a person's tone (Zorderz → User Management, shown as that person's own badge);
everyone else gets the next free tone in user-id order, so a new hire never changes anyone else's. The labels
come from the business's own role names.

```js
ZDZ.ui.personChip(7)            // <span class="zdz-person zdz-person--hexagon" role="img" aria-label="AB · Owner"><svg>…</svg></span>
ZDZ.ui.personChip('FR', { size: 'sm', title: 'Uploaded by' })   // sizes: sm 22 px, default 30 px, lg 44 px
ZDZ.ui.person(7)                // { id, initials, color, ink, edge, shape } — never a name or email
ZDZ.ui.badge('pentagon')        // the shape alone (role badges, legends); ZDZ.ui.shapes() names them
```

PHP: `ZDZ_Person_Color::for_user( $uid )` → `['initials','color','ink','edge','shape','chosen']`;
`ZDZ_Person_Color::shape_for_roles( $roles )`; `ZDZ_Person_Color::tones_for_js()` for a picker.
Every ink reaches 4.5:1 on its tone; the outline (the hue's middle step) keeps a light tone visible on a white
page and a dark tone visible on a dark one. In Sunlight every outline is black.

## 3. Buttons

```html
<button class="zdz-btn zdz-btn--primary">Save</button>
<button class="zdz-btn zdz-btn--secondary">Cancel</button>
<button class="zdz-btn zdz-btn--danger">Delete</button>
<button class="zdz-btn zdz-btn--quiet">More</button>
```

- **Modifiers:** `--block` (full width) and `--sm` (smaller text; still 44 px tall on a phone).
- **Corners:** 12 px rounded, never an oval.
- **Busy:**

```js
ZDZ.ui.busy(button, () => ZDZ.ui.ajax('my_action', { nonce, id }))
  .then(data => …)
  .catch(err => ZDZ.ui.toastError(err, retry));
```

`busy` keeps the button's size (NO-SUBMIT-SHIFT-CONTRACT-v1). A second press while busy gets the same promise
back, so a double tap never sends twice.

The busy mark takes the button's own text color (`--zdz-busy-ink`, read just before the label is hidden), so it
shows on a white button as well as a dark one.

## 4. Loading

```js
const l = ZDZ.ui.loading(el, 'Loading jobs…');  // replaces el's content; l.label('Almost there…'); l.done();
html += ZDZ.ui.loadingHtml('Loading…');         // in a template string
```

Three dots that breathe (opacity only), so it looks the same with Reduce Motion on and still says "working".

## 5. Toasts

```js
ZDZ.ui.toast('Moved to Done', { action: 'Undo', onAction: undo });
ZDZ.ui.toast('Saved', { kind: 'success' });
ZDZ.ui.toastError(err, retry);  // the error's plain sentence, with "Try again" when retry is given
```

- **At most one action.** A toast with an action stays 6 s; a plain one 3 s; an error 5 s.
- **Announcing:** errors are announced at once (`role=alert`); everything else politely.
- **Placement:** the theme's `showToast(msg)` still works and uses the same container.
- **Don't** show a toast *and* change the layout for the same event.

## 6. Sheets

```js
const s = ZDZ.ui.sheet({
  title: 'More tools',
  body: html,                  // a string or a Node
  actions: [
    { label: 'Cancel' },
    { label: 'Send', kind: 'primary', onClick: (sheet) => ZDZ.ui.ajax('x_send', { … }) },
  ],
  onClose: (reason) => {},     // 'action' | 'back' | 'escape' | 'backdrop' | 'close' | 'api'
});
s.body; s.status('Saved', 'success'); s.close(); await s.closed;
```

- **Shape:** a bottom sheet on a phone; a centered 560-pixel dialog at 820 px and wider.
- **Closing:** Back, Escape, the backdrop and the × close it (`dismissible: false` turns off all but ×).
- **Accessibility:** focus stays inside, and returns to where it was on close.
- **Actions:** an action's promise shows busy on its button. The sheet closes when the promise resolves,
  unless it resolves to `false`. A failure is written in the status line above the buttons, which is always
  there, so nothing moves.
- **Back:** the sheet adds one history entry; Back closes the sheet, never the app under it.
- **Open a sheet inside the tap that asks for it.** Browsers ignore a history entry added later (after an
  `await`), and then Back would leave the app instead of closing the sheet.

## 7. Data requests

```js
ZDZ.ui.ajax('zprep_mark_printed', { nonce, job_id: 6401 })   // admin-ajax → the `data` of {success:true}
ZDZ.ui.post(url, { … })                                      // any form POST; unwraps {success,data} too
ZDZ.ui.rest('zorderz/v1/media?mine=1')                            // wp-json, with the X-WP-Nonce header
ZDZ.ui.rest('zorderz/v1/media', { method: 'POST', body: { … } })  // JSON body
```

**Body encoding:**
- Objects are sent as form fields.
- Arrays are sent as `name[]`.
- `true` is sent as `1`.
- Nested objects are sent as JSON strings.

**Errors.** Every failure rejects with a `ZDZ.ui.RequestError`: a `message` a person can read, plus `kind` and
`status`.

| kind | When | Message |
|---|---|---|
| `offline` | no signal | No connection. Check your signal and try again. |
| `timeout` | no answer in 30 s (`timeout:` to change) | The server took too long to answer. Try again. |
| `auth` | 401/403, or admin-ajax `-1`/`0` | You are signed out or not allowed to do that. Reload the app and try again. On a 403 the server's own sentence wins: `message`, `data.message`, or `data` when it is a string, which is what `wp_send_json_error( 'a sentence', 403 )` sends. |
| `server` | 5xx, or an HTML page where JSON was expected (a 502 page, a PHP error) | The server had a problem. Try again in a moment. |
| `app` | `{success:false}` or a WP_Error | the app's own words |
| `response` | a 2xx that was not JSON | The server sent something unexpected. Try again. |

**Retries:**
- A GET is tried once more after a 502/503/504 or a lost signal.
- A POST is never repeated automatically, because it may have written before it failed. Pass `retry: 1`
  only when repeating it is harmless.

## 8. "Needs you"

```js
ZDZ.ui.needsYou('prep', 3);  // a vermilion 3 on Prep's icon, everywhere it is drawn
ZDZ.ui.needsYou('prep', 0);  // gone
window.addEventListener('zdz:needs-you', e => e.detail /* {appId, count} */);
```

- **What it marks:** every element with `data-zdz-app="<id>"`, which is every launcher, dock, recents and
  search plate.
- **Redraws:** the badge survives the launcher redrawing.
- **Inside an app's frame,** the call is passed up to the shell.
- **The count must mean** "things waiting on this person", not "things that exist".

## 9. The paper

`ZDZ.ui.paper(doc, opts)` draws an estimate or invoice laid out like the billing system's own PDF and returns the
element; the caller places it. Use it wherever a billing document is shown, so every app shows the same paper.

- **`doc`** is the shape the estimate app's document-for-viewer endpoint returns (and the charts invoice card uses):
  `kind` (`estimate` | `invoice`; missing means invoice), `number`, `status`, `issued`, `due`, `reference`, `currency`, `billed_to` (lines),
  `lines` (`name`, `description`, `rate`, `qty`, `amount`), `totals` (`subtotal`, `discount`, `tax`, `total`, and on an
  invoice `paid`, `due`) or `null`, `notes`, `terms`, `money`, `business` (`name`, `lines`), `logo` (https).
- **`opts`**: `print` (default true), `note` (one line above the paper), `label` (what a screen reader calls it), and
  `copy`: a function returning a Promise of the customer's share link. With it the bar has **Copy customer link**:
  copied inside the tap (or shown in a read-only box to select), only a whole address matching one of the billing
  connector's customer-link patterns (`zdzData.customerLinkPatterns`, from the `zdz_customer_link_patterns` filter),
  never opened, never an `<a>`; a rejection's `{ text }` is shown. Pass it only for someone who may see that customer's
  name and address, decided on your server. `doc.draft` true → "Still a draft: no customer link yet." instead of a button.
- **No link out:** `opts.link` is ignored; the app never sends people off to the billing system.
- **`ZDZ.ui.paperGhost(text)`**: the blank sheet, the size of a document, with Print waiting, to show while one
  is read; replace it with the paper when it arrives, and nothing on the page moves.
- **Estimate vs invoice:** an estimate reads "Prepared For", "Estimate Date",
  "Estimate Number", has no big amount at the top, and its totals end on "Estimate Total (USD) $…"; an invoice reads "Billed
  To", "Date of Issue", "Invoice Number", with "Amount Due (USD)" at the top and Total / Amount Paid / Amount Due below.
- **Rules it keeps:** text nodes only (a document is never read as HTML); rates and totals only when `doc.money` is
  true; a logo only from https. Who may see the document is decided on the server before it is sent, never here.
- **Fit:** it reflows by its own box (a container query), so a Messages panel and a full page both work. It stays
  white with black ink in dark mode, as paper does. Print prints the paper alone.

## 10. Drawings on a photo

Lines a person draws on a photo, kept apart from the photo (a non-destructive edit). **Anyone who sees a photo may draw
on it, and each person's drawing is their own layer**: seen by them alone until they share it (Only me · Everyone who
can see this photo · Chosen people · This project's people when drawn from a project); an arrow can end in a Post-it
holding a note (≤ 500 characters, written by the drawer); an administrator can choose to see everyone's ("Show
everyone's drawings", off by default). The theme decides who sees what (`ZDZ_Media_Marks`); the kit draws what it is sent. A viewer needs four calls:

```js
var layer = ZDZ.ui.marks.attach(img);                       // once per <img>; the same layer every time
layer.load(item.id, { query: ZDZ.ui.marks.grantFrom(src) }); // when the viewer shows a photo (set(null) for a video)
ZDZ.ui.marks.panel(layer, { anchor: btn, onDraw: draw });    // the "Drawings (N)" button: who drew what, show / hide
layer.edit({ id: item.id, context: 'project:79', done: function (view, saved) { /* counts on the tile */ } });   // Draw
```

- **Lists carry counts, never lines.** An item has `marks_n` (drawings this person sees), `notes_n` and `can_mark`; the
  viewer fetches the drawings of the one photo it opens (`GET /zorderz/v1/media/{id}/marks`). `layer.load` drops a slower
  answer for an earlier photo; loading another photo forgets what was hidden on the last one and closes its list.
- **`layer.on(fn)`** is told whenever what is shown changes (a load, a save, show / hide): label the buttons from
  `layer.count()`, `layer.notes()`, `layer.mine()` (yours, if any) and `layer.canMark()`.
- **The outline:** `ZDZ.ui.marks.outline(tile, item.marks_n, item.notes_n)` puts a 3 px outline and a small badge
  ("✎ 2 · 1 note") on a photo tile; with 0 it takes them off. Call it after the grid renders and after a save.
- **The grant:** an app that shows a private photo through its own signed grant (the jobs app's `zprj_g` / `zprj_e`)
  passes it as `query` (`grantFrom(src)` reads it off the photo's address), so the drawing routes answer for whoever the
  photo was shown to.
- **Context:** a drawing first made from a Project passes `context: 'project:<id>'`; it starts shared with that
  project's people (the theme asks the `zdz_media_layer_audience` filter; the jobs app answers), and the drawer can change it.
- **The viewer does nothing else.** The layer is an SVG laid over the `<img>` with the same box, the photo's own size as
  its viewBox, the same fit (`object-fit`) and the image's transform copied across, so zoom, pan and a rotate preview
  carry the drawings. Outside drawing, only a Post-it takes a tap (its note card opens; nothing under it sees the tap).
- **Draw holds the page.** While the bar is open every tap, key and gesture outside it is stopped at the window before
  any viewer sees it. Escape cancels. If the viewer closes under the pen, the drawing ends and nothing is saved. The
  bar: Pen / Note, five pens, three widths, Undo, Clear, Who sees it, Move bar, Cancel, Save; it wraps on a phone.
- **Saving** posts the person's own layer `{ rev, strokes, context?, share?, people? }` to `POST /zorderz/v1/media/{id}/marks`;
  another person saving at the same moment never refuses it (the server retries); a 409 only when the same person's own
  drawing moved (two devices), and the bar then shows what is there now. The kit never sends an author.
- Popups (the note card, the Drawings list) sit above every viewer (z-index 100095) and close with Escape or a tap
  outside. Notes and names are text nodes only, never markup.

## Testing checklist

1. The app's buttons are `.zdz-btn`; none is an oval; each is ≥ 44 px tall on a phone.
2. Its loading states use `ZDZ.ui.loading`. Turn on Reduce Motion: they still breathe.
3. Its requests go through `ZDZ.ui.ajax` / `post` / `rest`. Turn the network off: a sentence appears, not a
   blank or a spinner forever.
4. Its sheets close with Back, and the app stays open.
5. All four theme modes (light, dark, system, sunlight).

*— End of Kit of Parts Contract v1 —*

## Focus view

Apps open as their own page. What a widget can rely on inside that page (the one scroll, `zdz_app_focus` /
`zdz_app_blur`, `data-zdz-fill`, `--zdz-focus-h`, Pin to Home) is in **FOCUS-VIEW-CONTRACT-v1.md**.
