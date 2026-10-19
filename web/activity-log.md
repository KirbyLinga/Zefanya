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


## 2026-10-19 ? Local runtime and registration status compatibility

- Branch: `integration/groupmate-rider`; starting commit `bbd05332`. No commit, merge, push, reset, stash application or stash deletion.
- Created ignored `web/.env` from the existing root environment after checking APP_ENV=local, MySQL connection, loopback DB host and no DB_URL override. Existing APP_KEY was copied unchanged and equality verified without displaying it or credentials. Root `.env` unchanged. No production configuration or `phpunit.xml` changes; password-reset brokers unchanged.
- Restored the 13 historical migrations below byte-for-byte from `stash@{0}` (untracked stash parent used where necessary). Verified all 13 bytes against their source after formatting. Stash object remains `81454d886eba96831df7f490539f2af09f6bd8bc`.
- Aligned Buyer/Seller registration creation, OTP transitions, Admin queues/counts, authentication messages, middleware, factories and status fixtures with `unverified`, `pending`, `approved`, `rejected`, `suspended`. Retained Logistics enum case identifiers for compatibility while changing backing values and adding Suspended. Rider identity, approval service, Courier relationships, one-account policy and cross-role email registry remain intact.
- Seller email/contact uniqueness now includes canonical pending and suspended accounts. Logistics contact validation now matches the per-table database unique constraint and returns a field validation error before upload/registration work.
- Buyer OTP verify/resend now refuses rejected/suspended accounts; a stale valid OTP cannot reopen these accounts. Added canonical login-status matrix, all five Logistics enum casts/labels, suspended-session restrictions, blocked Buyer OTP, pending/suspended Seller uniqueness and duplicate Logistics contact endpoint coverage. Existing assertions were retained, with legacy status expectations updated.

### Verification

- Local Laravel boot successful; effective APP_ENV local, MySQL loopback, original key matches, config cache absent. Read-only MySQL connection succeeded. No application database writes, migrations or schema changes were performed.
- Migration repository comparison: zero applied migration names missing; all 13 restored files already applied. Exactly two pending: `2026_10_19_110858_create_rider_applications_table` and `2026_10_19_113958_create_rider_application_decisions_table`. Rider tables remain absent.
- Safe HTTP kernel checks with process-only array sessions/cache/mail: `/up` 200, `/register` 200, `/register/rider` 302 to registration, guest `/rider/dashboard` 302. No MySQL session/cache writes. Full live Rider registration/dashboard verification remains blocked by unapplied Rider migrations; no migration execution requested or performed.
- All tests used process-only SQLite `:memory:`, array cache/session/mail and a public dummy testing key; the actual application database/key were not used for test writes. PHPUnit builds disposable test schemas only.
- Focused compatibility + Logistics registration + Rider: **122 passed, 666 assertions** (includes existing 76 Rider cases).
- Exact baseline selection plus one new duplicate-contact case: **194 tests, 170 passed, 23 failed, 1 skipped, 599 assertions**. Baseline was 193 tests, 169 passed, 23 failed, 1 skipped. The same 23 failure names and messages match the recorded baseline after normalizing the local URL hostname/port; no newly failing baseline cases. The new case passed. The 23 consist of 17 Seller validation/authorization/audit expectation mismatches and six Google redirect expectations (`/login` expected, `/register` actual). Those assertions were not weakened.
- Expanded Logistics, Admin queues/counts, street registration, Buyer variant/cart selection: **114 tests, 109 passed, 5 failed, 467 assertions**. Five additional failures are outside the 23-case baseline: Buyer street happy-path fixtures omit the already-required Terms acceptance. Both the absence in the fixture and requirement exist at baseline `bbd05332`; status edits did not change that contract. Left these failures visible rather than changing unrelated assertions.
- `php vendor/bin/pint --dirty --format agent` completed successfully. No frontend assets changed; no build needed. Checkout controller/concurrency test files, `phpunit.xml` and auth broker configuration unchanged.
- Requested numbered four documentation files are absent in this checkout; available `docs/Context.md`, `docs/folder-structure.md`, current code and local Laravel/testing skills were consulted. Old documentation claims were not treated as verified schema facts.

