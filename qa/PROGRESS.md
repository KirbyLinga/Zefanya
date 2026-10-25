# Zefanya workbook progress

Branch: `qa/workbook-pass` (created from `mobile-dev` at `58e2f3c`)
Started: 25 October 2026
Source of truth: `qa/Mobile_App_Combined_Test_Results.xlsx` (377 cases).

## Phase status

| Phase | Status | Evidence |
|---|---|---|
| 0. Branch + baseline | Done | Baseline `flutter test`: **533 passed / 40 failed** (all 40 golden/screenshot. `qa/analyze_baseline.txt`: 10 lints, 0 errors). Baseline saved in `qa/full_test_run_baseline.txt`. |
| 1. Foundation | Done | See below. Full suite now **573 passed / 0 failed**. `flutter analyze`: 0 errors, 9 pre-existing info lints. |
| 2. Buyer account/profile | Previously built; needs workbook proof | Change email/phone/password, OTP, session revocation, photo permission denial. Audit before claiming. |
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

## Current test counts

- Full suite after Phase 1: **573 passed / 0 failed**
  (`qa/phase1_full_test.txt`).
- `flutter analyze --no-pub`: **0 errors**, 9 pre-existing info lints
  (`qa/analyze_phase1.txt`).
- Baseline before Phase 1: **533 passed / 40 failed**
  (`qa/full_test_run_baseline.txt`).

## Next

1. Audit Phases 2-4 against the workbook and mark only tested/code-proven
   cases.
2. Implement remaining high-value B clusters, then re-run full suite.
3. Wire/verify Laravel D cases and add PHP feature tests.
4. Build `qa/final_results.csv` for all 377 cases and the device/API manual
   script.
