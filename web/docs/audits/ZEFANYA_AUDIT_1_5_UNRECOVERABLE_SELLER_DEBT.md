# Zefanya Audit 1.5 — Unrecoverable Seller Debt

Date: 2026-11-12. Scope: `WebZefanya` only. Investigation uses the current working tree, including existing uncommitted Audit 1.2/1.4 protections. No production database was queried or changed. No historical balances were rewritten.

## 1. Investigation result

Two confirmed software defects were reproduced and minimally fixed:

1. A pending payout could bypass recovery of debt created or increased after its request was recorded.
2. The monthly finance report and CSV summary overstated money still owed by including already recovered debt.

Debt remaining unpaid when a Seller stops earning is an operational exposure, not itself an accounting vulnerability. Suspension and elapsed time do not erase the debt. The platform dashboard already calculates the correct all-time outstanding balance; it was preserved.

## 2. Existing lifecycle and authoritative evidence

1. COD collection, remittance confirmation, voucher allocation and commission finalization establish eligible earnings. `SellerSettlementService::prepare()` (`app/Services/Seller/SellerSettlementService.php:33`) and `SellerPayoutRequestService::isReconciled()` enforce the existing funding and settlement requirements.
2. A Seller requests gross earnings. The request reserves settlement allocations and snapshots `min(request amount, outstanding debt)` as `debt_deducted_minor`. Requesting, cancelling or rejecting does not recover debt (`SellerPayoutRequestService.php:79`, `:135`, `:254`). Available balance means gross eligible unreserved earnings, not the net bank transfer; preserve this existing formula.
3. Admin records a refund through `ParcelReturnService::recordRefund()` (`app/Services/Logistics/ParcelReturnService.php:207`). It validates role, collected COD, amount and refund revision; refund evidence and its event are transactional with settlement/debt changes. Voids retain the previous refund in history (`:251`).
4. `SellerSettlementService::returnBreakdown()` calculates the existing liability. `recordDebtForPaidReturn()` (`:495`) updates or creates one debt per Seller Order. Already paid settlement figures remain historical evidence. Unknown voucher funding does not justify manufacturing a debt. Failed deliveries can create debts without settlements (`:395`).
5. Return debt is `max(0, paid - retained seller earnings) + shortfall`; Logistics fault establishes no Seller liability. Corrections use `max(settled_minor, newly calculated liability)` (`:511`). Cancellation restoration also stops at the recovered floor (`:325`). These are supported liability corrections, not discretionary forgiveness.
6. Admin sees per-order debt in Order Monitoring (`app/Services/Admin/OrderMonitoringService.php:258`; `resources/views/Admin/OrderMonitoring/_financials.blade.php:104`), refund liability in the existing return component, monthly entries in Finance, and all-time debt on the dashboard.
7. Admin payout confirmation locks Seller, request, allocated settlements and outstanding debts. It now validates the deduction against the current locked debt total before spending allocations. Recovery increments `settled_minor`, ordered by debt ID ascending, with each increment capped at outstanding debt (`SellerPayoutRequestService.php:230`). Request status, settlement changes, recovery and `seller_payout_paid` audit commit together. A second confirmation returns without another recovery.
8. Direct settlement payout is blocked when debt exists (`SellerSettlementService.php:162`); it cannot bypass the payout-request recovery path. No external bank or wallet debit is performed.

### Field map

| Information | Authority | Stored or calculated / limitations |
|---|---|---|
| Recorded debt / current liability | `seller_debts.amount_minor` | Stored centavos. Preserved during recovery, but legitimately revised by refund/fault/cancellation correction. **Not an immutable original amount.** |
| Already recovered | `seller_debts.settled_minor` | Stored cumulative centavos; preserved by liability corrections. |
| Remaining | `SellerDebt::outstandingMinor()` (`app/Models/Seller/SellerDebt.php:27`) | Calculated `max(0, amount_minor - settled_minor)`. |
| Created date | `seller_debts.created_at` | Stored; allows age since row creation, not necessarily age of each later liability increase. |
| Last recovery date | No dedicated field | `updated_at` also changes on liability corrections; cannot reliably identify last recovery. |
| Seller / order / settlement | `seller_id`, unique `seller_order_id`, nullable `seller_settlement_id` | Stored foreign keys. |
| Refund reference | Order → `parcel_returns` and refund events | No direct refund foreign key on debt. One mutable debt summarizes the order's current obligation. |
| Status | Comparison of amount and settled | Derived: outstanding, partial (`0 < settled < amount`), settled (`settled >= amount`). No stored debt status. UI generally labels partial as “Open” with remaining amount. |
| Recovery evidence | Paid requests' `debt_deducted_minor`, `paid_at`, payout audit | Stored payout-level recovery amount; **no per-debt repayment allocation ledger** or guaranteed last-recovery timestamp. |
| Platform total | `PlatformFinanceService::position()` (`app/Services/Admin/PlatformFinanceService.php:98`) | All debts with `settled < amount`, summed as `amount - settled`, independent of Seller status/month. |

