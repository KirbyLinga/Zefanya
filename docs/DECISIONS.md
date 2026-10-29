# Decisions & Deviations

This file records deviations from the repository's baseline instructions or decisions required during execution.

## Phase A: Baseline & Integration Setup

1. **Base Branch Selection**:
   - `web-api` and `web-api-master` do not contain one another (`git merge-base --is-ancestor` returned 1 for both).
   - `web-api-master` has the newest commit (`4e2cb05` on Oct 4, 2026, 19:06:31 vs `fceb34b` on Oct 4, 2026, 18:55:23).
   - Cut `integration/mobile-backend` from `web-api-master` and checked out `mobile\` and `docs\` from `wip/phases-1-4`.

2. **Obsolete File Removal (`role_switcher_screen.dart`)**:
   - `mobile/lib/screens/role_switcher_screen.dart` was present on `web-api-master` (from commit `4e60367`) but was deleted in mobile development (commit `8d84f2e`).
   - `git checkout wip/phases-1-4 -- mobile docs` brought over tree files but left this orphaned file on disk, producing 2 `invalid_constant` errors in `flutter analyze`.
   - Removed with `git rm mobile/lib/screens/role_switcher_screen.dart`. Clean analysis restored.

3. **Backend Test Environment Fix (`HttpsUrlGenerationTest.php`)**:
   - `HttpsUrlGenerationTest::test_named_routes_start_with_https_in_production` failed because `config()->set('app.env', 'production')` does not update `$this->app['env']` in Laravel 11 container.
   - Added `$this->app['env'] = 'production'` before booting `AppServiceProvider` and restored it in `finally`, preserving test expectation.
