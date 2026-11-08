# Combined Courier presentation scope — 2026-11-08
Stage: Courier extension 5 item 2 (vehicle/license/private documents/company review) CODE + LOCAL SETUP DONE; manual C1/device acceptance OPEN.
Target: C:/Users/Lenovo/Documents/lavarel/ZefanyaWMI/Zefanya; branch codex/combined-courier-demo.
Verified vehicle source HEAD: 6289d7e; this follow-up only records state. Same Courier branch; no merge.
User explicitly prioritized this limited Courier scope over the old Courier exclusion/EXTEND order; see DECISIONS.md.
Keep legacy Rider role and linehaul Courier; shared Zefanya styling. Native manifest/custody/status/stock/payment services and trip history remain intact.
New assignment/accept/depart require passed checklist, On Duty and <5-minute location; submitted vehicle records additionally need approval/nonexpired license.
Vehicle edits require Off Duty/no active trip; optimistic versions; normalized cross-role plates under shared mutex/current locking reads in both Rider writers.
Professional license/codes/expiry, truck type/capacity; private license/OR-CR/NBI uploads checked by content/type and <=5 MB; safe replacement/rollback.
Logistics > Couriers: own-company list, private downloads, version-bound approval/corrections/capacity confirmation; immutable review replay; named 60/min limit.
Presentation compatibility: ALL unsubmitted accounts retain previous access; Active/email verification is not document approval. Mandatory onboarding deferred.
Earlier initial Courier, seal and duty migrations remain installed under prior specific exceptions; existing account/trip/order values unchanged this stage.
Owner approved/applied ONLY 2026_11_08_200000_create_linehaul_profiles.php (397.78ms): linehaul_profiles + vehicle_plate_locks; singleton 1, zero vehicle records.
Final related PHP: {"tool":"phpunit","result":"passed","tests":193,"passed":193,"assertions":2081,"duration_ms":63407}
Latest focused PHP after view-case alignment: {"tool":"phpunit","result":"passed","tests":10,"passed":10,"assertions":279,"duration_ms":9358}
Full mobile: 03:29 +901: All tests passed! Final focused after test-only style/minimal-diff corrections: 00:11 +19: All tests passed!
Mobile analysis: 8 issues found. (ran in 9.0s); same existing 7 infos/1 unused-test-helper warning, no new issues.
Web build: 8.72s. Added only file_selector 1.1.0 and its 3 missing platform packages; no upgrades. Pint remains waived/UNRUN.
18 new safety faults detected/restored; tmp/mutation deleted; prior security/seal/exception/duty/closeout proofs retained in mutation-proof.md.
Demo data preserved: zero-value completed batch #1/parcel #8; #2-4 unchanged by this stage. Demo Courier has an active draft: finish handover before editing.
Backend :8000 now uses tools/serve-local.ps1, process-only 5M uploads/20M requests; global php.ini/.env unchanged. Default PHP was 2M/8M.
Post-activation real HTTP 200: setup_required false/editable false/profile null; no-store/private. Three 5 MB files pass validation, oversize refused; deliberate missing expiry blocks saving; tokens logged out.
Preview http://127.0.0.1:5175 recompiled (54.3s), includes file-selector plugin; API http://127.0.0.1:8000. No merge or release APK this stage.
OPEN: real documents/camera/Files/PDF/device and hosted PHP limits; MySQL concurrency; hub GPS/background/push/reassignment/pay; older cancellation/S6.2/HTTPS gates.
NEXT: finish active demo trip, go Off Duty, use actual user documents and rehearse Courier > own-company review; no documents fabricated. All four batch statuses unchanged; see courier-profile-progress.md.
