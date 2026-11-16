# Phase 6 logistics stabilization report

Final status: **INCOMPLETE for overall regression sign-off**. The five required delivery scenarios work in automated SQLite and MySQL verification. One additional expanded-suite seller-note presentation failure remains outside the targeted logistics changes; the suite is not fully green.

## Baseline and results

The original three failing files were rerun before editing: 33 tests, 22 passed, 1 failure, 10 errors. The seven-file broader baseline was 92 tests: 81 passed and the same 11 failures/errors. After the fixes, that seven-file group passed 92/92 (506 assertions).

| Verification | Actual result |
| --- | --- |
| Original Phase 4/4.2/5 focused set | 177/177 passed in the combined regression run |
| Phase 5 exceptions | 42/42 passed |
| CrossProvinceCheckoutTest | 11/11 passed on SQLite and MySQL |
| New lifecycle, unsupported shipping, and persistence rollback | 8/8 passed, 572 assertions, on both SQLite and MySQL |
| Combined expanded SQLite run | 401 tests: 399 passed, 1 failed, 1 skipped; 2,734 assertions |
| Additional Buyer tracking/receipt/lifecycle regressions | 47/47 passed, 246 assertions |
| Distinct SQLite tests across final runs | 449: 447 passed, 1 failed, 1 skipped; overlapping lifecycle reruns counted once |
| Combined dedicated MySQL run | 144/144 passed, 1,319 assertions |
| Added real route-persistence rollback on MySQL | 1/1 passed, 13 assertions |
| Distinct MySQL tests | 145/145 passed; later 8-test lifecycle rerun also passed |
| Scoped Pint | Passed for all 22 changed PHP files |
| Frontend build | Passed: Vite 8.2.2, 45 modules |
| Scoped whitespace checks | Passed |

The SQLite skipped test is `CheckoutStockConcurrencyTest::test_true_concurrent_checkouts_do_not_oversell`, which requires MySQL. Dedicated MySQL checkout races verified last-unit stock, duplicate-token protection, stock/payment integrity, and serializable cart updates. This is not a claim that the skipped SQLite test itself ran.

Evidence: `storage/logs/phase6-logistics-stabilization-sqlite.xml`, `storage/logs/phase6-logistics-stabilization-mysql.xml`, `storage/logs/phase6-logistics-route-rollback-mysql.xml`, and `storage/logs/phase6-logistics-lifecycle-mysql.xml`.

## Five scenarios

| Scenario | Result and persisted evidence |
| --- | --- |
| A: Pila to Pila | PASS. Checkout saves a zero-leg route; seller accepts/prepares; eligible rider pickup and origin receipt are verified; no manifests or handoffs are created; sorting, rider dispatch, buyer OTP, proof/COD, and buyer completion succeed. |
| B: Lumban to Pila | PASS. Explicit ROAD connection, verified origin receipt, dashboard manifest creation/add/dispatch/receiving, final route completion, sorting, eligible destination rider, COD and buyer completion. The scenario also exercises report/review/hold/resume through the existing dashboard endpoints. |
| C: Bulacan to Quezon City | PASS. Approved directional provider link and ROAD connection, origin pickup, destination acceptance, dispatch without custody transfer, verified receipt transferring custody, destination-provider rider and buyer-confirmed delivery. |
| D: Luzon to Mindanao | PASS. Three explicit ROAD/SEA/AIR legs through Bulacan and Davao hubs; no Visayas hub. Each intermediate receipt advances once; the cross-provider SEA handoff requires acceptance and separate scans. Final receipt precedes last-mile eligibility. |
| E: Unsupported | PASS for missing connection, inactive connection, and inactive coverage. Buyer sees unavailability; no orders, seller orders, payments, routes, inventory ledger entries or stock deduction persist; cart remains. |

The real lifecycle test is `tests/Feature/Logistics/EndToEndLogisticsStabilizationTest.php:47`; unsupported checkout is at line 219; injected route-persistence failure is at line 241 (line numbers may move with formatting). Services and database operations are real. Notification delivery and photo storage use Laravel test fakes; no fabricated receiving/OTP records or mocked successful routing are used. The rollback test injects a failing Eloquent persistence event after the actual planner runs.

## Fixes and root causes

- `ParcelHandoffService.php:556`: saved multi-leg handoffs previously selected the buyer's final station instead of the saved current leg's receiving hub. Creation now locks the route, requires verified sending custody at its expected origin, and chooses the pending destination hub owned by the authorized partner. Existing link/acceptance/dispatch/receipt protections and legacy/return behavior remain.
- Migration `2026_11_02_060418_widen_manifest_status_for_receiving_exceptions.php:13`: MySQL rejected the 24-character `RECEIVED_WITH_EXCEPTIONS` value in a 20-character column. Widened to 32 without changing statuses. Rollback refuses to narrow while these receipt records exist.
- `CrossProviderOperationsTest.php`: eight tests referenced obsolete model/service namespaces. Correct imports exposed one stale POD fixture, now using an actual requested buyer delivery code. All eight pass.
- `AutomaticDispatchTest.php:218` and `:296`: already-sorted manifest fixtures lacked pickup evidence (`handover_at`). Added that evidence to the two fixtures without weakening manifest validation. All 20 dispatch tests pass.
- `F2ScanEnforcedLifecycleTest.php:291`: the lifecycle omitted the existing delivery-code request. It now requests through the rider endpoint and supplies the issued code. All five scan-lifecycle tests pass.
- `DeliveryMonitoring/show.blade.php:61`: a payment-bearing shipment detail page called nonexistent `PaymentStatus::label()`, producing HTTP 500. The view now formats the enum value. Payment processing/calculations are unchanged.
- MySQL route snapshot tests now capture persisted JSON before comparison; MySQL normalizes object-key order. Strict comparisons and the saved-route preservation requirements remain.
- `tests/MySql/CheckoutConcurrencyTest.php:194` and `Race.php:106`: an existing cart race watched UPDATE although the request blocks earlier on SELECT FOR UPDATE. The synchronization point now matches that lock, and the harness verifies actual overlapping MySQL contention. Final quantity and stock assertions are unchanged.

## Dashboard verification

Existing buttons/forms and their authorized HTTP actions were exercised without a browser:

- Seller accept/prepare/mark-ready; Logistics pickup assignment; Rider scan/Confirm Collection; Logistics Confirm Station Receipt.
- Manifest creation, **Add**, **Verify parcel**, **Dispatch Manifest**, and destination receiving. These call the existing ManifestService and token verification.
- **Accept handoff**, origin dispatch and destination receipt call ParcelHandoffService; acceptance/dispatch alone do not transfer custody.
- Transport connection create/activate/deactivate and provider isolation remain covered by 25 connection-management tests.
- **Move under review**, **Confirm resolution**, **Preview valid alternative**, and **Confirm authorized reroute** retain the Phase 5 authorization, custody, hold and audit checks. Lifecycle tests exercise hold/resume; Phase 5 tests exercise preview/authorized rerouting.
- Final sorting and delivery assignment retain existing station/coverage/rider requirements; Rider OTP/POD and Buyer **Order Received** complete the lifecycle.

No dashboard redesign, new transfer system, automatic manifest/handoff approval, implicit connection, payment/COD calculation, capacity or address-policy change was introduced.

## Tracking verification

`RouteExecutionService.php:87` provides a public tracking summary from saved routes and actual receipt/custody evidence. Buyer `OrderController.php:180` and Logistics `DeliveryAssignmentsController.php:113` supply the shared `Components/parcel-route-progress.blade.php` component.

Both pages are checked at origin, dispatched transport, intermediate receipt, final receipt, last-mile delivery and completion. Current location distinguishes in-transit/with-rider/delivered states from the last verified hub. The next planned destination is explicitly labeled; it is never substituted for verified location. Origin/destination, outstanding leg/mode, custody provider, actual receiving history and active holds are shown. Buyer pages do not expose internal incident reasons or descriptions. Existing tracking/status histories remain in place. Legacy parcels without saved routes retain their existing display unless a movement hold needs reporting.

## MySQL safety and concurrency

Used the existing `tests/MySql/Preflight.php` harness. It verified the dedicated disposable schema identity, server identity, restricted runner account, schema ownership marker, and denial of access to the application database. No application/development/production schema was used. Existing guarded test cleanup runs only inside that owned disposable schema; transactional cases roll back after each test.

Phase 4/5 migrations plus the new manifest-column correction were applied only there. The same routing, connection, checkout, progression and exception tests ran on InnoDB. `LogisticsConcurrencyTest.php:50` verifies two independent receiving sessions contend on custody locks and produce one receipt/completion. At line 70, concurrent reroute approvals produce one audited revision and preserve custody. Existing MySQL checkout races also pass. Route-persistence failure rolls back order/payment/stock/token state on both engines.

## Remaining issue and limitations

- Expanded SQLite failure: `tests/Feature/Buyer/CheckoutShippingTest.php:273` expects literal `Note: Please &lt;b&gt;gift&lt;/b&gt; wrap` on the seller order list. The unchanged `resources/views/Seller/Orders/index.blade.php:95` uses an expandable buyer-note display. The same test's note persistence, seller isolation, notifications and escaping checks pass before that assertion. This was outside the recorded 11 baseline failures. Neither that test nor the seller list was modified; it is recorded separately, not attributed to Phase 6 and not hidden by weakening coverage. Overall regression sign-off remains INCOMPLETE.
- The new manifest migration must be applied through the normal deployment workflow before using exception receipts on deployed MySQL. Production deployment was not requested or performed.
- Inter-island verification covers two providers and three configured transport legs. Arbitrary intermediate-provider networks without final-destination coverage were not verified; the existing handoff service still requires a serviceable authorized destination partner.
- No manual browser, camera hardware, actual email delivery or live parcel operation was tested. HTTP/service/SQL workflows were automated.

## Files modified in this phase

The workspace already contained unrelated changes. The following list is the Phase 6 change set, not the entire dirty worktree:

- `app/Services/Logistics/ParcelHandoffService.php`
- `app/Services/Logistics/RouteExecutionService.php`
- `app/Http/Controllers/Buyer/OrderController.php`
- `app/Http/Controllers/Logistics/DeliveryAssignmentsController.php`
- `resources/views/Buyer/Orders/show.blade.php`
- `resources/views/Logistics/DeliveryMonitoring/show.blade.php`
- `resources/views/Components/parcel-route-progress.blade.php`
- `database/migrations/2026_11_02_060418_widen_manifest_status_for_receiving_exceptions.php`
- `tests/Feature/Logistics/EndToEndLogisticsStabilizationTest.php`
- `tests/Feature/Logistics/AutomaticDispatchTest.php`
- `tests/Feature/Logistics/CrossProviderOperationsTest.php`
- `tests/Feature/Logistics/F2ScanEnforcedLifecycleTest.php`
- `tests/Feature/Logistics/TransportConnectionManagementTest.php`
- `tests/Feature/Logistics/Phase4MultiLegRoutingTest.php`
- `tests/MySql/UsesLogisticsDatabase.php`
- `tests/MySql/LogisticsStabilizationTest.php`
- `tests/MySql/LogisticsProgressionTest.php`
- `tests/MySql/LogisticsExceptionsTest.php`
- `tests/MySql/LogisticsConnectionsTest.php`
- `tests/MySql/LogisticsRoutingTest.php`
- `tests/MySql/LogisticsCheckoutTest.php`
- `tests/MySql/LogisticsConcurrencyTest.php`
- `tests/MySql/Race.php`
- `tests/MySql/worker.php`
- `tests/MySql/CheckoutConcurrencyTest.php`
- `docs/Phase6-Logistics-Stabilization-Report.md`
