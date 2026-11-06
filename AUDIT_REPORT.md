# Zefanya — Read-Only Audit Report

**Date:** 2026-11-04 · **Branch:** `feature/sanctum-api-auth` · **Scope:** Buyer, Seller, Logistics, Rider (web + API), Admin
**Method:** read the implementation (routes → middleware → controllers → services → models → migrations → views/JS), read-only database introspection (`SHOW INDEX`, `migrate:status`), `route:list` analysis, and one automated test run (SQLite in-memory — cannot touch the MySQL database). **No application code, config, migration or database row was changed.** This file is the only thing created.

**Evidence labels:** **VERIFIED** = I read the code (file:line given) and, where stated, ran it. **SUSPECTED** = follows from the code but depends on deployment, data volume or a product decision I could not observe. Nothing below was exercised in a real browser or on production infrastructure. Performance items were **not benchmarked**.

**Prior audit:** `docs/audits/ZEFANYA_FULL_SYSTEM_AUDIT.md` (2026-10-30). I re-checked its confirmed bugs against current code: E-01 (notification XSS), E-02 (payouts unfulfillable), E-03 (pending migrations), E-04 (incident route auth) and E-05 (non-atomic registration decisions) are **fixed**. **E-06 still exists** (finding H-1).

---

## 1. Verdict

The money path is unusually well defended for a project this size: checkout re-validates stock, price, shipping and vouchers under row locks; payouts and COD confirmation use a single documented lock order and idempotency keys; ownership checks on route-bound models are consistent; output escaping is consistent; SQL is parameterised. **I found no Critical issue and no exploitable IDOR, SQL injection, CSRF gap or XSS in the paths I traced.**

What is weak is the *edges*: one access-control gap that survives from the previous audit, one client-controlled `Content-Type`, a policy that is documented but no longer implemented (free-shipping threshold), a few deployment defaults, an entirely synchronous architecture with no queue, and some dead code and duplication.

## 2. Prioritised findings

| # | Sev | Area | Finding | Evidence | Where |
|---|---|---|---|---|---|
| H-1 | **High** | Auth / Business | Suspending a Logistics provider does not stop its riders or hubs; riders keep logging in (web + API), collecting COD and advancing parcels | VERIFIED | `app/Models/Rider/Rider.php:121-132`, `app/Models/Logistics/LogisticsHub.php:174-177`, `app/Http/Middleware/Rider/EnsureRiderActive.php:36`, `app/Services/Admin/AccountStatusService.php` |
| M-1 | Medium | Security | Delivery-proof image is served with the **client-supplied** MIME type (stored XSS polyglot against Logistics staff) | VERIFIED (exploit not run) | `app/Services/Rider/RiderOperationsService.php:333`, `app/Http/Controllers/Rider/DeliveryProofController.php:34`, `app/Http/Controllers/Logistics/DeliveryProofController.php:32` |
| M-2 | Medium | Business / Docs | "Free-shipping threshold" is documented, configured and tested, but **not implemented**; policy is silently "no free shipping except vouchers" | VERIFIED | `config/buyer.php:12,50`, `docs/4-Flow.md:97`, `app/Services/Buyer/CheckoutCarrierService.php:462-470`, `resources/views/Buyer/Help/index.blade.php:37-41` |
| M-3 | Medium | UI/UX | Moderators see ID/permit links on the registration queue that always return **403** | VERIFIED | `routes/admin.php:69,109`, `resources/views/Admin/Registrations/index.blade.php:297-299` |
| M-4 | Medium | Security | API bearer tokens are not revoked on password change/reset, old tokens survive re-login, and expired tokens are never pruned | VERIFIED | `app/Http/Controllers/Api/{Buyer,Rider}/LoginController.php:56,58`, `routes/console.php`, `config/sanctum.php:53` |
| M-5 | Medium | Reliability | Everything is synchronous: no queue/jobs, mail and notifications run in the request; scheduler is the only thing that completes orders and has no failure alerting | VERIFIED (code) / SUSPECTED (impact) | `app/Jobs` absent, `routes/console.php:13-24`, `.env.example` (`QUEUE_CONNECTION=database`) vs `.env` (`sync`) |
| M-6 | Medium | Reliability / UX | COD delivery **requires** an OTP that is delivered only as an in-app database notification; a buyer without a live session cannot receive it | VERIFIED (code) / SUSPECTED (field impact) | `app/Notifications/Buyer/DeliveryOtpCode.php:15`, `app/Services/Logistics/DeliveryOtpService.php:72-90`, `app/Services/Rider/RiderOperationsService.php:289-293` |
| M-7 | Medium | Security | No `Content-Security-Policy`, `X-Frame-Options`, `Referrer-Policy` or HSTS set anywhere in the app | VERIFIED (app) / SUSPECTED (may be set by web server) | grep of `app/`, `config/`, `bootstrap/` |
| M-8 | Medium | Reliability | Address lookup depends on a live external PSGC API inside user requests (5 s timeout), cached 24 h, no stale-on-error fallback | VERIFIED | `app/Services/Shared/PhilippineLocationService.php:27-40,66,110,138` |
| L-1 | Low | Performance | Chat open/poll issues one `UPDATE` per unread message inside a `GET` | VERIFIED | `app/Http/Controllers/Buyer/MessageController.php:58-60`, `Seller/MessageController.php:60`, `app/Models/Shared/Message.php:42-47` |
| L-2 | Low | Performance | Catalog search/sort scale badly: `LOWER(col) LIKE '%x%'`, correlated sale/price subqueries per row, no index on `products.status` | VERIFIED (query shape + indexes), NOT benchmarked | `app/Services/Buyer/CatalogSearch.php:45-48`, `app/Models/Catalog/Product.php:225-300` |
| L-3 | Low | Quality | Dead/duplicate code: duplicated notification routes, route registered twice, always-403 delete route, unused config keys, JSON-only admin settlement routes with no screen | VERIFIED | see §4 |
| L-4 | Low | Quality | Financial constants hard-coded in PHP **and copied into SQL strings** (commission 5 %, origin share 40 %, 168 h) although a platform-settings service exists | VERIFIED | `app/Services/Shared/CommissionFinalizationService.php:13`, `app/Models/Logistics/CodRemittance.php:19,25`, `app/Services/Buyer/AutomaticBuyerReceiptService.php:18` |
| L-5 | Low | Quality | Runtime schema probes in production code ("live site is served from this working tree"); three un-memoised `Schema::hasTable` calls on the checkout path | VERIFIED | `app/Support/RuntimeSchema.php:10-14`, `app/Http/Controllers/Buyer/CheckoutController.php:117,212,406` |
| L-6 | Low | Quality | Very large services/controllers; four near-identical registration-modal scripts (~2,500 lines) | VERIFIED | `ManifestService.php` 1,019 lines; `ParcelHandoffService.php` 995; `resources/js/*/…-register-modal.js` |
| L-7 | Low | Security / Ops | Insecure defaults in `.env.example` (`APP_DEBUG=true`, `SESSION_SECURE_COOKIE=false`, `SESSION_ENCRYPT=false`, empty `TRUSTED_PROXIES`) | VERIFIED | `.env.example`, `bootstrap/app.php:35-43` |
| L-8 | Low | Quality | Admin registration search does not escape `%`/`_` in `LIKE` | VERIFIED | `app/Http/Controllers/Admin/RegistrationController.php:240-250` |
| L-9 | Low | Reliability | Test suite runs on SQLite while production is MySQL (window functions, `ROUND`, JSON); a separate MySQL suite exists but is not part of `php artisan test` | VERIFIED | `phpunit.xml:27-28`, `HomeController.php:273-274` |
| L-10 | Low | Tests | Related test suites currently fail because they still assume the removed threshold behaviour | VERIFIED (run) | see §6 |

---

## 3. Detailed findings

### H-1 — Suspended Logistics provider's riders stay operational (High, carried over from prior E-06)
**Evidence.** `Rider::isEligibleForAssignment()` (`Rider.php:121-132`) checks rider status, e-mail verification, and that the station hub is operational and owned by the same provider. It never checks the **provider's** status. `LogisticsHub::isOperational()` (`LogisticsHub.php:174-177`) is `approved && is_active && !archived` — also no provider check. `EnsureRiderActive` (`:36`) and `ApiRole` (via `ValidatesRoleAbilities::accountIsOperational`) both reduce to `Rider::isOperational()`. `AccountStatusService::transition()` for a `LogisticsProvider` does not touch hubs or riders. New checkouts *are* blocked (`CheckoutCarrierService::routeFeasibilityReason` checks `$provider->isApproved()`), so the gap is for **in-flight work and existing sessions**.
**Impact.** After Admin suspends a provider for fraud or non-remittance, its riders can still sign in, start deliveries, collect COD cash and create `cod_remittances` owed to a suspended company. Money keeps accruing against an account Admin intended to freeze.
**Root cause.** Eligibility is derived from the hub chain, and nothing links the provider's lifecycle to it.
**Fix.** Add `provider->isApproved()` to `Rider::isEligibleForAssignment()`, `scopeEligibleForAssignment()` and `LogisticsHub::isOperational()`. Decide explicitly what happens to parcels already in custody (recommended: block new scans/starts, allow Admin to reassign custody) and add a regression test that suspends a provider with an `OUT_FOR_DELIVERY` parcel.

### M-1 — Delivery proof served with a client-controlled `Content-Type`
**Evidence.** `RiderOperationsService.php:333` stores `$photo->getClientMimeType()` (the browser's claim). Both `DeliveryProofController`s (`Rider/…:34`, `Logistics/…:32`) send it back as `Content-Type`. Upload validation (`CompleteDeliveryRequest.php:33`, `mimes:jpg,jpeg,png,webp`) checks content, so the *bytes* must look like an image — but a polyglot (valid PNG header + HTML in a metadata chunk) uploaded with a client MIME of `text/html` would be served as HTML. `X-Content-Type-Options: nosniff` is set, which does **not** help when the declared type is already `text/html`. Compare `Admin\DocumentController.php:56-64`, which correctly uses the server-detected `$disk->mimeType()`.
**Impact.** A malicious (or compromised) rider can run script in a Logistics staff member's session when they open the proof (same origin, session cookie, CSRF token readable). Requires a hostile rider and a victim who opens the link, hence Medium.
**Fix.** Never persist or trust the client type: derive it with `$photo->getMimeType()` (content sniffing) and restrict to an allow-list (`image/jpeg|png|webp`) at serve time; add `Content-Disposition: inline; filename=…` and a CSP `default-src 'none'` on these responses. Add a test that uploads with a spoofed `text/html` type.

### M-2 — Free-shipping threshold: documented, configured, tested — and not implemented
**Evidence.** `config/buyer.php:12` (`announcements.free_shipping_threshold_minor`, default ₱1,500) and `:50` (`cross_province.free_shipping_threshold_minor`) are not read by any code under `app/`. `docs/4-Flow.md:97` says "the free-shipping threshold still applies first". `CheckoutCarrierService::feeMinor()` (`:462-470`) and `crossProvinceFeeMinor()` (`:448-456`) ignore subtotal. The Buyer Help page says "No. Shipping is calculated automatically." Tests still assert the old rule (see §6).
**Impact.** Three sources disagree (config/docs/tests vs code/Help). This is a *conflicting rule*, not a calculation bug: the current behaviour — gross carrier fee preserved, free shipping only via a shipping voucher's `shipping_discount_minor` — is consistent with the financial snapshots (`commission + net + shipping_allocation = collected + marketplace_funded`, `SellerPayoutRequestService::isReconciled`) and keeps carrier revenue non-zero.
**Fix.** Confirm the intended policy. If "no threshold": delete the two config keys, fix `4-Flow.md:97`, update the stale tests. If a threshold is wanted: implement it as a **platform-funded shipping subsidy** (record in `shipping_discount_minor`, funding = marketplace), never by zeroing `fee_minor`.

### M-3 — Moderators are shown document links they cannot open
**Evidence.** The registration queue (`routes/admin.php:115`, `admin.role:admin,moderator`) renders `admin.documents.stream` URLs unconditionally (`Registrations/index.blade.php:297-299`), but that route sits inside `admin.role:admin` (`routes/admin.php:69,109`) — the code comment says moderators are excluded on purpose.
**Impact.** Moderators are asked to approve/reject applications but get a 403 on the ID/permit viewer, or must escalate to an Admin. Either the permission or the UI is wrong.
**Fix.** Product decision: if moderators decide applications they need (audited) document access — move the route to `admin.role:admin,moderator` (`DocumentAccessLog` already audits every view); otherwise hide the links for moderators and say why.

### M-4 — API token lifecycle
**Evidence.** Tokens last 60 min (`config/sanctum.php:53`; `LoginController.php:56,58`). Nothing calls `tokens()->delete()` except account deletion (`BuyerAccountDeletionService.php:99`). `routes/console.php` schedules no `sanctum:prune-expired`. `ApiRole` re-checks account status per request, so suspension is covered; password change is not.
**Impact.** A stolen token stays valid for up to an hour after the user resets their password; `personal_access_tokens` grows without bound.
**Fix.** Delete the account's tokens in the password-change/reset flows and (optionally) on new login; add `Schedule::command('sanctum:prune-expired --hours=24')->daily()`.

### M-5 — No queue; scheduler is a silent single point of failure
**Evidence.** There is no `app/Jobs`; the only `ShouldQueue` class is a login listener. Mail/OTP/notification sends run inside requests. `.env.example` says `QUEUE_CONNECTION=database` (no worker exists to drain it); `.env` says `sync`. Three scheduled commands (`routes/console.php:13-24`) drive **buyer auto-receipt after 168 h, return auto-approval and pickup-window rolling**; they have no `onFailure`/heartbeat. Auto-receipt completion is what triggers commission finalisation and seller settlement for orders buyers never confirm.
**Impact.** (SUSPECTED, deployment-dependent) If cron is not running, unconfirmed orders never complete, so sellers are never paid for them and nobody is alerted. SMTP latency/outage directly slows or fails user-facing requests (OTP, registration decisions).
**Fix.** Run a queue worker for notifications/mail (`ShouldQueue` + `afterCommit`), add scheduler monitoring (`->onFailure()`, `->pingOnSuccess()` or a dashboard "last run" tile), and document the cron entry in the deployment notes.

### M-6 — Delivery OTP is in-app only
**Evidence.** `DeliveryOtpCode::via()` returns `['database']` (`:15`). `completeDelivery()` calls `DeliveryOtpService::verify()` for every delivery that is not already `DELIVERED/COMPLETED` (`RiderOperationsService.php:289-293`). The code is also shown on the buyer's order page (`activeCodeFor`).
**Impact.** (Field impact SUSPECTED.) A buyer who is logged out or offline cannot give the rider a code; the rider's only escape is "delivery failed", which consumes one of only two attempts (`SellerOrderStatusService::MAX_DELIVERY_ATTEMPTS`) and can send the parcel back to the seller.
**Fix.** Also send the code by e-mail/SMS, or allow a documented fallback (e.g. buyer reads the code from the order page on any device, plus a Logistics-approved override with audit).

### M-7 — No browser security headers
**Evidence.** Searching `app/`, `config/`, `bootstrap/` finds no CSP, X-Frame-Options, Referrer-Policy or HSTS. The app serves user-uploaded content (chat images, product images, proofs).
**Impact.** No defence-in-depth for M-1-style bugs; the site can be framed (clickjacking on COD/payout actions).
**Fix.** Add a small middleware (or web-server config): `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, HSTS in production, and a report-only CSP first. If these already exist at the proxy/Cloudflare layer, mark this closed.

### M-8 — PSGC address API in the request path
**Evidence.** Region/province/city/barangay lookups call a remote service with a 5 s timeout inside `Cache::remember` (24 h). On failure the closure throws (good — failures are not cached), but there is no stale fallback.
**Impact.** On a cold or expired cache during an upstream outage, registration and address forms fail for every user; each failing request can block up to 5 s.
**Fix.** Serve stale data on error (`Cache::flexible`/keep a long-lived copy), or ship the PSGC dataset as a seeded table.

### L-1 — Chat read-marking
`MessageController::show` loads messages, then calls `markAsRead()` on each seller message, each doing `update()` (N queries) in a polled `GET`. Replace with one `->where(...)->whereNull('read_at')->update(['read_at'=>now()])` before loading, and move it to an explicit POST. The Buyer and Seller controllers are near-copies (`image_path` store at `Buyer/MessageController.php:100` / `Seller/MessageController.php:102`); extract a shared service.

### L-2 — Catalog query shape
`CatalogSearch.php:45-48` uses `LOWER(name) LIKE '%…%' OR LOWER(description) LIKE '%…%'` (cannot use an index). `Product::scopeWithBuyerPriceFilters` adds a correlated `MIN(CASE …)` subquery that itself contains a correlated flash-sale `SUM` subquery, evaluated per product for sort/filter. `SHOW INDEX` shows `products` has only `slug`, `(seller_id, creation_key)`, `category_id`, `restricted_by` — **no `status` index** although every catalog query filters `status='active'`. Not benchmarked; fine at demo scale, will degrade with catalogue size. Fix: `FULLTEXT(name, description)` (or Scout), index `(status, seller_id)`, and precompute a `min_price_minor` rollup (the project already maintains stock/price rollups).

### L-3 — Dead and duplicate code
- `routes/rider.php:60-65` and `:69-74` register the *same six* notification routes twice (names collapse, so no error).
- The incident-report action is registered at `routes/rider.php:79` and again at `routes/api.php:66-67` for the same controller method (one is session+CSRF under `/api`, which a bearer-token mobile client cannot use).
- `DELETE admin/incidents/{incident}` (`routes/admin.php:170`) always `abort(403)`s (`IncidentController::destroy`).
- `admin/settlements*` routes (`routes/admin.php:89-93`) are "JSON-only and without a screen".
- `config/buyer.php` threshold keys (see M-2); legacy single-voucher columns kept "for old reports" (`CheckoutController.php:605`).
- A comment about private documents sits above the voucher routes instead of the document route (`routes/admin.php:95-108`).
Remove or document each; every dead route is attack surface.

### L-4 — Hard-coded financial constants
Commission 500 bps (`CommissionFinalizationService.php:13`), cross-provider origin share 40 % (`CodRemittance.php:19`) **and again as the literal `40` inside a SQL string** (`:25`), and the 168 h auto-receipt window are code constants. The "SQL twin" pattern (`KEPT_SHIPPING_SQL`, `ORIGIN_SHARE_SQL`, `Product::buyerStartingPriceQuery`) must be edited in two places in lock-step. Today they agree; they are a latent source of reconciliation drift. Source the numbers from one place (config/platform setting) and generate the SQL from the same constant.

### L-5 — Runtime schema probes
`RuntimeSchema` memoises per request, but `CheckoutController` uses raw `Schema::hasTable('checkout_tokens')` three times (`:117,:212,:406`) — each is an `information_schema` query on the hottest write path. Features silently turn off when a table is missing (`PickupPolicy.php:28-31`), which hides un-run migrations instead of failing loudly. Replace with a deploy-time `migrate --force` check and delete the probes.

### L-6 — Size and duplication
Largest PHP files: `ManifestService` 1,019, `ParcelHandoffService` 995, `CourierAssignmentService` 896, `ParcelIncidentService` 878, `CheckoutController` 850 lines. `placeOrder` alone is ~290 lines mixing locking, pricing, vouchers, persistence and route planning — correct but hard to change safely. `buyer-/seller-/logistics-/rider-register-modal.js` contain the same stepper code (identical blocks at ~lines 85-105). Extract a shared stepper and move `placeOrder` into an `OrderPlacementService` with the existing locking order unchanged.

### L-7 — Deployment defaults
`.env.example` defaults to `APP_DEBUG=true`, `SESSION_SECURE_COOKIE=false`, `SESSION_ENCRYPT=false`, `TRUSTED_PROXIES=` (the bootstrap file has a TODO). `RuntimeSchema`'s docblock says "the live site is served from this working tree". Copying the example to production leaks stack traces and sends session cookies over HTTP. Ship a production example, and keep `laravel/boost` dev-only (it is — `require-dev`).

### L-8 — LIKE wildcards
`RegistrationController::registrySelect` builds `'%'.$search.'%'` without escaping (`:240`; the table row cites `:240-250`); a search of `%` or `_` matches everything. Values are bound, so this is correctness only, not injection. `CatalogSearch` already has `escapeLike()` — reuse it.

### L-9 — Test/production database parity
`phpunit.xml` pins SQLite `:memory:`; production uses MySQL 8 and relies on `ROW_NUMBER()/DENSE_RANK()` (`HomeController.php:273-274`), `ROUND`, and `CASE` money maths. A MySQL concurrency suite exists (per the prior audit) but is a separate command; make it part of CI.

---

## 4. Verified strengths (not defects — to avoid re-auditing)
- **Checkout** (`CheckoutController::placeOrder`): one-time token claimed inside the transaction; products → sellers → variants locked in id order; price, shipping and voucher fingerprints re-checked under lock; stock decremented with ledger rows; all-or-nothing.
- **Money invariants:** `isReconciled()` cross-checks order, payment, remittance and settlement before any payout; payouts use one pending request per seller, idempotency keys, and the same Seller→Settlement lock order as direct payouts; debts are netted at pay time.
- **Authorization:** a route-by-route heuristic over every route-bound handler flagged only admin-global routes and two Seller actions that authorise in a private helper (`advanceFulfillment`) — no IDOR found. Rider/Logistics ownership is asserted before idempotent success responses.
- **Injection/XSS/CSRF:** all raw SQL uses bindings or allow-lists; `{!! !!}` is used only for server-generated QR SVG and an encoded JSON config; JS `innerHTML` sinks escape or use `textContent`; no CSRF exclusions exist.
- **Auth hardening:** named rate limiters on login/OTP/registration/password flows, admin TOTP 2FA with throttle, Google sign-in requires a Google-verified e-mail, `private-storage` serves signed URLs only, admin document views are audit-logged, chat images and proofs live on the private disk.
- **Polling:** all notification/message pollers pause on `document.hidden` (30 s interval).

## 5. Unnecessary / removable features
Always-403 incident delete route · duplicate rider notification routes · JSON-only admin settlement endpoints (keep only if a screen is planned) · unused free-shipping config keys · `Schema::hasTable` degrade-gracefully probes once migrations are enforced · stale `Final-JavaScript-MySQL-Verification.md`/phase reports under `docs/` (archive) · duplicated `body` field in chat JSON (`MessageController.php:72,109`, the client renders the `html` field).

## 6. Test evidence
- **Targeted (this session, earlier work):** `CheckoutCarrierSelectionTest` + `CheckoutShippingTest` — 51 passed.
- **Related run** (`Checkout|Voucher|Logistics|Shipping`, 1,285 tests): **7 failed**, all verified outside the two classes above:
  - `CheckoutCarrierPersistenceTest::test_multi_seller_checkout_persists_independent_carriers_and_authoritative_fees` (line 51, 169800 vs 164900) and `ShippingRateManagementTest::test_the_free_shipping_rule_still_applies_first` (line 92, 6000 vs 0) — same stale threshold assumption as M-2.
  - `ShippingRateManagementTest::test_the_page_shows_each_zone_with_the_marketplace_rate_it_falls_back_to` (page assertion), `CrossProviderOperationsTest::test_tracking_shows_chronological_handoffs_without_private_actor_or_notes`, `PhysicalReturnTest::test_buyer_and_seller_tracking_show_return_progress_without_rider_identity`, and two `PickupSessionTest` tests — seller/logistics view assertions; **not investigated** (root cause unknown).
- **Full suite:** `php artisan test` (SQLite in-memory): **3,578 tests, 3,566 passed, 11 failed, 1 skipped**, 23,710 assertions, 397 s. The 11 failures are the 7 above plus 4 more not investigated: `Rule02OrderStatusFlowTest::test_buy_trk_002_tracking_is_once_per_transition_and_to_receive_starts_at_pickup` (line 131) and three in `SellerReturnDecisionTest` (lines 213, 454, 554). The prior audit recorded 2,231 tests with 0 failures, so these are regressions since 2026-10-30. Root cause is unknown for all but the two threshold-related ones.

## 7. Implementation roadmap

| Phase | When | Items | Effort |
|---|---|---|---|
| **0 — Decide** | Day 1 | Free-shipping policy (M-2); moderator document access (M-3); OTP fallback (M-6) | Product decisions |
| **1 — Close gaps** | Week 1 | H-1 provider-suspension cascade + tests; M-1 server-side MIME + allow-list; M-4 token revocation + prune; fix 2 stale threshold tests and the doc line | ~2–3 days |
| **2 — Harden & operate** | Week 2 | M-7 headers (report-only CSP first); production `.env` example (L-7); M-5 queue worker + scheduler monitoring; M-8 PSGC stale fallback | ~3 days |
| **3 — Performance** | Week 3 | L-2 indexes/FULLTEXT/`min_price_minor` rollup; L-1 bulk read-marking; measure with real data before and after | ~2 days |
| **4 — Clean up** | Week 4+ | L-3 dead routes/config; L-4 single source for financial constants; L-5 remove schema probes; L-6 extract `OrderPlacementService` and shared registration stepper; CI runs the MySQL suite (L-9) | ongoing |

## 8. Not covered / limits
I did not read every one of the ~400 PHP files line by line; coverage was targeted at the money, status, auth and upload paths plus a whole-route-table scan. I did not exercise the app in a browser, load-test it, review third-party packages for CVEs (`composer audit` was not run), or inspect the production server, proxy, cron or mail configuration. Findings marked SUSPECTED need confirmation in those environments.
