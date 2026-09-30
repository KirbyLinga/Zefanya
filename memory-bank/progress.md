# Progress

## What works

### Theme / design system
- Light **and** dark modes, toggleable from Profile → APPEARANCE, persisted.
- `AppColors` is a runtime resolver over `AppPalette.light`/`.dark`;
  `AppTheme.light`/`.dark` built from explicit palettes.
- `ShapeTier` is the one shape scale; ~115 `const` call sites migrated.
- Contrast is asserted in both modes, on both grounds, for text (4.5:1) and
  for meaningful graphics (3:1).

### Buyer
- Landing → login/registration → pending approval → shell.
- Home (cover story, category tiles, promo carousel, rails), search,
  categories, product details (multi-photo gallery, seller story, reviews,
  add-to-cart with flight animation), cart, wishlist, checkout, orders, order
  detail, courier tracker, chat, profile, edit profile.
- **Review flow (new):** write/edit a review from Orders, order detail, or a
  product page; stars + optional comment + optional photo; persisted;
  `YOUR REVIEW` tile on the product page.
- **Checkout confirmation (rebuilt):** names the order number, states a
  multi-seller checkout honestly, and opens the real tracker.
- **Personalization joins:** `Order.productIds` → recently-viewed filtering,
  wishlist "IN CART" tags, "ON SALE" from `compareAtPrice`.

### Courier
- Job dashboard (available / active / completed), milestone state machine,
  proof-of-delivery photo, completion screen with the
  confirm-many/celebrate-few rule, **returnToSender never celebrates** and
  states a *partial* trip fee (`kReturnFeeShare = 0.5`, per-job, so a rate
  change never rewrites history), courier name recorded at `assignedToRider`
  and shown on the buyer's tracker with photo + contact (chat).

### Data contracts
- **`Product.images`** — ordered photo list, **cover first**. This is the
  contract a Seller-side upload screen would have to produce, so it was
  decided once rather than twice. `Product.photos` falls back to a single
  placeholder derived from `id`, so no call site special-cases a photoless
  product; `Product.hasGallery` gates the pager chrome.

### Data layer (new — the mock/live seam)
- `lib/data/repositories/` holds **seven interfaces**, each with a `Mock…` and a
  `Live…` implementation, plus `repositories.dart` (the bundle) and
  `live_stub.dart` (the shared throw). `lib/data/api/` holds the `ApiClient`,
  typed `ApiException`s and `SecureTokenStore`; `lib/data/dto/` holds the
  server-shape mappers.
- **One config decision:** `Repositories.forMode(useLive: kUseLiveApi, …)` in
  `main()`. Nothing else in the app branches on the mode.
- **All six state classes that touch data take their repository by
  constructor** (`AuthState`, `CartState`, `ChatState`, `CourierState`,
  `OrderState`, `ReviewState`), and the three catalogue-id resolvers take the
  catalogue. `ChatState`, `ReviewState`, `CourierState` and `CartState` no
  longer hold their own copy of that data — the mock repository now owns the
  storage, with **keys and JSON shapes unchanged** so an upgrade loses nothing.
- **Live implementations throw** `ApiNotImplementedException` naming their
  endpoint (§3–§9) instead of containing never-executed request code.
- **`AuthRepository.register()` has a caller as of this session.** The
  registration form collects a password, `AuthState.submitForApproval()` is async
  and hands over a typed `RegistrationProfile`, and the status it gets back is the
  repository's answer — the rule `login()` already followed. The pending *gate*
  (`pending_verification` vs `pending_approval`) is stored *and persisted*, so the
  pending screen can say which of the two it is, including after a cold start.
- **`lib/data/dto/registration_payload.dart`** is the one file that knows what the
  server calls the registration form's answers (`last_name`, `sex` as
  `male|female`, `birthday` as `YYYY-MM-DD`, `address_mode`, `contact_no`). Only
  the live implementation converts; no screen spells a wire key, and the ID image
  is kept explicitly *out* of the field map because it is a multipart part. The
  live `register()` still throws `notWired(§3.2)`: the mapping is tested, the
  multipart transport is not written, because request code that has never run
  against a server is not a feature.

### Tests — 160 passing
`theme_contrast_test`, `courier_flow_test`, `personalization_test`,
`nav_glyphs_golden_test`, `delivery_completion_view_test`, `review_flow_test`,
`order_confirmation_dialog_test`, `product_image_cache_test` (6),
`product_gallery_test` (4), `courier_tracker_golden_test` (5), `widget_test`,
**`auth_flow_test` (4)** — sign-in had no test at all — and
**`repository_wiring_test` (7)** — each state reports its *injected*
repository's answer (proved to fail when the wiring is removed), and a live
bundle throws rather than falling back to mock data.

This session added **`registration_payload_test` (10)** — the wire mapping
(birthday as a date not a timestamp, sex lowercased, the address *code* slots
absent, empty optionals omitted rather than blank, the phone stripped to the
server's `^09\d{9}$`, `kMinPasswordLength` pinned to 8, the ID image excluded
from the field map because it is a multipart part) — and
**`registration_flow_test` (9)**: the form refuses a short password and a
mismatched confirmation *and does not advance* (with a positive control proving
it does advance when both are valid — otherwise the two negative tests would
pass for any reason at all), the Courier gets five named steps, `submitForApproval`
reaches the repository with the real password, mock mode is unchanged, the gate
survives a cold start, an install predating the gate key reads as the admin
gate, and the two gates render different copy.

**Both guard families were proved to fail on purpose.** Drifting
`kMinPasswordLength` to 5 broke the payload test (`Expected: <8> Actual: <5>`)
*and* let a 7-character password through the form; sending `''` instead of the
password broke exactly the one test that pins it (`Expected:
'hunter2hunter2' Actual: ''`).

Four golden PNG sets are tracked: `nav_glyphs_{light,dark}.png`,
`completion_{light,dark}.png`, `order_confirmation_{light,dark}.png`,
`courier_tracker_{light,dark}.png`.

## What's left

### Blocked on a user decision
- **`docs/api-contract.md` is referenced 14× in `lib/` and does not exist in this
  repo** (no `docs/`, nothing tracked). The live repositories' endpoint names and
  section numbers came from it, so it needs to land in the repo or the stubs need
  rewriting against whatever the real source is. See `techContext.md`. (One
  citation was dropped this session — `AuthState` no longer points at §3.2, since
  the endpoint is named in the live stub itself.)

### Not built (ranked)
1. **Live mode cannot actually boot.** `main()`'s `Future.wait` includes each
   state's `load()`, and in live mode `OrderState.load()` → `OrderRepository.orders()`
   throws `ApiNotImplementedException`, so the app dies before `runApp`. Correct
   for a build whose API doesn't exist, but it means "live" is not yet a state you
   can run and look at.
2. **The OTP half of registration is not built.** The form now sends a password
   and the pending screen tells the two gates apart, but there is no in-app field
   for the emailed code. The server side exists
   (`VerifyBuyerOtpController` / `VerifyLogisticsOtpController`: `POST
   /register/buyer/verify-otp/{buyer}`, 6 digits matched against a sha256 hash,
   10-minute expiry, a `resend` route, `422 {expired: true}` on expiry), and
   `remote_status.dart` already maps all four statuses. Doing it means
   `AuthRepository.verifyOtp`/`resendOtp`, a screen, and an opaque
   `verificationId` on the result to carry the server's `buyer_id` — the current
   `AuthResult` has nowhere to put it.
