# 2 — Design

> How Zefanya is built. Companion to `1-Context.md`, `3-Folder-Structure.md`, `4-Flow.md`.
> **Source of truth: migrations, enums, services, controllers, config/auth.** Unverified claims say **unverified**.
> Money: order/ledger amounts are integer **centavos**; catalog prices are `decimal(10,2)` **pesos** (see §3.3).

---

## 1. Architecture overview

**Monolithic, server-rendered Laravel.** Blade views + vanilla JS (Vite/Tailwind) + MySQL. No SPA, no API layer yet.

- **One login guard per role**, each a normal session guard with its own provider/model/table (`config/auth.php`).
- **Routing** is split per area: `routes/web.php` (shared/auth), `routes/buyer.php`, `routes/seller.php`, `routes/logistics.php`, `routes/admin.php`. `buyer.php` is registered in `AppServiceProvider::boot()` (with its own `/buyer` prefix + `buyer.` name); `admin.php`/`seller.php`/`logistics.php` are registered in `bootstrap/app.php`.
- **Where logic lives:**
  - **Controllers** orchestrate HTTP, validate (via form requests), authorize (via policies/middleware), and call services. They never set `seller_orders.status` directly.
  - **Services** hold the invariants: `SellerOrderStatusService` (the only writer of status), `CourierAssignmentService`, `StockService`, `VariantSyncService`, `CartSummary`.
  - **Models** hold relations, casts, small domain helpers (OTP, tracking-number generation, rollup computation).
  - **Middlewares** gate panel access by approval status and admin role.
- **Background work:** none meaningful. `QUEUE_CONNECTION=sync`; notifications run inline. There is one artisan command, `orders:resync` (`app/Console/Commands/OrdersResyncCommand.php`).
- **One scheduler?** `routes/console.php` registers only `inspire`. No scheduled tasks.

### 1.1 The service layer

| Service | File | Responsibility |
|---|---|---|
| `SellerOrderStatusService` | `app/Services/SellerOrderStatusService.php` | **The only writer of `seller_orders.status`.** Compare-and-swap under `lockForUpdate`, forward-only graph + role gate, writes `status_histories`, rolls up the parent order, settles COD, restores stock on `Returned`. |
| `CourierAssignmentService` | `app/Services/CourierAssignmentService.php` | Pickup/delivery rider selection (explicit → area → municipality); sets the courier column, flips `couriers.is_available`, writes a `delivery_assignments` row. |
| `StockService` | `app/Services/StockService.php` | Seller-initiated variant stock changes; absolute quantity + `inventory_transactions` ledger row; refreshes rollups. |
| `VariantSyncService` | `app/Services/VariantSyncService.php` | Rebuilds option axes (max 2) + variant combinations from a seller submission. |
| `CartSummary` | `app/Services/CartSummary.php` | Cart math in integer centavos: subtotal, voucher discount, total, seller grouping, JSON payload. |

`SellerOrderStatusService::transition()` is the single choke-point: **never** write `status` anywhere else. Callers pass an `OrderActor` (`Seller`, `Logistics`, `Rider`, `Buyer`); passing `actor: null` **bypasses** the role gate (`SellerOrderStatusService.php:124`) and must be avoided.

---

## 2. Status model

### 2.1 OrderStatus (identity map to ERP names)

`app/Enums/OrderStatus.php` — a **string-backed** enum. `->value` is written verbatim to the DB. There is **no separate ERP mapping layer**: the enum values *are* `PLACED … COMPLETED`, `DELIVERY_FAILED`, `RETURNED`.

| # (rollup rank) | Case | Value | bucket |
|---|---|---|---|
| 0 | Placed | `PLACED` | to_ship |
| 1 | Confirmed | `CONFIRMED` | to_ship |
| 2 | Preparing | `PREPARING` | to_ship |
| 3 | ReadyForPickup | `READY_FOR_PICKUP` | ready |
| 4 | PickedUp | `PICKED_UP` | shipped |
| 5 | AtSortingCenter | `AT_SORTING_CENTER` | shipped |
| 6 | Sorted | `SORTED` | shipped |
| 7 | AssignedToRider | `ASSIGNED_TO_RIDER` | shipped |
| 8 | OutForDelivery | `OUT_FOR_DELIVERY` | shipped |
| 9 | Delivered | `DELIVERED` | delivered |
| 10 | Completed | `COMPLETED` | delivered |
| 11 | DeliveryFailed | `DELIVERY_FAILED` | delivered |
| 12 | Returned | `RETURNED` | delivered |

