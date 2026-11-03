# Docs vs Reality at HEAD (integration/hardened e036a01)

Generated: 2026-11-03

---

## Route count

- `php artisan route:list --path=api --except-vendor`: **81 routes**
- api-contract.md §1.7 lists ~32 routes (it was written against an earlier working branch)
- Net additions since contract: PSGC (8), delivery-pass/proof (4), v1 mirrored surface (~35 added), admin logistics (6), account security (5), addresses (6), wishlist (3), auth/google (2)

---

## Rate-limiter names (from routes/api.php)

Named buckets actually registered:

| Name | Usage |
|------|-------|
| `api-register` | buyer + courier registration |
| `api-login` | buyer + courier login |
| `api-google` | Google sign-in |
| `api-uploads` | registration + review upsert + milestone |
| `api-delivery-verify` | courier milestone PATCH |
| `api-account-password` | password change |
| Per-route inline (3,5 / 10,1 / 60,1 pattern) | OTP verify, OTP resend, catalogue, PSGC |

api-contract.md §1.6 lists `api-otp-verify`, `api-otp-resend` as named — reality uses inline `throttle:10,1,api-otp-verify` and `throttle:3,5,api-otp-resend` (correct name in the route, but not a `RateLimiter::for(...)` named limiter, just an inline bucket prefix). Contract table is accurate in limits, names are implementation detail.

---

## Test file counts

- PHP test files: **45** (44 `*Test.php` + 1 `TestCase.php`)
- PHP tests passing: **401** (1858 assertions)
- Flutter tests passing: **749**

acceptance.md states "303 passed / 1325 assertions / 733 flutter" — those were from an earlier HEAD. Current counts are higher (10 more web tests, 16 more flutter tests).

---

## Migrations

`git diff --name-status web-api-master HEAD -- web/database/migrations` shows **14 files**, all `A` (added). Zero modified or deleted. Contract is met.

Migrations added since web-api-master:

1. `2026_10_05_000001_create_reviews_table.php`
2. `2026_10_07_000001_add_assigned_courier_to_seller_orders.php`
3. `2026_10_07_100001_create_chat_messages_table.php`
4. `2026_10_08_000001_add_maker_note_to_sellers.php`
5. `2026_10_08_100001_create_promotions_table.php`
6. `2026_10_29_230500_add_rider_fields_to_logistics_providers_table.php`
7. `2026_10_29_233100_create_psgc_tables.php`
8. `2026_10_29_233200_create_buyer_addresses_table.php`
9. `2026_10_30_001600_create_wishlist_items_table.php`
10. `2026_10_30_003000_add_delivery_handover_to_seller_orders_table.php`
11. `2026_10_30_004000_add_pending_contact_changes_to_buyers_table.php`
12. `2026_10_31_100000_add_delivery_handover_security_to_seller_orders.php`
13. `2026_10_31_120000_add_google_sub_to_identity_tables.php`
14. `2026_11_01_100000_add_item_verification_to_reviews.php`

---

## Not-implemented list (routes referenced in api-contract.md that do not exist at HEAD)

| Endpoint | Contract section | Status |
|----------|-----------------|--------|
| `GET /promotions` | §5.4 | **NOT BUILT** — no route |
| `GET /chat/{contact}` | §9.1 | **NOT BUILT** — no route |
| `POST /chat/{contact}` | §9.2 | **NOT BUILT** — no route |
| `GET /orders/{sellerOrder}/tracking` | §6.4 | **NOT BUILT** — documented as proposed |
| `POST /checkout/quote` | §4.3 | **NOT BUILT** — documented as intended |
| `POST /orders/{sellerOrder}/cancel` | §0.8 | **NOT BUILT** — buyer-side cancel |
| `POST /orders/{sellerOrder}/return` | §0.8 | **NOT BUILT** — returns |

Routes that were marked `NOT BUILT` in §0.8 but ARE now implemented:

| Endpoint | Was |
|----------|-----|
| `POST /api/v1/auth/google` | marked not built → **NOW BUILT** |
| `POST /api/register/courier` | marked not built → **NOW BUILT** |
| `GET /api/v1/buyer/orders` + full buyer order surface | **NOW BUILT** |

---

## Summary

- 81 API routes at HEAD (up from ~18 on web-api-master)
- 14 migrations added, 0 modified — clean
- 45 PHP test files, 401 tests passing
- 7 endpoints in contract still not implemented (promotions, chat, tracking, quote, cancel, return)
- All H1–H5 security properties verified by mutation testing (see docs/mutation-proof.md)
