# Zefanya — Project Overview

## What the application is
A multi-vendor e-commerce marketplace (Shopee/Lazada-style workflows, original UI/code) built as a **monolithic Laravel app**: Laravel + PHP 8.5, MySQL, Eloquent, Blade, Tailwind CSS v4 via Vite, Laravel Breeze-style auth, PHP backed enums for statuses. Icons: lucide (`<i data-lucide>`) + Font Awesome. Fonts: Playfair Display (`font-serif`), Montserrat (`font-sans`).

## Five roles
1. **Buyer** — browse, cart, checkout, pay, track, profile/addresses.
2. **Seller** — store, products, inventory, receives orders, fulfils up to READY FOR PICKUP.
3. **Logistics** — owns the ENTIRE courier lifecycle: courier applications, approval, courier records, assignment, delivery monitoring.
4. **Courier** — handles only deliveries assigned to them.
5. **Admin** — marketplace administration; does NOT own courier management.

## Order architecture (settled — do not restructure without approval)
```
orders → seller_orders → order_items
seller_orders 1—1 payments
seller_orders ← inventory_transactions (append-only stock ledger)
courier_applications → (Logistics approval) → couriers
```
- `orders.buyer_id → buyers` (RESTRICT); `seller_orders` unique `(order_id, seller_id)` — one slice per shop per order; `payments` unique `seller_order_id` (1:1, per seller_order, NOT per order).
- `orders`, `seller_orders`, `order_items`, `payments`, `inventory_transactions` are permanent records: never soft/hard deleted; cancel via status instead. FKs are RESTRICT (except `order_items.product_id` SET NULL).
- Money is unsigned-integer **centavos** everywhere, columns suffixed `*_minor` (subtotal/discount/total on `orders`, subtotal on `seller_orders`, amount on `payments`, price on `order_items`).
- `orders.shipping_address` is a JSON **snapshot** of the buyer's profile address at order time, not a live FK.
- Voucher discounts are allocated proportionally across seller_orders (largest-remainder) so per-shop COD payments sum exactly to `orders.total_minor`.
- No `deliveries`/`shipments`/courier tables yet — delivery pipeline is Phase 3.

## Database / status conventions
- Status columns are **VARCHAR**, never MySQL ENUM; values handled by **PHP backed enums** cast in models (`App\Enums\OrderStatus` = PLACED…RETURNED pipeline; `App\Enums\PaymentStatus` = pending|paid).
- Money columns are unsigned integer centavos suffixed `*_minor`; never floats or decimals for order money.
- Migration naming follows `2026_MM_DD_HHMMSS_snake_case_name.php`; never edit an executed migration — create a new one.
- Buyer-facing tables use the **buyer guard** (`App\Models\Buyer\Buyer`, provider `buyers` in `config/auth.php`). There is no `users`-table buyer.

## Cart + vouchers (current phase — DONE, pending developer verification)
- `cart_items` (buyer_id, product_id, quantity, timestamps; unique buyer_id+product_id; FK cascade) + `CartItem` model; `vouchers` (code, type fixed|percent, value, min_spend nullable, is_active, expires_at nullable, usage_limit nullable) + `Voucher` model + `VoucherType` enum; seeder `VoucherSeeder` with `ZEFANYANEW` = ₱200 fixed.
- Service `App\Services\CartSummary` is the single source of truth for cart math (integer **centavos** everywhere; server-computed money only). It computes lines, seller groups, selected/unavailable items, subtotal, voucher discount, total.
- `App\Http\Controllers\Buyer\CartController`: `add` (validates active product + quantity 1..stock, merges lines capped at stock, JSON `{cart_count}`; 401 JSON for guests so `buyer.js` opens the login modal), `update` (PATCH), `destroy`, `bulkDestroy`, `summary` (GET JSON), `applyVoucher`/`removeVoucher` (code stored in session, re-validated on every summary calc). Ownership checks on every action (403/404 otherwise).
- Routes: `buyer.cart.index/add/update/destroy/bulk-destroy/summary/voucher.apply/voucher.remove` inside the `auth:buyer` group (index+summary usable, guest 401s on JSON mutations).
- Views: `resources/views/Buyer/Cart/{index,_seller-group,_item,_summary,_empty}.blade.php`. Page JS: `resources/js/buyer/cart.js` (Vite input; loaded via `@push('scripts')`; server is the source of truth — selection/qty/voucher all re-fetched; page reloads into the server-rendered empty state when the cart empties).
- Checkout: `CheckoutController@index` accepts `?items[]=` cart-item ids, silently filtering to the buyer's own items, defaulting to all when none are passed. Checkout page itself was not redesigned.

