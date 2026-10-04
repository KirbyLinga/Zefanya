# Active Context

## Where things stand

**The app can read its server's catalogue, place an order through it, and get the
order back.** Three passes have landed against the real API: the auth half
(register → confirm → sign in → `GET /me` → sign out), the catalogue half
(`GET /products`, `/products/{id}`, `/categories`, `/vouchers`), and now the orders
half — `GET /orders` for the buyer's history and `POST /orders` to place one, both
behind `auth:sanctum` and both served by the web checkout's own money rules. The
mock/live decision is still one config line in `main()`:

```dart
final repositories = Repositories.forMode(useLive: kUseLiveApi, baseUrl: kApiBaseUrl);
```

`flutter analyze` is clean; **266 mobile tests pass** and **95 server tests pass**
in `tests/Feature/Api`. Every live call in every half has been driven at least once
over real HTTP against real MySQL — and the demo database now holds two seeded
orders, so the Orders tab has something true to list on a device.

This pass, in one line: **the placing rules stopped being a private method of the
web's checkout** — they are `App\Services\OrderPlacer`, reached by both doors as
plain lines, with the app's live repository posting to `POST /orders` and two demo
orders seeded *through* that service.

## This pass: the app can place an order (and the rules both doors share)

`POST /api/orders` exists, and the interesting part is not the route — it is that the
money rules are no longer private to one door, or in a place the app cannot reach.

- **The fork the last pass named is settled: extract, not sync.** The web's placing
  logic was private to `CheckoutController` and driven by a session of server-side
  `cart_items` rows; the app keeps its cart on the phone. What the two doors *can*
  share is **lines** — a product id and a quantity each — so `OrderPlacer::place()`
  takes exactly that, and each door resolves what is its own question first (which
  voucher: the web reads its session, the API reads the request). Everything
  authoritative is still computed server-side inside the transaction from the locked
  rows, so a body chooses *what* is bought and *how many*, never *how much*.
  `PlaceOrderApiTest` proves it by posting a `price_minor` of 1 and asserting the
  catalogue's price is what gets charged.
- **The extraction bought more than reuse: it made the rules testable.** The only web
  test that reached them was `CheckoutControllerTest`, and it died rendering the
  confirmation page — no built frontend in this repo, so it failed on the Vite
  manifest *before asserting anything about the rows it had just written*. The rules
  were effectively uncovered. `OrderPlacerTest` (7 tests, 40 assertions) places with
  no view in the path: the centavos, a two-shop discount split summing to exactly the
  discounted total, the address snapshot not following a later profile edit, and a
  sold-out line rolling the whole order back — no order, no payment, no phantom stock,
  no ledger row. It caught a real bug on its first run, too: re-running the catalogue
  seeder hands stock back to the orders already placed.
- **A refusal is `422` with the server's own sentence.** A basket that is no longer
  placeable says so in a sentence the app can show; a stale basket is a thing the
  person can fix, which an opaque `500` pretends is not true.
- **A voucher that does not apply is *refused*, not dropped.** The web door can drop
  one and carry on, because its next page re-renders the totals; a phone showing "₱200
  off" would otherwise place the order, be charged full price, and display the wrong
  number. Refusing is what makes a stale basket visible instead of letting it quietly
  change the price.
- **The reply is the history's shape, plus order money in `meta`** — because a
  discount is split *across* parcels, so only the order can report a total honestly.
  One consequence worth stating: `GET` and `POST` share **one** parser
  (`remote_orders.dart`), so a freshly placed parcel cannot be read differently from a
  listed one.
- **App-side, `LiveOrderRepository.place()` is real.** Lines go up, the token says
  who (never a body field), parcels come back through the same mapper — and a refusal
  travels as an error carrying the server's sentence rather than as an empty list,
  which would read as "placed, but with no parcels".
- **The payoff that made the seeding obvious: placing an order is now five lines.**
  The catalogue and buyer were seeded and `GET /orders` answered `[]` — honest, and
  therefore an unverifiable screen. `DemoOrderSeeder` places two orders for
  `buyer@zefanya.test` *through `OrderPlacer`*, so the demo data is written by the
  code that writes orders: real prices, a real stock decrement with its ledger entry,
  real payments, a real address snapshot, a real `ORD-…` reference. One order spans
  two shops on purpose — two parcels under one order number is the one thing a
  single-shop fixture cannot show on a screen.
- **A seeder that places orders must not re-lay the ground under them.** Calling
  `DemoCatalogueSeeder` unconditionally looked safe ("it is idempotent") and was not:
  a second `db:seed` handed stock back to the orders the first pass had placed. The
  dependency seeders are called only when their ground is missing, which is what
  "re-runnable" has to mean for this one.

## This pass: live mode boots (the two boot fetches stopped being fatal)

`main()` awaits nine `load()`s before `runApp`, so a rejection in any one of them
was never an error state — it was an app that never started. Two of those loads
ask the network for data the server does not serve yet (orders, courier jobs), so
a live build could not reach its first frame at all. That is now fixed, and the
shape of the fix is the part worth remembering:

- **The failure is recorded, not swallowed.** `OrderState.loadFailure` and
  `CourierState.loadFailure` carry the `ApiException` that left a cache empty. The
  Orders screen renders it, and so do the two courier dashboards' empty states,
  with **Retry only when `isRetryable` says retrying could work** — an endpoint
  that has not been built answers no, and a button that cannot work is worse than
  no button. So "no orders yet" and "we could not ask" are visibly different,
  which is the distinction `CourierRepository.assignedJobs()`'s own doc demands.
