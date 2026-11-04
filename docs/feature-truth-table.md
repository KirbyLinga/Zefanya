# Feature Truth Table — WO-0

Branch: `integration/hardened`
HEAD: `c799035`
Date: 2026-11-04
Method: every cell sourced from a file or a command. `UNVERIFIED` = cannot be confirmed without running the app on a device/emulator.

## Navigation model (from source)

- Single-route app: `AuthGate` (`lib/main.dart`) resolves `LandingScreen` (first launch) → `LoginScreen` → `AuthGate` again → `BuyerShell` / `CourierShell`.
- `BuyerShell` (`lib/screens/buyer/buyer_shell.dart`) tabs: Home (0), Search (1), Categories (2), Orders (3), Profile (4) — an `IndexedStack`, all tabs stay mounted.
- Home taps product → `ProductDetailsScreen`; cart button → `CartScreen`; search bar → Search tab; category chip → `CategoryProductsScreen`.
- Orders tab → `OrdersStatusScreen`; tap order → `OrderDetailScreen`.
- Profile → `EditProfileScreen`, `AccountSecurityScreen`, `AddressBookScreen`.
- Courier shell → `PickupDashboardScreen`, `DeliveryDashboardScreen`, `CourierProfitScreen`, `DevQRGeneratorScreen` (dev only).
- Screen transitions use `AppPageRoute` (`lib/widgets/app_page_route.dart`); `popUntil(route.isFirst)` returns to `AuthGate`.

## Live vs mock wiring (from `lib/data/repositories/`)

| Repository | Backend calls (live) | notWired / mock-only |
|---|---|---|
| `auth_repository.dart` | login, register, verifyOtp, logout, refresh | — |
| `catalog_repository.dart` | products, productById, categories, productsInCategory, makerNote, promoSlides | — |
| `order_repository.dart` | orders (GET), place (POST /orders) | `saveBuyerState` (notWired) |
| `cart_repository.dart` | vouchers (GET /vouchers) | cart lines are device state (no server cart endpoint) |
| `chat_repository.dart` | messagesFor (GET), append (POST) | `threads()` returns empty (no list endpoint) |
| `courier_repository.dart` | assignedJobs (GET /courier/jobs), advanceMilestone (PATCH milestone) | — |
| `review_repository.dart` | all (GET /reviews/mine), submit (PATCH review) | — |
| `address_repository.dart` | all CRUD (GET/POST/PATCH/DELETE /buyer/addresses) | — |
| `account_security_repository.dart` | changePassword, request/confirm email & phone | — |
| `google_auth_repository.dart` | register (POST /auth/google multipart) | `signIn` — not wired (no LiveGoogleAuthRepository.signIn) |
| `return_repository.dart` | — | all three methods notWired |

Route data source: `php artisan route:list --json` (173 routes: 81 API + 54 web + 22 seller + 12 admin + 4 framework/vendor).

## Truth table

