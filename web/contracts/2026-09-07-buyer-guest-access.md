# Contract: Buyer Guest Access — Routing Split

## Objective

Split the buyer route group so catalog browsing (home, categories, products) is accessible to guests without login, while identity-specific pages (cart, checkout, orders, account, chat) remain behind `auth:buyer`. Update navbar, login modal, and controllers to handle both guest and authenticated buyers.

## Scope

### In Scope
- Restructure `routes/buyer.php` into guest-accessible and `auth:buyer`-guarded groups
- Update `Buyer/Layouts/navbar.blade.php` for guest vs authenticated rendering
- Include `Components/login-modal.blade.php` in buyer layout
- Make modal's `openModal()` globally accessible for add-to-cart trigger
- Update `Buyer/Home/index.blade.php` to handle guest buyers
- Update "Continue as guest" link to point to `buyer.home`

### Out of Scope
- Persistent guest cart — cart remains login-required per spec default
- New controller logic — existing controllers are stubs
- Product/category page content changes
- Registration/OTP flow changes
- Other role route changes (admin/seller/logistics/courier)

## Research Findings

### Current Routes
- `routes/buyer.php` — ALL routes behind `auth:buyer`
- `routes/web.php` — Buyer login (guest:buyer) and logout (auth:buyer) here

### Existing Components
| Component | Status |
|-----------|--------|
| `Components/login-modal.blade.php` | EXISTS — full modal, openModal/closeModal in IIFE, NOT globally accessible |
| `Buyer/Layouts/navbar.blade.php` | EXISTS — has auth fallback but shows account dropdown unconditionally |
| `Buyer/Layouts/app.blade.php` | EXISTS — does NOT include login modal |
| `Buyer/Home/index.blade.php` | EXISTS — uses `$buyerName ?? 'there'` (never passed from controller) |
| `LoginController` | EXISTS — handles JSON/AJAX with wantsJson() |
| `buyer.js` | EXISTS — NO login modal integration |

### Key Discovery: Login Modal JS
`openModal()` is scoped in IIFE — not globally accessible. Need to expose via `window.openModal`. Modal triggered by `[data-login-trigger]` attribute.

### Key Discovery: Guest Link
"Continue as Guest" currently links to `shop.browse` (landing). Should be `buyer.home`.

## Implementation Plan

### Step 1: Restructure `routes/buyer.php`
Replace single auth:buyer group with guest group + nested auth group. Move login routes from web.php.

### Step 2: Update navbar for guest/auth conditional
`@auth('buyer')` dropdown / `@else` login button with `data-login-trigger` / `@endauth`

### Step 3: Include login modal in buyer layout
`@include('Components.login-modal')` in `Buyer/Layouts/app.blade.php`

### Step 4: Expose `openModal()` globally
Add `window.openModal = openModal;` in modal's IIFE

### Step 5: Fix guest link in modal
Change `route('shop.browse')` to `route('buyer.home')`

### Step 6: Pass `$buyerName` from HomeController
`$buyer = auth('buyer')->user(); $buyerName = $buyer ? $buyer->fullName() : null;`

## Security
- No new authorization boundaries — only existing `auth:buyer` middleware reused
- Guest routes intentionally public (catalog)
- Cart/checkout/orders remain behind `auth:buyer`
- No mass assignment or database changes

## Tests
- Guest accesses `/buyer`, `/buyer/categories`, `/buyer/products` — OK
- Guest hits `/buyer/cart`, `/buyer/checkout`, `/buyer/orders`, `/buyer/account` — redirected
- Navbar shows login for guests, dropdown for auth
- Modal opens from navbar guest button
- "Continue as guest" → buyer.home

## Open Questions
1. Cart guest-accessible? — Default: login-required
2. "Continue as guest" session suppression? — Default: show again
3. Seller/store info gating? — Default: show to guests
4. Modal "on success" callback? — Current modal uses classic form POST (page reload). No callback exists. "Retry add-to-cart after login" needs callback mechanism — flag for developer.

## Acceptance Criteria
- [ ] Guests can browse home/categories/products
- [ ] Guests redirected from cart/checkout/orders/account
- [ ] Navbar conditional rendering works
- [ ] Login modal opens from navbar
- [ ] Guest link correct
- [ ] Home greeting works for guests
- [ ] No regression on login/logout

## Developer Approval
- [ ] Research completed
- [ ] Plan reviewed
- [ ] Open questions resolved
- [ ] Scope approved
- [ ] Implementation approved

Developer approval:
Date:
Notes: