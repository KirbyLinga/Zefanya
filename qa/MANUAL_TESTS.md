# Manual Test Script — Phase 6 `Needs device/API`

This script covers every workbook row classified **Needs device/API** (58 cases).
Rows that can be proven in the widget/unit suite are already **Pass** and are not repeated here.
Rows with an absent behaviour are **Fail**; this script does not ask a tester to prove a missing feature.

## Test environments

**Mock build (device-only checks):**

```powershell
cd mobile
flutter run
```

The default is mock mode (`kUseLiveApi=false`). The debug build shows the tester-only login role selector.

**Live build (API checks):**

```powershell
cd web
php artisan migrate:fresh --seed
php artisan serve --host=0.0.0.0 --port=8000

cd ..\mobile
flutter run --dart-define=zefanya.useLiveApi=true `
            --dart-define=zefanya.apiBaseUrl=http://<PC-LAN-IP>:8000/api
```

Seeded accounts: `buyer@zefanya.test` / `password` and `courier@zefanya.test` / `password`.
Use two physical phones where the case needs two accounts or a lock screen. For network cases, use airplane mode
or a proxy that can drop one request; do not simulate a failure by killing the app unless the case says so.

Record for each run: build mode, device/OS, server commit, result (Pass/Fail/Blocked), and a screenshot or request log.

---

## Buyer — Account Creation (6)

### TC-BUY-001 — Register with all valid data
- **Dependency:** live API + real mailbox.
- **Steps:** On a fresh install, register a new buyer with valid name, sex, birth date, email, 09 phone, password,
  address and ID image. Accept the terms and submit. Open the inbox and enter the 6-digit code.
- **Expected:** Form accepts with no validation errors; a 6-digit OTP arrives; the verification screen opens; the
  account is saved as Unverified/pending approval (Decision 2). No duplicate account is created.

### TC-BUY-027 — Resend OTP
- **Dependency:** live API + mailbox.
- **Steps:** Request a code, wait for the resend cooldown, tap Resend. Try the first code, then the newly received code.
- **Expected:** A new email arrives; the first code is rejected; the second code verifies the account.

### TC-BUY-029 — Log in with the new verified account
- **Dependency:** live API + admin approval.
- **Steps:** Approve the account in the admin/Logistics web screen. Log out, then log in with the new email and password.
- **Expected:** Login succeeds and the buyer home opens with the registered name. No other user's data appears.

### TC-BUY-035 — Register with no internet connection
- **Dependency:** real device network control.
- **Steps:** Fill every registration step. Disable Wi-Fi/data, tap Register, observe. Restore the network and continue.
- **Expected:** A clear no-internet message; typed values remain; no crash; no account is created while offline.
  After reconnecting, exactly one account/OTP exists.

### TC-BUY-036 — Connection drops right after tapping Register
- **Dependency:** real device + server idempotency.
- **Steps:** Tap Register, then drop the connection immediately (proxy/airplane mode). Wait, restore the network, retry.
- **Expected:** A connection error or visible waiting state, never a frozen screen. In the end exactly one account
  exists and one OTP is sent; a retry must not create a duplicate.

### TC-BUY-040 — Large system font on a small screen
- **Dependency:** real device, small screen, maximum system font/display size.
- **Steps:** Set the system font to its largest size and display size to maximum. Open every registration step and
  trigger required-field errors. Scroll the form and reach the primary button.
- **Expected:** Labels, placeholders, errors and buttons are fully readable; nothing is cut off or overlapping;
  the active field no longer needs to be visible above the keyboard at the same time as Submit (the form scrolls).

---

## Buyer — Modules (37)

### Account & Security

#### BUY-ACCT-001 — Edit profile with valid data
- **Dependency:** live profile API.
- **Steps:** Change the buyer's name and photo, save, pull-to-refresh, log out and log in again.
- **Expected:** A success message; the new name/photo appear after refresh and re-login, proving server persistence.

#### BUY-ACCT-010 — Change the profile photo with the camera and the gallery
- **Dependency:** real device camera/gallery + OS permissions.
- **Steps:** Tap the avatar, take a camera photo, save, close and reopen. Repeat with Gallery. Deny the permission once.
- **Expected:** The chosen photo shows on the profile and survives an app restart. Denial shows a clear message or
  no-op, never a crash.

### Address Book

#### BUY-ADDR-009 — Cannot open another buyer's address
- **Dependency:** live address API/ownership.
- **Steps:** As Buyer 1, capture the address ID/route for ADDR-3 belonging to Buyer 2. Open it directly or tamper the
  request while signed in as Buyer 1.
- **Expected:** Access is denied (401/403/not found); no part of ADDR-3 is rendered.

#### BUY-ADDR-010 — Use My Location with permission allowed and denied
- **Dependency:** real device location + OS permission.
- **Steps:** In the address form tap Use My Location and allow permission. Edit the returned region/city/barangay and
  save. Repeat with permission denied.
- **Expected:** Allowed: fields fill from location and remain editable. Denied: a clear explanation and manual entry,
  no crash.

### Browse, Search & Filter

#### BUY-SRCH-005 — Search: special characters and injection
- **Dependency:** live catalogue API.
- **Steps:** Search for `' OR 1=1 --`, `<script>alert(1)</script>`, `%`, `_`, emoji and a very long string.
- **Expected:** Each returns no results or a literal match. No error screen, pop-up, SQL error, or list of all products.

