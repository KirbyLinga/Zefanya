# CSS → Tailwind Migration Ledger

Running tracker for the Zefanya CSS → Tailwind v4 migration.
Update after EVERY chunk. Owner: Cline + developer.

Confirmed decision: Tailwind v4 with `@tailwindcss/vite`, single entry
`resources/css/app.css`, tokens in `@theme` (no `tailwind.config.js`).

---

## Migration order & status

| # | Area / chunk | Status |
|---|--------------|--------|
| 1 | Setup (vite plugin, `app.css`, `@theme`) | ✅ Done |
| 2 | Landing — shared shell (base wrapper, navbar, footer) | ✅ Converted — awaiting dev visual verify |
| 3 | Landing — hero (`_hero.scss`) | ✅ Converted — awaiting dev visual verify |
| 4 | Landing — Explore Categories carousel | ✅ Converted — awaiting dev visual verify |
| 5 | Landing — Recommended products + `Components/product-card` | ✅ Converted — awaiting dev visual verify |
| 6 | Landing — delete `landing.scss` + partials, remove from vite.config | ✅ DONE (dev pre-approved; git-recoverable) |
| 7 | Buyer — setup (`app.css` first in buyer layout, buyer tokens, navbar `variant`) | ✅ Done |
| 8 | Buyer — page-by-page audit | ✅ Done (findings below) |
| 9 | Buyer — chunk a: footer (`Buyer/Layouts/footer.blade.php`) + removed `design-system.css` from the buyer layout (Hazard #1 root-cause fix) | ✅ Converted — awaiting dev visual verify |
| 9b | Landing — re-check needed? Landing never loaded `design-system.css`, so Hazards #1 does not affect it. | ✅ No action |
| 10 | Buyer — chunk b: Home + `home.css` + `home.js` card strings | ✅ Converted — awaiting dev visual verify (1 flagged deviation) |
| 11 | Buyer — chunk c: layout widgets (cart drawer / quick-view / chat / toast) | ⬜ |
| 12 | Buyer — chunk d: wrap-up (delete `buyer.css`, `auth-buttons.css`, 6 stub files, navbar legacy classes) | ⬜ |
| 13+ | Seller → Admin | ⬜ |

### Chunk 3/4 technical notes (verified in built CSS)
- `--cat-per-view` remains a REAL custom property, set via Tailwind
  arbitrary-property classes on `#catCarousel`: base 5, `max-lg` → 4,
  `max-sm` → 2. All three declarations confirmed in the build output; the
  card `flex-basis: calc(…var(--cat-per-view)…)` is intact; JS still reads
  it via `getComputedStyle()`. `--cat-gap` was inlined as `gap-4` (16px);
  the JS reads computed `gap`, so behavior is unchanged.
- Carousel dots: the JS dot factory now emits full Tailwind utility
  classes and `go()` toggles `w-[22px]` + `bg-primary-800` instead of the
  old `.cat-dot.active` state class. No custom state CSS remains.
- Hero ≤1024px headline replicated as `text-[46px]` — the OLD cascade had
  `_products.scss` overriding `_hero.scss`'s 40px at that breakpoint.
- `button:focus-visible` blue ring (was in `_base.scss`) preserved in
  `app.css` base layer with a TODO — likely an accident, confirm with dev.

### Deletions performed (landing wrap-up, dev pre-approved)
- Deleted: `resources/scss/landing.scss` + `resources/scss/landing/*` (8
  partials) + now-empty `resources/scss/` dir.
- Deleted: `resources/views/welcome.blade.php` — unrouted dead starter
  view (routes `/` and `/shop` render `LandingPage.index`). It had been
  referencing `app.css`/`app.js` — the CSS ref accidentally became valid
  once the real `app.css` existed; now moot.
- Discovery: git tracked `resources/css/landing.css`, but it had already
  been deleted from the working tree BEFORE this migration began (git
  status `D`) — that's why the buyer layout reference was broken.
- `sass-embedded` is now UNUSED (no .scss left) — removal pending dev OK
  (package.json/package-lock.json also carry pre-existing uncommitted
  changes from the earlier Tailwind install).

---

## SHARED CLASS CONTRACTS (do not break)

### 1. Navbar `auth-btn login-btn` / `auth-btn register-btn` (landing ↔ buyer)
- `Components/navbar.blade.php` is included by the landing layout AND
  `Buyer/Layouts/app.blade.php`.
- Buyer loads `resources/css/auth-buttons.css`, which styles these classes
  (dark Login button / outlined Register button). Landing currently relies
  only on the Tailwind utilities added next to them.
- → TODO tracked inline in the navbar component. Do NOT remove these
  classes until the Buyer phase explicitly re-homes that styling.
- TODO(Buyer phase): replace buyer's Font Awesome usage? (see Flags)
- ⚠️ **RADIUS FINDING (Buyer phase).** `auth-buttons.css` sets
  `border-radius: var(--radius-sm)` = **4px** on `.html-body .auth-btn`
  (specificity 0,2,0, unlayered) which **beats** Tailwind's `rounded-md`
  (6px). So the buyer buttons currently render at 4px; deleting
  `auth-buttons.css` without a fix would silently shift them to 6px on the
  last day of the phase — exactly the kind of drift this section exists to
  catch. Fixed: radius moved OFF the shared `$authLinkBase` and onto each
  variant — buyer = `rounded-[4px]` (4px, matches legacy), landing =
  `rounded-md` (landing never loads auth-buttons.css). Verified other props
  are already 1:1 with the legacy rule: 13px/`font-semibold`/uppercase/
  `tracking-[0.8px]`/`px-5 py-2` (20px 8px) and the ≤640px override
  `max-sm:px-4 max-sm:text-xs` (16px 8px / 12px). `border` on the buyer
  register button is re-stated as a utility because the legacy rule's
  `border: none` currently wins the tie.

### 2. Carousel `--cat-per-view` / `--cat-gap` (Chunk 4 — CRITICAL)
- The carousel script in `LandingPage/index.blade.php` reads
  `getComputedStyle(carousel).getPropertyValue('--cat-per-view')` and the
  track layout uses `var(--cat-per-view)` / `var(--cat-gap)` inside
  `flex-basis` calc().
- They MUST remain real CSS custom properties, including the responsive
  redefinitions (`--cat-per-view: 4` @≤1024px, `: 2` @≤640px). Inline them
  via a small `style=""` attribute or a scoped rule — NEVER as static
  Tailwind utility classes. Head-up comment added above the carousel wrap.
- The script also toggles `active` on generated `.cat-dot` buttons — when
  converting, either keep a tiny `.cat-dot.active` state rule or emit full
  Tailwind classes from the JS dot factory.

### 3. `resources/js/buyer/home.js` ↔ product-card classes (Buyer chunk b — CRITICAL)
- Same failure mode as §2, and the dev flagged it in advance: `flashCard()` /
  `recoCard()` assemble product cards as HTML **strings** carrying the very
  classes `buyer.css` styles for Blade-rendered cards (`.card`,
  `.card-media`, `.tag` / `.tag.new`, `.wish-toggle`(+`.active`),
  `.card-body`, `.name`, `.price-row`, `.price`, `.price-old`, `.sold-bar`,
  `.sold-label`, `.add-btn`). Converting the card markup without editing
  these strings — or vice versa — silently ships an unstyled/stale grid that
  *looks* fine on first load because the Blade-rendered part still works.
- ⇒ In chunk b, the Blade markup and BOTH JS template functions MUST be
  edited in the SAME change, and the result must be checked on the
  JS-generated grid (flash sale + recommended), not just the Blade one.
- Runtime-toggled state hooks that must still work afterwards:
  `this.classList.toggle('active')` on `.wish-toggle` (heart turns red) and
  `.tab.active` in the tab switcher. Keep a minimal state rule or toggle
  Tailwind utilities from the JS.
- ⚠️ Guard comment added at the top of `home.js` pointing back to this entry.

---

## Legacy token re-export (`app.css` @layer base :root)

Live list of which legacy CSS custom properties are still consumed by
not-yet-migrated CSS files, per chunk. Slim this block down at the very end.

⛔ BLOCKER (dev-flagged): the final `:root` slimdown CANNOT be done per-area.
`Auth/Register-Type`, `Auth/register-buyer-pending`,
`Buyer/register-buyer-pending`, and `Buyer/register-buyer-check-email` all
`@extends('Layouts.footer')`, so `Layouts/footer` is a SHARED dependency of
both the landing/buyer chunks AND these four auth pages. Do not attempt the
slimdown once buyer wraps — wait until landing + buyer + auth consumers are ALL
converted.

### After Chunk 2 (landing shell):
| File (still loaded on Layouts.footer pages) | Legacy tokens consumed |
|---|---|
| `css/login-modal.css` | `--font-serif`, `--font-sans`, `--bg-surface`, `--text-secondary`, `--text-muted`, `--text-inverse`, `--border-light`, `--border-default`, `--border-focus`, `--color-primary-100/700/800`, `--color-neutral-950`, `--radius-sm/lg/full`, `--shadow-sm/md/lg`, `--space-2/3/4/5/6` |
| `css/auth-pages.css` (via `auth.css`) | `--font-serif`, `--font-sans`, `--text-primary/secondary`, `--bg-surface`, `--border-light/default`, `--color-primary-50/800`, `--color-secondary-300`, `--radius-sm/lg/full`, `--shadow-sm/md`, `--space-1/2/3/4/5/6/8/10/12/16`, **`--space-24` (⚠️ UNDEFINED — see Bugs)** |
| `css/buyer/buyer-register-modal.css` (via `auth.css`) | `--font-serif`, `--font-sans`, `--bg-surface`, `--text-primary/secondary/muted/inverse`, `--border-light/default/focus`, `--color-primary-100/700/800`, `--color-secondary-300`, `--color-neutral-950`, `--color-error`, `--radius-sm/lg/full`, `--shadow-lg`, `--space-1/2/3/4/5/6/8` |
| (landing SCSS — REMOVED, no longer consumes tokens) | — |

### After Buyer chunk a (footer converted) — files loaded by `Buyer/Layouts/app.blade.php`
Buyer layout `@vite` order: `app.css`, `login-modal.css`,
`auth-buttons.css`, `buyer/buyer.css`, `buyer/buyer-register-modal.css`.
**`design-system.css` was REMOVED here in chunk a** (it used to sit second) —
see Hazard #1. Its tokens are re-exported by `app.css`.
| File | Legacy tokens consumed |
|---|---|
| `css/login-modal.css` | same set as the Chunk 2 row above |
| `css/buyer/buyer-register-modal.css` | same set as the Chunk 2 row above |
| `css/auth-buttons.css` | `--font-sans`, `--space-3`, `--space-2`, `--space-5`, `--space-4`, `--radius-sm`, `--color-neutral-950`, `--text-inverse`, `--color-primary-800` (exact list read from file) |
| `css/buyer/buyer.css` | **self-contained** — its own `:root` (`--primary/-dark`, `--secondary`, `--tertiary/-dark`, `--neutral`, `--ink`, `--ink-soft`, `--cream`, `--paper`, `--line`, `--radius-sm/md`, `--shadow`) covers every `var()` it uses. ⚠️ Its `--radius-md: 12px` / `--radius-sm: 6px` deliberately DIFFER from design-system's `6px` / `4px`; buyer.css wins on load order, so tokens are NOT interchangeable between the two systems — do not merge them. |
| `Buyer/Layouts/footer.blade.php` | none (fully Tailwind now) |
| `Buyer/Home/index.blade.php` | none (fully Tailwind now; only `buyer/home.css` remains in `@push('styles')` as an inert safety net) |

⚠️ Element-selector hazard: `buyer.css` styles the footer via
`footer`, `footer h4`, `footer ul`, `footer a` — class removal alone cannot
neutralise those, so utilities must be self-sufficient before deletion.

---

## CROSS-CUTTING HAZARD #1 — `design-system.css` is UNLAYERED and beats every utility
**Found while verifying the buyer footer. This is the single most dangerous
trap in this migration — read it before converting anything.**

`resources/css/design-system.css` contains element-level rules:

```css
h1,h2,h3,h4,h5,h6 { font-family: var(--font-serif); font-weight: 400;
                    color: var(--text-primary); margin: 0 }
body, p, span, label, input, select, textarea, button {
                    font-family: var(--font-sans); color: var(--text-secondary) }
```

It is a **plain, non-Tailwind entry compiled with NO `@layer`** (verified in
the built asset `public/build/assets/design-system-*.css` → `@layer: false`).
Tailwind utilities, by contrast, are emitted inside `@layer utilities`.

Per the CSS cascade, **unlayered normal declarations outrank ALL layered
declarations regardless of specificity.** So while `design-system.css` is
loaded, these utilities are simply DEAD:

| Utility | Defeated on | Result |
|---|---|---|
| `text-*` / `font-*` | `h1`–`h6` | colour forced to `--text-primary` |
| `text-*` / `font-*` | `p`, `span`, `label`, `button`, `input`, `select`, `textarea` | colour forced to `--text-secondary`, font forced to Montserrat, **and colour does not inherit in from any wrapper** |

Real breakage this caused: the converted buyer footer's
`<h4 class="text-white">` rendered `--text-primary` (#1e1b1b) on the
`#3A2529` ink band — effectively invisible — and the legal-line
`<span class="text-buyer-footer-muted">` rendered `#635e5e` instead of
`#C9B9B6`. Class-name removal cannot neutralise these, because they are
ELEMENT selectors.

### THE RULE for every remaining area
**Remove `design-system.css` from that area's `@vite` array AT THE START of
the area's conversion — not at wrap-up.** It is safe because:

- all **80** of its `:root` tokens (verified programmatically) are re-exported
  by `app.css`'s `@layer base :root`, with identical values;
- its element rules are ported into `app.css`'s `@layer base` with identical
  values (now correctly overridable by utilities), plus `html, body
  { min-height: 100% }` which was ported for the same reason;
- `login-modal.css` / `auth-buttons.css` / buyer's own CSS only consume its
  **tokens**, never its component classes;
- its component classes (`.btn`, `.input`, `.search-bar`, `.progress`,
  `.nav-pill`, `.action-btn`, `.tag`) were grepped across the area's views and
  are unused there (buyer has its own `.icon-btn`/`.fill-btn`/`.ghost-btn`/
  `.checkout-btn`/`.account-btn`/`nav-login-btn` in `buyer.css`).

Status (verified by grepping every `@vite`/`@import` — accurate as of Buyer chunk a):
| Area | How design-system.css reaches it | Action |
|---|---|---|
| Landing (`Layouts/footer`) | **not loaded** — `@vite(['app.css','login-modal.css'])` | ✅ already app.css-only (why the landing conversion works) |
| Buyer (`Buyer/Layouts/app.blade.php`) | **not loaded** — removed in chunk a; `@vite` is app.css + legacy files | ✅ done |
| Buyer register flow (`Auth/Register-Type`, `Auth/register-buyer-pending`, `Buyer/register-buyer-pending`, `Buyer/register-buyer-check-email`) | **not loaded** — they `@extends('Layouts.footer')` and only `@push` `auth.css` (= `@import 'auth-pages.css' + 'buyer/buyer-register-modal.css'`). They get tokens from the layout's app.css. | ✅ relies on the `:root` re-export — do not remove it |
| Seller (`Layouts/seller.blade.php`) | **explicitly loaded** (`@vite` line 11, before the 3 seller CSS files) | ⚠️ remove at the START of the Seller phase |
| Admin (`Admin/Layouts/app.blade.php`, `Admin/Auth/login.blade.php`) | **via `@import '../design-system.css'`** at `admin.css` line 1 (which also imports `admin-registration.css`) — NOT via `@vite` | ⚠️ add `app.css` to the admin `@vite` and drop that import at the START of the Admin phase |

⚠️ Two traps this table encodes:
1. **Admin's dependency is an `@import`, not a `@vite` entry** — grepping Blade for
   `design-system.css` finds nothing in the admin area, so it is easy to miss.
2. The four register pages are **not** self-contained: they inherit tokens from
   whichever layout they extend. Any change to `Layouts/footer`'s `@vite` array
   affects them.
3. No area uses a third-party admin template — `admin.css` (375 lines, 18 literal
   hexes, 53 rules using tokens) is hand-written and self-contained apart from
   that one import. Straight utility conversion is viable; no special strategy
   needed.

### Phase-start blanket check (dev instruction — applies to EVERY remaining phase)
At the start of Seller, Admin, and any later area, grep BOTH surfaces before
converting anything:
1. `@vite` entries in that area's Blade layouts (catches `seller.blade.php`-style
   direct loads), AND
2. `@import` lines inside that area's CSS files (catches `admin.css`-style
   transitive loads — `admin.css` line 1 `@import '../design-system.css'` is
   invisible to a Blade-only grep).
