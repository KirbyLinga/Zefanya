# Zefanya Audit 1.9: COD remittance manipulation and missing cash

Date: 2026-11-13 (Asia/Singapore). Scope: `C:\ProjectEcommerce\WebZefanya` only.

## Result

Four software defects were reproduced and fixed: replayed partial shortage recovery, missing/misclassified partial-handover cash in summaries, recovery amounts assigned to the wrong reporting month, and COD marked paid by delivery status without collection evidence. The supported financial writes otherwise enforce the tested role, ownership, amount, locking and audit boundaries.

Physical cash receipt and external bank/wallet transfers remain manual attestations. This audit does not establish that any real cash moved or that historical production records are complete. No production records were queried or rewritten. Existing dirty work and Audits 1.1–1.8 were retained; no commit or push was made.

## 1. Authoritative records and implemented workflow

| Meaning | Authoritative record / source |
|---|---|
| Expected Buyer COD | `payments.amount_minor`, per Seller order. The Rider-supplied amount must equal this server-stored amount. |
| Recorded Buyer payment | `payments.status`, `paid_at`, `collected_amount_minor`, `collected_at`, `collected_by_type/id`. `Payment.php:92` records the collection. A status transition alone now cannot manufacture payment evidence. |
| Collected cash obligation | One `cod_remittances` row per unique `payment_id`; original `amount_minor`, collecting Courier and actual collecting Logistics provider. Created by `RiderOperationsService.php:365` in the delivery transaction. |
| Rider-held cash | Pending row: original collection. Partially remitted row: `shortage_minor - shortage_recovered_minor`. `RiderCashService.php:22`, `PlatformFinanceService.php:102`. |
| Rider-to-company receipt | Owning Logistics records `received_amount_minor`, `confirmed_at/by`, and state `PARTIALLY_REMITTED` or `REMITTED_TO_LOGISTICS`. There is no separate Rider submission/Logistics acceptance pair. `CodRemittanceService.php:30`. |
| Shortage | Original `shortage_minor`, responsible company-scoped Rider, reason; later cumulative recovery and immutable per-recovery audit entries. Original shortage/owner/reason are preserved. `CodRemittanceService.php:122`. |
| Company-held unsent cash | Actual partial receipts plus eligible full receipts less their retained shipping; read by `LogisticsRemittanceService.php:92` and existing Admin position. Partial receipts stay ineligible for a platform batch. |
| Reported company transfer | `logistics_remittances` with `SUBMITTED`, parcel links and reference. `LogisticsRemittanceService.php:335`. This is a transfer claim, not platform receipt. |
| Confirmed platform receipt | Permitted Admin confirms exact covered parcel dues; batch records actual received amount and covered COD rows receive `zefanya_received_at`. `LogisticsRemittanceService.php:404`. |
| Outstanding platform flow | Existing Admin position adds Rider cash, company unsent cash and submitted transfers awaiting confirmation. These are distinct stages, not revenue. `PlatformFinanceService.php:102`. |
| Refund obligation / refund record | `parcel_returns` and numbered immutable return events; recording/voiding has revision checks. Original payment and COD custody survive refund. `ParcelReturnService.php:207`, `:251`. |
| Seller liability | `seller_settlements`, finalized order snapshots, payout allocations/requests and remaining debts. Preparation requires matching recorded full collection and company receipt. Actual payout also requires existing platform receipt. `SellerSettlementService.php:654`, `:141`; `SellerPayoutRequestService.php:321`. |
| Rider earnings | `rider_earnings` and `rider_payouts`, separately calculated and paid. Shortages are not automatic deductions. `RiderEarningService.php:86`, `:163`. |

The delivery path is `RiderOperationsService::completeDelivery()` (`:299`): ownership and delivery OTP are checked, the Seller order is locked, current stage is checked, exact COD is recorded under the payment lock, proof/status history and the unique cash obligation commit together. An exception rolls back the database changes and removes a newly stored proof file. Web Rider and token-authenticated mobile API controllers call this same service (`Rider/DeliveryAssignmentsController.php:179`, `Api/Rider/ParcelActionController.php:139`). Pickup and station handovers do not collect Buyer COD.

