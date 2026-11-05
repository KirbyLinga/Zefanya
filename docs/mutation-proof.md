# Mutation Proof

| Mutation | File:Line | Test Name | FAIL Line | PASS Line |
|---------|-----------|-----------|-----------|-----------|
| d1 | GoogleAuthController.php:164 claimsProblem email_verified | test_unverified_email_returns_generic_401 | Expected response status code [401] but received 200. | Tests: 1 passed (2 assertions) |
| d2 | GoogleApiTokenVerifier.php:92 email_verified | test_unverified_email_returns_generic_401 | PASS (no fail; claimsProblem also checks) — missing test, wrote ClaimsProblemTest | Tests: 1 passed (2 assertions) |
| d3 | Both d1+d2 removed | test_unverified_email_returns_generic_401 | Expected response status code [401] but received 200. | Tests: 1 passed (2 assertions) |
| d4 | GoogleAuthController.php:168 claimsProblem audience | test_wrong_audience_returns_generic_401 | Expected response status code [401] but received 200. | Tests: 1 passed (2 assertions) |
| d5-issuer | GoogleAuthController.php:160 claimsProblem issuer | (none — all 17 pass) | PASS (missing test) — covered by ClaimsProblemTest unit tests | 17 passed (44 assertions) |
| d5-expiry | GoogleAuthController.php:172 claimsProblem expiry | test_expired_token_returns_generic_401 | Expected response status code [401] but received 200. | Tests: 1 passed (1 assertions) |
| f | OrderDeliveryService.php:79 assigned-courier | test_a_different_courier_cannot_confirm | PASS (no fail; controller also checks) — missing test, wrote AssignedCourierServiceTest | Tests: 1 passed (2 assertions) |
| g1 | OrderDeliveryService.php verifyHandover expiry | test_expired_code_is_refused | Expected response status code [422] but received 200. | Tests: 1 passed (2 assertions) |
| g2 | OrderDeliveryService.php tokenMatches version | test_stale_token_version_is_refused | Expected response status code [422] but received 200. | Tests: 1 passed (3 assertions) |
| j-courier | routes/api.php:191 courier.approved middleware | test_pending_rider_cannot_reach_courier_jobs | Expected response status code [403] but received 200. | Tests: 1 passed (1 assertions) |
| j-review | ReviewController.php:127 DELIVERED requirement | test_an_undelivered_order_cannot_be_reviewed | Expected response status code [422] but received 201. | Tests: 1 passed (2 assertions) |
| d1-redo | GoogleAuthController.php:164 (single remaining check) | test_unverified_email_returns_generic_401 + ClaimsProblemTest | Expected response status code [401] but received 200. / null !== email_not_verified | All pass |

| 1a-issuer | GoogleAuthController claimsProblem issuer check removed | test_wrong_issuer_is_rejected (ClaimsProblemTest) | Failed asserting that null is identical to 'wrong_issuer'. | Tests:    1 passed (1 assertions) |
| 1b-controller | CourierMilestoneController abort_unless courier check removed | test_a_different_courier_cannot_confirm | PASS (service still catches it — controller check is duplicate) | Tests:    1 passed (2 assertions) |
| 1b-service | OrderDeliveryService assigned-courier check removed | test_service_rejects_a_courier_not_assigned_to_the_job (AssignedCourierServiceTest) | Failed asserting that exception of type "App\Exceptions\DeliveryHandoverException" is thrown. | Tests:    1 passed (1 assertions) |
| 1c-tokeninfo | GoogleAuthController catch block re-enables tokeninfo Http call | test_verifier_failure_returns_401_and_never_calls_tokeninfo (GoogleAuthApiTest) | Requests were recorded. Failed asserting that an array is empty. | Tests:    1 passed (3 assertions) |
| 1d-issuer-verifier | GoogleApiTokenVerifier issuer check removed | test_wrong_issuer_throws (GoogleApiTokenVerifierTest) | Failed asserting that exception of type "ErrorException" matches expected exception "InvalidArgumentException". | Tests:    1 passed (1 assertions) |
| 1d-audience-verifier | GoogleApiTokenVerifier audience check removed | test_wrong_audience_throws + test_d4_audience_check_is_present_in_verifier (GoogleApiTokenVerifierTest) | Failed asserting that exception of type "InvalidArgumentException" is thrown. | Tests:    2 passed (2 assertions) |

