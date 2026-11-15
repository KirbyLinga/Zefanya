# Zefanya Web–Mobile integration and Android release report

Branch `integration/phase11-stable` in ZefanyaWMII. The rehearsal started from `20c9bb1` and used synthetic records on an isolated MySQL 8.0.44 instance. The populated `zefanya` database, production services, and deployment configuration were outside the rehearsal.

## Phase 11.7 — Database compatibility

The branch's 182 regular migrations and the reference ZIP's 103 regular migrations each built on a fresh, separate MySQL schema. The branch's optional Courier migration also applied cleanly when run explicitly on the disposable demo schema. It created the Courier account table with a restricted foreign key to the existing Transfer Courier. The separate refund-policy activation migration was left out of the integration demo schema.

The ZIP has a different migration history and is unsuitable as a direct replacement. It lacks checkout recovery and recipient acknowledgement fields, differs in parcel-loss and financial records, and allows deletion of a manifest to cascade to Courier assignments. The branch populated its reference and synthetic data through the existing Admin, shipping rate, and guarded marketplace seeders. No migration or seeder source was replaced. See [the database upgrade plan](PHASE_11_7_DATABASE_UPGRADE.md) for schema counts, exact differences, and the backup-based upgrade sequence.

## Phase 11.8 — Integration evidence

| Workflow | Evidence on the disposable system |
|---|---|
| Buyer purchase and Seller fulfillment | Buyer API checkout and token recovery persisted a single Seller order and one stock deduction. The Seller Web order view showed the same tracking number. A subsequent voucher checkout persisted exactly ₱10.00 in platform funding, counted one redemption, and deducted stock once. A duplicate submit returned HTTP 409 and recovery returned the committed order. Native tests cover cross-Buyer token isolation and the approved multi-Seller allocation. |
| Seller, Logistics and Rider pickup | Seller Web accepted, packed, and marked the order ready. Logistics Web assigned and dispatched an eligible Rider. Rider API returned the same pickup, and an authorized scan and confirmation recorded collection. Logistics Web submitted the verified station receipt. The parcel reached `SORTED`. |
| Transport Courier | Logistics Web assigned a Transport Courier to a manifest. The Courier API returned that manifest and excluded another Courier's assignment. A further Web HTTP rehearsal added a synthetic parcel, dispatched it with a verified scan, confirmed origin custody remained in place, and recorded one verified destination receipt. The completed trip appeared in Courier history. Native cross-provider tests also cover receipt and replay rejection. |
| Delivery, COD and earnings | Rider API rejected an incorrect COD amount. An OTP-free delivery with a fresh scan, private proof and representative acknowledgement succeeded at the exact ₱1,500.00 obligation. MySQL recorded one proof, COD obligation and earnings entry. Logistics Web showed the remittance. |
| Returns and refunds | The Buyer API granted a photo-backed return request for the delivered shipment. Seller Web approved it and Logistics Web read the same return. Focused native tests cover physical custody, return audit, refund authorization and settlement reconciliation. A full physical return and refund were outside the loopback HTTP sequence. |
| Admin and security | Buyer and Courier bearer tokens were refused on Rider endpoints. Native tests exercise actor ownership, Rider eligibility, Admin approvals and financial/custody restrictions. The existing release route gates governed the mobile client. |

The rehearsal used automated HTTP requests and a separate Flutter transport test over HTTPS against the same Laravel and MySQL records. The actual `LiveAuthRepository`, order and Rider repositories, and Courier transfer repository passed role login, reads, session transitions, and the blocked return-collection check. The harness trusted only its temporary loopback certificate. Browser interaction and physical Android use were outside this run. Server operations exercised directly by the harness retain their existing release restrictions, including Rider pickup confirmation, return submission, and Rider return collection.

## Phase 11.9 — Stabilization

The Seller dashboard returned HTTP 500 after a verified transfer introduced an `ARRIVED_AT_HUB` order into its recent-order list. The shared Seller badge omitted `IN_TRANSIT`, `ARRIVED_AT_HUB`, and `LOST`. It now renders all three states using their existing status labels and colors drawn from the current palette. The Seller dashboard and order list regression exercises the three new cases alongside the old ones.

Additional test-only changes address SQLite assumptions uncovered by MySQL. Failure injection uses the active connection's SQL identifier quoting for checkout stock, image persistence, COD, settlement, provider allocation, and return assignment. The tests retain their exact exception and rollback assertions. JSON coverage checks exact metadata, product dimensions, and persisted address snapshots without depending on MySQL's object key order. The station coverage fixture uses a correctly sized synthetic code and still asserts that coverage editing preserves the station's physical address.

The two pending refund-policy test classes now prepare the optional schema before starting their transactions. MySQL commits DDL implicitly, which discarded savepoints when their setup called `ALTER TABLE` after the transaction had begun. The activation remains explicit in the application.

The wider native suite exposes further portability gaps. Some fixtures put values such as Buyer `active` and `deactivated` or Seller `inactive` into MySQL enums that reject them. The legacy coverage race reads SQLite's `sqlite_master`, and the dedicated checkout race hard-codes a separate test instance identity. The historical barangay rollback query is a separate schema finding. A read-only comparison on disposable MySQL confirmed that a constant-projection predicate detects the conflicting coverage groups without changing records.

## Phase 12 — Android release checks

The Flutter lockfile resolved offline with version changes forbidden. The release configuration selects Live WebZefanya, pins `https://zefanya.shop/api`, disables broad writes and uses the verified-write allowlist. The manifest declares network, camera and location access. Debug and profile permit cleartext for development. The release client uses the protected HTTPS contract, and mobile token storage uses the platform-backed secure-storage implementation. Flutter tests cover guarded API permissions, session renewal and profile isolation, error handling, startup and the role entry points.

The host has Flutter 3.44.0, Dart 3.12.0, Android SDK 36.1.0, a Java 21 JDK, and the Gradle 9.1.0 wrapper. `flutter doctor` reports missing Android command-line tools and could not verify SDK licenses. The installed toolchain still built the 77 MB release APK with the checked-in production API profile in an isolated copy. Android's signing verifier accepted its temporary `Zefanya Local Validation` certificate and APK Signature Scheme v2. The packaged manifest targets API 36, requires API 24, includes internet, camera, and location permissions, and has no development cleartext or debuggable setting. The artifact was neither installed nor distributed.

The repository uses `com.example.zefanya`, version `1.0.0` with version code `1`. Confirm the intended production ID and continuity with any installed or previously distributed builds before signing. A production keystore, device smoke test and release deployment were outside this rehearsal. The build emitted existing Kotlin plugin migration, obsolete Java option and geolocation deprecation warnings.

## Validation results

| Check | Result |
|---|---|
| Prior full Laravel regression baseline | 4,292 passed, 0 failed, 6 skipped. |
| Complete native MySQL coverage | All 4,298 cases accounted for across isolated shards and the resumed classes. The first result for each included case was 4,244 passed, 16 failed, 33 errors, and 5 skipped. |
| Latest native MySQL outcomes | 4,283 passed, 2 failed, 8 errors, and 5 skipped after replacing measured results with focused retests for the same cases. |
| Native MySQL integration | 129 passed, 0 failed. |
| Native Seller and Admin retest | 126 passed, 0 failed. The corrected Web HTTP login and order list also returned 200. |
| Native optional refund-policy and default-policy isolation | 91 passed, 0 failed. The run included a standard-policy refund test after both optional-policy classes. |
| Flutter Live API | 1,039 passed, 0 failed, 3 skipped. |
| Flutter production configuration | Four passed. One separate real-HTTPS Flutter repository test passed against the synthetic MySQL-backed Laravel application. |
| Flutter analysis | 10 existing informational findings and two existing warnings. |
| Laravel Pint | Full check reported 23 existing files. The verification ran in check-only mode. |
| Web production asset build | Passed after the Seller view fix. |
| Android release assembly and signature check | Passed with an isolated temporary validation key. |

The ten remaining native cases are the two historical barangay rollback cases, one SQLite-only barangay concurrency harness, the separately provisioned checkout concurrency harness, three invalid account-status fixtures, one scale-seeder test that expects a non-MySQL connection, and two demo tests that were refused on a general-purpose test schema. The standalone guarded demo seed passed on the correctly named disposable schema. Full native regression status remains failed.

## Changed components and local checkpoint

Commit `5a8533ff93b666e6e45de2edae039a7448a887e4` contains the shared Seller status view, its dashboard/order-list regression, the MySQL fixture and rollback assertions, the pending refund-policy test helper, and the decision log. The complete file list is available from `git show --stat 5a8533f`. The report and database upgrade plan are in the following documentation commit. Application migrations, seeders, API permissions and Flutter source were preserved.

Evidence logs were written under `%TEMP%\zefanya-phase117-a8f93abd`. Key reports include `migration-comparison.log`, `schema-diff.log`, `native-results.log`, `flutter-live.log`, `flutter-loopback-final.log`, `voucher-seller-fixed.log`, `android-release.log`, and `apk-signature.log`. The disposable database server was shut down after testing and its temporary credential files were removed. The APK and logs were excluded from Git.

## Readiness assessment

| Category | Status | Basis |
|---|---|---|
| Database compatibility | BLOCKED for the populated upgrade | Both histories build fresh. The reference is incompatible as a replacement, the historical rollback fails in strict MySQL, and the target ledger and records need a restored-backup rehearsal. |
| Laravel Web and API | FAIL on the full native regression gate | Core HTTP workflows ran successfully after fixing the Seller view. The full native run still exposes legacy migration and harness failures. |
| Flutter Mobile | PASS for automated validation | Full Live API suite, release configuration and real local HTTPS repository checks passed. |
| Shared Web–Mobile integration | PASS for the automated rehearsal | Shared records, verified custody, role boundaries and financial snapshots were checked using loopback HTTP, live Flutter repositories and native MySQL tests. Device behavior still needs an operational smoke test. |
| Android release build | PASS for local assembly and validation signing | The verified signature belongs to the temporary validation certificate. Production signing and device installation are pending. |
| Production deployment readiness | NOT READY | The populated upgrade, secure release signing and operational device smoke test are required. |

## Before any live deployment

1. Confirm the intended release API endpoint and backend configuration. Restore a verified backup into disposable MySQL and rehearse the forward migration path against its actual ledger and constraints.
2. Reconcile inventory, historical financial snapshots, refunds, COD, Seller payouts, loss/receipt history and role access on the restored copy. Review Courier enrollment and the pending refund policy separately.
3. Resolve or account for the remaining native regression failures, then complete the deployment-backup and restoration rehearsal. Obtain explicit authorization before modifying any populated database.
4. Confirm the final Android application ID and configure the approved keystore outside version control. Verify the APK signature and install it on Android. Smoke-test login, session expiry, network loss, camera permissions, scanning, and each enabled Buyer, Rider and Courier workflow against the approved backend.

For the approved signing setup, run from `mobile/`:

```powershell
flutter build apk --release --dart-define-from-file=env.webzefanya.production.json
```