Company receipt and recovery lock in the order **SellerOrder → Payment → CodRemittance → Settlement**. They re-read ownership/current financial values. A repeated full receipt is a no-op; a stale initial receipt against a partial receipt is rejected. Shortages require a responsible Rider of the same provider and an explanation. Partial receipt does not prepare a Seller settlement. Full recovery can prepare one settlement, protected by the unique COD-settlement relationship.

Company-to-platform submission locks the provider, checks its active transfer reference, locks only its eligible full-receipt parcels, validates every requested parcel and recomputes the exact net amount. Submitted/confirmed parcels cannot enter another batch. Admin confirmation/rejection locks the batch and its parcel rows. A short transfer can confirm only a subset whose dues exactly match actual received money; excluded parcels become due again. Rejected batches preserve their historical record and release their parcel links.

Existing payout policy already requires platform receipt; this audit did not add that requirement. Preparation, finalized commission and withdrawability are different steps. Full company receipt alone does not authorize a Seller withdrawal.

### Authorization and source inventory

- `routes/logistics.php:36–45`: owning approved Logistics account may record receipt/recovery and submit platform transfer claims. Buyer, Seller, Rider and Admin sessions cannot act as that company account.
- `routes/admin.php:46–62`: authenticated Admin plus `admin.role:admin` for platform money decisions; Super Admin is accepted by existing role middleware. Moderator/Support do not gain financial authority.
- `CodRemittanceController.php:62`, `:105`: ownership checks and non-negative integer-derived centavo parsing; no request mass assignment of financial state.
- `EarningsController.php:98`: selected parcel IDs, expected amount and required reference feed server-calculated submission.
- `Admin/LogisticsRemittanceController.php:47`: non-negative received amount and selected parcels; service independently checks exact covered dues and Admin role.
- `SellerOrderStatusService.php:313`, `:664` and `Console/Commands/OrdersResyncCommand.php:25`: alternate paid-status writers, now requiring an exact collection snapshot. The resync command was exercised only in isolated tests, not against application data.
- `Payment.php:61`, `:92`, `:123`: controlled payment methods and undelivered-return guard. These are trusted internal methods, not direct HTTP financial mutation endpoints.
- `LogisticsRemittanceService.php:257`, `:291`, `:501`: existing historical shipping allocation, destination payment and origin-share reversal. These adjust shipping payout accounting; they do not erase Buyer COD or clear a Rider shortage.
- Search of `app`, routes and migrations found the Rider completion service as the application creator of COD obligations and the two remittance services as their financial mutation paths. Console status resync was included. There is no separate `app/Jobs` directory or exposed COD edit/delete/reverse endpoint in this checkout. Seeders/migrations are setup/backfill code, not runtime user endpoints; none was run against production.
- `2026_10_25_030521_create_cod_remittances_table.php:13` makes `payment_id` unique and uses restrictive parent foreign keys. `2026_10_25_031235_create_seller_settlements_table.php:14` makes COD settlement unique. Subsequent receipt, shortage, batch-link and allocation migrations were traced. No migration was changed or historical backfill executed by this audit.

## 2. Confirmed findings and minimal fixes

### F1 — High: a repeated partial shortage recovery credited the same claim twice

Affected/fixed: `app/Services/Logistics/CodRemittanceService.php:154–164`, audit insertion `:184`.

Reproduction before fix:

1. Record a collection of 8,500 centavos, receipt of 6,000 and shortage of 2,500.
2. Recover 500 with reference `RECOVERY-A`, then 500 with `RECOVERY-B`.
3. Replay the original 500 / `RECOVERY-A` request.
4. Previously received cash became 7,500 and outstanding shortage 1,000, although the replay supplied no new recovery claim. The same issue occurred with two simultaneous 1,000 / identical-reference requests while sufficient shortage remained.

Root cause: the row lock serialized writes and prevented over-recovery but each retry was treated as another installment. A remaining positive shortage made a duplicate valid under amount checks. The latest-reference field alone could not detect replay of an older recovery.

Impact: overstated company cash, understated missing cash and potentially premature full-handover/settlement preparation. Existing independent platform receipt and Seller payout checks still apply; the defect was not a demonstrated direct payout authorization bypass.

