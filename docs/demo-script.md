# Demo script — S3.4

7 minutes per role. Two builds:

- **demo-mock** (default, `flutter run --dart-define-from-file=env.json`): no backend needed. Recommended for the presentation.
- **demo-live** (`flutter run --release --dart-define-from-file=env.json` with `zefanya.useLiveApi=true`): only features marked LIVE-READY in `docs/feature-status.md` work against the server.

## Pre-demo checklist (all roles)

- Phone charged, on same Wi-Fi as the backend (demo-live only)
- Backend up, `php artisan storage:link` run, DB seeded (`php artisan db:seed`; `php artisan demo:seed` for demo data)
- Database backed up (`mysqldump` to `..\zefanya-backups\`)
- Release build installed (demo-live) or debug build (demo-mock)
- Permissions pre-granted (camera, location)
- Airplane-mode test rehearsed (mock mode works offline)
- `env.json` supplied so Google sign-in shows or is knowingly hidden
- `kUseLiveApi=false` for demo-mock, `true` for demo-live

## Buyer — 7 minutes (mock mode, fresh install)

| Minute | Tap | Expected result | Credentials | Failure plan |
|--------|-----|----------------|-------------|--------------|
| 0:00 | App launch | Splash → intro gate (3 slides). No Skip. "Begin" only on slide 3. | — | If intro crashes, app falls back to login. |
| 0:30 | Tap "Begin" on slide 3 | `introSeen` saved; goes to login (or buyer shell if returning user with valid session). | — | If Begin missing, check slide index; fallback to login. |
| 1:00 | Tap "Register" → "Buyer" | Registration form opens. | — | If form doesn't open, check navigation stack. |
| 1:30 | Fill buyer form, tap Submit | OTP screen opens. Email: `juanmiguel.delacruz@example.com`. | Buyer 1: `Mamimili@2026` | If submit fails, check validation messages; re-enter. |
| 2:00 | Enter mock OTP `123456` | "Simulate Approval (Test)" button shows (dev tools). Tap it → buyer shell (Home). | OTP: `123456` | If OTP rejected, retype; check `kMockOtpCode`. |
| 2:30 | Browse categories → tap a product | Product detail opens with variants, price, stock. | — | If product missing, check mock catalog data. |
| 3:00 | Select variant, qty 1, tap "Add to cart" | Cart badge updates (1 product). "Quantity updated" snackbar. | — | If badge doesn't change, check cart state. |
| 3:30 | Tap cart icon → "Checkout" | Checkout screen shows lines, shipping, total. COD selected. | — | If checkout empty, verify cart has items. |
| 4:00 | Tap "Place Order" | Order placed; order confirmation screen. Order appears in "Orders" tab. | — | If order fails, check stock (product may be out of stock). |
| 4:30 | Tap Orders tab → tap order | Order detail shows tracking timeline. Status: PLACED. | — | If order missing, check mock order repository. |
| 5:00 | (Admin approves order on web) → refresh | Status advances to CONFIRMED / READY_FOR_PICKUP with tracking number. | Admin credentials | If status doesn't change, check web admin dashboard. |
| 5:30 | Tap order → tracking timeline | Shows seller, rider, sorting center updates. | — | If timeline empty, check milestone events. |
| 6:00 | (Rider delivers on web/mock) → mark Delivered | Buyer sees 6-digit OTP prompt; enter `123456`. Status → DELIVERED. | OTP: `123456` | If OTP rejected, verify code matches; check delivery pass. |
| 6:30 | Tap "Order Received" | Status → COMPLETED. Review option unlocks. | — | If button missing, check order is DELIVERED. |
| 7:00 | Done | Full buyer journey demonstrated. | | |

## Rider — 7 minutes (mock mode)

| Minute | Tap | Expected result | Credentials | Failure plan |
|--------|-----|----------------|-------------|--------------|
| 0:00 | App launch | Login screen. | Rider 1: `pedrojose.garcia@example.com` / `Rider@2026` | If login fails, check mock auth accepts credentials. |
| 0:30 | Login → rider shell | Job feed shows "x.x km to pickup" for assigned area. | — | If feed empty, check mock courier jobs. |
| 1:00 | Tap a job → job detail | Shows waybill, buyer address (after assignment), "Call buyer" button. | — | If phone hidden, check privacy gate (not yet assigned). |
| 1:30 | Tap "Accept" (if offered) or view assigned job | Job becomes rider's; waybill shown. | — | If accept fails, check job availability/concurrent limit. |
| 2:00 | Tap "Scan waybill" → enter tracking number | Seller confirms pickup → status PICKED_UP. | Waybill: `ZEF-…-01` | If scan fails, use manual entry fallback. |
| 2:30 | Rider brings to sorting center → web staff scan in | Status → AT_SORTING_CENTER / SORTED / ASSIGNED_TO_RIDER. | — | If scan fails, check web sorting center screen. |
| 3:00 | Job → OUT_FOR_DELIVERY → navigate to buyer | Buyer's full address + "Call buyer" visible. | — | If address hidden, check assignment gate. |
| 3:30 | At buyer door → "Mark Delivered" | Enter 6-digit OTP from buyer's app. Photo (camera or mock). | OTP: `123456` | If OTP fails, check attempt counter (5 wrong = 15 min lock). |
| 4:00 | COD collected = order total | Mark Paid; status → DELIVERED. | Amount: order total | If amount mismatch, re-collect exact total. |
| 4:30 | Earnings screen | Shows ₱50/delivery, today/week totals, COD balance to remit. | — | If earnings wrong, check pay rates (P50/P30/P0). |
| 5:00 | Rider profile → documents | Plate number, documents (JPG/PNG/PDF ≤5MB). | — | If documents missing, check mock rider profile. |
| 5:30 | Go Offline → Go Online | `is_online` toggles. | — | If toggle fails, check availability state. |
| 6:00 | Failed delivery scenario → mark failed | Reason required (Buyer not home / Wrong address / Refused). Attempt counter increments. | — | If no reason required, check validation. |
| 6:30 | 3rd failed attempt → Return to Sender | Status → RETURNED. Stock restored on seller confirm. | — | If not RETURNED, check attempt counter. |
| 7:00 | Done | Full rider journey demonstrated. | | |

## Seller — 7 minutes (web, buyer's seller = Seller 1)

| Minute | Tap | Expected result | Credentials | Failure plan |
|--------|-----|----------------|-------------|--------------|
| 0:00 | Open `/seller/login` | Login screen. | Seller 1: `mariaclara.santos@example.com` / `Tindahan@2026` | If login fails, check web auth. |
| 0:30 | Dashboard | Shows orders, revenue, products. | — | If dashboard empty, check seller data. |
| 1:00 | Orders → tap pending order | Order detail: confirm / ready / tracking. | — | If order missing, check seller orders. |
| 1:30 | Tap "Confirm" | Status → CONFIRMED. | — | If confirm fails, check order state machine. |
| 2:00 | Tap "Ready for Pickup" | Tracking number generated (ZEF-...). Status → READY_FOR_PICKUP. | — | If tracking fails, check format ZEF-{id}-{index}. |
| 2:30 | Products → edit a product | Update price/stock. | — | If edit fails, check product ownership. |
| 3:00 | Products → add new product | Product appears in catalog. | — | If product missing, check image upload. |
| 3:30 | Vouchers → create store voucher | Voucher claimable by buyers. Code: MCH50 pattern. | — | If voucher fails, check min spend/limits. |
| 4:00 | Shipments → view assigned deliveries | Rider assignments, delivery areas. | — | If empty, check delivery area assignment. |
| 4:30 | Print waybill (if implemented) | A6 printable with tracking, barcode, QR. | — | If not built, note as limitation (S7). |
| 5:00 | Approve buyer registration (admin) | Buyer moves to approved. | Admin: existing seeded admin | If approve fails, check admin permissions. |
| 5:30 | Done | Full seller journey demonstrated. | | |

## Admin — 7 minutes (web)

| Minute | Tap | Expected result | Credentials | Failure plan |
|--------|-----|----------------|-------------|--------------|
| 0:00 | Open `/admin/login` | Login screen. | Admin: existing seeded admin | If login fails, check web auth. |
| 0:30 | Dashboard | Registrations pending approval. | — | If empty, check registration data. |
| 1:00 | Registrations → buyer pending | Approve / reject with reason. | — | If approve fails, check audit row written. |
| 1:30 | Registrations → seller pending | Approve / reject. Seller can then log in. | — | If seller can't log in after approval, check status. |
| 2:00 | Registrations → logistics pending | Approve / reject. | — | Same as seller. |
| 2:30 | Audit log | All approval actions logged with actor, action, timestamp. | — | If missing rows, check audit middleware. |
| 3:00 | Users → view all | Buyers, sellers, logistics, riders listed with status. | — | If data missing, check user tables. |
| 3:30 | Done | Full admin journey demonstrated. | | |

## Failure plans (common)

| Failure | Response |
|---------|----------|
| Network error (demo-live) | Switch to demo-mock build; story continues offline. |
| OTP rejected | Retype code; check attempt counter; wait lockout if 5 wrong. |
| Stock out of stock | Choose different product or variant; check inventory. |
| Payment fails | Use COD (no gateway); check order total. |
| Camera denied | Use manual entry / gallery fallback; Open Settings button. |
| Google sign-in unavailable | Button hidden in live mode without client ID; use email/password. |

## Build to use

- **Primary**: demo-mock (no network, all features work). Build: `flutter run --dart-define-from-file=env.json` with `zefanya.useLiveApi=false`.
- **Backup**: demo-live (LIVE-READY features only against real server). Build: `flutter run --release --dart-define-from-file=env.json` with `zefanya.useLiveApi=true` and `zefanya.apiBaseUrl=https://<tunnel>/api`.

