# Combined Courier presentation scope — 2026-11-08
Stage: Courier extension 3 (duty/checklist) IMPLEMENTED/TESTED/ENABLED locally after explicit approval; real duty/permission rehearsal OPEN.
Target: C:/Users/Lenovo/Documents/lavarel/ZefanyaWMI/Zefanya; branch codex/combined-courier-demo.
Verified duty source HEAD: 586b2ed; this follow-up only records state. Same Courier branch; no merge.
User explicitly prioritized this limited Courier scope over the old Courier exclusion/EXTEND order; see DECISIONS.md.
Keep legacy Rider role and linehaul Courier. Branded login, Trips/History/Account use shared Zefanya fonts/colors/cards/nav/empty states.
New assignment/accept/depart require On Duty, passed checklist and <5-minute location update; owning company, active-trip and driver/manifest locks retained.
Courier accepts, scans QR/Code128/manual, departs with seal, reports arrival; native Logistics receiving alone yields Completed.
Native ManifestService/custody/status retained; encrypted/hidden scan tokens expire; actual actor audits retained.
New migration installed ONLY linehaul_drivers/linehaul_assignments in zefanya_friend_20261107, with explicit user exception.
Explicitly provisioned one demo Courier at Demo Laguna Express/Pila Station Hub; no existing account overwritten.
Baseline: 80 PHP tests/622 assertions and 873 mobile tests passed after locked dependencies restored and process-only GD enabled.
Restored broader PHP: {"tool":"phpunit","result":"passed","tests":121,"passed":121,"assertions":1009,"duration_ms":32119}
Duty related PHP: {"tool":"phpunit","result":"passed","tests":60,"passed":60,"assertions":819,"duration_ms":29601}
Duty full mobile: 04:07 +890: All tests passed! Final focused: 00:10 +15: All tests passed!
Duty final analysis: 8 issues found. (ran in 10.7s); existing 7 infos and 1 unused-test-helper warning, no new findings.
Duty web assets built in 30.24s; no new dependency. ONLY approved duty migration ran (360.52ms); demo Courier Off Duty, no checklist history.
Ten prior security faults + two seal + three exception + seven duty faults detected; all guards restored, final 60 passed; tmp/mutation deleted.
Duty: five checklist answers, failed note/history/Logistics database notice, active-trip Off Duty block, explicit foreground location, minute updates/pause/resume, private company readiness.
Deferred: activation/document/vehicle/capacity flows, 500 m hub GPS/background tracking/push, reassignment, pay and bulk discrepancy closeout.
Existing gaps retained: PLACED-only cancellation conflicts with prior cutoff; S6.2 breakdown/wallet, review, HTTPS/device gates open.
User approved separate local demo data: zero-value parcel #8 / batch #1, DEMO-COURIER-20261108-01. Existing orders/stock/payments untouched.
Real HTTP: all three logins/profiles succeeded; Courier trip #1 Completed/1 received, Rider access 403, Chrome CORS 204; own test tokens logged out.
Latest duty preview: http://127.0.0.1:5175; hot restart recompile complete (2635ms). Backend http://127.0.0.1:8000; actual duty API reports setup_required false/Off Duty.
Activation verified: all four fields present, duty history count 0, batches #1 Received/#2-4 Draft unchanged. Next: user location/checklist rehearsal; later vehicle/profile or bulk closeout. No merge/APK; Pint waived/UNRUN.
