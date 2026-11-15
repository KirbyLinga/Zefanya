# Mobile ↔ WebZefanya API Integration Audit

Date: 2026-11-06 · Backend of record: `WebZefanya/` (Laravel + Sanctum) · Client: `Zefanya/mobile/` (one Flutter app, Buyer and Rider roles)

> **Update (second pass, same day):** the buyer commerce features (catalog, cart, COD checkout, shipping, vouchers, addresses, cancel, returns, notifications, wishlist, messages) are now implemented. See **§9**, which supersedes the buyer rows in §1, the buyer "Missing" line in §3 and §8 items 1–2.

## 0. Summary

- **Before this pass**, WebZefanya's bearer API (`routes/api.php`) had only login/profile/logout for buyers and riders, plus rider registration. The mobile app called a *different* contract (built against `Zefanya/web`): `/login/buyer`, `/me`, `/orders`, `/products`, `/courier/jobs`, `PATCH /courier/jobs/{id}/milestone`, and so on. **In live mode against WebZefanya, nothing past the login request worked**, and even login failed, because the token sits under `data.token`.
- **Added to WebZefanya** (additive only): bearer endpoints for the whole rider delivery lifecycle (jobs, availability, scan verify, pickup confirm, start delivery, delivery OTP, proof of delivery with COD, failed delivery, earnings), and buyer per-seller shipment tracking with receipt confirmation. Every endpoint is a thin adapter over the service the web panel already uses. No existing business logic, migration, status rule, financial rule or auth policy was changed.
- **Mobile adjusted**: auth now uses WebZefanya's routes and envelope. The rider tracker is now server-authoritative: the server's status replaces local milestones, the scan token is fetched before each action, and proof of delivery is an awaited, duplicate-guarded multipart upload. 409 is surfaced as a conflict, failed syncs re-read the jobs instead of being swallowed, and the app never holds the delivery OTP.
- **Still blocked or not yet wired**: buyer catalog, cart, checkout, vouchers, addresses, cancellation, returns, notifications, messages and registration have no WebZefanya API. Some need only additive endpoints. Others (checkout, buyer cancel, rider pickup failure) have their logic inline in web controllers, so an API version would have to either duplicate it or refactor the web side. Both are forbidden here, so those are reported as blockers (§8).

Legend: **S** Supported · **A** Requires additive API endpoint · **M** Requires mobile adjustment · **B** Blocked by a missing backend capability or policy.
"Done" marks what this pass implemented.

## 1. Buyer Mobile compatibility matrix

| Feature | Mobile call (repository) | WebZefanya API | Web implementation (service) | Mismatch | Class | Status |
|---|---|---|---|---|---|---|
| Login | `POST /login/buyer` → `POST /buyer/login` (`auth_repository.dart`) | `POST /api/buyer/login` (throttle `api-login`) | `Api\Buyer\LoginController` | token under `data.token`; no account in body; 401 for bad credentials | M | **Done**: path, envelope, profile read after login, 401 → "Incorrect email or password." |
| Profile / session restore | `GET /me` → `GET /buyer/profile` | `GET /api/buyer/profile` | `Api\Buyer\ProfileController` | `first_name/last_name/status` instead of `name/registration_status` | M | **Done**: `approved`→approved, `pending`→pending_approval, unknown fails safe to pending |
| Logout | `POST /logout` → `POST /buyer/logout` | `POST /api/buyer/logout` → **204** | revokes current token only | client rejected empty 2xx | M | **Done**: client accepts 204; token always forgotten locally |
| Registration + email OTP | `POST /register/buyer`, `/register/buyer/verify-otp/{id}` | none | web `BuyerRegistration` flow (session) | no bearer/guest API | A | Not done; left unchanged (will 404) |
| Google sign-in | `POST /auth/google` | none | web OAuth | — | A | Not done |
| Account security (password/email/phone) | `/account/*` | none | web `Account*Controller` | — | A | Not done |
| Products, categories, search, filters, promotions | `/products`, `/products/{id}`, `/categories`, `/promotions` | none | `CatalogSearch`, `PriceResolver`, `OfferPricing` | — | A | Not done (services reusable) |
| Cart | local + `GET /vouchers` | none | `CartController`, `CartSummary`, `CartVariantResolver` | — | A | Not done |
| Vouchers | `GET /vouchers` | none | `VoucherApplication`, `VoucherSessionService` | voucher application is stored **in the web session** | B | Needs a stateless design decision (see §4) |
| Wishlist | — (local) | none | `WishlistController` | — | A | Not done |
| Addresses | `/buyer/addresses` CRUD + default | none | `BuyerAddressBook::save/makeDefault/delete` | — | A | Not done (service is directly reusable) |
| Shipping providers / checkout / COD | `POST /orders` | none | `CheckoutController` (≈850 lines inline), `CheckoutCarrierService` | pricing, stock, voucher and COD logic sit in the controller | B | Blocked: reuse requires extracting web checkout into a service |
| Orders and per-seller shipment tracking | `GET /orders` | **`GET /api/buyer/shipments`**, **`GET /api/buyer/shipments/{shipment}`** | existing WIP `Api\Buyer\OrderController` + new `ShipmentResource` | mobile models whole orders; API is per seller shipment | A → M | **API done**; mobile `LiveOrderRepository` not yet re-pointed |
| Delivery code (for the rider) | buyer pass / QR | `delivery_code` in `ShipmentResource` (only while OUT_FOR_DELIVERY) | `DeliveryOtpService::activeCodeFor` (as on the web order page) | — | S | **Done** (API) |
| Confirm receipt | — | **`POST /api/buyer/orders/{order}/shipments/{shipment}/confirm-received`** | `BuyerShipmentConfirmation::confirm` (row lock, idempotent) | not-delivered answers **422**, not 409 (service behaviour) | S | **API done**; mobile not wired |
| Cancellation | cancel order | none | `OrderController::cancelSellerOrder` (inline transaction) | — | B | Blocked: logic not in a service |
| Returns / refunds | `return_repository.dart` (local) | none | `ParcelReturnService` | — | A | Not done (service reusable) |
| Reviews | `GET /reviews/mine` | none | `ProductReviewService` | — | A | Not done |
| Notifications | — | none | DB notifications, `Buyer\NotificationController` | — | A | Not done |
| Messages | chat (mock-only in live mode) | none | `MessageController` | — | A | Not done |

