# Touched your files

## Courier hub addresses and directions — 2026-11-08

- LinehaulTripResource adds only origin_address/destination_address through native hub fullAddress(), with null for empty legacy addresses. Existing authentication, policy, driver/company scope, throttle, request and trip routes are unchanged. Added assigned-driver/other-driver/empty-address regression to LinehaulDemoTest; no previous assertions changed.
- LinehaulTrip DTO accepts the two optional strings for older API compatibility, refusing malformed types. LinehaulPortal displays addresses in active/history cards and adds Directions to destination for active trips with usable saved addresses. Reuses shared theme/cards/snackbar and existing url_launcher; no new dependency.
- New CourierDirections/DeviceCourierDirections service builds a fixed HTTPS Maps directions URL, encodes hub text, limits URL length and passes no token or customer/current-location data. False/exception is retryable without changing trip status; no app route is popped or pushed by map opening. New linehaul_navigation_test covers URI safety, missing/malformed addresses, 360x640/1.3 layout, launch/retry, pause/resume/no status POST and completed history. No schema, migration, order/payment/stock writes or web frontend changes.

## Courier vehicle and private records — 2026-11-08

- Activation follow-up: owner explicitly approved and installed only the new two-table migration (397.78ms) in verified local zefanya_friend_20261107. API setup_required false; mutex 1, zero submitted profiles, existing Courier count and four batch statuses unchanged. Three 5 MB parts pass real local file validation and oversized parts are refused; deliberately missing required expiry prevented saving in both probes. No fake documents or vehicle records stored.

- New migration `2026_11_08_200000_create_linehaul_profiles.php`: adds `vehicle_plate_locks` (singleton id) and `linehaul_profiles` (id, unique driver FK, unique normalized plate, license number/expiry/codes, vehicle type/capacity, hidden private document metadata, review status/version/reviewer/time/note, timestamps). Activated locally only after the explicit owner exception; no existing business row changes.
- New LinehaulProfile model and LinehaulProfileService: Off Duty/no-active-trip submissions, content/size/expiry/capacity validation, three required private documents, optimistic versions, file cleanup and owning-company review. Approved, nonexpired submitted records are required for readiness; accounts with no submission retain existing presentation access, so this is not mandatory onboarding for all provisioned accounts.
- New VehiclePlateService serializes the two existing Rider creation paths and Courier profile writes through one database row lock; locking reads check normalized plates in both roles. RiderRegistrationService and Logistics RiderController each add one call inside their existing transaction. No Rider registration form, activation, status or notification behavior is redesigned. Real MySQL concurrent-request proof remains open.
- New authenticated API GET/POST `/api/courier/vehicle` and GET `/api/courier/vehicle/documents/{document}`: existing real-role auth/shared 60/min throttle, LinehaulVehicleRequest, LinehaulPolicy and LinehaulVehicleResource/LinehaulDocumentResource. Files are attachment downloads from private storage, no-store/private and nosniff, with no exposed storage path.
- New Logistics GET `/logistics/couriers`, GET/POST `/logistics/couriers/{driver}/vehicle`, GET `/logistics/couriers/{driver}/vehicle/documents/{document}`: web Logistics auth/approval/CSRF, named company 60/min throttle, nullable-driver company policy and LinehaulVehicleReviewRequest/resources; company-scoped list and private review/download controllers/views. Review can confirm/correct capacity and bumps the version if changed. Sidebar adds Couriers separately from Riders; manifest readiness links to records. AppServiceProvider registers only this named limiter.
- LinehaulDutyService adds submitted-record approval/expiry to availability and On Duty checks; existing assignment/accept/depart reuse readiness. Existing trip arrival/handover paths are preserved. Expiry does not falsely report account suspension or alter saved approval history.
- Mobile adds LinehaulVehicleRepository, CourierDocumentPicker and branded LinehaulVehicleScreen; profile screen links to it. ApiClient adds optional in-memory multipart bytes, preserving existing path uploads and restoring unrelated formatter-only changes. `file_selector` 1.1.0 plus its Android/iOS/web implementations enable Files/PDF selection; no existing dependency version was upgraded. Camera uses the existing image_picker package. Images preview privately; PDFs use the device open/share/save flow after authenticated download. No bearer token is put in a URL.
- New LinehaulVehicleTest and mobile vehicle tests; existing profile test changes only its obsolete unavailable-feature copy assertion to the now-backed vehicle entry. No tests/assertions removed or weakened. New tools/serve-local.ps1 uses process-only 5 MB per-file/20 MB request limits and localhost binding; global php.ini/.env are untouched. PHP's current default 2 MB cannot meet the 5 MB acceptance boundary without this launcher/equivalent deployment settings.

