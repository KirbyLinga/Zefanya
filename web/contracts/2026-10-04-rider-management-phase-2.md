# Contract: Rider management for logistics providers (Phase 2)

## Objective

Give each approved logistics provider a provider-scoped Rider Management surface for its `couriers` rows: lifecycle (`pending` → `approved` / `rejected` with reason), activate/deactivate with an on-the-job guard, add/edit with uploads on the private disk, and authorized document streaming. Tighten `CourierAssignmentService::candidateFor()` so only approved + active riders are auto-selected. Follows Step 0 report (2026-10-04) and the five decisions below; **no rider login, reports, chat, dashboard changes, or other pages.**

Decisions locked in (Step 0 approval message):
1. **No `sex` column** — dropped from the form and the migration.
2. **Composite unique index** `(logistics_provider_id, plate_number)` in the migration + Form Request friendly message. Safe: all existing rows have NULL plates (MySQL and SQLite both allow repeated NULLs in a unique index).
3. **Narrow deactivation guard** — block only when `courier_id = rider AND status IN [READY_FOR_PICKUP, PICKED_UP]` or `delivery_courier_id = rider AND status IN [ASSIGNED_TO_RIDER, OUT_FOR_DELIVERY]`. **Verified in code** (`IncomingParcelsController@toSortingCenter:105-121`): the transition to `AtSortingCenter` is followed by `$this->assignment->release($pickupCourier)`, i.e. **pickup riders ARE released at the hub**, so a "not terminal" rule would wrongly block deactivation for parcels sitting at the sorting center / sorted — the narrow guard is correct, no fix needed.
4. **Documents**: owning provider (new provider-side stream route) **and** admin (extended `Admin\DocumentController` pattern) may open them; everyone else 403/404.
5. **"Courier Applications" stays a dead sidebar link** — no application intake in this phase.

## Scope

- New migration `2026_10_04_120000_add_rider_lifecycle_columns_to_couriers_table.php` (exact body in Implementation Plan; timestamp = creation time, adjusted if it collides).
- `App\Enums\CourierStatus` (`pending`, `approved`, `rejected` + `label()`); `Courier` casts `status` + `$fillable` gains only `email, birthday, plate_number, or_cr_path, license_path` (`status/is_active/rejection_reason/logistics_provider_id` stay OUT of `$fillable` — all state changes go through dedicated actions).
- `candidateFor()` gains `->where('status', 'approved')->where('is_active', true)`; lock + ownership guard untouched.
- `App\Http\Controllers\Logistics\RiderController` (index/create/store/edit/update/activate/deactivate/approve/reject) + `RiderDocumentController@stream`; `StoreRiderRequest` / `UpdateRiderRequest`; views `Logistics/Riders/{index,create,edit}.blade.php` (+ shared `_form` partial); sidebar wires `Rider Management`; every action flashes via `<x-logistics-flash>`.
- `Admin\DocumentController` gains `rider` match arms + route regex widened (`type: …|rider`, `document: …|or_cr|license`).
- `DemoRidersSeeder` + `DemoRidersSeederTest`: add B3 (pending, Pagsanjan) + A4 (approved + inactive, Pagsanjan); existing 5 backfilled by the same `updateOrCreate` run.

## Out of Scope

- Rider login/guard/credentials; courier_applications intake; reports; chat; dashboard stat wiring; manual rider picker; reschedule/return triggers; `orders.status` rollup; unassigned-parcel visibility rule (unchanged); `admin.role` middleware rollout; rewriting `TODO.md`/`SYSTEM_FLOW_AUDIT.md`.
- Approve/reject apply to **pending rows only** (anything else → error flash); no re-review flow, no manual `is_available` toggle, no hard delete.

## Research Findings

- `couriers`: `id, logistics_provider_id (FK RESTRICT), name, phone(11, not unique), service_area JSON (municipality names), is_available (default true), vehicle_type (default 'motorcycle'), max_weight_grams (default 5000)`; **no** status/active/identity/doc columns; **no** `Schema::table('couriers')` migration exists.
- No rider routes, controllers, views, or policies exist; sidebar has dead `Rider Management` / `Courier Applications` (`route => null`).
- `DocumentController@stream` pattern: `auth` guard → `findOrFail` → private `getDocumentPath()` match → `exists()` check → `$disk->download(..., nosniff)`; 403/404 semantics proven by `AdminDocumentStreamingTest`.
- `CourierFactory` exists (default area `['Quezon City','Makati','Taguig']`); untouched.
- Existing factory-created couriers carry no status/is_active → both defaults (`approved`, `true`) keep the whole existing suite green.

## Implementation Plan

**Step 1 — migration** (`database/migrations/2026_10_04_120000_add_rider_lifecycle_columns_to_couriers_table.php`):
```php
Schema::table('couriers', function (Blueprint $table): void {
    $table->string('status', 20)->default('approved')->after('phone');
    $table->boolean('is_active')->default(true)->after('status');
    $table->string('email')->nullable()->unique()->after('is_active');
    $table->date('birthday')->nullable()->after('email');
    $table->string('plate_number', 20)->nullable()->after('vehicle_type');
    $table->string('or_cr_path')->nullable()->after('plate_number');
    $table->string('license_path')->nullable()->after('or_cr_path');
    $table->text('rejection_reason')->nullable()->after('license_path');
    $table->unique(['logistics_provider_id', 'plate_number'], 'couriers_provider_plate_unique');
});
// down(): dropUnique('couriers_provider_plate_unique') + dropColumn([...8 columns...])
```
No existing column/index/FK touched; `down()` is a clean inverse.

**Step 2 — eligibility** (`CourierAssignmentService::candidateFor`, only change):
```php
return Courier::query()
    ->when($provider !== null, fn ($q) => $q->where('logistics_provider_id', $provider->getKey()))
    ->where('status', CourierStatus::Approved->value)
    ->where('is_active', true)
    ->where('is_available', true)
    ->where('max_weight_grams', '>=', $weight)
    ->orderBy('max_weight_grams', 'asc')
    ->get()
    ->first(fn (Courier $courier) => $this->servesMunicipality($courier, $municipality));
```
`lockForUpdate` + `assertCourierBelongsToProvider` untouched. Enum import added.

**Step 3 — screens.** Routes (`routes/logistics.php`, all inside the existing `auth:logistics` + `logistics.approved` group):
`riders.index` GET `/logistics/riders?tab=all|pending|approved|inactive|rejected` (+ server counts, invalid → `all`);
`riders.create/store` GET+POST `/logistics/riders/create` (providers add as **approved**);
`riders.edit/update` GET+PUT `/logistics/riders/{courier}/edit`;
`riders.activate/deactivate` POST `…/{courier}/activate|deactivate`;
`riders.approve/reject` POST `…/{courier}/approve|reject` (reject needs `rejection_reason`, pending-only);
`riders.documents.stream` GET `…/{courier}/documents/{document}` (`where('document','or_cr|license')`).
`RiderController`: every query/route-bound model scoped via `->where('logistics_provider_id', $provider->id)` / `firstOrFail()` (cross-provider = 404 on reads, 403-or-404 on writes — write tests to pin whichever Laravel produces); `index` shows name, phone, vehicle, plate, areas, availability badge, status badge; `_form` partial shared by create/edit; upload dirs `rider-or-crs/`, `rider-licenses/` on the private `local` disk; old files deleted on replace; deactivation guard = decision-3 narrow rule → error flash on block, success otherwise; approve clears `rejection_reason`, reject sets it; no delete route. Sidebar: `Rider Management` → `logistics.riders.index`.

**Step 4 — validation.** `StoreRiderRequest`: name required ≤100; email nullable email + `unique:couriers,email`; phone required `regex:/^09\d{9}$/`; birthday required date before today **and 18+** (`before:` today-minus-18-years); vehicle_type in `[bike, motorcycle, van]`; plate nullable ≤20 + unique-per-provider with friendly message; max_weight required integer ≥1 (≤50000 sanity cap); service_area required array min:1 of non-empty strings ≤100 each, normalized (trimmed, de-duplicated); `or_cr` + `license` required files `mimes:jpg,jpeg,png,pdf` `max:5120`. `UpdateRiderRequest`: same but uploads nullable, email/plate unique-ignore-self (+ provider scoping on the plate rule).
**Step 5 — security.** See Security section. **Step 6 — demo data**: seeder adds B3 (`09170000023`, pending, Pagsanjan) + A4 (`09170000014`, approved + `is_active=false`, Pagsanjan) via the same `updateOrCreate(phone)`; production guard + non-registration in `DatabaseSeeder` unchanged; placeholder identity/docs on the demo rows without overwriting the 5 existing riders.

## Security

- Every rider query and every route-bound `Courier` is scoped to the acting provider (`logistics_provider_id`); Provider B on Provider A's rider: reads 404, writes denied (403 or 404 — tests pin the exact code), document stream 403/404; no state change, no file leak. Admins reach rider docs only through the extended `Admin\DocumentController` (`auth:admin`).
- `$fillable` stays an allow-list and excludes `status`, `is_active`, `rejection_reason`, `logistics_provider_id` — these are written only by code (create-as-approved, action methods, seeder `updateOrCreate`), never from request data.
- Uploads: same private-disk pattern as admin docs; `mimes + max` server-side; `where()` route constraints; `nosniff` on stream; no public URLs.
- Rejection flows validate reason input server-side (required, string, max 1000).

## Data Integrity

- Columns are additive with safe defaults; existing rows read as approved + active; NULL plates coexist under the composite unique (MySQL + SQLite).
- Eligibility change is additive filtering inside the existing `DB::transaction` + lock + ownership guard; assignment writes unchanged.
- Deactivation guard reads statuses inside the action's transaction; activation/approve/reject are single-row writes; no permanent records (`orders`/`seller_orders`/`payments`/histories) touched.
- `down()` drops the index + columns; reverting after B3/A4 exist loses those rider rows' lifecycle fields only.

## Tests

New `tests/Feature/Logistics/RiderManagementTest.php` (provider actingAs, LazilyRefreshDatabase):
- index: 200, tabs filter server-side (all/pending/approved/inactive/rejected each show only their rows + correct counts; invalid tab → `all`); empty states; another provider's riders never listed; guest + buyer-guard → login redirect.
- store: success (approved, files on private disk, exact flash asserted) + render-proof that the success flash appears on the following index page; failures: bad phone, under-18 birthday, bad vehicle, zero areas, missing uploads, duplicate plate in same provider (friendly message), **same plate in another provider allowed**.
- update: success (uploads optional, old files replaced + deleted); ignored `status`/`is_active` in payload (mass-assignment proof — stays approved/active); 404 on other provider's rider.
- activate/deactivate: success flashes; deactivation **blocked with error flash** when rider has `courier_id` row at READY_FOR_PICKUP/PICKED_UP or `delivery_courier_id` row at ASSIGNED_TO_RIDER/OUT_FOR_DELIVERY; **allowed** (success) when the rider's only rows are at AtSortingCenter/Sorted/Delivered/Returned; already-active/inactive → explanatory flash, no-op.
- approve/reject: pending → approved clears reason / rejected sets reason + success flash; non-pending → error flash, no change; reject without reason → validation error.
- documents: owner streams or_cr + license (200 + contents + `nosniff` header); other provider 403/404; guest/login-redirect; missing file 404; wrong kind 404; public disk missing + no `public/storage` file.
- every mutating test asserts no state change on the denial path.
Extend `DemoRidersSeederTest`: B3 pending + A4 inactive created under the right providers, counts updated (A=4 incl. A4, B=3 incl. B3), second run still duplicate-free, and the **assignment candidate query for Pagsanjan returns only A2/B1 (never B3/A4)** — eligibility exclusion proven at the query level.
Then `php artisan test --compact` (full suite) + `vendor/bin/pint --dirty --format agent`.

## Rollback

Code: delete `RiderController`, `RiderDocumentController`, the two Form Requests, `CourierStatus`, `Logistics/Riders/*` views, `RiderManagementTest` additions; revert `routes/logistics.php`, sidebar, `Courier` model, `CourierAssignmentService`, `Admin\DocumentController` + admin routes, `DemoRidersSeeder(+Test)`; `view:clear`. Schema: `php artisan migrate:rollback --step=1` runs the new migration's `down()` (drops the composite index + 8 columns; data loss limited to the new lifecycle/identity/doc fields on rows created after it). No permanent records are written by this feature, so rollback touches no business history.

## Open Questions

1. **Cross-provider write denial code**: scoped `firstOrFail()` yields 404 on reads; for POST actions the gate may produce 403 or 404 depending on final wiring — I will test-pin whichever Laravel returns rather than forcing one. Acceptable?
2. **`birthday` is display-only**, never used in logic — keep the column (spec asks for it) or drop to reduce PII surface? Default plan: keep, nullable.
3. **`email` global-unique vs per-provider**: plan uses global `unique:couriers,email` (simplest; riders don't log in). Per-provider instead?
4. **Edit form `service_area` widget**: plan assumes a free-text comma input normalized server-side (trim/de-dupe) rather than a fixed municipality list, since no canonical municipality table exists. Acceptable, or supply a fixed list?
5. **Admin rider-doc access**: plan extends `Admin\DocumentController` with `rider` arms. Admins would need a rider's id — no admin rider-list UI is built here; is link-only access (via a known URL) sufficient, or defer admin arms entirely?
6. **Los Baños double-covered in demo data**: after B3/A4, Pagsanjan has A2(+A4 inactive)+B1(+B3 pending). Any change wanted to which areas the seeded riders cover?
7. **Migration timestamp**: `2026_10_04_120000` is a placeholder — renamed to the actual creation minute at build time if it collides with another file.

## Acceptance Criteria

- Migration applies cleanly on a fresh sqlite test DB **and** the dev MySQL DB (verified via the seeder + suite; NULL plates coexist, old factory rows read approved/active).
- All rider actions work provider-scoped with asserted flashes (incl. render-proof on at least one success path per action group).
- Pending/rejected/inactive riders never appear in `candidateFor()` results (query-level test) and deactivation is blocked exactly on the decision-3 statuses.
- Cross-provider attempts are denied with no state change, no file leak, no history rows.
- Documents stream with `nosniff`, 404 on missing, absent from the public disk.
- Full suite green (`php artisan test --compact`), Pint clean, no edits to executed migrations, no `$guarded = []`, no rider login.

## Developer Approval

- [ ] Research completed
- [ ] Plan reviewed
- [ ] Open questions resolved
- [ ] Scope approved
- [ ] Implementation approved

Developer approval:

Date:

Notes:
