# Final JavaScript and MySQL verification

Automated project regression sign-off: **NOT ACHIEVED**. Variant selection is corrected and all 17 MySQL test classes pass. Full Laravel has no failing/error test cases, but PHPUnit returns exit code 1 with unrelated data-provider warnings. Full Pint also fails on two untouched API files. No production-readiness claim is made.

## Git safety and files modified

Before editing: branch `feature/sanctum-api-auth`; 921 working-tree entries (402 modified, 151 deleted, 368 untracked). Existing changes were preserved. No reset, clean, checkout, merge, rebase, discard, commit or push was performed. The branch remains the same.

This task changed only:

- `resources/js/buyer/product-show.js`
- `tests/js/product-availability.test.mjs`
- `docs/Final-JavaScript-MySQL-Verification.md` (this report)

Verification artifacts are under `storage/logs/final-js-mysql-*`; Vite build outputs were regenerated normally. No PHP source, MySQL test code, runner configuration, logistics rules, checkout rules, financial rules, dependency or production configuration was edited.

## JavaScript findings and fixes

The original variant file reproduced 6 tests: 3 passed and 3 failed.

1. Availability ignored the server's `data-selection-message`; purchase-button disabling explicitly excluded incomplete selections. The picker now respects the configured prompt and disables both purchase actions until a valid complete, available selection exists (`product-show.js:119`, `:201`). Stock quantity is not shown before completion.
2. The price test supplied numeric `299`/`349`, while `ProductController::variantPricing()` supplies already-formatted `ResolvedPrice` strings, including running-sale price, regular price and badge. The test fixture now matches that demonstrated server contract; the expected currency assertion remains. Browser code still displays the server strings unchanged and performs no sale/price calculation.
3. Empty selected stock displayed `Sold Out`; it now displays the required `Out of Stock`. Both purchase buttons and the quantity controls are disabled, quantity is zero and the hidden variant ID is cleared.

Variant ID updates no longer depend on the presence of the price element. Purchase handlers normalize/clamp the current typed quantity before submitting and retain vacation/busy guards. They submit the exact selected variant ID rather than trusting an edited hidden field. Changing size/color still resolves exact combinations; changing an earlier axis clears dependent choices.

The existing six tests remain. Three added tests verify actual handler payloads, stale hidden-ID rejection, quantity clamping, partial/zero-stock refusal, and unchanged server-resolved sale text (`product-availability.test.mjs:130`, `:151`, `:164`). The original incomplete-selection assertion is strengthened to check both buttons and the empty variant ID after partial selection.

## Exact verification results

| Suite/check | Passed | Failed | Errors | Skipped | Notes |
| --- | ---: | ---: | ---: | ---: | --- |
| Focused variant JavaScript | 9 | 0 | 0 | 0 | Original three failures resolved; none removed/skipped |
| Full JavaScript | 63 | 0 | 0 | 0 | All discovered `.test.mjs` and `CoverageLookupTest.cjs` files |
| Full Laravel | 3,381 | 0 | 0 | 1 | 3,382 cases; 21,573 assertions; command exit 1 with discovery warnings |
| MySQL A: configured runner | 19 | 0 | 0 | 0 | 339 assertions |
| MySQL B: omitted self-locking/explicit classes | 158 | 0 | 0 | 0 | 1,409 assertions |
| MySQL C: payouts and return-completion | 7 | 0 | 0 | 0 | 104 assertions |
| MySQL total | 184 | 0 | 0 | 0 | All 17 classes; 1,852 assertions |
| Full Pint | - | 2 files | - | - | Read-only formatting check; unrelated API files left unchanged |
| Frontend build | Passed | 0 | - | - | Vite 8.2.2; 45 modules; final rebuild passed |
| Scoped whitespace check | Passed | 0 | - | - | Only changed source/test/report paths |

The full Laravel skipped case is `CheckoutStockConcurrencyTest::test_true_concurrent_checkouts_do_not_oversell` (requires MySQL). Dedicated MySQL inventory/checkout races ran and passed; this does not claim the skipped SQLite case itself executed.

An additional four-case diagnostic rerun passed 4/4 assertions-wise but reproduced the same discovery-time exit code 1. It is not added to full-suite totals.

