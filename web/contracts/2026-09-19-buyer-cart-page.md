# Contract: Buyer Shopping Cart Page (buyer.cart.index)

Status: **AWAITING DEVELOPER APPROVAL**

Date: 2026-09-19
Route: `GET /buyer/cart` → `buyer.cart.index`

---

## Objective

Deliver the real, working Buyer Shopping Cart page matching the approved design,
plus the data layer and JSON endpoints it needs (persistent cart, vouchers,
shared cart count, server-authoritative totals).

## Scope

* Completing the partially-built cart feature left uncommitted by a previous session.
* `cart_items` + `vouchers` tables, models, `CartSummary` service, 8 cart routes.
* Cart page Blade view + partials + `cart.js`.
* Shared `$cartCount` for the navbar badge.
* `CheckoutController@index` accepting a selected-item set (checkout page untouched).
* Feature tests for cart behaviour.

## Out of Scope

* No changes to navbar visuals, login modal, chat panel, footer, quick-view modal.
* The **hardcoded cart drawer** in `Buyer/Layouts/app.blade.php` is left as-is.
* No checkout redesign (`Buyer/Checkout/index.blade.php` stays a stub).
* No order/payment/delivery tables. `vouchers.times_used` is **not** incremented yet
  (belongs to the checkout/orders phase).
* No packages added (composer or npm).

---

## Research Findings (Step 0)

### Already built, uncommitted, by a previous session

| Artifact | State |
|---|---|
| `database/migrations/2026_09_20_000001_create_cart_items_table.php` | Exists, **PENDING** on DB |
| `database/migrations/2026_09_20_000002_create_vouchers_table.php` | Exists, **PENDING** on DB |
| `app/Models/CartItem.php` | Complete (`lineTotalCentavos`, `isAvailable`) |
| `app/Models/Voucher.php` | Complete (`discountCentavos`, `rejectionReason`) |
| `app/Services/CartSummary.php` | Present, has gaps (below) |
| `app/Models/Buyer/Buyer::cartItems()` | Complete |
| `app/Models/Product::cartItems()` | Complete |
| `routes/buyer.php` | All 8 cart routes registered inside `auth:buyer` |
| `app/Http/Controllers/Buyer/CartController.php` | 8 actions implemented, has bugs (below) |
| `resources/views/Buyer/Cart/index.blade.php` | Rewritten; **references 2 missing partials → page 500s** |
| `resources/views/Buyer/Cart/_item.blade.php` | Exists; 2 design deviations |
| `resources/views/Buyer/Cart/_seller-group.blade.php` | Exists; initials badge instead of store icon |

### Confirmed missing (must create)

1. `resources/views/Buyer/Cart/_summary.blade.php` — **referenced but does not exist**
2. `resources/views/Buyer/Cart/_empty.blade.php` — **referenced but does not exist**
3. `resources/js/buyer/cart.js` — **not in `vite.config.js` inputs either**
4. `database/seeders/VoucherSeeder.php` (`ZEFANYANEW` = 200 fixed)
5. `database/factories/VoucherFactory.php`, `database/factories/CartItemFactory.php`
6. `tests/Feature/Http/Controllers/Buyer/CartControllerTest.php`
7. `app/Http/Controllers/Buyer/CheckoutController.php` — **empty class stub; no `index()`
   method → `buyer.checkout.index` currently 500s**

### Environment / conventions confirmed

* Laravel 13.29.0, PHP 8.5.9. `bcmath`, `pdo_sqlite`, `pdo_mysql` all present.
* Tests: PHPUnit + `LazilyRefreshDatabase`, SQLite `:memory:` (`phpunit.xml`).
  Existing example: `tests/Feature/Http/Controllers/Buyer/HomeControllerTest.php`.
* Factories exist: `BuyerFactory`, `SellerFactory`, `ProductFactory`
  (+ `draft()`, `outOfStock()` states).
* Category table has `icon` + `label` (14 seeded categories; `Category::$fillable = ['icon','label']`).
* `Product::catalogImageUrl()`, `formattedPrice()`, `isLowStock()`, `isOutOfStock()`, `SoftDeletes`.
* `Seller::business_name`; `Seller::storeInitials()` exists.
* Guard `buyer` → `App\Models\Buyer\Buyer`. `bootstrap/app.php` redirects guests to `buyer.login`.
* `shouldRenderJsonWhen(... $request->expectsJson())` → `Accept: application/json` gives 401,
  not a redirect. ✅ `buyer.js` already sends `Accept` + `X-CSRF-TOKEN`. ✅
* Theme tokens in `resources/css/app.css`: `buyer-ink #3a2529`, `buyer-ink-soft #5b4448`,
  `buyer-line #ebddda`, `blush #faf2f1`, `buyer-cream`, `primary-800 #79545c`, `tertiary-*`,
  `secondary-*`, `neutral-*`, `--font-serif` (Playfair), `--font-sans` (Montserrat).
  No inline hex needed. No unlayered CSS will be added.
