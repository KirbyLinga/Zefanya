# Zefanya Audit 1.8 — High-Value Payout Authorization and Approval Bypasses

Date: 2026-11-12. Scope: existing Seller and Rider payouts in `WebZefanya`. Investigation uses the current working tree, including Audit 1.1–1.7 changes. No production financial data was accessed or modified; existing uncommitted work was preserved.

## 1. Overall result

One confirmed **Medium severity reporting/accounting defect** was reproduced and fixed: the supported direct Seller settlement payout path was absent from the Admin transfer ledger, Seller paid-withdrawal total and Seller transaction running balance. This could leave a reported balance after the Seller was fully paid. The actual payout eligibility service still prevented spending that reported balance again.

No new unauthorized payout approval, excessive-payment, cross-provider payment or stale-confirmation bypass was confirmed. New tests exercise ₱10,000, ₱100,000 and ₱1,000,000 amounts, forged HTTP financial/identity fields, audit-write rollback and account restrictions. Real MySQL coverage includes competing high-value requests and confirmations, Rider batches, refund/debt races and payout reversals.

No second approver, new threshold/limit, OTP, dashboard, notification, payment integration, PDF/CSV feature, commission change, forfeiture/freeze rule or COD shortage deduction was introduced.

## 2. Seller payout workflow

### Authority and actions

| Action | Existing boundary |
|---|---|
| Request / see own history | `auth:seller` + `seller.approved`, scoped to authenticated Seller. Amount is parsed as decimal pesos without floating-point truncation. Destination is required by the existing HTTP form. |
| Cancel own pending request | Same Seller boundary; service scopes by Seller and request ID. Cancellation retains history and releases the reservation. |
| View platform requests/history | `auth:admin` and `admin.role:admin`; Super Admin also passes. Moderator/Support cannot approve via this route. |
| Confirm / reject request | Admin or Super Admin. The authenticated Admin, not a submitted approver ID, is stored in the request and audit. |
| Direct settlement confirmation | Alternate `admin/settlements/{settlement}/confirm` route is Super Admin only and requires a reason. Admin/Seller/other role HTTP bypasses are rejected. |
| Change payment details | Seller supplies destination when creating a request; Admin supplies transfer reference at confirmation. No arbitrary confirmed-record edit endpoint was found. |
| Reverse / correct a paid Seller payout | No supported Seller-payout reversal/edit route found. A paid request retry does not replace the reference/actor/date. No new reversal mechanism was added. |

Sources: `routes/seller.php:43`, `routes/admin.php:73` and `:79`; `app/Http/Controllers/Seller/PayoutRequestController.php`; `app/Http/Controllers/Admin/SellerPayoutController.php`; `app/Http/Controllers/Admin/SellerSettlementController.php:24`.

`SellerPayoutRequestService::request()` (`app/Services/Seller/SellerPayoutRequestService.php:62`) locks the Seller, checks approved status, idempotency and the existing single-pending-request rule, then selects completed/finalized/reconciled earnings. COD collection, confirmed remittance to Logistics and confirmed receipt by Zefanya must agree with order/settlement funding snapshots. It reserves settlement allocations and quotes the applicable debt deduction. Available earnings are gross unreserved eligible earnings; cash sent is request amount minus debt deduction. The existing minimum remains unchanged.

`pay()` (`:157`) authorizes the Admin and locks Seller → request → allocated settlements → debts. It rejects nonpending state, stale deduction and changed settlement eligibility before payment. Current debt deduction is `min(request amount, locked outstanding debt)` (`:180`). Paid allocations increase `paid_amount_minor`; debts recover oldest ID first, capped at remaining debt. Request state, recovery, settlement changes and the `seller_payout_paid` audit commit together. A paid retry returns without another payment/audit or changed evidence. Reject/cancel release reservations rather than recovering debt.

`SellerSettlementService::confirmPayout()` (`app/Services/Seller/SellerSettlementService.php:125`) serializes against withdrawal reservations, rejects pending allocations/debt/holds/unreconciled cash, and requires exactly the still-owed amount. The exposed controller requires Super Admin and a reason; it writes an audit in the same transaction. The internal method accepts money Admins and an optional reason for trusted callers; the source search found only that protected controller as its production caller. No alternate HTTP endpoint using a weaker service call was found. This report does not equate the internal method's contract with the stricter HTTP contract.

