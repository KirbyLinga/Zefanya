# Contract: Buyer Checkout — Place Order (Phase 2)

## Objective

Make `POST /buyer/checkout` (`CheckoutController@store`) actually place an order on the
live app: re-resolve the buyer's parked selection + voucher from session, re-validate
stock, re-compute authoritative centavo totals via `CartSummary`, create
`orders → seller_orders → order_items → payments` in one transaction with locked stock
decrement, clear cart + voucher state, and redirect to a minimal order-confirmation page.

## Scope

- New migrations: `orders`, `seller_orders`, `order_items`, `payments`
- New models: `Order`, `SellerOrder`, `OrderItem`, `Payment` (+ relationships)
- `App\Enums\OrderStatus` (13 values), `App\Enums\PaymentStatus` (pending/paid)
- `CheckoutController@store` + one GET confirmation route + filled-in `Buyer/Checkout/success.blade.php`
- New feature test: `CheckoutControllerTest` (places an order through the real controller as a logged-in buyer)

## Out of Scope

- Order-tracking UI, seller fulfillment screens, logistics/courier anything (Phase 3)
- Status transitions beyond PLACED
## Research Findings

- Routes `buyer.checkout.index` / `buyer.checkout.store` already registered in
  `routes/buyer.php` behind `auth:buyer`. `store` currently points at a missing method (live 500).
