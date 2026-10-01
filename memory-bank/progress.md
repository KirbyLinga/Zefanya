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

### The API surface (new — the first live endpoints)

- **`web/routes/api.php` + Sanctum.** `POST /api/login/buyer`,
  `POST /api/register/buyer` (multipart), the two buyer OTP routes under
  `/api/register/buyer/verify-otp/{buyer}`, `GET /api/me` and `POST /api/logout`.
  Registered in `bootstrap/app.php` via `withRouting(api: …)`, which is what
  makes the group session-less and CSRF-less — the two things a phone cannot
  provide and the reason nothing here could work before.
- **Buyers only, and the courier half names what is missing.** A courier call
  throws `ApiNotImplementedException` naming `/register/logistics` instead of
  posting the buyer's request under a different word. `HasApiTokens` is on
  `Buyer` alone: a trait on a model nothing authenticates is an untested claim.
- **One policy, on the model.** `Buyer::createFromRegistration()` (the single
  spelling of what a new buyer row contains), `issueEmailVerificationCode()` (fresh
  code + hash + 10-minute expiry, returning the raw code for the mail),
  `verifyEmailCode()` (`hash_equals`, and a cleared column matches nothing) and
  `isPastEmailVerification()`. The web controllers and the API controllers both
  call these, so the two doors cannot drift — and the web suite still passes
  unchanged (19 tests, same 2 pre-existing failures), which is what says the
  extraction moved nothing.
- **`LiveAuthRepository` is real for buyers**: `login` (stores the token via
  `ApiClient.adoptToken`), `register` (multipart, `upload_id` from
  `RegistrationProfile.govIdPath`, refused locally when there is no file),
  `verifyOtp` (posts `{otp}` — read off the frozen web client, not guessed),
  `resendOtp`, `logout` (revokes on the server, clears locally in a `finally`) and
  `refresh` (`GET /me`; a 401 forgets the token and answers `null`).
- **`ApiClient` grew exactly two things**: `postMultipart()` (a JSON body cannot
  carry a file part) and `adoptToken`/`forgetToken` (it owned the vault but could
  only read it). The timeout/socket/client mapping was extracted into one
  `_dispatch` so both paths fail identically.
- **Server tests: `tests/Feature/Api/` — 29 passing**, including the loop that
  matters (`register` → read the code out of the notification → `confirm` →
  `pending_approval`), the three gates told apart at sign-in, and "no token for an
  account that cannot use one".
- **Proven end to end over real HTTP against real MySQL** (`php artisan serve` +
  `curl`, with the code read out of `laravel.log` because the mailer is `log`):
  register 201 → wrong code 422 → expired code 422 (`expired: true`) → resend 200
  → confirm 200 `pending_approval` → login 403 `PENDING_APPROVAL` → approve →
  login 200 with a token → `GET /api/me` 200 → logout 200 → `/api/me` **401**. The
  probe row, its upload and its process were all removed afterwards; the dev
  database is back to the state it was in.
- **The email gate is reachable from the sign-in form (new).** A sign-in refused
  with `PENDING_VERIFICATION` used to end in a sentence: the login screen caught
  every `ApiException`, showed its message and stopped — so someone who closed the
  app before typing their code was told to confirm an email with no way to do it
  (the OTP screen needs a handle, and registering again would open a second
  account). `PendingApprovalException` now carries the server's `verification_id`,
  `ApiClient` fills it from the 403 body, and the login screen hands it to the new
  `AuthState.beginEmailVerification()`, which goes through the same `_applyResult`
  path as a real registration — so the session it produces is indistinguishable
  from one that never left the form and `canConfirmEmail` comes out true. Every
  *other* refusal still ends on the form with the server's sentence.
- **`login_gate_test` pins both halves of that**, and the branch was deliberately
  broken and re-run to prove the test can fail (`AccountStatus.pendingApproval`
  expected, `loggedOut` actual) rather than passing for a reason of its own. The
  login 403 was then re-checked **over real HTTP** against a probe buyer
  (`status = pending_verification`, since deleted): the body really is
  `{"ok":false,"code":"PENDING_VERIFICATION","registration_status":"pending_verification","verification_id":"2","message":"Confirm your email address to continue."}`
  — the exact field the app now reads. The same request also proved the field is
  only sent for the *email* gate: the approval gate's refusal has no handle, which
  is why `canConfirmEmail` requires both facts.
