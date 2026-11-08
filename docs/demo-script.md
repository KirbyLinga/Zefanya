# Demo and recording in the newer checkout

## Courier seal extension — enabled locally

The user explicitly approved and enabled ONLY `2026_11_08_120000_add_seal_receipt_to_linehaul_assignments.php` on local MySQL (103.01ms), adding nullable JSON `seal_receipt`. Do not run the whole migration backlog. Verified the column, both existing assignments retained null receipts, and batch statuses unchanged (#1 Received; #2–#4 Draft). Logistics batch #2 receiving page loads; it correctly waits for dispatch. No demo trip was advanced to verify activation. A full real seal/mismatch trip rehearsal remains open; automated tests already cover those paths.

Once enabled: receive a dispatched Courier trip using the existing Logistics receiving screen. After verifying its first parcel, type the seal physically observed on arrival. A matching seal permits receipt; a different seal requires a note (1–500 characters). Confirmation retains original/observed seals, note, actor and time; a mismatch adds an append-only event and database-only Logistics notification. Later parcels reuse this confirmation. Final receipt with a seal discrepancy appears as Received with Issues in Courier History, not a clean Completed trip. This stage does not add GPS, push, duty, pay or the complete missing/damaged closeout workflow.

## Ready-to-use local Courier trips

User-requested fixtures created 2026-11-08: three separate zero-value transfer batches, two parcels each, Pila Station Hub to Laguna Province Hub. No existing order/stock/payment changes. These have no sale lines and only rehearse transfers.

| Batch | Availability | Manual tracking numbers |
| --- | --- | --- |
| 2 | Assigned to Demo Pila Courier | DEMO-COURIER-B01-P01, DEMO-COURIER-B01-P02 |
| 3 | Draft; Logistics assigns after the active trip finishes | DEMO-COURIER-B02-P01, DEMO-COURIER-B02-P02 |
| 4 | Draft; Logistics assigns after the active trip finishes | DEMO-COURIER-B03-P01, DEMO-COURIER-B03-P02 |

Refresh Courier Trips, accept batch 2, scan/enter both numbers, then depart with a seal such as BH-0004521. Report arrival; Logistics receives both parcels before assigning the next batch. One active trip per Courier remains enforced. Credentials stay in chat, not this document.

## Remaining Courier phone check

Device discovery on 2026-11-08 found no connected Android device; Pixel_4a is a saved emulator, not a running device. Android declares INTERNET and CAMERA. Existing scanner/trip widget checks pass (8 tests); they do not verify camera permission, QR or Code128 decoding on hardware.

1. Connect the intended Android phone with USB debugging enabled and accept its debugging prompt yourself. Confirm it appears as `device` in `adb devices -l`.
2. Obtain a reachable HTTPS API address for this checkout before building/running live mode on the phone. PC loopback `127.0.0.1` is not the phone's backend address. Do not reuse a tunnel serving another checkout.
3. Have Logistics prepare a new eligible demo transfer; batch #1 has already completed and cannot be replayed. Sign in through Courier login and accept the new assignment.
4. Open Scan or enter parcel. First deny camera permission: check that manual entry still works and returning to the trip preserves its state. Reopen and grant camera access yourself when ready to test it.
5. Scan an actual QR and a Code128 waybill for parcels on the new batch. Check that the correct tracking number loads once, an unrelated number is refused, closing/reopening the scanner works, and no permission prompt loops occur.
6. Scan all parcels, enter a valid seal, depart, report arrival, and let Logistics receive every parcel. Refresh History and verify Completed with the correct received count. Check logout followed by Back does not reopen Courier data.

Keep these results OPEN until observed on the intended phone. No new APK was built during this device-readiness check.

Local browser rehearsal completed: separate approved zero-value fixture DEMO-COURIER-20261108-01 (parcel #8, batch #1) moved Pila Station Hub to Laguna Province Hub. Logistics assignment, Courier acceptance, manual scan, sealed departure, reported arrival and Logistics receiving succeeded. Courier history is Completed; the fixed API retains 1 parcel / 1 received. Existing orders, inventory and payments were untouched. This fixture has no sale lines and is only a hub-transfer rehearsal, not a checkout/delivery acceptance result. Phone camera/permissions remain open. The temporary local setup script was removed.

## ZefanyaWMI combined Courier demonstration

Local setup is verified after explicit user approval: backend on http://127.0.0.1:8000; the latest branded Courier preview is http://127.0.0.1:5174 (5173 is the earlier preview). Open the new URL in Chrome. All three demo logins/profile calls previously passed against MySQL; full assigned-trip and phone/camera rehearsal remain open. Credentials were handed over in chat and are not committed.

Courier now uses shared Zefanya design components in both themes. Trips shows active assignments, History shows recent completed/cancelled/issue transfers, and Account shows the authenticated name/email and logout. These views do not add tracking, pay or onboarding claims. Rendering QA covers 360x640 with text scale 1.3; it is not a real browser/camera test.

1. After approved backend configuration/table installation, provision one local-demo Courier with `php artisan linehaul:provision`, supplying --provider, --hub, --email and --name for an approved company's operational hub and unused email. Password is prompted and hidden. The command never overwrites an account and is disabled outside local/testing. Full email activation/onboarding is deferred.
2. Start the configured backend on port 8000. From mobile run `flutter run -d chrome --dart-define=USE_LIVE_API=true --dart-define=zefanya.apiBaseUrl=http://127.0.0.1:8000/api`. Chrome can use loopback HTTP; Android needs a reachable HTTPS address. Courier login is hidden in mock mode.
3. In Logistics create a forward transfer batch with real eligible parcels and a destination hub. Select a Courier on batch details and Assign Courier. A second active trip is refused.
4. In Courier login sign in and Accept trip. Scan every tracking number using QR/Code128 or manual fallback. A number not on the manifest is refused. Depart before all scans must fail; expired scans require rescanning.
5. Enter a seal such as BH-0004521 and Depart. Native manifest becomes dispatched with Courier actor/audit records. Report arrival leaves the trip Arrived, awaiting Logistics. Arrival is reported, not GPS-verified.
6. In Logistics manually compare the recorded seal and use the existing receiving screen to scan/receive parcels. Refresh Courier trips: Completed appears only after native receipt finishes. Courier cannot confirm their own receiving. Do not claim full seal discrepancy automation.
7. Record Buyer/Rider flows, this main Courier path and logout/Back. Disclose manual scanning if Chrome has no camera; rehearse real QR/Code128 camera/permissions on the intended phone before claiming device verification.

Deferred: license/NBI/vehicle onboarding and activation, duty/checklist/capacity, 500 m GPS/location tracking, push, decline/reassignment/breakdown, pay and full discrepancy rules. User approved only local config, the Courier migration and one demo Courier; no other migration or existing order/stock/payment changes. Existing S6.2/cancellation conformance gaps remain in STATE/DECISIONS.

Run commands from `mobile/`. This guide applies to this checkout's `/api/buyer` and `/api/rider` backend, not the original checkout.

## Offline rehearsal

1. Run `flutter run --dart-define=USE_LIVE_API=false` (add `-d chrome` for the browser).
2. Use the mock buyer login with a valid email and password. Browse Home, search and categories; open a product, add it to the cart, check out with COD, and open Orders.
3. Open Profile, Settings and Appearance; demonstrate the theme switch. Log out through Settings, confirm, then press Back: the buyer shell must remain closed.
4. Log in with the mock rider role. Open pickup/delivery jobs, earnings and Profile. These are mock demonstrations, not claims of a real parcel delivery.
5. On Windows press Win+Alt+R to start/stop Game Bar recording of the selected app window (if Game Bar is unavailable, use Snipping Tool's Record control). Start at the launch screen, perform steps 2–4, stop, and play the saved video to confirm the entire run and readable text. Hide notifications and avoid showing passwords while recording.

## Local live browser

Start this checkout's Laravel server separately; use its existing MySQL configuration. Do not migrate or replace the development database as a demo step.

`flutter run -d chrome --dart-define=USE_LIVE_API=true --dart-define=zefanya.apiBaseUrl=http://127.0.0.1:8000/api`

Use actual approved buyer/rider accounts. Login, browse catalogue images, open Orders, and log out. Public images are served directly by the existing storage route; no storage link is required. Localhost on a phone refers to the phone itself and will not reach the PC.

## APK builds and installation

- Mock: `flutter build apk --release --dart-define=USE_LIVE_API=false`. Save the generated `build/app/outputs/flutter-apk/app-release.apk` as `demo-mock.apk` before building another mode.
- Live: obtain the active HTTPS backend URL for **this checkout**, including `/api`. Build with `flutter build apk --release --dart-define=USE_LIVE_API=true --dart-define=zefanya.apiBaseUrl=YOUR_HTTPS_API_BASE_URL`. Save as `demo-live.apk`.
- Install one mode at a time using `adb install -r demo-mock.apk` or `adb install -r demo-live.apk`. The package identity is shared, so installing one replaces the other. Clear app data when changing modes to remove stale sessions and mock data.
- Do not commit APKs, credentials, `env.json`, `.env`, database dumps or private documents.

The friend's tunnel is currently off and no HTTPS URL is available. A usable live APK and physical-device acceptance remain OPEN. Older APKs from the original checkout were not rebuilt or validated against this backend.
