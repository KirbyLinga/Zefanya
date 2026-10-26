# 3 — Folder Structure

> Annotated map of the Zefanya codebase and the conventions for where new code goes.
> **Written:** 2026-10-16. Every file listed exists (verified by listing). Orphans/duplicates are labelled **"unreferenced as of this audit"** — nothing here was deleted.
> Companions: `1-Context.md`, `2-Design.md`, `4-Flow.md`.

---

## 1. Repository root

| Path | What it is |
|---|---|
| `app/` | Application source (see §2) |
| `bootstrap/` | Framework bootstrap; `app.php` registers middleware aliases + routes; `providers.php` lists `AppServiceProvider` |
| `config/` | Laravel config (see §7) |
| `database/` | Migrations, seeders, factories (see §6) |
| `docs/` | **These four docs** |
| `public/` | Web root; `index.php`, built assets under `/build`, `robots.txt`, `storage` symlink |
| `resources/` | Blade views + JS + CSS (see §4, §5) |
| `routes/` | Route files (see §3) |
| `storage/` | Logs, cache, sessions, uploads (`app/private`) |
| `tests/` | PHPUnit suites (see §8) |
| `vendor/` | Composer deps (gitignored) |
| `node_modules/` | npm deps (gitignored) |
| `contracts/` | **Removed 2026-10-16** (content merged into these docs) |
| `memory-bank/` | **Removed 2026-10-16** (content merged into these docs) |

**Key root files:** `composer.json` (PHP `^8.3`, Laravel `^13.17`, Socialite, PHPUnit 12, Pint), `package.json` (Tailwind v4, Vite 8, Lucide), `vite.config.js` (the `@vite` input list — **must** include any new CSS/JS entry), `phpunit.xml` (SQLite `:memory:` test env), `artisan`, `README.md` (short pointer to these docs), `AGENTS.md` / `CLAUDE.md` (agent tooling — point to these docs).

**Route/middleware wiring:** `bootstrap/app.php` registers `admin`/`seller`/`logistics` route files + the `admin.role`, `seller.approved`, `logistics.approved` middleware aliases; `AppServiceProvider::boot()` registers `buyer.php` (own `/buyer` prefix + `buyer.` names) and the explicit policies.

---

## 2. `app/`

