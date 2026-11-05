# Limitations for presentation — S3.5

One-page reference for questions during the demo. Each item is either
documented in `docs/KNOWN_LIMITATIONS.md` or is a known gap that will be
addressed after the presentation.

## Push notifications

**Not implemented.** No notifications endpoint exists in the Laravel API.
The inbox is seeded fixture data (`kSeedNotifications`); only read/unread ids
persist locally. Push delivery, notification permissions, deep links from the
OS, and server-originated order updates are not wired. The bell badge reflects
the local fixture list, not unread server events.

**If asked:** "Notifications are a documented limitation. The inbox UI works
with local fixtures; server-sent push requires the notifications endpoint,
scheduled for after the presentation."

## Deep links

**Partially implemented.** Android app links are declared for
`https://zefanya.test/buyer/products/` and the custom scheme `zefanya://product`
is the fallback. Assetlinks verification against the host is not configured,
so deep links may not auto-verify on first install.

**If asked:** "Deep links are declared but not yet verified via assetlinks.
Product sharing works via the custom scheme as a fallback."

## Real payment gateway

**Not implemented.** No payment gateway route exists on either surface.
`POST /orders` accepts COD only. E-wallet/card payment is a documented
limitation.

**If asked:** "Real payment (e-wallet/card) is not yet integrated. COD is the
only payment method; the gateway is on the post-presentation roadmap."

## Chat

**Mock mode only.** `GET /api/chat`, `POST /api/chat`, websocket/push have no
controller, route, or migration. `MockChatRepository` returns fixture messages.
The in-app chat UI is purely local. Chat icons are hidden in live mode
(`FeatureFlags.chatLive = false`).

**If asked:** "Chat is a local mock only — no backend yet. It's hidden in live
mode so users never see a broken feature."

## Flash-sale creation

**Not implemented.** No flash-sale creation endpoint exists. The `PromoCarousel`
widget displays static mock data. No promotions model or seeder beyond the
`Voucher` model.

**If asked:** "Flash-sale creation is not built yet. The promo carousel shows
mock data; the server-side promotion system is planned for after the demo."

## Live tracking (map)

**Not implemented.** `GET /orders/{sellerOrder}/tracking` described in
`docs/api-contract.md` §6.4 is not implemented. Live mode shows milestone
timeline only; the map is hidden. The delivery map (`flutter_map`) is in
pubspec but disabled for `failedDelivery`/`returnToSender`.

**If asked:** "Live map tracking is not yet implemented. The milestone timeline
works; the map view is a post-demo item."

## Other documented limitations

See `docs/KNOWN_LIMITATIONS.md` for the full list: Google sign-in configuration,
delivery and COD confirmation, camera and QR scanning, checkout vouchers, and
other mocked or unwired seams.

**If asked about anything else:** "That's a documented limitation — see
KNOWN_LIMITATIONS.md for the complete list."
