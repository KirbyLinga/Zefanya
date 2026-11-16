# Phase 12.4 imported database compatibility

ZefanyaWMII on `integration/phase11-stable`, starting from `dd7622c`. The source checkpoints are `2719f3b` and `396edee`. MySQL 8.0.44 at `127.0.0.1:33183` hosted the Phase 12.3 restore and the Phase 12.4 rehearsal. The protected source was `zefanya_friend_test`. The final disposable copy was `zefanya_phase124_test_verified`.

## Findings and fixes

| Area | Evidence | Result |
|---|---|---|
| Historical Admin audit | Three `platform_setting_histories` rows share one Admin ID missing from `admins`. No other imported Admin operations-audit entry supplied corroborating identity. The Blade view dereferenced a null relationship. The model already rejects edits and deletes. | Preserve the recorded actor column as `legacy_admin_id`. The active `admin_id` relation remains restrictive, and MySQL requires exactly one reference per row. The Admin-only view displays “Unresolved Admin” with the recorded ID. The original actor’s identity remains unknown. |
| Parcel-loss records | Both loss tables in the restored database are empty. The imported schema has legacy required actor, reason and timestamp fields, and is missing `confirmed_by` and `kind`. Some references were unconstrained and the payment date used a timestamp. | Add required current actor and kind fields. Enforce the current incident, Seller, return, recovered hub and adjustment-Admin relationships. Align the date and field lengths on the empty tables. Retain legacy columns and preserve their availability for reference. The bridge refuses a copy with any existing loss records. |
| Seller promotions | The teammate ledger records the deliberate removal of `seller_promotions` and `seller_promotion_product`. This integration branch still serves Seller promotion pages and uses them for checkout pricing. | Use the existing integration definitions to restore the two empty tables and their pivot constraints on the disposable copy. Historical promotions remain unavailable. Voucher funding, prices and commission rules stay as implemented. |

The bridge is outside normal migration discovery. It requires the named rehearsal schema, a loopback MySQL endpoint, an explicit server UUID and active foreign-key checks. A wrong UUID was refused. An early rehearsal identified an implicit MySQL index retained after dropping the historical FK. The verified migration gives the new FK its own name and was applied from a clean clone. Rerunning its explicit Artisan command returned “Nothing to migrate.”

## Record preservation

The original restored schema was read through its SELECT-only account to create a consistent snapshot. The transfer used an empty schema and a separate restricted local account. MySQL matched all 109 initial table projections before the migration. The populated `zefanya` on port 3306 and the Phase 12.3 restore were outside the write scope.

| Check | Clone before | Clone after compatibility migration |
|---|---:|---:|
| Tables | 109 | 111 |
| Ledger rows | 297 | 298 |
| Rows across all tables | 1,096 | 1,097 |
| Foreign keys | 227 | 236 |
| Unresolved foreign-key references | 3 | 0 |

All 1,096 original rows retained their SHA-256 fingerprints in a primary-key-ordered projection. The historical actor ID is read from the legacy column for this comparison. The extra row is the new migration ledger entry. The verification treats the missing identity as unresolved even though the database no longer has an invalid foreign key.

After the rehearsal, all 109 tables in the source restore matched their original checksums. The original `zefanya` schema metadata and migration ledger matched the prior snapshot. The SQL export's SHA-256 was unchanged.

After the synthetic writes, the compatible clone had 1,269 rows and zero orphaned foreign-key references across 236 constraints. Every one of the 1,096 original row fingerprints still matched across the 109 source tables.

## Web–Mobile verification

Web, API and Flutter repository checks used the same writable rehearsal copy and dedicated synthetic accounts after the migration integrity check passed.

