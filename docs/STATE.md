# Combined Courier presentation scope — 2026-11-08
Stage: Courier extension 9 (recorded parcel weight/count and actual trip times) DONE; COU-TRIP-001 partial, schedule/pay OPEN.
Target: C:/Users/Lenovo/Documents/lavarel/ZefanyaWMI/Zefanya; branch codex/combined-courier-demo.
Verified details source HEAD: 4c73382; this follow-up records state. Same authorized Courier branch; no merge.
User explicitly prioritized this limited Courier scope over the old Courier exclusion/EXTEND order; see DECISIONS.md.
Keep legacy Rider role and linehaul Courier; shared Zefanya styling. Native manifest/custody/status/stock/payment services and trip history remain intact.
Assignment/accept/depart still require passed checklist, On Duty/fresh location; submitted vehicle records require approval/nonexpired license. Unsubmitted compatibility retained.
New read-only trip details show visible parcel count, complete recorded positive weight snapshot sum, and actual accepted/departed/arrived local times.
Missing/zero/mixed snapshots return null with incomplete-weight copy; no mutable catalog fallback, partial total or hidden write.
Same visible item collection drives count/weight/rows; removed unreceived excluded, received/issue history retained. Eager order read selects only id/weight.
No schedule/pay invented; no assignment-created time reused as reassignment time. Existing delay/decline/readiness rules unchanged.
Owner approved/applied ONLY 2026_11_08_230000_create_linehaul_trip_delays.php (387.04ms), alone in batch 77; zero reports; original assignments/statuses unchanged.
Prior initial Courier/seal/duty/vehicle/decline migrations remain installed under their specific exceptions; no other pending migration run.
Final related PHP: {"tool":"phpunit","result":"passed","tests":31,"passed":31,"assertions":707,"duration_ms":23701}
Delay/demo/decline suites include new details test for actual times, native-record equality, mixed/null/zero weights and removed/received membership.
Final Courier mobile: 00:19 +38: All tests passed! Two new details tests. Last full app: prior vehicle-stage 901; full not rerun this stage.
Mobile analysis: 8 issues found. (ran in 8.4s); unchanged seven infos/one unused-test-helper warning, no new issues.
No web frontend changes; build not repeated. Prior delay-stage PHP 210/2444 and build 6.41s retained as prior evidence only. Pint waived/UNRUN.
No new security/money rule, route/schema/service/dependency/config; prior mutation proofs retained. No database update or demo mutation this stage.
Actual local HTTP: #2 Loading/2 parcels/1600g, #1 Completed/1 parcel/800g; saved actual times returned, verification token logged out.
Current demo #2 already accepted/Loading, so delay stays unavailable until genuine departure; #1 Completed. No fake report/GPS/docs/pay.
Preview http://127.0.0.1:5176 hot restarted (1088ms), no client connected. API http://127.0.0.1:8000. No merge/APK.
OPEN: physical C5/C1 document/decline/Maps rehearsal, simultaneous MySQL proof, trip weight/schedule/pay.
OPEN: in-transit reassignment, geofence/background GPS/push, hosted HTTPS/APK and older S6.2 gates; Courier not fully complete.
NEXT: remaining backed schedule/capacity/pay work or full real rehearsal; see courier-trip-details-progress.md. STOP after this stage.
