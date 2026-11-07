# Combined Courier presentation scope — 2026-11-08
Stage: Courier design integration DONE; limited Courier/local setup retained; assigned-trip/device rehearsal still OPEN.
Target: C:/Users/Lenovo/Documents/lavarel/ZefanyaWMI/Zefanya; branch codex/combined-courier-demo.
Verified design/fix HEAD: e5f1c95 (design core 98ee278); follow-up records this hash only.
User explicitly prioritized this limited Courier scope over the old Courier exclusion/EXTEND order; see DECISIONS.md.
Keep legacy Rider role and linehaul Courier. Branded login, Trips/History/Account use shared Zefanya fonts/colors/cards/nav/empty states.
Logistics assigns one active forward manifest to an eligible same-company Courier; driver/manifest locks and ownership policy.
Courier accepts, scans QR/Code128/manual, departs with seal, reports arrival; native Logistics receiving alone yields Completed.
Native ManifestService/custody/status retained; encrypted/hidden scan tokens expire; actual actor audits retained.
New migration installed ONLY linehaul_drivers/linehaul_assignments in zefanya_friend_20261107, with explicit user exception.
Explicitly provisioned one demo Courier at Demo Laguna Express/Pila Station Hub; no existing account overwritten.
Baseline: 80 PHP tests/622 assertions and 873 mobile tests passed after locked dependencies restored and process-only GD enabled.
Restored broader PHP: {"tool":"phpunit","result":"passed","tests":121,"passed":121,"assertions":1009,"duration_ms":32119}
Final Artisan: {"tool":"phpunit","result":"passed","tests":9,"passed":9,"assertions":154,"duration_ms":5781}
Design final full mobile: 03:08 +886: All tests passed! Final focused navigation/action/design: 20 passed.
Design final analysis: 8 issues found. (ran in 27.1s); existing 7 infos and 1 unused-test-helper warning, no new findings.
Web assets: built in 7.30s. Live-mode Chrome build: Built build/web (222.4s). No live endpoint baked into build.
Ten deliberate security faults detected, exact hunks restored and named tests pass; tmp/mutation deleted before commit.
Design: no PHP changes. Ten widget-render previews inspected at 360x640/1.3 text, light/dark; source/whitespace checks passed.
Deferred: activation/document/vehicle/duty/capacity flows, 500 m GPS/tracking/push, reassignment, pay and full discrepancy rules.
Existing gaps retained: PLACED-only cancellation conflicts with prior cutoff; S6.2 breakdown/wallet, review, HTTPS/device gates open.
User approved config/new tables/account explicitly; .env copied from existing local backend, ignored by Git. No order/stock/payment edits.
Real HTTP: all three logins/profiles succeeded; Courier trips 200/empty, Rider access 403, Chrome CORS 204; own test tokens logged out.
Latest design preview: http://127.0.0.1:5174; backend http://127.0.0.1:8000. Debug web recompile passed; see demo-script.md.
Next: Logistics assigns a real demo manifest, rehearse full physical handover; verify phone camera/permissions. No merge/APK; Pint waived/UNRUN.