### Modified tracked files

- `web/app/Enums/LogisticsProviderStatus.php`
- `web/app/Http/Controllers/Admin/DashboardController.php`
- `web/app/Http/Controllers/Admin/RegistrationController.php`
- `web/app/Http/Controllers/Admin/SellerRegistrationController.php`
- `web/app/Http/Controllers/Auth/GoogleAuthController.php`
- `web/app/Http/Controllers/Auth/UnifiedLoginController.php`
- `web/app/Http/Controllers/Buyer/LoginController.php`
- `web/app/Http/Controllers/Buyer/RegisterBuyerController.php`
- `web/app/Http/Controllers/Buyer/VerifyBuyerOtpController.php`
- `web/app/Http/Controllers/Seller/AuthController.php`
- `web/app/Http/Controllers/Seller/RegisterSellerController.php`
- `web/app/Http/Controllers/Seller/VerifySellerOtpController.php`
- `web/app/Http/Middleware/Logistics/EnsureLogisticsApproved.php`
- `web/app/Http/Middleware/Seller/EnsureSellerApproved.php`
- `web/app/Http/Requests/Logistics/StoreLogisticsRegistrationRequest.php`
- `web/app/Http/Requests/Seller/StoreSellerRegistrationRequest.php`
- `web/app/Models/Buyer/Buyer.php`
- `web/app/Models/Seller/Seller.php`
- `web/database/factories/LogisticsProviderFactory.php`
- `web/database/factories/SellerFactory.php`
- `web/tests/Feature/AdminDashboardStatsTest.php`
- `web/tests/Feature/AdminRegistrationsFilterTest.php`
- `web/tests/Feature/Auth/GoogleBuyerLoginTest.php`
- `web/tests/Feature/BuyerRegistration/BuyerRegistrationTest.php`
- `web/tests/Feature/LogisticsRegistrationTest.php`
- `web/tests/Feature/RegistrationStreetTest.php`
- `web/tests/Feature/SellerRegistration/SellerRegistrationTest.php`
- `web/activity-log.md`

### New focused test

- `web/tests/Feature/RegistrationStatusCompatibilityTest.php`

### Restored historical migrations (not executed against MySQL)

- `web/database/migrations/2026_10_12_000001_standardize_registration_status_and_add_otp_attempts.php`
- `web/database/migrations/2026_10_14_000001_make_seller_order_id_nullable_on_inventory_transactions.php`
- `web/database/migrations/2026_10_16_000001_add_postal_code_to_address_tables.php`
- `web/database/migrations/2026_10_16_000002_add_handover_at_to_seller_orders.php`
- `web/database/migrations/2026_10_16_000003_add_delivery_attempts_to_seller_orders.php`
- `web/database/migrations/2026_10_16_000004_create_areas_and_parcel_area.php`
- `web/database/migrations/2026_10_16_000005_create_delivery_assignments_table.php`
- `web/database/migrations/2026_10_16_000006_add_registration_archive_to_role_tables.php`
- `web/database/migrations/2026_10_16_000007_create_role_password_reset_tokens_tables.php`
- `web/database/migrations/2026_10_17_000001_unique_contact_no_and_align_logistics_sex.php`
- `web/database/migrations/2026_10_17_030510_add_suspended_status_to_role_tables.php`
- `web/database/migrations/2026_10_17_200000_create_reviews_table.php`
- `web/database/migrations/2026_10_18_042755_add_variant_options_to_order_items_table.php`

### Remaining constraints

No status data conversion is required for the inspected MySQL schema. Applying the two pending Rider migrations requires a separate approval; until then live Rider submissions cannot persist. Historical migrations remain immutable; their potentially destructive down methods must not be used for a blanket rollback. Live browser/mobile and SMTP checks were not performed in this task.