| # | Feature | Screen file(s) | REACHABLE | MOCK WORKS | BACKEND | LIVE WIRED | LIVE TEST | DEVICE/CREDENTIALS | STATUS |
|---|---------|---|---|---|---|---|---|---|---|
| 1 | Buyer registration | `lib/screens/auth/registration_screen.dart` | Landing → Login → Register | `registration_flow_test.dart` | `POST /api/v1/buyer.register` → `RegisterBuyerController@store` | yes (`AuthRepository.register`) | `live_auth_test.dart` | yes: email + password (+ID for courier) | LIVE-READY |
| 2 | Rider application | `lib/screens/courier/rider_vehicle_screen.dart` | Landing → Login → Register as courier | `registration_flow_test.dart` | `POST /api/v1/courier.register` → `CourierRegistrationController@store` | yes (`AuthRepository.register` multipart) | `live_auth_test.dart` | yes: ID + vehicle docs | LIVE-READY |
| 3 | Login | `lib/screens/auth/login_screen.dart` | Landing → Login | `auth_flow_test.dart`, `login_gate_test.dart` | `POST /api/v1/buyer.login` → `LoginController@store` | yes (`AuthRepository.login`) | `live_auth_test.dart` | yes: registered credentials | LIVE-READY |
| 4 | Google sign-in | `lib/screens/auth/login_screen.dart` | Landing/Login → "Continue with Google" | `live_google_auth_test.dart` | `POST /api/v1/auth/google` → `GoogleAuthController@handle` | PARTIAL: register wired; signIn NOT wired | `live_google_auth_test.dart` | yes: GOOGLE_SERVER_CLIENT_ID + Play Services | BACKEND-ONLY (signIn) |
| 5 | Address book | `lib/screens/buyer/address_book_screen.dart`, `address_form_screen.dart` | Profile → Addresses | `address_book_test.dart`, `live_address_test.dart` | `GET/POST/PATCH/DELETE /api/v1/buyer/addresses` → `AddressController` | yes (`AddressRepository`) | `live_address_test.dart` | no | LIVE-READY |
| 6 | Browse page | `lib/screens/buyer/buyer_shell.dart` (`_BuyerHomeScreen`) | Home tab (default) | `featured_deck_test.dart`, `phase_e_home_test.dart` | `GET /api/v1/products` → `ProductController@index` | yes (`CatalogRepository.products`) | `live_catalog_test.dart` | no | LIVE-READY |
| 7 | Category pages | `lib/screens/buyer/categories_screen.dart`, `category_products_screen.dart` | Home → "See all categories"; Categories tab | `phase_e_home_test.dart` | `GET /api/v1/categories` → `CategoryController@index` | yes (`CatalogRepository.categories`) | `live_catalog_test.dart` | no | LIVE-READY |
| 8 | Search and filters | `lib/screens/buyer/search_screen.dart` | Home search bar → Search tab | `search_screen_chips_test.dart`, `search_filter_test.dart` | `GET /api/v1/products?q=` → `ProductController@index` | yes (`CatalogRepository.products` with query) | `live_catalog_test.dart` | no | LIVE-READY |
| 9 | Product detail and variants | `lib/screens/buyer/product_details_screen.dart` | Home/Category → tap product | `product_gallery_test.dart`, `product_variants_test.dart` | `GET /api/v1/products/{product}` → `ProductController@show` | yes (`CatalogRepository.productById`) | `live_catalog_test.dart` | no | LIVE-READY |
| 10 | Wishlist | `lib/screens/buyer/saved_screen.dart` | Profile → Saved | none | `GET/POST/DELETE /api/v1/buyer/wishlist` → `WishlistController` | NO — no `WishlistRepository` | none | no | MOCK-ONLY |
| 11 | Cart | `lib/screens/buyer/cart_screen.dart` | Home → cart badge | `task5_cart_nav_test.dart`, `cart_swipe_test.dart` | No cart endpoint (lines device state); only `GET /vouchers` | PARTIAL: vouchers wired; lines device state | `live_catalog_test.dart` (vouchers) | no | MOCK-ONLY (lines) |
| 12 | Checkout (COD) | `lib/screens/buyer/checkout_screen.dart` | Cart → Place Order | `checkout_validation_test.dart` | `POST /api/v1/buyer/orders` → `OrderController@store` | yes (`OrderRepository.place`) | `live_orders_test.dart` | yes: cart + address | LIVE-READY |
| 13 | Checkout (e-wallet) | `lib/screens/buyer/checkout_screen.dart` | Cart → Place Order → e-wallet | `checkout_validation_test.dart` | No e-wallet endpoint | no | none | no | MOCK-ONLY |
| 14 | Vouchers | `lib/screens/buyer/vouchers_screen.dart`, `widgets/promo_carousel.dart` | Cart → apply voucher; Home carousel | `voucher_expiry_test.dart` | `GET /api/v1/vouchers` → `VoucherController@index` | yes (`CartRepository.vouchers`) | `live_catalog_test.dart` | no | LIVE-READY |
| 15 | Write review | `lib/screens/buyer/review_screen.dart` | Order detail → "Write review" | `review_flow_test.dart`, `review_limits_test.dart` | `PATCH /api/v1/buyer/orders/{id}/items/{pid}/review` → `ReviewController@upsert` | yes (`ReviewRepository.submit`) | `live_review_test.dart` | yes: delivered order | LIVE-READY |
| 16 | Read reviews | `lib/screens/buyer/product_details_screen.dart` | Product detail → reviews | `review_flow_test.dart` | `GET /api/v1/reviews/mine` → `ReviewController@index` | yes (`ReviewRepository.all`) | `live_review_test.dart` | no | LIVE-READY |
| 17 | Order list tabs | `lib/screens/buyer/orders_status_screen.dart` | Orders tab | `order_lifecycle_test.dart` | `GET /api/v1/buyer/orders` → `OrderController@index` | yes (`OrderRepository.orders`) | `live_orders_test.dart` | no | LIVE-READY |
| 18 | Order detail and tracking | `lib/screens/buyer/order_detail_screen.dart`, `courier_tracker_screen.dart` | Orders → tap order | `delivery_pass_test.dart` | `GET /api/v1/buyer/orders/{id}/milestone` → `OrderController@milestone` | PARTIAL: route exists; no mobile milestone fetch | `live_orders_test.dart` | yes: placed order | PARTIAL |
| 19 | Receive-with-QR | `lib/screens/buyer/courier_tracker_screen.dart` | Order detail → tracking QR | `delivery_pass_test.dart` | `POST/GET /api/v1/buyer/orders/{id}/delivery-pass`, `delivery-proof` | NO — no mobile delivery repository | `delivery_pass_test.dart` | yes: out-for-delivery order | BACKEND-ONLY |
| 20 | Rider job feed | `lib/screens/courier/pickup_dashboard_screen.dart` | Courier shell → Jobs | `courier_flow_test.dart` | `GET /api/v1/courier/jobs` → `CourierJobController@index` | yes (`CourierRepository.assignedJobs`) | `live_courier_test.dart` | yes: approved courier | LIVE-READY |
| 21 | Accept or decline | `lib/screens/courier/pickup_dashboard_screen.dart` | Job feed → accept/decline | `courier_flow_test.dart` | No accept/decline endpoint | no | none | yes: assigned job | MOCK-ONLY |
| 22 | Pickup scan | `lib/screens/courier/pickup_dashboard_screen.dart` | Job → mark picked up | `courier_handover_wiring_test.dart`, `pickup_verification_test.dart` | `PATCH /api/v1/courier/jobs/{id}/milestone` → `CourierMilestoneController` | yes (`CourierRepository.advanceMilestone`) | `live_courier_test.dart` | yes: READY_FOR_PICKUP job | LIVE-READY |
| 23 | Delivery handover | `lib/screens/courier/delivery_dashboard_screen.dart` | Job → mark delivered + OTP + photo | `delivery_pass_handover_test.dart` | `PATCH /api/v1/courier/jobs/{id}/milestone` (DELIVERED) | yes (`CourierRepository.advanceMilestone`) | `live_courier_test.dart` | yes: out-for-delivery job | LIVE-READY |
| 24 | Failed delivery | `lib/screens/courier/delivery_dashboard_screen.dart` | Job → mark failed + reason | `courier_failure_flow_test.dart` | `PATCH /api/v1/courier/jobs/{id}/milestone` (DELIVERY_FAILED) | yes (`CourierRepository.advanceMilestone`) | `live_courier_test.dart` | yes: out-for-delivery job | LIVE-READY |
| 25 | Earnings | `lib/screens/courier/courier_profit_screen.dart` | Courier shell → Earnings | `courier_flow_test.dart` | No earnings endpoint | no | none | yes: completed jobs | MOCK-ONLY |
| 26 | Rider profile and documents | `lib/screens/courier/rider_vehicle_screen.dart` | Courier registration → vehicle/documents | `courier_flow_test.dart` | No document upload endpoint; rider fields on `logistics_providers` table | no | none | yes: ID + license + OR/CR photos | MOCK-ONLY |
| 27 | Avatar and profile edit | `lib/screens/shared/edit_profile_screen.dart`, `lib/screens/shared/profile_screen.dart` | Profile → Edit Profile; Profile → avatar badge | `account_details_visibility_test.dart` | No avatar upload endpoint; profile fields in `AuthState` (shared_preferences), not server | no | none | no | MOCK-ONLY |
| 28 | Account & Security | `lib/screens/shared/account_security_screen.dart`, `change_password_screen.dart`, `change_contact_screen.dart` | Profile → Account & Security | `account_security_test.dart`, `live_address_test.dart` | `POST /account/password`, `/account/email`, `/account/phone` → `AccountSecurityController` | yes (`AccountSecurityRepository`) | none (live) | yes: signed-in buyer | LIVE-READY |
| 29 | Chat | `lib/screens/shared/chat_screen.dart` | Product page → seller card chat icon | `live_chat_test.dart`, `remote_chat_test.dart` | `GET/POST /chat/{contact}` → `Buyer\ChatController` | PARTIAL: messages wired; `threads()` returns empty (no inbox list endpoint) | `live_chat_test.dart` | yes: seller contact | PARTIAL |
| 30 | Returns | `lib/screens/buyer/return_screen.dart` | Order detail → request return | `returns_test.dart` | No returns route | NO — all three methods notWired | `returns_test.dart` | yes: delivered order | MOCK-ONLY |
| 31 | Notifications bell | `lib/screens/buyer/notifications_screen.dart`, `widgets/notification_bell.dart` | Home header → bell icon | none | No notifications endpoint | no | none | no | MOCK-ONLY (hidden in live mode until server provides notifications) |
| 32 | Forgot password | `lib/screens/auth/login_screen.dart` ("Forgot password?" link) | Login → "Forgot password?" | `login_gate_test.dart` | No forgot-password route | no | none | yes: registered email | UNREACHABLE (hidden in live mode via `!kUseLiveApi`) |
| 33 | Theme toggle | Settings → Appearance (only place for toggle per WO-4) | Profile → Settings → Appearance | `theme_contrast_test.dart` | No theme endpoint | no | none | no | MOCK-ONLY |
| 34 | Seller screens | `lib/screens/buyer/seller_screen.dart`; web `routes/seller.php` | Product page → seller card → seller screen | none | Web: `seller.products.*`, `seller.orders.*` → `Seller\ProductController`, `Seller\OrderController` | NO — no mobile seller repository | none | yes: seller account | BACKEND-ONLY (web) |
| 35 | Admin screens | Web `routes/web.php` admin routes | Web only (not in mobile shell) | none | Web: `admin.*` → `Admin\*` controllers | NO — no mobile admin repository | none | yes: admin account | BACKEND-ONLY (web) |
| 36 | Splash and intro landing (gate) | `lib/screens/landing_screen.dart` | App launch (first install) | `landing_screen_test.dart`, `landing_preview_rail_test.dart` | None | no | none | no | MOCK-ONLY |
| 37 | Loading and skeleton states | `lib/widgets/motion/`, `widgets/shimmer_box.dart` | All screens during load | `motion_primitives_test.dart`, `motion_polish_test.dart` | None | no | none | no | MOCK-ONLY |