Also re-grep for unexpected `@import`s in stub files (`home.css`, stub buyer
files, etc.) — a stub can gain an import between phases.

### Consequence for component-class conversions
Because the element rules are lost as a *layer* advantage, any element that
relied on them for colour/font must state it explicitly. Practical checklist
when converting a block:
1. `text-*` must be on the `h1`–`h6` / `p` / `span` / `label` / `button` /
   input itself — never only on a wrapper.
2. `font-serif` needs restating on a `<p>`/`<button>` that should be Playfair.
3. After conversion, re-read every text element in the block and ask "which
   rule colours this now?".

## CROSS-CUTTING HAZARD #2 — legacy unlayered files still loaded per area
`login-modal.css`, `auth-buttons.css`, `buyer.css`, `buyer-register-modal.css`
and `auth.css` are also unlayered, so their class rules beat utilities on any
tie until the area they belong to is fully converted and those files are
deleted. Drop the legacy class from an element as soon as its section is
converted, and treat "delete the file" as a hard requirement at wrap-up.

---

## Bugs / quirks discovered (pre-existing)

1. ~~`--space-24` undefined~~ → **RESOLVED** (defined as 96px — see Decisions log).
2. ~~`Buyer/Layouts/app.blade.php` loads missing `resources/css/landing.css`~~
   → **RESOLVED**: `app.css` is first in the buyer `@vite` array.