**Storage is a VARCHAR(20), not a DB enum.** `orders.status`, `seller_orders.status`, `status_histories.from_status/to_status` are all `string(20)` (migrations `2026_09_22_110001:33`, `2026_09_22_110002:28`, `2026_09_25_000004:23-25`). The `OrderStatus` docblock that claims a native ENUM is **wrong**. `status` is intentionally **not** fillable; it is set via `forceFill`.

### 2.2 Allowed transitions

**Graph gate** (`SellerOrderStatusService.php:41-55`), forward-only, no skips, no reversals:

```
PLACED            -> CONFIRMED, READY_FOR_PICKUP
CONFIRMED         -> PREPARING
PREPARING         -> READY_FOR_PICKUP
READY_FOR_PICKUP  -> PICKED_UP
PICKED_UP         -> AT_SORTING_CENTER
AT_SORTING_CENTER -> SORTED
SORTED            -> ASSIGNED_TO_RIDER
ASSIGNED_TO_RIDER -> OUT_FOR_DELIVERY
OUT_FOR_DELIVERY  -> DELIVERED, DELIVERY_FAILED
DELIVERED         -> COMPLETED
DELIVERY_FAILED   -> ASSIGNED_TO_RIDER, RETURNED
RETURNED          -> COMPLETED          <-- no actor owns this edge (see C7 in 1-Context)
COMPLETED         -> (terminal)
```

**Role gate** (`app/Enums/OrderActor.php:25-71`) — a `(from,to)` pair is valid only if the acting role is allowed it:

| from → to | actor |
|---|---|
| PLACED → CONFIRMED | seller |
| CONFIRMED → PREPARING | seller |
| PREPARING → READY_FOR_PICKUP | seller |
| PLACED → READY_FOR_PICKUP | seller *(extra hop)* |
| READY_FOR_PICKUP → PICKED_UP | rider |
| PICKED_UP → AT_SORTING_CENTER | rider |
| ASSIGNED_TO_RIDER → OUT_FOR_DELIVERY | rider |
| OUT_FOR_DELIVERY → DELIVERED | rider |
| OUT_FOR_DELIVERY → DELIVERY_FAILED | rider |
| AT_SORTING_CENTER → SORTED | logistics |
| SORTED → ASSIGNED_TO_RIDER | logistics |
| DELIVERY_FAILED → ASSIGNED_TO_RIDER | logistics |
| DELIVERY_FAILED → RETURNED | logistics |
| DELIVERED → COMPLETED | buyer |
| *(none — empty)* | system |

**Delivery attempt policy (confirmed 2026-10-23):** Two total attempts: the initial delivery plus one retry. `SellerOrderStatusService::MAX_DELIVERY_ATTEMPTS = 2` is the shared limit. The legacy column `seller_orders.delivery_attempts` counts failed attempts, not starts: 0 before any failure, 1 after the first failure, 2 after the second. `transition()` increments it atomically only on a real `OUT_FOR_DELIVERY -> DELIVERY_FAILED` hop; duplicate reports do not increment it. `canRetryDelivery()` permits retry only from `DELIVERY_FAILED` with exactly one recorded failure. The second failure leaves the parcel failed until Logistics returns it; no third attempt is allowed. Changing Riders does not reset the count. A failed parcel with missing (zero) history requires review; no historical count is guessed or rewritten.

### 2.3 Order-level vs seller-order-level status

`orders.status` is a **derived rollup** of its slices:
- `Order::computeRollupStatus()` (`app/Models/Order.php:65-83`): all slices `Completed` → `Completed`; otherwise the **minimum-rank** status among non-`Completed` slices (rank = declaration order above).
- Persisted by `Order::rollUpStatus()` and re-run inside the transition transaction by `SellerOrderStatusService::rollUpParentOrder()` (`:306-323`, locks the parent row).
- `Order::canBeConfirmed()`: true when at least one shipment is Delivered. Delivered shipments can be confirmed independently of in-transit, Completed or Returned siblings; BuyerShipmentConfirmation validates and locks the selected shipment(s).

---

## 3. Database design

All tables below are derived from `database/migrations/`. Notation: `NN` = NOT NULL, `NULL` = nullable, `intU` = unsignedInteger, `bigintU` = unsignedBigInteger. Every table with `->timestamps()` has `created_at`/`updated_at`.

