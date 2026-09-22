# Buyer Coding Checklist

> **Audit legend (2026-09-20):** [x] = working (code + route + backing data verified) ·
> [ ] = not implemented. Notes in *(italics)* flag dependencies, UI-only shells, or dead routes.

---

## Foundation / Registration

- [x] Buyer registration page *(modal + standalone pages; `RegisterBuyerController` + `StoreBuyerRegistrationRequest`)*
- [x] Last name*
- [x] First name*
- [x] Middle initial
- [x] Sex *(male/female, `Rule::in`)*
- [x] E-mail*
- [x] Contact number *(PH format `09XXXXXXXXX`, regex-validated)*
- [x] Birthday*
- [x] Age (auto-generated) *(server-computed via `Carbon::parse(...)->age`)*
- [x] Province dropdown
- [x] Municipality dropdown
- [x] Barangay dropdown
- [x] Street / house number / additional address *(manual address mode; required in that mode)*
- [x] Address API integration *(PSGC API — `https://psgc.gitlab.io/api` cascading dropdowns; API vs manual mode switch)*
- [x] Upload valid ID *(required; jpg/jpeg/png/pdf, ≤5 MB)*
- [x] ID file validation
- [x] Registration form validation
- [x] Duplicate email prevention *(`unique:buyers,email`)*
- [ ] Duplicate contact number *(no unique rule on `contact_no`)*
- [x] Registration submission *(throttled 5/min)*
- [x] Pending approval status *(pending-verification page + `pending_approval` status)*
- [x] Administrator approval *(`Admin\RegistrationController@approveBuyer`)*
- [x] Administrator rejection *(with rejection reason)*
- [x] Registration approval email *(`BuyerRegistrationDecision` notification)*
- [x] Registration rejection email *(includes reason)*
- [x] Buyer account activation after approval *(login blocked unless `status === 'approved'`)*

---

## Login & Authentication

- [x] Buyer login *(standalone page + modal; unified `POST /login`, throttled 5/min)*
- [x] Email validation
- [x] Password validation
- [x] Approved-account verification
- [x] Pending-account handling *(`pending_verification` → "verify your email")*
- [x] Rejected-account handling *(shows rejection reason)*
- [x] Login error messages *(422 JSON for modal; `withErrors` for page)*
- [ ] Remember login/session *(session regenerate only; no remember-me token)*
- [x] Logout *(invalidates session + regenerates token; JSON redirect)*
- [x] Buyer authentication middleware *(`auth:buyer` guard, provider `buyers`)*
- [x] Buyer-only route protection *(cart/checkout/orders/account/chat behind `auth:buyer`; catalog open to guests)*
- [x] Unauthorized access handling *(cart mutations return 401 JSON to guests — tested)*

---

## Main Menu

- [x] Buyer homepage *(tested: layout for guests, active/in-stock products only, HTML-escaped names, unapproved sellers excluded from best-selling)*
- [ ] Product categories *(`CategoryController` is an EMPTY STUB — `GET /buyer/categories` and `/buyer/categories/{slug}` are dead routes despite views existing)*
- [ ] Category navigation *(same dead routes)*
- [x] Product listing *(`/buyer/products` — basic; active products only)*
- [x] Search bar *(UI present)*
- [ ] Product search *(`ProductController@index` has an explicit TODO — no query handling)*
- [ ] Search results *(not implemented)*
- [ ] Product filtering *(TODO in controller)*
- [ ] Product sorting *(TODO in controller)*
- [ ] Product pagination *(TODO in controller)*
- [x] Product availability/status *(only active + in-stock products are listed — tested)*

---

## Product Details

