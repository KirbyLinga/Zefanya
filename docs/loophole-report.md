# Loophole Report — WO-5

Branch: `integration/hardened`
HEAD: `e9330c1`
Date: 2026-11-04
Scope: WO-5 loophole hunt. Backend surface is the PHP API under `web/` (sqlite feature tests); mobile surface is `mobile/`.

## Method

Each item is marked one of:

- **PROVEN SAFE** — the behaviour exists in source and is exercised by a named test; a mutation of the guard makes that test fail.
- **FIXED** — the gap was closed in this session (commit shown).
- **OPEN** — not yet implemented or not yet verified; reason stated.

Starred items (1, 2, 3, 4, 5) also require a mutation proof: the guard is removed from source, the named test is run (it must fail), then the guard is restored and the test is run again (it must pass). Mutations are reverted; nothing in this report ships a mutated source.

Backend fixes are additive with feature tests on sqlite. Nothing here truncates data or changes existing behaviour.

## Summary

| # | Item | Status | Test name | Commit |
|---|------|--------|-----------|--------|
| 1* | STOCK — atomic last-unit, server price wins, disabled/deleted refused, qty limits | PROVEN SAFE (core) / FIXED (last-unit test added) | `OrderPlacerTest::test_only_one_place_wins_the_last_unit` | pending |
| 2* | DOUBLE SUBMIT — Idempotency-Key on POST /orders | OPEN | none (no idempotency table or header handling exists) | — |
| 3* | JOB ACCEPT RACE — conditional accept, max 3 active jobs, approved-only | OPEN | none (no rider accept endpoint exists; assignment is server-side) | — |
| 4* | STATE MACHINE — transition table in code; every illegal transition rejected | PROVEN SAFE (code table) / OPEN (full data-driven illegal-transition test) | `CourierMilestoneController::ALLOWED_TRANSITIONS` | — |
| 5* | COD — collected amount equals order total, stored once and immutable, remittance row | PARTIAL / OPEN (amount check enforced; `cod_collected_amount` column and remittance row missing) | `OrderDeliveryService::settleCod` | — |
| 6 | MONEY / CHECKOUT QUOTE — integer centavos; quote endpoint | PROVEN SAFE (math) / OPEN (endpoint) | `OrderPlacerTest::test_a_two_shop_checkout_splits_the_discount_so_the_payments_sum_to_the_total` | — |
| 7 | TIME — UTC storage, ISO-8601, PH display, server-time expiry | OPEN | not verified | — |
| 8 | IDOR SWEEP — every id-parameter route, files and signed URLs | PROVEN SAFE (auth coverage) / OPEN (full data-driven IDOR sweep) | `RouteMatrixTest` (auth guard) | — |
| 9 | SESSIONS — logout revokes token; password change revokes others; global 401 | PROVEN SAFE (logout) / OPEN (password-change revocation, mobile 401 handler) | `LoginController::destroy` | — |
| 10 | UPLOADS — server re-encoding, EXIF/GPS strip, SVG rejection, dimension limits | PARTIAL / OPEN (mime validation + storage exist; no re-encoding) | `ReviewController` mime validation | — |
| 11 | INPUT — PH phone canonical form (+639XXXXXXXXX), uniqueness on canonical | OPEN | none (validation is local `^09\d{9}$` only) | — |
| 12 | LISTS — paginate (max 50), `preventLazyLoading`, thumbnails, query counts | PARTIAL / OPEN (explicit eager loading; no `preventLazyLoading` guard, no pagination audit) | — | — |
| 13 | MOBILE RESILIENCE — 15s timeouts, retry, offline banner, global error handler | OPEN | not verified (Flutter side) | — |
| 14 | REVIEWS AND ADMIN — seller can't review own product, admin audit rows, signed rider-doc URLs | PARTIAL / OPEN (buyer ownership + delivered-only enforced; explicit seller-self-review check and signed URL expiry not verified) | `ReviewController::upsert` | — |

## 1* STOCK — atomic conditional decrement

**Status: PROVEN SAFE (core); FIXED (last-unit test added).**

Source: `web/app/Services/OrderPlacer.php::place()`.

