# Known limitations

Zefanya currently runs against a mock repository bundle by default. The app is
honest about the boundary: anything below is either local-only, explicitly
unwired, or cannot be verified in a widget test. None of these are hidden behind
fake success copy.

Last reviewed: 31 October 2026 (Flutter 3.44.0, Windows).

## Google sign-in

- Real Google accounts use `NativeGoogleSignInService`, which needs a Google
  Cloud OAuth client ID for the platform (`kGoogleServerClientId` /
  `kGoogleIosClientId`). Without one it refuses before opening the account
  sheet rather than pretending to sign in.
- Android also needs the app's SHA-1 (debug and release) registered on that
  OAuth client. A debug build on another machine will not authenticate until its
  fingerprint is added.
- The app posts the Google `id_token` to `POST /auth/google`; the server must
  verify that token's signature, audience and issuer. The mobile app cannot do
  that verification and does not trust the token on its own.
- Courier registration through Google is explicitly `notWired`
  (`POST /auth/google (courier registration)`). First-time Google couriers
  therefore land on the pending-approval gate; they do not get a shell.
- Mock mode uses `MockGoogleSignInService` (the `zefanya.mockGoogleEmail`
  identity) and never opens a native account picker.

## Delivery and COD confirmation

- The courier's delivery-code and cash-on-delivery checks are implemented in
  `CourierState` with mock/local persistence. There is no verified server-side
  issuance of the buyer's 6-digit code and no server-side COD settlement yet.
- Milestone updates are sent fire-and-forget to `MockCourierRepository`. A live
  endpoint failure is swallowed so the courier's screen stays authoritative;
  the buyer's tracker can therefore show a stale milestone until the next
  successful sync.
- Buyer "Order Received" writes through `PATCH /orders/{sellerOrder}/buyer-state`,
  which is still `notWired` in the live order repository. Delivery completion
  in live mode depends on the server implementing that route.
- COD remittance, outstanding balances and collection history are local mock
  state; they are not reconciled against a logistics backend.

## Notifications

- There is no notifications endpoint in the Laravel API. The inbox is seeded
  fixture data (`kSeedNotifications`); only read/unread ids persist locally.
- Push delivery, notification permissions, deep links from the OS, and
  server-originated order updates are not wired.
- The bell badge reflects the local fixture list, not unread server events.

## Camera and QR scanning

- The courier receiving flow uses `mobile_scanner`. Widget tests exercise the
  permission-denied, manual-entry and error paths with fakes; they cannot prove
  a real camera opens, focuses, or scans a physical QR code.
- Camera permission prompts and the "Open Settings" recovery path need a
  physical Android/iOS device. An emulator with a synthetic camera is not
  sufficient evidence for the scanning flow.
- Manual code entry remains the fallback when the camera is unavailable or
  permission is denied. The fallback is always on screen, including while the
  camera is still starting.
- The buyer's delivery-pass QR is generated on the phone from the same 6-digit
  code the courier validates. It is not a signed server token, so it proves
  only that the two phones exchanged the code; the server remains responsible
  for verifying the handover. A photographed pass for another order is refused
  locally because the payload carries the job id.
- Live jobs arrive with an empty on-device delivery code (the server keeps only
  its hash), so the buyer's pass card is hidden in live mode until the buyer
  order endpoint exposes the code.

## Buyer tracking map

- In mock mode `MockTrackingRepository` draws a fixed demo route between two
  Pila, Laguna coordinates. It is not the buyer's saved address, not a geocoded
  seller, and not a road route: the rider's position is interpolated along a
  straight line from the milestone, with a 1.35 route factor and a 260 m/min
  ETA. It illustrates progress; it does not navigate.
- Live mode polls only `GET /orders/{sellerOrder}/milestone`, so it has no
  coordinates. `GET /orders/{sellerOrder}/tracking` is specified in
  `docs/api-contract.md` §6.4 but is not implemented, and the live repository
  does not call it. The tracker therefore shows the milestone timeline without
  the map in live mode rather than inventing a position.
- The map is deliberately static (no pan or zoom) because it sits inside the
  tracker's scroll view. Tiles come from the public OpenStreetMap server over
  the network; widget and golden tests replace them with a transparent tile
  provider, so tests prove layout and markers, not real tile rendering.
- The map is hidden before dispatch and for `failedDelivery` /
  `returnToSender`, where a route line would be misleading.

## Checkout vouchers

- The checkout picker is cart-checkout only. Buy Now's lines are not in the
  cart, so the picker is omitted rather than showing a discount those lines do
  not qualify for.
- The picker reuses the wallet's claim/eligibility UI and `CartState` for
  pricing. The app supports stacked vouchers, but the server accepts one
  `voucher_code` on `POST /orders` and the live order repository sends none
  (`docs/api-contract.md` §0.2). A live checkout can therefore display a local
  saving that the server does not record.

## Other mocked or unwired seams

- Returns use `MockReturnRepository` locally; the live `GET /returns`,
  `POST /orders/{sellerOrder}/returns` and `POST /returns/{return}/escalate`
  routes are `notWired`.
- Profile edits, addresses and wishlist/shortlist data are local state. Address
  validation uses the bundled PSGC catalogue; no server address book exists yet.
- Vouchers, reviews, chat and order placement run through mock repositories
  unless the app is built with the live flag and a configured API base URL.
- Golden tests cover the main buyer/courier screens only. Checkout, the address
  form and registration steps have widget tests but no golden baselines.

## Not implemented / mock only (audit findings)

The following features are referenced in `docs/api-contract.md` or other documentation but have **no backend implementation** in any current branch. They are mocked on the mobile side only.

### Authentication extras
- `POST /auth/google` (courier registration path) — the endpoint exists and creates a pending rider, but first-time Google couriers never receive a usable token (pending approval gate). The mobile `notWired('POST /auth/google (courier registration)')` comment in `live_stub.dart` is accurate.
- Password reset / forgot-password — no route exists on either surface. The `api-account-password` limiter is wired for the change-password route; a forgot-password flow would reuse the same 3/min bucket.

### Chat
- `GET /api/chat`, `POST /api/chat`, websocket/push — no controller, no route, no migration. `MockChatRepository` returns fixture messages. The in-app chat UI is purely local.

### Promotions
- No promotions endpoint, no promotions model, no seeder beyond the `Voucher` model. The `PromoCarousel` widget displays static mock data.

### Returns
- `GET /returns`, `POST /orders/{sellerOrder}/returns`, `POST /returns/{return}/escalate` — all `notWired` in the live repository. No backend implementation.

### Buyer tracking coordinates
- `GET /orders/{sellerOrder}/tracking` described in `docs/api-contract.md §6.4` — not implemented. Live mode shows milestone timeline only; the map is hidden.

### Voucher stacking on orders
- `POST /orders` accepts one `voucher_code`. The mobile app supports stacked vouchers locally but the live repository sends none. Any local multi-voucher saving is not recorded server-side.