* `navbar.blade.php` accepts `$cartCount` (badge `id="cartCount"`), defaults to `0`.
  **The layout does not pass it, and no `View::composer`/`View::share` exists anywhere in `app/`.**
* `index.blade.php` pushes `@vite(['resources/js/buyer/cart.js'])` into `@stack('scripts')`
  (layout line 213). ✅
* `.ai/rules` and `memory-bank/` do **not** exist in this repo. `AGENTS.md`, `CLAUDE.md`,
  `docs/`, `contracts/` do.

---
## Gaps / Defects Found (to fix)

### Blocking

| # | File | Issue |
|---|---|---|
| G1 | `Cart/index.blade.php` | Includes `_summary` + `_empty`, neither exists → page 500s |
| G2 | `resources/js/buyer/cart.js` | Missing; `@vite` throws "Unable to locate file in Vite manifest" |
| G3 | `CheckoutController` | Empty class; `buyer.checkout.index` 500s |
| G4 | `vouchers` migration | Native MySQL `enum('type', ...)` → violates rule #6 (VARCHAR). Migration is **pending**, so safe to fix in place |
| G5 | `Buyer/Layouts/app.blade.php` | Mobile bar has `hidden` **and** `lg:hidden` → `hidden` always wins, bar never shows |
| G6 | `Buyer/Layouts/app.blade.php` | Mobile bar sits outside the non-empty `@if` → shows on the empty state |
| G7 | `CartController@add` | No `stock_quantity > 0` guard → an active-but-out-of-stock product creates a **quantity-0 line** |
| G8 | `CartController@update` | `$item->product` is `null` for a soft-deleted product → **fatal error** reading `stock_quantity` |
| G9 | `CartSummary` | `selectedIds === []` means "all available" → **cannot express "nothing selected"**, so deselecting everything still shows a full total |
| G10 | `buyer.js` | `let cartCount = 2` hardcoded |

### Design deviations

| # | File | Issue |
|---|---|---|
| G11 | `_item.blade.php` | Category label is a full-width band; design wants a **small chip in the bottom-right corner** |
| G12 | `_seller-group.blade.php` | Uses an initials circle; design/spec wants a **store icon** |
| G13 | `CartController@bulkDestroy` | Silently ignores non-owned ids instead of failing (spec asks 403/404) |
| G14 | `Cart/index.blade.php` | Hardcodes `/buyer/cart/` URLs instead of building from named routes |
| G15 | `_item.blade.php` | `data-price-centavos` holds the *line* total under a *unit*-price name |

### Verified correct (no change needed)

* Eager loading is complete (`product.images`, `product.seller`, `product.category`)
  → **no N+1**. `images()` already orders by `sort_order`.
* `Product` soft-delete default scope excludes trashed products in `add()`. ✅
* 401 JSON path for guests works via middleware + `expectsJson()`. ✅
* `$fillable` allow-lists only; **no `$guarded = []`**; `status`/`courier_id` not involved. ✅
* `cart_items` is not a permanent record (rule #5 unaffected). ✅
* Cart does **not** mutate `products.stock_quantity`, so rule #7 (`DB::transaction` +
  `lockForUpdate` + `inventory_transactions`) is **not triggered** by this contract.

---

## Implementation Plan

### 1. Migrations (pending → edit in place, then migrate)

* `2026_09_20_000002_create_vouchers_table.php`: `enum('type', [...])` → `string('type')`.
* Leave `2026_09_20_000001_create_cart_items_table.php` unchanged
  (`unique(buyer_id, product_id)` + both FKs `cascadeOnDelete` are correct).
* Add `app/Enums/VoucherType.php` (backed enum `Fixed`/`Percent`, TitleCase keys per AGENTS.md)
  and cast it on `Voucher::$casts`.
* Run `php artisan migrate`.

### 2. `CartSummary` service — make selection explicit

* Constructor `selectedIds` becomes `?array $selectedIds = null`
  (`null` = all available, `[]` = nothing selected).
* Add `selectedLineCount` (distinct lines) alongside `selectedQuantity()` (sum of quantities,
  for the pill). Keep `toJson()`; add `item_count` + `line_totals` so JS can trust the server.
* No float math: integers in centavos via `bcmul`.

### 3. `CartController` fixes

* `add`: require `stock_quantity > 0` (422 otherwise); validate `quantity` `min:1`;
  cap the merge at stock; wrap the read-merge-write in `DB::transaction` +
  `lockForUpdate()` on the buyer's existing cart row (prevents the unique-key race).
  Still **never** touches `products.stock_quantity`. Keep the 401 JSON branch.
