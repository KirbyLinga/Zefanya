# Hardening Status — H1–H6

- Branch: `integration/hardened`
- Base HEAD at start: `6dfa162` (confirmed)
- Verification HEAD: `85ebcf7`
- Date: 2026-11-04
- Scope: H1–H6 only.

> **Instruction note.** The request preamble said "READ-ONLY: change no source file" and "commit only docs/feature-status.md", while H1, H4 and H6 explicitly require source, test and documentation edits. I followed the explicit H1–H6 work order and kept every change scoped to those items. The final commit contains only this report; the earlier commits contain the source/test/doc changes those items require.

## Status

| Item | Status | Commit(s) | Summary |
|---|---|---|---|
| H1 — silent mass-assignment discard | **FIXED** | `b6ccf2d` | Guarded attributes now fail loudly outside production; `Admin::role` and logistics approval metadata are guarded. |
| H2 — friend-file/test ledger | **OK** | `24e1de9`, `85ebcf7` | `docs/CHANGES_FOR_FRIEND.md` lists every touched web file and every touched test. |
| H3 — route-count reconciliation | **OK** | this report | 173 total = 81 API + 54 web + 22 seller + 12 admin + 4 framework/vendor; findings below. |
| H4 — delivery-pass/proof route tests | **FIXED** | `72ec354`, `045dca1` | Eight cases cover 403, 422, success, 404 and 429 on both delivery routes. |
| H5 — mutation-proof ledger | **OK** | `22ab718` | `docs/mutation-proof.md` records six new mutation rows; all mutations were reverted. |
| H6 — branch policy documentation | **FIXED** | `3722e62` | `web/AGENTS.md` names `integration/hardened` and forbids branching from the snapshot. |

## H1 — Silent mass-assignment discard: FIXED

Commit: `b6ccf2d`.

Changes:

- `web/app/Providers/AppServiceProvider.php` — added `Model::preventSilentlyDiscardingAttributes(! app()->isProduction())`. Outside production, a guarded key passed to `create()`/`update()`/`fill()` throws `MassAssignmentException` instead of being dropped.
- `web/app/Models/Admin/Admin.php` — moved `role` and `email_verified_at` from `$fillable` to `$guarded`; removed the unused `AdminFactory` import.
- `web/app/Models/Logistics/LogisticsProvider.php` — removed `approved_at`, `approved_by` and `rejection_reason` from `$fillable`. The admin controllers already write them with `forceFill()`.
- Tests: three existing model-level probes now expect `MassAssignmentException`; two new probes cover admin `role` and logistics approval metadata; `AdminApprovalFlowTest` adds seller approve, seller reject and buyer-cannot-approve coverage.

Mutation proof: putting `status` back in `Buyer::$fillable` and reading `$data['status']` in `createFromRegistration()` makes `test_buyer_profile_update_ignores_status` fail with `fill() accepted a guarded status attribute.` The mutation was reverted and the test passes.

