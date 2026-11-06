# Progress

## What Works
- Basic Flutter app structure
- Buyer screens (home, product catalog, cart, checkout)
- Courier screens (dashboard, delivery tracker)
- Address form with PSGC cascade (Region → Province → City → Barangay)
- Google sign-in integration (stored in flutter_secure_storage)
- Wishlist page exists and reachable
- Courier QR scanner (compiles cleanly, widget tested)
- Dev QR generator screen (hidden via kDebugMode)

## What's Left to Build
- Laravel backend API integration (repository interfaces + mocks ready)
- Additional test coverage for remaining features

## Current Status
- All 4 project steps complete
- Analyze: 0 errors (3 pre-existing info-level warnings only)
- Tests: 624 passing (+4 new), 45 failing (all pre-existing golden/screenshot failures)

## Known Issues
- 45 pre-existing test failures (golden/screenshot pixel diffs, unrelated to QR scanner work)
- Golden tests fail due to rendering differences, not code regressions