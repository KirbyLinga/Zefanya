# Zefanya QA workbook triage - 24 October 2026

Source of truth: the current `mobile/lib` tree, not the older zip copies.

Artifacts:

- `qa/triage.csv` - all 333 non-Pass cases with group, size, root cause, projection and resolution.
- `qa/build_triage.py` - reproducible A-E classifier that regenerates the CSV.
- `qa/implemented_fixes.diff` - unified diff for every app/test change in this pass.
- `qa/full_test_run.txt` - latest full-suite log (**516 passed / 41 failed**).
- `qa/failing_tests_all.txt` - the 41 failing tests, all pre-existing golden/screenshot/harness cases.
- `qa/QA_REPORT.md` - this report.

No commit or push has been made.

---

## 1. Headline

The workbook has **377 cases**: 44 Pass, 196 Fail, 137 Blocked.

Of the 333 non-Pass cases, the baseline triage is unchanged:

| Group | Meaning | Cases | Share of non-Pass |
|---|---|---:|---:|
| A | App defect, fixed in Flutter | **20** | 6.0% |
| B-M | Missing app feature, medium | **58** | 17.4% |
| B-L | Missing app feature, large | **29** | 8.7% |
| B-S | Missing app feature, small | **0** | 0.0% |
| C | Test-plan mismatch / wrong surface / mock-mode contradiction | **129** | 38.7% |
| D | Needs Laravel API/server rule/payment gateway | **57** | 17.1% |
| E | Needs device/permission/network/seeded data | **40** | 12.0% |
| **Total** | | **333** | **100%** |

B-S remains zero: every remaining missing feature needs at least a new model/API field plus UI, or a state machine. Nothing was relabelled S to balance the table.

### What has actually been built

- The original 20 A fixes are implemented and tested.
- **27 additional B cases** now have app code and targeted tests (listed in section 4).
- **10 cases are partial**: the app-side behavior is done, but the stated outcome also requires a server/device action this repo cannot perform. They are deliberately **not** counted as Pass.

Projection:

- Baseline workbook Passes: **44 / 377 (11.7%)**.
- Projected after implemented fixes: **91 / 377 (24.1%)**.
- After the 129 C cases are approved as out of scope: **91 / 248 (36.7%)**.

This is a projection, not a rerun result. A workbook rerun against the same fixtures/live server is still required before any row is called Pass.

---

## 2. Per-workbook triage and projection

| Workbook | Total | Pass | Fail | Blocked | A | B-M | B-L | C | D | E | Projected Pass |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Buyer Account Creation | 42 | 18 | 9 | 15 | 7 | 0 | 0 | 2 | 11 | 4 | **25** |
| Buyer Modules | 131 | 21 | 85 | 25 | 7 | 37 | 25 | 3 | 23 | 15 | **53** |
| Rider Account Creation | 47 | 1 | 8 | 38 | 0 | 0 | 0 | 42 | 4 | 0 | **1** |
| Courier Account Creation | 52 | 2 | 9 | 41 | 0 | 0 | 0 | 46 | 4 | 0 | **2** |
| Rider Modules | 53 | 2 | 39 | 12 | 6 | 21 | 4 | 0 | 9 | 11 | **10** |
| Courier Modules | 52 | 0 | 46 | 6 | 0 | 0 | 0 | 36 | 6 | 10 | **0** |
| **Total** | **377** | **44** | **196** | **137** | **20** | **58** | **29** | **129** | **57** | **40** | **91** |

The +47 projection is 20 A fixes plus 27 newly implemented B cases:

| Workbook | Pass now | A fixes | New B fixes | Projected |
|---|---:|---:|---:|---:|
| Buyer Account Creation | 18 | +7 | 0 | **25** |
| Buyer Modules | 21 | +7 | +25 | **53** |
| Rider Account Creation | 1 | 0 | 0 | **1** |
| Courier Account Creation | 2 | 0 | 0 | **2** |
| Rider Modules | 2 | +6 | +2 | **10** |
| Courier Modules | 0 | 0 | 0 | **0** |
| **Total** | **44** | **+20** | **+27** | **91** |

The 10 partial cases are not included in the 91.

---

## 3. Group C decisions and recommendations

C is the largest group because the workbook mixes three surfaces and one role definition. These are not app bugs, and bending the app to them would contradict the stated assumptions and the mock-mode requirement.

| C cluster | Cases | Why it is C | Recommendation |
|---|---:|---|---|
| Rider Account Creation | **42** | Tests Logistics' web Add Rider form and activation flow. The Flutter app has no Rider role. `TC-RID-028/030/031/032` are D because they are server ownership/access rules. | Move all 42 to the Logistics web test plan. If mobile Rider onboarding is required, treat it as a new B-L feature. |
| Courier Account Creation | **46** | Tests Logistics' web Add Courier form and activation flow. The app has a public courier sign-up instead; A17 says activation is not in the public app. `TC-COU-033/035/036/037` are D. | Move all 46 to Logistics web. If A17/A18 are authoritative, remove the public courier sign-up and build the Logistics-created activation flow as B-L + D. |
| Courier Modules | **36** | The workbook's Courier is a hub-to-hub line-haul role (trips, load manifests, hub arrival, remittance). The app's Courier role is the pickup/delivery role covered by Rider Modules. | Re-map to Logistics web. If hub-to-hub must be in this app, it is a new B-L module, not a fix to the current courier screens. |
| `TC-BUY-023`, `TC-BUY-028` | **2** | The mock build deliberately sends registration straight to Under Review and accepts any credentials, so a live verified-account gate cannot be tested there. A12 also puts the buyer behind review, not instant Active. | N/A for the mock build. Verify only in live mode, where it becomes D. Do not break the required mock behavior. |
| `BUY-CHK-002` | **1** | Test expects per-store/zone shipping. The app's documented rule is flat PHP 60, free at PHP 1,000. | Approve the flat rule and mark C. If per-store/zone shipping is required, change the product rule first; that is a B-L pricing change. |
| `BUY-CART-010`, `BUY-CART-011` | **2** | Tests assume a server-side cart with cross-account ownership. The app's cart is local by design. | Approve the local-cart architecture and mark C. If a server cart is required, that is B-L plus D. |
| **Total** | **129** | | |

Both denominators are valid:

- All workbook cases: **91 / 377 = 24.1%**.
- Mobile-app-applicable after C is approved out of scope: **91 / 248 = 36.7%**.

The second number is the useful one for the Flutter app, **provided product/test-plan signs off on the C decisions above**.

---

## 4. What was implemented after the first triage

### 4.1 Twenty-seven additional B cases now pass app-side