### 3.1 Framework / legacy tables
`users` (Laravel default — **no FK points to it; app auth uses buyers/sellers/admins/logistics_providers**), `password_reset_tokens` (email PK, token, created_at — shared by **all five** brokers), `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `notifications` (Laravel morph `notifiable`, no FK — where database-channel notifications are stored).

### 3.2 Identity tables

**`admins`** — id, name, email *(unique)*, email_verified_at, password, `role` varchar default `admin` (comment lists `admin, super_admin, moderator, support`), remember_token, timestamps.

**`buyers`** — id, last_name, first_name, middle_initial, `sex` enum(`male`,`female`), email *(unique)*, `password` **nullable**, `google_id` *(unique, nullable)*, remember_token, **`contact_no(11)` unique nullable**, birthday, age *(column still written on create; model accessor recomputes from birthday)*, `address_mode` enum(`api`,`manual`) default `api`, province/municipality/barangay `_code`+`_name`, street, house_number, address_detail, **postal_code(10)**, upload_id_path, email_verification_code, email_verification_expires_at, email_verified_at, `otp_attempts` tinyint default 0, `settings` json, `status` enum(`unverified`,`pending`,`approved`,`rejected`) default `unverified`, admin_remarks, rejection_reason, approved_by **FK→admins.id (nullOnDelete)**, approved_at, **`archived_at`**, **`registration_email`**, timestamps. Global scope hides archived rows.

**`sellers`** — same personal/address/archive columns as buyers plus `business_name`, `line_of_business_id` **FK→categories.id (nullOnDelete)**, upload_id_path, business_permit_path, same verification/status/audit columns. `status` enum default `unverified`. **`contact_no` unique nullable**.

**`logistics_providers`** — id, name fields, `sex` enum(`male`,`female`) *(aligned with buyers/sellers)*, email *(unique)*, password nullable, google_id *(unique)*, **`contact_no` unique nullable**, birthday, age *(accessor from birthday)*, `business_name`, address columns, postal_code, upload_id_path, dti_permit_path, verification/status/audit + archive columns (`status` enum default `unverified`), admin_remarks.

**One email = one role:** `App\Support\EmailRegistry` rejects cross-role registration and refuses ambiguous unified/Google login. Re-registration **archives** (does not delete) leftover `unverified`/`rejected` rows.

**`categories`** — id, icon, label; **seeded fixed list (14 rows)**; not CRUD-editable.

### 3.3 Catalog (denormalized rollups live here)

**`products`** — id, seller_id **FK→sellers.id (cascade)**, category_id **FK→categories.id (nullOnDelete)**, name, `slug` *(unique)*, description, **`price` decimal(10,2) — pesos (rollup)**, **`stock_quantity` intU default 0 — SUM of variants (rollup)**, low_stock_threshold, weight_grams, dimensions json, **`status` varchar(20) default `draft`** (was an enum; converted to string), softDeletes.

**`product_variants`** — id, product_id **FK (cascade)**, `sku` varchar(64) *(indexed, not unique — per-seller uniqueness is app-level)*, **`price` decimal(10,2)**, **`stock_quantity` intU**, `is_available` bool default true, weight_grams, dimensions json, timestamps.

**`product_options`** — id, product_id FK cascade, name(50), sort_order — index `(product_id, sort_order)`.
**`product_option_values`** — id, product_option_id FK cascade, value(100), sort_order — index `(product_option_id, sort_order)`.
**`product_variant_option_values`** — pivot (variant_id, option_value_id), both FK cascade, **unique `(product_variant_id, product_option_value_id)`** (named explicitly — the auto name exceeds MySQL's 64-char limit).
**`product_images`** — id, product_id FK cascade, `variant_id` FK **nullOnDelete** (product-level when null), path, is_primary, sort_order.

> **Money split:** catalog money is `decimal(10,2)` pesos. **Every order/ledger money column is integer centavos.** Converting `products.price` ⇄ `order_items.price_minor` requires ×100. `CartItem::unitPriceCentavos()` uses `bcmul(price,'100',0)` (`app/Models/CartItem.php:58`).

### 3.4 Cart / checkout / wishlist / newsletter

**`cart_items`** — id, buyer_id FK cascade, product_id FK cascade, `variant_id` FK **cascade**, quantity, **unique `(buyer_id, product_id, variant_id)`** (replaced the old 2-col unique; NULL variants are not deduped on MySQL).
**`checkout_tokens`** — id, `token` *(unique)*, buyer_id FK cascade, used bool, expires_at; guards double-submit. Indexes `(buyer_id, used)`, `(expires_at)`.
**`wishlists`** — id, buyer_id FK cascade, product_id FK cascade, **unique `(buyer_id, product_id)`**.
**`newsletter_subscribers`** — id, `email` *(unique)*, is_active — **no route/subscribe UI (missing)**.
**`vouchers`** — id, `code` *(unique)*, `type` varchar (`fixed`|`percent`), `value` bigintU (**centavos** when fixed; 0–100 when percent), `min_spend` bigintU (**centavos**), is_active, expires_at, usage_limit, `times_used` intU default 0. **No FK to orders.**

### 3.5 Orders / fulfilment / money

**`orders`** — id, buyer_id **FK→buyers.id (restrictOnDelete)**, `reference` *(unique)*, `shipping_address` **json (immutable snapshot — deliberately not a live FK)**, `subtotal_minor` intU, `discount_minor` intU, `total_minor` intU, `status` varchar(20) default `PLACED`. Index `(buyer_id, created_at)`.

**`seller_orders`** — id, order_id **FK→orders.id (restrict)**, seller_id **FK→sellers.id (restrict)**, `subtotal_minor` intU (slice **before** the order-level voucher discount), `status` varchar(20) default `PLACED`, `tracking_number` varchar(64) *(unique)*, `courier_id` **FK→couriers.id (nullOnDelete)** (pickup), `delivery_courier_id` **FK→couriers.id (nullOnDelete)** (delivery), `delivery_failure_reason` varchar(255), `delivery_failure_notes` text, `handover_at` timestamp, `delivery_attempts` tinyint default 0, `destination_area_id` **FK→areas.id (nullOnDelete)**. **Unique `(order_id, seller_id)`**.

**`order_items`** — id, seller_order_id **FK (restrict)**, product_id **FK→products.id (nullOnDelete)**, variant_id **FK→product_variants.id (nullOnDelete)**, `product_name` (snapshot), nullable JSON `variant_options` (ordered purchase-time option name/value snapshots; NULL for legacy rows, [] for optionless purchases), quantity, **`price_minor` intU (snapshot)**.

**`payments`** — id, seller_order_id **FK (restrict)** *(unique — 1:1)*, `amount_minor` intU, `method` varchar(20) default `cod`, `status` varchar(20) default `pending`, `paid_at` timestamp.

**`inventory_transactions`** — id, product_id **FK (restrict)**, `seller_order_id` **nullable** FK (restrict), variant_id **FK (nullOnDelete)**, quantity_before, quantity_after, **`delta` signed int**, `reason` varchar(30) (`checkout`, `return`, `seller_initial`, `seller_adjustment`). Index `(product_id, created_at)`. **Append-only.**

**`status_histories`** — id, seller_order_id **FK (restrict)**, from_status, to_status, `changed_by` varchar(30), `actor_type` varchar(20), `actor_id` bigintU, `source` varchar(20) (`web`|`simulator`|`api`|`system`), note. Indexes `(seller_order_id, created_at)`, `(seller_order_id, actor_type)`, `(source)`. **Append-only.**

**`delivery_assignments`** — id, seller_order_id **FK (cascade)**, courier_id **FK→couriers.id (nullOnDelete)**, `role` varchar(12) (`pickup`|`delivery`), `assigned_by_type` varchar(20), `assigned_by_id` bigintU, notes(500). Index `(seller_order_id, role)`. **Append-only assignment history.**

### 3.6 Logistics

**`couriers`** — id, logistics_provider_id **FK (restrict)**, `area_id` **FK→areas.id (nullOnDelete)**, name, phone(11), `service_area` **json** (legacy free-form list), `is_available` bool default true, `vehicle_type` varchar(20) default `motorcycle`, `max_weight_grams` intU default 5000. Indexes `(is_available, vehicle_type)` and `(logistics_provider_id)`.

**`areas`** — id, logistics_provider_id **FK (cascade)**, name, `municipality`, `is_active` bool default true. **Unique `(logistics_provider_id, municipality)`**.

> Two parallel coverage models exist: the legacy `couriers.service_area` JSON and the newer `areas` table. The `areas` table supersedes it but `service_area` is still NOT NULL.

### 3.7 Messaging
**`conversations`** — id, buyer_id FK cascade, seller_id FK cascade, product_id (set null), order_id (set null), `last_message_at` (denormalized). **Unique `(buyer_id, seller_id)`** — so `product_id`/`order_id` cannot actually scope more than one thread per pair.
**`messages`** — id, conversation_id **FK (cascade)**, `sender_role` **enum(`buyer`,`seller`)** *(a native enum — inconsistent with the "no native enum" policy)*, sender_id (no FK), body, read_at. Index `(conversation_id, created_at)`.

### 3.8 FK delete-behaviour cheat-sheet
- **cascade**: products→sellers; variants/options/option-values→products/options; product_images→products; cart_items→buyers/products/variants; wishlists; checkout_tokens→buyers; delivery_assignments→seller_orders; areas→logistics_providers; conversations/messages.
- **restrict**: orders→buyers; seller_orders→orders/sellers; order_items→seller_orders; payments→seller_orders; inventory_transactions→products/seller_orders; status_histories→seller_orders; couriers→logistics_providers.
- **nullOnDelete**: approved_by→admins; variants referenced from product_images/order_items/inventory_transactions; couriers from seller_orders; areas from seller_orders/couriers; product_id from order_items; category/line_of_business.
- **Inconsistency:** `cart_items.variant_id` is **cascade** while every other `variant_id` FK is **nullOnDelete**.

### 3.9 Indexes / unique summary
`products.slug`; `vouchers.code`; `orders.reference`; `seller_orders.tracking_number`; `seller_orders (order_id, seller_id)`; `payments (seller_order_id)`; `product_variant_option_values (variant_id, value_id)`; `cart_items (buyer, product, variant)`; `wishlists (buyer, product)`; `areas (provider, municipality)`; `newsletter_subscribers.email`; `couriers (is_available, vehicle_type)`; `inventory_transactions (product_id, created_at)`; `status_histories (seller_order_id, created_at)`.

---

## 4. Auth and security design

### 4.1 Guards & providers (`config/auth.php`)
| guard | provider | model | table |
|---|---|---|---|
| `web` (default) | users | `App\Models\Shared\User` | `users` *(unused by features)* |
| `admin` | admins | `App\Models\Admin\Admin` | `admins` |
| `buyer` | buyers | `App\Models\Buyer\Buyer` | `buyers` |
| `seller` | sellers | `App\Models\Seller\Seller` | `sellers` |
| `logistics` | logistics | `App\Models\Logistics\LogisticsProvider` | `logistics_providers` |

**Password brokers:** `users`, `buyers`, `sellers`, `logistics`, `admins` — all share the `password_reset_tokens` table, `expire` 60 min, `throttle` 60 s.

### 4.2 Middleware
| Alias | Class | Behaviour |
|---|---|---|
| `admin.role:...` | `Admin/EnsureAdminRole` | `super_admin` always passes; otherwise the role must be in the list. `admin.role:admin,moderator` **excludes `support`** → `support` reaches only the dashboard. No user → 403. |
| `seller.approved` | `Seller/EnsureSellerApproved` | Not approved → logout + invalidate session + redirect `login` with a status message (`unverified`/`pending`/`rejected`/default). |
| `logistics.approved` | `Logistics/EnsureLogisticsApproved` | Not `Approved` → logout + invalidate + redirect `login` with an enum-matched message. |

Guest/authenticated redirects are configured in `bootstrap/app.php`: guest HTML requests under `admin/*` go to `admin.login`; other protected web requests go to `home` with `login=1`, opening the shared login modal. JSON requests return 401. The modal submits to `unified.login` (`POST /login`); there is no named `login` GET route. Authenticated users bounce to their own dashboard. Verified against the current middleware/routes on 2026-10-18.

### 4.3 Policies & gates
| Policy | Model / gate | Abilities | Authorizes |
|---|---|---|---|
| `BuyerOrderPolicy` | `Order` | `view`, `confirmReceived` | buyer owns the order; foreign orders return 404 for both actions (`confirmReceived` delegates to `view`) |
| `SellerOrderPolicy` | `SellerOrder` | `view`, `markReady` | seller owns the slice (`seller_id`) |
| `SellerOrderFleetPolicy` | gate **`advance-shipment`** | `advance` | logistics provider owns `courier`/`deliveryCourier`; **any** provider if no courier yet |
| `ConversationPolicy` | `Conversation` | `view`,`update`,`create` | buyer/seller party to the conversation |
| *(auto-discovered)* | `Conversation` | — | resolved by naming convention; **not** in `AppServiceProvider` |

Registration is explicit in `AppServiceProvider::boot()` (`:38-44`). `Admin\RegistrationController` relies **only** on the `admin.role` middleware (no policy); seller product CRUD relies on `auth:seller` + query scoping.

### 4.4 OTP design (buyer/seller/logistics share ONE implementation)

**Access control:** OTP show / verify / resend are **temporary signed URLs** (`SignedOtpLink`, 60 minutes). The sequential `{id}` alone is rejected (`middleware: signed` → 403). Login / Google / approval middleware flash a signed `auth.resume_url` when an unverified account tries to sign in.
The logic lives in two shared traits so the three roles cannot drift:
`app/Models/Concerns/HasEmailOtp.php` (generate → hash → expire → compare) and
`app/Http/Controllers/Concerns/VerifiesEmailOtp.php` (the two rate windows).
All three verify controllers `use VerifiesEmailOtp`; all three models `use HasEmailOtp`.

- **Generate:** `random_int(100000, 999999)` (6 digits).
- **Store:** `email_verification_code = hash_hmac('sha256',$otp,config('app.key'))` (raw never stored; column is `$hidden`), `email_verification_expires_at = now()+10 min`.
- **Verify:** constant-time `hash_equals(stored, hmac(submitted))`; expiry 10 min.
- **On success:** `markEmailVerified()` sets `email_verified_at`, clears code+expiry, `status = pending` (buyer/seller string; logistics `LogisticsProviderStatus::Pending`), `otp_attempts = 0`, and **clears the failure window**.
- **Resend:** allowed only while unverified; the **new code is committed only after the mail send succeeds** (a failed send keeps the old code valid → HTTP 503). `commitOtp()` deliberately does **not** touch the failure counter, so a resend can never unlock a brute-forced account.
- **No secrets in logs:** no controller or notification logs the code, the email or the phone (see §4.8).
- ✅ **Keyed HMAC:** the code is stored as `hash_hmac('sha256',$otp,config('app.key'))` (not a bare sha256), so a leaked code/hash is useless without `APP_KEY`. The single change point is `HasEmailOtp::hashOtp()` (now `public static`, so `BuyerRegistrationTest` seeds the code through the same helper).

### 4.5 Rate limits

**Named limiters (`AppServiceProvider`) + route throttles:**
| Limit | Applied to |
|---|---|
| `register`: **5 / min per email+IP** and **30 / min per IP** | all three registrations (shared-network friendly; no longer a hard 5/IP) |
| `otp-show`: **30 / min per IP** | all three OTP **GET** pages (signed) |
| `otp-resend`: **5 / min per IP** | all three OTP **resend** routes (signed) |
| `otp-verify`: **10 / min per IP** | all three OTP **verify** POSTs (signed) |
| 5 / 1 min (route) | unified login; seller login; admin login; forgot-password; reset-password; unified logout |
| 10 / 1 min (route) | the whole `/auth/google/*` group |

**OTP ownership:** every OTP GET/verify/resend URL is a Laravel **temporary signed URL** (60 min, `App\Support\SignedOtpLink`). Changing the numeric id invalidates the signature → **403**. Strangers cannot open another applicant's page, lock their 5-attempt window, or trigger resend mail.

**Per-email OTP limits (`VerifiesEmailOtp`, keyed `otp-verify:{role}:{email}` / `otp-resend:{role}:{email}` — archive+re-register cannot reset; a resend cannot unlock):**
| Limit | Rule |
|---|---|
| OTP verify | **5 wrong codes → that email is locked for 15 minutes.** JSON → HTTP **429**. Cleared **only** on successful verification. |
| OTP resend | **3 resends per 10 minutes** per email; exceeding it returns HTTP 429. |

### 4.6 Uploads & document storage
- Disk **`local`** → `storage/app/private` (**not** web-served). `public` disk is separate.
- Paths: buyer `ids/`; seller `seller-ids/` + `business-permits/`; logistics `logistics-ids/` + `logistics-permits/`.
- Validation (all three): `file`, `mimes:jpg,jpeg,png,pdf`, `max:5120` (5 MB).
- Old files are deleted when a rejected/unverified applicant re-registers.
- **Streaming:** `Admin\DocumentController@stream` — `{type}` limited to `seller|logistics|buyer`, `{document}` whitelisted to `id|business_permit|dti_permit` (route `where` + controller `match` on known path columns). Private-disk download with `X-Content-Type-Options: nosniff`; gated by `auth:admin` + `admin.role`.

### 4.7 Session rules — one account per browser
`app/Http/Controllers/Concerns/ClearsOtherGuards.php`. Participating guards: `['admin','seller','logistics','buyer']` (the `web` guard is **not** included).
- `clearOtherGuards($keep)` — called after every successful login (unified, seller, admin, Google): logs out the other three.
- `clearAllGuards()` — called on every logout: ends all four.
- Every login also `session()->regenerate()`; every logout `invalidate()` + `regenerateToken()`.
- Guest-only pages (`guest.role` / `RedirectIfAuthenticatedRole`) redirect any signed-in role to that role's dashboard without logging out — switching accounts requires an explicit logout.

### 4.8 Known weak points
- `SESSION_ENCRYPT=false`, `SESSION_SECURE_COOKIE=false` in `.env` (must be reviewed for production).
- `EnsureAdminRole` + `Admin` model validate roles against `AdminRole` enum (`super_admin` bypasses; unknown roles throw on save).
- OTP codes are stored with a **keyed HMAC** (sha256 + `APP_KEY`), not a bare sha256. See §4.4.
- **Production log/mail config:** use `LOG_LEVEL=warning` (never `debug`/`info`) and `MAIL_MAILER=smtp` — **never `log`**, which writes full email bodies (OTP included) to `storage/logs`.

**Fixed 2026-10-16 (previously listed here — now resolved):**
- `routes/web.php` no longer has stray bytes before `<?php`; no PHP file emits output after a closing `?>`.
- Buyer OTP verify is now throttled (`throttle:10,1`) like seller/logistics.
- No raw OTP / email / phone is written to logs anywhere in `app/`.

---

## 5. UI / design system (summary)

Source: the Tailwind v4 migration. Full detail lived in the old `ui-design-spec.md`; only what is **still true** is kept here.

- **Tailwind CSS v4** via `@tailwindcss/vite`. Single entry `resources/css/app.css`; **no `tailwind.config.js`** — tokens live in the `@theme` block of `app.css`.
- **Style with utilities in Blade.** Custom CSS only inside `@layer components` in `app.css` (e.g. seller sidebar collapse). **Never** add unlayered plain CSS (it beats every utility) or recreate `design-system.css`.
- `app.css` must be the **first** entry in a layout's `@vite([...])`.
- **Legacy exception:** the shared auth chain still uses `login-modal.css` and `auth.css` (imports `auth-pages.css`, `buyer/buyer-register-modal.css`) — pending migration; do not slim the `:root` token block in `app.css` until it's converted.
- **Fonts:** Playfair Display 400 (headings/modal titles), Montserrat 400/500/600/800 (body/UI). **Icons:** Lucide.
- **Palette (authoritative source is `@theme` in `app.css`):** Primary Rose, Secondary Coral, Tertiary Sage, Neutral greys; semantic success `#6B916B`, warning `#D4A017`, error `#C44A4A`, info `#C07A85`. Buyer adds `buyer-*` tokens.
- **Radius:** sm 4 / md 6 / lg 8 / xl 12 / full. **Shadows:** sm `0 1px 3px rgba(30,27,27,.08)` / md `0 4px 12px …` / lg `0 12px 32px …`.
- **Components** are utilities/components in Blade, not shared CSS classes (`.btn`/`.input`/`.tag` no longer exist).
- **Status badges:** Active = `tertiary-500`/white; Draft = `neutral-200`/`neutral-900`; Inactive/error = `#FBE9E7`/`#A5333D`.
- **Per-role layout shells:** public `Layouts/footer.blade.php` (no sidebar); buyer `Buyer/Layouts/app.blade.php` (no sidebar, account sidebar on account pages); seller `Layouts/seller.blade.php` (`<x-seller.sidebar/>`); logistics `Layouts/logistics.blade.php` (`<x-logistics.sidebar/>`); admin `Admin/Layouts/app.blade.php` (`@include('Components.admin-sidebar')`).
- **Theme keys are per-role:** `public.theme`, `seller.theme`, `logistics.theme`; buyer falls back to `zf-theme`. Toggle persisted to `localStorage: zf-theme`, shared JS `resources/js/seller/theme-toggle.js`.
- **Breakpoints in use:** seller 1100/720; admin 1200/700/1024.
- **CSS hazards:** unlayered CSS beats utilities; Preflight strips `<p>` margins and `a` underlines; keep `button:focus-visible` outline; don't break the navbar `auth-btn login-btn|register-btn` contract; after adding classes run `npm run build` (Tailwind only emits classes it can see).
- **Known cleanup:** hard-coded error hexes → `error` token; convert the auth chain; fix a stray `>` after `@csrf` in `Admin/Auth/login.blade.php`.

---

## 6. Notifications, messaging, integrations

### 6.1 Notifications
Channels are **split by purpose** (verified against each class's `via()`):

| Channel | Classes |
|---|---|
| **`mail`** | OTP + registration decisions: `BuyerVerifyOtp`, `SellerRegistrationOtp`, `LogisticsRegistrationOtp`, `BuyerRegistrationDecision`, `SellerRegistrationDecision`, `LogisticsRegistrationDecision` |
| **`database`** | In-app order/fulfilment alerts: `CourierAssigned`, `OrderOutForDelivery`, `OrderDelivered`, `DeliveryFailed` (buyer + seller), `NewOrderReceived`, `ParcelReturned`; Rider alerts: `Rider\{PickupAssigned,DeliveryAssigned,AssignmentReassigned,DeliveryReturned,StationChanged,AccountStatusChanged}` |

OTP **must** be mail — registration cannot complete if the code never leaves the server. Decision emails likewise use mail. Order lifecycle alerts are database-only (bell / notifications centre).

| Notification | Class | Channel | Sent when |
|---|---|---|---|
| Buyer registration decision | `Buyer\BuyerRegistrationDecision` | mail | admin approve/reject |
| Buyer OTP | `Buyer\BuyerVerifyOtp` | mail | registration; resend |
| Courier assigned | `Buyer\CourierAssigned` | database | `ASSIGNED_TO_RIDER` |
| Out for delivery | `Buyer\OrderOutForDelivery` | database | `OUT_FOR_DELIVERY` |
| Delivered | `Buyer\OrderDelivered` | database | `DELIVERED` |
| Delivery failed | `Buyer\DeliveryFailed` | database | `DELIVERY_FAILED` |
| Seller registration decision | `Seller\SellerRegistrationDecision` | mail | admin decision |
| Seller OTP | `Seller\SellerRegistrationOtp` | mail | registration; resend |
| **New order received** | `Seller\NewOrderReceived` | database | checkout commit (once per seller_order) |
| Delivery failed | `Seller\DeliveryFailed` | database | `DELIVERY_FAILED` |
| Parcel returned | `Seller\ParcelReturned` | database | `RETURNED` |
| Logistics registration decision | `Logistics\LogisticsRegistrationDecision` | mail | admin decision |
| Logistics OTP | `Logistics\LogisticsRegistrationOtp` | mail | registration; resend |
| Rider pickup assigned | `Rider\PickupAssigned` | database | `CourierAssignmentService::reservePickup()` (and legacy `assignPickup()`) |
| Rider delivery assigned | `Rider\DeliveryAssigned` | database | `CourierAssignmentService::assignDelivery()`; `rescheduled = true` after a failed attempt |
| Rider task reassigned | `Rider\AssignmentReassigned` | database | `assignDelivery()` when a different courier replaces the failed one |
| Rider delivery returned | `Rider\DeliveryReturned` | database | failed parcel returned to seller |
| Rider station changed | `Rider\StationChanged` | database | `RiderRegistrationService::changeStation()` |
| Rider account status | `Rider\AccountStatusChanged` | database | suspend / deactivate / reactivate |

Status-change notifications for Buyer/Seller are fired centrally from `SellerOrderStatusService::maybeNotify()` (`:224-252`). **No notification is sent for `COMPLETED`, `SORTED`, pickup, or the buyer on `RETURNED`.** Rider notifications are not emitted there: `CourierAssignmentService` and `RiderRegistrationService` call `RiderNotifier::send()` after their own transaction commits, and the stored `event_key` suppresses replayed events.

### 6.2 Messaging
`conversations` (buyer↔seller, one thread per pair) + `messages` (`sender_role` enum buyer|seller). Endpoints exist on both sides (`MessageController`) with a `unread-count` endpoint (literal route ordered before the wildcard). The **Chat** pages are separate stubs that redirect "coming soon" — messaging itself works through `/messages`.

### 6.3 External integrations
- **Google Sign-In** via Laravel Socialite. Routes: `/auth/google/redirect` + `/auth/google/callback` (role-agnostic) and `/auth/google/{role}/redirect|callback` for `seller|logistics|buyer`. On callback it links `google_id` and logs in (if approved); unknown email → error; not-yet-approved → blocked. Guard resolution order seller → logistics → buyer.
- **Mail:** SMTP locally, `log` in `.env.example`; OTP + decision notifications. No webhook/payment-gateway/push integrations exist. **Only COD is integrated** (`PaymentMethod::isIntegrated()` true only for `Cod`).

---


## Buyer shipment, variants and review invariants (2026-10-17)

- BuyerShipmentConfirmation checks order ownership, locks the selected shipment(s), and delegates Delivered -> Completed only to SellerOrderStatusService::transition(). A Returned shipment has no outgoing transition.
- Order rollup excludes resolved Completed/Returned shipments while others are active. All Returned -> Returned; any fully resolved mix containing Completed -> Completed. DeliveryFailed remains visible.
- CartVariantResolver owns required explicit selection, product/variant membership, enabled-state and positive-stock checks. Stock reservation still occurs only at checkout. Cards receive variant/review aggregates through Product::withBuyerCardData().
- reviews: id, buyer_id FK, product_id FK, order_item_id FK, rating (1-5), nullable body, timestamps; unique (buyer_id, product_id). ProductReviewService verifies the order item belongs to a Completed shipment owned by the buyer. Review writes serialize per buyer and update the existing review. Feedback is always escaped.
- Reviews become eligible after receipt confirmation, including delivered siblings of returned shipments. Product cards, product details and store cards use database aggregates; no invented rating fallback.


## Seller product publishing and color photos (2026-10-17)

`ProductPublishingService` owns the transactional save of one listing, its variants and image records. Newly stored files are removed if persistence fails. Variant stock is written through `StockService`; product price and stock are refreshed from the variants. The category remains the seller's registered line of business.

Color photos require no schema change: each upload is stored once and its path is associated through `product_images.variant_id` with every size variant of that Color/Colour value. Removing one image association preserves a file still referenced by another size. General images retain `variant_id = null`; `cover_index` chooses a newly uploaded cover. Variant synchronization captures old combination signatures before rebuilding option values so unchanged combinations preserve IDs, cart lines and image associations.

The request validates up to 50 unique, complete combinations, nonnegative prices and integer stocks, valid cover indexes, image content/type/size and product-owned variant-image uploads. The buyer variant payload includes image URLs and the Color/Colour axis; selecting a color previews its photo before a size is selected.
