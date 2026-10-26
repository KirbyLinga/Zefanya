# 1 — Context

> **Zefanya** — a single-vendor-hosted e-commerce marketplace / ERP built on Laravel.
> This is one of four canonical docs (`docs/1-Context.md`, `2-Design.md`, `3-Folder-Structure.md`, `4-Flow.md`).
> **Written:** 2026-10-16. **Source of truth: the code.** Every non-obvious claim below was checked against a file; where a claim could not be verified it says **unverified**.
> Currency is the Philippine peso (₱). Amounts in the DB are integers in **centavos** ("minor" units) except catalog prices (see `2-Design.md`).

---

## 1. What this project is

Zefanya is a marketplace where independent **sellers** list products, **buyers** purchase them, a **logistics provider** (sorting center) moves parcels from seller to buyer using **riders/couriers**, and an **admin** back office approves accounts and oversees the platform. It is a **monolithic Laravel web app** (Blade server-rendered) with five session login guards (`admin`, `seller`, `logistics`, `buyer`, `rider`).

Riders now have a **web operations UI** (dashboard, pickups, deliveries, proof of delivery, COD) and the Admin back office includes Hub approval plus the registration queue. A Rider **mobile** app is still not built. See §6.

---

## 2. The five roles

| Role | Guard | Login entry | What they do |
|---|---|---|---|
| **Buyer** | `buyer` | `/login` (unified) or Google | Browse, cart, checkout, track orders, confirm receipt, message sellers, manage account/wishlist/settings |
| **Seller** | `seller` | `/login` (unified) or Google | Register a shop, list products (+variants), accept or decline orders, pack, print waybills, hand over to logistics, view shipments |
| **Courier / Rider** | `rider` | `/login` (unified) | Pick up assigned parcels from sellers, deliver to buyers, capture proof of delivery and collect COD; operational only when approved + verified + assigned to an approved Station Hub with a linked Courier |
| **Logistics / Sorting Center** | `logistics` | `/login` (unified) or Google | Receive parcels, scan/sort, determine delivery area, assign riders by area, monitor deliveries, manage delivery areas |
| **Admin** | `admin` | `/admin/login` | Approve/reject buyer, seller and logistics registrations; stream ID documents; (back-office modules pending) |

There is also a legacy `web` guard bound to `App\Models\Shared\User` / the `users` table, which no app feature uses (§9).

**How a shared email logs in:** the unified login tries **seller → logistics → buyer**, then **rider** (`app/Http/Controllers/Auth/UnifiedLoginController.php`). A Rider only receives a session when `Rider::isOperational()`. Google role resolution uses the same order (`GoogleAuthController` `ROLES`). Password reset tries brokers **admins → sellers → logistics → buyers** (`app/Http/Controllers/Auth/PasswordResetController.php:33`).

---

## 3. ERP requirements by role (what *should* exist)

### Buyer
- [x] Register → email OTP (6-digit) → admin decision → login only if `approved`.
- [x] Province / municipality / barangay chosen from **dropdowns only**; manual text allowed **only** for street/house number, address details/landmark, and **postal code**.
- [x] One account per browser (any login logs out the other roles; logout ends all).
- [x] Shop: category browse, search, product detail with variants, cart, vouchers, wishlist.
- [x] Checkout: confirm saved address, choose payment method, place order (single transaction, no double-submit).
- [x] Track order status; confirm receipt (order → Completed).
- [ ] Rate / review a seller or product — **missing**.
- [ ] Buyer-initiated return request — **missing** (returns only happen when delivery fails, see below).

### Seller
- [x] Register → OTP → admin decision → login only if `approved`.
- [x] Manage products with 1–2 option axes (variants), prices, stock, images.
- [x] View incoming orders, accept or decline (cancels that shop’s slice + restores stock), mark ready, print waybill, hand over.
- [x] See shipments list; stock ledger is written for every stock change.
- [ ] Vouchers, Reports, Feedback pages — **placeholders** (routes render stub views).
- [ ] Account management page — **placeholder**.
- [ ] Seller-created vouchers — **missing**.

### Courier / Rider
- [x] Riders **register themselves** (web dialog or mobile API) with OTP verification and Logistics review.
- [x] **Logistics** approves / rejects / activates / suspends / deactivates riders and assigns their Station Hub.
- [x] Logistics assigns parcels to a rider **by delivery area** (with manual override) using the linked `couriers` record.
- [x] Rider **web** interface: dashboard, pickup assignments, delivery assignments, delivery history, account.
- [x] Rider confirms pickup, starts delivery, records proof of delivery, collects COD and reports failed attempts.
- [ ] Rider **mobile app** — still unbuilt; `routes/api.php` covers registration/OTP and Logistics review only (no Sanctum).

### Logistics / Sorting Center
- [x] Register → OTP → admin decision → login only if `approved`.
- [x] Receive parcels, mark picked up, move to sorting center, sort (determines **destination area**).
- [x] Assign delivery rider by area (auto) with manual override.
- [x] Mark out-for-delivery / delivered / failed; on failure retry (≤2) or return to seller.
- [x] Manage **delivery areas** (provider-scoped) and link riders to areas.
- [x] "Rider Management" screen (fleet list, applications, station assignment, suspend/deactivate) — implemented; Courier Applications is covered by the rider application review API.

### Admin
- [x] Unified registration queue for buyers, sellers, logistics with type/status/date/search filters.
- [x] Approve / reject with reason (notifies the applicant); stream uploaded documents.
- [x] User accounts (suspend / reactivate) — `Admin/UserAccountController`.
- [ ] Seller compliance, complaints/disputes, commissions, reports, settings, chat — **sidebar "Soon" placeholders** (no dead `#` links).

---

## 4. Tech stack and versions

From `composer.json` and `package.json` (verified):

| Layer | Package | Constraint |
|---|---|---|
| Runtime | PHP | `^8.3` (environment reports PHP 8.5) |
| Framework | `laravel/framework` | `^13.17` |
| OAuth | `laravel/socialite` | `^5.31` |
| REPL | `laravel/tinker` | `^3.0` |
| Tests | `phpunit/phpunit` | `^12.5.12` (dev) |
| Format | `laravel/pint` | `^1.27` (dev) |
| Dev aid | `laravel/boost`, `laravel/pail`, `laravel/pao`, `nunomaduro/collision`, `fakerphp/faker`, `mockery/mockery` | dev |
| CSS | `tailwindcss` + `@tailwindcss/vite` | `^4.0.0` |
| Bundler | `vite` | `^8.0.0` |
| Vite plugin | `laravel-vite-plugin` | `^3.1` |
| Icons | `lucide` | `^1.37.0` |

**No `laravel/sanctum` is installed** (confirmed absent from `composer.json`). A rider API would need it (or a hand-rolled token table).

**Storage / infra:** session driver `database`, cache store `database`, queue connection `sync` locally (`database` in `.env.example`), filesystem disk `local` (`storage/app/private`). Mail is `smtp` in local `.env` (`log` in `.env.example`). Google Sign-In is configured through `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `GOOGLE_REDIRECT_URI` (`.env.example:65-70`).

---

## 5. Environments, running it, and tests

**Environments**
- **Local dev** (`.env`): `APP_ENV=local`, `APP_URL=http://localhost:8000`, `DB_CONNECTION=mysql`, `DB_DATABASE=Zefanya`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=smtp`.
- **Production target:** MySQL (`Zefanya`), HTTPS. `.env.example` ships `DB_CONNECTION=sqlite` as a placeholder but both real `.env` files use **MySQL**.
- **Tests:** SQLite **`:memory:`** (`phpunit.xml:26-27`), `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `SESSION_DRIVER=array`.

**Run it**
```sh
composer install
copy .env.example .env        # Windows; then set DB_*, APP_KEY, ADMIN_SEED_PASSWORD
php artisan key:generate
php artisan migrate
php artisan db:seed           # requires ADMIN_SEED_PASSWORD (seeder refuses otherwise)
npm install && npm run build
php artisan serve             # or: composer run dev
```
- `composer run setup` runs the whole setup (`composer.json` scripts). `composer run dev` runs `php artisan dev`.
- If a frontend change does not appear, run `npm run dev`, `npm run build`, or `composer run dev`.

**Run the tests**
```sh
php artisan test --compact          # or: composer test  (clears config first)
vendor/bin/phpunit                  # same suites; accepts a path or --filter=name
```
- Test suites: `tests/Unit` (1 file) and `tests/Feature` (**57 files**), base class `tests/TestCase.php`.

**Known test-environment limits** (verified in code / prior audit docs)
- **SQLite in tests vs MySQL in production.** A migration can pass on `:memory:` yet fail/be absent on MySQL (lesson from the 2026-09-27 Google migration). Always migrate the real DB too.
- **GD extension is not installed** in the test environment. `tests/Feature/Http/Controllers/Seller/SellerStockLedgerTest.php:46` uses `UploadedFile::fake()->image(...)` (needs GD) and errors with *"GD extension is not installed"*. Other upload tests use `create()`/`createWithContent()` and do **not** need GD.
- **`email:rfc,dns` is too strict** here; use `email:rfc`.
- **Case-insensitive email uniqueness** must be queried with `whereRaw('LOWER(email)=?')`.
- **Test count is disputed across old docs** (157 → 242 → "341+"; the most recent audit reported *506 passed / 21 failed-or-errored / 2335 assertions* on 2026-10-16). **The true baseline is unverified** — run the suite to establish it. Known pre-existing failures: registration tests (403-vs-302 + `postal_code`) and one GD-related stock-ledger error.

---

## 6. Current status by module (verified against code)

Legend: **Implemented** = works end-to-end; **Partial** = works but incomplete; **Placeholder** = page/route exists but no real behaviour; **Missing** = nothing.

### Authentication & accounts
| Feature | Status | Evidence |
|---|---|---|
| Buyer registration → OTP → admin decision | Implemented | `Buyer/RegisterBuyerController.php`, `VerifyBuyerOtpController.php`, `Admin/RegistrationController.php` |
| Seller registration → OTP → admin decision | Implemented | `Seller/RegisterSellerController.php`, `VerifySellerOtpController.php` |
| Logistics registration → OTP → admin decision | Implemented | `Logistics/RegisterLogisticsController.php`, `VerifyLogisticsOtpController.php` |
| Unified login (seller/logistics/buyer) | Implemented | `Auth/UnifiedLoginController.php:33-99` |
| Google Sign-In (buyer/seller/logistics) | Implemented | `Auth/GoogleAuthController.php` |
| Password reset (all four roles) | Implemented | `Auth/PasswordResetController.php`; routes `password.*` |
| One account per browser | Implemented | `Concerns/ClearsOtherGuards.php`; wired into 4 controllers |
| Registration retry after rejection | Implemented | archive (`HasRegistrationArchive`) of `unverified`/`rejected` rows; OTP lock keyed by email |
| One email = one role | Implemented | `EmailRegistry` on register + unified/Google login |
| Admin login + role gate | Implemented | `Admin/AuthController.php`, middleware `admin.role` |

### Buyer
| Feature | Status | Evidence |
|---|---|---|
| Home, categories, search, product detail, variants | Implemented | `Buyer/HomeController.php`, `CategoryController.php`, `ProductController.php` |
| Cart (add/merge/update/remove/voucher) | Implemented | `Buyer/CartController.php`, `Services/CartSummary.php` |
| Wishlist (UI + toggle) | Implemented | `Buyer/WishlistController.php`; `wishlist-cards.js` |
| Checkout (address confirm + payment + place order) | Implemented | `Buyer/CheckoutController.php` |
| Orders list / timeline / tracking / confirm receipt | Implemented | `Buyer/OrderController.php` |
| Account (profile/address/password), settings | Implemented | `Buyer/AccountController.php`, `SettingsController.php` |
| Notifications centre | Implemented | `Buyer/NotificationController.php` |
| Messages (buyer↔seller) | Implemented | `Buyer/MessageController.php`, `Components/.../Messages` views |
| **Chat** page | **Placeholder** | `Buyer/ChatController.php:20-32` redirects "Chat is coming soon"; `Buyer/Chat/index.blade.php` (144 B) is a comment |
| **Category grid** page | **Placeholder** | `Buyer/Categories/index.blade.php` (134 B) body is a comment |
| Account landing (`/account`) | **Placeholder** | `Buyer/Account/index.blade.php` (422 B) renders an empty `<main>` |
| Rate / review seller | **Missing** | no rating code anywhere |

### Seller
| Feature | Status | Evidence |
|---|---|---|
| Products + variants (1–2 axes), images, status | Implemented | `Seller/ProductController.php`, `Services/VariantSyncService.php` |
| Stock ledger (every change) | Implemented | `Services/StockService.php`, `inventory_transactions` |
| Orders: accept → preparing, mark ready | Implemented | `Seller/OrderController.php:79-172` |
| Shipments list + printable waybill | Implemented | `Seller/ShipmentController.php`, `Seller/Shipments/{index,label}.blade.php` |
| Messages | Implemented | `Seller/MessageController.php` |
| **Vouchers / Reports / Feedback / Chat / Account** | **Placeholder** | stub views 153–183 B; routes `routes/seller.php:47-73` |
| Seller dashboard stats | Partial | `Seller/DashboardController` renders; figures are largely placeholders |

### Logistics
| Feature | Status | Evidence |
|---|---|---|
| Dashboard | Partial | `Logistics/DashboardController.php`; view renders no notifications despite passing them |
| Incoming parcels: pickup, to sorting center | Implemented | `Logistics/IncomingParcelsController.php` |
| Sorting: receive → sort (determines destination area) | Implemented | `Logistics/ParcelSortingController.php:102-138` |
| Assign delivery rider by area (+ override) | Implemented | `ParcelSortingController.php:146`; `Services/CourierAssignmentService.php` |
| Delivery: start, delivered, failed, reschedule, return | Implemented | `Logistics/DeliveryAssignmentsController.php` |
| Delivery monitoring | Implemented | `Logistics/DeliveryMonitoringController.php` |
| Delivery areas CRUD + link riders | Implemented | `Logistics/AreaController.php`, `Logistics/Areas/index.blade.php` |
| **Rider Management** | Implemented | `Logistics/RiderController.php`, `Logistics/Riders/*`; pending applications reviewed via `api/logistics/rider-applications` |

### Rider
| Feature | Status | Evidence |
|---|---|---|
| Unified login + operational gate | Implemented | `Auth/UnifiedLoginController::tryRider`, `Rider::isOperational()`, `Middleware/Rider/EnsureRiderActive.php` |
| Dashboard (company, station, real counters, attention items) | Implemented | `Rider/DashboardController.php`, `Services/RiderOperationsService::dashboard()` |
| Pickup assignments + confirm collection | Implemented | `Rider/PickupAssignmentsController.php`, `RiderOperationsService::confirmPickup()` |
| Delivery assignments, start, failed attempts | Implemented | `Rider/DeliveryAssignmentsController.php`, `RiderOperationsService::{startDelivery,failDelivery}()` |
| Proof of delivery + COD collection | Implemented | `Rider/DeliveryProofController.php`, `Logistics/DeliveryProofController.php`, `Models/DeliveryProof.php`, `Payment::recordCodCollection()` |
| Delivery history + account | Implemented | `Rider/DeliveryHistoryController.php`, `Rider/AccountController.php` |
| Notifications centre (assignments, reassignment, station, account) | Implemented | `Rider/NotificationController.php`, `Services/RiderNotifier.php`, `Notifications/Rider/*` |

### Admin
| Feature | Status | Evidence |
|---|---|---|
| Registration queue (filters, search, pagination) | Implemented | `Admin/RegistrationController.php:30-99` |
| Approve / reject (buyer/seller/logistics) | Implemented | `Admin/RegistrationController.php:309-436` |
| Document streaming (private disk, nosniff) | Implemented | `Admin/DocumentController.php` |
| Dashboard (pending count, active users) | Implemented | `Admin/DashboardController.php` |
| User accounts (suspend / reactivate) | Implemented | `Admin/UserAccountController.php`, `Admin/Users/index.blade.php` |
| **Compliance / disputes / commissions / reports / settings / chat / account** | **Placeholder** | sidebar "Soon" labels (not `#` links) |

### Order & delivery engine
| Feature | Status | Evidence |
|---|---|---|
| Status pipeline PLACED…COMPLETED | Implemented | `Services/SellerOrderStatusService.php:41-55` |
| DELIVERY_FAILED → retry (≤2) / RETURNED | Implemented | `DeliveryAssignmentsController.php:201-250` |
| COD settlement on delivery | Implemented | `SellerOrderStatusService.php:336-346` |
| Stock restore on return | Implemented | `SellerOrderStatusService.php:258-287` |
| Status history (audit) | Implemented | `status_histories` table, written in `transition()` |
| **Commission (10%) on COMPLETED** | **Missing** | no commission code anywhere in `app/` |
| **Shipping fee** | **Missing** | no column/service/logic |

---

## 7. Known bugs and logic gaps (verified)

Each item was checked in the current code. "Verified" means the file/line was read.

| # | Problem | File | Verified | Severity |
|---|---|---|---|---|
| C1 | `App\Models\Seller\ProductImage` is a **0-byte dead file**; live code uses `App\Models\ProductImage` | `app/Models/Seller/ProductImage.php` | yes (empty) | High (dead code) |
| C2 | `App\Models\Seller\Product.php` declares `namespace App\Models` (same FQCN as `app/Models/Product.php`) → **unreachable** | `app/Models/Seller/Product.php` | yes (0 refs) | Medium |
| C7 | `RETURNED` can → `COMPLETED` in the graph but **no actor owns it**; a returned slice is a dead end and blocks buyer confirmation of the whole order | `SellerOrderStatusService.php:53`, `Enums/OrderActor.php:61-63`, `Order.php:118-123` | yes | High (logic) |
| C8 | Idempotent-replay path can **re-fire notifications** (the post-transaction `status === $to` guard is true on a racing replay) | `SellerOrderStatusService.php:119-121,186` | yes | Medium |
| C9 | `Buyer/CourierAssigned` notification on `ASSIGNED_TO_RIDER` reads `courier?->name` (**pickup** courier), not `delivery_courier_id` | `Notifications/Buyer/CourierAssigned.php:25` | yes | Low |
| C10 | **Fixed 2026-10-23:** failed attempts increment atomically in `SellerOrderStatusService::transition()`, including direct service callers; duplicate reports do not increment. Two total attempts (initial + one retry) are enforced under lock. | `SellerOrderStatusService`, `CourierAssignmentService` | yes | Resolved |
| C11 | Pickup courier is released **twice** on `AT_SORTING_CENTER` (service + controller) — harmless but redundant | `IncomingParcelsController.php:123-126`, `SellerOrderStatusService.php:204-208` | yes | Low |
| C12 | Order rollup rank puts `DELIVERY_FAILED`/`RETURNED` **after** `COMPLETED`, so a failed sibling shows `DELIVERED` as the bottleneck instead of the failure | `Order.php:52-63` | yes | Medium |
| C13 | `OrderStatus` docblock claims a native MySQL ENUM; the columns are `VARCHAR(20)` | `Enums/OrderStatus.php:9-11` vs migrations | yes | Low |
| C14 | Logistics dashboard passes `$notifications`/`$unreadCount` but the view renders neither | `Logistics/DashboardController.php:38-54` | yes | Low |
| C15 | Buyer order list runs a **second full query** to compute pill counts | `Buyer/OrderController.php:55-97,120-123` | yes | Low |
| C16 | Cart voucher is not cleared on the checkout **failure** path (only on success) | `Buyer/CheckoutController.php` | yes | Low |
| C17 | `LoginChoiceController` is **dead** (no route references it; `/login` is a closure) | `Auth/LoginChoiceController.php` | yes | Low |
| C18 | `Admin\SellerRegistrationController` is **unrouted** (dead legacy controller) | `app/Http/Controllers/Admin/SellerRegistrationController.php` | yes | Low |
| C20 | ~~`buyers.contact_no` has no unique constraint~~ **Fixed** — unique nullable `contact_no` on buyers/sellers/logistics_providers (`2026_10_17_000001`); archive nulls the phone | migration + FormRequests | yes | — |
| C21 | `ConversationPolicy` exists but is **not registered** in `AppServiceProvider` | `Providers/AppServiceProvider.php` | yes | Low |
| C22 | ~~Admin registrations "View details" unwired~~ **Fixed** — detail modal with address + document links | `Admin/Registrations/index.blade.php` | yes | — |
| C23 | `SESSION_ENCRYPT=false` and `SESSION_SECURE_COOKIE=false` in `.env` (dev; must be reviewed for prod) | `.env:32,35` | yes | Medium (prod) |

**Notable structural gaps:** no `deliveries` table (delivery carried by `seller_orders` columns + `status_histories`); no commission; no shipping fee; no Rider mobile app/bearer tokens (web guard only; `routes/api.php` is registration/OTP + Logistics review).
**Documented-but-absent classes:** `CourierApplicationPolicy`, `DeliveryPolicy` — grep finds them **only in old docs**; they do not exist. `CourierApplication` model does **not** exist either.

**Things old docs claimed that are now FALSE (do not re-report):** `buyer.home` duplicate route name (fixed — `/home` is now unnamed); `Seller/Products/show.blade.php` being byte-identical to `_form.blade.php` (they differ); wishlist being unwired (it works); `CheckoutController` clearing voucher on the wrong path (no). `EnsureLogisticsApproved` enum bug, `/messages/unread-count` shadowing, `seller->name`, and voucher `times_used` are **fixed**.

**Fixed 2026-10-16 (security pass — previously C4/C5/C6):** `routes/web.php` no longer has bytes before `<?php` and no PHP file emits output after a closing `?>`; the buyer OTP **verify** route is now throttled (`throttle:10,1`) like seller/logistics; and **no raw OTP, email or phone is written to logs anywhere**. OTP generate/hash/expire/compare now live in one shared trait (`app/Models/Concerns/HasEmailOtp.php`) and the per-account limits (5 wrong codes → 15-minute lock; 3 resends / 10 min) live in `app/Http/Controllers/Concerns/VerifiesEmailOtp.php`.

**Fixed 2026-10-16 (OTP follow-up — previously C3/C19):** a **non-XHR** buyer registration submit now redirects to the buyer's OTP step (`register.buyer.verify-otp.show`) instead of the non-existent `register.buyer.check-email`; the seller non-XHR submit likewise goes to its OTP step (it previously skipped verification and went straight to "pending"). Buyer and seller now have **GET OTP pages** (`Buyer/register-buyer-verify-otp.blade.php`, `Seller/register-seller-verify-otp.blade.php`) mirroring logistics, so an unverified account can resume verification without re-registering, and a login attempt by an unverified account shows a link to that page. The three OTP **resend** routes are now `throttle:5,1` (verify stays `throttle:10,1`). OTP hashing is now a **keyed HMAC** (`hash_hmac('sha256', $otp, config('app.key'))`); `HasEmailOtp::hashOtp()` is `public static` so tests seed the code exactly the way the models store it.

---

## 8. Decisions made, and open decisions

### Decisions (dated)
| Date | Decision |
|---|---|
| 2026-10-16 | **One account per browser** — signing in on one guard clears the other three; logout clears all (`ClearsOtherGuards`). |
| 2026-10-16 | Only `PaymentMethod::Cod` is **integrated**; GCash / Bank transfer / Card are selectable, stored `pending`, and labelled so checkout never blocks. |
| 2026-10-23 | **Delivery policy clarified:** two total attempts (initial delivery + one retry). The second failure requires Logistics return; not two reschedules or a third failure. Counting/enforcement are centralized in `SellerOrderStatusService`. |
| 2026-10-16 | Delivery is routed by **provider-scoped areas**; `areas` + `couriers.area_id` + `seller_orders.destination_area_id`; every assignment is logged to `delivery_assignments`. |
| 2026-10-16 | Tracking number is minted **at order placement** so a waybill can be printed before pickup. |
| 2026-10-17 | **Admin approval Stage 3:** registration queue defaults to **all-time** for pending; list uses SQL UNION pagination; approve/reject already **mail**; document `{document}` whitelisted; View details modal wired; User Accounts suspend/reactivate + `suspended` status; dead admin `#` links replaced with "Soon" labels; `AdminRole` enum enforced on save. |
| 2026-10-17 | **OTP ownership via signed URLs:** every OTP show/verify/resend route requires a temporary signed URL (`SignedOtpLink`, 60 min) plus IP throttles (`otp-show` 30/min, `otp-verify` 10/min, `otp-resend` 5/min). Unsigned id guessing cannot lock verification or trigger mail. OTP + registration-decision notifications use **`mail`**; order alerts use **`database`** (docs previously claimed database-only for all — that was wrong). |
| 2026-10-20 | **Rider notifications are database-only** (`Notifications/Rider/*`), dispatched by `RiderNotifier` after the owning service transaction commits. A payload `event_key` suppresses replayed events; links point at Rider list/account pages so `rider.active` and the list query revalidate ownership when opened. No WebSocket, Redis, queue or email channel was added. |
| 2026-10-16 | **OTP hardening:** buyer/seller/logistics share one OTP implementation (`HasEmailOtp`) and one limit implementation (`VerifiesEmailOtp`). Per account: 5 wrong codes → 15-minute lock (HTTP 429 / validation error); 3 resends / 10 min. The three **verify** routes are `throttle:10,1`; the three **resend** routes are `throttle:5,1`. `commitOtp()` no longer resets the failure counter — only a successful verification does. |
| 2026-10-16 | **OTP codes are keyed:** stored as `hash_hmac('sha256', $otp, config('app.key'))`, not a bare sha256, so a leaked code hash is useless without `APP_KEY`. Single change point is `HasEmailOtp::hashOtp()` (public static). |
| 2026-10-16 | Unverified accounts can **resume verification**: buyer/seller/logistics each have a GET OTP page, and the login surfaces link to it when a correct-credentials login is blocked by `unverified` status. |
| 2026-10-16 | **No secrets in logs:** raw OTPs, emails and phones are removed from every `Log::` call; normal events log at `info`, failures at `error`. Production must use `LOG_LEVEL=warning` and `MAIL_MAILER=smtp`. |
| 2026-10-15 | Every variant stock change goes through `StockService` (`lockForUpdate` + ledger row). |
| 2026-10-12 | Registration statuses standardised to `unverified`/`pending`/`approved`/`rejected`; invalid admin input coerces to `pending`. |
| standing | All seller-order status changes go through `SellerOrderStatusService::transition()` — never set `->status` directly. |
| standing | `status` and `courier_id` are intentionally **not** mass-assignable; controller-owned audit fields use `forceFill()`. |
| standing | **Logistics owns the courier lifecycle** — there is deliberately no buyer/seller rider-registration UI. |

### Open decisions / questions for the owner
1. **Shipping fee** — none exists. Is delivery intentionally free for the MVP?
2. **Gateway refund** — returns restore stock but never reverse payment. What is the refund mechanism?
3. **`deliveries` table** — build it, or formally accept the `seller_orders` + `status_histories` deviation?
4. **Session hardening for production** — `SESSION_ENCRYPT` / `SESSION_SECURE_COOKIE` / `secure` cookies.
5. **Rider API** — Sanctum, or a hand-rolled hashed token table? (Batch D)
6. **Commission model** — where 10% is recorded and how seller earnings are surfaced.
7. **Dead code** — delete `Seller/ProductImage.php`, `Seller/Product.php`, `Seller/Products/show.blade.php`, `Admin\SellerRegistrationController`, `Auth/LoginChoiceController`? (They are listed in `3-Folder-Structure.md`.)
8. **Registration test failures** — fix the 403-vs-302 + `postal_code` assertions, or accept?
9. **Test baseline** — establish the true pass/fail count.

---

## 9. Mistakes already made — do not repeat

| Mistake | Correction |
|---|---|
| **Route shadowing:** a wildcard route registered before a literal swallows it (`/messages/{conversation}` before `/messages/unread-count`; `/cart/{id}` before `/cart/voucher`). | Register literal routes **first**; keep the inline ordering comments in `routes/buyer.php` / `routes/seller.php`. |
| **Enum constant drift:** middleware referenced `PendingVerification`/`PendingApproval` that the migration never created → fatal error for non-approved logistics users. | When you standardise DB values, grep every enum/middleware/view for the old cases. |
| **Phantom attributes:** views/controllers referenced `seller->name` (no such column; it is `business_name`). | Check the migration before referencing a model column. |
| **Mass-assignment drops:** audit fields (`approved_by`, `approved_at`, `rejection_reason`) silently dropped by `update()`. | Use `forceFill()` for controller-owned audit data (or add to `$fillable` deliberately). |
| **Migration vs test-DB divergence:** a migration passed SQLite `:memory:` but was missing on MySQL. | Migrate the real DB and run tests; don't trust `:memory:` alone. |
| **Docs drift:** several docs asserted bugs that were already fixed (wishlist, `buyer.home`, `show.blade`, chat internals). | **Verify in code, not docs.** This file marks anything unverified as such. |
| **Stash hygiene:** uncommitted work was lost/confused across git stashes. | Commit in logical chunks; keep the working tree clean. |
| **Secrets in logs:** the raw seller OTP and recipient emails were written to the log (and `MAIL_MAILER=log` writes full email bodies, OTP included, to disk). | Never log OTPs/passwords/tokens/emails; use `MAIL_MAILER=smtp` and `LOG_LEVEL=warning` in production. |

---


## Buyer completion update (2026-10-17)

- Returned-order confirmation (C7) is fixed: delivered shipments can be confirmed independently. Returned shipments remain terminal; all-returned parents stay Returned, and resolved Completed/Returned mixes roll up to Completed.
- Variant selection is required by CartVariantResolver for option-bearing or multi-SKU products, for both cart-add and Buy Now. Cards open product details for selection.
- Purchase-verified product reviews are implemented: completed purchases only, 1-5 rating, optional feedback up to 2,000 characters, one editable review per buyer/product. Product/store ratings now use real review aggregates.
- The reviews-table migration has been applied locally. Formatting, asset build, Blade compilation, and read-only public-page rendering passed. Full browser purchase/delivery journeys remain unverified; no test suite was run.
