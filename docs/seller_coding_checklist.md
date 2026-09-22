# Seller Coding Checklist

> **Audit legend (2026-09-20):** [x] = working (code + route + backing data verified) ·
> [ ] = not implemented. Notes in *(italics)* flag "UI shell exists but no backend" items.

## Foundation
- [x] Registration
- [x] OTP
- [x] Seller account approval flow
- [x] Seller login
- [x] Seller authentication
- [x] Seller dashboard layout
- [x] Seller navigation
- [x] Seller logout

## Dashboard
- [x] Dashboard page *(real page; order widgets render an honest empty state)*
- [ ] Total sales *(placeholder `null` in `Seller\DashboardController` — needs `orders` table)*
- [ ] Total orders *(placeholder `null` — needs `orders` table)*
- [ ] Pending orders *(placeholder `null` — needs `orders` table)*
- [x] Product count
- [x] Low-stock products
- [ ] Sales chart *(zeroed placeholder trend until orders exist)*
- [ ] Recent orders *(empty placeholder collection)*
- [x] Low-stock products section

## Product & Inventory Management
- [x] Product list *(filters, sort, pagination; 25 feature tests)*
- [x] Add product *(modal + multipart store; failures reopen modal with old input)*
- [x] Product name
- [x] Product description
- [x] Product category *(locked to seller line of business)*
- [x] Product images *(upload + delete; jpg/jpeg/png/webp, max 3 MB, max 6)*
- [x] Product price
- [x] Product stock *(set in form; no dedicated inventory ops yet)*
- [ ] Product variants *(no variants table — TODO in `Buyer/Cart/_item.blade.php`)*
- [ ] Product discount
- [x] Product status *(draft/active/inactive + AJAX `toggleStatus`)*
- [x] Edit product *(modal edit mode + AJAX `_form` partial — trigger fix applied 2026-09-20, re-verify in browser)*
- [x] Archive product *(destroy = soft delete, tested)*
- [ ] Restore product *(no restore route/trash UI)*
- [ ] Update inventory *(`seller/inventory` is a static closure view — no controller)*
- [x] Low-stock warning *(dashboard section + STOCK pill amber/rose)*
- [x] Out-of-stock handling *(out-of-stock pill + cart add capped at stock; no checkout-time enforcement yet)*

## Discounts & Vouchers
- [ ] Create discount
- [ ] Percentage discount
- [ ] Fixed discount
- [ ] Start/end date
- [ ] Minimum purchase
- [ ] Maximum discount
- [ ] Enable/disable discount
- [ ] Edit discount
- [ ] Archive discount
- [ ] Create voucher *(`vouchers` table/model/seeder exist; `Seller/Vouchers` pages are static views with no routes/controllers)*
- [ ] Voucher code *(model + seeded `ZEFANYANEW` exist; seller-side creation not built)*
- [ ] Voucher usage limit *(column supported; seller-side creation not built)*
- [x] Voucher validation *(buyer-side, server-validated in `CartController`/`CartSummary`)*

## Order Notifications
> *Not started — no `notifications` table or Laravel Notification usage exists yet.*
- [ ] New order notification
- [ ] Notification badge
- [ ] Notification list
- [ ] Mark as read
- [ ] View order from notification
- [ ] Order status notifications

## Order Management
> *Not started — no `orders`/`seller_orders`/`order_items` tables. `seller/orders` is a static
> closure view; `Buyer\OrderController` is an empty stub. This blocks Dashboard totals, Reports,
> Prepare Order and Courier Handover.*
- [ ] Seller orders page
- [ ] Pending orders
- [ ] Confirmed orders
- [ ] Preparing orders
- [ ] Ready for pickup
- [ ] Completed orders
- [ ] Cancelled orders
- [ ] Search orders
- [ ] Filter orders
- [ ] Sort orders
- [ ] Order details
- [ ] Customer information
- [ ] Order items
- [ ] Quantity and pricing
- [ ] Discounts
- [ ] Shipping information
- [ ] Payment status
- [ ] Order status

## Prepare Order
> *Not started — depends entirely on Order Management.*
- [ ] Confirm order
- [ ] Change status to preparing
- [ ] View items to prepare
- [ ] Print packing slip
- [ ] Print waybill/shipping label
- [ ] Mark order as packed
- [ ] Change status to ready for pickup

## Courier Handover & Tracking
> *Not started — no `deliveries`/`delivery_status_history` tables; `seller/shipments` is a static
> view. Also: `/register/logistics` routes to `Auth.register-logistics` which does NOT exist (500).*
- [ ] View orders ready for pickup
- [ ] Pickup status
- [ ] View pickup information
- [ ] Track shipment
- [ ] Courier pickup notification
- [ ] Shipment status notifications
- [ ] Delivery tracking timeline
- [ ] Monitor delivered status

## Customer Feedback
> *Not started — no reviews/feedback tables. `seller/feedback` is a static view; the buyer product
> page `_reviews` partial is static markup. No controllers.*
- [ ] Feedback page
- [ ] Product reviews
- [ ] Ratings
- [ ] Filter reviews
- [ ] Search reviews
- [ ] Reply to reviews
- [ ] Report inappropriate review
- [ ] Average rating
- [ ] Rating breakdown