- **One exception stays fatal: `ApiNotConfiguredException`.** A live build with no
  base URL is a build mistake with a developer on the other end, not a runtime
  condition for the person holding the phone. One of `repository_wiring_test.dart`'s
  two tests was rewritten this pass: it used to assert that `load()` *throws*, and
  now asserts that the cache stays **empty rather than mock** and that the failure
  is named. Same rule, different mechanism — and the rule was the part that
  mattered, because a silent fallback to mock data is a build that looks
  functional while serving invented things.
- **`live_boot_test.dart` (7 tests)** pins all of it, including the widget path: an
  unbuilt endpoint shows the failure with no Retry, a dropped connection shows it
  with Retry, and Retry then genuinely re-reads.
- **Proven by mutation**: reverting `load()`'s try/catch fails 6 of those tests and
  nothing else — the tolerance, the retry path, the widget behaviour and the wiring
  rule.

## This pass: the order history is real (and the fork `POST /orders` hits)

`GET /api/orders` exists: the buyer's own parcels, behind `auth:sanctum` — the first
authenticated *read* on this API, which is also why `/me` exists beside it. The query
begins at the join (`seller_orders` → `orders`) and is scoped through
`orders.buyer_id` rather than an id from the request, so a request cannot name
another buyer's order and there is no ownership check to forget. Newest checkout
first, ordered by the *parent* order's timestamp, so the two parcels of one checkout
stay together instead of interleaving by their own ids.

`OrderResource` is keyed on a seller order because that is what the app already
models — one parcel, one `Order` — and it sends the three things the app needs and
cannot derive: centavos, the server's own thirteen-value status vocabulary (the app
chose to show four stages, so the mapping lives app-side in `remote_orders.dart`,
where an unrecognised status is *not* claimed to have moved), and `product_ids` — the
join key the wishlist, the recently-viewed rail and the order history itself were
previously unable to produce at all.

App-side, `LiveOrderRepository.orders()` asks for `/orders` with the token stated
explicitly, and the Orders screen's "not built yet" failure became a real, possibly
*empty* history — empty being honest, because the endpoint answers.

**`POST /orders` was the decision this pass inherited, and it is now made: extract.**
The web's placing logic is `App\Services\OrderPlacer`, taking plain lines, so the
session-driven web door and the cart-on-the-phone door reach the same money rules —
see the pass above for what that bought, including an `OrderPlacerTest` the web
controller could never have written.

One test changed *mechanism* rather than rule: `repository_wiring_test.dart`'s "a
live bundle fails loudly instead of quietly using mock data" now asserts through the
catalogue instead of the order history, because the catalogue is the public read that
reaches the network without the token store — and in a unit test the secure token
store has no binding to read. The rule is unchanged: never fall back to mock data,
and name the failure.

## This pass: the browsing screens read the catalogue (your "placeholders")

The first live build that booted showed **the eight mock products** while the server
held thirteen real ones. Half the wiring was missing, and precisely which half:
`CatalogRepository` existed and both implementations worked, and the states that
*resolve ids* used it (`CartState`'s cart restore, `WishlistState`,
`RecentlyViewedState`). The screens that *browse* did not — the home grid, the
category grid, the category counts, search, the seller screen and the review button
all read `mockProducts` from `data/mock_data.dart` directly. Mock mode hid it
perfectly, because the mock repository returns that same list, so no test could tell
the difference and no one looking at a mock build could either.

`CatalogState` now holds the catalogue once, fetched from the repository in
`main()` and guarded like every other load, and those six call sites read it:
`inCategory`, `bySeller`, `search` (name and category — the same two fields the
server matches, so the two modes agree about a query), `products`, `categories`.
`mockProducts` is reached only through the mock repository, which is what the
interface's own doc asked for.

**The test that was missing now exists, and its fixture is the point.** Every
fixture in `catalog_state_test.dart` (6) and `catalog_wiring_test.dart` (3) is a
catalogue that shares *nothing* with the mock file — products built through the
wire mapper, with ids and names `mockProducts` does not contain. A mock-backed
fixture cannot tell the two apart, which is exactly why a 242-test suite did not
catch this: the tests were asking the same question the screens were answering.
Proven by mutation: putting the category grid back on `mockProducts` fails
**exactly that one test** and leaves the other two green.

**Then the phone showed the other half of the same lesson.** The categories tab
overflowed — "A RenderFlex overflowed by 20 pixels on the bottom". The tiles' height
had been computed for a *one-line* label ("two text lines (~44px)") and the feature
panel drew its label at 28px inside a fixed 168px card: both fine for the mock's
three short names, both wrong for "Furniture and Office Equipment". Fixed by
measuring the tile height from the theme's own metrics for the two lines the label
may now occupy, bounding both labels at two lines with an ellipsis, and lowering the
aspect-ratio floor so a taller tile is permitted. No existing test could see it —
every fixture used the mock's three short names — so `catalog_wiring_test.dart` now
renders the server's real fourteen labels at 390 *and* at 320 wide, and removing the
bound reproduces it exactly ("overflowed by 20/40/60 pixels").

