# Activity log

## 2026-10-19 - Safe Rider integration

Branch: integration/groupmate-rider. Base: origin/recovery-update (7db31cae46bc4563bc414d790b04062b27407039). Groupmate source: origin/feature/web-rider-registration (facf3efcd3545ed4f55fb40be5ece99f2f2a95c9). R01 source: stash@{0} (81454d886eba96831df7f490539f2af09f6bd8bc); tracked stash blobs and its untracked-file parent were read individually and mapped into web/. The stash was not applied, modified, popped or deleted.

### Implemented

- Recovered R01 self-registration, provider selection, private ID/OR-CR uploads, validation, password hashing, signed email OTP verification/resend, retry archiving, rate limits, application identity and authentication dependencies.
- Retained RiderApplication as the sole Rider registration/authentication identity. No Rider model, riders table, activation tokens, or competing password-setting activation path was introduced.
- Adapted groupmate provider-scoped Rider listing/detail UI and profile dashboard. Lists are paginated and limited to email-verified applications owned by the acting Logistics provider. Private document downloads remain provider-scoped and deny Admin access.
- Added Logistics decision request, policy and service. Decisions require an approved provider, matching ownership, verified email and pending application; provider/application rows are locked and rechecked inside the transaction. Rejection requires a reason. A separate audit row records application/provider, from/to status, reason and timestamps. Status changes and audit writes roll back when synchronous decision-email delivery fails. Replays cannot overwrite a final decision or resend its notification.
- Canonical Rider statuses remain unverified -> pending -> approved/rejected; suspended accounts are denied. Dashboard access requires approved status and verified email. The password selected during self-registration is retained after approval.
- Integrated single-account session handling with unified, Seller, Admin and Google login boundaries and Rider logout. Cross-role registration uniqueness includes Rider applications. Rider email collisions are rejected during password/Google authentication; older non-Rider Google duplicate-role priority behavior is retained to avoid unrelated regression.
- Adapted R01 to current base statuses and optional archive columns without importing unrelated role-status/schema migrations or replacing existing role validation. Shared address lookup gains an optional prefix/manual-field mode; existing hosts retain default behavior. The Rider modal uses the current Logistics Tailwind shell, not missing stashed CSS classes.
- Strengthened Rider document validation to check actual file bytes/MIME in addition to allowed extensions and size. Tests use real PDF content rather than only a fake MIME label.

### Verification

The initial no-key run was an environment failure, not the usable baseline. The unchanged web/ app was built, then the baseline and final tests were run with the same process-only, public dummy APP_KEY. No .env or phpunit.xml file was modified. SQLite :memory: is the test database; only its disposable schemas were created by the test framework. No artisan migrate command or application/production database migration was executed.

- Rider suites: 76/76 passed, 427 assertions.
- Identical pre-change/final regression set: 193 tests; 169 passed; 23 failed; 1 skipped; 593 assertions. Failure names and messages compared programmatically: identical, zero introduced/resolved failures.
- Additional Logistics/Buyer regression set: 81/81 passed, 371 assertions.
- Pint passed and the asset build succeeded. No browser interaction, live SMTP delivery or MySQL concurrency verification is claimed.
- Existing checkout controllers and all pre-existing tracked tests remain identical to origin/recovery-update. Composer/npm manifests and lockfiles and phpunit.xml are unchanged. Courier relationships/fulfillment logic are unchanged; its supported vehicle values were exposed as a shared constant.

Commands (from web/; tests use a process-only APP_KEY):

- `php artisan test --compact tests/Feature/Rider`
- `php artisan test --compact tests/Feature/BuyerRegistration tests/Feature/SellerRegistration tests/Feature/LogisticsRegistrationTest.php tests/Feature/Auth tests/Feature/Http/Controllers/Buyer/CheckoutControllerTest.php tests/Feature/Http/Controllers/Buyer/CheckoutDoubleSubmitTest.php tests/Feature/Http/Controllers/Buyer/CheckoutStockConcurrencyTest.php`
- `php artisan test --compact tests/Feature/Logistics tests/Feature/LogisticsDashboardTest.php tests/Feature/LogisticsLoginFlowTest.php tests/Feature/Http/Controllers/Buyer/BuyerVariantFlowTest.php tests/Feature/Http/Controllers/Buyer/CartControllerTest.php`
- `php vendor/bin/pint --dirty --format agent (plus explicit paths for newly recovered PHP files)`
- `npm run build`

