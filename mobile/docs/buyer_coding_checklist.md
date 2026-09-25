# Buyer Coding Checklist

> **System context (for AI):** Zefanya is a Laravel 11 / PHP 8.x marketplace. The buyer surface (`/buyer/*`, `auth:buyer` guard, `buyers` table) covers registration → approval → login → catalog browsing → cart → checkout stub. Registration collects personal info, PSGC API-driven address (with manual fallback), street/house number (always required), and a valid ID upload. Cart is fully functional (add/update/remove/bulk, vouchers, selection, CartSummary with centavo arithmetic). Checkout page exists as a stub (index only); `CheckoutController@store` does not exist and the `POST /buyer/checkout` route currently produces a 500 — the Place Order button is disabled in the UI. No `orders`, `payments`, `deliveries`, `reviews`, or `notifications` tables exist yet. `CategoryController`, `AccountController`, `OrderController`, and `ChatController` are all empty stubs. 3 duplicate `contact_no` values exist in the DB (no uniqueness constraint). All 127 tests pass as of 2026-09-22.

> **Audit legend:** `[x]` = working (code + route + backing data verified) · `[ ]` = not implemented. *Italics* flag known defects, shells, or dependencies.

---

## Foundation / Registration

- [x] Buyer registration modal + standalone page *(`RegisterBuyerController` + `StoreBuyerRegistrationRequest`)*
- [x] Last name, first name, middle initial, sex *(male/female, `Rule::in`)*
- [x] E-mail *(required, unique:buyers)*
- [x] Contact number *(PH format `09XXXXXXXXX`, regex validated)*
- [ ] Duplicate contact number prevention *(no `unique:buyers,contact_no` rule — 3 duplicate values exist in DB)*
- [x] Birthday *(three-select day/month/year, hidden input, auto-age via JS)*
- [x] Age (auto-generated) *(server-computed `Carbon::parse(...)->age`)*
- [x] Province / Municipality / Barangay dropdowns *(PSGC API cascading, manual fallback)*
- [x] Street / house number *(always required in both address modes; `address_detail` optional)*
- [x] Upload valid ID *(required; jpg/jpeg/png/pdf ≤5 MB)*
- [x] Registration form validation *(`StoreBuyerRegistrationRequest`)*
- [x] Duplicate email prevention *(`unique:buyers,email`)*
- [x] Registration submission *(throttled 5/min)*
- [x] OTP email verification *(6-digit code, SHA-256 stored, 10-min expiry)*
- [x] Pending approval status *(pending-verification page → `pending_approval` after OTP)*
- [x] Administrator approval *(`Admin\RegistrationController@approveBuyer`)*
- [x] Administrator rejection *(with rejection reason)*
- [x] Registration approval / rejection email *(`BuyerRegistrationDecision` notification)*
- [x] Buyer account activation after approval *(login blocked unless `status === 'approved'`)*

---

## Login & Authentication

- [x] Buyer login *(standalone page + modal; unified `POST /login`, throttled 5/min)*
- [x] Email + password validation
- [x] Approved-account verification *(pending/rejected handled with correct messages)*
- [x] Login error messages *(422 JSON for modal; `withErrors` for page)*
- [ ] Remember login / session *(session regenerate only; no remember-me token)*
- [x] Logout *(invalidates session, regenerates token, JSON redirect)*
- [x] Buyer authentication middleware *(`auth:buyer` guard)*
- [x] Buyer-only route protection *(cart/checkout/orders/account/chat behind `auth:buyer`; catalog open to guests)*
- [x] Unauthorized access handling *(cart mutations return 401 JSON to guests — tested)*

---

## Main Menu / Catalog

- [x] Buyer homepage *(tested: guest/buyer layout, active+in-stock products, approved sellers only)*
- [ ] Product categories *(`CategoryController` is an empty stub — `GET /buyer/categories[/{slug}]` are dead routes)*
- [x] Product listing *(`/buyer/products` — active products only)*
- [x] Product availability/status *(only active + in-stock listed — tested)*
- [ ] Product search *(explicit TODO in `ProductController@index`)*
- [ ] Product filtering / sorting / pagination *(TODO in controller)*

---

## Product Details

- [x] Product details page *(`Buyer/Products/show` + partials: `_gallery`, `_buybox`, `_tabs`, `_related`, `_seller-strip`)*
- [x] Product name, description, images, price, stock, category, seller info
- [ ] Product ratings / reviews *(no reviews table; `_reviews` partial is static markup)*
- [ ] Product variations *(no variants table)*
- [x] Select quantity *(buybox `#qvQty`)*
- [x] Quantity validation *(server-side: positive, capped at stock — tested)*
- [x] Add to cart *(`CartController@add`; merges duplicates, caps at stock, guests get 401 → login modal)*
- [x] Out-of-stock handling *(rejected on add — tested)*

---

## Cart

- [x] View cart *(tested; `CartSummary` is the single source of truth in integer centavos)*
- [x] Cart item list, quantity update, remove, bulk remove
- [x] Select / deselect cart items
- [x] Stock validation *(add + update reject above-stock and unavailable — tested)*
- [x] Item subtotal, cart subtotal, final total
- [x] Voucher application *(POST/DELETE `buyer/cart/voucher`, code in session)*
- [x] Voucher validation *(re-validated on every summary calc — tested; unknown/inactive/expired/below-minimum rejected)*
- [ ] Voucher usage limit *(`usage_limit` column exists; enforcement not implemented)*
- [ ] Shipping fee *(no shipping logic in `CartSummary`)*
- [ ] Product variation display *(no variants table)*
- [x] Empty cart state *(server-rendered — tested)*

---

