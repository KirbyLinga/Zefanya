# Tech Context — Zefanya

## Stack (verified)

- **Flutter 3.44.0** stable, Dart SDK `^3.12.0`.
- Package name `zefanya`; app lives entirely in `mobile/`.
- Android + iOS targets. `flutter_native_splash` and
  `flutter_launcher_icons` are configured from the monogram assets.

## Dependencies (from pubspec.yaml)

| Package | Version | Used for |
|---|---|---|
| `provider` | ^6.1.5+1 | All app state (9 `ChangeNotifier`s) |
| `shared_preferences` | ^2.5.5 | Persistence, now reached through the *mock* repositories (plus the state classes that still own their own keys — see below) |
| `google_fonts` | ^8.2.1 | Playfair Display + Montserrat |
| `image_picker` | ^1.2.3 | Avatar, registration docs, courier proof, review photo |
| `path_provider` | ^2.1.6 | Permanent copies of picked images |
| `share_plus` | ^10.1.4 | Product share |
| `http` | ^1.6.0 | The live `ApiClient` only — mock mode never constructs one |
| `flutter_secure_storage` | ^10.3.4 | `SecureTokenStore`: the live auth token, by construction not in prefs |
| `cupertino_icons` | ^1.0.8 | — |
| `flutter_lints` | ^6.0.0 | Lint set (analyzer is clean; `flutter analyze` is a gate) |

## Known technical gaps (verified, not assumed)

1. **The fonts are NOT actually bundled.** `pubspec.yaml` carries a comment
   claiming Playfair Display and Montserrat are bundled locally "so
   google_fonts uses these instead of fetching over HTTP on first run …
   auto-detected by name … (see the two folders below)". There are **no such
   folders and no `.ttf`/`.otf` files in the repo** — `assets:` declares only
   `assets/icon/`. Consequences:
   - The app **fetches fonts over HTTP** on first launch. Offline first run
     (or a blocked CDN) falls back, which is the exact thing the comment
     claims was solved.
   - **Golden tests can never render real text** — this is why the PNG
     goldens show box glyphs. This is the stated reason goldens here assert
     *geometry and colour, never copy*.
   - Fix (not done, needs network + license files): download the weights
     referenced by `montserratTextTheme` / `playfairDisplayTextTheme` into
     `mobile/assets/fonts/`, declare that folder in `pubspec.yaml`, and
     correct the comment.
2. **The mobile app's data layer is mock-backed but *switched*.** `lib/data/`
   now holds `mock_data.dart` (the models + the sample lists), `repositories/`
   (seven interfaces, each with a `Mock…` and a `Live…` implementation), `api/`
   (`ApiClient`, typed exceptions, `SecureTokenStore`) and `dto/`. Which side is
   used is decided **once**, by `Repositories.forMode(useLive: kUseLiveApi, …)`
   in `main()`. In live mode every repository method throws
   `ApiNotImplementedException` naming its endpoint (see `live_stub.dart`).
   Still true: no product has more than one real photo
   (`picsum.photos/seed/$id`), so a real catalogue will break the gallery and
   stack-showcase layouts as built.
3. **`docs/api-contract.md` does not exist in this repo.** 14 references across
   12 files in `lib/` cite it by section (§0.1/§0.2/§0.4 open questions,
   §3–§9 endpoints), and `docs/` is not tracked by git and not present on disk.
   Every live repository therefore points at a document nobody can read. Either
   it was never committed or it lives outside the repo — **worth resolving with
   the user before more live-mode wiring is written**, because the stubs'
   endpoint/section names were taken from it. Note also that the server's own
   routes disagree with some of them: the real endpoints are `POST
   /register/buyer` and `POST /register/logistics` (Inertia web controllers, no
   `routes/api.php`, no Sanctum), which is neither the URL shape nor the
   vocabulary the mobile stubs assume.
4. **`Product.rating` / `ratingCount` are catalogue constants.** They are
   server-owned aggregates this app cannot re-derive; see the reviews design
   rule in `systemPatterns.md`.

## Conventions that matter when editing

- **`flutter analyze` must be clean** and `flutter test` must pass (160 tests)
  before any change is considered done.
- **State classes are caches over repositories.** Every state class that touches
  data takes its repository as a *constructor* argument defaulting to the mock
  (`CartState({catalog, vouchers})`, `OrderState(repository:)`,
  `ReviewState(repository:)`, `ChatState(repository:)`,
  `CourierState(repository:)`, `AuthState(repository:)`), and `main()` passes the
  resolved bundle. A defaulted mock is what keeps a bare `XState()` working in a
  test; the explicit pass is what makes the live switch possible. Never create a
  repository anywhere but `main()`.
