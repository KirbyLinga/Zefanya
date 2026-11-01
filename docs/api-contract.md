# Zefanya API contract

The contract between the Flutter app (`mobile/`) and the Laravel API (`web/`).

This document is derived from the repository, not from memory. It was written
after reading:

- `web-api` at `fceb34b`
- `web-api-master` at `4e2cb05`
- the working branch `wip/phases-1-4` at `20bc4d3` (worktree dirty)

`git diff --quiet web-api web-api-master -- web/routes/api.php web/app/Enums`
exits `0`: **the two reviewed branches carry an identical API surface and
identical status enums.** That surface is registration, sign-in, catalogue,
vouchers, `/me` and `/logout` only.

The working branch extends the same `web/routes/api.php` by 130 lines with
orders, courier jobs, reviews, chat, promotions and account security. The app
already calls those routes, so this contract documents the union and marks
which endpoints exist where. See §12 for the coverage matrix.

The Flutter side of this contract lives in:

- `mobile/lib/data/api/api_client.dart` — transport, auth header, error typing
- `mobile/lib/data/repositories/*.dart` — one live implementation per surface
- `mobile/lib/data/dto/*.dart` — server shape → app model

---

## §0 Open questions

These are decisions the two sides do not currently agree on. They are listed
first because several of them are money bugs waiting to be written, not
refactors.

### §0.1 Shipping is charged in the app and not on the server

The app charges ₱60 shipping (free at ₱1,000) in `mobile/lib/config/pricing.dart`.
Laravel's order total is `subtotal - discount` with no shipping term. Nothing
in the current API carries a shipping fee, and `POST /orders` does not accept
one. Until this is settled, the app's displayed total and the server's recorded
total can differ.

### §0.2 Vouchers are not the same model

The app supports fixed, percentage, shipping-rebate, category-scoped and
max-discount-capped vouchers. Laravel supports **fixed** and **percent only**,
with a minimum spend and no cap, scope or shipping term.

Consequences already enforced in code:

- `mobile/lib/data/dto/remote_catalog.dart` maps a server `percent` voucher and
  **drops** a `fixed` one, because the app's pricing engine would otherwise
  read a fixed discount as a shipping rebate.
- `POST /orders` accepts `voucher_code`, but `mobile/lib/data/repositories/order_repository.dart`
  does not send one yet. The server computes the discount; the app would have
  to stop computing its own to avoid showing a different number.
- Checkout now shows the vouchers already applied to the cart and can add more
  from the wallet (§4.2). That list is priced by `CartState`/`quoteCart`, which
  supports stacking; the server accepts exactly one `voucher_code`. In live
  mode the picker can therefore display a stack the server will not receive.
  This is documented rather than half-wired: sending only the first code would
  make the visible total and the placed order disagree even more.

`POST /checkout/quote` (specified in §4.3) is the intended endpoint that takes
the total calculation over once this is answered.

### §0.3 Category list size differs by design

The mock app shows four chips (`All`, `Fashion`, `Home & Living`, `Beauty`).
The server returns fourteen curated rows. Live mode therefore shows fourteen
chips. This is the answer to the question, not a bug: the app prepends its own
`All` sentinel and renders the server's real list.

### §0.4 Two money scales on the wire

Money fields named `*_minor` are **integer centavos**. Two fields are **pesos as
a double**: `DeliveryJob.fee` and `DeliveryJob.cod_amount`. Both are documented
at their endpoint and mapped explicitly. Do not add another peso-scale field.

### §0.5 The app simplifies thirteen statuses into four

Deliberate. The server sends its raw pipeline status; the buyer's screens map
it to four display stages and the courier's screens map it to ten milestones
(§11). A new server status must not crash either mapping — unknown values fail
safe.

### §0.6 Rejected registration has no screen

`registration_status: "rejected"` maps to `AccountStatus.loggedOut`, because
the app has no rejected-application screen. The server's message is lost. If a
screen is ever wanted, this is the place to add it.

### §0.7 `arrivedAtSeller` has no server state

The courier's "I am at the shop" milestone has no counterpart among the
thirteen server statuses. `READY_FOR_PICKUP` describes the parcel, not the
rider's position. Nothing maps to `DeliveryMilestone.arrivedAtSeller`; the
milestone is currently reachable only locally.

### §0.8 Live endpoints the app calls that do not exist

See §12.3. Google sign-in, courier registration, buyer-side order state
(confirm received / cancel) and returns are not implemented server-side.

### §0.9 A review photo is a local device path

`PATCH .../review` accepts `photo_path` as a string and stores it as-is. The app
sends its own filesystem path. That path is meaningless on another device and
is not a URL. A real upload endpoint is required before review photos can be
shown across devices.

### §0.10 Chat identity is a display name

`{contact}` is a seller's business name or a courier's full name, URL-encoded.
There is no conversation id and no thread-list endpoint. Renaming a seller
changes the thread key. §9.3 records the intended stable-id shape.

---

## §1 Conventions

### §1.1 Base URL and mode

The Laravel routes are mounted under `/api` by `bootstrap/app.php`. The app
expects the base URL to include that prefix:

```
--dart-define=zefanya.useLiveApi=true
--dart-define=zefanya.apiBaseUrl=https://api.example.test/api
```

`mobile/lib/config/dev_flags.dart` holds both flags. Mock mode is the default
and is unaffected by this document.

### §1.2 Request headers

```
Accept: application/json
Content-Type: application/json        # except multipart registration
Authorization: Bearer <plainTextToken> # authenticated routes only
```

Tokens are Sanctum personal access tokens. Registration and the catalogue are
public and must not attach a token.

### §1.3 Response envelope

Collection and single-resource endpoints built on Laravel API Resources wrap
their payload:

```json
{ "data": [ ... ] }
```

Newer hand-written endpoints (`/login/*`, `/me`, `/orders/{id}/milestone`,
courier milestone PATCH, account security, chat POST) return a bare object.
`ApiClient.unwrapMap` / `unwrapList` tolerate both, so a change of envelope in
either direction does not break parsing. New endpoints should prefer `data`.

### §1.4 Identifiers

