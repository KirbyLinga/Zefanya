# Pass 0 — Recon: Rider Registration (read-only)

Date: 2026-10-16. App root: `web/` (Laravel). No app code changed.

## 1. Stack
- PHP 8.5.9, Laravel ^13.17, Eloquent, Blade + Tailwind v4/Vite, socialite.
- Dev DB MySQL (`Zefanya` @127.0.0.1); tests sqlite `:memory:`, `MAIL_MAILER=array`, `QUEUE=sync`, `SESSION=array`.
- Env fix this pass: enabled `pdo_sqlite` + `sqlite3` in `C:\PHP\php.ini` (suite was red: 451F/36P `could not find driver`; after fix `LogisticsRegistrationTest` 22P/68A in 7.5s).
- Commands: `cd web; php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `php artisan route:list --name=X`, `php artisan serve`, `npm run build`.
- Timezone: `config/app.php` = UTC; business Asia/Manila must be explicit in code (`now('Asia/Manila')`).
- E2E: Playwright, only `playwright/buyer-registration.spec.ts`.

## 2. Folder map (rider/logistics/auth)

- `routes/logistics.php`: dashboard + incoming-parcels + parcel-sorting + delivery-assignments/monitoring only, inside `auth:logistics` + `logistics.approved`. NO rider routes.
- `routes/web.php`: `/register` type page, `/register/{buyer|seller|logistics}` store + OTP verify/resend + pending; unified `POST /login`, Google `/auth/google/*`, `POST /logout`.
- `routes/seller.php` (auth:seller+seller.approved), `routes/admin.php` (auth:admin queue + doc stream), `routes/buyer.php` (shop/cart/checkout/orders/account).
- Controllers Logistics/: Dashboard, DeliveryAssignments, DeliveryMonitoring, IncomingParcels, ParcelSorting, RegisterLogistics, VerifyLogisticsOtp. No RiderController / activation controller.
- Requests: Buyer/Seller/Logistics Store*RegistrationRequest (+MarkDeliveryFailed, StoreProduct). No rider request.
- Models: Courier = rider row today (provider FK RESTRICT, name, phone(11), service_area JSON, is_available, vehicle_type, max_weight_grams); LogisticsProvider (Authenticatable+Notifiable, status enum cast, issueOtp/otpMatches/markEmailVerified); Buyer, Seller, Admin; SellerOrder (courier_id, delivery_courier_id).
- Enums: LogisticsProviderStatus (pending_verification/pending_approval/approved/rejected); OrderActor::Rider = status-hop actor only, not a login role.
- Auth: guards web/admin/buyer/seller/logistics (config/auth.php) — NO rider guard. UnifiedLoginController tries seller,logistics,buyer, requires status approved. EnsureLogisticsApproved logs out + redirects non-approved. bootstrap/app.php aliases + per-prefix redirects.
- Views: Logistics/{Dashboard,DeliveryAssignments,DeliveryMonitoring,IncomingParcels,ParcelSorting,register-logistics-*}; Auth/Register-Type.blade.php has Buyer/Seller/Logistics cards only (no Rider — TC-029 UI half-satisfied). Sidebar dead links route=>null for Rider Management / Courier Applications.
- Mail: MAIL_MAILER=log dev, array test; OTP/decision via Notifiable::notify(), asserted with Notification::fake(). No activation mail exists.
- Uploads: local disk = storage/app/private; precedent mimes:jpg,jpeg,png,pdf max:5120 + Admin DocumentController@stream (auth:admin, nosniff). No rider uploads, no magic-byte check anywhere.
- Validators to reuse: buyer request strongest (email trim+lower, +63→09, email regex, password complexity, ^09\d{9}$, before:today + before_or_equal:-18 years, uploads mimes+max). Seller adds letters-only names + cross-role email. Logistics weakest (names max100, password min8+confirmed, birthday before:today, no phone uniqueness).
- Tests: tests/Feature/{BuyerRegistration,SellerRegistration,LogisticsRegistrationTest,Auth/Google*,Logistics/*,Seller/*,Buyer/*,DemoRidersSeederTest,...}. Patterns: LazilyRefreshDatabase, Storage::fake, UploadedFile::fake, Notification::fake, Carbon::setTestNow, actingAs, postJson/get.
- Seeders: DatabaseSeeder→AdminSeeder only. DemoRidersSeeder (2 providers+5 couriers keyed by phone), TestCouriersSeeder (dev-only). Factories for all roles + Courier.

## 3. Add Rider UI/API, data model, enforcement gaps

- Add Rider: NONE. `route:list --name=logistics` shows 14 routes, zero rider; grep Add Rider/store rider hits only the unimplemented contract; no Logistics/Riders views.
- Data model gap: couriers lacks first/last/dob/email/mobile/license_no/license_expiry/plate(canonical)/license_path/orcr_path/status/password/token fields + global unique constraints for email/mobile/license/plate (spec A3/A4/A14).
- Auth gap: no rider guard/provider/middleware/login/dashboard; UnifiedLogin + logout + Google callback need rider wiring.
- Activation gap: no token table (hashed, single-use, 24h), no set-password page (A5), no resend-invalidates-previous, no Pending Activation login denial.
- Upload gap: no magic-byte check; 5,242,880-byte boundary untested; .jpeg question open.
- Contract `contracts/2026-10-04-rider-management-phase-2.md` is a DIFFERENT feature (provider CRUD pending→approved/rejected, plate unique-per-provider) and was never implemented. SYSTEM_FLOW_AUDIT.md:113,262-263 confirms no rider CRUD/guard/routes.

## 4. Baseline

- Before PHP fix: 451 failed / 36 passed (`could not find driver`, sqlite :memory:).
- After enabling pdo_sqlite+sqlite3: LogisticsRegistrationTest 22 passed / 68 assertions in 7.5s (proof harness works). Full suite re-run pending in Pass 2.

