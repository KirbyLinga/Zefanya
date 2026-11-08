# Native API — S6.4 incremental contract

## Courier duty extension

GET /api/courier/duty returns only the authenticated Courier's setup_required/on_duty/available/status, checklist time, last update time and last-check outcome, with no-store/private caching. POST /api/courier/duty/{on|off|heartbeat} shares Sanctum authentication, linehaul role + active-account policy and 60/minute throttle, and uses LinehaulDutyRequest/Resource. No driver identity or computed readiness is accepted from the client.

On: latitude [-90,90], longitude [-180,180], accuracy [0,100] metres, and checklist with exactly tires/lights/brakes/fuel/cargo_lock, each a required boolean. A Not OK answer requires a nonblank note (<=500 chars); valid failed checks return 200 with Off Duty and send a database Logistics notice. Off: no body; rejected while any Draft/Dispatched manifest is assigned. Heartbeat: the same location fields, only while already On Duty. The server stamps update time. Availability expires at five minutes and gates new assignment, acceptance and departure. Coordinates are client-reported, not proof of hub proximity or continuous tracking. Missing local schema reports Setup pending; write actions refuse without data changes.

## Courier handover note extension

`POST /api/courier/trips/{manifest}/exception-note` uses existing Sanctum authentication, linehaul role middleware, 60/minute throttle, LinehaulRequest and assignment ownership policy. Body: `tracking_number` (required, <=100 chars) and `note` (required, nonblank, <=500 chars). Only the assigned Courier can annotate an actual missing/damaged incident bound to this manifest item, during Dispatched or Received with Issues. Response uses the existing trip resource. Identical resubmission is idempotent; overwrite is rejected (422). This action cannot receive parcels or change custody/status.

Trip items now include nullable `issue` (`missing`/`damaged`) and `courier_note`. Received and missing issue items remain in history; removed/unreceived non-issue items stay excluded. No incident photo path is exposed.

This documents the cancellation change in the newer checkout only. Other endpoints are not audited by this page.

`POST /api/buyer/orders/{order}/shipments/{shipment}/cancel`

- Existing Sanctum bearer authentication, buyer role, ownership policy and 20/minute write throttle apply. Missing token: 401; wrong role: 403; another buyer/wrong parent: 404.
- Body: `{"reason":"Changed my mind"}`. Allowed reasons: Changed my mind; Ordered the wrong item; Need to update the address; Found a better price; Other. Arbitrary/empty reasons: 422. The server chooses the owner and money values.
- Allowed while PLACED, CONFIRMED or PREPARING. READY_FOR_PICKUP and every later/terminal status are refused with 409. A repeated cancellation is 409 and has no repeated stock, voucher or refund effect.
- Success uses the existing success/data envelope and ShipmentResource. Shipment/order detail `can_cancel` is authoritative and false after cancellation. Clients must not infer eligibility from the broad To Ship display group.
- Stock is restored through the existing variant inventory ledger. Shop vouchers return independently; shared platform/shipping usage is released only when no active sibling still uses it. Usage decreases once; a new wallet claim is restored only while unexpired.
- A paid payment creates one pending `payment_cancellation_refunds` liability for the exact stored `amount_minor`; client amounts/owner IDs are ignored. The paid payment record is preserved. All cancellation effects roll back if the liability cannot be recorded. Unpaid COD creates no liability.

The additive refund migration must be deployed before paid cancellation. Without it, paid cancellation returns validation failure and rolls back. No development migration was run. Checkout still creates COD payments; recognizing prepaid records does not enable an e-wallet/card processor. A pending refund is money owed, not proof of money sent. No payout endpoint is introduced here.
