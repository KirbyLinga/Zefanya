# ADMIN ORDER MONITORING — PHASE 1 AUDIT (READ-ONLY)

**Project:** `ProjectEcommerce/WebZefanya/` (Laravel backend)
**Subject:** Existing data and tracking for a planned Admin End-to-End Order Monitoring System
**Date:** 2026-11-11
**Branch:** `main` (working tree preserved; no edits made)
**Method:** Read-only inspection of routes, controllers, models, services, migrations, views, tests, scheduled tasks. Evidence read directly from source files; file:line citations given. Nothing was changed, migrated, seeded, or executed against production data.

> **Evidence label:** `VERIFIED` = read in source code (file:line cited). `SUSPECTED` = inferred from code but not observed running. `GAP` = no supporting code found.

## 1. Executive Summary

### What already works (VERIFIED)

- Order creation & splitting: Buyer checkout creates one `orders` row plus one `seller_orders` fulfilment slice per seller, each starting at `PLACED`, with `order_items` and `payments`. `CheckoutService` (`app/Services/Buyer/CheckoutService.php`) writes all in one transaction under row locks.
- Seller fulfilment pipeline: Every `seller_orders.status` change funnels through `SellerOrderStatusService::transition()` (`app/Services/Seller/SellerOrderStatusService.php:94`), which enforces a forward-only state graph + role gate under `lockForUpdate`, writes `status_histories`, rolls up the parent `Order`, settles COD, credits rider pay, finalizes commission, and prepares provider allocation — all atomically.
- Status graph is explicit: 16 statuses (`OrderStatus` enum, `app/Enums/OrderStatus.php`) with actor ownership (`OrderActor` enum, `app/Enums/OrderActor.php`).
- Parcel custody fields exist: `seller_orders.custody_provider_id`, `custody_hub_id`, `carrier_provider_id`, plus `currentCustodyProviderId()` (`SellerOrder.php:136-147`) and `scopeHeldBy()` (`SellerOrder.php:168-190`).
- Verified handoffs & station receipt: `ParcelHandoff` (`app/Models/Logistics/ParcelHandoff.php`) with origin/destination provider + sending/receiving hub. `Manifest` + `ManifestItem` record per-parcel receiving scan with `received_by_type/id` and dispatch verification.
- Rider assignment is auditable: `DeliveryAssignment` (`app/Models/Logistics/DeliveryAssignment.php`) is append-only with `assigned_by_type/id`, `notes`; `CourierAssignmentService` audits manual rider choice/reassignment via `auditManualRider()`.
- Money is tracked per parcel: `Payment`, `CodRemittance`, `LogisticsRemittance`, `SellerSettlement`, `RiderEarning` + `RiderPayout`, `SellerDebt`, `OriginSharePayout`.
- Exceptions & SLA jobs exist: `Incidents`/`IncidentEvents`, `ParcelLossCase`, `ParcelReturn`, `ParcelHandoffException`. Scheduler (`routes/console.php`) runs auto-complete receipts, auto-approve overdue returns, roll missed pickup windows, flag overdue transfers, escalate overdue preparation, daily Sanctum/failed-job pruning.
- Admin audit trail: `OperationsAuditLog` (immutable) and `AccountActionHistory`; read-only `/admin/audit-logs` route (`routes/admin.php:54`).

### What is partial

- Custody is derivable but not presented as a single "who last verified physical custody" answer. Custody can be reconstructed from `custody_provider_id`/`custody_hub_id` + latest `ManifestItem.received_at` + `ParcelHandoff.received_at` + `StatusHistory`, but there is no dedicated "last verified physical custodian" column or service method.
## 2. Data-Source Map

Requirement → Model/Table → Service/Controller → Existing Admin page

