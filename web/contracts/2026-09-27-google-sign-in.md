# Contract: "Continue with Google" sign-in for buyer / seller / logistics

## Objective

Let an existing buyer, seller or logistics provider sign in with Google instead of a password.
The flow **links** a Google account to a row that already exists; it never creates an account,
because all three roles have an approval-gated registration flow that must stay the only way in.

## Scope

- `laravel/socialite` dependency + `google` config block (`GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `GOOGLE_REDIRECT_URI`).
- One migration (created, **not run**): nullable unique `google_id` + nullable `password` on `buyers`, `sellers`, `logistics_providers`.
- Routes: role-agnostic `auth.google.redirect` / `auth.google.callback`, role-scoped `auth.google.role.redirect` / `auth.google.role.callback` (`{role}` constrained to `GoogleAuthController::ROLES`).
- `App\Http\Controllers\Auth\GoogleAuthController` (LINK-only callback: link → approval gate → guard login).
- One "Continue with Google" button in the shared login modal + the two CSS rules it needs.
- Three feature tests (one per role) mocking the Socialite driver.

## Out of Scope

- No auto-registration for any role; no change to the buyer/seller/logistics registration flows, admin approval queue, models' `$fillable`, or `UnifiedLoginController`.
- No seller/logistics **login surface** is built (see Open Questions) — only the one shared modal gets the button.
- No courier/admin Google sign-in. No password-reset/account-linking UI. Nothing run against the app DB by the agent.

## Research Findings

- `laravel/socialite` was absent; `composer require` resolves v5.31.0 (5 installs, 0 updates, 0 removals) on Laravel 13.29 / PHP 8.5.9.
- Only **one** login modal exists: `Components/login-modal.blade.php`, included at `Layouts/footer.blade.php:107`, triggered by `[data-login-trigger]`, posting to `POST /login`, with an inline (no-JS-handler) modal script. `Seller/Auth/login.blade.php` is unreachable (`GET /seller/login` → `redirect()->route('login')`); logistics has no login route/view at all (`routes/logistics.php:10-12` calls it a future phase).
- Guards: `buyer`→`Buyer`, `seller`→`Seller`, `logistics`→`LogisticsProvider` (`config/auth.php`).
- Live MySQL schema confirmed via `SHOW COLUMNS`: no `google_id` anywhere; `password` is `varchar(255) NOT NULL` on all three tables.
- All three models already cast `password => 'hashed'`, and Laravel 13's `castAttributeAsHashedString()` returns `null` unchanged for null input → nullable password needs no model change.
- `UnifiedLoginController::tryGuard()` refuses any account whose `status !== 'approved'` and flashes role-specific wording into `auth.status_message` — Google sign-in must not become a bypass.
- `Auth/login-choice.blade.php` (GET `/login`) and the modal both render `session('auth.status_message')`; the modal is closed by default, so an error flashed onto a page whose modal is closed would be invisible.

## Implementation Plan

1. `composer require laravel/socialite` (approved).
2. `config/services.php` → `google` block from env; `.env.example` → placeholder keys (developer fills `.env`).
3. Migration `2026_09_27_000001_add_google_id_to_role_tables.php` (create only).
4. `routes/web.php`: Google route group (throttle 10/1), `{role}` constrained with `implode('|', GoogleAuthController::ROLES)`.
5. `GoogleAuthController`: a `ROLE_MAP` (guard/model/home per role) drives one shared callback path.
6. `Components/login-modal.blade.php`: one role-agnostic button + `login-modal__google` styles in `resources/css/login-modal.css`; then `npm run build`.
7. Three test files mocking `Socialite::driver('google')->user()`.

## Security

- **Approval gate preserved**: `statusMessage()` mirrors `UnifiedLoginController` (same rule, same wording) — an unapproved account is linked but never signed in.
- **`google_id` is never request data**: written only from the verified Socialite payload via `forceFill()`; not added to any `$fillable`.
- Linking is refused when the id belongs to another row, and when the email is already linked to a *different* Google account (no silent relink).
- No auto-registration: an unknown email is redirected back to `/login` with `auth.status_message`, and the tests assert the role table is still empty.
- Role resolution for the shared button is privileged-first (seller → logistics → buyer), identical to `UnifiedLoginController`, so an email present in several tables always reaches the same panel.
- Session fixation: `session()->regenerate()` after login, as in the password flows.

## Data Integrity

- New columns only; no existing column, index, FK or permanent record is modified. `google_id` is unique **per table**.
- `password` becomes nullable; `down()` documents that reverting it to NOT NULL fails while Google-only rows (password IS NULL) exist.
- No transaction needed: this is a single-row link, with no stock/ledger/money path.

## Tests

`tests/Feature/Auth/GoogleBuyerLoginTest.php`, `GoogleSellerLoginTest.php`, `GoogleLogisticsLoginTest.php` — Socialite driver mocked, Google never contacted:
- existing email → `google_id` linked + authenticated on the right guard + redirect to the role home;
- unknown email → guest, redirect to `login`, `auth.status_message` = `NO_ACCOUNT_MESSAGE`, role table still empty;
- unapproved account → guest + that role's approval message (and still linked);
- the logistics file additionally pins privileged-first resolution with one email in the buyer + logistics tables.

## Rollback

Delete the controller and the three tests, revert `routes/web.php`, the modal button, the CSS block and the config block, `composer remove laravel/socialite`, then `migrate:rollback` for the new migration (see the `down()` caveat above).

## Open Questions

1. **Seller + logistics login surfaces** do not exist; the shared modal is the only login UI. The role-scoped routes are already in place for the day `Seller/Auth/login.blade.php` is revived and a logistics login page is added.
2. **`Auth/login-choice.blade.php` has no Google button**, yet it is where Google errors land (chosen because its inline error block is visible, unlike a modal that is closed on `/home`). Recommend adding the same button there.
3. Role resolution order was specced as "buyer → seller → logistics"; implemented as **seller → logistics → buyer** to match `UnifiedLoginController` (see Security). One-line change in `GoogleAuthController::ROLES` if the other order is preferred.

## Acceptance Criteria

- The button renders in the shared login modal and links to `/auth/google/redirect`.
- Existing email links + signs in on the correct guard; unknown email errors out without creating a row; unapproved accounts stay signed out.
- `php artisan test --compact` green apart from the pre-existing `BuyerOrderLifecycleTest` `Orders &amp; Tracking` assertion mismatch (view hard-codes the entity, test double-escapes it).
- The migration file exists — **superseded: run on 2026-09-27** (see below).

## Post-approval changes (2026-09-27)

1. **`config/services.php`** — `services.google.redirect` now falls back to `rtrim(env('APP_URL'), '/').'/auth/google/callback'` when `GOOGLE_REDIRECT_URI` is unset. Without it Google answered *400 invalid_request / Missing required parameter: redirect_uri*. `.env` now carries the three `GOOGLE_*` keys explicitly.
2. **Server CA bundle (outside the repo)** — PHP's cURL was failing every outbound HTTPS call with *cURL error 60 / unable to get local issuer certificate*. Fix applied to `C:\php\php.ini`: `curl.cainfo = "C:\php\extras\ssl\cacert.pem"` and `openssl.cafile="C:\php\extras\ssl\cacert.pem"`, with the official bundle downloaded from curl.se (SHA-256 verified against curl.se's `cacert.pem.sha256`) and `php.ini` backed up as `C:\php\php.ini.bak-2026-09-27`. **The dev server must be restarted after any php.ini change** (PHP reads it at process start).
3. **Migration run** — `php artisan migrate --force` applied `2026_09_27_000001_add_google_id_to_role_tables` to the local/dev MySQL DB `Zefanya` after the developer confirmed it is a local DB (18 buyers / 9 sellers / 3 logistics providers / 2 orders) and that the file touches only the three role tables. Verified afterwards: `google_id` `YES`/`UNI` and `password` `YES` (nullable) on all three; `admins`/`users` unchanged; the previously failing `where google_id …` query returns `false` instead of SQLSTATE 42S22.

**Lesson:** the feature tests run on sqlite `:memory:` (migrated from scratch every run), so they stayed green while the MySQL app DB was still missing the column — a new migration must also be applied to each real environment.


## Developer Approval

- [x] Research completed
- [x] Plan reviewed
- [x] Open questions resolved — login-surface question answered with **Option B**

**Scope approved** — Option B: one role-agnostic Google button in the shared login modal; the callback resolves the role from the Google email; the three role-scoped route pairs remain available.

- [x] Implementation approved (STEP 2 of the task explicitly requested implementation; the Option B answer resolved the only blocker)

Developer approval: Option B selected in session

Date: 2026-09-27

Notes: developer fills the Google keys in `.env` and runs the migration; statuses/open questions above still need developer verification.

