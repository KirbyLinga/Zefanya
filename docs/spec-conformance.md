# Spec conformance — High-priority cases (S1-lite 1.2, lite scope)

Branch: `feature/s1-lite` (cut from `integration/hardened` @ 6a9605a)
Date: 2026-11-05
Source: `docs/qa/csv/*.csv` (39 sheets exported from the spec workbook at S0.7; `Summary.csv` counts 290 active cases across B1–B14 and R1–R7, all "Not Run"; AC Courier and C1–C7 sheets are parked and excluded).

## Scope (per SCHEDULE OVERRIDE)

This document covers **only** the High-priority cases of B1–B14 and R1–R7: **101 cases**. The AC sheets (AC Buyer, AC Rider) and all Medium/Low cases are skipped, as are the AC conformance sheets themselves.

Cases were **not run** (per the S1 method: "Do not run the cases"). STATUS reflects code inspection only — route existence (`php artisan route:list --json` = 173 routes), controller/test presence (`web/tests/Feature`, 405 `test_*` methods), mobile screens and repositories (`mobile/lib`), and documented limitations (`docs/KNOWN_LIMITATIONS.md`, `docs/loophole-report.md`, `docs/api-contract.md`). Evidence marked UNVERIFIED where the code does not settle it.

STATUS values: IMPLEMENTED-TESTED | IMPLEMENTED-UNTESTED | PARTIAL | MISSING | WEB-ONLY | LIMITATION

## Conformance table — High-priority cases