#### BUY-SRCH-010 — Inactive, deleted and draft products never appear
- **Dependency:** live catalogue states.
- **Steps:** Seed/activate a draft product, a deleted product and an inactive product. Search, browse its category and
  store, and open its share link.
- **Expected:** None appears in search/category/store. Each share link opens an unavailable/not-found screen.

#### BUY-SRCH-013 — Search with no internet
- **Dependency:** real device network control + live catalogue.
- **Steps:** Disable networking, open Search and submit a query. Restore networking and search for P-02.
- **Expected:** Step 1 shows a no-internet message, not an empty "no results" state and not a crash. Step 2 shows P-02.

### Product Detail Page

#### BUY-PDP-009 — Swipe and zoom product images
- **Dependency:** real touch screen.
- **Steps:** Open a multi-photo product. Swipe the gallery both ways, pinch-zoom, double-tap, and close.
- **Expected:** Images follow the uploaded order with a position indicator; zoom works and stays sharp; closing returns
  to the same PDP state. (The pager/dots themselves are already covered by `product_gallery_test.dart:122`.)

#### BUY-PDP-010 — Share link opens the product in the app
- **Dependency:** real host + Android `assetlinks.json` / iOS associated domains + second phone.
- **Steps:** Share P-02 from Phone A. Open the link on Phone B with the app installed, then uninstall the app and open it.
- **Expected:** With the app: P-02 opens directly. Without: a mobile web page for P-02 or the store listing. No blank
  screen and no wrong product. (The in-app parser is covered by `buyer_bm_quick_wins_test.dart:343`.)

### Wishlist & Follow Store

#### BUY-WISH-003 — Wishlist item goes out of stock or is deleted
- **Dependency:** live catalogue stock/state.
- **Steps:** Save P-02, then set its stock to 0 on the server. Refresh. Then delete P-05 and refresh again.
- **Expected:** P-02 shows Sold out with Add to Cart disabled. P-05 shows Unavailable with no Add to Cart. The saved
  screen does not crash or silently drop the row.

### Cart

#### BUY-CART-006 — Price changes while in the cart
- **Dependency:** live price revalidation.
- **Steps:** Add a ₱420 item, change its server price to ₱480, reopen/refresh the cart and go to checkout.
- **Expected:** The cart shows ₱480 and the subtotal updates; the order is not placed at the stale ₱420.

### Vouchers & Promotions

