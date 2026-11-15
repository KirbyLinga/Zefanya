# Audit 1.15 follow-up: Station Hub return inventory

Date: 2026-11-13. Scope: Laravel Station Hub inventory and its existing View parcels entry point.

## Result

Confirmed reporting defects were fixed. Station receipt now makes an approved, collected Buyer return appear once in the correct station's Inside count and View parcels list. Collection alone does not. No production custody, transport, refund, earnings, or payment writer was changed.

**Final status: FIXED BUT NOT FULLY TESTED**, because browser visual acceptance and a live legacy-record review remain unverified; all listed final automated checks passed. Existing dirty work was preserved; no commits, pushes, migrations, configuration changes, Flutter changes, or production database writes were made.

## Existing definitions and data sources

Before this change, `HubsController::stationInventory()` supplied all Station Hub tiles on `Logistics/Hubs/index.blade.php`:

| Display | Existing definition and source | Preserved meaning after correction |
|---|---|---|
| Province / Station Hubs | `logistics_hubs`, provider ownership, parent/station relationship; active province listing excludes archived province hubs | Same hub hierarchy and listing |
| Inside | `seller_orders.custody_provider_id` and `custody_hub_id`, restricted to AT_SORTING_CENTER, SORTED, ARRIVED_AT_HUB, ASSIGNED_TO_RIDER, DELIVERY_FAILED | Physically station-held operational parcels; now also verified active returns. Dispatched stock and failed deliveries still held by a Rider are excluded. |
| To sort | Inside shipments in AT_SORTING_CENTER or ARRIVED_AT_HUB | Same outbound sorting classification. Return receipt does not create an outbound sorting task. |
| Exceptions | Distinct inside shipment IDs with `incidents.type` in `ParcelIncidentService::ACTIVE_TYPES`, status OPEN/UNDER_REVIEW or HOLD resolution | Same incident rules, applied to qualifying station-held returns too. No historical incident multiplication or wrong-station attribution. |
| Inbound | Unreceived/unreleased `manifest_items` on DISPATCHED `manifests` to the destination hub, plus IN_TRANSIT `parcel_handoffs` to the receiving hub | Expected arrivals, not physical receipts. Now deduplicated by parcel and scoped to the destination provider/station. |
| Total parcels | Inside + Inbound | Same formula; To sort and Exceptions are subsets, not additional stock. |
| View parcels | All buttons linked to unfiltered `logistics.delivery-monitoring.index`; that list only selected ASSIGNED_TO_RIDER, OUT_FOR_DELIVERY, DELIVERED, DELIVERY_FAILED | Same endpoint with a provider-owned `station` filter and inventory-specific table. The normal delivery list retains its existing behavior. |

The former counters did not query `parcel_returns` or `parcel_return_events`. Consequently, a received Buyer return whose Seller order remained DELIVERED was excluded from Inside. It could appear incidentally in the generic delivery list as an outbound delivery; the list was not the selected station's stock. Reverse manifests/handoffs already contributed to Inbound independent of shipment status. Failed-delivery returns could appear as DELIVERY_FAILED but remained falsely Inside after reverse dispatch because transport intentionally retains sender liability until verified receipt.

`StationInventoryService` now shares the same parcel rows between hub counters and station-filtered View parcels. It reads current shipment custody, return status, collection and station-receipt evidence, active movement records, recovery requirements, and existing incidents. Reads run inside a database transaction. MySQL verification uses the existing isolated InnoDB configuration; no production transaction isolation setting is changed.

## Lifecycle outcomes

| Stage | Origin / collection station | Destination station |
|---|---|---|
| Rider assigned, not collected | Not Inside or Inbound merely because a Rider is assigned | No receipt inferred |
| Rider collected, hand-in pending | Not Inside; custody hub remains empty in Audit 1.15 | No receipt inferred |
| Logistics confirms station receipt | Inside +1, Total +1; one Buyer Return row; no automatic To sort classification | Other stations unchanged |
| Reverse manifest/handoff drafted or accepted | Still Inside until dispatch | Not expected Inbound until dispatch |
| Verified reverse dispatch | Removed from origin Inside even though sender custody fields retain liability | Inbound +1, Inside unchanged at zero |
| Verified destination receipt | No remaining origin stock | Inbound -1, Inside +1, Total unchanged |
| Seller handover prepared | Still active station stock until verified Seller receipt | Existing return workflow applies |
| Verified Seller receipt | Removed from active inventory | Historical return, custody events, and financial history retained |