| ID | Sheet | Priority | Module | What it needs | STATUS | Evidence |
|----|-------|----------|--------|---------------|--------|----------|
| BUY-ACCT-003 | B1 Account | High | Account & Security | Change password with the correct old password; logged in as Buyer 1 | IMPLEMENTED-TESTED | `POST /api/account/password`; `AccountSecurityApiTest` |
| BUY-ACCT-004 | B1 Account | High | Account & Security | Change password with a wrong old password | IMPLEMENTED-TESTED | `POST /api/account/password`; `AccountSecurityApiTest` |
| BUY-ACCT-005 | B1 Account | High | Account & Security | New password breaks the rule (8+, upper/lower/number/special, confirm match) | IMPLEMENTED-TESTED | password validation rules on change-password; `AccountSecurityApiTest` |
| BUY-ACCT-006 | B1 Account | High | Account & Security | Change email with verification; old email still works before OTP | IMPLEMENTED-TESTED | `POST /api/account/email`, `POST /api/account/email/verify` |
| BUY-ACCT-011 | B1 Account | High | Account & Security | [Mobile] Password change ends the session on the other phone | PARTIAL | logout revokes token implemented; password-change revokes other tokens OPEN (`docs/loophole-report.md` item 9) |
| BUY-ACCT-012 | B1 Account | High | Account & Security | [Mobile] Stay logged in, then log out and press Back | PARTIAL | token in `flutter_secure_storage` across restarts; mobile global 401 handler UNVERIFIED |
| BUY-ADDR-001 | B2 Address Book | High | Address Book | Add an address with valid data | IMPLEMENTED-TESTED | `POST /api/buyer/addresses`; `BuyerAddressApiTest` |
| BUY-ADDR-002 | B2 Address Book | High | Address Book | Region → city → barangay cascade | IMPLEMENTED-TESTED | `GET /api/psgc/regions\|/cities\|/barangays`; `PsgcApiTest` |
| BUY-ADDR-003 | B2 Address Book | High | Address Book | Each required field left blank | PARTIAL | validation exists; exact field messages UNVERIFIED |
| BUY-ADDR-007 | B2 Address Book | High | Address Book | Delete the default address | IMPLEMENTED-UNTESTED | `DELETE /api/buyer/addresses/{address}` |
| BUY-ADDR-008 | B2 Address Book | High | Address Book | Edit or delete an address used by an active order | PARTIAL | business rule not verified; immutable address snapshot UNVERIFIED |
| BUY-ADDR-009 | B2 Address Book | High | Address Book | Cannot open another buyer's address | IMPLEMENTED-TESTED | controller scopes to `auth()->user()` (DECISIONS H6); `BuyerAddressApiTest` |
| BUY-SRCH-003 | B3 Browse & Search | High | Browse, Search & Filter | Search: exact and partial match | PARTIAL | `GET /api/products`; client-side filtering confirmed, server-side search UNVERIFIED |
| BUY-SRCH-005 | B3 Browse & Search | High | Browse, Search & Filter | Search: special characters and injection | PARTIAL | user text escaped in web views (spec §11); mobile behaviour UNVERIFIED |
| BUY-SRCH-007 | B3 Browse & Search | High | Browse, Search & Filter | Price range filter | PARTIAL | catalogue query params UNVERIFIED |
| BUY-SRCH-010 | B3 Browse & Search | High | Browse, Search & Filter | Inactive, deleted and draft products never appear | IMPLEMENTED-TESTED | `ProductCatalogApiTest` |
| BUY-PDP-001 | B4 Product Detail | High | Product Detail Page | All variation types must be selected | IMPLEMENTED-UNTESTED | UI enforcement; widget-test coverage UNVERIFIED |
| BUY-PDP-002 | B4 Product Detail | High | Product Detail Page | Selecting a variant updates price, stock and image | PARTIAL | UI logic UNVERIFIED |
| BUY-PDP-003 | B4 Product Detail | High | Product Detail Page | Out-of-stock variant disabled | PARTIAL | stock rules exist server-side; UI UNVERIFIED |
| BUY-PDP-004 | B4 Product Detail | High | Product Detail Page | Quantity limits (1..99,999) | IMPLEMENTED-TESTED | qty 0/negative/>99,999 → 422 (`docs/loophole-report.md` item 1); `OrderPlacerTest` |
| BUY-PDP-005 | B4 Product Detail | High | Product Detail Page | Per-buyer purchase limit | PARTIAL | limit rule UNVERIFIED |
| BUY-CART-001 | B6 Cart | High | Cart | Same variant added twice merges | PARTIAL | local cart logic; cart widget tests exist, merge coverage UNVERIFIED |
| BUY-CART-002 | B6 Cart | High | Cart | Different variants stay separate lines | PARTIAL | local cart logic; UNVERIFIED |
| BUY-CART-004 | B6 Cart | High | Cart | Select and deselect items for checkout | PARTIAL | local cart logic; UNVERIFIED |
| BUY-CART-005 | B6 Cart | High | Cart | Change quantity at the limits | PARTIAL | local cart logic; UNVERIFIED |
| BUY-CART-006 | B6 Cart | High | Cart | Price changes while in the cart | PARTIAL | server price wins → 409 with new totals, proven server-side (`docs/loophole-report.md` item 1); app-side UNVERIFIED |
| BUY-CART-007 | B6 Cart | High | Cart | Item goes out of stock or is deleted while in the cart | PARTIAL | disabled product → 422 proven server-side; app-side UNVERIFIED |
| BUY-CART-010 | B6 Cart | High | Cart | Cannot change another buyer's cart | MISSING | no server cart resource exists; cart is device-local |
| BUY-VOU-001 | B7 Vouchers | High | Vouchers & Promotions | Claim a voucher into the wallet | IMPLEMENTED-TESTED | claim/list/claimable wallet endpoints (DECISIONS Phase C); `VoucherApiTest` |
| BUY-VOU-002 | B7 Vouchers | High | Vouchers & Promotions | Apply a store voucher | IMPLEMENTED-TESTED | cart voucher apply/remove routes; `VoucherApiTest` |
| BUY-VOU-003 | B7 Vouchers | High | Vouchers & Promotions | Stacking per the agreed rule (1 store + 1 platform + 1 shipping voucher) | PARTIAL | server accepts one `voucher_code`; app stacks locally (`docs/api-contract.md` §0.2) |
| BUY-VOU-004 | B7 Vouchers | High | Vouchers & Promotions | Minimum spend at the boundary | IMPLEMENTED-TESTED | minimum spend checked on the price actually paid; `VoucherApiTest` |
| BUY-VOU-005 | B7 Vouchers | High | Vouchers & Promotions | Expired, not-started, exhausted and invalid codes | IMPLEMENTED-TESTED | `VoucherApiTest` |
| BUY-VOU-007 | B7 Vouchers | High | Vouchers & Promotions | Removing an item drops below the minimum spend | PARTIAL | `CartState` repricing; UNVERIFIED |
| BUY-VOU-008 | B7 Vouchers | High | Vouchers & Promotions | Percentage voucher respects its cap | PARTIAL | app supports a cap; server percent voucher has no cap (`docs/api-contract.md` §0.2) |
| BUY-CHK-001 | B8 Checkout | High | Checkout | Checkout with the worked example | IMPLEMENTED-TESTED | `POST /api/v1/buyer/orders`; `PlaceOrderApiTest` |
| BUY-CHK-002 | B8 Checkout | High | Checkout | Shipping fee per store and per address | MISSING | `POST /api/v1/buyer/checkout/quote` does not exist (`docs/loophole-report.md` item 6); server total has no shipping term |
| BUY-CHK-004 | B8 Checkout | High | Checkout | Unserviceable address (ADDR-4 → 422) | MISSING | no quote endpoint; no wired `shipping_rates`/zone table |
| BUY-CHK-007 | B8 Checkout | High | Checkout | Double-tap Place Order | PARTIAL | no Idempotency-Key handling (`docs/loophole-report.md` item 2* OPEN); UI guard UNVERIFIED |
| BUY-CHK-008 | B8 Checkout | High | Checkout | Price changed during checkout | IMPLEMENTED-TESTED | server price wins → 409 with new totals (`OrderPlacerTest`) |
| BUY-CHK-009 | B8 Checkout | High | Checkout | Stock ran out during checkout | IMPLEMENTED-TESTED | atomic conditional decrement (`OrderPlacerTest`) |
| BUY-CHK-011 | B8 Checkout | High | Checkout | Tampered price or total in the request | IMPLEMENTED-TESTED | server price wins (`OrderPlacerTest`) |
| BUY-CHK-012 | B8 Checkout | High | Checkout | [Mobile] Connection lost after tapping Place Order | PARTIAL | mobile resilience OPEN (`docs/loophole-report.md` item 13) |
| BUY-PAY-001 | B9 Payment | High | Payment | COD order | IMPLEMENTED-TESTED | `POST /api/v1/buyer/orders` (COD); `PlaceOrderApiTest` |
| BUY-PAY-002 | B9 Payment | High | Payment | E-wallet or card payment succeeds | LIMITATION | no real payment gateway (`docs/KNOWN_LIMITATIONS.md`) |
| BUY-PAY-003 | B9 Payment | High | Payment | Payment fails | LIMITATION | no payment gateway |
| BUY-PAY-006 | B9 Payment | High | Payment | Unpaid order auto-cancels | MISSING | no cancel route on the route list |
| BUY-PAY-008 | B9 Payment | High | Payment | [Mobile] Pay in the e-wallet app and switch back | LIMITATION | no payment gateway |