Schema safeguards include unique `(seller_id, idempotency_key)`, unique pending Seller, unique request/settlement allocation and restrictive financial foreign keys (`database/migrations/2026_10_27_170606_create_seller_payout_requests_tables.php`). These support transaction/locking safeguards rather than replacing them.

## 3. Rider payout workflow

Rider payouts are independent of Seller payout approval. An approved Logistics account owns the earning jobs and records a manual payment to its Courier. Rider earnings and cash/COD shortages are separate records.

`routes/logistics.php:36` applies `auth:logistics` and `logistics.approved`. Payment and reversal routes are at lines 45–46. `Logistics/EarningsController::payRider()` validates positive integer expected amount, job cutoff, history revision, reference and Philippine payment date (`app/Http/Controllers/Logistics/EarningsController.php:56`). Provider identity comes from the guard and Courier from the route, not submitted IDs.

`RiderEarningService::payCourier()` (`app/Services/Rider/RiderEarningService.php:163`) checks provider/Courier ownership and locks the Courier. It selects only that Courier's unpaid jobs belonging to the authenticated provider through the displayed cutoff. It sums server amounts and requires exact equality to `expected_amount_minor`, then checks payment/reversal history revision. It stores a batch, marks exactly those earnings paid and appends an audit in one transaction. The audit actor is the authenticated **Logistics company account**; there is no separate per-company employee identity field to infer.

`reversePayout()` (`:220`) checks provider ownership, locks Courier/payout/allocations, validates count and total, refuses a second reversal, records an immutable operations audit and releases the allocations. Original payout amount, reference and date remain unchanged. A subsequent replacement must use the current history revision. Reversing the application record does not reverse an external cash/bank transfer.

Riders see their own money page/API projections. A Logistics company sees only its own payout history and job batches. A different provider cannot pay/reverse another provider's records. `RiderPayout::reversal()` reads the append-only audit; `paidThisMonthMinor()` excludes reversed payouts, and unpaid earnings/paid summaries follow allocation links. Logistics expense reports keep Rider pay separate from remittance cash and shortages.

Schema: `database/migrations/2026_11_03_090000_create_rider_earnings_table.php` makes shipment/job unique; `2026_11_03_100200_create_rider_payouts_table.php` stores batch amounts and restrictive allocation foreign keys. Courier locks, exact totals and history revisions prevent duplicate or stale payment, including after reversal.

## 4. Scenarios A–T

