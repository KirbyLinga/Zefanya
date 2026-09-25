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
| 9 | Buyer — chunk a: footer + removed `design-system.css` from buyer layout | ✅ Converted |
| 10 | Buyer — chunk b: Home + `home.css` + `home.js` card strings | ✅ Converted |
| 11 | Buyer — chunk c: layout widgets + buyer.js null-guard fix | ✅ Converted |
| 12 | Buyer — chunk d: wrap-up (deleted dead files, stripped legacy classes, pruned vite.config) | ✅ Done |
| 13+ | **Shared auth CSS chain** | ⬜ |
| 14 | Admin — page-by-page audit | ✅ Done (findings below) |
| 15 | Admin — conversion | ✅ Done (ADMIN-b/c/d/e — awaiting dev visual verify) |

---

## Seller Phase (complete)

| Chunk | Status | Files |
|---|---|---|
| A | ✅ DONE | `Layouts/seller.blade.php` (Hazard #1 fix), sidebar components (7 files), `seller-app.css` → DELETED |
| B | ✅ DONE | `Seller/Dashboard/index.blade.php`, `seller-dashboard.css` → DELETED |
| C | ✅ DONE | `Seller/Products/{index,show,create,edit,_form}.blade.php`, `seller-products.css` → DELETED |
| D | ✅ DONE | 13 stub views (class cleanup), `seller/` dir deleted, vite.config pruned, docs updated |

### Seller — summary
- **3 legacy CSS files fully deleted**: `seller-app.css`, `seller-dashboard.css`, `seller-products.css`
- **`resources/css/seller/` directory deleted** (empty)
- **`vite.config.js` pruned**: 3 seller CSS entries removed, only `app.css` remains in seller `@vite`
- **Hazard #1 resolved**: `design-system.css` removed from seller layout `@vite`, replaced with `app.css` (layered base rules)
- **Hazard #2 clean**: No `@import` of design-system.css in any seller CSS file
- **Collapse state** (`.seller-sidebar.is-collapsed`) preserved in `app.css` `@layer components`
- **Icon scale on hover/active** preserved in `app.css` `@layer components`
- **Total classes converted**: ~120 unique class names across 22+ Blade files → Tailwind utilities
- **Element-selector hazards handled**: All descendant/element selectors (`.sp-form-field label`, `.sp-form-field input`, `.sp-card__body h3`, `.sp-card__image img`, `.sp-existing-image img`) converted to explicit inline classes on each element
- **Seller auth pages** (login, pending, verify-otp): NOT converted — they extend `Layouts.footer` and use the shared auth chain (`auth.css`), handled separately
- **`design-system.css`**: DELETED in ADMIN-e (see Admin Phase below) — `app.css` is the sole token source now

---

## Admin Phase — page-by-page audit (DONE — no markup converted yet)

### Load map (Hazard #2 grep results)
| File | How CSS loads |
|---|---|
| `Admin/Layouts/app.blade.php:11` | `@vite(['resources/css/admin/admin.css'])` — single entry, no `@stack('styles')` consumers |
| `Admin/Auth/login.blade.php:11` | Standalone full HTML doc (no layout) — `@vite(['resources/css/admin/admin.css'])` directly |
| `admin/admin.css:1` | **`@import '../design-system.css'`** ← the invisible-dependency trap (Hazard #2, live) |
| `admin/admin.css:2` | `@import 'admin-registration.css'` |

- Recursive Blade grep for `admin.css|design-system`: **only the two files above** (buyer hits are comments only).
- `design-system.css` is currently loaded ONLY via `admin.css`'s `@import` — no Blade `@vite` references it anywhere anymore.
- **No Admin JS exists** (`resources/js/` = `app.js`, `buyer/buyer.js`, `buyer/home.js`); grep for `admin-`/`reg-` class strings in JS: zero hits. No JS-coupling risk.
- Only interactivity: inline `onclick="document.getElementById('rejectPanel').hidden = false"` — a `hidden` attribute toggle, no CSS class coupling. Safe.
- `Components/admin-sidebar.blade.php` is the only shared component; `is-active` state is CSS-only (no JS toggles it).

### Views → class inventory
1. **Layouts/app**: `admin-body`, `admin-shell`, `admin-main`, `admin-topbar`, `__title`, `__user`, `admin-content`
2. **Components/admin-sidebar**: `admin-sidebar`, `__brand`, `__brand-row` (+ descendant `img`, `span`), `__brand-role`, `__nav` (+ descendant `a`, `a:hover`, `a.is-active`, `a svg`), `__nav-badge` (+ `a.is-active .admin-sidebar__nav-badge`), `__logout` (+ `button`, `button:hover`)
3. **dashboard**: `admin-page-title`, `admin-page-subtitle`, `dash-summary-strip`, `dash-summary-item`, `__value`, `__label`
4. **Registrations/index + sellers** (identical class set): `reg-toast`, `reg-layout`, `reg-list`, `__header` (+ `h2`), `__item` (+ `.is-active`, `:hover`), `__avatar`, `__info`, `__name`, `__role`, `__empty`, `reg-count-badge`, `reg-status-badge` `--pending` (approved/rejected variants defined but UNUSED in views), `reg-detail`, `__header`, `__avatar`, `__email`, `__section` (+ `h4`), `__grid` (+ descendant `div`, `span`, `strong`), `__address`, `__documents`, `__id-link` (+ `:hover`), `__actions`, `__empty` (+ `p`), `reg-btn` `--approve`/`--reject`/`--reject-confirm`, `reg-reject-panel` (+ `label`, `textarea`)
5. **Auth/login**: `admin-login-body`, `admin-login-card` (+ `__logo`, `__title`, `__subtitle`, `__error`, `__label`, `__remember`, `__remember input`), PLUS design-system shared classes `.input` and `.btn .btn--inverted`

### CSS inventory
- **`admin.css`** (375 lines): shell + sidebar + topbar + `dash-summary-*` + `admin-login-card*` + a **forced-override block (lines 352–375, `color: … !important`)** on nav links/spans/svgs + logout button states. Sidebar palette is HARDCODED (`#1e1b1b` bg, `#79545c` active, `#f7d6d0` badge/active-shadow, `rgba(255,248,247,.78)` text) — the `!important` block is a defensive duplicate that exists only to beat design-system's unlayered element rules; it must NOT be ported to utilities (utilities inherently win once the unlayered source is gone).
- **`admin-registration.css`** (375 lines): all `reg-*` classes, almost entirely hardcoded hexes (not tokens); one breakpoint: `@media (max-width:1024px)` → `reg-layout` single column.
- **`design-system.css`** (318 lines) as consumed by admin: tokens (`--bg-page`, `--bg-surface`, `--border-light/default`, `--radius-*`, `--space-*`, `--font-serif/sans`, `--text-primary/secondary`, `--text-inverse`, `--shadow-lg`, `--color-primary-700` for `accent-color`) + shared components `.btn`/`.btn--inverted`/`.input` (used by Admin login only within admin scope).

### Hazard checks
- **Hazard #1 — LIVE for admin**: `admin.css` `@import`s design-system UNLAYERED; its element rules (`h1–h6`, `body/p/span/label/input/select/textarea/button` font+color) outrank ALL Tailwind utilities. Same fix as Seller required before any conversion will visibly work.
- **app.css coverage VERIFIED 1:1**: `@layer base :root` (lines 139–245) re-exports every token admin.css consumes (bg/text/border/radius/space/fonts/shadows — checked rule by rule against design-system.css values, including `--space-24` which design-system never defined but auth-pages needed). Element rules ported with identical values. **Zero app.css changes expected for the admin phase.**
- **Hazard #2 mapped**: single entry point = admin.css's two `@import`s; both go dark the moment views stop loading admin.css.

### Element-selector rules needing per-element conversion (seller discipline)
`.admin-sidebar__brand-row img/span`, `.admin-sidebar__nav a(+svg)/a:hover/a.is-active`, `.admin-sidebar__logout button(+hover)`, `.reg-list__header h2`, `.reg-detail__section h4`, `.reg-detail__grid div/span/strong`, `.reg-reject-panel label/textarea` — all to be converted as explicit utilities on each element.

### Proposed conversion chunks (awaiting developer approval)
- **ADMIN-b — Hazard fix + shell + dashboard**: (1) remove `@import '../design-system.css'` from admin.css — zero visual delta: tokens still resolve via app.css re-export, element rules move from unlayered to identical layered values (design-system then has ZERO consumers → propose file + vite entry deletion at ADMIN-e); (2) both admin entry points swap `@vite` to `['resources/css/app.css', 'resources/css/admin/admin.css']` (app.css first — unlayered admin.css still wins ties for the not-yet-converted views, converted markup drops legacy classes); (3) convert Layouts/app + admin-sidebar; (4) convert dashboard (3 classes, trivial). admin-registration.css untouched.
- **ADMIN-c — Registrations**: `Registrations/index` + `sellers` (same class set, convert once, apply twice) → delete `admin-registration.css`, drop its `@import`.
- **ADMIN-d — Auth/login**: standalone page → app.css only; `.input`/`.btn--inverted` become utilities; delete `admin.css`, prune vite entry.
- **ADMIN-e — design-system removal**: delete `resources/css/design-system.css` + prune `vite.config.js` (requires re-grep: `.input`/`.btn`/`.tag`/`.search-bar`/`.progress`/`.nav-pills`/`.action-btn` are design-system component classes that may have consumers OUTSIDE admin — verify before deletion; requires dev approval).

### Chunk ADMIN-b — DONE (hazard fix + shell + sidebar + dashboard + login pulled forward)
- ✅ `admin.css:1` `@import '../design-system.css'` **REMOVED** — Hazard #1 fix for admin (was live: unlayered element selectors outranked all utilities). Tokens resolve via app.css re-export (verified 1:1 in audit). **design-system.css now has ZERO consumers.**
- ✅ Entry points: `Admin/Layouts/app.blade.php` → `@vite(['resources/css/app.css', 'resources/css/admin/admin.css'])`; `Admin/Auth/login.blade.php` → `@vite(['resources/css/app.css'])`.
- ✅ Converted: layout shell (admin-body/shell/main/topbar/content), `Components/admin-sidebar` (all 10 links + badge + logout), dashboard (page title/subtitle + dash-summary strip). ~30 legacy class names dropped — `is-active` state expressed as conditional utilities (`$navLink`/`$navLinkActive`/`$badgeBase` @php vars); the `a.is-active .admin-sidebar__nav-badge` child-state rule became a conditional badge class. admin.css's `!important` override block NOT ported (it existed only to beat design-system element rules, which no longer load).
- ⚠️ **SCOPE DEVIATION (reported, not silently done):** `Admin/Auth/login.blade.php` converted in THIS chunk instead of ADMIN-d. Reason: it was the ONLY consumer of design-system's `.input`/`.btn--inverted` component classes — leaving it on legacy classes would have broken its inputs/buttons the moment the `@import` was removed. ADMIN-d is reduced to "delete admin.css + prune vite entry".
- ⚠️ **TRANSIENT DELTAS** on the not-yet-converted Registrations views (self-heal in ADMIN-c; listed for the dev's visual pass):
  1. app.css preflight zeroes `<p>` margins → `.reg-list__empty p` / `.reg-detail__empty p` lose UA vertical margins.
  2. app.css base `a { text-decoration: none }` → `.reg-detail__id-link` loses its UA underline (restated as `underline` utilities in ADMIN-c).
  All headings on those views carry explicit size/weight in admin-registration.css, so preflight's heading reset causes no delta there.
- ✅ Verified: `vite build` clean — `shadow-[inset_3px_0_0_0_#f7d6d0]`, `first-of-type:mt-6`, `max-[1200px]`/`max-[700px]`, `accent-primary-700`, `text-cream` all present in compiled app.css. `php artisan view:cache` passes. No legacy admin-* / dash-summary / admin-login / `class="input"` / `class="btn"` / `is-active` class usage remains in converted markup (grep-verified; comment-only mentions excluded).
- ⚠️ **Out-of-scope finding (pre-existing, PRESERVED as-is):** `Admin/Auth/login.blade.php` has a stray `>` after `@csrf` (renders a literal `>` text node inside the form). Not a migration artifact; needs a dev decision — one-character fix on request.

#### Chunk ADMIN-c — DONE (Registrations + admin-registration.css deleted)
- ✅ `Admin/Registrations/index.blade.php` + `sellers.blade.php` converted (~50 class names, shared `$reg*` @php contract per file, same pattern as the sidebar). `reg-layout`'s own 1024px breakpoint kept as `max-[1024px]:grid-cols-1`.
- ✅ `@import 'admin-registration.css'` removed from admin.css; **`resources/css/admin/admin-registration.css` DELETED** (pre-delete gate: recursive grep of blade/css/js/config → only comment mentions; zero `reg-` classes left in markup; no vite entry existed).
- ✅ Both ADMIN-b transient deltas healed: `.reg-detail__id-link` gets explicit `underline` (legacy was UA underline); empty-state/address `<p>` margins restated as `my-[13px]`/`my-[13.5px]` (UA `margin: 1em 0` re-stated per element font size — fidelity, not decoration).
- ✅ Legacy quirks preserved & documented in-file: `.reg-detail__section:last-of-type { border-bottom: none }` matched the last DIV child (reject/empty panel), never a section — so all sections keep their bottom border (plain `border-b` on all); `.reg-detail__documents` never had a CSS rule — its div stays bare; unused `--approved`/`--rejected` badge variants NOT ported (no view used them).
- ✅ Verified: `vite build` clean (admin bundle 11.17 kB → 5.90 kB — deleted rules confirmed gone from the pipeline); `php artisan view:cache` passes; compiled app.css contains `grid-template-columns:340px 1fr`, `max-[1024px]`, `margin-block:13px/13.5px`, `#fdf1de`, `#a8cdb0`, `#e8a49c`, `#2f5c3a`, `#9a6a1f`, `#eaf3ec`, `truncate`.
- One authoring slip made and fixed during this chunk: the @import removal initially produced an invalid `@import /* comment */ ''` line; corrected to a plain comment before any build ran.

#### ADMIN-c deletion report
- `admin-registration.css` — **DELETED** ✅
- `admin.css` — now 100% dead weight: every class it defines (shell/sidebar/dash-summary/admin-login) is unused by any view. It only still loads because `Admin/Layouts/app.blade.php` @vites it. Dropping that entry and deleting the file can happen immediately (ADMIN-d collapses into a layout-entry removal + file deletion) — pending dev approval since it removes a legacy CSS file.
- `design-system.css` — ZERO consumers; deletable at ADMIN-e (final re-grep + dev approval).

### Chunks ADMIN-d + ADMIN-e — DONE (final admin cleanup, dev-approved together)
- ✅ `Admin/Layouts/app.blade.php` → `@vite(['resources/css/app.css'])` only.
- ✅ **`resources/css/admin/admin.css` DELETED** — was 100% dead weight (every class unused since ADMIN-b/c conversions; its Auth/login page had already converted in ADMIN-b). `resources/css/admin/` directory removed (empty).
- ✅ **`resources/css/design-system.css` DELETED** — zero consumers since ADMIN-b (its only consumer was admin.css's `@import`, itself only consumed by views that have all converted). Pre-deletion re-grep of `.input`/`.btn*`/`.search-bar`/`.nav-pill*`/`.action-btn`/`.tag`/`.progress` across all views + JS: only BEM false-positives owned by auth/buyer-modal CSS (`buyer-modal__file-btn`, `buyer-otp-input`, …) — no design-system tokens.
- ✅ `vite.config.js` pruned: `resources/css/design-system.css` and `resources/css/admin/admin.css` entries removed. Remaining entries: `app.css`, `login-modal.css`, `auth.css`, `buyer/buyer-register-modal.css`, `buyer/buyer.js`, `buyer/home.js` — ALL are still-referenced (the auth/login-modal/buyer-modal files belong to the pending shared-auth-chain chunk 13+).
- ✅ `app.css` header + `:root` comments updated: design-system.css no longer described as a live token source (deleted; app.css is canonical).
- ✅ Verified: `vite build` clean — 7 modules, 4 CSS chunks (login-modal, buyer-register-modal, auth, app); admin/design-system chunks gone from the manifest; `php artisan view:cache` passes. `resources/css/` now contains only `app.css`, `auth.css`, `auth-pages.css`, `login-modal.css`, `buyer/buyer-register-modal.css`.

### Admin Phase — summary
- **4 legacy CSS files fully deleted**: `admin/admin-registration.css`, `admin/admin.css`, `design-system.css` (+ `seller/` dir earlier in the Seller phase).
- **Hazard #1 resolved for Admin** (ADMIN-b: design-system import removed before conversion), **Hazard #2 mapped & retired** (both @imports gone with their files).
- **Zero app.css changes were needed** for the entire Admin phase — the audit-time 1:1 token verification held.
- **Remaining vite entries are all shared-auth-chain scope** (chunk 13+): `login-modal.css`, `auth.css` (→ `auth-pages.css` + `buyer/buyer-register-modal.css`), plus buyer JS.

---

### Seller — conversion progress

#### Chunk A — DONE (hazard fix + shell/sidebar)
- ✅ `Layouts/seller.blade.php` `@vite`: `app.css` first (was `design-system.css`), seller-products.css, seller-dashboard.css
- ✅ All 7 sidebar components converted to Tailwind utilities
- ✅ `seller-app.css` DELETED (shell classes inline in layout + components)
- ✅ Collapse rules (`.seller-sidebar.is-collapsed`) ported to `app.css` `@layer components`
- ✅ Hazard #1 resolved for Seller area

#### Chunk B — DONE (dashboard)
- ✅ `Seller/Dashboard/index.blade.php` fully converted to Tailwind utilities
- ✅ `seller-dashboard.css` DELETED (all classes converted inline)

#### Phase-start blanket check (Hazard #2) — RESULTS
1. **Blade `@vite` grep** (all `resources/views/Seller/**` + `Layouts/seller.blade.php`):
   - `Layouts/seller.blade.php:10-15` — `@vite(['resources/css/design-system.css', 'resources/css/seller/seller-app.css', 'resources/css/seller/seller-products.css', 'resources/css/seller/seller-dashboard.css'])`
   - Seller auth pages (`Seller/Auth/login.blade.php`, `register-seller-pending.blade.php`, `register-seller-verify-otp.blade.php`) — `@vite('resources/css/auth.css')` via `@push('styles')` (they `@extends('Layouts.footer')`, whose own `@vite` loads `app.css` + `login-modal.css`).
   - **Zero other `@vite` hits in `resources/views/Seller/`** — no per-page CSS.
2. **`@import` grep inside CSS** (all `resources/css/seller/*.css`):
   - `seller-app.css` — **no `@import`**
   - `seller-dashboard.css` — **no `@import`**
   - `seller-products.css` — **no `@import`**
   - (Contrast: `admin.css:1` DOES `@import '../design-system.css'` — the invisible-dependency trap. Seller has none.)
3. **Conclusion**: Seller's ONLY `design-system.css` dependency is the `@vite` line in `Layouts/seller.blade.php`. No transitive `@import` trap. Hazard #2 clean for seller.

#### Chunk C — DONE (products)
- ✅ `Seller/Products/{index,show,create,edit,_form}.blade.php` all converted to Tailwind
- ✅ `seller-products.css` DELETED — all 350 lines converted
- ⚠️ Element-selector rules in seller-products.css required explicit conversion on each element (`.sp-form-field label`, `.sp-form-field input/select/textarea`, `.sp-card__body h3`, `.sp-card__image img`, `.sp-existing-image img`)

---

#### Step 1 — design-system.css removal (DONE in Chunk A)

- ✅ `Layouts/seller.blade.php` `@vite` updated: `app.css` first + seller-products.css + seller-dashboard.css
- ✅ Element-selector hazard cleared — h1-h6/p/span/label/button/input rules now layered in app.css, overridable by Tailwind utilities
- ✅ `.seller-sidebar.is-collapsed` rules ported to `app.css` `@layer components` before seller-app.css deletion
- ✅ Seller auth pages unaffected — they extend Layouts.footer and get tokens from app.css via the shared auth chain
---

#### Step 2 — full audit: every CSS/SCSS file used by seller views, `@vite`/`@import` map, custom classes

##### 2a. Layout
| File | Role | How loaded | CSS it pulls |
|---|---|---|---|
| `resources/views/Layouts/seller.blade.php` | Seller shell layout (sidebar + main) | `@vite` line 10-12: `app.css`, `seller/seller-products.css`, `seller/seller-dashboard.css` | Direct load of 3 files. design-system.css REMOVED in Chunk A (Hazard #1 fix). `seller-app.css` DELETED in Chunk A (classes converted to Tailwind). No `@stack('styles')` consumers inside seller proper (auth pages use `Layouts.footer` instead). |

##### 2b. Seller CSS files (3 active, all in `resources/css/seller/`)
| File | Size | Responsibility | `@import` of design-system? | Consumes tokens via `var()`? |
|---|---|---|---|---|
| `seller-app.css` | **DELETED** (Chunk A) | Shell: `.seller-layout`, `.seller-sidebar`, `.seller-sidebar__brand`, `.seller-sidebar__brand-text`, `.seller-sidebar__toggle`, `.seller-sidebar__toggle-icon`, `.seller-main`, `.seller-sidebar.is-collapsed*` — all converted to Tailwind utilities inline in layout + components. Collapse rules ported to `app.css` `@layer components`. | No | Yes — `--bg-page`, `--bg-sidebar`, `--text-inverse`, `--space-*`, `--radius-md` (all re-exported by app.css `@layer base :root`) |
| `seller-dashboard.css` | 12079 bytes | Dashboard + shared panel/table/empty-state primitives: sidebar nav (`.seller-nav*`); stat cards (`.stat-card*`, 4 color variants); page header (`.seller-page-header*`); panels (`.panel*`, `.panel__header/title/badge/link`); sales chart SVG surface; low-stock list; `.seller-table`; `.empty-state*`; `.seller-sidebar__footer`, `.seller-sidebar__seller*` | No | Yes — heavy token consumer: `--space-*`, `--font-sans`, `--font-serif`, `--text-*`, `--bg-surface`, `--border-light`, `--radius-*`, `--color-primary-*`, `--color-secondary-*`, `--color-tertiary-*`, `--color-warning`, `--color-neutral-*`, `--color-secondary-500` |

##### 2c. `@vite` / `@import` dependency map (seller area only)
```
Layouts/seller.blade.php  @vite →
  ├─ app.css                ← NEW in Chunk A (replaces design-system.css, provides tokens + base rules)
  ├─ seller/seller-products.css ← product UI; no @import; some hardcoded hexes
  └─ seller/seller-dashboard.css ← dashboard/panels/tables; no @import; token-heavy

  REMOVED: design-system.css (Hazard #1, Chunk A)
  DELETED: seller-app.css (Chunk A — all classes converted to Tailwind, collapse rules → app.css @layer components)

Seller auth pages (login, register-pending, verify-otp)  @extends Layouts.footer →
  Layouts.footer @vite → app.css + login-modal.css
  + @push('styles') @vite('auth.css')
  auth.css @import → auth-pages.css + buyer/buyer-register-modal.css   ← these 2 consume design-system tokens via app.css :root re-export
```
Note: `auth.css` is NOT seller-specific — it's the shared auth chain used by buyer register flow too. Seller auth pages piggyback on it.
| `seller-products.css` | 7556 bytes | Product UI: toast (`.sp-toast`), header (`.sp-header`), buttons (`.sp-btn*`, 5 variants), filters (`.sp-filters`), grid (`.sp-grid`), cards (`.sp-card*` including image/status/body/actions), status badges (`.sp-status-badge*`, 3 states), forms (`.sp-form*`, `.sp-form-field*`, `.sp-field-error`, `.sp-locked-category`, `.sp-field-hint`), file upload (`.sp-file-wrap`, `.sp-file-btn`, `.sp-file-name`, `.sp-existing-images`, `.sp-existing-image`, `.sp-primary-tag`), form rows | No | Mix: some rules use `var()` (only via the shared tokens inherited at runtime from `design-system.css` load), but MANY rules use hardcoded hex values directly (see token-consumption table below) |

##### 2d. seller-products.css — detailed class inventory

| Class | Purpose | Token usage / hardcoded values |
|---|---|---|
| `.sp-toast` | Success toast | Hardcoded: `#eaf3ec` bg, `#a8cdb0` border, `#2f5c3a` text, Montserrat 13px, radius 4px |
| `.sp-header` | Page header row | Flex, gap, margin-bottom 24px |
| `.sp-btn` | Base button | Montserrat 600 12.5px, height 42px, padding 0 20px, radius 4px, transition |
| `.sp-btn--primary` | Primary button | Hardcoded: `#1e1b1b` bg, `#ffffff` text, hover `#79545c` bg |
| `.sp-btn--outline` | Outline button | Hardcoded: transparent bg, `#79545c` text/border, hover swap |
| `.sp-btn--danger` | Danger button | Hardcoded: transparent bg, `#a5333d` text/border, hover swap |
| `.sp-btn--sm` | Small button | Height 34px, padding 0 14px, 11px |
| `.sp-btn--submit` | Submit button | Margin-top 8px, width 100%, height 48px |
| `.sp-filters` | Filter row | Flex, gap 12px, margin-bottom 24px |
| `.sp-filters input[type="text"]` | Search input | Hardcoded: `#e8e2e0` border, radius 4px, Montserrat 13.5px, height 42px, max-width 320px |
| `.sp-filters select` | Filter select | Hardcoded: `#e8e2e0` border, `#ffffff` bg, radius 4px, Montserrat 13.5px, height 42px |
| `.sp-grid` | Product grid | `grid-template-columns: repeat(auto-fill, minmax(240px, 1fr))`, gap 20px |
| `.sp-card` | Product card | Hardcoded: `#ffffff` bg, `#e8e2e0` border, radius 8px, overflow hidden, column flex |
| `.sp-card__image` | Image area | Aspect-ratio 1, hardcoded `#faf2f1` bg, position relative |
| `.sp-card__image img` | Product image | Width/height 100%, object-fit cover |
| `.sp-card__no-image` | No-image placeholder | Hardcoded `#79545c` color, centered flex |
| `.sp-status-badge` | Status badge base | Absolute top-left 10px, padding 4px 10px, radius 9999px, Montserrat 600 10px uppercase |
| `.sp-status-badge--active` | Active status | Hardcoded: `#eaf3ec` bg, `#2f5c3a` text |
| `.sp-status-badge--draft` | Draft status | Hardcoded: `#f2ebe9` bg, `#4f4446` text |
| `.sp-status-badge--inactive` | Inactive status | Hardcoded: `#fbe9e7` bg, `#a5333d` text |
| `.sp-card__body` | Card body | Padding 14px 16px, column flex, gap 4px, flex 1 |
| `.sp-error-summary` | Error message | Hardcoded: `#fbe9e7` bg, `#e8a49c` border, radius 4px, Montserrat 12.5px, `#a5333d` text |
| `.sp-form-row` | Form row | Flex, gap 16px |
| `.sp-form-field` | Form field | Flex column, flex 1 |
| `.sp-form-field--small` | Small field | Flex 0 0 180px |
| `.sp-form-field label` | Field label | Hardcoded: Montserrat 600 11px uppercase, `#4f4446` text, margin-bottom 8px |
| `.sp-form-field input, select, textarea` | Form inputs | Hardcoded: padding 10px 14px, `#e8e2e0` border, radius 4px, Montserrat 13.5px, `#1e1b1b` text |
| `.sp-form-field input:focus, select:focus, textarea:focus` | Focus state | Hardcoded: `outline: none`, `#79545c` border-color |
| `.sp-field-error` | Field error text | Hardcoded: `#a5333d` text, Montserrat 11.5px, margin-top 5px |
| `.sp-locked-category` | Locked category display | Hardcoded: `#faf2f1` bg, `#e8e2e0` border, radius 4px, `#4f4446` text, Montserrat 13.5px, gap 8px, padding 10px 14px |
| `.sp-locked-category svg` | Lock icon | Hardcoded `#79545c` color, flex-shrink 0 |
| `.sp-field-hint` | Field hint text | Hardcoded: `#4f4446` text, Montserrat 10.5px italic, margin-top 6px |
| `.sp-file-wrap` | File upload wrapper | Flex, align-center, gap 12px |
| `.sp-file-input` | Hidden file input | Position absolute, 1x1px, clip rect |
| `.sp-file-btn` | File button | Hardcoded: `#ffffff` bg, `#79545c` border/text, radius 4px, Montserrat 600 11.5px, height 40px, padding 0 16px, gap 8px |
| `.sp-file-btn:hover` | File button hover | Hardcoded: `#79545c` bg, `#ffffff` text |
| `.sp-file-name` | File name text | Hardcoded: `#4f4446` text, Montserrat 12.5px, ellipsis |
| `.sp-existing-images` | Existing images grid | Flex-wrap, gap 12px |
| `.sp-existing-image` | Image thumbnail | Hardcoded: 80x80, radius 6px, overflow hidden, `#e8e2e0` border, position relative |
| `.sp-existing-image img` | Thumbnail image | Width/height 100%, object-fit cover |
| `.sp-primary-tag` | Primary badge | Hardcoded: `rgba(30,27,27,0.7)` bg, `#ffffff` text, 9px, bottom-left bar |
| `.sp-existing-image form` | Delete form | Position absolute, top-right 4px |
| `.sp-existing-image button` | Delete button | Hardcoded: `all: unset`, `rgba(30,27,27,0.7)` bg, `#ffffff` text, radius 9999px, 20x20, flex-center |
| `@media (max-width: 640px)` | Responsive | `.sp-form-row` stacks, `.sp-header` stacks with gap 12px |

### Buyer wrap-up (chunk d) deletions — dev visual-verified, committed
- Deleted: `Buyer/Layouts/navbar.blade.php` (confirmed dead — no `@include`
  anywhere; buyer + landing both use `Components/navbar`), `buyer/buyer.css`,
  `buyer/home.css` (+ its `@vite` line in `Buyer/Home/index.blade.php`),
  `auth-buttons.css`, and the 6 stub files (`account/cart/chat/checkout/
  orders/products.css` — each re-verified comment-only immediately before
  deletion, per the stub-deletion gate). `resources/css/buyer/` now holds
  only `buyer-register-modal.css` (still loaded: buyer layout + auth pages
  via `auth.css`).
- `Buyer/Layouts/app.blade.php` `@vite` is now `app.css`, `login-modal.css`,
  `buyer/buyer-register-modal.css`.
- Legacy `auth-btn login-btn/register-btn` classes stripped from
  `Components/navbar` (both variants Tailwind-only; buyer keeps 4px
  `rounded-[4px]`, landing keeps 6px `rounded-md`).
- `vite.config.js` pruned of all 9 deleted entries (stubs + `buyer.css` +
  `home.css` + `auth-buttons.css`). Remaining entries: `app.css`,
  `design-system.css` (seller/admin still need it), `login-modal.css`,
  `admin.css`, `auth.css`, `buyer-register-modal.css`, 3 seller files,
  2 buyer JS files.
- Pre-existing `buyer.js` latent TypeError fixed separately (optional-chained
  `accountDropdown` refs, lines 10/14) — reviewed independently of the
  navbar deletion.

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
| Seller (`Layouts/seller.blade.php`) | `app.css` now FIRST in `@vite` (Chunk A); `design-system.css` REMOVED | ✅ Hazard #1 fixed — tokens from app.css `@layer base`, element rules layered, utilities now work |
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
  Radius note (chunk c, verified): the buyer navbar variant already carries
   `rounded-[4px]` on each variant branch (NOT the shared base), so deleting
   `auth-buttons.css` at chunk d causes NO radius shift — its 4px rule merely
   duplicates the utility value. Landing keeps `rounded-md` (6px) and never
   loaded the file. Nothing further needed.

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