* `update`: guard against a missing (soft-deleted) product; cap at stock; reject qty < 1;
  keep `where('buyer_id', auth('buyer')->id())` ownership scope.
* `destroy`: unchanged (ownership-scoped `firstOrFail()` → 404).
* `bulkDestroy`: verify every id belongs to the buyer → else 403; then delete.
* `summary`: accept `selected_ids` as JSON body or query (`[]` = none).

### 4. Shared cart count

* In `AppServiceProvider::boot()`: `View::composer('Components.navbar', ...)` setting
  `$cartCount` = sum of quantities for the authenticated buyer (skips the query entirely for
  guests; a single indexed aggregate — no N+1).
* Pass `'cartCount' => $cartCount` explicitly from the buyer layout for clarity, and fix the
  stale `3` in the navbar docblock.
* `buyer.js`: replace `let cartCount = 2` with reading `#cartCount`'s text on load.
  No other behaviour change.

### 5. View partials

* **Create `_summary.blade.php`** — Order Summary header + "N items" pill
  (`aria-live="polite"`), Subtotal, Shipping ("Calculated at checkout"),
  Voucher Discount (negative, `text-tertiary-700` when applied), voucher input +
  inline error slot + Apply button, green applied-code chip with tag icon + X,
  divider, large serif Total, full-width `bg-primary-800` "Proceed to Checkout →"
  (disabled when nothing is selected), lock-icon "Secure 256-bit SSL encrypted checkout".
* **Create `_empty.blade.php`** — blush circle with cart icon, serif "Your cart is empty",
  muted line, "Start Shopping" → `buyer.home`.
* **Fix `_item.blade.php`** — bottom-right category chip; `data-unit-centavos` +
  `data-line-total-centavos`; keep the `TODO:` about colour/size variants.
* **Fix `_seller-group.blade.php`** — `store` lucide icon in a soft circle.
* **Fix `index.blade.php`** — remove `hidden` from the mobile bar, move it inside the
  non-empty branch, build the JSON-island URLs from named routes.
* All new hooks use the `js-cartpage-*` class / `cartpage-` id prefix.

### 6. `resources/js/buyer/cart.js` (new) + `vite.config.js`

* Add `'resources/js/buyer/cart.js'` to the Vite `input` array.
* Selection sync (item ↔ seller group ↔ select all, `indeterminate`), server-recomputed
  summary via `GET /buyer/cart/summary`, debounced quantity PATCH, remove/bulk-remove with
  row animation, empty-state swap, voucher apply/remove, badge sync, toast feedback.
* Proceed to Checkout → `buyer.checkout.index?items[]=<id>`.
* All fetches send `X-CSRF-TOKEN` from the meta tag + `Accept: application/json`.

### 7. `CheckoutController@index`

* Accept `items[]` from the query, validated to the buyer's own cart items; fall back to all
  available items when none are passed.
* Mirror the ids into the session for the later `checkout.store` phase.
* Render the existing stub `Buyer/Checkout/index.blade.php` with `$items` + `$summary`.
  **The checkout page itself is not redesigned.**

### 8. Seeder + factories

* `VoucherSeeder`: `ZEFANYANEW`, `fixed`, `value = 20000` (200 in centavos),
  `min_spend = null`, `is_active = true`. Registered in `DatabaseSeeder`.
* `VoucherFactory`, `CartItemFactory` for tests.

### 9. Tests

`tests/Feature/Http/Controllers/Buyer/CartControllerTest.php`
(PHPUnit + `LazilyRefreshDatabase`, `test_*` snake_case naming like the existing suite):

1. Add merges quantity for the same product/seller.
2. Add caps at `stock_quantity`.
3. Add rejects an inactive / deleted / out-of-stock product (422).
4. Update changes quantity and returns the new line total + summary.
5. Update rejects quantity < 1 and caps above stock.
6. Remove deletes a line; removing the last line reports an empty cart.
7. **A buyer cannot modify or delete another buyer's cart item (404).**
8. Bulk remove rejects ids not owned by the buyer (403).
9. **Guests get 401 JSON** on add/patch/delete/bulk-delete/summary with
   `Accept: application/json`.
10. **Invalid / expired / inactive / below-min-spend vouchers are rejected** (422);
    a valid voucher applies and reduces the total.
11. The page renders 2 seller groups with correct totals; unavailable items are excluded
    from totals and their checkboxes are disabled.

### 10. Finalise

* `vendor/bin/pint --dirty --format agent`.
* `php artisan route:list --name=buyer.cart` (all 8 routes).
* `php artisan view:clear && php artisan view:cache`.
* `npm run build`.

---
---
## Security

* Every cart mutation is ownership-scoped with `where('buyer_id', auth('buyer')->id())`
  (404) — the existing project pattern.
