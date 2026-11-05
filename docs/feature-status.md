# Feature status — Zefanya mobile (S1-lite 1.1)

Branch: `feature/s1-lite` (cut from `integration/hardened` @ 6a9605a)
Date: 2026-11-05
Applies to: the Flutter buyer/rider app under `mobile/`. Web (Seller/Logistics/Admin) routes are referenced only as the backend surface.

## Method

Every cell below comes from a file or a command; otherwise it is marked UNVERIFIED.

- Screens: `grep class ...Screen` over `mobile/lib` — 35 screen classes (incl. `_BuyerHomeScreen`). Each was checked for navigation references (`AppPageRoute(...)`, `Navigator.push`, buyer shell tabs). All 35 are referenced by at least one other file ⇒ **no UNREACHABLE screens**.
- Mock/live boundary: `kUseLiveApi` is defined in `mobile/lib/config/dev_flags.dart` (default `false` = mock), resolved by `Repositories.forMode` in `mobile/lib/data/repositories/repositories.dart`, and consumed at the cart/checkout voucher UI (`show: !kUseLiveApi`), the login "Forgot password?" link (`!kUseLiveApi`), and `isApiConfigured`.
- Backend surface: `php artisan route:list --json` = 173 routes (`web/routes/api.php`, `web/routes/web.php` plus framework/vendor); `docs/api-contract.md`.
- Tests: web feature tests (405 `test_*` methods across `web/tests/Feature`); mobile widget tests under `mobile/test` (e.g. `auth_flow_test.dart`, `address_book_test.dart`, `cart_*.dart`, `catalog_state_test.dart`, `checkout_validation_test.dart`, `courier_flow_test.dart`, `delivery_pass_*.dart`).

STATUS values: LIVE-READY | MOCK-ONLY | BACKEND-ONLY | UNREACHABLE | NEEDS-CREDENTIALS | STUB

## Feature truth table