| # | Monitoring question | Data source (model/table) | Service / controller | Existing Admin page |
|---|---------------------|---------------------------|----------------------|---------------------|
| 1 | Order/shipment current status | `seller_orders.status` (+ parent `orders.status` rollup) | `SellerOrderStatusService::transition()` (`SellerOrderStatusService.php:94`); `Order::computeRollupStatus()` (`Order.php:55`) | None (dashboard aggregate only) |
| 1 | Status history / who changed status | `status_histories` (`from_status`, `to_status`, `actor_type`, `actor_id`, `source`, `changed_by`, `note`) | `StatusHistory::record()` (`StatusHistory.php:47`); called inside `transition()` | `/admin/audit-logs` (operations only, not status) |
| 2 | Parcel location / last verified physical custodian | `seller_orders.custody_provider_id`, `custody_hub_id`; `ManifestItem.received_at`/`received_by`; `ParcelHandoff.received_at`; `status_histories` | `SellerOrder::currentCustodyProviderId()` (`SellerOrder.php:143`); `scopeHeldBy()` (`SellerOrder.php:168`) | None |
| 2 | Assigned rider | `seller_orders.courier_id` (pickup), `delivery_courier_id` (delivery); `DeliveryAssignment.courier_id` | `CourierAssignmentService::assignPickup`/`assignDelivery` | None |
| 2 | Responsible logistics provider | `seller_orders.carrier_provider_id` (commercial owner) + `custody_provider_id` (physical holder) | `SellerOrder::carrierProvider()`/`custodyProvider()` | None |
| 2 | Planned next custodian | `ParcelHandoff.destination_provider_id` / `receiving_hub_id`; `Manifest.destination_hub_id` | `ParcelHandoffService`, `ManifestService` | None |
| 3 | Who performed each action incl. rider assignments | `DeliveryAssignment.assigned_by_type/id`; `StatusHistory.actor_type/actor_id/source`; `OperationsAuditLog` (`rider_chosen_manually`, `rider_reassigned`) | `CourierAssignmentService::recordAssignment()` + `auditManualRider()` | None |
| 3 | Who requested pickup | `PickupSession.courier_id`/`logistics_provider_id`; `PickupAttempt.rider_id` | `PickupSessionService`; `CourierAssignmentService::assignPickup` | None |
| 3 | Who verified scans / station receipts | `ManifestItem.received_by_type/id`, `verified_for_dispatch_by_type/id`; `Manifest.received_by_type/id`, `dispatched_by_type/id` | `ManifestService`; `ManifestItem::markReceived()` | None |
| 3 | Which provider accepted handoffs | `ParcelHandoff.origin_provider_id`/`destination_provider_id`, status Accepted/Received | `ParcelHandoffService` | None |
| 4 | Buyer payable & COD status | `orders.total_minor`; `Payment.amount_minor`/`status`/`method` (Cod) | `CheckoutService`; `Payment::requiresCollection()` (`Payment.php:66`) | None |
| 4 | Rider COD collection | `Payment.collected_by_type/id`, `collected_amount_minor`, `collected_at` | `Payment::recordCodCollection()` (`Payment.php:82`) | None |
| 4 | Rider→Logistics remittance | `CodRemittance.amount_minor`, `received_amount_minor`, `status` (PENDING_REMITTANCE/REMITTED_TO_LOGISTICS/PARTIALLY_REMITTED), `shortage_minor` | `CodRemittanceService::confirmReceipt()`/`recoverShortage()` | None |
| 4 | Rider earnings | `RiderEarning.amount_minor`, `job` (pickup/delivery), `rider_payout_id` (null=unpaid) | `RiderEarningService::creditFor()` (`RiderEarningService.php:35`) | None |
| 4 | Platform commission | `seller_orders.commission_minor`, `commission_bps`, `commission_finalized_at` | `CommissionFinalizationService::finalize()` (called at Completed in `transition()`) | Dashboard `platformMoney()` (`DashboardController.php:185`) |
| 4 | Seller settlement & payouts | `SellerSettlement.collected_amount_minor`, `shipping_allocation_minor`, `commission_minor`, `seller_payout_minor`, `paid_amount_minor`, `remainingPayoutMinor()`; `SellerPayoutRequest` | `SellerSettlementService::prepare()`/`applyFinalizedFinancials()` | `/admin/settlements` (`routes/admin.php`) |
| 4 | Refunds, seller debts, logistics liabilities | `SellerDebt.amount_minor`/`settled_minor`/`outstandingMinor()`; `ParcelReturn.refund_amount_minor`; `ParcelLossCase.merchandise_minor`/`netCompensationMinor()` | `SellerSettlementService`; `ParcelReturnService`; `SellerOrderStatusService::recordLoss()` | `/admin/lost-parcels`, `/admin/returns` |
| 4 | Unpaid / outstanding balances | `CodRemittance.outstandingShortageMinor()`; `SellerSettlement.remainingPayoutMinor()`; `SellerDebt.outstandingMinor()`; `RiderEarning.isPaid()` | Various model methods | None aggregated |
| 5 | Needs-attention orders/parcels | None dedicated. Derivable: `PickupSession` status, `seller_orders.pickup_missed_windows`, `needs_reassignment`, `delivery_attempts`, `Incidents` open, `ParcelHandoffException` open, `Manifest` open | `RollMissedPickupWindowsCommand`; `PreparationDeadlineService`; `FlagOverdueTransfersCommand` | Dashboard attention queue (registrations/payouts/remittances/returns/incidents/hubs/partnerships only — no orders) |
## 3. Order Lifecycle Flow — Actual Statuses and Transitions

### 3.1 Status enum (VERIFIED)

`app/Enums/OrderStatus.php` — 16 cases in pipeline order: `PLACED → CONFIRMED → PREPARING → READY_FOR_PICKUP → PICKED_UP → AT_SORTING_CENTER → SORTED → IN_TRANSIT → ARRIVED_AT_HUB → ASSIGNED_TO_RIDER → OUT_FOR_DELIVERY → DELIVERED → [COMPLETED | RETURNED]`, plus terminal `CANCELLED` and `LOST`. `DELIVERY_FAILED` branches out of `OUT_FOR_DELIVERY`.

Actor ownership (`app/Enums/OrderActor.php:24-84`):
- Seller: Placed→Confirmed→Preparing→ReadyForPickup; Cancel before handover.
- Rider: ReadyForPickup→PickedUp (pickup); ReadyForPickup→Cancelled (2nd failed pickup); AssignedToRider→OutForDelivery→Delivered/DeliveryFailed.
- Logistics: PickedUp→AtSortingCenter→Sorted→InTransit→ArrivedAtHub→Sorted; DeliveryFailed→AssignedToRider or Returned (recovery).
- Buyer: Delivered→Completed (confirm receipt); Placed→Cancelled.
- System: Delivered→Completed (automatic receipt after wait period).

### 3.2 Forward-only status graph (VERIFIED)

`SellerOrderStatusService::ALLOWED` const (`SellerOrderStatusService.php:104-127`):

```
PLACED       → CONFIRMED, CANCELLED
CONFIRMED    → PREPARING, CANCELLED
PREPARING    → READY_FOR_PICKUP, CANCELLED
READY_FOR_PICKUP → PICKED_UP, CANCELLED
PICKED_UP    → AT_SORTING_CENTER
AT_SORTING_CENTER → SORTED, IN_TRANSIT
SORTED       → ASSIGNED_TO_RIDER, IN_TRANSIT
IN_TRANSIT   → ARRIVED_AT_HUB
ARRIVED_AT_HUB → SORTED, IN_TRANSIT
ASSIGNED_TO_RIDER → OUT_FOR_DELIVERY
OUT_FOR_DELIVERY → DELIVERED, DELIVERY_FAILED
DELIVERED    → COMPLETED, RETURNED
DELIVERY_FAILED → ASSIGNED_TO_RIDER, RETURNED
RETURNED/COMPLETED/CANCELLED/LOST → [] (terminal)
```