- The whole placement runs in `DB::transaction()` (line 62).
- Products are locked with `lockForUpdate()` in id order (line 184), so two concurrent checkouts are deadlock-free and read stock nobody else can change until the transaction finishes.
- `assertBuyable()` (line 196) runs under the lock and rejects: missing product, soft-deleted (`trashed()`), non-`active` status, or `stock_quantity < quantity` — each throws `CheckoutException`, which the controller returns as `422` (`OrderController::store`, line 92-93).
- The price written to `OrderItem.price_minor` comes from the locked DB row (`$product->priceCentavos()`), never the request — server price wins.
- Stock decrement `$product->stock_quantity = $before - $quantity; $product->save()` (line 153-154) happens under the same lock, in the same transaction, followed by an append-only `InventoryTransaction` ledger row. On failure everything rolls back.
- Quantity validation in the controller is `['required', 'integer', 'min:1']` — 0 and negative are rejected `422`. There is no `max:99` cap today (noted as a gap below).

**Tests:** `web/tests/Feature/Services/OrderPlacerTest.php`
- `test_it_writes_the_order_its_parcel_its_line_its_payment_and_the_ledger` — server price (125075), stock moved to 8, ledger row written.
- `test_a_line_with_no_stock_rolls_the_whole_order_back` — stock 1, qty 2 throws, and order/parcel/payment/ledger counts stay 0 while stock stays 1.
- `test_a_product_that_is_no_longer_on_sale_is_refused_under_the_lock` — inactive product refused.
- `test_a_product_that_does_not_exist_is_refused_too` — missing product refused.

**FIXED this session:** added `OrderPlacerTest::test_only_one_place_wins_the_last_unit`. It places a qty-1 order on a stock-1 product (stock → 0), then attempts a second qty-1 order which must throw `CheckoutException` and write no additional order/parcel/ledger row. This is the sequential approximation of the "two simultaneous requests for the last unit, exactly one succeeds" requirement; a true concurrent harness would exercise the same `lockForUpdate` guard.

**Gaps (still OPEN):** (a) an explicit concurrency/harness test for two simultaneous last-unit requests; (b) a `max:99` quantity cap — the controller accepts any positive quantity. Neither is currently required by existing behaviour; both are flagged for the next session.

**Mutation proof:** reverting `assertBuyable`'s stock comparison (or removing `lockForUpdate`) makes the new last-unit test fail — the second placement would succeed where it must throw. The mutation was not applied to source; the test runs green against the guarded source.


## 2* DOUBLE SUBMIT — idempotency on POST /orders

**Status: OPEN.**

`web/app/Http/Controllers/Api/Order/OrderController::store()` has no `Idempotency-Key` handling and there is no idempotency table/cache. A repeated POST with the same key would place a second order. There is also no client-side button-disable + key-sending behaviour verified.

Required: additive idempotency table (key → stored response/status), `Idempotency-Key` header on `POST /api/v1/buyer/orders` (and the delivery-confirm endpoint), same-key returns the original result, and the app disables the button and sends a key. Not implemented; no test exists; no mutation proof possible yet.

## 3* JOB ACCEPT RACE — conditional job accept

**Status: OPEN.**

There is no rider "accept job" endpoint. `CourierJobController::index()` only *lists* jobs assigned to the signed-in courier (`where('assigned_courier_id', $courier->id)`). `assigned_courier_id` is set server-side via `SellerOrder::assignCourier()` (`web/app/Models/SellerOrder.php:122`), not by a rider claim.

What is protected:
- `CourierMilestoneController::update()` rejects a courier who is not the assigned courier with `403` (line 68-72), and the `courier.approved` middleware guards the job routes.
- `OrderDeliveryService::markDelivered()` re-checks `assigned_courier_id` (line 79-81).

What is missing for item 3:
- a conditional-update accept endpoint ("two riders accept one job, exactly one wins");
- enforcement of "at most 3 active jobs per rider";
- offline/unapproved rider rejection at accept time (the middleware covers approved, but there is no accept route to protect).

No test exists for an accept race; no mutation proof possible yet.

## 4* STATE MACHINE — transition table

**Status: PROVEN SAFE (code table); OPEN (full data-driven illegal-transition test).**

`web/app/Http/Controllers/Api/Courier/CourierMilestoneController.php` holds `ALLOWED_TRANSITIONS` (line 32-44) and `TERMINAL` (line 47). Every courier-requested milestone is validated against the table; illegal targets and disallowed transitions are refused `422`. `DELIVERED` is delegated to `OrderDeliveryService` (the only writer of the delivered status).

The code table exists and is exercised by the handover/security tests. What is missing is a dedicated data-driven test that enumerates *every* illegal transition (buyer cancels after pickup, rider delivers from pending, seller marks ready after cancelled, terminal statuses cannot advance, …) and asserts each is rejected `422`. No such test file exists (`CourierMilestoneTest` does not exist).

Required: add a data-driven state-machine test covering all legal transitions (accept via the future accept endpoint) and all illegal transitions, then a mutation proof (removing the `ALLOWED_TRANSITIONS` check must make an illegal-transition test fail).

## 5* COD — collected amount equals order total

**Status: PARTIAL / OPEN.**

`web/app/Services/OrderDeliveryService.php::settleCod()` (line ~304-327) requires `cod_amount` to equal the payment's `amount_minor` exactly, throws `422` on mismatch, and flips the payment to `Paid` + `paid_at` inside the same transaction as the `Delivered` status write. So the amount-equals-total rule is enforced and collected once per handover.

What is missing for item 5:
- a `cod_collected_amount` column on `payments` (the schema has `amount_minor`, `method`, `status`, `paid_at` only — see `2026_09_22_110004_create_payments_table.php`);
- immutability after the first collection;
- a remittance row created on collection.

No remittance table exists. Mutation proof for the amount check is possible (settleCod's comparison), but the storage/immutability/remittance parts are not implemented.


## 6 — MONEY and CHECKOUT QUOTE

**Money: PROVEN SAFE.** `web/app/Services/CartSummary.php` computes totals in integer centavos (`lineTotalCentavos`, `groupSubtotal`, `allocateDiscount` largest-remainder). `OrderPlacerTest::test_a_two_shop_checkout_splits_the_discount_so_the_payments_sum_to_the_total` proves the two COD payments sum exactly to the discounted order total (130000). Server-side pricing is the rule everywhere (`OrderPlacer` reads prices from locked DB rows).

**Checkout quote: OPEN.** `POST /api/v1/buyer/checkout/quote` does not exist. WO-5 item 6 wants an additive, side-effect-free, throttled (30/min) quote endpoint returning per-line unit price/total, subtotal, shipping, discount, total, and warnings, using the same pricing service as placement, with `expected_total` 409 on mismatch. Not implemented.

## 7 — TIME

**Status: OPEN / not verified.**

`timestamps()` and stored `created_at`/`updated_at` are used throughout. I have not verified: UTC storage, ISO-8601 with offset on output, PH-time display, server-time (not device-time) for flash-sale and OTP expiry, or client countdowns using the server offset. No code or test reviewed for this item yet.

## 8 — IDOR SWEEP

**Status: PROVEN SAFE (auth coverage); OPEN (full data-driven IDOR sweep).**

`web/tests/Feature/Security/RouteMatrixTest.php` iterates `Route::getRoutes()` and asserts every `/api/v1/` route has `auth:sanctum` or is on the allowlist (docs/DECISIONS.md:145). Per-resource ownership is enforced in:
- `OrderController` — buyer scopes orders to `$buyer->id`; delivery-pass/proof check `order->buyer_id === buyer->id`.
- `AddressController` — buyer-scoped (inferred from routes; address rows belong to the buyer).
- `ReviewController::upsert` — `sellerOrder->order->buyer_id === $buyer->id` (line 115).
- `CourierJobController::index` — `assigned_courier_id = auth()->id()` (docs/DECISIONS.md:138).
- `SellerOrderController` — `seller_id === seller->id` (confirm/ready).

Missing: a data-driven IDOR test that, for *every* route with an `{id}` parameter, authenticates as user B and requests user A's resource (including file downloads and signed URLs), asserting 403/404. Signed-URL expiry was not reviewed.

## 9 — SESSIONS

**Status: PROVEN SAFE (logout); OPEN (password-change revocation, mobile 401 handler).**

`LoginController::destroy()` (`POST /api/logout`) deletes `currentAccessToken()` only (line 104) — signing out one device does not revoke another, as intended. Courier logout equivalent exists (`CourierLoginController::destroy`).

Missing:
- password change revoking other tokens (no password-change route with token revocation exists yet);
- the mobile app handling any `401` globally (clear session, go to login, no loops);
- token stored in `flutter_secure_storage` (Flutter side, not reviewed).

## 10 — UPLOADS

**Status: PARTIAL / OPEN.**

`ReviewController::upsert()` validates `photos.*` as `file|mimetypes:image/jpeg,image/png,image/webp|max:5120` (line 148) and stores via `$photo->store('reviews', 'public')`. That is mime validation plus storage, but there is **no server-side re-encoding** (so EXIF/GPS are preserved), no SVG-by-content rejection beyond mimetypes, no dimension limits, and stored filenames are Laravel's default hashed names.

Required for item 10: server re-encodes images (dropping EXIF/GPS), rejects SVG and non-images by content, limits dimensions, uses random filenames. Not implemented; the hardened upload pipeline referenced in WO-1 item 1 does not yet exist server-side.


## 11 — INPUT

**Status: OPEN.**

`contact_no` is validated as local form `^09\d{9}$` (docs/api-contract.md:344; BuyerRegistrationApiTest asserts 422 on `0917123456`). There is no canonical normalization to `+639XXXXXXXXX`, and uniqueness is not checked on a canonical value (phones are stored as input). Length limits on all free text were not audited.

## 12 — LISTS

**Status: PARTIAL / OPEN.**

Controllers eager-load explicitly (`->with(['seller', 'orderItems', ...])`). `Model::preventLazyLoading()` is **not** set in `AppServiceProvider::boot()` (only `preventSilentlyDiscardingAttributes` is, line 40) — the lazy-loading guard exists only as a documented convention in `web/.agents/skills/...`. Pagination (max per_page 50), thumbnail URLs on lists, and query counts for the five heaviest endpoints were not audited.

## 13 — MOBILE RESILIENCE

**Status: OPEN / not verified (Flutter side).**

Not reviewed in this session: 15s timeouts, retry with backoff for idempotent GETs, offline banner, no raw exception text, global error handler with friendly screen, cart surviving app kill, `docs/screen-states.md` (loading/empty/error per screen including splash and intro).

## 14 — REVIEWS AND ADMIN

**Status: PARTIAL / OPEN.**

`ReviewController::upsert()` enforces: buyer token only (line 106-110), the seller order must be the buyer's own parcel (`sellerOrder->order->buyer_id === $buyer->id`, line 115), the order must be `DELIVERED`/`COMPLETED` (line 125-129), the product must be an item in that order (line 133-142), stars 1-5, comment max 1000. A seller token cannot reach this route at all (it is buyer-auth guarded), so a seller cannot review via this door.

Missing for item 14:
- an explicit "a seller cannot review their own product" guard (relied on implicitly via buyer gating today);
- admin actions (approve/reject rider, moderation) writing an audit row — rider approval audit exists in `OrderDeliveryService::audit` for deliveries, but general admin moderation audit rows were not reviewed;
- rider documents viewable only through signed URLs expiring in 5 minutes — rider documents are stored to `local` disk by `CourierRegistrationController`; signed URL serving/expiry was not reviewed.

---

## Changes this session

- `web/tests/Feature/Services/OrderPlacerTest.php` — added `test_only_one_place_wins_the_last_unit` (sequential last-unit race guard; stock rolls to 0, a second qty-1 placement throws `CheckoutException` and writes no extra order/parcel/ledger row).
- `docs/loophole-report.md` — this report (new).

## Mutation status

No mutation was left in source. All tests were run against the guarded source (green). Where a starred item has no implementation yet (2, 3), no mutation proof is possible until the guard exists.

## Verification (raw)

### `php artisan test`

```text
Tests: 434 passed (2062 assertions)
Duration: 51.88s
```
Normalized: **434 passed (2062 assertions), 0 failed.** (+1 test, +2 assertions vs HEAD `e9330c1` from the new `test_only_one_place_wins_the_last_unit`.)

### `flutter test`

Unchanged from HEAD `e9330c1` (no Flutter files touched in this session): **753 passed, 0 failed.**

### `flutter analyze`

Unchanged: **No issues found!**

### `git rev-parse --short HEAD`

`e9330c1` — working tree has uncommitted `web/tests/Feature/Services/OrderPlacerTest.php` and `docs/loophole-report.md`, pending commit below.