```
app/
├── Console/Commands/OrdersResyncCommand.php      # orders:resync — repair status rollups / payments
├── Enums/
│   ├── DeliveryFailureReason.php                 # recipient_unavailable, wrong_address, refused_by_recipient, unreachable_by_phone, damaged_parcel, other
│   ├── LogisticsProviderStatus.php               # unverified, pending, approved, rejected
│   ├── OrderActor.php                            # seller, logistics, rider, buyer, system (+ allowedTransitions)
│   ├── OrderStatus.php                           # PLACED … COMPLETED, DELIVERY_FAILED, RETURNED
│   ├── PaymentMethod.php                         # cod, gcash, bank_transfer, card (only cod integrated)
│   ├── PaymentStatus.php                          # pending, paid
│   └── VoucherType.php                            # fixed, percent
├── Exceptions/
│   ├── CheckoutException.php                      # thrown for checkout failures
│   └── ForbiddenBuyPageException.php              # buyer-page access
├── Http/
│   ├── Controllers/
│   │   ├── Controller.php
│   │   ├── Concerns/ClearsOtherGuards.php         # one-account-per-browser trait
│   │   ├── Concerns/VerifiesEmailOtp.php          # per-account OTP failure lock + resend limit (shared)
│   │   ├── Admin/
│   │   │   ├── AuthController.php                 # admin login/logout
│   │   │   ├── DashboardController.php            # pending counts, active users
│   │   │   ├── DocumentController.php             # private-disk document streaming (nosniff)
│   │   │   ├── RegistrationController.php         # registration queue, filters, approve/reject
│   │   │   └── SellerRegistrationController.php   # DEAD — unrouted (do not extend)
│   │   ├── Auth/
│   │   │   ├── GoogleAuthController.php           # Socialite redirect/callback + role linking
│   │   │   ├── LoginChoiceController.php          # DEAD — no route references it
│   │   │   ├── PasswordResetController.php        # role-resolving reset for all four guards
│   │   │   └── UnifiedLoginController.php         # POST /login (seller→logistics→buyer) + logout
│   │   ├── Buyer/
│   │   │   ├── AccountController.php              # profile / address / password
│   │   │   ├── CartController.php                 # cart + voucher + buy-now
│   │   │   ├── CategoryController.php
│   │   │   ├── ChatController.php                 # → stubs (redirect "coming soon")
│   │   │   ├── CheckoutController.php             # index/store/placeOrder/success
│   │   │   ├── HelpController.php
│   │   │   ├── HomeController.php
│   │   │   ├── LoginController.php                # DEAD — buyer login is the unified controller
│   │   │   ├── MessageController.php
│   │   │   ├── NotificationController.php
│   │   │   ├── OrderController.php                # list/show/confirm-received
│   │   │   ├── PageController.php                 # static pages
│   │   │   ├── ProductController.php
│   │   │   ├── RegisterBuyerController.php
│   │   │   ├── SearchController.php
│   │   │   ├── SettingsController.php
│   │   │   ├── VerifyBuyerOtpController.php
│   │   │   └── WishlistController.php
│   │   ├── Logistics/
│   │   │   ├── AreaController.php                 # delivery-area CRUD + link riders
│   │   │   ├── DashboardController.php
│   │   │   ├── DeliveryAssignmentsController.php  # start/delivered/failed/reschedule/return
│   │   │   ├── DeliveryMonitoringController.php
│   │   │   ├── IncomingParcelsController.php      # pickup, to-sorting-center
│   │   │   ├── ParcelSortingController.php        # sort (determine area), assign counter-delivery
│   │   │   ├── RegisterLogisticsController.php
│   │   │   └── VerifyLogisticsOtpController.php
│   │   └── Seller/
│   │       ├── AuthController.php                 # seller login/logout
│   │       ├── DashboardController.php
│   │       ├── MessageController.php
│   │       ├── OrderController.php                # accept, mark-ready
│   │       ├── ProductController.php              # products + variants + images
│   │       ├── RegisterSellerController.php
│   │       ├── ShipmentController.php             # shipments index + waybill label
│   │       └── VerifySellerOtpController.php
│   ├── Middleware/
│   │   ├── Admin/EnsureAdminRole.php              # admin.role:… (super_admin bypass)
│   │   ├── Logistics/EnsureLogisticsApproved.php  # logistics.approved
│   │   └── Seller/EnsureSellerApproved.php        # seller.approved
│   └── Requests/
│       ├── Buyer/…            # buyer registration + address validation
│       ├── Logistics/…        # logistics registration
│       └── Seller/…           # seller registration + product
├── Models/
│   ├── Admin/Admin.php
│   ├── Buyer/Buyer.php                            # OTP, CanResetPassword, address
│   ├── Concerns/{BackfillsDefaultVariant,HasEmailOtp,SyncsVariantRollups}.php  # HasEmailOtp = shared OTP generate/hash/expire/match
│   ├── Logistics/{Area,LogisticsProvider}.php
│   ├── Seller/{Product,ProductImage,Seller}.php   # Product + ProductImage here are DEAD (see §9)
│   ├── Shared/{Category,User}.php                 # both referenced (config/auth.php, controllers)
│   ├── CartItem.php  CheckoutToken.php  Conversation.php  Courier.php
│   ├── DeliveryAssignment.php  InventoryTransaction.php  Message.php
│   ├── NewsletterSubscriber.php  Order.php  OrderItem.php  Payment.php
│   ├── Product.php  ProductImage.php  ProductOption.php  ProductOptionValue.php
│   ├── ProductVariant.php  SellerOrder.php  StatusHistory.php  Voucher.php  Wishlist.php
├── Notifications/
│   ├── Buyer/{BuyerRegistrationDecision,BuyerVerifyOtp,CourierAssigned,DeliveryFailed,OrderDelivered,OrderOutForDelivery}.php
│   ├── Logistics/{LogisticsRegistrationDecision,LogisticsRegistrationOtp,RiderAssignmentsNeedReassignment,RiderParcelActivity}.php
│   └── Seller/{DeliveryFailed,NewOrderReceived,ParcelReturned,SellerRegistrationDecision,SellerRegistrationOtp}.php
├── Policies/{BuyerOrderPolicy,ConversationPolicy,SellerOrderFleetPolicy,SellerOrderPolicy}.php
├── Providers/AppServiceProvider.php               # policies, buyer routes, rate config
└── Services/{CartSummary,CourierAssignmentService,RiderNotifier,SellerOrderStatusService,StockService,VariantSyncService}.php
```