## 2. Rider Mobile compatibility matrix

| Feature | Mobile call | WebZefanya API | Web implementation (service) | Mismatch | Class | Status |
|---|---|---|---|---|---|---|
| Registration | `POST /register/courier` (multipart) | `POST /api/rider/register`, signed `verify-otp` / `resend-otp` | `RiderRegistrationService` | field names differ (`license_number`→`license_no`, `license_doc`→`drivers_license`, `or_cr_doc`→`or_cr`); requires `logistics_provider_id`, PSGC codes with `address_mode=api`, `terms`; verify uses **signed URLs** returned by the server, not an id path; no public provider-list API | M + A | Not done; documented |
| Approval | pending screens | login answers 403 with a message | Logistics approves (`api/logistics/rider-applications/*`, session) | 403 has no `code`/`registration_status`, so the app shows it as Forbidden (message intact) | S | Works; the pending screen cannot be routed from login |
| Login / profile / logout | `/login/courier`, `/me/courier`, `/logout/courier` → `/rider/login`, `/rider/profile`, `/rider/logout` | exist | `Api\Rider\*Controller` | rider status `active` | M | **Done** |
| Online / offline | — | **`POST /api/rider/availability`** `{online}` | `RiderAvailabilityService::setOnline` (row lock; flags unstarted jobs) | — | S | **API done**; no mobile toggle wired yet |
| Assigned pickups and deliveries | `GET /courier/jobs` → `GET /rider/jobs` | **`GET /api/rider/jobs`** | `RiderOperationsService::pickupAssignments/deliveryAssignments` | COD in minor units; no OTP; no per-job fee | M | **Done** (`deliveryJobFromRiderParcel`) |
| Pickup sessions / schedules | — | none | `PickupSessionService::finish/summary` | — | A | Not done |
| Pickup verification + confirm | milestone `PICKED_UP` | **`POST /api/rider/scan/verify`** → **`POST /api/rider/pickups/{id}/confirm`** | `ParcelTrackingVerificationService`, `ParcelHandoffVerification`, `RiderOperationsService::confirmPickup` | needs a scan token | M | **Done** |
| Pickup failure | — | none | `PickupAssignmentsController::reportFailure` (inline: attempts, cancel on 2nd, notifications) | — | B | Blocked: logic not in a service |
| Station receipt / sorting | milestones `AT_SORTING_CENTER`, `SORTED`, `ASSIGNED_TO_RIDER` | — (Logistics web actions) | `ParcelLegService`, `ManifestService` | not rider actions | M | **Done**: the app no longer sends them |
| Start delivery (trip) | milestone `OUT_FOR_DELIVERY` | **`POST /api/rider/deliveries/{id}/start`** | `RiderOperationsService::startDelivery` | needs a scan token | M | **Done** |
| Delivery OTP | local check against `job.deliveryOtp` | **`POST /api/rider/deliveries/{id}/otp`** (throttle 5/min) | `DeliveryOtpService::send/verify/consume` (hashed, 10 min, 5 attempts) | app expected the code in the job payload | M | **Done**: "Send code to buyer"; the server verifies |
| Proof of delivery + COD | milestone `DELIVERED` (no photo sent) | **`POST /api/rider/deliveries/{id}/deliver`** (multipart) | `RiderOperationsService::completeDelivery` (exact COD amount, proof once, remittance) | photo, OTP and COD must go in one request | M | **Done**: awaited multipart; local completion only on 200 |
| Failed delivery | milestone `DELIVERY_FAILED` + free text | **`POST /api/rider/deliveries/{id}/failed`** | `RiderOperationsService::failDelivery` | reasons map to the `DeliveryFailureReason` enum | M | **Done** (`failureReasonWire`) |
| Return to sender | local `returnToSender` after 3 attempts or "Buyer refused" | — | Logistics decides (`ParcelReturnService`) | **business-rule conflict** (§4) | M | **Done**: sent as a failed attempt; server status wins on refresh |
| Incidents | — | `POST /api/rider/parcels/{id}/incidents` exists but is **session + CSRF only** | `ParcelIncidentController` / `ParcelIncidentService` | unreachable with a bearer token | A | Not done |
| Return pickups (return-to-station) | — | none | `ReturnPickupController::collect` | — | A | Not done |
| Earnings, payouts, COD custody | local `completeJob` payout maths | **`GET /api/rider/earnings`** | `RiderEarningService::summaryFor/recentFor`, `RiderCashService::summaryFor` | app computes payouts itself | M | **API done**; earnings screen not yet re-pointed |
| Notifications | — | none | `Rider\NotificationController` | — | A | Not done |
| Wind-down (company suspended) | — | `api.role:rider` refuses with 403 | web `EnsureRiderActive` lets riders finish OUT_FOR_DELIVERY parcels | API stricter than web | B | Policy blocker (§8) |

## 3. Existing and missing API endpoints

All under `/api`, Sanctum bearer, `api.role:<role>` (token ability, model type, expiry, account eligibility).

**Pre-existing (unchanged):** `POST buyer/login`, `GET buyer/profile`, `POST buyer/logout`, `POST rider/login`, `GET rider/profile`, `POST rider/logout`, `POST rider/register`, `POST rider/registration/resume`, `POST rider/registration/{rider}/verify-otp|resend-otp` (signed), `POST rider/parcels/{sellerOrder}/incidents` (session + CSRF), `api/logistics/rider-applications/*`.

**Added in this pass:**

| Method | Path | Throttle | Delegates to |
|---|---|---|---|
| GET | `rider/jobs` | 60/min | `RiderOperationsService::pickupAssignments`, `deliveryAssignments`, `isWindingDown` |
| GET | `rider/earnings` | 60/min | `RiderEarningService`, `RiderCashService` |
| POST | `rider/availability` | 20/min | `RiderAvailabilityService::setOnline` |
| POST | `rider/scan/verify` | 20/min | `ParcelTrackingVerificationService::verifyForRider` (web URLs stripped; keys + tokens only) |
| POST | `rider/pickups/{sellerOrder}/confirm` | 20/min | `assertPickupAssigned` → `ParcelHandoffVerification` → `confirmPickup` |
| POST | `rider/deliveries/{sellerOrder}/start` | 20/min | `assertDeliveryAssigned` → token → `startDelivery` |
| POST | `rider/deliveries/{sellerOrder}/otp` | 5/min | `DeliveryOtpService::send` (the code is never returned) |
| POST | `rider/deliveries/{sellerOrder}/deliver` | 20/min | `CompleteDeliveryApiRequest` (web rules) → `completeDelivery` |
| POST | `rider/deliveries/{sellerOrder}/failed` | 20/min | `MarkDeliveryFailedApiRequest` (web rules) → `failDelivery` |
| GET | `buyer/shipments` | 60/min | WIP `Api\Buyer\OrderController@index` (buyer-scoped, paginated 20) |
| GET | `buyer/shipments/{shipment}` | 60/min | `OrderController@show` (404 for other buyers) |
| POST | `buyer/orders/{order}/shipments/{shipment}/confirm-received` | 20/min | `OrderController@confirm` → `BuyerShipmentConfirmation` |

Response shape: `{success, message, data, errors}` (`ApiResponses`). Validation answers 422 `{message, errors}`; stale state answers 409 (the services' `StaleStateException::field()` / `StaleStateException`, and any other status-transition `InvalidArgumentException`, mapped in `ParcelActionController::guarded`). Ownership aborts answer 403 or 404.

**Missing (by priority):** buyer catalog (products, categories, search) → addresses → returns → notifications (both roles) → rider incidents (bearer) → pickup sessions → return pickups → reviews → wishlist → buyer registration → checkout/cancel/pickup-failure (blocked, §8).

## 4. Backend ↔ mobile logic conflicts

| Conflict | Mobile behaviour | WebZefanya rule (authoritative) | Resolution |
|---|---|---|---|
| Delivery OTP | Code shipped in the job payload and checked on the device (5 tries, 15 min lock) | Code sent to the buyer only, stored hashed, verified on the server (5 attempts, 10 min expiry) | Server never sends it, so the local check is skipped (`deliveryOtp` empty). The rider asks the server to send it. |
| Milestone sync | Optimistic, fire-and-forget, failures swallowed | Every transition runs under a row lock and may be refused (409/422/403) | Failure is now kept in `CourierState.syncFailure`, shown once, and the jobs are re-read so the server status replaces the optimistic one |
| Proof of delivery | Local photo; `DELIVERED` sent without the photo | Photo (jpg/png/webp, ≤5 MB), OTP and exact COD are required in one request | Awaited multipart upload; the local "complete" runs only after 200 |
| Attempt limit / return | 3rd failed attempt or "Buyer refused" → local `returnToSender` | Server records `DELIVERY_FAILED`; Logistics decides the return | Sent as a failed attempt with the mapped reason. The local "return" label is display state that the next refresh overwrites. **Recommend removing the local rule in live mode.** |
| Rider earnings | `completeJob` credits the fee (and `kReturnFeeShare` for returns) on the device | `RiderEarningService::creditFor` on the status transition | `/rider/earnings` added. The earnings screen still shows local sums: **mobile follow-up** |
| Station steps | Rider could advance `AT_SORTING_CENTER`, `SORTED`, `ASSIGNED_TO_RIDER` | Logistics/station actions with their own scan tokens | No longer sent from the app |
| Failure reasons | Free labels (`Payment not ready`, `Other`) | `DeliveryFailureReason` enum; `failure_notes` required for `other` | Mapped. Unmapped labels go as `other` with the label as notes |
| Registration status names | `approved / pending_approval / pending_verification / rejected` | `approved` (buyer), `active` (rider), `pending`, `rejected`, `suspended`, `deactivated` | Translated in `_webZefanyaStatus`; unknown fails safe to pending |
| Vouchers / checkout pricing | Mobile `config/pricing.dart` computes totals | Server-only pricing; vouchers held in the **web session** | Not integrated. Do not let the app compute what it will be charged. A stateless voucher quote needs a backend design decision |

## 5. Security findings

| # | Area | Finding | Severity | Evidence / status |
|---|---|---|---|---|
| 1 | OTP exposure | The mobile DTO accepted `delivery_otp` in the rider's job payload, which would let a rider complete a delivery without the buyer | High (design) | WebZefanya never sends it. Test: `test_jobs_lists_only_my_parcels_and_never_exposes_the_delivery_code`, `test_send_otp_..._never_returns_the_code` |
| 2 | Cross-account access | Buyer token on rider routes, session-only access, other rider's parcel, other buyer's shipment | — | All refused (403/401/403/404). Tests: `test_endpoints_require_a_rider_bearer_token`, `test_start_delivery_..._rejects_other_riders`, `test_pickup_confirm_requires_assignment`, `test_shipments_are_scoped_to_the_token_owner`, `test_rider_tokens_cannot_read_buyer_shipments`, `test_confirm_received_completes_only_my_delivered_shipment` |
| 3 | COD tampering | A client-sent amount is compared with `payments.amount_minor` under lock; a mismatch rolls back | — | `test_deliver_rejects_tampered_cod_amount_and_bad_proof_without_state_change` |
| 4 | Duplicate requests | Deliver / start / confirm repeats are idempotent (no second proof, payment or history row) | — | `test_deliver_with_cod_records_proof_and_payment_once`; mobile `_submitting` guard |
| 5 | Stale state | Parcel moved after render → 409, nothing written | — | `test_stale_delivery_returns_409_and_changes_nothing` |
| 6 | Proof upload | Web rules reused (jpg/png/webp, ≤5 MB); stored on the private `local` disk; path never returned | — | PDF rejected in test |
| 7 | Rate limits | Login: `api-login` (5/min per email+IP, 25/min per IP); OTP send 5/min; actions 20/min; reads 60/min | — | `routes/api.php` |
| 8 | Token storage and expiry | `SecureTokenStore` (flutter_secure_storage). Tokens expire after `sanctum.expiration` = 60 min; **no refresh endpoint**, so riders re-login hourly mid-shift. 401 → token forgotten | Medium (UX) | Recommend a product decision on token lifetime or a refresh route (§8) |
| 9 | Notification privacy | Pickup leg hides buyer name, phone and address. Shipment resource omits commission, seller net and funding split | — | `RiderParcelResource`, `ShipmentResource` |
| 10 | Price / voucher tampering | Checkout not exposed, so no new surface | — | Blocked item |
| 11 | Wind-down riders | API refuses wind-down riders that the web lets finish deliveries; fails closed (safe), but blocks work | Low | §8 |
| 12 | Confirm-received stale code | Confirming an undelivered shipment answers 422 (service), not 409 | Low | `test_confirm_received_rejects_an_undelivered_shipment_without_change`; service unchanged by rule |
| 13 | Offline | No offline queue. Failed handoffs are reported and re-synced, never assumed successful. Delivery completion needs connectivity | Info | By design |

HTTP handling in the app: 401 → `UnauthorizedException` (session cleared on refresh; login shows "Incorrect email or password."), 403 → `ForbiddenException` / `PendingApprovalException`, 404 → `NotFoundException`, 409 → `RequestRejectedException.isConflict` (jobs re-read), 422 → `ValidationException.fieldErrors` (first field error shown), network/timeout → `NetworkException`.

## 6. Files changed and why

### WebZefanya (backend) — all additive except one route-file edit

| File | Change |
|---|---|
| `routes/api.php` | Added the rider operations routes and the buyer shipment routes inside the existing bearer groups, with throttles. Existing routes untouched |
| `app/Http/Controllers/Api/Rider/JobController.php` | **New**: jobs list, availability, earnings |
| `app/Http/Controllers/Api/Rider/ParcelActionController.php` | **New**: scan verify, pickup confirm, start, OTP, deliver, failed; mirrors the web controller's assert → token → service → consume order; 409 mapping |
| `app/Http/Requests/Api/Rider/CompleteDeliveryApiRequest.php` | **New**: extends the web `CompleteDeliveryRequest` (same rules) + `verification_token`; always JSON 422 |
| `app/Http/Requests/Api/Rider/MarkDeliveryFailedApiRequest.php` | **New**: same for `MarkDeliveryFailedRequest` |
| `app/Http/Resources/Mobile/RiderParcelResource.php` | **New**: rider parcel projection (no OTP, no proof paths, buyer contact on delivery leg only) |
| `app/Http/Resources/Mobile/ShipmentResource.php` | **New**: the class the pre-existing untracked `Api/Buyer/OrderController.php` referenced but which was missing. That controller was routed **unmodified** |
| `tests/Feature/Rider/RiderMobileApiTest.php` | **New**: 12 tests |
| `tests/Feature/Buyer/BuyerShipmentApiTest.php` | **New**: 4 tests |

Not modified: services, models, migrations, middleware (`ApiRole`), web routes and controllers, config.

### Zefanya/mobile

| File | Change |
|---|---|
| `lib/data/api/api_client.dart` | 204 No Content is a success (WebZefanya logout) |
| `lib/data/api/api_exception.dart` | `RequestRejectedException.isConflict` (409) |
| `lib/data/repositories/auth_repository.dart` | `/buyer|rider/login|profile|logout`; `data.token`; profile read after login; status translation; login 401 message |
| `lib/data/dto/remote_courier.dart` | `deliveryJobFromRiderParcel` mapper (minor → pesos, no OTP) |
| `lib/data/mock_data.dart` | `DeliveryJob.serverStatus` (optional) |
| `lib/data/repositories/courier_repository.dart` | WebZefanya rider contract: jobs, scan-token actions, OTP request, multipart proof, reason mapping |
| `lib/state/courier_state.dart` | Server status → milestones; sync failures kept and re-synced; `submitDelivery`, `requestDeliveryCode`; conflict → refresh |
| `lib/screens/courier/delivery_tracker_screen.dart` | Live mode: "Send code to buyer", awaited and duplicate-guarded delivery submit, server error messages, sync-failure snackbar |
| `test/live_auth_test.dart`, `test/live_courier_test.dart`, `test/courier_handover_wiring_test.dart`, `test/repository_wiring_test.dart` | Contract updates (recorded in `docs/DECISIONS.md`) |

`Zefanya/web/` was not touched. Nothing was committed (WebZefanya forbids commits without an exception; branch policy in Zefanya applies).

## 7. Test results

All backend runs used the isolated in-memory SQLite test database (`phpunit.xml`). No migrations were run against any real database.

| Run | Result |
|---|---|
| Baseline before changes: `Auth/BuyerRiderApiAuthenticationTest` + `RiderRegistration/MobileRiderRegistrationTest` | 90 passed |
| New: `Rider/RiderMobileApiTest` | 12 passed (63 assertions) |
| New: `Buyer/BuyerShipmentApiTest` | 4 passed (16 assertions) |
| Regression: `tests/Feature/Rider` + `tests/Feature/Auth` + `tests/Feature/RiderRegistration` + new buyer test | **646 passed**, 0 failed (4228 assertions) |
| Regression: `tests/Feature/Buyer` + `Logistics` + `Security` + `Order` | **1542 passed**, 0 failed (11610 assertions) |
| `vendor/bin/pint --dirty` / `--test` on changed files | passed |
| `php artisan route:list --path=api` | all new routes registered |
| Mobile `flutter analyze` / `flutter test` | **Not run: no Flutter/Dart SDK on this machine.** Mobile changes are unverified and must be analysed and tested before merge |
| Frontend build | Not applicable (no web frontend files changed) |

## 8. Remaining blockers and recommended next steps

**Policy-level blockers (not changed; decision needed):**

1. **Checkout, buyer cancellation and rider pickup failure** have their business logic inline in web controllers (`CheckoutController`, `Buyer\OrderController::cancelSellerOrder`, `Rider\PickupAssignmentsController::reportFailure`). A bearer API would have to duplicate it or move it into services; the second changes the web side. Recommend: approve moving each one into a service with no behaviour change (covered by the existing web tests), then add thin API adapters.
2. **Voucher application lives in the web session** (`VoucherSessionService`), so a stateless mobile checkout needs a server-side quote design (e.g. a signed quote fingerprint, as checkout already uses `priceFingerprint` / `quoteFingerprint`).
3. **Wind-down riders**: `ApiRole` requires `Rider::isOperational()`, while the web allows finishing OUT_FOR_DELIVERY parcels. Changing that is an auth-policy change.
4. **Token lifetime**: 60-minute tokens with no refresh force a re-login mid-shift. Decide on a lifetime or a refresh route.
5. **Rider incident API** is session + CSRF only. A bearer twin is additive but must reuse `ParcelIncidentService`.

**Next additive work (no policy change):**

6. Buyer: catalog (`CatalogSearch`), addresses (`BuyerAddressBook`), returns (`ParcelReturnService`), notifications and reviews endpoints, each with throttle, form request, resource and tests.
7. Rider: pickup-session finish (`PickupSessionService::finish`), return-pickup collect, notifications.
8. Rider registration: align `RegistrationProfile.toCourierFields` with `StoreRiderRegistrationRequest` and follow the signed `verify_url` / `resend_url`; needs a public approved-provider list endpoint.

**Mobile follow-ups:**

9. Point `LiveOrderRepository` at `/buyer/shipments` (per-seller shipments) and wire confirm-received; show `delivery_code` on the buyer pass.
10. Read earnings from `/rider/earnings` in live mode; drop the local payout and 3-attempt return rules when `isServerAuthoritative`.
11. Add an online/offline toggle using `/rider/availability`.
12. Run `flutter analyze` and `flutter test` (especially `live_auth_test`, `live_courier_test`, `courier_*_test`, `repository_wiring_test`) and fix anything reported.
13. Decide what to do with the untracked `WebZefanya/app/Http/Controllers/Api/Buyer/OrderController.php` (pre-existing WIP, now routed). Either commit it with `ShipmentResource`, or replace it before release.

## 9. Second pass — Buyer commerce (2026-11-06)

The user approved moving the inline web logic into services (§8 items 1–2), so this pass covered the whole buyer list. It **supersedes the buyer rows in §1, the buyer "Missing" line in §3 and §8 items 1–2.** The rider sections are unchanged.

### 9.1 Buyer status after this pass

| Priority | Feature | Backend (WebZefanya) | Mobile | Status |
|---|---|---|---|---|
| P0 | Product catalog | `GET /api/buyer/products` (public, 120/min), `ProductCatalogQuery` shared with the web shop | `LiveCatalogRepository` → `/buyer/products` | **Done** |
| P0 | Categories, search, filters | `GET /api/buyer/categories`; `q`, `category`, `seller`, `on_sale`, `min_price`, `max_price`, `sort`, `page`; typo suggestion | label/name → server id maps; unknown filter answers empty without a request | **Done** |
| P0 | Product details, images, prices | `GET /api/buyer/products/{id}` (same `isListedForSale` gate, `PriceResolver` per variant, centavos) | `productFromWebZefanyaJson`; `ProductVariant.id` carries the SKU | **Done** |
| P0 | Shopping cart | `/api/buyer/cart`, `/cart/items` (add/patch/delete/bulk) via `CartService` (extracted) | Local cart stays the UI; the live checkout syncs lines into the server cart (`PATCH` existing, `POST` new) | **Done** (server cart used at checkout) |
| P0 | Checkout and COD | `POST /api/buyer/checkout/quote` → `POST /api/buyer/checkout` via `CheckoutService::placeOrder` (extracted): row locks, one-time token, four fingerprints, COD payment row | Server-total confirm dialog, then place with `expectedTotal`; 409 if it changed; e-wallet hidden in live mode | **Done** |
| P0 | Shipping fee calculation | Server only: `CheckoutCarrierService` options + rate snapshot on the token; client-sent fees rejected (422) | Shows the server total; first offered carrier per shop (the web default) | **Done** (no carrier picker in the app yet) |
| P1 | Address management | `/api/buyer/addresses` CRUD + default via `BuyerAddressBook`; rules shared through `BuyerAddressRules` | PSGC codes → `api` mode, names only → `manual`; PUT/POST verbs | **Done** (recipient/phone/label not stored server-side, §9.3) |
| P1 | Voucher application | Stateless `VoucherSessionService::forStatelessBuyer()` (same rules, explicit buyer for per-buyer limits); `GET /api/buyer/vouchers` (claimable shop vouchers); `POST`/`DELETE /cart/vouchers` | Applied codes are sent at checkout; the server applies and re-validates them; `vouchers_state` round-trips | **Done** |
| P1 | Cancel order | `POST /api/buyer/orders/{order}/shipments/{shipment}/cancel` via `BuyerOrderCancellation` (extracted); 409 + current status when stale | `saveBuyerState(cancelled)` → cancel route | **Done** |
| P1 | Returns and refunds | `POST …/return` (multipart, the web rules), `POST …/return/escalate` via `ParcelReturnService` | `LiveReturnRepository` (filed under reason "Other" with the buyer's description, §9.3) | **Done** |
| P1 | Notifications | `GET /api/buyer/notifications`, `POST …/{id}/read`, `POST …/read-all` (own notifications only) | `NotificationState(client:)`: live inbox; never shows fixture notifications in live mode | **Done** |
| P2 | Wishlist | `GET /api/buyer/wishlist`, `PUT`/`DELETE /wishlist/{product}` (idempotent) | `WishlistState(client:)`: loads from the server, optimistic toggle, reverts on refusal | **Done** (seller follows stay on the device) |
| P2 | Buyer–seller messages | `/api/buyer/messages` (list/open/show/send/image) via `ConversationPolicy`, `StoreChatMessageRequest`, `Conversation::open` | Not wired: chat stays hidden in live mode (`FeatureFlags.chatLive`) | **Backend done; mobile follow-up** |
| P1 | Shipment tracking screen | `GET /api/buyer/orders/{order}` adds `tracking` (`RouteExecutionService::trackingSummary`) and status history | Orders tab reads `/buyer/shipments` (per-seller status). The detailed `CourierTrackerScreen` timeline and the delivery-code pass still read local state | **Partly done** |
| P1 | Confirm received | existing `confirm-received` | `saveBuyerState(buyerConfirmed)` → confirm route; covered by `live_orders_test` | **Done** (mobile tests not run, §9.5) |

### 9.2 WebZefanya web code moved into services (approved; behaviour unchanged)

| From | To | Web caller now |
|---|---|---|
| `Buyer\ProductController::index/loadMore` (duplicated listing query) | `Services\Buyer\ProductCatalogQuery` | both actions |
| `Buyer\CartController` add/update/remove/bulk/apply-voucher | `Services\Buyer\CartService` (+ `Exceptions\CartItemUnavailableException`) | web cart; JSON shapes unchanged |
| `Buyer\CheckoutController` `placeOrder`, `planRoutesForOrder`, `addressFingerprint`, `addressSnapshot`, selection checks, token issue, seller notification | `Services\Buyer\CheckoutService` | web checkout (`placeOrder` kept as a private delegating seam for 2 reflection-based concurrency tests) |
| `CheckoutController::defaultShippingFees` | `CheckoutCarrierService::defaultFees` | web checkout |
| `Buyer\OrderController::cancelSellerOrder` transaction | `Services\Buyer\BuyerOrderCancellation` | web cancel (its 409/422 JSON branches unchanged) |
| `Buyer\AccountController::addressRules` | `Support\BuyerAddressRules` | web address book |
| `Buyer\NotificationController::getNotificationTitle` | `Support\BuyerNotificationTitle` | web bell |
| `VoucherSessionService` (session only) | + `forStatelessBuyer()` / `appliedState()`; the session path is untouched | — |

No migration, status rule, financial rule, COD/voucher/commission logic or auth policy changed. **Security fix along the way:** `VoucherSessionService::rejectionReason` read `auth('buyer')->id()`, which is null for a bearer token and would have skipped the per-buyer usage limit. The stateless copy carries the buyer id (test: `test_per_buyer_voucher_limit_applies_without_a_web_session`).

### 9.3 New backend/mobile conflicts (documented, not "fixed" on the server)

- **Shipping fee:** the app's checkout shows a flat local fee (`kStandardShippingFee`); WebZefanya prices per shop by carrier and route. Live mode now shows the server total in a confirm dialog and places only that total. The on-screen breakdown still shows local figures until the checkout screen renders the quote. Mobile follow-up.
- **Carrier choice:** the app has no carrier picker, so it uses the first offered carrier per shop (the web page's default selection).
- **Addresses:** WebZefanya stores no per-address recipient, phone, label, region or map pin (orders use the buyer profile's name and phone). The app does not send them, and they come back empty. A server change would alter the schema, so it is left alone.
- **Returns:** the app has no reason picker; requests use the server's "Other" reason plus the buyer's own description. Escalation sends a fixed sentence. Add a reason picker and an escalation text field in the app.
- **Seller follows** (`WishlistState.toggleFollow`) stay on the device: `StoreFollowService` exists, but there is no API for it yet.
- **Maker notes / promo slides:** WebZefanya has neither, so the live app shows none and makes no request.

### 9.4 Security (buyer commerce)

Ownership is enforced and tested for cart lines, addresses, orders (BuyerOrderPolicy answers 404, hiding existence), messages (ConversationPolicy 403), notifications (404 for someone else's) and checkout (another buyer's line id answers 409). Price/COD tampering: unit prices, totals and shipping fees in requests are ignored or refused (422), and the COD `payments.amount_minor` equals the server-quoted payable total (test). Duplicate submit: the one-time checkout token means a replay answers 409 and one order exists (test). Stale state: a price change after review answers 409, with no order and no stock change (test); cancelling an accepted or cancelled shipment answers 409 with the current status (test). Rate limits: catalog 120/min, buyer reads 60/min, writes 20/min, place order 10/min, messages 30/min. Uploads: return photos and chat images use the web rules (JPG/PNG ≤ 5 MB) and stay on the private disk; chat images are served only through the bearer route.

### 9.5 Tests (second pass)

| Run | Result |
|---|---|
| New `tests/Feature/Buyer/BuyerCommerceApiTest.php` | 20 passed |
| All mobile-API tests (buyer commerce + shipments + rider + API auth) | 69 passed, 451 assertions |
| Web catalog-listing suites after the `ProductCatalogQuery` extraction | 726 passed |
| Web cart/voucher/address suites after the `CartService` extraction | 899 passed, 1 skipped (pre-existing) |
| Web checkout/order suites after the `CheckoutService` extraction | 724 passed, 1 skipped (pre-existing) |
| Web cancellation suites after the `BuyerOrderCancellation` extraction | 650 passed |
| Full WebZefanya suite (Feature + Unit) | 3684/3685 passed, 1 pre-existing skip, after the extractions. A later re-run reported 6 seller-media failures; they came from a second test process running at the same time and writing the same fake storage disk. Re-run alone, `tests/Feature/Seller` passed 721/721 |
| Pint | passed |
| Mobile `flutter analyze` / `flutter test` | **Not run: no Flutter SDK on this machine.** Updated tests: `live_catalog_test`, `live_orders_test`, `live_address_test`, `workbook_buyer_phase3_test` (logged in `docs/DECISIONS.md`) |

### 9.6 Remaining

1. Run `flutter analyze` and `flutter test`. All mobile changes in both passes are unverified until then.
2. Checkout screen: render the server quote (per-shop shipping, voucher lines, carrier picker) instead of the local breakdown.
3. Buyer tracker: read `GET /buyer/orders/{order}` (`tracking`, `history`) and show the server `delivery_code` on the delivery pass.
4. Messages UI in live mode against `/buyer/messages`; return reason picker; seller-follow API.
5. Still open from §8: buyer registration API, rider registration alignment, rider pickup-failure extraction (same approach as cancel), wind-down rider policy, token lifetime/refresh, bearer rider incidents.
6. Nothing is committed. WebZefanya's pre-existing untracked `Api/Buyer/OrderController.php` is now routed and relied on; commit it together with this work.

## 10. Third pass — Rider mobile (2026-11-07)

Scope: the rider list (registration, incidents, earnings, notifications, token renewal, wind-down, job history). The mobile rider screens and flows were kept; only their data source changed to WebZefanya.

### 10.1 Rider status after this pass

| Feature | WebZefanya (server) | Mobile | Status |
|---|---|---|---|
| Registration | `POST /api/rider/register` (existing), new public `GET /api/rider/providers` (approved, not archived: the web register page's list) | `toRiderFields` sends `StoreRiderRegistrationRequest` names (`license_no`, PSGC codes + `address_mode=api`, `logistics_provider_id`, `terms`); parts `drivers_license` + `or_cr`; company picker and server vehicle list in live mode; code confirmed at the signed `verify_url`, resend at `resend_url` (re-based on the app's API host) | Wired |
| Incidents | New bearer `POST /api/rider/jobs/{id}/incidents` → `ParcelIncidentService::report` (`StoreIncidentApiRequest` only swaps the guard check). Session route unchanged | "Report a problem" on the delivery tracker (lost/damaged, reason, description, photo for damage) | Wired |
| Earnings | `GET /api/rider/earnings` adds `totals` (today/week/all-time sums of `RiderEarning`, store timezone), `?limit`, seller name | Earnings tab reads server totals and ledger rows; pull to refresh; refreshed after each job read | Wired; no local pay maths in live mode |
| Notifications | New `GET /api/rider/notifications`, `POST …/{id}/read`, `POST …/read-all` (own rows only; web URL not exposed) | Bell on the For Delivery tab; the shared inbox switches to `/rider` | Wired |
| Token renewal | New `POST /api/rider/token/refresh`: rotates, same ability and lifetime | `ApiClient` renews within 10 min of expiry (or once after restart) | Wired (riders only) |
| Wind-down | `api.role:rider,wind-down` on the web's allow-list routes; login accepts wind-down riders; jobs show no pickups while winding down | Refusals arrive as 403 with the server's wind-down message | Matches web |
| Job history | New `GET /api/rider/history` (`deliveryHistory`, buyer contact hidden, credited earning per parcel) | Not shown separately: the Earnings ledger is the app's history list | API only |
| Compile/test Flutter | — | — | **Not done:** no Flutter SDK here |

### 10.2 Tests

- WebZefanya: new `tests/Feature/Rider/RiderMobileOpsApiTest.php`, 9 tests, 64 assertions. It covers:
  - wind-down allow and deny
  - suspended without a wind-down still refused
  - token rotation; expired and buyer tokens refused
  - incident ownership, photo and duplicate checks
  - notification scoping
  - history
  - earnings totals
  - provider filter
- Rider, API-auth and incident suites: 346/346. Full WebZefanya suite: 3695/3696 passed, 1 skipped (the same pre-existing skip as before).
- Mobile: see `DECISIONS.md` (Rider mobile second pass). Not run.

### 10.3 Remaining

1. Run `flutter analyze` and `flutter test` (both passes).
2. Rider pickup failure still needs `PickupAssignmentsController::reportFailure` extracted to a service.
3. Return pickups and pickup-session finish are not in the API.
4. Buyer token renewal was not requested; buyers still re-login after 60 minutes.
5. The rider registration signed URLs last 1 hour. After that, `POST /api/rider/registration/resume` (email + password) issues new ones; the app does not call it yet.

### 10.4 Rider registration alignment and OTP recovery (2026-11-07)

- **Fields:** the mobile rider form now asks for the web rider form's fields (no sex or middle initial; optional house number and landmark added). The web UI is unchanged. Vehicle type is Motorcycle only.
- **Company by address:** `GET /api/rider/providers?barangay=` narrows the list to companies whose validated coverage serves that barangay (`LogisticsHubService::areaForBarangay`). The app has a "Show all" fallback. WebZefanya test: `test_provider_list_narrows_to_companies_covering_the_barangay`; `RiderMobileOpsApiTest` passes 10/10.
- **OTP:**
  - Signed `verify_url` / `resend_url` are posted unchanged.
  - An expired or invalid link is renewed through the existing `POST /api/rider/registration/resume`, after a password prompt; the stored links and their expiry are replaced.
  - A 429 starts a cooldown of the server's `retry_after`.
  - Separate messages for: expired link, wrong code, cooldown, network failure, and success.
- **Not verified:** the mobile tests (Flutter SDK unavailable). The live URL scheme of signed links could not be checked: zefanya.shop answered 502 and the local `php artisan serve` was not running. If the server issues `http://` links behind the tunnel, `TRUSTED_PROXIES` must cover the tunnel connector. That is deployment configuration, not code.