- **Which state owns which repository (and what it may not own):** the mock
  repository owns the *storage* (key + JSON shape) for placed orders
  (`placed_orders`), written reviews (`product_reviews`) and chat threads
  (`chat_threads`) — keys and formats kept byte-for-byte compatible on purpose,
  because an existing install must not lose data on upgrade. The voucher
  catalogue comes from `CartRepository`; courier *jobs* come from
  `CourierRepository` (the milestone machine, the completion timestamps/payouts
  and the ratings stay in `CourierState`, which still owns its own
  `courier_*` keys).
  Deliberately **not** in a repository: cart lines, the auth profile fields,
  courier milestones/payouts/ratings, and the pricing maths — each with a stated
  reason in the file that owns it.
- State classes: `ChangeNotifier`, a `load()` called in `main()` before
  `runApp`, `_save()` fire-and-forget, corrupt JSON swallowed rather than
  thrown. Synchronous getters (`orders`, `all`, `vouchers`, `messagesFor`,
  `allJobs`) read a cache that `load()` and the mutators fill from the
  repository — they are called from `build()` and must stay synchronous.
- **A live repository throws, never falls back.** `live_stub.dart`'s `notWired()`
  throws `ApiNotImplementedException` naming the endpoint; a misconfigured live
  build throws `ApiNotConfiguredException` rather than using mock data. A live
  build says what is missing instead of pretending.
- **New state classes must be registered in three places in `main.dart`**:
  the local `final xState = X(repository: repositories.…)`, the
  `Future.wait([...load()])`, and the `MultiProvider` list.
- Never pass a widget as a `child:` argument to a test pump helper if that
  widget reads `AppColors` — pass a builder (see `systemPatterns.md`).
- `google_fonts` cannot fetch in tests; always set
  `GoogleFonts.config.allowRuntimeFetching = false` in test setup.
- **The test font is roughly one em wide per glyph**, so the same copy is about
  twice as wide in a widget test as on a device. Two consequences, both learned
  the hard way this session:
  - A `RenderFlex overflowed by N pixels` in a test at a *real phone width* is
    often the test font rather than a device bug — but check it at a realistic
    text scale before dismissing it, and prefer the canonical remedy anyway:
    `Flexible` around text that shares a `Row` with something else, and
    `isExpanded: true` on a `DropdownButtonFormField` (whose internal row is as
    wide as its *hint*, not its field).
  - Never assert a text's *position or width* in a widget test; assert presence,
    step, or which widget owns it.
- **`pumpAndSettle` never returns on a screen containing `ZefanyaLoader`** — its
  controller `repeat()`s forever, so "settled" never arrives. Use
  `pump(Duration)`: `StaggeredReveal` delays by `25ms × index` and then runs
  `280ms`, so one 600ms advance settles the reveals without waiting on the loader.
- **`GlobalKey` + `AnimatedSwitcher` + `PageView` is a trap.** Keying an
  `AnimatedSwitcher` on the current step rebuilds the `PageView` while the
  outgoing copy is still fading, and both subtrees then claim the same
  `GlobalKey<FormState>`s; the framework reparents the elements back into the
  outgoing subtree, so the *new* page renders empty and the form silently stops
  advancing. See `progress.md` — this was live in `registration_screen.dart` and
  is why the swept test exists.
- **A pending-timer failure at teardown is evidence, not noise.** Flutter fails a
  widget test with "A Timer is still pending even after the widget tree was
  disposed", and the fix is usually in the *widget*, not the test. Two shapes
  seen here: `StaggeredReveal` schedules its start with `Future.delayed(25ms ×
  index)` and never cancels it (it checks `mounted` instead), so a test that ends
  before the reveal has run must pump past it — and an unkeyed `ListView` whose
  children shift slots (an `if`-guarded block appearing between them) is
  re-inflated rather than reused, which restarts every reveal below it. Keying
  those children fixed both the timer and a real re-fade the person would see.
- **The live repositories throw *synchronously*** — they are not `async`, so the
  `throw` happens while the argument is being built. In a test,
  `expectLater(live.verifyOtp(...), throwsA(...))` fails as an uncaught error;
  wrap the call: `expectLater(() async => live.verifyOtp(...), ...)`, which
  handles a synchronous throw and a rejected future alike.
- **Push a screen, then count its timers.** Anything on a route that owns a
  repeating animation (a `ZefanyaLoader`) makes `pumpAndSettle` hang forever, and
  anything that owns a one-shot `Timer` makes an early test end fail. The OTP
  screen's harness therefore uses `pump(Duration)` at every step, and the
  cooldown test asserts the countdown, then pumps the full cooldown to prove the
  button comes back.