* `bulkDestroy` verifies all ids before deleting (403).
* All cart routes stay inside the existing `auth:buyer` group.
* Totals and discount are **always** computed server-side from DB rows; `selected_ids` only
  ever narrows the set. A client-sent discount or price is never trusted.
* The voucher is stored as a **code in the session** and re-validated on every summary
  calculation; expired / inactive / below-min-spend vouchers are dropped automatically.
* `CartItem::$fillable = ['buyer_id','product_id','quantity']` — allow-list, no `$guarded = []`.
  `buyer_id` always comes from the authenticated guard, never from the request.
* No `status` / `courier_id` mass-assignment involved.

## Data Integrity

* The cart does **not** touch `products.stock_quantity` → not a stock-sensitive operation;
  no `inventory_transactions` row is required at this phase.
* The quantity merge is wrapped in `DB::transaction` + `lockForUpdate()` to protect the
  `unique(buyer_id, product_id)` constraint against concurrent adds.
* `cart_items` is ephemeral (cascade-deleted with buyer/product) — not a permanent record,
  so hard delete is correct here.
* Money stays in integer centavos (`bcmul`), never floats.

## Tests (acceptance)

See §9. All 11 cases must pass via `php artisan test --compact` (SQLite in-memory).

## Rollback

* `php artisan migrate:rollback --step=2` drops `vouchers` + `cart_items`.
* Revert `routes/buyer.php`, `CartController`, `CheckoutController`, `Buyer`, `Product`,
  `AppServiceProvider`, `vite.config.js`, `buyer.js`; delete the new partials, `cart.js`,
  the seeder/factories and the test file.
* Nothing touches permanent order/payment/delivery records, so rollback is clean.

## Open Questions (need developer answers)

1. **"Select all (N items)"** — count only *available* lines (current behaviour, matches what
   can actually be selected) or **all** distinct lines? I recommend **available lines**.
2. **Navbar badge count** — total quantity of *all* lines (including unavailable) or only
   available ones? I recommend **all lines**, while the summary pill counts selected items only.
3. **`vouchers.type`** — OK to change `enum` → `string` + add a `VoucherType` PHP backed enum
   (rule #6)? Or keep as-is since `type` is arguably not a "status"?
4. **Policy** — the project's minimum policy list doesn't include carts, so I plan to keep the
   ownership-scoped-query pattern. Want a `CartItemPolicy` anyway?
5. **Checkout id transport** — query string (`?items[]=1&items[]=2`) **plus** a session mirror
   (my recommendation) vs session only?
6. **`php artisan migrate`** — the two migrations are pending. Confirm I may run it against the
   local MySQL `Zefanya` database.

Known debt to report, not fix:

* The **cart drawer** in `Buyer/Layouts/app.blade.php` (lines 93–138) is still fully hardcoded
  mockup data (Rupiah prices, static items, static voucher/payment fields) and its "Place order"
  link points at `buyer.checkout.index`. Left untouched per instructions — it should later
  become a real mini-cart or be removed.
* `Buyer/Checkout/index.blade.php` is an empty stub (out of scope).
* `Voucher::times_used` / `usage_limit` are never incremented (checkout phase).

## Acceptance Criteria

* [ ] `Buyer/Cart/{index,_item,_seller-group,_summary,_empty}.blade.php` all exist.
* [ ] `resources/js/buyer/cart.js` exists, is a Vite input, pushed via `@push('scripts')`.
* [ ] Cart page renders for a buyer with items from 2+ sellers; layout matches the design.
* [ ] Empty cart shows the empty state and no mobile bar.
* [ ] Checkbox tri-state sync works; totals/item count update without reload, server-authoritative.
* [ ] Quantity stepper bounded 1..stock, debounced, toast on failure.
* [ ] Single + bulk remove animate out, drop empty seller cards, swap to the empty state.
* [ ] `ZEFANYANEW` applies (−₱200.00) and can be removed; invalid/expired codes show inline errors.
* [ ] Proceed to Checkout passes only the selected ids; checkout loads only the buyer's own items.
* [ ] Navbar badge shows the real count on every buyer page.
* [ ] No horizontal scroll at 375px; summary stacks below the items.
* [ ] All new elements use `js-cartpage-*` / `cartpage-` hooks; existing hooks untouched.
* [ ] No npm/composer packages added; no inline hex where a token exists; no unlayered CSS.
* [ ] `vendor/bin/pint --dirty`, `php artisan view:cache`, `npm run build` succeed.
* [ ] All §9 tests pass.

## Developer Approval

- [ ] Research completed
- [ ] Plan reviewed
- [ ] Open questions resolved
- [ ] Scope approved
- [ ] Implementation approved

Developer approval:

Date:

Notes: