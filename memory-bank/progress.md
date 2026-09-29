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

### Tests — 141 passing
`theme_contrast_test`, `courier_flow_test`, `personalization_test`,
`nav_glyphs_golden_test`, `delivery_completion_view_test`, `review_flow_test`,
`order_confirmation_dialog_test`, `product_image_cache_test` (6),
`product_gallery_test` (4), `courier_tracker_golden_test` (5), `widget_test`,
plus this session's two: **`auth_flow_test` (4)** — sign-in had no test at all —
and **`repository_wiring_test` (7)** — each state reports its *injected*
repository's answer (proved to fail when the wiring is removed), and a live
bundle throws rather than falling back to mock data.

Four golden PNG sets are tracked: `nav_glyphs_{light,dark}.png`,
`completion_{light,dark}.png`, `order_confirmation_{light,dark}.png`,
`courier_tracker_{light,dark}.png`.

## What's left

### Blocked on a user decision
- **`docs/api-contract.md` is referenced 15× in `lib/` and does not exist in this
  repo** (no `docs/`, nothing tracked). The live repositories' endpoint names and
  section numbers came from it, so it needs to land in the repo or the stubs need
  rewriting against whatever the real source is. See `techContext.md`.

### Not built (ranked)
1. **Live mode cannot actually boot.** `main()`'s `Future.wait` includes each
   state's `load()`, and in live mode `OrderState.load()` → `OrderRepository.orders()`
   throws `ApiNotImplementedException`, so the app dies before `runApp`. Correct
   for a build whose API doesn't exist, but it means "live" is not yet a state you
   can run and look at.
2. **Registration has no password field**, so `AuthRepository.register()` is
   unused: `AuthState.submitForApproval()` still sets the pending status locally
   rather than inventing a credential to send. Add the field (with the OTP step)
   before wiring it.
3. **Sort / filter on category grids** — currently a static slice.
4. **Search history + saved searches** — only 5 hardcoded popular searches,
   despite `RecentlyViewedState` existing.
5. **Real seller photos + maker quotes** — `Product.photos` and
   `mockMakerNotes` are the placeholders; both are one lookup away from real
   data, and the portrait slot deliberately shows a monogram rather than a
   stock photo of a stranger.
6. **Font bundling** — see the finding in `techContext.md`; the comment is
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