- [x] Product details page *(`Buyer\Products\show` + `_gallery/_buybox/_tabs/_related/_seller-strip` partials)*
- [x] Product name
- [x] Product description
- [x] Product images
- [x] Product price
- [x] Product stock
- [x] Seller information *(`_seller-strip` partial; only approved sellers' products render at all)*
- [x] Product category
- [ ] Product ratings *(no reviews table)*
- [ ] Product reviews *(no reviews table)*
- [ ] Product variations *(no variants table — TODO in `Buyer/Cart/_item.blade.php`)*
- [ ] Color variation
- [ ] Size variation
- [ ] Other product variations
- [ ] Variation-specific price
- [ ] Variation-specific stock
- [x] Select quantity *(`#qvQty` in `buyer.js` / buybox)*
- [x] Quantity validation *(server-side: positive, capped at stock — tested)*
- [x] Add to cart *(`CartController@add` merges duplicate lines, caps at stock, guests get 401 → login modal)*
- [x] Out-of-stock handling *(non-purchaseable products rejected on add — tested)*
- [ ] Product review display *(`_reviews` partial is static markup)*

---

## Cart

- [x] View cart *(tested; server-rendered, `CartSummary` is the single source of truth in integer centavos)*
- [x] Cart item list
- [x] Select cart items
- [x] Deselect cart items *(selection re-fetched server-side on every change)*
- [x] Product quantity update *(PATCH, capped at stock — tested)*
- [x] Remove cart item *(DELETE — tested)*
- [ ] Product variation display *(no variants table)*
- [x] Stock validation *(add + update reject above-stock and unavailable products — tested)*
- [x] Item subtotal
- [x] Cart subtotal
- [ ] Shipping fee *(no shipping logic in `CartSummary`)*
- [x] Discount calculation *(voucher fixed/percent, server-computed)*
- [x] Voucher application *(POST/DELETE `buyer/cart/voucher`, code in session)*
- [x] Voucher validation *(re-validated on every summary calc — tested)*
- [x] Invalid voucher handling *(unknown/inactive/expired/below-minimum rejected — tested)*
- [ ] Voucher usage limit *(`usage_limit` column exists; enforcement not implemented/tested)*
- [x] Final order total
- [x] Empty cart state *(server-rendered via `@if`, tested)*

---

## Checkout / Place Order

> *`CheckoutController@index` renders a stub page: it resolves + parks the buyer's selected cart
> items (query + session) and re-resolves the voucher. Everything past the summary is not built.*
- [x] Checkout page *(stub UI; selection is silently filtered to the buyer's own items — tested)*
- [x] Selected products displayed
- [x] Order quantity confirmation *(server-computed summary)*
- [ ] Product variation confirmation
- [ ] Buyer information
- [ ] Delivery address
- [ ] Select saved address
- [ ] Add/edit delivery address
- [ ] Shipping information
- [ ] Shipping fee calculation
- [x] Voucher/discount application *(carried from cart session; re-resolved + re-validated)*
- [ ] Payment method selection
- [ ] Payment method validation
- [x] Order summary *(CartSummary: subtotal, voucher discount, total in centavos)*
- [x] Final total calculation
- [ ] Place order *(`POST /buyer/checkout` → `CheckoutController@store` which DOES NOT EXIST → live 500)*
- [ ] Order confirmation *(`Checkout/success.blade.php` exists but is unreachable)*
- [ ] Order number generation
- [ ] Prevent duplicate order submission
- [ ] Stock re-validation during checkout
- [ ] Stock deduction
- [ ] Order creation *(no `orders` table)*
- [ ] Seller order creation *(no `seller_orders` table)*
- [ ] Order item creation *(no `order_items` table)*

---

## Payment

> *Not started — no `payments` table; `orders` don't exist yet.*
- [ ] Payment method selection
- [ ] Cash on Delivery
- [ ] Online payment
- [ ] Payment status
- [ ] Payment confirmation
- [ ] Failed payment handling
- [ ] Pending payment handling
- [ ] Payment reference/transaction number
- [ ] Payment information displayed in order details

---

## Orders

> *Not started — `Buyer\OrderController` is an EMPTY STUB; `Buyer/Orders/index+show` views are
> static shells; no `orders` tables.*
- [ ] My Orders page
- [ ] All orders
- [ ] To Pay
- [ ] To Ship
- [ ] To Receive
- [ ] In Transit
- [ ] Out for Delivery
- [ ] Delivered
- [ ] Cancelled
- [ ] Order search
- [ ] Order filtering
- [ ] Order sorting
- [ ] Order details
- [ ] Order number
- [ ] Seller information
- [ ] Ordered products
- [ ] Product variations
- [ ] Quantity
- [ ] Item prices
- [ ] Shipping fee
- [ ] Discounts
- [ ] Voucher
- [ ] Total amount
- [ ] Payment status
- [ ] Order status
- [ ] Delivery address
- [ ] Shipping information
- [ ] Order timeline

---

## Order Cancellation / Issues

> *Not started — depends on orders.*
- [ ] Cancel order
- [ ] Cancellation reason
- [ ] Cancellation confirmation
- [ ] Seller cancellation handling
- [ ] Cancellation status
- [ ] Refund status
- [ ] Refund information
- [ ] Order issue/report

---

## Shipping & Delivery Tracking

> *Not started — no `deliveries`/`delivery_status_history` tables; Logistics owns this workflow
> per the settled architecture.*
- [ ] View shipping information
- [ ] View courier information
- [ ] Shipment tracking
- [ ] Order tracking timeline
- [ ] To Ship status
- [ ] In Transit status
- [ ] Out for Delivery status
- [ ] Delivered status
- [ ] Delivery notifications
- [ ] Courier assignment display
- [ ] Estimated delivery information
- [ ] Delivery confirmation

---

## Rate / Feedback

> *Not started — no reviews/feedback tables; the product-page `_reviews` partial is static markup.*
- [ ] Rate completed order
- [ ] Rate product
- [ ] Product rating
- [ ] Written feedback
- [ ] Upload review images
- [ ] Edit review
- [ ] Delete review
- [ ] Review submission validation
- [ ] Average product rating
- [ ] Rating breakdown
- [ ] View submitted reviews
- [ ] Prevent duplicate review
- [ ] Only allow reviews for purchased products
- [ ] Only allow review after delivery

---

## Chat / Messaging

> *Not started — `Buyer\ChatController` is an EMPTY STUB; `Buyer/Chat/index+show` views are
> static shells; no conversation/message tables.*
- [ ] Chat page
- [ ] Conversation list
- [ ] Buyer-to-seller conversation
- [ ] Send message
- [ ] Receive message
- [ ] Message timestamps
- [ ] Read/unread status
- [ ] Unread message count
- [ ] Order-related chat
- [ ] Product-related chat
- [ ] Message history
- [ ] Empty conversation state

---

## Account Management

> *Not started — `Buyer\AccountController` is an EMPTY STUB, so `GET|PATCH /buyer/account` are
> dead routes. The `Buyer/Account` views (index/profile/address/password) are static shells with
> no data binding.*
- [ ] Buyer account page
- [ ] View personal information
- [ ] Edit personal information
- [ ] Edit first name
- [ ] Edit last name
- [ ] Edit middle initial
- [ ] Edit sex
- [ ] Edit birthday
- [ ] View auto-generated age
- [ ] Edit contact number
- [ ] Edit email
- [ ] Change password
- [ ] Profile picture
- [ ] View uploaded ID
- [ ] Update uploaded ID
- [ ] Address management
- [ ] Add address
- [ ] Edit address
- [ ] Delete address
- [ ] Set default address
- [ ] Province / Municipality / Barangay selection
- [ ] Street / house number
- [ ] Account status

---

## Notifications

> *Not started — no `notifications` table or Notification usage for orders/chat (only the
> registration OTP + registration-decision notifications exist).*
- [ ] Notification center
- [ ] Registration approval notification *(exists as email notification, not an in-app center)*
- [ ] Registration rejection notification *(same — email only)*
- [ ] New order notification
- [ ] Order confirmation notification
- [ ] Order status notification
- [ ] Shipment notification
- [ ] Out-for-delivery notification
- [ ] Delivery confirmation notification
- [ ] Cancellation notification
- [ ] Payment notification
- [ ] Voucher/discount notification
- [ ] Chat notification
- [ ] Review notification
- [ ] Notification badge
- [ ] Mark as read
- [ ] Mark all as read

---

## Security & Authorization

- [x] Buyer route protection *(`auth:buyer` on cart/checkout/orders/account/chat)*
- [x] Buyer-only access *(guests get 401 JSON from mutations — tested)*
- [x] Buyer cannot access seller functions *(separate `seller` guard + `EnsureSellerApproved`)*
- [x] Buyer cannot access logistics functions *(no logistics surface exists at all)*
- [x] Buyer cannot access admin functions *(separate `admin` guard + `EnsureAdminRole`)*
- [ ] Buyer can only access own account *(account module not built)*
- [ ] Buyer can only access own orders *(no orders)*
- [ ] Buyer can only access own addresses *(no addresses module)*
- [ ] Buyer can only access own conversations *(no chat)*
- [x] Buyer cannot modify another buyer's data *(cart ownership — tested)*
- [x] Form validation *(FormRequests + throttles on register/login/OTP)*
- [x] File upload validation *(registration ID: jpg/jpeg/png/pdf ≤5 MB)*
- [x] Password security *(`Hash::make`, min 8 + confirmed; OTP codes stored SHA-256 with expiry)*
- [x] Session security *(regenerate on login; invalidate + token regenerate on logout)*
- [x] CSRF protection *(Laravel default on all POST/PUT/PATCH/DELETE)*
- [x] Unauthorized access handling *(401/403 + redirects — tested)*
- [ ] Order ownership authorization *(no orders)*
- [ ] Review ownership authorization *(no reviews)*

---

## UI & Responsive

- [x] Buyer desktop UI
- [x] Buyer tablet UI *(visual QA pending)*
- [x] Buyer mobile UI
- [x] Responsive navigation *(navbar variants incl. landing; guest/buyer states tested)*
- [x] Responsive search
- [x] Responsive product grid
- [x] Responsive product details
- [x] Responsive cart *(page behaviour tested)*
- [ ] Responsive checkout *(stub page)*
- [ ] Responsive orders *(static shell)*
- [ ] Responsive order tracking *(not built)*
- [ ] Responsive chat *(static shell)*
- [ ] Responsive account page *(static shell)*
- [x] Loading states *(login modal, add-to-cart toasts via `buyer.js`)*
- [x] Empty states *(cart empty state server-rendered — tested)*
- [x] Error states *(422 modal errors, form errors, 401 → login-modal flow)*
- [x] Success messages *(flash + toast helpers)*
- [ ] Confirmation dialogs *(not audited)*

---

## Testing

- [x] Cart test *(`CartControllerTest` — 21 tests incl. ownership, guest 401s, selection, empty state, navbar badge)*
- [x] Voucher test *(inside `CartControllerTest`: apply/remove/rejections/min-spend drop)*
- [x] Authentication test *(navbar guest/buyer/logout states — `NavbarTest`)*
- [ ] Buyer registration test
- [ ] Registration validation test
- [ ] Address API test
- [ ] ID upload test
- [ ] Approval flow test
- [ ] Registration email test
- [ ] Buyer login test *(the login POST itself is untested)*
- [ ] Product search test
- [ ] Product details test
- [ ] Product variation test
- [ ] Checkout test *(only the index selection-filter is tested; `store()` is a live 500)*
- [ ] Order creation test
- [ ] Payment test
- [ ] Order status test
- [ ] Order cancellation test
- [ ] Delivery tracking test
- [ ] Review/rating test
- [ ] Chat test
- [ ] Account management test
- [ ] Authorization test *(cart-level only)*
- [ ] Responsive UI test
- [ ] End-to-end buyer flow test

---

## Complete Buyer Flow

- [x] Register
- [x] Wait for administrator approval
- [x] Receive approval email
- [x] Login
- [ ] Browse categories *(dead route)*
- [ ] Search products
- [x] View product
- [ ] Select variation
- [x] Select quantity
- [x] Add to cart
- [x] View cart
- [x] Apply voucher/discount
- [ ] Select delivery address
- [ ] Select payment method
- [ ] Place order *(live 500)*
- [ ] Receive order confirmation
- [ ] Track order
- [ ] Order shipped
- [ ] Order in transit
- [ ] Order out for delivery
- [ ] Order delivered
- [ ] Rate product
- [ ] Submit feedback
- [x] Continue shopping
- [x] Logout

---

## Known defects / compliance notes (from 2026-09-20 audit)

1. **`POST /buyer/checkout` → 500** — `CheckoutController@store` does not exist (route registered, method missing). Highest-priority buyer defect.
2. **`GET /buyer/categories` → dead** — `CategoryController` is an empty stub although `Buyer/Categories` views exist.
3. **`GET|PATCH /buyer/account` → dead** — `AccountController` is an empty stub; account views are static shells.
4. **`Buyer\OrderController` + `Buyer\ChatController` are empty stubs** — orders/chat pages render nothing useful.
5. **No `orders`/`seller_orders`/`order_items`/`payments`/`deliveries`/reviews/chat/`notifications` tables exist** — the entire post-cart funnel is blocked on the Orders phase.
6. **Duplicate `contact_no` not enforced** at registration (email is).
7. **No Laravel Policies** — cart ownership is controller-level (tested); project rules require Policies for sensitive models before orders/payments land.
8. **Voucher `usage_limit` not enforced** anywhere yet.
