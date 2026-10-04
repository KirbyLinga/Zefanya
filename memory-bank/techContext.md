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
   in `main()`. In live mode a repository method with no endpoint behind it throws
   `ApiNotImplementedException` naming that endpoint (see `live_stub.dart`); the
   buyer auth half is real and calls `web/routes/api.php` — see `progress.md`.
   Still true: no product has more than one real photo
   (`picsum.photos/seed/$id`), so a real catalogue will break the gallery and
   stack-showcase layouts as built.
3. **`docs/api-contract.md` does not exist in this repo** — 14 references across
   12 files in `lib/` cite it by section (§0.1/§0.2/§0.4 open questions,
   §3–§9 endpoints), and `docs/` is not tracked and not present on disk. Every
   repository still unwired therefore points at a document nobody can read, and
   for those the citation is the *only* record of the endpoint's name.
   **Narrowed twice, not closed.** Neither the auth endpoints nor the four
   catalogue endpoints cite it any more: they are real, and `web/routes/api.php`
   is the authority for their paths and payloads (the OTP body's field name was
   read off the frozen web client, not recovered from a §). Its *auth* section,
   meanwhile, was wrong — the server registers buyers at `POST /register/buyer`
   through Inertia web controllers, with no `routes/api.php` and no token auth at
   all until the auth pass added one — which is the failure mode a §-numbered
   citation has when the document is neither in the repository nor in step with
   it. What still cites it, and so still has a citation as its only record:
   `maker_note`, `GET /promotions`, orders, reviews, chat and the courier job
   routes. Either it lands corrected, or each remaining stub names its route, or
   its absence, the way the live halves now do.
4. **`Product.rating` / `ratingCount` have no source on either side.** The mock
   catalogue's numbers are constants, and the server has no reviews table at all,
   so a *live* product carries 0 and the product page drops its rating row and its
   review aggregate rather than printing an average over nothing. The mapper sets
   that 0 explicitly, because the model's own default (4.5) is the mock's number.
   When a reviews table lands, the aggregate becomes real in one place — the
   resource — and no screen has to change.

## Running the server's tests and the app against it (verified in this checkout)

This is environment knowledge that cost a session to acquire, and none of it is
guessable from the repository:

- **`php` is on PATH (8.5.9) and `composer` is installed**, but `pdo_sqlite` and
  `sqlite3` are *commented out* in `C:\PHP\php.ini`. `phpunit.xml` sets
  `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`, so the server suite cannot
  reach its database until the extension is loaded.
- **`php artisan test` cannot be fixed with `php -d`**, because it shells out to
  PHPUnit in a *child* process that does not inherit the flag — it fails 134 tests
  with "could not find driver". Run PHPUnit directly instead:

  ```
  php -d extension=php_pdo_sqlite.dll vendor/bin/phpunit --colors=never
  php -d extension=php_pdo_sqlite.dll vendor/bin/phpunit tests/Feature/Api
  ```

  With the driver loaded, migrations and bindings work on the in-memory database.
- **Feature tests that render a Blade view also need a Vite build**
  (`npm run build`); without `public/build/manifest.json` they fail with
  "Vite manifest not found" and always will here until one is built. The real
  count is **60 of 169 tests in the whole suite**, measured by stashing this
  pass's changes and re-running — not the "4 of `RegistrationStreetTest`" an
  earlier note recorded, which had only ever run a subset of files. Establish the
  count against a stash before attributing a failure to your own change. Nothing
  in `tests/Feature/Api` renders a view, so that suite is green regardless.
- **MySQL is up on 127.0.0.1:3306** (`.env` points at a real `Zefanya` database),
  and every migration has now been applied — the catalogue pass ran the last 11,
  so products, vouchers, orders, cart and logistics tables all exist. They hold
  **no rows**, though: `categories` (14, seeded by its own migration) is the only
  catalogue table with data, so a live build shows an empty shop. A real HTTP
  round trip works: `php artisan serve --port=8123`, then
  `curl.exe -s http://127.0.0.1:8123/api/products`.
- **`php artisan serve` is two processes.** The launcher spawns a child `php -S`
  that owns the port, so `Stop-Process` on the launcher leaves the server
  listening (verified by finding the port still in `netstat` while the app was
  still answering). Kill the pid that owns the port, then check the port is free
  *and* `Get-Process php` is empty. Note also that PowerShell's `curl` is an alias
  for `Invoke-WebRequest` — use `curl.exe` when you want curl's own flags.
- **A migration's existence says nothing about whether it ran.** Probe the schema
  (`SHOW TABLES`, or a row count that catches "doesn't exist") rather than
  inferring it from the models and controllers, which read as if every table were
  there. `php artisan migrate --pretend` prints the SQL and writes nothing, so
  checking first is free.
- **`MAIL_MAILER=log`**, so an OTP code is written to
  `web/storage/logs/laravel.log` as `**Verification code: NNNNNN**`. That is how
  the registration → confirm → approve → login loop was driven end to end without
  a mail account.
- `web/vendor` is **not** tracked by git, so installing a package changes
  `composer.json`, `composer.lock` and new application files only — which is why
  adding Sanctum was a reviewable diff rather than a vendor dump.

5. **The web suite cannot run on this machine without a flag — and this is the
   other developer's machine too.** `web/phpunit.xml` pins `DB_CONNECTION=sqlite`
   and `DB_DATABASE=:memory:`, and every test under `web/tests/` uses
   `LazilyRefreshDatabase`. But `C:\PHP\php.ini` has `;extension=pdo_sqlite`
   **commented out** (line 937, with `extension=pdo_mysql` live on 934), while
   `C:\PHP\ext\php_pdo_sqlite.dll` sits right there unused. So `php artisan test`
   does not silently fall back to MySQL — it dies with 70-odd "could not find
   driver" errors. Run it as
   `php -d extension=pdo_sqlite vendor/bin/phpunit --filter=Api` (verified:
   71 tests, 235 assertions, green). `web/contracts/2026-09-19-buyer-cart-page.md`
   states "`bcmath`, `pdo_sqlite`, `pdo_mysql` all present" — that claim is
   **false on this machine**, so anyone following the contracts' test
   instructions meets this immediately. Either always pass the flag or
   uncomment line 937.

6. **An uploaded product photo is currently unloadable by the app.** Probed, not
   reasoned: `Storage::url('products/x.jpg')` answers with the *relative*
   `/storage/products/x.jpg` (the default disk is `local`, rooted at
   `storage/app/private`, with no `url` key), `public/storage` does not exist
   because `storage:link` has never been run, and the app renders photos with
   `Image.network`, which cannot fetch a relative URL. `ProductResource::photoUrl()`
   now returns an already-absolute URL untouched, which is what makes the demo
   seeder's Picsum photos work; the upload path itself is still open, and fixing
   it is a decision between `Storage::disk('public')->url()` and resolving a
   relative path on the client against the API base.

## Conventions that matter when editing

- **`flutter analyze` must be clean** and `flutter test` must pass (235 tests)
  before any change is considered done.
- **Boot never depends on the network.** `main()` awaits nine `load()`s before
  `runApp`, so a rejection in any of them is not an error state — it is an app that
  never starts, with the splash holding the screen and the person seeing a logo
  forever. That happened on the first real live run, through `ChatState.load()`.
  Every load is wrapped in `StartupState.guard(what, load)`, which records the
  failure under a human name and never rethrows; `StartupNotice` shows those above
  the shell with a Retry, offered only when `isRetryable` says trying again could
  work. The one exception is `ApiNotConfiguredException`, fatal on purpose: a live
  build with no base URL is a build mistake whose owner is a developer. A state
  with a screen able to explain its own failure may also keep it
  (`OrderState.loadFailure`, `CourierState.loadFailure`) — the Orders tab can say
  what orders are, which a notice cannot. See `test/live_boot_test.dart` and
  `test/startup_notice_test.dart`.
- **Money is centavos on the wire, in both directions.** The app's `money.dart` is
  the only file that knows the scale factor, and the server converts on its own
  side of the boundary: `products.price` is a peso `DECIMAL(10,2)`, so
  `ProductResource` emits `price_minor` (integer centavos) and names the field for
  its scale. A money field that crosses without `_minor` in its name is a bug
  waiting for someone to guess — ₱1,250.75 read as centavos renders as
  ₱125,075.00, and nothing about the response looks wrong.
- **A widget test's width is not a phone width.** The test binding's default font
  gives every glyph the width of the font size, so the wordmark
  (`AppType.masthead`, 54pt) measures ~370pt and overflows the 342pt column a
  390pt phone leaves after padding — a `RenderFlex` failure caused by font
  metrics, not by layout, and one the app never has. Harnesses that force 390px
  therefore only pump screens with no wordmark in them (`otp_flow_test`,
  `registration_flow_test`); a screen that has one (login, in
  `login_gate_test`) is pumped at the binding's default size and says why.
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