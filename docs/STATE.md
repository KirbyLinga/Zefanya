# Combined Courier presentation scope — 2026-11-09
Stage: Courier extensions 22/23 reassignment backend and app/Logistics/history integration DONE. Local transfer activation and physical acceptance OPEN.
Target: C:/Users/Lenovo/Documents/lavarel/ZefanyaWMI/Zefanya; branch codex/combined-courier-demo.
Verified integrated source HEAD: 1aa4056; this state commit records verification. Breakdown local setup a265cd1. No merge.
Owner prioritized consistency pass over next rehearsal; earlier limited Courier scope overrides retained; see DECISIONS.md.
Keep legacy Rider role and linehaul Courier; shared Zefanya styling. Native manifest/custody/status/stock/payment services and trip history remain intact.
Assignment/accept/depart still require passed checklist, On Duty/fresh location; submitted vehicle records require approval/nonexpired license. Unsubmitted compatibility retained.
Submitted profiles retain reviewed capacity gates at assign/accept/depart; no-profile presentation exception remains; universal A39 enforcement OPEN.
Shared LoginScreen handles in-form Courier switch/email validation/branding/autofill and existing real-role auth; legacy Courier login delegates to it.
Four direct shared tabs: Trips/History/Earnings/Profile. Lazy kept-alive date/page state; no separate recent-to-dated History navigation step.
History reuses full trip cards, preserving seal/delay/decline/notes; saving handover note refreshes dated history as well as Trips. Background errors observed.
Profile editorial identity/Settings gear; shared role-safe Settings owns appearance and confirmed/awaited logout. Buyer/Rider-specific records hidden from Courier.
Prior pay snapshot activation approved/applied alone in batch 79 (106.48ms), two original pay/type null; scheduling batch 78 and earlier exceptions retained.
Current 13-file Courier PHP: 96 passed/1895 assertions (51695ms) in SQLite/fakes. Eleven intentional faults caught and restored; tmp/mutation deleted.
Current ten-file Courier mobile: 00:44 +60: All tests passed! Focused navigation/history: 00:09 +16: All tests passed!
Analysis: 8 issues found. (ran in 18.2s); same seven infos/one warning. Web build 13.38s; generated Lucide restored. Pint previously waived/UNRUN.
Current real API login/profile/trips passes; empty breakdown422, logout/revoked token401. #2 Loading/#1 Completed correctly cannot report breakdown yet.
Owner-approved ONLY migration 2026_11_09_020000 applied184.13ms, alone batch80; evidence empty. No other migration/.env/global PHP/dependency/APK change.
Full assignment/manifest/item/order/audit/delay fingerprints unchanged before/after setup and API checks; no trip/readiness/GPS/documents/pay fabricated.
Browser automation previously stopped because URL could not be verified; no alternate UI bypass. Real Back/camera/location/handover checks OPEN.
MySQL zefanya_friend_20261107: #2 accepted/Loading, two parcels/1600g, zero loading tokens; #1 Completed. Off Duty/zero vehicle submissions.
Current backend :8000/session78876 via launcher enables GD process-only when absent. Prior preview :5177 not restarted; restart Flutter for latest source.
OPEN: payouts/Van unsupported/10-wheel no rate; physical full Courier rehearsal/concurrent MySQL/universal reviewed onboarding/capacity.
OPEN: two-Courier physical reassignment rehearsal and simultaneous MySQL transfer verification, arrival geofence/background GPS/push, hosted HTTPS/APK.
NEXT: obtain explicit approval for only transfer migration 2026_11_09_030000, verify local activation, then rehearse. Current demo records untouched.