| Flow | Observed result |
|---|---|
| Buyer and Seller | Flutter reviewed and placed a checkout via local HTTPS. Seller Web displayed the same order. A separate voucher order confirmed platform funding, one redemption and one stock deduction. Repeating checkout returned HTTP 409 and reconciliation returned the original order. |
| Seller, Logistics and Rider | Seller accepted, packed and marked the order ready. Logistics assigned and dispatched the Rider. Rider API confirmed pickup, and Logistics recorded verified station custody. |
| Transport Courier | Logistics configured a valid route, assigned the Courier through Web and verified the dispatch and receiving scans. Dispatch retained origin custody and receipt transferred custody once. The Courier API showed the completed trip in history. |
| Delivery and COD | The Rider delivered the actual Flutter checkout order after a verified start and proof scan. The proof stored recipient acknowledgement and the collected COD was exactly ₱149. A separate fixture rejected incorrect COD with HTTP 422 and recorded one earning, proof and remittance on success. Logistics Web displayed the remittance. |
| Returns and accounting | The Buyer requested a return for the Flutter order. Seller approved it. Rider collected it, Logistics verified station and origin receipts, and Seller verified and confirmed the handover. The Admin authorized the ₱149 obligation once, and a duplicate was refused. The obligation remained unpaid. The Seller earnings and payout pages and Admin accounting page loaded on the copy. Native Laravel tests covered payout and platform-funded refund posting. |
| Admin and access | Admin Web displayed all three unresolved audit entries. Support received 403 for settings access and loss resolution. A synthetic Buyer could sign in after Admin approval, and a pending product appeared in the public API after approval. Seller created a new promotion through the restored Web workflow. Buyer and Courier bearer tokens received 403 from Rider endpoints. |
| Parcel loss | The populated incident queue rendered, the authorized Admin recorded the loss from verified custody, and the saved case retained the resolving Admin and source incident. The case generated neither COD nor paid compensation. |

The first populated Admin incident queue returned HTTP 500 because the Blade view referenced a provider list the index controller never supplied. The bulk resolver derives each parcel's liability through the existing custody rules and does not accept a provider override. The view now explains that behavior. The regression opens a populated incident queue and checks it before submitting the loss resolution. Flutter's Rider earnings workbook test now checks each lazily rendered entry at its own scroll position, preserving the exact result and payout assertions.

These are automated HTTP and Flutter repository results. Physical device verification was outside the rehearsal. No financial payment was sent.

## Regression results and readiness

| Check | Result |
|---|---|
| Full Laravel native MySQL before the final incident-view correction | 4,294 passed, 0 failed, 0 errors, 5 existing skips. The skips cover opt-in parcel-loss concurrency and refund-policy variants. |
| Native MySQL after the final incident-view correction | All 54 cases passed in the affected parcel-loss and platform-settings classes. The imported-copy loss flow also passed. |
| Full Flutter Live API | 1,040 passed, 0 failed, 3 existing skips. |
| Focused Flutter Rider workbook | 15 passed. |
| Flutter analysis | 12 existing findings, comprising ten informational findings and two warnings. |
| Laravel Web production build | Passed again after the incident-view correction, with Vite plugin-timing diagnostics. |
| Laravel Pint and Git whitespace | Passed on the changed PHP and committed diff. |

The compatible copy is for local development. Production remains blocked pending a separately verified production backup, confirmation of the original audit actor if evidence becomes available, and review of promotion policy across branches before any production migration. A historical export containing parcel-loss records needs its own source-backed migration plan.

## Safe local operation

Use the existing isolated instance on port 33183. The local rehearsal launcher is `%LOCALAPPDATA%\Temp\zefanya-phase124\start-web.ps1`. It verifies the database identity and the recorded integrity check before serving HTTP at `http://127.0.0.1:38139`. On this machine its matching TLS proxy is `tls-proxy.py` in the same directory, listening on port 38140.

If either web listener is stopped, run its command in a separate PowerShell terminal. The MySQL startup command is in the [Phase 12.3 report](PHASE_12_3_FRIEND_DATABASE_INTEGRATION.md). The synthetic login details are in the rehearsal directory's private `http-fixture.json`.

```powershell
$phase = Join-Path $env:LOCALAPPDATA 'Temp\zefanya-phase124'
& "$phase\start-web.ps1"
```

```powershell
$phase = Join-Path $env:LOCALAPPDATA 'Temp\zefanya-phase124'
python "$phase\tls-proxy.py" $phase
```

From the Flutter directory, use the protected development profile and a trusted local certificate.

```powershell
flutter run -d chrome --dart-define=USE_LIVE_API=true --dart-define=zefanya.backend=webzefanya --dart-define=zefanya.webzefanya.apiBaseUrl=https://127.0.0.1:38140/api --dart-define=zefanya.webzefanya.courierApp=true --dart-define=zefanya.webzefanya.allowWrites=false --dart-define=zefanya.webzefanya.allowVerifiedWrites=true --dart-define=zefanya.devTools=false
```

For another rehearsal, restore to a new empty and verified local copy before running the migration by its explicit path. A failed MySQL DDL sequence requires a fresh disposable restore from the same source. The existing import, historical actors and production configuration require their own later upgrade plan.