- **The throttle prefix is not decoration.** `ThrottleRequests` keys a guest by
  `domain|ip` with *no route in the key*, so every `throttle:…` route shares one
  counter per address and the limit that applies is whichever route you hit. The
  first real resend answered `429 Too Many Attempts` because four unrelated
  requests had been counted against the same bucket. Each API route now passes a
  prefix (`throttle:3,5,api-otp-resend`), which gives it its own counter — found
  by running the thing, not by reading it. **The web routes still share one
  bucket**; that is a live bug in the other surface and is flagged below.
### The catalogue (new — products, categories, vouchers)

- **The blocker was 11 unrun migrations, not code.** `products`,
  `product_images`, `cart_items`, `vouchers`, `logistics_providers`, `orders`,
  `seller_orders`, `order_items`, `payments` and `inventory_transactions` did not
  exist in the dev database at all; a `migrate --pretend` dry run checked them
  first (exit 0, writes nothing) and `migrate --force` then applied all 11.
  Invisible from the application code: the models, controllers and routes all read
  as though the tables were there.
- **Four read-only endpoints, all public, one throttle bucket each**:
  `GET /api/products` (filters `q` over name+category, `category` by label,
  `seller` by shop name; newest first; **unpaginated on purpose**, because the
  app's repository contract has no page in it and a truncated catalogue is not an
  error), `GET /api/products/{product}`, `GET /api/categories` (the 14 curated
  rows, in the migration's own order) and `GET /api/vouchers` (active, unexpired,
  under usage limit).
- **Visibility is `status = active` *and* an approved seller**, on both the list
  and the single read — an unapproved seller's product is not browsable, and it
  answers 404 rather than 403 so the id cannot be probed. A sold-out product is
  still listed, with `in_stock: false`: hiding it would be an unexplained absence.
- **`price_minor`, always centavos.** `products.price` is a `DECIMAL(10,2)` in
  pesos while every other money column is `*_minor`, so the conversion happens in
  `ProductResource` and the field is named for its scale. Sending `1250.75` would
  not fail loudly — it would render as ₱125,075.00.
- **Three fields are absent rather than faked**: `compare_at_price` (no column),
  ratings (no reviews table) and the maker note (no column). The web buyer page
  fakes all three — a struck-through price as `price / 0.75`, a hardcoded 4.9 with
  "284 reviews", placeholder feature copy — and this project's
  no-invented-content rule is why this side does not. The app also *overwrites*
  `Product`'s constructor defaults (4.5 stars, the mock's numbers) with 0, and the
  product page now drops its rating row and its review aggregate when
  `ratingCount == 0`, so a live product says "No reviews yet" instead of
  "★ 0.0 average".
- **A flat voucher is not offered to the app.** `voucherDiscountFor` reads
  `discountRate <= 0` as a *shipping* rebate, so mapping the server's
  "₱200 off the goods" would have become ₱200 off the delivery fee — a money bug
  that looks like working code. Percentage vouchers map exactly (10% → `0.10`),
  and the server's absent cap maps to `double.infinity` rather than a ceiling
  nobody set.
- **`LiveCatalogRepository` and `LiveCartRepository.vouchers()` are real**;
  `makerNote` and `promoSlides` still name themselves as not wired, because
  neither has a server-side source at all.
- **Proven on the wire.** A probe seller, product, two images and two vouchers
  were created, the four endpoints curled against `artisan serve` and real MySQL,
  and every row deleted afterwards (verified `products=0`, `sellers=0`,
  `vouchers=0`). The responses carried `id: "1"` as a **string**,
  `price_minor: 125075` from a ₱1,250.75 row, `b.jpg` **first** although it was
  created second (cover-first, end to end), both voucher scales, and the 14
  categories.

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
- **The email gate has a screen (the OTP pass).** `screens/auth/otp_screen.dart`
  collects the emailed six digits; `AuthRepository.verifyOtp`/`resendOtp` are its
  seam; `AuthResult.verificationId` carries the server's opaque handle and
  `AuthState` persists it, so the gate survives a cold start. `canConfirmEmail`
  requires the gate **and** the handle, and the pending screen's "Enter Code"
  button is gated on it. The live stubs name the *real* routes
  (`POST /register/buyer/verify-otp/{buyer}` and
  `POST /register/logistics/verify-otp/{logistics_provider}`, each with a
  `/resend` sibling) read from `web/routes/web.php`, and cite no contract section:
  the route file is in this repository and the document is not.