No new collection, station receipt, transport, Seller receipt, or financial event is emitted by inventory reads.

## Confirmed defects and corrections

| Severity | Defect | Correction |
|---|---|---|
| High | Received Buyer returns were excluded by the outbound status allowlist. | Include current active return custody, requiring collection and an actual station receipt for Requested Buyer returns. |
| High | A failed-delivery return remained counted at its sender after reverse dispatch. | Active transport takes precedence over retained sender custody. The receiving station counts expected Inbound only. |
| Medium | View parcels ignored the selected station and did not identify return movement. | Pass and authorize the station ID, use the shared inventory calculation, and display waybill, Buyer Return/Return type, return status, station or transit state, custody provider, movement endpoints, and the existing return detail link where authorized. |
| Medium | DELIVERY_FAILED with a stale station pointer could be counted Inside before verified Rider hand-back. | Reuse `SellerOrder::isBackAtStationAfterFailure()` for outbound failed deliveries. |

No separate Return Inventory dashboard was added. The new Blade partial is the station-filtered state of the existing View parcels endpoint. It follows the current Logistics setup header, table, theme tokens, pagination, focus styles, and horizontal scrolling conventions. It shows no Buyer name, email, telephone number, full delivery address, refund destination, or Seller street address.

## Duplication, authorization, coverage, and legacy compatibility

- Rows originate from Seller orders, not receipt/assignment/event joins. Collection assignment history and repeated station acknowledgements cannot multiply stock.
- Existing unique receipt events, locks, tracking checks, operational station checks, and provider-account identity remain unchanged.
- Received/released historic manifests do not contribute to expected Inbound. Multiple inconsistent active containers select only the latest departure and display an investigation warning, rather than counting multiple physical parcels or treating transport as station receipt. No history is rewritten.
- Lost, damaged beyond return, completed, and administratively closed returns are excluded from active inventory. Cancelled/rejected Buyer requests do not make delivered shipments physically present. Open qualifying missing/damage incidents on held active parcels use the existing Exceptions rules.
- Cancelled dispatched transfers awaiting recovery are not counted physically Inside; existing recovery scans remain authoritative.
- Inventory services independently constrain requested station IDs to the provider's Station Hubs. The HTTP filter rejects a foreign station ID with 404. Buyers, Sellers, and Riders remain outside authenticated Logistics inventory and receipt routes.
- Audit 1.15's saved Buyer barangay, eligible assigned Rider, operational station, provider, and receipt authorization checks are preserved and exercised by the existing return tests. No employee permission system or automatic dispatch was introduced.
- Legacy Requested Buyer returns with an old custody hub but no receipt are not treated as acknowledged stock. Existing Returns views continue to expose their hand-in requirement. Legacy later-stage station custody and legitimate in-transit returns retain their existing status/history; a new station receipt is not synthesized.

## Financial integrity

The production diff changes inventory reads, controller orchestration, and display only. It does not invoke settlement, commission, payment, COD, remittance, refund, earnings, return-charge, debt, or stock writers. SQLite regression snapshots compare persisted financial and custody table values before and after both inventory GET requests. Existing return lifecycle tests still pass.

Current 10% commission, saved historical 5% snapshots, applicable cross-provider 40/60 shipping splits, Seller/Rider/Logistics earnings, COD collection/remittance, refund and platform-expense records, Seller debts, and return-trip charges are not recalculated by viewing inventory. Ordinary existing lifecycle operations may still perform their established financial effects; those operations were not changed by this follow-up.

## Files modified by this follow-up

1. `app/Services/Logistics/StationInventoryService.php` (new shared read-only inventory calculation).
2. `app/Http/Controllers/Logistics/HubsController.php` (consume shared rows for counters).
3. `app/Http/Controllers/Logistics/DeliveryMonitoringController.php` (authorize and paginate selected station stock).
4. `resources/views/Logistics/Hubs/index.blade.php` (station-specific View parcels URL).
5. `resources/views/Logistics/DeliveryMonitoring/index.blade.php` (station-filtered display state).
6. `resources/views/Logistics/DeliveryMonitoring/_station-inventory.blade.php` (new partial).
7. `tests/Feature/Logistics/StationInventoryTest.php` (new regression coverage).
8. `tests/MySql/PhysicalReturnConcurrencyTest.php` (extend existing concurrency suite).
9. `tests/MySql/worker.php` (isolated test actions and receipt-event barrier).
10. This report.