Schema: `database/migrations/2026_11_03_140000_create_seller_debts_table.php:14` uses restrictive Seller/order/settlement foreign keys; line 15 makes order unique. `2026_11_06_130000_create_logistics_return_charges_table.php:36` makes settlement nullable for failed deliveries. The unsigned fields are not a database CHECK enforcing `settled <= amount`; application services enforce the supported lifecycle. Direct database edits remain outside that authorization boundary.

## 3. Scenarios A–N

Classifications describe the investigated behavior; fixed vulnerabilities remain identified as such.

| Scenario | Classification | Evidence and outcome |
|---|---|---|
| A — stops selling | ALREADY PROTECTED | Debt has no expiry. New 121-day regression retains attributes and global outstanding balance with no eligible earnings. `routes/console.php` schedules no debt deletion/forgiveness job. Aging UI is a separate operational gap. |
| B — suspension | ALREADY PROTECTED | `AccountStatusService::transition()` changes account status and records account history, without debt mutations. Existing HTTP suspension regression preserves debt, payout and dashboard amount. Finance debt queries do not filter approved Sellers. |
| C — account closure/deletion | NOT APPLICABLE | No Seller account closure/deletion route found in web/API/account controllers. Registration replacement archives rejected/abandoned applications; it is not closure of trading accounts. Restrictive financial foreign keys additionally reject parent deletion (regression added). |
| D — partial recovery | ALREADY PROTECTED | Original recorded amount stays fixed during recovery; cumulative recovery and outstanding amounts change correctly. Added exact ₱2,000 / ₱700 / ₱1,300 regression, including zero sent and retry safety. |
| E — multiple debts | ALREADY PROTECTED | Each order has one row; seller-wide aggregation includes all open rows. Existing two-order recovery test proves ID-ascending priority and capped recovery across requests. No age-based priority policy added. |
| F — refund/payout race | CONFIRMED VULNERABILITY — FIXED | Stale deductions now fail against a current locking read. MySQL coverage includes refund-before-payout debt reductions, creation/increase, payout-before-refund, duplicate refund and duplicate payout. See finding 1. |
| G — correction after partial recovery | ALREADY PROTECTED | Existing refund regression preserves the recovered floor; amount cannot fall below recovered balance. Refund revision checks reject stale correction attempts. Earlier liability amounts require refund-event reconstruction; no immutable debt-original field exists. |
| H — Admin reporting | CONFIRMED VULNERABILITY — FIXED | Monthly owed total used gross debt including recoveries; now sums outstanding amounts. Dashboard total was already correct. Monthly rows provide Seller, tracking/order reference, creation timestamp, recorded amount and status/remaining; per-order monitor explicitly shows settled amount. No single aging/recovery-history dashboard exists. |
| I — aging | OPERATIONAL GAP | No 0–30 / 31–60 / 61–90 / >90-day grouping found. Read-only grouping by `created_at` is feasible, but would measure row age rather than each liability revision. Optional only; not implemented. |
| J — unauthorized manipulation | ALREADY PROTECTED | Debt model guards all mass assignment; no debt CRUD or arbitrary adjustment endpoints found. Seller routes require approved Seller auth and scope by Seller; Admin money roles are checked at routes and services. Existing access tests and new forged-fields test verify requests cannot supply debt/status/recovered values. |
| K — duplicate creation | ALREADY PROTECTED | Unique order constraint, order/debt locking and transactional updates; repeat return and concurrent duplicate-refund regressions preserve one debt/refund. |
| L — unsupported write-off | NOT APPLICABLE | No debt forgiveness, expiry, suspension clearing or cleanup mechanism found. Supported fault/cancellation/refund corrections can reduce liability, always retaining recovered amounts. No new write-off mechanism introduced. |
| M — insufficient earnings | ALREADY PROTECTED | ₱1,500 debt plus ₱200 eligible gross earnings allows a ₱200 request under the existing minimum; all ₱200 recovers debt, ₱0 is sent, ₱1,300 remains. Recovery is capped, no negative payout or external debit. Forged deductions cannot bypass this. New partial-recovery regression exercises the same all-deducted path. |
| N — historical integrity | NOT VERIFIED | Source permits partial reconstruction, but no production records were inspected. Missing immutable liability revisions and per-debt payout allocations can prevent exact historical reconstruction. No historical repair performed. |

Seller suspension history stays available. Registration archival uses a global Seller scope, so an anomalously archived historical Seller may lose its display name through normal relationships, while unscoped debt totals remain intact. Trading-account archival is not an exposed closure workflow; no speculative scope change was made.

