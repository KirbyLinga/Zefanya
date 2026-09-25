# Seller Coding Checklist

> **System context (for AI):** Zefanya is a Laravel 11 / PHP 8.x marketplace. The seller surface (`/seller/*`, `auth:seller` guard + `EnsureSellerApproved` middleware, `sellers` table) covers registration → OTP → admin approval → login → dashboard → full product + inventory CRUD. Products (`products` table, `App\Models\Product`) use a `VARCHAR(20)` status column (migrated from ENUM on 2026-09-22) cast to the `ProductStatus` enum (`App\Enums\ProductStatus` — cases: `Draft`, `Active`, `Inactive`). The product modal handles both create and edit mode (AJAX form fetch + normal multipart submit); validation failures reopen the modal with errors. The seller dashboard shows real product/low-stock counts; order-related stats are honest `null` / empty-collection placeholders. Dark/light theme toggle lives in the dashboard hero card only (sidebar toggle removed). Collapsed sidebar shows icons-only with body-appended CSS tooltips. Two ghost model files (`app/Models/Seller/Product.php`, `app/Models/Seller/ProductImage.php`) exist on disk but declare `namespace App\Models` — they are dead and never autoloaded. All 127 tests pass as of 2026-09-22.

> **Audit legend:** `[x]` = working (code + route + backing data verified) · `[ ]` = not implemented. *Italics* flag known defects, shells, or dependencies.

---

## Foundation

- [x] Registration *(modal + `RegisterSellerController` + `StoreSellerRegistrationRequest`; OTP email verification; admin approval)*
- [x] Street / house number always required *(both address modes — `StoreSellerRegistrationRequest`)*
- [x] OTP email verification *(SHA-256 stored, 10-min expiry, resend endpoint)*
- [x] Seller account approval flow *(Admin approves/rejects → `SellerRegistrationDecision` notification)*
- [x] Seller login *(standalone page + modal; throttled; approved-only)*
- [x] Seller authentication *(`auth:seller` guard + `EnsureSellerApproved` middleware)*
- [x] Seller dashboard layout *(sidebar, hero card, nav, dark/light theme toggle in hero)*
- [x] Seller navigation *(collapsible sidebar with icon-only collapsed rail + body-appended tooltips)*
- [x] Seller logout

---

## Dashboard

- [x] Dashboard page *(`Seller\DashboardController` → `Seller/Dashboard/index`)*
- [x] Hero card *(gradient card with welcome heading, date chip, theme toggle, notification bell)*
- [x] Dark / light theme toggle *(in hero card only; persisted to `localStorage: zf-theme`)*
- [x] Notification bell *(empty state "You're all caught up"; reads from DB when `notifications` table exists)*
- [x] Product count *(real query)*
- [x] Low-stock products section *(real query, dashboard widget)*
- [ ] Total sales *(placeholder `null` — needs `orders` table)*
- [ ] Total / pending orders *(placeholder `null` — needs `orders` table)*
- [ ] Sales performance chart *(zeroed placeholder trend — needs `orders` table)*
- [ ] Recent orders *(empty placeholder collection — needs `orders` table)*

---

## Product & Inventory Management

- [x] Product list *(`Seller/Products/index`; filters by status/category/search, sort, pagination — 25 feature tests)*
- [x] Add product modal *(multipart store; validation failures reopen modal with old input)*
- [x] Edit product modal *(AJAX `_form` fetch → normal PUT submit; validation failures reopen modal with errors)*
- [x] Product name, description, category *(locked to seller's line of business)*
- [x] Product images *(upload + delete; jpg/jpeg/png/webp, ≤3 MB each, ≤6 per product)*
- [x] Product price, stock quantity, low-stock threshold
- [x] Product status *(draft / active / inactive; `VARCHAR(20)` column cast to `ProductStatus` enum)*
- [x] AJAX status toggle *(`PATCH seller/products/{product}/status` → JSON response with `status.value` and `label`)*
- [x] Archive / soft-delete product *(tested)*
- [ ] Restore archived product *(no restore route or trash UI)*
- [ ] Product variants *(no variants table)*
- [ ] Dedicated inventory management page *(`seller/inventory` is a redirect to products)*
- [ ] Seller-facing discount / voucher creation *(vouchers table + `ZEFANYANEW` seeder exist; seller management UI not built)*

---

## Discounts & Vouchers

- [ ] Create / edit / archive discount or voucher *(seller-side UI not built)*
- [x] Voucher validation (buyer-side) *(server-validated in `CartController` / `CartSummary`)*
- [ ] Voucher usage limit enforcement *(column exists; not implemented)*

---

## Order Management

> Not started — no `orders`/`seller_orders`/`order_items` tables. `seller/orders` is a static closure view.

- [ ] All order management features (list, details, confirm, prepare, packing slip, waybill, ready-for-pickup)

---

## Courier Handover & Tracking

> Not started. `seller/shipments` is a static view. Per settled architecture, Seller workflow ends at READY FOR PICKUP — after that Logistics/Courier takes over.

- [ ] All shipment / handover / tracking features

---

## Customer Feedback

> Not started — no reviews/feedback tables. `seller/feedback` is a static view.

- [ ] All feedback / review / rating features

---

## Financial Reports

> Not started — `seller/reports` is a static view; all figures depend on orders/payments.

- [ ] All report features (sales, orders, commission, chart, export)

---

## Chat / Messaging

> `Seller/Chat` is a static view. No conversation/message tables.

- [ ] All chat features

---

## Account Management

> `Seller/Account` views exist but `seller/account` is a static closure with no controller or data binding. Nothing persists.

- [ ] All account management features (profile, contact, email, password, business info, uploaded documents)

---

## Notifications

> `notifications` table does not exist. When it does, the dashboard bell will read up to 8 items with `title`/`message` payload keys. Registration OTP + decision notifications are email-only.

- [ ] In-app notification center
- [ ] Order / delivery / account / admin notifications
- [ ] Mark as read / all read

---

## Security & Authorization

- [x] Seller route protection *(`auth:seller` + `EnsureSellerApproved`; guest redirects to `seller.login`)*
- [x] Seller can only access own products *(`authorizeOwnership` → 403, controller-level — tested)*
- [x] Seller cannot modify another seller's data *(products covered and tested)*
- [x] Form validation *(`StoreProductRequest`, throttled login/register/OTP)*
- [x] File upload validation *(images: jpg/jpeg/png/webp ≤3 MB ≤6; ID/permit: jpg/jpeg/png/pdf ≤5 MB)*
- [x] Unauthorized access handling *(403 aborts + guest redirects — tested)*
- [ ] Laravel Policies *(no `app/Policies`; ownership is controller-level only — required before orders/payments)*
- [ ] Seller can only access own orders *(no orders)*

---

## UI & Responsive

- [x] Seller desktop / tablet / mobile UI *(breakpoints at 1100 px / 720 px)*
- [x] Collapsible sidebar *(icon-only rail when collapsed; body-appended CSS tooltips on hover/focus)*
- [x] Responsive product list *(category/actions columns drop at breakpoints)*
- [x] Loading states *(modal "Loading product…" state)*
- [x] Empty states *(products empty state + honest dashboard empties — tested)*
- [x] Error states *(modal error panel + validation re-render)*
- [x] Success messages *(`sp-flash` session banner)*
- [x] Confirmation dialogs *(native confirm on product delete)*
- [ ] Responsive order list / reports / chat *(not built)*

---

## Testing

- [x] Product CRUD test *(`ProductControllerTest` — 25 tests: list/filters/sort/create/update/toggle/delete/ownership)*
- [x] Authorization test *(cross-seller toggle/delete blocked; guest redirects — tested)*
- [x] Seller dashboard hero tests *(`SellerDashboardHeroTest` — 5 tests)*
- [x] Seller panel fixes tests *(`SellerPanelFixesTest` — hero layout, theme toggle, sidebar tooltips)*
- [x] Seller auth redirect tests *(`SellerAuthRedirectTest`)*
- [x] Seller theme toggle test *(`SellerThemeToggleTest`)*
- [x] Inventory removed test *(`InventoryRemovedTest`)*
- [x] Registration street/address tests *(`RegistrationStreetTest` — 9 seller tests)*
- [ ] Seller registration / OTP / login tests
- [ ] Discount / voucher test
- [ ] Order management / courier handover / feedback / reports / chat tests

---

## Complete Seller Flow

- [x] Register → OTP → wait for admin approval → receive email → login
- [x] View dashboard (real product + low-stock stats; order stats honest empty)
- [x] Create / edit / archive products, toggle status, manage images
- [ ] Create discounts / vouchers
- [ ] Receive / confirm / prepare orders
- [ ] Mark order ready for pickup (handover to Logistics)
- [ ] View customer feedback / reports
- [x] Logout

---

## Known defects / open items (as of 2026-09-22)

1. **`app/Models/Seller/Product.php` and `app/Models/Seller/ProductImage.php` are ghost files** — both declare `namespace App\Models` (wrong for their path). They cannot be autoloaded and are dead code, but still on disk. Safe to delete.
2. **`products.status` migrated from ENUM to VARCHAR(20)** on 2026-09-22 (migration `2026_09_22_085043_change_products_status_to_string`). The `ProductStatus` enum (`App\Enums\ProductStatus`) is cast in `App\Models\Product`. All blade comparisons and controller responses updated to use `->value` or enum constants.
3. **No Laravel Policies anywhere** — required before orders/payments land.
4. **Seller-facing voucher/discount management not built** — only buyer-side validation exists.
5. **No restore/trash flow** for soft-deleted products.
6. **All 127 tests pass** as of 2026-09-22.