**And the icons that the label list implied.** `CategoriesScreen`'s private icon map
knew the mock's three names, so a live build's other eleven labels drew the neutral
fallback. It is now a top-level `categoryIcons` covering the server's fourteen *and*
the mock's three, with a test asserting both directions: every label the app can
receive has an icon, and no entry exists for a label it cannot (a typo that would
silently never render).

## This pass: the app that only showed a logo (the boot rule, applied once)

The first real live run on a phone booted to the **native splash and stayed
there** — the logo, no button, nothing responsive. The console said why:

    ApiNotImplementedException: The live API for this is not built yet
      LiveChatRepository.threads (chat_repository.dart:161)
      ChatState.load (chat_state.dart:62)
      Future.wait (main.dart:70)

`ChatState.load()` fetches its threads, the server has no chat endpoint, and the
exception left `main()`'s `Future.wait` before `runApp` — so no first frame, so the
splash never gave way. The order of operations is the trap: `main()` awaits nine
`load()`s, so *any* one of them rejecting is not an error state, it is an app that
never starts.

My own record of which loads were dangerous was wrong, and how it was wrong is
worth keeping: I grepped for a fetch, read the *first* line of each `load()`, saw
`await _repository.load()` in chat and review, and called them local-only — while
the fetch was the next line in both. The real list, read properly this time:
**cart (vouchers), chat (threads), review (all)** fetch and were fatal; orders and
courier were already fixed; wishlist and recently-viewed re-index the catalogue
*inside* a local `try`, so a failure there was already swallowed; auth and theme
read only `shared_preferences`.

So the fix landed in one place rather than nine, because nine is how this recurs:

- **`StartupState.guard(what, load)`** wraps every load in `main()`. A failure is
  recorded under a human name (`'your chats'`) and never rethrown, so the first
  frame always arrives; `ApiNotConfiguredException` *is* rethrown, because a live
  build with no base URL is a build mistake and its owner is a developer.
- **`StartupNotice`** renders them above the shell (in `AuthGate`, once, for both
  roles) with a Retry — and no Retry at all when every failure is one the server
  has not built, because a button that cannot work teaches a person to mash it. It
  appears only for a signed-in session: a cart that failed to load says nothing
  useful on the login screen.
- **`startup_notice_test.dart` (7 tests)** pins the mechanism, the sentence, the
  retry, and both states of the notice. The retry test caught a real bug on its
  first run — a retry that failed again appended a second entry for the same thing.

Still true, and named rather than fixed: `WishlistState` and
`RecentlyViewedState` re-index the catalogue inside a local `try`, so a failure
there is swallowed as if it were corrupt storage. They now have a notice that could
carry it; moving that re-index out of the catch is a small, separate change.

**And there is now a way in.** `DemoBuyerSeeder` seeds `buyer@zefanya.test` /
`password`, approved and verified on the record, because a live build was
otherwise unreachable: a registration made through the app sits at
`pending_approval` with no admin to approve it, so every screen behind the shell —
the catalogue above all — could only be checked over HTTP. It refuses to run in
production, and it is the one fixture in this project that is also a way in, which
is why that guard exists rather than a comment. Driven over real HTTP against the
dev MySQL: sign-in returns a token, `/me` answers
`{ok, role, name, email, registration_status: approved}`, and `/api/products`
returns 13 with `price_minor=76000`.

## This pass: the demo catalogue (the catalogue was real and empty)

`GET /api/products` was correct and returned nothing, because the dev database
held 0 sellers and 0 products — a state indistinguishable from broken. It now
holds 6 shops, 15 products (13 visible) and 40 photos across the curated
categories, all re-runnable, with three rows that exist only to be hidden (a
draft, and one from a seller still awaiting approval) so the visibility rule is
demonstrable rather than merely asserted.

Verified over real HTTP rather than only in tests: 13 products, first one
`price_minor=76000` for a ₱760.00 price (the centavos boundary holding), 14
categories, 2 vouchers, a single read with 3 photos.

Two findings matter more than the data:

1. **An uploaded product photo cannot be loaded by the app.** `Storage::url()`
   on the `local` disk answers with a *relative* `/storage/…`, `public/storage`
   was never linked, and the app renders photos with `Image.network`. The branch
   added here — an already-absolute URL passes through `ProductResource`
   untouched — is what makes the seeded photos work; the same problem for real
   uploads is recorded in `techContext.md` gap 6 and still open, because the fix
   is a decision (`Storage::disk('public')->url()`, or resolving a relative path
   on the client against the API base).
2. **Live mode still cannot reach its first frame**, so this pass is visible over
   HTTP and not yet on a device. `main()`'s `Future.wait` has no catch, and two of
   its nine loads fetch in live mode: `OrderState.load()` → `orders()` (no `try`,
   so it propagates) and `CourierState.load()` → `assignedJobs()` (deliberately
   outside its own `try`). Both were already flagged; this is the first time
   there is something to *see* behind them, which is why the flag now has an
   observably wrong consequence rather than a theoretical one.

## Where the web question landed (this session)

The task was the mobile app, and two commits still touched `web/` (the auth pass
in `21f5712`, the catalogue pass in `a09e97f`) — because a phone cannot
authenticate against an API that does not exist: there was no `routes/api.php`,
no token auth and no catalogue endpoint anywhere in `web/`. That is an argument
for *asking* first, not for proceeding, and `projectbrief.md` line 6 said the
directory had not been read. The user was asked; the answer was **keep the work
and split it onto its own branch**, which is done.

Both commits are **mixed** (web *and* mobile in one commit), so they were split
rather than moved: `web-api` (`c6e640a` auth API, `fceb34b` catalogue API),
built from `c7a805a`, the last commit where `web/` is pristine. Verified:
`git diff web-api a09e97f -- web/` is empty, so the branch's web tree is
byte-identical to what `mobile-dev` carries, and `mobile/` plus `memory-bank/`
there match the base exactly. `mobile-dev` is untouched, and the duplication is
deliberate — live mode on `mobile-dev` needs those endpoints.

**The layout assumption was wrong, and it matters.** `master` (and
`origin/HEAD` → `master`, Sep 23) has **no `web/` directory at all**: the Laravel
app sits at the repo root there, and `4e60367` "Move project files into web
folder" (622 files, +33,314/−91) has only ever existed on the `mobile-dev` line.
`master`'s root copies of the four files this session edited are byte-identical
to `web/`'s at `c7a805a`, so the move was content-preserving — but a
`web/`-based branch therefore cannot merge into a `master`-based branch without
that relocation riding along. Check the target branch's shape before proposing a
branch.

**The dev database was not damaged, and that is now evidence rather than
hope.** The `migrations` table holds 19 rows in batches 1/2/4/5 — the 11 applied
this session are batch 5, the Sanctum one batch 4. `LazilyRefreshDatabase`
against MySQL would have run `migrate:fresh` and collapsed all 19 into batch 1;
that never happened. `products`, `vouchers` and `buyers` are all 0, so there was
no domain data to lose in any case.

Corrections to earlier notes: "phpunit.xml pins tests to SQLite, so the suite
cannot touch the dev database" was recorded without the mechanism (the
`-d extension=pdo_sqlite` flag — see `techContext.md` gap 5, and note the suite
does not run at all without it); `memory-bank/mistakes.md` does not exist, though
a note cited it; and a stray `web/` edit is not the only footprint — the DB has
the 11 tables.

## This pass: the catalogue (migrations, then products / categories / vouchers)

**The blocker was the database, not the code.** The dev DB had 14 tables and
`products`, `product_images`, `cart_items`, `vouchers`, `logistics_providers`,
`orders`, `seller_orders`, `order_items`, `payments` and `inventory_transactions`
did not exist at all — 11 migrations were on disk and had never run. A
`migrate --pretend` dry run (exit 0, no writes) checked them before touching
anything; `migrate --force` then applied all 11 in ~1.3s. Found by probing the
schema, not by reading the code: the migrations' *existence* says nothing about
whether they ran.

What is now real on the server (`routes/api.php`, all public, one throttle bucket
each):

| Endpoint | Answers |
|---|---|
| `GET /api/products` | Every active product from an approved seller, newest first. Filters `q` (name + category), `category` (label), `seller` (shop name). Unpaginated on purpose. |
| `GET /api/products/{product}` | One product, 404 unless active **and** seller-approved |
| `GET /api/categories` | The 14 curated rows, in the migration's own order |
| `GET /api/vouchers` | Active, unexpired, under-usage-limit vouchers |

And on the app side, `LiveCatalogRepository` and `LiveCartRepository.vouchers()`
are implemented against them.

Decisions, with the reason each one exists:

| Decision | Why |
|---|---|
| The catalogue is **public** — no token, and the client is told so (`authenticated: false`) | A catalogue is not per-buyer, and the web's own `/buyer/products` pages sit outside their `auth:buyer` group for the same reason. Sending a bearer token to a route that cannot use it is a habit worth not forming |
| `price_minor`, always centavos | `products.price` is a `DECIMAL(10,2)` in **pesos** while every other money column is `*_minor`, and the app reads every server amount as centavos (`money.dart`). Converting on the side that owns the column, and naming the field for its scale, is what stops ₱1,250.75 rendering as ₱125,075.00 |
| A product is visible only when `status = active` **and** its seller is approved | The seller's own dashboard filters by status alone (they may look at their own drafts) and the web buyer page checks status alone; an unapproved seller's product must not be browsable, so both rules apply on both the list and the single read, stated once |
| An out-of-stock product is **listed**, with `in_stock: false` | Hiding it is an unexplained absence that looks like a bug, and the app has no stock UI to explain one. The flag carries the fact |
| `compare_at_price`, rating and maker note are **absent, not faked** | No column exists for any of the three. The web buyer page fakes all three (a struck-through price as `price / 0.75`, a hardcoded 4.9 and "284 reviews", placeholder feature copy) — exactly what the app's no-invented-content rule forbids. An absent field renders as no badge and no rating |
| The app overwrites the model's rating defaults with 0, and the product page **drops** the rating row and the review aggregate when nothing has been rated | `Product`'s constructor defaults (4.5 stars) are the *mock* catalogue's numbers. Left to default, a live product would claim an average over zero ratings — a number about nothing. Gating on `ratingCount > 0` changes nothing in mock mode (all 8 mock products carry one) |
| A **flat** voucher is not offered to the app at all | ₱200 off the goods has no expression in this app: `voucherDiscountFor` reads `discountRate <= 0` as a *shipping* rebate, so mapping one would silently become ₱200 off the delivery fee. Refusing is visible and arguable; applying the wrong one is neither. A percentage voucher maps exactly (10% → `discountRate: 0.10`), and the server's lack of a cap maps to `double.infinity` — "the server caps nothing" rather than a made-up ceiling |
| The category chips come from the server, and the app prepends its own `'All'` | The app's mock list is four names ('Fashion', 'Home & Living'…) and the server's 14 do not overlap — this is the answer to OPEN QUESTIONS §0.3. `'All'` is a UI affordance, not a row, so it is never sent as a filter |
| The product list is **unpaginated** | The app's repository contract is "the catalogue", with no page or `hasMore` in it. A paginated response would hand the app its first page and silently drop the rest — a missing page is not an error, it is a shorter catalogue |

