# Combined Courier presentation scope — 2026-11-08
Stage: Courier extension 5 item 1 (profile visibility) DONE; full C1 vehicle/document module OPEN. Prior seal/exception/duty/closeout retained.
Target: C:/Users/Lenovo/Documents/lavarel/ZefanyaWMI/Zefanya; branch codex/combined-courier-demo.
Verified profile source HEAD: 954e05c; this follow-up only records state. Same Courier branch; no merge.
User explicitly prioritized this limited Courier scope over the old Courier exclusion/EXTEND order; see DECISIONS.md.
Keep legacy Rider role and linehaul Courier. Branded login, Trips/History/Account use shared Zefanya fonts/colors/cards/nav/empty states.
New assignment/accept/depart require On Duty, passed checklist and <5-minute location update; owning company, active-trip and driver/manifest locks retained.
Courier accepts, scans QR/Code128/manual, departs with seal, reports arrival; native Logistics receiving alone yields Completed.
Native ManifestService/custody/status retained; encrypted/hidden scan tokens expire; actual actor audits retained.
New migration installed ONLY linehaul_drivers/linehaul_assignments in zefanya_friend_20261107, with explicit user exception.
Explicitly provisioned one demo Courier at Demo Laguna Express/Pila Station Hub; no existing account overwritten.
Baseline: 80 PHP tests/622 assertions and 873 mobile tests passed after locked dependencies restored and process-only GD enabled.
Restored broader PHP: {"tool":"phpunit","result":"passed","tests":121,"passed":121,"assertions":1009,"duration_ms":32119}
Profile final PHP: {"tool":"phpunit","result":"passed","tests":70,"passed":70,"assertions":1071,"duration_ms":16328}
Profile/trip/duty mobile: 00:07 +12: All tests passed! Full mobile prior: 04:07 +890: All tests passed! Full not rerun this stage.
Mobile analysis: 8 issues found. (ran in 11.5s); same existing 7 infos and 1 unused-test-helper warning; no new profile issues.
No web frontend change/build this stage; previous closeout web build 14.43s. No dependency/schema/dev-data changes. Pint waived/UNRUN.
Ten prior security faults + two seal + three exception + seven duty + seven closeout faults detected/restored; final 67 passed; tmp/mutation deleted.
Closeout: owning active Logistics, named company 20/min limit, reviewed exact pending set, confirmed arrival/seal/note, atomic native missing incidents, preserved receipts/notes, idempotent replay.
Profile: Account > Company & profile; live identity/email verification/company/hub, explicit missing vehicle/documents, retry/refresh, no-store allowlist, selector refusal.
Existing gaps retained: PLACED-only cancellation conflicts with prior cutoff; S6.2 breakdown/wallet, review, HTTPS/device gates open.
User approved separate local demo data: zero-value parcel #8 / batch #1, DEMO-COURIER-20261108-01. Existing orders/stock/payments untouched.
Real HTTP: all three logins/profiles succeeded; Courier trip #1 Completed/1 received, Rider access 403, Chrome CORS 204; own test tokens logged out.
Profile live HTTP: Demo Pila Courier / Demo Laguna Express / Pila Station Hub; no-store/private; own verification token logged out. Backend started on :8000.
Preview http://127.0.0.1:5175 hot restart complete (1189ms); reused existing server after port-in-use refusal. Next item 2: vehicle/license/private documents + company review (docs/courier-profile-progress.md); migration exception required. Hardware/500m GPS/background/push/reassignment/pay OPEN.
