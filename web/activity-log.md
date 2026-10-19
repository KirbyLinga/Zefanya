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


## 2026-10-19 ? Post-merge regression diagnosis (stopped at requirements boundary)

- Confirmed branch metadata is `recovery-update` at `e16066901959ab2005aa9e70975c6c78b26cd69b` by reading metadata files; no Git commands used.
- Reproduced affected Seller, Google authentication and street-registration cases using SQLite `:memory:`, array mail/cache/session and a process-only public testing key: **108 tests, 80 passed, 28 failed, 231 assertions**. Evidence: `storage/logs/post-merge-before.txt`. No local MySQL writes or application migrations were executed; test schema lifecycle was isolated in memory. No application code, schema, environment or assertions changed.
- Google (6 failures): `routes/web.php` explicitly redirects GET `/login` to `register.type`; `GoogleAuthController::failed()` deliberately redirects directly to that same registration/login surface, which displays `auth.status_message`. Six tests expect the intermediate `/login` route. Current reachable flow supports `/register`; changing callback routing merely to satisfy those assertions would add an unnecessary redirect. No test expectations changed before stopping.
- Seller validation (10 failures): one first-name case expects max 50 versus current max 100; two cases reject forms accepted by `email:rfc` (domain without a dot and quoted local part); one whitespace-trimming case documents trimming/saving but asserts 422 instead. Terms is not collected by the current Seller form and no corresponding backend rule exists. Shop-name uniqueness is expected by one test but not defined in current request/schema. Four cases expect rejection for SEC/TIN/postal requirements without submitting those fields: `test_sel_024`, `025`, `026`, `028` call the same default valid registration helper used by successful tests. `test_sel_023_documents_by_seller_type` explicitly calls that same helper and expects 201. This is contradictory coverage, not evidence that every normal Seller registration must be rejected.
- Seller authorization (5 failures): browser requests expect 403, while `auth:admin`, `auth:logistics` and `EnsureSellerApproved` intentionally redirect unauthenticated/nonapproved sessions to login (302). Access is denied; changing this is a behavior-contract decision, not proof of authorization bypass.
- Seller audit persistence (2 failures): approval/rejection controllers call `update()` with `approved_by`, `approved_at`, `rejection_reason`, but those attributes are not fillable on Seller. Confirmed mass-assignment defect; a subsequent authorized fix should use controller-owned `forceFill()->save()`, preserving model guards.
- Buyer street fixtures (5 failures): buyerPayload omits the already-required `terms`; valid fixture should explicitly accept Terms. Keep required/accepted validation and all failure assertions.
- Per the user's explicit stop condition, stopped when missing/contradictory Seller behavior requirements were established. No attempt made to invent Seller-type/SEC/TIN requirements, alter name limits/normalization, relax assertions, or add schema/UI features. Only this requested activity log was edited. Full-suite/Rider/checkout reruns were not reached; there is no after-fix result.
- Required decision: define whether unsupported Seller SEC/TIN/postal cases should be rewritten around supported inputs/deferred as explicit feature gaps, or implemented in a separately approved UI/schema scope. Also confirm Seller Terms, shop-name uniqueness, name-length/email policy and browser 302-versus-403 expectations before attempting to make all 17 cases pass. Independent confirmed fixes remain unapplied because of the requested stop boundary.


## 2026-10-19 ? Approved regression stabilization

### Confirmed implementation fix

- `app/Http/Controllers/Admin/RegistrationController.php`: Seller approval/rejection now uses controller-owned `forceFill([...])->save()` for status, reviewer, timestamp and rejection reason. Previously `update()` silently discarded guarded audit fields. Seller model guards remain unchanged. Buyer/Logistics/Rider decision code, schema, registration requests and commerce logic were not changed.
- Strengthened existing Seller approval/rejection regressions: verify persisted reviewer and timestamp at the database's second precision, saved rejection reason, old rejection cleared on approval, and decision notification dispatched. The initial timestamp assertion was corrected for existing second precision; an intermediate test-file encoding issue was corrected before final runs.

### Contract-backed test corrections

- Google Buyer/Seller/Logistics tests now assert the exact `/register` destination. Existing GET `/login` explicitly redirects there, and the callback deliberately returns `register.type` with the status message. Callback behavior and guest/link-only assertions remain unchanged.
- Buyer street happy-path fixture now submits accepted `terms`; required/accepted validation is unchanged and the Buyer negative-validation suite passes.
- Seller name-length scenario now rejects 101 characters with a field-specific error and no persisted row; added an accepted 100-character boundary with persisted-name/status assertions. Existing request explicitly uses `max:100`; no new limit introduced.
- Seller whitespace-email test now verifies the documented trimming behavior: created response, exact normalized email persisted, one unverified account. Cross-role and duplicate-email rejection tests remain intact.
- Five browser denial cases assert their actual middleware destinations: pending Seller to unified login; Buyer/Seller entering Admin routes to Admin login; Seller entering Logistics to unified login. They assert the target guard remains unauthenticated; pending Seller also retains pending status and status message. Wrong-role JSON requests additionally assert 401. Existing Rider authorization tests still check true policy failures as 403. No auth/middleware behavior changed.

