# Zefanya workbook progress

Branch: `qa/workbook-pass` (created from `mobile-dev` at `58e2f3c`)
Started: 25 October 2026
Source of truth: `qa/Mobile_App_Combined_Test_Results.xlsx` (377 cases).

## Phase status

| Phase | Status | Evidence |
|---|---|---|
| 0. Branch + baseline | Done | Baseline `flutter test`: **533 passed / 40 failed** (all 40 golden/screenshot. `qa/analyze_baseline.txt`: 10 lints, 0 errors). Baseline saved in `qa/full_test_run_baseline.txt`. |
| 1. Foundation | Done | See below. Full suite now **573 passed / 0 failed**. `flutter analyze`: 0 errors, 9 pre-existing info lints. |
| 2. Buyer account/profile | Done (003-009, 011 app half) | Password/email/phone change end to end: server endpoints + token revocation, mock/live repository, screens, 13 case-named app tests and 9 PHP tests. ACCT-001/010 still need live profile/device. |
| 3. Buyer commerce gaps | Partly built | 27 B cases + B-M quick-win cluster implemented in earlier pass. Remaining B failures still need work. |
| 4. Courier/rider app | Partly built | Pickup verification, job lifecycle, completion view. Online toggle, offers, profile documents, COD/remittance still to audit. |
| 5. Laravel server | Not started in this pass | Laravel app found in `web/`; PHP 8.5 and Composer available. D cases still blocked. |
| 6. Traceability/report | Not started in this pass | `qa/final_results.csv` and rebuilt `qa/static_review.csv` still to do. |

## Phase 1 details

### 1a. Featured deck overflow

The two overflow failures were real: the rail card body used a fixed-height
Column without a slimming path, and the promo copy columns centred a full-width
Column. `featured_deck.dart` now uses tighter body padding
(`fromLTRB(16, 10, 16, 28)`); `promo_carousel.dart` uses `Align` +
`MainAxisSize.min` so the copy centres vertically without claiming the whole
height.

- `mobile/test/featured_deck_test.dart`: **15 passed**.
- This also fixed the 411dp promotion/rail alignment check: the first
  `Align` used `Alignment.center` and shifted the left copy; it is now
  `Alignment.centerLeft`.

### 1b. Bundled fonts

- Added `assets/fonts/PlayfairDisplay-{Regular,Medium,SemiBold,Bold,Italic}.ttf`
  and `Montserrat-{Regular,Medium,SemiBold,Bold}.ttf`, instantiated from the
  upstream Google Fonts variable files with fontTools. OFL licences are beside
  them.
- Declared both families (with weights/styles) in `pubspec.yaml`.
- `lib/theme/app_theme.dart` now uses `kDisplayFontFamily` /
  `kBodyFontFamily` directly instead of `GoogleFonts.*`; the app no longer
  reaches fonts.gstatic.com at runtime. Playfair display styles fall back to
  Montserrat for glyphs Playfair lacks (notably `₱`).
- `test/flutter_test_config.dart` loads the bundled files once per test suite.
  Without it, `flutter_test` renders only Ahem boxes.
- Regenerated 42 goldens (36 screenshots across 9 screens × 2 sizes × 2
  modes, plus courier tracker, delivery completion and order confirmation).
  Reviewed Home, Cart, Product Detail, Profile, Landing Beat 2, Courier
  completion and order confirmation: typography is now the real bundled type.
  Material icons still render as outline boxes in widget-test goldens because
  `flutter_test` does not ship the MaterialIcons font; that is a harness
  limitation, not an app asset gap.

### 1c. Delivery completion label and TODAY line

`lib/screens/courier/widgets/delivery_completion_view.dart` already showed
`Delivered` and `TODAY  ₱480  ·  4 closed`. Added explicit assertions for the
word `closed` on both the delivered and returned paths.
`test/delivery_completion_view_test.dart`: **6 passed**.

## Phase 2 details

### 2a. Server: credential changes

- Migration `2026_10_25_000001_add_pending_contact_changes_to_buyers_table.php`
  adds `pending_email`, `pending_email_code`, `pending_email_expires_at`,
  `pending_phone`, `pending_phone_code`, `pending_phone_expires_at`. The current
  address/number stays live until the new destination is verified, so an
  abandoned change cannot lock someone out of their recovery address.
