# State

Branch: integration/hardened  HEAD: 1c65cce  Date: 2026-11-04

Base branch is integration/hardened. Never branch from feature/phase-f or snapshot/pre-hardening-merge. The snapshot holds a checkout voucher UI, a tracking map and web/app/Services code that have NOT been security-reviewed.

## Test counts (HEAD 1c65cce)
- php artisan test: 433 passed, 0 failed (2060 assertions)
- flutter test: 753 passed, 0 failed
- flutter analyze: 0 issues

## Worktrees
- Main only

## Provenance (step 6)
- storeProof null check created in 929bf46 (H1), always intact.
- fix/security-hardening and integration/hardened: check present, never commented out.
- feature/phase-f and snapshot/pre-hardening-merge: file didn't exist yet.

## Mutations verified (step 3, integration/hardened e036a01)
- d1 email_verified in claimsProblem -> CAUGHT: 401 expected, got 200
- d2 email_verified in GoogleApiTokenVerifier only -> PASSES (claimsProblem catches); d3 (both removed) -> CAUGHT
- d4 audience in claimsProblem -> CAUGHT: 401 expected, got 200
- d5 issuer in claimsProblem -> NOT CAUGHT by integration test; caught by new ClaimsProblemTest unit test
- d5 expiry in claimsProblem -> CAUGHT: 401 expected, got 200
- f assigned-courier in service -> NOT CAUGHT by HTTP test (controller checks); new AssignedCourierServiceTest catches directly
- g1 token expiry -> CAUGHT: 422 expected, got 200
- g2 token version -> CAUGHT: 422 expected, got 200
- j-courier courier.approved middleware removed -> CAUGHT: 403 expected, got 200
- j-review DELIVERED requirement removed -> CAUGHT: 422 expected, got 201
- Duplicate email_verified check removed from GoogleApiTokenVerifier; canonical check in claimsProblem only

## Mutations verified (step 1, integration/hardened 968aa77)
- 1a-issuer: ClaimsProblemTest test_wrong_issuer_is_rejected -> CAUGHT
- 1b-controller: CourierMilestoneController abort_unless removed -> PASS (service catches it — duplicate)
- 1b-service: OrderDeliveryService assigned-courier removed -> AssignedCourierServiceTest CAUGHT
- 1c-tokeninfo: GoogleAuthController catch block re-enables tokeninfo -> Http::assertNothingSent CAUGHT
- 1d-issuer-verifier: GoogleApiTokenVerifierTest test_wrong_issuer_throws -> CAUGHT
- 1d-audience-verifier: GoogleApiTokenVerifierTest test_wrong_audience_throws + test_d4_audience -> CAUGHT
- 2-mass-assignment: status back in $fillable + createFromRegistration accepts $data['status'] -> MassAssignmentGuardTest CAUGHT
- 3-delivered-writers: $order->status='delivered' in controller -> DeliveredWritersTest CAUGHT
- 5-route-mutation: bare /api/v1 route with no auth -> RouteMatrixTest CAUGHT

## HTTP BOM proof (step 4)
- GET /api/v1/psgc/regions: first byte 0x7B (JSON)
- GET /api/v1/buyer/me (no token): first byte 0x7B (JSON, 401)

## Snapshot diff (step 5)
- 2 test files only on snapshot: checkout_voucher_test.dart (5 tests), tracking_map_test.dart (6 tests)
- 4 non-test files only on snapshot: 2 mobile/lib, 1 mobile/test/support, 1 web/app/Services
- See docs/snapshot-diff.md

## Route reality (step 5)
- Total routes: 173
- /api/v1 routes: 37 (audit had 35)
- New since audit: api.v1.buyer.orders.delivery-pass, api.v1.buyer.orders.delivery-proof
- Auth guard test: RouteMatrixTest iterates Route::getRoutes() (dynamic)
- Throttle guard test: ApiRateLimitingTest iterates $router->getRoutes() (dynamic)
- Mutation: bare /api/v1 route with no auth -> RouteMatrixTest catches

