# Zefanya Audit 1.17 — Final end-to-end security, financial, and system integrity

Date: 2026-11-13. Repository: `WebZefanya`, branch `webzefanya-live`.
Continuation B: Freebuff. Continuation C: Codex. No commit, push, deployment, production migration or production data access.

**Status: CONTINUATION C COMPLETE. All discovered MySQL concurrency classes passed; test harness/fixture repairs verified. Production release remains conditional; current evidence and remaining work are in §14.**

---

## 1. Executive summary

Continuation B finished the two open MySQL checkout races and, while proving them, found a real
production defect — not a test artefact:

- **Fixed:** `CheckoutService::placeOrder()` ran its `Schema::hasTable('checkout_tokens')` readiness
  probe *inside* the order transaction. That probe is an `information_schema` read, and MySQL fixes a
  REPEATABLE READ snapshot at the transaction's first consistent read. The purchase locks are taken
  after it, so any promotion/offer change committed while a checkout waited on a row lock was
  **invisible** to the "authoritative" locked re-price. A bundle deleted during that window was
  therefore honoured, and the order committed at the deleted discount. The probe now runs before
  `DB::transaction()`; the first in-transaction consistent read is the locked pricing read, so the
  re-price sees the committed change and rejects with "price changed" (§6).
- Checkout review state writers re-audited: only `CheckoutController::index()` writes the selection,
  the reviewed token and the reviewed price fingerprint. No externally reachable path can create a
  partial review session, so there is **no checkout-review bypass** (§5.5).
- MySQL fixture repaired without reconstructing fingerprints: the two bundle cases now render the real
  `buyer.checkout.index` page and replay the session it writes (§7).
- **MySQL `CheckoutConcurrencyTest`: 28 tests, 497 assertions, OK (10:24.251)** — the two previous
  failures fixed, the other 26 unchanged.

Additional integration coverage executed in this continuation (all green): parcel loss/recovery and
complete financial scenarios (27 tests / 1,197 assertions, previously blocked), Flutter-facing API
(138 / 966), historical/migration compatibility (32 / 332), and six more isolated-MySQL concurrency
classes covering station receipt vs cancellation/loss/handoff, rider assignment, delivery vs COD,
refund vs seller payout, origin/destination financial splits and platform refunds (54 tests / 597
assertions). Checkout regressions (67 / 571) and JavaScript (11) also pass.

At the end of B, demo fixtures, Flutter runtime, full SQLite, historical data and remaining
MySQL concurrency were unverified. Continuation C closes the discovered concurrency classes
and updates the other limitations in §14; the B assessment below is historical.

---

## 2. Environment and tool availability

| Item | Result |
|---|---|
| PHP / Node / Composer | 8.5.9 (ZTS) / v24.19.0 / 2.10.2 |
| Graphify | `0.9.108` at `C:\Users\Rei\.local\bin\graphify.exe`; graph current |
| SQLite config | `phpunit.xml`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` |
| MySQL config | `phpunit.mysql.xml` + `tests/MySql/bootstrap.php` |
| JS runner | `node --test` |

Graphify queries this continuation:

- `graphify explain "SellerPromotionService"` — degree 7; edges to `PromotionController::store()`
  (`app/Http/Controllers/Seller/PromotionController.php:34`) and `::destroy()` (`:41`), plus
  `tests/MySql/worker.php:34`. Source-confirmed.
- `graphify explain "CheckoutController"` — ambiguous (web + API controller). Resolved by reading both
  sources directly; the API controller imports `CheckoutService` and re-checks all fingerprints.
- Graphify does not model Laravel route binding or MySQL isolation semantics; every edge used below
  was verified in source and by execution.

---

## 3. Preservation of pre-existing work

The working tree remains as inherited: 227 modified and 58 untracked files. Nothing was reset,
stashed, cleaned or reverted. This continuation changed only the files listed in §13. No migration was
run, no production database was touched, and `tests/MySql/Preflight.php` continued to prove isolation
before every statement (server UUID, database, account, exact grants, and `USE Zefanya` → error 1044).

---

## 4. Dependency path (verified in source)

```
routes/buyer.php:85-86  buyer.checkout.index / buyer.checkout.store
  → app/Http/Controllers/Buyer/CheckoutController.php
      index()  :57  loads buyer cart, :99 parks selection,
               :122-125 issues CheckoutToken + writes TOKEN_KEY and PRICE_FINGERPRINT_KEY
      store()  :188 parked-selection gate, :212 live cart re-resolution,
               :241 reviewed-token equality, :247 address fingerprint, :251 price fingerprint,
               :295 shipping fingerprint, :312 voucher fingerprint, :320 live re-price
  → app/Services/Buyer/CheckoutService.php
      placeOrder() :126 DB::transaction → :131 buyer lock → :139 token claim
               → :149 product/seller/cart/variant locks → :239 locked CartSummary
               → :270 locked price fingerprint comparison
routes/api.php:104-105 → Api\Buyer\CheckoutController (all four fingerprints required)

Seller bundle change:
routes/seller… → app/Http/Controllers/Seller/PromotionController.php:34/:41
  → app/Services/Seller/SellerPromotionService.php:14 create (locks products, then seller, inserts)
                                            :62 delete (locks seller, deletes)
```

---

## 5. Verified protections

### 5.1 Checkout review tokens and stale-quote rejection — VERIFIED

- `store()` rejects a POST with no parked selection (`CheckoutController.php:188–193`).
- `index()` writes the selection (`:99`) and the reviewed token + price fingerprint (`:122–125`) in
  the same request, so the equality guard at `:241` is armed for every real flow.
- Four independent checks before the write: reviewed token (`:241`), address (`:247`), prices
  (`:251`), shipping (`:295`), voucher (`:312`), plus the live re-price at `:320` and the
  transaction-locked re-price at `CheckoutService.php:270`.
- `CheckoutToken::claim()` is an atomic conditional `UPDATE ... WHERE used=false AND expires_at>now()`
  scoped to `buyer_id`, rejecting malformed tokens by regex (`app/Models/Buyer/CheckoutToken.php:60–74`).
- The API path is stricter: `price_fingerprint`, `address_fingerprint`, `shipping_fingerprint`,
  `voucher_fingerprint` and `checkout_token` are all required (`Api/Buyer/CheckoutController.php:205–215`)
  and re-hashed after voucher application (`:174–181`).
- Server-computed money only: submitted `shipping_fee`/`shipping_fees`/`shipping_total` are rejected
  (web `:276`, API `:130`).

### 5.2 Station Hub inventory — VERIFIED (definition and scoping)

Unchanged from the first half of 1.17: `Total = Inside + Inbound`, every query scoped by
`providerId`/`stationIds`, inventory derived per parcel from custody/manifest/handoff state, and
terminal statuses skipped (`StationInventoryService.php:30–125`). `StationInventoryTest` remains green
(prior run) and `PhysicalReturnConcurrencyTest` re-ran green (§8).

### 5.3 Refund cap and partial-refund reason — VERIFIED

`ParcelReturnService::recordRefund()` locks the payment, caps at `refundableCollectedMinor()`, refuses
zero and any excess, and requires a 10–1000 character reason for partial refunds
(`ParcelReturnService.php:244–249`). `Payment::refundableCollectedMinor()` returns 0 unless `Paid`
(`Payment.php:72–80`). Re-exercised by `PlatformRefundConcurrencyTest` (8 tests, §8).

### 5.4 MySQL test isolation — VERIFIED (strong, re-ran)

`tests/MySql/Preflight.php` refuses cached config, reads credentials only from `.codex/t05.env`,
checks `@@server_uuid`, port, `DATABASE()`, `CURRENT_USER()`, roles, exact grant list, escaped database
scope, InnoDB-only tables, and confirms the runner cannot `USE Zefanya` (error 1044). Re-verified on
every run in this continuation.

### 5.5 Checkout session-state writers — VERIFIED, no reachable bypass

Every production writer of checkout-review state:

| Session key | Written by | Read by |
|---|---|---|
| `checkout_item_ids` (`SESSION_KEY`) | `CheckoutController::index()` :99 only | `store()` :188 |
| `checkout_form_token` (`TOKEN_KEY`) | `index()` :122–125 only | `store()` :240 |
| `checkout_reviewed_price` | `index()` :122–125 only | `store()` :241, :250 |
| `checkout_carrier_selection` | `store()` :292 (after carrier validation) | `CheckoutCarrierService` |
| `cart_vouchers` | `VoucherSessionService::apply()` / `resolve()` (`VoucherSessionService.php:367`) | `store()` voucher re-resolution |

`rg` over `app/` shows no other controller writes any of them (only authentication, 2FA and password
keys elsewhere). The API controller is stateless for these values: it takes `item_ids` from the request
and requires every fingerprint.

Reachability of a partial session:

- A request can only obtain `SESSION_KEY` by rendering `index()`, which writes the token and
  fingerprint in the same call; an external actor cannot write arbitrary session keys.
- `store()` with `SESSION_KEY` but no token/fingerprint (only possible if `index()` aborted mid-request,
  e.g. a server error between `:99` and `:122`) still has to pass
  `CheckoutToken::claim()` with a valid, unused, unexpired token (`CheckoutService.php:139`); tokens are
  issued only by `index()` and by the API quote endpoint, and neither can be paired with a partially
  written web session by the client.
- A client-supplied `price_fingerprint` can never override the session value (`??` at `:250`), and an
  absent value simply skips the *comparison*, not the authoritative pricing.

**Conclusion:** no externally reachable checkout-review bypass. The conditional guards at `:241` and
`:250–251` remain a **defence-in-depth concern** (they fail open when session state is partial), not a
vulnerability. Making the reviewed-token comparison unconditional would require every older fixture
to render the page first; recorded as hardening work, not applied.

---

## 6. CONFIRMED DEFECT (fixed): promotion changes committed mid-checkout were invisible to the locked re-price

**Severity:** High for seller-funded discounts (money), narrow concurrency window.
**File:** `app/Services/Buyer/CheckoutService.php` (`placeOrder`).

### Evidence

1. The two bundle races failed with a *successful* checkout at the pre-change price. The order showed
   `discount_minor = 0` and full-price lines, with stock/ledger/token otherwise correct.
2. Inside the checkout transaction, `DB::table('seller_promotions')->count()` returned **0** while a
   fresh connection returned **1**, after the concurrent bundle insert had committed. The transaction's
   snapshot predated the commit.
3. Isolation experiment against the same MySQL instance (`START TRANSACTION`, concurrent commit while a
   row lock was held):
   - first statement a locking read → the later consistent read **sees** the commit;
   - first statement an `information_schema` probe (as `Schema::hasTable` issues) → the later
     consistent read **does not** see the commit.
4. `placeOrder()` ran `Schema::hasTable('checkout_tokens')` as its first in-transaction statement, so
   the snapshot was pinned before the product/seller locks were taken.

### Consequence

Bundle/offer pricing is read with a plain (consistent) `SELECT` in `OfferPricing::build()`. A bundle
deleted while the checkout waited on the seller lock therefore still received its discount, and the
order committed **after** the deletion committed. Symmetrically, a bundle created during the window was
not applied. Product price changes were unaffected because product rows themselves are locked and read
as current rows.

### Fix (smallest safe change, no guard weakened)

The readiness probe moved ahead of `DB::transaction()`:

```php
if (! Schema::hasTable('checkout_tokens')) {
    throw new CheckoutException('Checkout is temporarily unavailable. Please try again later.');
}

