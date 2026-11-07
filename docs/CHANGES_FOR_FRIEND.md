# Touched your files

- Mobile main/watcher/banner: await portrait; live-only `/buyer/products` probe; reactive offline Retry, coalesced requests.
- Buyer category/product layouts: scaled text can grow/wrap without overflow.
- OTP screen: one-time-code autofill.
- Build configuration/example: canonical `USE_LIVE_API`; ignore Android Kotlin cache.
- Tests: real small-screen tab flows, Retry, dirty Back, logout Back, repository mode selection.
- Buyer shell/auth tests: reload orders on authenticated entry following startup 401.
- Order state: preserve existing checkout quote interface cast fix.
- Public storage CORS/tests: allow public images across Flutter web origins; exclude private documents.
- Public storage assets: user's restored products, banners, logos and thumbnails.

## Native review adaptation

- `web/app/Models/Shared/SellerOrder.php`: completion time reads existing trusted COMPLETED transition history; no new column.
- `web/app/Models/Buyer/Review.php`: one-edit window starts at trusted completion, exact day 30 excluded.
- `web/app/Services/Buyer/ProductReviewService.php`: completion-window and content validation; native shipment adapter locks buyer/item and reuses existing create/edit operations.
- `web/app/Http/Requests/Buyer/StoreProductReviewRequest.php`: JPG/PNG only, up to 5120 KB.
- `web/app/Http/Requests/Buyer/UpdateProductReviewRequest.php`: same file limits for edits.
- `web/app/Http/Resources/Mobile/ShipmentResource.php`: additive completed_at from history.
- `web/app/Policies/SellerOrderPolicy.php`: explicit buyer ownership ability for mobile review writes.
- `web/routes/api.php`: authenticated buyer GET /buyer/reviews/mine and PATCH /buyer/shipments/{shipment}/products/{product}/review, throttled with native API role middleware.
- `web/resources/views/buyer/products/_reviews.blade.php`: display actual completion-window and JPG/PNG/5 MB rules.
- `web/tests/Feature/Buyer/Rule08ProductReviewsTest.php`: trusted-history fixture and intended exact-day-30 refusal.
- `web/tests/Feature/Buyer/BuyerShipmentApiTest.php`: verify trusted completion history is created and exposed after actual receipt confirmation.
- New `ReviewPolicy`, `Api/Buyer/MobileReviewRequest`, `Api/Buyer/ReviewController`, `Mobile/ReviewResource`, `Buyer/MobileReviewApiTest`: policy/request/resource-backed native adapters and endpoint tests.
- No new table, column or parallel review service. Existing status transitions, inventory and payment services retained.
- Mobile review/order models, persistence, mappers, form and button: trusted completion date and server edit count; multipart bytes to native review route. Buyer entry refreshes reviews after signed-out startup.
- Test fixtures: preserve catalogue/review assertions; use completed purchases. UTF-8 mock response fixes the pre-existing peso-symbol encoding failure.
- Added direct ownership-policy and ambiguous-variant regression tests; all five deliberately broken security guards were restored and retested before committing.

## Remaining scope

Public reviews from other buyers, independent mobile variant-line review forms, remote review photo display/removal, multi-item atomic submission and real MySQL concurrency proof remain open. These were not completed in the source checkout. Mobile ambiguous product variants fail closed. Cancellation stages were not implemented in the source checkout; this consolidation adds none.
