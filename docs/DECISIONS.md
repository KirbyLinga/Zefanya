# Decisions & Deviations

- Courier extension 6: C1 real-document rehearsal still requires the user's actual documents/device. Continue independent C3-TRIP-008 navigation plus the saved-address part of C3-TRIP-001; do not claim weight/schedule/pay, decline/reassignment or the full C3 module complete. Add origin/destination hub addresses through native LogisticsHub::fullAddress in the existing authorized trip resource, with null for missing address. No new route, schema, dependency, business mutation or dev migration.
- Navigation uses only the displayed saved destination hub/name, encoded into a fixed HTTPS Google Maps directions URL (https://developers.google.com/maps/documentation/urls/get-started), via existing url_launcher. No session token/driver identity/customer address/current coordinates are included. The external map handles its own location. Do not claim geofence arrival, validated pin precision, live ETA, background GPS or truck-specific routing. Missing/unusable/overlong addresses hide the action and direct the user to Logistics; native launch false/exception shows a recoverable message and leaves trip state intact. Completed/cancelled history keeps addresses without an active directions action.
- Tests: add a method to existing LinehaulDemoTest without removing/changing prior assertions. Initial empty-address fixture hit `NOT NULL constraint failed: logistics_hubs.province_name`; represent the legacy empty string instead of illegal null. New mobile lifecycle test initially awaited a synchronous callback (`This expression has type 'void' and can't be used`); call it synchronously. After that correction, initial taps used stale pre-scroll layout (Offset 189,705 outside 360x640); pump after scrolling before tapping, retaining launch/retry/state assertions and warnings. Final 39 PHP / 994 assertions and 26 mobile tests pass; analysis same eight baseline issues. Pint remains waived/unrun; no web frontend build required.
- Two explicit URL faults tested on tmp/mutation (wrong host, raw unencoded address), both detected, exact restored; return to feature branch/delete temporary branch before final 26-test pass. Existing preview session 9764 rejected hot restart with `stdin is closed for this session`; leave it intact and start updated preview on verified free port 5176 with an interactive session. Real own-Courier trip GET returns saved Pila/Santa Cruz demo hub addresses; own verification token logged out. No external map app launch or actual device directions acceptance claimed.

- Vehicle activation: user explicitly approved the specific local migration exception. Verify host localhost/127.0.0.1 and database zefanya_friend_20261107/environment local; run ONLY 2026_11_08_200000_create_linehaul_profiles.php (397.78ms). Verify this migration is alone in its batch, both tables exist, mutex id 1, profile rows 0, Couriers 1 and batches 1 RECEIVED/2–4 DRAFT unchanged. Actual API setup_required is false; editable remains false because the demo Courier has an active draft, and profile remains null. No real records/documents/approvals fabricated.
- Live upload boundary: intentionally omit required license_expiry so the FormRequest must reject before any submission/file storage. Three simultaneous exact 5 MB PNG parts passed all file validation (only expiry error/422); above-5 MB parts produced file errors/422 through the process-limited server. The first PowerShell -Form attempt hit a shared temporary-file handle error (`The process cannot access the file ... because it is being used by another process`); retry once with in-memory MultipartFormDataContent/ByteArrayContent and explicit disposal, successfully test both boundaries, remove only the own leftover temporary fixture, revoke test tokens and reconfirm zero profiles/unchanged batches. This verifies local PHP transport/validation, not a real Courier's document acceptance or hosted deployment settings.

- Courier vehicle/profile item 2: implement actual Off Duty submissions, professional license/codes/expiry, normalized plate, truck type/capacity and private license/OR-CR/NBI uploads; company-only roster/review/capacity confirmation and immutable versioned decisions. Submitted pending/rejected/expired records block new readiness; all accounts without any submission retain previous presentation access. Mandatory onboarding of every provisioned Courier is still deferred; never label Active/email verification as document approval. No real license/plate/document/GPS was fabricated, no existing trip advanced, no local migration run.
- Cross-role plates: both application Rider creation paths and Courier submissions take a shared singleton mutex in their existing transaction; use locking current reads for duplicates so MySQL REPEATABLE READ snapshots cannot hide a newly committed other-role plate. This covers application writers, not direct database imports/SQL/model factories; real concurrent MySQL requests still need rehearsal. No Rider self-application redesign. Local activation needs one new migration containing only two additive tables; prior seal/duty permissions do not authorize it.
- Documents: store random names on the existing private disk, validate detected MIME and image contents with <=5120 KB, keep old references/files when validation/save fails, delete replaced files only after commit, and preserve newly committed records if old-file cleanup reports an error. Downloads require the real Courier or owning approved Logistics and never expose storage paths. PDF opening uses the native open/share/save flow rather than embedding a PDF renderer. File selection follows Flutter's file_selector documentation (https://pub.dev/packages/file_selector); add only its four missing package entries.
- Intended test change: linehaul_profile_test now checks the vehicle-record entry and company-review copy instead of asserting uploads are unavailable. Retain all identity/privacy/layout assertions. New vehicle preview test originally used a `.first` finder before its lazy list child existed (`Bad state: No element`); select the specific saved-document button by key instead, retaining every preview assertion. Add dirty text rebuilds so Back immediately prompts, and show actual server field messages on validation errors.
- Verification notes: the first full mobile process ended during the additional CONTINUE without a completion summary; do not count its partial +615 result as a pass. Its complete rerun reached +901. Final focused rerun follows test-only brace/import fixes and restoration of unrelated ApiClient formatting. Initial analysis added test-only lint issues; fix them and compare final output to the eight pre-existing issues. Pint remains waived/unrun. Web assets were built before dependent PHP tests.
- Local PHP upload cap is 2M/request cap 8M, below the 5 MB document requirement. Provide a project-local launcher with 5M/20M process settings and bind only 127.0.0.1; do not modify php.ini or .env. Creating the new tools folder initially failed through apply_patch (`Failed to create parent directories`); explicitly create the directory, then retry the same patch successfully. A separate-port launcher smoke test touches no database setup or demo records.

- Profile tooling corrections: initial related PHP invocation used `tests/Feature/InternalManifestDatabaseTest.php` instead of its existing Logistics subdirectory (`Test file "tests/Feature/InternalManifestDatabaseTest.php" not found`); correct the paths, then all 70 tests pass. An attempted extra preview server failed because port 5175 was already in use; reuse session 8316 and hot restart (1189ms), leaving other processes intact. Dependency resolution from that attempted run left no tracked dependency changes. The existing preview, not the refused new process, serves this version.

- Courier extension 5 is the profile visibility foundation, not completion of the full C1 vehicle/document module. The current driver schema has no vehicle/license/private document records; do not invent them or represent an active account as document-verified. Add a read-only branded profile screen reached from Account, showing only the authenticated driver's identity, email verification, own company and home hub, plus an explicit vehicle/document limitation. The next numbered item is additive vehicle/license/document submission and owning-company review, with a separate local migration exception required before activation. No new route, schema, demo account or business-data change in this item.
- Profile verification: extend the existing GET profile resource with an explicit nested allowlist and no-store/private; existing real-role middleware, policy/FormRequest and shared throttle remain. Reject attempted identity/company/hub selectors with 422 and test inaccessible ID paths, inactive account/company/hub, privacy and rate limiting. Existing tests are unchanged. New mobile tests retain all assertions; target the parent profile scrollable specifically because SelectableText adds nested scrollables. The initial mobile run exposed a Future-returning setState retry callback; fix the handler and rerun all 12 Courier mobile tests successfully. Pint remains waived/unrun.
- Profile live check initially could not connect because the local backend was stopped. Start the existing project's server without configuration/database changes, retry login/profile, verify actual Demo Laguna Express/Pila Station Hub and no-store/private, and revoke only the test session token. Do not describe the initial connection refusal as an application failure or a passing check.

- Courier extension 4: add owning-Logistics Close Receiving for a reported-arrived, dispatched Courier batch. Review/confirmation, a nonblank missing-parcel explanation and an exact pending-item snapshot are mandatory (max 500 pending items). Under the manifest lock, compare the current unreceived/unreleased set; refuse stale/partial/foreign-ID lists. Reuse native seal confirmation and per-item missing incidents inside one transaction, preserving physical receipts/custody, native delivery status, notifications and Courier notes. Closed replays are idempotent and do not edit explanations. No schema or mobile source change; no local demo trip was advanced.
- Closeout test findings: native numeric throttles shared a counter between Courier and Logistics IDs in the fixture, so this new endpoint uses its own named, company-scoped 20/min limiter; unrelated throttles are unchanged. Courier item order after release was not deterministic, so sort the existing resource by item ID and verify note identity by tracking number. Seven tests cover ownership, confirmation/snapshot/stale refusal, reported arrival, seal mismatch, partial receipt, existing Courier notes, replay, rollback/notification cancellation and throttling/isolation. Existing tests/assertions retained; Pint remains waived/unrun.
- Verification sequencing correction: building Vite concurrently with asset-dependent tests caused one pre-existing incident page test to fail with ViteManifestNotFoundException while the output was replaced (initial related run: 67 tests, 66 passed). Build finished; rerun the full related suite after manifest existence verification. No test changed to hide this failure.

- Duty activation: owner explicitly approved enabling Courier duty locally. Applied only 2026_11_08_180000_add_linehaul_duty_readiness.php (360.52ms), verified readiness fields/history table and real HTTP Off Duty/setup complete, and left checklist history empty and all demo batch statuses unchanged. No checklist or GPS position was fabricated; user must complete the actual location/checklist flow.

- Courier extension 3: add On/Off Duty with all five explicit checklist answers (tires, lights, brakes, fuel, cargo door lock). Not OK requires a note, persists a failed check, leaves Off Duty and notifies only the owning Logistics database inbox. Assignment, acceptance and departure require a passed checklist plus app-reported location updated less than five minutes ago, accuracy <=100 m; active Draft/Dispatched trips block Off Duty. Driver row locks serialize duty and assignment. Home-hub proximity/continuous background tracking remain deferred; reported coordinates are not GPS attestation. Foreground updates run once per minute and stop while paused; reopen/resume refreshes status without automatic permission prompts.
- Add only four guarded LinehaulDriver readiness fields plus linehaul_duty_checks via 2026_11_08_180000_add_linehaul_duty_readiness.php; local migration NOT RUN pending an explicit exception. Existing Couriers start Off Duty; an already-assigned legacy trip may complete a passed checklist. Duty login remains available while Off Duty. Missing schema returns Setup pending and refuses new assignment/accept/depart rather than silently weakening checks.
- Duty test changes: existing Courier fixtures now complete a passed checklist; the expired-scan test sends a fresh location heartbeat after its 11-minute time travel, preserving every original expired-token assertion. Duty GET re-reads the authenticated driver so cached guard objects cannot return stale duty state. New feature tests cover role/ownership, incomplete/failed checklist, valid location, failed notifications, active-trip Off Duty, five-minute expiry/recovery, assignment availability, private company roster and shared throttle. Dedicated mobile tests cover permission denial, checklist validation and pause/resume at 360x640/1.3 text. Android permissions already existed; iOS purpose copy now includes foreground Courier duty. Pint remains waived/unrun.

- Courier extension 2: reuse native incidents/private photo storage/notifications for missing and damaged handover parcels, without a migration. Missing uses explicit checkbox + note and no invented scan; damaged requires the existing receiving token plus a content-validated image up to 5 MB. Native canonical delivery status stays unchanged; incident holds govern further movement. Released missing items remain visible, pending counts exclude resolved items, and a later clean receipt cannot erase an earlier issue. Courier can submit one immutable own note, tied to the specific manifest item/incident via native incident events; metadata retains the full 500 characters. Remaining bulk Close Receiving and automatic missing-list workflow is not claimed.
- Test changes: existing seal-bypass fixture now includes explicit missing confirmation so it still reaches the seal guard; no assertion removed. New cross-provider receipt test expects the native 404 ownership-hiding response (initial new test expected 403). Added missing confirmation/blank-note/refusal atomicity, damage photo/type/size/private-storage, incident notifications, final status/counts, auth/ownership/note validation/immutability/actual actor and mobile 360x640/1.3-text note submission checks. No local demo data was changed. Pint remains waived/unrun.

- Courier extension, seal stage: user requested remaining Courier features; implement one stage now (seal confirmation/mismatch), keeping duty/GPS/pay/onboarding for later stages. Add only nullable `linehaul_assignments.seal_receipt`; do not migrate the local database without a new explicit exception. Native receiving requires physical seal entry once per Courier manifest; a mismatch requires a 1-500 character note, records original/observed seal, actor/time, append-only event and database-only Logistics notification, and closes as Received with Issues. No push/email is claimed. Existing non-Courier transfers are unchanged; a missing migration fails closed with a setup message. User reported camera works; independent QR/Code128/device permission acceptance remains unverified.
- Seal tests: existing successful handover fixtures now supply the matching seal at first receipt, retaining all original assertions; later parcels reuse the stored confirmation and assert one audit event. Added mismatch, blank/oversized note, missing/invalid seal, no mutation on refusal, missing/damaged bypass refusal, notification, receipt-page and Courier-history checks. Pint remains waived/unrun; no formatting bypass.

- Browser rehearsal completed with explicitly approved separate zero-value local fixture parcel #8 / batch #1 (DEMO-COURIER-20261108-01), Pila Station Hub to Laguna Province Hub. No existing order, inventory or payment was changed. Manual entry only; physical camera remains unverified. Found completed-history count dropped received/released items; retain received items while excluding removed, unreceived items. Strengthened the existing full-trip test with retained item count and received flag assertions; no assertions removed.

- Courier continuation: added a two-parcel handover regression without changing existing assertions. Departure with one unscanned parcel must fail atomically; Logistics receiving one parcel leaves Arrived, receiving both completes the trip. Separate factory orders respect the order/seller uniqueness constraint. Tests use SQLite only; local MySQL/order data untouched. PHP production code unchanged; Pint remains waived/unrun. Direct PHPUnit: 10 passed, 175 assertions, 6213 ms. Physical camera and real assigned-trip rehearsal remain open.

This file records deviations from the repository's baseline instructions or decisions required during execution.

## 2026-11-08 — Courier design integration

- User prioritized making Courier feel part of Zefanya. Reuse the existing Playfair/Montserrat typography, AppColors palette, ZefanyaMark/Loader, EditorialHeader, EmptyState, editorial card shapes and FloatingNavBar. No new visual framework, dependencies, backend routes or database writes.
- Trips/History/Account are views of the existing server list (up to 50 recent assignments) and authenticated name/email. History shows Completed/Received with Issues/Cancelled; active trips remain separate. No earnings, duty, live tracking or unsupported account controls advertised.
- Existing trip buttons keep their server requests/guards. Show failed actions through the app's timed feedback so an error cannot remain above a scrolled viewport. Native Material cards preserve ExpansionTile ink/interaction. Password visibility is a local login-field control only.
- Intended test harness adaptation: keep all linehaul_test refusal/state assertions, add a frame after ensureVisible scrolls, then drain the shared timed feedback's four-second test clock after assertions. No weakened/deleted assertions. New courier_design_test covers both palettes, 360x640 at text scale 1.3, server-backed navigation/history/account and empty/login layouts. Ten PNG renders were visually inspected; optional capture output stays under ignored mobile/build/courier-design.
- Render checks exposed a stretched route connector; constrain it to one pixel. They also exposed missing test icon fonts, so load bundled MaterialIcons and all body/display font weights in the capture harness. No existing goldens regenerated.
- Updated preview runs on 5174; previous 5173 preview had closed stdin and was preserved. New server started with tty input and hot restart recompiled successfully. UI is verified with widget renders/tests; no fresh native-browser/device/camera rehearsal claimed.
- Follow-up lifecycle check: keep the trip FutureBuilder mounted across Account navigation, and immediately observe reload futures before the next frame can subscribe. An instant server failure must not become an unhandled future. A dedicated test verifies Account stays usable and Trips subsequently shows Retry, not fake empty data.

## 2026-11-08 — combined Courier presentation scope

- Local setup exception: user explicitly answered "Yes, finish local setup" to reusing existing MySQL settings, installing only the two Courier tables and creating one demo Courier. Copied the existing ignored local configuration; verified local/mysql database zefanya_friend_20261107, persistent database cache and loopback APP_URL. Ran only the named Courier migration and provisioning command for approved Demo Laguna Express/Pila Station Hub. No other migration, order, inventory or payment was changed. Real HTTP login/profile/logout checks passed for all three demo roles; Courier cannot access Rider jobs, trips return empty until assigned; CORS preflight passed.
- Local debug runner initially failed "Null check operator used on a null value" in Flutter tools currentPackageConfig: the direct snapshot invocation omitted the SDK package configuration. Re-run with the SDK's --packages path; app serves HTTP 200 on 5173. No SDK/source fix or dependency change. Backend serves 8000. No assigned-trip/phone-camera rehearsal is claimed.
- User selected ZefanyaWMI and authorized a limited combined Buyer/Rider/Courier demo for early next week, superseding the old Courier exclusion/EXTEND order for this bounded scope. Branch from this newer integration snapshot 09d27aa into codex/combined-courier-demo, preserving the friend's work; no merge into integration/friend branches.
- Keep persisted UserRole.courier as Rider. Add linehaul for Courier and its own Sanctum account model/ability. Reuse ManifestService and native Logistics receiving, with separate actual-Courier audits; no duplicate parcel/status/payment system.
- Scope: explicitly provisioned local-demo accounts, one active assigned forward manifest, acceptance, QR/Code128/manual scans, sealed departure, reported arrival and Logistics-confirmed receipt. No public Courier registration. Missing live configuration fails closed; Courier login is live-only.
- Deferred: email activation/deep links, professional-license/NBI/vehicle onboarding, capacity/duty/checklist rules, 500 m GPS enforcement, background tracking/push, decline/reassignment/breakdown handling, earnings and full seal/discrepancy rules. Reported arrival is not location-verified; saved seal is displayed, not automatically compared. Do not claim all 104 workbook cases pass.
- Intended test change: auth_gate_routing_test now supplies a linehaul mock email for the added role, retaining every prior logout/Back assertion and adding portal absence plus root routing coverage. First full run caught this two-role fixture assumption. No assertions weakened or tests deleted.
- Baseline first ran before Composer finished (missing vendor/autoload.php); rerun after restore required process-only GD for two image fixtures. Corrected baseline: 80 tests/622 assertions and 873 mobile tests pass. No PHP configuration, dependency versions or setup scripts changed.
- Widget tests caught a missing exception import and a Future-returning setState callback; fix source and retain tests. Final mobile: 879 pass. Production-provisioning mutation initially reached lazy DB confirmation: initialize the memory test DB before simulating production, retain no-write assertions, and repeat the isolated proof successfully.
- At the initial code checkpoint, no local config/database writes were performed; the explicit setup exception above subsequently authorized only configuration, Courier tables and one new demo account. Credentials remain outside Git. Prior formatter waiver remains: Pint UNRUN, no bypass.
- Retain/report existing gaps: canBuyerCancel currently allows PLACED only, conflicting with the earlier Ready-for-Pickup cutoff; detailed S6.2 checkout/wallet, review and HTTPS/device gates remain open. Courier work does not mark these done.

## Phase A: Baseline & Integration Setup

1. **Base Branch Selection**:
   - `web-api` and `web-api-master` do not contain one another (`git merge-base --is-ancestor` returned 1 for both).
   - `web-api-master` has the newest commit (`4e2cb05` on Oct 4, 2026, 19:06:31 vs `fceb34b` on Oct 4, 2026, 18:55:23).
   - Cut `integration/mobile-backend` from `web-api-master` and checked out `mobile\` and `docs\` from `wip/phases-1-4`.

2. **Obsolete File Removal (`role_switcher_screen.dart`)**:
   - `mobile/lib/screens/role_switcher_screen.dart` was present on `web-api-master` (from commit `4e60367`) but was deleted in mobile development (commit `8d84f2e`).
   - `git checkout wip/phases-1-4 -- mobile docs` brought over tree files but left this orphaned file on disk, producing 2 `invalid_constant` errors in `flutter analyze`.
   - Removed with `git rm mobile/lib/screens/role_switcher_screen.dart`. Clean analysis restored.

3. **Backend Test Environment Fix (`HttpsUrlGenerationTest.php`)**:
   - `HttpsUrlGenerationTest::test_named_routes_start_with_https_in_production` failed because `config()->set('app.env', 'production')` does not update `$this->app['env']` in Laravel 11 container.
   - Added `$this->app['env'] = 'production'` before booting `AppServiceProvider` and restored it in `finally`, preserving test expectation.

## Phase C: Backend Vertical Slices

1. **Rule 3 Compliance in `live_auth_test.dart`**:
   - Preserved `the courier role names the missing endpoint instead of posting` by throwing `notWired('POST /register/logistics')` when registration profile lacks rider credentials/plate, and calling live multipart endpoint when rider plate is present.

2. **PSGC Hierarchy & Province-less NCR**:
   - Seeded Philippine Standard Geographic Code tables (`psgc_regions`, `psgc_provinces`, `psgc_cities`, `psgc_barangays`) from `mobile/assets/data/ph_psgc.json` via chunked upserts. NCR localities have null `province_code`, using fallback label "Metro Manila".
   - Immutable shipping address snapshot is captured on `Order` checkout via `BuyerAddress::toSnapshot()`.

3. **Restoration of Historical Migrations**:
   - Re-aligned migrations with dev database schema for `reviews`, `assigned_courier`, `chat_messages`, `maker_note`, and `promotions` using original definitions from commit `8ff3826`.

4. **Shared `OrderPlacer` Service**:
   - Shared business rules across web checkout and mobile `POST /orders` via `OrderPlacer` service (id-ordered stock locking, integer centavos, largest-remainder discount allocation).

5. **Delivery Handover Gate**:
   - Preserved server-side delivery verification: OTP stored as hash (bcrypt/sha256), rate limiting after 5 wrong attempts (423 for 15 mins), and COD exact centavo check on `PATCH /api/courier/jobs/{job}/milestone`.



## Phase D: End-to-End Acceptance

1. **Seller Order Transitions**:
   - Added `confirm()` and `ready($trackingNumber)` actions to `web/app/Http/Controllers/Seller/OrderController.php` and mapped them to `PATCH /seller/orders/{order}/confirm` and `PATCH /seller/orders/{order}/ready` so sellers can confirm orders and issue tracking numbers through the standard seller interface.
   - Enhanced `SellerOrder::assignCourier($courier, $feeMinor, $area, ?OrderStatus $status = null)` to allow assigning couriers without prematurely overriding orders that are in `READY_FOR_PICKUP` state.

2. **Multi-Role Acceptance Coverage**:
   - Automated end-to-end integration test `web/tests/Feature/EndToEndAcceptanceTest.php` covers the 15-step story across Buyer, Seller, Rider, and Admin.
   - Comprehensive checklist documented in `docs/acceptance.md` including physical device verification guidelines (Camera, Barcode/QR scanner, GPS, Google OAuth).

## Phase F: Features (Vertical Slices)

1. **Seller & Admin API Routes (Slice 5)**:
   - Added `web/app/Http/Controllers/Api/Seller/SellerOrderController.php` with `index`, `confirm`, `ready` methods.
   - Added `web/app/Http/Controllers/Api/Admin/AdminLogisticsController.php` with `pending`, `approve`, `reject` methods.
   - Added v1 routes: `GET /v1/seller/orders`, `PATCH /v1/seller/orders/{sellerOrder}/confirm`, `PATCH /v1/seller/orders/{sellerOrder}/ready`, `GET /v1/admin/logistics/pending`, `POST /v1/admin/logistics/{logisticsProvider}/approve`, `POST /v1/admin/logistics/{logisticsProvider}/reject`.
   - No new tables, columns, or enum values — purely additive route/controller surface reusing existing `SellerOrder`, `LogisticsProvider`, and `OrderStatus` models.

2. **Photo Reviews UI (Slice 1)**:
   - Added `ratingDistribution` field to `Product` model (mock data) and `_ratingDistribution()` mapper in `remote_catalog.dart` reading `rating_distribution` from server.
   - Added `_RatingDistributionBars` widget and `_ReviewPhotoViewer` (tap-to-expand) to product details screen.

3. **Voucher Wallet (Slice 2)**:
   - Rewrote `vouchers_screen.dart` with Available/Claimed/Used tabs, claim-with-ineligible-reasons, inline blocker messages.
   - Added `claimedVoucherCodes` getter to `CartState`.

4. **Search Filters (Slice 4)**:
   - Added `shipsFrom`, `supportsCod` fields to `Product` model (mock data).
   - Extended `SearchFilter` with `shipsFrom`, `freeShipping`, `supportsCod` fields; updated `filterProducts()`.
   - Added ships-from/free-shipping/COD filter chips to `search_screen.dart`.

5. **Buyer Tracking Map & TrackingRepository**:
   - Moved the tracker's milestone poll out of `CourierTrackerScreen` into `TrackingRepository` (mock/live implementations). Mock mode derives a deterministic Pila, Laguna demo route from the milestone; live mode polls `GET /orders/{sellerOrder}/milestone` every 30 s.
   - Added `TrackingMapCard` using the already-present `flutter_map`/`latlong2`: static camera, pickup/rider/dropoff pins, route line, ETA pill, OSM attribution.
   - Live mode has no coordinates because `GET /orders/{sellerOrder}/tracking` is not implemented. `snapshotFor` returns null there and the tracker omits the map rather than inventing a position; the intended shape is documented as `docs/api-contract.md` §6.4.
   - Widget/golden tests install `debugTrackingTileProvider` (`TransparentTileProvider`) so no test fetches OSM tiles.

6. **Checkout Voucher Picker**:
   - Checkout lists the cart's applied vouchers with per-code discount, a savings row and per-code remove, and opens `VouchersScreen` to apply another claimed code through `CartState.applyVoucher`.
   - The picker is cart-checkout only; Buy Now hides it because those lines have no cart-derived voucher eligibility.
   - The app's stacked-voucher pricing is local-only: `POST /orders` accepts one `voucher_code` and the live repository still sends none (§0.2 of `docs/api-contract.md`).

## Security Hardening (fix/security-hardening branch)

**Base branch:** `feature/phase-f` (commit `563a59e`) — the most recent backend work containing Phases A–F. Cut worktree at `c:\Users\Lenovo\Documents\lavarel\zefanya-security` (`fix/security-hardening`).

### H1 — Fail-Closed Delivery Handover

1. **Single writer gate:** All delivered-status writes now route through `OrderDeliveryService`. The two application code paths found were: (a) `app/Enums/OrderStatus.php` (the enum definition itself), and (b) `app/Services/OrderDeliveryService.php`. The `CourierMilestoneController` calls the service; it does not set the status directly.

2. **OTP storage:** Plain code never persisted. HMAC-SHA256 keyed on `APP_KEY` stored in `delivery_otp_hash`. Additive columns `delivery_otp_expires_at`, `delivery_otp_used_at`, `delivery_proof_path` added to `seller_orders`. New `delivery_handover_audits` table for full audit trail.



## S8B-lite (feature/s1-lite, 2026-11-06)

1. **Portrait lock:** `main.dart` locks the app to `portraitUp|portraitDown` via `SystemChrome.setPreferredOrientations([...])` before `runApp`. The app is a full-screen consumer (catalogue, orders, tracking) and never needs split-screen/slide-over.
2. **No-internet banner with Retry:** `no_internet_banner.dart` shows a toolbar-height banner when the connection is offline. `connection_watcher.dart` probes `8.8.8.8` via `InternetAddress.lookup` every 3s and sets `connectionState`. Banner is silent when online.
3. **Overflow sweep:** `overflow_sweep_test.dart` renders the 5 main buyer screens at 360x640 with textScale 1.3 and checks the top-level `RenderBox` for `hasVisualOverflow`. No real overflows found.
4. **Error handling:** `NetworkException` in `api_exception.dart` already has `isRetryable = true`, allowing screens to show a Retry button. The no-internet banner complements this with a persistent top-of-shell alert.
5. **Discard-changes confirm:** already exists in `dirty_guard.dart` (S8B L4). No new work needed.
6. **Back behaviour after logout:** already correct in `AuthGate` (single Navigator root, `popUntil((route) => route.isFirst)`). No new work needed.

## Open
- Avatar is local-only: no server upload route exists (DEVIATION in DECISIONS.md).
- API admin logistics approve/reject lacks a success test; Admin lacks HasApiTokens. Carry to S10.

3. **Signed QR token:** Payload `{order_id, nonce, expires_at, version}`; HMAC-signed with app key; never contains the code; version is the truncated hash so a reissued code immediately stales all older tokens.

4. **Admin override:** Available only to `role = 'admin'` (not `'support'`). Requires reason string + proof photo. Writes an audit row with `action = 'admin_override'`.

5. **COD bypass removed:** `DELIVERED` requires code + photo regardless of payment method. No exception for COD.

### H2 — Fail-Closed Google Sign-In

1. **Tokeninfo fallback removed entirely.** The old `GoogleTokenVerifier` service (which called `https://www.googleapis.com/oauth2/v3/tokeninfo`) was replaced with `GoogleApiTokenVerifier` using `google/apiclient`'s `Client::verifyIdToken()` (cryptographic verification, no HTTP).

2. **503 fail-closed:** If `GOOGLE_CLIENT_IDS` env var is empty, the endpoint returns 503 rather than accepting any token.

3. **Rider role via Google:** New riders created through Google arrive in `pending_approval` status. The endpoint returns `403` rather than a token — the client cannot reach authenticated routes until an admin approves.

4. **Admin role via Google:** Attempting `role=admin` via this endpoint returns `422`. Admin accounts are created out-of-band only.

5. **google_sub column:** Added nullable unique `google_sub` to `users`, `buyers`, `logistics_providers` (migration `2026_10_31_120000`).

### H3 — Named Rate Limits

All rate limits are named buckets in `AppServiceProvider::configureRateLimiting()` keyed on the actor (not the URL), so the legacy `/api/...` and `/api/v1/...` spellings of the same action share one counter and cannot bypass each other.

| Bucket | Limit | Key |
|---|---|---|
| `api-login` | 5/min | `lowercase_email\|ip` |
| `api-register` | 5/min | `ip:...` |
| `api-google` | 10/min | `ip:...` |
| `api-delivery-verify` | 10/min | courier model+id |
| `api-account-password` | 3/min | actor model+id |
| `api-uploads` | 20/min | actor model+id |
| `api-default` | 60/min | actor or ip (appended to api group) |

### H4 — Reviews

1. `order_item_id` FK added to `reviews` (migration `2026_11_01_100000`). Unique index `reviews_order_item_unique` enforces one review per line item at the DB level.

2. `verified_purchase` is server-computed (`true` when order status is `DELIVERED`). It is not readable from the request.

3. `photo_paths` JSON column (up to 4 photos). Old `photo_path` string kept for compat; backfilled on migration. Review photos go to the public disk (readable without auth).

### H5 — Access Control & Uploads

- **Mass assignment:** `Buyer::createFromRegistration()` hardcodes `status = 'pending_verification'` regardless of request input. **OK — existing behavior.**
- **Pending/rejected courier gate:** `courier.approved` middleware (`EnsureCourierApproved`) applied to all courier routes. **FIXED.**
- **Buyer cross-ownership:** OrderController and AddressController already scope to `auth()->user()`. **OK.**
- **Courier cross-ownership:** `CourierJobController::index()` filters by `assigned_courier_id = auth()->id()`. **OK.**
- **Rider documents on private disk:** `CourierRegistrationController` stores to `local` disk. **OK.**
- **Review photos on public disk:** `ReviewController` stores to `public` disk. **OK.**
- **Secrets hygiene:** `git ls-files` found zero tracked `.env`, `.key`, `.pem`, `google-services.json`, `.sql`, or `backups/*` files.

### H6 — Route Test Matrix

- `RouteMatrixTest`: guard test checks all `/api/v1/` routes have `auth:sanctum` or are on the allowlist; second guard checks all routes inherit `ThrottleRequests` (via `gatherRouteMiddleware()` which surfaces the api-group default).
- `@dataProvider` annotation replaced with PHP 8 `#[DataProvider]` attribute (PHPUnit 11 compatibility).
- `BuyerAddressFactory` created (the model lives in `App\Models\Buyer\` but the factory registry expects `Database\Factories\BuyerAddressFactory`; `newFactory()` override added to the model).

### H7 — Docs

- `docs/api-contract.md`: not regenerated from `route:list` because the security branch routes are an additive superset of the integration branch; a full regeneration would duplicate work done by the architect for the integration review. **DEVIATION:** logged here. The two new routes (`POST /orders/{sellerOrder}/delivery-pass`, `GET /orders/{sellerOrder}/delivery-proof`) are described in §6.3–6.4 shape notes.
- `docs/KNOWN_LIMITATIONS.md`: updated with "Not implemented / mock only" section for auth/chat/promotions.
- `docs/CHANGES_FOR_FRIEND.md`: updated with all security hardening changes.

### H8 — Failing Flutter Test

- **Root cause:** `stock_logic_test.dart:71` — `expect(find.text('3'), findsOneWidget)` failed because `tester.tap(find.byIcon(Icons.add))` issued a warning "Offset would not hit test on the specified widget." The `+` button in `ProductDetailsScreen` sits inside a `SingleChildScrollView`; with the test viewport fixed at `390×844`, the bottom action bar and quantity stepper are positioned near the bottom of the screen and land exactly at Y=760.3 — technically in-bounds but behind the app's `FloatingActionBar`/bottom-safe-area layer that absorbs pointer events.
- **Classification:** fixture bug. The assertion (`expect '3'`) is correct stock-clamp logic. The taps silently no-op'd, leaving quantity at 1, so `find.text('3')` found nothing.
- **Fix:** `tester.ensureVisible(plusButton)` before each tap scrolls the stepper into the visible, hit-testable area. The assertion is unchanged.
- **Pre-existing check:** The same failure reproduces on `feature/phase-f` (the base of fix/security-hardening). It was introduced when the screen's bottom-action area height increased; the test was never updated. Not a security-branch regression.

## S0-lite (integration/hardened, 2026-11-05)

1. **Base check deviation:** `integration/hardened` was at `69dc883` (13 commits after `6dfa162`; H1-H6 and WO-0) when S0-lite started. `6dfa162` was verified as an ancestor. Continued instead of stopping because those later commits are the hardening work S0.1 audits and the architect asked to resume at the first unfinished stage. Recorded in `docs/STATE.md`.
2. **Mutation restore:** restored only the mutated file (`git restore web/app/Http/Controllers/Admin/RegistrationController.php`) instead of `git restore .`, because the working tree held pre-existing uncommitted S2.1 media-picker work that must not be discarded.
3. **Flutter analyze:** the 4 reported issues are all in the pre-existing uncommitted `mobile/lib/services/media_picker.dart`; S0-lite changed no mobile file. S2.1 owns that cleanup.

## S4.4 / S5-lite (feature/s1-lite, 2026-11-05)

1. **`order_state.dart` syntax repair:** HEAD contained `try { ... } finally { ... } on ...`, which is invalid Dart and prevented the app compiling. Moved `finally` after the `on` clauses. No behaviour change: `_loading` still clears and notifies on every path.
2. **`delivery_pass_handover_test.dart` teardown:** temp-directory deletion is now best-effort (`try/catch` around `deleteSync`). On Windows a still-open file handle made the old teardown fail a passing test. No assertion was changed, weakened, or removed.

## S5-lite (feature/s1-lite, 2026-11-06)

1. **Avatar upload is client-side only — DEVIATION:** the live API has no buyer avatar-upload route (only rider document uploads), so `AvatarPicker` stores the chosen image locally through `AuthState.updateProfile` / `persistProfile` and invents no endpoint. A server-backed avatar needs a new authenticated, throttled `POST /api/v1/buyer/account/avatar` with a form request, policy, resource and feature tests before the UI can claim upload. Until then the avatar is a local medallion and does not follow the buyer to another device.
2. **Chat is mock-only:** `FeatureFlags.chatLive = !kUseLiveApi`. Every chat entry (Home inbox, product/seller, orders, order detail, buyer and rider trackers) is hidden in live mode because the server has no conversation endpoints. Order detail offers "Copy order ID" instead; nothing pretends a message was sent.
3. **Rider Settings substitute:** the live account routes are buyer-only (`/api/v1/buyer/account/*`), so rider Settings shows Vehicle & documents rather than Account & Security/Addresses it cannot back. Add those rows when rider account endpoints exist.
4. **Cart counts distinct products:** variants and quantity raises count once. Labels in cart and checkout say "N products" to match the badge, and a repeat Add to Cart says "Quantity updated" without the flight animation or badge bounce.
5. **One chat entry on a product:** the seller row no longer repeats the Chat button that the bottom action bar already provides; the row keeps browse-the-shop navigation instead.
6. **Developer options gesture is not gated on `kDebugMode`:** seven taps on About intentionally work in a demo release build. The tools are a local QR renderer and build-mode information, not privileged server actions; log out still requires confirmation and server session invalidation.
7. **5.3 skipped per schedule override:** tab labels already meet the ≥12 sp requirement through the theme (`labelSmall`/`labelMedium` pinned to 12 sp); no navigation recomposition was done.
8. **Test change — golden refresh:** 16 `screens_screenshot_test.dart` goldens were regenerated in a separate commit after inspecting the failure images. Diffs were intentional: Buyer Home (chat inbox button), Cart (product-count wording), Profile (avatar + edit badge + settings gear), Courier Home (dev QR FAB removed). No assertion, threshold, or test case was changed. Behaviour remains covered by targeted widget tests; goldens only re-baseline pixels.

## Mobile ↔ WebZefanya API integration (2026-11-06)

See `docs/MOBILE_API_INTEGRATION_AUDIT.md` for the full audit.

1. **Test change — `mobile/test/live_auth_test.dart`:** login/refresh/logout expectations moved from the retired `/login/buyer`, `/login/courier`, `/me`, `/me/courier`, `/logout`, `/logout/courier` contract to WebZefanya's `/buyer|rider/login`, `/buyer|rider/profile`, `/buyer|rider/logout` (data envelope, 204 logout, login followed by a profile read). Every assertion kept its meaning (token stored, bearer sent, status/role/name read, token forgotten on logout/401). One case added: a login 401 reads as wrong credentials.
2. **Test change — `mobile/test/live_courier_test.dart` rewritten:** the old file pinned `GET /courier/jobs` and `PATCH /courier/jobs/{id}/milestone`, which WebZefanya does not have and will not get (its rider lifecycle is scan-token + per-action endpoints). Same scenarios re-expressed against `/rider/jobs`, `/rider/scan/verify`, `/rider/pickups/{id}/confirm`, `/rider/deliveries/{id}/start|otp|deliver|failed` (mapping, token, empty list, 401, not configured, field errors, plus 409 conflict and no-OTP-in-payload cases).
3. **Test change — courier fakes:** `courier_handover_wiring_test.dart` and `repository_wiring_test.dart` fakes gained the new `CourierRepository` members (`isServerAuthoritative => false`, no-op `requestDeliveryCode`/`completeDelivery`). No assertion changed.
4. **Not verified locally:** no Flutter SDK on the integration machine; `flutter analyze` / `flutter test` must be run before merge.

## Buyer commerce API integration (2026-11-06, second pass)

1. **WebZefanya web refactor (approved by the user: "Extract to services"):** cart, checkout placement, buyer cancellation, the catalog listing query, address rules and notification titles were moved verbatim from web controllers into services/support classes the web and the API now share. No rule, message or JSON shape changed; the web suites pass unchanged (`CheckoutController::placeOrder` kept as a delegating seam for the two concurrency tests that call it by reflection).
2. **Test change — `mobile/test/live_catalog_test.dart` rewritten** for `/buyer/products|categories|vouchers`. Changed expectations, by design: category/seller filters are sent as server ids; flat vouchers are now offered (the server applies them); maker notes and promo slides are not requested (WebZefanya has none) instead of being fetched.
3. **Test change — `mobile/test/live_orders_test.dart`:** the `orderFromJson` mapping group is unchanged; the request group now covers `/buyer/shipments`, cancel/confirm routes, cart sync + quote + place, the server-total guard and the not-configured case.
4. **Test change — `mobile/test/live_address_test.dart`:** WebZefanya paths/verbs (`PUT` update, `POST` default) and the PSGC `api`/`manual` wire shape.
5. **Test change — `mobile/test/workbook_buyer_phase3_test.dart`:** the order-repository fake's `place()` gained the optional `voucherCodes`/`expectedTotal` parameters. No assertion changed.
6. **Not verified locally:** no Flutter SDK on this machine; run `flutter analyze` and `flutter test` before merge.


## Rider mobile second pass (2026-11-07)

1. **Wind-down parity, not a policy change:** `api.role:rider,wind-down` admits a rider whose suspended company has an Admin-granted wind-down, on the same routes the web's `EnsureRiderActive` allow-list opens (profile, logout, token refresh, jobs, earnings, notifications, scan, deliver/OTP/failed, incidents). Availability, pickup confirm, delivery start and history stay operational-only. The services' own `allowWindDown` gates are unchanged. The API login now accepts wind-down riders exactly as `UnifiedLoginController::tryRider` does.
2. **Token renewal:** `POST /api/rider/token/refresh` rotates a still-valid token (same `rider` ability, same `sanctum.expiration` lifetime, old token deleted). Expired tokens cannot be renewed. The app renews within 10 minutes of expiry, and once after a restart when the expiry is unknown. Buyers have no renewal route; their 60-minute lifetime is unchanged.
3. **Earnings:** live earnings are WebZefanya's `RiderEarning` rows and server sums (today/week in Asia/Manila, like the month); the app no longer derives pay from job fees in live mode. Mock mode keeps its local ledger.
4. **Test change — `mobile/test/live_auth_test.dart`:**
   - "the courier role names the missing endpoint" became "a courier code without a server-signed handle is not posted anywhere". Courier registration is now wired, so its first half no longer applied; the verify half is kept.
   - "courier refresh" now expects the renewal request before `/rider/profile` (new behaviour), and also asserts which token each request carried.
   - "courier registration" now targets `POST /rider/register` with WebZefanya field and part names, then confirms the code at the signed URL.
   - Two cases added: renewal before expiry, and no renewal for buyers.
5. **Test addition — `mobile/test/live_courier_test.dart`:** earnings, incident multipart and provider list. No existing case changed.
6. **Not verified locally:** no Flutter SDK on this machine; run `flutter analyze` and `flutter test` before merge.

## Rider registration alignment + OTP recovery (2026-11-07)

1. **Same fields as the web rider form (web UI unchanged):**
   - The mobile rider form no longer shows Sex or Middle Initial.
   - It adds the web's optional House/Building Number and Landmark/Address Details (`house_number`, `address_detail`).
   - The buyer form is unchanged.
2. **Vehicle type:**
   - Riders register with Motorcycle only, in both modes (a product decision). The server also accepts Car/Van, so this narrows nothing on the server.
   - The rider "Vehicle & documents" edit screen still uses the wider demo list; it is outside registration.
3. **Company by address:**
   - `GET /api/rider/providers?barangay={PSGC}` returns only approved companies that cover the barangay, using the existing `LogisticsHubService::areaForBarangay` rule. Without `barangay`, the list is the web register page's list, as before.
   - The app asks for the barangay picked on the address step and offers "Show all logistics companies" as a fallback. `StoreRiderRegistrationRequest` still accepts any approved company; no rule changed.
4. **Signed URLs are sent exactly as issued:** this reverts the earlier re-basing. The client only refuses a URL whose host is not the configured API host.
5. **Expired link recovery uses the server's existing `POST /api/rider/registration/resume` (email + password):**
   - The app checks the URLs' own `expires` parameter, and treats a 403 from `signed` as expired.
   - It then asks for the password, resumes, stores the fresh links, and retries the interrupted action once.
   - The password is not stored. Resume sends no code; the server says to request one.
6. **Cooldowns:** a 429 carries the server's `retry_after` / `Retry-After`, and the OTP screen disables "Send a new code" for that long. The screen's own 30-second cooldown and its duplicate-tap guards are kept.
7. **Test change — `mobile/test/live_auth_test.dart`:**
   - The rider registration case now expects the verify request at the server-issued URL unchanged; it previously expected the re-based path.
   - New cases: unchanged resend URL, expired link (no request), 403 → expired, foreign host refused, 429 wait, wrong code, resume storing fresh links, wrong password on resume.
   - The fake server also records full URLs.
8. **Test additions:**
   - `live_courier_test.dart`: barangay query on the provider list (the fake also records query parameters).
   - `otp_flow_test.dart`: server cooldown.
   - No existing assertion was weakened.
9. **Not verified locally:** no Flutter SDK; run `flutter analyze` and `flutter test`.

## Rider registration finalization (2026-11-07)

1. **Vehicle & Documents edit screen is Motorcycle-only** (`kRiderRegistrationVehicleTypes`), like registration. A previously saved value outside the list (e.g. "Tricycle") is shown as Motorcycle instead of crashing the dropdown; it is only written if the rider saves. Upload behaviour unchanged.
2. **Out-of-area company:** with "Show all logistics companies", the form states that applying does not guarantee approval or deliveries. The company assigns a station hub on approval, and parcels follow that hub's validated coverage. No server rule changed.
3. **Test addition:** `account_details_visibility_test.dart` adds a case for the Motorcycle-only list with an old saved value. No assertion changed.
4. **zefanya.shop 502:** the PC rebooted at 2026-11-07 10:10. `cloudflared` and `MySQL80` start automatically, but the Laravel origin (`php artisan serve` on 127.0.0.1:8000) is a manual process and was not running. Nothing changed in code or config.
5. **Not verified:** no Flutter SDK on C: or D:; `flutter analyze` / `flutter test` still to run.


## Adaptation decisions (combined web-mobile hardening)

- S6.2 fixture corrections: use the native SellerShippingProvider foreign key to find the carrier (Seller has no shippingProviders relationship); advance the test clock 61 seconds between validation and placement groups so the existing nested placement throttle remains enabled. Strengthen successful placement by changing the current reference rate after review, proving the saved rate snapshot remains authoritative. No existing assertions removed.
- S6.2 mobile transport test intentionally replaces the old preview's cart-PATCH expectation with a single provisional-line quote request. Preserve placement cart synchronization and fingerprints; additionally send expected_total as a decimal string for the server's locked comparison. Full server breakdown and conflict refresh UI remain pending until explicitly recorded done.
- S6.2 continuation: keep the user-authorized newer checkout/branch and native carrier, pricing, voucher and token services. Add a read-only `/api/v1/buyer/checkout/quote` adapter accepting provisional lines; retain the legacy native review-token endpoint for compatibility/placement, so previewing does not mutate cart or checkout tokens.
- S6.2 intended test behavior change: the workbook requires PLAT100 split 63.66/36.34 on 699/399. Native allocation put every leftover cent on the largest store (63.67/36.33). PlatformVoucherAllocationTest's two small-cent datasets, unequal-store dataset (333/668 becomes 334/667) and persisted allocation assertion change to largest-fractional-remainder balancing; all sum, capacity, ordering and ownership assertions remain. Percentage amounts continue to use integer half-up rounding.
- S6.2 shipping: add an idempotently seeded reference shipping_rates table for the four workbook routes. Native provider-specific overrides and operational coverage/handoff checks retain precedence. Capture reference rates with existing checkout snapshots. Unmigrated/unseeded deployments retain existing native shipping rules; no dev migration or seeder is run.

- 2026-11-07 S6.4: User authorized continuing the remaining tasks past the previously blocked formatting gate ("I'll allow anything in those whole tasks"). Keep formatting OPEN; do not retry/bypass its rejection. Continue in the newer checkout on the existing authorized branch; no merge or development migration.
- Intended cancellation behavior/test changes: BUY-CAN-001 now permits PLACED, CONFIRMED and PREPARING, stopping at READY_FOR_PICKUP. Rule04's complete status matrix retains every success/refusal assertion and changes the two expected allowed stages. BuyerOrderCancel's confirmed-sibling visibility/HTTP test now expects successful cancellation; the partial-sibling fixture uses a preset reason. These implement the architect's override rather than hiding failures.
- Reuse the five existing mobile reasons on the server/web. Validate inside the transaction after stale-status checks so a repeated cancellation remains 409 with no side effects, even for an old client's arbitrary reason.
- Add payment_cancellation_refunds as a pending liability ledger keyed uniquely by trusted payment ID; amount comes only from the paid payment row. Preserve paid status/history: a pending liability is not a transferred refund. Recognize ewallet/card for existing prepaid records without enabling new checkout payment methods. Live checkout remains COD-only.
- Keep stock, voucher and refund writes in the existing status-transition transaction. Return claims only if unexpired; retain existing shared-platform/shipping voucher sibling rules. Block account deletion while a cancellation refund is pending. Missing ledger migration blocks paid cancellation atomically; no development migration is executed.

- 2026-11-07: User authorized adapting the original checkout's staged work into this newer checkout, retesting, committing and pushing a new branch. Preserve the newer API/model architecture instead of merging incompatible histories.
- S8B: Replace the ineffective overflow harness with actual buyer/rider tab navigation at 360x640 and text scale 1.3, both themes. Retain login assertions and add logout/system Back coverage. Banner tests verify reactive updates and Retry. No test changes conceal failures.
- S10: `USE_LIVE_API` is canonical, default false. The obsolete `zefanya.useLiveApi` key no longer enables live mode. API URL remains `zefanya.apiBaseUrl`.
- Original APKs/test counts do not verify this copy. Live HTTPS/device gates remain open while the friend's tunnel is off.
- Required PHP formatting was previously rejected repeatedly by automatic approval review despite user approval. Do not bypass; report unrun.
- S6.6: Reuse native review tables, buyer edit timestamp, ProductReviewService and trusted COMPLETED status-history timestamps. No database migration is required. Reject ambiguous multiple variants instead of guessing an item.
- Test changes: Rule08 fixture records trusted completion; the exact day-30 edit expectation changes from acceptance to rejection, per the architect's completion-window rule. Catalogue review-button fixture becomes completed with a known timestamp; catalogue assertions retained. Original review-flow/limits fixtures gain completion metadata and transport tests verify actual multipart bytes instead of sending device paths.
- Test runtime: the installed PHP GD extension is enabled per test process, without modifying PHP or app configuration. Review route has one write throttle to avoid double-counting nested numeric throttles.
- Full mobile run exposed the pre-existing voucher fixture encoding error (peso symbol in a Latin-1 mock response); set the response's actual UTF-8 content type without changing assertions. Inspected home golden differences: buyer order badge now loads after shell entry; rider feed cards replace the stale first-frame empty state. Capture the loaded feed and refresh only eight home baselines in a separate commit.
- Refresh server reviews on authenticated buyer entry as well as orders, and notify listeners when loading finishes; otherwise the signed-out startup 401 leaves edit counters stale after login. Add an authenticated-entry regression.
- The formatter rejection's stated reason was that the command was forbidden by trusted repository instructions, although web/AGENTS.md requires it and the user explicitly approved it. Formatting remains unrun; no indirect formatter invocation is used.
- Verification sequencing correction: the full backend suite began before frontend dependencies/assets were available, causing 27 early admin view failures. Build completed successfully; reran all four affected admin classes plus the final review/shipment/image suites: 95 passed, 556 assertions. Do not describe the initial full run as passing.
- Native mutation checks used tmp/mutation with one guard broken at a time. Restored exact hunks with apply_patch instead of restoring the entire dirty checkout, preserving the pending regression tests and notes. Production files match the committed guards after restoration; 37 affected tests pass.
- S6.4 fixture corrections: take the paid-payment snapshot after fresh() so database defaults/key order are compared consistently; link the new rider-based purchase fixture's item to its actual variant so stock/ledger assertions exercise real inventory; use an existing mock order ID for saved buyer-state persistence. No assertions removed. Correct the new voucher test's case prefix to BUY-CAN-003/006 after reading B11 Cancellation.csv; the mutation runs used its earlier BUY-CAN-004 prefix with the identical test body.