## Checkout / Place Order

> `CheckoutController@index` resolves and parks selected items + voucher in session. `CheckoutController@store` does not exist. The `POST /buyer/checkout` route is registered but hitting it causes a 500. The checkout blade is a stub with a visually disabled "Coming soon" button — no form or order UI exists.

- [x] Checkout page stub *(selection silently filtered to buyer's own available items — tested)*
- [x] Order summary stub *(CartSummary: subtotal, voucher discount, total in centavos)*
- [x] Voucher / discount re-applied *(carried from cart session, re-resolved)*
- [ ] Place order *(`POST /buyer/checkout` → `CheckoutController@store` — method does not exist → live 500)*
- [ ] Buyer info / delivery address / shipping / payment method selection
- [ ] Order confirmation / order number generation
- [ ] Stock re-validation + deduction during checkout
- [ ] Order creation *(no `orders` table)*

---

## Payment

> Not started — no `payments` table; orders don't exist.

- [ ] All payment features (COD, online, status, confirmation, transaction reference)

---

## Orders

> `Buyer\OrderController` is an empty stub. `Buyer/Orders/index+show` are static shells. No `orders` tables.

- [ ] All order features (list, details, cancellation, tracking, timeline)

---

## Shipping & Delivery Tracking

> Not started — Logistics owns this workflow per settled architecture.

- [ ] All delivery tracking features

---

## Rate / Feedback

> Not started — no reviews/feedback tables.

- [ ] All review/rating features

---

## Chat / Messaging

> `Buyer\ChatController` is an empty stub. `Buyer/Chat` views are static shells. No conversation/message tables.

- [ ] All chat features

---

## Account Management

> `Buyer\AccountController` is an empty stub. `GET|PATCH /buyer/account` are dead routes. `Buyer/Account` views are static shells.

- [ ] All account management features (personal info, address, password, ID, profile picture)

---

## Notifications

> No `notifications` table. Registration OTP + decision notifications are email-only (no in-app center).

- [ ] All notification center features (order, shipment, chat, review, badge, read/unread)

---

## Security & Authorization

- [x] Buyer route protection *(`auth:buyer` on cart/checkout/orders/account/chat)*
- [x] Guests get 401 JSON from cart mutations — tested
- [x] Buyer cannot access seller / admin / logistics functions *(separate guards)*
- [x] Buyer cannot modify another buyer's cart *(ownership tested)*
- [x] Form validation *(`StoreBuyerRegistrationRequest`, throttles on register/login/OTP)*
- [x] File upload validation *(ID: jpg/jpeg/png/pdf ≤5 MB)*
- [x] Password security *(`Hash::make`, min 8 + confirmed; OTP SHA-256 + 10-min expiry)*
- [x] Session security *(regenerate on login; invalidate + token regenerate on logout)*
- [x] CSRF protection *(Laravel default)*
- [ ] No Laravel Policies project-wide *(ownership is controller-level; Policies required before orders/payments)*
- [ ] Buyer can only access own orders / addresses / conversations *(modules not built)*
- [ ] Voucher `usage_limit` not enforced

---

## UI & Responsive

- [x] Buyer desktop / tablet / mobile UI
- [x] Responsive navbar (guest/buyer states tested), product grid, product details, cart
- [ ] Responsive checkout stub, orders, chat, account *(stubs/shells)*
- [x] Loading states (login modal, add-to-cart toast), empty states (cart — tested), error states (422, 401 → login modal)
- [x] Success messages (flash + toast helpers)

---

## Testing

- [x] Cart test *(`CartControllerTest` — 21 tests: ownership, guest 401s, selection, empty state, navbar badge)*
- [x] Voucher test *(inside `CartControllerTest`: apply/remove/rejections/min-spend drop)*
- [x] Authentication test *(navbar guest/buyer/logout states — `NavbarTest`)*
- [x] Buyer registration street/address tests *(`RegistrationStreetTest` — 10 buyer tests)*
- [x] Home controller test *(`HomeControllerTest`)*
- [ ] Buyer registration validation / OTP / approval flow / login tests
- [ ] Product search / details / variations test
- [ ] Checkout / order / payment / tracking tests *(dependent on unbuilt modules)*
- [ ] Account / chat / review tests *(dependent on unbuilt modules)*

---

## Complete Buyer Flow

- [x] Register → OTP → wait for admin approval → receive email → login
- [x] Browse products, view product, select quantity, add to cart
- [x] View cart, apply voucher, see totals
- [ ] Select delivery address / payment method
- [ ] Place order *(live 500 — `store()` missing)*
- [ ] Track order / receive delivery / rate product / submit feedback
- [x] Logout

---

## Known defects / open items (as of 2026-09-22)

1. **`POST /buyer/checkout` → 500** — `CheckoutController@store` does not exist. The blade now shows a disabled "Coming soon" button, but the route is still registered and hitting it directly causes a fatal error.
2. **`GET /buyer/categories` → dead** — `CategoryController` is an empty stub despite views existing.
3. **`GET|PATCH /buyer/account` → dead** — `AccountController` is an empty stub; account views are static shells.
4. **`Buyer\OrderController` + `Buyer\ChatController` are empty stubs** — order/chat pages are useless.
5. **No `orders`/`payments`/`deliveries`/`reviews`/`notifications` tables** — entire post-cart funnel is blocked.
6. **Duplicate `contact_no` not enforced** — 3 duplicate phone numbers exist in the `buyers` table; `unique:buyers,contact_no` rule not yet added to `StoreBuyerRegistrationRequest`.
7. **No Laravel Policies** anywhere in the project.
8. **Voucher `usage_limit` not enforced** anywhere.
