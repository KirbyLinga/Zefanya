# Phase 12 MySQL stabilization and database upgrade report

Project ZefanyaWMII, branch `integration/phase11-stable`, starting at `a868b2c`. The stabilization changes are in `19eb043`. The schema under review was the local populated `zefanya` on MySQL 8.0.44, loopback port 3306. Inspection ran in a read-only transaction and queried only schema metadata and migration names. Migration tests used a separate disposable server on port 33182.

## Native regression corrections

| Original cases | Root cause | Correction |
|---|---|---|
| Two barangay rollback cases | The generated `EXISTS` subquery selected all columns while grouping by provider and municipality. MySQL strict mode rejected the projection. | Select a constant. Preserve the refusal when more than one barangay shares a municipality and the round-trip checks for existing row IDs and coverage. |
| Barangay race | The test used `sqlite_master` even when the default connection was MySQL. | Retain the SQLite path and run the current InnoDB unique index with two verified workers on MySQL. Observe the overlapping insert and require one success and one duplicate-key rejection. |
| Checkout stock race | The T05 runner expected a fixed server identity and a local credential file. Its configuration also listed two absent test files. | Accept an explicitly configured disposable loopback server and verify its UUID, its separate port, the dedicated schema and account, the exact grants and denial of application-schema access. Retain the original identity for the legacy file configuration. Remove the stale file references. The Feature demo cases remain in the main Laravel suite. |
| Three invalid account status fixtures | The Buyer enum has no `active` or `deactivated`, and the Seller enum has no `inactive`. SQLite accepted those isolated test inputs. | Check approved Buyer login, active Rider login and both role abilities. Check archive isolation for Buyer and confirm native MySQL rejects the invalid Seller value without persisting it. Keep the other eligible and ineligible checkout assertions. |
| Scale seeder command | The test expected a non-MySQL refusal while its configuration selected MySQL. | Explicitly choose the SQLite configuration for the negative invocation and assert the refusal message. The test opens no SQLite connection for that check. |
| Two guarded demo cases | The default native schema name and an unset or cleared per-schema opt-in failed the seeder's safety checks. | Run on named disposable demo schemas, supply the exact opt-in, and restore the previous opt-in after negative guard tests. Keep the checks for production, live names, existing orders, and non-demo marketplace accounts. |

The full suite exposed one additional Seller defect. The bulk product query did not specify an order. MySQL returned the restricted product before the product with unfinished orders. The query now sorts by product ID. The regression submits IDs in reverse order and still verifies both reasons, preserves both protected products and their order data, and archives the eligible product.

## Populated schema comparison

The fresh integration baseline contains 103 tables and 182 regular migration records. The populated database contains 50 tables and 64 migration records. There are 41 shared table names, 62 integration-only tables, and nine legacy-only tables. The ledger has 47 matches, 135 integration entries missing from the populated ledger, and 17 legacy-only entries.

On shared tables, 145 integration columns are absent, 49 columns are legacy-only and 14 same-name column definitions differ. Comparing definitions by name, 40 integration indexes and 20 integration foreign keys are absent from shared tables. Twelve indexes and two foreign keys are legacy-only, and two same-name foreign keys differ. A missing target name does not establish that an equivalent differently named constraint is absent.

| Area | Live evidence and upgrade implications |
|---|---|
| Identity and catalog | `buyers.archived_at` is absent. The Buyer, Seller and Logistics enums lack `suspended`. Legacy product statuses are a native enum and integration uses a string. Review existing values before any type conversion or product-approval backfill. |
| Checkout and pricing | `checkout_tokens` lacks `order_id`, `reconciled_rejected_at` and `shipping_rates_snapshot`. `shipping_rates` exists under a legacy definition. Its missing zone and centavo fields require a reviewed mapping. Preserve order totals, token ownership and historical voucher funding. |
| Delivery and money | The populated schema lacks `delivery_proofs`, `cod_remittances`, settlement and payout tables, and the integration's collected-COD fields in `payments`. A legacy `delivery_handover_audits` table exists. Its records need evidence-based mapping. No acknowledgement, remittance, settlement, payout or refund entry should be inferred from a delivery status alone. |
| Transport and returns | `transfer_couriers`, assignments, manifests, hubs, handoffs, route tables and `parcel_returns` are absent. Legacy `delivery_areas` and PSGC tables are present. Verify coverage from the recorded barangay and origin station. Mobile Courier accounts require the pending migration after transfer tables exist, plus explicit server activation. |
| Reviews and referential integrity | Legacy `reviews` and `personal_access_tokens` predate integration create migrations. Legacy review order-item and product references allow nulls. The Buyer FK is `RESTRICT` and the Product FK is `SET NULL`, where the integration baseline uses `CASCADE`. Preserve reviews and deliberate historical references. Translate orphaned or null references through an approved rule before changing nullability or delete behavior. |
| Legacy-only tables | `chat_messages`, `delivery_areas`, `delivery_handover_audits`, `promotions`, `psgc_barangays`, `psgc_cities`, `psgc_provinces`, `psgc_regions`, and `wishlist_items`. Retain these rows until an explicit copy or retention policy is validated. |