3. ~~`welcome.blade.php` loads non-existent `app.css`/`app.js`~~ →
   **RESOLVED**: no route renders `welcome.blade.php` (`/` and `/shop` both
   use `LandingPage.index`) → file deleted.
4. ~~`css/auth/login-choice.css` orphaned~~ → **RESOLVED**: deleted.
5. ~~Buyer uses a second token system~~ → **RESOLVED**: registered as
   `--color-buyer-*` / `shadow-buyer` extensions (see Decisions log).
   ⚠️ Still open as a *policy* question for seller/admin: keep exact hexes or
   normalize later.
6. ~~Buyer uses Font Awesome~~ → **RESOLVED**: kept during the migration.
7. **`Layouts/seller.blade.php` still loads `design-system.css`** → must be
   removed at the START of the Seller phase (Hazard #1). Not yet done.
8. **`Buyer/Layouts/navbar.blade.php`** — ✅ CONFIRMED DEAD (verified
   2026-09-17): no view `@include`s it — buyer layout uses
   `Components/navbar` (`app.blade.php:44`), landing uses
   `Components/navbar` (`Layouts/footer.blade.php:18`). Safe to delete at
   buyer wrap-up (chunk d). ⚠️ Its classes (`.icon-btn`/`.account-btn`) are
   NOT dead with it — the LIVE quick-view modal in `Buyer/Layouts/app`
   (`app.blade.php:148`) uses `.icon-btn`, so the buyer.css rules must stay
   until chunk c converts that widget.
9. **`buyer.css` header/search block** (`.header-row`, `.logo-mark`,
   `.logo-word`, `.cat-select`, `.search-wrap`, `.nav-login-btn`) — ✅
   CONFIRMED unreferenced by any live view (verified 2026-09-17): the ONLY
   file using them is the dead `Buyer/Layouts/navbar.blade.php` above
   (all six classes hit nowhere else). They vanish with `buyer.css` at
   chunk d; no separate action needed.
10. **`/register/logistics` renders a non-existent view** — live 500 bug,
    standalone from this migration (see Decisions log).

## Decisions log (dev, resolved)
- ✅ `sass-embedded` REMOVED (`npm uninstall`, gone from package.json + lockfile).
- ✅ Landing focus ring: KEEP the blue `button:focus-visible` outline —
  accessibility feature, not an accident. Stays in `app.css` base. (If it
  ever clashes with the palette, swap the COLOR, never delete it.)
- ✅ `css/auth/login-choice.css` + the `css/auth/` folder DELETED (orphaned).
- ✅ `--space-24: 96px` DEFINED in `app.css` base `:root` — explicit,
  intentional visual change (register hero regains its intended bottom
  padding instead of the collapsed 0). Cline's call, dev-approved.
- ✅ `/register/logistics` → missing `Auth.register-logistics` view: LIVE
  BUG (500 on that route), tracked here as its own standalone fix — NOT
  part of the CSS migration, do not let it get lost. Suggested fix: create
  the view or pull the route until the Logistics module exists.
- ✅ Buyer palette resolution: buyer.css's distinct hexes are registered as
  clearly-named `--color-buyer-*` extensions in `app.css` `@theme`
  (`buyer-primary-dark #c88a96`, `buyer-tertiary-dark #7fa080`,
  `buyer-ink #3a2529`, `buyer-ink-soft #5b4448`, `buyer-cream #fbf5f3`,
  `buyer-line #ebddda`, `shadow-buyer`). Values that already match shared
  scales use those tokens. Pixel-identical first; normalization optional later.
- ✅ Font Awesome decision: KEEP during the buyer migration (third-party
  CDN CSS, out of scope for "custom CSS → Tailwind"); lucide is untouched.
  An optional FA→lucide glyph swap is a separate visual-affecting change
  requiring explicit sign-off (glyphs differ slightly).
- ✅ `auth-btn` contract RESOLVED via `variant` prop on `Components.navbar`
  ('landing' default | 'buyer'): buyer variant renders dark filled Login /
  outlined Register as Tailwind utilities identical to auth-buttons.css.
  Legacy classes remain ONLY while auth-buttons.css is still loaded;
  TODO(buyer wrap-up): remove `auth-btn login-btn/register-btn` classes
  from navbar + delete `resources/css/auth-buttons.css` after visual verify.

## Buyer phase — audit findings
- **Scope is small:** ALL buyer sub-views (Products, Categories, Cart,
  Checkout, Orders, Account, Chat) are one-line placeholder stubs. Real
  markup exists only in:
  1. `Buyer/Layouts/app.blade.php` — navbar (shared, already Tailwind) +
     global widgets: cart drawer, quick-view modal, chat panel, toast
     (hardcoded mockup markup styled by `buyer.css`; JS toggles
     `.open`/`.show`/`.selected`/`.active` via `buyer.js`)
  2. `Buyer/Home/index.blade.php` + `home.css` (hero, category teaser,
     order-tracker stepper, store cards)
  3. `resources/js/buyer/home.js` — generates product-card HTML strings
     (`.card`, `.card-media`, `.tag`, `.wish-toggle`, `.price-row`…) with
     Font Awesome icons — the JS must be updated in lockstep with the card
     conversion
  4. `Buyer/Layouts/footer.blade.php` (buyer footer, `.footer-grid` etc.)
- `buyer.css` also styles the buyer header/search (`.header-row`,
  `.search-wrap`…) — but `Components/navbar` (the real navbar) doesn't use
  those classes, so they're dead-ish; confirm no view uses them before
  treating as dead.
- Conversion order: (a) buyer footer → (b) Home page + home.css + home.js
  cards → (c) layout widgets (drawer/QV/chat/toast) → (d) buyer.css deletion
  + stub CSS cleanup + auth-buttons.css removal (navbar TODO above).
- `app.css` is now FIRST in the buyer layout's `@vite` (broken
  `landing.css` reference removed). Unlayered legacy CSS still wins ties
  over Tailwind layers — so converted elements must drop their legacy
  classes when converted (same discipline as landing).