### 3.3 End-to-end flow with evidence (VERIFIED)

| Step | Actor | Route → method | Status change | File |
|------|-------|----------------|---------------|------|
| Checkout | Buyer | `CheckoutService` (checkout) | `orders` + `seller_orders` created at `PLACED`; `payment` created | `CheckoutService.php` |
| Hub-to-hub dispatch | Logistics | `recordTransferMovement()` → IN_TRANSIT | verified dispatch scan | `SellerOrderStatusService.php:recordTransferMovement` |
| Hub-to-hub arrival | Logistics | `recordTransferMovement()` → ARRIVED_AT_HUB | verified receiving scan | `SellerOrderStatusService.php:recordTransferMovement` |
| Delivery rider assigned | Logistics | transition to ASSIGNED_TO_RIDER | SORTED→ASSIGNED_TO_RIDER | `CourierAssignmentService::assignDelivery()` |
| Out for delivery | Rider | `DeliveryAssignmentsController@start`/`@deliver` | ASSIGNED_TO_RIDER→OUT_FOR_DELIVERY→DELIVERED | `routes/rider.php` |
| COD collected | Rider | `Payment::recordCodCollection()` | payment Pending→Paid at DELIVERED | `Payment.php:82` |
| Buyer confirms | Buyer | `BuyerShipmentConfirmation` | DELIVERED→COMPLETED | `BuyerShipmentConfirmation.php` |
| Auto-complete receipt | System | `AutoCompleteBuyerReceiptsCommand` (every 5 min) | DELIVERED→COMPLETED after wait | `routes/console.php:20-22` |
| Delivery fails | Rider | transition to DELIVERY_FAILED (max 2 attempts) | OUT_FOR_DELIVERY→DELIVERY_FAILED | `SellerOrderStatusService.php` (`MAX_DELIVERY_ATTEMPTS=2`) |
| Retry delivery | Logistics | DELIVERY_FAILED→ASSIGNED_TO_RIDER | `canRetryDelivery()` | `SellerOrderStatusService.php:83` |
| Return to seller | Logistics/Seller | DELIVERED→RETURNED (verified seller receipt) | `ParcelReturn` completed | `ParcelReturn.php`, `SellerOrderStatusService.php` |
| Cancel (pre-handover) | Seller/Buyer/Rider | →CANCELLED | stock restored, vouchers returned | `SellerOrderStatusService.php` |
| Confirm lost | Admin | `recordLoss()` → LOST (terminal) | `SellerOrderStatusService.php:recordLoss` | `/admin/lost-parcels`, `/admin/incidents` |

### 3.4 "Request Pickup and manual assignment workflow" — implementation status (VERIFIED)

Implemented at the service/controller level:
- `CourierAssignmentService::assignPickup()`, `reservePickup()`, `confirmPickupDispatch()`, `reassign()`, `assignDelivery()`, `manualRiderBlockReason()`, `auditManualRider()` (private).
- `PickupSessionService` (`attach`, `beginPickup`, `closeIfFinished`, `summary`), `PickupPolicy` (`sessionsAvailable()`, `windows()`, `rates()`), `PickupWindowService`.
## 4. Custody Verification Map

Custody can be **reconstructed** from verified records but is **not stored** as a single "last verified physical custodian" value. Physical possession is deliberately not inferred from assignment alone.

| Custody question | Holder concept | Verification method / evidence | Gaps |
|------------------|---------------|-------------------------------|------|
| Seller→Rider verified pickup | Rider (courier) who collected | `seller_orders.status = PICKED_UP` (Rider actor) + `DeliveryAssignment` (role=pickup) + `RiderEarning` (job=pickup) + `PickupAttempt` (failed attempts) | No per-parcel "pickup verified at/scanned by" timestamp column |
| Rider→Station receipt | Logistics hub that scanned receipt | `status = AT_SORTING_CENTER` (Logistics actor) + `ManifestItem.received_at`/`received_by_type/id` | `ManifestItem.received_by_id` may be null when migration column absent (`RuntimeSchema`) |
| Station→Transport dispatch | Sending hub / transfer courier | `ParcelHandoff` (sending_hub_id, status InTransit/Dispatched) + `Manifest.dispatched_at`/`dispatched_by_type/id` + `ManifestItem.verified_for_dispatch_at`/`by` | No single parcel-level "dispatch verified by" column on `seller_orders` |
| Hub→Hub transfers | Receiving hub | `ParcelHandoff.received_at` + receiving hub; `recordTransferMovement()` → ARRIVED_AT_HUB with `StatusHistory` (actor=Logistics, providerId) | Verified |
| Cross-provider handoffs | Destination provider | `ParcelHandoff.origin_provider_id`/`destination_provider_id` + `LogisticsProviderLink` partnership + `carrier_provider_id` (commercial owner) vs `custody_provider_id` (physical holder) | `currentCustodyProviderId()` fallback chain documented (`SellerOrder.php:143-147`) |
| Station→Delivery rider | Delivery rider | `seller_orders.delivery_courier_id` + `DeliveryAssignment` (role=delivery) + status ASSIGNED_TO_RIDER/OUT_FOR_DELIVERY | No separate "delivery rider took custody at station" scan record |
| Final delivery / return custody | Buyer (delivery) or Seller (return) | `status = DELIVERED`/`RETURNED`; `Payment.collected_at` (COD); `ParcelReturn.seller_received_at`/`collected_at`; `DeliveryProof` | Return custody requires `seller_received_at` to finalize RETURNED |

### Distinction required by the audit (VERIFIED)

The code already distinguishes:
## 5. Accountability Audit

Which actions record actor identity, timestamp, and reason (VERIFIED):

| Action | Actor recorded | Timestamp | Reason/notes | Source |
|--------|---------------|-----------|--------------|--------|
| Every `seller_orders.status` change | `StatusHistory.actor_type/actor_id` + `source` | transition time | `from`/`to`/`note`/`changed_by` | `StatusHistory.php:47`, inside `transition()` |
| Hub-to-hub movement | `actor=Logistics`, `actorId=$providerId`, `source` | movement time | `note` | `recordTransferMovement()` (`SellerOrderStatusService.php`) |
| Rider assigned (pickup/delivery) | `DeliveryAssignment.assigned_by_type/id` | assignment time | `notes`; `recordAssignment()` | `DeliveryAssignment.php:17-24`, `CourierAssignmentService.php` |
| Rider manually chosen / reassigned | `OperationsAuditLog` (`rider_chosen_manually`, `rider_reassigned`) | action time | old/new values + `reason`; previous courier passed to `auditManualRider()` | `CourierAssignmentService.php:auditManualRider`, `OperationsAuditLog.php:ACTIONS` |
| COD cash collected | `Payment.collected_by_type/id`, `collected_amount_minor`, `collected_at` | collection time | amount must equal `amount_minor`; idempotent | `Payment.php:82-104` |
| Provider confirms cash received | `CodRemittance.received_amount_minor`, `confirmed_at`; shortage → `shortage_rider_id`, `shortage_reason` | confirmation time | shortage never marked received | `CodRemittanceService.php:30-72` |
| Remittance confirm/reject | `LogisticsRemittance.decided_by_admin_id`, `confirmed_at`/`rejected_at` | decision time | `left_out_count` for partial | `LogisticsRemittance.php`, `LogisticsRemittanceService.php` |
| Seller payout decided | `SellerPayoutRequest.decided_by_admin_id`, `paid_at`/`rejected_at` | decision time | `debt_deducted_minor` | `SellerPayoutRequest.php` |
| Rider payout paid/reversed | `OperationsAuditLog` (`rider_payout_paid`, `rider_payout_reversed`) | payout time | reference, amount, earning IDs | `RiderEarningService.php` |
| Lost parcel confirmed | `OperationsAuditLog` (`parcel_lost_confirmed`); `ParcelLossCase.confirmed_at`, liable `logistics_provider_id` | confirmation time | `reason` | `SellerOrderStatusService::recordLoss()`, `ParcelLossCase.php` |
| Manifest dispatch/receive | `Manifest.dispatched_by_type/id`, `received_by_type/id` | dispatch/receive time | per-item `received_by`/`verified_for_dispatch_by` | `Manifest.php`, `ManifestItem.php` |
| Admin settings changed | `PlatformSettingHistory` (immutable) | change time | linked `admin` | `PlatformSettingHistory.php` |
| Registration decisions | `AccountActionHistory` (+ audit fields on role tables) | decision time | `approved_by`, `approved_at`, `rejection_reason` (forceFill) | Decision services |

### Missing actor information (GAP) — no duplicate audit table proposed
## 6. Financial Source Map — exact source of each amount and status

Amounts are integer **centavos** (`1-Context.md:6`). Authoritative sources:

| Amount / status | Authoritative source (persisted) | Calculated / derived | Notes |
|-----------------|----------------------------------|----------------------|-------|
| Buyer payable | `orders.total_minor` | — | Set at checkout |
| Seller slice subtotal | `seller_orders.subtotal_minor` | — | Before order-level voucher |
| Shipping fee | `seller_orders.shipping_fee_minor` | — | Quoted at checkout |
| COD amount due | `Payment.amount_minor`, `status` (pending/paid), `method=cod` | `requiresCollection()` = cod && pending | `Payment.php` |
| COD collected by rider | `Payment.collected_amount_minor`, `collected_by_type/id`, `collected_at` | — | Idempotent, amount-checked |
| Rider→provider cash received | `CodRemittance.amount_minor`, `received_amount_minor`, `status` | `outstandingShortageMinor()` = shortage − recovered | `CodRemittance.php:116` |
| Shortage & recovery | `CodRemittance.shortage_minor`, `shortage_recovered_minor`, `shortage_rider_id` | — | `CodRemittanceService::confirmReceipt()`/`recoverShortage()` |
| Origin shipping share (cross-provider 40%) | `CodRemittance.origin_share_minor` (snapshot) | `currentOriginShareMinor()` = 40% of shipping fee if cross-provider | `CodRemittance::ORIGIN_SHARE_PERCENT=40`; `snapshotAllocation()` freezes once |
| Destination kept shipping (60%) | `CodRemittance.kept_shipping_minor` | `shipping_fee_minor − origin_share_minor` | `CodRemittance::KEPT_SHIPPING_SQL` |
| Provider→platform remittance | `LogisticsRemittance.cod_total_minor`, `shipping_total_minor`, `amount_minor`, `received_amount_minor`, `short_minor`, `status` | `owedMinor` computed in dashboard | SUBMITTED/CONFIRMED/REJECTED; `decided_by_admin_id` |
| Platform earnings | `seller_orders.commission_minor`/`commission_bps` | `PlatformFinanceService::earnings()`/`position()` | Finalized at Completed |
| Seller net payout | `SellerSettlement.seller_payout_minor`, `paid_amount_minor`, `remainingPayoutMinor()` | `collected − commission − shipping allocation` | PENDING_PAYOUT/PAID/RELEASED |
| Seller payout requests | `SellerPayoutRequest.amount_minor`, `debt_deducted_minor` | — | PENDING/CANCELLED/PAID/REJECTED; `decided_by_admin_id` |
| Seller debt | `SellerDebt.amount_minor`, `settled_minor`, `outstandingMinor()` | — | Return after payout, return charges, failed delivery |
| Rider earnings | `RiderEarning.amount_minor`, `job` (pickup ₱30 / delivery ₱50), `rider_payout_id` (null = unpaid) | Rates from `PickupPolicy::rates()` snapshotted per earning | `RiderEarning::RATES`, `RiderEarningService::creditFor()` |
| Rider payout | `RiderPayout.amount`, `reference`, `paid_on` | earnings linked via `rider_payout_id` | `RiderEarningService::pay()` |
| Origin share payout (cross-provider) | `OriginSharePayout` | 40% share owed to origin carrier | `LogisticsRemittanceService::payOriginShare()` |
| Lost-parcel compensation | `ParcelLossCase.merchandise_minor` (= `seller_orders.subtotal_minor` at checkout), `adjustments`, `netCompensationMinor()` | — | Paid off-platform once; `ParcelLossAdjustment` corrections |
| Return fault & refund | `ParcelReturn.fault` (seller/logistics), `refund_amount_minor` | — | `ParcelReturn::FAULT_SELLER`/`FAULT_LOGISTICS` |

### Persisted vs calculated — reconciliation feasibility (VERIFIED)

Financial reconciliation **is possible with existing records** but requires joining across tables; there is no single "money location" row per order. Key reconciliation chains exist:
## 7. SLA / Exception Inventory

### Existing configurable deadlines (VERIFIED)

| Deadline | Value / source | Enforced by |
|----------|---------------|-------------|
| Buyer auto-receipt wait | `AutomaticBuyerReceiptService::WAIT_HOURS` (7 days) | `AutoCompleteBuyerReceiptsCommand` every 5 min (`routes/console.php:20`) |
| Return seller decision | `ParcelReturn::SELLER_DECISION_DAYS = 3` | `AutoApproveOverdueReturnsCommand` every 5 min (`routes/console.php:24`) |
| Max return collection attempts | `ParcelReturn::MAX_COLLECTION_ATTEMPTS = 2` | `ParcelReturnService` |
| Missed pickup window roll | — | `RollMissedPickupWindowsCommand` every 5 min (`routes/console.php:28`) |
| Overdue transfer flags | `TRANSFER_OVERDUE_AFTER_HOURS` (road default 24h), SEA 72h, AIR 48h; escalate `TRANSFER_ESCALATE_AFTER_HOURS` | `FlagOverdueTransfersCommand` every 15 min (`routes/console.php:32-34`) |
| Preparation overdue | `seller_orders.preparation_due_at`; notify once via `preparation_overdue_notified_at` | `EscalateOverduePreparationCommand` every 15 min + `PreparationDeadlineService::escalateOverdue()` |
| Pickup windows | `PickupPolicy::windows()` (morning/afternoon, from `PlatformSetting` or config defaults) | `PickupWindowService` |
| Rider pay rates | `PickupPolicy::rates()` (first/additional/delivery) from `PlatformSetting` | `RiderEarningService` |
| Sanctum token prune | 24h daily (`routes/console.php:40`) | — |
| Failed-job prune | 168h daily (`routes/console.php:44`) | — |

### Existing alerts / notifications (VERIFIED)

- Seller notified of preparation overdue (`PreparationOverdue`), once only.
- Buyer notified on CourierAssigned, OutForDelivery, OrderDelivered, DeliveryFailed, SellerOrderCancelled.
- Seller notified on DeliveryFailed, ParcelReturned, BuyerOrderCancelled.
- Rider notified on PickupAssigned, DeliveryAssigned, AssignmentReassigned, RiderPayoutRecorded.
- `ScheduledTaskAlert` wraps each scheduled command: records cache heartbeat, emails admins on failure (`routes/console.php:17-18`).
- No rider offline/unavailable alert to Admin. (`RiderAvailabilityService` exists; no scheduled check.)
- No parcel-stalled-at-station or unfinished-transport-leg SLA alert beyond transfer overdue flags.

### Existing Needs Attention queues (VERIFIED)

`DashboardController::attention()` (`app/Http/Controllers/Admin/DashboardController.php:78-130`):
1. Registrations to review
2. Seller payout requests (admin only)
3. Logistics payments to confirm (`LogisticsRemittance::SUBMITTED`)
4. Escalated return disputes (`ParcelReturn::InDispute`)
5. Refunds to record (`financial_review_required && !refunded_at`)
6. Return exceptions (`exception_reason` not null)
7. Incident reports (open lost/damaged)
8. Hub approvals (pending hubs/location changes)
9. Partnerships and handoffs (pending links + open `ParcelHandoffException`)

## 8. Gap Matrix

Supported = end-to-end implemented and Admin-visible. Partial = implemented in backend/service layer but not exposed to Admin or not aggregated. Missing = no code. Priority: P0 = blocks the five Admin monitoring questions; P1 = important for accountability/SLA; P2 = enhancement.

| # | Gap | Supported / Partial / Missing | Priority | Key files |
|---|-----|------------------------------|----------|-----------|
| G1 | Admin order/shipment monitoring page (list + detail) | **Missing** | P0 | `routes/admin.php` (no orders route) |
| G2 | "Current status + location" per parcel for Admin | **Partial** (status + custody fields exist, not aggregated) | P0 | `SellerOrder.php`, `ParcelHandoff.php`, `ManifestItem.php` |
| G3 | "Last verified physical custodian" answer | **Missing** (derivable, not stored/exposed) | P0 | `ManifestItem.received_by`, `StatusHistory`, `ParcelHandoff` |
| G4 | Who performed each action incl. rider assignments (Admin view) | **Partial** (rows exist, not listed for Admin) | P0 | `DeliveryAssignment.php`, `StatusHistory.php`, `OperationsAuditLog.php` |
| G5 | "Where is the money / what remains unpaid" per order | **Partial** (per-table amounts exist, no per-order aggregate) | P0 | `Payment`, `CodRemittance`, `LogisticsRemittance`, `SellerSettlement` |
| G6 | Needs-attention queue for orders/parcels (unassigned pickups, stalled stations, missed windows, failed deliveries) | **Missing** | P0 | `DashboardController::attention()`, `routes/console.php` |
| G7 | Admin manual rider assignment/reassignment from the monitoring page | **Partial** (service exists, no Admin route/UI) | P1 | `CourierAssignmentService::assignPickup/reassign`, `routes/admin.php` |
| G8 | Rider offline/unavailable SLA alert | **Missing** | P1 | `RiderAvailabilityService`, no scheduled alert |
| G9 | Parcel stalled-at-station / unfinished transport leg SLA | **Partial** (transfer overdue only for IN_TRANSIT) | P1 | `FlagOverdueTransfersCommand`, `StuckTransferService` |
| G10 | Delivery failure / return needs-attention surfacing to Admin | **Partial** (returns dashboard queue exists; failed deliveries not) | P1 | `ParcelReturn`, `DashboardController::attention()` |
## 9. Phase 2 Implementation Recommendations

Reuse existing services and behavior; do not alter established logic. Minimal changes:

### 9.1 Files to create (new Admin monitoring surface)

1. **`routes/admin.php`** — add read-only routes:
   - `GET /orders` → new `Admin\OrderMonitoringController@index` (list parcels with status, custody provider, assigned rider, money state, attention flags)
   - `GET /orders/{sellerOrder}` → `@show` (detail: status timeline, custody chain, money chain, actor audit)
   - Keep read-only: no status/custody/money writes from Admin.
2. **`app/Http/Controllers/Admin/OrderMonitoringController.php`** — thin controller delegating to read-only queries/services.
3. **`app/Services/Admin/OrderMonitoringService.php`** (read-only) — assembles per-parcel view:
   - current status (`SellerOrder::status` + parent rollup `Order::computeRollupStatus()`)
   - custody resolution (`SellerOrder::currentCustodyProviderId()`, `scopeHeldBy()`)
   - last verified custodian (latest of `ManifestItem.received_at`, `ParcelHandoff.received_at`, `StatusHistory`)
   - assigned rider (`courier_id`/`delivery_courier_id` + latest `DeliveryAssignment`)
   - money state (`Payment`, `CodRemittance`, `SellerSettlement`, `RiderEarning.isPaid()`)
   - attention flags (missed windows, unassigned, stalled, failed)
4. **`resources/views/Admin/OrderMonitoring/index.blade.php`** and `show.blade.php` — Tailwind v4, existing `@theme` tokens (`app.css`); no new plain-CSS files.

### 9.2 Reusable services (do not duplicate)

- Custody: `SellerOrder::currentCustodyProviderId()` (`SellerOrder.php:143`), `scopeHeldBy()` (`SellerOrder.php:168`), `pickupStateLabel()` (`SellerOrder.php:751`).
- Status timeline: `sellerOrders.statusHistories` (`StatusHistory.php`).
- Rider assignment trail: `DeliveryAssignment` ordered by id (`DeliveryAssignment.php`).
- Money: `CodRemittance::outstandingShortageMinor()`, `keptShippingMinor()`, `originShareMinor()`; `SellerSettlement::remainingPayoutMinor()`; `RiderEarning::isPaid()`; `Payment::requiresCollection()`.
- Attention data: `RollMissedPickupWindowsCommand`, `PreparationDeadlineService`, `FlagOverdueTransfersCommand` logic reused as read-only queries.
- Finance display: `PlatformFinanceService::position()`, `FinanceReportService`, `DashboardController::logisticsSettlements()`.
## 10. Testing Recommendations

### Acceptance cases (new, read-only against the Phase 2 UI)

1. Admin can open `/admin/orders` and see every parcel with current status, custody provider, assigned rider, and money state.
2. Admin opening `/admin/orders/{id}` sees the full status timeline (`status_histories` with actor/timestamp/source) and custody chain (pickup → station → hubs → delivery rider → buyer/seller receipt).
3. For a COD parcel, Admin sees buyer payable, amount collected by rider (`collected_by_type/id/at`), provider remittance status (`CodRemittance.status`), and any outstanding shortage.
4. Admin can filter/sort by attention flags: unassigned (`courier_id` null at READY_FOR_PICKUP), missed pickup windows (`pickup_missed_windows > 0`), stalled at hub (`AT_SORTING_CENTER`/`SORTED`/`ARRIVED_AT_HUB` aged), delivery failed, open incident/loss.
5. No Admin page allows changing status, custody, or money (read-only enforcement; existing policies unchanged).

### Regression cases (existing behavior must not break)

1. `CheckoutService` still creates `seller_orders` at PLACED with payment and inventory under locks (51 tests in `CheckoutCarrierSelectionTest` + `CheckoutShippingTest` passed previously).
2. `SellerOrderStatusService::transition()` still enforces the graph + role gate and writes `status_histories` (compare-and-swap under lock; idempotent replay).
3. `CourierAssignmentService` rider assignment/resassignment still writes `DeliveryAssignment` + `OperationsAuditLog` (`rider_chosen_manually`/`rider_reassigned`).
4. `Payment::recordCodCollection()` remains idempotent and amount-checked.
5. `CodRemittanceService::confirmReceipt()` / `recoverShortage()` still track shortage and never mark partial as fully received.
6. `SellerSettlementService::prepare()` still requires remitted-to-logistics + paid COD + matching amounts.
7. Scheduler still runs all six commands without overlapping; `ScheduledTaskAlert` heartbeats intact.
8. Existing Admin pages (`/admin/audit-logs`, `/admin/logistics-remittances`, `/admin/lost-parcels`, `/admin/incidents`, `/admin/returns`) unchanged.
9. Full PHPUnit suite baseline: previously 3,578 tests, 3,566 passed, 11 failed, 1 skipped (investigate the 11 before/after Phase 2 — do not conceal regressions; several are stale free-shipping threshold assumptions, see `AUDIT_REPORT.md:141-143`).

---

## Conclusion

- **Admin can already monitor:** only aggregates — registrations, payout requests, logistics remittances, open return disputes/incidents, hub approvals, partnership/handoff exceptions, and two order counters (`ordersToday`, `ordersInProgress`). Money is visible at provider level (COD owed) in the dashboard.
- **Data exists but is not shown to Admin:** per-parcel status history, custody provider/hub, assigned rider, delivery/pickup assignments, COD collection, remittances, settlements, rider earnings, incidents, loss cases, returns — all stored and auditable, but no Admin page or aggregate exposes them per order.
- **Genuinely missing:** Admin order/shipment monitoring page and detail; per-order "where is the money/unpaid" aggregate; "last verified physical custodian" as a single derived answer; needs-attention queue for orders/parcels; Admin manual rider assignment UI; rider offline/SLA alerts.
- **Current parcel custody verification:** can be **derived** from verified records (`custody_provider_id`/`custody_hub_id`, `ManifestItem.received_by`, `ParcelHandoff.received_at`, `StatusHistory`) but is **not** stored as one authoritative "last verified physical custodian" value. Physical possession is not inferred from assignment alone (code already respects this distinction).
- **Rider assignments fully auditable:** yes, per-action — `DeliveryAssignment` rows with `assigned_by_type/id/notes` plus `OperationsAuditLog` for `rider_chosen_manually`/`rider_reassigned`, including previous rider. Not yet listed to Admin.
- **Financial reconciliation possible:** yes — chains exist (`Payment`→`CodRemittance`→`LogisticsRemittance`→`SellerSettlement`; `RiderEarning`→`RiderPayout`), amounts persisted per table, but not aggregated per order for Admin.
- **Top 5 implementation gaps:** (1) no Admin order/parcel monitoring page; (2) no per-parcel custody/money aggregate; (3) no needs-attention queue for orders/parcels; (4) no "last verified physical custodian" derived answer; (5) no rider offline/stall SLA alerting.
- **Recommended Phase 2 scope:** read-only Admin order/parcel list + detail reusing `SellerOrderStatusService`, `CourierAssignmentService`/`DeliveryAssignment`, `CodRemittance`/`SellerSettlement`/`RiderEarning`, `ManifestItem`/`ParcelHandoff`, and `StatusHistory`; no writes, no financial/custody logic changes, no SLA rule changes.

Phase 1 audit complete. No application code was changed.


### 9.3 Minimal behavioral changes (none to money/custody logic)

- None required to existing money/custody/status services. Phase 2 adds **read-only** Admin views only. If Admin manual rider assignment is later added, route through existing `CourierAssignmentService::reassign()` + `auditManualRider()` (do not reimplement).
- Any new scheduled attention query should reuse the scheduler heartbeat pattern (`ScheduledTaskAlert`, `routes/console.php`) without changing existing job schedules.

### 9.4 Recommended Phase 2 scope

P0 first: read-only Admin order/parcel list + detail showing current status, location/custody, assigned rider, last verified custodian, money state, and attention flags. No new writes, no new financial/custody logic, no SLA rule changes.

| G11 | Previous rider on reassignment as dedicated column | **Missing** (derivable from `DeliveryAssignment` history) | P2 | `DeliveryAssignment.php` |
| G12 | Dedicated `delivery_events` shipment log (referenced in `OrderStatus` doc comment) | **Missing** (no migration/model found) | P2 | `app/Enums/OrderStatus.php:7` |

**Missing from the queue:** parcels awaiting rider assignment, missed pickup windows, stalled parcels at hubs, unfinished transport legs, failed deliveries awaiting retry/return, offline riders. **GAP.**

### Scheduler coverage gaps (GAP)

- No scheduled task flags parcels stuck in `READY_FOR_PICKUP` beyond N windows / unassigned riders.
- No scheduled task flags parcels stalled at `AT_SORTING_CENTER`/`SORTED`/`ARRIVED_AT_HUB` beyond N hours (only transfer overdue for `IN_TRANSIT`).
- No scheduled task flags riders offline/unavailable with active parcels.
- No scheduled task surfaces `DELIVERY_FAILED` parcels past retry window to Admin.
- Jobs run sync unless `QUEUE_CONNECTION=database` + worker; no queue monitoring in scheduler beyond pruning.

