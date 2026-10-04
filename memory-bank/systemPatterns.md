# System Patterns — Zefanya

## Directory shape

```
lib/
  config/     pricing.dart (shipping, voucher maths), dev_flags.dart (mock/live)
  data/       mock_data.dart (models + sample lists)
              api/          ApiClient, api_exception.dart, token_store.dart
              dto/          server-shape → model mappers (money.dart, remote_status.dart)
              repositories/ 7 interfaces + Mock/Live impls, repositories.dart (bundle)
  screens/    auth/  buyer/  courier/  shared/
              buyer/widgets/  courier/widgets/
  state/      9 ChangeNotifier classes (see below)
  theme/      app_theme.dart — palette, AppColors, AppTheme, AppType
  utils/      image_picker_helper.dart, timed_snackbar.dart, motion.dart
  widgets/    shared design primitives
```

## Repository layer — the mock/live switch is a *config* change

`Repositories.forMode(useLive: kUseLiveApi, baseUrl: kApiBaseUrl)` in `main()` is
**the only place the decision is made**, and nothing else in the app branches on
the mode (no service locator, no Provider lookup for the bundle). Screens and
state classes receive interfaces and cannot tell which side they are talking to.

- Seven interfaces: `Auth`, `Catalog`, `Cart`, `Order`, `Review`, `Chat`,
  `Courier`. Each file holds `abstract class X`, then `MockX`, then `LiveX`, in
  that order, with the reasoning for what the interface does *not* cover written
  at the top of the file.
- **The mock owns the storage** for the things it is the data source for, so a
  state class is never a second copy of the same data. Keys and JSON shapes were
  kept identical to what the state classes wrote, so upgrades don't lose data.
- **The live implementations throw** (`live_stub.dart` → `notWired(...)` →
  `ApiNotImplementedException` naming the endpoint) rather than containing
  untested request code. A live build is runnable-and-honest, not functional.
- **State classes are caches over a repository**: constructor injection
  (defaulting to the mock, which is what keeps `XState()` working in a test),
  a `load()` that fills the synchronous getters' cache, mutators that write
  through the repository and re-read it rather than writing the cache twice.
- Deliberately left out of the repositories, each with a stated reason in the
  owning file: cart lines, auth profile fields, courier milestones/payouts/
  ratings, pricing maths. `CourierRepository` is the narrowest on purpose (jobs
  only) — see its header.

## Shared server rules — one service, both doors

The web app and the API are two doors into the same database, and the rule that keeps
them honest is: **when both need the same logic it becomes a service they both call —
never a copy, and never a private method one of them cannot reach.**

- `App\Services\CartSummary` — the money, in integer centavos, for a set of lines.
- `App\Services\OrderPlacer` — placing an order: the locked stock decrement, the
  proportional discount split across shops, the address snapshot and the append-only
  ledger, all inside one transaction. It takes **lines** (`[{product_id, quantity}]`)
  because that is the only shape both doors have: the web's cart is server-side
  `cart_items` rows parked in a session, and the phone's cart is on the phone. Each
  door resolves what is genuinely its own question first — which voucher (session vs
  request) — and hands over nothing but ids and quantities. A request contributes *what*
  is bought and *how many*, never *how much*.
- `ProductResource::photoUrl()` — passes an already-absolute URL through untouched, so
  the seeded photos and a real upload can coexist.

The payoff is not only reuse. Moving the rules out of `CheckoutController` is what made
them **testable**: the one test that could reach them went through a *rendered*
confirmation page, and this repo has no built frontend, so it died on the Vite manifest
before asserting anything about the rows it had just written. Rules in one place, with no
view in the path, finally get their own tests — and they catch things (`OrderPlacerTest`,
7 tests, found a seeder bug on its first run).

Corollary for demo data: seed **through the service**, not beside it. `DemoOrderSeeder`
places real orders, so the demo rows are the rows a checkout writes — the demo is never
the only code path that has ever exercised a hand-built shape.

## State layer — 9 `ChangeNotifier`s, one root `MultiProvider`

`AuthState`, `CartState`, `ChatState`, `CourierState`, `OrderState`,
`RecentlyViewedState`, `ReviewState`, `WishlistState`, plus `ThemeState`.

All are created in `main()` (each handed the repository/bundle members it needs),
`load()`ed via a single `Future.wait` before `runApp` (no empty-then-jump flash),
and exposed with `ChangeNotifierProvider.value`.

**Each state's `load()` is also what restores its repository's local data**, so
`Repositories.loadLocal()` is deliberately *not* in that `Future.wait` — calling
both would read the same storage twice for the same result (`loadLocal()` is
kept for a future caller that holds no states). Every live repository's `load()`
is a no-op, so a live build still doesn't block its first frame on the network.

**`AuthGate` is the app's only route root.** Every "back to the start"
action uses `popUntil((route) => route.isFirst)`. Landing is a *swap of what
AuthGate returns*, not a route — giving it a route would break logout and
post-registration unwinding across four screens. There is exactly one root
route; keep it that way.

## Theme layer — the part with the most hard-won rules

- `AppPalette` is **data** (`.light` / `.dark`); `AppColors` is a **runtime
  resolver** over `_active`. `AppColors.apply(brightness)` is called once at
  the root, before the first paint, because `MaterialApp` evaluates *both*
  `theme` and `darkTheme` on every build.
- **A fill and a rule need opposite per-mode treatment.** `hueOnSurface`
  composites a hue onto the surface (light mode scales it ×2.2);
  `ruleOnGround` darkens pale hues on paper. They are not interchangeable.
- **Ink for a fill is per-fill, not per-mode.** `textOnLight` /
  `textOnDark` / `tintInk` / `onPromoGround` exist because a pale sage fill
  takes dark ink in *both* modes. Using `primaryDark` there produced the
  2.43:1 "Featured" bug.
- **Contrast floors, asserted in `test/theme_contrast_test.dart` for both
  modes:** 4.5:1 for text, **3.0:1 for graphics a user must *understand***
  (stars, rules, icons), and loosely >1.05:1 for decorative tint fills.
  Every token must clear its floor **on every ground it appears on** — a
  token that only clears one ground is not a token (this is how
  `ratingStar` was caught at 2.86:1 on `surfaceMuted`).
- `ShapeTier` (hero/card/accent/control/pill) is the single shape scale.
  `editorialRadius` / `editorialShape` require a tier; alternating by index
  gives adjacent tiles opposite leans. Only 3 documented literal radii
  remain.

## The test-harness trap (cost me a wrong golden once)

In a golden/pump helper, **pass a builder, not a widget**:

```dart
required Widget Function() child,   // correct
required Widget child,              // WRONG — see below
```

`child: _sheet()` is evaluated as an *argument*, **before**
`AppColors.apply(...)` runs inside the helper — so anything that reads
`AppColors` while being constructed captures the previous mode. This
produced a "dark" golden with a light background. Same class of bug as the
app-level one where `AppTheme.light` returned `Brightness.dark`.

**Corollary: verify the harness, not just the output.** Sampling a corner
pixel (expecting non-transparent, dark) is what caught it; my eyes did not.

## Review system

- `ReviewState` holds the loaded copy; the reviews themselves are the
  `ReviewRepository`'s, keyed by `(orderId, productId)`. Re-submitting
  **replaces**, which is what makes "Edit Your Review" an edit — and the
  repository is required to implement the same rule, because in live mode the
  replacement happens on the server.
- **Deliberate non-blend:** `Product.rating`/`ratingCount` is catalogue data
  and is rendered as a self-contained aggregate block; written reviews are
  labelled `YOUR REVIEW`. Inventing a blend would make every screen disagree
  with every other one.
- A review is attached to a real purchase. `ReviewButton` renders **nothing**
  when an order's `productIds` can't be resolved ("contents unknown"), rather
  than opening an empty form.

## Personalization (the join)

`Order.productIds` is the single join key, surfaced as
`OrderState.purchasedProductIds`. It is derived from existing data on
purpose — no event stream, nothing that can drift from order history. It
answers exactly one question: *has this person already bought this?*

## Image loading

`ProductImage` measures its own slot with a `LayoutBuilder` and asks for a
decode at `slotWidth × devicePixelRatio`, clamped to the 400px source, and
passes `null` (not 400) once the request reaches the source so no second
cache entry is created. Call sites do **not** declare sizes.