### Individually deferred Seller requirements

These eight tests use explicit `markTestIncomplete('Deferred: ...')` notices. Their old assertions remain in place rather than being changed into permissive checks. They are not counted as passing:

- `test_sel_007_email_no_tld`: stricter public-domain/TLD policy is outside current `email:rfc` request contract.
- `test_sel_007_email_with_space`: rejecting RFC-valid quoted local parts requires a stricter policy; not added.
- `test_sel_019_terms_not_accepted`: Seller Terms field/rule absent from current form/request; no speculative UI/rule added.
- `test_sel_021_shop_name_already_used`: no existing global shop-name unique rule/index; existing required/string/max constraints preserved.
- `test_sel_024_corporation_without_sec_certificate`: no Seller-type/SEC workflow; test submits ordinary valid data.
- `test_sel_025_tin_not_supported`: no implemented TIN field/validation; no TIN submitted.
- `test_sel_026_tin_already_registered`: no implemented TIN uniqueness or fixture; no TIN submitted.
- `test_sel_028_postal_code_not_supported`: no mandatory Seller postal-code request contract and no value submitted.

Available source files establish the implemented contracts; ERP PDFs were not available in the relevant checkout. No unverified ERP rule was invented. This follows the user's explicit approval to defer unsupported Seller requirements.

### Verification (isolated SQLite only)

- No Git commands, application MySQL writes, migrations against application databases, schema changes, environment/key edits, production calls or frontend changes. Test schema lifecycle was confined to SQLite `:memory:`. Process-only fake mail/cache/session and public dummy test key were used; `phpunit.xml` unchanged.
- Affected before: **108 tests, 80 passed, 28 failed** (previous diagnosis reproduced baseline).
- Affected after, extracted from the final focused JUnit report: **109 tests, 101 passed, 0 failed, 8 deferred**. One new name-length boundary case added.
- Focused Seller/Google/Buyer/Rider/compatibility/checkout suite: **290 tests, 281 passed, 0 failed, 8 deferred, 1 existing skip**, 1195 assertions. Rider registration, OTP, Logistics decisions, dashboard, private-document/provider isolation, cross-role email uniqueness and one-account sessions all pass. Checkout stale-stock/double-submit tests pass; real concurrent MySQL test remains skipped on SQLite.
- Full before: **594 tests, 554 passed, 39 failed, 1 skipped**, 2808 assertions.
- Full after: **595 tests, 575 passed, 11 failed, 8 deferred, 1 skipped**, 2852 assertions. Accurate pass counts come from JUnit; the compact JSON reporter includes incomplete cases in its `passed` field, so its 583 is not reported as actual passes.
- All 11 remaining failing test names existed in the before-change full-suite run; no newly failing tests. They are outside this approved stabilization scope: 3 Buyer account-tab markup expectations; 4 Buyer order-detail renders referencing missing `buyer.messages.get-or-create`; 2 search queries using unsupported `ilike` on SQLite; 1 scaffold ExampleTest missing database setup (`categories`); 1 production-HTTPS URL generation assertion. Left visible without unrelated fixes.
- Pint completed successfully on the six edited PHP files using explicit paths (no `--dirty` Git lookup). No asset build needed because no frontend assets changed.
- Evidence: `storage/logs/stabilization-full-before.txt`, `stabilization-focused.txt`, `stabilization-focused.xml`, `stabilization-full-after.txt`, `stabilization-full-after.xml`. Intermediate affected-after artifacts document earlier harness failures; final focused/full artifacts are authoritative.

### Files changed

- `app/Http/Controllers/Admin/RegistrationController.php`
- `tests/Feature/Auth/GoogleBuyerLoginTest.php`
- `tests/Feature/Auth/GoogleSellerLoginTest.php`
- `tests/Feature/Auth/GoogleLogisticsLoginTest.php`
- `tests/Feature/RegistrationStreetTest.php`
- `tests/Feature/SellerRegistration/SellerRegistrationTest.php`
- `activity-log.md`

Remaining blockers: the full suite is not green due to the 11 pre-existing failures above; deferred requirements require a separately approved contract before implementation. True concurrent MySQL testing needs isolated infrastructure and was not attempted against the application database.


## 2026-10-19 ? Final regression fixes (11 remaining failures)

### Reproduced causes and scoped fixes

