# Phase 12.5 Web–Mobile acceptance and Android preparation

ZefanyaWMII, branch `integration/phase11-stable`, baseline `042e1bb`. Write testing uses only the Phase 12.4 compatible rehearsal copy, `zefanya_phase124_test_verified` on MySQL 8.0.44 at `127.0.0.1:33183`. The existing Laravel Web endpoint is `http://127.0.0.1:38139` and the Chrome Live API endpoint is `https://127.0.0.1:38140/api`. The populated local database and teammate source restore are outside the write scope.

## Database compatibility

The read-only rehearsal check found 111 tables, 298 recorded migrations, and 236 foreign-key constraints with zero orphans. All 1,096 original rows matched their recorded checksums across 109 tables. The separate teammate source restore also matched its original fingerprint. The three missing historical Admin identities still require authoritative reconciliation before production.

## Confirmed defect and repair

Logistics Web returned HTTP 500 when opening **Transport Couriers** with existing assignments. The controller already requested the assignment's manifest or handoff and its two hubs. The assignment model did not define either relationship. Restoring those relationships exposed a second HTTP 500 caused by the missing shared Add/Edit form template. The restored form uses the existing field names and server validation.

Added two regression tests for the rendered assignment history, covering an internal batch and a cross-provider handoff. The internal case also checks the Add form and the prefilled Edit form. Both passed in the focused run, with eight assertions. The same rehearsed Logistics request returned HTTP 200 after the fix. The existing write rules and provider scoping still apply.

On Flutter Web, Laravel returned HTTP 204 for Buyer logout but Settings stayed open when Google's script was unavailable. The password-only session had no Google SDK state. The Flutter service now skips Google sign-out if SDK initialization never started. Existing server revocation and initialized Google sign-out remain in place. The rebuilt browser app returned directly from Settings to the public sign-in screen while Google's script was still blocked. The focused Flutter run passed nine checks, with one existing mock-only skip.

The Rider profile displayed the Transport Courier role name. Read-only returns included a collection instruction even for a return marked “Returned to Seller,” and displayed “0 items” when the API only supplied tracking and status. The profile now says “Rider,” and the read-only card displays the returned status with a neutral Web portal message. The focused Settings and release-profile tests passed all ten cases. Chrome rerendered both corrected screens. Collection permissions, data and tracking are preserved.

## Rehearsal evidence

- The Flutter Chrome build used the local HTTPS loopback certificate and verified writes. Splash rendered at 360×640 and 411×890. At 411×890, onboarding, successful Buyer sign-in, the Buyer shell, order history, profile, and settings all rendered. The Buyer shell initially showed the catalog connection-error state. The same catalog endpoint separately returned HTTP 200, and the rebuilt Flutter client later rendered the category rail and live products. Intermittent local failures limited visual checkout coverage.
- Chrome restored the Buyer session after a full page reload, found one result for “Atomic,” opened a live product and increased the selected quantity from one to two. The selected product rendered the image placeholder. A separate referenced catalog image returned HTTP 404 on the rehearsal server, so imported media coverage remains open alongside functional acceptance.
- The Flutter repository confirmed the synthetic checkout in the authenticated Buyer's order history. The corresponding Seller order and tracking number rendered on the owning Seller's Web detail page.
- Seller, Logistics and Admin signed in using their browser forms. All 20 checked pages returned HTTP 200, with no HTTP 500 responses during the completed retest. Visible navigation links were used when available. Three screens were opened by their exact browser URL.
  - Seller opened Products, Orders, Returns, Earnings and Payout Requests.
  - Logistics opened Incoming Parcels, Parcel Sorting, Assign Deliveries, Hubs and Stations, Couriers, Transfer Batches, COD Remittances and Parcel Exceptions.
  - Admin opened Registration Management, Pending Products, Returns and Disputes, Finance, Parcel Incidents, Lost Parcels and Platform Settings. The three unresolved historical audit actors were visible.
- An actual HTTPS Flutter repository run verified Rider sign-in, assigned-job and earnings reads, and that the protected profile blocks Rider return collection. A later all-role run experienced a local connection interruption. That run does not establish a complete Rider or Courier UI pass.
- The isolated HTTPS API confirmed Courier login, profile, assigned transfers and logout, with HTTP 200 for each. The Courier saw its assigned batch and did not see the unrelated batch. The Courier token received HTTP 403 from the Rider profile endpoint. An invalid Buyer token received HTTP 401.
- The rebuilt Flutter Chrome client signed in as the Transport Courier and rendered the assigned batch, the empty On the Road state, the history with a received batch, and the Courier profile. Sign-out returned to public sign-in with HTTP 200 from the API. The view kept dispatch and receipt with hub staff, and showed no earnings action under this profile.
- Chrome signed in as Rider and loaded the active availability state, the pickup and delivery queues, earnings, profile, delivery history, and notifications. The notifications, jobs, profile and earnings reads each returned HTTP 200. The rehearsal account had no active pickup or delivery in those views. The controlled tests still verify proof and exact COD safeguards.
- The Buyer settings view rendered both themes in Chrome. The timeouts and interrupted connections exercised the catalog and offline error feedback. Sustained reconnection, mobile rapid taps, camera scanning, real device background/resume and the remaining workflow buttons require separate acceptance evidence.
- The Phase 12.4 report contains prior local HTTP evidence for verified Rider pickup and exact COD, Web-controlled Courier dispatch and receipt, returns, refund authorization and incident handling. These remain valuable regression evidence. Phase 12.5 records separate outcomes for the workflows exercised again.
- Local rehearsal screenshots under `%TEMP%/zefanya-phase125` include `flutter-360x640-entry.png`, `flutter-411x890-entry.png`, `flutter-onboardingNext.png`, `flutter-restoredBuyerHome.png`, `flutter-buyerSearchResults.png`, `flutter-buyerOrders.png`, `flutter-finalRiderProfile.png`, `flutter-finalRiderReturns.png`, `flutter-courierHistory.png`, and `android-native-retry.png`.

## Automated verification

- Laravel native MySQL full run: **4,293 passed, 0 assertion failures, 1 error, 5 skipped**. The barangay concurrent assignment runner hit its 240-second process timeout under concurrent build and emulator load. Its isolated rerun passed **1 test, 2 assertions**. The two new Courier page cases were run separately and passed under native MySQL and SQLite. Across those distinct verified cases, **4,296 passed and 5 skipped**, with the initial timeout retained above.
- Flutter Live API baseline: **1,040 passed, 0 failed, 3 skipped**. The first full run after the logout fix passed 1,040 tests, skipped three and failed one handover widget test that used a fixed 150 ms wait for real file I/O. The test now waits up to ten seconds for the existing “Photo attached” assertion. Production Rider code and acceptance criteria are unchanged. The focused handover, logout, and Settings run passed **12 tests with one existing skip**.
- Final full Flutter Live API run from the integration checkout: **1,042 passed, 0 failed, 3 skipped**.
- Flutter analysis: **2 warnings and 10 informational diagnostics**, matching the earlier baseline.
- Web production asset build passed.
- Pint passed for the changed PHP model and tests.

## Android release configuration

| Item | Current source |
|---|---|
| Application ID and label | `com.example.zefanya` and Zefanya, with the existing launcher icon. |
| Version | `1.0.0+1` in Flutter. |
| Manifest | Internet, foreground coarse and fine location, and camera permissions. The application uses the existing Android platform TLS behavior. Cleartext permission is in the debug manifest only. |
| Live API and write policy | The release guard requires the pinned HTTPS host `https://zefanya.shop/api`, `USE_LIVE_API=true`, the protected profile and no broad writes. The checked-in release configuration enables only verified writes and Courier visibility. |
| Token storage | Native Live API sessions use the existing secure token store. |
| Signing | The build requires a configured keystore. Phase 12.5 uses a temporary validation key and a separate build directory. |

Before a public release, confirm the permanent application ID. A candidate is `shop.zefanya.mobile` if the team controls the namespace and agrees to use it. Changing the ID creates a separate install and app-data identity. An update to an existing install requires the same application ID and an accepted signing lineage. Review the manifest's asset-links host and matching package/certificate association before release.

### Local Android results

- The Pixel 4a emulator booted, and an emulator-to-host request through `10.0.2.2` reached the isolated catalog with HTTP 200. The test HTTPS proxy passed certificate verification. The debug APK trusts only the added test certificate through a temporary Dart entry point and uses `https://10.0.2.2:38150/api`. The test application ID `com.example.zefanya.phase125` kept existing emulator app data separate.
- The debug APK installed and rendered native onboarding after a System UI hang. Pixel Launcher stopped responding after restart and the emulator disconnected. Authenticated native workflows and camera verification remain blocked.
- The release-profile APK including the logout and Rider display fixes built and passed APK Signature Scheme v2 verification with a temporary validation certificate. The APK uses `com.example.zefanya`, version name `1.0.0`, version code `1`, min API 24 and target API 36. The output includes `arm64-v8a`, `armeabi-v7a` and `x86_64`. The packaged Zefanya label, launcher icon, internet permission and camera permission were checked. The release-profile app was not launched against the production API.
- Local output: `%TEMP%/zefanya-phase125/rc/mobile/build/app/outputs/flutter-apk/app-release.apk` (80,715,767 bytes). SHA-256: `0F9C0394BBDFEA8267A19490EE19872DB5829361CE6FA095D24023A476AF202E`.
- The installed toolchain reported Java restricted native access, older Kotlin plugin integration in `app_settings`, `package_info_plus` and `share_plus`, and Java 8 or deprecated API warnings in dependencies.

## Acceptance verdicts

| Area | Verdict | Evidence or outstanding acceptance |
|---|---|---|
| Database compatibility | PASS | Imported-row checksums, schema and foreign keys verified on the isolated rehearsal copy. Historical Admin identities remain a release follow-up. |
| Laravel Web and API | BLOCKED | All 20 checked Web pages, both Courier regressions and focused API checks passed. Full browser transaction replay is outstanding. |
| Flutter Buyer | BLOCKED | Sign-in, live catalog, orders, profile and cross-system checkout verified. Complete purchase, return, messaging and reconnection UI walkthrough remains open. |
| Flutter Rider | BLOCKED | Rider sign-in, pickup and delivery queues, earnings and profile rendered in Chrome. Native camera, proof and full delivery UI acceptance remain open. |
| Flutter Courier | BLOCKED | Chrome verified sign-in, assigned trips, history, the On the Road empty state and profile. Native session restoration and the full error-state walkthrough remain open. |
| Android emulator | BLOCKED | Installed and rendered onboarding, then Android system hangs and disconnection prevented full native acceptance. |
| Android release build | PASS | Local validation artifact built and its temporary signature and packaged metadata were verified. |
| Production deployment | NOT READY | Requires full acceptance, authorized signing, media compatibility, exact production-copy rehearsal and historical identity reconciliation. |

## Production release sequence

1. Obtain a verified backup and rehearse restoration and compatibility on an exact isolated production copy. Review the orphaned Admin identity and Seller promotion policy across branches. Use the verified forward changes for that history.
2. Confirm HTTPS, media and authenticated private-document handling, API contract behavior, and environment-specific Courier activation. Preserve the release write allowlist and verify production configuration with an authorized staging environment first.
3. Confirm the permanent package ID, secure the authorized release signing lineage, select the next version code and verify the Android manifest, packaged resources, certificate and build flags.
4. Verify the exact signed candidate on a representative Android device with scanning, camera permissions, background/resume, session handling and the network behavior expected in production. Complete the team's release approval before distribution.