**Rider notifications:** `app/Notifications/Rider/` holds the database-only rider alerts (`PickupAssigned`, `DeliveryAssigned`, `AssignmentReassigned`, `DeliveryReturned`, `StationChanged`, `AccountStatusChanged`) and their `RiderNotification` base. The bell lives in `resources/views/Components/rider/notification-bell.blade.php`; the page is `Rider/Notifications/index.blade.php`.

---

## 3. `routes/`

| File | Prefix / name | Contents |
|---|---|---|
| `web.php` | — | landing (`/`), `/login`, registration + OTP (buyer/seller/logistics), `password.*` reset, Google OAuth, unified `POST /login` / `POST /logout` |
| `buyer.php` | `/buyer`, `buyer.` | public browsing + `auth:buyer` group (cart, checkout, orders, account, wishlist, settings, notifications, messages) — registered in `AppServiceProvider` |
| `seller.php` | `/seller`, `seller.` | login/logout, dashboard, products, orders, shipments, messages, + stub pages (vouchers/reports/feedback/chat/account) |
| `logistics.php` | `/logistics`, `logistics.` | dashboard, incoming-parcels, parcel-sorting, delivery-assignments, delivery-monitoring, areas — all under `auth:logistics, logistics.approved` |
| `admin.php` | `/admin`, `admin.` | login/logout, dashboard, registrations, documents — `admin.role:admin,moderator` |
| `console.php` | — | only `inspire` |

**Route-order hazards (already mitigated — do not reorder):** literal routes must precede wildcards. `routes/buyer.php` keeps `/cart/voucher` before `/cart/{id}` and `/messages/unread-count` before `/messages/{conversation}`; `routes/seller.php` keeps `/messages/unread-count` first; `routes/web.php` keeps `/auth/google/redirect|callback` before `/auth/google/{role}/…`. There is **no `routes/api.php`**.

---

## 4. `resources/views` (Blade)

Layouts / shells: `Layouts/footer.blade.php` (public + auth + buyer-pending), `Layouts/seller.blade.php`, `Layouts/logistics.blade.php`, `Admin/Layouts/app.blade.php`, `Buyer/Layouts/app.blade.php`.

```
resources/views/
├── Admin/           Auth/login, dashboard, Layouts/app, Registrations/{index,sellers}
├── Auth/            forgot-password, reset-password, login-choice, Register-Type, register-buyer-pending (orphan dup)
├── Buyer/
│   ├── Account/{address,index,password,profile}
│   ├── Auth/login (orphan), Cart/{index,_empty,_item,_seller-group,_summary}
│   ├── Categories/{index,show}, Chat/{index,show}, Checkout/{index,success}
│   ├── Help/index, Home/index, Layouts/{app,footer}, Messages/{index,show}
│   ├── Orders/{index,show}, Pages/{about,contact,privacy-policy,shipping-returns,terms-of-service}
│   ├── Products/{index,show,_buybox,_gallery,_related,_seller-strip,_tabs}
│   ├── Search/index, Settings/index, Wishlist/index, register-buyer-pending, register-buyer-verify-otp
├── Components/      admin-sidebar; buyer/*; seller/*; logistics/*; navbar; login-modal; *-register-modal; registration-personal-step; category-chip (orphan)
├── LandingPage/index
├── Layouts/{footer,seller,logistics}
├── Logistics/       Areas/index, Dashboard/index, DeliveryAssignments/{index,mark-failed}, DeliveryMonitoring/index, IncomingParcels/index, ParcelSorting/index, register-logistics-*
└── Seller/          Account/*, Auth/login (orphan), Chat/index, Dashboard/index, Feedback/index,
                     Messages/{index,show}, Orders/{index,show}, Products/{index,show,_form},
                     Reports/index, Shipments/{index,label,show}, Vouchers/{index,create,edit},
                     register-seller-pending, register-seller-verify-otp
```

**Placeholder views** (page exists, no real behaviour): `Seller/{Account/index,Account/edit,Chat/index,Feedback/index,Reports/index,Shipments/show,Vouchers/index,Vouchers/create,Vouchers/edit}`; `Buyer/Account/index`, `Buyer/Categories/index`, `Buyer/Chat/{index,show}`.

**Dead nav links:** admin sidebar 8 items (`Components/admin-sidebar.blade.php:32-60`); logistics sidebar **Rider Management** + **Courier Applications** + **Account** (`Components/logistics/sidebar.blade.php`); 13 public-footer links + buyer navbar "Sale" (`Components/navbar.blade.php:188`). Seller sidebar and buyer account sidebar are **all live** (some point at stubs, but no broken links).

---

## 5. `resources/js` and `resources/css`

**JS** (`resources/js/`): `app.js` (barrel), `registration-validation.js`; `buyer/` (address-lookup, add-to-cart-animation, buyer, buyer-register-modal, cart, category-scroll, home, messages, navbar, navbar-search, product-show, search, wishlist-cards); `seller/` (notification-bell, product-create-modal, products-index, products-table, seller-register-modal, sidebar-tooltips, theme-toggle); `logistics/` (logistics-register-modal, sidebar-tooltips); `rider/` (notification-bell, rider-register-modal, sidebar-tooltips).

**CSS** (`resources/css/`): `app.css` (Tailwind v4 `@theme` — **the design-token source**), `login-modal.css`, `auth.css` + `auth-pages.css` (legacy auth chain), `theme.css`, `buyer/buyer-register-modal.css`, `seller/{seller-register-modal,products-index}.css`, `logistics/logistics-register-modal.css`.

> Any new entry must be added to the `@vite([...])` array **and** to `vite.config.js` inputs. Tailwind v4 has **no config file** — tokens are in `app.css` `@theme`.

---

## 6. `database/`

- **Migrations (49):** chronological. Order/fulfilment core from `2026_09_22_110001…110005`; logistics `2026_09_21_*`, `2026_09_25_000002`, `2026_10_03`, `2026_10_04`; variants `2026_09_27_*`; the 2026-10-16 set is `postal_code`, `handover_at`, `delivery_attempts`, `areas` (+`couriers.area_id`, `seller_orders.destination_area_id`), `delivery_assignments`. See `2-Design.md §3` for the schema.
- **Factories:** `AreaFactory`, `BuyerFactory`, `CartItemFactory`, `CourierFactory`, `LogisticsProviderFactory`, `OrderFactory`, `OrderItemFactory`, `PaymentFactory`, `ProductCatalogData`, `ProductFactory`, `ProductImageFactory`, `ProductVariantFactory`, `SellerFactory`, `SellerOrderFactory`, `UserFactory`, `VoucherFactory`.
- **Seeders:** `DatabaseSeeder`, `AdminSeeder` (refuses without `ADMIN_SEED_PASSWORD`), `DemoRidersSeeder`, `TestCouriersSeeder`.