| BUY-TRK-001 | B10 Order Tracking | High | Order Tracking & History | Orders appear in the correct tab | IMPLEMENTED-TESTED | `GET /api/v1/buyer/orders`; `OrderApiTest` |
| BUY-TRK-002 | B10 Order Tracking | High | Order Tracking & History | Timeline matches seller, rider and sorting center updates | IMPLEMENTED-TESTED | milestone + `CourierHandoverApiTest` (sorting-center updates UNVERIFIED server side) |
| BUY-TRK-003 | B10 Order Tracking | High | Order Tracking & History | Order Received button | MISSING | `PATCH /orders/{sellerOrder}/buyer-state` notWired (`docs/KNOWN_LIMITATIONS.md`) |
| BUY-TRK-006 | B10 Order Tracking | High | Order Tracking & History | Cannot open another buyer's order | IMPLEMENTED-TESTED | IDOR sweep; `RouteMatrixTest` |
| BUY-TRK-007 | B10 Order Tracking | High | Order Tracking & History | [Mobile] Push notification opens the order | LIMITATION | no notifications endpoint (`docs/KNOWN_LIMITATIONS.md`) |
| BUY-TRK-008 | B10 Order Tracking | High | Order Tracking & History | Full order flow from the ERP: placed to completed | IMPLEMENTED-TESTED | `EndToEndAcceptanceTest` (15-step Buyer→Seller→Rider story) |
| BUY-CAN-001 | B11 Cancellation | High | Cancellation | Cancel a COD order before shipping | MISSING | no cancel route on the route list |
| BUY-CAN-003 | B11 Cancellation | High | Cancellation | Cancel a prepaid order | MISSING | no cancel route |
| BUY-CAN-004 | B11 Cancellation | High | Cancellation | No cancel after Ready for Pickup | MISSING | no cancel route; state machine partial |
| BUY-CAN-005 | B11 Cancellation | High | Cancellation | No cancel after shipping | MISSING | no cancel route |
| BUY-RET-001 | B12 Return & Refund | High | Return & Refund Request | Request a return within the window | MISSING | returns notWired (`docs/KNOWN_LIMITATIONS.md`) |
| BUY-RET-002 | B12 Return & Refund | High | Return & Refund Request | Return not allowed before delivery | MISSING | returns notWired |
| BUY-RET-003 | B12 Return & Refund | High | Return & Refund Request | Return window boundary | MISSING | returns notWired |
| BUY-RATE-001 | B13 Ratings & Reviews | High | Ratings & Reviews | Rate a completed order | IMPLEMENTED-TESTED | `PATCH /api/orders/{order}/items/{product}/review`; `ReviewApiTest` |
| BUY-RATE-002 | B13 Ratings & Reviews | High | Ratings & Reviews | Rating not allowed before completion | IMPLEMENTED-TESTED | delivered-only enforced (`ReviewController::upsert`) |
| BUY-CHAT-003 | B14 Chat & Notifs | High | Chat & Notifications | Order status notifications | LIMITATION | no notifications endpoint (`docs/KNOWN_LIMITATIONS.md`) |
| RID-PROF-004 | R1 Profile & Vehicle | High | Profile, Vehicle & Documents | License expiry boundary | PARTIAL | rider profile UI exists; boundary validation UNVERIFIED |
| RID-PROF-006 | R1 Profile & Vehicle | High | Profile, Vehicle & Documents | Suspended rider cannot work | PARTIAL | suspension rule UNVERIFIED |
| RID-PROF-007 | R1 Profile & Vehicle | High | Profile, Vehicle & Documents | Rider cannot see another rider's profile | PARTIAL | IDOR protection for rider profiles UNVERIFIED |
| RID-AVAIL-001 | R2 Availability | High | Availability | Go Online | MISSING | no online/offline endpoint on the route list |
| RID-AVAIL-002 | R2 Availability | High | Availability | Go Offline | MISSING | no online/offline endpoint |
| RID-AVAIL-005 | R2 Availability | High | Availability | [Mobile] Location permission needed to go Online | PARTIAL | client-side permission; no backend to gate against |