Fix: while holding the existing parcel/payment/COD locks, check immutable recovery history for the same reference on **this COD shortage only** and reject replay before financial changes. Original shortage ownership/reason remain unchanged. References remain reusable on unrelated shortages; no global uniqueness constraint was introduced. A different legitimate installment still adds to the locked current balance and cannot exceed the current shortage. Different claims or references cannot prove that physical recovery occurred.

Evidence: `CodRemittanceShortageTest.php:184`, `:239`; real MySQL duplicate/distinct recovery races at `CodRemittanceConcurrencyTest.php:88`. The pre-fix replay regression failed.

### F2 — Medium: partial receipts disappeared from Admin company cash and remained overstated as Rider cash

Affected/fixed: `app/Services/Logistics/LogisticsRemittanceService.php:52–112`; consumer `PlatformFinanceService.php:102–106` was traced but not changed.

Reproduction: collect 8,500, receive 6,000 at Logistics, then recover another 500. Actual recorded custody is 6,500 at Logistics and 2,000 outstanding with the Rider. Previously Admin showed company cash 0 and outstanding cash only 2,000, while Logistics' Rider cash tile still showed the full 8,500. The existing per-parcel cash balance already showed the actual 6,500, exposing the inconsistency.

Root cause: Admin company cash reused an eligibility query that admitted only fully handed-over parcels. Logistics' Rider stage summed original collection rather than outstanding shortage. Existing reconciliation/shortage rows preserved amounts, but the summary queries ignored their meaning.

Fix: unsent company reporting includes actual partial receipts; the existing Rider cash tile sums outstanding shortage for partial rows. Remittance eligibility remains full-parcel only. Whole-parcel dues/shipping earnings and the 40/60 snapshot are unchanged.

Gross partial custody stays visible, matching the existing gross pending-Rider convention. Shipping is subtracted from platform dues once full handover makes a parcel eligible; no priority rule allocating retained fees against an incomplete receipt was invented. Therefore `outstandingCashMinor` remains the existing staged operational figure, not a universal physical-cash or revenue balance.

Evidence: `CodRemittanceShortageTest.php:202`, exact 6,500/2,000 reconciliation and ineligible batch assertion; MySQL partial recovery position checks at `CodRemittanceConcurrencyTest.php:88`. The pre-fix company-cash regression failed.

### F3 — Medium: monthly Rider handover totals used the wrong cash-movement date

Affected/fixed: `app/Services/Rider/RiderCashService.php:29–61`, `CodRemittanceService.php:187`.

Reproduction: record initial receipt and a recovery in the previous Manila month, then recover cash this month. Previously this month's handover total was zero because the query filtered on original `confirmed_at` and summed cumulative received cash. Conversely, cumulative later recoveries could be attributed to the initial receipt month.

Root cause: cumulative balance was treated as a dated cash event. The immutable recovery history already held each movement's date and exact before/after received cash, but the summary did not use it.

Fix: initial receipt is cumulative received less cumulative recovery, counted in its own receipt month; recovery deltas are counted using their audit event dates. New recovery audits include an integer-centavo delta. Older audits are decoded from their exact formatted before/after values using integer arithmetic. No historical records or original timestamps are rewritten.

Evidence: `CodRemittanceShortageTest.php:219` covers prior-month recovery, current-month legacy-format history and current-month numeric history together. The pre-fix monthly regression failed. This compatibility path assumes existing genuine recovery audits retain the format written by the service; corrupt/missing historical audit evidence is not reconstructed from an external bank statement.

### F4 — Medium: delivery status and status resync could manufacture paid COD without collection evidence

Affected/fixed: `app/Services/Seller/SellerOrderStatusService.php:664–675`, `app/Console/Commands/OrdersResyncCommand.php:54–65`.

Reproduction: take a Delivered Seller order whose COD payment remains Pending without `collected_at` or `collected_amount_minor`. Buyer receipt completion previously marked its payment Paid. Separately, `orders:resync` marked Delivered/Completed COD paid from delivery status alone. Neither writer created collection evidence or a COD cash obligation.