## New tests added this session
- web/tests/Unit/ClaimsProblemTest.php (9 tests)
- web/tests/Feature/AssignedCourierServiceTest.php (1 test)
- web/tests/Unit/GoogleApiTokenVerifierTest.php (8 tests)
- web/tests/Feature/MassAssignmentGuardTest.php (7 tests)
- web/tests/Feature/Security/DeliveredWritersTest.php (1 test)
- mobile/test/live_mode_honesty_test.dart (4 tests)
- RouteMatrixTest: added delivery-pass/proof to 401 data provider

## Tests that must exist (step 4) — all present
- Admin override writes audit row: test_admin_override_delivers_with_a_reason_photo_and_audit_row
- DELIVERED requires verified code AND proof photo: test_success_requires_and_stores_a_proof_photo
- Valid photo with null hash refused: test_null_hash_never_allows_delivery

## Delivered writers (step 3)
- See docs/delivered-writers.md
- Only OrderDeliveryService.php and OrderStatus.php assign delivered status

## Mass assignment (step 2)
- status, email_verified_at, is_admin, role removed from $fillable on Buyer, LogisticsProvider, Seller
- Admin controllers use forceFill()->save() instead of update()
- ProductCatalogApiTest setup line changed (listed in CHANGES_FOR_FRIEND.md)

## Open items
1. Forgot password: dead stub, hidden in live mode only
2. Chat, promotions, returns: no backend
3. Voucher stacking: app shows local savings server doesn't record (hidden in live mode)

## Worktrees
- Main only

## Provenance (step 6)
- storeProof null check created in 929bf46 (H1), always intact.
- fix/security-hardening and integration/hardened: check present, never commented out.
- feature/phase-f and snapshot/pre-hardening-merge: file didn't exist yet.

## Mutations verified (step 3, integration/hardened e036a01)
- d1 email_verified in claimsProblem -> CAUGHT: 401 expected, got 200
- d2 email_verified in GoogleApiTokenVerifier only -> PASSES (claimsProblem catches); d3 (both removed) -> CAUGHT
- d4 audience in claimsProblem -> CAUGHT: 401 expected, got 200
- d5 issuer in claimsProblem -> NOT CAUGHT by integration test; caught by new ClaimsProblemTest unit test
- d5 expiry in claimsProblem -> CAUGHT: 401 expected, got 200
- f assigned-courier in service -> NOT CAUGHT by HTTP test (controller checks); new AssignedCourierServiceTest catches directly
- g1 token expiry -> CAUGHT: 422 expected, got 200
- g2 token version -> CAUGHT: 422 expected, got 200
- j-courier courier.approved middleware removed -> CAUGHT: 403 expected, got 200
- j-review DELIVERED requirement removed -> CAUGHT: 422 expected, got 201
- Duplicate email_verified check removed from GoogleApiTokenVerifier; canonical check in claimsProblem only

## HTTP BOM proof (step 4)
- GET /api/v1/psgc/regions: first byte 0x7B (JSON)
- GET /api/v1/buyer/me (no token): first byte 0x7B (JSON, 401)

## Snapshot diff (step 5)
- 2 test files only on snapshot: checkout_voucher_test.dart (5 tests), tracking_map_test.dart (6 tests)
- 4 non-test files only on snapshot: 2 mobile/lib, 1 mobile/test/support, 1 web/app/Services
- See docs/snapshot-diff.md

## New tests added this session
- web/tests/Unit/ClaimsProblemTest.php (9 tests)
- web/tests/Feature/AssignedCourierServiceTest.php (1 test)

## Open items
1. Mass assignment: Buyer::$fillable still has 'status' — not hardened at model level
2. Forgot password: dead stub, hidden in live mode only
3. Chat, promotions, returns: no backend
4. Voucher stacking: app shows local savings server doesn't record
