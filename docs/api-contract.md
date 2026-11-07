# Native API — S6.4 incremental contract

This documents the cancellation change in the newer checkout only. Other endpoints are not audited by this page.

`POST /api/buyer/orders/{order}/shipments/{shipment}/cancel`

- Existing Sanctum bearer authentication, buyer role, ownership policy and 20/minute write throttle apply. Missing token: 401; wrong role: 403; another buyer/wrong parent: 404.
- Body: `{"reason":"Changed my mind"}`. Allowed reasons: Changed my mind; Ordered the wrong item; Need to update the address; Found a better price; Other. Arbitrary/empty reasons: 422. The server chooses the owner and money values.
- Allowed while PLACED, CONFIRMED or PREPARING. READY_FOR_PICKUP and every later/terminal status are refused with 409. A repeated cancellation is 409 and has no repeated stock, voucher or refund effect.
- Success uses the existing success/data envelope and ShipmentResource. Shipment/order detail `can_cancel` is authoritative and false after cancellation. Clients must not infer eligibility from the broad To Ship display group.
- Stock is restored through the existing variant inventory ledger. Shop vouchers return independently; shared platform/shipping usage is released only when no active sibling still uses it. Usage decreases once; a new wallet claim is restored only while unexpired.
- A paid payment creates one pending `payment_cancellation_refunds` liability for the exact stored `amount_minor`; client amounts/owner IDs are ignored. The paid payment record is preserved. All cancellation effects roll back if the liability cannot be recorded. Unpaid COD creates no liability.

The additive refund migration must be deployed before paid cancellation. Without it, paid cancellation returns validation failure and rolls back. No development migration was run. Checkout still creates COD payments; recognizing prepaid records does not enable an e-wallet/card processor. A pending refund is money owed, not proof of money sent. No payout endpoint is introduced here.
