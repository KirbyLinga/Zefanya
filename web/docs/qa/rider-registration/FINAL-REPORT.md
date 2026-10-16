# FINAL REPORT — Rider Registration (TC-RID-001…032)

Date: 2026-10-16. Command: `cd web; vendor/bin/phpunit tests/Unit/RiderValidationTest.php tests/Feature/RiderRegistration` → **OK (44 tests, 193 assertions)**: 32 spec + 6 unit + 6 hardening. Pint clean.

## Per-case results (all Pass)

001 happy-path row pending_activation + mail; 002 all-11 required errors; 003 per-field blank error; 004 digit/symbol names reject; 005 P+51 reject / Po+50 accept; 006 Manila 17y reject, 18y±1 accept; 007 five bad emails reject; 008 buyer email blocked; 009 four bad mobiles reject; 010 10/12-digit reject, 11 accept; 011 09 and +639 same-number blocked; 012 four bad licenses reject w/ format hint; 013 duplicate license blocked; 014 yesterday reject, today/tomorrow accept; 015 duplicate plate blocked; 016 jpg/png/pdf accept; 017 docx/gif/exe reject; 018 4.9/5.0 accept, 5.1 reject; 019 triple post → 1 row; 020 activation → active + login redirect; 021 five weak passwords reject; 022 mismatch error; 023 24h+1min expired 403; 024 resend kills first, second works; 025 used link 403; 026 pending login denied generically; 027 active login redirects; 028 B hides A rider, direct ID 403; 029 no RIDER card + guest redirect; 030 buyer/seller redirect, no row; 031 pending logistics redirect, no row; 032 rider → other panels redirect.
Hardening (6 Pass): exe-as-jpg, double-ext, email trim+case, XSS escaped, tampered token, ñ names.
Unit (6 Pass): normalizers + Manila boundaries + license case.


## Files changed and why

- `database/migrations/2026_10_16_000001_create_riders_tables.php` (new): `riders` + `rider_activation_tokens` (hash/24h/used_at), global uniques email/contact/license/plate. Rollback: `--step=1`.
- `app/Models/Rider.php` + `database/factories/RiderFactory.php` (new): Authenticatable+Notifiable rider.
- `app/Support/RiderValidation.php` (new): Manila dates, normalizers, regexes — shared single source.
- `app/Http/Requests/Logistics/StoreRiderRequest.php` (new): server rules + cross-role uniqueness + magic bytes + inline messages.
- `app/Http/Controllers/Logistics/RiderController.php` (new): company-scoped index/show/create/store/resend; DB transaction + unique-violation to field mapping; hashed 24h token + mail.
- `app/Http/Controllers/Rider/ActivationController.php` + `DashboardController.php` (new): single-use/expiry-checked activation (A5) + dashboard (name + company).
- `app/Http/Middleware/Rider/EnsureRiderActive.php`, `config/auth.php` (rider guard/provider), `bootstrap/app.php` (`rider.active` + redirects), `UnifiedLoginController` (active-only rider login + logout), `routes/logistics.php` + `routes/web.php`, views `Logistics/Riders/*` + `Rider/*` (per-field errors, old() except files, button disable).
- Tests: `tests/Feature/RiderRegistration/*` (8 files), `tests/Unit/RiderValidationTest.php`, `tests/fixtures/rider/*` (10 files + generator).
- Docs: `docs/qa/rider-registration/{00-recon,01-traceability,02-baseline,DECISIONS,FINAL-REPORT}.md`, `results.csv` (all Pass).

## Decisions / unverifiable / risks / out-of-scope defects

- See `DECISIONS.md` D1–D10 (license uppercase-only; plate/license uppercased; email lower+trim global; mobile +63 to 09 global; vehicles Motorcycle/Car/Van; jpeg accepted; Manila dates; new riders table; resend kills previous). Naming: Pint forces `test_tc_rid_XXX` lowercase — each still ID-prefixed.
- Not verifiable here: real email delivery (log/array drivers; asserted via Notification fake); production MySQL migration run; browser e2e (feature + render assertions used).
- Residual risks: provider contact_no has no unique constraint (pre-existing); activation pages minimal/unstyled; activation throttled but no CAPTCHA.
- Out of scope, not fixed: SellerRegistrationTest 17 failures on clean tree (pre-existing); suite was red on missing pdo_sqlite (enabled in C:\PHP\php.ini).
