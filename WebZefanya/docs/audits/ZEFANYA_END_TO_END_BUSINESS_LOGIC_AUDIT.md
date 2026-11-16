# Zefanya — End-to-End Business Logic & Workflow Audit (READ-ONLY)

**Project:** `C:\ProjectEcommerce\WebZefanya` (Laravel backend + Blade/Livewire UI + Sanctum API)
**Branch:** `main` (working tree preserved; no edits made)
**Date:** 2026-11-12
**Mode:** Read-only audit. No application code, config, routes, views, tests, or migrations were changed. This report is the only file created.
**Method:** Read-only inspection of routes → middleware → controller → service → model → migration → DB schema. File:line citations are given for implemented code only. Nothing was executed against a live MySQL database or a real browser.

> **Evidence labels:** `VERIFIED` = read in source code (file:line cited). `SUSPECTED` = inferred from code but not observed running. `GAP` = no supporting implementation found. `CONFLICT` = code and documentation disagree.

---

## A. Executive Summary

### Overall assessment

Zefanya implements a substantial, largely transactional marketplace lifecycle: Buyer checkout → Seller accept/pack/ready → Logistics pickup dispatch (manual rider assignment) → hub receipt & sorting → hub-to-hub transfer → delivery rider assignment → proof of delivery with COD collection → Buyer receipt (or 168-hour auto-completion) → Logistics COD remittance → settlement with commission → Seller payout. Returns, disputes, lost/damaged parcels, and COD shortage recovery are also implemented.

The core COD commerce loop is **code-complete and heavily tested** (see Section K), but several **P0/P1 operational and financial gaps** would surface under hundreds or thousands of orders:

- **No Admin-facing pickup queue or manual rider-assignment UI.** Logistics can assign pickup riders from the Incoming Parcels screen, but Admin has no route/page to view pending pickup requests, unassigned parcels, or to manually assign/reassign a rider. Admin is blind to the biggest volume choke point (Section G).
- **COD payment is marked "Paid" at `DELIVERED` (cash in rider's hand), before the rider remits cash to Logistics and Logistics remits to Zefanya.** The `Payment` row says "paid" while cash is still in the courier's remittance chain. Tracked separately via `CodRemittance`/`LogisticsRemittance`, so reconcilable, but any report reading `payments.status = Paid` as "money received by Zefanya" overstates collected revenue.
- **Stock restoration on return is config-gated** (`config('returns.restore_verified_resellable')`) and only when the returned item is verified resellable. If off or verification fails, stock is not restored and no automatic re-listing exists.
- **Return window (168 h) equals the auto-completion window (168 h).** A buyer waiting until the end of the window risks the order auto-completing before a return is requested; an open return blocks auto-completion, but the two clocks are identical — a live boundary race.
- **Pickup assignment is manual-only in principle, but `CourierAssignmentService::assignPickup()` transitions a parcel straight to `PickedUp` when Logistics assigns a rider**, i.e. before physical collection. This conflates "rider assigned/reserved" with "physical custody transferred" unless a separate rider confirmation step gates it (needs live verification).

### Critical risks

| # | Risk | Severity | Why it matters at scale |
|---|------|----------|-------------------------|
| R1 | Admin has no pickup-queue view or manual rider assignment route | P1 | 100+ simultaneous pickup requests cannot be triaged, batched, or re-assigned by Admin; Logistics must do it all from one screen |
| R2 | COD `Payment` marked Paid at `DELIVERED` before cash reaches Zefanya | P1 | Reports treating `payments.status = Paid` as platform receipts overstate collected money; reconciliation depends entirely on the separate remittance chain |
| R3 | `assignPickup()` advances to `PickedUp` on assignment (pre-evidence custody) | P1 | A parcel can be recorded as in rider custody before physical collection; a lost parcel between assignment and collection has no verified custody gap |
| R4 | Return stock restoration is config- and verification-gated | P2 | Sellable stock can be silently understated after returns; no automatic re-listing |
| R5 | 168 h return window == 168 h auto-complete window | P2 | Boundary race between buyer return request and system auto-completion |
### Confirmed working workflows

- Buyer checkout (token-guarded, one-time), stock validation under row locks, multi-seller order splitting, `Order` + `SellerOrder` + `OrderItem` + `Payment` created atomically. (`CheckoutService`, `CheckoutController`, VERIFIED)
- Seller accept/pack/ready with `lockForUpdate` compare-and-swap, status-graph enforcement, actor gating (`OrderActor`), `StatusHistory` audit rows, parent `Order` rollup. (`SellerOrderStatusService`, `OrderActor::allowedTransitions`, VERIFIED)
- Two delivery attempts max, failed delivery → return to hub/seller path, pickup failure → second attempt → cancel + stock restore. (`SellerOrderStatusService::MAX_DELIVERY_ATTEMPTS = 2`, VERIFIED)
- COD collection with exact-amount validation, idempotent `recordCodCollection`, rider remittance, logistics remittance (submit/confirm/reject), origin-share 40/60 split. (`Payment`, `CodRemittance`, `LogisticsRemittanceService`, VERIFIED)
- Seller settlement requires CodRemittance confirmed + received by Zefanya + commission finalized + full reconciliation check before payout eligibility. (`SellerPayoutRequestService::isReconciled`, VERIFIED)
- 7-day return window, 3-day Seller decision, one Admin escalation, return custody events, fault attribution (seller/logistics/other). (`ParcelReturnService`, `ParcelReturn`, VERIFIED)
- Auto-completion after 168 h with incident/return/exception guards. (`AutomaticBuyerReceiptService`, VERIFIED)
- Registration/OTP/Admin approval for Buyer, Seller, Logistics, Rider. (VERIFIED per prior audit)

### Biggest operational bottlenecks

1. **Pickup intake is single-screen, Logistics-only.** No Admin oversight, no batch/manual-bulk confirmation UI, no rider-workload surface for Admin (Section G).
2. **Financial reconciliation is scattered** across `Payment`, `CodRemittance`, `LogisticsRemittance`, `SellerSettlement`, `SellerPayoutRequest`, `SellerDebt`, `RiderPayout`, `OriginSharePayout`. No single "where is the money" aggregate for Admin (Section F).
3. **Custody is derivable, not a single column.** Last verified physical custodian must be reconstructed from `ManifestItem.received_at`, `ParcelHandoff.received_at`, `StatusHistory`, `custody_provider_id`/`custody_hub_id` (noted GAP in prior audit, still present).

## B. End-to-End Workflow Map

The complete order lifecycle, as implemented:

```
1. BUYER checkout
   Cart → checkout token (one-time) → address/barangay + shipping-rate snapshot
   → voucher resolve → price re-validation under lock
   → placeOrder (DB transaction):
     INSERT orders (PLACED)
     INSERT seller_orders per seller (PLACED)
     INSERT order_items (price snapshot, variant snapshot)
     INSERT payments (Pending, COD only)
     INSERT inventory_transactions (checkout decrement)
     planRoutesForOrder → parcel_route snapshots
     → notify sellers
   [CheckoutService::placeOrder; CheckoutController::store]

2. SELLER fulfilment (per slice)
   PLACED → CONFIRMED (accept) → PREPARING (pack) → READY_FOR_PICKUP (mark ready)
   → pickup window scheduled (PickupWindowService::schedule)
   → carrier notified (PickupRequested notification)
   [SellerOrderController::accept/prepare/markReady; SellerOrderStatusService::transition]
   Seller may CANCEL at PLACED/CONFIRMED/PREPARING/READY_FOR_PICKUP → stock restored

3. PICKUP (Logistics assigns; Rider collects — MANUAL ONLY)
   READY_FOR_PICKUP → Logistics picks rider from Incoming Parcels
   → CourierAssignmentService::assignPickup (reserves courier, records DeliveryAssignment)
   → Rider confirms collection with verification token → PICKED_UP
   [IncomingParcelsController; PickupAssignmentsController::confirm; RiderOperationsService]

4. ORIGIN HUB → TRANSFER → DESTINATION HUB
   PICKED_UP → Logistics station receipt scan → AT_SORTING_CENTER
   → SORTED → (hub-to-hub transfer) IN_TRANSIT → ARRIVED_AT_HUB → SORTED
   → delivery rider assigned (ASSIGNED_TO_RIDER) → OUT_FOR_DELIVERY
   [ParcelScanController; ParcelSortingService; CourierAssignmentService::dispatch(delivery)]

5. DELIVERY (Rider)
   OUT_FOR_DELIVERY → delivery OTP → buyer door → OTP/code + photo proof
   → COD collected (exact amount) → DELIVERED
   [RiderOperationsService::completeDelivery; DeliveryAssignmentsController::deliver]
   Failed attempt → DELIVERY_FAILED (max 2 attempts) → reschedule or return to hub/seller

6. BUYER receipt
   Buyer confirms receipt → COMPLETED (immediate)
   OR System auto-completes after 168 h if no confirmation and no open return/incident/exception
   [BuyerShipmentConfirmation; AutomaticBuyerReceiptService::complete]
   At DELIVERED, COD payment marked Paid (settleCodPayment)

7. SETTLEMENTS
   Rider collects COD → CodRemittance (PENDING_REMITTANCE)
   Rider remits to Logistics → Logistics remittance (SUBMITTED)
   Admin confirms/rejects (CONFIRMED/REJECTED, partial allowed)
   → SellerSettlement prepared (commission finalized, net computed)
   → Seller payout request → Admin approve/reject → external payment recorded
   [LogisticsRemittanceService; SellerSettlementService; SellerPayoutRequestService]

8. RETURNS (reverse logistics)
   Buyer requests within 168 h of delivery → Seller decides within 3 days
   → Approve (return pickup, reverse route) / Reject → Buyer may escalate to Admin (one)
   → Admin upholds/overturns → return pickup → Seller receipt (or dispute wrong/damaged/empty box)
   → stock restored (config-gated) → refund recorded (manual) → fault attribution
   [ParcelReturnService; ReturnChargeService; SellerSettlementService::restoreAfterCancelledReturn]

9. DISPUTES & INCIDENTS
   ParcelIncident (lost/damaged/missing), ParcelLossCase (Admin-confirmed lost), return disputes
   [ParcelIncidentService; ParcelLossService; Admin IncidentController]

10. COD REMITTANCE & RIDER EARNINGS
   CodRemittance → LogisticsRemittance → origin share payout (40% cross-provider)
   RiderEarning (pickup/delivery) → RiderPayout → Logistics pays rider
   [LogisticsRemittanceService; RiderEarningService; EarningsController]
```
## C. Findings Table

Each finding cites implemented code (file:line). Severity: P0 = financial loss / security exploit / irreversible custody or data corruption; P1 = broken critical workflow or serious operational bottleneck; P2 = important validation / monitoring / usability issue; P3 = minor optimization / documentation.

| ID | Severity | Role | Workflow | Problem | Evidence (file:line) | Impact | Recommended change |
|----|----------|------|----------|---------|----------------------|--------|--------------------|
| F-01 | P1 | Admin | Pickup operations | No Admin route/page to view pending pickup requests, unassigned parcels, or to manually assign/reassign a pickup rider. `routes/admin.php` has dashboard, remittances, settlements, returns, incidents — but no pickup/assignment routes. Admin cannot triage the 100+ pickup queue. | `routes/admin.php` (no pickup routes); prior audit `docs/audits/ADMIN_ORDER_MONITORING_PHASE1.md` §"NOT implemented" | Pickup backlog cannot be monitored or balanced by Admin; single Logistics screen is the sole triage point | Add Admin read-only pickup-queue view and a manual rider-assignment action gated by eligibility (coverage, workload, online status). Admin assigns but never auto-assigns. |
| F-02 | P1 | Logistics / Rider | Pickup custody | `CourierAssignmentService::assignPickup()` reserves a courier AND advances the parcel straight to `PickedUp` when Logistics assigns the rider — before the rider physically collects the parcel. This conflates "assigned/reserved" with "physical custody transferred." | `app/Services/Logistics/CourierAssignmentService.php` `assignPickup()` (~165-241); `PickupAssignmentsController::confirm` re-checks `fresh()?->status !== PickedUp` | A parcel can be recorded as in rider custody before physical collection. A parcel lost between assignment and collection shows a false "PickedUp" custody state. | Split into two atomic steps: (a) Logistics assigns/reserves rider (status stays READY_FOR_PICKUP, courier_id + DeliveryAssignment), (b) Rider confirms physical collection with verification token → PICKED_UP. Verify `assignPickup` does not skip step (b). |
| F-03 | P1 | All roles | Financial reporting | `Payment::markPaid()` / `settleCodPayment()` marks a COD payment `Paid` when the seller_order reaches `DELIVERED` — i.e. when cash is in the rider's hand, before the rider remits to Logistics and Logistics remits to Zefanya. `payments.status = Paid` is not "money received by the platform." | `app/Services/Seller/SellerOrderStatusService.php:666` `settleCodPayment()`; `app/Models/Shared/Payment.php:55` `markPaid()`, `:82` `recordCodCollection()`; `app/Models/Logistics/CodRemittance.php` | Reconciliation and any dashboard reading `Payment.status=Paid` overstate platform receipts. Actual platform receipt depends on the separate `CodRemittance` → `LogisticsRemittance` chain. | Label payment status precisely (e.g. `collected_by_rider` vs `remitted_to_platform`) or ensure all money reports read `CodRemittance.received_amount_minor`/`zefanya_received_at`, not `Payment.status`. Never treat collected == received. |
| F-04 | P2 | Seller / Admin | Returns & stock | Stock restoration after a verified return is config-gated: `StockService::set(reason='return')` is only called when `config('returns.restore_verified_resellable') === true` AND condition is resellable. If off or verification fails, stock is not restored and there is no automatic re-listing or re-verification flow. | `app/Services/Logistics/ParcelReturnService.php` (return-receipt path → StockService); `app/Services/Seller/StockService.php:36` `set()`; `app/Models/Logistics/ParcelReturn.php` dispositions | Sellable inventory silently understated after returns; no trace of non-resellable returns for disposition. | Make stock restoration unconditional for verified resellable returns; add a disposition queue (destroy/return-to-buyer/held) for non-resellable returns with Admin review. |
| F-05 | P2 | Buyer / System | Returns vs completion | Return window (168 h from delivery) equals the auto-completion window (168 h). `AutomaticBuyerReceiptService::eligibleOrders()` blocks completion when an open return exists, and `ParcelReturnService::canBuyerRequest()` requires delivery within the deadline, but the two clocks are identical — a boundary race exists where auto-completion and a late return request can contend. | `app/Services/Buyer/AutomaticBuyerReceiptService.php:18` `WAIT_HOURS = 168`; `:24` `eligibleOrders()`; `app/Services/Logistics/ParcelReturnService.php:59` `buyerReturnDeadline()` uses same 168 h | Buyer may lose return eligibility to auto-completion at the boundary; support load at the 7-day edge. | Stagger the windows (e.g. return window shorter than auto-complete, or explicit "return requested pauses the completion clock" guard), documented as a policy decision. |
| F-06 | P2 | Logistics | Custody accountability | No single "last verified physical custodian" column/service. Custody must be reconstructed from `custody_provider_id`/`custody_hub_id` + latest `ManifestItem.received_at` + `ParcelHandoff.received_at` + `StatusHistory`. | `app/Models/Shared/SellerOrder.php:136-147` `currentCustodyProviderId()`, `:168-190` `scopeHeldBy()`; prior audit `docs/audits/ADMIN_ORDER_MONITORING_PHASE1.md` §custody GAP | At any moment, Admin/Logistics cannot answer "who last physically held this parcel?" without assembling multiple tables. Slow exception handling at scale. | Add a `last_verified_custody_*` snapshot (provider/hub/riders, verified_at, evidence ref) updated only on verified scans/handoffs. |
| F-07 | P2 | Rider / Logistics | Delivery assignment | It is not fully evident from source whether delivery-rider assignment is automatic (`CourierAssignmentService::dispatch('delivery')` is invoked from `dispatchAfterCommit` when status = Sorted) or requires a Logistics manual step. `dispatchAfterCommit` auto-triggers delivery dispatch after station receipt/sorting. | `app/Services/Logistics/CourierAssignmentService.php:32` `dispatchAfterCommit()`; `:45-47` `if status === Sorted → dispatch(delivery)` | If delivery assignment is automatic, it may conflict with the "manual rider assignment" business rule for the delivery leg and bypass workload/coverage checks under load. | Clarify and document whether delivery assignment is automatic or manual; if automatic, add eligibility/workload guards and an Admin override. |
| F-08 | P2 | Buyer | Checkout concurrency | Duplicate-order protection relies on the one-time `CheckoutToken` (consumed in the order transaction). A browser refresh / double-submit after token consumption returns to cart rather than creating a duplicate order — partially protected, but token issuance vs. consumption race under extreme concurrency is untested in MySQL (only Seller has a race test per prior audit). | `app/Services/Buyer/CheckoutService.php:92` `issueToken()`; `app/Models/Buyer/CheckoutToken.php`; prior audit `docs/audits/ZEFANYA_FULL_SYSTEM_AUDIT.md` §Buyer/Logistics decision races untested | Rare duplicate order or lost selection under rapid double-click. | Add a unique constraint / idempotency key on (buyer, checkout_token) and a MySQL concurrency test for checkout. |
| F-09 | P3 | Seller | Cancellation | Seller can cancel at `READY_FOR_PICKUP` (after packing, before pickup). Stock is restored via `restoreStock()`. A parcel already handed to a rider cannot be cancelled by the Seller (graph blocks it) — correct — but cancellation after pickup requires Logistics/System. | `app/Enums/OrderActor.php:29-39` Seller allowed transitions; `app/Services/Seller/SellerOrderStatusService.php` `restoreStock()` | Seller cannot cancel a parcel already in Logistics custody — correct behavior, but no "request cancellation from Logistics" workflow exists for in-transit parcels. | Add a Logistics-mediated cancellation request workflow for post-pickup parcels, with return-to-seller routing. |
| F-10 | P3 | Admin | Monitoring | No per-order financial summary for Admin ("where is the money / what remains unpaid per order"). Data is scattered across `Payment`, `CodRemittance`, `LogisticsRemittance`, `SellerSettlement`, `SellerPayoutRequest`, `SellerDebt`, `RiderPayout`, `OriginSharePayout`. | Prior audit `docs/audits/ADMIN_ORDER_MONITORING_PHASE1.md` §financial reconciliation scattered | Admin cannot reconcile a specific order's money trail at a glance. | Add an Admin order/shipment monitoring page with a per-order financial summary (Section A of prior audit). |
| F-11 | P3 | Logistics | Remittance partials | `LogisticsRemittance` supports partial confirmation (`isPartlyConfirmed()`, `left_out_count`) and `CodRemittance` supports partial remittance (`PARTIALLY_REMITTED`, `shortage_minor`). Shortage recovery exists but there is no automatic shortfall escalation timeline beyond `OVERDUE_AFTER_DAYS = 3` flag. | `app/Models/Logistics/LogisticsRemittance.php:13-17,37`; `app/Models/Logistics/CodRemittance.php:15-21,116-124`; `app/Services/Logistics/LogisticsRemittanceService.php:41` `OVERDUE_AFTER_DAYS = 3` | Partial remittances and shortages can age without enforced escalation. | Add scheduled escalation of overdue remittances/shortages to Admin with aging report. |
| F-12 | P2 | Buyer | Returns | One return request per eligible shipment is enforced via `parcel_return === null` check, but there is no hard unique constraint at DB level visible; a rapid double request could race. | `app/Services/Logistics/ParcelReturnService.php:77` `canBuyerRequest()` (`$parcel->parcelReturn === null`) | Duplicate return requests under concurrency. | Add a unique index on `parcel_returns.seller_order_id` where status is open, or lock the seller_order during return intake. |
| F-13 | P2 | System | Idempotency | Financial write paths (`recordCodCollection`, payout request idempotency key, remittance confirm) are idempotent, but general custody transitions rely on compare-and-swap under lock rather than idempotency keys. Browser refresh during a financial submission is guarded for COD collection but not universally. | `app/Models/Shared/Payment.php:82` `recordCodCollection()` (idempotent); `app/Services/Seller/SellerPayoutRequestService.php:62` `request()` (uuid idempotency key) | Duplicate financial actions on refresh/retry. | Add idempotency keys to all financial write endpoints (remittance confirm, settlement payout, refund record). |
| F-14 | P0/P1 | Rider | Earnings | Rider earnings (`RiderEarning`) are computed per pickup/delivery; payout to rider is via Logistics `EarningsController::payRider`/`reverseRiderPayment`. It is not evident from source that rider payout is blocked when the rider has an outstanding COD shortage (`CodRemittance.shortage_minor`). | `app/Models/Logistics/CodRemittance.php:116` `outstandingShortageMinor()`; `routes/logistics.php` earnings routes | A rider with an unpaid COD shortage could still be paid out, netting against the platform. | Block rider payout when an outstanding shortage exists; net shortage against rider earnings before payout. |
| F-15 | P2 | System | Notifications | Notification delivery failures are not visibly retried or surfaced to Admin (failed-job pruning exists in scheduler, but no per-order "notification undelivered" flag for critical steps: pickup assigned, delivery OTP, return decision). | `routes/console.php` (failed-job pruning); `app/Notifications/` | Buyer/rider may miss a delivery OTP or pickup assignment with no alert. | Add notification-delivery status and a resend action for critical transactional notifications. |
## D. Role Responsibilities

Exactly what each role must do at every stage, as implemented:

### Buyer

1. Browse catalogue, add to cart, select items, choose shipping address + barangay (coverage validated at checkout).
2. Review shipping-fee snapshot and vouchers on the checkout review page; fingerprints guard against price/shipping changes mid-checkout.
3. Place order (one-time checkout token). Receives `Order` + per-seller `SellerOrder` slices at `PLACED`.
4. May cancel only while the slice is `PLACED`/`CONFIRMED` (before Seller accepts/packs).
5. Tracks shipment; at `DELIVERED`, confirms receipt → `COMPLETED`. If silent, System auto-completes after 168 h (unless an open return/incident/exception blocks it).
6. May request a return within 168 h of delivery (one per shipment). Provides reason + photos.
7. If Seller rejects the return, may escalate to Admin exactly once.
8. Pays COD cash to the Rider at the door (exact amount), optionally after delivery OTP/code.

### Seller

1. Receives `NewOrderReceived` notification at `PLACED`.
2. Accepts (`PLACED`→`CONFIRMED`), packs (`CONFIRMED`→`PREPARING`), marks ready (`PREPARING`→`READY_FOR_PICKUP`) — each through `SellerOrderStatusService::transition` with row lock + compare-and-swap.
3. May reject/cancel at `PLACED`/`CONFIRMED`/`PREPARING`/`READY_FOR_PICKUP` → slice `CANCELLED`, stock restored.
4. Cannot perform Logistics-only actions: cannot assign a Rider, cannot confirm pickup, cannot execute hub-to-hub transfer (actor graph blocks these; `OrderActor::allowedTransitions(Seller)` omits them).
5. On a return request: decide within 3 days (approve/reject). On receipt of the returned parcel: confirm receipt or report wrong/damaged/empty box (receipt dispute).
6. Requests payout of finalized settlements; Admin approves/rejects.

### Logistics Provider

1. Sees `ReadyForPickup` and `PickedUp` parcels in Incoming Parcels (coverage-scoped; unclaimed legacy parcels offered by barangay coverage).
2. **MANUALLY selects and confirms an eligible pickup Rider** (coverage + eligibility + workload). No automatic pickup assignment, no automatic retry/fallback.
3. Confirms station receipt scan → `PickedUp`→`AT_SORTING_CENTER`; releases pickup courier.
4. Sorts (`AT_SORTING_CENTER`→`SORTED`), builds hub-to-hub transfers (`IN_TRANSIT`), confirms destination receipt (`ARRIVED_AT_HUB`), re-sorts.
5. Assigns delivery rider at destination (`SORTED`→`ASSIGNED_TO_RIDER`→`OUT_FOR_DELIVERY`) — verify whether this is manual or auto-triggered (`dispatchAfterCommit`).
6. Handles failed delivery: return to hub / reschedule / return-to-seller (max 2 delivery attempts).
7. Rider remits COD cash to Logistics; Logistics submits `LogisticsRemittance` to Zefanya (COD − kept shipping); Admin confirms/rejects/partially-confirms.
8. Cross-provider: origin carrier keeps 40% of shipping fee (`CodRemittance.origin_share_minor`), paid via `OriginSharePayout`.

### Rider

1. Sets availability; is eligible only when approved/active/verified/station-owned/linked Courier (`Rider::isOperational()`, `isEligibleForAssignment()`).
2. Receives pickup assignment from Logistics; confirms physical collection with verification token → `PICKED_UP` (verified handoff).
3. Scans parcel at origin hub; handoff to station (Logistics confirms receipt).
4. On delivery leg: assigned to rider → out for delivery → delivery OTP → buyer door → collect exact COD → photo proof → `DELIVERED`.
5. Failed delivery → `DELIVERY_FAILED`; max 2 attempts; then return to hub/seller path.
6. Second pickup failure → parcel `CANCELLED`, stock restored, Admin alerted.
7. Earnings: pickup + delivery fees; paid out by Logistics (blocked if outstanding COD shortage — to be enforced).

### Admin

1. Monitors (does NOT operate Logistics, does NOT assign Riders): all active orders, pending Seller acceptance, preparation delays, pickup requests awaiting assignment, assigned Riders, pickup completion, hub-to-hub movement, cross-provider handoffs, delivery failures, delayed shipments, returns, open disputes, lost/damaged parcels, COD awaiting remittance, Seller payouts, refund obligations, Logistics settlement obligations.
2. Approves/rejects registrations (Buyer/Seller/Logistics/Rider).
3. Confirms/rejects/partially-confirms `LogisticsRemittance`; pays origin share; records Seller payouts (external payment reference).
4. Resolves disputes: return disputes (one escalation), parcel loss cases (lost/damaged), incidents.
5. Suspends/reactivates accounts; writes immutable `OperationsAuditLog` entries.
6. **No pickup-queue or rider-assignment route exists today** (F-01) — a gap, not a permission issue.
## E. Status Transition Audit

Status graph (`app/Enums/OrderStatus.php`): `PLACED, CONFIRMED, PREPARING, READY_FOR_PICKUP, PICKED_UP, AT_SORTING_CENTER, SORTED, IN_TRANSIT, ARRIVED_AT_HUB, ASSIGNED_TO_RIDER, OUT_FOR_DELIVERY, DELIVERED, COMPLETED, DELIVERY_FAILED, RETURNED, CANCELLED, LOST`.

Actor ownership (`app/Enums/OrderActor::allowedTransitions()`):

- **Seller**: `PLACED→CONFIRMED`, `CONFIRMED→PREPARING`, `PREPARING→READY_FOR_PICKUP`; cancels `PLACED/CONFIRMED/PREPARING/READY_FOR_PICKUP→CANCELLED`. Also `DELIVERY_FAILED→RETURNED` and `DELIVERED→RETURNED` (return receipt disputes). ✅ Cannot assign Rider / confirm pickup / execute transfer (actor graph excludes these — correct).
- **Rider**: `READY_FOR_PICKUP→PICKED_UP` (pickup collection), `READY_FOR_PICKUP→CANCELLED` (second pickup failure), `ASSIGNED_TO_RIDER→OUT_FOR_DELIVERY`, `OUT_FOR_DELIVERY→DELIVERED`, `OUT_FOR_DELIVERY→DELIVERY_FAILED`. ✅ Cannot confirm station receipt (`PickedUp→AT_SortingCenter` is Logistics).
- **Logistics**: `PICKED_UP→AT_SORTING_CENTER`, `AT_SORTING_CENTER→SORTED`, `SORTED→ASSIGNED_TO_RIDER`, hub transfers `AT_SORTING_CENTER/SORTED/ARRIVED_AT_HUB→IN_TRANSIT`, `IN_TRANSIT→ARRIVED_AT_HUB`, `ARRIVED_AT_HUB→SORTED`, recovery `DELIVERY_FAILED→ASSIGNED_TO_RIDER` or `→RETURNED`.
- **Buyer**: `DELIVERED→COMPLETED` (confirm receipt), `PLACED→CANCELLED` (pre-acceptance).
- **System**: `DELIVERED→COMPLETED` (auto-receipt after 168 h).

### Invalid / missing / conflicting transitions

| Transition | Status | Evidence | Assessment |
|------------|--------|----------|------------|
| `READY_FOR_PICKUP→PICKED_UP` by Logistics `assignPickup()` | **CONFLICT** | `CourierAssignmentService::assignPickup()` advances to `PickedUp` on assignment | Assignment ≠ physical collection. Either keep status at `READY_FOR_PICKUP` until Rider confirms, or document that assignment == collection (weak custody). |
| `DELIVERED→COMPLETED` by Buyer AND by System | OK (both allowed) | `OrderActor::allowedTransitions` Buyer + System | Idempotency guard: `transition()` compare-and-swap prevents double completion. |
| Seller `READY_FOR_PICKUP→CANCELLED` | OK | Seller allowed transitions | Stock restored; correct post-pack cancellation. |
| Buyer cancel after Seller accepts | BLOCKED (correct) | Buyer allowed transitions only `PLACED→CANCELLED` | Buyer cannot cancel once Seller accepted — correct, must go through returns. |
| `DELIVERED→COMPLETED` while open return exists | BLOCKED | `AutomaticBuyerReceiptService::eligibleOrders()` excludes open returns; `SellerOrderStatusService::blocksCompletion()` | Correct guard; race at 168 h boundary (F-05). |
| `PICKED_UP→AT_SORTING_CENTER` by Rider | BLOCKED (correct) | Rider allowed transitions omit it; Logistics owns it | Correct custody separation. |
| `LOST` re-opened by later scan | BLOCKED (by design) | `OrderStatus` docblock: "Never reopened by a later scan" | Correct — `ParcelLossCase` is terminal; Admin-confirmed only. |
| `RETURNED` vs `CANCELLED` rollup | OK | `Order::computeRollupStatus()` | All-returned → `Returned`; all-cancelled → `Cancelled`; mixed → `Completed`. |

### Irreversible transitions
- `CANCELLED`, `LOST`, `RETURNED`, `COMPLETED` are terminal in the rollup. `LOST` is explicitly never reopened. `COMPLETED` can only be followed by a return (reverse logistics is a separate `ParcelReturn`, not a status re-open — correct).
## F. Financial Reconciliation Audit

Money trail (COD only payment method — `PaymentMethod` has only `cod`):

1. **Buyer → Rider**: `Payment` (per `seller_order`) `Pending`, amount = order total. At `DELIVERED`, `settleCodPayment()` marks `Paid` (`SellerOrderStatusService.php:666`). Rider collects exact cash; `Payment::recordCodCollection()` validates `amountMinor === amount_minor`, idempotent (`app/Models/Shared/Payment.php:82`).
2. **Rider → Logistics**: Rider remits collected cash. `CodRemittance` per payment: `amount_minor`, `kept_shipping_minor` (60% of shipping if cross-provider else 100%), `origin_share_minor` (40% cross-provider), `received_amount_minor`, `shortage_minor`, status `PENDING_REMITTANCE`→`REMITTED_TO_LOGISTICS`/`PARTIALLY_REMITTED`.
3. **Logistics → Zefanya**: `LogisticsRemittance` (`cod_total_minor`, `shipping_total_minor`, `amount_minor`, `received_amount_minor`, `short_minor`, `left_out_count`). Admin `SUBMITTED`→`CONFIRMED`/`REJECTED`; partial confirmation allowed (`isPartlyConfirmed()`). `OVERDUE_AFTER_DAYS = 3`.
4. **Zefanya → origin carrier (cross-provider)**: `OriginSharePayout` pays the 40% origin share to the carrier the buyer chose at checkout.
5. **Zefanya → Seller**: `SellerSettlement` prepared only when CodRemittance confirmed + received by Zefanya + commission finalized + full reconciliation (`SellerPayoutRequestService::isReconciled`, `app/Services/Seller/SellerPayoutRequestService.php:297`). `SellerPayoutRequest` (idempotency key, min ₱100) → Admin approve/reject → external payment recorded → `SellerSettlement.paid_at`.
6. **Rider earnings**: `RiderEarning` (pickup/delivery fees) → `RiderPayout`; Logistics pays rider (`EarningsController::payRider`/`reverseRiderPayment`).

### Reconciliation findings

| Check | Status | Evidence |
|-------|--------|----------|
| Every peso accounted, no double counting | `isReconciled()` asserts `payment.amount_minor === remittance.amount_minor === settlement.collected_amount_minor`, commission + net = subtotal − allocation, shipping allocation consistent | `app/Services/Seller/SellerPayoutRequestService.php:297-335` |
| Collected ≠ platform receipt | `Payment.Paid` at `DELIVERED` precedes `CodRemittance.zefanya_received_at`; `LogisticsRemittance.received_amount_minor` vs `amount_minor` shows the gap | F-03 |
| Duplicate payout prevention | Idempotency key (uuid) on payout request; `reservedMinor()` locks pending requests; `eligibleSettlements` locks seller row | `app/Services/Seller/SellerPayoutRequestService.php:62,56,281` |
| Partial remittance / shortage | `CodRemittance.PARTIALLY_REMITTED`, `shortage_minor`, `shortage_recovered_minor`, `outstandingShortageMinor()` | `app/Models/Logistics/CodRemittance.php:15-21,116-124` |
| Seller debt (returned after paid) | `SellerDebt` tracked; `outstandingDebtMinor()` reduces available balance | `app/Models/Seller/SellerDebt.php`; `SellerPayoutRequestService::availableBalance()` |
| Escrow | None invented. Money flows through `CodRemittance`/`LogisticsRemittance`/`SellerSettlement`; no platform-held escrow beyond confirmed remittance. | Confirmed by design |

**GAP:** No single Admin "where is the money / unpaid obligations" aggregate per order. Admin must assemble `Payment` + `CodRemittance` + `LogisticsRemittance` + `SellerSettlement` manually (F-10).
## G. 100-Pickup Stress Scenario

### How the current system handles 100 simultaneous pickup requests

1. **Ingress:** 100 Sellers mark parcels ready → 100 `SellerOrder` rows at `READY_FOR_PICKUP`, each with a scheduled pickup window (`PickupWindowService::schedule`) and a `PickupRequested` notification to the carrier. Writes are serialized per-row by `lockForUpdate` in `markReady`/`transition` — no table-level lock, so 100 concurrent `markReady` calls do not deadlock on each other (different rows).

2. **Queue surface:** `IncomingParcelsController::index` queries `seller_orders` where status in (`READY_FOR_PICKUP`, `PickedUp`), scoped to the Logistics provider by `custody_provider_id`/`carrier_provider_id` or by barangay coverage for unclaimed parcels. This is the **single queue** for all pending pickups for that provider. With 100 parcels, one provider's inbox = 100 rows (paginated). Workload per courier is annotated (`withWorkload()`).

3. **Assignment (MANUAL ONLY):** Logistics opens a parcel and calls `CourierAssignmentService::assignPickup()` (or the equivalent manual confirmation). It:
   - locks the provider (`LogisticsHubService::lockProvider`) and the `seller_order` row (`lockForUpdate`),
   - runs `candidateFor()` to find eligible couriers (coverage via `coversBarangay`/`coversMunicipality`, `isEligibleForAssignment()`, station-hub match),
   - records a `DeliveryAssignment` (`role='pickup'`, `assigned_by_type='logistics'`),
   - writes an `OperationsAuditLog` manual-rider audit entry (`auditManualRider`),
   - transitions to `PickedUp` (see F-02 — custody conflation).
   There is **no automatic assignment, no automatic retry assignment, and no automatic fallback** — consistent with the business rule. The system does not auto-promote from the queue.

4. **Rider confirmation:** The assigned Rider sees the parcel in `PickupAssignmentsController::index` (their pickups), confirms collection with a verification token (`PickupAssignmentsController::confirm` → `assertPickupAssigned` + `ParcelHandoffVerification::ACTION_CONFIRM_PICKUP`), which transitions `READY_FOR_PICKUP`→`PICKED_UP` if not already.

5. **Workload limits:** Courier workload is exposed (`withWorkload()`) and eligibility (`isEligibleForAssignment()`) is checked, but the audit did not find a hard per-rider maximum-parcel cap enforced at assignment time (only eligibility + coverage). Under 100 requests, one courier could be overloaded if Logistics keeps assigning to them.

6. **Pickup windows & overdue:** `PickupWindowService` schedules a window; `pickup_missed_windows` counts misses. A scheduler job rolls missed pickup windows. Second pickup failure → `CANCELLED` + stock restore + Admin alert (`PickupAssignmentsController::reportFailure`).

7. **Offline riders:** `isEligibleForAssignment()` and `isOperational()` gate offline/suspended riders; `rider_wind_down_until` supports wind-down. Offline riders are excluded from candidates but nothing auto-reassigns their parcels (manual only — correct per rule, but creates backlog under load).
### Can the queue/assignment design handle hundreds of pickup requests?

- **DB level:** Yes — row-level locking, no table contention, paginated queue, unique `open_key` guards prevent duplicate `PickupSession` creation (`PickupSessionService::create` catches `UniqueConstraintViolationException`).
- **Operational level:** **No.** All 100 parcels land in one Logistics inbox; assignment is one-by-one manual; no batch confirmation; no Admin oversight; no workload cap enforced; no auto-reassignment for offline riders. At hundreds of requests, Logistics staff become the bottleneck and parcels age past pickup windows.

### Opportunities (grouping / filtering / batching / bulk manual confirmation)

These are recommendations, not implemented features:

- Group by seller + address hash + window (`PickupSessionService::addressHash`, `attach()`) — already used to bundle multiple parcels from the same seller into one rider visit. This should be exposed in the queue UI so Logistics confirms one session for N parcels.
- Filter by barangay/station coverage (`Area.coversBarangay`, `coversMunicipality`), courier workload (`withWorkload`), online status.
- **Bulk manual confirmation:** Logistics selects N parcels + one eligible courier → one transaction assigns all, respecting per-courier coverage and workload, recording one `DeliveryAssignment` per parcel + audit entries. Must remain manual (Logistics initiates) — no auto-assignment.
- **Admin overlay:** Admin read-only queue + ability to reassign a stuck parcel to another provider's courier (manual), without operating the pickup itself.
## H. Missing Features and Dead Ends

### Confirmed bugs (code-reading evidence)

| ID | Problem | Evidence |
|----|---------|----------|
| B-01 | No Admin pickup-queue / manual rider-assignment route | `routes/admin.php` has no pickup routes (VERIFIED) |
| B-02 | `assignPickup()` advances to `PickedUp` at assignment (pre-evidence custody) | `CourierAssignmentService::assignPickup()` (VERIFIED, requires live confirmation of confirm-gating) |
| B-03 | COD `Payment` marked Paid at `DELIVERED` before platform receipt | `SellerOrderStatusService::settleCodPayment()` `:666` (VERIFIED) |
| B-04 | Return stock restoration config-gated; no disposition queue | `StockService::set(reason='return')` (VERIFIED), config-gated per `ParcelReturnService` |
| B-05 | Return window == auto-complete window (168 h) boundary race | `AutomaticBuyerReceiptService::WAIT_HOURS = 168`; `ParcelReturnService::buyerReturnDeadline()` same 168 h (VERIFIED) |
| B-06 | No single "last verified physical custodian" column | `SellerOrder::currentCustodyProviderId()`/scopeHeldBy derive custody (VERIFIED; GAP per prior audit) |
| B-07 | Delivery assignment may be auto-triggered (`dispatchAfterCommit`) | `CourierAssignmentService::dispatchAfterCommit()` `:45-47` (VERIFIED; manual-vs-auto unclear) |
| B-08 | No per-order financial summary for Admin | Prior audit `ADMIN_ORDER_MONITORING_PHASE1.md` (VERIFIED) |

### Dead ends (no implementation found / not exposed)

| ID | Dead end | Evidence |
|----|----------|----------|
| D-01 | Admin cannot view/assign pickup requests | `routes/admin.php` — no pickup/assignment routes (VERIFIED) |
| D-02 | No "request cancellation from Logistics" for post-pickup parcels | Seller cancel graph stops at `READY_FOR_PICKUP`; no Logistics-initiated cancel-after-pickup UI |
| D-03 | No appeal form/workflow for suspensions or financial decisions | Policy review `docs/audits/POLICY_REVIEW_2026-11-11.md` §appeals GAP |
| D-04 | No self-service data export / retention purge (DPA portability) | Policy review §GAPs |
| D-05 | No age/capacity check at registration | Policy review §eligibility |
| D-06 | No automatic fraud scoring or suspension by return frequency | Confirmed ABSENT by design (policy review §rules) — this is correct; do not add |

### Proposed improvements (not bugs; do not implement now)

- Batch/bundle pickup confirmation by `PickupSession`
- Admin aging report for overdue remittances/shortages
- Notification delivery status + resend
- Idempotency keys on all financial write endpoints
- Stagger return window vs auto-complete window
- `last_verified_custody_*` snapshot columns
## I. Recommended Implementation Phases

Order by severity, dependency, risk, operational importance.

### Phase 1 — P0/P1 stability (do before high-volume launch)
1. **Admin pickup oversight:** Add Admin read-only pickup-queue view + manual rider-reassign action (no auto-assignment). Depends on: none. Risk: low (read-only + manual action).
2. **Clarify pickup custody:** Decide whether `assignPickup()` may advance to `PickedUp`. If not, split assignment (reserve, status stays `READY_FOR_PICKUP`) from Rider collection confirm (→`PickedUp`). Risk: medium (touches custody graph).
3. **COD receipt semantics:** Stop treating `Payment.Paid` as platform receipt; build Admin money report reading `CodRemittance.received_amount_minor`/`zefanya_received_at`. Risk: low (reporting only).
4. **Commit working tree + pending migrations** (per prior audit P0-01/P0-02) so the codebase is deployable on MySQL.

### Phase 2 — P1 operational
5. **Per-order financial summary for Admin** (where is the money per order). Depends on: Phase 1 #3.
6. **Enforce rider-payout block on outstanding COD shortage.** Depends on: CodRemittance shortage model (exists).
7. **Overdue remittance/shortage escalation + aging report** (`OVERDUE_AFTER_DAYS = 3` already exists).
8. **Bulk manual pickup confirmation** by `PickupSession` grouping (seller + address + window). Depends on: Phase 1 #1.

### Phase 3 — P2 correctness
9. **Unconditional stock restoration for verified resellable returns** + disposition queue for non-resellable. Depends on: return-receipt flow.
10. **Stagger return window vs auto-complete window** (policy decision — Section J).
11. **Last-verified-custody snapshot columns.**
12. **Idempotency keys on all financial write endpoints** (remittance confirm, settlement payout, refund record).
13. **Notification delivery status + resend** for critical transactional messages.

### Phase 4 — P3 polish
14. Delivery-assignment manual-vs-auto clarification + workload cap enforcement at assignment.
15. DB unique constraint on open return per seller_order.
16. MySQL concurrency test for checkout (buyer/Logistics decision races).
17. Documentation refresh (docs are stale per prior audit).

## J. Decision Required

### J-1: Return window vs auto-completion window (both 168 h)

- **Current behavior:** Buyer may request a return within 168 h of delivery (`ParcelReturnService::buyerReturnDeadline` = `AutomaticBuyerReceiptService::WAIT_HOURS = 168`). System auto-completes after 168 h unless an open return/incident/exception blocks it. At the exact boundary, auto-completion and a late return request contend; an open return blocks completion, but the clocks are identical.
- **Risk:** Buyer loses return eligibility to auto-completion; support disputes at the 7-day edge; conflicts with the documented 7-day return window being effectively shorter in practice.
- **Recommended decision:** Keep the 7-day return window (statutory-aligned) but make the auto-completion clock start only after the return window closes **and** require that an open return pauses/extends completion. Concretely: auto-completion eligible at delivery + 168 h only if no return was requested within the window; if a return is requested, completion is blocked until the return is closed/cancelled. Do NOT silently shorten the return window.
- **Alternatives:** (a) Shorten return window to <168 h — NOT recommended (weakens consumer protection, conflicts with published 7-day rule). (b) Leave as-is — accepts boundary race. (c) **Recommended above**.
- **Impact on existing workflows:** `AutomaticBuyerReceiptService::eligibleOrders()` already excludes open returns; the change is mostly about the boundary timing and user-facing clarity. No statutory conflict if the 7-day window is preserved.

### J-2: Pickup custody at assignment vs collection

- **Current behavior:** `assignPickup()` reserves the courier and advances to `PickedUp` in one step (needs live confirmation of whether Rider confirm re-gates).
- **Risk:** False custody state; a parcel lost between assignment and collection shows "PickedUp."
- **Recommended decision:** Require Rider physical-collection confirmation (verification token) as the `READY_FOR_PICKUP→PICKED_UP` transition. Logistics assignment only reserves (`courier_id` + `DeliveryAssignment`, status unchanged or a new `ASSIGNED` sub-state). This preserves "manual assignment only" while strengthening custody accountability. Never advance custody without verified evidence (audit rule).
- **Impact:** Touches `CourierAssignmentService::assignPickup`, `PickupAssignmentsController::confirm`, status graph.

### J-3: Delivery assignment manual vs automatic

- **Current behavior:** `dispatchAfterCommit()` calls `dispatch('delivery')` automatically when a parcel reaches `SORTED`.
- **Risk:** May bypass rider eligibility/workload checks under load; conflicts with a "manual only" rider-assignment policy if extended to delivery.
- **Recommended decision:** Confirm the intended policy. If delivery assignment should also be manual, gate `dispatchAfterCommit`'s auto-dispatch behind a configuration flag and require Logistics confirmation with eligibility checks. If automatic is intended, document it and add workload caps.
- **Impact:** `CourierAssignmentService::dispatchAfterCommit`.

### J-4: Admin oversight — enable read-only pickup queue

- **Risk:** Without Admin visibility, Logistics bears 100% of pickup triage; at scale parcels age and stock is silently understated.
- **Recommended decision:** Implement the Admin pickup-queue view and manual rider-reassign action (Phase 1 #1, J-1). Admin assigns but never auto-assigns. This does not change business rules; it adds monitoring capability.
- **Impact:** Adds Admin routes/pages; no changes to seller/rider/buyer logic.
## K. Verification

### Tests inspected / run (from repository and prior audit)
- PHPUnit Unit + Feature (SQLite in-memory): **2,231 tests: 2,230 passed, 1 skipped, 0 failed**, 14,329 assertions — per prior full-system audit. Covers registration/OTP/approval, catalogue/cart/checkout, order lifecycle, cancellation/completion/auto-receipt/commission, logistics dispatch/sorting/manifests/handoffs/COD remittance/failed-delivery return, rider POD/scans, seller payout request (Seller side only).
- PHPUnit MySQL concurrency (`phpunit.mysql.xml`, dedicated `zefanya_concurrency_test` DB): **51 tests: 51 passed**, 726 assertions — covers Seller race; **Buyer/Logistics decision races on MySQL untested** (per prior audit).
- Node JS unit tests (`tests/js/*.test.mjs`): 24 tests, 23 passed, 1 failed — the 1 failure is unrelated to core business logic (to be identified).
- Node frontend coverage test: 4 passed.

### Not run (read-only audit constraint)
- `php artisan migrate:status` against live MySQL (2 migrations were pending per prior audit; not executed here).
- `npm run build`, Pint, Playwright/browser journeys.
- Any test against the application (MySQL) database beyond the concurrency suite already run.
- Live browser run of Flows 1–5 and 10 with real accounts on MySQL.

### Unverified assumptions (require live MySQL or browser testing)
1. `CourierAssignmentService::assignPickup()` — whether it truly advances to `PickedUp` before Rider confirmation, or whether `PickupAssignmentsController::confirm` re-gates with `ParcelHandoffVerification::ACTION_CONFIRM_PICKUP`. **Must be verified on a running MySQL instance.**
2. Whether delivery assignment is automatic (`dispatchAfterCommit` → `dispatch('delivery')`) or requires Logistics manual confirmation at runtime.
3. The exact behavior of `config('returns.restore_verified_resellable')` default and whether stock restoration actually fires on verified returns.
4. The Node JS unit-test failure (1 of 24) — identify whether it touches business logic.
5. Pending migrations (2) applied cleanly on local MySQL and `migrate:status` shows 0 pending.
6. XSS payload (E-01 in prior audit) and its fix — browser verification.
7. Real-browser run of the full buyer→seller→logistics→rider→buyer flow including return, dispute, COD remittance, and payout.

### Conclusion
Zefanya's implemented business logic is **substantially complete and transactionally sound for the core COD loop**, with strong row-locking, actor-gated status graphs, idempotent financial writes, and a clear custody/audit trail. The principal blockers to safely handling hundreds or thousands of orders are **operational, not transactional**: no Admin pickup oversight, manual-only pickup assignment without batching, COD payment marked Paid before platform receipt, config-gated return stock restoration, and a 168 h return window identical to the 168 h auto-completion window. None of these require weakening authentication, authorization, custody, or financial protections; all can be addressed through the phased recommendations above without changing established business rules or inventing an escrow system.