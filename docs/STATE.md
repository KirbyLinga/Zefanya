# Combined Courier presentation scope — 2026-11-08
Stage: Courier extension 6 (saved hub addresses/destination directions) DONE; prior vehicle/profile local setup retained; manual C1/C3/device acceptance OPEN.
Target: C:/Users/Lenovo/Documents/lavarel/ZefanyaWMI/Zefanya; branch codex/combined-courier-demo.
Verified navigation source HEAD: 6d6bc28; this follow-up only records state. Same Courier branch; no merge.
User explicitly prioritized this limited Courier scope over the old Courier exclusion/EXTEND order; see DECISIONS.md.
Keep legacy Rider role and linehaul Courier; shared Zefanya styling. Native manifest/custody/status/stock/payment services and trip history remain intact.
New assignment/accept/depart require passed checklist, On Duty and <5-minute location; submitted vehicle records additionally need approval/nonexpired license.
Vehicle edits require Off Duty/no active trip; optimistic versions; normalized cross-role plates under shared mutex/current locking reads in both Rider writers.
Trips show native saved origin/destination hub addresses; active trips open fixed HTTPS Maps directions with encoded destination only; missing addresses have no action.
Logistics > Couriers: own-company list, private downloads, version-bound approval/corrections/capacity confirmation; immutable review replay; named 60/min limit.
Presentation compatibility: ALL unsubmitted accounts retain previous access; Active/email verification is not document approval. Mandatory onboarding deferred.
Earlier initial Courier, seal and duty migrations remain installed under prior specific exceptions; existing account/trip/order values unchanged this stage.
Owner approved/applied ONLY 2026_11_08_200000_create_linehaul_profiles.php (397.78ms): linehaul_profiles + vehicle_plate_locks; singleton 1, zero vehicle records.
Final affected PHP: {"tool":"phpunit","result":"passed","tests":39,"passed":39,"assertions":994,"duration_ms":29054}
Prior broader PHP: 193 tests/2081 assertions passed in vehicle stage; not rerun for this resource-only backend change.
Final navigation/Courier mobile: 00:13 +26: All tests passed! Last full-suite prior stage: 03:29 +901: All tests passed! Full not rerun this stage.
Mobile analysis: 8 issues found. (ran in 9.4s); same existing 7 infos/1 unused-test-helper warning, no new issues.
No new route/schema/dependency/web frontend change or web build this stage; prior web build 8.72s. Pint remains waived/UNRUN.
2 URL faults detected/restored; tmp/mutation deleted; prior 18 vehicle and earlier security/seal/exception/duty/closeout proofs retained in mutation-proof.md.
Demo data preserved: zero-value completed batch #1/parcel #8; #2-4 unchanged by this stage. Demo Courier has an active draft: finish handover before editing.
Backend :8000 now uses tools/serve-local.ps1, process-only 5M uploads/20M requests; global php.ini/.env unchanged. Default PHP was 2M/8M.
Real own-Courier trip GET: #2 Loading/#1 Completed with saved Pila/Santa Cruz addresses; test token logged out. No trip/duty/profile/status POST or business change.
UPDATED preview http://127.0.0.1:5176 recompiled (55.7s); old 5175 left intact (closed stdin); API http://127.0.0.1:8000. No merge/APK.
OPEN: actual phone Maps/C1 documents/camera/Files/PDF/hosted PHP; MySQL concurrency; trip weight/schedule/pay; hub GPS/background/push/reassignment; older S6.2/HTTPS gates.
NEXT C3: reason-required decline with retained assignment history/safe unassignment; C1 rehearsal still needs actual documents after active handover/Off Duty. See courier-navigation-progress.md.