Root cause: status synchronization inferred payment from a delivery state. Normal Rider delivery already records exact collection and obligation atomically, but that protection was bypassed by the generic status settlement helper and CLI reconciliation path.

Impact: paid status and legacy fallback refund entitlement could claim an unrecorded payment. Existing matching-collection/company/platform checks still blocked Seller payout; this was not a bypass of those checks.

Fix: these two alternate writers can settle only an already-present exact collection snapshot. A status-only completion/resync leaves uncollected payment Pending and creates no cash obligation. Normal recorded Rider delivery continues to mark payment Paid. No historical Paid record was reverted and no collection amount was inferred or backfilled.

Evidence: `CodRemittanceShortageTest.php:280` (paid status, refund entitlement, collection snapshot and no settlement); `OrdersResyncCommandTest.php:68`, `:91` (uncollected status-only record and existing exact snapshot). The pre-fix status-completion regression failed.

## 3. Scenario results A–X

Classification describes the pre-fix finding where present; “fixed” identifies the verified final behavior.

| Scenario | Classification | Evidence and final assessment |
|---|---|---|
| A. Buyer pays less than expected | ALREADY PROTECTED | `RiderOperationsService.php:599`, `Payment.php:92` reject an amount different from the stored COD due. Delivery/proof/OTP consumption rolls back. Existing web/mobile/OTP tests exercise wrong amounts. There is no partial-Buyer-COD acceptance workflow: an attempted partial collection is rejected, not stored as a new paid/shortage policy. Cash taken contrary to that workflow requires operational investigation. |
| B. Full collection, short handover | CONFIRMED VULNERABILITY — fixed F1/F2 | Initial actual receipt, shortage, responsible Rider and reason were already stored; settlement was withheld. Replay and summary defects are corrected. Tests preserve original shortage through recovery. |
| C. Rider falsely marks remitted | ALREADY PROTECTED | No Rider remittance confirmation endpoint. Direct Buyer/Seller/Rider/Admin POSTs to company receipt/recovery/submission reject the wrong guard; only owning Logistics records handover. This proves software authority, not actual physical custody. |
| D. Logistics falsely confirms receipt | OPERATIONAL GAP | Company can attest receipt, with amount bounds and audit identity; no independent cash sensor or second handover record exists. An authorized false attestation cannot be disproved by this software. Platform receipt is a separate Admin action. |
| E. Company sends less | PARTIALLY PROTECTED | `LogisticsRemittanceService.php:404` requires received money to equal selected covered parcels, returns excluded parcel dues to the company and stores original batch/actual receipt/shortfall. Existing tests cover subset confirmation and short-payment rejection. Arbitrary partial platform payment within one parcel has no allocation workflow; it must remain unconfirmed/rejected pending operational reconciliation. No new policy was added. |
| F. Duplicate Rider handover | ALREADY PROTECTED | Seller-order/payment/COD locks, pending-state gate, full-receipt no-op and one settlement. Real MySQL full/full and partial/stale-full races verify one receipt/audit, unchanged collection and no shortage overwrite. |
| G. Duplicate company remittance | ALREADY PROTECTED | Provider lock, eligibility/parcel locks, exact IDs and one current batch link. MySQL competing submissions with different references cannot allocate the same parcel twice; duplicate Admin confirmation creates one receipt/audit. |
| H. Reference reuse | PARTIALLY PROTECTED | Existing active platform-transfer references are provider-scoped and serialized; rejected references can be corrected/reused. F1 now rejects repeated recovery references only within one shortage, including older history. Unrelated shortages/providers may share references. External reference authenticity or deliberately different references remain manual checks. |
| I. Forged financial/identity fields | ALREADY PROTECTED | Strict decimal syntax/integer conversion, exact server payment/parcel dues, selected parcel membership, provider ownership and explicit service writes. Negative, excessive, exponent, third-decimal and oversized HTTP amounts reject; forged amount/status/payment/provider fields do not override the computed shortage or owner. |
| J. Unauthorized roles | ALREADY PROTECTED | Direct HTTP guard matrix added for Buyer/Seller/Rider/Admin against company actions; Buyer/Seller/Rider cannot reach Admin confirmation. Existing tests reject Moderator/Logistics on Admin decisions and permit owning Logistics/permitted Admin. No hidden-interface assumption was used. |
| K. Cross-provider changes | ALREADY PROTECTED | Receipt/recovery re-read provider under locks; submissions filter every parcel by provider and compare the selected count. Existing cross-provider submission tests and new HTTP recovery denial preserve other-company records. |
| L. Cross-province custody/split | ALREADY PROTECTED | Cash obligation uses actual delivery Courier's provider, not original carrier or current assignment alone. One payment obligation; immutable shipping snapshot allocates 40% origin/60% destination without creating another Buyer-cash obligation. Existing multi-provider complete-financial scenarios exercise local/cross-province transport and payout flow. |
| M. Shortage manipulation | CONFIRMED VULNERABILITY — fixed F1 | Negative/excess recovery rejected, current outstanding balance locked, original shortage/owner/reason immutable through supported recovery. Duplicate same-reference installment previously cleared cash twice; now rejected using full history. No earnings action clears it. |
| N. Rider earnings separation | ALREADY PROTECTED | Rider earnings/payout services calculate work earnings independently. Existing `RiderEarningsTest::test_an_outstanding_cod_shortage_does_not_reduce_or_block_rider_pay` remains passing; Rider payout/reversal races rerun. No automated payroll deduction was added; a manual reference/note does not execute one. |
| O. Seller payout before collection | ALREADY PROTECTED | Full matching collection/company receipt required to prepare; existing platform-receipt, completion, finalized allocation, return hold, debt and reservation checks govern payable/confirmation. F4 removes false paid-state evidence but does not weaken these checks. |
| P. Refund after collection | ALREADY PROTECTED | New partial-handover/refund regression snapshots payment and COD history unchanged, retains full custody obligation/shortage, rejects refund replay and creates no duplicate debt. Earlier full/partial refund, paid debt, void/replacement and payout races remain passing. Refund is a separate outgoing obligation, not evidence that collected cash was handed over. |
| Q. Status manipulation | CONFIRMED VULNERABILITY — fixed F4 | Web/API Rider delivery ownership/OTP/amount/transaction guards retained; generic delivery/receipt synchronization and CLI resync no longer infer unrecorded cash from status. Duplicate delivery leaves one collection/obligation. |
| R. Stale forms | CONFIRMED VULNERABILITY — fixed F1 | Initial handover cannot overwrite a newer partial receipt/recovery; selected platform batch/amount is checked against current locked eligibility; terminal decisions reject stale changes. Recovery uses current balance and refuses repeated references. Different references still represent separate additive manual claims; their physical truth is not independently verified. |
| S. Concurrent corrections/reversal | CONFIRMED VULNERABILITY — fixed F1 | Eight real COD races cover full/partial duplicate receipts, duplicate/distinct recoveries, overlapping submission, duplicate confirmation, confirmation versus rejection and recovery versus stale handover. No exposed reversal of confirmed COD/platform receipt exists. Origin/Rider payout reversal is separate and tested; no speculative COD reversal feature was added. |
| T. Rollback | ALREADY PROTECTED | Injected collection-obligation failure rolls back payment/proof/status and proof file. Receipt/recovery audit or settlement failure restores cash/shortage/audit/settlement. Platform submit/confirm/reject audit failure restores batch/links. Dedicated InnoDB rollback checks additionally cover receipt, recovery and platform confirmation. |
| U. Reports | CONFIRMED VULNERABILITY — fixed F2/F3 | Actual partial company cash now remains visible, Rider holding is the remaining shortage, and monthly handover follows actual event dates. Pending transfer is not platform receipt; confirmed actual batch amount alone enters the platform cash-in ledger. Fees/commissions/Rider pay remain separate. |
| V. Historical integrity | ALREADY PROTECTED within exposed workflows | Receipt is not editable after decision; recoveries append immutable audits and retain original shortage; rejected batches keep history. Shipping/Rider payout corrections preserve originals/audits. `OperationsAuditLog.php:52`, `:65` reject ORM edit/delete including bulk Eloquent operations. No COD edit/delete endpoint was found. Privileged raw SQL can bypass application events; no tamper-proof external archive was assumed. |
| W. Unverified physical cash | OPERATIONAL GAP | Records identify accountable provider/Courier, attestation, shortage and audit, but do not prove cash custody or bank arrival independently. Admin confirmation remains an authorized manual attestation. Absence of physical verification is not classified as an additional code defect. |
| X. Alternate writers | CONFIRMED VULNERABILITY — fixed F4 | Shared web/mobile collection paths, generic status service, automatic completion, CLI resync, both remittance services, shipping reconciliation/reversal and refund/debt services were searched/traced. Status-only paid writers were corrected. No additional exposed COD financial mutation path was found in the searched checkout. |

## 4. Files changed for this audit

Production files (five):

1. `app/Services/Logistics/CodRemittanceService.php`: same-shortage replay guard using immutable history; numeric recovery audit delta.
2. `app/Services/Logistics/LogisticsRemittanceService.php`: actual partial-cash custody in existing summaries; no eligibility or shipping split change.
3. `app/Services/Rider/RiderCashService.php`: event-dated monthly handover calculation with legacy audit compatibility.
4. `app/Services/Seller/SellerOrderStatusService.php`: collection evidence required before status synchronization can mark COD paid.
5. `app/Console/Commands/OrdersResyncCommand.php`: same evidence requirement in existing reconciliation command.

Tests/config/report:

- `tests/Feature/Logistics/CodRemittanceShortageTest.php`: recovery replay/history/reference scope; custody summaries; cross-month history; refund/cash separation; uncollected status completion; HTTP roles/forgery/ownership; audit/settlement rollback.
- `tests/Feature/Logistics/CodRemittanceCreationTest.php`: failure after collection/proof/status but before cash-obligation insertion rolls everything back, including proof file.
- `tests/Feature/Logistics/LogisticsRemittanceTest.php`: submit/confirm/reject rollback after financial mutation but before audit commit.
- `tests/Feature/Console/OrdersResyncCommandTest.php`: status-only records remain unpaid; exact existing collection snapshots can reconcile.
- `tests/MySql/CodRemittanceConcurrencyTest.php`: eight overlapping-worker cases and three InnoDB rollback cases.
- `tests/MySql/worker.php`, `tests/MySql/Race.php`: COD HTTP tasks and observable platform-batch lock barrier; existing tasks retained.
- `phpunit.mysql.xml`: include COD regression class in the existing isolated suite.
- This report.

No frontend assets, new dashboards, notification behavior, gateway, rates, COD-only rule, 40/60 split, debt recovery/return/refund policy or automatic earnings deduction was added/changed. No Flutter or integration project changes.

## 5. Verification

Baseline focused SQLite: **54 tests / 478 assertions passed**, covering existing shortage, creation, financial workflow, company remittance and reconciliation.

Red regression run: **17 tests / 71 assertions, 13 passed and four failed**, reproducing F1–F4 before production fixes. Additional test-authoring corrections were a replicated Seller-order fixture violating the existing order/seller unique key, a MySQL worker trying to decode successful redirects as JSON, and stale-state test expectations using 422 where the app intentionally returns 409. These were corrected in test code; financial guards were not relaxed.

Final SQLite: **415 tests / 3,489 assertions passed**. Final isolated MySQL: **32 tests / 368 assertions passed across two disjoint commands**: 29 concurrency/financial tests with 353 assertions, plus three newly added InnoDB rollback datasets with 15 assertions. Within the MySQL total, the new COD class accounts for eight overlapping-worker tests / 60 assertions plus three rollback tests / 15 assertions; the preserved Seller/Rider financial race classes account for 21 tests / 293 assertions. No final failures or skipped tests were reported.

Final SQLite command (18 test files):

