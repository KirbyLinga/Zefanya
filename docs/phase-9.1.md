# Phase 9.1 release verification

## A. Regression status

| Check | Result |
| --- | --- |
| Laravel full regression before the Phase 9.1 fixes | 3,902 passed, 0 failed, 1 skipped, 27,472 assertions |
| Laravel full regression after the Phase 9.1 fixes | 3,903 passed, 0 failed, 1 skipped, 27,476 assertions |
| Flutter Live API full suite | 1,254 passed, 0 failed, 0 skipped |
| Flutter focused Rider and Courier tests | 94 passed, 0 failed, 0 skipped |
| Static and build checks | Flutter analysis, Dart format on seven affected files, Pint on six affected files, Vite web build and Git whitespace checks passed |

The Laravel full suite used PHP 8.5.9, Laravel 13.35.0, and the repository's in-memory SQLite testing configuration. The skip is the existing native-MySQL checkout stock test. Independent native-MySQL checkout races are documented below. Flutter used controlled Live API responses and the repository's test-only Google client ID.

Four previously recorded Pint findings still reproduce in `ManifestController.php`, `SendLinehaulTripPush.php`, `LinehaulAssignment.php`, and `LinehaulPayTest.php`. They are formatter findings outside the Phase 9.1 patch. The Vite build generated a different tracked Lucide bundle from the current templates. Its working copy was restored to the pre-build version.

Reproduce from the same reviewed source tree:

```powershell
# web/
$env:OPENSSL_CONF = 'C:/PHP/extras/ssl/openssl.cnf'
php -d extension=gd vendor/bin/phpunit
npm run build

# mobile/
flutter test --no-pub --dart-define=USE_LIVE_API=true --dart-define=TEST_EXPECT_LIVE=true --dart-define=zefanya.googleServerClientId=widget-test.apps.googleusercontent.com
flutter analyze --no-pub
```

## B. MySQL concurrency

The existing T05 preflight stopped with `Dotenv\Exception\InvalidPathException`. The checkout lacks `web/.codex/t05.env` and the disposable-schema marker, and the existing MySQL service does not match the pinned server identity. The original guard remains in place. The authorized environment needs `web/.codex/`, the approved credential file, the pinned server on port 3306, and `zefanya_concurrency_runner@localhost` with the prescribed grants limited to `zefanya_concurrency_test`. From `web/`, run `php tests/MySql/run.php --preflight-only`, then `php tests/MySql/run.php` after a successful preflight. The runner owns marker creation for an empty authorized schema.

An independent MySQL 8.0.44 instance ran on loopback port 33167 with a fresh temporary data directory, dedicated schemas, and generated credentials. Its guards checked server UUID, data directory, port, schema, effective account and grants. The feature suite used a separate schema and a DML-only account, with test writes rolled back. Two-process cases held the first transaction open and observed the second worker reach a conflicting lock before releasing it. Each case uses one controlled ordering of the operations.

| Area | Native MySQL evidence |
| --- | --- |
| Checkout | Last unit produced one sale and decrement. Voucher usage limit produced one redemption and one discounted order. Duplicate token produced one order and payment. A separate feature test reconciled the committed order using only its token, without a second placement request. |
| Delivery | Duplicate completion wrote one proof, COD obligation and earning. Failure winning prevented delivery writes. Completion winning prevented reassignment. Suspension winning returned 403 and wrote no proof, COD obligation or earning. |
| COD and refunds | Duplicate cash acknowledgement retained one receipt. Discrepancy review before recovery retained the original short count. Duplicate refund retained one refund and event. Settlement preparation preserved released earnings after refund. A concurrent payout request could not reserve refunded earnings. |
| Parcel custody | Duplicate hub receipt produced one receipt audit. Departure prevented concurrent manifest removal. Courier arrival left the unreceived parcel at the origin. Physical worker receipt blocked premature reassignment and completed recovery defeated a stale reassignment. Partial receipt concurrent with missing-parcel closeout retained the missing parcel's origin custody and closed with exceptions. |
| Courier payouts | Duplicate recording returned the same payment. Two corrections produced one reversal. A competing replacement left one active payment and preserved the original reference and frozen amount. |

This covers 21 observed overlaps, including two for worker recovery, plus a separate sequential lost-checkout-response test. The standard T05 runner remains unverified in this checkout. The separate MySQL feature runs passed 262 tests with 2,284 assertions across checkout, delivery, custody, COD, refunds and payouts. The isolated instance was stopped and its temporary credential files removed after verification. The existing MySQL service remained running.

## C. Migration readiness

Laravel's migration status confirmed exactly five pending migrations on the populated local MySQL database. All five applied successfully to the verified isolated MySQL schemas. The populated schema was inspected read-only.

| Pending migration | Compatibility and deployment impact |
| --- | --- |
| `2026_11_12_000001_add_order_id_to_checkout_tokens.php` | Adds a nullable order FK with null-on-delete and a nullable rejection timestamp. Existing tokens remain valid. The reconciliation endpoint requires the new column and returns 503 without it. Rollback drops reconciliation data. |
| `2026_11_12_000002_add_recipient_acknowledgement_to_delivery_proofs.php` | Adds nullable JSON. Existing proof records remain readable. New OTP-free proof writes include the column and need the migration first. Rollback drops the acknowledgement evidence. |
| `2026_11_12_000003_add_discrepancy_to_cod_remittances.php` | Adds nullable JSON. Existing obligations and cash amounts are preserved. The discrepancy flow checks schema availability. Rollback drops discrepancy evidence. |
| `2026_11_12_000004_create_delivery_disputes.php` | Adds one dispute per seller order, restricted-delete FKs, a nullable provider, and a provider/status index. The existing runtime schema gate controls availability. Rollback drops the case table and its evidence. |
| `2026_11_12_000009_add_courier_payout_corrections.php` | Widens manifest audit action to 40 characters. Legacy payouts default to sequence 1. The composite assignment/sequence uniqueness is installed before replacing single-assignment uniqueness. The correction table has restricted-delete FKs and a unique nullable reversal pointer. The feature checks schema availability. Rollback refuses when correction history exists and retains the widened audit column. |