Every id that crosses the boundary is a **string**, including ids stored as
integers. The app keys its sets and maps on strings (`'p1'`, `'ORD-10246'`), and
mixing types would force every membership test to know the mode.

The route parameter `{sellerOrder}` is the **seller order id**, not the parent
checkout id. The app models one parcel per shop, so a seller order is what it
calls an order.

### §1.5 Authentication and roles

One path per role, because one guard is one provider:

| Role | Login | Identity | Logout |
|---|---|---|---|
| Buyer | `POST /login/buyer` | `GET /me` | `POST /logout` |
| Courier | `POST /login/courier` | `GET /me/courier` | `POST /logout/courier` |

The buyer token belongs to `buyers`; the courier token belongs to
`logistics_providers`. Calling the other role's endpoint answers `403`, not
`401`, because the token is valid but belongs to the wrong account type.

### §1.6 Throttles

Each route has its own bucket. Limits are per minute unless stated.

| Route group | Limit |
|---|---|
| Registration | 5 |
| OTP verify | 10 |
| OTP resend | 3 per 5 minutes |
| Buyer/courier login | 5 each |
| Catalogue, vouchers, promotions, categories | 60 each |
| Orders list / order milestone | 60 each |
| Order placement | 10 |
| Courier job list | 60 |
| Courier milestone write | 30 |
| Reviews list / upsert | 60 / 10 |
| Chat read / send | 60 / 20 |
| Account security requests / verifies | 5 / 10 |

`429` is Laravel's `{"message": "Too Many Attempts."}`. `ApiClient` maps it to
`RequestRejectedException`.

### §1.7 Route index

Public:

| Method | Path | Since |
|---|---|---|
| POST | `/register/buyer` | `web-api` |
| POST | `/register/buyer/verify-otp/{buyer}` | `web-api` |
| POST | `/register/buyer/verify-otp/{buyer}/resend` | `web-api` |
| POST | `/login/buyer` | `web-api` |
| POST | `/login/courier` | `wip` |
| GET | `/products` | `web-api` |
| GET | `/products/{product}` | `web-api` |
| GET | `/categories` | `web-api` |
| GET | `/vouchers` | `web-api` |
| GET | `/promotions` | `wip` |

Authenticated:

| Method | Path | Since |
|---|---|---|
| GET | `/me` | `web-api` |
| POST | `/logout` | `web-api` |
| GET | `/me/courier` | `wip` |
| POST | `/logout/courier` | `wip` |
| POST | `/account/password` | `wip` |
| POST | `/account/email` | `wip` |
| POST | `/account/email/verify` | `wip` |
| POST | `/account/phone` | `wip` |
| POST | `/account/phone/verify` | `wip` |
| GET | `/orders` | `wip` |
| POST | `/orders` | `wip` |
| GET | `/orders/{sellerOrder}/milestone` | `wip` |
| GET | `/courier/jobs` | `wip` |
| PATCH | `/courier/jobs/{sellerOrder}/milestone` | `wip` |
| GET | `/reviews/mine` | `wip` |
| PATCH | `/orders/{sellerOrder}/items/{product}/review` | `wip` |
| GET | `/chat/{contact}` | `wip` |
| POST | `/chat/{contact}` | `wip` |

`wip` means the working branch only; neither `web-api` nor `web-api-master`
contains that route. See §12.

---

## §2 Errors and envelopes

### §2.1 Validation — `422`

Laravel's standard shape:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email has already been taken."]
  }
}
```

`ApiClient` flattens each field to its first message. Screens render one line
per input.

### §2.2 Business refusals — `422`

An operation the server understands but will not perform returns `message`,
usually without an `errors` bag:

```json
{ "message": "That delivery code is not correct." }
```

Some endpoints add a machine-readable key:

| Key | Endpoint | Meaning |
|---|---|---|
| `expired: true` | OTP confirmations | Code was right once, now expired |
| `voucher_rejected: "<code>"` | `POST /orders` | The voucher no longer applies |
| `ok: false` | OTP/auth routes | Explicit failure flag |

### §2.3 Account gates — `403`

Sign-in and `/me` return one vocabulary for "credentials fine, account not
usable". `code` is the required discriminator; `registration_status` follows
`LogisticsProviderStatus` / buyer status spelling.

```json
{
  "ok": false,
  "code": "PENDING_VERIFICATION",
  "registration_status": "pending_verification",
  "verification_id": "42",
  "message": "Confirm your email address to continue."
}
```

| `code` | `registration_status` | App routes to |
|---|---|---|
| `PENDING_VERIFICATION` | `pending_verification` | OTP screen, using `verification_id` |
| `PENDING_APPROVAL` | `pending_approval` | Pending-approval screen |
| `ACCOUNT_REJECTED` | `rejected` | Login (§0.6) |

`ApiClient` accepts `code`, `error_code` or a `registration_status` beginning
with `pending` as the pending signal; `code` is the contract.

A `403` for ownership or wrong role carries only `{"message": "..."}`.

### §2.4 Other statuses

| Status | Body | App exception |
|---|---|---|
| `401` | `{"message": "Unauthenticated."}` | `UnauthorizedException` |
| `403` | gate or message above | `PendingApprovalException` / `ForbiddenException` |
| `404` | `{"message": "..."}` | `NotFoundException` |
| `423` | `{"message": "Too many wrong delivery codes. Try again in N minutes."}` | `RequestRejectedException` |
| `429` | `{"message": "Too Many Attempts."}` | `RequestRejectedException` |
| `5xx` | `{"message": "..."}` | `ServerException` |

`423` has no dedicated exception type today. The courier's milestone call is
fire-and-forget, so the lockout copy currently comes from local state, not this
body.

---

## §3 Auth and registration

### §3.1 `POST /register/buyer`

Public. `multipart/form-data`, because `upload_id` is a file part.

| Field | Rules |
|---|---|
| `last_name` | required, string, ≤100 |
| `first_name` | required, string, ≤100 |
| `middle_initial` | nullable, string, ≤2 |
| `sex` | required, `male` or `female` |
| `email` | required, email, ≤255, unique |
| `password` | required, ≥8, `password_confirmation` must match |
| `contact_no` | required, `^09\d{9}$` |
| `birthday` | required, date, before today |
| `address_mode` | required, `api` or `manual` |
| `province`, `province_name`, `municipality`, `municipality_name`, `barangay`, `barangay_name` | required when `address_mode=api` |
| `street` | required, ≤255 |
| `house_number` | nullable, ≤50 |
| `address_detail` | nullable, ≤255 |
| `upload_id` | required file, jpg/jpeg/png/pdf, ≤5120 KB |

`201`:

```json
{
  "ok": true,
  "verification_id": "42",
  "email": "buyer@example.test",
  "registration_status": "pending_verification"
}
```

`verification_id` is opaque. It is the buyer row id and the URL segment for the
OTP routes.

### §3.2 OTP confirmation

`POST /register/buyer/verify-otp/{buyer}` — body `{"otp": "123456"}`, exactly
six digits.

`200`:

```json
{
  "ok": true,
  "registration_status": "pending_approval",
  "message": "Email confirmed. Your application is now with our team."
}
```

Already verified answers `200` with `already_verified: true`. Wrong code and
expired code both answer `422` with `ok: false` and `message`; expired adds
`expired: true`.

`POST /register/buyer/verify-otp/{buyer}/resend` — no body. `200` with
`{"ok": true, "message": "A new code has been sent."}`. Already verified is
`422`.

Codes are 6 digits, hashed at rest, expire in 10 minutes, and are rate-limited
as in §1.6.

### §3.3 `POST /login/{role}` and `POST /logout/{role}`

Login body:

```json
{ "email": "buyer@example.test", "password": "secret" }
```

`200`:

```json
{
  "ok": true,
  "token": "1|plainTextSanctumToken",
  "role": "buyer",
  "name": "Marielle Santos",
  "email": "buyer@example.test",
  "registration_status": "approved"
}
```

A missing token on a `200` is a malformed response, not a failed login.

Wrong credentials are `422` on the `email` field with the same sentence for
"no account" and "wrong password", so the response cannot be used to enumerate
registered addresses.

Logout revokes **only the current token**. `200`:

```json
{ "ok": true, "message": "Signed out." }
```

### §3.4 `GET /me` and session restore

`200` with the same object as login, minus `token`. A cold start calls this with
the stored token:

- `200` → restore the session
- `401` → forget the token and show Login
- `403` → keep the token, route to the named gate

---

## §4 Vouchers

### §4.1 `GET /vouchers`

Public. Only active, unexpired vouchers under their usage limit are listed.
Minimum spend is not filtered here because a catalogue read has no subtotal.

```json
{
  "data": [
    {
      "code": "NEWHOME15",
      "type": "percent",
      "value": 15,
      "min_spend_minor": 150000,
      "expires_at": "2026-12-31T23:59:59+00:00"
    }
  ]
}
```

| Field | Scale |
|---|---|
| `type` | `fixed` or `percent` |
| `value` | centavos when `fixed`; whole percent (0–100) when `percent` |
| `min_spend_minor` | centavos, nullable |
| `expires_at` | ISO 8601, nullable |

The app's mapper drops `fixed` vouchers (§0.2). A voucher the app cannot
represent is better absent from its list than applied with the wrong rule.

### §4.2 Applying a voucher

There is no `POST /cart/voucher` on the API. A voucher is applied at order
placement by sending `voucher_code` with `POST /orders` (§6.2). A code that no
longer applies does **not** silently disappear: the order is refused `422`
with `voucher_rejected`, so a phone showing "₱200 off" cannot place the order
at full price.

The app's wallet (`VouchersScreen`) is the claim/eligibility surface. Checkout
reuses it for discovery: "Browse" / "Add" opens the wallet, the buyer taps
"Use" on a claimed voucher, and the returned code goes through
`CartState.applyVoucher` — the same gate Cart uses for stacking, minimum spend
and expiry. Checkout then lists each applied code with its quoted discount and
a remove action. Buy Now uses checkout lines that are not in the cart, so it
omits the picker rather than showing a discount those lines do not qualify for.

This is a local pricing UI, not a new wire call; the server-side single-voucher
gap is described in §0.2.

### §4.3 `POST /checkout/quote` — not built

The intended endpoint once §0.1 and §0.2 are settled. It should accept the
cart lines plus a voucher code and return the authoritative subtotal, discount,
shipping and total in centavos, so the app stops computing totals locally.
Nothing implements it today.

---

## §5 Catalogue

All catalogue routes are public and read-only.

### §5.1 `GET /products`

Query parameters, all optional:

| Parameter | Meaning |
|---|---|
| `q` | case-insensitive LIKE on product name and category label |
| `category` | exact category **label** (not id) |
| `seller` | exact seller business name |

Only `status = active` products from approved sellers are returned. Sold-out
products are included; `in_stock` says whether they are buyable. There is no
pagination — the response is the catalogue.

```json
{
  "data": [
    {
      "id": "7",
      "name": "Handwoven Rattan Tote",
      "description": "…",
      "category": "Home & Living",
      "price_minor": 129900,
      "in_stock": true,
      "seller": {
        "name": "Rattan & Reed Co.",
        "maker_note": "Woven by hand in Laguna."
      },
      "images": ["https://…/cover.jpg", "https://…/detail.jpg"],
      "rating_count": 12,
      "rating_avg": 4.6
    }
  ]
}
```

`images` is ordered cover-first. `rating_count` / `rating_avg` are computed
from real reviews and are `0` / `0.0` when there are none; the app hides the
rating row rather than printing zero. `maker_note` is nullable and the app
hides the section when absent.

The app's `compareAtPrice` ("ON SALE") is not sent: there is no
`compare_at_price` column. It must not be invented client-side.

### §5.2 `GET /products/{product}`

`200` with `{"data": <product>}`. `404` if the product is not active, the
seller is not approved, or the id does not exist. A delisted product is a
`404`, not a `403`.

### §5.3 `GET /categories`

```json
{ "data": [{ "id": "1", "label": "Fashion" }] }
```

Ordered by id, which is the curation order. `label` is what the app renders and
what it sends back as the `category` filter.

### §5.4 `GET /promotions`

Public. Active slides in display order.

```json
{
  "data": [
    {
      "eyebrow": "THE MAKER'S EDIT",
      "title": "Woven slowly,\nworn daily",
      "subtitle": "…",
      "layout": "editorial",
      "ground": "linen",
      "icon": "local_florist_outlined",
      "big_icon": "spa_outlined"
    }
  ]
}
```

`icon` / `big_icon` are Flutter icon names resolved by a lookup table; unknown
names fall back to a neutral icon.

---

## §6 Orders

### §6.1 `GET /orders`

Buyer token. Returns the buyer's **seller orders** (one parcel per shop),
newest checkout first, ties broken by seller order id.

```json
{
  "data": [
    {
      "id": "31",
      "reference": "ORD-20261029-ABC123",
      "status": "OUT_FOR_DELIVERY",
      "shop": "Willow & Bloom Atelier",
      "subtotal_minor": 259800,
      "items": [
        {
          "name": "Linen Blend Wrap Dress",
          "quantity": 2,
          "price_minor": 129900,
          "product_id": "7"
        }
      ],
      "product_ids": ["7"]
    }
  ]
}
```

`status` is the raw server status (§11.1); the app maps it to four display
stages. A line whose product was deleted keeps its snapshot and sends
`product_id: null`; `product_ids` contains only ids that still resolve.

The app derives `Order.eta` and `Order.itemSummary` from the status and items.
They are not wire fields.

### §6.2 `POST /orders`

Buyer token. Places the phone's basket. The request chooses **what** and **how
many**, never **how much**: price, stock, discount split and shipping snapshot
are computed on the server from locked rows.

```json
{
  "items": [
    { "product_id": 7, "quantity": 2 }
  ],
  "voucher_code": "NEWHOME15"
}
```

`product_id` is an integer here, not a string — it is a database key, and the
server validates `integer|min:1`. `voucher_code` is optional.

`201`:

```json
{
  "data": [ <seller order, same shape as §6.1> ],
  "meta": {
    "order_id": "18",
    "reference": "ORD-20261029-ABC123",
    "subtotal_minor": 259800,
    "discount_minor": 38970,
    "total_minor": 220830
  }
}
```

`422` means "the basket is no longer placeable": a line sold out, went
inactive, vanished, or the voucher stopped applying. Nothing is written and the
transaction rolls back. The message is meant to be shown.

The route is **not idempotent**. Two taps that both reach the server are two
orders. The app disables the button while the request is in flight.

> Current app gap: `order_repository.dart` sends only `items`, so the live
> checkout cannot apply a voucher yet (§0.2).

### §6.3 `GET /orders/{sellerOrder}/milestone`

Buyer token. Lightweight poll target for the tracker screen. Ownership is
checked against the parent order's buyer.

```json
{ "milestone": "OUT_FOR_DELIVERY" }
```

Bare object, not wrapped in `data`. The value is the raw server status so a
future tracker can show finer progress.

### §6.4 `GET /orders/{sellerOrder}/tracking` — proposed, not built

Buyer token. Would feed the tracker's route map. Neither API branch implements
it; the live `TrackingRepository` deliberately does not call it, because a
request/parser against a route nobody serves cannot be verified. In live mode
`TrackingRepository.snapshotFor` therefore returns null and the tracker omits
the map; only the milestone poll above is live.

Proposed shape, matching `TrackingSnapshot`:

```json
{
  "data": {
    "pickup":  { "latitude": 14.2346, "longitude": 121.3649 },
    "dropoff": { "latitude": 14.2118, "longitude": 121.3926 },
    "courier": { "latitude": 14.2201, "longitude": 121.3812 },
    "remaining_distance_meters": 1640.0,
    "eta_minutes": 7,
    "updated_at": "2026-10-31T08:15:00+00:00"
  }
}
```

An implementation must decide how coordinates are produced (device GPS,
geocoded addresses, or a routing provider); the app only renders what it is
sent. Coordinates are decimal degrees, distance is metres, and `eta_minutes` is
a whole number. Returning `404` before a job exists is acceptable — the app
already hides the map when it has no snapshot.

---

## §7 Courier

### §7.1 `GET /courier/jobs`

Courier token. Returns the parcels assigned to the signed-in courier that are
still active. Terminal statuses (`DELIVERED`, `COMPLETED`, `RETURNED`) are
excluded — this is the current workload, not history.

```json
{
  "data": [
    {
      "id": "31",
      "order_id": "18",
      "shop_name": "Willow & Bloom Atelier",
      "pickup_address": "12 Mabini Street, Lahug, Cebu City, Cebu",
      "buyer_name": "Marielles Santos",
      "delivery_area": "Quezon City (8.4 km)",
      "package_size": "2 items · ₱2,598.00",
      "fee": 85.0,
      "tracking_number": "ZEF-5521-01",
      "payment_method": "cod",
      "payment_status": "pending",
      "cod_amount": 2598.0,
      "delivery_otp_required": true,
      "delivery_attempts": 0,
      "failure_reason": null
    }
  ]
}
```

| Field | Notes |
|---|---|
| `id` | seller order id — the route key for milestone writes |
| `order_id` | parent checkout id, used to join the buyer's order |
| `fee` | **pesos**, not centavos |
| `cod_amount` | **pesos**, `0.0` when prepaid or already paid |
| `delivery_otp_required` | boolean; the code itself is never sent |
| `delivery_attempts` | server's count, 0–3 |
| `failure_reason` | last recorded reason, nullable |

**The delivery code is deliberately absent.** The server stores only its hash.
The app's `deliveryJobFromJson` therefore leaves `DeliveryJob.deliveryOtp`
empty in live mode, which is why the buyer's delivery-pass card is hidden in
live mode (see `docs/KNOWN_LIMITATIONS.md`). The handover still works: the
buyer reads the code aloud, or the courier types it, and the server verifies.

`courier_name` and `courier_photo_path` are not on the wire either. The rider
identity is currently set locally when the rider accepts a job.

### §7.2 `PATCH /courier/jobs/{sellerOrder}/milestone`

Courier token. The job must be assigned to the token holder; otherwise `403`.

Body:

```json
{
  "milestone": "DELIVERED",
  "tracking_number": "ZEF-5521-01",
  "failure_reason": "Buyer not home",
  "delivery_otp": "482913",
  "cod_amount": 2598.00
}
```

Only `milestone` is required. The rest are checks performed when the parcel
carries the data to check.

| Target | Required checks |
|---|---|
| `PICKED_UP` | `tracking_number` must match the issued waybill when one exists. Blank or wrong → `422`. A parcel with no issued number is allowed through. |
| `DELIVERY_FAILED` | `failure_reason` required, ≤80. Increments `delivery_attempts`. The third failure is stored as `RETURNED`. |
| `RETURNED` | `failure_reason` required. Accepted at the third attempt, or immediately when the reason contains `Buyer refused`. Any other early return → `422`. |
| `DELIVERED` | If the job has an OTP hash: `delivery_otp` must be exactly six digits and match. 5 wrong codes → `423` for 15 minutes. If COD payment is still pending: `cod_amount` must equal the amount owed to the centavo, which marks the payment paid. |

Allowed transitions (server-enforced):

```
PLACED            -> CONFIRMED
CONFIRMED         -> PREPARING
PREPARING         -> READY_FOR_PICKUP
READY_FOR_PICKUP  -> PICKED_UP
PICKED_UP         -> AT_SORTING_CENTER
AT_SORTING_CENTER -> SORTED
SORTED            -> ASSIGNED_TO_RIDER
ASSIGNED_TO_RIDER -> OUT_FOR_DELIVERY
OUT_FOR_DELIVERY  -> DELIVERED | DELIVERY_FAILED | RETURNED
DELIVERY_FAILED   -> OUT_FOR_DELIVERY | DELIVERED | RETURNED
DELIVERED, COMPLETED, RETURNED = terminal
```

`200`:

```json
{
  "ok": true,
  "milestone": "DELIVERED",
  "delivery_attempts": 1,
  "payment_status": "paid"
}
```

`422` with `{"message": "..."}` for an invalid transition, terminal job, wrong
waybill, missing reason, wrong OTP or wrong cash. `423` for the OTP lockout.

> Client risk: `CourierState.advanceMilestone` updates local state first and
> sends the repository call fire-and-forget. A `422`/`423` from this route does
> not roll the courier's screen back. The local state machine mirrors the
> server rules (3 attempts, buyer-refusal shortcut, OTP lockout), but they are
> two implementations and must be kept in step.

---

## §8 Reviews

### §8.1 `GET /reviews/mine`

Buyer token. Every review this buyer wrote, newest first.

```json
{
  "data": [
    {
      "id": "5",
      "order_id": "31",
      "product_id": "7",
      "stars": 5,
      "comment": "Beautiful weave.",
      "photo_url": "https://…/review.jpg",
      "created_at": "2026-10-29T08:12:00+00:00"
    }
  ]
}
```

`order_id` is the seller order id. `product_id` and `photo_url` are nullable
when the product or photo is gone.

### §8.2 `PATCH /orders/{sellerOrder}/items/{product}/review`

Buyer token. Upsert: `201` for a new review, `200` for an edit. The app treats
both as success.

```json
{
  "stars": 5,
  "comment": "Beautiful weave.",
  "photo_path": "C:/…/review.jpg"
}
```

| Field | Rules |
|---|---|
| `stars` | integer 1–5 |
| `comment` | nullable, ≤1000 |
| `photo_path` | nullable, ≤500 |

The seller order must belong to the buyer (`403`), and the product must be a
line in that order (`422`). `photo_path` is stored verbatim; see §0.9.

---

## §9 Chat

### §9.1 `GET /chat/{contact}`

Buyer token. `{contact}` is a URL-encoded display name. Returns up to the last
100 messages, oldest first, for the buyer ↔ contact pair.

```json
{
  "data": [
    {
      "id": "91",
      "body": "Is the parcel nearby?",
      "is_me": true,
      "sent_at": "2026-10-29T08:12:00+00:00"
    }
  ]
}
```

`is_me` is resolved from the requesting token, so the same row is `true` for
the sender and `false` for the recipient. An unknown contact is `404`; a
non-buyer token is `403`.

### §9.2 `POST /chat/{contact}`

Buyer token. Body `{"body": "..."}`, 1–2000 characters. `201` with
`{"data": <message>}`. Same `403` / `404` rules.

There is no thread-list endpoint. `LiveChatRepository.threads()` returns an
empty map; threads are cached lazily when opened.

### §9.3 Intended stable-id design — not built

Once conversations have ids, the reads become
`GET /conversations` and `GET /conversations/{id}/messages`, and sends become
`POST /conversations/{id}/messages`. The display-name segment is a stand-in,
not a contract to preserve.

---

## §10 Account security

All buyer-authenticated.

| Endpoint | Body | `200` |
|---|---|---|
| `POST /account/password` | `current_password`, `password`, `password_confirmation` | `{ok, message}` |
| `POST /account/email` | `email` | `{ok, message}` |
| `POST /account/email/verify` | `otp` (6 digits) | `{ok, email, message}` |
| `POST /account/phone` | `contact_no` (`09XXXXXXXXX`) | `{ok, message}` |
| `POST /account/phone/verify` | `otp` (6 digits) | `{ok, contact_no, message}` |

Password rules: ≥8 characters, at least one lowercase, one uppercase, one
digit, one symbol, no whitespace, confirmed. The current password is required.
Every other token is revoked; the requesting token stays valid.

An email or phone change stores the new value as pending, sends a code to the
new contact, and only applies it after confirmation. Wrong or expired codes are
`422 {ok: false, message}`; expired adds `expired: true`. A value taken by
another account is `422`.

There is no SMS provider in the codebase yet; the phone code is written to the
server log for the operator. The hash and 10-minute expiry are already the
production shape.

---

## §11 Status vocabulary

### §11.1 `OrderStatus` (13 values)

Exactly as `web/app/Enums/OrderStatus.php`:

```
PLACED, CONFIRMED, PREPARING, READY_FOR_PICKUP, PICKED_UP,
AT_SORTING_CENTER, SORTED, ASSIGNED_TO_RIDER, OUT_FOR_DELIVERY,
DELIVERED, COMPLETED, DELIVERY_FAILED, RETURNED
```

### §11.2 Payment

| Enum | Values |
|---|---|
| `PaymentMethod` | `cod` (only) |
| `PaymentStatus` | `pending`, `paid` |

COD is settled at the door by the milestone endpoint. A prepaid order never
asks for cash; `cod_amount` is `0.0`.

### §11.3 Registration and logistics

`RegistrationStatus` / `LogisticsProviderStatus`:

```
pending_verification, pending_approval, approved, rejected
```

Two gates, in order: the email OTP the person can act on, then the admin
review they cannot. The app must not collapse them in copy.

### §11.4 Server → buyer → courier mapping

| Server status | Buyer (`OrderStatus`) | Courier (`DeliveryMilestone`) |
|---|---|---|
| `PLACED` | `toShip` | — |
| `CONFIRMED` | `toShip` | — |
| `PREPARING` | `toShip` | — |
| `READY_FOR_PICKUP` | `toShip` | `accepted` |
| `PICKED_UP` | `inTransit` | `pickedUp` |
| `AT_SORTING_CENTER` | `inTransit` | `atSortingCenter` |
| `SORTED` | `inTransit` | `sorted` |
| `ASSIGNED_TO_RIDER` | `outForDelivery` | `assignedToRider` |
| `OUT_FOR_DELIVERY` | `outForDelivery` | `inTransit` |
| `DELIVERED` | `toRate` | `delivered` |
| `COMPLETED` | `toRate` | `delivered` |
| `DELIVERY_FAILED` | `outForDelivery` | `failedDelivery` |
| `RETURNED` | `inTransit` | `returnToSender` |
| unknown | `toShip` | — (null) |

Rules behind the surprising cells:

- `DELIVERED` and `COMPLETED` are both "in the buyer's hands" to a buyer; the
  distinction matters to the seller's payout.
- `DELIVERY_FAILED` stays `outForDelivery` because a retry is coming;
  `RETURNED` is `inTransit` because it is moving away.
- `arrivedAtSeller` has no server state (§0.7).
- Unknown fails safe: buyer sees the earliest stage; courier sees no job.

Parsing accepts the enum's SHOUTY_SNAKE value, the Dart-style case name and
stray whitespace. It never throws.

---

## §12 Branch coverage and not-built surface

### §12.1 What the two API branches contain

`web-api` and `web-api-master` are identical for this purpose:

- registration + OTP (buyer)
- buyer login
- `/me`, `/logout`
- products, categories, vouchers

### §12.2 What only the working branch contains

- courier login, `/me/courier`, `/logout/courier`
- promotions
- account security (password, email, phone)
- orders (list, place, milestone read)
- courier jobs and milestone write
- reviews
- chat

### §12.3 Called by the app, implemented nowhere

| Endpoint | Caller | Consequence |
|---|---|---|
| `POST /auth/google` | `LiveGoogleAuthRepository` | Google sign-in cannot work in live mode |
| `POST /register/logistics` | `LiveAuthRepository.register` for courier | Courier sign-up is `notWired` |
| `POST /register/logistics/verify-otp/{id}` (+ `/resend`) | `LiveAuthRepository` | Courier OTP is `notWired` |
| `PATCH /orders/{sellerOrder}/buyer-state` | `LiveOrderRepository.saveBuyerState` | "Order received" and cancellation are local-only |
| `GET /returns` | `LiveReturnRepository` | Returns list is `notWired` |
| `POST /orders/{sellerOrder}/returns` | `LiveReturnRepository` | Return submission is `notWired` |
| `POST /returns/{return}/escalate` | `LiveReturnRepository` | Escalation is `notWired` |
| `POST /checkout/quote` | none yet | §4.3 design only |
| `GET /orders/{sellerOrder}/tracking` | none yet | §6.4 design only; live tracker omits the map |

`notWired` throws `ApiNotImplementedException` naming the endpoint. A live
build is runnable and honest; those surfaces are not functional. The tracking
route is not even called: the app knows it does not exist, so it shows the
milestone timeline without a map instead of requesting a 404 every 30 s.

### §12.4 Wire fields the app models but the server does not send

| App field | Status |
|---|---|
| `Order.cancelled`, `Order.buyerConfirmed` | local flags only; no buyer-state endpoint |
| `DeliveryJob.deliveryOtp` | never sent; server keeps only the hash |
| `DeliveryJob.courierName`, `courierPhotoPath` | set locally at pickup |
| `Product.compareAtPrice` | no column; must not be faked |
| `Voucher.maxDiscount`, category scope, shipping rebate | unsupported by the server model |
| `Review.photo` | `photo_path` is a local path, not an uploaded URL |

### §12.5 Operational notes

- `GET /products`, `GET /orders` and `GET /chat/{contact}` are unpaginated
  (chat is capped at 100 messages). The app's repository interfaces have no
  cursor, so a first `page` parameter would silently truncate.
- `POST /orders` has no idempotency key. Client-side button disable is the
  only guard.
- Local artwork resolves relative `/storage/...` URLs; `Image.network` needs an
  absolute URL. Uploaded product photos therefore need an absolute
  `APP_URL`/storage URL in live builds.
- `delivery_otp` is capped at 6 characters by validation, and the server
  additionally requires exactly `\d{6}` before hashing.

---

## §13 Merge readiness: repository methods, mappers, mode and secrets

This section is the audit the merge needs. It answers four questions in one
place: what every repository method calls, where every wire field is translated,
how the mock/live decision is made, and whether any credential is committed.

### §13.1 Every repository method and its route

`—` means the method has no HTTP route: it restores device-local state, is a
capability flag, or is deliberately unimplemented. A route marked **not built**
is in neither `web-api`, `web-api-master`, nor the working branch.

| Repository method | Route | Auth | Request | Response → app |
|---|---|---|---|---|
| `AuthRepository.login` | `POST /login/buyer` or `/login/courier` | public | `{email, password}` | `token`, `role`, `registration_status` → `AuthResult` |
| `AuthRepository.register` (buyer) | `POST /register/buyer` | public | multipart: profile fields + `upload_id` | `verification_id`, `registration_status` → `AuthResult` |
| `AuthRepository.register` (courier) | `POST /register/logistics` **not built** | public | would be the logistics registration shape | `notWired` |
| `AuthRepository.verifyOtp` | `POST /register/buyer/verify-otp/{id}` | public | `{otp}` | `registration_status` → `AuthResult` |
| `AuthRepository.verifyOtp` (courier) | `POST /register/logistics/verify-otp/{id}` **not built** | public | `{otp}` | `notWired` |
| `AuthRepository.resendOtp` | `POST /register/buyer/verify-otp/{id}/resend` | public | none | `void` |
| `AuthRepository.resendOtp` (courier) | `POST /register/logistics/verify-otp/{id}/resend` **not built** | public | none | `notWired` |
| `AuthRepository.logout` | `POST /logout` or `/logout/courier` | bearer | none | `void`; token forgotten locally in `finally` |
| `AuthRepository.refresh` | `GET /me` or `/me/courier` | bearer | none | same body as login → `AuthResult?`; `401` → null |
| `AccountSecurityRepository.changePassword` | `POST /account/password` | bearer buyer | `{current_password, password, password_confirmation}` | `void` |
| `AccountSecurityRepository.requestEmailChange` | `POST /account/email` | bearer buyer | `{email}` | `void` |
| `AccountSecurityRepository.confirmEmailChange` | `POST /account/email/verify` | bearer buyer | `{otp}` | `{email}` → `String` |
| `AccountSecurityRepository.requestPhoneChange` | `POST /account/phone` | bearer buyer | `{contact_no}` | `void` |
| `AccountSecurityRepository.confirmPhoneChange` | `POST /account/phone/verify` | bearer buyer | `{otp}` | `{contact_no}` → `String` |
| `GoogleAuthRepository.signIn` | `POST /auth/google` **not built** | public | `{id_token}` | would be `GoogleAuthOutcome` |
| `GoogleAuthRepository.register` | `POST /auth/google` **not built** | public | multipart: `id_token`, `role`, profile fields | would be `AuthResult` |
| `CatalogRepository.products` | `GET /products` | public | optional `q`, `category`, `seller` | `data[]` → `Product` |
| `CatalogRepository.productById` | `GET /products/{id}` | public | path id | `data` → `Product?`; `404` → null |
| `CatalogRepository.categories` | `GET /categories` | public | none | `data[].label`; app prepends `All` |
| `CatalogRepository.productsInCategory` | `GET /products?category={label}` | public | exact label | `data[]` → `Product` |
| `CatalogRepository.makerNote` | `GET /products?seller={name}` | public | exact seller name | first row's `seller.maker_note` → `String?` |
| `CatalogRepository.promoSlides` | `GET /promotions` | public | none | `data[]` → `PromoSlide` |
| `CartRepository.vouchers` | `GET /vouchers` | public | none | `data[]` → `Voucher`; `fixed` rows dropped |
| `OrderRepository.load` | — | — | restores local orders | live implementation is a no-op |
| `OrderRepository.orders` | `GET /orders` | bearer buyer | none | `data[]` → `Order` |
| `OrderRepository.place` | `POST /orders` | bearer buyer | `{items:[{product_id, quantity}], voucher_code?}` | `data[]` → `Order`; `meta` ignored |
| `OrderRepository.saveBuyerState` | `PATCH /orders/{sellerOrder}/buyer-state` **not built** | bearer buyer | would carry receipt/cancel state | `notWired` |
| `ReviewRepository.load` | — | — | restores local reviews | live implementation is a no-op |
| `ReviewRepository.all` | `GET /reviews/mine` | bearer buyer | none | `data[]` → `ProductReview` |
| `ReviewRepository.submit` | `PATCH /orders/{sellerOrder}/items/{productId}/review` | bearer buyer | `{stars, comment?, photo_path?}` | `void`; upsert `200`/`201` |
| `ChatRepository.supportsAttachments` | — | — | capability flag | live `false`, mock `true` |
| `ChatRepository.simulatesReplies` | — | — | capability flag | live `false`, mock `true` |
| `ChatRepository.load` | — | — | restores local threads | live implementation is a no-op |
| `ChatRepository.messagesFor` | `GET /chat/{contact}` | bearer buyer | URL-encoded display name | `data[]` → `ChatMessage` |
| `ChatRepository.threads` | — | — | no list-all route | live returns `{}`; threads fill lazily |
| `ChatRepository.append` | `POST /chat/{contact}` | bearer buyer | `{body}` | `void`; text only |
| `ChatRepository.seedIfEmpty` | — | — | no route | live no-op; the server already has the opener |
| `CourierRepository.assignedJobs` | `GET /courier/jobs` | bearer courier | none | `data[]` → `DeliveryJob` |
| `CourierRepository.advanceMilestone` | `PATCH /courier/jobs/{jobId}/milestone` | bearer courier | `{milestone, tracking_number?, failure_reason?, delivery_otp?, cod_amount?}` | `void`; `422`/`423` propagate |
| `TrackingRepository.poll` | `GET /orders/{sellerOrder}/milestone` | bearer buyer | none | `milestone` → `DeliveryMilestone?`; no coordinates |
| `TrackingRepository.snapshotFor` | — | — | mock derives a fixed demo route from the milestone | live returns null until §6.4 exists |
| `TrackingRepository.pollsServer` | — | — | capability flag | live `true`, mock `false` |
| `ReturnRepository.load` | — | — | restores local requests | live implementation is a no-op |
| `ReturnRepository.all` | `GET /returns` **not built** | bearer buyer | none | `notWired` |
| `ReturnRepository.submit` | `POST /orders/{sellerOrder}/returns` **not built** | bearer buyer | would carry description + photos | `notWired` |
| `ReturnRepository.escalate` | `POST /returns/{return}/escalate` **not built** | bearer buyer | none | `notWired` |

### §13.2 Mapper ownership

Every response object is translated in one place. A server field rename is
therefore a one-file change plus the test that pins it.

| Wire object | Mapper file / function | App model |
|---|---|---|
| Product, category, voucher, promotion | `mobile/lib/data/dto/remote_catalog.dart`: `productFromJson`, `categoryLabelFromJson`, `voucherFromJson`, `PromoSlide.fromJson` | `Product`, category label, `Voucher`, `PromoSlide` |
| Order / seller order | `mobile/lib/data/dto/remote_orders.dart`: `orderFromJson` | `Order` |
| Delivery job | `mobile/lib/data/dto/remote_courier.dart`: `deliveryJobFromJson` | `DeliveryJob` |
| Review | `mobile/lib/data/dto/remote_review.dart`: `reviewFromJson` | `ProductReview` |
| Chat message | `mobile/lib/data/dto/remote_chat.dart`: `chatMessageFromJson`, `chatTimeLabel` | `ChatMessage` |
| Server status → app enums | `mobile/lib/data/dto/remote_status.dart`: `buyerStatusForWire`, `courierMilestoneForWire`, `RemoteRegistrationStatus.parse`; `remote_orders.dart`: `orderStatusFromServer` | `OrderStatus`, `DeliveryMilestone`, `AccountStatus` |
| Money | `mobile/lib/data/dto/money.dart`: `pesosFromJsonOr` | integer centavos → formatted pesos |
| Registration form | `mobile/lib/data/dto/registration_payload.dart`: `RegistrationProfile.toBuyerFields` | wire fields for `POST /register/buyer` |
| Login / `/me` answer | `mobile/lib/data/repositories/auth_repository.dart`: `authResultFrom` | `AuthResult` as data-layer mapper |
| Email/phone confirmation value | `mobile/lib/data/repositories/account_security_repository.dart`: `_changedValue` | the confirmed `String` |

**UI code does not read API JSON.** No file under `lib/screens/` parses an HTTP
body. The only `jsonDecode` in that tree is the recent-search list, which is a
device preference. The other `jsonDecode` calls are the same kind of local
persistence:

| File | JSON is | Not API because |
|---|---|---|
| `lib/state/auth_state.dart` | stored profile/session flags | written by the app, read before a token is restored |
| `lib/state/cart_state.dart` | cart lines, voucher claims/uses | cart is local by decision (`qa/DECISIONS.md` §4) |
| `lib/state/courier_state.dart` | completed jobs, attempts, COD/remittance | local history; the live job list comes from `GET /courier/jobs` |
| `lib/state/chat_state.dart` | `ChatMessage.toJson/fromJson` | local thread cache; the wire mapper is `remote_chat.dart` |
| `lib/state/return_state.dart` | local return requests | live returns are `notWired` |
| `lib/state/review_state.dart` | local review cache | live reviews come from `GET /reviews/mine` |
| `lib/state/wishlist_state.dart` | followed shops / saved products | device preference, no route |
| `lib/state/recently_viewed_state.dart` | product ids | device preference, no route |
| `lib/screens/buyer/search_screen.dart` | recent search terms | local `SharedPreferences` list, no HTTP |
| `lib/data/ph_address_catalog.dart` | bundled PSGC asset | static asset, not HTTP |
| `lib/data/address_entry.dart` | saved addresses | device preference, no route |

### §13.3 The mock/live switch

There is one decision and one flag:

1. `mobile/lib/config/dev_flags.dart` defines `kUseLiveApi` (default `false`) and
   `kApiBaseUrl` (default empty), both compile-time `String.fromEnvironment`.
2. `mobile/lib/main.dart` is the only production caller of
   `Repositories.forMode(useLive: kUseLiveApi, baseUrl: kApiBaseUrl)`.
3. `Repositories.forMode` is the only place that chooses `Repositories.mock()`
   or `Repositories.live(ApiClient(...))`.
4. Screens and state classes never read the flag. Capability differences (chat
   attachments, simulated replies) are asked of the repository instead.

A live bundle with an empty base URL is **not** silently downgraded to mock: its
`ApiClient` reports `ApiNotConfiguredException` on first use, which is the
honest failure for a misconfigured build.

### §13.4 Secrets and credentials

- No API key, bearer token, password or client secret is committed. A search of
  `lib/`, `android/` and `ios/` finds no `AIza…`, `sk_live`, `pk_live` or
  hardcoded authorization header.
- The Google client IDs are `String.fromEnvironment` values with empty defaults
  (`kGoogleServerClientId`, `kGoogleIosClientId`). iOS reads them from build
  settings (`$(GOOGLE_…_CLIENT_ID)` in `Info.plist`), not literals.
- Mock emails, voucher code `RVSECRET` and the mock Google address are fixtures,
  not credentials.
- `ApiClient` reads the bearer token from `SecureTokenStore`; it is never a
  constructor literal or a checked-in string.

### §13.5 Exact role and enum values

The one naming trap: the app says **courier**, the server's login route and
database table say **logistics**, and the wire still answers `role: "courier"`.

| Layer | Values |
|---|---|
| App `UserRole` | `buyer`, `courier` |
| Wire `role` on login/`me` | `buyer`, `courier` |
| Route/table vocabulary | buyer, logistics |
| App `OrderStatus` | `toShip`, `inTransit`, `outForDelivery`, `toRate` |
| App `DeliveryMilestone` | `accepted`, `arrivedAtSeller`, `pickedUp`, `atSortingCenter`, `sorted`, `assignedToRider`, `inTransit`, `delivered`, `failedDelivery`, `returnToSender` |
| App `AccountStatus` | `active`, `pendingVerification`, `pendingApproval`, `loggedOut` |
| Server `OrderStatus` | the 13 SHOUTY_SNAKE values in §11.1 |
| Server registration status | `pending_verification`, `pending_approval`, `approved`, `rejected` |
| Server `VoucherType` | `fixed`, `percent` |
| Server `PaymentMethod` / `PaymentStatus` | `cod` / `pending`, `paid` |

`arrivedAtSeller` is the only app milestone with no server counterpart (§0.7).
The buyer's four statuses are display stages, not wire values (§0.5).