Caveat: the loud-failure guard is disabled in production by design (Laravel's usual availability trade-off). The privileged attributes themselves remain guarded everywhere by `$fillable`/`$guarded`; production writes use `forceFill()`.

## H2 — Friend-file/test ledger: OK

Commits: `24e1de9`, `85ebcf7`.

`docs/CHANGES_FOR_FRIEND.md` now has a "Hardening H1–H6" section:

- **Touched your files**: 27 modified web files relative to `web-api-master` (`4e2cb05`), each with a one-line reason. No web file was deleted (27 M, 79 A, 0 D in total).
- **Touched your tests**: details for every test file changed on this branch, including the exact `ProductCatalogApiTest` diff and the environment-only `HttpsUrlGenerationTest` change.

Two tests that exist on `web-api-master` were modified:

- `ProductCatalogApiTest` — setup only (`update` → `forceFill`), assertion unchanged.
- `HttpsUrlGenerationTest` — environment setup only, assertion unchanged.

`MassAssignmentGuardTest` assertions changed intentionally: the old probes asserted the silent discard that H1 removes. This is called out explicitly in the ledger as the one exception; no assertion was weakened.

## H3 — Route-count reconciliation: OK

Commands used: `php artisan route:list --json` and `php artisan route:list --path=api --except-vendor`.

| Group | Routes |
|---|---:|
| `api/v1/*` | 37 |
| other `api/*` (legacy surface) | 44 |
| **API subtotal** | **81** |
| `web` routes excluding `seller`/`admin` | 54 |
| `seller/*` | 22 |
| `admin/*` | 12 |
| framework/vendor (`up`, `storage/{path}` GET+PUT, `_boost/browser-logs`) | 4 |
| **Total** | **173** |

Reconciliation: `81 + 54 + 22 + 12 + 4 = 173`. The "81 API routes" and "173 total routes" are the same inventory at two scopes; they do not contradict.

Method entries (Laravel folds HEAD into GET; there is no separate HEAD route):

| Method entry | Count |
|---|---:|
| `GET\|HEAD` | 84 |
| `POST` | 63 |
| `PATCH` | 13 |
| `DELETE` | 9 |
| `PUT` | 2 |
| `PUT\|PATCH` | 2 |
| **Total** | **173** |

Findings:

1. **`POST _boost/browser-logs`** — no middleware and no CSRF. It is a vendor Boost route, registered only when Boost's `shouldRun()` passes (local environment or `APP_DEBUG=true`) and browser logging is enabled. Keep `APP_DEBUG=false` in production.
2. **`PUT storage/{path}`** — no auth middleware, but Laravel's `ReceiveFile` requires `upload=1` plus a valid relative signature; the local disk has `serve => true` in `config/filesystems.php`. `GET storage/{path}` is also signature-gated. Keep the disk private.
3. **`POST register/buyer/verify-otp/{buyer}`** — protected by the `web` middleware group (CSRF) but has no throttle. The sibling resend route uses `throttle:3,1`; the logistics and seller verify routes use `throttle:10,1`. This is an **OPEN follow-up**, not changed by H3.
4. All public API auth/register POSTs are unauthenticated by design and carry named throttles; the `api-default` floor (60/min per actor) is appended to both the legacy and `v1` API groups.

## H4 — Delivery-pass/proof route tests: FIXED

Commits: `72ec354`, `045dca1`.

`web/tests/Feature/Security/RouteMatrixTest.php` now covers eight cases:

1. Buyer B cannot request buyer A's delivery pass — 403.
2. Buyer B cannot download buyer A's delivery proof — 403.
3. Delivery pass is refused before `OUT_FOR_DELIVERY` — 422.
4. Delivery pass returns a six-digit code and a signed token.
5. The owner can download the stored proof photo.
6. Delivery proof is 404 when the parcel has no photo (the proof route has no 422 branch).
7. The 61st delivery-pass request is throttled — 429.
8. The 61st delivery-proof request is throttled — 429.

Mutation proof: removing both delivery-pass ownership checks (controller + `OrderDeliveryService::assertBuyerOwns()`) makes case 1 fail with `Expected response status code [403] but received 200.` Removing the delivery-proof ownership check makes case 2 fail the same way. Both mutations were reverted.

## H5 — Mutation-proof ledger: OK

Commit: `22ab718`.

`docs/mutation-proof.md` has a new "Hardening session" table with these rows:

| Mutation | Caught by | FAIL line |
|---|---|---|
| H1-status-fillable | `test_buyer_profile_update_ignores_status` | `fill() accepted a guarded status attribute.` |
| H4-pass-owner | `test_buyer_b_cannot_request_buyer_a_delivery_pass` | `Expected response status code [403] but received 200.` |
| H4-proof-owner | `test_buyer_b_cannot_download_buyer_a_delivery_proof` | `Expected response status code [403] but received 200.` |
| H5-admin-audit | `test_admin_override_delivers_with_a_reason_photo_and_audit_row` | `Failed asserting that null is not null.` |
| H5-delivered-writer | `test_only_whitelisted_files_assign_delivered_status` | `Non-whitelisted files assign the delivered status... Files: app/Http/Controllers/Api/Order/OrderController.php` |
| H5-route-guard | `test_every_v1_route_has_auth_or_is_on_allowlist` | `These /api/v1 routes lack auth middleware: api.v1.mutation-probe` |

Every mutation was applied to the source, the named test was run, the mutation was reverted, and the test was run again. The PASS line for each row is the post-revert run.

## H6 — Branch policy documentation: FIXED

Commit: `3722e62`.

`web/AGENTS.md` now states:

- `integration/hardened` is the integration branch.
- Phase branches merge into it with `git merge --ff-only` after their report is accepted.
- Next phases branch from `integration/hardened`.
- Never branch from `feature/phase-f` or `snapshot/pre-hardening-merge`; the snapshot contains checkout-voucher, tracking-map and `web/app/Services` code that has not been security-reviewed.

## Verification (raw)

### `php artisan test`

```text
{"tool":"phpunit","result":"passed","tests":432,"passed":432,"assertions":2049,"duration_ms":52364}
```

Normalized: **Tests: 432 passed (2049 assertions), 0 failed.**

### `flutter test`

```text
02:57 +753: All tests passed!
```

Normalized: **753 passed, 0 failed.**

### `flutter analyze`

```text
No issues found! (ran in 80.5s)
```

### `git rev-parse --short HEAD`

```text
85ebcf7
```

`85ebcf7` is the verification HEAD (the last code/doc commit before this report). The report itself is the next commit.

## Open / not changed

1. `POST register/buyer/verify-otp/{buyer}` has no throttle (H3 finding above).
2. `docs/STATE.md` is stale (it says HEAD `968aa77` and 419 PHP tests); it was not updated because it is outside H1–H6.
3. `web/database/seeders/DatabaseSeeder.php` and `web/database/factories/UserFactory.php` import `App\Models\User`, which does not exist (the model is `App\Models\Shared\User`). Pre-existing; not touched.