## Courier bulk receiving closeout — 2026-11-08

- New POST /logistics/manifests/{manifest}/close-receiving uses existing Logistics web auth/approval/CSRF, a named company-scoped 20/min throttle, LinehaulPolicy closeReceiving gate, new LinehaulCloseReceivingRequest and ManifestCloseoutResource, and feature tests.
- New ManifestCloseoutService compares a locked exact pending snapshot, requires reported arrival/confirmation/note/seal, then reuses native missing reporting atomically. Replays do not duplicate incidents/audits or overwrite evidence. No new table/migration, no canonical order/stock/payment writes.
- ManifestController/receiving Blade show the pending review form only after the assigned Courier reports arrival. Reuse existing scanner confirmation dialog. ManifestAuditLog adds a human-readable closeout label; AppServiceProvider registers gate/limiter. Other route throttles unchanged.
- LinehaulTripResource sorts retained items by ID, keeping tracking/note presentation deterministic after receipt/missing releases. No Flutter source changes. LinehaulCloseReceivingTest covers native integration and preserves existing Courier notes.

## Courier duty/checklist stage — 2026-11-08

- Migration 2026_11_08_180000_add_linehaul_duty_readiness.php adds linehaul_drivers.on_duty (default false), duty_checked_at, last_seen_at, duty_location and the linehaul_duty_checks history table. Applied alone after explicit owner approval (360.52ms); real Courier Off Duty/history empty, existing batches unchanged.
- New LinehaulDutyService, LinehaulDutyCheck, LinehaulDutyRequest and LinehaulDutyResource implement trusted duty/checklist state, bounded location input, locks/freshness, failed-check history and setup-pending refusal. New database-only CourierChecklistFailed notification.
- GET /api/courier/duty and POST /api/courier/duty/{on|off|heartbeat} use existing Sanctum/linehaul/60-per-minute throttle, active-account policy, new request/resource and feature tests. No target driver is accepted from input; no Buyer/Rider position exposure.
- LinehaulDriver casts new guarded fields and exposes latest checklist internally. LinehaulService guards assignment/accept/depart; its original active-trip lock remains. Login and completed-trip annotations do not require On Duty.
- ManifestController and batch details show owning-company readiness, last checklist/update and last reported location; assignment options exclude unavailable/stale drivers. Standalone Courier team roster remains deferred.
- Mobile repository/portal and new courier_location_service add duty state, explicit permission/checklist controls and foreground-only minute updates, with pause/resume/dispose handling. Existing Android foreground permissions retained; iOS location-purpose wording updated. New LinehaulDutyTest and linehaul_duty_test.dart; prior trip assertions retained.

## Courier missing/damaged handover stage — 2026-11-08

- No new table, column or migration. Existing local incident photo/event fields were verified present; demo batches unchanged.
- `ManifestController` permits token-free missing reports only, preserves existing scanned receiving for normal/damaged, exposes seal requirement and retains missing items in receiving lists.
- `ManifestService` requires explicit missing confirmation, reuses `ParcelIncidentService::reportOperational` for Courier evidence/notifications, binds incidents to manifest items with native events, writes issue audits, retains issue completion/timestamps and correct counts. Legacy non-Courier issue behavior retained.
- `parcel-scanner.js` / receiving Blade add multipart damage condition/note/photo controls and explicit missing confirmation; notes/labels are escaped. Images stay in native private storage.
- API action `POST /api/courier/trips/{manifest}/exception-note` added to the existing authenticated/role-guarded/60-per-minute action route; existing LinehaulRequest/policy/resource and feature tests enforce scope. Native incident events store immutable Courier note + actual actor.
- `LinehaulTripResource`, mobile repository and portal retain missing/damaged parcel outcomes, show issue labels and Courier's own note, and provide note entry. Backend and narrow-screen mobile regressions added; existing assertions retained.

## Courier seal receiving stage — 2026-11-08