- COD: `Payment` → `CodRemittance` → `LogisticsRemittance` → `SellerSettlement` (locked in `SellerSettlementService::lockEligibleRemittance()`, `SellerSettlementService.php:628`).
- Cross-provider split: `CodRemittance.origin_share_minor` / `kept_shipping_minor` snapshotted, with SQL fallback.
- Rider pay: `RiderEarning` → `RiderPayout` (paid/unpaid via `isPaid()`).

GAP: No Admin-visible per-order "where is the money / what remains unpaid" aggregate. (Admin sees provider-level COD owed only in the dashboard, `DashboardController::logisticsSettlements()`.)


- Parcel-level "last verified physical custodian" actor is not stored in one place; must be assembled from `ManifestItem.received_by`, `ParcelHandoff` receiving hub, `StatusHistory` (see Section 4).
- Previous rider on reassignment: captured in `CourierAssignmentService::auditManualRider($previous, ...)` and `OperationsAuditLog` old/new values, but **not** as a dedicated `previous_courier_id` column. Acceptable — derive from `DeliveryAssignment` history ordered by id.
- Who requested the original pickup is not separately recorded beyond the `READY_FOR_PICKUP` status-change actor (Seller) and the `PickupSession`/`DeliveryAssignment` that follows. Typed actor columns on `status_histories` only work when the migration ran (`StatusHistory.php:65-69`); otherwise `changed_by` is free text.

- **Assigned rider:** `seller_orders.courier_id` / `delivery_courier_id` + `DeliveryAssignment.courier_id`.
- **Responsible logistics provider:** `carrier_provider_id` (commercial/buyer-selected carrier) vs `custody_provider_id` (physical holder). `currentCustodyProviderId()` resolves custody→carrier→courier fallback.
- **Last verified physical custodian:** **not a single column.** Must be derived as the latest of `ManifestItem.received_at`, `ParcelHandoff.received_at`, `StatusHistory` for PICKED_UP/AT_SORTING_CENTER/ARRIVED_AT_HUB/DELIVERED. **GAP.**
- **Planned next custodian:** `ParcelHandoff.destination_provider_id` / `receiving_hub_id`, or the assigned delivery courier. Not exposed as a single field. **GAP.**

- Routes: `routes/rider.php` (`pickups.index`, `pickups.confirm`, `pickups.stops.finish`, `pickups.reportFailure`); `routes/logistics.php` (handoffs, scans).
- **NOT implemented:** any Admin-facing page or route to view pending pickup requests, unassigned parcels, or to manually assign/reassign a rider from Admin. `routes/admin.php` has no pickup/assignment routes.

| Seller accepts | Seller | `OrderController@show` → `advanceFulfillment(...Confirmed)` | PLACED→CONFIRMED | `routes/seller.php`, `OrderController.php` |
| Packing starts | Seller | `advanceFulfillment(...Preparing)` | CONFIRMED→PREPARING | `OrderController.php` |
| Mark ready | Seller | `OrderController@markReady` | PREPARING→READY_FOR_PICKUP | `routes/seller.php` (`orders.markReady`) |
| Pickup window scheduled | System | `PickupWindowService::schedule()` (in `transition()` at ReadyForPickup) | sets `pickup_window_*_at` | `SellerOrderStatusService.php` + `PickupWindowService.php:44` |
| Rider assigned (pickup) | Logistics | `CourierAssignmentService::assignPickup()` / `reservePickup()` / `confirmPickupDispatch()` | assigns `courier_id` + `DeliveryAssignment` row | `CourierAssignmentService.php` |
| Rider collects parcel | Rider | `PickupAssignmentsController@confirm` | READY_FOR_PICKUP→PICKED_UP (tracking number generated) | `routes/rider.php` (`pickups.confirm`) |
| Station receipt | Logistics | transition to AT_SORTING_CENTER | PICKED_UP→AT_SORTING_CENTER | `SellerOrderStatusService.php` |
| Sort | Logistics | transition to SORTED | AT_SORTING_CENTER→SORTED | `SellerOrderStatusService.php` |


| 4 | Logistics→Platform remittance | `LogisticsRemittance.cod_total_minor`, `shipping_total_minor`, `amount_minor`, `received_amount_minor`, `status` (SUBMITTED/CONFIRMED/REJECTED), `decided_by_admin_id` | `LogisticsRemittanceService::submit()`/`confirm()`/`reject()` | `/admin/logistics-remittances` (`routes/admin.php:56-60`) |
| 4 | Shipping allocation between providers | `CodRemittance.origin_share_minor` (40% origin) / `kept_shipping_minor` (60% destination), `snapshotAllocation()` | `CodRemittance` model methods; `LogisticsRemittanceService::payOriginShare()`/`payDestinationShare()` | `/admin/logistics-remittances` (allocation policy, pay-origin/pay-destination) |

- Rider assignments are auditable per-action, but there is no unified "who assigned/reassigned each rider and when" listing for Admin.
- Financial reconciliation is largely possible but scattered; no single "where is the money / what remains unpaid" aggregate per order exists for Admin.

### What is missing (GAP)

- No Admin order/shipment monitoring page exists. `routes/admin.php` has dashboard, settings, audit-logs, logistics-remittances, settlements, lost-parcels, incidents, users, products, partnerships, hubs, returns — but **no orders/shipments index or detail route**. Admin cannot currently answer any of the five monitoring questions for a specific order/parcel.
- No "Needs Attention" queue for orders/parcels. Existing attention queues are registration, payouts, remittances, returns/disputes, incidents, hubs, partnerships (`DashboardController::attention()`).
- No per-order financial summary for Admin. No rider offline/availability SLA alerting for Admin.
- "Request Pickup" and manual rider assignment are implemented at the service level but have **no Admin-facing view** of pending pickup requests or unassigned parcels.