## Financial Reports
> *Not started — `seller/reports` is a static view; all figures depend on orders/payments.*
- [ ] Reports page
- [ ] From date
- [ ] To date
- [ ] Total sales
- [ ] Total orders
- [ ] Completed sales
- [ ] Cancelled orders
- [ ] Refunds
- [ ] Discounts
- [ ] Net revenue
- [ ] Profit
- [ ] Sales chart
- [ ] Product performance
- [ ] Best-selling products
- [ ] Export report

## Chat / Messaging
> *Not started — `Buyer\ChatController` and `Seller\ChatController` are empty stubs; views are
> static shells; no conversation/message tables.*
- [ ] Chat page
- [ ] Conversation list
- [ ] Customer conversation
- [ ] Send message
- [ ] Receive message
- [ ] Unread message count
- [ ] Message timestamps
- [ ] Read/unread status
- [ ] Order-related chat

## Account Management
> *UI exists (`Seller/Account/index+edit.blade.php`) but routes serve static closure views with no
> controller or data binding — nothing persists. (Buyer account is the same: `Buyer\AccountController`
> is an empty stub and `GET|PATCH /buyer/account` are dead routes.)*
- [ ] Seller profile page
- [ ] Edit personal information
- [ ] Edit contact number
- [ ] Edit email
- [ ] Edit address
- [ ] Change password
- [ ] Profile picture
- [ ] Business information
- [ ] Edit business name
- [ ] Edit business category
- [ ] View uploaded ID
- [ ] View business permit
- [ ] Account status

## Notifications
- [ ] Notification center
- [ ] Order notifications
- [ ] Delivery notifications
- [ ] Account notifications
- [ ] Admin approval notification
- [ ] System notifications
- [ ] Mark as read
- [ ] Mark all as read

## Security & Authorization
- [x] Seller route protection *(`seller.approved` → `EnsureSellerApproved`; guest guard redirects)*
- [x] Role-based access *(separate `buyer`/`seller`/`admin` guards + `EnsureAdminRole`)*
- [x] Seller can only access own products *(`authorizeOwnership` → 403, controller-level)*
- [ ] Seller can only access own orders *(no orders exist)*
- [x] Seller cannot modify another seller's data *(products covered & tested; other modules N/A)*
- [x] Form validation *(`StoreProductRequest`, throttled login/register/OTP)*
- [x] File upload validation *(image, mimes jpg/jpeg/png/webp, ≤3 MB, ≤6 files)*
- [ ] Authorization policies *(no `app/Policies` — ownership is controller-level only; project rules require Policies for sensitive models)*
- [x] Unauthorized access handling *(403 aborts + guest redirects, tested)*
- [ ] Order status authorization *(no orders)*
- [ ] Product archive handling *(no restore/trash flow)*

## UI & Responsive
- [x] Seller desktop UI
- [x] Seller tablet UI *(breakpoints at 1100 px / 720 px; visual QA pending)*
- [x] Seller mobile UI
- [x] Responsive sidebar *(not deeply audited — verify collapse behaviour)*
- [x] Responsive product list *(category column and actions column drop at breakpoints)*
- [ ] Responsive order list *(static page)*
- [ ] Responsive reports *(static page)*
- [ ] Responsive chat *(static page)*
- [x] Loading states *(modal "Loading product…" state)*
- [x] Empty states *(products empty state + honest dashboard empties, tested)*
- [x] Error states *(modal error panel + validation error re-render)*
- [x] Success messages *(`sp-flash` session banner)*
- [x] Confirmation dialogs *(native confirm on product delete)*

## Testing
- [x] Product CRUD test *(`Seller/ProductControllerTest` — 25 tests: list/filters/modal/store/toggle/delete; edit-fetch + update not yet asserted)*
- [x] Authorization test *(products only — cross-seller toggle/delete blocked, guest redirect)*
- [ ] Seller registration test
- [ ] OTP test
- [ ] Seller login test
- [ ] Inventory test
- [ ] Discount/voucher test
- [ ] Order management test
- [ ] Order status flow test
- [ ] Courier handover test
- [ ] Feedback test
- [ ] Reports test
- [ ] Chat test
- [ ] Responsive UI test
- [ ] End-to-end seller flow test

## Known defects / compliance notes (from 2026-09-20 audit)
1. **`POST /buyer/checkout` → 500.** `routes/buyer.php` points to `CheckoutController@store`, which does not exist (only `index`).
2. **`GET /register/logistics` → 500.** Route references missing view `Auth.register-logistics`.
3. **`products` migration uses MySQL `ENUM('draft','active','inactive')`** — violates project rule §6 (VARCHAR + PHP backed enums). Fix in a *new* migration.
4. **Edit-modal fix (2026-09-20):** `data-product-edit-open`/`data-update-url` added to pencil + ⋮ triggers in `Seller/Products/index.blade.php` — verify in browser (Ctrl+Shift+R).
5. **No Laravel Policies anywhere** — ownership checks are controller-level only.
6. Buyer-side vouchers/discounts are validated server-side, but **no seller-facing management UI** (only the `ZEFANYANEW` seeder).
