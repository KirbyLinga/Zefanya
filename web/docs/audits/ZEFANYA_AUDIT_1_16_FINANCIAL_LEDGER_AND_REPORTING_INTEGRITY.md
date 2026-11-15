# Zefanya Audit 1.16 — Financial ledger and reporting integrity

Date: 2026-11-13. Repository: `WebZefanya`. No production database was read or modified. No commit or push.

## 1. Executive summary

Zefanya does not keep a double-entry ledger. It keeps separate integer-centavo records for COD obligations, collections, remittances, seller settlements, debts, payouts, shipping shares, rider earnings, refunds, and platform refund expenses. Those records are written inside row locks, and the paths reviewed here do not create a second payment, a second collection, or a second active refund.

Two confirmed defects were fixed:

1. After a seller-funded partial refund, the unpaid settlement stored the seller’s kept net, but payout still required the pre-refund identity. The retained balance could not be withdrawn or paid. Payout now accepts that kept net only when it matches the saved return breakdown and the original collected cash is unchanged.
2. Settled logistics return charges were omitted from the finance ledger and CSV. Settled charges now appear once, in the direction recorded on the charge. Unsettled charges stay off the ledger.

Seller earnings and seller reports now show the return deduction that was already stored in seller net, so the displayed parts add up to that net. Platform “net earnings” is still commission minus marketplace voucher cost. Platform refund expense stays on its own line and is not subtracted. That label should be clarified; the amount was not redefined.

Historical rows were not rewritten. Saved 5% commission rates were not replaced with 10%. The 40/60 shipping split was not changed.

## 2. Existing financial architecture

This is an event-based set of financial records, not double-entry accounting. There is no journal of balancing debits and credits. Amounts are integer centavos. There is no currency column. The interface shows Philippine pesos by dividing by 100.

| Record | Event | Writer | Authoritative amount | Payer / payee | Reversal |
|---|---|---|---|---|---|
| `payments` | Buyer COD obligation, then door collection | `CheckoutService::placeOrder`; `Payment::recordCodCollection` | `amount_minor`, then `collected_amount_minor` | Buyer pays the rider | Cancel only while still `pending` |
| `cod_remittances` | Rider cash handed to Logistics, including shortage | `RiderOperationsService` insert; `CodRemittanceService` | `amount_minor`, `received_amount_minor`, `shortage_minor` | Rider to Logistics | Shortage recovery is a new audit row. A full confirm is not deleted |
| `logistics_remittances` | Logistics cash handed to Zefanya | `LogisticsRemittanceService` | `received_amount_minor` | Logistics to Zefanya | Reject only while submitted. Confirmed handover is terminal |
| `seller_settlements` | Seller gross, commission, net, payout | `SellerSettlementService`; payout cash in `SellerPayoutRequestService` | `seller_payout_minor` | Zefanya to Seller | Paid rows are not edited. Unpaid rows can be rewritten by a seller-funded return |
| `seller_debts` | Amount already paid to the seller that a later return takes back | `SellerSettlementService`; recovery in `SellerPayoutRequestService` | `amount_minor` minus `settled_minor` | Seller owes Zefanya | Recovery increases `settled_minor`. The row is not deleted |
| `seller_payout_requests` | Seller withdrawal, including debt recovery | `SellerPayoutRequestService` | `amount_minor` minus `debt_deducted_minor` | Zefanya to Seller | Reject while pending. Paid rows stay |
| `rider_earnings` / `rider_payouts` | Pickup and delivery pay, then a manual logistics payment | `RiderEarningService` | Flat saved rates, not a share of COD | Logistics to Rider | Payout reversal clears the link and keeps the payout row |
| `origin_share_payouts` and destination paid date | 40/60 shipping shares after Zefanya has the cash | `LogisticsRemittanceService` | `origin_share_minor`, `destination_share_minor` | Zefanya to the provider | Origin reversal keeps the payout row. Destination pay has no reversal method |
| `parcel_returns` refund columns plus refund history | One active recorded refund | `ParcelReturnService::recordRefund` / `voidRefund` | `refund_amount_minor` | Recorded obligation, not proof the bank transfer succeeded | Void clears the live columns and appends history. It does not delete the event |
| `platform_refund_expenses` | Platform-funded exception refund and its void | `ParcelReturnService` | `amount_minor` by `entry_type` | Zefanya expense | One reversal row per void. The expense row stays |
| `logistics_return_charges` | Logistics owes Zefanya, or Zefanya owes Logistics, for a return | `ReturnChargeService` | `amount_minor` and `direction` | Provider or Zefanya | Settled rows are not changed by later `sync` |
| `operations_audit_logs` | Actor and before/after values | `OperationsAuditLog::record` | Snapshot text | Actor on the log | Model updates and deletes throw |

COD collection is not the same fact as delivery. `recordCodCollection` requires the collected centavos to equal `payments.amount_minor`, and it runs in the same transaction as proof and the status change. Commission for an ordinary completion is 1000 basis points (10%), or the rate already stored on the order. Shipping for two providers is 40% origin and the remainder destination (`CodRemittance::ORIGIN_SHARE_PERCENT`). Three or more providers get amountless `pending_policy` rows.

## 3. Dependency analysis

Graphify 0.9.108 is installed. `graphify hook status` showed no Git hooks. No graph existed at the start.

Commands actually run:

- `graphify --version`
- `graphify --help`
- `graphify hook status`
- `graphify extract . --code-only --no-cluster` (1,431 code files; PDF extraction skipped because `pypdf` is not installed; 547 non-code files skipped)
- `graphify query "SellerSettlementService confirmPayout isReconciled partial refund seller payout" --budget 1500 --depth 2` (3,139 nodes matched; the budget cut the answer to 49 nodes, so it was not used as proof)
- `graphify explain "SellerSettlementService"`
- `graphify update . --no-cluster` after the payout fix (13,463 nodes, 38,563 edges; 1,346 of 1,431 files from cache)
- `graphify explain "payoutIdentityHolds"`

`graphify-out/` is untracked and is not in `.gitignore`. It was not committed.

Limits: Graphify’s PHP extractor records static calls and imports. It does not prove Laravel route binding, `app()` resolution, or Eloquent relations. `SellerPayoutRequestService` calls `payoutIdentityHolds` through `app()`, and that edge is not in the graph. The finance ledger’s new return-charge query was added after the last graph rebuild and was checked in source. Dynamic edges below were read in PHP.

Verified payout chain after the fix:

- `SellerSettlementService::confirmPayout` calls `payoutIdentityHolds` (`SellerSettlementService.php:202`), which calls `returnBreakdown` (`:557`).
- `SellerPayoutRequestService::isReconciled` calls the same method (`SellerPayoutRequestService.php:345`).
- `writeReturnSettlement` stores the voucher funding snapshot on the return settlement (`SellerSettlementService.php:577`).

COD chain read in source: checkout token claim (`CheckoutService.php:133`) to one `payments` row; delivery lock (`RiderOperationsService.php:313`) to `Payment::recordCodCollection` (`Payment.php:92`); rider handover (`CodRemittanceService.php:37`).

## 4. Scenario results A–AZ

| Scenario | Result |
|---|---|
| A. Duplicate COD obligation | ALREADY PROTECTED. Checkout claims a one-time token inside the buyer row lock. `payments.seller_order_id` is unique. |
| B. Forged COD collection | ALREADY PROTECTED. First completion requires the delivery code and photo. The model accepts only COD and only the stored amount due. |
| C. Collection amount tampering | ALREADY PROTECTED. `Payment.php:102` rejects any amount other than `amount_minor`. |
| D. Duplicate collection | ALREADY PROTECTED. A delivered or completed parcel returns before a second write. One remittance per `payment_id`. |
| E. Collection versus delivery race | ALREADY PROTECTED. Collection, proof, status, and the remittance insert share one transaction and the seller-order lock. MySQL concurrency tests in this run passed. |
| F. Collection versus cancellation | ALREADY PROTECTED. `OutForDelivery` cannot move to `Cancelled`. A pending payment is the only one cancellation can cancel. |
| G. Rider remittance duplication | ALREADY PROTECTED. A row already remitted to Logistics returns without a second write. |
| H. Rider remittance overpayment | ALREADY PROTECTED. Received cash cannot exceed the collected amount (`CodRemittanceService.php:51`). |
| I. Rider remittance reversal | ALREADY PROTECTED for shortage recovery, which appends an audit row and cannot reuse the same reference. A fully confirmed rider handover has no delete path. |
| J. Logistics remittance duplication | ALREADY PROTECTED. The provider row is locked and a live reference cannot repeat. |
| K. Logistics remittance overpayment | ALREADY PROTECTED. Confirmation requires the received amount to equal the selected parcels. |
| L. Logistics remittance reversal | PARTIALLY PROTECTED. A submitted batch can be rejected and the parcel links cleared. A confirmed handover is terminal. Origin-share reversal keeps the payout row. There is no reversal of a confirmed platform cash handover. |
| M. COD shortage reporting | ALREADY PROTECTED. Shortage is collected amount minus received amount, and recovery cannot exceed what is still missing. |
| N. Rider earnings versus shortage | ALREADY PROTECTED. Shortage writes do not update `rider_earnings`. |
| O. Seller gross | ALREADY PROTECTED. Gross is the stored merchandise snapshot, not a recomputed live price. |
| P. Seller discount funding | ALREADY PROTECTED. Seller-funded discounts reduce the commission base and seller net. |
| Q. Platform voucher funding | ALREADY PROTECTED. Marketplace-funded discounts are not subtracted from seller net. |
| R. Historical commission rate | ALREADY PROTECTED. A stored 500 basis points stays 500. This audit paid a 5% partial-refund net of ₱76.00 without rewriting the rate. |
| S. Commission before completion | POLICY DECISION REQUIRED. A seller-funded return can set `commission_finalized_at` while the shipment is still `Delivered`. The existing test records that on purpose. No new recognition rule was added. |
| T. Commission after completion | ALREADY PROTECTED. A second ordinary finalization does not insert another amount. |
| U. Commission versus refund | FIXED for the unpaid partial-refund payout block described in section 5. A paid settlement is left as history and the difference becomes one debt. Platform-funded refunds do not enter this rewrite. |
| V. Seller net | FIXED on the earnings and report pages. Stored net was already the kept amount. The pages now show the return deduction so the visible sum matches that net. |
| W. Duplicate seller payout | ALREADY PROTECTED. A paid request returns immediately. MySQL still rejects paying the original net after a concurrent partial refund. |
| X. Seller debt recovery | ALREADY PROTECTED. Recovery is oldest debt first, and the locked deduction must match the debt outstanding before any write. |
| Y. Seller payout versus new debt | ALREADY PROTECTED. The MySQL payout suite in this run passed, including refund-versus-payout races. |
| Z. Negative seller balances | ALREADY PROTECTED. A negative net is stored with `negative_seller_net` and cannot be withdrawn. |
| AA. Same-provider shipping | ALREADY PROTECTED. The delivering company keeps the shipping fee. Origin share is 0. |
| AB. Cross-provider split | ALREADY PROTECTED. Origin is 40%. Destination is the remainder. The snapshot is not rewritten. |
| AC. Duplicate logistics earnings | ALREADY PROTECTED. The share is snapshotted once per payment. |
| AD. Missing logistics earnings | ALREADY PROTECTED on the delivery write path. Production rows were not scanned. |
| AE. Logistics payout versus adjustment | ALREADY PROTECTED. An origin reversal aborts when the linked parcels no longer match the payout. The logistics feature tests in the broader SQLite run passed. |
| AF. Rider earnings duplication | ALREADY PROTECTED. One pickup and one delivery per parcel, with a unique key. |
| AG. Rider earnings eligibility | ALREADY PROTECTED. Earnings are written on `PickedUp` and `Delivered`, not before. |
| AH. Duplicate rider payout | ALREADY PROTECTED. The MySQL rider payout suite passed. |
| AI. Rider payout versus reversal | ALREADY PROTECTED. A second reversal is rejected. A stale confirmation cannot repay a reversed transfer. |
| AJ. Refund greater than collected COD | ALREADY PROTECTED. The amount must be within `refundableCollectedMinor()`. |
| AK. Duplicate refund | ALREADY PROTECTED. One active refund. A stale revision is rejected. MySQL platform-refund and return-decision races passed. |
| AL. Partial final refund | ALREADY PROTECTED under the current rule. A smaller refund is final. Installments were not added. |
| AM. Seller-funded refund | FIXED for the unpaid kept-net payout. Debt after an already paid settlement was already one row per order. |
| AN. Logistics-funded refund | ALREADY PROTECTED. The seller is not charged. The provider charge is the transit-damage amount. |
| AO. Platform-funded refund | ALREADY PROTECTED. It books a platform expense and does not create seller debt, a logistics charge, or a return-trip charge. |
| AP. Duplicate platform expense | ALREADY PROTECTED. One expense per refund event, with unique `refund_event_id` and `reference`. |
| AQ. Platform refund void | ALREADY PROTECTED. One reversal row. The original expense remains. |
| AR. Platform refund replacement | ALREADY PROTECTED. The replacement is a new event and a new expense. |
| AS. Platform refund versus commission | ALREADY PROTECTED. Finalized commission is not rewritten. |
| AT. Platform refund versus seller settlement | ALREADY PROTECTED. Seller balances and paid payouts stay. |
| AU. Shipping subsidy versus collected COD | POLICY DECISION REQUIRED. A marketplace shipping voucher larger than collected COD is accepted at checkout and then blocked at settlement. No cash is invented. No subsidy payout was added. |
| AV. Numeric precision | ALREADY PROTECTED for the guards that ran in `Rule10` and the financial suites: integer centavos, half-up commission, and rejection of a negative or over-cap refund. A separate fuzz of every integer boundary was not added. |
| AW. Date boundaries | ALREADY PROTECTED. Finance months use Asia/Manila, including the month-end UTC boundary covered by `FinancialLedgerIntegrityTest`. |
| AX. Dashboard accuracy | PARTIALLY PROTECTED. Dashboard and finance net both use commission minus marketplace voucher cost. Platform refund expense is a separate finance line and is absent from the dashboard. Seller payable on the dashboard does not call `isReconciled`. See section 8. |
| AY. CSV accuracy | FIXED for settled return charges, which were missing. CSV amounts for the existing summary still match the service. A void still removes the live refund from the cash ledger; the history and the expense reversal remain. |
| AZ. Alternate writers | ALREADY PROTECTED for the searched application code. No `DB::table` writes were found for the financial tables. Status and money writes go through the services above. |

## 5. Confirmed defects

### 5.1 Unpaid partial refund could not be paid

Severity: high. Code defect.

Reproduction: merchandise ₱100.00, no seller discount, shipping ₱0.00, collected COD ₱100.00, commission 10%. A ₱20.00 seller-funded refund leaves commission ₱8.00 and seller net ₱72.00 (`writeReturnSettlement`). `confirmPayout` and `isReconciled` still required commission plus net to equal the full ₱100.00 merchandise. Withdrawal and direct payout both refused the ₱72.00 that the settlement had stored as payable. If the return existed before voucher columns were copied, those columns stayed null and also failed the `0` comparison.

Root cause: the return writer and the payout identity encoded different equations. The cash snapshot was correct. The payout gate had not been updated for a kept-item refund.

Impact: the seller’s retained earnings stayed pending forever. The original ₱90.00 could not be paid either, so this did not overpay. It underpaid.

Fix: `payoutIdentityHolds` accepts the reduced net only when the return breakdown matches and the gap equals the refunded merchandise plus refunded shipping plus the return trip, while collected cash stays the original obligation. `writeReturnSettlement` stores the voucher snapshot. The pre-refund amount is still refused.

### 5.2 Settled return charges were missing from finance reports

Severity: medium. Reporting defect.

Reproduction: settle a ₱125.00 transit-damage charge owed to Zefanya and a ₱50.00 return trip owed to Logistics. Leave a ₱99.00 failed-delivery charge unsettled. The November ledger contained no `return_charge` rows. `FinancialLedgerIntegrityTest` expected two.

Root cause: `FinanceReportService::ledger` never read `logistics_return_charges`.

Impact: finance totals and the CSV omitted settled cash between Zefanya and Logistics. Unsettled charges were correctly absent, but only because nothing was read.

Fix: settled charges in the Manila month are ledger rows. `to_zefanya` is money in. `to_provider` is money out. The unsettled charge stays out.

### Seller earnings identity

Severity: low. Reporting defect, same orders as 5.1.

The earnings page said net equals gross minus store sales, seller vouchers, and commission. After the ₱20.00 refund the stored net is ₱72.00, and ₱100.00 minus ₱8.00 commission is ₱92.00. The page now shows a “Returns and charges” line of ₱20.00 when that gap is not zero. Stored `seller_net_minor` was not changed.

## 6. Fixes implemented

- `SellerSettlementService::payoutIdentityHolds` is the single payout equation for direct payout and for withdrawal eligibility. Ordinary orders still need commission plus net to equal merchandise after the seller-funded discount, and commission plus net plus shipping to equal collected cash plus marketplace funding. A seller-funded partial refund may be lower by exactly the recorded refund and return charges.
- `writeReturnSettlement` persists `seller_funded_discount_minor` and `marketplace_funded_discount_minor` from the saved allocation.
- Seller earnings summary and seller report expose `returnAdjustmentMinor`. The earnings page and the report sentence show it when it is not zero.
- The finance ledger and CSV type list include settled return charges. Unsettled charges are not listed.

No commission rate, shipping percentage, COD rule, debt policy, or historical row was changed.

## 7. Financial reconciliation

Worked seller-funded partial refund, integer centavos, shown in pesos.

| Item | 10% order | Same order after a ₱20.00 refund | Historical 5% after the same refund |
|---|---:|---:|---:|
| Merchandise | 100.00 | 100.00 | 100.00 |
| Collected COD | 100.00 | 100.00 | 100.00 |
| Commission | 10.00 | 8.00 | 4.00 |
| Seller net paid | — | 72.00 | 76.00 |
| Check | 10 + 90 = 100 | 8 + 72 + 20 = 100 | 4 + 76 + 20 = 100 |

The original ₱90.00 payout is rejected. The kept net is what gets paid. Collected COD is not reduced by the refund record. The refund remains its own record.

Settled return charges in the finance ledger:

| Charge | Direction | Ledger |
|---|---|---|
| Transit damage ₱125.00, settled | Company to Zefanya | Money in 125.00 |
| Return trip ₱50.00, settled | Zefanya to company | Money out 50.00 |
| Failed delivery ₱99.00, not settled | — | Omitted |

Platform earnings formula, unchanged:

`net = commission − marketplace item vouchers − marketplace shipping vouchers`

Platform refund expense is `expenses booked in the Manila month − reversals booked in that month`, reported beside net and not inside it.

## 8. Reporting verification

The finance page and `PlatformFinanceService::earnings` use the same commission and voucher totals. The CSV summary writes those service figures. Voucher costs are shown as negative on the page and as positive amounts in the CSV, which is the same convention as the other cost lines.

“Net platform earnings” means earnings before platform refund expense. The page footnote and the CSV label already say the expense is not deducted. The dashboard uses the same net and does not show the expense. Recommended label, not applied: “Net before platform refund expense.” Applying it would be a copy change, not a change to the amount. It was left for approval so the existing metric is not quietly renamed.

A void clears `refunded_at`, so the cash refund leaves the ledger month where it was first recorded. The refund history event remains, and a platform-funded void still has its expense and reversal. Putting a reversing cash row back into the ledger would change existing CSV totals, so it was not done.

`position()` seller payable still sums pending settlements with a completed order and no hold. It does not call `isReconciled`. After this fix, a reconciled partial refund is payable in both places. A settlement that fails the identity for some other reason can still be counted on the dashboard until payout refuses it.

## 9. Testing results

SQLite uses `phpunit.xml` (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`). MySQL uses `phpunit.mysql.xml` and `tests/MySql/bootstrap.php`, which refuses any database other than isolated `zefanya_concurrency_test` on `127.0.0.1`.

### Focused SQLite

| Run | Tests | Passed | Failed | Errors | Skipped | Assertions | Time |
|---|---:|---:|---:|---:|---:|---:|---:|
| Partial-refund payout, including the new ₱72.00 case and the 5% ₱76.00 payout | 3 | 3 | 0 | 0 | 0 | 44 | 13.0s |
| Earnings, payouts, return decisions, finance page, platform-funded refunds, commission and shipping | 257 | 257 | 0 | 0 | 0 | 1,941 | 54.3s |
| Return-charge ledger test plus two existing finance assertions, after the ledger fix | 3 | 3 | 0 | 0 | 0 | 31 | 8.4s |

### MySQL / InnoDB

`CodRemittanceConcurrencyTest`, `RiderPayoutConcurrencyTest`, `PlatformRefundConcurrencyTest`, `FinancialLedgerIntegrityConcurrencyTest`, `ReturnDecisionConcurrencyTest`, `FinancialCalculationConcurrencyTest`, and `SellerPayoutConcurrencyTest`:

65 passed, 0 failed, 0 errors, 627 assertions, 883.9 seconds.

These overlap the critical sections for duplicate collection, delivery versus collection, rider and logistics remittance, seller payout versus debt and refund, rider payout versus reversal, and platform expense versus void. They are real concurrent connections, not sequential calls in one process. Origin-share pay versus origin reversal was covered by the SQLite logistics remittance tests in the broader run, not by a separate MySQL race in this pass.

### Broader SQLite

`tests/Feature/Logistics`, `tests/Feature/Seller`, `tests/Feature/Order`, `tests/Feature/Admin/AdminFinancePageTest.php`, and `tests/Feature/Admin/FinancialLedgerIntegrityTest.php`:

1,933 tests: 1,921 passed, 5 failed, 3 errors, 4 skipped, 15,026 assertions, about 17.7 minutes. No timeouts.

Failures and errors, reassessed against Audit 1.15:

| Test | Result | Relation to this audit |
|---|---|---|
| `DemoMarketplaceTest::test_marketplace_seeds_accounts_catalog_images_routes_and_supports_delivery_flows` | Failure: product status `pending`, expected `active` | Same demo-catalog approval gap as Audit 1.15. Not changed. |
| `DemoMarketplaceTest::test_negative_checkout_blocks_unsupported_destination` | Error: PSGC validation unavailable | Environment error, not a ledger change. |
| `DemoNetworkSeedersTest::test_demo_network_seeders_build_complete_coverage` | Error: PSGC validation unavailable | Same. |
| `NinjaExpressLagunaDemoTest::test_marketplace_seeds_accounts_catalog_images_routes_and_supports_delivery_flows` | Error: PSGC validation unavailable | Same demo flow. |
| `EdGamingCatalogSeederTest::test_it_lists_forty_purchasable_products_with_real_photos_once` | Failure on DualSense listing visibility | Pre-existing catalog expectation. Not changed. |
| `SellerOrderDeclineTest::test_order_list_shows_only_the_important_columns` | Failure: the HTML no longer matches an old disabled-button string | Order-list markup. Not a money calculation. |
| `SellerVacationModeTest::test_open_seller_can_be_checked_out_from_a_mixed_cart_without_touching_vacation_items` | Failure: unexpected session `error` | Checkout harness. Audit 1.13 already recorded checkout-token test debt. Not changed. |
| `FinancialLedgerIntegrityTest::test_settled_return_charges_appear_once_in_finance_and_csv_in_their_actual_directions` | Failed in this broader run, then passed after the ledger fix | This was the reporting defect in section 5.2. |

The full PHPUnit suite and `tests/Feature/Logistics/DemoMarketplaceTest` outside this broader run were not run again. Checkout-token failures outside Logistics, Seller, Order, and these admin finance files were not remeasured.

The seller earnings assertion was rendered by the feature test. A logged-in browser session was not opened.

## 10. Historical integrity

No migration updated existing rows. Paid settlements, paid payout requests, saved `commission_bps`, shipping snapshots, and platform expense rows were not rewritten. The payout fix applies when a payout is attempted. It does not change amounts already stored. The ledger fix only changes what a later report reads.

## 11. Unresolved policy decisions

1. Commission on a seller-funded return can be recognized before `Completed`. Whether that should wait for completion is undecided. The code and the existing discrepancy test were left as they are.
2. A marketplace shipping voucher can exceed collected COD. Settlement refuses to invent cash. Who funds the carrier, and whether the voucher should be capped, is undecided.
3. Three or more logistics providers stay on amountless `pending_policy` rows. No intermediate split was invented.
4. A partial refund is final. There is still no authorized remainder and no installment refund.
5. Confirmed Logistics-to-Zefanya cash has no reversal workflow. Reject exists only before confirmation.
6. Whether “Net platform earnings” should be renamed, and whether voided refunds should reappear in the cash ledger as reversals, needs a reporting decision. The amounts were not redefined.

## 12. Files changed

Production:

- `app/Services/Seller/SellerSettlementService.php`
- `app/Services/Seller/SellerPayoutRequestService.php`
- `app/Services/Seller/SellerEarningsService.php`
- `app/Http/Controllers/Seller/ReportController.php`
- `app/Services/Admin/FinanceReportService.php`
- `resources/views/Seller/Earnings/index.blade.php`
- `resources/views/Seller/Reports/index.blade.php`

Tests:

- `tests/Feature/Seller/SellerReturnDecisionTest.php` (new payable kept-net case, and the 5% case now pays ₱76.00)

Documentation:

- `docs/audits/ZEFANYA_AUDIT_1_16_FINANCIAL_LEDGER_AND_REPORTING_INTEGRITY.md`

No migration. `vendor/bin/pint --dirty` also reformatted already dirty `tests/MySql/worker.php` and the untracked financial concurrency tests (imports and line endings). Their assertions were not edited for this audit.

`graphify-out/` was created by the code-only index and is untracked.

## 13. Final status

PARTIALLY PROTECTED — REMAINING RISKS

The two confirmed code and reporting defects are fixed and covered by the SQLite cases above. The MySQL concurrency suite that was run passed. Policy gaps in section 11 remain, and the full application suite was not rerun.
