    # Zefanya — Full System Audit (read-only)

    **Date:** 2026-10-30 · **Branch:** `feature/sanctum-api-auth` (HEAD `6b592615`) · **Scope:** whole working tree, including uncommitted and untracked files
    **Method:** code reading (routes → middleware → controller → service → DB → view/JS), plus the existing automated suites. No application code, config, routes, views, tests or migrations were changed. This report is the only file created.

    > **Status legend:** WORKING = code path read end-to-end **and** covered by a passing automated test · PARTIAL = works but incomplete, or missing UI/steps · MISSING = no implementation · BROKEN = confirmed defect or dead end · CONFLICT = code and documentation disagree · UNVERIFIED = code exists but there is no test and it was not executed.
    > Nothing here was verified in a real browser or on a phone. "WORKING" means automated HTTP/kernel tests, not live UI.

    ---

    ## 0. Requirement sources

    | Source | Result |
    |---|---|
    | Official black-box test-case workbook (`*.xlsx`/`*.docx`) | **Not found.** Searched `C:\Web\Zefanya` (excluding `vendor`/`node_modules`) plus `Documents`, `Desktop` and `Downloads` under the user profile. The only `.docx` is a test fixture (`tests/fixtures/rider/price_list.docx`). |
    | Implementation plan / "Zefanya project rules, sections 1–34" (cited in `WORKFLOW_GUIDE.md`) | **Not found** in the workspace. |
    | Docs available | `docs/1-Context.md`, `2-Design.md`, `3-Folder-Structure.md`, `4-Flow.md`, `5-Barangay-Coverage.md`, `P0-Regression-Baseline.md`, `WORKFLOW_GUIDE.md`, `PLAYWRIGHT_FUNCTIONAL_AUDIT.md`, `storage/logs/phase1-readiness.md` |

    Because the workbook is missing, **the Requirement IDs in this report (`B-xx`, `S-xx`, `L-xx`, `R-xx`, `A-xx`) are internal audit IDs, not official test IDs.** `storage/logs/phase1-readiness.md` names official IDs (SEL-ORD-002/003, BUY-PAY-001, BUY-TRK-002/005, BUY-CAN-001/004/005/006, BUY-RET-001/003, SEL-EARN-005/006/007), but without the workbook I could not check them against their expected results.

    **Documentation is badly stale.** `docs/1-Context.md` says Sanctum is not installed, that commission and shipping fees are missing, and that rider reviews are missing. The code now has Sanctum token auth, a shipping/carrier service, 5% commission finalization and product reviews. Treat the four canonical docs as historical until they are rewritten.

    ---

    ## A. Executive summary

    ### Overall status
    The core **COD commerce loop works and is heavily tested**. It runs: Buyer checkout → Seller accept/pack/ready → Logistics pickup dispatch → Rider QR pickup → hub receipt and sorting → delivery assignment → Rider proof of delivery with COD collection → Buyer confirmation (or 168-hour auto-completion) → Logistics COD remittance confirmation → settlement with commission finalized. Failed delivery (two attempts) → return to seller is also complete. Registration/OTP/Admin approval works for Buyer, Seller and Logistics. Rider registration/OTP/Logistics approval works too.

    Automated evidence collected today:

    | Suite | Result |
    |---|---|
    | PHPUnit Unit + Feature (SQLite in memory) | **2,231 tests: 2,230 passed, 1 skipped, 0 failed**, 14,329 assertions, 195 s |
    | PHPUnit MySQL concurrency (`phpunit.mysql.xml`, dedicated `zefanya_concurrency_test` DB) | **51 tests: 51 passed**, 726 assertions |
    | Node JS unit tests (`tests/js/*.test.mjs`) | **24 tests: 23 passed, 1 FAILED** |
    | Node frontend (`tests/Frontend/CoverageLookupTest.cjs`) | 4 passed |

    ### Major risks (blocking for a capstone demo)
    1. **About 100 core files are untracked in git**, including 16 migrations, the payout, voucher-session, platform-settings, 2FA, store-category and product-restriction code, and `SellerRegistrationDecisionService`. Another 150 tracked files have uncommitted changes. One `git checkout`/`stash`/reclone mistake would erase most recent work. (`git status --short`: 254 entries, 103 untracked.)
    2. **Two migrations are pending on the local MySQL database**: `2026_10_29_000000_add_two_factor_columns_to_admins_table` and `2026_10_29_000001_create_admin_document_access_logs_table`. `Admin\DocumentController::stream()` writes `DocumentAccessLog::record(...)`, so **Admin ID-document viewing will fail on the real DB** until these run. Admin 2FA enrolment will also fail.
    3. **Seller payout requests can never be fulfilled.** A Seller can request and cancel a payout. No Admin route, controller or status exists to approve or pay a request (`SellerPayoutRequest` has only `PENDING`/`CANCELLED`). `SellerSettlementService::confirmPayout()` deliberately refuses settlements reserved by a pending request. Flow 6 stops at "requested".
    4. **Admin settlement has no UI.** `admin/settlements*` routes return raw JSON (`Admin\SellerSettlementController`), and no view or sidebar entry links to them.
    5. **Stored XSS (code-confirmed, browser-unverified).** The Buyer notification dropdown (`resources/js/buyer/navbar.js:113-121`) builds HTML from notification `title`/`message` without escaping. `SellerOrderCancelled` puts the Seller-controlled `business_name` into `message`, and a 29-character payload fits the 30-character store-name limit.
    6. **A delivered-order Buyer return is a dead end.** The request is recorded, but there is no reverse pickup from the Buyer. `Delivered` is closed for manifests and handoffs, and no Seller/Admin accept/reject step exists. The open return also blocks completion, so that shipment never completes and the Seller is never paid.
    7. **The Flutter (Sanctum) API covers authentication only.** Buyer: login/profile/logout. Rider: login/profile/logout plus registration/OTP. There are no catalogue, cart, checkout, order, assignment, scan, POD, COD or failed-delivery endpoints, so a Flutter app cannot do any business action.

    ### Biggest missing functionality
    Voucher administration (no Admin or Seller can create a voucher, only the factory can). Seller Vouchers/Promotions and Reports pages (stubs). Admin payout processing and settlement UI. Admin order and financial oversight. Buyer follow-store. Buyer address book (single profile address only). Mobile API business endpoints. Refund mechanism for returns. Rider incident reporting UI.

    ### Capstone readiness
    **Conditionally demo-ready for the happy-path COD flow, cross-province handoff and failed-delivery return**, provided that P0-01…P0-05 are done first: commit the work, run the pending migrations, seed vouchers, fix the XSS, and prepare a scripted demo path. **Not ready** to demo payouts end-to-end, buyer returns after delivery, vouchers created through the UI, Seller reports, or a Flutter app.

    ---

    ## B. Role-by-role audit tables

    ### B.1 Buyer

    | ID | Feature | Status | Evidence | Problem | Recommended fix | Priority |
    |---|---|---|---|---|---|---|
    | B-01 | Registration + email OTP (signed URLs, throttles, lockout) | WORKING | `Buyer/RegisterBuyerController`, `VerifyBuyerOtpController`, `HasEmailOtp`, `VerifiesEmailOtp`; `BuyerRegistrationTest`(64), `OtpSecurityTest`, `OtpLockTest`, `RegistrationEmailConsistencyTest`(54), MySQL `RegistrationEmailConcurrencyTest` | — | — | — |
    | B-02 | Admin approval/rejection of Buyer | PARTIAL | `Admin/RegistrationController::approveBuyer/rejectBuyer` (lines ~302-338) | Not atomic (no transaction/row lock); **no `AccountActionHistory` row**; notification not deferred to commit. Seller path does all three (`SellerRegistrationDecisionService`). | Move the Buyer decision into a service that mirrors `SellerRegistrationDecisionService` | P1 |
    | B-03 | Login / logout / single-browser account | WORKING | `Auth/UnifiedLoginController`, `ClearsOtherGuards`, `EnforceSingleBrowserAccount`; `SingleBrowserAccountTest`(26), `CrossRoleSessionIsolationTest`(28) | — | — | — |
    | B-04 | Suspension enforcement (web) | WORKING | `EnsureBuyerActive`, `UserAccountController::setStatus` + `AccountSessionService::revoke`; `AdminUserAccountTest`, `UserAccountControllerSessionRevocationTest` | `EnsureBuyerActive` calls `setRememberToken(null)` but never saves (Low; Admin suspension already clears it) | Add `saveQuietly()` | P3 |
    | B-05 | Profile / password | WORKING | `Buyer/AccountController`; `BuyerAccountPagesTest`(10) | — | — | — |
    | B-06 | Address management | PARTIAL | `AccountController::addressEdit/addressUpdate`; one address on `buyers` row; no addresses table | Only one profile address; no address book or per-order address choice | Accept as MVP, or add a `buyer_addresses` table and a checkout picker | P2 |
    | B-07 | Browse / categories / search / filters / pagination | WORKING | `HomeController`, `CategoryController`, `SearchController`; `BuyerCatalogEligibilityTest`, `CategoryControlsTest`, `SearchPaginationTest`, JS `buyer-search`/`category-controls` | — | — | — |
    | B-08 | Product detail + variants | WORKING (JS label regression) | `ProductController`, `CartVariantResolver`; `BuyerVariantFlowTest`, `PurchasedVariantSnapshotTest` | JS test `product-availability.test.mjs` fails: uncommitted `resources/js/buyer/product-show.js:106-107` shows "Sold Out", test expects "Out of Stock" | Decide the label; make code and test agree (don't edit the test just to pass) | P1 |
    | B-09 | Wishlist | WORKING | `WishlistController`; `WishlistAvailabilityTest`(18) | — | — | — |
    | B-10 | Follow store | MISSING | No model, migration or route containing "follow" | — | Add `store_follows` + toggle route + store-page button | P3 |
    | B-11 | Cart + Buy Now | WORKING | `CartController::add/buyNow` (seller approved, restriction, vacation, row locks); `CartControllerTest`(22), `BuyNowTest` | — | — | — |
    | B-12 | COD checkout (single transaction, token, locks, server shipping) | WORKING | `CheckoutController::placeOrder` (`CheckoutToken::claim`, `lockForUpdate`, re-validation); `CheckoutDoubleSubmitTest`(17), `CheckoutFinancialIntegrityTest`(17), MySQL `CheckoutConcurrencyTest`(12), `InventoryOversellingConcurrencyTest` | — | — | — |
    | B-13 | Vouchers / discounts (apply at cart/checkout) | PARTIAL | `VoucherApplication`, `VoucherSessionService`, `CartController::applyVoucher`; `VoucherUsageTest`(1) | Applying works, but **nothing in the app creates vouchers** (only `database/factories/VoucherFactory.php`). Demo depends on manual DB rows. Only one usage test. | Admin voucher CRUD (A-08) and/or a demo seeder | P1 |
    | B-14 | Order tracking / timeline | WORKING | `Buyer/OrderController::show`; `BuyerTrackingPresentationTest`(19) | — | — | — |
    | B-15 | Buyer cancellation + stock restore | WORKING | `OrderController::cancelSellerOrder`; `Rule04BuyerCancellationTest`(21), `BuyerOrderCancelTest` | Voucher restoration after cancellation has no policy (per readiness report) | Decide policy | P2 |
    | B-16 | Confirm Received / auto-completion (168 h) | WORKING | `OrderController::confirmReceived`, `AutomaticBuyerReceiptService`, scheduled every 5 min (`routes/console.php`); `AutomaticBuyerReceiptTest`(12), `Rule05…`(19) | Auto-completion only runs if the scheduler is running | Run `php artisan schedule:work` during the demo | P2 |
    | B-17 | Return request (7-day window) | PARTIAL | `OrderController::requestReturn` → `ParcelReturnService::requestByBuyer`; `Rule05…` | Intake works; nothing physically moves the parcel back (see Flow 8). No Seller/Admin accept/reject. No refund. | See F-03 | P1 |
    | B-18 | Product reviews (+photos, seller reply) | WORKING | `ReviewController`, `ProductReviewService`; `Rule08ProductReviewsTest`(13) | — | — | — |
    | B-19 | Messages (buyer↔seller) | UNVERIFIED | `Buyer/MessageController`, `Seller/MessageController` | No automated test found for messages | Add feature tests | P2 |
    | B-20 | "Chat" page | PARTIAL | `Buyer/ChatController` redirects "Chat is coming soon" | A duplicate entry point next to the working Messages | Point Chat links at Messages, or remove them | P3 |
    | B-21 | Notifications centre/bell | BROKEN (security) | `Buyer/NotificationController`, `resources/js/buyer/navbar.js:113-121` | Unescaped `innerHTML` (see E-01). No tests. | Escape output; add tests | **P0** |
    | B-22 | Buyer API (Flutter) | PARTIAL | `routes/api.php`: login/profile/logout only; `BuyerRiderApiAuthenticationTest`(31) | Auth works. **No business endpoints. No API registration/OTP for buyers.** | See F-06 | P1 (if Flutter buyer app is in scope) |

    ### B.2 Seller

    | ID | Feature | Status | Evidence | Problem | Recommended fix | Priority |
    |---|---|---|---|---|---|---|
    | S-01 | Registration + OTP + Admin decision | WORKING | `RegisterSellerController`, `SellerRegistrationDecisionService` (lock + history + afterCommit); `SellerRegistrationTest`(83), `SellerRegistrationDecisionTest`(41), MySQL decision race(4) | — | — | — |
    | S-02 | Store profile, logo, banner | WORKING | `Seller/AccountController`, `SellerStoreProfileService`, `UpdateStoreProfileRequest` (image + mimes); `StoreProfileAcceptanceTest`(54), `StoreProfileTest` | Store name has no character whitelist (feeds E-01) | Fix at the output (E-01); optionally restrict characters | P0 (via E-01) |
    | S-03 | Vacation mode → blocks buying | WORKING | `SellerVacationService`, `Product::isPurchasable`, checkout re-check under lock; `SellerVacationModeTest`(20), MySQL `SellerVacationConcurrencyTest` | — | — | — |
    | S-04 | Products: create, draft, edit, publish, duplicate, bulk, images | WORKING | `Seller/ProductController`, `ProductPublishingService`; `SellerProductManagementTest`(79), `ProductPublishingTest`, `ProductEditingSafetyTest` | — | — | — |
    | S-05 | Categories (store categories) + variations + inventory ledger | WORKING | `StoreCategoryService`, `VariantSyncService`, `StockService`; `StoreCategoryManagementTest`(31), `Rule06VariationLimitsTest`, `InventoryManagementTest`(34), `SellerStockLedgerTest` | `/seller/inventory` only redirects to products | Acceptable | P3 |
    | S-06 | Low-stock alerts | PARTIAL | `InventoryAlertService` (on `InventoryTransaction::created`); `LowStockAlertTest`(14), MySQL(2) | Alerts are created, but Sellers only see them in a read-only dashboard bell (`Components/seller/notification-bell.blade.php`, JS has no fetch/mark-read). No Seller notifications page or route; the unread count never clears. | Add `seller/notifications` index + mark-read routes | P1 |
    | S-07 | Orders: Accept → Pack → Ready for Pickup; Decline | WORKING | `Seller/OrderController`, `SellerOrderStatusService::transition`; `Rule02OrderStatusFlowTest`(11), `SellerOrderDeclineTest`, `MarkReadyDoubleSubmitTest`, `FulfillmentLifecycleTest` | — | — | — |
    | S-08 | Shipments + printable waybill/QR | WORKING | `ShipmentController` (policy), `WaybillQrCode`; `WaybillQrCodeTest`(9) | — | — | — |
    | S-09 | Shipping-provider preferences | WORKING | `SellerShippingProviderService`; `SellerShippingProvidersTest` | — | — | — |
    | S-10 | Promotions / vouchers | MISSING | `routes/seller.php:80-82` closure → `Seller/Vouchers/index.blade.php` (165 bytes, heading only) | Seller-funded voucher accounting exists (`VoucherFundingService`) but Sellers cannot create vouchers | Seller voucher CRUD scoped to own products | P1 |
    | S-11 | Earnings, commission | PARTIAL / CONFLICT | `CommissionFinalizationService::COMMISSION_BPS = 500` (5%); `Rule10MoneyRoundingTest`(21), `CodRemittanceFinancialWorkflowTest` | Code says **5%**; `docs/1-Context.md:225,295` says **10%**. No earnings report page beyond the payout screen. | Owner confirms the approved rate (no code change until then) | P1 (decision) |
    | S-12 | Payout requests | BROKEN (flow) | `PayoutRequestController`, `SellerPayoutRequestService` (PENDING/CANCELLED only); `SellerPayoutRequestTest`(46), MySQL `SellerPayoutConcurrencyTest`(5) | Request/cancel works, but no Admin can approve or pay it (see A-06) | See P0-04 | **P0** |
    | S-13 | Returns: verify + receive (physical return) | WORKING (failed-delivery path) | `Seller/ReturnController`, `ParcelReturnService::verifySeller/receiveBySeller`; `PhysicalReturnTest`(30) | Delivered-return path never reaches the Seller (Flow 8) | — | — |
    | S-14 | Feedback (reviews + replies) | WORKING | `Seller/FeedbackController`; `Rule08…` | — | — | — |
    | S-15 | Reports | MISSING | Closure → `Seller/Reports/index.blade.php` (170 bytes, heading only) | — | Sales/earnings report from `seller_orders`/settlements | P2 |
    | S-16 | Seller "Chat" page | PARTIAL | Closure → 153-byte stub; Messages work (`Seller/MessageController`) | Duplicate entry point | Remove or redirect to Messages | P3 |
    | S-17 | Account restriction (suspension) / product restriction | WORKING | `EnsureSellerApproved`, `Product::isListedForSale` (`restriction_reason`); `AdminProductOversightTest`(27), `CheckoutSellerEligibilityTest` | — | — | — |
    | S-18 | Dashboard stats | WORKING | `DashboardStatisticsTest`, `SellerDashboardHeroTest` | — | — | — |

    ### B.3 Logistics Provider

    | ID | Feature | Status | Evidence | Problem | Recommended fix | Priority |
    |---|---|---|---|---|---|---|
    | L-01 | Registration + OTP + Admin decision (+ province verification) | PARTIAL | `RegisterLogisticsController`, `RegistrationController::approveLogistics/rejectLogistics`; `LogisticsRegistrationTest`(24), `ProviderProvinceGovernanceTest` | Same as B-02: no transaction or lock, **no AccountActionHistory**. `verifyProvinceOnApproval()` and the status update are separate writes. | Service with lock + history + afterCommit | P1 |
    | L-02 | Station Hubs + barangay coverage + Admin hub approval | WORKING | `HubsController`, `LogisticsHubService`, `Admin/HubApprovalController`; `HubsTest`(25), `BarangayCoverageTest`(27), `HubApprovalTest`(14) | — | — | — |
    | L-03 | Delivery areas | WORKING | `AreaController`; `AreaManagementTest`(14) | — | — | — |
    | L-04 | Pickup requests + automatic/manual rider dispatch | WORKING | `IncomingParcelsController`, `CourierAssignmentService::dispatch`; `AutomaticDispatchTest`(20), `PickupDispatchConfirmationTest`(12), MySQL `AutomaticDispatchConcurrencyTest`(7, all pass now) | — | — | — |
    | L-05 | Receiving, sorting, address verification | WORKING | `ParcelSortingController`; `ParcelSortingTest`(12), `IncomingParcelsTest`(23) | — | — | — |
    | L-06 | Manifests (internal) + scan/receive | WORKING | `ManifestController`, `ManifestService`; `InternalManifestFlowTest`(9), `InternalManifestDatabaseTest` | — | — | — |
    | L-07 | Delivery assignment/reassign/reschedule/return | WORKING | `DeliveryAssignmentsController`; `DeliveryAttemptPolicyTest`(10), `RiderReassignmentTest`(27) | — | — | — |
    | L-08 | Tracking + custody scans | WORKING | `ParcelScanController`, `ParcelTrackingVerificationService`; `ParcelScanTest`(10), `F2ScanEnforcedLifecycleTest` | — | — | — |
    | L-09 | Cross-provider / cross-province handoffs + partnerships | WORKING | `HandoffController`, `ParcelHandoffService`, `PartnershipController`, Admin partnership approval; `CrossProviderHandoffTest`(12), `CrossProviderHandoffQrTest`(10), `CrossProviderOperationsTest`, `F2HandoffAuthorizationTest`, `PartnershipApprovalTest` | — | — | — |
    | L-10 | Rider applications + management | WORKING | `RiderApplicationController` (JSON, `authorizeProvider`), `RiderController`; `RiderManagementTest`, `MobileRiderRegistrationTest`(54) | Rider-application review is a JSON API under the logistics session (`routes/logistics.php:149`); confirm the Riders screen actually calls it (browser-unverified) | Browser check | P2 |
    | L-11 | Delivery incidents (lost/damaged) | UNVERIFIED | `DeliveryAssignmentsController::reportIncident`, `ParcelIncidentService`, `Admin/IncidentController` | **No test class covers incidents** | Add feature tests | P1 |
    | L-12 | Failed delivery → return-to-sender (reverse logistics) | WORKING | `ParcelReturnService` + `PhysicalReturnTest`(30), MySQL `PhysicalReturnConcurrencyTest` | — | — | — |
    | L-13 | Delivered Buyer return reverse logistics | BROKEN | `ManifestService.php:712` (Delivered treated as terminal), `SellerOrder::isClosedForHandoff()` (`SellerOrder.php:196-204`), `ParcelReturnService::requestByBuyer` | No collection from the Buyer; the parcel can't enter reverse transport | See F-03 | P1 |
    | L-14 | COD remittance confirmation → settlement prep | WORKING | `CodRemittanceController`, `CodRemittanceService::confirmReceipt` → `SellerSettlementService::prepare`; `CodRemittanceFinancialWorkflowTest`(7), `CodRemittanceCreationTest` | Logistics → platform cash transfer is not modelled (the platform never "receives" the cash) | Policy decision | P2 |
    | L-15 | Notifications | WORKING | `Logistics/NotificationController`; `LogisticsNotificationsTest`(10) | — | — | — |
    | L-16 | Suspension of a provider | PARTIAL | `EnsureLogisticsApproved` blocks the provider; `CourierAssignmentService.php:101,783` blocks new assignments | Riders of a **suspended provider stay operational**: `Rider::isEligibleForAssignment()` and `LogisticsHub::isOperational()` never check provider status, so riders can still log in (web/API) and finish already-assigned legs and collect COD | Add a provider-status check to `Rider::isOperational()` | P1 |

    ### B.4 Rider

    | ID | Feature | Status | Evidence | Problem | Recommended fix | Priority |
    |---|---|---|---|---|---|---|
    | R-01 | Registration (web + API), OTP, Logistics approval, activation link | WORKING | `Rider/RegistrationController`, `RiderRegistrationService`, `ActivationController`; `MobileRiderRegistrationTest`(54), `WebRiderRegistrationTest`, `RiderActivationOneTest`, `RiderHardeningTest` | — | — | — |
    | R-02 | Web login + operational gate | WORKING | `UnifiedLoginController::tryRider`, `EnsureRiderActive`; `RiderAuthenticationTest`(11), `RiderAccessTest` | Provider suspension not enforced (L-16) | — | P1 |
    | R-03 | API auth (Sanctum login/profile/logout, ability + status re-check per request) | WORKING | `Api/Rider/*`, `ApiRole`, `ValidatesRoleAbilities`; `BuyerRiderApiAuthenticationTest`(31) | 60-minute tokens with no refresh endpoint. Tokens aren't deleted on suspension or password change (they are rejected per request by `ApiRole`, so this is low risk). | Add refresh or longer expiry; revoke tokens on status change | P2 |
    | R-04 | Assigned parcel visibility, pickup confirm, pickup failure | WORKING (web) | `PickupAssignmentsController`, `RiderOperationsService`; `RiderPickupTest`, `PickupFailureCancellationTest` | Web only | — | — |
    | R-05 | Delivery start / POD upload / COD collection / failed attempt | WORKING (web) | `DeliveryAssignmentsController`, `RiderOperationsService::completeDelivery` (creates `CodRemittance`); `RiderDeliveryTest`, `RiderProofOfDeliveryTest`(12) | Web only | — | — |
    | R-06 | QR scans + digital waybill | WORKING (web) | `Rider/ParcelScanController`, `DigitalWaybillController`; `Rider/ParcelScanTest`(12), `DigitalWaybillTest` | — | — | — |
    | R-07 | Rider-to-hub handoff | WORKING | Logistics station receipt after Rider pickup (`IncomingParcelsController::toSortingCenter` + scan tokens); covered by `F2ScanEnforcedLifecycleTest` and the readiness COD probe | — | — | — |
    | R-08 | Incident reporting (lost/damaged) | PARTIAL | JSON routes `rider.php:70` and `api.php:65`; **no Rider view references them** | No UI; web route has no auth middleware (E-04) | Add form on delivery page; fix middleware | P1 |
    | R-09 | Mobile API business endpoints | MISSING | `routes/api.php` has no assignment/scan/POD/COD/failed endpoints under `auth:sanctum` | Flutter Rider app can only log in | See F-06 | P1 (if Flutter is in demo scope) |
    | R-10 | Notifications | WORKING | `RiderNotifier`; `RiderNotificationsTest`(20) | `routes/rider.php:50-65` registers the notification routes twice (harmless duplicate) | Delete the duplicate block | P3 |

    ### B.5 Admin

    | ID | Feature | Status | Evidence | Problem | Recommended fix | Priority |
    |---|---|---|---|---|---|---|
    | A-01 | Login, roles (`admin`/`moderator`/super), optional TOTP 2FA | WORKING (tests) / BROKEN on local MySQL | `Admin/AuthController`, `EnsureAdminRole`, `EnsureAdminTwoFactor`; `AdminRoleAccessTest`, `AdminTwoFactorTest`(11) | 2FA columns migration **pending on local MySQL** | Run pending migrations (P0-02) | **P0** |
    | A-02 | Registration queue (filters, search, detail modal) | WORKING | `RegistrationController::index`; `AdminRegistrationsFilterTest`(14) | — | — | — |
    | A-03 | Approve/reject Buyer, Seller, Logistics | PARTIAL | See B-02 / L-01 | Only Seller decisions are atomic and logged | — | P1 |
    | A-04 | Suspend/reactivate + session revocation + audit history rows | WORKING | `UserAccountController`, `AccountStatusService`, `AccountSessionService`; `AccountActionHistoryTest`(20), `AdminUserAccountTest` | **No UI to read audit history** (`AccountActionHistory` is shown in no view) | Add a history panel on User Accounts | P2 |
    | A-05 | Identity-document access (private disk, nosniff, access log) | WORKING (tests) / BROKEN on local MySQL | `Admin/DocumentController`, `DocumentAccessLog`; `AdminDocumentStreamingTest`(9), `AdminDocumentAuditTest`(5) | `admin_document_access_logs` table **missing on local MySQL** → streaming will error | Run migrations | **P0** |
    | A-06 | Seller settlement / payout processing | BROKEN | `Admin/SellerSettlementController` returns JSON; no view; no payout-request admin route | Admin can't process payouts in the UI; payout requests are never actionable | Build Admin payout queue (approve → `confirmPayout`-style service; reject → release) + Blade page | **P0** |
    | A-07 | Product moderation (restrict/unrestrict) | PARTIAL | `Admin/ProductController`; `AdminProductOversightTest`(27) | **Not in the admin sidebar** (`Components/admin-sidebar.blade.php`), so it's reachable only by typing the URL | Add sidebar link | P1 |
    | A-08 | Voucher administration | MISSING | No admin/seller voucher route or controller | — | Admin voucher CRUD (code, scope, funding source, limits, dates) | P1 |
    | A-09 | Platform settings (with optimistic lock + history) | PARTIAL | `PlatformSettingService` (`platform_name`, `contact_email` only); `PlatformSettingsTest`(37) | Nothing else in the app reads these settings | Use them in views/mail, or scope down the claim | P3 |
    | A-10 | Incidents queue (resolve/reject) | UNVERIFIED | `Admin/IncidentController` | **Not in the sidebar; no tests** | Sidebar link + tests | P1 |
    | A-11 | Physical return exceptions, hub approvals, partnerships | WORKING | Sidebar links present; `PhysicalReturnTest`, `HubApprovalTest`, `PartnershipApprovalTest` | — | — | — |
    | A-12 | User / order / financial oversight dashboards | MISSING | Sidebar "Soon": Seller Compliance, Complaints & Disputes, Commissions, Reports, Chat, Account Management (`admin-sidebar.blade.php:96-125`) | No admin order list or financial ledger | Minimal read-only orders + commissions pages | P2 |

    ---

    ## C. Approval and access matrix

    | Role | Registration | Approval | Pending access | Approved access | Suspended access | Audit logging |
    |---|---|---|---|---|---|---|
    | **Buyer** | Self (web modal; signed OTP pages). **No API registration.** | Admin (`admin` or `moderator`) | Web: logged out by `EnsureBuyerActive` with message. API: `ApiRole` → 403. | Web buyer routes; API `profile`/`logout` | Web: sessions revoked (DB driver) + per-request logout; API: tokens remain but `ApiRole` → 403 each request | **No** `AccountActionHistory` for approve/reject; **yes** for suspend/reactivate |
    | **Seller** | Self (web) | Admin | Logged out by `EnsureSellerApproved`; resume-OTP link for `unverified` | Seller routes | Sessions revoked; products hidden (`isListedForSale`); checkout re-checks under lock | **Yes** (decision + suspension); decision atomic with row lock; notification after commit |
    | **Logistics** | Self (web) | Admin (+ province verification) | Logged out by `EnsureLogisticsApproved` | Logistics routes + `api/logistics/rider-applications` (service checks approval) | Provider blocked; new assignments blocked; **its riders stay operational** (L-16) | **No** for decisions; yes for suspension |
    | **Rider** | Self (web dialog or `api/rider/register`); legacy Logistics-created riders | Logistics provider (own riders only; station hub required) | Web login refused unless `isOperational()`; API 403 | Rider web routes; API `profile`/`logout` | `rider.active` logs out; API 403. Provider suspension not inherited. | Rider decisions recorded via `RiderRegistrationService` (not re-verified in detail) |
    | **Admin** | Seeder only (`ADMIN_SEED_*`) | — | — | Role-gated routes; settlement & settings `admin`-only | No admin suspension mechanism | Document access log (table pending on MySQL); platform-setting history |

    Cross-cutting checks:
    - **Direct-URL bypass:** every role route group uses `auth:<guard>` plus a status middleware (route list verified). Exception: `POST /parcels/{sellerOrder}/report-incident` (E-04).
    - **Record ownership:** enforced in services or policies (e.g. `ProductReviewService::update` checks `buyer_id`; `ShipmentController::label` → policy; `HandoffController::participant`; `DeliveryProofController` matches `courier_id`; `CodRemittanceService` checks provider). Spot-checked; no IDOR found.
    - **Web vs token isolation:** API routes carry no session (`api` group); `ApiRole` rejects `TransientToken`, checks model class + `tokenCan(role)` + live account status (`BuyerRiderApiAuthenticationTest`, 31 tests).

    ---

    ## D. End-to-end flow findings

    | # | Flow | Works | Breaks / gaps |
    |---|---|---|---|
    | 1 | Registration → OTP → Admin approval → Login | All five roles end-to-end (tests) | Buyer and Logistics decisions are not atomic or logged (B-02/L-01). Local MySQL is missing two migrations (affects Admin docs/2FA). |
    | 2 | Product publish → browse → cart → COD checkout | Fully: drafts, publish, restriction/vacation gates, server-side shipping, checkout token, row locks, MySQL races | Vouchers must be seeded by hand (B-13). |
    | 3 | Checkout → Seller Accept → Pack → Ready | Yes; Ready reserves the pickup rider | — |
    | 4 | Pickup assignment → Rider pickup → hub sorting → dispatch | Yes (QR-verified custody, manifests) | Rider mobile API absent. |
    | 5 | Delivery → COD → Buyer confirm → Seller earnings | Yes, up to finalized commission (5%) and an available balance once Logistics confirms remittance | Commission rate CONFLICT (5% vs 10% in docs). |
    | 6 | Seller payout request → Admin settlement | Seller request, replay protection, cancellation | **Breaks at Admin:** no route/UI to approve or pay a request; direct settlement is refused while reserved; settlement endpoints are JSON-only. |
    | 7 | Buyer cancellation → stock restore | Yes, exactly once (Rule04, 21 tests) | Voucher restoration policy undefined. |
    | 8 | Delivered → return request → reverse logistics → Seller receipt | Request intake + 7-day window + completion blocking | **Breaks after intake:** no Buyer pickup; `Delivered` can't enter manifests/handoffs; no accept/reject; no refund. Shipment stays Delivered with an open return forever, so the Seller is never paid for it. |
    | 9 | Suspension / restriction / vacation → purchase prevention | Yes (cart, Buy Now, checkout under lock; MySQL vacation race) | Provider suspension doesn't stop its riders (L-16). |
    | 10 | Cross-province handoff between providers | Yes (partnership approval, QR handoff, accept/reject/dispatch/receive) | Needs seeded partnerships/coverage (`LOGISTICS_FLOW_SEED_APPLY=1`) for the demo. |
    | 10b | Failed delivery ×2 → return to Seller | Yes (`PhysicalReturnTest`, 30) | — |

    ---

    ## E. Confirmed bugs and security issues

    ### E-01 — Stored XSS in Buyer notification dropdown · **High**
    - **Where:** `resources/js/buyer/navbar.js` → `loadNotifications()` (lines 113-121); data from `Buyer\NotificationController::index()`; payload source `App\Notifications\Buyer\SellerOrderCancelled::toArray()` (`message` includes `business_name`).
    - **Reproduce (code path; not browser-executed):** Seller sets store name to a ≤30-character HTML payload (`UpdateStoreProfileRequest` allows any characters, 3–30 length) and declines a Buyer's order. The Buyer opens the bell, and `notifList.innerHTML` renders the payload.
    - **Expected:** text is escaped. **Actual:** inserted as HTML.
    - **Impact:** script runs in the Buyer's session (CSRF-token theft, actions as the Buyer).
    - **Fix:** build nodes with `textContent` or escape `title`/`message`/`id`; optionally add a CSP. Add a regression test.

    ### E-02 — Seller payout requests are unfulfillable · **High (functional)**
    - **Where:** `App\Models\SellerPayoutRequest` (only `PENDING`, `CANCELLED`); `SellerSettlementService::confirmPayout()` rejects reserved settlements; no admin route (`grep payout routes/` → seller only).
    - **Expected:** Admin approves or pays a request, and the request and its allocations close.
    - **Actual:** the request stays `PENDING` forever unless the Seller cancels it.
    - **Fix:** add `APPROVED/PAID/REJECTED` states, an Admin service under the same Seller → settlement lock order, and Blade UI. Extend `SellerPayoutConcurrencyTest`.

    ### E-03 — Local MySQL schema behind code · **High (demo-blocking)**
    - **Where:** `php artisan migrate:status` → pending `2026_10_29_000000_add_two_factor_columns_to_admins_table`, `2026_10_29_000001_create_admin_document_access_logs_table`. `Admin\DocumentController::stream()` calls `DocumentAccessLog::record()`.
    - **Actual (inferred, not executed against MySQL):** a SQL error when an admin opens an ID document; 2FA enrolment fails.
    - **Fix:** back up, then `php artisan migrate` (not `migrate:fresh`).

    ### E-04 — Incident route without auth middleware; Logistics caller crashes · **Medium**
    - **Where:** `routes/rider.php:70-71` `POST /parcels/{sellerOrder}/report-incident` (no `auth`, no `rider.active`). `StoreIncidentRequest::authorize()` accepts a rider **or logistics** session. `Rider\ParcelIncidentController::store()` passes `$request->user('rider')` to `ParcelIncidentService::report(SellerOrder, Rider|LogisticsProvider)`.
    - **Reproduce:** a logistics-session POST makes `user('rider')` null → `TypeError` → HTTP 500. A rider session bypasses the `rider.active` operational gate (service ownership checks still apply).
    - **Fix:** wrap in `auth:rider` + `rider.active` (Logistics already has its own route). Add tests.

    ### E-05 — Buyer/Logistics registration decisions not atomic or audited · **Medium**
    - **Where:** `Admin\RegistrationController::approveBuyer/rejectBuyer/approveLogistics/rejectLogistics`.
    - **Reproduce:** two admins act on the same pending row at the same time. Both pass the `status === 'pending'` check, last write wins, and both emails are sent (e.g. "approved" and "rejected"). No `AccountActionHistory` row is written.
    - **Fix:** same pattern as `SellerRegistrationDecisionService` (transaction, `lockForUpdate`, history, `DB::afterCommit` notify).

    ### E-06 — Riders of a suspended Logistics provider stay operational · **Medium**
    - **Where:** `Rider::isEligibleForAssignment()` / `isOperational()` (`app/Models/Rider.php:111-131`), `LogisticsHub::isOperational()` (`LogisticsHub.php:179-182`).
    - **Actual:** after Admin suspends a provider, its riders can still log in (web and API) and complete legs already assigned to them, including COD collection.
    - **Fix:** require `logisticsProvider->isApproved()` in `isEligibleForAssignment()`; decide what happens to in-flight parcels.

    ### E-07 — Buyer return after delivery is a dead end · **Medium (functional)**
    See Flow 8 and `storage/logs/phase1-readiness.md` ("BLOCKED"). Evidence: `ManifestService.php:712`, `SellerOrder::isClosedForHandoff()`, `ParcelReturnService::requestByBuyer()`.

    ### E-08 — JS regression: availability label · **Low**
    `node --test tests/js/*.test.mjs` → 1 failure: "a selected variant becoming empty shows Out of Stock…" (actual `Sold Out`). Source: uncommitted change in `resources/js/buyer/product-show.js:106-107`.

    ### E-09 — Hard-coded database credential in a tracked file · **Medium**
    `query_accounts.php` (repo root, tracked) contains a literal MySQL root connection string. The value is not reproduced here. It isn't web-reachable (`public/` is the docroot), but it is in git history. Fix: delete the script, rotate the credential, and move other root scratch scripts (`db_query.php`, `test_query.php`, `inspect_service.php`, `tmp_*.php`, …) out of the repo.

    ### E-10 — Production configuration risks · **Medium (production)**
    `.env` and `.env.example` both have `APP_DEBUG=true`, `LOG_LEVEL=debug`, `SESSION_ENCRYPT=false`, `SESSION_SECURE_COOKIE=false`, and an empty `TRUSTED_PROXIES`. `.env.example` uses `MAIL_MAILER=log` (writes OTP emails to disk). This is fine locally; it must change for any hosted demo.

    ### E-11 — Logistics-created rider documents have no type validation · **Low**
    `app/Http/Requests/Logistics/StoreRiderRequest.php:70-71` uses `['required','file','max:5120']` with no `mimes` (compare `StoreRiderRegistrationRequest` and its `mimes:jpg,jpeg,png,pdf`). The files go to the private disk and are served as downloads with `nosniff`, so the risk is limited. Add `mimes`/`extensions`.

    ### E-12 — Minor
    - `EnsureBuyerActive` calls `setRememberToken(null)` without saving (`app/Http/Middleware/Buyer/EnsureBuyerActive.php`).
    - `routes/rider.php:50-65` registers the notification routes twice.
    - The Buyer API login matches `email` exactly after `strtolower` (`Api/Buyer/LoginController::resolveAccount`). Rows stored with mixed case would fail. Registration normalisation is covered by `RegistrationEmailConsistencyTest`; legacy rows are not.

    ---

    ## F. Missing development work

    | ID | Item | Routes / controllers / services | DB | UI | Tests |
    |---|---|---|---|---|---|
    | F-01 | Admin payout & settlement processing | `admin/payout-requests` (index, approve/pay, reject); service in the `SellerSettlementService` lock order; Blade wrapper for `admin/settlements` | `seller_payout_requests.status` new states + `processed_by/at`, `reference` | Admin sidebar "Payouts" + table + modals | Feature + extend MySQL `SellerPayoutConcurrencyTest` |
    | F-02 | Voucher administration | `Admin\VoucherController` (CRUD, activate/deactivate); optional `Seller\VoucherController` scoped to own products (replace closure `routes/seller.php:80`) | Existing `vouchers` table (+ scope/funding columns from `2026_10_27_170000`) | Admin + Seller voucher pages | CRUD, scope, usage limits, funding allocation |
    | F-03 | Delivered Buyer return completion | Seller/Admin accept-reject of a return; Buyer → Logistics collection (rider pickup leg or drop-off scan); narrowly allow `Delivered` with an *accepted* return into reverse manifests/handoffs; refund policy record (COD → manual refund reference) | `parcel_returns` decision columns; refund record | Seller return decision; Logistics collection screen; Buyer status | Full reverse-flow test + MySQL race |
    | F-04 | Seller notifications | `seller/notifications` index, mark-read, read-all | — | Wire bell JS like Logistics/Rider | Feature test |
    | F-05 | Admin navigation + oversight | Sidebar links for Products, Incidents, Settlements/Payouts; read-only Orders and Commissions pages; Account history panel | — | Sidebar + pages | Access tests per role |
    | F-06 | Sanctum business API for Flutter (if in scope) | Rider: `GET assignments` (pickup/delivery), `POST scan/verify`, `POST pickups/{id}/confirm`, `POST deliveries/{id}/start`, `POST deliveries/{id}/deliver` (multipart POD + COD), `POST deliveries/{id}/failed`, `POST parcels/{id}/incidents`, notifications. Buyer: catalogue, product, cart, checkout, orders, confirm/cancel/return. All must reuse `RiderOperationsService`, `CheckoutController` logic moved into a service, etc. | — | — | Per-endpoint auth/ownership tests |
    | F-07 | Rider incident UI | Form on the delivery/pickup page posting to a middleware-protected route | — | Rider view | Feature tests for `ParcelIncidentService` + Admin resolve |
    | F-08 | Buyer/Logistics decision service | `BuyerRegistrationDecisionService`, `LogisticsRegistrationDecisionService` | — | — | Mirror `SellerRegistrationDecisionTest` + MySQL race |
    | F-09 | Provider-suspension cascade | `Rider::isEligibleForAssignment()` provider check | — | Message on rider login | Test |
    | F-10 | Seller reports | `Seller\ReportController` (sales, earnings, commission by period) | — | Replace stub view | Test |
    | F-11 | Messages/notifications tests | — | — | — | Buyer/Seller message ownership; Buyer notification JSON |
    | F-12 | Optional | Follow store; buyer address book; Admin account management; real Platform-settings consumers | `store_follows`, `buyer_addresses` | — | — |

    ---

    ## G. Test coverage and verification gaps

    ### Commands actually run (2026-10-30)
    | Command | Result |
    |---|---|
    | `php artisan test --compact --log-junit <scratch>/audit-php.xml` | 2,231 tests, 2,230 passed, 1 skipped (`CheckoutStockConcurrencyTest::test_true_concurrent_checkouts_do_not_oversell`, needs MySQL), 0 failures/errors, 14,329 assertions, 195.4 s |
    | `php vendor/bin/phpunit -c phpunit.mysql.xml tests/MySql` | 51/51 passed, 726 assertions, 146.6 s (dedicated `zefanya_concurrency_test` DB). The four dispatch-status mismatches and five checkout harness errors in `phase1-readiness.md` **no longer reproduce**. |
    | `node --test tests/js/*.test.mjs` | 24 tests, 23 passed, **1 failed** (E-08) |
    | `node --test tests/Frontend/CoverageLookupTest.cjs` | 4/4 passed |
    | `php artisan migrate:status` (read-only) | 115 migration files; **2 pending** (E-03) |
    | `php artisan route:list --except-vendor --json` | 322 routes reviewed |

    **Not run:** `npm run build` (it rewrites `public/build`, which is outside the "report only" permission), Pint, Playwright/browser journeys, and any test against the application database.

    ### Coverage map
    | Area | Status |
    |---|---|
    | Registration/OTP/approval (all roles), session isolation, Sanctum auth | Implemented and tested |
    | Catalogue, cart, Buy Now, checkout, stock, vouchers-apply, reviews, wishlist | Implemented and tested (voucher usage: 1 test only) |
    | Seller products/variants/categories/vacation/store profile/low-stock | Implemented and tested |
    | Order lifecycle, cancellation, completion, auto-receipt, money rounding, commission | Implemented and tested |
    | Logistics dispatch, sorting, manifests, handoffs, partnerships, coverage, COD remittance, failed-delivery return | Implemented and tested |
    | Rider web operations, POD, scans, notifications | Implemented and tested |
    | Seller payout request | Implemented and tested (Seller side only); Admin side missing |
    | Messages (buyer↔seller), Buyer notifications JSON, incidents (Rider/Logistics/Admin) | **Implemented but untested** |
    | Delivered-return reverse logistics, Admin payout processing, voucher administration, Seller reports, mobile business API | **Missing** |
    | Buyer/Logistics decision races on MySQL | **Untested** (only Seller has a race test) |

    ### Unexecuted scenarios that need manual or browser verification before the demo
    1. A full browser run of Flows 1–5 and 10 with real accounts on MySQL (after migrating). Use `WORKFLOW_GUIDE.md` §F as the script.
    2. The XSS payload in E-01 (to confirm it fires and that the fix stops it).
    3. The Logistics Riders screen calling `api/logistics/rider-applications` (approve/reject from UI).
    4. Admin document streaming after migration.
    5. Any Flutter client: there isn't one in the repo.

    ---

    ## H. Prioritized implementation roadmap

    ### P0 — Blocking / critical (do before any rehearsal)
    | ID | Role | Task | Files | Depends on | Acceptance criteria |
    |---|---|---|---|---|---|
    | P0-01 | All | Commit the working tree in logical chunks (include the 103 untracked files). Remove scratch scripts and the hard-coded credential (E-09). | repo root, `app/**`, `database/migrations/**` | — | `git status` clean except intentional ignores; fresh clone + `composer install` + `migrate` + test suite passes |
    | P0-02 | Admin | Back up the DB and run the pending migrations on local MySQL | `2026_10_29_000000…`, `2026_10_29_000001…` | P0-01 | `migrate:status` shows 0 pending; admin opens an ID document; access-log row written |
    | P0-03 | Buyer | Fix E-01 XSS in the notification dropdown | `resources/js/buyer/navbar.js` | — | Store name `<img src=x onerror=alert(1)>` renders as text; regression test added |
    | P0-04 | Seller/Admin | Admin payout-request processing + settlement UI (F-01) | new `Admin\PayoutRequestController`, `SellerPayoutRequestService`, `SellerSettlementService`, `SellerPayoutRequest` model, migration, Admin views/sidebar | P0-01 | Seller requests → Admin pays with reference → Seller balance and history update; double-pay blocked (MySQL race test) |
    | P0-05 | Demo | Demo data: vouchers, partnership/coverage seed (`LOGISTICS_FLOW_SEED_APPLY=1`), approved accounts for every role, scheduler running | seeders / runbook | P0-02 | Scripted run of Flows 1–7, 10 and 10b succeeds in the browser |

    ### P1 — Required functionality
    | ID | Role | Task | Files | Depends on | Acceptance criteria |
    |---|---|---|---|---|---|
    | P1-01 | Admin | Atomic + audited Buyer/Logistics decisions (E-05, F-08) | `Admin/RegistrationController`, new services | — | History rows written; concurrent approve/reject → exactly one decision and one email |
    | P1-02 | Admin/Seller | Voucher administration (F-02) | new controllers/views; replace `routes/seller.php:80` closure | — | Admin creates a voucher in the UI; buyer applies it; usage counted once |
    | P1-03 | Admin | Sidebar links for Products, Incidents, Payouts/Settlements (F-05) | `Components/admin-sidebar.blade.php` | P0-04 | Every admin feature reachable by clicking |
    | P1-04 | Rider/Logistics | Secure incident route + Rider incident UI + tests (E-04, F-07) | `routes/rider.php`, Rider views, new tests | — | Logistics POST to rider route → 302/401, not 500; rider can report from the UI; Admin can resolve |
    | P1-05 | Logistics/Rider | Provider-suspension cascade (E-06) | `app/Models/Rider.php` | — | Suspended provider's riders refused on web and API |
    | P1-06 | Seller | Seller notifications page + mark-read (F-04) | `routes/seller.php`, new controller, bell JS | — | Low-stock alert visible, markable, count decreases |
    | P1-07 | Buyer/Seller/Logistics | Delivered-return completion (F-03), **or** formally take it out of scope for the capstone and hide the Buyer "Return" button | `ParcelReturnService`, `ManifestService`, `ParcelHandoffService`, views | Owner decision | Either a full reverse flow test passes or the UI no longer offers an unfinishable action |
    | P1-08 | Owner | Confirm the commission rate (5% in code vs 10% in docs) and update whichever is wrong | `CommissionFinalizationService::COMMISSION_BPS` or docs | — | One documented rate; tests match |
    | P1-09 | Buyer | Resolve JS label regression (E-08) | `resources/js/buyer/product-show.js` or the test (only if the label change was intended) | — | `node --test` 24/24 |
    | P1-10 | Rider/Buyer | Mobile business API (F-06), **only if** a Flutter demo is planned | `routes/api.php`, new `Api\*` controllers reusing services | — | Flutter rider completes pickup → delivery → POD/COD via API; ownership tests pass |
    | P1-11 | QA | Tests for messages, buyer notifications, incidents (F-11) | `tests/Feature/**` | — | New tests pass in the full suite |

    ### P2 — Important improvements
    | ID | Role | Task | Acceptance criteria |
    |---|---|---|---|
    | P2-01 | Admin | Account action history viewer; read-only Orders and Commissions pages | Admin sees per-account history and platform order/commission totals |
    | P2-02 | Seller | Reports page (F-10) | Sales, commission and net per period |
    | P2-03 | Buyer | Address book (multiple addresses, checkout picker) | Buyer chooses among saved addresses; snapshot stored |
    | P2-04 | API | Token refresh / revoke-all on suspension or password change | Old tokens deleted on status change |
    | P2-05 | Ops | Production `.env` hardening (E-10) | `APP_DEBUG=false`, secure/encrypted sessions, `LOG_LEVEL=warning`, SMTP, trusted proxies set |
    | P2-06 | Docs | Rewrite `docs/1-4` to match the code (Sanctum, shipping, commission, payouts) | Docs and code agree |
    | P2-07 | Policy | Voucher restoration on cancellation; refund mechanism for returns | Written decision, then tests |

    ### P3 — Optional
    Follow store (B-10) · remove Chat placeholders or redirect them to Messages (B-20, S-16) · use Platform Settings across the app (A-09) · rider route duplicate cleanup and `EnsureBuyerActive` save (E-12) · `mimes` on Logistics rider uploads (E-11) · Admin account management.

    ---

    *End of report. No application files were modified. Only `docs/audits/ZEFANYA_FULL_SYSTEM_AUDIT.md` was created; test logs were written to the session scratchpad, not the repo.*
