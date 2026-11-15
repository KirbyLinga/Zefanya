# Zefanya — Audit 1.13: Return and Refund Fraud

Date: 2026-11-13. Scope: WebZefanya only (Laravel / MySQL). No Flutter, Zefanya-integration, production data, commit or push.

## 1. Overall result

One confirmed vulnerability was found and fixed: a Seller-rejected return could be escalated and then overturned after its shipment had already completed (**R13-1, Medium**). An overturn there zeroed the completed order's finalized commission and Seller net, and reopened a return that can never reach `Returned`. The fix applies the existing intake invariant ("only a delivered, uncompleted shipment can be returned") to escalation and to the overturn decision. The other return, refund, custody, inventory and debt paths traced in this audit were already protected, or are documented operational/policy gaps that this audit did not change.

**Final status: FIXED BUT NOT FULLY TESTED.** Focused SQLite regressions and real MySQL races pass. The full SQLite suite still has failures inherited from the unfinished Audit 1.12 checkout work (§11), and 22 return/loss-related tests cannot reach their assertions until that is resolved.

## 2. Existing return and refund workflow (source-traced)

| Step | Actor / entry point | Authoritative check |
|---|---|---|
| Request | Buyer web `buyer.orders.requestReturn`, API `api…orders.return` | Order policy `view` + shipment belongs to order (`Buyer/OrderController.php:197`, `Api/Buyer/OrderActionController.php:93`); service locks the shipment and re-checks `orders.buyer_id` (`ParcelReturnService.php:90`). Reason must be one of `BUYER_REASONS`; 1–5 new JPG/PNG uploads. Shipment must be `Delivered`; deadline = latest persisted `Delivered` history + 168 h (`:60`). One row per shipment (`parcel_returns.seller_order_id` unique); a repeat returns the existing row. |
| Seller decision | `seller.returns.approve/reject` | `lockedForDecision()` (`:731`): approved owning Seller, not in dispute, undecided, not overdue. Overdue requests auto-approve via `AutoApproveOverdueReturnsCommand` → `autoApproveOverdue()` under the same lock. |
| Escalation | Buyer `escalateReturn` (web/API) | Once, from `Rejected`; **now also requires the shipment to still be `Delivered`** (`ParcelReturn::canBuyerEscalate()`, `ParcelReturn.php:314`). |
| Dispute | `admin.returns.dispute` (`admin.role:admin,moderator`) | `resolveDispute()` (`:178`): must be `InDispute`; **overturn now requires a delivered shipment** (`:188`). Upheld stays available. |
| Collection | Logistics `assign-collection`; Rider `return-pickups.collect/failed` | Custody provider (`owner()`, `:820`) assigns its own active rider; only the assigned rider confirms, with matching waybill; collection requires a Seller decision (`awaitingBuyerCollection()`). Two failures escalate; Admin may authorize exactly one more. |
| Reverse transport | Manifests/handoffs (`direction = return`) → `movement()` | Movement only from stored, verified manifest items/handoffs; custody provider only. |
| Origin receipt | Logistics `scan` → `origin` | `atOrigin()` (`:984`): original provider/hub, parcel at hub, no active transport; single-use verification token. |
| Handover | Logistics `handover` | 16-character code (SHA-256 stored), 10-minute expiry, new nonce per handover. |
| Seller receipt | Seller `verify` → `receive` | Token bound to Seller, return, waybill, origin hub, nonce and condition; consumed once. Sets `Completed`, then `SellerOrderStatusService::transition(Returned)`. |
| Receipt dispute | Seller `dispute-receipt`; Admin `resume` / `close` / `disposition` | Parcel stays with Logistics; Admin money roles only. |
| Stock | Seller receipt (`resellable`) or Admin `returns.stock` | `stock_restored_at` plus per-variant `return` ledger dedupe in `StockService::set()` and `SellerOrderStatusService::restoreStock()`; product→variant lock order; missing variant → `InventoryException`, nothing restored. |
| Refund record / void | `admin.returns.refund` / `refund.void` (Admin/Super Admin) | Lock shipment → return → payment; cap `Payment::refundableCollectedMinor()` (Paid only; min(billed, collected)); one active refund; latest refund-history ID revision; explanation for partial; required fault decision for "Other". |
| Seller money | `SellerSettlementService::releasePendingForReturn()` / `restoreAfterCancelledReturn()` | Lock order SellerOrder → Payment → CodRemittance → SellerSettlement → SellerDebt; saved commission rate; debt never below `settled_minor`; pending payout request blocks; partly paid → reconciliation hold. |
| Reporting | `FinanceReportService::ledger()`, `PlatformFinanceService::position()` | Active recorded refunds dated by `refunded_at`; unpaid estimate via `awaitingRefundRecording()` (excludes cancelled/uncollected). |

A request, Seller approval, physical recovery, refund recording and actual external transfer are distinct records. `refunded_at` is an Admin attestation, not proof of transfer (see Audits 1.6/1.7).