- New nullable column `linehaul_assignments.seal_receipt` in `2026_11_08_120000_add_seal_receipt_to_linehaul_assignments.php`; local migration applied alone after explicit user approval (103.01ms). No existing assignment/trip advanced.
- New `LinehaulSealService` validates and stores first seal confirmation under the native manifest lock; mismatch notes/audit/database notification, with setup-pending refusal before migration.
- New database-only `CourierSealDiscrepancy` notification; no mail/push or new routes.
- `LinehaulAssignment` casts the guarded receipt JSON. `ManifestService` gates normal/missing/damaged receiving and preserves seal discrepancies on completion; existing non-Courier manifests unchanged.
- `ManifestController` passes trusted request context and seal receipt to the existing receiving page. `parcel-scanner.js` renders seal/note fields from the server action; receiving Blade shows confirmation/discrepancy and closed-with-issues copy.
- `LinehaulDemoTest` adds/refines receiving seal assertions without removing prior assertions. No Buyer/Rider mobile changes, stock/payment writes or existing demo trip changes.

- Courier rehearsal fix: `web/app/Http/Resources/Mobile/LinehaulTripResource.php` retains received parcels after native receiving releases them, so completed history reports the actual received count. Removed/unreceived items stay excluded. Full-trip regression strengthened; no schema or route changes.

## Courier design integration — 2026-11-08

- Final navigation regression fix: keep trip observation mounted on Account and immediately observe reload failures before the next frame; the original future still feeds the visible error/Retry panel. Added the background-refresh failure test. Final full mobile 886 pass; focused 20 pass; analyzer unchanged at eight existing findings.
- mobile/lib/screens/courier/linehaul_portal.dart: Zefanya-branded login, shared fonts/colors/shapes, Material route cards with expandable parcels, shared loading/empty/error states, floating Trips/History/Account navigation and authenticated profile. Server trip actions unchanged; errors use existing timed feedback and pull-to-refresh retains error handling. History is the existing recent server list, not a new full-history API.
- mobile/test/linehaul_test.dart: preserve all existing refusal/state assertions; allow post-scroll layout frames and timed-feedback teardown. New mobile/test/courier_design_test.dart verifies navigation/status filtering and both-theme small-screen layouts, and can render ten optional QA images into ignored build output. No dependencies, PHP, database or golden baseline changes.

## Combined Courier demo — 2026-11-08

- Approved local setup follow-up: existing ignored backend config copied, only the new Courier migration applied, one Courier provisioned for Demo Laguna Express/Pila Station Hub. Real HTTP account/role/CORS checks passed; no existing orders/stock/payments changed. Initial "not migrated" checkpoint notes below are superseded only by this explicit user exception. No configuration or credentials committed.
- New Models/Logistics/LinehaulDriver and LinehaulAssignment: separate Courier authentication; encrypted/hidden scan tokens. Migration 2026_11_07_170547_create_linehaul_drivers_and_assignments_tables creates linehaul_drivers (id, logistics_provider_id FK, home_hub_id FK, name, unique email, password, status default inactive, nullable email_verified_at, timestamps) and linehaul_assignments (id, unique manifest_id FK, linehaul_driver_id FK, nullable accepted_at/arrived_at/seal_number/loading_tokens, timestamps). No existing table changes; not migrated on dev.
- New Services/Logistics/LinehaulService: company-owned assignment, driver/manifest locks, one active trip, acceptance, native scans/dispatch and reported arrival. Existing Logistics receiving alone completes the trip. Actual actor events append to manifest_audit_logs.
- New Policies/LinehaulPolicy; Requests/Api/LinehaulLoginRequest and LinehaulRequest; Requests/Logistics/LinehaulAssignRequest; Controllers/Api/LinehaulController; Resources/Mobile/LinehaulTripResource. Policy/request/resource-backed adapters; explicit generic JsonResource for session/profile projections. Login intentionally public/throttled. No passwords/verification tokens returned.
- web/routes/api.php: POST /courier/login (api-login throttle), plus authenticated api.role:linehaul GET /courier/profile, POST /courier/logout, GET /courier/trips and POST /courier/trips/{manifest}/{action}, constrained accept|scan|depart|arrive (60/min). No Courier receiving, pay or provisioning API.
- ApiRole.php, Api/Traits/ValidatesRoleAbilities.php: explicit linehaul model/ability and operational hub/company checks, preserving Buyer/Rider/wind-down behavior. AppServiceProvider registers linehaul-access/assign gates.
- web/routes/logistics.php adds approved-Logistics POST /manifests/{manifest}/courier (20/min). ManifestController delegates assignment and loads company-owned choices. Logistics/Manifests/show.blade.php displays assignment/acceptance/arrival/seal and retains receiving. Missing new tables leave old manifest pages usable.
- New Console/Commands/ProvisionLinehaulDriver: local/testing-only linehaul:provision, explicit company/hub/unused email and prompted strong password. No overwrite, public registration or automatic execution; activation/document onboarding deferred.
- New Feature/LinehaulDemoTest: auth/model/policy/tenant isolation, one active trip, throttling, duplicate actions, acceptance/seal/all-scan guards, encrypted/expired scans, native receiving, provisioning/no-overwrite/production refusal.
- Mobile auth_state, auth_repository, main/AuthGate and login_screen: distinct persisted linehaul role and live-only Courier entry; preserve Rider role; hide unrelated startup notices in Courier. New LinehaulRepository DTO/transport and courier/linehaul_portal: server states, loading/errors/Retry, accept/load/depart/reported arrival/logout; no optimistic completion or invented earnings.
- Shared courier_qr_scanner: optional Code128 and actual input-method callback, preserving Rider defaults. New linehaul_test plus extended auth_gate_routing_test verify transport/auth, small-screen server refusals, Retry and three-role logout/Back; existing assertions retained. Dart formatting applied to new files; PHP formatter waived/unrun.

## S6.2 checkpoint — quote, reference shipping and reviewed total

- New authenticated `POST /api/v1/buyer/checkout/quote`: provisional product/variant/quantity lines, optional owned address/carriers and voucher codes. Sanctum buyer role, 20/min throttle, `CheckoutPolicy`, `CheckoutPreviewRequest`, `CheckoutPreviewResource`, `CheckoutPreviewController` and `SpecCheckoutQuoteTest` cover ownership, 401/403/422/429 and success. Preview creates no cart, token, claim, order, payment or inventory records.
- New `web/app/Services/Buyer/CheckoutPreviewService.php`: unsaved native CartItem adapter; delegates prices, stock, address choice, vouchers and operational routes to the existing services. No second pricing formula.
- New `web/app/Models/Shared/ShippingRate.php`, migration `2026_11_07_071238_create_shipping_rates_table.php` and `SpecShippingRateSeeder`: table `shipping_rates` has id, origin_zone, destination_zone, first_kg_minor, additional_kg_minor, created_at, updated_at; unique origin/destination pair. Four reference cards, idempotent without overwriting existing rates. Neither migration nor seeder was run on the development database.
- `web/app/Services/Logistics/ShippingRateService.php`: provider overrides retain priority; recognized NCR/Visayas reference cards join native rate snapshots. Existing fallback remains when reference cards are absent.
- `web/app/Services/Buyer/CheckoutCarrierService.php`: seller/destination zone context for reference-rate fallback, retaining operational coverage, handoff/connection feasibility, per-store ceil-kg billing and frozen checkout snapshots.
- `web/app/Services/Buyer/VoucherApplication.php`: platform centavo remainders use largest fractional shares, stable seller-ID ties and existing capacity ceilings. Worked PLAT100 shares are 6366/3634, not 6367/3633.
- `web/app/Services/Buyer/CheckoutService.php`: optional reviewed-total comparison after locked prices/vouchers/shipping are rebuilt and before order writes; conflict rolls back token consumption and all business effects.
- `web/app/Http/Controllers/Api/Buyer/CheckoutController.php`: validate/parse optional decimal expected_total to integer cents and pass to the transaction. Existing token/fingerprint API retained for compatible placement; preview uses the new read-only endpoint.
- `web/app/Providers/AppServiceProvider.php`, `web/routes/api.php`: register quote ownership policy and additive versioned preview route.
- `web/tests/Feature/Buyer/PlatformVoucherAllocationTest.php`: intended balanced-rounding expectation changes recorded in DECISIONS.md; sum/capacity/order/placement assertions retained. New SpecCheckoutQuoteTest verifies worked total, absence of business writes, authorization, operational serviceability, stock, throttling, forged values, voucher replacement, overrides/snapshots and atomic expected-total handling.
- `mobile/lib/data/repositories/order_repository.dart`: quoteTotal performs one read-only provisional-line request; place sends the reviewed decimal expected_total in addition to existing native token/fingerprints. `mobile/test/live_orders_test.dart` asserts that preview performs no cart synchronization and placement sends the expected total.
- Pending in S6.2: full server breakdown on checkout, 409 summary refresh, native claimable/claim/wallet APIs and live wallet transport, voucher creation code-format alignment and full workbook eligibility coverage. This checkpoint does not mark S6.2 DONE or advertise these features as implemented.

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