Before applying locally, confirm `migrate:status` still points to the intended MySQL database, verify the target name and a restorable database backup, and retain a separate copy of private evidence files. Run from `web/` only after explicit approval for that populated database:

```powershell
php artisan migrate:status --database=mysql
php artisan migrate --database=mysql --path=database/migrations/2026_11_12_000001_add_order_id_to_checkout_tokens.php --path=database/migrations/2026_11_12_000002_add_recipient_acknowledgement_to_delivery_proofs.php --path=database/migrations/2026_11_12_000003_add_discrepancy_to_cod_remittances.php --path=database/migrations/2026_11_12_000004_create_delivery_disputes.php --path=database/migrations/2026_11_12_000009_add_courier_payout_corrections.php
php artisan migrate:status --database=mysql
```

For deployment, apply the approved migrations before enabling matching Laravel and Flutter releases. Verify the result of each MySQL DDL migration, because DDL can commit independently. Once live records exist, prefer a forward correction to a rollback that would drop evidence.

## D. Hub delay scheduler

`parcels:check-hub-delays` is registered every 15 minutes in Asia/Manila with overlap protection. The clock comes from verified receipt history. The MySQL feature suite covered 24h warning, 48h Logistics escalation, 120h mandatory review, and 168h Admin assessment. It also covered company ownership, repeat checks, departure ending the old wait, and receipt starting the new wait only for received parcels.

Ran Laravel's `schedule:test --name=parcels:check-hub-delays` twice on the disposable MySQL instance. A 49-hour fixture produced one case with the correct hub, two threshold events, one Logistics notification, one Buyer notification, and a persistent last-success record. The second run produced no duplicates. Failure handling writes a critical log and a last-failure cache entry and notifies the configured Admin roles.

Hostinger cron and notification delivery were not inspected. Deployment needs a custom cron entry every minute that invokes the deployed PHP CLI and absolute path to `artisan schedule:run`, a persistent cache for overlap locks and last-success, and configured alert mail. Hostinger's cron runs in UTC. Laravel evaluates the task timezone. [Laravel scheduler documentation](https://laravel.com/framework/docs/scheduling) · [Hostinger cron setup](https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/)

## E. Financial, custody and authorization safety

The automated checks covered payment-backed refund caps and approval, COD discrepancy history, Rider earnings separate from cash obligations, Seller reservations and debt offsets, payout correction history, receipt-gated custody, partial manifests, and provider, Buyer, Admin and Rider authorization. The suspension race specifically verified that authorization is rechecked under locks before writing delivery proof, COD or earnings. Existing OTP-free proof and account security remain covered by the working-tree suites.

Three source defects were corrected:

- Delivery completion and failed-delivery writes could use Rider authorization cached before a concurrent suspension. Both operations now recheck the Rider and provider under shared locks before locking the parcel.
- MySQL can reorder JSON keys. Comparing sorted hub snapshots prevents unchanged waits from being recorded as updates. The recipient acknowledgement test compares all expected key/value pairs after sorting the keys for the same reason.
- Admin incident queries inherited an event ordering column into an aggregate, causing MySQL `ONLY_FULL_GROUP_BY` errors. The aggregate removes the relationship's ordering before grouping.

## F. Git readiness

Branch `codex/combined-courier-demo` has the reviewed local backend checkpoint `7858dfe`. Its staged Rider service preserved the branch's pre-existing delivery contract. The working-tree OTP-free service, migrations and mobile changes were preserved, and the acknowledgement assertion change remains with the earlier untracked test it depends on.

The full suite verified the complete working tree. The two focused checkpoint tests also passed when loaded with the exact staged Rider service. Earlier checkout reconciliation and OTP-free migrations are still untracked. Rider Flutter changes share files with prior authentication, session-isolation and OTP-free work. A push of the branch alone would omit those dependencies. The backend commit is a usable local checkpoint. A complete release checkpoint requires reviewing and committing the coupled changes together.

A targeted pattern scan of staged additions, committed additions since the remote tip and working diff found no private-key headers, common GitHub/AWS credentials, JWTs or credential-bearing URLs. The untracked `mobile/stuff.zip` contains sensitive-looking filenames and was excluded. Local environment and signing settings, test logs and database exports were excluded. No push, merge or deployment took place.

## G. Remaining blockers

| Classification | Remaining work |
| --- | --- |
| Environment setup | Restore the approved T05 environment if the original runner is required for release. The independent native-MySQL runs already cover the listed Phase 9.1 cases. |
| Migration pending | Apply the five reviewed migrations to populated environments after backup and approval. |
| Deployment configuration | Confirm Hostinger cron, persistent cache and alert delivery on the deployed environment. |
| Release packaging | Review and commit the earlier coupled Flutter, checkout reconciliation and OTP-free changes before publishing a full checkpoint. |
| Physical-device verification | Rehearse real camera/photo capture, credential storage across process death, on-device reconnection and push receipt with the final app and migrated API. |

No outstanding code defect or business-policy decision was established by the checks above. Production readiness remains open pending the release packaging, migrated schema and deployment checks.
