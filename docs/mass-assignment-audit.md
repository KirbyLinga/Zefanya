# Mass-Assignment Audit (S0-lite item 0.1)

- Branch: `integration/hardened`
- HEAD at audit start: `69dc883`
- Date: 2026-11-05
- Guard: `Model::preventSilentlyDiscardingAttributes(! app()->isProduction())` in `web/app/Providers/AppServiceProvider.php`.

## Authenticatable and role models

| Model | Table | Guard | Privileged attributes | State |
|---|---|---|---|---|
| `App\Models\Buyer\Buyer` | `buyers` | `buyer` | `status`, `email_verified_at`, `is_admin`, `role` | Not in `$fillable`; listed in `$guarded`. |
| `App\Models\Seller\Seller` | `sellers` | `seller` | `status`, `email_verified_at`, `is_admin`, `role` | Not in `$fillable`; listed in `$guarded`. |
| `App\Models\Logistics\LogisticsProvider` (rider and logistics) | `logistics_providers` | `logistics` | `status`, `email_verified_at`, `is_admin`, `role`, `approved_at`, `approved_by`, `rejection_reason` | Not in `$fillable`; account guards in `$guarded`, approval metadata simply not fillable. |
| `App\Models\Admin\Admin` | `admins` | `admin` | `role`, `email_verified_at` | Not in `$fillable`; listed in `$guarded`. |
| `App\Models\Shared\User` | `users` | `web` | none used by this app | Attribute-based `#[Fillable(['name', 'email', 'password'])]`; `email_verified_at` is not fillable. |

There is no separate `App\Models\User`; `config/auth.php` points the `web` guard at `App\Models\Shared\User`.

## Grep result

Command (PowerShell, from `web/`):

```powershell
Get-ChildItem app,routes,database -Recurse -Filter *.php |
  Select-String -Pattern '(->|::)(create|update|fill|updateOrCreate|firstOrCreate|forceCreate|forceFill)\('
```

Every match was read and classified. No `create()`, `update()`, `fill()`, `updateOrCreate()` or `firstOrCreate()` call passes `status`, `role`, `is_admin`, `email_verified_at`, `approved_at`, `approved_by` or `rejection_reason` for an account model.

Privileged writes are explicit:

- `Buyer::createFromRegistration()` sets `status` by property assignment; verification transitions use `forceFill()`.
- `RegisterSellerController`, `RegisterLogisticsController` and `CourierRegistrationService` set `status` by property assignment.
- `Admin\RegistrationController` (buyer, seller, logistics approve/reject) and `Api\Admin\AdminLogisticsController` (approve/reject) use `forceFill()`.
- `Admin\SellerRegistrationController` uses `forceFill()`.
- `Admin\OrderDeliveryOverrideController` / `OrderDeliveryService` audit writes are not account attributes.
- `GoogleAuthController` sets `email_verified_at` and `status` with `forceFill()`.

Non-privileged `status` writes found by the grep:

- `Seller\ProductController` writes the product's listing state (`draft`, `active`, `inactive`) from a validated request field.
- `SellerOrder` / `OrderDeliveryService` write order states through the order state machine, not account roles.
- `BuyerAddress::makeDefault()` updates `is_default`; `CartController` updates quantities.

No `routes/*.php` or `database/*` file mass-assigns an account privilege. `VoucherSeeder` is the only `updateOrCreate()` in `database/`; it writes voucher fields.

## Flows and end-to-end coverage

| Flow | Privileged write | Test |
|---|---|---|
| Web buyer registration | `status`, `email_verified_at` ignored | `MassAssignmentGuardTest::test_web_buyer_registration_ignores_status_and_verification` |
| Web seller registration | `status`, `email_verified_at` ignored | `MassAssignmentGuardTest::test_web_seller_registration_ignores_status_and_verification` |
| Web logistics registration | `status`, `email_verified_at` ignored | `MassAssignmentGuardTest::test_web_logistics_registration_ignores_status_and_verification` |
| API buyer registration | `status`, `email_verified_at`, `role` ignored | `MassAssignmentGuardTest::test_buyer_registration_ignores_*` (3 tests) |
| API rider registration | `status` forced to `pending_approval` | `CourierRegistrationApiTest::test_courier_registration_succeeds_and_creates_pending_provider` |
| Google buyer registration | `status`, `email_verified_at`, `is_admin` ignored | `MassAssignmentGuardTest::test_google_buyer_registration_ignores_status`; `GoogleAuthApiTest::test_new_buyer_registers_pending_and_links_the_google_sub` |
| Google rider registration | rider stays pending without a token | `GoogleAuthApiTest::test_new_rider_ends_pending_approval_without_a_token` |
| Admin approves/rejects buyer | `status`, approval metadata | `AdminApprovalFlowTest::test_admin_approves_a_buyer_and_the_decision_is_persisted`, `test_admin_rejects_a_buyer_with_a_reason` |
| Admin approves/rejects seller | `status`, approval metadata | `AdminApprovalFlowTest::test_admin_approves_a_seller_and_the_decision_is_persisted`, `test_admin_rejects_a_seller_with_a_reason`; buyer cannot approve |
| Admin approves/rejects logistics provider | `status`, approval metadata | `LogisticsRegistrationTest::test_admin_can_approve_a_logistics_registration`, `test_admin_can_reject_a_logistics_registration_with_a_reason`; unauthenticated/buyer denied |
| Admin approves a rider | `status`, approval metadata | `EndToEndAcceptanceTest` step 10 (`admin.registrations.logistics.approve`) |
| Seller product status | product listing state only | `ProductControllerTest::test_toggle_status_*` (valid states, unknown state 422, foreign product 403) |

## Real callers exposed by the guard

None. The full PHP suite was green at the start of this audit (434 passed, 2062 assertions) and the guard had already been enabled by H1 (`b6ccf2d`). No production caller needed a change in this pass.

## Mutation proof

`S0-plain-update` in `docs/mutation-proof.md`: changing `Admin\RegistrationController::approveBuyer()` from `forceFill()->save()` to `update()` makes the buyer-approval test fail with a 500 `MassAssignmentException` naming `status, approved_by, approved_at, rejection_reason`.

## Open follow-up (not changed in S0-lite)

`/api/v1/admin/logistics/{provider}/approve|reject` writes the same privileged attributes with `forceFill()` but has no dedicated success test, and it sits behind `auth:sanctum` while `Admin` does not use `HasApiTokens`. The web admin routes (the ones the demo uses) are covered above. Recorded for the S6 route/test sweep.