Baseline failures retained:

- `Tests\Feature\SellerRegistration\SellerRegistrationTest::test_sel_006_51_char_first_name_rejected`
- `Tests\Feature\SellerRegistration\SellerRegistrationTest::test_sel_007_email_no_tld`
- `Tests\Feature\SellerRegistration\SellerRegistrationTest::test_sel_007_email_with_space`
- `Tests\Feature\SellerRegistration\SellerRegistrationTest::test_sel_010_email_with_spaces_trimmed`
- `Tests\Feature\SellerRegistration\SellerRegistrationTest::test_sel_019_terms_not_accepted`
- `Tests\Feature\SellerRegistration\SellerRegistrationTest::test_sel_021_shop_name_already_used`
- `Tests\Feature\SellerRegistration\SellerRegistrationTest::test_sel_024_corporation_without_sec_certificate`
- `Tests\Feature\SellerRegistration\SellerRegistrationTest::test_sel_025_tin_not_supported`
- `Tests\Feature\SellerRegistration\SellerRegistrationTest::test_sel_026_tin_already_registered`
- `Tests\Feature\SellerRegistration\SellerRegistrationTest::test_sel_028_postal_code_not_supported`
- `Tests\Feature\SellerRegistration\SellerRegistrationTest::test_sel_038_pending_seller_dashboard_access_denied`
- `Tests\Feature\SellerRegistration\SellerRegistrationTest::test_sel_040_admin_approves_application`
- `Tests\Feature\SellerRegistration\SellerRegistrationTest::test_sel_041_admin_rejects_with_reason`
- `Tests\Feature\SellerRegistration\SellerRegistrationTest::test_sel_043_buyer_access_admin_seller_list`
- `Tests\Feature\SellerRegistration\SellerRegistrationTest::test_sel_043_seller_access_admin_seller_list`
- `Tests\Feature\SellerRegistration\SellerRegistrationTest::test_sel_044_approved_seller_access_admin_dashboard`
- `Tests\Feature\SellerRegistration\SellerRegistrationTest::test_sel_044_approved_seller_access_logistics_dashboard`
- `Tests\Feature\Auth\GoogleBuyerLoginTest::test_unknown_google_email_is_sent_back_to_login_with_an_error`
- `Tests\Feature\Auth\GoogleBuyerLoginTest::test_buyer_awaiting_approval_is_linked_but_cannot_sign_in_with_google`
- `Tests\Feature\Auth\GoogleLogisticsLoginTest::test_unknown_google_email_is_sent_back_to_login_with_an_error`
- `Tests\Feature\Auth\GoogleLogisticsLoginTest::test_provider_awaiting_approval_cannot_sign_in_with_google`
- `Tests\Feature\Auth\GoogleSellerLoginTest::test_unknown_google_email_is_sent_back_to_login_with_an_error`
- `Tests\Feature\Auth\GoogleSellerLoginTest::test_seller_awaiting_approval_cannot_sign_in_with_google`

### Pending migrations and compatibility

- database/migrations/2026_10_19_110858_create_rider_applications_table.php
- database/migrations/2026_10_19_113958_create_rider_application_decisions_table.php

Neither migration has been applied to the application database. Actual application database schema is unverified. The web/ execution environment did not supply APP_KEY, so local runtime configuration still needs a valid key supplied externally; environment files were left untouched. Existing Buyer/Seller/Logistics statuses and broker configuration were retained. No operational Courier is provisioned by registration or this profile/approval integration; existing fleet records and relationships are preserved. Pickup/delivery integration remains separate.

The 23 baseline failures comprise 17 Seller registration/authorization expectation mismatches and 6 existing Google redirect expectations. They were not weakened or rewritten. Non-Rider code/tests present only in the older root-layout stash were not bulk-restored over origin/recovery-update; the latest base's checkout implementation and tests are the protected reference.

### Files modified

- `web/app/Http/Controllers/Admin/AuthController.php`
- `web/app/Http/Controllers/Auth/GoogleAuthController.php`
- `web/app/Http/Controllers/Auth/UnifiedLoginController.php`
- `web/app/Http/Controllers/Seller/AuthController.php`
- `web/app/Http/Requests/Buyer/StoreBuyerRegistrationRequest.php`
- `web/app/Http/Requests/Logistics/StoreLogisticsRegistrationRequest.php`
- `web/app/Http/Requests/Seller/StoreSellerRegistrationRequest.php`
- `web/app/Models/Courier.php`
- `web/app/Providers/AppServiceProvider.php`
- `web/bootstrap/app.php`
- `web/config/auth.php`
- `web/resources/js/buyer/address-lookup.js`
- `web/resources/views/Auth/Register-Type.blade.php`
- `web/resources/views/Components/logistics/sidebar.blade.php`
- `web/routes/logistics.php`
- `web/routes/web.php`
- `web/vite.config.js`

### Files added

- `web/activity-log.md`
- `web/app/Enums/AuthRole.php`
- `web/app/Http/Controllers/Concerns/ClearsOtherGuards.php`
- `web/app/Http/Controllers/Concerns/VerifiesEmailOtp.php`
- `web/app/Http/Controllers/Logistics/RiderApplicationDocumentController.php`
- `web/app/Http/Controllers/Logistics/RiderController.php`
- `web/app/Http/Controllers/Rider/DashboardController.php`
- `web/app/Http/Controllers/Rider/RegisterRiderController.php`
- `web/app/Http/Controllers/Rider/VerifyRiderOtpController.php`
- `web/app/Http/Middleware/AuthenticateRoleSession.php`
- `web/app/Http/Middleware/EnforceSingleBrowserAccount.php`
- `web/app/Http/Middleware/RedirectIfAuthenticatedRole.php`
- `web/app/Http/Middleware/Rider/EnsureRiderApproved.php`
- `web/app/Http/Requests/Logistics/DecideRiderApplicationRequest.php`
- `web/app/Http/Requests/Rider/StoreRiderRegistrationRequest.php`
- `web/app/Models/Concerns/HasEmailOtp.php`
- `web/app/Models/Concerns/HasRegistrationArchive.php`
- `web/app/Models/RiderApplication.php`
- `web/app/Models/RiderApplicationDecision.php`
- `web/app/Notifications/Rider/RiderApplicationDecisionNotification.php`
- `web/app/Notifications/Rider/RiderRegistrationOtp.php`
- `web/app/Policies/RiderApplicationPolicy.php`
- `web/app/Services/RiderApplicationDecisionService.php`
- `web/app/Services/RiderRegistrationService.php`
- `web/app/Support/EmailMask.php`
- `web/app/Support/EmailRegistry.php`
- `web/app/Support/SignedOtpLink.php`
- `web/database/factories/RiderApplicationFactory.php`
- `web/database/migrations/2026_10_19_110858_create_rider_applications_table.php`
- `web/database/migrations/2026_10_19_113958_create_rider_application_decisions_table.php`
- `web/resources/js/registration-validation.js`
- `web/resources/js/rider/rider-register-modal.js`
- `web/resources/views/Components/registration-address-step.blade.php`
- `web/resources/views/Components/registration-personal-step.blade.php`
- `web/resources/views/Components/rider-register-modal.blade.php`
- `web/resources/views/Logistics/Riders/index.blade.php`
- `web/resources/views/Logistics/Riders/show.blade.php`
- `web/resources/views/Rider/dashboard.blade.php`
- `web/resources/views/Rider/pending.blade.php`
- `web/resources/views/Rider/verify-otp.blade.php`
- `web/tests/Feature/Rider/RiderApprovalTest.php`
- `web/tests/Feature/Rider/RiderRegistrationTest.php`

No merge, push, deployment, stash mutation, or application database migration was performed. Stopped after implementation and verification.