> `BuyerFactory` sets only `address_mode='manual'` (no municipality/street) — checkout address completeness is enforced in the UI, not the DB.

---

## 7. `config/`

| File | Notes |
|---|---|
| `auth.php` | 5 guards, 5 providers, 5 password brokers (all share `password_reset_tokens`) |
| `database.php` | default `env('DB_CONNECTION','sqlite')`; real `.env` uses MySQL |
| `filesystems.php` | `local` → `storage/app/private`; `public` → `storage/app/public` |
| `mail.php`, `services.php` | mail driver; Google OAuth |
| others | Laravel defaults (`app`, `cache`, `queue`, `session`, `logging`, …) |

---

## 8. `tests/`

`tests/TestCase.php` (base) + `tests/Unit/ExampleTest.php` + **57 files in `tests/Feature`**. Grouped by area: registration (`BuyerRegistration/`, `SellerRegistration/`, `LogisticsRegistrationTest`, `RegistrationStreetTest`, `RegisterAddressLookupTest`), auth (`Auth/Google*`, `Auth/LoginRateLimitTest`, `LogisticsLoginFlowTest`), orders/fulfilment (`OrderStatusGraphTest`, `OrderActorMapTest`, `FulfillmentLifecycleTest`, `Console/OrdersResyncCommandTest`), buyer (`Buyer/BuyerOrderLifecycleTest`, `Buyer/BuyerAccountPagesTest`, `Buyer/VoucherUsageTest`, `Buyer/ManualAddressMunicipalityTest`, `Buyer/RejectedBuyerOtpTest`, cart/checkout/buy-now/variant tests), seller (`Seller/*`, `Http/Controllers/Seller/ProductVariantsTest`, `SellerStockLedgerTest`), logistics (`Logistics/{IncomingParcels,ParcelSorting,DeliveryAssignments,DeliveryMonitoring}Test`), admin (`Admin*`, `Admin/AdminRoleAccessTest`, `Admin/AdminSeederTest`), UI (`Components/NavbarTest`, `Buyer/HomepageTest`, `SellerDashboardHeroTest`, `SellerPanelFixesTest`, `ThemeKeyScopingTest`, `HttpsUrlGenerationTest`). See `1-Context.md §5` for the GD/SQLite limits.

---

## 9. Orphaned / duplicate files (unreferenced as of this audit — NOT deleted)

| File | Status | Notes |
|---|---|---|
| `app/Models/Seller/ProductImage.php` | **0-byte dead file** | live code uses `App\Models\ProductImage` |
| `app/Models/Seller/Product.php` | **duplicate/dead** | declares `namespace App\Models` (same FQCN as `app/Models/Product.php`); 0 references |
| `app/Http/Controllers/Auth/LoginChoiceController.php` | **dead** | no route references it |
| `app/Http/Controllers/Buyer/LoginController.php` | **dead** | buyer login is `Auth\UnifiedLoginController` |
| `app/Http/Controllers/Admin/SellerRegistrationController.php` | **dead** | unrouted legacy controller |
| `resources/views/Seller/Products/show.blade.php` | **orphan view** | no route/`view()`/include references it (7,190 B; **different** from `_form.blade.php`) |
| `resources/views/Seller/Vouchers/{create,edit}.blade.php` | **orphan views** | only `Vouchers.index` is routed |
| `resources/views/Seller/Shipments/show.blade.php` | **orphan view** | `ShipmentController` returns only index/label |
| `resources/views/Seller/Account/edit.blade.php` | **orphan view** | only `Account.index` is routed |
| `resources/views/Seller/Auth/login.blade.php` | **orphan view** | `showLogin()` redirects instead of rendering |
| `resources/views/Auth/register-buyer-pending.blade.php` | **orphan duplicate** | live route uses `Buyer/register-buyer-pending.blade.php` |
| `resources/views/Components/category-chip.blade.php` | **orphan view** | self-referenced only |
| `resources/views/Components/seller/theme-toggle.blade.php` | **orphan view** | toggle is inline + `theme-toggle.js` |

