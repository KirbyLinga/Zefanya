# Workbook Decisions — Zefanya

**Date:** 24 October 2026  
**Decided by:** User (all four mismatches resolved as Option A)

---

## Decision 1 — Courier/rider self-sign-up stays in the app

**Workbook conflict:** The Rider Account Creation and Courier Account Creation workbooks test Logistics' web "Add Rider/Courier" flow with admin activation. The Flutter app has public self-sign-up instead.

**Decision:** Option A — keep app self-sign-up. Logistics account creation is out of scope for the mobile app.

**Affected workbook assumptions:** A17, A18 (rider/courier account creation and activation belong to Logistics web, not the Flutter app) now **differ** from the app. The app's public sign-up is intentional; these 88 C cases are re-mapped as out of scope.

---

## Decision 2 — Buyer stays on admin approval after OTP

**Workbook conflict:** TC-BUY-023 and TC-BUY-028 assume the buyer becomes Active immediately after OTP verification. The app sends registration to `pendingApproval` (admin approval required), per `auth_state.dart` and the ERP-Components spec.

**Decision:** Option A — keep admin approval. Buyer becomes Active only after admin review.

**Affected workbook assumptions:** A12 (buyer becomes Active after OTP) now **differs** from the app. The app treats new buyers as `pendingApproval` until an administrator approves them. These 2 C cases are re-mapped as out of scope.

---

## Decision 3 — Shipping stays flat (₱60, free from ₱1,000, per seller group)

**Workbook conflict:** BUY-CHK-002 expects per-store/zone shipping; BUY-CHK-003 expects weight-based shipping at the 1 kg boundary.

**Decision:** Option A — keep flat shipping per `lib/config/pricing.dart`:
- `kStandardShippingFee = 60` (₱60 below the threshold)
- `kFreeShippingThreshold = 1000` (free from ₱1,000 subtotal)
- `shippingForItems()` groups by seller; per-seller override via `PricedLine.shippingFee`; otherwise `shippingFeeFor(groupSubtotal)`

No weight-based shipping exists. BUY-CHK-002 (1 C case) is re-mapped as out of scope. BUY-CHK-003 is B-L (missing feature, not a C mismatch) and remains unchanged.

---

## Decision 4 — Cart stays local on the phone

**Workbook conflict:** Some checkout cart cases assume a server-side cart.

**Decision:** Option A — keep the local cart (`CartState` + local storage). `OrderPlacer` already abstracts lines from either door, so the architecture supports both but the phone is the data source.

**Affected workbook assumptions:** BUY-CART-010 and BUY-CART-011 (2 C cases) are re-mapped as out of scope.

---

## Decision 5 — Courier Modules are hub-to-hub, not the app's pickup/delivery role

**Workbook conflict:** The Courier Modules workbook tests a hub-to-hub line-haul role: assigned trips between hubs (HUB-QC to HUB-PSG), load manifests, departure/arrival, hub handover and trip remittance. The Flutter app has no hub-to-hub workflow. Its Courier role is the pickup-and-delivery role already covered by the Rider Modules workbook; the courier and rider screens share the same delivery job flow.

**Decision:** Option A — re-map all 52 Courier Modules cases to the Logistics web plan. Do not build or test hub-to-hub trips against the app's pickup/delivery courier screens. All 52 are N/A in this report. The app-side courier work that does exist (profile, vehicle, documents, availability, pickup, delivery, failures, COD, earnings) is evidenced under Rider Modules.

**Affected workbook assumptions:** The Courier Modules role definition (hub-to-hub line-haul) differs from the app's pickup/delivery Courier role. The 52 cases are recorded as N/A, not as failures, because the tested workflow is out of mobile scope.

**Courier Account Creation:** Decision 1 already makes Logistics-created courier accounts out of scope. All 52 Courier Account Creation cases are N/A for the same reason, including `TC-COU-051`/`TC-COU-052`, which assume a Logistics-created courier account and a courier home screen; the app's session persistence is covered for the implemented roles under `TC-RID-046`/`TC-RID-047` and the buyer session tests.

---

## Summary of workbook assumption changes

| Assumption | Workbook states | App does | Status |
|------------|----------------|----------|--------|
| A12 | Buyer Active after OTP | Buyer `pendingApproval` until admin approval | **Differs** |
| A17 | Rider account created by Logistics web | Public rider/courier self-sign-up in app | **Differs** |
| A18 | Courier account created by Logistics web | Public rider/courier self-sign-up in app | **Differs** |

All other assumptions (A2, A5, A6, etc.) remain consistent with the app.
