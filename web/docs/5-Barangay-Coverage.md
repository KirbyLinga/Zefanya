# Mandatory barangay coverage implementation - 2026-10-21

The existing `areas` table now holds one operational PSGC barangay claim per row. Station Hub physical address fields are unchanged. No Barangay Hub or parallel coverage/assignment system was added.

Audit findings addressed:

- High: provider/municipality uniqueness prevented stations in the same municipality from serving separate barangays (`areas_provider_city_unique`). Replaced by provider/active-barangay uniqueness on a generated column; inactive and legacy rows have a NULL claim key.
- High: `CourierAssignmentService` routed by municipality and then readable-name fallback. New pickup routing uses the seller barangay; delivery uses the immutable shipping snapshot barangay. Explicit riders and destination-area hints cannot override the address or matching station. Available, verified, approved riders must belong to that station and provider and satisfy parcel weight limits.
- High: activation did not require coverage. `LogisticsHubService::activate()` now requires approval, an operational parent and at least one active, validated barangay, revalidated using existing PSGC lookup. Pending/inactive stations can configure coverage before activation.
- High: coverage validation accepted submitted names on PSGC failure. Coverage now fails closed when the barangay or municipality cannot be verified. Names come from PSGC, not request fields.
- High: missing-row locks alone cannot arbitrate concurrent claims. Coverage changes and activation serialize on the existing provider row, and database uniqueness remains the final guard. Rider selection locks the courier as well.
- Medium: checkout omitted the barangay code. New shipping snapshots include it; legacy snapshots never infer it from a changed buyer profile.
- Medium: failed pickup reassignment could release the old courier without a replacement. The old courier is released only after selection succeeds.

Coverage writes validate provider ownership. Multi-selection is atomic. Existing assignments and disabled conflicting claims are visible in the searchable selector. Updates/removal refuse rider dependencies, in-progress station work, deleting parcel history references, and removing the last valid claim from an active station. Existing Rider notifications, POD, COD and status transitions remain on their original services and workflows.

Migration:

`database/migrations/2026_10_21_083944_add_barangay_coverage_to_areas_table.php` adds nullable `barangay_code`, `barangay_name`, `coverage_validated_at`, and virtual `active_barangay_code`, plus `areas_provider_barangay_unique`, and removes `areas_provider_city_unique`.

Apply the migration before serving the new application code (`php artisan migrate`). No application database migration or seeder was run in this task. Existing municipality rows, foreign keys, parcel references and physical hub addresses are retained without guessed coverage. Populate verified barangay claims through Logistics coverage setup. Historical municipality rows may coexist with new claims.

Rollback restores municipality uniqueness only if existing data permits it. It refuses before DDL when multiple barangay rows share one provider/municipality, instead of merging or deleting records. Export new coverage data before rollback: dropping the added columns loses their values. A forward fix is preferable once multiple barangays are configured.

Changed application files:

- `app/Models/Logistics/Area.php`
- `app/Models/SellerOrder.php`
- `app/Services/LogisticsHubService.php`
- `app/Services/CourierAssignmentService.php`
- `app/Services/RiderOperationsService.php`
- `app/Http/Controllers/Buyer/CheckoutController.php`
- `app/Http/Controllers/Logistics/AreaController.php`
- `app/Http/Controllers/Logistics/HubsController.php`
- `app/Http/Controllers/Logistics/ParcelSortingController.php`
- `resources/js/logistics/coverage-lookup.js`
- `resources/views/Logistics/Areas/index.blade.php`
- `resources/views/Logistics/Hubs/index.blade.php`
- The new migration listed above.

Changed test files:

- `tests/Feature/Logistics/BarangayCoverageTest.php` (24 new tests)
- `tests/Support/CreatesAssignableFleet.php`
- `tests/Feature/Admin/HubApprovalTest.php`
- `tests/Feature/FulfillmentLifecycleTest.php`
- `tests/Feature/Logistics/HubsTest.php`
- `tests/Feature/Logistics/HubsUiTest.php`
- `tests/Feature/Logistics/IncomingParcelsTest.php`
- `tests/Feature/Logistics/ParcelSortingTest.php`
- `tests/Feature/Logistics/RiderManagementTest.php`
- `tests/Feature/Rider/RiderTestCase.php`
- `tests/Feature/Seller/CourierAssignmentTest.php`
- `tests/Feature/Seller/CourierPickupMunicipalityTest.php`
- `tests/Feature/TestCouriersSeederTest.php`

Existing regression fixtures were updated to explicit barangay addresses and claims. Activation-before-coverage and silent coverage-transfer expectations now test the requested ordering/conflict behavior. Municipality-only demo seeder records explicitly remain blocked from new assignment. Unrelated existing workspace changes were retained.

Verification:

- Before changes: 996 tests, 969 passed, 7 failures, 19 errors, 1 skipped; 6218 assertions.
- Focused coverage/Hub/fulfillment/Rider/notification/seeder regression run: 191 passed, 1048 assertions.
- Focused coverage and UI run: 29 passed, 133 assertions; includes all 24 new tests.
- Populated legacy-schema upgrade and safe rollback tested on SQLite.
- Actual two-process, two-connection SQLite race produced one committed claim and one unique-constraint rejection.
- `php vendor/bin/pint --dirty --format agent`, its check-only follow-up, `git diff --check`, and `npm run build` passed.
- Final full suite: 1020 tests, 993 passed, 7 failures, 19 errors, 1 skipped; 6282 assertions. The failing/errored test identities match baseline; no new failures remain. Raw final output: `storage/logs/barangay-suite-verified.json`.

Remaining limitations:

- Legacy stations already marked active retain that database flag. They cannot receive new assignments without verified coverage. Future activation and coverage removal enforce the invariant, but the absolute requirement that every historical active flag already have barangays still needs explicit real coverage configuration. Existing assigned parcels retain their continuation paths.
- Automatic approval review rejected a proposed bulk deactivation migration because it would disrupt existing service and had unsafe rollback implications. No such changes were applied. A staged operational decision is required before changing legacy station states; do not invent barangays or blindly deactivate stations with work in progress.
- Generated-column/index and row-lock behavior were exercised on SQLite, not a MySQL/MariaDB staging database. Verify on the deployment engine before rollout, including concurrent activation/removal requests.
- The selector was rendered in HTTP tests and production-built; real-browser interaction was not verified.
- Existing docs describe municipality routing and older test counts. This report records the implemented barangay behavior; the broader documentation was not rewritten.

Baseline failures left unchanged: five guest/login 405 failures, the buyer home-page text expectation, the ambiguous-email Logistics login expectation; eighteen missing `login`/`seller.login` route errors and one missing-GD error. One baseline test remains skipped.