## MySQL coverage matrix

The current directory contains **27 PHP files: 17 test classes and 10 helpers/workers**. All were inspected for runner membership, database safety, cleanup, locking and worker requirements. `phpunit.mysql.xml` includes only the first four classes below. Helpers and worker entrypoints must not be added as PHPUnit test files.

| Test file in `tests/MySql` | Cases passed | Execution | Coverage and runner status |
| --- | ---: | --- | --- |
| `CheckoutConcurrencyTest.php` | 12 | A: configured; outer lock | Checkout, token idempotency, cart serialization and stock. Already in default runner. |
| `SellerVacationConcurrencyTest.php` | 3 | A: configured; outer lock | Vacation versus checkout/cart locks. Already in default runner. |
| `InventoryOversellingConcurrencyTest.php` | 2 | A: configured; outer lock | Final SKU unit; stock cannot oversell. Already in default runner. |
| `LowStockAlertConcurrencyTest.php` | 2 | A: configured; outer lock | Independent and duplicate alert crossings. Already in default runner. |
| `LogisticsStabilizationTest.php` | 8 | B: explicit files | Real local/intra/inter-province/inter-island delivery, unsupported checkout and rollback. Omitted from default runner; executed explicitly. |
| `LogisticsProgressionTest.php` | 20 | B: explicit files | Manifest/handoff receiving, custody and automatic progression. Omitted from default runner; executed explicitly. |
| `LogisticsExceptionsTest.php` | 42 | B: explicit files | Holds, authorized recovery/rerouting and audits. Omitted from default runner; executed explicitly. |
| `LogisticsConnectionsTest.php` | 24 | B: explicit files | Connection modes, authorization, direction and activation. Omitted from default runner; executed explicitly. |
| `LogisticsRoutingTest.php` | 26 | B: explicit files | Saved multi-leg routes and verified execution. Omitted from default runner; executed explicitly. |
| `LogisticsCheckoutTest.php` | 11 | B: explicit files | Cross-province checkout and explicit connectivity. Omitted from default runner; executed explicitly. |
| `LogisticsConcurrencyTest.php` | 2 | B: explicit files | Concurrent receipt/custody and reroute approvals. Omitted from default runner; executed explicitly. |
| `AutomaticDispatchConcurrencyTest.php` | 7 | B: explicit files | Rider capacity, assignment and duplicate dispatch concurrency. Omitted from default runner; executed explicitly. |
| `PhysicalReturnConcurrencyTest.php` | 2 | B: explicit files | Return requests, seller receipt and one-time restoration. Omitted from default runner; executed explicitly. |
| `SellerRegistrationDecisionConcurrencyTest.php` | 4 | B: explicit files | Competing seller approval/rejection decisions. Omitted from default runner; executed explicitly. |
| `RegistrationEmailConcurrencyTest.php` | 12 | B: explicit files | Cross-role email claims and case-insensitive unique indexes. Omitted from default runner; executed explicitly. |
| `SellerPayoutConcurrencyTest.php` | 5 | C: outer lock | Payout reservation and settlement concurrency. Omitted from default runner; executed explicitly. |
| `Rule05ReturnCompletionConcurrencyTest.php` | 2 | C: outer lock | Return versus automatic completion race. Omitted from default runner; executed explicitly. |

### Runner and worker details

- Group A used the existing `php tests/MySql/run.php --no-progress`. The runner acquires the existing dedicated lock, validates Preflight and launches its four configured classes.
- Group B used `php vendor/bin/phpunit --configuration phpunit.mysql.xml` with the 11 explicit filenames listed in the matrix, plus `--log-junit storage/logs/final-js-mysql-additional.xml`. This avoids an outer-lock conflict with the logistics/dispatch/physical-return classes and registration races, which acquire their own existing locks. Classes and fixture cleanup ran serially; database suites were not launched concurrently.
- Group C launched `SellerPayoutConcurrencyTest.php` and `Rule05ReturnCompletionConcurrencyTest.php` with the same MySQL configuration, inside a PHP `Process` invocation holding the existing `.codex/t05-runner.lock` exclusively. These classes do not acquire it themselves. Preflight ran first; the parent released the lock in `finally`. No runner file was added or changed.