## Checkout + orders (current phase — DONE, pending developer verification)
- **Schema** (migrations `2026_09_22_110001…110005`, NOT yet run by the agent — developer runs `php artisan migrate` on the live DB): `orders`, `seller_orders`, `order_items`, `payments`, `inventory_transactions` per the settled architecture above. `reference` = `ORD-YYYYMMDD-XXXXXX` unique.
- **Models**: `Order` (buyer→Buyer, hasMany sellerOrders; casts: status enum, address array, *_minor int; `Order::generateUniqueReference()`), `SellerOrder` (order + seller + hasMany items + hasOne payment), `OrderItem` (product_name/price snapshots, `lineTotalMinor()`), `Payment` (PaymentMethod/PaymentStatus casts, `markPaid()`; `status` NOT mass-assignable), `InventoryTransaction` (append-only ledger). `App\Exceptions\CheckoutException` for in-transaction aborts.
- **`CheckoutController@store`** (`buyer.checkout.store`, live 500 fixed): reads parked `checkout_item_ids` + `cart_voucher`; re-resolves from the LIVE cart scoped to the buyer (tampered/missing ids → flash error + redirect to cart, never silently drop); re-validates availability + stock; `Voucher::resolveCode` re-validation identical to index (invalid voucher → dropped with notice, order still places); totals only from `CartSummary`; then one `DB::transaction()` (product rows `lockForUpdate()` ordered by id, stock re-checked under lock, order + one SellerOrder per shop + OrderItems + one pending COD Payment per shop + stock decrement + `inventory_transactions` rows reason `checkout`); success deletes ONLY the selected cart lines, forgets both session keys, redirects to `buyer.checkout.success` (ownership 404 for other buyers).
- **Confirmation page**: `Buyer/Checkout/success.blade.php` — reference, status, per-seller groups with COD amounts, order totals, address snapshot, links to orders/home. No new Vite inputs.
- **Tests**: `tests/Feature/Http/Controllers/Buyer/CheckoutControllerTest.php` (10 tests, 85 assertions: happy path ×2 sellers, single-seller full discount, insufficient stock / removed item / soft-deleted product / tampered foreign id / empty selection / guest all reject with zero writes, expired voucher drop, confirmation 404). Full suite 137 tests green. Superseded Milestone-4 scaffolding (users-FK + MySQL-enum versions, incl. shipments/delivery_events) was deleted per contract `2026-09-22-buyer-checkout-place-order.md`.
- **Deferred**: seller fulfilment transitions (PLACED→…→READY_FOR_PICKUP), voucher `times_used` increment, COD `markPaid()` on delivery, address picker at checkout (snapshots profile address for now).

## Security rules (non-negotiable)
- `$fillable` allow-lists only (never `$guarded = []`); `status`/`courier_id` never mass-assignable from requests.
- Sensitive models get Policies; never rely on controller checks alone.
- Stock ops: `DB::transaction()` + `lockForUpdate()` + `inventory_transactions` row; never bare `decrement()`.
- Buyer sees only own cart/orders; seller only own seller orders; courier only own deliveries.
- Applied vouchers/discounts are always re-validated server-side; client-sent amounts are never trusted.

## Frontend conventions (project-specific, IMPORTANT)
- **No unlayered CSS** — it overrides Tailwind utilities here. Custom CSS only inside `@layer components` in `resources/css/app.css`; never build Tailwind class names dynamically in JS; toggle state classes styled via `[&.selected]:` variants.
- Use only `@theme` tokens (`buyer-ink`, `buyer-line`, `buyer-cream`, `buyer-ink-soft`, `bg-blush`, `primary-*`, `secondary-*`, `tertiary-*`, `neutral-*`, `success`). No inline hex where a token exists.
- Dark mauve buttons = `primary-800`. Cart-page DOM hooks use the `js-cartpage-*` / `cartpage-` prefixes (`.js-cart-toggle`, `.swatch`, `.size-opt`, `#qvQty` belong to `buyer.js`).
- Shared `resources/js/buyer/buyer.js` exposes `showToast`-style helpers and `addToCart(productId, quantity)`; it reads the real cart count from the navbar badge `#cartCount` on load (no hardcoded value).
- The navbar badge is fed by a `View::composer('Components.navbar')` in `AppServiceProvider` (real quantity sum for the authenticated buyer; 0 for guests, no query run).

## Logistics registration (NEW — 2026-09-21, awaiting developer verification)
- **Routes** (`routes/web.php`): `GET /register/logistics` now redirects to `register.type?open=logistics` (it previously rendered the non-existent `Auth.register-logistics` view and 500'd). `POST /register/logistics` → `Logistics\RegisterLogisticsController@store` (throttle 5,1). `register.logistics.verify-otp` / `.verify-otp.store` (10,1) / `.verify-otp.resend` (3,5) → `Logistics\VerifyLogisticsOtpController`. `register.logistics.pending`.
- **Table** `logistics_providers` (migration `2026_09_21_000001`): same personal/address fields as sellers (`address_mode`, PSGC codes, `street`, `house_number`, `address_detail`), `business_name`, `upload_id_path` + `dti_permit_path` (no line-of-business), seller-pattern OTP columns, `status` **VARCHAR** + `App\Enums\LogisticsProviderStatus` cast (rule #6 compliant), plus `rejection_reason`/`approved_by`/`approved_at` for the admin decision. `email` is unique in-table; reuse of a buyer/seller email is rejected in `StoreLogisticsRegistrationRequest`.
- **Flow** = seller's: store → `pending_verification` → OTP (hash, 10 min) → `pending_approval` → admin approve/reject in the unified queue (`Admin\Registrations.index` now takes `type=logistics`; `approveLogistics`/`rejectLogistics` + `Notifications\Logistics\LogisticsRegistrationDecision`). Uploads: `logistics-ids` / `logistics-permits` on the private `local` disk. **Age is never submitted** — client displays it, the controller recomputes it with `Carbon::parse($birthday)->age`.
- **Frontend**: `Components/logistics-register-modal.blade.php` (4 panels, reuses the `buyer-modal-overlay` + `auth.css` shell — no new CSS), `resources/js/logistics/logistics-register-modal.js` (Vite entry added; engine copied from the working buyer modal), trigger `[data-logistics-register-trigger]` on `Auth/Register-Type.blade.php`.
- **Every logistics element id is prefixed `logistics`** — the buyer and seller modals share generic ids (`birthday`, `birthMonth`, `province`, `email`, `contactNo`, …) on the register-type page, so a third modal without unique ids would bind to the first modal's nodes via `getElementById`.
- **Deferred (out of scope, not built)**: no `logistics` auth guard/provider in `config/auth.php`, no logistics login/dashboard, no `riders`/`couriers` tables (courier lifecycle stays with the `courier_applications → couriers` phase), and no Policies yet for the new model.

## Known outstanding items
- **Pre-existing seller modal defects (untouched by the logistics work)**: `resources/js/seller/seller-register-modal.js` has no submit handler and no OTP handler, so the seller modal's Submit/Verify buttons are dead; and the buyer+seller modals share duplicate element ids on the register-type page (`birthday`, `birthMonth`, `province`, `email`, `contactNo`, …) so seller JS binds to the *buyer* modal's nodes. Needs its own fix contract.
- **Pre-existing mass-assignment gap**: `Admin\RegistrationController` sets `approved_by`/`approved_at`/`rejection_reason` via `Model::update()`, but those columns are absent from `Buyer`/`Seller` `$fillable`, so those values are silently dropped. `LogisticsProvider` includes them and works; the buyer/seller models still need a fix.
- The cart **drawer** in `resources/views/Buyer/Layouts/app.blade.php` still has hardcoded demo content — replace with a real mini-cart or remove later (left untouched by design).
- `TODO:` in `Buyer/Cart/_item.blade.php` — color/size meta line awaits a product_variants table.
- **Buyer product card** (`Components/buyer/product-card.blade.php`) is a landing-styled tile showing **name, price, shop location, sold count, delivery estimate — and NO add-to-cart button** (add-to-cart stays in the quick-view modal and the PDP buy-box; only `.js-open-qv` + `.wish-toggle` remain as hooks). Shop location is REAL (`sellers.municipality_name, province_name` via the eager-loaded `seller` — every caller must keep loading it). **Sold count and delivery days are DEMO values derived from `product id`** (developer-approved 2026-09-22, same derived-value approach as `store-card.blade.php`'s star rating) — replace with `SUM(order_items.quantity)` / a real lead-time column once the delivery phase exists. The card body is pink (`bg-secondary-300`) so it does not blend into the cream page. **Size spec (2026-09-22):** image is an exact **4:3** (`aspect-[4/3]`; the inner box is `h-full w-full`, so the landing card's nested `aspect-[0.82]`/`aspect-[0.8]` quirk is gone — a 320px tile gives 320 × 240), tile is `w-full max-w-[320px]` + `rounded-[10px]` with a **content-driven height (~390px at 320 wide)**. Two variants were REJECTED — do not re-introduce them: **16:9** (`aspect-video`) pins the image to just 180px at this width so the card looked body-heavy, and a forced `sm:min-h-[420px]` gave the body 240px for only ~150px of content, leaving an empty pink gap. A HARD `w-[320px]` was deliberately rejected: the flash-sale track is only ~250px wide (outer 5-col grid, cards in `lg:col-span-4`), so a fixed width would overflow it — the for-you and related tracks are ~316–319px, so the card still renders at the full 320 there. Meta rows are 12px `text-neutral-950` (near-black; the earlier `buyer-ink-soft` washed out on pink) with an `h-3 w-px bg-buyer-ink/25` divider between the delivery estimate and the location.
- `routes/web.php` `/` (`home`) renders `LandingPage.index`, which extends the **shared** shell `Layouts/footer.blade.php` (also used by the register/pending pages, seller login and the logistics register/OTP pages). That shell includes the navbar with `variant => 'buyer'`, so guests get plain Login/Register while a signed-in buyer gets the account dropdown (asserted by `Tests\Feature\Components\NavbarTest`) — do NOT switch it to `'landing'`. **The landing/buyer/auth shells have no sidebar**: navigation is the top navbar only (`Layouts/seller`, `Layouts/logistics` and `Admin/Layouts/app` are the only shells that own a sidebar). `Buyer\HomeController@index` still carries a local `$cartCount = 0` (composer overrides it at render time; the stale local + TODO could be cleaned up).

## Verification commands
`php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `php artisan route:list --name=buyer.cart`, `php artisan view:clear && php artisan view:cache`, `npm run build`.