return DB::transaction(function () ... {
    $buyer = Buyer::query()->whereKey($buyer->id)->lockForUpdate()->firstOrFail();
    if (! CheckoutToken::claim($buyer->id, $formToken)) { ... }
```

The fail-closed behaviour and message are unchanged; only the snapshot timing changes. The first
consistent read is now the locked pricing read, so a discount change committed during a lock wait is
observed and the order is rejected with "A product price changed while placing your order…".

### Verification

- Targeted: `test_bundle_creation_racing_checkout_rejects_the_stale_quote` and
  `test_bundle_removal_racing_checkout_rejects_the_stale_discount` → **OK, 2 tests, 44 assertions**,
  each rejection carrying `price changed`, with 0 orders, unchanged stock, 0 consumed tokens and
  exactly one/zero promotions as appropriate.
- Full class: **28 tests, 497 assertions, OK (10:24.251)**.
- No checkout regression on SQLite (67 tests / 571 assertions, §8) and no change to double-submit,
  commission, shipping or voucher behaviour.

---

## 7. MySQL fixture correction

The previous harness posted `SESSION_KEY` + token only, so the two session guards were skipped by
construction. The rejected first attempt reconstructed fingerprints by hand; it was not repeated.

**What changed (`tests/MySql/CheckoutConcurrencyTest.php`):**

- New `reviewed(array $task)` helper authenticates the test Buyer, renders
  `buyer.checkout.index` with the task's cart lines, asserts HTTP 200, then captures the values the
  **application** wrote: `CheckoutController::TOKEN_KEY`, the private `checkout_reviewed_price`
  session value, and the parked selection (asserted equal, canonically, to the task's items).
- It replaces the task token with the reviewed token and deletes the token the fixture issued before
  the render, so the fixture matches a real single review.
- It is applied only to the two bundle cases, after both cart lines exist (and, for removal, after the
  bundle exists), so the reviewed quote is the pre-change quote. The other 26 cases keep byte-identical
  tasks.

**Worker (`tests/MySql/worker.php`):** the checkout action seeds the reviewed token and reviewed price
fingerprint into `withSession()` when the task carries them, alongside the parked selection and
vouchers. The reviewed token is the posted `checkout_token`, so the one-time claim consumes the same
row the page issued.

**Related fixture repair (`tests/Feature/Buyer/CheckoutShippingTest.php`):** four posts issued a
brand-new token *after* rendering the page, so the reviewed-token guard correctly rejected them
(2 errors, 1 failure: no `SellerOrder` was created and the expected `errors` bag was absent). They now
submit the token the page issued (`session(CheckoutController::TOKEN_KEY)` with an issuance fallback),
the pattern `CheckoutCarrierSelectionTest` already used. This is a fixture correction only — no guard
was changed. Re-run with the cross-province and carrier-selection classes: **62 tests, 401 assertions,
OK**.

**Synchronisation (unchanged, now meaningful):** A holds a real InnoDB transaction at
`promotion_insert` / `promotion_delete`; B is started after A signals `held`, is observed blocked on
`SELECT … FROM products … FOR UPDATE` / `SELECT … FROM sellers … FOR UPDATE` via
`SHOW FULL PROCESSLIST`, A is released, A commits, then B proceeds. `Race` still asserts distinct
connections and overlapping execution intervals, and the evidence file records both transactions and
the observed in-flight statement.

---

## 8. Test results (executed in this continuation)

All runs used `--do-not-cache-result`. MySQL runs are isolated in `zefanya_concurrency_test`.

### 8.1 MySQL (isolated InnoDB)

| Suite | Tests | Passed | Failed | Errors | Assertions | Time |
|---|---:|---:|---:|---:|---:|---|
| `CheckoutConcurrencyTest` (targeted bundle cases) | 2 | 2 | 0 | 0 | 44 | 38.4 s |
| `CheckoutConcurrencyTest` (complete class) | 28 | 28 | 0 | 0 | 497 | 10:24.3 |
| `PhysicalReturnConcurrencyTest` (station receipt vs cancellation/loss/handoff) | 7 | 7 | 0 | 0 | 72 | 1:18.1 |
| `AutomaticDispatchConcurrencyTest` (rider assignment/capacity) | 7 | 7 | 0 | 0 | 100 | 1:22.9 |
| `FinancialLedgerIntegrityConcurrencyTest` (delivery vs COD obligation) | 1 | 1 | 0 | 0 | 14 | 0:12.9 |
| `SellerPayoutConcurrencyTest` (refund vs payout/debt) | 16 | 16 | 0 | 0 | 252 | 3:04.4 |
| `FinancialCalculationConcurrencyTest` (finalization, COD reconciliation, provider splits) | 15 | 15 | 0 | 0 | 102 | 2:56.1 |
| `PlatformRefundConcurrencyTest` | 8 | 8 | 0 | 0 | 57 | 1:33.1 |

### 8.2 SQLite (PHPUnit)

| Suite | Tests | Passed | Failed | Errors | Assertions | Time |
|---|---:|---:|---:|---:|---:|---|
| `ParcelLossTest` + `CompleteFinancialScenariosTest` (previously blocked) | 27 | 27 | 0 | 0 | 1,197 | 47.6 s |
| Buyer/rider mobile API consistency (6 classes) | 138 | 138 | 0 | 0 | 966 | 16.9 s |
| Historical/migration compatibility (legacy barangay, origin station, parcel return, purchased variant snapshot) | 32 | 32 | 0 | 0 | 332 | 6.6 s |
| Checkout regression (review guard, double submit, carrier persistence, financial integrity, flash deals/bundles) | 67 | 67 | 0 | 0 | 571 | 10.4 s |
| Checkout shipping, cross-province and carrier selection (after fixture repair) | 62 | 62 | 0 | 0 | 401 | 10.1 s |

### 8.3 JavaScript

`node --test tests/js/submit-guard.test.mjs` → **11 tests, 11 passed, 0 failed, 0 skipped**.

### 8.4 Previously executed (first half of 1.17, not re-run here)

| Suite | Tests | Result | Assertions |
|---|---:|---|---:|
| COD/remittance/shortage/station inventory/returns/platform refunds | 78 | OK | 605 |
| Checkout double-submit, commission/shipping, ledger, order monitoring | 82 | OK | 586 |
| Authorization/role isolation | 73 | OK | 697 |
| Pre-audit ledger smoke | 5 | OK | 39 |
| MySQL `CheckoutConcurrencyTest` baseline | 28 | 26 pass / 2 fail | 465 |

Overlap note: `CheckoutConcurrencyTest`, `CheckoutReviewGuardTest` and the JS suite appear in both
halves; **the runs are not additive**. Unique PHPUnit tests newly exercised in this continuation: 256
(28 + 7 + 7 + 1 + 16 + 15 + 8 + 27 + 138 + 32). The 67-test regression batch re-runs previously
reported checkout classes plus `FlashDealTest`/`CheckoutFinancialIntegrityTest`.

### 8.5 Not executed / blocked at end of B (superseded by §14)

- `DemoMarketplaceTest`, `NinjaExpressLagunaDemoTest`, `DemoNetworkSeedersTest`, `DeliveryScenarioSeederTest`
  (MySQL), `PsgcFailClosedTest` and any suite that performs live PSGC lookups:
  `PhilippineLocationService` uses `Http::timeout(5)` and this environment has no outbound access.
  Not replaced with a fake — that would change what those suites assert.
- Flutter (`Zefanya/mobile`): no tests run; the client was not modified.
- Full SQLite suite: not run (no compatible baseline for this tree).
- MySQL classes not re-run: `CodRemittanceConcurrencyTest`, `RiderPayoutConcurrencyTest`,
  `InventoryOversellingConcurrencyTest`, `LowStockAlertConcurrencyTest`, `SellerVacationConcurrencyTest`,
  `LogisticsConcurrencyTest`, `ParcelLossConcurrencyTest`, `ReturnDecisionConcurrencyTest`,
  `Rule05ReturnCompletionConcurrencyTest`, `RegistrationEmailConcurrencyTest`.

---

## 9. Previously failing tests — updated disposition

| Test / area | Previous state | Now | Classification |
|---|---|---|---|
| `CheckoutConcurrencyTest` bundle creation/removal | 2 failures | **28/28 pass** | **CONFIRMED DEFECT FIXED** (§6) plus fixture repair (§7) |
| `ParcelLossTest` | Blocked by 1.12 harness | **OK** (part of 27/1,197) | Resolved — harness, not product |
| `CompleteFinancialScenariosTest` | 6 cases blocked | **OK** (part of 27/1,197) | Resolved — harness, not product |
| Audit 1.12 checkout-token fixtures | 34 errors, ~8 failures | Green | Resolved earlier |
| Demo/PSGC suites | No result (live HTTP) | Still not runnable | Environment limitation |
| `EdGamingCatalogSeederTest`, `SellerOrderDeclineTest`, `SellerVacationModeTest` | Historical failures | Not run | Not determined |

---

## 10. Unresolved policy decisions (carried forward, unchanged)

| # | Item | Current behavior | Blocking? |
|---|---|---|---|
| 1 | Commission recognition on seller-funded return before `Completed` | `commission_finalized_at` can be set while still `Delivered` | Yes, for accounting sign-off |
| 2 | Marketplace shipping voucher greater than collected COD | Accepted at checkout, refused at settlement; no cash invented | Yes, for shipping subsidy |
| 3 | Three-or-more-provider revenue allocation | Amountless `pending_policy` rows | Yes, for multi-hop routing |
| 4 | Refund installments / authorized remainder | One final refund; no installments | No |
| 5 | Platform-funded refund budgets/thresholds | Any authorized admin, no budget cap | No |
| 6 | Individual station-employee identity | Provider account is the recorded actor | Partly |
| 7 | Confirmed Logistics→Zefanya cash has no reversal | Reject only before confirmation | Yes, for cash corrections |
| 8 | "Net platform earnings" label | Net before platform refund expense | No (copy only) |

---

## 11. Continuation B production-readiness matrix (superseded by §14)

| # | Area | Verdict | Basis |
|---|---|---|---|
| 1 | Authentication / role boundaries | READY WITH DOCUMENTED LIMITATIONS | 73 authz tests (prior) + API auth suite green |
| 2 | Buyer checkout | READY FOR CONTROLLED TESTING | 28/28 MySQL incl. both bundle races; 10 direct-HTTP guard tests; defect §6 fixed |
| 3 | Inventory | NOT VERIFIED | `InventoryOversellingConcurrencyTest` not re-run |
| 4 | Seller fulfillment | NOT VERIFIED | Not run |
| 5 | Logistics coverage | NOT VERIFIED | PSGC-dependent suites blocked by network |
| 6 | Rider assignment | READY WITH DOCUMENTED LIMITATIONS | `AutomaticDispatchConcurrencyTest` 7/7 (capacity, duplicates); dispatch UI not re-audited |
| 7 | Parcel custody | READY FOR CONTROLLED TESTING | F2 authorization + `PhysicalReturnConcurrencyTest` 7/7 |
| 8 | Station inventory | READY FOR CONTROLLED TESTING | Definition/scoping verified; green suites |
| 9 | Delivery and COD | READY FOR CONTROLLED TESTING | COD suites (prior) + delivery-vs-COD concurrency 1/1 |
| 10 | Remittances | READY FOR CONTROLLED TESTING | Prior suite green; concurrency class not re-run |
| 11 | Seller payout | READY FOR CONTROLLED TESTING | `SellerPayoutConcurrencyTest` 16/16, refunds vs payout/debt included |
| 12 | Rider payout | NOT VERIFIED | `RiderPayoutConcurrencyTest` not run |
| 13 | Logistics accounting | READY WITH DOCUMENTED LIMITATIONS | `FinancialCalculationConcurrencyTest` 15/15; multi-provider policy open |
| 14 | Returns and refunds | READY FOR CONTROLLED TESTING | `ParcelLossTest`, return/refund accounting, `PlatformRefundConcurrencyTest` 8/8 |
| 15 | Platform-funded refunds | READY FOR CONTROLLED TESTING | Concurrency + prior suite green |
| 16 | Financial reporting | READY WITH DOCUMENTED LIMITATIONS | Ledger tests green; label decision open |
| 17 | Flutter / API compatibility | READY WITH DOCUMENTED LIMITATIONS | 138 API tests green; Flutter client not executed |
| 18 | Database migrations | BLOCKED BY CONFIRMATION | Two untracked migrations exist, unrun here |
| 19 | Historical data compatibility | PARTIAL | 32 compatibility tests green; no production data read |
| 20 | Operational controls | READY WITH DOCUMENTED LIMITATIONS | Audit-log immutability from 1.16, not re-tested |

**Overall: CONDITIONAL — checkout is now closed and no known money defect remains open, but this is not
a GO for controlled end-to-end testing** while areas 3–5, 12, 18 and the full-suite/PSGC/Flutter gaps
remain.

---

## 12. Continuation B remaining work (superseded by §14.8)

1. Run the un-run MySQL concurrency classes listed in §8.5 (rider payout, overselling, low stock,
   vacation, logistics, parcel loss, return decisions, rule 05, registration email, COD remittance).
2. Provide an environment with outbound access (or an approved, explicit PSGC fixture) for the
   demo/PSGC suites; do not weaken barangay coverage.
3. Execute the Flutter client test suite against this API revision (no Flutter change made here).
4. Establish a full-SQLite baseline for this tree and compare it with the last recorded 1.13 run.
5. Decide the eight policy items in §10.
6. Optional hardening: make the reviewed-token comparison unconditional after every fixture renders
   the checkout page, removing the fail-open conditional described in §5.5.
7. Read-only verification of historical production-shaped data (migrations 2026_11_11_180000 and
   2026_11_13_045900 are still unrun).

---

## 13. Files changed in this continuation

| File | Change |
|---|---|
| `app/Services/Buyer/CheckoutService.php` | Hoisted the `checkout_tokens` readiness probe out of `placeOrder()`'s transaction (defect fix, §6). |
| `tests/MySql/CheckoutConcurrencyTest.php` | Added `reviewed()` (renders the real checkout page and replays its session); applied to the two bundle cases; added persisted-promotion assertions. |
| `tests/MySql/worker.php` | Checkout task replays the reviewed token and reviewed price fingerprint from the page. |
| `tests/Feature/Buyer/CheckoutReviewGuardTest.php` | Import ordering only (Pint). |
| `tests/Feature/Buyer/CheckoutShippingTest.php` | Four fixtures now submit the reviewed page token instead of a freshly issued one. |
| `docs/audits/ZEFANYA_AUDIT_1_17_FINAL_END_TO_END_SYSTEM_INTEGRITY.md` | This report. |
| `tmp/audit117/*.log` | Throwaway run logs. |

No other production, test, view, migration or configuration file was modified. No commit, push,
deploy or production database access occurred. `vendor/bin/pint --dirty` passes.

---

## 14. Continuation C — completed MySQL concurrency verification

Date: 2026-11-13, Asia/Singapore. This section supersedes the remaining-MySQL inventory,
network-blocker claims and readiness assessment in the Continuation B sections above.

### 14.1 Outcome and safety

**All remaining concurrency classes completed: 55 tests, 572 assertions, zero failures,
errors or skips in their final runs.** Ten classes were found under `tests/MySql` beyond the
seven already verified in B. An eleventh remaining class, `ParcelLossConcurrencyTest`, lives
under `tests/Feature/Logistics`; it is included here rather than silently omitted.

Seven additional MySQL integration classes passed **147 tests / 1,316 assertions**. These
are sequential integration tests, not evidence of concurrent overlap. Final new MySQL
coverage is **202 tests / 1,888 assertions**. Earlier B results are retained separately:
**82 tests / 1,094 assertions**, not re-run or counted as new C executions.

No new production vulnerability was confirmed. The prior High-severity checkout snapshot
defect remains fixed (§6). C changed test fixtures and the parcel-loss test harness only;
no application, route, migration, `.env`, credential, financial policy or production
configuration changed. Existing uncommitted work, including earlier token fixture repairs,
was retained. No commit, push or deployment occurred.

Isolation was checked before execution and again at completion with
`php tests/MySql/run.php --preflight-only`. `Preflight` verified the exact server UUID,
`zefanya_concurrency_test`, restricted account, escaped schema grants, absence of roles,
and error 1044 on the denied `USE Zefanya` probe. No application-schema tables were read.
The driver checked `SHOW FULL PROCESSLIST` for other sessions of the dedicated account;
none existed at batch starts. The shared runner lock was respected (held externally only
for classes without their own lock). Existing unrelated PHP processes were left alone.
Suites ran sequentially; worker concurrency occurred only inside individual tests.
Schema preparation/cleanup used the existing strict preflight and owned disposable schema;
no `migrate:fresh`, production migration or production cleanup was executed.

### 14.2 Full discovered inventory

PHPUnit discovery used `--list-tests-xml tmp/audit117c/discovered.xml tests/MySql` with
`phpunit.mysql.xml`. It found **27 classes / 298 dataset-expanded cases**: 17 concurrency
classes and 10 ordinary MySQL wrapper classes. The latter include three demo classes
(17 cases) reserved for the next fixture work. This is the discovered count, not an
assumption based on B's list. `SellerRegistrationDecisionConcurrencyTest` was missing
from that earlier list; parcel loss was listed under the wrong directory.

| Class | Discovered tests (datasets included) | Workflow | Status before C | C disposition |
|---|---:|---|---|---|
| `AutomaticDispatchConcurrencyTest` | 7 | Rider assignment and capacity | B: passed | Prior evidence retained; not re-run |
| `CheckoutConcurrencyTest` | 28 | Checkout tokens, pricing, promotions, stock | B: passed | Prior evidence retained; not re-run |
| `CodRemittanceConcurrencyTest` | 11 | Cash receipt, recoveries, platform remittances, rollback | Not verified in B | Passed; results below |
| `DeliveryScenarioSeederTest` | 11 | Demo delivery fixtures; not a concurrency class | B: not run / reported blocked | NOT VERIFIED; non-concurrency follow-up |
| `DemoMarketplaceTest` | 5 | Demo marketplace fixtures; not a concurrency class | B: not run / reported blocked | NOT VERIFIED; non-concurrency follow-up |
| `DemoNetworkSeedersTest` | 1 | Demo network fixtures; not a concurrency class | B: not run / reported blocked | NOT VERIFIED; non-concurrency follow-up |
| `FinancialCalculationConcurrencyTest` | 15 | Commission, settlement, frozen provider allocation | B: passed | Prior evidence retained; not re-run |
| `FinancialLedgerIntegrityConcurrencyTest` | 1 | Delivery versus COD obligation creation | B: passed | Prior evidence retained; not re-run |
| `InventoryOversellingConcurrencyTest` | 2 | Final SKU, variants, stock ledger, rollback | Not verified in B | Passed; results below |
| `LogisticsCheckoutTest` | 11 | Cross-provider checkout and server pricing | Not verified in B | Passed; results below |
| `LogisticsConcurrencyTest` | 2 | Manifest receipt and route revision | Not verified in B | Passed; results below |
| `LogisticsConnectionsTest` | 24 | Transport network ownership and authorization | Not verified in B | Passed; results below |
| `LogisticsExceptionsTest` | 42 | Holds, recovery, rerouting and receipts | Not verified in B | Passed; results below |
| `LogisticsProgressionTest` | 20 | Route progression, duplicate receipts and custody | Not verified in B | Passed; results below |
| `LogisticsRoutingTest` | 26 | Saved routing, transfer eligibility and history | Not verified in B | Passed; results below |
| `LogisticsStabilizationTest` | 8 | HTTP delivery lifecycle and financial snapshots | Not verified in B | Passed; results below |
| `LowStockAlertConcurrencyTest` | 2 | Same/different SKU threshold notification races | Not verified in B | Passed; results below |
| `PhysicalReturnConcurrencyTest` | 7 | Return custody, station receipt and inventory | B: passed | Prior evidence retained; not re-run |
| `PlatformRefundConcurrencyTest` | 8 | One refund/expense, reversal and payer races | B: passed | Prior evidence retained; not re-run |
| `RegistrationEmailConcurrencyTest` | 12 | Cross-role normalized email ownership | Not verified in B | Passed; results below |
| `ReturnDecisionConcurrencyTest` | 9 | Completion/escalation, payer/refund and COD races | Not verified in B | Passed; results below |
| `RiderPayoutConcurrencyTest` | 5 | Duplicate/overlapping payouts and stale reversal retry | Not verified in B | Passed; results below |
| `RoutedTransitionsTest` | 16 | Sorting, verified handoffs, reverse routing | Not verified in B | Passed; results below |
| `Rule05ReturnCompletionConcurrencyTest` | 2 | Return-window versus completion lock ordering | Not verified in B | Passed; results below |
| `SellerPayoutConcurrencyTest` | 16 | Reservation, payout, refund and debt serialization | B: passed | Prior evidence retained; not re-run |
| `SellerRegistrationDecisionConcurrencyTest` | 4 | First committed Seller review wins | Not verified in B | Passed; results below |
| `SellerVacationConcurrencyTest` | 3 | Vacation/checkout locks and concurrent cart stock caps | Not verified in B | Passed; results below |

Outside this directory: `tests/Feature/Logistics/ParcelLossConcurrencyTest.php` has 3 cases;
previous status NOT VERIFIED, final status FIXED AND VERIFIED (harness), 3/3 pass.

### 14.3 Actual final C results, one row per class

Durations below are the executed PHPUnit duration recorded in each class log, excluding
the audit driver's additional process startup overhead. Root cause/fix for the four
initially failing integration classes is in §14.4; all other classes had no assertion or
application error. Per-class remaining coverage limits are the workflow boundaries in
§14.5; these tests do not verify all possible interleavings or off-platform transfers.

| Class | Tests | Passed | Failed | Errors | Skipped | Assertions | Duration | Real overlap |
|---|---:|---:|---:|---:|---:|---:|---|---|
| `CodRemittanceConcurrencyTest` | 11 | 11 | 0 | 0 | 0 | 75 | 131.373 s | Yes: 8 lock races; 3 rollback-only cases |
| `InventoryOversellingConcurrencyTest` | 2 | 2 | 0 | 0 | 0 | 43 | 5.613 s | Yes: 2 blocked-read races |
| `LogisticsCheckoutTest` | 11 | 11 | 0 | 0 | 0 | 72 | 16.598 s | No: sequential integration, not a race |
| `LogisticsConcurrencyTest` | 2 | 2 | 0 | 0 | 0 | 14 | 25.070 s | Yes: 2 blocked-read races |
| `LogisticsConnectionsTest` | 24 | 24 | 0 | 0 | 0 | 92 | 15.310 s | No: sequential integration, not a race |
| `LogisticsExceptionsTest` | 42 | 42 | 0 | 0 | 0 | 225 | 20.467 s | No: sequential integration, not a race |
| `LogisticsProgressionTest` | 20 | 20 | 0 | 0 | 0 | 98 | 18.628 s | No: sequential integration, not a race |
| `LogisticsRoutingTest` | 26 | 26 | 0 | 0 | 0 | 50 | 13.262 s | No: sequential integration, not a race |
| `LogisticsStabilizationTest` | 8 | 8 | 0 | 0 | 0 | 569 | 24.005 s | No: sequential integration, not a race |
| `LowStockAlertConcurrencyTest` | 2 | 2 | 0 | 0 | 0 | 22 | 4.134 s | Yes: 1 blocked-read + 2 both-held races across 2 tests |
| `RegistrationEmailConcurrencyTest` | 12 | 12 | 0 | 0 | 0 | 84 | 7.640 s | Yes: 9 observed GET_LOCK waits; 3 index-only cases |
| `ReturnDecisionConcurrencyTest` | 9 | 9 | 0 | 0 | 0 | 86 | 104.364 s | Yes: 8 races; 1 reporting-only case |
| `RiderPayoutConcurrencyTest` | 5 | 5 | 0 | 0 | 0 | 41 | 58.825 s | Yes: 5 blocked-read races |
| `RoutedTransitionsTest` | 16 | 16 | 0 | 0 | 0 | 210 | 48.236 s | No: sequential integration, not a race |
| `Rule05ReturnCompletionConcurrencyTest` | 2 | 2 | 0 | 0 | 0 | 20 | 22.758 s | Yes: 2 observed shipment lock waits |
| `SellerRegistrationDecisionConcurrencyTest` | 4 | 4 | 0 | 0 | 0 | 80 | 3.825 s | Yes: 4 observed Seller row-lock waits |
| `SellerVacationConcurrencyTest` | 3 | 3 | 0 | 0 | 0 | 36 | 4.832 s | Yes: 2 blocked-read + 1 both-held race |
| `ParcelLossConcurrencyTest` | 3 | 3 | 0 | 0 | 0 | 71 | 32.497 s | Yes: all 3 cases; held transaction + competing locking read |

`Race::run` verifies separate database connections, overlapping worker intervals and an
observed in-flight locking read before releasing A. Its `bothHeld` branch verifies both
transactions are held concurrently, rather than pretending they blocked on one row.
The registration tests separately observe MySQL advisory lock or Seller row-lock waits.
The repaired parcel-loss harness holds the first real transaction and observes competing
locking reads in all three cases, recording connection IDs and transaction level.

Evidence is retained in `.codex/t05-runs/*/evidence.json`,
`storage/logs/phase6-races`, `storage/logs/rule05-races`,
`storage/logs/registration-email-races`, `storage/logs/seller-registration-races` and
`storage/logs/loss-races/*/evidence.json`, with worker task/results alongside it.
Per-class `.log` and JUnit `.xml` files are in `tmp/audit117c`.

Additional C SQLite verification (separate from B and MySQL):

| Scope | Tests | Passed | Failed | Errors | Skipped | Assertions | Duration |
|---|---:|---:|---:|---:|---:|---:|---|
| Four changed routing/exception/lifecycle fixture classes | 86 | 86 | 0 | 0 | 0 | 1,102 | 36.897 s |
| Existing PSGC fail-closed suite | 9 | 9 | 0 | 0 | 0 | 21 | 3.299 s |
| Existing Courier-required and active-provider-owned Courier guards | 2 | 2 | 0 | 0 | 0 | 44 | 2.215 s |
| **Total** | **97** | **97** | **0** | **0** | **0** | **1,167** | |

Targeted MySQL fixture reproduction after repair: one previously failing rerouted-manifest
receipt test passed, 1 test / 5 assertions / 11.022 s, before the full affected classes.
This repeated test is not added to unique coverage. A default SQLite run of the opt-in
parcel-loss class correctly skipped 3 tests without opening its MySQL application.
Pint formatted only the changed PHP files and temporary driver; final
`php vendor/bin/pint --dirty --test --format agent` and `git diff --check` passed.

### 14.4 Every initial failure and correction

**Fixture defect, Medium (coverage obstruction):** old positive transfer fixtures omitted
the Courier assignment now required by `TransferCourierService::assertReadyForDispatch()`.
MySQL uses the current required-transport default; SQLite's existing configuration gives
older transfers legacy behavior. The production guard correctly refused dispatch.
For service calls the exact error was `Illuminate\Validation\ValidationException`:
"Assign the Courier who carries this batch before dispatching it." (or "this handoff").
The three lifecycle HTTP cases failed `assertSessionHasNoErrors()` with that same message.
This was not classified as test-only on assertion text alone: the test dispatch setup,
service requirement and absence of an assigned Courier were checked in source, then a
real assignment through the production service made the targeted test and full classes pass.

| Initial class run | Tests | Passed | Failed | Errors | Skipped | Assertions | Duration |
|---|---:|---:|---:|---:|---:|---:|---|
| `LogisticsExceptionsTest` | 42 | 40 | 0 | 2 | 0 | 209 | 20.682 s |
| `LogisticsProgressionTest` | 20 | 4 | 0 | 16 | 0 | 17 | 14.246 s |
| `LogisticsStabilizationTest` | 8 | 5 | 3 | 0 | 0 | 270 | 20.743 s |
| `RoutedTransitionsTest` | 16 | 12 | 0 | 4 | 0 | 150 | 43.955 s |

Exact initially failing methods/datasets:

- `LogisticsExceptionsTest::test_rerouted_future_manifest_receipt_advances_without_rewriting_completed_prefix`
- `LogisticsExceptionsTest::test_wrong_hub_recovery_uses_real_receiving_then_authorized_reroute_and_final_receipt`
- `LogisticsProgressionTest::test_verified_manifest_receipt_advances_exactly_one_leg_and_exposes_next_destination`
- `LogisticsProgressionTest::test_verified_cross_provider_receipt_advances_one_leg_and_acceptance_or_dispatch_alone_do_not`
- `LogisticsProgressionTest::test_duplicate_manifest_receipt_cannot_advance_again`
- `LogisticsProgressionTest::test_duplicate_cross_provider_receipt_cannot_advance_again`
- `LogisticsProgressionTest::test_wrong_destination_rolls_back_receipt_and_custody`
- `LogisticsProgressionTest::test_wrong_provider_cannot_receive_or_advance`
- `LogisticsProgressionTest::test_custody_with_wrong_provider_cannot_advance_even_with_receipt`
- `LogisticsProgressionTest::test_receiving_rechecks_custody_after_scan_before_recording_receipt`
- `LogisticsProgressionTest::test_receipt_without_matching_custody_cannot_advance`
- `LogisticsProgressionTest::test_final_manifest_receipt_completes_route_without_skipping_sorting`
- `LogisticsProgressionTest::test_final_cross_provider_completion_enables_existing_delivery_after_sorting`
- `LogisticsProgressionTest::test_inactive_future_connection_does_not_block_historical_receipt_but_blocks_next_dispatch`
- `LogisticsProgressionTest::test_failed_route_update_rolls_back_receipt_and_can_retry_same_token`
- `LogisticsProgressionTest::test_deactivated_current_connection_can_still_be_received_after_dispatch`
- `LogisticsProgressionTest::test_failed_cross_provider_progression_rolls_back_handoff_and_custody`
- `LogisticsProgressionTest::test_legacy_parcel_without_saved_route_keeps_existing_receiving_behavior`
- `LogisticsStabilizationTest::test_verified_delivery_lifecycle with data set "Lumban to Pila"`
- `LogisticsStabilizationTest::test_verified_delivery_lifecycle with data set "Bulacan to Quezon City"`
- `LogisticsStabilizationTest::test_verified_delivery_lifecycle with data set "Luzon to Mindanao"`
- `RoutedTransitionsTest::test_sorting_waits_for_the_final_destination_hub`
- `RoutedTransitionsTest::test_parcel_travels_through_an_intermediate_provider_with_no_direct_partnership`
- `RoutedTransitionsTest::test_return_without_a_reverse_partnership_needs_admin_authorization_for_that_parcel`
- `RoutedTransitionsTest::test_return_steps_back_through_an_intermediate_provider`

The shared new `Tests\Support\AssignsTransferCouriers` fixture creates an active Courier
through `TransferCourierService::save()` and assigns it using `assignToManifest()` or
`assignToHandoff()`. Explicit calls were added only to positive transfer fixtures in the
four affected feature classes. No dispatch validation, ownership guard, transport-required
configuration, expected refusal or production code was relaxed. Existing negative Courier
guard tests also passed. Full initial stack/expectation evidence is in the preserved
`*-initial.xml`, `*-initial.log` and `initial-failures.json` artifacts.

**Harness defect, High test-isolation risk; fixed before execution:** the old parcel-loss
class used only a disposable-looking database-name check, invoked `migrate:fresh`, and
booted its workers through ordinary environment configuration. It also released workers
at a barrier without proving database overlap. The class and worker now use `Preflight`,
the shared lock, `prepareSchema()`/owned-schema `clean()`, independent-connection assertions,
and held-transaction/observed-lock evidence. Cleanup releases/stops only its own workers.
No unsafe old harness run was performed.

**Temporary driver issue, resolved:** the first loss-class attempt skipped all 3 cases
(0 assertions, 0.092 s). Symfony Process filters CLI environment variables using
`$_SERVER`/`$_ENV`; setting only `putenv('LOSS_CONCURRENCY_DB=1')` in the new temporary
driver did not propagate the opt-in flag. Setting all three representations repaired
the driver. The subsequent real 3/3 result above replaces that skipped attempt; skips
are preserved in `ParcelLossConcurrencyTest-initial-skipped.*`, not counted as coverage.

**Existing runner coverage limitation, documented:** `phpunit.mysql.xml` explicitly lists
11 classes, not the 27 discovered. `tests/MySql/run.php` also owns the lock that several
classes acquire themselves. C therefore used explicit per-file PHPUnit selection with
correct lock ownership, not a filtered default run that could omit classes or skip on its
own lock. Neither configuration nor the existing runner was changed. Until that separate
harness integration is repaired, use `tmp/audit117c/run.php` (sequential driver) or explicit
class paths with the appropriate lock; do not assume the default suite covers everything.

**Current executed test failures: zero.** Full SQLite and deferred demo/Flutter/browser
suites were not run; their absence is not a passing result.

### 14.5 Cross-system invariants and remaining races

| Critical workflow / invariant | Classification | Evidence and practical limit |
|---|---|---|
| Seller payout cannot duplicate; refund/debt deductions serialize | VERIFIED | B SellerPayout 16/16; C COD/return convergence and lifecycle snapshots. B cases not re-run. |
| Rider payouts cannot duplicate or overspend; stale retry after reversal fails | VERIFIED | C RiderPayout 5/5, including high amounts and overlapping batches. External transfer settlement remains manual. |
| Rider earning identity, reassignment, no automatic shortage deduction | PARTIALLY VERIFIED | Source: credit keyed by parcel/job under status lock; payout sums earning rows, no COD deduction path. C lifecycle exercises earnings; no separate simultaneous earning/reassignment race added. |
| Delivery/COD evidence and exact collection, one obligation | VERIFIED | B ledger delivery race 1/1 and prior COD validation; C lifecycle + COD 11/11. Not proof of real cash movement. |
| Concurrent remittances/recoveries cannot invent supported balances | VERIFIED | C duplicate handover, installment references, allocation, stale overwrite, platform confirm/reject and rollback tests. Confirmed platform cash reversal policy remains open (§10). |
| Logistics earning allocations do not duplicate; 40/60 retained | PARTIALLY VERIFIED | B FinancialCalculation 15/15; source frozen allocations and provider locks; C integration retains parcel financial snapshots. No dedicated origin-share payout versus destination-adjustment simultaneous race was added. Three-plus-provider allocation still BLOCKED BY POLICY. |
| Platform refund creates one expense and reversal, no Seller debt or Logistics charge | VERIFIED | B PlatformRefund 8/8 including injected rollback, duplicate refund/void and conflicting payer. C return-decision races 9/9. One active refund per return preserved. |
| Current commission 10%; saved historical 5% and settled history preserved | VERIFIED | `COMMISSION_BPS = 1000`, saved `commission_bps ?? default`; B financial calculation datasets exercise 500/1000 and frozen history. No historical or policy rewrite in C. |
| Assignment is not physical custody; station receipt requires acknowledgement | VERIFIED | C custody/routing integration and LogisticsConcurrency 2/2; B PhysicalReturn 7/7. Courier fixture assignment does not move custody. |
| Station inventory derived once per parcel and excludes uncommitted receipt | VERIFIED | B PhysicalReturn inventory race and StationInventory tests; source remains provider/station scoped, parcel-derived, terminal-aware. No new inventory source changes. |
| Return transport cannot precede station receipt/authorized route | VERIFIED | B reverse-handoff receipt race and C routed return flows, invalid receipt/route refusals; verified for these paths. |
| Return versus completion/funding decision has one committed winner | VERIFIED | C ReturnDecision 9/9 and Rule05 2/2; authorized unpaid-refund reporting also covered. |
| Parcel-loss confirmation and compensation cannot duplicate | FIXED AND VERIFIED | Test harness repaired; C 3/3 with real overlap, one obligation/history/notice per recipient. Production logic unchanged. |
| Checkout locked inventory, tokens, fingerprints and stale promotions | FIXED AND VERIFIED | B checkout 28/28 including stale promotion fix; C final-SKU, vacation, cross-provider checkout and route-write rollback tests. No guard weakened. |
| Provider ownership/authorization for routing and transfers | VERIFIED | C connections 24/24, exceptions 42/42, progression/routing and negative active/owned Courier checks. Not a fresh audit of every application endpoint. |
| Full SQLite regression on this dirty tree | NOT VERIFIED | Only the 97 C SQLite cases above; B batches remain separate. |
| Offline demo fixtures / complete PSGC dataset | PARTIALLY VERIFIED | Existing 9 fake-HTTP fail-closed tests passed. Snapshot exists; three MySQL demo classes (17 cases) remain unexecuted. |
| Flutter/API runtime compatibility | PARTIALLY VERIFIED | B API 138/138 retained; Flutter and Dart are installed, but client/device runtime not executed. |
| Historical database upgrade/migration compatibility | PARTIALLY VERIFIED | Isolated schema preparation succeeds; B 32 compatibility cases retained. No production-shaped upgrade rehearsal or production schema inspection. |
| Browser end-to-end workflows | NOT VERIFIED | No C browser, mobile viewport or assistive-technology checks. |
| Accounting recognition, shipping subsidy, multi-provider/cash correction policy | BLOCKED BY POLICY | Carry forward §10; no decision inferred or policy changed. |

Additional unverified interleavings: every possible forward pickup-collection/cancellation
ordering; station receipt/loss/handoff under all forward manifests; real external cash
transfers and retries across devices; multi-node/deployment failure recovery. Existing
tests cover named cases, not exhaustive race freedom. No discovered concurrency class
remains unexecuted, but these finer-grained scenarios must not be claimed fully proven.

### 14.6 Dependency tracing and tools

`C:\ProjectEcommerce\CLAUDE_GRAPH_WORKFLOW.md` was absent at the required path and was not
found by the targeted filename search. No workflow file was invented. `graphify --help`
confirmed usable query commands; existing `graphify-out/graph.json` contains 13,463 nodes
and a code-only build. It was reused, not regenerated or assumed current merely because
the CLI exists. Commands used:

```text
graphify query "COD remittance rider payout refund custody" --depth 1 --budget 900
graphify query "ParcelLossService" --depth 1 --budget 1100
graphify query "TransferCourierService" --depth 1 --budget 500
```

Targeted `rg` and source reads verified graph results against services and tests. Relevant
paths traced: Logistics/Admin/Rider routes and controllers → remittance, earning/payout,
manifest/handoff/incident, return/refund, status and commission services → locked
SellerOrder/Payment/return/settlement/debt/allocation/earning/custody rows and audit events.
Parcel-loss Admin incident resolution locks the parcel before finalizing a case;
compensation locks the case. Transfer dispatch checks owner, draft/accepted state and
Courier eligibility before consuming verified scans; only receipt moves custody/route.
Changed support fixtures call those same services. The only affected consumers are the
four feature classes and their inherited MySQL wrappers, plus the standalone loss class
and its worker. No Web/Flutter contract or job behavior changed. The affected classes
were retraced and re-run on MySQL and, for the four inherited fixture classes, SQLite.

### 14.7 Exact files changed by C

Production files modified: **none**. Existing uncommitted application changes belong to
earlier work and were not reverted or claimed as C changes.

| Test/document file | C change |
|---|---|
| `tests/Feature/Logistics/ParcelLossConcurrencyTest.php` | Strict preflight/owned cleanup and opt-in runner lock; actual overlap and independent sessions; safe own-worker cleanup; persistent evidence. |
| `tests/Support/loss_worker.php` | Strict application preflight with suppressed credential errors; connection identity and held-transaction synchronization. |
| `tests/Support/AssignsTransferCouriers.php` | New fixture helper using existing Courier save/assignment services. |
| `tests/Feature/Logistics/AutomaticRouteProgressionTest.php` | Assign Courier in manifest/handoff fixtures. |
| `tests/Feature/Logistics/Phase5ExceptionHandlingTest.php` | Assign Courier to positive reroute/recovery manifest fixtures. |
| `tests/Feature/Logistics/EndToEndLogisticsStabilizationTest.php` | Assign Courier before successful manifest/handoff HTTP dispatch; preserve preexisting reviewed-token changes. |
| `tests/Feature/Logistics/RoutedTransitionsTest.php` | Assign Courier in manifest/handoff fixture helpers; preserve preexisting edits. |
| `docs/audits/ZEFANYA_AUDIT_1_17_FINAL_END_TO_END_SYSTEM_INTEGRITY.md` | C inventory, executed evidence, failure disposition, invariants and readiness. |

Temporary artifacts: `tmp/audit117c/run.php`, `update-report.py`, discovery/method inventories,
per-class logs/JUnit, preserved initial failures, aggregate JSON, formatting/diff logs,
and `psgc-regions.json`. Standard race tasks/results/evidence were generated in the
existing isolated harness log directories. No frontend file changed, so no build was needed.

### 14.8 Next required work and updated readiness

1. **Full SQLite regression next:** establish an actual baseline for this exact dirty tree,
   classify failures against earlier audits, and fix confirmed issues. Targeted C regressions
   are green; they cannot replace the full suite.
2. **Offline PSGC/demo verification:** execute the deferred three MySQL demo classes (17
   cases) and related NinjaExpress/demo tests using the existing snapshot where appropriate.
   `database/seeders/data/demo/psgc.json` exists; `DemoNetwork::psgc()` reads it. Do not
   silently replace live-lookup expectations. C's bounded request to the configured public
   regions endpoint returned HTTP 200; B's blanket no-outbound claim is not current.
   `PsgcFailClosedTest` already fakes HTTP and passed unchanged. One HTTP 200 does not
   establish every municipality/barangay lookup or snapshot currency.
3. **Historical upgrade rehearsal:** exercise both new migrations against a disposable
   older schema with representative historical rates/refunds/settlements, separately from
   fresh/current schema preparation. Production migrations remain unexecuted and unauthorized.
4. **Flutter/API runtime:** execute `Zefanya/mobile` tests and real client/API flows against
   this backend revision, including stale checkout, COD evidence and payout/refund state.
   SDK availability alone is not compatibility evidence.
5. **Browser end-to-end:** use isolated synthetic accounts/data for Buyer → Seller →
   Logistics → Rider → Admin journeys and responsive/auth/validation/receipt states.
   Browser automation must not default to the live `Zefanya` database.

The missing-class/default-runner lock integration and the specific untested interleavings
in §14.5 should also be scheduled; neither blocks the truth of the explicit C executions.

**Recommendation: MySQL concurrency gate passed for all discovered classes, with test
harness defects repaired and no newly confirmed production money/custody defect. This
is not production release approval.** Continue controlled testing on an isolated deployment;
retain a NO-GO for production release until full regression, fixture/upgrade, runtime/browser
verification and the accounting-blocking policy decisions have been resolved. No C work
is left running and no discovered concurrency class is deferred.



## 15. Checkout regression and refund/COD follow-up - 2026-11-14

Scope: two reported checkout failures, order #41 / return #2, and proposed refund rules. Production database investigation used `START TRANSACTION READ ONLY` and rollback. No production source, records, migrations, commission/shipping policies or historical snapshots were changed. The live site serves this working tree; edits are limited to tests and audit documentation.

### 15.1 Reproduction, dependencies and minimal correction

Independent isolated SQLite runs reproduced Vacation: 22 tests, 21 passed, 1 failed, 172 assertions; PriceResolver: 26 tests, 25 passed, 1 error, 110 assertions. Both fixtures opened the real checkout page, then submitted a newly issued token instead of that page's reviewed token. CheckoutController correctly rejected the mismatch with its session `checkout_form_token` / `checkout_reviewed_price` state. These were obsolete fixtures, not a reproduced production defect.

Both tests now submit `viewData('checkoutToken')` from the reviewed page. PriceResolver additionally verifies token consumption and has a negative regression proving another otherwise-valid token cannot replace the reviewed token: no order item, stock/cart change or token consumption occurs. Existing mixed-cart assertions preserve the vacation seller's line and stock while ordering the open seller's line. No validation was relaxed.

Traced routes/controller to CheckoutToken, CheckoutService, PriceResolver, carrier snapshots, cart selection, seller vacation checks, inventory locks and Order reference generation; downstream order items, payment and financial snapshots remain unchanged. Existing pre-transaction `Schema::hasTable` checks remain outside MySQL's order-placement transaction, preserving the REPEATABLE READ snapshot fix. Reference generation uses the configured schedule timezone and a random collision-checked suffix; it did not cause either failure. Graphify was not necessary or invoked: direct PHP source tracing, `rg`, Git status/diffs and focused tests were used. Graphify 0.9.108 is installed; generated graphs were not treated as behavioral evidence. The AGENTS-referenced parent `CLAUDE_GRAPH_WORKFLOW.md` was absent.

### 15.2 Executed verification

| Execution | Passed | Failed / errors / skipped | Assertions |
|---|---:|---|---:|
| Both affected classes, checkout review guards and Manila time display (post-format verification) | 68 | 0 / 0 / 0 | 367 |
| Neighboring checkout, COD, returns, payout and financial regressions | 383 | 0 / 0 / 0 | 2,833 |
| Isolated MySQL races | 5 | 0 / 0 / 0 | 73 |
| Total distinct cases in final verification | 456 | 0 / 0 / 0 | 3,273 |

The affected pair initially passed 49 tests / 298 assertions after correction; the final 68-case run includes that pair plus CheckoutReviewGuardTest and ManilaTimeDisplayTest (no double counting). Targeted formatting and `git diff --check` completed.

SQLite neighbor classes: CheckoutFinancialIntegrityTest, CheckoutCarrierSelectionTest, BuyerCommerceApiTest, CommissionShippingIntegrityTest, CodRemittanceCreationTest, CodRemittanceShortageTest, LogisticsRemittanceTest, ReturnRefundApprovalPolicyTest, ReturnRefundAccountingTest, PlatformFundedRefundTest, PhysicalReturnTest, ReturnRecoveryTest, SellerPayoutRequestTest and RiderEarningsTest. These exercise valid checkout, stale/invalid quotes and tokens, stock, authorization, refund caps/final partial refunds, historical commission snapshots, COD shortages, platform liability and payout protections.

MySQL `phpunit.mysql.xml` execution selected CheckoutConcurrencyTest's bundle creation/removal stale-quote races, SellerPayoutConcurrencyTest's duplicate refund/debt-after-payout race, and CodRemittanceConcurrencyTest's duplicate handover/stale full-confirmation cases (two datasets). Dedicated `zefanya_concurrency_test` schema, preflight identity checks and runner locks were used; suites ran sequentially. No full-suite rerun or production browser mutation was performed. These focused passes do not clear unrelated historical failures or release gates in section 14.8.

### 15.3 Order #41 read-only reconciliation

All following times are **2026-11-13 Asia/Manila** (stored UTC plus eight hours).

| Event / record | Evidence |
|---|---|
| COD collection 14:02:06 | Payment #41 paid, amount and collected amount PHP 5,040; Courier #1 links Rider #3 to Logistics #18. Delivery OTP consumed and delivery proof submitted at the same time. This supports application collection, not independent verification of physical cash. |
| Return request 14:04:22; approval 14:05:26 | Return #2, wrong item received, Seller #3 approved. Fault defaults to seller under current rules; no Admin fault override. |
| Pickup assignment 14:24:37; collection 14:25:14 | Courier #1 assigned; Rider #3 collected. No station-received event, `origin_arrived_at` or `seller_received_at`; helper reports awaiting station receipt. Stored hub #3 pointer alone does not prove hand-in. Legacy collected event has empty metadata. |
| Damage event 14:28:09 | Logistics #18 recorded transit damage; incident #2 remains open with no resolution. This does not automatically replace the seller-fault decision; responsibility needs case review. |
| Refund recording 14:35:33 | Authorized Admin #2 recorded PHP 5,040, paid_on Nov 13, recipient present, reference `5040.00`, no proof path/file. Refund event records actor, amount and has_proof=false. No documented early-refund exception; current approved-return workflow does not require one. |
| COD remittance #9 | PENDING_REMITTANCE, PHP 5,040; no Logistics confirmation, received amount, platform remittance link or platform receipt. Shipping allocation snapshot retains PHP 50; no shortage/recovery recorded. Refund did not clear this obligation. |
| Settlement and liabilities | No SellerSettlement, SellerDebt, LogisticsReturnCharge or PlatformRefundExpense for this order. Current returnBreakdown: items PHP 4,990 refunded, commission/net zero, PHP 50 original plus PHP 50 return shipping, computed PHP 100 seller shortfall. This is a prospective calculation, not a posted debt or proven external payment. Settlement/debt and completed-return charge writers have deferred prerequisites. |

The full refund equals the recorded collection cap; no duplicate active refund, over-refund, erased COD obligation or automatic rider earnings deduction was found. Seller is the current payer; there is no platform-funded exception. The 10% snapshot remains stored while refund-aware commission/net are zero. No historical 5% rates or Logistics allocations were changed.

**Classification:** permitted by current approved-return/recorded-collection rules; external refund payment remains unverified. Receipt-before-refund and mandatory early-refund authorization are unresolved owner policies, not established guards to add silently. The legacy custody pointer/event mismatch is a historical evidence limitation. An amount-like reference and absent proof cannot establish that money reached the buyer. Ordinary refunds have ReturnEvent attribution; absence of an operations-audit row alone does not establish unauthorized payment. Order #41 and all related records were left untouched.

### 15.4 Proposed policy versus current implementation

| Proposed rule | Current behavior / decision |
|---|---|
| 1. Approval separate from payment | Already separate events; recording is not a bank transfer. |
| 2. Refund no greater than verified collected COD | Existing paid/recorded collection cap enforced by refundableCollectedMinor, including legacy paid-record fallback. #41 has matching OTP/proof. Define any stronger independent cash-verification requirement before changing eligibility. |
| 3. Normally require Seller receipt | Not currently enforced for approved refunds. Owner approval required. |
| 4. Document authorized early exceptions | Current exception basis/note/payer covers unapproved returns; approved early refunds have no timing-exception gate. Owner approval required. |
| 5. Pending remittance stays owed | Preserved independently of refunds. |
| 6. Verifiable payment evidence | Reference, recipient and paid date required; private proof optional. No bank verification. Stronger evidence/process requirements need owner approval. |
| 7. Explicit refund payer | Approved seller-fault default, Admin fault determination and explicit platform-funded exception supported. Review the unresolved damage incident without silently reassigning payer. |
| 8. No automatic rider shortage earnings deduction | Preserved; existing shortage and rider earnings regressions passed. |
| 9. One active refund; final partial refund rule | Existing locked/revision-checked recording, final-partial reason and history-preserving void/replacement retained and tested, including MySQL duplicate race. |
| 10. Statutory buyer rights | Existing returns-page legal-rights wording and legal-requirement exception preserved. No new legal interpretation or statutory compliance certification is made. |

Operational exposures: an approved refund can be recorded while COD is still with a rider and the parcel has not reached the seller; optional proof/free-text reference does not verify external payment. These require policy/process decisions, not an invented automatic remittance, liability reassignment or earnings deduction. No new confirmed accounting loophole was reproduced in this follow-up.

Files changed by this follow-up: `tests/Feature/Seller/SellerVacationModeTest.php`, `tests/Feature/Buyer/PriceResolverTest.php`, this report and `ZEFANYA_BUYER_SELLER_LOGISTICS_BROWSER_AUDIT.md`. No commit, push, deployment or live data mutation. Production readiness remains NO-GO pending the existing release gates and refund evidence/policy decisions; the two reported checkout failures are resolved as fixture defects.


## 16. Owner-approved refund authorization and payment verification - 2026-11-14

**Implementation prepared and verified in isolation; NOT activated on production.** This section supersedes section 15.4's unresolved policy classification for the approved rules. Historical external payments remain unverified where evidence is missing. No live migrations, data mutations, commits, pushes or deployments were performed.

### 16.1 Controls and impact analysis

- Return approval remains separate from refund authorization, evidence submission and paid confirmation. The existing ParcelReturnService, ReturnEvent history, Admin controller/routes and financial writers are reused; there is no second refund or expense ledger.
- Normal authorization requires the owning Seller's recorded verified receipt (`seller_received_at` and matching `received_by_seller_id`), approved return eligibility, an explicit established payer, amount and justification. Collection must have recorded timestamp and amount; the legacy paid-only fallback remains readable but cannot establish collection for new policy payments.
- Before receipt, or without ordinary return approval, an Admin/Super Admin must explicitly authorize a legal-requirement or separate-approval exception with justification and valid private supporting evidence. No automatic exception approval or fabricated custody event is used. A legal exception can proceed despite a rejected return without changing that rejection or inventing receipt.
- Authorization freezes the payer and amount for that payment cycle, records collection evidence, and does not mark paid or book a platform expense. Existing Admin-established payer decisions cannot be replaced. An uncollected return cannot be casually cancelled after a refund has been authorized; its financial review must be resolved rather than discarded.
- Payment evidence is a separate submission: explicit actual amount/payer matching authorization, reference, recipient, paid date, and mandatory private JPG/PNG/PDF up to 5 MB. File bytes are checked using finfo rather than trusting a filename or MIME fallback. Supporting evidence is not proof of authenticity by itself.
- Confirmation requires an authorized Admin's substantive verification note and the exact latest submission. It rechecks authorization, payer, receipt/exception evidence, collection cap, final-partial reason, current refund revision and existing paid state under the original SellerOrder -> ParcelReturn lock order and accounting transaction. The old HTTP recording route and direct service calls cannot bypass these guards once activated.
- Only confirmation writes `refunded_at`, the existing refund financial fields, and `refund_policy_version=1`. Its refund event links the authorization, evidence submission and Admin verification note. This is **manual Admin evidence review**, not automatic bank settlement verification or initiation of a transfer. No new two-person approval requirement was invented.
- Existing one-active-refund, final-partial decision, history-preserving void/replacement and unique platform-expense protections remain. A void keeps the history and requires a fresh authorization/submission cycle for replacement; stale evidence cannot be reused. Competing confirmations serialize and only one refund/expense is recorded.
- Pending Rider -> Logistics -> Platform COD remains separately owed. No remittance amounts, statuses or collection records are cleared by these stages; no automatic rider earnings deduction is introduced. Current 10%, historical 5% commission snapshots and the two-provider 40/60 allocations remain covered by regressions. Seller debt, Logistics refund charges and platform expense posting continue through existing payer-specific writers.
- Existing Admin forms show awaiting receipt / exception review, authorized payment pending, submitted evidence and confirmed payment separately. Buyer, Seller and Logistics surfaces get safe payment-stage wording through existing return views. Buyer views omit internal verification instructions, payer/account data and private proof. Existing mobile response contracts remain unchanged; privacy and role tests cover Buyer API projections. New evidence-file endpoints and confirmed new-policy proof downloads are restricted to financial Admin roles; Moderators cannot authorize, submit or confirm.

Dependency trace: Admin authenticated routes/financial role checks -> ReturnController -> ParcelReturnService -> ParcelReturn/ParcelReturnEvent and Payment; verified Seller handover -> receipt fields; financial confirmation/void -> SellerSettlementService, SellerDebt, ReturnChargeService and PlatformRefundExpense; pending COD -> CodRemittance; public consumers -> shared return summary, Buyer next steps, Seller/Logistics returns and explicit mobile ShipmentResource projection. Scheduled Seller auto-approval remains a return decision only. No new background payment job or Flutter API write endpoint was introduced.

Graphify 0.9.108 was confirmed with `Get-Command graphify`, `graphify --help` and `graphify --version`. Its documented query/affected capabilities were inspected; the graph was not rebuilt or relied on. Targeted `rg`, PHP source tracing, Git diffs and task-start file snapshots supplied the impact analysis. The parent workflow file referenced by AGENTS remained absent. No Laravel Boost tools were connected. Existing dirty work was preserved; task-local diffs are under `tmp/refund-policy/`.

### 16.2 Explicit activation and historical compatibility

Prepared migration: `database/migrations/pending/2026_11_14_120000_activate_refund_payment_policy.php` adds one nullable unsigned tiny integer column, `parcel_returns.refund_policy_version`. It performs no historical update or backfill. Events already store the authorization/evidence metadata, so no new financial table or redundant status columns are needed.

The pending subdirectory is intentional: ordinary migrations do not silently activate this policy on the live-working-tree site. On a separately approved deployment, the migration must be explicitly included (Laravel migration path `database/migrations/pending`). **It was executed only in isolated SQLite/MySQL tests.** Schema availability gates the prospective workflow; before activation, the existing live workflow remains in place. After activation, every new payment confirmation, including an unpaid older return, requires the new stages. Previously recorded refunds retain their financial values and display as historical rather than retrospectively verified. Existing nullable markers are never inferred to be successful verification.

Read-only recheck of order #41 / return #2: PHP 5,040 refund, reference `5040.00`, recipient present, proof absent; Seller approval exists, pickup recorded, no origin or Seller receipt; collected COD PHP 5,040; remittance still PENDING_REMITTANCE without Logistics/platform receipt. The live activation column is absent. **Flag for authorized manual payment-evidence review.** No void, reversal, fabricated receipt/proof, ledger change or historical reclassification was performed.

### 16.3 Executed verification

| Distinct verification group | Passed | Assertions | Failed / errors / skipped |
|---|---:|---:|---|
| Existing financial, return, COD, payout, earnings, commission/shipping and Buyer API compatibility regressions (pre-activation schema) | 340 | 2,507 | 0 / 0 / 0 |
| Additional Seller-return and Admin ledger/history regressions | 119 | 958 | 0 / 0 / 0 |
| RefundPaymentPolicyTest with activation applied | 30 | 268 | 0 / 0 / 0 |
| Existing physical return journeys with activation applied | 47 | 296 | 0 / 0 / 0 |
| Dedicated MySQL concurrency and historical upgrade | 4 | 32 | 0 / 0 / 0 |
| **Total distinct cases** | **540** | **4,061** | **0 / 0 / 0** |

Execution accounting: the first 363-case batch (2,706 assertions) included an earlier 23-case policy class (199 assertions), leaving 340 existing compatibility cases / 2,507 assertions. A later 192-case batch (1,476 assertions) included 73 policy/active-custody cases (518 assertions), leaving 119 additional cases / 958 assertions. The final policy class passed 30 / 268; active custody passed 47 / 296. Repeated iterations are excluded from the distinct total. Initial implementation-test failures were corrected, including actual file-byte validation; final gates are green.

Existing compatibility inventory: ReturnRefundApprovalPolicyTest, ReturnRefundAccountingTest, PlatformFundedRefundTest, PhysicalReturnTest, ReturnRecoveryTest, CodRemittanceCreationTest, CodRemittanceShortageTest, LogisticsRemittanceTest, SellerPayoutRequestTest, RiderEarningsTest, CommissionShippingIntegrityTest and BuyerCommerceApiTest. Additional classes: SellerReturnDecisionTest, FinancialLedgerIntegrityTest and BuyerReturnHistoryTest.

New active-policy coverage includes all twelve requested areas: no paid state on approval/authorization/submission; normal receipt gate and successful received flow; Admin/Super Admin early and legal exceptions; unauthorized roles; missing/fake/deleted proof and missing review; unchanged pending COD; over-collection rejection and cap recheck; duplicate/stale confirmations; all three payer liabilities, paid settlement preservation and platform expense isolation; readable historical records; Buyer/Seller/Logistics/Admin boundaries and safe public/API projections. Partial/final/void-replacement, frozen payer, superseded/cross-return evidence and cancellation protection are included.

RefundPolicyPhysicalReturnTest inherits and runs the 47 existing actual QR/OTP custody journeys with the activation migration applied, rather than assuming that a generated graph or a receipt flag fixture proves handover behavior. RefundPaymentPolicyConcurrencyTest runs two independent InnoDB HTTP workers for duplicate authorization and full/partial platform confirmation (three cases), plus a historical migration rehearsal (one case). Dedicated schema `zefanya_concurrency_test`, identity/grant checks, schema ownership and runner lock were used. The rehearsal preserves all legacy refund/COD attributes and leaves the verification marker null. Tests cleanly restore the isolated schema; suites were not run concurrently against shared databases.

Targeted Pint and `git diff --check` passed. Vite production compilation passed using `npx vite build --outDir tmp/refund-policy/build`, keeping live public assets/manifest and the dirty lucide bundle untouched. Server-rendered Admin/public forms were exercised by HTTP tests. No fresh browser/mobile visual verification, real transfer, full-suite rerun or statutory-compliance certification is claimed.

### 16.4 Files and remaining gates

| File | Change |
|---|---|
| `app/Models/Logistics/ParcelReturn.php` | Activation check, verified receipt, event-derived stage/authorization/submission, safe wording, frozen payer and obligation/cancellation guards. |
| `app/Services/Logistics/ParcelReturnService.php` | Authorization, evidence submission, confirmed payment checks, byte validation and existing locked accounting/void integration. |
| `app/Http/Controllers/Admin/ReturnController.php` | Validated staged actions, private upload cleanup/access and confirmation. |
| `routes/admin.php` | Staged authorization/evidence/confirmation and scoped private evidence routes. |
| `resources/views/Admin/Returns/index.blade.php` | Policy form selection and legacy review wording. |
| `resources/views/Admin/Returns/_refund-policy.blade.php` | Existing-panel authorization, evidence review and confirmation forms. |
| `resources/views/Components/return-summary.blade.php` | Safe shared refund-stage display. |
| `resources/views/Buyer/Orders/_shipment-next-steps.blade.php` | Buyer-safe payment status without private evidence details. |
| `database/migrations/pending/2026_11_14_120000_activate_refund_payment_policy.php` | Explicit, nullable, no-backfill activation migration. |
| `tests/Feature/Seller/RefundPaymentPolicyTest.php` | Thirty active-policy regression cases. |
| `tests/Feature/Logistics/RefundPolicyPhysicalReturnTest.php` | Forty-seven existing custody cases with activation. |
| `tests/MySql/RefundPaymentPolicyConcurrencyTest.php` | Three races and one historical schema rehearsal. |
| `tests/MySql/worker.php` | Real staged HTTP actions on isolated race workers. |
| This Audit 1.17 and browser audit | Executed results, historical review flag and rollout limits. |

Remaining gates: approved deployment/explicit migration activation, real browser/mobile review of the new states, authorized manual review of #41's external payment evidence, and the previously documented unrelated release blockers. External evidence authenticity remains a human verification responsibility; a reference or uploaded document alone is never labelled automatic bank verification. **Production remains NO-GO; the approved policy implementation is technically verified but not live.**


## 17. Refund custody verification follow-up - 2026-11-14

**FIXED AND TESTED IN ISOLATED STAGING ONLY; NOT DEPLOYED.** The owner's latest instruction supersedes section 16's activation status: a read-only live query confirmed the existing refund policy column is present. It was not migrated or reactivated. This task did not edit the served PHP/Blade/test files. Two served files changed externally during packaging; their origin/Seller hardening and three new tests were preserved in the refreshed staging delivery.

Staging: `C:/ProjectEcommerce/.staging/refund-custody-20261114`. Laravel base path and the loaded ParcelReturn class resolve there; no production `.env` was copied. SQLite uses `:memory:` with explicit testing configuration. PHP vendor files were copied, so autoloading does not resolve application classes back to the live tree. The existing dedicated MySQL harness configuration/ownership marker/runner lock are shared through the `.codex` junction; identity, restricted grants and database checks confine writes to `zefanya_concurrency_test`. Node dependencies are reused, while uploads, compiled views and Vite outputs stay in staging.

### 17.1 Confirmed loopholes and correction

The current live partial check compared event names, two metadata IDs and some ordering. It did not establish the Rider assignment/identity, actual parcel tracking, provider actor, original-station arrival, Seller completion event or intermediate transport acknowledgements. Identical duplicate station events were accepted. Clearing the buyer deadline selected the non-Buyer/origin-only branch, incorrectly described by an old test as a direct return. Ordinary Admins could also confirm paid refunds, whereas this task requires final confirmation by the Super Admin.

Baseline replay in staging against the original model/service: the 21 newly added custody scenarios produced **4 passes, 17 failures, 99 assertions**. The failures demonstrate missing checks, not permission to manipulate live custody. The candidate files were restored immediately after this bounded replay.

The new RefundCustodyVerification service validates persisted evidence rather than a status label:

- The request belongs to this parcel and Buyer. Recorded Seller/system/Admin-overturn approval must precede the latest valid Logistics assignment. Assignment never substitutes for collection.
- The unique collection event must match the assigned Courier/Rider, provider, real collection station, tracking number and recorded collection time. The unique station acknowledgement must match that Rider/parcel/provider/station and follow collection. Duplicate critical events, missing/future timestamps and stale fields fail closed.
- Every actual dispatched return manifest item and cross-provider handoff must have its own valid destination receipt. The chain must connect the collection station to the frozen original Seller station, with matching providers and time order. Handoff request/acceptance/dispatch actors and chronology are checked. There is no fixed station/transfer count; a genuine one-station path needs no invented transfer.
- The origin arrival and owning-Seller completion events must be unique, correctly attributed, ordered after transport and consistent with receipt fields and final recorded hub/provider. A Seller timestamp or completed label alone cannot pass.
- Final normal-path validation runs inside the existing accounting transaction after SellerOrder and ParcelReturn locks. Existing collection, station, manifest and handoff writers also lock that SellerOrder before changing custody. No child-row lock inversion or second financial writer was introduced.
- Existing documented legal/separate-approval exceptions still require authorized justification, supporting proof, immutable payer/amount and payment evidence. They may bypass normal custody; they never synthesize events or bypass caps, final-partial rules, duplicate protection or COD obligations.
- Final payment confirmation requires **Super Admin** server-side, including the low-level refund writer while policy is active. Ordinary Admins can authorize and submit evidence under existing boundaries. One Super Admin may perform every step; no second-Admin requirement or new account-count policy was added.

The Admin panel displays the exact normal-path blocker and the last stage supported by validated custody. Confirmation is disabled when normal evidence is incomplete or the signed-in user is not Super Admin; server enforcement remains decisive. Existing single-person review warnings, actor IDs/times, verification notes and private proof access remain. Public status wording and mobile contracts are unchanged.

### 17.2 Direct-route and historical limits

No existing authoritative direct-to-Seller route classification or authorizing workflow was found in source/schema. `seller_response_due_at IS NULL` describes non-Buyer/system returns; it does not authorize a direct Buyer return. The fix rejects that shortcut. **A new normal direct-return route and its positive end-to-end test remain BLOCKED pending an established authorized workflow; no route status or authorization policy was invented.** The existing documented exception mechanism remains the available authorized remedy when station prerequisites may legitimately be bypassed. Valid one-station Logistics returns pass without transfers, but still require real collection, station and Seller receipt.

Only new confirmation attempts are restricted. Previously completed refunds are not reclassified or rewritten. Read-only live snapshot: order #41 / return #2 still has **PHP 5,040**, refunded_at `2026-11-13 06:35:33 UTC`, no origin/Seller receipt and no proof file. Its existing request/approval/assignment/collection/refund/damage events were left untouched. No missing station acknowledgement, receipt or external payment evidence was fabricated. It remains flagged for authorized manual review. Older unpaid normal authorizations with incomplete evidence also need manual review; the fix does not silently convert them to exceptions.

### 17.3 Executed results

| Verification group | Passed | Assertions | Failed / errors / skipped |
|---|---:|---:|---|
| Existing return, Logistics, COD, payout, earnings, commission/shipping, Buyer API and Admin regressions, including 47 active-policy custody journeys | 459 | 3,465 | 0 / 0 / 0 |
| Final RefundPaymentPolicyTest | 67 | 549 | 0 / 0 / 0 |
| Isolated MySQL race/upgrade cases | 5 | 37 | 0 / 0 / 0 |
| **Distinct final verification** | **531** | **4,051** | **0 / 0 / 0** |

The 519-case broad run passed 3,966 assertions, including an earlier 60-case policy class with 501 assertions. The final 67-case policy run supersedes that class only; repeats are excluded from the total. Coverage includes valid one-station/multi-station/cross-provider normal confirmation, assignment without collection, missing station/Seller/origin evidence, wrong actors/parcel/station/provider, duplicate and stale events, missing intermediate/destination/acceptance acknowledgements, disconnected/out-of-order transfers, false direct classification, Seller/automatic/Admin-overturn approvals, documented exceptions, missing payment proof, payer/cap/COD invariants, role/private-evidence restrictions and historical reads.

MySQL cases: two simultaneous confirmations from the **same Super Admin** for full/partial platform refunds (one refund/expense only); competing authorizations (one accepted); a station-event correction holding the parcel lock while confirmation waits (confirmation rechecks and returns 422 without payment); and an existing historical-schema rehearsal on the dedicated test database. No live migration was rerun. An initial race-fixture error duplicated old fake-ID custody rows after the complete fixture; the obsolete test-only rows were removed and all five cases re-passed.

Additional regression classes: RefundPolicyPhysicalReturnTest, ReturnRefundApprovalPolicyTest, ReturnRefundAccountingTest, PlatformFundedRefundTest, ReturnRecoveryTest, CodRemittanceCreationTest, CodRemittanceShortageTest, LogisticsRemittanceTest, SellerPayoutRequestTest, RiderEarningsTest, CommissionShippingIntegrityTest, BuyerCommerceApiTest, SellerReturnDecisionTest, FinancialLedgerIntegrityTest and BuyerReturnHistoryTest. No full-suite rerun or overlapping suites against a shared database.

Targeted Pint passed; Vite production compilation passed into staging `tmp/custody-build`. Admin blocker/disabled-action rendering and single-Super-Admin flow were verified by HTTP tests. No fresh browser/mobile visual verification or independent bank verification is claimed. Graphify 0.9.108 is installed; actual analysis used `rg`, direct PHP/middleware/route tracing and task-start diffs. No generated graph was used as behavioral proof; the AGENTS-referenced parent workflow file remains absent.

### 17.4 Exact files and delivery

All implementation files below are in the staging copy, not the served tree:

| File | Change |
|---|---|
| `app/Services/Logistics/RefundCustodyVerification.php` | New focused read-only custody validator and confirmed-stage reader. |
| `app/Models/Logistics/ParcelReturn.php` | Delegate custody checks while preserving existing financial/actor helpers. |
| `app/Services/Logistics/ParcelReturnService.php` | Recheck normal custody and Super Admin permission inside final accounting transaction. |
| `resources/views/Admin/Returns/_refund-policy.blade.php` | Exact custody blocker/stage and disabled unavailable confirmation. |
| `tests/Feature/Seller/RefundPaymentPolicyTest.php` | Real identity fixtures and 67 policy/custody cases; replace fake-ID/direct-route assumptions. |
| `tests/MySql/RefundPaymentPolicyConcurrencyTest.php` | Same-Super-Admin duplicate confirmations, complete normal fixture and stale-custody race. |
| `tests/MySql/worker.php` | Test-only locked station-correction action. |

This report and `docs/audits/ZEFANYA_BUYER_SELLER_LOGISTICS_BROWSER_AUDIT.md` were updated as documentation in both copies. Review artifact: staging `tmp/refund-custody-fix.patch` (implementation only; docs already updated). `git apply --check --ignore-space-change --whitespace=error` passed against the untouched served source; no apply was performed. Task-start hashes initially matched. Final packaging detected external edits to ParcelReturn.php and RefundPaymentPolicyTest.php; the original snapshot was retained and the patch was rebased against a separate current snapshot. The three concurrent tests were retained with complete identity fixtures and stronger full-chain expectations. The final policy run includes them. Baseline replay counts refer to the original task-start snapshot, before these external edits. Test logs/JUnit and the baseline replay are in staging `tmp/`.

**Database changes: NONE. No new migration, activation, production write, deployment, commit or push.** Before any deployment proposal, a secure persistent database backup must be evidenced and its access/persistence verified. No such backup was established by the inspected repository metadata; no backup was created or falsely certified here. Remaining gates are backup verification, authorized deployment review, browser/mobile verification, the unsupported normal direct-route workflow and manual historical evidence review. Production activation readiness is **BLOCKED / NO-GO**. The existing refund policy is already active; these custody fixes are staged only.