The ten supporting files are:

| Supporting file | Role |
| --- | --- |
| `bootstrap.php` | Restricted MySQL connection/identity preflight before discovery |
| `Preflight.php` | Dedicated schema/account/server identity, ownership and InnoDB checks; guarded disposable-schema preparation/cleanup |
| `run.php` | Outer-lock runner for the four configured classes |
| `Race.php` | Independent worker processes, synchronization barriers and observed MySQL lock contention |
| `worker.php` | Existing checkout/stock/vacation, dispatch, custody/reroute, return, payout and settlement worker actions |
| `CheckoutShippingFixture.php` | Worker-local shipping/PSGC fixtures; prevents stray requests |
| `UsesLogisticsDatabase.php` | Logistics test adapter, class lock and transactional isolation |
| `registration-email-worker.php` | Competing cross-role registration claims |
| `seller-registration-worker.php` | Competing seller registration decisions |
| `rule05-worker.php` | Controlled clock and locked return/automatic-completion race |

The specialized workers were invoked by their tests, not directly as test suites. The seven logistics classes reuse the real Phase 4/4.2/5 and lifecycle tests on MySQL, covering manifests, handoffs, custody, route persistence, exception rollback, rider eligibility and assignment.

### Database safety and standalone script

Preflight passed against the existing owned disposable `zefanya_concurrency_test` database. It checks the pinned server identity, restricted runner account, exact grants and denial of access to the application database. Only that dedicated schema was used for MySQL test preparation/cleanup and fixture writes. No production or populated development database was used. No credentials, `.env` or production configuration were changed.

`tests/Support/run-barangay-mysql.php` is a separate standalone staging script. It targets port **13327**, root authentication and `barangay_stage_*` schemas rather than the authorized dedicated test database on port **3306**. It was **not executed**: doing so would violate this task's dedicated-database restriction. This is a blocked standalone staging script, not a skipped PHPUnit case, and does not reduce the 17/17 class coverage above. No alternative server/schema was created or reconfigured.

## Remaining sign-off blockers

1. **PHPUnit discovery warnings / nonzero exit.** The full Laravel JUnit report has zero failures/errors, yet the command exits 1. A diagnostic run with `--display-all-issues --log-events-text` identifies four warnings from `BuyerAddressBookTest::test_buy_addr_009_someone_elses_id_looks_the_same_as_one_that_does_not_exist`: `addressRoutes` supplies three arguments to a method accepting two (datasets: edit, edit with invalid fields, set as default, delete). The unrelated test was not changed. Evidence: `storage/logs/final-js-mysql-runner-events.txt:288`.
2. **Full Pint fails on two untouched API files:** `app/Http/Controllers/Api/Buyer/ProfileController.php` and `app/Http/Middleware/Traits/ApiResponses.php`. The checker reports import/spacing formatting rules. These are outside the product JavaScript/MySQL verification change set and were not reformatted. Evidence: `storage/logs/final-js-mysql-pint.json`.
3. **Standalone alternate-server staging script** remains blocked as explained above. All applicable dedicated-MySQL PHPUnit classes executed without skips.

The earlier two checkout failures were not reproduced as failing cases in this full Laravel run. No checkout or financial changes were made to obtain the current counts; a fully green process exit is still not claimed.

Default runner coverage remains four classes. Future verification must include the explicit B/C groups; simply rerunning `tests/MySql/run.php` does not cover the other thirteen classes. No claim is made that the default runner configuration was expanded.

## Evidence

- `storage/logs/final-js-mysql-laravel.xml`
- `storage/logs/final-js-mysql-javascript.txt`
- `storage/logs/final-js-mysql-discovery.xml`
- `storage/logs/final-js-mysql-additional.xml`
- `storage/logs/final-js-mysql-payout-return.xml`
- `storage/logs/final-js-mysql-runner-events.txt`
- `storage/logs/final-js-mysql-pint.json`
- `storage/logs/final-js-mysql-build.txt`

No manual browser testing, automatic commit/push, production deployment, or production-readiness certification was performed.
