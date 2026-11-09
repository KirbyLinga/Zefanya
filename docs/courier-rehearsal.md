# Courier rehearsal — current guide, 2026-11-09

Use this guide instead of older Courier steps in demo-script.md. Checkout: C:/Users/Lenovo/Documents/lavarel/ZefanyaWMI/Zefanya, branch codex/combined-courier-demo. Credentials stay in chat.

## Start and sign in

Open the local Chrome preview at http://127.0.0.1:5177 while the Flutter server is running. It connects to the local API at http://127.0.0.1:8000.

If restarting the backend, run from the checkout root:

```powershell
./tools/serve-local.ps1 -Php 'C:\PHP\php.exe'
```

For your own Chrome run, use a separate terminal in the mobile folder:

```powershell
flutter run -d chrome --dart-define=USE_LIVE_API=true --dart-define=zefanya.apiBaseUrl=http://127.0.0.1:8000/api
```

`USE_LIVE_API` is the current flag; `zefanya.useLiveApi` does not enable this checkout's live mode. Reuse the intended preview or select another free port rather than stopping an unidentified server. The backend launcher supplies only process-level upload settings; it does not edit global PHP configuration.

Choose **Are you a Courier? Sign in here** on the shared sign-in page. Use the company-issued demo Courier account from chat. The form stays on the same route and uses Courier email/password validation. Unsupported Courier Google sign-in and self-registration are not offered.

## Safe walkthrough before moving the trip

Breakdown reporting is locally enabled (batch 80). After genuine departure, use Report breakdown to enter a reason, a future ETA and a JPG/PNG photo. Logistics receives the report and can download the photo from its manifest page. Retry keeps the form and photo. Demo #2 remains Loading.

Reassignment is enabled in local MySQL through the approved migration in batch 81. The two-Courier rehearsal requires a second operational Courier in the same company. At preflight, the company had one Courier, who was Off Duty, and zero approved vehicle profiles. Use separate browser profiles or devices so both Courier sessions can stay signed in. To verify actual earnings during the rehearsal, the replacement needs an approved vehicle with a configured rate before taking the trip.

Returning from Maps/another app refreshes trip state and the currently visible History/Earnings report. If the server is unreachable, use the explicit Retry state after restoring connectivity; do not interpret a failed refresh as a successful trip action. Unsent delay form input is retained while switching apps. Background route recording/push remain unavailable.

After restarting Flutter with the current source, History and Earnings refresh when returning from another tab, retaining the selected dates/page. Pull-to-refresh and Retry also retain that page; a failed refresh hides stale results. Selecting a different date preset starts at page one. This fetches on navigation, not by background push.

| Screen | Verified data / expected display |
| --- | --- |
| Trips | #2 is already accepted: Loading, two parcels, recorded total 1.6 kg. Do not accept or decline it again. |
| History | Include Nov 8, 2026 in the dates. #1 is Completed; date filtering/pagination is directly in this tab. |
| Earnings | Nov 8–9: one completed handover, zero confirmed earned trips, one unconfirmed trip excluded from the known amount. This is not a payout balance. |
| Profile | Company/profile, Duty & readiness and Settings gear. Current duty is Off Duty. |
| Settings | Appearance/support/about and confirmed logout. No Buyer address/security or Rider-specific records. |

The local database has no submitted Courier vehicle profiles. The demo retains the agreed presentation exception, not full reviewed onboarding. Both existing trips show rate not configured. Do not fabricate a vehicle, approval or historical earning to display money.

Use Profile → Settings → Log Out. Stay must preserve the session; confirmation must return to sign-in. Browser Back must not reopen authenticated screens. Actual API logout revoked the verification token (401 afterward); real Chrome Back/permission behavior remains a manual check.

## Moving demo batch #2 — manual, not performed by the agent

These steps change the demo trip and are outside the read-only preflight result.

1. Profile → Duty & readiness → Go On Duty. Allow real foreground location yourself and answer the five checklist items based on the rehearsal. Missing answers block submission; Not OK requires a note and leaves Off Duty.
2. Departure needs a passed checklist and location within five minutes, accurate to 100 m or better. If desktop Chrome cannot provide it, stop and use a suitable device with real location. Do not insert pretend readiness/coordinates. A 500 m hub geofence and background tracking are not implemented.
3. Batch #2 had zero loading scans at preflight. Scan actual demo labels or use manual entry for DEMO-COURIER-B01-P01 and DEMO-COURIER-B01-P02. Neither parcel was received or released. Confirm unrelated numbers refuse and repeats do not increase the count. Camera opening was user-reported; real QR/Code128 and permission-lifecycle checks remain open.
4. In Logistics at http://127.0.0.1:8000/logistics/manifests, optionally save a future Philippine-time planned departure for #2. It should appear separately from actual departure and must not move the batch.
5. Load both parcels, then depart with the demo label's seal (BH-0004521 is a valid format example). Check In Transit and retained parcel/seal details. No real departure was performed during preflight.
6. While In Transit, before arrival, use Report breakdown for the reassignment rehearsal. Submit a reason, a future ETA and a JPG/PNG photo of the rehearsal evidence. In the owning Logistics manifest view, confirm that the photo downloads, select the ready replacement Courier and record the reassignment reason. The original Courier should see Reassigned in History with zero pay. Sign in as the replacement. Try continuing after one parcel scan and confirm it remains blocked. Rescan the second parcel, then tap Continue trip. Report arrival should now be available.
7. Report arrival when appropriate. Courier-reported arrival is not verified geofencing. Logistics, not Courier, confirms handover.
8. Logistics verifies/receives every present parcel and enters the observed demo seal on first receipt. A mismatch needs a note and yields an issue result. Missing/damaged controls belong to a separate genuine exception rehearsal; damaged evidence must be real. These actions change records and were not executed locally during preflight.
9. Refresh History/Earnings for the actual handover date: one trip entry, no duplicate earnings. Without a supported reviewed vehicle/rate snapshot, pay stays unconfigured/excluded. Only resolve the current trip before another active assignment.

Draft batches #3/#4 each retain two demo parcels and are unassigned. A decline rehearsal requires a new unaccepted offer and another ready Courier for reassignment. Do not discard or reassign the accepted Loading trip to expose Decline. Vehicle submission/review waits until the active trip finishes and the Courier is Off Duty.

## Preflight evidence

| Check | Result |
| --- | --- |
| Real local login; GET profile/duty/vehicle/trips/history/earnings; logout | PASS. Own verification token revoked; old token returned 401. |
| Live MySQL trip/assignment/native-audit fingerprints | Identical before/after reads. No schedule, scan, receipt, incident, profile or pay record created. |
| Native lifecycle and related Courier integrations | `{"tool":"phpunit","result":"passed","tests":83,"passed":83,"assertions":1707,"duration_ms":47581}`; SQLite memory only. Includes readiness/vehicle, scan/receiving/seal/closeout, decline/delay/schedule, pay/earnings/history. |
| Shared live-mode login/navigation/Settings widget rehearsal | `00:05 +4: All tests passed!`; mocked HTTP, not real Chrome UI. |
| Preview | Fresh 5177 compiled in 55.7s; HTTP 200. Existing 5176 HTTP 200 preserved. |
| Chrome UI / real camera/GPS/Back / physical handover | OPEN. Browser automation previously stopped because it could not verify the current Chrome URL; no alternate UI control bypassed it. |

Isolated tests prove software behavior, not actual location/documents/custody or simultaneous MySQL scheduling. No full-mobile rerun/analysis was needed for this documentation/runtime-only stage; prior stage has 931 mobile passes and eight existing analysis issues.

Physical acceptance, simultaneous MySQL reassignment, payout recording, the unspecified 10-wheel rate, universal reviewed onboarding, geofencing, background tracking, push and a hosted HTTPS phone build remain open. The loopback preview runs on the PC. A separate phone needs a reachable API.