#### BUY-VOU-012 — Voucher returned after cancellation
- **Dependency:** cancellation/refund server support.
- **Steps:** Apply MCH50 to an eligible order, place it, cancel before shipping, then reopen the voucher wallet.
- **Expected:** MCH50 is back in the wallet and usable again before it expires. The cancellation is reflected on both
  buyer and seller sides.

### Checkout

#### BUY-CHK-004 — Unserviceable address
- **Dependency:** live serviceability API.
- **Steps:** Select an address outside the serviceable area; attempt Place Order.
- **Expected:** A message says the area is not serviceable and Place Order is disabled. No order is created.

#### BUY-CHK-008 — Price changed during checkout
- **Dependency:** live price revalidation.
- **Steps:** Reach checkout at ₱450, change the server price to ₱480, tap Place Order.
- **Expected:** The order is not placed at ₱450. A message/summary shows ₱480 and the buyer must accept the new total.

#### BUY-CHK-011 — Tampered price or total in the request
- **Dependency:** live API + request interception.
- **Steps:** Intercept `POST /orders`, change a line price or the total to ₱1, send it.
- **Expected:** The server rejects or ignores the tampered amount; any order created uses the real catalogue price.

#### BUY-CHK-012 — Connection lost after tapping Place Order
- **Dependency:** real device + server idempotency.
- **Steps:** Tap Place Order, drop the connection, wait, restore, retry from the app.
- **Expected:** A connection error/waiting state, never frozen. Exactly one order exists; the retry does not duplicate it.

#### BUY-CHK-013 — App interrupted on the checkout screen
- **Dependency:** real device background/kill.
- **Steps:** Fill address, vouchers, COD and a ₱1,083 total. Send the app to the background and kill it. Reopen.
- **Expected:** Checkout restores the same address/vouchers/payment/total (₱1,083.00), or reloads safely without
  placing a duplicate order or losing the cart.

### Payment

#### BUY-PAY-002 — E-wallet or card payment succeeds
- **Dependency:** live payment gateway + callback.
- **Steps:** Choose E-Wallet/Online Payment, complete a successful sandbox payment, return to the app.
- **Expected:** The order moves To Pay → To Ship and stores the payment reference. The charged amount equals the order
  total exactly.

#### BUY-PAY-003 — Payment fails
- **Dependency:** live payment gateway failure simulation.
- **Steps:** Trigger a declined/failed payment.
- **Expected:** The order stays To Pay with "Payment failed" and Pay Again. It is not marked paid and the seller cannot
  ship it.

#### BUY-PAY-004 — Buyer cancels at the gateway
- **Dependency:** live gateway.
- **Steps:** Start payment, cancel/close the gateway, return to the app.
- **Expected:** The order is back in To Pay; no duplicate order is created.

#### BUY-PAY-005 — App closed on the gateway screen
- **Dependency:** live gateway callback + device.
- **Steps:** Kill the app while the gateway is open. Complete/confirm payment in the gateway, then reopen the app.
- **Expected:** The order shows To Ship once the gateway confirms; it is charged once only.

#### BUY-PAY-006 — Unpaid order auto-cancels
- **Dependency:** live scheduler/queue worker.
- **Steps:** Create an unpaid order, advance the server clock/job past the payment deadline, then inspect the order,
  product stock and voucher wallet.
- **Expected:** The order is Cancelled (reason unpaid); stock is 20; MCH50 is back in the wallet.

#### BUY-PAY-007 — Pay just before vs after the auto-cancel time
- **Dependency:** live scheduler + gateway.
- **Steps:** Run two unpaid orders: pay at minute 59, attempt at minute 61.
- **Expected:** The 59-minute payment succeeds. The 61-minute order is already Cancelled and Pay is not available.

#### BUY-PAY-008 — Pay in the e-wallet app and switch back
- **Dependency:** real device + gateway deep link.
- **Steps:** Start an e-wallet payment, switch to the e-wallet app, pay, switch back.
- **Expected:** The app returns to the order and shows To Ship with the reference; charged once. Returning via Recents
  or the app icon also lands on the correct state.

#### BUY-PAY-009 — Back button while payment is processing
- **Dependency:** real device + gateway.
- **Steps:** While the payment spinner is active, press system Back several times; then check the order and gateway log.
- **Expected:** The app asks before leaving or ignores Back; the order is either paid once or still unpaid. No second
  charge and no duplicate order.

### Order Tracking & History

#### BUY-TRK-005 — Automatic completion
- **Dependency:** live scheduler.
- **Steps:** Deliver an order, inspect it before day 7, advance time past day 7, inspect again and check seller earnings.
- **Expected:** Before 7 days: To Receive. After: Completed; earnings are released on the seller side.

#### BUY-TRK-006 — Cannot open another buyer's order
- **Dependency:** live order API/ownership.
- **Steps:** As Buyer 1, capture Buyer 2's order ID and open it directly or tamper the request.
- **Expected:** Denied (401/403/not found); no details of Buyer 2's order are shown.

#### BUY-TRK-007 — Push notification opens the order
- **Dependency:** FCM/APNs + physical device lock screen.
- **Steps:** Change the order status while the buyer's phone is locked. Inspect the lock-screen notification and tap it.
- **Expected:** A push arrives with the status change; tapping opens that exact order. Cold-start and warm-start both
  work. (This build has no push plugin configured; record Blocked if the push infrastructure is unavailable.)

### Return & Refund Request

#### BUY-RET-001 — Request a return within the window
- **Dependency:** live returns API + seller notification.
- **Steps:** On a delivered order inside day 7, submit a return with evidence and a description. Check buyer, seller and
  cancellation controls.
- **Expected:** The request is submitted; the order moves to Return/Refund; Seller 1 is notified; the order can no longer
  be cancelled.

#### BUY-RET-005 — Track request status
- **Dependency:** live returns API.
- **Steps:** Have the seller reject the request with a reason. Open the buyer's return screen and tap Escalate.
- **Expected:** Status Rejected with the seller's exact reason and an Escalate button; escalation reaches the server and
  the status updates. No invented reason text.

#### BUY-RET-006 — Return evidence from the camera and the gallery
- **Dependency:** real device camera/gallery.
- **Steps:** Attach photos from Camera and Gallery in a chosen order, preview them, remove one, then submit. Deny the
  camera permission once.
- **Expected:** Photos appear in the chosen order with previews and can be removed before submit. The request carries the
  remaining photos. Permission denial is explained without a crash.

### Ratings & Reviews

#### BUY-RATE-006 — Average rating updates
- **Dependency:** live review aggregate.
- **Steps:** Seed one 4-star review for a product. Submit a 2-star review from a second completed order. Open the PDP and
  search card.
- **Expected:** Average becomes 3.0 and count 2 on both the product screen and the search card.

#### BUY-RATE-007 — Review photos from the camera and a multi-select
- **Dependency:** real device camera/gallery multi-select.
- **Steps:** Attach photos one by one to the 5-photo limit, then try to add a 6th. Preview/remove before submitting.
- **Expected:** No more than 5 photos attach; the gallery stops at 4 more or says only 5 are allowed. Previews are in
  order and removable. (The 5-photo limit is unit-tested at `review_limits_test.dart:136`.)

### Chat & Notifications

#### BUY-CHAT-001 — Chat with a seller from a product
- **Dependency:** live chat transport + two clients.
- **Steps:** From P-02, message Seller 1 with text and the product card. Reply from the seller account.
- **Expected:** Seller 1 receives both in order; the buyer's chat shows the P-02 product card. No message is lost or
  duplicated on reconnect.

#### BUY-CHAT-006 — Chat message while the app is in the background
- **Dependency:** FCM/APNs + device + live chat.
- **Steps:** Background the buyer app. Send a seller message. Inspect the push and tap it.
- **Expected:** A push shows Seller 1's name and a message preview; tapping opens that chat with the message visible.
  (No push plugin is present; record Blocked if credentials are unavailable.)

#### BUY-CHAT-007 — Notifications turned off in phone Settings
- **Dependency:** real device OS settings.
- **Steps:** Disable notifications for the app, send a seller message, then open the in-app notification list.
- **Expected:** No push arrives; the app neither crashes nor repeatedly prompts. The in-app list still shows the message.

#### BUY-CHAT-008 — Send a chat message with no internet
- **Dependency:** real device network control + live chat.
- **Steps:** Disable networking, send a message, restore networking. Check both sides.
- **Expected:** The message is marked not sent with a retry option, or is sent automatically once online. Seller 1
  receives it once, with no duplicate.

---

## Rider — Modules (15)

### Profile, Vehicle & Documents

#### RID-PROF-007 — Rider cannot see another rider's profile
- **Dependency:** live rider profile API/ownership.
- **Steps:** As Rider 1, capture Rider 2's profile ID/route and open it directly or tamper the request.
- **Expected:** Denied (401/403/not found); no Rider 2 data (name, plate, documents) is shown.

### Availability

#### RID-AVAIL-005 — Location permission needed to go Online
- **Dependency:** real device location permission + Logistics presence.
- **Steps:** Deny location, tap Online. Then allow location and tap Online again; check Logistics.
- **Expected:** Denied: Online is blocked with a message that location is needed and a link to Settings; no crash.
  Allowed: Rider 1 goes Online and Logistics sees the change.

#### RID-AVAIL-006 — Location turned off while Online
- **Dependency:** real device location services + live presence/offers.
- **Steps:** Go Online, turn off device location, wait for offers. Turn location back on and wait.
- **Expected:** The app warns that location is off; no new offer arrives while it is off. After restoring location,
  presence recovers and offers resume.

#### RID-AVAIL-007 — App force-closed while Online
- **Dependency:** live presence/session timeout + device.
- **Steps:** Go Online with an assigned job, force-stop the app, wait up to 5 minutes, check Logistics. Relaunch.
- **Expected:** Within 5 minutes Rider 1 shows Offline and receives no offer. The assigned job stays with Rider 1 and is
  shown on relaunch.

### Job Assignment

#### RID-JOB-005 — Accept and manual assignment at the same moment
- **Dependency:** live assignment API + two clients.
- **Steps:** Have Rider 1 tap Accept while Logistics manually assigns the same job to Rider 2 at the same moment.
- **Expected:** Exactly one rider owns the job and Logistics shows that rider. The losing side gets a clear "already
  assigned" message; the job is not left unassigned or double-assigned.

#### RID-JOB-007 — Cannot accept another company's job
- **Dependency:** live job API/authorization.
- **Steps:** As Rider 1 (Company A), capture Company B's job ID and attempt to accept/view it.
- **Expected:** Rejected (403 or not found); the job is unchanged and no Company B data is shown.

#### RID-JOB-008 — Job offer while the phone is locked
- **Dependency:** FCM/APNs + device + server offer timer.
- **Steps:** Lock the rider's phone, dispatch a timed offer, observe the notification, unlock and open it.
- **Expected:** A push with sound arrives while locked; the offer shows about 40 seconds left; Accept works and assigns
  the job. Expiry after 60 seconds is rejected.

#### RID-JOB-009 — Navigate to the pickup address
- **Dependency:** real device + map/url launcher handoff.
- **Steps:** Open the assigned job and tap Navigate. Switch back to the app.
- **Expected:** The map app opens at Seller 1's pickup location (Commonwealth, Quezon City). Returning shows the same
  job and state.

### Pickup

#### RID-PICK-005 — Double scan at pickup
- **Dependency:** real scanner/device + server idempotency.
- **Steps:** Scan the same waybill twice as quickly as possible. Check the pickup event and pay.
- **Expected:** One Picked Up event only; the second attempt says "already picked up" or is rejected; pay is counted
  once.