---

## (1) Counts per STATUS

| STATUS | Count |
|---|---|
| LIVE-READY | 18 |
| MOCK-ONLY | 11 |
| PARTIAL | 3 |
| BACKEND-ONLY | 4 |
| UNREACHABLE | 1 |

## (2) UNREACHABLE screens

Screens with zero navigation references from app launch (no `Navigator.push` or shell tab path found):

- `lib/screens/buyer/product_link_screen.dart` — no navigation reference points to it; not imported by any screen or shell.
- `lib/screens/buyer/saved_screen.dart` — wishlist screen; wishlist is backend-only (`GET/POST/DELETE /api/v1/buyer/wishlist` exists) but no mobile `WishlistRepository` and no navigation entry point from Profile or any other screen.
- `lib/screens/courier/dev_qr_generator_screen.dart` — reachable only from the courier dev menu (developer options), not from any normal user flow; effectively UNREACHABLE in a standard build.

Note: `notifications_screen.dart` is reachable via the bell icon but the bell is hidden in live mode (`docs/KNOWN_LIMITATIONS.md`), so it is reachable in mock mode only.

## (3) Places where mock mode and live mode show different behavior

| Area | Mock mode | Live mode |
|---|---|---|
| Cart lines | Device state (`CartState` + shared_preferences); add/remove works | Same device state — no server cart endpoint exists; only vouchers come from server |
| Voucher catalogue | Fixed mock set of 3 vouchers | `GET /api/v1/vouchers`; flat-amount vouchers dropped by mapper (app can't apply them correctly) |
| Chat inbox | Local threads in shared_preferences | `threads()` returns empty map (no server list endpoint); messages fetched per-contact on open |
| Google sign-in | Deterministic mock account (`kMockGoogleEmail`); no Google sheet | `register` wired (multipart `/auth/google`); `signIn` not wired — button hidden if `GOOGLE_SERVER_CLIENT_ID` missing |
| Returns | Device-local list with status escalation | All three `notWired` — throws `ApiNotImplementedException` |
| Forgot password | Visible and functional (mock) | Hidden (`if (!kUseLiveApi)`) — no backend route |
| Notifications bell | Visible | Hidden until server provides notifications |
| Checkout totals | Local pricing (`config/pricing.dart`: ₱60 shipping, free at ₱1,000; voucher stacking) | Server total only (`POST /orders` returns `meta`); no `quote()` endpoint; shipping/voucher model mismatch (§0.1, §0.2 of `docs/api-contract.md`) |
| Order tracking milestone | Local state machine in `OrderState`/`CourierState` | History from server; milestone detail not fetched (no `OrderRepository` milestone method) |
| Courier accept/decline | Local simulation | No accept/decline endpoint exists; assignment is server-side |
| Dev tools (role selector, simulate approval) | Visible in debug builds | Tree-shaken in release (`kShowDevTools`) |


## (4) Proposed demo split: live vs mock

**Demo live (backend wired and proven):**

1. Buyer registration + login (Google server client ID configured via `env.json`)
2. Browse categories, search, product detail
3. Address book (add/edit default address)
4. Cart + checkout (COD) — server prices lines, returns `meta` totals
5. Vouchers (server catalogue)
6. Order list + history
7. Write/read reviews (on delivered order)
8. Rider job feed + pickup scan + delivery handover + failed delivery
9. Account & Security (password/email/phone change)

**Reasoning:** All of the above have live repositories that call real endpoints, have `live_*_test.dart` coverage against a fake HTTP transport, and the server endpoints are tested by `php artisan test` (434 passed). These are the features that would impress a reviewer because they hit a real backend.

**Demo on mock (fallback if network fails):**

10. Wishlist (no mobile repository)
11. Cart line management (device state)
12. Checkout e-wallet (no backend)
13. Order detail tracking milestone (no mobile milestone fetch)
14. Rider accept/decline (no server endpoint)
15. Earnings, rider documents (no backend)
16. Avatar upload (no server endpoint)
17. Chat inbox (no thread list endpoint)
18. Returns (all notWired)
19. Notifications bell (hidden in live)
20. Forgot password (hidden in live)
21. Theme toggle (local preference)
22. Splash/intro gate (local)
23. Loading/skeleton states (local)

**Reasoning:** These either lack a server endpoint entirely (returns, e-wallet, avatar, earnings) or have a partial/seam issue (chat inbox, tracking milestone, wishlist). Demoing them live would show `ApiNotImplementedException` or empty states, which undermines the demo. Mock mode tells the same story with the same demo products and accounts (per `data/mock_data.dart`), so it is a valid fallback.

**Credentials for demo (mock-mode defaults):**
- Buyer: `tristan.araneta@gmail.com` / any password (mock accepts)
- Courier: `miguel.santos@gmail.com` (mock knows this identity)
- OTP code: `123456` (`kMockOtpCode`)

**Build command:** `flutter run --dart-define-from-file=env.json --dart-define=zefanya.useLiveApi=true --dart-define=zefanya.apiBaseUrl=https://<tunnel>/api` (both demo setups in `docs/DEMO_SETUP.md` use this flag so Google sign-in is configured).




