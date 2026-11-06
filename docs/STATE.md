Branch: feature/s1-lite  HEAD: 213e70e  Date: 2026-11-06
Stage: S8B-lite DONE (S0-lite, S1-lite, S2, S3-lite, S4, S5 already DONE). Next: S10 (FREEZE).
Base check: branch cut from integration/hardened @ 6a9605a.

## Test counts (HEAD 213e70e)
- php artisan test: 439 passed, 0 failed (2082 assertions)
- flutter test: +804: All tests passed!
- flutter analyze: 5 info-level suggestions only (sized_box_for_whitespace in orders_status_screen.dart; no errors/warnings)

## S8B-lite done (213e70e)
- 8.1 OK 213e70e — Portrait lock: SystemChrome.setPreferredOrientations([portraitUp, portraitDown]) in main.dart before runApp.
- 8.2 OK — Keyboard types and autofill hints already existed (login_screen, otp_screen, registration_screen).
- 8.3 OK 213e70e — No-internet banner with Retry: no_internet_banner.dart + connection_watcher.dart. Banner shows only when offline, with Retry and refresh. Tested.
- 8.4 SKIPPED by schedule override — discard-changes confirm already exists in dirty_guard.dart.
- 8.5 SKIPPED by schedule override — AuthGate is a single Navigator root; popUntil((route) => route.isFirst) handles logout.
- 8.6 OK 213e70e — Overflow sweep: test/overflow_sweep_test.dart renders 360x640 with textScale 1.3 and checks hasVisualOverflow on the top-level RenderBox. All screens pass.

## S8B-lite files (213e70e)
- mobile/lib/main.dart — portrait lock added (13 lines).
- mobile/lib/services/connection_watcher.dart — new (connection state, DNS probe via InternetAddress.lookup on 8.8.8.8).
- mobile/lib/widgets/no_internet_banner.dart — new (offline banner with Retry and pull-to-refresh).
- mobile/test/no_internet_banner_test.dart — new (3 widget tests: online shrinks to zero height, offline renders toolbar banner, Retry/refresh call callbacks).
- mobile/test/overflow_sweep_test.dart — new (7 main screens rendered at 360x640/textScale 1.3, overflow checked on top-level RenderBox).

## Open
- Avatar is local-only: no server upload route exists (DEVIATION in DECISIONS.md).
- API admin logistics approve/reject lacks a success test; Admin lacks HasApiTokens. Carry to S10.