## 4. Confirmed vulnerabilities

### Finding 1 — stale payout deduction bypasses recovery (High)

**Source:** `app/Services/Seller/SellerPayoutRequestService.php:174`–current validation starts at line 180; the former path proceeded directly from allocation loading to settlement payment and `repayDebts()`. The latter still has its legitimate zero-deduction early return at line 232.

**Root cause:** the deduction was calculated only at request creation. Confirmation capped repayments and rejected deductions exceeding remaining debt, but did not reject a deduction smaller than newly applicable debt. In particular, zero skipped debt locking/recovery entirely.

**Reproduction:** pay an older order; create eligible new earnings; request ₱584.10 while debt is zero; record a legitimate partial refund on the older paid order creating ₱90 debt; confirm the pending request. Before the fix confirmation succeeded (HTTP 302), sent the full quoted amount and left the debt unpaid. The new regression expected 422 and failed before the change.

**Financial impact:** earnings that should support recovery leave the platform, potentially becoming irrecoverable if the Seller stops selling. The debt remains recorded; this is a missed recovery opportunity rather than deletion or false settlement.

**Existing safeguards / insufficiency:** transactions, request idempotency, caps, debt row locks, direct-payout restrictions and duplicate-payment checks did not validate a stale under-deduction.

**Fix:** after locking settlements, calculate `min(request amount, current locked outstanding debt)` and require equality with the request's quoted deduction. Reject a mismatch before changes. Admin uses the existing reject/resubmit path; no transfer amount is silently changed. Existing settlement → debt lock order and three-attempt transaction retry remain intact. Debt increases that leave the applicable capped deduction unchanged remain valid.

### Finding 2 — finance receivable totals include recovered amounts (Medium)

**Source:** `app/Services/Admin/FinanceReportService.php:206` (debt entries), `:221` (totals); both page and CSV consume `totals()` via `app/Http/Controllers/Admin/FinanceController.php`.

**Root cause:** a debt row's recorded `amountMinor` was used both for its original ledger display and the “Owed to Zefanya” total. Recovery changed only `settled_minor` and the status text.

**Reproduction:** create ₱70 debt in the selected month and recover ₱20. The row correctly says ₱50 left but the owed total shows ₱70. Fully recover ₱70 and the owed total still shows ₱70 while the row says Settled. Both assertions failed before the fix.

**Financial impact:** reported receivables are overstated by the recovered portion, misleading manual reconciliation and CSV consumers. This does not change the authoritative debt record or dashboard total.

**Existing safeguards / insufficiency:** correct status text and a correct independent dashboard total did not change the monthly summary calculation.

**Fix:** debt entries retain their recorded amount and add calculated `outstandingMinor` metadata; totals use it for debt entries. No historical row is modified. The report still describes debts created in its selected month; it is not an all-time debt register or a historical month-end balance. Use dashboard position for the platform-wide current total. No dashboard enhancement was added.

## 5. Files modified by this audit

Pre-existing uncommitted edits were retained. This audit's additions are:

| File | Purpose |
|---|---|
| `app/Services/Seller/SellerPayoutRequestService.php` | Validate current deduction at confirmation under lock. |
| `app/Services/Admin/FinanceReportService.php` | Calculate owed totals from remaining debt, retaining recorded ledger amounts. |
| `tests/Feature/Seller/SellerPayoutRequestTest.php` | Stale new debt, partial recovery, forged fields, old debt and restrictive parent deletion regressions. |
| `tests/Feature/Admin/AdminFinancePageTest.php` | Correct partial/fully recovered totals, CSV and retained ledger amount. |
| `tests/MySql/SellerPayoutConcurrencyTest.php` | Overlapping refund debt insert/update versus stale zero-deduction confirmation. |
| `tests/MySql/worker.php` | Add a debt-insert synchronization point to existing isolated race harness. |
| `docs/audits/ZEFANYA_AUDIT_1_5_UNRECOVERABLE_SELLER_DEBT.md` | Investigation, evidence, limitations and results. |

No migrations, frontend, payment rules, payout formulas, commission rates, collection/suspension policies, dependencies, production configuration or external applications were changed. No commit or push.

## 6. Financial examples

All values below are pesos; database values are integer centavos.

| Case | Recorded liability | Recovered | Outstanding | Result |
|---|---:|---:|---:|---|
| Fully outstanding | 2,000 | 0 | 2,000 | Open; no recovery booked just because a payout is requested. |
| Partial recovery | 2,000 | 700 | 1,300 | Original recorded amount retained; no fully settled status. |
| Multiple debts A/B/C | 500 / 800 / 1,200 | 0 / 0 / 0 | 2,500 total | Each order retained; recovery follows debt ID ascending. A ₱700 recovery settles A and recovers ₱200 from B, leaving ₱1,800 total. |
| No future earnings | 2,000 | 0 | 2,000 | Remains outstanding indefinitely, including after suspension. |
| Fully recovered | 2,000 | 2,000 | 0 | Settled; contributes zero to current owed totals. |
| Limited eligible earnings | 1,500 | 200 after confirmation | 1,300 | Gross request ₱200, deduction ₱200, transfer ₱0. |

## 7. Verification

Baseline before source fixes: SQLite **148 tests / 1,092 assertions passed** (Seller payout, Seller return, Admin finance); isolated MySQL **11 tests / 165 assertions passed**.

Reproduction checks deliberately failed before fixes: stale zero-deduction payout returned 302 instead of 422; partial and fully recovered debt totals incorrectly retained the full recorded amount. Each focused check passed after its fix.

Final SQLite run: **238 tests / 1,723 assertions passed**, using:

```text
php vendor/bin/phpunit tests/Feature/Seller/SellerPayoutRequestTest.php tests/Feature/Seller/SellerReturnDecisionTest.php tests/Feature/Admin/AdminFinancePageTest.php tests/Feature/Admin/OrderMonitoringTest.php tests/Feature/Logistics/ReturnRecoveryTest.php tests/Feature/Logistics/PhysicalReturnTest.php
```

Existing regression coverage includes suspension, multiple debts, duplicate recovery, over-recovery rejection, refund correction floors, stale refund revisions, auth boundaries, refund/debt insertion rollback and failed payout audit rollback. The tests run on SQLite `:memory:` and the existing dedicated MySQL database `zefanya_concurrency_test`. The MySQL preflight verifies server/database/account identity, narrow grants, denied application-schema access and InnoDB before disposable-schema work. Race workers use independent connections and record actual overlapping blocked locking statements in `storage/logs/payout-races/*/evidence.json`.

Formatting: `php vendor/bin/pint --dirty --format agent` passed. `git diff --check` passed.

Final isolated MySQL run: **13 tests / 195 assertions passed**, using `php vendor/bin/phpunit --configuration phpunit.mysql.xml --filter SellerPayoutConcurrencyTest`. Both new debt-insert and debt-update overlap cases returned refund success / stale payout rejection, retained the unpaid debt and pending payout, and wrote no contradictory payout audit. All existing payout/refund race regressions passed. Final failing tests: **none**.

An intermediate MySQL run passed 12 cases but errored in the added debt-insert case because the harness watched the wrong lock. Inserting the debt takes a shared foreign-key lock on its parent Seller, so payout confirmation waits at its Seller lock before reaching the debt query. The test originally watched the later debt query, hit its barrier deadline and rolled back the held refund. The harness expectation was corrected to observe the Seller lock for inserts and the debt lock for updates; the failing run is not counted as concurrency verification.

SQLite tests alone are not evidence of MySQL concurrency safety. The full repository suite and live financial data reconciliation are outside this focused run. No production data was used. No frontend build is necessary because no frontend files changed.

## 8. Remaining operational risks and optional improvements

- Sellers with no future earnings remain debtors; no existing automatic external collection was found. Recovery beyond future payouts needs an approved operational process. No policy was invented.
- Optional read-only aging could group creation dates into 0–30, 31–60, 61–90 and >90 days. It must explain that corrections can change liability after creation. No penalties or automated actions should be inferred. Not implemented.
- Admin must combine per-order debt, returns, payouts and audit records for reconciliation. There is no dedicated per-debt recovery allocation/history view and no trustworthy last-recovery field.
- `amount_minor` is the current corrected obligation, not a frozen original debt. Refund events preserve original refund references/amounts; not every intermediate debt calculation is independently versioned. A future ledger/visibility enhancement needs separate approval.
- Historical anomalies can be identified read-only: negative balances, recovered above amount, Seller/order/settlement ownership mismatch, amounts inconsistent with known refund/funding evidence, duplicate order debt, and total paid payout deductions inconsistent with total recovered debt. Unique/FK/unsigned safeguards cover only part of these conditions; legacy import/direct SQL can leave anomalies.
- Reliable reconstruction requires historical paid settlement amounts, voucher funding, commission rate, fault/return charges and refund/void events. Without those inputs, do not infer liability. Payout-level recovery cannot always be allocated back to individual historical debts after subsequent corrections without per-debt recovery records.
- No silent historical rewrite or new automatic reconciliation command was added. Any observed discrepancy needs evidence-led manual review.

## 9. Final status

**FIXED AND TESTED.** Both confirmed defects passed focused regressions and the isolated MySQL payout concurrency suite. Confirmed fixes do not remove the operational exposure of Sellers who never earn again; aging visibility, per-debt historical reconstruction and external recovery remain the limitations described above.