## Hardening session (integration/hardened, 2026-11-04)

Each mutation was applied to the source, the named test was run, the mutation was reverted, and the test was run again. The PASS column is the post-revert run.

| Mutation | File:Line | Test Name | FAIL Line | PASS Line |
|---------|-----------|-----------|-----------|-----------|
| H1-status-fillable | Buyer.php `$fillable` (+`status`) and `createFromRegistration()` reads `$data['status']` | test_buyer_profile_update_ignores_status | `fill() accepted a guarded status attribute.` | Tests: 1 passed (3 assertions) |
| H4-pass-owner | OrderController.php deliveryPass ownership check + OrderDeliveryService.php `assertBuyerOwns()` both removed | test_buyer_b_cannot_request_buyer_a_delivery_pass | Expected response status code [403] but received 200. | Tests: 1 passed (1 assertion) |
| H4-proof-owner | OrderController.php deliveryProof ownership check removed | test_buyer_b_cannot_download_buyer_a_delivery_proof | Expected response status code [403] but received 200. | Tests: 1 passed (1 assertion) |
| H5-admin-audit | OrderDeliveryService.php:149 `audit()` call removed from `adminOverride()` | test_admin_override_delivers_with_a_reason_photo_and_audit_row | Failed asserting that null is not null. | Tests: 1 passed (9 assertions) |
| H5-delivered-writer | OrderController.php temporary `$sellerOrder->status = 'delivered';` | test_only_whitelisted_files_assign_delivered_status | Non-whitelisted files assign the delivered status. Route all delivered writes through OrderDeliveryService. Files: app/Http/Controllers/Api/Order/OrderController.php | Tests: 1 passed (1 assertion) |
| H5-route-guard | routes/api.php bare `api.v1.mutation-probe` with no auth middleware | test_every_v1_route_has_auth_or_is_on_allowlist | These /api/v1 routes lack auth middleware: api.v1.mutation-probe | Tests: 1 passed (1 assertion) |

## S0-lite session (integration/hardened, 2026-11-05)

Mutation applied on a temporary branch (`tmp/mutation`), the named test run, the file restored, and the test run again. Only the mutated file was restored; no other working-tree change was touched.

| Mutation | File:Line | Test Name | FAIL Line | PASS Line |
|---------|-----------|-----------|-----------|-----------|
| S0-plain-update | `Admin/RegistrationController.php:92` `approveBuyer()` uses `$buyer->update([...])` instead of `forceFill()->save()` | `AdminApprovalFlowTest::test_admin_approves_a_buyer_and_the_decision_is_persisted` | `Expected response status code [201, 301, 302, 303, 307, 308] but received 500.` + `MassAssignmentException: Add fillable property [status, approved_by, approved_at, rejection_reason] ... on [App\Models\Buyer\Buyer]` | Tests: 1 passed (5 assertions) |

## New tests added (step 1d)
- GoogleApiTokenVerifierTest (8 unit tests): runs REAL GoogleApiTokenVerifier with stubbed Google\Client — valid, email_verified false, wrong audience, wrong issuer, expired, client throws, d1 re-run, d4 re-run
- GoogleApiTokenVerifier refactored: accepts optional ?Client $googleClient for testability (production uses new Client by default)

## Missing tests added
- ClaimsProblemTest (9 unit tests): email_verified false/missing/string"false", wrong audience, wrong issuer, expired, empty sub, empty email, valid
- AssignedCourierServiceTest: service rejects unassigned courier directly (bypasses controller)
- Duplicate email_verified check removed from GoogleApiTokenVerifier; canonical check kept in claimsProblem