- `CheckoutController@index` parks the resolved selection in `session['checkout_item_ids']`
  (always a concrete array of the buyer's own available cart-item ids); the voucher code
  stays in `session['cart_voucher']`.
- `App\Services\CartSummary` is the single source of truth for money: `subtotalCentavos`,
  `discountCentavos`, `totalCentavos` — integer centavos from DB rows only.
  `Voucher::resolveCode()` re-validates and can drop an invalid voucher with a notice.
- `buyers` holds one inline address per buyer (PSGC names + street/house_number/
  address_detail, `address_mode` api|manual). No separate `addresses` table exists live.
- `Buyer/Checkout/success.blade.php` exists as an empty stub extending `Buyer.Layouts.app`.
- Tests run on sqlite `:memory:` (phpunit.xml) — no MySQL touched by tests.
- **CONFLICT**: uncommitted scaffolding from the earlier Milestone-4 pass (this session)
  already defines orders/seller_orders/order_items/payments/shipments/delivery_events
  migrations + models + enums + factories + `OrderFulfillmentTreeTest`, but against the
  OLD spec: `buyer_id → users`, MySQL `$table->enum()` statuses, `total_minor` only, plus
  shipments/delivery_events. Never migrated on the live DB (only on throwaway
  `zefanya_schema`). Must be reconciled before anything new is added.

## Implementation Plan

1. **Remove the superseded scaffolding** (all uncommitted, created this session, never on
   the live DB): migrations `2026_09_22_100001…100006`, models `Order`, `SellerOrder`,
   `OrderItem`, `Payment`, `Shipment`, `DeliveryEvent`, enums `PaymentStatus` (rewritten
   per new spec), `PaymentMethod`, factories `OrderFactory`, `SellerOrderFactory`,
   `OrderItemFactory`, `PaymentFactory`, `ShipmentFactory`, `DeliveryEventFactory`,
   `database/factories/Shared/UserFactory`, and `tests/Feature/OrderFulfillmentTreeTest.php`.
   (`App\Enums\OrderStatus` keeps its 13 values — already matches the new spec.)
   ⚠️ Developer decision required — see Open Questions 1.
2. New migrations (VARCHAR(20) statuses, default PLACED; unsigned-int centavo *_minor):
   - `orders`: `buyer_id` (RESTRICT — permanent record), `reference` unique
     (`ORD-YYYYMMDD-XXXXXX`), `shipping_address` JSON snapshot, `subtotal_minor`,
     `discount_minor`, `total_minor`, `status`, timestamps
   - `seller_orders`: `order_id` (RESTRICT — permanent record), `seller_id` (RESTRICT),
     unique `(order_id, seller_id)`, `subtotal_minor`, `status`, timestamps
   - `order_items`: `seller_order_id` (RESTRICT — permanent record), `product_id` (SET NULL
     — keep the audit line), `product_name` snapshot column, `quantity`, `price_minor`, timestamps
   - `payments`: `seller_order_id` (RESTRICT — permanent financial record), unique
     `seller_order_id` (1:1), `amount_minor`, `method` VARCHAR default `cod` (COD only this
     pass), `status` VARCHAR default `pending`, `paid_at` nullable, timestamps
3. Models: `Order` (belongsTo buyer→`Buyer`, hasMany sellerOrders; casts: status enum,
   shipping_address array, *_minor int), `SellerOrder` (belongsTo order + seller, hasMany
   orderItems, hasOne payment), `OrderItem` (belongsTo sellerOrder + product),
   `Payment` (belongsTo sellerOrder, method/status enum casts). `$fillable` allow-lists;
   `status` NOT mass-assignable (transitions via controlled methods in a later pass).

- Voucher `usage_limit` enforcement / `times_used` increment (existing gap, separate pass)
- No changes to `cart_items`, `vouchers`, `products`, or any executed migration

4. `CheckoutController@store`:
   - Read `SESSION_KEY`; empty -> redirect to cart with a flash error.
   - Load the buyer's cart fresh (withTrashed products, eager seller) - same source as index.
   - Verify every parked id is still in the buyer's cart AND `isAvailable()` AND
     `quantity <= stock` - any failure -> flash error listing the problem items,
     redirect to cart. Never silently drop.
   - `Voucher::resolveCode(session('cart_voucher'), subtotal, $notice)`; if dropped,
     proceed without it and surface the notice (same behaviour as the cart page).
   - Build `CartSummary` -> authoritative subtotal/discount/total centavos. Nothing
     from the request body is ever trusted for pricing.
   - `DB::transaction()`: lock product rows (`lockForUpdate`, ordered by id), re-check
     stock under lock, create Order (status PLACED, address snapshot from the buyer's
     profile columns), group items by seller -> one SellerOrder per shop (status PLACED,
     own subtotal), create OrderItems + one pending COD Payment per SellerOrder,
     decrement stock. Any stock failure under lock -> transaction rolls back, flash
     error, redirect to cart.
   - On success: delete the buyer's selected cart items, clear `checkout_item_ids` +
     `cart_voucher` session keys, redirect to the confirmation page.
5. Confirmation: GET `buyer/checkout/success/{order}` (`buyer.checkout.success`,
   auth:buyer, 404 unless the order belongs to the buyer) rendering the filled-in
   `success.blade.php` - reference, status, per-seller groups (items, quantities,
   amounts), totals, COD note, link to orders. No new Vite inputs.

## Security

- `auth:buyer` middleware (existing route group); guest -> buyer login redirect (existing).
- Session-parked ids re-filtered against the buyer's own cart (a tampered id can never
  place an order for another buyer's line).
- Pricing only from `CartSummary` (DB rows); the request body is never trusted for money.
- `$fillable` allow-lists; `status` never mass-assigned; ownership check on the
  confirmation route (404 unless the order is the buyer's).
- Policies deferred to the order-tracking pass (matches the current buyer-surface
  convention of inline ownership checks - noted as a deliberate exception to rule 8).

## Data Integrity

- One `DB::transaction()` wraps order + seller_orders + items + payments + stock writes.
- Product rows locked with `lockForUpdate()` (ordered by id to avoid deadlocks); stock
  re-validated under lock; decrement inside the transaction.
- All four tables are permanent records: RESTRICT deletes on order/seller_order/payment
  FKs; nothing soft-deletes; cancellation is a status change in a later pass.
- WARNING: rule 7 also mandates an `inventory_transactions` row per stock write - that
  table does not exist in the live app. Developer decision - see Open Questions 2.

## Tests

New `tests/Feature/Http/Controllers/Buyer/CheckoutControllerTest.php` (no existing test
is modified):
- Happy path: logged-in buyer, items from 2 sellers -> 1 order + 2 seller_orders +
  items + 2 pending COD payments; correct centavo totals; stock decremented; selected
  cart items removed; voucher + selection session keys cleared; redirect to confirmation.
- Unavailable/insufficient stock since parking -> redirect to cart with error, zero rows
  created, stock untouched.
- Invalid/expired parked voucher -> order placed without discount, notice flashed
  (matches cart behaviour).
- Guest POST -> redirect to login. Empty selection -> redirect to cart.
- Confirmation page: owner sees it; another buyer gets 404.

## Rollback

Migration files are new-only and never run by the agent; the developer runs
`php artisan migrate` themselves - rollback = `php artisan migrate:rollback --step=4`
before any live traffic depends on the tables, or drop the four tables manually. Code
changes are additive (one new method + route + filled stub); revert by deleting the new
files and the added route line.

## Open Questions

1. **Superseded scaffolding** - delete/rewrite the earlier pass's orders* files (listed
   in Plan step 1) per the new spec, since they were never migrated live?
   (Recommended: yes - rewrite in place; the live DB has none of these tables.)
2. **Rule 7 inventory ledger** - add a minimal `inventory_transactions` table
   (product_id, seller_order_id nullable, quantity_before, quantity_after, delta,
   reason) written in the same transaction now, or defer to the Phase 6 inventory pass?
   (Recommended: add it now - it is a documented non-negotiable for stock-touching
   flows, and retrofitting history later is impossible.)
3. **Voucher discount across sellers** - seller payments are per shop but the voucher
   discount is order-level. Allocate the discount proportionally across seller_orders so
   the COD payments sum to the order total (largest-remainder to the first seller), or
   leave each seller's payment at its full subtotal with the discount recorded only on
   `orders.discount_minor`? (Recommended: proportional allocation - COD riders collect
   per parcel, so payments must sum to the real total.)
4. **Address snapshot source** - no address picker exists in the checkout stub. Snapshot
   the buyer's profile address (all PSGC/street fields) as-is this pass?
   (Recommended: yes; a picker belongs to the buyer-account pass.)

## Acceptance Criteria

- [ ] `POST /buyer/checkout` places a real order (no more 500) and redirects to a working
      confirmation page showing the reference + summary
- [ ] Order tree (order -> seller_orders -> order_items -> payments) created correctly in
      one transaction; totals in centavos match `CartSummary` exactly
- [ ] Stock decremented under lock inside the transaction; insufficient stock rejects
      with a flash error, no partial writes
- [ ] Cart + voucher session state cleared on success
- [ ] All schema rules honoured: buyers FK, JSON snapshot, VARCHAR+enum-cast statuses,
      *_minor centavo columns, unique (order_id, seller_id)
- [ ] New feature test green; full suite green (129+ tests); zero existing tests modified
- [ ] No migration executed by the agent (developer runs it)

## Developer Approval

- [ ] Research completed
- [ ] Plan reviewed
- [ ] Open questions resolved
- [ ] Scope approved
- [ ] Implementation approved

Developer approval:

Date:

Notes:
