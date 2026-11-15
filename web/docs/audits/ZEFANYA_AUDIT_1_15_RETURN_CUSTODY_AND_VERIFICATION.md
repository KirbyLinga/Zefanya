# Audit 1.15 — Return custody and verification

## A. Executive summary

Buyer return pickup now has two independently recorded custody events. The assigned Rider records collection from the Buyer. The custody provider then acknowledges physical hand-in at the designated operational station. Reverse transport requires that acknowledgement. The existing return event table supplies an append-only, unique receipt record, so this change requires no migration or historical backfill.

## B. Before and after

Previously the Rider's collection request wrote the Rider's station directly to `seller_orders.custody_hub_id`. A return could then qualify for reverse transport immediately.

1. Seller, automatic, or authorized Admin approval opens the existing collection workflow. Logistics still assigns a Rider manually.
2. The custody provider selects an eligible Rider. The Rider's operational station must serve the saved Buyer barangay. The existing numbered assignment history is preserved.
3. The assigned Rider submits the matching waybill. The shipment and return are locked. The service records the Rider, Courier, provider, intended station, waybill, and timestamp. The station custody field is empty while the Rider holds the parcel.
4. The custody provider signs in with its existing Logistics account and submits the physical waybill and station. The service checks the current holder, collection evidence, designated station, operational status, and conflicting transport. It writes station custody and one `station_received` event in the same transaction. The event's actor is the authenticated Logistics account. The application has no separate staff identity behind a shared Logistics account.
5. The existing reverse manifest, directed handoff, origin verification, and Seller receipt continue. Return loss and damage remain on the incident path. Refund recording remains independent of physical movement.

### Legacy compatibility

An already-collected return still in Requested must receive a real acknowledgement. Its prior stored station may identify the expected hand-in location when an older collection event lacks station metadata. A legacy draft can dispatch after acknowledgement. A return already in transit or at a later physical stage keeps its existing movement history. No receipt event is synthesized. A contradictory legacy record with dispatched transport while still Requested requires investigation and cannot be acknowledged as a new station hand-in.

## C. Confirmed defects

| Severity | Reproduction and root cause | Correction |
|---|---|---|
| High | Approve a Buyer return, assign the Rider, and confirm collection. The former handler wrote station custody before station acknowledgement. Reverse manifest eligibility treated collection as sufficient. | Split the events and gate manifest selection, creation, dispatch, provider handoff, and origin verification. See `ParcelReturnService::confirmBuyerCollection`, `receiveFromBuyerCollection`, `ParcelReturn::movementBlockedReason`, and `ManifestService::currentHubId`. |
| Medium | Assign an eligible same-provider Rider whose station serves a different barangay. Audit 1.14 enforced provider and station eligibility without matching the Buyer barangay. | Resolve the saved Buyer barangay against operational coverage and require that station at assignment and collection. |
| Test fixture | Open checkout, then post a different freshly issued token. The checkout rejects before route validation, so tests reported missing field errors or missing orders. | Submit the token from the reviewed session wherever the fixture opened checkout. Preserve checkout validation. `CompleteFinancialScenariosTest` inherits the shared fixture. |
| Test fixture | The delivery-assignment alert test expected the focus wrapper itself to carry the ARIA role. The nested shared error flash carries that role. | Assert both the focus wrapper and the nested alert. |

## D. Security and interface verification

- Web tests exercise foreign Riders, wrong waybills, foreign stations, receipt before collection, wrong provider, duplicate collection, duplicate station acknowledgement, reverse controls before acknowledgement, and cross-provider return through Seller receipt.
- SQLite return recovery tests cover missed collection, reassignment, cancellation boundaries, and lost or damaged parcels. Existing assignment audit rollback is retained.
- The isolated MySQL return suite uses separate workers and shipment locks. The race cases and their measured results are recorded below.
- Logistics can acknowledge physical hand-in from the existing return details page. The Rider web pickup page distinguishes assigned, collected, awaiting hand-in, received, reverse movement, failed collection, and terminal return states.
- The existing Rider jobs API exposes scoped return status to the Flutter For Pickup screen. Flutter displays return status and tracking. Collection and failure actions remain on the existing Rider web workflow. No new mobile action endpoint was introduced.
- Remaining dedicated MySQL races include different outbound Riders competing for the same pickup, full Rider delivery versus a separate COD writer, duplicate earnings through full concurrent delivery, origin payout versus destination adjustment, and station receipt versus cancellation, loss, or handoff. Staff-level identity is only as granular as the existing Logistics account model.

## E. Financial integrity

Station hand-in writes custody and its event only. Outbound pickup and successful delivery remain the configured Rider earning events. Return pickup and hand-in have no additional Rider rate in the traced implementation. COD remains Rider → Logistics → Zefanya. No deduction is made from Rider earnings for shortages. Commission stays at 10% for current orders with saved rates including 5% retained. The applicable two-provider split remains 40% origin and 60% destination. Three-or-more provider participation remains amountless under `pending_policy`. Platform refund expense and reversal history remain separate from shipment custody.

## F. Test results