- `AccountSecurityController` (`web/app/Http/Controllers/Api/Buyer/`) implements
  `POST /account/password`, `/account/email`, `/account/email/verify`,
  `/account/phone`, `/account/phone/verify`, each behind `auth:sanctum` and its
  own throttle bucket. Password change requires the current password, revokes
  every other Sanctum token and keeps the requesting token. Email/phone changes
  store a pending value plus a SHA-256 code and a 10-minute expiry; duplicate
  and unchanged values are refused before a code is issued.
- `BuyerEmailChangeOtp` mails the code to the **pending** address. Phone codes
  are logged with `Log::warning` because this codebase has no SMS gateway yet;
  the hash/expiry path is the one a gateway will use.
- `web/tests/Feature/Api/AccountSecurityApiTest.php`: **9 passed / 93 assertions**
  (`qa/phase2_php_account.txt`). It checks the old password/token fails, the new
  one works, the current phone keeps its session, wrong/expired codes change
  nothing, duplicates never send a code, and unauthenticated calls get 401.

### 2b. App: account and security

- `lib/data/repositories/account_security_repository.dart`: one interface with
  stateful mock and live implementations. Mock codes reuse `kMockOtpCode`
  (`123456`); the mock tracks pending changes so wrong/expired codes are
  demonstrable without a backend.
- `Repositories.accountSecurity` and `main.dart` wire the chosen mode into
  `AuthState`; the login-screen fakes do not need account methods.
- `AuthState` exposes `changePassword`, `request/confirmEmailChange`,
  `request/confirmPhoneChange`. It does not move `email`/`phone` until the
  confirmation returns, and persists the confirmed value.
- Screens: Profile → “Account & Security” hub, `ChangePasswordScreen`, and a
  two-step `ChangeContactScreen` for email/phone. Keys:
  `change-password-tile`, `change-email-tile`, `change-phone-tile`,
  `current-password`, `new-password`, `confirm-password`,
  `change-password-submit`, `contact-value`, `contact-send-code`,
  `contact-otp`, `contact-verify`.
- `validatePassword` now names the exact failed rule (“Add an uppercase
  letter”, etc.); `normalizePhMobile` collapses `+639XXXXXXXXX` to
  `09XXXXXXXXX` before any server call.
- `test/account_security_test.dart`: **13 passed**, groups named
  `BUY-ACCT-003` through `009` plus `011`
  (`qa/phase2_account_test.txt`). Covered: success/old-password failure, each
  password rule, old email live until OTP then cold-start persistence, wrong and
  expired codes, duplicate/unchanged email and phone, `+63` normalization,
  save-only-after-verify, and the changing phone staying signed in.

### 2c. Honest limits in this phase

- `BUY-ACCT-001` still needs a live profile endpoint; edits remain device-local.
- `BUY-ACCT-010` (camera/gallery permission denial with a Settings link) needs a
  real phone/emulator; the code has no permission-denial Settings affordance yet.
- `BUY-ACCT-011`: the server test proves the other token gets 401 and the old
  password stops working; the Dart test proves phone A stays signed in. Showing
  phone B's login screen still needs two devices against the live API.

## Current test counts

- Full suite after Phase 2: **586 passed / 0 failed**
  (`qa/phase2_full_test.txt`).
- `flutter analyze --no-pub`: **0 errors**, the same 9 pre-existing info lints
  (`qa/phase2_analyze_final.txt`).
- Phase 2 account tests: **13 passed** (`qa/phase2_account_test.txt`).
- Phase 2 PHP account API tests: **9 passed / 93 assertions**
  (`qa/phase2_php_account.txt`).
- Full suite after Phase 1: **573 passed / 0 failed**
  (`qa/phase1_full_test.txt`).
- Baseline before Phase 1: **533 passed / 40 failed**
  (`qa/full_test_run_baseline.txt`).

## Next

1. Audit Phases 3-4 against the workbook and mark only tested/code-proven
   cases.
2. Implement remaining high-value B clusters, then re-run full suite.
3. Wire/verify Laravel D cases and add PHP feature tests.
4. Build `qa/final_results.csv` for all 377 cases and the device/API manual
   script.
