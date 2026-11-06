# P0 regression baseline

Verified on 2026-10-27 (Asia/Singapore) against the current working tree, including the pending COD and P0 fixes. This is a test baseline, not a clean Git working tree or a production verification.

## Results

| Check | Actual result |
| --- | --- |
| `php artisan test --log-junit storage/logs/p0-regression.xml` | 1,536 tests: 1,535 passed, 1 skipped, 0 failures/errors; 10,129 assertions; 143.603 seconds |
| `node --test tests/js/buyer-search.test.mjs tests/js/category-controls.test.mjs tests/js/product-availability.test.mjs` | 24 passed, 0 failed |
| `php vendor/bin/pint --dirty --format agent` | Passed |
| `npm run build` | Passed |
| `git diff --check` | Passed |

PHP CLI is 8.5.9 with GD and SQLite loaded. PHPUnit uses an in-memory SQLite database. The skipped test is `CheckoutStockConcurrencyTest::test_true_concurrent_checkouts_do_not_oversell`: it requires MySQL because SQLite does not implement the row locks being tested. The separate `phpunit.mysql.xml` concurrency suite was not run.

The JUnit report is `storage/logs/p0-regression.xml` (local generated artifact).

## Findings and fixes

- **P0-07:** Order status graph and actor-map tests already pass. The System actor permits only Delivered to Completed; the older baseline expecting no System transitions is stale. No transition changes were necessary.
- **P0-08:** GD is already enabled in the active CLI runtime. Four stock-ledger tests instead failed because their requests omitted the mandatory product/variant weight and unit fields. Updated only their request fixtures to satisfy the current validation contract; retained all ledger and stock assertions. All four pass.
- **P0-09:** Reproduced a full-suite fatal memory exhaustion at PHP's 128 MB limit. Older logs show the same cause at several different test locations. Added a PHPUnit-only 512 MB limit in `phpunit.xml`; the full rerun completed without a crash. System PHP configuration was not changed. The evidence identifies the immediate resource limit, not a proven underlying memory leak.
- **P0-10:** No pending account-controller changes appear in Git. The Admin controller calls session/remember-token revocation after a successful status action. Existing account-action, account-session and buyer-account regression tests pass; no controller rewrite was necessary.
- **P0-11:** `app/Services/ParcelReturnService.php.bak` and `app/Http/Controllers/Seller/OrderController.php.bak` have no references in the searched application, routes, templates, tests or package configuration. They are cleanup candidates and were not deleted. Existing `diff-*.txt` and older baseline artifacts were left intact.
- **P0-11A:** Active checkout saves `PaymentMethod::Cod` directly, and the payment-method enum contains only COD. No active card, GCash, bank-transfer or gateway references were found in application code, templates, routes, configuration or database source, excluding backups. The Logistics delivery-assignment view still has an unreachable non-COD display branch. Existing database rows with historical non-COD values were not inspected or converted; those values would fail the enum cast and require a deployment data decision.
- **P0-12:** The configured Unit and Feature suites have no failures or errors. The remaining SQLite concurrency skip is explicit above; MySQL concurrency and production data compatibility remain unverified.

The old `baseline-full.txt` recorded 1,456 tests, 2 errors, 23 failures and 1 skip. Test counts have since changed, so this is a historical comparison rather than a like-for-like run.