Laravel SQLite commands used PowerShell environment variables `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, and an empty `DB_URL`. MySQL used the owned disposable InnoDB schema with its existing preflight and suite lock. Counts below are per run and overlap.

| Engine | Exact command | Passed | Failed | Errors | Skipped | Assertions |
|---|---|---:|---:|---:|---:|---:|
| SQLite | `php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/Feature/Logistics/PhysicalReturnTest.php tests/Feature/Logistics/ReturnRecoveryTest.php tests/Feature/Buyer/CheckoutCarrierSelectionTest.php` | 78 | 0 | 0 | 0 | 512 |
| SQLite | `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/Feature/Logistics/PhysicalReturnTest.php tests/Feature/Logistics/ReturnRecoveryTest.php tests/Feature/Logistics/DeliveryAssignmentDialogTest.php tests/Feature/Buyer/CheckoutCarrierSelectionTest.php tests/Feature/Rider/RiderMobileApiTest.php tests/Feature/Rider/RiderEarningsTest.php tests/Feature/Rider/RiderProofOfDeliveryTest.php tests/Feature/Order/CommissionShippingIntegrityTest.php tests/Feature/Seller/PlatformFundedRefundTest.php tests/Feature/Logistics/LogisticsRemittanceTest.php tests/Feature/Logistics/CodRemittanceShortageTest.php` | 249 | 0 | 0 | 0 | 1773 |
| SQLite | `php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter='test_station_receipt_requires\|test_logistics_page_offers' tests/Feature/Logistics/PhysicalReturnTest.php` | 2 | 0 | 0 | 0 | 29 |
| MySQL | `php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml tests/MySql/PhysicalReturnConcurrencyTest.php` (first run) | 3 | 0 | 0 | 0 | 39 |
| MySQL | `php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml tests/MySql/PhysicalReturnConcurrencyTest.php` (collection versus receipt, then concurrent duplicate receipt) | 3 | 0 | 0 | 0 | 39 |
| SQLite | `php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/Unit tests/Feature/Rider/RiderMobileApiTest.php` | 44 | 0 | 0 | 0 | 151 |
| SQLite | `php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/Feature/Logistics/PhysicalReturnTest.php tests/Feature/Logistics/ReturnRecoveryTest.php tests/Feature/Logistics/DeliveryAssignmentDialogTest.php` | 71 | 0 | 0 | 0 | 491 |
| SQLite | `php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter=test_a_found_parcel_is_recorded_by_a_verified_scan tests/Feature/Logistics/ParcelLossTest.php` | 1 | 0 | 0 | 0 | 47 |
| Flutter, no database | `flutter test --no-pub test/workbook_rider_phase4_test.dart` | 16 | 0 | 0 | 0 | unreported by runner |
| SQLite | `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/Feature/Logistics/CompleteFinancialScenariosTest.php tests/Feature/Logistics/DeliveryScenarioSeederTest.php tests/Feature/Logistics/EndToEndLogisticsStabilizationTest.php tests/Feature/Logistics/HandoffCancellationTest.php tests/Feature/Logistics/ParcelLossTest.php tests/Feature/Logistics/DeliveryAssignmentDialogTest.php tests/Feature/Logistics/DemoMarketplaceTest.php tests/Feature/Logistics/NinjaExpressLagunaDemoTest.php` (started before alert fixture correction) | 66 | 4 | 0 | 1 | 3666 |
| SQLite | `php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/Feature/Logistics/HandoffCancellationTest.php tests/Feature/Logistics/ParcelLossTest.php` | 21 | 0 | 0 | 0 | 673 |
| SQLite | `php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter='test_legacy_collected_return\|test_same_provider_rider_at\|test_station_receipt_requires\|test_logistics_page_offers' tests/Feature/Logistics/PhysicalReturnTest.php` (initial legacy fixture held a stale model, then rerun after fixing it) | 3 then 4 | 0 then 0 | 1 then 0 | 0 | 33 then 35 |

`npm run build`, targeted Pint, and `git diff --check` completed successfully. The Flutter tests exercise the existing pickup and delivery screens. A dedicated widget assertion for the new return status list was not run.

The broader run's alert failure passed in the subsequent 71-test run. The three other failures match Audit 1.14's demo baseline: `DemoMarketplaceTest::test_marketplace_seeds_accounts_catalog_images_routes_and_supports_delivery_flows`, `DemoMarketplaceTest::test_negative_checkout_blocks_unsupported_destination`, and the inherited marketplace flow in `NinjaExpressLagunaDemoTest`. The demo catalog uses standard Seller publication, which queues new listings. These tests expect active listings and an accessible Buyer product page. The existing Ninja unsupported-destination test skips because the demo seeder covers that barangay. No production approval or coverage behavior was changed.

## G. Changed files

- Backend custody and transport: `app/Models/Logistics/ParcelReturn.php`, `app/Services/Logistics/ParcelReturnService.php`, `app/Services/Logistics/ManifestService.php`, and `app/Services/Logistics/ParcelHandoffService.php`.
- Laravel interfaces: `routes/logistics.php`, `app/Http/Controllers/Logistics/ReturnController.php`, `app/Http/Controllers/Rider/ReturnPickupController.php`, `app/Http/Controllers/Api/Rider/JobController.php`, `resources/views/Logistics/Returns/show.blade.php`, and `resources/views/Rider/ReturnPickups/index.blade.php`.
- Flutter: `lib/data/repositories/courier_repository.dart`, `lib/state/courier_state.dart`, and `lib/screens/courier/pickup_dashboard_screen.dart`.
- Test fixtures and regressions: `CheckoutCarrierSelectionTest.php`, `DeliveryAssignmentDialogTest.php`, `DeliveryScenarioSeederTest.php`, `EndToEndLogisticsStabilizationTest.php`, `HandoffCancellationTest.php`, `ParcelLossTest.php`, `PhysicalReturnTest.php`, `ReturnRecoveryTest.php`, `RiderMobileApiTest.php`, `tests/MySql/PhysicalReturnConcurrencyTest.php`, and `tests/MySql/worker.php`.

## H. Remaining policy decisions

Intermediate-provider revenue percentages, Audit 1.10 pre-completion refund commission recognition and excess shipping subsidies, and Audit 1.13 authorized obligations and installments remain open. If individual station-employee attribution is required, the application needs an approved staff identity and station permission model. Existing provider authentication is the identity recorded by this change.

## I. Final status

**FIXED BUT NOT FULLY TESTED**
