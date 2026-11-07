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

## S6.4 cancellation continuation

- `web/app/Enums/OrderStatus.php`: one shared PLACED/CONFIRMED/PREPARING cancellation predicate.
- `web/app/Enums/OrderActor.php`: permit buyer cancellation from CONFIRMED/PREPARING; retain refusal at READY_FOR_PICKUP and later.
- `web/app/Enums/PaymentMethod.php`: recognize existing ewallet/card records for refund accounting; checkout creation remains COD-only.
- `web/app/Services/Buyer/BuyerOrderCancellation.php`: enforce ownership/parent consistency, current status under row locks, and the existing five mobile preset reasons.
- `web/app/Services/Seller/SellerOrderStatusService.php`: atomically record paid cancellation liability; return claims only if unexpired; retain stock, shared-voucher and rollup logic.
- `web/app/Services/Buyer/BuyerAccountDeletionService.php`: pending cancellation liabilities block account deletion.
- `web/app/Http/Controllers/Api/Buyer/OrderActionController.php`: expose the same cancellation predicate in order details.
- `web/app/Http/Resources/Mobile/ShipmentResource.php`: additive `can_cancel` boolean.
- `web/resources/views/Buyer/Orders/show.blade.php`: show cancellation before Ready for Pickup; replace free text with the shared preset list.
- `web/tests/Feature/Buyer/Rule04BuyerCancellationTest.php`: retain full status-matrix assertions with intended CONFIRMED/PREPARING eligibility.
- `web/tests/Feature/Buyer/BuyerOrderCancelTest.php`: confirmed-sibling visibility/HTTP expectations and valid preset fixture, per the architect's override.
- New model/table: `PaymentCancellationRefund` / `payment_cancellation_refunds`, unique payment ID, owner/shipment foreign keys, trusted amount in minor units, pending status. Migration `2026_11_07_062857_create_payment_cancellation_refunds_table.php` is NOT run on the development database.
- New `CancellationRulesTest.php`: native API/web/service reason boundaries, exact prepaid liability, rollback, voucher expiry/use, duplicate database prevention, pending COD and ownership.
- Mobile order model/mapper/mock persistence/state and new `cancellation_rules_test.dart`: preserve server eligibility, hide Ready/unknown cancellation and reject arbitrary reasons.
- No new route or parallel inventory/payment service. Existing auth, role, throttle, policy and transaction boundaries retained.

## Remaining scope after S6.4

Public reviews from other buyers, independent mobile variant-line review forms, remote review photo display/removal, multi-item atomic submission and real MySQL concurrency proof remain open. These were not completed in the source checkout. Mobile ambiguous product variants fail closed. Cancellation was absent from the original consolidation; the S6.4 continuation above now supplies its code and tests.