| RID-JOB-001 | R3 Job Assignment | High | Job Assignment | View and accept a job | PARTIAL | view: `GET /api/v1/courier/jobs`; accept: no endpoint (`docs/loophole-report.md` item 3* OPEN) |
| RID-JOB-003 | R3 Job Assignment | High | Job Assignment | Offer timeout | MISSING | no offer/accept endpoint (60 s offers are S7) |
| RID-JOB-005 | R3 Job Assignment | High | Job Assignment | Accept and manual assignment at the same moment | MISSING | race protection OPEN (`docs/loophole-report.md` item 3*) |
| RID-JOB-006 | R3 Job Assignment | High | Job Assignment | Concurrent job limit (max 3 active) | MISSING | not enforced (`docs/loophole-report.md` item 3*) |
| RID-JOB-007 | R3 Job Assignment | High | Job Assignment | Cannot accept another company's job | PARTIAL | assignment server-side (`SellerOrder::assignCourier`) |
| RID-JOB-008 | R3 Job Assignment | High | Job Assignment | [Mobile] Job offer while the phone is locked | PARTIAL | client-side; no backend offer channel |
| RID-JOB-010 | R3 Job Assignment | High | Job Assignment | Delivery assignment by area | PARTIAL | server-side area assignment (S7); UNVERIFIED |
| RID-JOB-011 | R3 Job Assignment | High | Job Assignment | Sorting by the delivery address | PARTIAL | UNVERIFIED |
| RID-JOB-013 | R3 Job Assignment | High | Job Assignment | Cannot open a parcel of another rider's area | PARTIAL | IDOR test; `CourierJobController` scopes to `assigned_courier_id` (DECISIONS H6) |
| RID-PICK-002 | R4 Pickup | High | Pickup | Confirm pickup by scanning | MISSING | no waybill endpoint (S7); scanner exists locally |
| RID-PICK-004 | R4 Pickup | High | Pickup | Wrong tracking number | MISSING | no waybill validation endpoint |
| RID-PICK-007 | R4 Pickup | High | Pickup | [Mobile] Pickup with no internet | PARTIAL | mobile resilience OPEN (`docs/loophole-report.md` item 13) |
| RID-PICK-008 | R4 Pickup | High | Pickup | Hand the parcel over at the sorting center | PARTIAL | milestone exists server-side; scan-in flow not wired end-to-end |
| RID-PICK-009 | R4 Pickup | High | Pickup | Seller confirms the handover | PARTIAL | `PATCH /seller/orders/{sellerOrder}/confirm` exists; mobile side not connected |
| RID-DLV-001 | R5 Delivery | High | Delivery & Status Updates | Deliver with proof in sequence | IMPLEMENTED-TESTED | `ALLOWED_TRANSITIONS` (`CourierMilestoneController`); loophole report item 4* PROVEN SAFE |
| RID-DLV-002 | R5 Delivery | High | Delivery & Status Updates | Skipping a status | IMPLEMENTED-TESTED | illegal transitions rejected 422 |
| RID-DLV-003 | R5 Delivery | High | Delivery & Status Updates | Photo is required | IMPLEMENTED-TESTED | delivery-proof route (H4 tests) |
| RID-DLV-004 | R5 Delivery | High | Delivery & Status Updates | OTP checks | PARTIAL | 5 wrong attempts lock 15 min (DECISIONS Phase D); code issuance/storage UNVERIFIED |
| RID-DLV-005 | R5 Delivery | High | Delivery & Status Updates | COD amount must match | PARTIAL | amount check enforced (`settleCod`); `cod_collected_amount` column missing (`docs/loophole-report.md` item 5*) |
| RID-DLV-007 | R5 Delivery | High | Delivery & Status Updates | Cannot update another rider's job | IMPLEMENTED-TESTED | 403 when not the assigned courier (`CourierMilestoneController`) |
| RID-DLV-008 | R5 Delivery | High | Delivery & Status Updates | [Mobile] Proof-of-delivery photo must be taken live | PARTIAL | camera/`mobile_scanner` widget tests use fakes; real camera not proven in widget tests |
| RID-DLV-009 | R5 Delivery | High | Delivery & Status Updates | [Mobile] Mark Delivered with no internet | PARTIAL | mobile resilience OPEN (`docs/loophole-report.md` item 13) |
| RID-DLV-012 | R5 Delivery | High | Delivery & Status Updates | Collect the parcel at the sorting center | PARTIAL | milestone server-side; end-to-end flow not wired |
| RID-FAIL-001 | R6 Failed Delivery | High | Delivery Failed | Mark failed with a reason | PARTIAL | milestone transitions; failure reasons UNVERIFIED server side |
| RID-FAIL-003 | R6 Failed Delivery | High | Delivery Failed | Refused goes straight to Return to Sender | MISSING | no reschedule/return-to-sender endpoint (S7) |
| RID-FAIL-004 | R6 Failed Delivery | High | Delivery Failed | Attempt counter and reschedule | PARTIAL | attempt counter UNVERIFIED; reschedule not built |
| RID-FAIL-005 | R6 Failed Delivery | High | Delivery Failed | Return to Sender after the 3rd failed attempt | MISSING | no return-to-sender endpoint |
| RID-EARN-002 | R7 Earnings & COD | High | History, Earnings & COD Remittance | Pay per job and daily total | PARTIAL | local mock state; no earnings endpoint |
| RID-EARN-003 | R7 Earnings & COD | High | History, Earnings & COD Remittance | COD balance to remit | PARTIAL | local mock state; not reconciled against logistics backend |
| RID-EARN-004 | R7 Earnings & COD | High | History, Earnings & COD Remittance | Remit COD and confirmation | MISSING | no remittance table/endpoint (`docs/loophole-report.md` item 5*) |
| RID-EARN-006 | R7 Earnings & COD | High | History, Earnings & COD Remittance | Rider cannot confirm own remittance | PARTIAL | local rule; server-side UNVERIFIED |

## Counts by sheet × status × priority (High-priority cases only)

| Sheet | High cases | IMPLEMENTED-TESTED | IMPLEMENTED-UNTESTED | PARTIAL | MISSING | LIMITATION | WEB-ONLY |
|-------|------------|--------------------|----------------------|---------|---------|------------|----------|
| B1 Account | 6 | 4 | 0 | 2 | 0 | 0 | 0 |
| B2 Address Book | 6 | 3 | 1 | 2 | 0 | 0 | 0 |
| B3 Browse & Search | 4 | 1 | 0 | 3 | 0 | 0 | 0 |
| B4 Product Detail | 5 | 1 | 1 | 3 | 0 | 0 | 0 |
| B5 Wishlist & Follow | 0 | — | — | — | — | — | — |
| B6 Cart | 7 | 0 | 0 | 6 | 1 | 0 | 0 |
| B7 Vouchers | 7 | 4 | 0 | 3 | 0 | 0 | 0 |
| B8 Checkout | 8 | 4 | 0 | 2 | 2 | 0 | 0 |
| B9 Payment | 5 | 1 | 0 | 1 | 1 | 3 | 0 |
| B10 Order Tracking | 6 | 4 | 0 | 1 | 1 | 1 | 0 |
| B11 Cancellation | 4 | 0 | 0 | 0 | 4 | 0 | 0 |
| B12 Return & Refund | 3 | 0 | 0 | 0 | 3 | 0 | 0 |
| B13 Ratings & Reviews | 2 | 2 | 0 | 0 | 0 | 0 | 0 |
| B14 Chat & Notifs | 1 | 0 | 0 | 0 | 0 | 1 | 0 |
| R1 Profile & Vehicle | 3 | 0 | 0 | 3 | 0 | 0 | 0 |
| R2 Availability | 3 | 0 | 0 | 1 | 2 | 0 | 0 |
| R3 Job Assignment | 9 | 0 | 0 | 6 | 3 | 0 | 0 |
| R4 Pickup | 5 | 0 | 0 | 3 | 2 | 0 | 0 |
| R5 Delivery | 9 | 4 | 0 | 5 | 0 | 0 | 0 |
| R6 Failed Delivery | 4 | 0 | 0 | 2 | 2 | 0 | 0 |
| R7 Earnings & COD | 4 | 0 | 0 | 3 | 1 | 0 | 0 |
| **Total** | **101** | **28** | **2** | **44** | **22** | **5** | **0** |

