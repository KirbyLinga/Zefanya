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
| 3. Buyer commerce gaps | Done for this pass (18 new case tests + 19 re-tagged) | Address book, reviews, vouchers, checkout, search, variants and store follow now have case-named evidence. Full suite **609 passed / 0 failed**. Device/API-only rows are listed under “Phase 3 honest limits”. |
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

## Phase 3 details

### 3a. Cases now backed by a named test

`mobile/test/workbook_buyer_phase3_test.dart` (**18 passed**) covers:

- `BUY-ACCT-002` — invalid first name shows the field message and leaves the
  saved profile untouched.
- `BUY-VOU-004` — ₱299.99 is short by exactly ₱0.01; ₱300.00 and ₱300.01 earn
  the ₱50 store voucher.
- `BUY-VOU-006` — a per-buyer-used `MCH50` stays refused after a cart restart.
- `BUY-VOU-007` — removing the second line drops below ₱300, prunes the voucher
  and returns the total to ₱249.00.
- `BUY-VOU-008` — 10% of ₱999 is ₱99.90; ₱1,000 and above cap at ₱100.
- `BUY-VOU-009` — a ₱50 shipping voucher makes ₱50 free and leaves ₱15 on a ₱65
  fee, never discounting goods.
- `BUY-PDP-007` — Buy Now passes one detached line to checkout and leaves the
  existing cart untouched.
- `BUY-CHK-001` — the workbook example renders ₱1,098 + ₱185 − ₱200 = ₱1,083
  and splits into one order per seller.
- `BUY-CHK-005` — the note field stops at 200 characters.
- `BUY-CHK-006` — Cash on Delivery is preselected.
- `BUY-CHK-007` — a held repository proves Place Order disables and is called
  once.
- `BUY-CHK-009` — an out-of-stock line blocks the order and the message stays
  on screen.
- `BUY-CHK-010` — a voucher that no longer meets its minimum is removed, the
  sentence is shown, and the total moves to ₱259.00.
- `BUY-SRCH-004` — a one-letter typo offers `rattan`; `zzqxw` stays an honest
  empty state.
- `BUY-SRCH-007` — min > max and negative prices are refused without filtering;
  both bounds are inclusive.
- `BUY-SRCH-012` — the Search key closes the keyboard and pull-to-refresh reloads
  once without duplicating cards.

`mobile/test/address_book_test.dart` now carries `BUY-ADDR-001`–`007` and
`BUY-ADDR-011`, including the tenth/eleventh limit, both phone formats, the
region → city → barangay cascade, one default at a time, delete-default
protection, and a 300px simulated keyboard inset with Save still reachable.
`mobile/test/checkout_validation_test.dart` tags the checkout default-address
test `BUY-ADDR-006`.

`mobile/test/review_limits_test.dart` now carries `BUY-RATE-004` (0 stars
disables Submit, 501 characters stop at 500, five photos hide the add tile) and
`BUY-RATE-005` (first edit saves; second and day-30 edits are refused).
`mobile/test/store_follow_test.dart` carries `BUY-WISH-004`, including the
following count going N → N+1 → N with the list.

### 3b. Real bugs fixed while writing the tests

- `cart_state.dart`: minimum-spend text read “Spend ₱0.01 at Seller more”; it
  now reads “Spend ₱0.01 more at Seller”.
- `checkout_screen.dart`: any non-empty item list cleared a stock/limit error
  on the next frame. It now clears only the “no items selected” sentence, so a
  sold-out line keeps its explanation.
- `zefanya_mark.dart`: `_ZefanyaLoaderState` created its animation controller
  lazily inside `dispose()` when reduced motion was on, which crashed on
  teardown. The controller is now built in `initState`.
- `search_screen.dart`: the price-range dialog disposed its controllers when
  the route started popping, then rebuilt during the exit animation against
  disposed controllers. The dialog is now a `StatefulWidget` that owns them.

### 3c. Honest limits in this phase

- `BUY-ACCT-001` still needs a live profile endpoint; edits remain device-local.
- `BUY-ACCT-010` needs a real phone/emulator for camera and gallery denial.
- `BUY-ACCT-011` needs two phones against the live API for the second login
  screen; server-side token revocation is already proven.
- `BUY-ADDR-008` needs an order address snapshot; orders still store none.
- `BUY-ADDR-009` needs the live API; the address book is local-only.
- `BUY-ADDR-010` needs device location permission.
- `BUY-CAN-001/003/006` — stock restore, refund start, seller notification and
  voucher return have no implementation.
- `BUY-RET-001` status exists, but seller notification needs the live API.
- `BUY-RATE-001` — review display still lacks the variant and masked name.
- `BUY-RATE-007` — camera and multi-select gallery need a device; the 5-photo
  cap itself is proven by `BUY-RATE-004`.
- `BUY-PDP-006` — sale price shows, but countdown, flash units and a claim
  button are absent.
- `BUY-PDP-009` — swipe/zoom and selection retention need a device.
- `BUY-CART-008` contradicts the recorded local-cart decision.
- `BUY-CHK-002/003/004` contradict flat shipping / need serviceability data.
- `BUY-CHK-008/011/012`, `BUY-PAY-*` need the live API/payment gateway.
- `BUY-CHK-013` needs an interrupted-connection device run.
- `BUY-TRK-002/005/006/007`, `BUY-CHAT-001/003/004/006/007`,
  `BUY-VOU-012` remain Needs device/API.

## Current test counts

- Full suite after Phase 3: **609 passed / 0 failed**
  (`qa/phase3_full_test.txt`).
- `flutter analyze --no-pub`: **0 errors**, the same 9 pre-existing info lints
  (`qa/phase3_analyze.txt`).
- Phase 3 workbook tests: **18 passed** (`workbook_buyer_phase3_test.dart`).
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