- **The email gate is demonstrable in mock mode without lying.** `kMockOtpCode`
  (`123456`) and `kMockVerificationId` live in `config/dev_flags.dart` — one
  constant, two readers (the mock, and the screen's dev-only hint, which must
  agree). The mock accepts the first and refuses everything else with the server's
  own sentence as a `RequestRejectedException` (a 422 with no field bag, not a
  `ValidationException`), and `AuthState.debugStartEmailVerification()` (dev-tools
  only) opens the gate so the screen is reachable by hand. The mock's `register()`
  still answers the **admin** gate: it has no mail to send, and claiming otherwise
  would send someone to an inbox nothing will ever arrive in.

### Tests — 228 passing (mobile) + 65 (server API)
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

The OTP pass added **`otp_flow_test` (24)**, in three kinds: the mock's honesty
rules (its own code accepted, every other refused with the server's sentence and
no field bag, a mock registration landing on the admin gate with no handle), the
seam (`confirmEmail` sends the registration's handle plus the six digits; a
refused code leaves the gate open; the handle survives a cold start; logging out
forgets it; no handle means no request at all; the dev-tools gate opens the mock
gate without opening the answer), the live stubs (naming `/register/buyer/...`
and `/register/logistics/...` from the route file, and a missing base URL being a
*configuration* failure), and the screen (a short code never reaches the
repository, the field cannot hold non-digits or a seventh digit, a refusal shows
the server's sentence inline and clears on edit, the resend cooldown counts down
and returns, a failed resend starts no cooldown, the dev hint names the code the
mock actually accepts, and the round trip out of the pending screen and back with
the gate flipped).
The API pass added **`live_auth_test.dart` (15)**, and it is the first suite here
that asserts on *requests* rather than on results: a fake transport records what
went over the wire, so the multipart body is checked for the server's own field
names and for the uploaded file's actual bytes, `verifyOtp` is checked to post
`{otp}` to the handle's path with no `Authorization` header attached, and login is
checked to *store* the token rather than merely return it. The refusals are
scripted, because they are the interesting ones: a shut gate arriving as
`PendingApprovalException` rather than as a credential failure, a `200` with no
token being malformed rather than a session, a `500` on logout still clearing the
token, and a `401` on `/me` clearing a dead one.

The gate pass added **5 more** (`login_gate_test` is new, `auth_flow_test` +2,
`live_auth_test` +3 assertions), all of them about the one refusal the app can act
on: a `PENDING_VERIFICATION` sign-in now carries the server's `verification_id`
into `AuthState.beginEmailVerification()` instead of ending on the form as a
sentence. `login_gate_test` is the first suite here to pump the *login screen at a
phone-shaped size* and assert which of the two outcomes it takes — and it was
broken on purpose (the gate branch disabled) to watch it fail on exactly that
assertion before being trusted.

The catalogue pass added **24 more** in `live_catalog_test.dart` (new) and **36
more** server tests across three new files (`ProductCatalogApiTest`,
`CategoryApiTest`, `VoucherApiTest`). The mobile half is the first suite here that
asserts on a *query string* (`category=Home and Garden`, `q=rattan`, `seller=…`),
and its two load-bearing assertions are absences: a live product carries no sale
price and no rating, and a flat voucher is not offered at all. Five guards were
then broken on purpose to prove they fail: the resource's `* 100` (exactly the two
centavo tests), the cover-first sort (exactly the ordering test), the
approved-seller filter (exactly the visibility test), the app mapper's
`type != 'percent'` (exactly the two flat-voucher tests), and the `rating: 0`
override (the live-product test, failing with `Expected: <0> / Actual: <4.5>`).