## 3. Scenario results A–AZ

| # | Scenario | Classification | Evidence / result |
|---|---|---|---|
| A | Another Buyer's order | ALREADY PROTECTED | Policy `view` + shipment↔order binding (404) + locked `buyer_id` re-check. Existing `SellerReturnDecisionTest` cross-buyer cases pass. |
| B | Unpaid order | ALREADY PROTECTED | Refund requires `Paid`; since Audit 1.12 a COD payment becomes `Paid` only with an exact collection snapshot. Uncollected returns are excluded from refund work. |
| C | Cancelled order | ALREADY PROTECTED | Request requires `Delivered`; cancelled shipments have no refundable payment; cancelled return blocks refund. |
| D | Seven-day window | ALREADY PROTECTED | Absolute instant (latest persisted delivery + 168 h), so Asia/Manila calendar boundaries cannot shift it; missing/future timestamps fail closed. Statutory rights are outside the app and not restricted. **R13-1 fixed an escalation path that previously reopened returns with no time limit after completion.** |
| E | Duplicate requests | ALREADY PROTECTED | Shipment lock + unique `seller_order_id`; repeat returns the existing record (200, not 201). |
| F | Concurrent requests | ALREADY PROTECTED | MySQL `PhysicalReturnConcurrencyTest::test_concurrent_return_requests_create_one_active_return_and_request_event` passes. |
| G | Forged reason | ALREADY PROTECTED | `Rule::in(BUYER_REASONS)`; description 10–500 chars; "Other" forces an Admin fault decision before refund. |
| H | Evidence manipulation | ALREADY PROTECTED | Only freshly uploaded files are accepted (no client paths); private disk; Seller photo route authorizes the shipment and indexes only that return's own array; Admin routes are authenticated. |
| I | Evidence replacement | ALREADY PROTECTED | No edit/delete endpoint for request or receipt-dispute photos. |
| J | Unauthorized approval | ALREADY PROTECTED | Seller ownership + approved status; other guards cannot authenticate as Seller/Admin. Moderator dispute resolution is existing tested permission (`test_sel_ret_005_admin_can_overturn_the_rejection`); see §12. |
| K | Unauthorized rejection / review bypass | ALREADY PROTECTED | Same lock/ownership gate; in-dispute and overdue states block Seller action. |
| L | Stale approval | ALREADY PROTECTED | Locked re-check returns 409 "already decided". |
| M | Approve vs reject race | ALREADY PROTECTED | **New** MySQL `ReturnDecisionConcurrencyTest::test_conflicting_seller_decisions_commit_only_the_first` (both orders): winner commits, loser 409, one decision event. |
| N | Forged status | ALREADY PROTECTED | No endpoint accepts a status; all writes use `forceFill` inside services. |
| O | Custody manipulation | ALREADY PROTECTED | Collection needs a decision + assigned rider + waybill; origin/handover need verified scans and hub custody; assignment never sets custody. |
| P | Other provider/rider | ALREADY PROTECTED | `owner()` custody check; rider must match `collection_courier_id`. |
| Q | False Seller receipt | ALREADY PROTECTED | `AwaitingSeller` + unexpired code + nonce-bound token. |
| R | Duplicate receipt | ALREADY PROTECTED | Second call fails `sellerReady()`; token consumed. |
| S | Concurrent receipt | ALREADY PROTECTED | MySQL `test_concurrent_seller_receipts_finalize_and_restore_stock_once` passes. |
| T | Unauthorized restoration | ALREADY PROTECTED | Requires verified `resellable` receipt, owning Seller and `returns.restore_verified_resellable`. Audit 1.12 product→variant lock order preserved. |
| U | Duplicate restoration | ALREADY PROTECTED | `stock_restored_at` + per-variant `return` ledger dedupe on all three paths. |
| V | Legacy variant snapshot | ALREADY PROTECTED | Null/missing variant → no restoration, `InventoryException`, `stock_restored_at` stays null; Admin release refuses ledger conflicts. |
| W | Amount tampering | ALREADY PROTECTED | Strict decimal regex, integer centavo parsing, service cap; internal fields not mass-assigned (Audit 1.6 tests). |
| X | Refund > collected COD | ALREADY PROTECTED | Cap at `min(amount_minor, collected_amount_minor)` under payment lock. |
| Y | Duplicate refund | ALREADY PROTECTED | Active `refunded_at` check + revision. |
| Z | Concurrent refund | ALREADY PROTECTED | MySQL `test_two_admins_record_only_one_refund_and_debt_after_payout` passes. |
| AA | Stale correction | ALREADY PROTECTED | MySQL `test_stale_refund_record_or_void_cannot_overwrite_a_concurrent_replacement` (both datasets) passes. |
| AB | Void replay | ALREADY PROTECTED | Void needs an active refund + exact revision. |
| AC | Destination change | OPERATIONAL GAP | Unchanged from Audit 1.7: recipient is Admin text; no verified Buyer payout account exists. No Buyer/Seller endpoint can set it. |
| AD | Receipt manipulation | OPERATIONAL GAP | Proof is optional, private, and replaced only by void + new record (history keeps the old path). Upload does not prove transfer authenticity. |
| AE | Seller-fault allocation | ALREADY PROTECTED | Refund comes off items first, then shipping; return trip charged; saved rate; shortfall → one debt. |
| AF | Logistics-fault allocation | ALREADY PROTECTED | Logistics fault → Seller keeps the order; any existing debt drops to `settled_minor`. |
| AG | Platform-funded voucher | OPERATIONAL GAP — POLICY | Seller item amount excludes only Seller-funded discount, so platform liability is not moved to the Seller. On a full refund the Seller can keep part of a platform subsidy (refund applied to items first). Whether the platform should recover it is undefined; overlaps open Audit 1.10 questions. Not changed. |
| AH | Partial refund | ALREADY PROTECTED | Capped, explained, allocated at the saved rate; remainder payable. |
| AI | Refund after payout | ALREADY PROTECTED | Single order debt, never below repaid; MySQL payout/refund races pass. |
| AJ | Refund before payout | ALREADY PROTECTED | Pending settlement rewritten/released; settlement created later re-applies the return breakdown (`applyFinalizedFinancials`). |
| AK | Refund during payout | ALREADY PROTECTED | Pending request blocks; MySQL `test_refund_waits_for_payout…`, `test_payout_waits_for_a_refund…`, `test_direct_payout_waits_for_a_concurrent_refund_debt_update` pass. |
| AL | Refund during COD remittance | ALREADY PROTECTED | Both take the `seller_orders` lock first. **New** MySQL `test_refund_and_cod_handover_converge_in_either_lock_order` (both orders): one refund, payment/collection unchanged, remittance confirmed, settlement released at ₱0, one ₱10.00 debt. |
| AM | Return cancellation | ALREADY PROTECTED | Buyer (owner) or money Admin, only before collection and without an active refund; earnings restored once (`earnings_restored_at`). |
| AN | Reopening | ALREADY PROTECTED (after R13-1) | Requests reuse the existing row; escalation once; dispute resolution only from `InDispute`. **Escalation/overturn after shipment completion was the confirmed defect R13-1.** |
| AO | Repeated history | ALREADY PROTECTED | One return per shipment, one debt per order, numbered refund events. |
| AP | Cross-Seller | ALREADY PROTECTED | Route-bound return → locked shipment → its own Seller; ownership 403. |
| AQ | Cross-provider | ALREADY PROTECTED | Custody-provider gate; provider visibility scope on index/show. |
| AR | Currency precision | ALREADY PROTECTED | Integer centavos; regex rejects >2 decimals and >9 peso digits; cap fits unsigned INT columns. |
| AS | Negative/zero/fractional/huge | ALREADY PROTECTED | Regex rejects sign and 3+ decimals; service rejects ≤ 0; cap rejects excess. |
| AT | Rollback | ALREADY PROTECTED | Single transaction per action (3 attempts); existing Audit 1.6 injected-failure regressions pass; uploaded proof removed on failure. |
| AU | Audit history | PARTIALLY PROTECTED | No duplicate events (`event()` dedupes by key; refunds/failures/dispositions numbered). Non-numbered transition keys (e.g. `awaiting_seller`, `at_origin`) are **not** re-logged when a step legitimately repeats after a receipt-dispute resume; the resume itself is logged. Completeness gap only; not changed. |
| AV | Notifications | ALREADY PROTECTED | All return notifications are synchronous `database` channel inside the transaction or sent after commit. |
| AW | Refund reporting | ALREADY PROTECTED | Audit 1.6 reporting fix preserved; active refunds only, unpaid estimate excludes cancelled/uncollected. |
| AX | Debt reporting | ALREADY PROTECTED | One debt row per order; outstanding = amount − settled; Audit 1.5 tests pass. |
| AY | Historical integrity | ALREADY PROTECTED (after R13-1) | Saved rate reused; R13-1 previously allowed a completed order's finalized commission/net to be rewritten. |
| AZ | Alternate paths | ALREADY PROTECTED | `refunded_at`, refund fields, `stock_restored_at` and return status are written only by `ParcelReturnService` and the locked `SellerOrderStatusService` paths; API exposes only request/escalate; the auto-approve command uses the locked service; no job/command edits refunds. |

## 4. Confirmed vulnerability

### R13-1 — Escalation/overturn after shipment completion (Medium)

**Files:** `app/Models/Logistics/ParcelReturn.php:314` (`canBuyerEscalate`), `app/Services/Logistics/ParcelReturnService.php:162` (`escalateByBuyer`), `:178` (`resolveDispute`), `app/Services/Seller/SellerSettlementService.php:245`/`:306`, `app/Services/Seller/SellerOrderStatusService.php:108`/`:111`.

**Reproduction (before fix):**
1. Buyer requests a return; Seller rejects → return `Rejected` (terminal, so it no longer blocks completion).
2. Buyer confirms receipt (or 7-day automatic receipt runs) → shipment `Completed`, commission finalized (₱100 order: commission ₱10.00, Seller net ₱90.00).
3. Buyer order page still shows **Escalate**; `escalateByBuyer()` accepts it → `InDispute` (no time limit).
4. An Admin or Moderator overturns → return `RETURN_REQUESTED`, awaiting collection.

**Observed impact (measured):** completed order changed from `commission_minor 1000 / seller_net_minor 9000` to `0 / 0` with no refund recorded. `Completed` has no outgoing transitions, so Seller receipt (`transition(Returned)`) can never finish the reopened return. By code path (not executed here), if the settlement was already paid, `recordDebtForPaidReturn` would turn the full payout into a Seller debt.

**Root cause:** `canBuyerEscalate()` checked only the return record. The intake invariant "only a delivered, uncompleted shipment can be returned" (`requestByBuyer`) was not re-applied when a rejected return was allowed to proceed again.

**Existing protections and why they failed:** an active return blocks completion, but `Rejected` is terminal by design so the Buyer can complete; once completed, nothing re-checked shipment state at escalation or overturn.

## 5. Fixes implemented

- `ParcelReturn::canBuyerEscalate()` additionally requires `sellerOrder.status === Delivered`. This hides the Escalate form on completed shipments (`Buyer/Orders/show.blade.php:190`, `_shipment-next-steps.blade.php:18`) and rejects the service call with the existing message. The service evaluates it on the shipment row it has just locked.
- `ParcelReturnService::resolveDispute()` refuses `overturned` unless the locked shipment is `Delivered` (409, "Keep the rejection instead"). `upheld` stays available, so a legacy dispute escalated before this fix can still be closed.

No eligibility window, permission, refund, commission, shipping-split or debt rule changed.

## 6. Production files modified

- `app/Models/Logistics/ParcelReturn.php` (`OrderStatus` import; `canBuyerEscalate()`; R13-P1 `REFUND_EXCEPTION_BASES`, `refundApproved()`)
- `app/Services/Logistics/ParcelReturnService.php` (overturn guard in `resolveDispute()`; R13-P1 exception check, history and audit in `recordRefund()`; corrected `moneyAdmin()` docblock)
- `app/Http/Controllers/Admin/ReturnController.php` (R13-P1 validation/pass-through)
- `resources/views/Admin/Returns/index.blade.php` (R13-P1 exception fields)

All four files already had uncommitted changes from earlier audits; those were preserved. No migration.

## 7. Tests added or updated

- **Added** `tests/Feature/Seller/ReturnEscalationAfterCompletionTest.php` (4 tests). Three failed before the fix (reproduction) and pass after it; the fourth confirms escalation while delivered still works and blocks completion.
- **Added** `tests/MySql/ReturnDecisionConcurrencyTest.php` (6 races): completion-vs-escalation (both lock orders), approve-vs-reject (both orders), refund-vs-COD-handover (both orders).
- **Updated** `tests/MySql/worker.php`: additive `return_escalate`, `return_approve`, `return_reject`, `return_refund` actions calling the real routes (default run root).
- **Added** `tests/Feature/Seller/ReturnRefundApprovalPolicyTest.php` (9 tests): pending/rejected/upheld returns reject ordinary refunds, plus missing-note and invalid-basis exceptions, with no money/history/audit change; a documented exception records history and audit; approved and overturned returns need none; moderators stay forbidden; the Admin page shows exception fields only when needed; a platform-funded voucher is not charged to the Seller.
- **Updated** `tests/Feature/Seller/SellerReturnDecisionTest.php` for R13-P1. 18 refund-mechanics tests now use an approved return (`approvedRecord()`). 5 tests whose assertions depend on refund-time effects on a return that was not approved (debt-insert rollback, unknown legacy funding ×2, "Other" reason, who-pays lock) now record with a documented exception (`REFUND_EXCEPTION`). Their money assertions are unchanged.
- **Updated** `tests/MySql/Rule05ReturnCompletionConcurrencyTest.php`: stale `500` (5%) commission expectation replaced by the current rate constant. That order has no saved rate, so the authoritative 10% applies; this is not a historical 5% snapshot.

## 8. SQLite results (`phpunit.xml`, in-memory)

| Run | Tests | Passed | Failed | Errors | Skipped | Assertions |
|---|---|---|---|---|---|---|
| New regression file | 4 | 4 | 0 | 0 | 0 | 46 |
| All 37 return/refund/settlement/debt/finance files | 733 | 711 | 1 | 21 | 0 | 5,460 |
| Full suite before fix (baseline) | 4,092 | 4,038 | 15 | 34 | 5 | 29,961 |
| Full suite after fix | 4,096 | 4,042 | 15 | 34 | 5 | 30,007 |

After R13-P1: `ReturnRefundApprovalPolicyTest` 9/9 (82 assertions); `SellerReturnDecisionTest` 105/105 (851); all 38 return/refund files 742 tests, 720 passed, 1 failed, 21 errors, 0 skipped, 5,567 assertions. The failures are the same 22 baseline IDs. The full suite was not rerun after R13-P1.

All 22 return-set failures are baseline failures with the 1.12 checkout cause (§11): 6 `CompleteFinancialScenariosTest`, 16 `ParcelLossTest`.

### 8a. Full suite after fix

The only difference from the baseline is the 4 new tests, which pass. The 49 failing/erroring test IDs are identical to the baseline (compared by test name). No new failures, and none fixed incidentally. No timeouts (run took 21.6 minutes).

## 9. MySQL concurrency results (isolated `zefanya_concurrency_test`, Preflight-verified, serial)

| Group | Classes | Tests | Passed | Failed | Skipped | Assertions |
|---|---|---|---|---|---|---|
| B (self-locking) | `PhysicalReturnConcurrencyTest`, `CodRemittanceConcurrencyTest`, `ReturnDecisionConcurrencyTest` | 19 | 19 | 0 | 0 | 160 |
| C (runner lock held by wrapper) | `Rule05ReturnCompletionConcurrencyTest`, `SellerPayoutConcurrencyTest` | 18 | 17 | 1 | 0 | 271 |
| C rerun after stale-rate correction | `Rule05ReturnCompletionConcurrencyTest` | 2 | 2 | 0 | 0 | 20 |

| After R13-P1 (self-locking) | `ReturnDecisionConcurrencyTest` | 6 | 6 | 0 | 0 | 66 |
| After R13-P1 (wrapper) | `SellerPayoutConcurrencyTest` | 16 | 16 | 0 | 0 | 252 |

No timeouts. Each race asserts an observed blocked `FOR UPDATE` on the second connection. The MySQL races were not separately run against the pre-fix code; the SQLite reproduction demonstrates the defect.

## 10. Remaining operational limitations

- Refund attestation, recipient, reference and proof remain unverified external facts (Audits 1.6/1.7).
- Repeated non-numbered return transitions after a receipt-dispute resume are not re-logged (AU).
- 16 `ParcelLossTest` cases (including `closeAsLoss` for return parcels) and 6 `CompleteFinancialScenariosTest` cases cannot reach their assertions until the 1.12 checkout test harness is fixed. Those return-loss paths are **not verified** in this audit.
- Legacy disputes already escalated on completed shipments (if any exist) can now only be upheld. Production data was not inspected.

## 11. Audit 1.12 verification dependencies

The full SQLite baseline had 15 failures and 34 errors. The large majority (34 errors and 8 failures) share one root cause in the uncommitted 1.12 checkout work. `CheckoutController::store()` now rejects a POST whose `checkout_token` differs from the token stored in the session by the last checkout page view, which a real browser always posts back. The affected tests open the checkout page and then POST a freshly issued token. This is test-harness debt from 1.12, not a return/refund defect, and it was left for the 1.12 follow-up. The remaining baseline failures (`DemoMarketplaceTest`, `EdGamingCatalogSeederTest`, two HTML-assertion tests, `SellerVacationModeTest`) are outside 1.13 scope and were not investigated. The 1.12 MySQL checkout suite was not rerun in this audit.

## 12. Policy decisions (owner decisions received 2026-11-13) and how they were applied

| Question | Decision | Implementation |
|---|---|---|
| Refunds on pending, rejected or upheld returns | Not allowed by default; only an authorized, documented exception for legally required or separately approved refunds. | **Implemented (R13-P1, below).** |
| Platform-funded voucher on a full refund | Do not charge the Seller for the platform subsidy; keep the funding liability separate until refund/commission accounting is defined. | **No production change needed.** Current code already excludes platform funding from the Seller item amount. Pinned by new test `test_a_full_refund_never_charges_the_seller_the_platform_funded_voucher` (₱100 item, ₱20 platform voucher, ₱80 refund → Seller debt ₱72.00 = ₱90 paid − ₱18 kept; the ₱20 subsidy is not charged). The platform's own recovery of the subsidy remains undefined. |
| Moderator dispute permissions | Preserve for now; correct the misleading comment. | **Comment corrected** on `ParcelReturnService::moneyAdmin()`; permissions unchanged. |

### R13-P1 — refund approval requirement

- `ParcelReturn::refundApproved()` (new): a buyer return counts as approved after Seller approval, automatic approval, or an Admin overturn. Logistics/Admin failed-delivery returns have no Seller decision step and are unchanged.
- `ParcelReturnService::recordRefund()` (new optional `$exceptionBasis`, `$exceptionNote`): under the shipment/return lock, a return that is not approved requires a basis from `ParcelReturn::REFUND_EXCEPTION_BASES` (`legal_requirement`, `separate_approval`) and a 10–1000 character note. The exception (basis, note, return status, Seller decision, dispute outcome at the time) is stored in the `refund_recorded[_n]` event metadata, and an `return_refund_exception` operations audit entry is written in the same transaction. Approved returns record no exception.
- Who may authorize: the existing refund roles (Admin, Super Admin). Moderators remain forbidden. No stricter role was invented.
- `Admin/ReturnController::recordRefund()` validates and passes the two fields; `Admin/Returns/index.blade.php` shows them only for a return that is not approved.
- Unpaid-refund reporting and exception-refund funding were then corrected by the approved clarifications in §12a. That section supersedes the earlier "not changed" and "open" notes here.

