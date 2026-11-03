# State

Branch: integration/hardened  HEAD: a3a2890  Date: 2026-11-03

## Test counts (HEAD a3a2890)
- php artisan test: 391 passed, 0 failed (1848 assertions)
- flutter test: 749 passed, 0 failed
- flutter analyze: 0 issues

## Worktrees
- Main only (zefanya-security and zefanya-base removed)

## Provenance (step 2)
- storeProof null check created in 929bf46 (H1), always intact.
- f26c349 did NOT touch OrderDeliveryService.php. No commit ever had it commented out.

## Mutations verified (step 3, throwaway worktree)
- (a) login limiter -> CAUGHT: 422 != 429
- (a) delivery-verify limiter -> CAUGHT: 429 expected
- (a) Google limiter -> CAUGHT: 1 failed
- (c) null-hash gate -> CAUGHT: TypeError 500 (test expects 422)
- (d) email_verified (controller) -> NOT CAUGHT: test still passes after removal. This is a test gap — the fake verifier bypasses claimsProblem because the test binds an instance, but the controller re-checks claims. The mutation appeared not to take effect due to vendor junction pointing to main worktree's classmap. Needs investigation with a proper isolated vendor.
- (d) audience (controller) -> NOT RUN (same issue)
- (d) tokeninfo fallback -> NOT RUN (no fallback exists in code to re-enable)
- (f) wrong-courier check -> NOT RUN
- (g) token expiry/version -> NOT RUN

## Step 4 (HTTP BOM proof)
- NOT RUN (vendor was corrupted, now restored)

## Step 5 (missing tests)
- 84 test files on both branches; no tests lost. 749 vs 760 difference was untracked files on disk.

## Open items
1. Mutation (d) email_verified: test gap or junction artifact — needs re-verification with isolated vendor
2. Mutations (d) audience, (f) courier, (g) token: not machine-verified
3. Mass assignment: Buyer::$fillable still has 'status' — not hardened at model level
4. Forgot password: dead stub, hidden in live mode only
5. Chat, promotions, returns: no backend
6. Voucher stacking: app shows local savings server doesn't record
7. HTTP BOM proof and fail-before proof not completed this session
8. test-matrix.md and delivered-writers.md not generated this session