## High-priority cases predicted to fail (against current code)

These are the cases the current build cannot satisfy honestly; they should shape the demo script (S3.4) rather than be demonstrated live:

- **MISSING (22):** BUY-CART-010, BUY-CHK-002/004, BUY-PAY-006, BUY-TRK-003, BUY-CAN-001/003/004/005, BUY-RET-001/002/003, RID-AVAIL-001/002, RID-JOB-003/005/006, RID-PICK-002/004, RID-FAIL-003/005, RID-EARN-004.
- **LIMITATION (5):** BUY-PAY-002/003/008, BUY-TRK-007, BUY-CHAT-003.
- **Highest-risk PARTIAL cases in the buyer demo path:** BUY-CHK-002/004 (shipping has no server endpoint), BUY-CHK-007 (no idempotency key), RID-DLV-004/005 (OTP/COD settlement storage missing).

## CONFLICTS (where the code does the opposite of an explicit spec rule)

1. **Shipping (§S6.2 vs `docs/api-contract.md` §0.1):** the spec wants shipping per store from a `shipping_rates` table; the app charges ₱60 shipping (free at ₱1,000) in `mobile/lib/config/pricing.dart`, while the server order total is `subtotal − discount` with no shipping term and `POST /orders` accepts none → the app's displayed total and the server's recorded total can differ.
2. **Vouchers (§S6.2 vs `docs/api-contract.md` §0.2):** the spec allows 1 store + 1 platform + 1 shipping voucher with caps and scopes; the app supports fixed/percent/shipping-rebate/category-scoped/capped vouchers with stacking, while the server supports fixed/percent only, one `voucher_code`, no cap/scope/shipping term → local multi-voucher savings are never recorded server-side.
3. **Buyer OTP then admin approval before login (spec A12 example in MASTER_PLAN §S1):** new buyers land on the pending-approval gate and cannot log in until an admin approves — opposite of an immediate post-OTP login. Listed as flagged in the plan; confirm against the AC sheets.
4. **Minimum spend (§S6.2):** "minimum spend is checked on the price actually paid" — the app prices stacked vouchers locally in `CartState` while the server applies a single code → divergence (related to conflict 2).

## Proposed scope split

**MUST (High priority, features that have UI today):** BUY-ACCT-003/004/005/006, BUY-ADDR-001/002/009, BUY-SRCH-010, BUY-PDP-004, BUY-CART-001..007, BUY-VOU-001/002/005, BUY-CHK-001/008/009/011, BUY-PAY-001, BUY-TRK-001/002/006/008, BUY-RATE-001/002, RID-DLV-001/002/003/007, RID-PICK-009.

**SHOULD:** BUY-ADDR-003/007/008, BUY-PDP-001/002/003/005, BUY-VOU-003/004/007/008, BUY-CHK-007/012, RID-PROF-004/006/007, RID-AVAIL-005, RID-JOB-001/007/008/010/011/013, RID-PICK-007/008/012, RID-DLV-004/005/008/009/012, RID-FAIL-001/004, RID-EARN-002/003/006.

**LIMITATION (documented, not built):** all MISSING and LIMITATION cases above (BUY-CART-010, BUY-CHK-002/004, BUY-PAY-002/003/006/008, BUY-TRK-003/007, BUY-CAN-*, BUY-RET-*, BUY-CHAT-003, RID-AVAIL-001/002, RID-JOB-003/005/006, RID-PICK-002/004, RID-FAIL-003/005, RID-EARN-004) plus out-of-scope items: real payment gateway, push notifications, chat backend, live tracking map, server-side voucher stacking, server-side shipping, COD remittance storage.

This document is read-only except for docs (S1 scope). Cases were not run. REVIEW STOP 1: send this file (summary part) plus `docs/feature-status.md` (counts and demo split).