Two harness lessons, both found by the suite failing rather than by review: a
multipart body cannot be decoded with `bytesToString()` (a JPEG is not UTF-8 — the
recorder decodes Latin-1 instead, so the ASCII parts are still findable and the
binary parts survive for a `contains` check on the file's own bytes), and
`AuthResult`'s gate getter is `canConfirmEmail`, which requires the handle as well
as the gate — a stronger assertion than the one that failed to compile.



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
- **The web routes' throttles share one bucket per IP.** `ThrottleRequests` keys a
  guest by `domain|ip` with no route in the key, so `routes/web.php`'s
  `throttle:5,1` (register), `throttle:10,1` (verify) and `throttle:3,5` (resend)
  are three limits on *one* counter: three registration attempts consume the
  resend budget, and on a carrier-NAT mobile connection the bucket is shared by
  everyone behind that address. The API routes were fixed with a per-route prefix;
  the web routes were left alone because changing them changes the web app's
  behaviour, which is the user's call. Found by running the real flow.
- **`docs/api-contract.md` is referenced 14× in `lib/` and does not exist in this
  repo** (no `docs/`, nothing tracked). Narrowed, not closed: the auth endpoints
  no longer cite it — they are specified by `web/routes/api.php`, which is here —
  but every endpoint that does not exist yet still points at a document nobody can
  read. Either it lands corrected or those stubs name the route file when they
  become real.

### Not built (ranked)
1. **Live mode still cannot boot** — and the reason has narrowed to two fetch
   sites. The catalogue half is now real (products, categories, vouchers all
   resolve), so what remains is `OrderState.load()` → `orders()` and
   `CourierState.load()` → `assignedJobs()`, both *state* `load()`s fetching
   immediately after their (no-op) repository `load()`, and the second of them
   outside its `try` on purpose. So a live build still dies on launch, now because
   *orders and courier jobs* have no endpoint rather than because the catalogue
   has none — and "let it boot empty" is a decision about those two call sites,
   not a patch to one.
2. **The courier half of the API does not exist.** The buyer half is real and
   proven end to end; `LiveAuthRepository` names the missing routes
   (`POST /login/logistics`, `POST /register/logistics`, their two OTP paths)
   rather than sending a buyer's request with a different word in it. One blocker
   did fall in this pass: `logistics_providers` now **exists** (it was one of the
   11 unrun migrations). It still waits on the form decision below.
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
  failed exactly the one test asserting it arrives. The OTP pass did it twice
  more: inverting the mock's code check failed exactly the three tests about
  accepting its own code and refusing others, and weakening `canConfirmEmail` to
  ignore the missing handle failed exactly the two that pin "no handle, no field".
  "Prove a guard can fail" is now the first thing done after writing tests, not
  the last.
- **The mock does not fake the email gate; a dev-tools affordance opens it.**
  `MockAuthRepository.register()` keeps answering `pendingApproval`, because a
  mock claiming `pending_verification` would send someone to an inbox nothing will
  ever arrive in — the same class of lie as the reviewer ETA this screen already
  had removed. The gate is entered by `debugStartEmailVerification()` (mirroring
  `debugApprovePendingAccount`) with a handle the mock accepts, and the screen's
  hint names the code it accepts, so the flow is finishable by hand in the one
  build that exists to finish it.
- **A wrong code is `RequestRejectedException`, not `ValidationException`.** The
  server's 422 for a mismatch is `{ok: false, message}` with no `errors` bag, so a
  field-keyed type would claim the server had said *which* field was wrong when
  all it said was no.
- **Resend returns nothing, and the screen borrows the server's sentence.** No new
  status or handle comes back, so `resendOtp` is `Future<void>` and the copy is
  "A new code has been sent." — a claim only the party that sent the mail can
  make. The 30s cooldown starts *after* this screen sends one, never on arrival:
  someone who opened the screen because no mail came must be able to ask at once.
- **The OTP screen's list children are keyed.** The error block appears and
  disappears between them, and unkeyed children shift slots on every toggle —
  which made Flutter re-inflate everything below the error and restart each
  `StaggeredReveal`'s delay. That was a real re-fade of the Confirm button when a
  digit was retyped, and it surfaced first as a pending-timer failure in a test
  that ended on the rebuild.