| # | Feature | Screen files | REACHABLE | MOCK WORKS | BACKEND | LIVE WIRED | LIVE TEST | DEVICE/CREDENTIALS NEEDED | STATUS |
|---|---------|--------------|-----------|------------|---------|------------|-----------|---------------------------|--------|
| 1 | Buyer registration | `registration_screen.dart`, `otp_screen.dart`, `pending_approval_screen.dart` | Yes — launch → Register → Buyer → OTP | Yes — mock OTP `123456` (`kMockOtpCode`, `dev_flags.dart`); dev-tools "Simulate Approval (Test)" button; widget tests exist (`auth_flow_test.dart`, coverage UNVERIFIED) | `POST /api/register/buyer`, `POST /api/register/buyer/verify-otp/{buyer}`, `/resend` | `LiveAuthRepository`; new buyers land on the pending-approval gate until an admin approves | `BuyerRegistrationApiTest`, `BuyerOtpApiTest` | Approved buyer account + OTP (email inbox) | LIVE-READY |
| 2 | Rider application | (web) `register/logistics` — no dedicated mobile rider-apply screen | Yes, on web `/register/logistics` | None on mobile | `POST /api/register/logistics`, `POST /api/v1/courier/register` | `CourierRegistrationController` | `CourierRegistrationApiTest`, `LogisticsRegistrationTest` | Admin approval | BACKEND-ONLY |
| 3 | Login | `login_screen.dart` | Yes — app launch | Yes (mock credentials) | `POST /api/login/buyer|courier|logistics`, `/api/v1/*`, `POST /api/logout` | `LiveAuthRepository`; token in `flutter_secure_storage` | `BuyerLoginApiTest`, `CourierLoginApiTest`, `LogisticsLoginFlowTest` | Role credentials | LIVE-READY |
| 4 | Google sign-in | `login_screen.dart` (Google button) | Button shown only when `kGoogleServerClientId` is set | Yes — `MockGoogleSignInService` (`kMockGoogleEmail`) | `POST /api/auth/google`, `POST /api/v1/auth/google` | `NativeGoogleSignInService`; button hidden + debug log when client ID missing | `GoogleAuthApiTest` | Google web client ID (= server client ID) + Android SHA-1 (debug and release differ); test users while consent screen is in Testing | LIVE-READY (config-gated; hidden by default) |
| 5 | Address book | `address_book_screen.dart`, `address_form_screen.dart` | Yes — Profile → Addresses | Yes (`MockAddressRepository`; `address_book_test.dart`) | `GET/POST /api/buyer/addresses`, show/update/destroy/default, `/v1` duplicates | `LiveAddressRepository` | `BuyerAddressApiTest` | None | LIVE-READY |
| 6 | Browse and categories | `categories_screen.dart`, `category_products_screen.dart` | Yes | Yes (`MockCatalogRepository`; `catalog_state_test.dart`) | `GET /api/categories`, `GET /api/products` (+ `/v1`) | `LiveCatalogRepository` | `CategoryApiTest`, `ProductCatalogApiTest` | None | LIVE-READY |
| 7 | Search and filters | `search_screen.dart` | Yes — Home search bar and Categories search field | Yes (mock catalog filtered locally; server-side search UNVERIFIED) | `GET /api/products` (search params UNVERIFIED) | `LiveCatalogRepository` | `ProductCatalogApiTest` | None | PARTIAL |
| 8 | Product detail and variants | `product_details_screen.dart` | Yes | Yes | `GET /api/products/{product}` (+ `/v1`) | `LiveCatalogRepository` | `ProductCatalogApiTest` | None | LIVE-READY |
| 9 | Wishlist | `saved_screen.dart` | Yes | Yes | `POST/DELETE /api/buyer/wishlist/{product}` (+ `/v1`) | — | `WishlistApiTest` | None | LIVE-READY (`docs/KNOWN_LIMITATIONS.md` claims wishlist/shortlist data is local state despite existing wishlist routes — UNVERIFIED conflict) |
| 10 | Cart | `cart_screen.dart` | Yes — nav bar badge | Yes (`MockCartRepository`; `cart_*` widget tests) | None — no cart routes on either surface | None (device-local persisted state; "cart survives an app kill") | None | None | MOCK-ONLY |
| 11 | Checkout COD | `checkout_screen.dart` | Yes | Yes (`checkout_validation_test.dart`; `OrderPlacerTest` server side) | `POST /api/v1/buyer/orders` | `LiveOrderRepository`; app charges ₱60 shipping (free at ₱1,000) but the server total has no shipping term (`docs/api-contract.md` §0.1) | `PlaceOrderApiTest` | None | LIVE-READY (shipping/price divergence documented) |
| 12 | Checkout e-wallet | `checkout_screen.dart` | Yes | Yes in mock | None — no payment gateway route | None | None | None | LIMITATION |
| 13 | Vouchers | `vouchers_screen.dart`, cart/checkout voucher UI | Yes | Yes (wallet claim + apply; `VoucherApiTest`) | `GET /api/vouchers`; cart voucher apply/remove; `POST /orders` accepts one `voucher_code` | Voucher UI hidden in live mode (`show: !kUseLiveApi`); server records one code only | `VoucherApiTest` | None | MOCK-ONLY (live mode hides voucher entry; stacking is local-only — `docs/api-contract.md` §0.2) |
| 14 | Write and read reviews | `review_screen.dart`, `order_detail_screen.dart` | Yes | Yes (`MockReviewRepository`) | `PATCH /api/orders/{order}/items/{product}/review` (+ `/v1`), `GET /api/reviews/mine` | `LiveReviewRepository` | `ReviewApiTest`, `ReviewIntegrityTest` | Completed order | LIVE-READY (spec rules — completed order, within 30 days, one per item, 1–5 stars, text ≤ 500 — server-side enforcement UNVERIFIED) |
| 15 | Order tabs | `orders_status_screen.dart` (buyer shell Orders tab) | Yes | Yes (`MockOrderRepository`) | `GET /api/v1/buyer/orders` (+ `/v1`) | `LiveOrderRepository` | `OrderApiTest` | None | LIVE-READY |
| 16 | Order detail and tracking | `order_detail_screen.dart`, `delivery_tracker_screen.dart`, `courier_tracker_screen.dart` | Yes | Yes | `GET/PATCH /api/orders/{order}/milestone` (+ `/v1`) | `LiveOrderRepository`; `GET /orders/{sellerOrder}/tracking` NOT implemented (map hidden — `docs/KNOWN_LIMITATIONS.md`) | `OrderApiTest`, `CourierHandoverApiTest` | None | PARTIAL |
| 17 | Receive-with-QR | delivery pass in buyer order detail; courier scan | Yes | Yes (`delivery_pass_*` widget tests) | `POST /api/orders/{order}/delivery-pass`, `GET /api/orders/{order}/delivery-proof` | Code + signed token server-side; buyer QR generated locally on the phone (not server-signed — `docs/KNOWN_LIMITATIONS.md`) | H4 delivery-pass/proof tests (8 cases) | Buyer with an `OUT_FOR_DELIVERY` order | LIVE-READY |
| 18 | Rider job feed | courier shell screens | Yes | Yes (`MockCourierRepository`; `courier_flow_test.dart`) | `GET /api/v1/courier/jobs` | `LiveCourierRepository` | `CourierHandoverApiTest` | Rider area | LIVE-READY |
| 19 | Accept or decline | None — no accept/decline UI today | No | None | No rider accept endpoint exists; assignment is server-side | None | None | None | MISSING |
| 20 | Pickup scan | `courier_qr_scanner.dart`, `pickup_dashboard_screen.dart` | Yes (scanner screen) | Yes (`courier_qr_scanner_test.dart`) | No waybill endpoint on the current route list | None | None | Waybill number | MISSING (scanner works locally; `GET /seller/orders/{order}/waybill` not built — S7) |
| 21 | Delivery handover | `delivery_tracker_screen.dart` | Yes | Yes (`delivery_pass_handover_test.dart` et al.) | `PATCH /api/courier/jobs/{order}/milestone` | Milestone updates fire-and-forget; live failure swallowed (stale tracker possible — `docs/KNOWN_LIMITATIONS.md`) | `CourierHandoverApiTest`, `DeliveryHandoverSecurityTest` | Buyer's 6-digit code | PARTIAL (server-side issuance of the buyer's 6-digit code not verified) |

| 22 | Failed delivery | rider failure-flow screens | Yes | Yes (`courier_failure_flow_test.dart`) | Milestone transitions (`ALLOWED_TRANSITIONS`) | Reschedule / return-to-sender endpoints not built (S7) | None | None | PARTIAL |
| 23 | Earnings | `courier_profit_screen.dart` | Yes | Yes (local mock state) | No earnings endpoint on the route list | None | None | None | MOCK-ONLY (rates ₱50/₱30/₱0 local; COD remittance not reconciled — `docs/KNOWN_LIMITATIONS.md`) |
| 24 | Rider profile and documents | `rider_vehicle_screen.dart` | Yes | Yes | No rider-document routes on the current route list | None | None | Plate number unique (case-insensitive) | PARTIAL (signed rider-doc URLs with 5-minute expiry not verified — loophole report 14 OPEN) |
| 25 | Avatar and profile edit | `edit_profile_screen.dart`, `profile_screen.dart` | Yes | Yes | `POST /api/account/email`, `/password`, `/phone` (+ verify) | `LiveAccountSecurityRepository` | `AccountSecurityApiTest` | None | PARTIAL (profile fields live; avatar upload pipeline — server re-encode/EXIF-strip not implemented — loophole report 10 OPEN) |
| 26 | Account & Security | `account_security_screen.dart`, `change_password_screen.dart`, `change_contact_screen.dart` | Yes | Yes | `POST /api/account/password`, `/email`, `/phone` (+ verify) | — | `AccountSecurityApiTest` | None | LIVE-READY (password change revoking other sessions is OPEN — loophole report 9) |
| 27 | Chat | `chat_screen.dart`; web `buyer/chat`, `seller/chat` | Yes in mock; hidden in live mode | Yes (`MockChatRepository` fixtures) | No backend chat routes/controllers | None | None | None | LIMITATION |
| 28 | Returns | `return_screen.dart` | Yes | Yes (`MockReturnRepository`) | `GET /returns`, `POST /orders/{sellerOrder}/returns` notWired | None | None | None | LIMITATION |
| 29 | Notifications bell | `notifications_screen.dart` | Yes | Yes (fixture list; badge is local) | No notifications endpoint | None | None | None | LIMITATION |
| 30 | Forgot password | `login_screen` ("Forgot password?") | Hidden in live mode | Visible in mock but a dead end | No route | None | None | None | MISSING |
| 31 | Theme toggle | Settings Appearance (Light/Dark/System) | Yes | Yes (local preference) | None | None | None | None | MOCK-ONLY |
| 32 | Seller screens | `seller_screen.dart`; web `seller/*` | Yes (mobile seller screen) | Yes (mobile) | `seller/orders` confirm/ready, products, shipments, vouchers (web routes) | Web seller controllers | `SellerDashboardHeroTest`, `SellerPanelFixesTest` | Seller credentials | PARTIAL |
| 33 | Admin screens | — (web only) | Yes, on web `/admin/*` | n/a | Admin dashboard, registrations approve/reject | — | `AdminApprovalFlowTest` | Admin credentials | WEB-ONLY |
| 34 | Splash and intro | `landing_screen.dart` | Yes — app launch | Yes (intro gate; Begin only on the last slide, no Skip) | None | None | None | None | LIVE-READY |
| 35 | Loading states | `ZfLoader` (app-wide) | Yes | Yes | None | None | None | None | LIVE-READY |

## Counts per STATUS

| STATUS | Count | Features |
|--------|-------|----------|
| LIVE-READY | 15 | buyer registration, login, Google sign-in, address book, browse and categories, product detail and variants, wishlist, checkout COD, write and read reviews, order tabs, receive-with-QR, rider job feed, Account & Security, splash and intro, loading states |
| MOCK-ONLY | 4 | cart, vouchers, earnings, theme toggle |
| PARTIAL | 7 | search and filters, order detail and tracking, delivery handover, failed delivery, rider profile and documents, avatar and profile edit, seller screens |
| LIMITATION | 4 | checkout e-wallet, chat, returns, notifications bell |
| BACKEND-ONLY | 1 | rider application |
| WEB-ONLY | 1 | admin screens |
| MISSING | 3 | accept or decline, pickup scan, forgot password |
| UNREACHABLE | 0 | — |
| **Total** | **35** | |

## Mock vs live — where the two behave differently

1. `kUseLiveApi` defaults to `false`; a live build requires `--dart-define=zefanya.useLiveApi=true --dart-define=zefanya.apiBaseUrl=https://...`.
2. Voucher entry is hidden in live mode (`VoucherUiVisibility(show: !kUseLiveApi)`); the server accepts exactly one `voucher_code` on `POST /orders`, so the app's stacked local savings are not recorded server-side.
3. "Forgot password?" is hidden in live mode (no forgot-password route exists).
4. The Google button is hidden when `kGoogleServerClientId` is empty, with the reason logged in debug — never a button that cannot work.
5. Chat icons are hidden in live mode (`FeatureFlags.chatLive = false`); mock mode keeps a local-only inbox.
6. Two of the nine loads in `main()` fetch over the network and have no endpoint yet, so a live build that defaulted to live would reach no first frame — turning live on is a deliberate act (`dev_flags.dart`).
7. The delivery-pass QR is generated on the phone from the 6-digit code (proves phone-to-phone exchange only); the server verifies the handover.
8. Milestone updates are fire-and-forget in live mode; a live endpoint failure leaves the buyer's tracker stale until the next successful sync.

## Proposed live-versus-mock demo split

**Demo-mock (default build, no network needed) — recommended for the presentation.** Full buyer journey: register → OTP → (admin approves) → login → browse → search → product detail → variants → cart → checkout COD → order tabs → tracking timeline → receive-with-QR; plus the rider job feed and delivery handover in mock. Everything marked LIVE-READY above works, and mock fills the gaps (cart, vouchers, earnings, chat, notifications).

**Demo-live (`--dart-define=zefanya.useLiveApi=true`).** Only the LIVE-READY buyer features with real backend routes (registration, login, address book, catalogue/products, wishlist, COD order placement, order tabs) are honest against the server. Everything else degrades: vouchers, chat, forgot password and the bell are hidden; rider accept/decline, pickup scan and reschedule/return are not built. Not recommended as the sole demo build until `POST /api/v1/buyer/checkout/quote`, the cancel and returns endpoints, and the missing buyer/courier endpoints land (S2/S6).

This document is read-only except for docs (S1 scope). REVIEW STOP 1: send this file (counts and demo split) plus `docs/spec-conformance.md` (summary part).