The three guards worth naming, each proven to fail by breaking it on purpose:

- dropping the `* 100` in the resource → exactly the two centavo tests failed;
- dropping the cover-first sort → exactly the cover-order test failed;
- dropping the seller-approved filter → exactly the unapproved-seller test failed;
- dropping `type != 'percent'` in the app's voucher mapper → exactly the two
  flat-voucher tests failed;
- dropping the `rating: 0` override → the live-product test failed with
  `Expected: <0> / Actual: <4.5>` — the invented rating, caught by name.

## What the auth pass changed (the half that was already there)

1. **A pre-existing bug was found and fixed, and it was a blocker.**
   `registration_screen.dart` wrapped its `PageView` in an `AnimatedSwitcher`
   keyed on `_step`. Changing that key rebuilt the PageView while the outgoing
   copy was still fading, so for 200ms two PageViews existed and both built the
   same `Form(key: _formKeys[id])` widgets — one set of `GlobalKey<FormState>`s,
   two subtrees claiming them. The framework resolved that by reparenting the
   elements back into the outgoing subtree, which left the incoming page without
   its children: **the step band advanced while the form content stayed on step
   one, permanently, from the first Continue onwards.** Every step after the
   first has been unreachable in the app. Proved *pre-existing* by stashing this
   session's `lib/` changes and reproducing it on the committed code. Fixed by
   removing the wrapper — the PageView's own page animation is the motion the
   switcher was standing in for.
2. **The password step.** A new `_StepId.credentials` between Personal and
   Address, with the server's `min:8` + confirmation mirrored in its validators.
   Every `_StepId` switch is exhaustive, so adding a step was compile-checked at
   each site — and the step *numbers* are now derived from position
   (`_steps.indexOf(id) + 1`) instead of typed into each caption, which is
   exactly what inserting a step would otherwise have silently broken.
3. **`AuthState.submitForApproval()` is async and goes through the
   repository.** It used to set `pendingApproval` itself, with a comment
   explaining that `register()` needs a password the form did not collect and
   that passing `''` would be inventing a credential. Now the form collects one.
   The *gate* is persisted (`auth_registration_status`, a new additive key) and
   survives a cold start.
4. **The pending screen tells the two gates apart.** `awaitsEmailVerification`
   — which existed with no callers — now decides between "we emailed a code to
   <address>" and the reviewer copy. The gate copy is deliberately *not* gated on
   `kShowDevTools`: a repository answering `pending_verification` is one that
   reached a server which really sent a code. It names the action and does **not**
   offer an in-app code field, because this build has no OTP screen.
5. **`lib/data/dto/registration_payload.dart`** — the one file that knows what
   the server calls the form's answers. `register()` takes a typed
   `RegistrationProfile` (app vocabulary) instead of `Map<String, Object?>`,
   which would have made the wire keys a hidden contract spelled by a screen.
6. **Two latent overflows were found by the new tests** and fixed with the
   canonical remedies: `Flexible` around the pending screen's status text and
   the DOB picker's date, and `isExpanded: true` on every dropdown in the
   registration form (a `DropdownButton`'s row is as wide as its *hint*, so a
   long hint or a large text scale pushes the arrow off the field). The Address
   step had never been rendered before, because of (1).

7. **The OTP screen exists, so the email gate now has an action in it.** Until
   this, the pending screen told someone "we emailed you a code" and then offered
   nothing to do with it: the app describing a step and declining to provide it.
   `lib/screens/auth/otp_screen.dart` takes the six digits (digits-only and
   six-long, enforced by input formatters, so the validator answers only for
   *completeness*), `AuthState.confirmEmail()`/`resendConfirmationCode()` submit
   them through `AuthRepository.verifyOtp`/`resendOtp`, and a successful answer
   unwinds to `AuthGate` — this app's single route root — so the person lands on
   the *other* gate rather than on a screen describing it.
8. **The handle is carried, persisted, and never invented.** `AuthResult` gained
   an opaque `verificationId` (the server's own row id: an email is not an id),
   `AuthState` persists it (`auth_verification_id`, additive), and
   `canConfirmEmail` requires **both** the gate *and* the handle. A
   `pending_verification` with no handle renders no field at all, because every
   call the app could make would need something it does not have.
9. **The mock cannot honestly open the email gate, so a dev-tools affordance
   does.** Sending mail is not something the mock can do, and answering
   `pending_verification` would send someone to an inbox that will never receive
   anything — the exact lie the pending screen was rewritten to stop telling.
   `AuthState.debugStartEmailVerification()` mirrors `debugApprovePendingAccount`
   and opens the gate with `kMockVerificationId`, while `MockAuthRepository`
   accepts `kMockOtpCode` and refuses every other code: the screen is reachable
   by hand *and* its failure path is reachable.
10. **A refused code is a rejection, not a validation error.** The server's 422
    for a wrong code carries `{ok: false, message}` and no `errors` bag, so the
    mock throws `RequestRejectedException` and the screen shows the server's own
    sentence inline above the field (cleared on edit). The resend link counts
    down 30s, but only after *this* screen sends a code — someone who opened the
    screen because no mail came must be able to ask immediately.

## What the earlier session did (still the base of the data layer)

1. **`ChatState`, `ReviewState`, `CourierState` and `CartState` are no longer
   stores.** Each took over-passing its data to a repository: the mock owns the
   storage (keys `chat_threads`, `product_reviews`, `placed_orders` — unchanged,
   byte for byte), and the state class is a cache the synchronous getters read.
   `CartState` also gets the voucher catalogue from `CartRepository` instead of
   the `mockVouchers` constant, so the two screens that list vouchers can no
   longer disagree with the data layer.
2. **`AuthState` went through `AuthRepository`** for login/logout. This was the
   last place a *live* build would have faked reality: `login()` used to set
   `status = active` itself, so a live build would have claimed a session the
   server never issued. The status is now the repository's answer.
3. **`main()` hands every state its repository**, and
   `Repositories.loadLocal()` was dropped from the startup `Future.wait` —
   each state's `load()` already restores its own repository, so calling both
   read the same storage twice.
4. **Two new test files** (`auth_flow_test`, `repository_wiring_test`) — sign-in
   had *no* test at all, which is worth knowing before touching it again.

## Decisions taken this session (with reasons)

| Decision | Reason |
|---|---|
| `register()` takes a typed `RegistrationProfile`, not a map | A `Map<String, Object?>` makes the wire keys a contract with no owner; the DTO owns that vocabulary and only the live impl converts it |
| No `passwordConfirmation` parameter anywhere in the app | The form refuses to submit unless the two entries match, so a mismatch is *unrepresentable* at the seam rather than merely unlikely — and the live path satisfies the server's `confirmed` rule by sending the one verified value twice, which fabricates nothing |
| `address_mode: 'manual'`, but the names still sent in `*_name` | The picker is a bundled sample of *names*, so the code slots have nothing truthful to hold; the name slots mean what they say, and dropping them would leave a reviewer approving an account with no address |
| The gate is persisted as the server's **wire** string, read back through the forgiving parser | What sits on disk is what the server said, and an unrecognised value renders as "unrecognised" instead of throwing at startup — where the catch would drop the whole session |
| The email-gate copy is **not** gated on `kShowDevTools` | It is only reachable when a server answered `pending_verification`, which means a code really was sent; the dev-tools split exists for the opposite case, no backend at all |
| The `AnimatedSwitcher` wrapper was **removed**, not repaired | It could not work with one shared `PageController` plus `GlobalKey` Forms; the PageView already animates page changes, so the wrapper bought a cross-fade at the price of the form being unusable |
| Live `register()` still throws `notWired(§3.2)` | The *mapping* is written and tested; the *transport* is multipart code that has never run against a server |
| The password step is its own step, not two more fields on Personal | "What's wrong?" stays answerable only while each step's errors are about that step's subject — Personal already held seven fields |
| A refused sign-in at the email gate **enters that gate**; every other refusal stays on the form | `PENDING_VERIFICATION` is not a failure to report: the credentials were accepted and a code was already sent, so `PendingApprovalException` carries the server's `verification_id` and the login screen hands it to `AuthState.beginEmailVerification()`. Without it, someone told to confirm their email had nothing to confirm *with* — the OTP screen needs a handle, and re-registering would open a second account |
| The mapping stays in `ApiClient`, the *decision* stays on the screen | The type's own doc says a screen handling `PendingApprovalException` is making a product decision; the repository's contract is to throw for a shut gate (pinned by a test), so the transition cannot live there |
| `beginEmailVerification()` goes through the same `_applyResult` path as a real registration | A session entered this way must be indistinguishable from one that never left the form — including `canConfirmEmail`, which then comes out true for the same reason |

## Decisions taken earlier (still standing)

| Decision | Reason |
|---|---|
| The mock repository owns the storage, not the state class | Two stores is two sources of truth for the same data; "the reviews I wrote" and "the reviews the data layer has" could disagree |
| Storage keys/JSON kept **identical** to what the state classes wrote | An upgrade must not lose a single order, review or thread |
| Live repositories **throw** rather than containing request code | Untested HTTP that has never run is not a feature; `ApiNotImplementedException` names the missing endpoint, and a test now pins "no silent fallback" |
| `Repositories.loadLocal()` **not** called from `main()` | Each state's `load()` already restores its repository; doing both read the same storage twice |
| `AuthState.login()` became async and takes `password` | The credential has to reach the repository — otherwise a live sign-in would be attempted with an empty one |
| The **600ms login, 700ms checkout and 500ms registration delays were deleted** | Each stood in for a call that now actually happens |
| `CourierRepository` stays the narrowest (jobs only) | Milestones/payouts/ratings are still device-simulated; moving them would rewrite a behaviour pinned by tests for no gain |
| `CartState(vouchers:)` named for what it owns | A parameter called `cart` inside `CartState` would say nothing about what is being handed over |

## Flags raised and awaiting the user

- **`routes/web.php`'s throttles share one bucket per IP.** Found by running the
  real flow: `ThrottleRequests` keys a guest by `domain|ip` with no route in the
  key, so register (5/min), verify (10/min) and resend (3/5min) are three limits
  on the *same* counter — three registration attempts spend the resend budget, and
  behind a carrier-NAT mobile connection everyone on that address shares it. The
  new API routes each got a per-route prefix (`throttle:3,5,api-otp-resend`); the
  web routes were left alone because changing them changes the web app's
  behaviour, which is your call.
- **`docs/api-contract.md` still does not exist in this repo** (`docs/` is absent
  and untracked), and is now cited only by endpoints that do not exist. Wide, then
  narrower, now narrowest: the auth endpoints and the four catalogue endpoints are
  specified by `web/routes/api.php`, which *is* here — and the document's own auth
  section was wrong anyway (Inertia controllers, no `routes/api.php`, no token
  auth, different URL shapes). What still cites it, and so still has a citation as
  its only record: `maker_note`, `GET /promotions`, orders, reviews, chat, and the
  courier job routes.
- **Live mode still cannot boot**, and it is down to two fetch sites — both
  *state* `load()`s, not repository ones. A live repository's own `load()` is a
  no-op by design, but two states fetch immediately after it inside their own
  `load()`: `OrderState.load()` → `orders()` (no `try`, so it propagates) and
  `CourierState.load()` → `assignedJobs()` (**deliberately outside** its `try`,
  with a comment saying a live failure belongs to the screen that asked).
  `main()`'s `Future.wait` has no catch, so the first rejection escapes before
  `runApp`. The catalogue half of this is now fixed rather than dodged —
  `CartState.load()`'s `vouchers()` and the wishlist/history catalogue reads all
  resolve against real endpoints, which is what took the three sites down to two.
  Either boot with empty state and a visible notice (touching those two), or give
  orders and courier jobs endpoints to succeed against.
- **The courier half has no API.** `LiveAuthRepository` names
  `POST /login/logistics` and `POST /register/logistics` rather than dressing a
  buyer request in courier words. It waits on the form decision below — and one
  piece of this pass did help it: `logistics_providers` now **exists** (it was one
  of the 11 unrun migrations), so the courier half is no longer blocked on the
  schema as well as on the form.
- **The courier registration form does not match the server's request.** It
  collects a vehicle type and a plate number, which
  `StoreLogisticsRegistrationRequest` has no field for, and omits two fields that
  request *requires* (`business_name`, `dti_permit_upload`). A product decision
  (change the form, or the server) is needed before any code — see
  `progress.md`. The courier cannot actually register until it is settled.
- **The catalogue has a source but no rows.** Products come from the seller web
  app — `routes/seller.php` and `Seller\ProductController` are complete (create,
  edit, images with a primary flag, status toggle, ownership checks) — so nothing
  needs building to get real data. What the dev DB holds is 0 products and 0
  sellers, so a live build boots into an empty shop with a correct empty state
  until someone registers a seller, is approved, and lists something. A decision:
  go through the real seller flow, or seed demo rows.
- **`test/failures/` is covered** by the root `.gitignore` (verified with
  `git check-ignore`), so a failing golden leaves ignored PNGs rather than a dirty
  tree.
- **Fonts are still not bundled** (`techContext.md`) — the pubspec comment
  claims otherwise.
- **A saved wishlist or history can still hide a live failure.**
  `WishlistState.load()` and `RecentlyViewedState.load()` call `_indexCatalog()`
  (→ `catalog.products()`) *inside* their `try`, whose `catch` was written for
  corrupt local JSON. The catalogue read is real now, so this only bites when the
  network fails — which is exactly when it matters: a failed re-index then reads
  as "your wishlist is empty" rather than as "we could not reach the shop".

## Next steps

1. **Whether live mode boots with an empty cache.** It is down to two fetch sites
   now — `OrderState.load()` → `orders()`, `CourierState.load()` → `assignedJobs()`
   — so the choice is smaller than it was: boot with empty state and a visible
   notice, or give those two endpoints to succeed against. Either way a live build
   now has a real catalogue to show, which it did not before this pass.
2. **Product data for a live build.** The seller web flow exists and is complete
   (`routes/seller.php`), so nothing needs building — but the dev DB has 0
   products and 0 sellers, so go through that flow or seed demo rows.
3. **The courier registration form vs `StoreLogisticsRegistrationRequest`** — a
   product decision (see the flag). It blocks the courier's API half as well as
   the form.
4. **Whether to fix the web routes' throttles too** (flag above). One word per
   route, but it changes the web app's behaviour.
5. **Whether `AuthState` calls `refresh()` on boot** now that `GET /me` exists —
   the repository method is implemented and tested, and nothing calls it yet.
6. **Reviews and chat are the next slices** (`GET /reviews/mine`,
   `POST /conversations/{key}/messages` — both have tables now), then the courier's
   job list. Orders are done end to end: read, write, and seeded through the service.
7. Tier 2's other half: sort / filter on category grids, then search history.

## Working notes / preferences

- **The migrations on disk are not the schema you have.** The catalogue's blocker
  was that 11 migrations had never run: `products`, `orders`, `vouchers`,
  `logistics_providers`, `cart_items` and six more did not exist in the dev
  database — invisible from the application code, where the models, controllers
  and routes all read as if the tables were there. Found by probing (count rows,
  catch "doesn't exist"), then confirmed against the `migrations` ledger. Also
  found that way: the 14 categories are **seeded by their own migration**, which
  is why a test can read one without a factory. `migrate --pretend` prints the
  SQL and writes nothing, so the check costs nothing.
- **`artisan serve` is two processes.** `php artisan serve` spawns a child
  `php -S` that owns the port, so stopping the launcher leaves the server
  listening — caught by checking `netstat` after printing "server stopped", while
  the app was still answering requests. Kill the pid that owns the port, then
  verify the port is free *and* that no `php` process is left.
- **A probe should make the server prove the claim.** The probe product's two
  images were created deliberately out of display order (`sort_order: 0` unflagged,
  `sort_order: 1` flagged primary), and the HTTP response returned the primary
  first — the only way to know the cover-first rule survives `Storage::url()` and
  the resource's own sort. A test can assert the resource's output; only the wire
  shows what a client receives.
- **Establish a baseline before calling something a regression.** The full web
  suite has **60** pre-existing failures in this checkout — all
  `Vite manifest not found`, from a frontend that has never been built here — not
  the 4 an earlier note recorded, because that note had only ever run a subset.
  Proved by stashing this pass's changes and re-running: `Tests: 169,
  Failures: 60` before, `Tests: 205, Failures: 60` after. The count is the same;
  the 36 new tests all pass.

- **Run it, or it is not real.** Three things this pass were *only* visible by
  executing the flow, and all three were wrong before that: the OTP body's field
  name (settled by reading the frozen web client), the throttle bucket (a first
  resend answered `429` because four unrelated requests shared its counter — the
  web routes still have this), and the guard behaviour on a revoked token (which
  the in-process PHPUnit test *cannot* observe, because Laravel reuses the app
  inside one test method and Sanctum's request guard caches the user it resolved;
  the 401 only appeared when each request was its own process). Reading the code
  would have produced three confident, wrong sentences.
- **Prove a guard can fail.** Four families this session, each broken and then
  reverted: `kMinPasswordLength` drifted to 5 (broke the pinned constant *and*
  let a 7-character password through the form); `''` sent instead of the password
  (broke exactly the one test pinning it); the mock's code check inverted (broke
  exactly the three about accepting its own code and refusing others);
  `canConfirmEmail` weakened to ignore the missing handle (broke exactly the two
  that pin "no handle, no field"). The pattern worth keeping is *targeted*
  failure: a guard whose removal breaks ten unrelated tests is a guard nobody can
  reason about.
- **A pending-timer failure in a widget test is evidence, not noise.** The OTP
  screen's `ListView` children were unkeyed; adding the error block shifted their
  slots, Flutter re-inflated the widgets *after* it, and each fresh
  `StaggeredReveal` restarted its `Future.delayed` — surfacing as "A Timer is
  still pending" at teardown, and *also* being a real visual bug (the Confirm
  button re-faded when you retyped a digit after a refusal). Keying the children
  fixed both. Pumping a second and moving on would have left the re-fade in the
  app.
- **The live repositories throw synchronously** (they are not `async`), so
  `expectLater(live.verifyOtp(...), throwsA(...))` throws while *building* the
  argument rather than while awaiting it. Wrap the call:
  `expectLater(() async => live.verifyOtp(...), ...)`.
- **Before reporting a bug, establish whose it is.** The registration stepping
  bug was confirmed *pre-existing* by stashing this session's `lib/` changes and
  reproducing it on committed code — `git stash push -- lib/`, run the probe,
  `git stash pop`. "I broke this" and "this was already broken" are different
  claims and only one of them is worth reporting as a finding.
- **Look at the output**, and measure rather than eyeball (colours).
- Goldens are tracked and assertable: `--update-goldens`, **then open the PNGs**.
  Assert geometry and colour, never copy.
- Delete temporary harnesses (`tmp_*`) when done; keep the tree clean.
- **The tree is committed** (6 commits on `mobile-dev`, not pushed): the root
  `.gitignore`, `memory-bank/`, the mobile data layer, the password/registration
  pass, the OTP pass, and this one — the API surface. The last is the first commit
  to touch **both** sides: `web/` (Sanctum, `routes/api.php`, two API controllers,
  the buyer model's shared code policy, and `tests/Feature/Api`), `mobile/lib/`
  (`LiveAuthRepository`, `ApiClient.postMultipart`, the two model-side refactors),
  `mobile/test/live_auth_test.dart`, and the memory bank.

## Earlier decisions, still standing

- `Product.images` is an ordered photo list, cover first; `Product.photos` falls
  back to a derived placeholder and `Product.hasGallery` gates the pager chrome.
- The courier name guard lives *inside* `advanceMilestone`, so "a name before
  assignment" is structurally impossible rather than caller-dependent.
- Courier portrait shows a monogram, not a stock photo of a stranger.
- Return pays a partial trip fee (`kReturnFeeShare = 0.5`), stored per job.
- Gallery dots use `AppColors.accent` — measured 4.99 light / 5.12 dark.
- `ProductImage` takes the whole `Product` rather than a hand-passed tint.
- **The courier rating is still deliberately not shown to buyers**, even though
  `CourierState.averageRating` exists: showing it to the person at the door
  changes how the worker behaves there, and a new rider must never be shown
  "0.0". That is a product decision, not a code one — still open.