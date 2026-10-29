# Changes for Friend Review

This document tracks all new tables, columns, enum values, routes, and service classes introduced during mobile-backend integration.

## Phase A: Baseline & Phase A.5 Integrity Checks

- Branch created: `integration/mobile-backend` (cut from `web-api-master` at `4e2cb05`).
- Mobile codebase and documentation brought over from `wip/phases-1-4`.
- Dev database backed up via `mysqldump` to `backups/dev_db_20261029_212446.sql`.
- Backend test suite verified against in-memory SQLite (205 tests passing).
- Mobile test suite verified (709 tests passing).
- No new database tables, columns, enum values, or routes added in Phase A.

### Touched your tests

- `web/tests/Feature/HttpsUrlGenerationTest.php`:
  - **Reason**: Test failed on base `web-api-master` with `"Generated URLs must use https in production. Failed asserting that false is true."` because `config()->set('app.env', 'production')` does not update `$this->app->environment()` (which reads `$this->app['env']` in Laravel 11).
  - **Change**: Added `$this->app['env'] = 'production'` before calling `(new AppServiceProvider($this->app))->boot()`, and restored both `$this->app['env']` and `config('app.env')` in `finally`.
  - **Assertion**: Completely unchanged (`$this->assertTrue(str_starts_with(route('seller.products.index'), 'https://'), 'Generated URLs must use https in production.')`).