#### RID-PICK-007 — Pickup with no internet
- **Dependency:** real device network control + milestone API.
- **Steps:** Disable networking at pickup, confirm/scan, restore networking.
- **Expected:** The scan is blocked with a no-internet message, or saved as pending and sent once online with the
  original timestamp. No duplicate Picked Up event.

### Delivery & Status Updates

#### RID-DLV-007 — Cannot update another rider's job
- **Dependency:** live milestone API/authorization.
- **Steps:** As Rider 2, capture Rider 1's job ID and attempt to advance its milestone or attach proof.
- **Expected:** Rejected (401/403/not found); Rider 1's job is unchanged and no proof is attached.

#### RID-DLV-009 — Mark Delivered with no internet
- **Dependency:** real device network control + retry queue.
- **Steps:** Disable networking at the door, enter OTP/COD and attach the photo, confirm delivery. Restore networking.
- **Expected:** The app clearly shows the delivery is not sent (or blocks it) and keeps the photo/code; after reconnect
  the delivery is sent once, with no duplicate COD or proof.

#### RID-DLV-010 — Call or message the buyer from the job
- **Dependency:** real device dialer/SMS or chat.
- **Steps:** On an active delivery tap Call, then Message.
- **Expected:** The phone dialer opens with Buyer 1's number (or a masked relay number). Messaging opens a conversation
  with Buyer 1. Returning preserves the job state.

#### RID-DLV-011 — Delivery location is recorded
- **Dependency:** real device GPS + server field.
- **Steps:** Deliver with location on. Inspect the delivery record/logistics view.
- **Expected:** The record shows the GPS location and delivery time, close to ADDR-1. No permission bypass or invented
  coordinates when location is off.

### History, Earnings & COD Remittance

#### RID-EARN-006 — Rider cannot confirm own remittance
- **Dependency:** live remittance API.
- **Steps:** Submit a COD remittance as Rider 1. Attempt to confirm it from the rider app or by replaying the confirm
  request with the rider token.
- **Expected:** No rider-side confirm control exists; the replay is rejected (403) and the COD balance stays outstanding
  until Logistics confirms it.

---

## Coverage checklist

Use this list to confirm every Needs row was run. A blank result means the row is still unproven.

- Buyer Account Creation: TC-BUY-001, TC-BUY-027, TC-BUY-029, TC-BUY-035, TC-BUY-036, TC-BUY-040
- Account & Security: BUY-ACCT-001, BUY-ACCT-010
- Address Book: BUY-ADDR-009, BUY-ADDR-010
- Browse/Search: BUY-SRCH-005, BUY-SRCH-010, BUY-SRCH-013
- PDP: BUY-PDP-009, BUY-PDP-010
- Wishlist: BUY-WISH-003
- Cart: BUY-CART-006
- Vouchers: BUY-VOU-012
- Checkout: BUY-CHK-004, BUY-CHK-008, BUY-CHK-011, BUY-CHK-012, BUY-CHK-013
- Payment: BUY-PAY-002, BUY-PAY-003, BUY-PAY-004, BUY-PAY-005, BUY-PAY-006, BUY-PAY-007, BUY-PAY-008, BUY-PAY-009
- Tracking: BUY-TRK-005, BUY-TRK-006, BUY-TRK-007
- Returns: BUY-RET-001, BUY-RET-005, BUY-RET-006
- Ratings: BUY-RATE-006, BUY-RATE-007
- Chat: BUY-CHAT-001, BUY-CHAT-006, BUY-CHAT-007, BUY-CHAT-008
- Rider Profile: RID-PROF-007
- Rider Availability: RID-AVAIL-005, RID-AVAIL-006, RID-AVAIL-007
- Rider Jobs: RID-JOB-005, RID-JOB-007, RID-JOB-008, RID-JOB-009
- Rider Pickup: RID-PICK-005, RID-PICK-007
- Rider Delivery: RID-DLV-007, RID-DLV-009, RID-DLV-010, RID-DLV-011
- Rider Earnings: RID-EARN-006