The MySQL test and worker files already contained uncommitted Audit 1.15 changes; those were extended, not reverted. The frontend build left the pre-existing modified Lucide file byte-for-byte unchanged.

## SQLite verification

All commands explicitly used APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory:, and empty DB_URL.

| Command / suite | Result |
|---|---|
| PhysicalReturnTest + ReturnRecoveryTest | 67 passed, 473 assertions |
| StationInventoryTest (final focused run) | 6 passed, 53 assertions |
| StationInventoryTest + PhysicalReturnTest + ReturnRecoveryTest + ProviderProvinceGovernanceTest + ManualStatusOverrideTest + ParcelIncidentReportingTest + ParcelSortingTest + ParcelScanTest | 142 passed, 908 assertions |

The focused inventory suite verifies assignment before collection, Rider custody pending receipt, duplicate receipt, exact hub counters and link, one return row, no unrelated personal details, draft transport, dispatch, destination receipt, Seller receipt and historical receipt preservation, legacy no-receipt pointers, valid later-stage legacy custody, terminal states, foreign station access, no financial/custody writes during reads, outbound To sort, existing incident exceptions, wrong-station exception exclusion, failed-delivery Rider custody, repeated historical manifests, cancelled-transfer recovery, and Buyer/Seller/Rider access denial.

Initial new fixtures encountered the existing unique order/Seller and generated active-manifest-item constraints. Fixtures were corrected to create independent shipments and released history; no schema or safeguards were weakened. The existing Audit 1.15 return suites had no new failures.

## MySQL verification

Only the existing owned disposable `zefanya_concurrency_test` database was used. Its preflight validates server identity, dedicated restricted account, and schema ownership, and the existing runner lock prevents simultaneous suites. Race workers use independent real connections and record overlap/lock evidence. No production schema or records were touched.

| Exact command | Result |
|---|---|
| `php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml tests/MySql/PhysicalReturnConcurrencyTest.php` (existing baseline before extending) | 3 passed, 39 assertions |
| `php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml tests/MySql/PhysicalReturnConcurrencyTest.php` (final expanded suite) | 7 passed, 72 assertions |

The final run completed without failures, errors, or skips. Earlier incomplete runs reflected the synchronization and transport-fixture corrections described below. The inventory reader observed zero Inside while a separate connection held an uncommitted custody update and station receipt event, then one Inside after commit. Receipt/cancellation and loss/receipt races retained consistent committed counts and at most one station receipt. Reverse dispatch removed sender Inside; verified cross-provider arrival changed destination Inbound to Inside without duplicating the parcel.

Added scenarios: station receipt versus Buyer cancellation, Admin loss resolution versus receipt, reverse handoff rejection before receipt commits, receipt replay versus actual reverse handoff dispatch, cross-provider destination receipt, and inventory reads while the station receipt event and custody update are still uncommitted. The existing suite covers concurrent duplicate receipt, collection versus receipt, assignment replay, return requests, and duplicate Seller receipt.

Initial reverse-handoff synchronization expected a lock, but the existing pre-receipt gate correctly rejected the action before locking. The test now checks that early rejection, then dispatches an accepted handoff with a real manually assigned transport Courier. Its initial vehicle fixture used the wrong enum casing and was corrected to the existing Motorcycle value. These were test setup corrections, not production defects.

## Other checks and remaining limits

- `npm run build`: passed; existing Lucide file hash unchanged.
- Targeted `php vendor/bin/pint --dirty --format agent ...`: passed.
- `git diff --check`: passed.
- Browser desktop/mobile visual review and assistive-technology review were not performed. HTTP rendering and the production frontend build were verified.
- No live production legacy-data census or investigation of individual historical contradictions was performed. Legacy assertions use isolated fixtures; no historical custody was backfilled.
- Concurrency cases exercise named orderings and verified overlap, not every possible scheduler interleaving, every provider chain, or all cancellation/recovery combinations.
- The whole application suite and unrelated known demo-marketplace baseline failures were not rerun. Relevant return, hub governance, parcel tracking/scan/sorting and incident suites were run as listed.

**Final status: FIXED BUT NOT FULLY TESTED.** All listed automated suites pass; the browser and live legacy-data limits above remain explicit.