```text
php vendor/bin/phpunit tests/Feature/Logistics/CodRemittanceShortageTest.php tests/Feature/Logistics/CodRemittanceFinancialWorkflowTest.php tests/Feature/Logistics/CodRemittanceCreationTest.php tests/Feature/Logistics/LogisticsRemittanceTest.php tests/Feature/Logistics/CodReconciliationTest.php tests/Feature/Console/OrdersResyncCommandTest.php tests/Feature/Rider/RiderDeliveryTest.php tests/Feature/Rider/DeliveryOtpTest.php tests/Feature/Rider/RiderMobileApiTest.php tests/Feature/Order/Rule02OrderStatusFlowTest.php tests/Feature/Seller/SellerPayoutRequestTest.php tests/Feature/Rider/RiderEarningsTest.php tests/Feature/Admin/AdminFinancePageTest.php tests/Feature/Logistics/OperationsAuditLogTest.php tests/Feature/Seller/SellerReturnDecisionTest.php tests/Feature/Logistics/CompleteFinancialScenariosTest.php tests/Feature/Admin/OrderMonitoringTest.php tests/Feature/Logistics/AutomaticBuyerReceiptTest.php
```

MySQL commands:

```text
php vendor/bin/phpunit -c phpunit.mysql.xml --filter 'CodRemittanceConcurrencyTest|SellerPayoutConcurrencyTest|RiderPayoutConcurrencyTest'
php vendor/bin/phpunit -c phpunit.mysql.xml --filter 'CodRemittanceConcurrencyTest::test_audit_failure_rolls_back_mysql_cash_history_and_settlement'
```

The first MySQL command ran before the additional three rollback datasets were added to the class; those three were then run by the second command. Their counts are not represented as one combined PHPUnit invocation.

`php vendor/bin/pint --dirty --format agent` completed and formatted only audit-related PHP files. Final targeted Pint `--test` and `git diff --check` passed. Existing earlier payout/refund/debt and cross-provider scenario coverage was included in the final SQLite selection and the real MySQL Seller/Rider suites.

SQLite uses PHPUnit's isolated in-memory database. MySQL uses the existing preflight-verified disposable `zefanya_concurrency_test` account/schema, server identity/grants checks, denied application-schema access and InnoDB. The race harness starts independent PHP workers and observes the second connection's overlapping locking statement before releasing the first. SQLite success is not used as evidence of row-lock concurrency.

Evidence directories: `.codex/t05-runs` for COD/Rider races and `storage/logs/payout-races` for Seller/refund races. No credential values are included in this report.

Not executed: unrelated full repository suite, production reconciliation/backfills, live browser clicks, physical cash count or external bank/wallet verification. Source-traced and HTTP/service test evidence is distinguished from those unverified real-world activities. No frontend build is required because this audit changed no frontend file.

## 6. Operational limits and financial interpretation

- A successful Rider delivery/collection is still an authorized Rider claim backed by the implemented OTP/proof workflow. Software checks exact expected amount and accountable identity, not the contents of a cash bag.
- Company receipt/recovery and Admin platform confirmation are separate attestations. Company submission/reference alone never establishes platform receipt.
- Partial Buyer COD and arbitrary fractional platform-parcel allocation have no existing acceptance workflow. This audit neither stores a new partial-Buyer-payment state nor invents a penalty or allocation policy.
- Initial/partial custody totals are gross cash; fully handed-over platform dues exclude destination-retained shipping under existing rules. Shipping shares and Rider earnings are accounted separately. The dashboard outstanding figure is not interchangeable with total physical notes/coins or platform revenue.
- Replaying the same recovery reference on one shortage cannot add cash again. Different references, false attestations, unrelated providers' legitimate reference reuse and bank authenticity still need human reconciliation. Logistics attribution is the company account, not an independently identified employee approval chain.
- Supported corrections preserve audit history. Administrators with raw database privileges remain outside application-level immutability; missing/corrupt legacy evidence is not automatically repaired.

Within the tested supported workflow, recorded partial custody and shortage are now reconciled consistently, repeated credits are prevented, monthly cash movements use their own dates, and delivery status cannot invent a new paid collection. That conclusion does not certify historical production completeness or physical receipt.

## 7. Final status

**FIXED AND TESTED.** All four reproduced software defects are fixed, with passing isolated SQLite regressions, real MySQL lock-overlap tests and InnoDB rollback checks. Physical-cash and external-transfer attestation limitations remain operational gaps. No new business policy, automatic deduction or production-data repair was introduced.