### Buyer chunk a — footer CONVERTED (`Buyer/Layouts/footer.blade.php`)
- All values 1:1 from `buyer.css` (see the header comment in the Blade file
  for the per-rule map). Notable fidelity points:
  - `980px` two-column collapse preserved as `max-[980px]:grid-cols-2` —
    buyer.css uses its own 980px breakpoint, NOT Tailwind's `lg` (1024px).
    Verified in the build: `@media not all and (width>=980px)`.
  - `footer h4` inherits `font-style` from the document default, i.e.
    Playfair — deliberately NOT converted to `font-sans`.
  - `<h4>` uses `text-white` and each legal-line `<span>` carries its own
    `text-buyer-footer-muted`. **Both are mandatory, not decorative** — see
    Hazard #1. Before `design-system.css` was dropped from this layout these
    utilities were dead: the `<h4>` rendered `--text-primary` (near-black on
    the ink band) and the `<span>`s rendered `--text-secondary`.
  - `font-family` is left to inherit on the `<h4>`/`<a>` (the base rule forces
    Montserrat on `p`/`button`/form controls only), matching the legacy
    `footer h4`/`footer a` behaviour.
  - Tailwind preflight `*{margin:0}` zeroes the UA margin the `.foot-desc`
    <p> used to carry; the collapsed 12.5px gap is re-stated explicitly
    (`mt-[12.5px] mb-[12.5px]`). Safe to drop if the tighter spacing is
    preferred — flagged in the Blade comment.