| Scenario | Classification | Evidence / result |
|---|---|---|
| A — unauthorized Seller approval | ALREADY PROTECTED | New HTTP matrix tests Buyer, own Seller, Rider, Logistics, Moderator and Support against confirm/reject/direct-settlement routes. Other guards receive 401; unauthorized Admin roles receive 403; no financial state changes. |
| B — unauthorized Rider payment | ALREADY PROTECTED | Provider-authenticated route and provider/Courier/earning scopes. Existing cross-company tests reject payment and reversal. No Rider/Admin substitute for the Logistics guard. |
| C — high-value amount manipulation | ALREADY PROTECTED | New ₱10,000 / ₱100,000 / ₱1,000,000 cases pass only against sufficient server balances. Excess/zero/negative/overflow/unsupported precision/array input is rejected through existing validation and exact amount checks. No new business maximum. |
| D — HTTP tampering | ALREADY PROTECTED | Tests forge Seller/provider/Courier IDs, settlement/request IDs, amount, status, deduction and approval identity. Selected controller inputs and route/auth identities prevail. Reference and Rider date remain legitimately entered, validated attestation fields. Seller confirmation timestamp is server-generated. |
| E — Seller self-approval | ALREADY PROTECTED | Own Seller guard is explicitly tested against every exposed confirmation path; Admin auth/role is still required. A single permitted Admin approver is not classified as a vulnerability. |
| F — duplicate Seller confirmation | ALREADY PROTECTED | MySQL simultaneous Admin confirmations produce one payment/audit. Seller/request locks, paid-state replay handling and settlement allocation caps persist. |
| G — duplicate Rider payment | ALREADY PROTECTED | MySQL Courier-lock tests exercise the same earning batch concurrently, including a ₱1,000,000 case; one batch/audit commits. |
| H — stale approval | ALREADY PROTECTED | Paid retries preserve prior evidence; rejected/cancelled requests fail state checks. Settlement eligibility and debt are revalidated under locks. Existing refund/debt and duplicate-confirmation races remain covered. |
| I — debt created after request | ALREADY PROTECTED | Audit 1.5's current locked deduction comparison rejects an under-deducting stale quote, including former zero deduction; Admin must use existing rejection/resubmission process. No recovery policy changed. |
| J — suspended accounts | ALREADY PROTECTED | Seller restriction applies to requesting/cancelling, not an automatic forfeiture of earned funds; Admin payout confirmation has no new Seller-status freeze. Logistics middleware blocks a suspended provider. New test verifies its blocked request and preserves payment of earned funds to a suspended Rider once the provider is approved. Admin has roles/2FA controls but no implemented suspension field/workflow to test as such. |
| K — settlement correction | ALREADY PROTECTED | Requested and direct payment paths recheck current eligible amount, hold, commission/funding/cash association and reservations. Existing MySQL partial-refund/direct-payout race rejects stale amounts. |
| L — cross-provider earnings | ALREADY PROTECTED | Both Courier ownership and earning provider scopes are enforced; client provider/job IDs cannot move another company's batch. Allocations checked again on reversal. |
| M — history tampering | ALREADY PROTECTED | No paid-record edit/delete routes found. Paid Seller retries do not replace evidence. Rider reversal leaves original payment intact and appends protected operations audit. Existing failed/changed-allocation reversal tests preserve history. |
| N — external reference reuse | OPERATIONAL GAP | Seller/Rider payout references are attestations, not provider-verified globally unique transfer IDs. Cross-payment reuse is not independently detected. No structured bank/wallet namespace establishes a safe global uniqueness rule; none was invented. |
| O — transaction failure | ALREADY PROTECTED | Added requested-payout audit failure rolls back payout, paid settlement and debt recovery, then permits one correct retry. Existing direct-payment, Rider-payment and reversal audit/transaction failures remain passing. |
| P — concurrent high values | ALREADY PROTECTED | New MySQL Seller fixture has ₱1.8m available; competing ₱1m requests reserve only one. Concurrent confirmation pays once, leaving ₱800k. Rider competing high-value batches cannot apply ₱4m against ₱3m jobs. |
| Q — approval identity | ALREADY PROTECTED | HTTP-forged approver IDs do not replace the authenticated Admin/company. New assertions inspect both payout and audit identity at high values. Logistics actor identifies the company account, not an unimplemented employee user. |
| R — alternate endpoints | ALREADY PROTECTED | Mutation search covered request/cancel/pay/reject, direct settlement, Rider pay/reverse, routes, API and console callers. Only the protected direct-settlement route bypasses requesting, while retaining reservations/debt/reconciliation checks. Rider APIs expose earnings, not payout recording. Internal service trust boundary is described above. |
| S — reports | CONFIRMED VULNERABILITY — FIXED | Direct settlement transfers were omitted from existing Admin/Seller records. New read-only calculation includes only their paid portion not already allocated to paid requests, including mixed paths and cross-month cases. Debt deductions count as recovery, not cash sent. Rider reversals and shortage separation remain unchanged. |
| T — external verification | OPERATIONAL GAP | “Paid” records mean the permitted Admin/company attested to manual payment. Interfaces say payout/payment “recorded”; no independently verified bank settlement code or automatic transfer integration was found. No verification claim or workflow was added. |

## 5. Confirmed defect

### P1 — direct Seller transfers omitted from financial reporting (Medium)

**Affected locations:** `app/Services/Admin/FinanceReportService.php:184` previously loaded only paid requests; the new direct-settlement block starts at line 193. `app/Services/Seller/SellerEarningsService.php:96` previously summed only requests. `app/Services/Seller/SellerTransactionService.php:34` loaded settlement earnings, while only request rows supplied negative withdrawals; direct withdrawal insertion now starts at line 48.

**Reproduction:**

1. Prepare reconciled eligible earnings of ₱584.10.
2. Confirm the supported direct settlement through the Super Admin endpoint with reference and reason.
3. Observe that settlement is paid, yet the former Admin outgoing total is ₱0 and Seller paid withdrawals/transaction running balance omit the transfer.
4. Alternatively, pay ₱200 through a request, then directly confirm the remaining ₱384.10. The former Admin ledger contains one ₱200 row; the remaining payment is missing. Both regressions failed before the source fix.

**Root cause:** the direct and requested payment paths update settlement accounting consistently, but read-side withdrawal/transfer queries assumed every transfer had a `SellerPayoutRequest`. A paid settlement cannot simply be added at its full amount because paid request allocations may already cover part/all of it.

**Impact:** understated outgoing payments and paid-withdrawal totals; overstated Seller transaction balance; misleading reconciliation of high-value transfers. Eligibility still derives from remaining settlements and reservations, so this reporting defect did not itself authorize a duplicate withdrawal.

**Existing protections / insufficiency:** direct-route Super Admin authorization, reason, current reconciliation, exact remaining amount, reservation/debt checks and audit already protected payment. They did not populate request-only reports or deduct direct payments from the Seller's running statement.

## 6. Minimal production fixes and files modified

The direct portion is read-only:

```text
direct paid portion = max(0, settlement.paid_amount_minor - sum(allocations belonging to PAID payout requests))
```

Only a `PAID` settlement with a recorded `paid_at` qualifies. Subtracting all-time paid allocations prevents both request-only duplication and double-counting when a request was paid in an earlier month. The direct transfer uses settlement `paid_at`/reference; request transfers retain their own timestamp/reference and cash amount after debt deduction. Missing legacy paid amount/date is not silently manufactured.

| Production file | Audit 1.8 change |
|---|---|
| `app/Models/Seller/SellerSettlement.php:73` | Paid-request allocation relation and derived direct-payment amount. No stored financial value changes. |
| `app/Services/Admin/FinanceReportService.php:193` | Include actual direct portion in the existing Seller payout ledger type. No new report/UI/export feature. |
| `app/Services/Seller/SellerEarningsService.php:96` | Include direct portion in existing gross paid-withdrawal total. |
| `app/Services/Seller/SellerTransactionService.php:37` | Eager-load paid allocations and include direct withdrawal in the existing transaction/running balance. |

Test/document files changed:

- `tests/Feature/Seller/SellerPayoutRequestTest.php`: direct/mixed/request-only/cross-month reporting; mixed debt/cash; high-value HTTP fields/identity; unauthorized role matrix; requested-payment audit rollback.
- `tests/Feature/Rider/RiderEarningsTest.php`: high-value server totals, forged provider/Courier fields and actor identity, invalid expected amounts, provider suspension versus legitimate Rider entitlement.
- `tests/MySql/SellerPayoutConcurrencyTest.php`: high-value competing request/confirmation scenario, remaining direct payment and real MySQL reporting assertions.
- `tests/MySql/RiderPayoutConcurrencyTest.php`: preserve baseline cases and add high-value datasets for duplicate and overlapping batch confirmation.
- This report.

No approval service, existing entitlement/formula, Rider earning calculation, debt recovery rule, COD shortage rule, financial record, migration, auth workflow or production configuration was changed. Earlier uncommitted audit work was retained. No commit or push.

### Financial examples

| Payment mix | Gross earnings consumed | Debt recovered | Cash outgoing reported |
|---|---:|---:|---:|
| Direct only | ₱584.10 | ₱0 | ₱584.10 |
| Requested ₱200 + direct ₱384.10 | ₱584.10 | ₱0 | ₱584.10, once across two rows |
| Same mix, request recovers ₱50 debt | ₱584.10 | ₱50 | ₱534.10 |
| Request-only full payment | ₱584.10 | ₱0 | ₱584.10; no second settlement transfer |

Seller paid withdrawals continue to show gross withdrawn earnings (including recovery), while Admin outgoing cash excludes debt deductions. This distinction preserves the existing accounting policy.

## 7. Verification

Baseline SQLite: **113 tests / 757 assertions passed** across Seller payout, Rider earnings, Admin finance and operations audit. Baseline isolated MySQL Seller/Rider suites: **18 tests / 257 assertions passed**.

Both direct/mixed reporting reproductions failed before the fix and passed afterward (**2 tests / 12 assertions**). Additional request-only and cross-month checks passed (**2 tests / 6 assertions**). New Rider boundary/status checks passed (**11 tests / 63 assertions**).

One new rollback test initially compared an unrefreshed model missing database-default columns with its reloaded version. Its fixture snapshot was corrected to reload before comparison; the injected-failure test then passed (**1 test / 6 assertions**). This test-authoring mismatch was not an application defect.

Final SQLite command:

```text
php vendor/bin/phpunit tests/Feature/Seller/SellerPayoutRequestTest.php tests/Feature/Rider/RiderEarningsTest.php tests/Feature/Admin/AdminFinancePageTest.php tests/Feature/Logistics/OperationsAuditLogTest.php tests/Feature/Seller/SellerReturnDecisionTest.php tests/Feature/Logistics/CompleteFinancialScenariosTest.php tests/Feature/Admin/OrderMonitoringTest.php
```

Final MySQL command:

```text
php vendor/bin/phpunit --configuration phpunit.mysql.xml --filter 'SellerPayoutConcurrencyTest|RiderPayoutConcurrencyTest'
```

The existing MySQL preflight verifies the disposable `zefanya_concurrency_test` database/account, server identity, restricted grants, denied application-schema access and InnoDB. Concurrent workers use separate connections and the harness observes actual overlapping locking statements. Evidence is retained under `storage/logs/payout-races` and `.codex/t05-runs` for the existing Rider harness. SQLite tests alone are not used as concurrency proof. High-value fixtures represent synthetic accumulated balances, not new per-job Rider rates or commission rules.

Final SQLite run: **276 tests / 2,548 assertions passed**. Final isolated MySQL run: **21 tests / 293 assertions passed**. The added high-value Seller race reserved and paid one request, retained the remaining payable balance and correctly reported the later direct remainder on MySQL. High-value duplicate/overlapping Rider batches were prevented; existing refund/debt and reversal races remained passing.

`php vendor/bin/pint --dirty --format agent` completed; final targeted Pint `--test` and `git diff --check` passed. Final failing tests: **none**. Pre-fix reporting failures and the new rollback-test fixture correction are disclosed above.

Not executed: unrelated full repository suite, production financial reconciliation, live browser operations or external bank/wallet verification. No frontend files changed, so no frontend build was needed.

## 8. Remaining limitations and authorization assessment

- Existing exposed payout authorization is adequately enforced for the tested supported paths: Seller money decisions require permitted Admin roles, direct HTTP settlement requires Super Admin, and Rider payments require the owning approved Logistics account.
- No new high-value approval threshold or second-approver policy exists or was assumed. Their absence alone is not a demonstrated software defect.
- The application records external payment attestations. It cannot establish transfer authenticity, destination ownership, reference uniqueness across providers/accounts or actual bank reversal/recovery. Reference reuse and external reconciliation remain operational risks.
- Logistics audit identity is a company account. A separate employee approval identity/workflow was not inferred or added.
- The corrected direct-payment calculation trusts existing paid amount/date/allocation evidence. Incomplete or inconsistent legacy records need read-only evidence-led review; no historical repair or inferred payment backfill was performed.
- Internal payout service methods have trusted caller contracts narrower/different from HTTP middleware guarantees. The current source search found no exposed weaker caller. No speculative new authentication policy was inserted into those internal APIs.

## 9. Final status

**FIXED AND TESTED.** The confirmed reporting defect is fixed and verified with focused SQLite regressions and real MySQL financial/concurrency checks. No new authorization/approval bypass was confirmed. Existing manual payout authorization, entitlements and financial write protections were preserved; external attestation/reconciliation limitations remain as documented.
