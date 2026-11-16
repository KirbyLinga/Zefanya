# Zefanya Buyer / Seller / Logistics Browser Audit

Date: 2026-11-14 (Asia/Manila). Target: https://zefanya.shop (the live site; it serves this working tree and the local MySQL `Zefanya` database).
Method: connected Chrome (Claude in Chrome), existing test accounts for Buyer, Seller and Logistics. Rider and Admin accounts were not part of this run.

## 1. Executive summary

Every Buyer (25 pages), Seller (24) and Logistics (30) route loaded without a server error, other users' records return 404, and the cross-role dates, COD sums and commission snapshots agree. Five reproducible defects were fixed and verified in the browser; the rest are confirmed and listed below. Overall readiness is **NO-GO for release, GO for a capstone demo** once the "must fix" list in section 12 is closed (remaining shipping, staged custody fixes and historical payment-evidence issues; the dashboard source fix is present and the two checkout fixture failures are resolved in the follow-up below).

Safety rule applied: zefanya.shop is a live site, not an isolated test environment. No orders, returns, payouts, inventory, rider assignments or financial records were created or changed in this run. Anything that needs a write was marked BLOCKED. Earlier in this conversation (separate task, explicitly approved) one test order lifecycle was run; its leftovers (#43 cancelled, #44 out for delivery) still exist.

## 2. Pages and workflows inspected

Browser = loaded and looked at in Chrome. Fetch = loaded through the signed-in browser session and checked for status, errors and obvious defects (not looked at visually).

| Role | Browser (visual) | Fetch only |
|---|---|---|
| Buyer | Home, cart, empty checkout, wishlist, messages, order #41 detail, saved addresses, settings, failed-login state, login redirect, footer | Shop, categories, account, orders, help, policy pages, notifications, password page, orders 1/9999 (404) |
| Seller | Products, order #44 detail, payout requests | Dashboard, notifications, store categories, store sales, promotions, vouchers, orders, shipments, returns, messages, feedback, earnings, reports, shipping providers, account, other-seller order 404s |
| Logistics | Dashboard, hubs/stations, delivery tracking, COD remittances, earnings, returns, riders, manifest #2 | Incoming parcels, sorting, deliveries, exceptions, handoffs, manifests, transfers, scan, notifications, lost parcels, partnerships, couriers, add rider |

## 3. Browser-tested vs source-only

- Browser-tested and fixed: items F1-F5 below.
- Source-only: root cause lines for each finding; refund/COD invariants were read from the database with SELECT queries (no writes).
- Not covered: all mutation workflows (see section 8).

## 4. Critical and high-priority defects

| ID | Severity | Role | Finding | Status |
|---|---|---|---|---|
| H1 | High | Buyer | Purely Femme's catalogue (the cheapest items) shows "Delivery unavailable" yet Checkout/Add to Cart stay clickable. The shop has no shipping provider enabled (data) and the buy box does not disable the buttons. | CONFIRMED (earlier run; not re-tested) |
| H2 | High | Seller | Original browser showed PHP 49,960 versus PHP 39,980. Subsequent source/database verification found the return-aware fix already present: sales PHP 39,980, net PHP 35,982, completed 2, total 7. | SOURCE/DB VERIFIED; browser recheck blocked |
| H3 | High | Logistics/Buyer | Order #41 refund PHP 5,040 matches recorded collected COD with OTP/proof, but remittance is pending and neither station nor seller receipt is recorded. Approved early refunds are currently allowed; external payment has no proof file. | POLICY ACTIVE; CUSTODY FIX STAGED / HISTORICAL REVIEW |
| H4 | High | Logistics | Returns list labelled every return "Return requested", even one still awaiting the seller and one already collected from the buyer. | FIXED (F4) |
| H5 | High | Logistics | Delivery Tracking showed "—" as the seller on every parcel and on the detail page. | FIXED (F1) |

## 5. Bugs fixed and exact files

| ID | Fix | Files |
|---|---|---|
| F1 | Seller name on Delivery Tracking list and detail used a non-existent `name` attribute; now `business_name`. | `resources/views/Logistics/DeliveryMonitoring/index.blade.php:103`, `.../show.blade.php:35`; test in `tests/Feature/Logistics/DeliveryMonitoringTest.php` |
| F2 | Messages popup opened by default on every buyer page and covered the cart totals and Proceed to Checkout. Now opens only if the buyer opened it earlier in the tab. | `resources/views/Buyer/Messages/_floating-host.blade.php:126` |
| F3 | `GET /login` returned a raw 405 page; it now opens the login modal. Footer: removed the "Coming soon" app badges and the duplicate How to Buy link; fixed the garbled button "Assign in Assign deliveries" to "Assign delivery rider". | `routes/web.php`, `resources/views/Layouts/footer.blade.php`, `resources/views/Logistics/ParcelSorting/index.blade.php`, `tests/Feature/Logistics/ParcelSortingTest.php` |
| F5 | Raw framework error pages. The app had no error templates, so a bad link showed a bare "404 Not Found" and a wrong-method request showed the framework's "Oops! An Error Occurred" 405 page. Added branded pages for 401, 403, 404, 405, 419, 429, 500 and 503 (own layout, no session needed, light and dark, Go to home / Go back, plus a link to that panel's dashboard on /seller, /logistics, /rider and /admin URLs). JSON/API responses are unchanged. | `resources/views/errors/layout.blade.php` and `errors/{401,403,404,405,419,429,500,503}.blade.php`; tests in `tests/Feature/Shared/BrowserAuditFixesTest.php` |
| F4 | Logistics return badge now shows the real stage: Awaiting seller decision / Approved needs a pickup rider / pickup rider assigned / Collected from Buyer awaiting station hand-in. New `ParcelReturn::logisticsStageLabel()`; list view also eager-loads `events`. Visibility of undecided returns to Logistics was not changed (policy). | `app/Models/Logistics/ParcelReturn.php`, `resources/views/Logistics/Returns/index.blade.php`, `app/Http/Controllers/Logistics/ReturnController.php` |

