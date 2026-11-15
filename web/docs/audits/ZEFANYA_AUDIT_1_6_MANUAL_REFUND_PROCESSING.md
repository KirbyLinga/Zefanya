# Zefanya Audit 1.6 — Manual Refund Processing Loopholes

Date: 2026-11-12. Scope: `WebZefanya`, current working tree, including existing Audit 1.2/1.4/1.5 changes. No production data was read or changed. Existing uncommitted work was preserved.

## 1. Overall result

One confirmed reporting defect, with three manifestations, was reproduced and fixed: cancelled returns and uncollected COD inflated the existing unpaid-refund count/liability; partial COD collections were overstated using the full payment amount. The existing dashboard work count used the same insufficient eligibility predicate and was corrected as part of this defect.

No new unauthorized refund recording, duplicate active refund, stale correction overwrite or transaction rollback defect was confirmed. These protections were exercised with HTTP regressions and isolated MySQL races. Manual transfer references, beneficiaries and uploaded proof remain Admin attestations; the application does not independently verify bank settlement. Those limitations are not classified as software vulnerabilities.

No SLA, deadline, escalation, notification, dashboard, PDF/CSV feature, payment integration, collection policy or automatic transfer was added. No historical data migration or rewrite was performed.

## 2. Existing workflow and authoritative records

### Intake and approval

`ParcelReturnService::buyerReturnDeadline()` and `requestByBuyer()` (`app/Services/Logistics/ParcelReturnService.php:59`, `:89`) use the persisted delivery clock and existing seven-day return rule. Intake locks the Seller Order, checks Buyer ownership, rejects ineligible shipment state and reuses an existing return. `parcel_returns.seller_order_id` is unique.

Seller approval is `seller_decision = approved` or `auto_approved`; an Admin can overturn a rejection with `dispute_outcome = overturned`. These are return decisions, not separate fixed-amount bank-payment approvals. No independent approved-refund-amount field or verified beneficiary account is stored. Existing Admin refund recording can itself establish the financial adjustment; this audit did not impose a new requirement for prior Seller approval.

The implemented physical states are `RETURN_REQUESTED`, `RETURN_IN_TRANSIT`, `RETURN_AT_ORIGIN`, `AWAITING_SELLER_RECEIPT`, `RETURNED_TO_SELLER`, `RETURN_REJECTED`, `RETURN_IN_DISPUTE`, `RETURN_RECEIPT_DISPUTED`, `RETURN_CANCELLED`, `RETURN_CLOSED`, `RETURN_LOST` and `RETURN_DAMAGED`. There is no separate refund-status enum. Physical terminal status does not itself mean a refund was paid.

### Refund awaiting payment / recording

`financial_review_required` identifies return records with financial review work; it is not an independently verified payment or a fixed approved obligation. `refunded_at = null` means there is no current recorded payment. Approval, elapsed time, Seller suspension and physical return completion do not populate `refunded_at`. The existing Admin Returns directory includes terminal as well as active records; pending financial work is not restricted to active transport.

The corrected existing finance calculation excludes cancelled returns and requires a positive refundable paid amount. It remains a full-refundable-cash estimate for financial-review records, not a register of separately approved exact partial-refund amounts. Pending/rejected/disputed returns may still require an Admin financial decision under the existing workflow. No new eligibility or approval policy was inferred from this estimate.

### Recording the external payment

Routes are under `auth:admin`; the Returns group permits Admin/Moderator inspection, while controller and service checks restrict money decisions to `admin` and `super_admin` (`app/Http/Controllers/Admin/ReturnController.php:23`, `:107`; `ParcelReturnService.php:715`). Other guards do not authenticate as Admin. There is no public refund mutation API in `routes/api.php`.

`ReturnController::recordRefund()` validates reference/recipient strings, decimal amount, nonfuture paid date, refund-history revision and optional private proof (JPG/PNG/PDF, maximum 5 MB). Integer parsing converts pesos to centavos. Submitted `amount_minor`, `refunded_at`, debt or status fields are not mass-assigned.

`ParcelReturnService::recordRefund()` (`:207`) trims required reference/recipient, requires a positive amount, locks Seller Order → return → payment, requires financial review, rejects cancelled returns and an existing current refund, compares the expected refund-history revision and checks any required fault decision. The amount is capped by `Payment::refundableCollectedMinor()` (`app/Models/Shared/Payment.php:72`): paid status, minimum of billed/recorded collected cash, or the existing legacy paid-record fallback when collection amount is absent. A smaller refund requires an explanation.

The authoritative current attestation is on `parcel_returns`: `refunded_at`, `refund_reference`, `refund_amount_minor`, `refund_recipient`, `refund_paid_on`, `refund_proof_path` and `refund_reason`. `refunded_at` is the recording timestamp; `refund_paid_on` is the Admin-entered transfer date. Reference and recipient are free text, not bank identifiers validated by a provider.

### Void and correction

`voidRefund()` (`ParcelReturnService.php:251`) requires a money Admin, reason and exact latest refund-history revision. It snapshots the original reference, amount, recipient, paid date, proof path and reason into a numbered `refund_voided` event and an operations audit entry, clears only current refund fields and recalculates finances. A replacement is a new `refund_recorded[_n]` event on the same return. `assertRefundRevision()` (`:275`) derives the revision from the latest locked refund-history event ID. A missing initial revision behaves as zero and cannot replay after a void/correction has created history.

Proof files are stored privately. A failed record removes its newly uploaded proof; a successful void retains the old proof file and its path in history. Historical proof does not gain a new viewing feature in this audit.

### Financial transaction boundary

The record/void transaction includes the return write, refund event, Seller settlement/debt recalculation and applicable Logistics charge synchronization, with three transaction attempts. `SellerSettlementService::releasePendingForReturn()` preserves paid settlement history, maintains existing holds and updates the single order debt, never below amounts already recovered. `ReturnChargeService::sync()` preserves the existing Seller/Logistics fault rules and frozen settled charges. A reserved Seller payout may need cancellation/rejection before a refund can change its earnings; the transaction rejects rather than partially recording the refund.

Current active refunds enter `FinanceReportService::ledger()` as `Recorded`, dated by `refunded_at` (`app/Services/Admin/FinanceReportService.php:200`). `PlatformFinanceService::position()` separately reports unpaid estimates and this month's currently recorded refund amounts. Neither counts an approved unpaid return as a completed transfer. These reports are application records, not independent bank statements or historical bank-payment-date ledgers.

## 3. Scenario results A–N

| Scenario | Classification | Result |
|---|---|---|
| A — approved refund never paid | ALREADY PROTECTED | Approved unpaid return remains recorded and visible. New 40-day/Seller-suspension regression preserves unpaid value and recording action. No automatic expiry, payment inference or refund SLA exists. |
| B — falsely recorded payment | OPERATIONAL GAP | Authorized Admin can attest using a reference, amount, recipient and date; proof is optional. The UI says “Refund recorded,” not bank verified. Whether the transfer actually arrived needs external reconciliation. No gateway integration added. |
| C — duplicate confirmation | ALREADY PROTECTED | Order/return locking, active `refunded_at` check, one return per order and unique per-return event keys permit one active refund. Existing duplicate-refund MySQL test verifies one history entry and one debt. |
| D — correction race | ALREADY PROTECTED | Latest locked history ID rejects outdated record/void submissions. Added real MySQL replacement-versus-stale-record and replacement-versus-stale-void cases supplement the existing sequential revision regression. |
| E — incorrect amount | ALREADY PROTECTED | Paid/collected-cash cap and positive service validation reject unpaid, over-collected, negative and zero amounts. HTTP tests reject scientific notation, extra decimals, arrays, overflow-length input and forged internal fields. There is no separate approved amount to enforce; exact partial amount remains an authorized Admin decision, with a required explanation. |
| F — incorrect beneficiary | OPERATIONAL GAP | Recipient name/account is required text, not identity matching against a verified Buyer payout destination. Transfer-reference possession proves neither ownership nor receipt. Fixing that requires a beneficiary-verification policy/workflow; that portion was not implemented. |
| G — reused external reference | OPERATIONAL GAP | Cross-order reuse is allowed; only one current refund per return is enforced. No provider/account/reference namespace exists to define a safe global uniqueness rule. Reuse during same-transfer corrections is not automatically fraudulent. No global unique constraint or reference registry added. |
| H — unauthorized actions | ALREADY PROTECTED | New direct HTTP matrix rejects Buyer, Seller, Logistics and Rider with 401 and Support/Moderator with 403 for both record and void. Existing controller/service role checks and Buyer/Seller ownership tests remain passing. Authorized money Admins intentionally handle platform orders. |
| I — status changes erase obligation | ALREADY PROTECTED | Suspension and elapsed time do not clear financial records; completed/lost/damaged/closed physical states remain in Admin directory and financial review. Completion blocks on applicable returns. Seller decision/dispute changes do not clear refund fields/history. Explicit precollection withdrawal is supported: Buyer keeps item, no refund is due, held earnings are restored. A recorded refund blocks this cancellation until explicitly voided. No new cancellation policy added. |
| J — transaction failure | ALREADY PROTECTED | Existing injected failure after financial writes and debt-insert failure regressions compare return, events, order, settlement, debt, Logistics charges, audits and notifications before/after rollback. No partial refund attestation survives failure. |
| K — missing audit | ALREADY PROTECTED | Record events include actor, amount, reference, recipient, date and reason; void preserves the prior record/proof path and creates an operations audit. Numbered event keys preserve corrections. No web/API event-edit/delete endpoint exists. The event model is not a cryptographically immutable database ledger; privileged direct SQL is outside application authorization. |
| L — reporting mismatch | CONFIRMED VULNERABILITY — FIXED | Unpaid count/liability included cancelled or uncollected returns and overstated partial collections. See finding below. Active paid-refund reporting and Audit 1.5 debt totals remain unchanged. |
| M — refund / Seller payout race | ALREADY PROTECTED | Existing MySQL payout/refund, duplicate payout, debt-insert/update, stale deduction and direct settlement races remain covered. Audit 1.5 confirmation-time debt validation and recovery caps were preserved. |
| N — historical integrity | PARTIALLY PROTECTED | Supported void/replacement keeps events and rejects old revisions; no raw financial-edit route exists. Legacy paid records intentionally allow missing collection snapshots. Older refunds can lack amount, proof or revision evidence; actual historical bank settlement cannot be reconstructed from incomplete attestations alone. No production data audit, migration or historical rewrite performed. |

## 4. Confirmed vulnerability and reproduction

### R1 — invalid unpaid-refund count and liability (Medium)

**Affected sources:** `app/Services/Admin/PlatformFinanceService.php:93` and `:95`; `app/Http/Controllers/Admin/DashboardController.php:86`. Shared fixed eligibility is at `app/Models/Logistics/ParcelReturn.php:317`.

**Root cause:** both queries treated `financial_review_required = true AND refunded_at IS NULL` as sufficient. That flag is set for noncancelled payment records at return intake, including pending COD. A supported precollection return withdrawal does not delete that historical financial-review flag. The monetary query then summed billed `payments.amount_minor`, rather than the refund recorder's paid/collected cap.

**Reproductions (isolated tests):**

1. Create a paid ₱100 Buyer return, approve it, then let its Buyer cancel before collection. The return correctly becomes `RETURN_CANCELLED` and the item stays with the Buyer, but the former dashboard count stays 1 and liability stays ₱100.
2. Create a return with ₱100 billed and only ₱60 recorded collected. The recorder caps refunds at ₱60, but the former unpaid liability is ₱100.
3. Create a return with pending/uncollected ₱100 COD. The recorder rejects any refund, but the former dashboard counts one refund and ₱100 owed.

All three regressions failed before the calculation fix. The first existing-record preservation/suspension test passed before the fix, showing that absence of an SLA was not the defect.

**Financial impact:** overstated potential refunds and total platform liabilities; contradictory Admin action counts can mislead manual reconciliation. This did not itself send money, erase an obligation, bypass refund validation or alter Seller debt.

**Existing safeguards and insufficiency:** the refund writer correctly rejects cancelled returns, unpaid COD and amounts above collected cash. Those safeguards were absent from the read-only report queries and therefore did not correct their results.

## 5. Exact fixes and modified files

Production files changed by this audit:

- `app/Models/Logistics/ParcelReturn.php`: internal query scope for existing unrecorded refund work with paid positive refundable cash, excluding cancelled returns. Does not change return/refund eligibility or stored state.
- `app/Services/Admin/PlatformFinanceService.php`: reuse that scope and calculate the billed/collected minimum, preserving the intentional legacy paid-record fallback.
- `app/Http/Controllers/Admin/DashboardController.php`: reuse the same scope for the existing “Refunds to record” action count. No new dashboard or UI element.

Tests/documentation changed:

- `tests/Feature/Seller/SellerReturnDecisionTest.php`: seven invalid-amount datasets, six unauthorized-role datasets, cancelled/uncollected/partial-collection report checks, unpaid time/suspension preservation and void reopening.
- `tests/Feature/Admin/AdminDashboardStatsTest.php`: existing “already paid” action-count fixture now supplies an actual paid payment row; existing assertions are retained.
- `tests/MySql/SellerPayoutConcurrencyTest.php`: two overlapping replacement-versus-stale-record/void regressions.
- `tests/MySql/worker.php`: existing isolated refund worker accepts explicit reference/revision and supports the existing void HTTP action.
- `tests/MySql/Race.php`: recognize the void worker action within the same isolated payout-race evidence directory.
- This report.

No refund writer, eligibility service, commission/payout formula, COD policy, Rider earnings, financial-history record or database migration was changed. The internal scope unifies only the two affected existing read queries. Previous uncommitted edits in touched files remain intact.

## 6. Tests and verification

Baseline: SQLite **173 tests / 1,305 assertions passed** across Seller returns, return recovery, Admin finance and Seller payout requests. Baseline isolated MySQL payout suite: **13 tests / 195 assertions passed**.

New reporting reproductions: 3 expected pre-fix failures (cancelled, uncollected and partial-collected balances); all four focused reporting/preservation tests passed after the fix. The 13 focused HTTP amount/authorization cases passed, with **59 assertions**.

Final SQLite run: **272 tests / 1,941 assertions passed**:

```text
php vendor/bin/phpunit tests/Feature/Seller/SellerReturnDecisionTest.php tests/Feature/Logistics/ReturnRecoveryTest.php tests/Feature/Admin/AdminFinancePageTest.php tests/Feature/Admin/AdminDashboardStatsTest.php tests/Feature/Seller/SellerPayoutRequestTest.php tests/Feature/Order/Rule05CompletionAndReturnWindowTest.php tests/Feature/Logistics/PhysicalReturnTest.php tests/Feature/Buyer/BuyerReturnFormTest.php
```

Real MySQL uses the existing dedicated `zefanya_concurrency_test` runner. Its preflight validates server/database/account identity, restricted grants, denied application-schema access and InnoDB before disposable test-schema work. Workers use independent connections; the harness observes overlapping blocked locking statements, not SQLite timing assumptions. Evidence is stored in `storage/logs/payout-races/*/evidence.json`.

One intermediate added stale-void test was stopped by the worker directory allowlist because its new test action was not yet mapped to the existing payout-race directory. The test harness mapping was corrected; no database identity/grant check was removed or relaxed. That interrupted run is not counted as successful concurrency verification.

Final isolated MySQL run: **15 tests / 223 assertions passed**, using:

```text
php vendor/bin/phpunit --configuration phpunit.mysql.xml --filter SellerPayoutConcurrencyTest
```

Both added replacement races returned replacement success (302) and stale submission conflict (409), retained the replacement amount/reference, preserved all three refund-history events and the original paid settlement, and kept the correct single debt. Existing duplicate refund, refund/payout and Audit 1.5 recovery races passed. These MySQL tests verify refund/payout concurrency; the new read-only report calculations are covered by the SQLite regressions.

`php vendor/bin/pint --dirty --format agent` completed; a final targeted Pint `--test` passed. `git diff --check` passed. Final failing tests: **none**. The pre-fix reproduction failures and intermediate harness interruption are disclosed above.

Not executed: full unrelated repository suite, live browser actions, production financial-data reconciliation and verification against any external bank/wallet. No frontend build is required because no frontend files changed. No claim of independently verified external transfers is made.

## 7. Remaining operational limitations

- An approved unpaid refund can remain pending indefinitely. Tracking persists, but there is no formal refund SLA. No deadline/escalation feature was added.
- Admin attestation can be mistaken or dishonest. Bank/wallet statements, actual beneficiary identity and receipt must be reconciled externally. Optional screenshots are not independent bank verification.
- Reference uniqueness cannot safely be specified without provider/account/transfer semantics, including correction and multi-beneficiary/batch cases. No global constraint was invented.
- There is no independently stored approved exact refund amount; current unpaid totals estimate full refundable cash for financial-review records. A smaller final refund remains the existing authorized, explained decision. This estimate should not be read as a new automatic refund entitlement.
- Legacy paid/refund records may lack collection snapshots, amounts or proof. Missing evidence must not be silently manufactured. Current revision protections do not reconstruct missing bank evidence.
- Settled Logistics charges retain their existing frozen-record semantics; correcting an already externally settled charge can require manual reconciliation. No reversal policy or automatic new charge was introduced.

## 8. Final status

**FIXED AND TESTED.** The confirmed reporting defect is fixed, and focused SQLite regressions plus real MySQL refund/payout concurrency checks passed. Confirmed reporting fixes do not independently verify or execute external payments. The attestation, beneficiary, reference-semantics and historical-evidence limitations remain as documented; no new policy or workflow was introduced to address them.