- ⚠️ **ELEMENT-SELECTOR CAVEAT (buyer-specific).** Unlike landing, `buyer.css`
  styles this footer largely through element selectors (`footer`,
  `footer h4`, `footer ul`, `footer a`, `footer a:hover`). Dropping class
  names cannot neutralise those, so the old block is an inert duplicate with
  identical values. The new utilities are written to be **self-sufficient**
  for the moment `buyer.css` is deleted, which is therefore a HARD
  requirement at buyer wrap-up, not an optional cleanup. Search `buyer.css`
  for element selectors before deleting it.
- Not yet verified visually — dev to confirm before chunk b. `buyer.css` is
  intentionally still loaded so this is a zero-visual-delta swap.
- ️ **Root-cause fix made as part of this chunk:** `design-system.css`
  removed from the buyer layout's `@vite` (Hazard #1). This affects the WHOLE
  buyer area, not just the footer — but it is a *no-op* for every
  not-yet-converted element, because its token set is fully re-exported by
  `app.css` and its element rules were ported into `app.css`'s `@layer base`
  with identical values (plus `html, body { min-height: 100% }`). The only
  behavioural change is that Tailwind utilities can now win on `h1`–`h6` /
  `p` / `span` / `label` / `button` / form controls — which is required for
  any conversion to work at all.

## Stub CSS deletion gate (approved by dev, gated)
Dev approved deleting the six comment-only stubs at buyer wrap-up:
`buyer/account.css`, `buyer/cart.css`, `buyer/chat.css`, `buyer/checkout.css`,
`buyer/orders.css`, `buyer/products.css`.
⚠️ "Approve now, delete later" leaves a drift window — immediately BEFORE
deleting, re-check each file is STILL effectively empty (they will be
re-verified for actual rule content, not just size, in case a future
stub-fill populated one). Do not delete on the strength of this note alone.

## Flags awaiting developer decisions
- [ ] Delete the six stub buyer CSS files (`buyer/account.css`,
      `buyer/cart.css`, `buyer/chat.css`, `buyer/checkout.css`,
      `buyer/orders.css`, `buyer/products.css` — comment-only placeholders)?
- Out-of-scope finding: `routes/web.php` `/register/logistics` renders
  `Auth.register-logistics`, a view that does not exist. Not touched —
  recommend a future fix (route or missing view).