| Cases | Patch files | What changed | Proof |
|---|---|---|---|
| `BUY-SRCH-004`, `BUY-SRCH-007` | `mobile/lib/screens/buyer/search_screen.dart`, `mobile/lib/utils/search_filter.dart` | No-results state returns a catalogue spelling suggestion; min/max price dialog filters inclusively and refuses negatives or min > max. | `test/search_filter_test.dart` (23 passing) |
| `BUY-WISH-004` | `mobile/lib/state/wishlist_state.dart`, `mobile/lib/screens/buyer/seller_screen.dart`, `mobile/lib/screens/buyer/saved_screen.dart`, `mobile/lib/screens/shared/profile_screen.dart` | Persisted store follow/unfollow, separate from product wishlist; Saved shows Following and Profile counts it. | `test/store_follow_test.dart` (5 passing) |
| `BUY-CAN-002`, `BUY-CAN-004`, `BUY-CAN-005` | `mobile/lib/state/order_state.dart`, `mobile/lib/screens/buyer/order_detail_screen.dart`, `mobile/lib/screens/buyer/orders_status_screen.dart`, `mobile/lib/data/repositories/order_repository.dart` | Cancellation requires a reason, only appears before pickup, guards double taps, and persists `cancelled` + reason. Cancelled orders show a badge instead of disappearing. | `test/order_lifecycle_test.dart` (10 passing) |
| `BUY-TRK-004` | same order files | Order Received is only accepted for a delivered order, transitions once to Completed, and cannot be invoked before delivery. | `test/order_lifecycle_test.dart` |
| `BUY-RATE-004`, `BUY-RATE-005`, `BUY-RATE-007` | `mobile/lib/state/review_state.dart`, `mobile/lib/screens/buyer/review_screen.dart`, `mobile/lib/data/mock_data.dart` | 500-character limit, up to 5 photos, edit-once within 30 days, original `createdAt` preserved, `editCount` persisted; duplicate submit guarded. | `test/review_limits_test.dart` (with `review_flow_test.dart`) |
| `BUY-ADDR-001` to `007`, `BUY-ADDR-011` | `mobile/lib/data/address_entry.dart`, `mobile/lib/data/ph_regions.dart`, `mobile/lib/state/auth_state.dart`, `mobile/lib/screens/buyer/address_book_screen.dart`, `mobile/lib/screens/buyer/address_form_screen.dart`, `mobile/lib/screens/shared/profile_screen.dart`, `mobile/lib/screens/buyer/checkout_screen.dart` | Local address book: recipient + per-address phone, Region → City → Barangay cascade that clears children, required-field messages, `09XXXXXXXXX` / `+639XXXXXXXXX` only, 10-address cap, exactly one default, default deletion blocked until another is chosen, Profile entry point, checkout pre-selects the default. | `test/address_book_test.dart` (10 passing), `test/checkout_validation_test.dart` (3 passing) |
| `RID-PICK-003`, `RID-PICK-004` | `mobile/lib/screens/courier/delivery_tracker_screen.dart` | Pickup asks for the tracking number; blank and wrong values are rejected, the correct value (case-insensitive, trimmed) advances to Picked Up exactly once. | `test/pickup_verification_test.dart` (2 passing) |
| `BUY-RET-002`, `BUY-RET-003`, `BUY-RET-004` | `mobile/lib/state/return_state.dart`, `mobile/lib/data/repositories/return_repository.dart`, `mobile/lib/screens/buyer/return_screen.dart`, `mobile/lib/screens/buyer/order_detail_screen.dart`, `mobile/lib/screens/buyer/orders_status_screen.dart`, `mobile/lib/state/order_state.dart` | A return is unavailable before delivery; day 7 is allowed and day 8 is refused; a completed order and an existing request are refused. The description needs 10+ characters and evidence needs 1-5 photos, with a specific message for each failure. The request is persisted locally and shown as Return/Refund. | `test/returns_test.dart` (12 passing) |
| `BUY-PDP-001`, `BUY-PDP-002`, `BUY-PDP-003`, `BUY-CART-002` | `mobile/lib/data/mock_data.dart`, `mobile/lib/screens/buyer/product_details_screen.dart`, `mobile/lib/screens/buyer/widgets/product_card.dart`, `mobile/lib/state/cart_state.dart`, `mobile/lib/screens/buyer/cart_screen.dart`, `mobile/lib/screens/buyer/checkout_screen.dart`, `mobile/lib/state/order_state.dart` | Additive variant model: legacy flat labels stay, while rich products carry `VariationType` + `ProductVariant` (selections, price, stock, image). Mock `p9` is Color × Size with Black/M out of stock and White/M available. PDP requires every group, shows the exact `Please select a variation` gate before either action, updates price/stock/image from the selected SKU, and disables only impossible combinations. Cart identity stays `(product, variant label)`; each line keeps its own `unitPrice`, `variantStock` and `variantImage`, persists/rehydrates through the SKU and sends the line price to orders. | `test/product_variants_test.dart` (4 passing) |

Address book limitations, stated plainly:

- Persistence is local to `AuthState` (`auth_addresses`), like the profile data. There is still no address endpoint.
- `BUY-ADDR-009` (another buyer's address) is an ownership rule and remains server-side.
- `BUY-ADDR-010` (Use My Location) needs OS location permission/GPS and is not built.
- Region/city/barangay data is a fixture in `ph_regions.dart`, not a PSGC API.
- Checkout uses the default address for display and dispatch, but an order does not yet store a structured address snapshot; that is why `BUY-ADDR-008` is partial.

### 4.2 Ten partial cases (not counted as Pass)

| Case | App-side done | Still needed |
|---|---|---|
| `BUY-ADDR-008` | Editing/deleting the address does not mutate existing orders. | Order/waybill/seller snapshot of the address used at checkout; server-side. |
| `BUY-CAN-001` | COD order cancels with a reason and a Cancelled badge. | Stock restoration and seller notification; server-side. |
| `BUY-CAN-003` | Prepaid order cancels with a reason. | Refund start and voucher return to wallet; server-side. |
| `BUY-CAN-006` | Double tap produces one cancellation. | Stock is not restored at all yet, so "restored once" cannot be proven. |
| `BUY-RATE-001` | Photos now attach (up to 5). | Variant and masked buyer name are still absent. |
| `BUY-TRK-003` | Order Received moves the order to Completed and exposes Rate. | Seller earnings release is server-side. |
| `RID-FAIL-005` | Third failed attempt becomes Return to Sender; no fourth attempt. | Buyer/seller notifications are server-side. |
| `BUY-RET-001` | Request submits within 7 days, order shows Return/Refund, and Order Received is blocked while open. | Seller notification and the seller decision are server-side; local mock cannot deliver either. |
| `BUY-RET-005` | A rejected request shows Rejected, the seller's exact reason, and a working Escalate action. | The seller rejection event itself has no seller dashboard/API to produce it; tested with a repository that supplies the rejection. |
| `BUY-RET-006` | Photos are stored in the chosen order with previews before submit and removal by index. | The seller seeing the submitted photos requires the missing seller surface/API and live photo upload. |

The remaining 50 B cases still fail app-side, and 10 are partial. The 57 D and 40 E cases are unchanged.

---

## 5. Remaining prioritized plan

The order below is by cases turned Pass per unit of work, easiest first. It deliberately excludes C.

### P1 - server contract first: 57 D cases

These are blocked on Laravel/API behavior, not on more Flutter UI. Build the contract once, then the app can consume it.

| Contract | Cases | What the server must own |
|---|---:|---|
| Registration, OTP, profile, access control, address ownership | 20 | Duplicate email/phone, normalized uniqueness, OTP expiry/resend, verified-account gate, role checks, profile/address endpoints, `BUY-ADDR-009`. |
| Price/stock/voucher/payment | 18 | Re-price every line, reject changed prices, re-check stock, voucher expiry/minimum/limit/eligibility, shipping, payment intent/expiry. |
| Ownership and order state | 15 | Buyer cannot read/change another buyer's order; rider/courier cannot see another company's job; delivered/auto-complete/remittance rules. |
| Live search/reviews/chat | 4 | Search endpoint, draft visibility, review aggregates, chat transport. |
| **Total** | **57** | |

Server re-checks that must not be skipped even after the app fixes:

- Price, line total, shipping and voucher discount.
- Stock at order creation and at payment confirmation.
- Voucher existence, expiry, minimum spend, usage limit and per-buyer limit.
- Payment status, amount and order expiry.
- Ownership/role on every order, job, profile, address and chat resource.
- Duplicate email/phone and OTP validity/expiry/resend.
- COD collection and remittance confirmation.
- Review aggregates and "verified purchase" rules.
- Cancellation side effects: stock restore, refund, voucher return, seller notice.
- Order Received: seller earnings release.

### P2 - remaining B-M: 36 fail + 6 partial (of 58)

Highest-yield next features:

| Feature | Remaining fail | Partial |
|---|---:|---:|
| Change password/email/phone | 8 | 0 |
| Rider profile/vehicle/documents | 5 | 0 |
| Rider job offer/accept/decline/concurrency | 5 | 0 |
| PDP purchase limit/share deep link | 2 | 0 |
| Voucher wallet/stacking/eligibility | 4 | 0 |
| Rider delivery OTP/COD | 3 | 0 |
| Rider COD balance/remittance | 3 | 0 |
| Chat attachments/notification list | 2 | 0 |
| Cart availability while in cart | 1 | 0 |
| Search: vacation store | 1 | 0 |
| Rider pickup: camera scan | 1 | 0 |
| Failed delivery: mark failed with a reason | 1 | 1 |
| Cancellation side effects | 0 | 3 |
| Review variant/masked name | 0 | 1 |
| Seller earnings after Order Received | 0 | 1 |
| Store follow, search typo/price, pickup typing, PDP variants, variant cart lines | 0 | 0 (implemented) |
| **Total** | **36** | **6** |

Note: `BUY-ACCT-003..009` and `BUY-ACCT-011` cannot honestly be finished in mock mode. The mock login accepts any credentials and stores no password, so "wrong old password" and "old password stops working" need a real credential store. Treat the change-password cluster as D + B-M UI, not an app-only fix. (`BUY-ACCT-010` is the separate profile-photo E case.)

### P3 - remaining B-L: 14 fail + 4 partial (of 29)

| Feature | Remaining fail | Partial |
|---|---:|---:|
| Returns/refunds (evidence, window, status) | 0 | 3 |
| Address book residual (ownership + location) | 2 | 1 |
| Rider online/offline presence | 4 | 0 |
| Multi-voucher/per-store/weight checkout | 2 | 0 |
| Notification centre/deep links | 2 | 0 |
| Delivery timeline | 1 | 0 |
| Voucher after cancellation | 1 | 0 |
| Sale/limited-drop rules | 1 | 0 |
| Server-synced cart | 1 | 0 |
| **Total** | **14** | **4** |

The address residual is `BUY-ADDR-009` (ownership, server-side) and `BUY-ADDR-010` (Use My Location, device permission). `BUY-ADDR-008` is the partial snapshot case.

Returns: `BUY-RET-002/003/004` now have app-side code and passing tests (`test/returns_test.dart`). `BUY-RET-001/005/006` are partial because seller notification, the seller rejection event, and the seller's view of the uploaded photos require a seller surface/API that this app does not contain.

### P4 - E: 40 cases

Do not write app code for these until the device/data is available; many will pass on the correct fixture without a code change. Use the checklist in section 7.

---

## 6. Projected Pass counts per workbook

| Workbook | Current | Projected | Delta | C N/A | B remaining | B partial | D remaining | E remaining |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Buyer Account Creation | 18 | **25** | +7 | 2 | 0 | 0 | 11 | 4 |
| Buyer Modules | 21 | **53** | +32 | 3 | 28 | 9 | 23 | 15 |
| Rider Account Creation | 1 | **1** | 0 | 42 | 0 | 0 | 4 | 0 |
| Courier Account Creation | 2 | **2** | 0 | 46 | 0 | 0 | 4 | 0 |
| Rider Modules | 2 | **10** | +8 | 0 | 22 | 1 | 9 | 11 |
| Courier Modules | 0 | **0** | 0 | 36 | 0 | 0 | 6 | 10 |
| **Total** | **44** | **91** | **+47** | **129** | **50** | **10** | **57** | **40** |

Both views:

- All cases: **91 / 377 = 24.1%**.
- App-applicable after C is moved out: **91 / 248 = 36.7%**.

The projection is conservative: it counts only app-side fixes with code and targeted tests. It does not count partial, D or E cases, and it does not count C as Pass.

---

## 7. Blocked-case re-test checklist

The original workbook has 137 Blocked cases: **73 C, 43 D, 21 E**. No Blocked case is A or B.

### 7.1 C - close by scope decision (73 Blocked, 129 total C)

- [ ] Product/test-plan sign-off on all six C decisions in section 3.
- [ ] Rider Account Creation: move `TC-RID-001..019`, `TC-RID-026/027`, `TC-RID-033..043`, `TC-RID-046/047` to Logistics web (42 C; D exceptions 028/030/031/032).
- [ ] Courier Account Creation: move `TC-COU-001..024`, `TC-COU-031/032`, `TC-COU-038..050` to Logistics web (46 C; D exceptions 033/035/036/037).
- [ ] Courier Modules: move the 36 hub-to-hub cases to Logistics web, or open a separate B-L product decision. Do not test them against the pickup/delivery courier screens.
- [ ] Buyer mock: mark `TC-BUY-023` and `TC-BUY-028` N/A for mock mode; schedule live verification under D.
- [ ] Shipping/cart: approve flat shipping/local cart, or explicitly open the B-L replacement work for `BUY-CHK-002`, `BUY-CART-010`, `BUY-CART-011`.

### 7.2 D - live API/payment re-test (43 Blocked D cases)

- [ ] Registration/OTP: `TC-BUY-001`, `010`, `011`, `015`, `016`, `026`, `027`, `029`, `030`, `035`, `036`; Rider `TC-RID-028`, `030`, `031`, `032`; Courier `TC-COU-033`, `035`, `036`, `037`.
- [ ] Buyer profile/search/cart: `BUY-ACCT-001`, `BUY-SRCH-005`, `BUY-SRCH-010`, `BUY-CART-006`.
- [ ] Checkout integrity: `BUY-CHK-008`, `BUY-CHK-011`, `BUY-CHK-012`.
- [ ] Ownership/reviews/chat: `BUY-TRK-006`, `BUY-RATE-006`, `BUY-CHAT-001`, `BUY-CHAT-008`, plus `BUY-ADDR-009` for another buyer's address.
- [ ] Rider ownership/earnings: `RID-PROF-007`, `RID-JOB-005`, `RID-JOB-007`, `RID-PICK-007`, `RID-DLV-007`, `RID-DLV-009`, `RID-EARN-006`.
- [ ] Courier ownership: `COU-PROF-006/007`, `COU-TRIP-005/006`, `COU-ARR-006`, `COU-PAY-005`.
- [ ] Payment gateway: the 8 `BUY-PAY-002..009` cases with test cards, expiry, declined payment and duplicate-submit checks.
- [ ] Server re-check evidence: capture request/response or DB state showing price, stock, voucher, payment, ownership and duplicate checks, not just UI text.
- [ ] Cancellation/receipt side effects: stock restore, refund, voucher return, seller notification, seller earnings release.

### 7.3 E - device/data re-test (21 Blocked E cases; 40 E total)

- [ ] Keyboard/rotation/background/large font: `TC-BUY-032`, `TC-BUY-033`, `TC-BUY-034`, `TC-BUY-040`; the Rider/Courier equivalents in the C cluster are web-form cases.
- [ ] Camera/gallery and profile photo: `BUY-ACCT-010`, `RID-PICK-006` (camera denied for waybill scan), review photo capture; document upload cases in Rider/Courier Account Creation are C because the form is web-only.
- [ ] Real network loss/airplane mode: `BUY-SRCH-013`, `BUY-CHK-013` (and the live-network D cases).
- [ ] Seeded fixtures: `BUY-SRCH-001/002/003`, `BUY-PDP-008`, `BUY-WISH-003`, `BUY-VOU-002/004/010` - seed the `P-xx`, `ADDR-x`, `CAT-x`, Buyer 1, Seller 1 and voucher records the workbook names.
- [ ] Location permission: `BUY-ADDR-010` (Use My Location with permission allowed and denied).
- [ ] Device camera for proof of delivery: Rider delivery proof and pickup scan cases.
- [ ] GPS/maps/background: `RID-JOB-009`, `RID-PICK-005`, `RID-DLV-001`, `RID-EARN-001/002`.
- [ ] FCM/push/deep links: `BUY-TRK-007`, `BUY-CHAT-006/007`, `RID-JOB-008`, and the courier trip/arrival notification cases.
- [ ] Two-device/session checks: password change ending the other session, logout/session timeout, cross-device cart/order state.
- [ ] Record the exact build (`--dart-define=zefanya.useLiveApi=true/false`), device/OS, permissions and network state for each run.

---

## 8. Verification

- `flutter analyze --no-pub`: **0 errors**, 9 pre-existing style/deprecation infos (curly braces, `Radio` deprecation). No new analyzer errors.
- Full suite: **516 passed / 41 failed** in `qa/full_test_run.txt`.
- The 41 failures are the remaining pre-existing baseline set: 32 screenshot cases, 2 courier tracker goldens, 2 order-confirmation goldens, 3 delivery-completion cases and 2 featured-deck cases. They are font/golden/harness failures, not functional regressions. See `qa/failing_tests_all.txt`. The former live-boot failure is fixed: the Orders failure title now matches the tested copy, and `test/live_boot_test.dart` passes 7/7.
- Targeted suites added or extended in this pass:
  - `test/address_book_test.dart` - 10 passing: seeding, ADDR-2, one default, both phone formats, 10-address cap, default-deletion rule, persistence, add form, cascade clearing, blank fields.
  - `test/checkout_validation_test.dart` - 3 passing: empty address/terms errors and checkout default-address pre-selection.
  - `test/order_lifecycle_test.dart` - 10 passing: cancellation reason, pre-shipment gate, double tap, persisted cancellation, Order Received before/after delivery.
  - `test/search_filter_test.dart`, `test/search_screen_chips_test.dart` - typo suggestion, no results, inclusive min/max, invalid ranges.
  - `test/review_limits_test.dart` - 500 characters, five photos, one edit in 30 days, duplicate submit.
  - `test/store_follow_test.dart` - 5 passing: follow/unfollow persistence, separate from wishlist, Saved/Profile counts.
  - `test/pickup_verification_test.dart` - 2 passing: blank, wrong, correct tracking number, one-way advance.
  - `test/returns_test.dart` - 12 passing: pre-delivery/completed refusals, day-7/day-8 boundary, 10-character and 1-5 photo rules, ordered-photo persistence, open-request completion block, exact rejection reason, Escalate, status overlay, form validation and remove/reorder behavior.
  - `test/product_variants_test.dart` - 4 passing: missing-variation gate on Add to Cart and Buy Now, SKU price/stock/image updates, Black/M disabled while White/M is selectable, and two cart lines with separate `₱249`/`₱199` prices.
  - `test/voucher_expiry_test.dart` - 6 passing; the local-day expiry fixture now derives today from local midnight instead of UTC midnight, removing an early-morning timezone flake.
  - `test/courier_failure_flow_test.dart`, `test/courier_flow_test.dart` - failed-delivery reason/attempt/return and pickup/delivery flows.
  - `test/validators_test.dart`, `test/registration_flow_test.dart`, `test/pricing_rules_test.dart`, `test/quantity_limit_test.dart` - shared validation, OTP, pricing, quantity caps.
  - Affected-surface suite (`product_variants`, `accessibility_task4`, `product_gallery`, `stock_logic`, `cart_swipe`, `pricing_rules`, `quantity_limit`, `checkout_validation`, `order_lifecycle`, `product_image_cache`, `live_catalog`) - **72 passing**.
- No commit or push.

Limitations:

- Projected Pass counts are not actual workbook Passes; rerun against the same fixtures/live server.
- Pickup verification is manual tracking-number entry, not barcode scanning; `RID-PICK-006` camera-denied remains E.
- Address book is local-only; `BUY-ADDR-008` (order snapshot) and `BUY-ADDR-009` (ownership) remain server work, and `BUY-ADDR-010` remains device work.
- Cancellation/receipt app behavior is complete, but stock restore, refunds, voucher return, seller notices and seller earnings remain D.
- Returns are local-only in mock mode. Seller notification, the seller rejection event, the seller's view of submitted photos and refund settlement remain server-side; `BUY-RET-001/005/006` are partial for that reason.
- Rich per-SKU variants are demonstrated by the mock `p9` fixture. The live catalogue mapper still reads the existing flat `variants` shape; a live per-SKU price/stock/image payload and a stable SKU identifier remain a server-contract item, so live mode keeps its current one-dimensional behavior without breaking.
- Variant cart lines store the display label (for example `Black / M`) and resolve it back to the SKU on reload. That is sufficient for the mock catalogue; a live build should key lines by server SKU id instead.
- Review photos are local paths; live upload still sends the existing single `photo_path` until the multi-photo endpoint exists.
- The 129 C cases need product/test-plan sign-off before they can be excluded from the mobile denominator.

---

## 9. Files changed in this pass

Exact old/new lines are in `qa/implemented_fixes.diff`.

Modified app files:

- `mobile/lib/data/mock_data.dart`
- `mobile/lib/data/repositories/order_repository.dart`
- `mobile/lib/data/repositories/repositories.dart`
- `mobile/lib/main.dart`
- `mobile/lib/screens/auth/otp_screen.dart`
- `mobile/lib/screens/auth/registration_screen.dart`
- `mobile/lib/screens/buyer/cart_screen.dart`
- `mobile/lib/screens/buyer/checkout_screen.dart`
- `mobile/lib/screens/buyer/order_detail_screen.dart`
- `mobile/lib/screens/buyer/orders_status_screen.dart`
- `mobile/lib/screens/buyer/product_details_screen.dart`
- `mobile/lib/screens/buyer/review_screen.dart`
- `mobile/lib/screens/buyer/saved_screen.dart`
- `mobile/lib/screens/buyer/search_screen.dart`
- `mobile/lib/screens/buyer/seller_screen.dart`
- `mobile/lib/screens/buyer/widgets/product_card.dart`
- `mobile/lib/screens/courier/delivery_tracker_screen.dart`
- `mobile/lib/screens/shared/edit_profile_screen.dart`
- `mobile/lib/screens/shared/profile_screen.dart`
- `mobile/lib/state/auth_state.dart`
- `mobile/lib/state/cart_state.dart`
- `mobile/lib/state/catalog_state.dart`
- `mobile/lib/state/courier_state.dart`
- `mobile/lib/state/order_state.dart`
- `mobile/lib/state/review_state.dart`
- `mobile/lib/state/wishlist_state.dart`
- `mobile/lib/utils/image_picker_helper.dart`
- `mobile/lib/utils/search_filter.dart`
- `mobile/lib/widgets/dirty_guard.dart`

New app files:

- `mobile/lib/data/address_entry.dart`
- `mobile/lib/data/ph_regions.dart`
- `mobile/lib/data/repositories/return_repository.dart`
- `mobile/lib/screens/buyer/address_book_screen.dart`
- `mobile/lib/screens/buyer/address_form_screen.dart`
- `mobile/lib/screens/buyer/return_screen.dart`
- `mobile/lib/state/return_state.dart`
- `mobile/lib/utils/validators.dart`

New or extended tests:

- `mobile/test/address_book_test.dart`
- `mobile/test/checkout_validation_test.dart`
- `mobile/test/courier_failure_flow_test.dart`
- `mobile/test/order_lifecycle_test.dart`
- `mobile/test/pickup_verification_test.dart`
- `mobile/test/pricing_rules_test.dart`
- `mobile/test/product_variants_test.dart`
- `mobile/test/quantity_limit_test.dart`
- `mobile/test/returns_test.dart`
- `mobile/test/review_limits_test.dart`
- `mobile/test/store_follow_test.dart`
- `mobile/test/validators_test.dart`
- `mobile/test/registration_flow_test.dart`
- `mobile/test/search_filter_test.dart`
- `mobile/test/voucher_expiry_test.dart`

QA files:

- `qa/triage.csv`
- `qa/build_triage.py`
- `qa/implemented_fixes.diff`
- `qa/full_test_run.txt`
- `qa/failing_tests_all.txt`
- `qa/QA_REPORT.md`

---

## 10. Assumptions used

The workbook has no Assumptions sheet, so these were inferred from the test text and current app behavior:

- **A2** - person names are 2-50 characters, letters/spaces/hyphen/apostrophe/period.
- **A5** - password is 8+ with upper, lower, digit, special, no whitespace.
- **A6** - 18+ at registration; the 18th birthday counts.
- **A12** - the buyer is behind review after registration; mock sends registration to Under Review.
- **A13/A17/A18** - rider/courier account creation and activation belong to Logistics web, not the Flutter app. This is the basis for the 88 C cases in those two workbooks.
- **A10/M7/M8/M11** - buyer pricing/cart/rider rules were inferred from `config/pricing.dart`, `CartState` and the Courier/Rider screens.
- **Variant pricing/stock/image** - owned by the selected SKU for rich products; legacy one-dimensional products keep the product-level price/stock. The mock `p9` fixture is Color × Size, with Black/M out of stock and White/M in stock, so disabled-state and cart-separation behavior can be tested without a live seller API.

If product disagrees with any inference, the affected cases must be re-triaged before using the projection. The code should not be bent to a test plan that contradicts these assumptions.