## 12a. Final accounting corrections (approved clarifications)

### Unpaid refund reporting

Investigated every unpaid/outstanding refund calculation: `ParcelReturn::scopeAwaitingRefundRecording()` (single source), consumed by the Admin dashboard "Refunds to record" count (`Admin/DashboardController.php:86`) and `PlatformFinanceService::position()` (`refundsToRecordCount`, `refundsToRecordMinor`). There are also two consumers outside the Admin reports: the Buyer account-deletion gate (`BuyerAccountDeletionService::blockingReason()`) and the Buyer "Your refund is being reviewed" message (`Buyer/Orders/_shipment-next-steps.blade.php`). Recorded-refund totals (`refundsRecordedMonthMinor`, `FinanceReportService::ledger()`) count paid attestations only and were not changed.

- **Before:** the scope counted every financial-review return with collected cash and no active refund, including pending, rejected and upheld requests. The deletion gate was broader still: it also blocked on cancelled returns and on returns with no collected cash, saying "a refund is being processed".
- **Now:** an obligation is established when the return is approved (Seller approval, automatic approval, Admin overturn, or a return type with no Seller decision step), **or** an authorized exception refund was recorded for it (a `refund_recorded*` event carrying `exception`). The second case covers a voided exception transfer, which is still owed. Model helpers: `hasAuthorizedRefundException()`, `refundObligationEstablished()`.
- One row per return, at `min(billed, collected)`; an active (including partial) refund removes it; void/replacement history is untouched, so nothing is double-counted. Existing manual verification and evidence are unchanged.
- The deletion gate now uses the same scope. The Buyer message no longer tells a rejected/upheld buyer a refund is under review unless an authorized exception exists.

### Exception refund funding

- **Before:** recording any refund applied `faultDefaults()`, so an exception refund on a rejected/upheld return made the Seller payer by default **and** set a return-trip fee for a trip that never happened. `releasePendingForReturn` then charged both.
- **Now** (`ParcelReturnService::exceptionFunding()`, under the existing shipment/return lock):
  - An earlier explicit Admin payer decision (`fault_set_by_admin_id`) is preserved. A submitted payer that conflicts with it is refused with 409 (stale form).
  - Otherwise the Admin must choose an existing payer, Seller or Logistics (their existing allocation formulas), with a 10–1000 character justification. This is stored on the return (`fault_*`), in the refund event (`exception.funding_party`, `funding_established`) and as a `return_fault_set` audit entry.
  - The exception path never applies the Seller default or the return-trip fee.
  - Approved returns keep the existing default (Seller, return trip) unchanged.
  - Existing Seller debts and completed payouts are not modified automatically. A paid settlement keeps `PAID` and its paid amount; any debt follows the existing one-debt-per-order rule.

**BLOCKED — POLICY DECISION REQUIRED:** a refund the **platform** should fund (neither Seller nor Logistics). No platform-funded return allocation exists, and inventing one would require a new financial formula (how Seller earnings, commission and return charges behave when Zefanya absorbs the refund). Until that is decided, such an exception refund cannot be recorded without naming the Seller or Logistics; it is rejected with no liability created. Choosing "platform" is refused by validation.

### Files (this round)

Production: `app/Models/Logistics/ParcelReturn.php`, `app/Services/Logistics/ParcelReturnService.php`, `app/Services/Buyer/BuyerAccountDeletionService.php`, `app/Http/Controllers/Admin/ReturnController.php`, `resources/views/Admin/Returns/index.blade.php`, `resources/views/Buyer/Orders/_shipment-next-steps.blade.php`.

Tests:
- **Added** `tests/Feature/Seller/ReturnRefundAccountingTest.php` (14 tests): pending/rejected/upheld not counted; approved counted once; recorded → voided → re-recorded exception counted only while owed, one debt; partial not counted; deletion gate and Buyer message; no payer → no liability (including "platform" refused); explicit Seller payer charges no return trip (debt ₱95 not ₱100); explicit Logistics payer leaves the Seller whole; earlier Admin decision preserved, conflicting one refused; approved defaults unchanged; Admin form.
- **Updated** `SellerReturnDecisionTest.php` and `ReturnRefundApprovalPolicyTest.php`: their exception refunds now give an explicit Seller payer (`SELLER_FUNDS`).
- **Updated** `BuyerAccountDeletionTest::test_a_refund_still_owed_blocks_deletion_until_it_is_recorded`: the fixture now has the collected COD payment any owed refund requires.
- **Updated** `tests/MySql/ReturnDecisionConcurrencyTest.php` (+3: payer decision vs stale exception refund, exception refund vs later payer decision, MySQL reporting/JSON evaluation) and `tests/MySql/worker.php` (`return_fault` action; `extra` fields for `return_refund`).

### Results (this round)

| Run | Tests | Passed | Failed | Errors | Skipped | Assertions |
|---|---|---|---|---|---|---|
| SQLite `ReturnRefundAccountingTest` | 14 | 14 | 0 | 0 | 0 | 100 |
| SQLite policy + decision files | 114 | 114 | 0 | 0 | 0 | 933 |
| SQLite `BuyerAccountDeletionTest` | 23 | 23 | 0 | 0 | 0 | 161 |
| SQLite return/refund/deletion set | 756 | 733 | 2* | 21 | 0 | 5,662 |
| MySQL `ReturnDecisionConcurrencyTest` | 9 | 9 | 0 | 0 | 0 | 86 |
| MySQL `PhysicalReturn` + `CodRemittance` | 13 | 13 | 0 | 0 | 0 | 94 |
| MySQL `Rule05` + `SellerPayout` (wrapper) | 18 | 18 | 0 | 0 | 0 | 272 |

\* Of the 23, 22 are the baseline 1.12 checkout failures. The remaining one was `BuyerAccountDeletionTest` (fixture without payment), fixed above and now passing (23/23). Full-suite result: see §12b.

## 12b. Full SQLite suite after the accounting corrections

4,119 tests: 4,065 passed, 15 failed, 34 errors, 5 skipped, 30,214 assertions (22.0 minutes, no timeouts). The 49 failing/erroring test IDs are identical to the pre-audit baseline (compared by name): no new failures, none fixed incidentally. All of them are outside the return/refund code. 42 share the unfinished Audit 1.12 checkout-token test-harness cause (§11), which this audit did not change. The Audit 1.12 checkout protections were not modified.

## 12c. Follow-up: platform-funded exception refunds

Owner decision (2026-11-13): implement Platform as a third payer and keep the existing refund model. There is one active refund per return; a recorded partial refund remains the final explained decision. The multi-installment "authorized obligation" model (brief Phases 4–5) is **BLOCKED — POLICY DECISION REQUIRED**; see the end of this section.

### Existing implementation (confirmed) and what was reused

- **Refund record:** a single active refund on `parcel_returns` (`refunded_at`, `refund_amount_minor`, …). Void clears it; replacement writes a new numbered `refund_recorded_n` event. Revision checks use the latest refund-history event. All of this was reused unchanged.
- **Payer:** `parcel_returns.fault_party` with `fault_note`, `fault_set_by_admin_id`, `fault_set_at`. Reused: Platform is a new value (`platform`), recorded only through the exception funding decision.
- **Seller money:** `SellerSettlementService` (release/hold, one debt per order). Reused: Platform is treated like Logistics (`sellerNotCharged()`), and `releasesPendingEarnings()` is false, so settlements, payouts, commission and Seller net are not touched.
- **Logistics money:** `ReturnChargeService` charges Logistics only on a Logistics fault, and the return trip only on a Seller fault. Platform creates no charge, with no code change.
- **Ledger:** there was **no stored financial ledger**. `FinanceReportService::ledger()` is derived from source tables at read time and is a cash ledger; the refund cash is already its `return_refund` row. A stored expense with references, reversals and idempotent writes therefore needed one new table (below).
- **Earnings:** `PlatformFinanceService::earnings()` `netMinor` = commission − platform vouchers − shipping vouchers. To avoid silently redefining it, the expense is a separate line and is not subtracted.
- **Transactions, locks, audit:** the existing shipment → return → payment lock order, the 3-attempt transaction and `OperationsAuditLog` were all reused.

### Changes

- **Authorization:** unchanged roles (Admin and Super Admin, enforced server-side by `moneyAdmin()` and the controller; Moderators 403; other guards cannot reach `auth:admin`). Platform is accepted only in `exceptionFunding()`, i.e. a new decision for an exception refund with no earlier Admin decision. The `setFault` route still accepts only Seller or Logistics. An existing decision conflicting with a submitted payer returns 409. A Platform decision cannot be changed later (`faultCanChange()` is false).
- **Persistence and justification:** `fault_party = platform`, `fault_note` (10–1000 characters), `fault_set_by_admin_id`, `fault_set_at`; the refund event's `exception.funding_party`; a `return_fault_set` audit.
- **Expense accounting:** new table `platform_refund_expenses`, model `App\Models\Logistics\PlatformRefundExpense`. One `expense` row per `refund_recorded*` event, with the exact amount, return, shipment, payment, event, Admin, justification and timestamp, and reference `PRF-{event id}`. The row and its `platform_refund_expense_recorded` audit are written inside the refund transaction.
- **Void and replacement:** a void appends one `reversal` row (`reverses_id` = the expense, reference `PRF-{id}-R`, linked to the `refund_voided*` event) and a `platform_refund_expense_reversed` audit. The original rows are never edited. A replacement keeps the established Platform payer and books a new expense on its own event.
- **Database idempotency:** unique `reference`, unique `refund_event_id`, unique `reverses_id`.
- **Outstanding refunds:** the shared `awaitingRefundRecording` rule (§12a) is unchanged and already counts a voided authorized exception once at the collected amount. Partial refunds stay final, so ₱0 remains outstanding after one is recorded.
- **Finance reporting:** `FinanceReportService::summary()['platformRefundExpenseMinor']` = expenses − reversals booked in the Philippine month. It is shown as "Platform refund expense" on the Finance page and in the existing CSV, explicitly not deducted from commission or net platform earnings.
- **UI:** the Admin return form adds the Platform option only for a new exception funding decision; the Seller and Admin "Who pays" panel gets one line each for Platform.
- **Unavailable before migration:** Platform is hidden and refused, and the expense line reads ₱0, until the migration is applied (`PlatformRefundExpense::available()`).

### Migration

`2026_11_13_045900_create_platform_refund_expenses_table`: additive, no existing row changed, no backfill. It is necessary because no table could hold an append-only expense/reversal pair with unique references and links to the refund event, return, payment and Admin. It ran on the isolated SQLite and InnoDB test schemas only. **It was not run on the live `Zefanya` database.**

### Worked reconciliation (tested: `test_partial_then_void_then_replacement_reconciles_to_the_centavo`)

| Item | Amount |
|---|---|
| Collected COD (₱450 item + ₱50 delivery) | ₱500.00 |
| Commission (10%, finalized) / Seller net / Seller payout already paid | ₱45.00 / ₱405.00 / ₱405.00 |
| Exception refund 1 (Platform) → expense `PRF-a` | +₱200.00 |
| Void → reversal `PRF-a-R`; outstanding back to the collected cap | −₱200.00; outstanding ₱500.00 |
| Replacement (established Platform payer) → expense `PRF-b` | +₱300.00 |
| Net platform refund expense = active refund | ₱300.00 |
| Remaining outstanding (partial refund is final) | ₱0.00 |
| Seller debt / Logistics charges / return-trip charge | ₱0.00 / ₱0.00 / ₱0.00 |
| Commission, Seller net, settlement, COD remittance row and its allocation snapshot, shipping fee | unchanged (asserted byte-for-byte) |

### Tests (this follow-up)

- **Added** `tests/Feature/Seller/PlatformFundedRefundTest.php` (16 tests, 151+ assertions). Covers:
  - Admin and Super Admin can record; Moderator 403; missing or short justification and invalid payer rejected; conflicts with an earlier Seller/Logistics decision, or a later attempt to replace Platform, return 409.
  - No Seller debt, Logistics charge or return trip; commission, settlement, remittance, payment and shipping unchanged; historical 5% snapshot unchanged.
  - Over-collected, fractional-centavo and negative amounts rejected; exact partial amount.
  - The full partial → void → stale void → replacement reconciliation, plus deletion gate and Buyer messages along it.
  - Manila month boundary for the finance line; Seller page wording; Admin form; rollback on expense, audit or reversal failure.
- **Added** `tests/MySql/PlatformRefundConcurrencyTest.php` (8 tests, 57 assertions):
  - A: duplicate recording.
  - B: competing partial amounts.
  - C: void vs duplicate void, and void vs stale replacement.
  - D: injected expense/audit failure on InnoDB.
  - E: payer decision vs platform refund, both orders.
- **Updated** `tests/MySql/worker.php` (`return_refund_void`) and `ReturnRefundAccountingTest` (its invalid-payer case now uses `buyer`, since `platform` is valid).

### BLOCKED — POLICY DECISION REQUIRED: authorized obligation and installments

The data model cannot distinguish an authorized refund obligation from collected COD. Nothing stores "₱300 of ₱500 authorized", and one active refund per return is the current rule; no amount was invented or backfilled. The smallest compatible design, for decision:

1. Add nullable `parcel_returns.authorized_refund_minor`, set by a money Admin with a reason (≤ collected COD). Null means today's behaviour (full collected).
2. Add a `refund_installments` table (amount, event, payer) so several recorded refunds can coexist. `refund_amount_minor` becomes their sum, keeping Seller-debt maths and the finance ledger on one figure.
3. Outstanding = authorized − Σ active installments; voids act per installment.

This changes Seller-funded partial-refund behaviour (a partial refund would no longer be final), so it needs an explicit decision before implementation.

## 13. Final status

**FIXED BUT NOT FULLY TESTED.** All 1.13 fixes and both accounting corrections pass focused SQLite and isolated MySQL verification. Full verification is still blocked by:
- the 49 baseline failures from Audit 1.12 and other areas, including 22 return/loss tests that cannot reach their assertions;
- one open policy item: **BLOCKED — POLICY DECISION REQUIRED** for platform-funded exception refunds (§12a).
