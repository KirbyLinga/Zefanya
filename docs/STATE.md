# Combined Courier presentation scope — 2026-11-08
Stage: Courier extension 10 (reviewed capacity gates at assign/accept/depart) DONE; universal A39 enforcement/schedule/pay OPEN.
Target: C:/Users/Lenovo/Documents/lavarel/ZefanyaWMI/Zefanya; branch codex/combined-courier-demo.
Verified capacity source HEAD: 75e252c; this follow-up records state. Same authorized Courier branch; no merge.
User explicitly prioritized this limited Courier scope over the old Courier exclusion/EXTEND order; see DECISIONS.md.
Keep legacy Rider role and linehaul Courier; shared Zefanya styling. Native manifest/custody/status/stock/payment services and trip history remain intact.
Assignment/accept/depart still require passed checklist, On Duty/fresh location; submitted vehicle records require approval/nonexpired license. Unsubmitted compatibility retained.
For submitted vehicle profiles, new assignment/accept/depart require all active parcel weights positive and total <= reviewed capacity in integer grams.
Existing driver/manifest transaction locks plus stable-ID parcel row locks; recheck on accept/depart. Exact 3.60kg allowed; 3.601kg refused.
Missing/zero weight blocks before writes; no catalog fallback. Rejection preserves manifest/assignment/parcel/audit records. Existing native lifecycle remains.
No-profile presentation exception remains explicitly; demo Courier not universally capacity-enforced. Native Logistics dispatch outside Courier service unchanged.
Owner approved/applied ONLY 2026_11_08_230000_create_linehaul_trip_delays.php (387.04ms), alone in batch 77; zero reports; original assignments/statuses unchanged.
Prior initial Courier/seal/duty/vehicle/decline migrations remain installed under their specific exceptions; no other pending migration run.
Final related PHP: {"tool":"phpunit","result":"passed","tests":214,"passed":214,"assertions":2491,"duration_ms":56624}
Three capacity boundary/rollback tests plus all Courier, native internal manifest/handoff/incidents and Rider registration regressions pass.
Prior extension-9 Courier mobile: 00:19 +38: All tests passed! Unchanged mobile not rerun. Last full app prior vehicle-stage 901.
Prior mobile analysis: 8 issues found. (ran in 8.4s); unchanged seven infos/one unused-test-helper warning; not rerun this PHP-only stage.
No frontend changes; build not repeated. Pint waived/UNRUN. No new route/schema/service/dependency/configuration; no local setup needed.
Five capacity faults detected/exactly restored; tmp/mutation deleted; earlier mutation proofs retained. No database update/demo mutation this stage.
Prior local HTTP: #2 Loading/2 parcels/1600g, #1 Completed/1 parcel/800g; times returned, token logged out. Not rerun this stage.
Current demo #2 already accepted/Loading, so delay stays unavailable until genuine departure; #1 Completed. No fake report/GPS/docs/pay.
Preview http://127.0.0.1:5176 hot restarted (1088ms), no client connected. API http://127.0.0.1:8000. No merge/APK.
OPEN: physical C5/C1 document/decline/Maps rehearsal, simultaneous MySQL proof, universal verified onboarding/capacity, schedule/pay.
OPEN: in-transit reassignment, geofence/background GPS/push, hosted HTTPS/APK and older S6.2 gates; Courier not fully complete.
NEXT: backed departure scheduling or full real rehearsal; pay remains open. See courier-capacity-progress.md. STOP after this stage.