The exact table, column, index, foreign-key and ledger deltas are in the read-only evidence file `populated-schema-delta.json` under `%TEMP%\zefanya-phase12-31cb00d1`. It contains schema definitions and migration names. The reference ZIP's 103 forward migrations were previously verified on a separate fresh schema. Four filenames match the integration history, three of them Laravel framework migrations. The reference has a different history guard, lacks checkout recovery and recipient acknowledgement, and uses a manifest-assignment cascade where integration uses a restriction. Keep it as a reference. See [the Phase 11.7 comparison](PHASE_11_7_DATABASE_UPGRADE.md).

## Populated upgrade sequence and recovery

1. Obtain a verified backup of the actual target, including its ledger and any separately stored proof, return and audit files. Verify its provenance and restore it to a dedicated disposable MySQL instance. Record schema definitions, row counts and checksums for protected tables before applying any bridge.
2. Use the current integration migrations to build a separate destination schema, or develop a forward-only bridge against the restored schema after classifying each dependency as missing, equivalent, or incompatible. Preserve primary keys and FK ownership in every copy. Directly replaying integration on the populated schema would collide with existing create migrations such as `reviews`, `personal_access_tokens`, and `shipping_rates`. Ledger names and table name matches alone cannot authorize recording a migration as applied.
3. Add or map identity and catalog prerequisites before checkout, finance, and logistics. Keep legacy rows and historical evidence intact while nullable fields are backfilled. Old checkout tokens should only bind to a proven committed order. Preserve existing proof and consent data. Historical deliveries without proof of acknowledgement stay unacknowledged.
4. Add stations, coverage, verified parcel custody, Courier assignments and finance dependencies in FK order. Verify duplicate barangay claims, orphaned relations and enum values. Test refunds, Seller settlements, earnings, COD and payout totals against the before-upgrade snapshots without recalculating past transactions. Courier app activation and the pending refund policy each require independent approval.
5. Rehearse checkout recovery, OTP-free delivery and Courier authorization on the backup copy. Confirm row counts, order ownership, exact money totals, voucher redemptions and verified custody events. Document expected gaps where old records have no evidence.
6. Use backup restoration for recovery across schema histories. MySQL DDL can commit between statements, so record and verify each bridge checkpoint independently. The corrected historical barangay rollback is suitable only when the earlier uniqueness rule still fits the data. Review destructive historical operations before execution, including integration's delivery-OTP table drop and the reference ZIP's Seller promotion drop. Leave legacy evidence available until the recovery window closes.

**Restored-backup rehearsal is BLOCKED.** `Dump20261107.sql` declares the same database name but has 96 tables and 171 migration records. Ten of the populated database's tables and all 17 of its legacy-only migration names are absent from that dump. It was inspected as text and was not restored. The other local dumps predate it, and no verified backup of the current 50-table target was available. No restore, upgrade migration or data export was performed against the populated database.

## Verification

| Check | Result |
|---|---|
| Full Laravel native MySQL | **4,293 passed, 0 failed, 0 errors, 5 skipped** across 4,298 unique cases. The starting baseline was 4,283 passed, 2 failed, 8 errors and 5 skipped. The complete shard containing the Seller defect was rerun after the fix. Both guarded marketplace cases passed. |
| Native MySQL focused regression | 79 passed, 0 failed, 0 errors, 0 skipped. The existing rollback tests verified a preserved legacy row on round trip and the refusal to merge live coverage. |
| T05 safety and real contention | Dedicated preflight passed. Wrong UUID and application-port tests both refused the configuration. The native checkout and barangay race cases passed with separate connections. |
| Fresh integration migration | 182 regular migrations passed. Checkout recovery fields and recipient acknowledgement were present. |
| Pending Courier dependencies | The single Courier account migration passed separately on the fresh schema. The account link to `transfer_couriers` is `RESTRICT`. |
| Focused Laravel integration | 129 passed, 0 failed, 0 errors, 0 skipped. Coverage included Buyer checkout and vouchers, Seller fulfillment, Rider jobs and COD, verified cross-provider custody, Courier assignments, returns and refunds. |
| Seller bulk product retest | 119 passed, 0 failed, 0 errors, 0 skipped, across Seller product management, product approval and Admin product oversight. |
| Flutter Live API | 1,039 passed, 0 failed, 3 skipped. |
| Web production asset build | Passed. |
| Pint on modified PHP | Passed. |
| Repository-wide Pint | 23 pre-existing files reported. The reported files and fixers match the Phase 11.7 baseline. |
| Git whitespace check | Passed. |

The five Laravel skips were existing opt-in cases. Three exercise parcel-loss concurrency under its dedicated profile and two require the refund-policy variant.

## Upgrade readiness

The fresh schema validates that the integration can start on an empty MySQL database. Production deployment remains blocked until a verified backup is restored and the proposed bridge or side-by-side copy is rehearsed with data-preservation and recovery checks. Preserving order ownership, financial snapshots and custody on that restored copy remains unverified.
