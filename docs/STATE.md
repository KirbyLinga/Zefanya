# State

Branch: integration/hardened  HEAD: e036a01  Date: 2026-11-03

## Test counts (HEAD e036a01)
- php artisan test: 401 passed, 0 failed (1858 assertions)
- flutter test: 749 passed, 0 failed
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
