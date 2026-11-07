# Combined Courier presentation scope — 2026-11-08
Stage: limited Courier implemented/tested; local MySQL setup/logins verified; assigned-trip/device rehearsal still OPEN.
Target: C:/Users/Lenovo/Documents/lavarel/ZefanyaWMI/Zefanya; branch codex/combined-courier-demo.
Verified implementation HEAD: 8e8d7d5; follow-up records its hash and local-setup continuation only.
User explicitly prioritized this limited Courier scope over the old Courier exclusion/EXTEND order; see DECISIONS.md.
Keep UserRole.courier as legacy Rider; new linehaul role/model/ability and live-only Courier login/portal.
Logistics assigns one active forward manifest to an eligible same-company Courier; driver/manifest locks and ownership policy.
Courier accepts, scans QR/Code128/manual, departs with seal, reports arrival; native Logistics receiving alone yields Completed.
Native ManifestService/custody/status retained; encrypted/hidden scan tokens expire; actual actor audits retained.
New migration installed ONLY linehaul_drivers/linehaul_assignments in zefanya_friend_20261107, with explicit user exception.
Explicitly provisioned one demo Courier at Demo Laguna Express/Pila Station Hub; no existing account overwritten.
Baseline: 80 PHP tests/622 assertions and 873 mobile tests passed after locked dependencies restored and process-only GD enabled.
Restored broader PHP: {"tool":"phpunit","result":"passed","tests":121,"passed":121,"assertions":1009,"duration_ms":32119}
Final Artisan: {"tool":"phpunit","result":"passed","tests":9,"passed":9,"assertions":154,"duration_ms":5781}
Full mobile: 03:50 +879: All tests passed! Focused auth/routing/Courier suites pass.
Analysis: 8 issues found. (ran in 11.7s); existing 7 infos and 1 unused-test-helper warning, no new findings.
Web assets: built in 7.30s. Live-mode Chrome build: Built build/web (222.4s). No live endpoint baked into build.
Ten deliberate security faults detected, exact hunks restored and named tests pass; tmp/mutation deleted before commit.
All 19 changed/new PHP/Blade files passed syntax; source BOM and git diff whitespace checks passed.
Deferred: activation/document/vehicle/duty/capacity flows, 500 m GPS/tracking/push, reassignment, pay and full discrepancy rules.
Existing gaps retained: PLACED-only cancellation conflicts with prior cutoff; S6.2 breakdown/wallet, review, HTTPS/device gates open.
User approved config/new tables/account explicitly; .env copied from existing local backend, ignored by Git. No order/stock/payment edits.
Real HTTP: all three logins/profiles succeeded; Courier trips 200/empty, Rider access 403, Chrome CORS 204; own test tokens logged out.
Running locally: app http://127.0.0.1:5173 and this backend http://127.0.0.1:8000; both reach HTTP 200. See demo-script.md.
Next: Logistics assigns a real demo manifest, rehearse full physical handover; verify phone camera/permissions. No merge/APK; Pint waived/UNRUN.