**Confirmed NOT orphans** (kept, referenced): `app/Models/Shared/User.php` (`config/auth.php`), `app/Models/Shared/Category.php` (many controllers/models).

**Additionally missing (referenced but absent):** `CourierApplicationPolicy`, `DeliveryPolicy`, and the `CourierApplication` model — old docs mention them; they do not exist in the codebase.

---

## 10. Root-level clutter (kept, for the owner to review)

Generated dumps and caches — safe to delete by hand, **not** removed here:
`.phpunit.result.cache`, `zefanya_schema`, `analysis.txt`, `buyer_test.txt`, `clean.txt`, `full.txt`, `route-list.txt`, `route-list-full.txt`, `seller-test-output.txt`, `suite-final.txt`, `suite-now.txt`, `suite-run2.txt`, `test-final.txt`, `test-run.clean.txt`, `test-run.txt`, `test-verify-final.txt`, `verify-check.txt`, `analyze.ps1`, and the gitignored `web/` (a vendored copy of the app) and `playwright/test-results/`.

---

## 11. Naming conventions & where new code goes

| New thing | Location | Convention |
|---|---|---|
| Controller | `app/Http/Controllers/<Role>/<Name>Controller.php` | `PascalCaseController`; thin — delegate to a service |
| Form request | `app/Http/Requests/<Role>/<Action>Request.php` | rules in `rules()` |
| Policy | `app/Policies/<Model>Policy.php` | register explicitly unless the name matches auto-discovery |
| Service | `app/Services/<Name>Service.php` | holds invariants / transactions |
| Enum | `app/Enums/<Name>.php` | string-backed; PHP TitleCase cases |
| Notification | `app/Notifications/<Role>/<Event>.php` | OTP + decisions → `['mail']`; order alerts → `['database']` |
| Middleware | `app/Http/Middleware/<Role>/<Name>.php` | alias in `bootstrap/app.php` |
| View | `resources/views/<Role>/<Feature>/<page>.blade.php` | partials prefixed `_` |
| Component | `resources/views/Components/<scope>/<name>.blade.php` | `<x-scope.name/>` |
| Migration | `database/migrations/YYYY_MM_DD_NNNNNN_verb_table.php` | one concern per file |
| Factory | `database/factories/<Model>Factory.php` | |
| Test | `tests/Feature/<Area>/<Thing>Test.php` | create with `php artisan make:test --phpunit` |

**Architectural rules (from `1-Context.md §8-9`):** status changes only via `SellerOrderStatusService`; stock only via `StockService`; literal routes before wildcards; use `forceFill()` for controller-owned audit fields; register every new Vite entry; add tokens to `app.css` `@theme`, not new stylesheets.

---


## Buyer completion additions (2026-10-17)

- app/Services/BuyerShipmentConfirmation.php: shipment confirmation orchestration.
- app/Services/CartVariantResolver.php: cart/Buy Now variant selection invariants.
- app/Services/ProductReviewService.php: purchased-product eligibility and review upsert.
- app/Models/Review.php; app/Http/Requests/Buyer/StoreProductReviewRequest.php; app/Http/Controllers/Buyer/ReviewController.php.
- database/migrations/2026_10_17_200000_create_reviews_table.php.
- resources/views/Buyer/Products/_reviews.blade.php: public review list and eligible-buyer form.
- orders:resync --statuses-only [--dry-run] recalculates parent statuses without payment changes.


Product editor additions (2026-10-17): `app/Services/ProductPublishingService.php` coordinates listing/inventory/image persistence; `tests/Feature/Http/Controllers/Seller/ProductPublishingTest.php` covers publication and image/variant integrity. `playwright/check-seller-product-form.mjs` checks the rendered five-step form and buyer image selection against an isolated local fixture. The check renders the real Blade modal without a database connection; evidence is in `playwright/product-form-preview/`.