3. **The Courier registration form does not match
   `StoreLogisticsRegistrationRequest`.** That request requires `business_name`
   (the form never asks for one) and `dti_permit_upload` (the form collects a
   driver's licence and an OR/CR in its place), and it has **no vehicle field at
   all** — while the form collects a vehicle type and a plate number, which
   therefore have no destination server-side. The buyer half of the mapping was
   written; the courier half deliberately was not, because a mapping that can
   only produce a 422 is worse than none. This needs a product decision (change
   the form, or the server) before the courier can register at all.
4. **Buyer registration status is a bare string server-side.** `Buyer` has no
   status enum — `RegisterBuyerController` hardcodes `'pending_verification'` and
   `Buyer::isApproved()` compares `=== 'approved'` — whereas
   `LogisticsProviderStatus` *is* an enum whose four values are exactly the ones
   this app maps. The app's typed mapping is therefore ahead of the server for
   buyers, which is a drift risk worth knowing before it bites.
5. **Sort / filter on category grids** — currently a static slice.
6. **Search history + saved searches** — only 5 hardcoded popular searches,
   despite `RecentlyViewedState` existing.
7. **Real seller photos + maker quotes** — `Product.photos` and
   `mockMakerNotes` are the placeholders; both are one lookup away from real
   data, and the portrait slot deliberately shows a monogram rather than a
   stock photo of a stranger.
8. **Font bundling** — see the finding in `techContext.md`; the comment is
   currently untrue and the app fetches over HTTP.

### Known issues
- Golden tests **will fail on a Flutter/Skia upgrade**; regenerate with
  `--update-goldens` and open the PNGs.
- Goldens show placeholder boxes for text (fonts unavailable in tests). By
  design, copy is never goldened — but note the *icons* are also tofu in a
  golden, because no icon font is registered either. A golden therefore
  proves layout, colour and hierarchy, and says nothing about glyph artwork
  (that is what the nav-glyph sheet is for).
- In the *test* canvas (720px) the two-order confirmation dialog scrolls to
  reach "Done". Real phones fit; the short-screen case has an overflow test.

## Evolution of decisions

- **Light mode was restored from git history (`cfbac3e`) and dark preserved
  exactly** — rather than re-deriving either.
- **`AppColors` stayed a global resolver** instead of migrating ~460 call
  sites to a context-scoped extension. The role layer
  (`hueOnSurface` / `ruleOnGround` / `tintInk`) makes that mechanical later.
- **Fabricated review content was deleted, not rewritten.** Two hardcoded
  tiles and a five-entry "See all" sheet (invented buyers and comments) are
  gone, and a test now asserts those names never render again. Writing
  *better* fake reviews was the trap.
- **`LinenDivider` was deleted** rather than preserved; `textureThread` went
  with it.
- **Confirmed: extract-to-test beats inspect-by-eye** for layout work. Both
  extractions (`DeliveryCompletionView`, `OrderConfirmationDialog`) exist
  because a data-in widget can be rendered, and rendering is what found the
  204px overflow and the mislabelled button.
- **`Product.images` was built after all**, once the user greenlit it: a
  shared contract is better decided than deferred, provided it is the
  *smallest* shape that expresses the idea (an ordered list of strings, with
  how a photo is stored left explicitly unsettled in the doc comment).
- **Return fee settled as partial** (`kReturnFeeShare = 0.5`). A courier who
  made the trip is paid for the trip; the full fee is for a completed
  delivery. Stored per job at completion time so changing the rate later
  cannot rewrite past earnings.
- **A clipped `clamp()` was found by a test, not by reading.** `ProductImage`
  documented "out-of-range clamps to the cover" while the code did
  `clamp(0, len - 1)`, which lands on the *last* photo. Code now matches its
  stated contract.
- **An invented deviation was withdrawn after measuring.** The gallery dots
  were first given `primaryDark` for a reason stated in a comment (accent
  "fails the 3:1 floor on a light scrim"). Measurement showed accent clears it
  in both modes (4.99 light / 5.12 dark) and that `primaryDark` was the weaker
  choice in dark (3.58). Dots now match the promo carousel's own vocabulary.
- **A blocker was found by writing the test the feature needed, and it was
  pre-existing.** The stepped registration form's Continue button had never
  advanced its content: the `AnimatedSwitcher` keyed on `_step` rebuilt the
  `PageView` mid-fade, two subtrees claimed the same `GlobalKey<FormState>`s, and
  the framework resolved it by leaving the incoming page empty. The new password
  step would have been unreachable, so it was fixed rather than worked around.
  Proved pre-existing by stashing the session's `lib/` changes and reproducing it
  on committed code — the claim "I broke this" and "this was already broken" are
  different claims and only one of them is worth reporting.
- **Two overflows were fixed with the canonical remedies rather than the test
  being widened**: `Flexible` where text shares a `Row`, `isExpanded: true` on
  every dropdown. Both were invisible because the Address step had never been
  rendered — the stepping bug hid the layout behind another bug.
- **Both new guard families were broken on purpose before being trusted.**
  Drifting `kMinPasswordLength` to 5 failed the pinned-constant test *and* let a
  7-character password through the form; sending `''` instead of the password
  failed exactly the one test asserting it arrives. "Prove a guard can fail" is
  now the first thing done after writing tests, not the last.