Regression tests added: `tests/Feature/Shared/BrowserAuditFixesTest.php` (8 tests) plus the new test in `DeliveryMonitoringTest`. Timezone work done earlier in this conversation (Manila display, `manila()` helper) is documented in the QA report (Z-05).

Browser verification after the fix: Delivery Tracking shows EdGaming; `/login` redirects to the modal; footer shows one Help Center link and no badges; the cart page loads with the popup closed (launcher visible); Returns shows the new labels; `/no-such-page` and `GET /logout` show the branded 404 and 405 pages and `/seller/no-such-page` adds a Seller dashboard link.

## 6. Logic and financial checks (read-only)

| Check | Result |
|---|---|
| Duplicate refunds per return | None (0 duplicates) |
| Refund vs collected amount (#41) | ₱5,040 refund = ₱5,040 collected; equal, not above |
| Commission | #37/#40: 10% snapshots (1000 bps, ₱1,999 each). #41 commission 0 after full refund. #42/#44 not finalized yet. Matches policy |
| Rider COD holding vs Logistics | Rider holds ₱25,160 (#40 + #41); Logistics pending remittances ₱30,200 = + #42 held by the other rider. Consistent |
| Logistics COD summary | Collected ₱50,320, remitted ₱20,120, difference ₱30,200 |
| Cross-user access | Buyer orders 1/9999 and seller orders 1/9999 return 404. Manifests belong to this provider; only one provider has manifests, so cross-provider exposure could not be tested |
| Dispatch of empty batch | Blocked in `ManifestService::dispatch`; Batch #2 is a dispatched empty batch from seed data |

## 7. UI/UX improvements by role

Buyer: cart label "Set of N" stays stale after changing quantity (`Buyer/Cart/_item.blade.php:79-81`); order detail shows "QTY × 1" (`Buyer/Orders/show.blade.php:54`); after "Confirm cancel" the badges stay "Preparing" until reload; failed login shows the same error twice (banner and field).
Seller: `GET /seller/products/{id}` returns a raw 405; "Variation: No variations" placeholder on waybills; Low-stock count (12) is useful but the products table has no low-stock filter.
Logistics: "Destination region 04" is cryptic; Assign then Confirm assignment is two steps (and repeats after a reassign); START CAMERA buttons shown when the page says camera scanning is unavailable.
All: unstyled 404/405 pages (fixed, F5). `GET /seller/products/{id}` now shows the branded 405 page instead of the framework's; a read-only product page does not exist.

## 8. Broken, incomplete or blocked workflows

BLOCKED (would create or change live records, or needs Rider/Admin access): placing a new order, seller accept/pack/request pickup, rider assignment, hub receipt and sorting, station-to-station and province-to-province transfers, delivery and COD remittance, failed delivery, buyer return request, seller approve/reject, refund, product create/edit/delete, stock changes, payout request, voucher use. Routes 3 and 4 (cross-province, inter-island) need two providers; only one provider exists with data, so they are NOT TESTED.
Mobile/tablet layouts: BLOCKED. The browser tool does not change the viewport (it stayed 1920 px) and pages cannot be framed.
Keyboard/accessibility, loading indicators and expired-session behaviour: not tested.

## 9. Cross-role consistency (order #44, #41)

Order number, product, quantity, price (₱3,490 + ₱50), tracking numbers, and Manila timestamps agree across Buyer, Seller, Logistics, Rider and Admin. Existing order numbers keep the stored UTC date (e.g. ORD-20261113 for an order created Nov 14, 12:06 AM Manila); new orders use Manila. The Logistics return badge disagreed with the Buyer and Seller views before F4.

## 10. Regression test results

See section 13.

## 11. Unresolved policy decisions and blockers

1. The owner-approved receipt/exception and payment-evidence policy is implemented and tested, but awaits explicit deployment activation (section 15). Historical #41 remains unchanged and needs authorized manual evidence review. Pending COD remains separately owed; Logistics/platform receipt and external refund payment are unverified.
2. Should Logistics see return requests the seller has not decided yet, or only approved ones? (The page now says "Awaiting seller decision"; visibility unchanged.)
3. Should the cart show "Set of N" at all?
4. Rider and Admin audits are separate; Admin is not covered here.

## 12. Prioritized action plan

| Priority | Role | Issue | What needs changing | Status |
|---|---|---|---|---|
| 1 | Buyer/Seller | Purely Femme cannot ship but Checkout is offered | Enable a provider for seller 11; disable Checkout when undeliverable (`_buybox.blade.php:101`) | CONFIRMED |
| 2 | Seller | Original dashboard discrepancy | Existing return-aware fix present; no duplicate fix required. Source/DB and focused tests verified previously; repeat browser check remains blocked. | SOURCE/DB VERIFIED |
| 3 | Logistics/Buyer | Refund custody and payment evidence | Refund policy active; custody corrections verified only in staging; historical #41 review and backup/deployment gates remain | CUSTODY FIX STAGED |
| 4 | Logistics | Seller shown as "—" | Use `business_name` | FIXED |
| 5 | Buyer | Messages popup covers checkout | Default closed | FIXED |
| 6 | Logistics | Return badge always "Return requested" | Show real stage | FIXED |
| 7 | All | Raw 404/405 pages, `GET /login` | Branded error pages; `/login` redirect | FIXED (F3, F5) |
| 8 | Buyer | Stale "Set of N", stale badges after cancel, "QTY ×" | Small view/JS fixes | CONFIRMED |
| 9 | Logistics | Two-step rider assignment, cryptic region labels | Copy/UX | CONFIRMED |
| 10 | All | Phone-width layouts | Retest with a real emulator | BLOCKED |

**A. Must fix before release:** shipping item 1 and staged custody/historical-evidence item 3, plus remaining release gates. Dashboard fix already exists; the two reported checkout failures were obsolete token fixtures and now pass (section 14).
**B. Should improve before the capstone demo:** 8, 9, mixed-category catalogue (every product is in Electronics and Gadgets), replace the demo return photos and the `Reference: 5040.00` record.
**C. Optional:** low-stock filter on products, branded error pages, keyboard/accessibility pass.
**D. Overall readiness: NO-GO for release; capstone readiness remains conditional on shipping, custody deployment review and historical payment-evidence review. The two checkout fixture failures are resolved.**

## 13. Test results

Focused runs (all passed): `BrowserAuditFixesTest` 4, `DeliveryMonitoringTest` 12, `ParcelSortingTest` + `PhysicalReturnTest` + chat tests 51 (with the new tests).

Error-page run: `tests/Feature/Security`, `Auth`, `Http` and `ExampleTest`: 642 tests, 641 passed, 0 failed, 1 skipped, 4,316 assertions.

Neighbouring regression run: Logistics (all except the two network-dependent demo seeders), `tests/Feature/Shared`, `tests/Feature/Admin`, Buyer and Seller chat: **1,358 tests, 1,355 passed, 0 failed, 0 errors, 3 skipped, 11,679 assertions** (6.6 minutes). No failures were introduced by these fixes.

Historical full-run failures not re-run in this follow-up: `SellerOrderDeclineTest::test_order_list_shows_only_the_important_columns`, `DemoMarketplaceTest`, `NinjaExpressLagunaDemoTest` (PSGC lookup unavailable) and `EdGamingCatalogSeederTest` (missing seed photos). SellerVacationModeTest and PriceResolverTest were independently reproduced and corrected in section 14. Historical full suite: 4,163 tests, 4,126 passed, 30 failed, 2 errors, 5 skipped (before these fixes; approximately 20 failures re-passed alone). This is not a current full-suite result.

## 14. Checkout and refund/COD follow-up - 2026-11-14

Both reported checkout failures were obsolete fixtures: they reviewed one checkout token then posted a newly issued token. Production correctly rejected the mismatch. Tests now submit the actual reviewed token; a new negative test verifies a replacement token cannot create order items, alter cart/stock or consume either token. Production checkout, inventory, session/fingerprint validation and MySQL snapshot fix were unchanged.

Executed sequentially on isolated databases: both affected classes initially **49 passed / 298 assertions**, followed by a post-format run including checkout review guards and Manila date-display checks **68 passed / 367 assertions**; neighboring checkout/API/security, commission/shipping, COD, returns/refunds, payout and rider earnings classes **383 passed / 2,833 assertions**; real MySQL stale-quote, duplicate refund/debt and duplicate handover races **5 passed / 73 assertions**. Final distinct-case total (excluding the repeated 49-case run) **456 passed, 3,273 assertions; zero failures, errors or skips**. Initial independent reproductions were Vacation 21/22 passed (1 failure, 172 assertions), PriceResolver 25/26 passed (1 error, 110 assertions). No full-suite rerun.

Read-only order #41 / return #2 inspection: COD PHP 5,040 recorded paid with matching OTP/proof; remittance #9 pending with no Logistics/platform receipt. Approved seller-fault return was collected by Rider #3 but has no station-received event or Seller receipt. Admin #2 recorded PHP 5,040 refund on Nov 13 at 14:35:33 Manila, reference `5040.00`, recipient present, proof absent. Damage incident #2 remains unresolved. Current rules permit this approved refund; actual external payment is unverified. No duplicate/over-cap refund or cleared COD obligation was found. No records were changed. Seller-receipt, documented early exceptions and mandatory verifiable proof require owner policy decisions.

Full timeline, payer/deferred liability reconciliation, all ten proposed rules, test inventory and limitations: [Audit 1.17 section 15](ZEFANYA_AUDIT_1_17_FINAL_END_TO_END_SYSTEM_INTEGRITY.md#15-checkout-regression-and-refundcod-follow-up--2026-11-14). This follow-up used source tracing, Git diffs and isolated tests; no new live-browser verification or mutation occurred. Historical browser observations above remain historical evidence, not a fresh browser result.

## 15. Approved refund-policy implementation - 2026-11-14

The owner-approved controls are prepared: separate authorization, payment-evidence submission and confirmed payment; verified owning-Seller receipt or documented Admin/Super Admin exception with supporting evidence; recorded collected-COD cap; explicit frozen payer; mandatory private payment proof and Admin verification note; locked stale/duplicate protection. Moderator and other-role bypasses are rejected. Existing refund/void accounting, final partial decisions, 5%/10% snapshots and 40/60 shipping allocations are preserved. Refund confirmation never clears pending COD or deducts rider earnings.

**Not active on production:** the nullable, no-backfill `refund_policy_version` column is prepared in `database/migrations/pending/2026_11_14_120000_activate_refund_payment_policy.php`. Explicit deployment activation is required; no live migration or data write was performed. Existing paid records remain historical, with an Admin manual-review flag rather than fabricated verification. #41 still has PHP 5,040 recorded refunded, no proof or Seller receipt, and pending remittance; it was investigated read-only and left unchanged.

Final distinct verification: **540 passed, 4,061 assertions; zero failures, errors or skips**. This comprises 459 existing compatibility/financial/API cases (3,465 assertions), 30 active-policy cases (268), 47 active custody journeys (296) and four isolated MySQL race/upgrade cases (32). Counts exclude repeated iterations. Vite compiled successfully to an isolated output directory; focused formatting/diff checks passed. New states were verified through HTTP-rendered views, not a new live-browser run. Manual Admin evidence review is distinguished from automatic bank verification.

Files, policy controls, dependency trace, test accounting and rollout gates: [Audit 1.17 section 16](ZEFANYA_AUDIT_1_17_FINAL_END_TO_END_SYSTEM_INTEGRITY.md#16-owner-approved-refund-authorization-and-payment-verification---2026-11-14). Production readiness remains NO-GO pending activation, browser/mobile review, #41 manual evidence review and existing unrelated release blockers. Section 14 describes the earlier pre-policy investigation; its owner-decision classification is superseded by this approved implementation, while its historical payment-evidence limitations remain.


## 16. Refund custody verification - 2026-11-14

The refund policy is already active (read-only live schema check); its migration/activation was not repeated. The custody corrections below are **staging only, not deployed**. This task did not edit served implementation files. External origin/Seller hardening changes and three new tests appeared during packaging; the staging delivery was refreshed to preserve them.

Confirmed gaps in the partial check: no assignment/actor/parcel validation, missing origin/Seller event proof, unverified intermediate transfers, tolerance of duplicate station events and a false direct-return shortcut. Final confirmation also allowed an ordinary Admin. The staged fix validates the unique chronological request/approval/assignment/collection/station/transport/origin/Seller chain against actual records and provider/station/parcel identities, then rechecks it under the existing refund transaction locks. Final confirmation requires Super Admin; one Super Admin may handle every step. Existing documented exceptions, payment proof, payer/cap rules and separate COD obligations remain intact.

The Admin form displays the exact blocker and confirmed custody stage and disables unavailable confirmation. No normal direct-to-Seller authorizing workflow was found; it is not invented from a missing Buyer deadline. False classification is rejected, valid one-station Logistics returns need no transfers, and documented exceptions remain supported. A positive normal direct-route journey is BLOCKED until such a workflow is established.

Executed in isolation: **531 distinct cases passed, 4,051 assertions; zero final failures/errors/skips**, including 67 policy/custody cases, 47 active custody journeys and five MySQL race/upgrade cases. Baseline replay of 21 new scenarios against the copied original model/service found 17 failures, confirming gaps. Staging Vite build, targeted Pint, HTTP-rendered blocker/disabled-action checks and read-only patch applicability checks passed. No new browser/mobile visual run or live mutation occurred.

Order #41 remains untouched: PHP 5,040 historical refund, missing origin/Seller receipt and payment proof; authorized manual review is still required. No new migration or financial balance change. Persistent secure database backup verification remains unestablished, so deployment/activation readiness is BLOCKED. Exact staging paths, files, dependency trace, test accounting and limitations are in [Audit 1.17 section 17](ZEFANYA_AUDIT_1_17_FINAL_END_TO_END_SYSTEM_INTEGRITY.md#17-refund-custody-verification-follow-up---2026-11-14). Earlier activation-pending statements in sections 14-15 are historical and superseded by this live read-only check; the custody patch itself has not been deployed.