- Reproduced all 11 remaining failures in the five affected test files: **44 tests, 33 passed, 11 failed**. Evidence: `storage/logs/final-regressions-before.txt` and `.xml`.
- **Buyer account navigation (3):** the current pages include `Components.buyer.account-sidebar`, while assertions targeted an obsolete tab-strip span and obsolete labels. Preserved the sidebar layout/labels, added conditional `aria-current="page"` to its actual active navigation link, and changed coverage to parse that navigation: exactly one active link, correct destination and label. Theme checks now inspect the component actually rendered. No redesign, CSS or JS asset change.
- **Order detail rendering (4):** order-chat JavaScript called nonexistent named route `buyer.messages.get-or-create`; the existing authenticated POST route is `buyer.messages.getOrCreate`. Corrected only the view reference; no route aliases, new endpoints or order-status behavior added. Existing order lifecycle/rendering tests now pass and assert that the rendered endpoint URL exists.
- A new request-level regression exposed another confirmed defect in that same chat flow: `exists:sellers`, `exists:products`, `exists:orders` inferred request-named columns instead of each table's `id`. Corrected the lookup columns in `getOrCreateConversation()` only. Coverage rejects guests and missing referenced IDs with no writes; valid requests create the current buyer's conversation and repeated requests reuse it. No changes to conversation schema, messaging features or other handlers.
- **Search (2):** removed PostgreSQL-only `ilike` from name, description, category and suggestion queries. Laravel `whereLike`/`orWhereLike` supplies bound portable patterns, using LIKE on SQLite/MySQL and the framework's driver grammar elsewhere. Existing active-product and approved-seller filters, substring patterns, pagination and suggestion limits remain. Strengthened result assertions and added description/category case-insensitive search, inactive/unapproved exclusion and SQL-syntax-as-data coverage. No MySQL runtime writes/operations were used; MySQL compatibility was checked against the installed framework grammar, not claimed as a real MySQL query test.
- **Scaffold smoke test (1):** added `LazilyRefreshDatabase` so the public landing test has its required disposable tables (including categories). No application boot/schema workarounds or seed changes.
- **HTTPS test (1):** provider correctly uses the application environment binding, not a later change to `config('app.env')`. The test now switches both in the isolated application, executes the existing production branch, checks secure route AND asset URLs, and restores the previous binding/config/forced scheme in finally. Real environment files and production behavior untouched; no production operation occurred.

### Verification

- Focused affected/Rider/Logistics/compatibility/checkout selection: **247 tests, 246 passed, 0 failed, 1 existing skip**.
- Affected after: **47 passed, 0 failed** (3 new request/search regression cases).
- Rider: **76 passed**; registration, OTP, provider approval/rejection, document access and dashboard/session protections remain intact.
- Checkout controller/double-submit/stock concurrency: **24 passed, 1 existing skip**. The skipped real simultaneous MySQL test cannot run on SQLite; stale-stock and double-submit regressions passed.
- Full before (previous stabilization baseline, same working code at task start): **595 tests, 575 passed, 11 failed, 8 deferred, 1 skipped**.
- Full after: **598 tests, 589 passed, 0 failed, 8 deferred, 1 skipped**. All eight explicit Seller `markTestIncomplete` cases are unchanged; not counted as passes. Pass counts verified from JUnit because the compact reporter includes incomplete cases in its reported passed total.
- Final artifacts: `storage/logs/final-regressions-focused.txt`/`.xml`, `final-regressions-full.txt`/`.xml`. Earlier affected/focused artifacts exposed and guided correction of the conversation lookup defect; final focused/full artifacts are authoritative.
- Pint passed on explicit edited PHP paths, without `--dirty` or Git commands. No asset build needed: frontend asset sources/classes unchanged; Blade changes are route reference and accessibility attributes only.
- No Git commands, migrations against application databases, schema changes, local MySQL writes, production operations, dependency changes, `.env`, APP_KEY or `phpunit.xml` edits. Tests used process-only SQLite `:memory:`, array session/cache/mail and a public dummy test key. Unrelated stabilization changes and commerce services/controllers were preserved.

### Files changed in this task

- `app/Http/Controllers/Buyer/SearchController.php`
- `app/Http/Controllers/Buyer/MessageController.php`
- `resources/views/Buyer/Orders/show.blade.php`
- `resources/views/Components/buyer/account-sidebar.blade.php`
- `tests/Feature/Buyer/BuyerAccountPagesTest.php`
- `tests/Feature/Buyer/BuyerOrderLifecycleTest.php`
- `tests/Feature/Buyer/HomepageTest.php`
- `tests/Feature/ExampleTest.php`
- `tests/Feature/HttpsUrlGenerationTest.php`
- `activity-log.md`

No failing tests remain. The eight approved Seller feature/policy deferrals and isolated real-MySQL concurrency verification remain outstanding; neither was hidden or converted to a pass.
