# AGENTS.md — Zefanya

> **Read the documentation first.** The project is documented in exactly four files; they are the source of truth and this file only points to them.

| File | Read it for |
|---|---|
| [`docs/1-Context.md`](docs/1-Context.md) | Purpose, the five roles, stack/versions, how to run + test, per-module status, **known bugs**, decisions, lessons |
| [`docs/2-Design.md`](docs/2-Design.md) | Architecture, services, DB schema, status model, auth/security, UI system, notifications |
| [`docs/3-Folder-Structure.md`](docs/3-Folder-Structure.md) | Where everything lives, naming conventions, orphans/duplicates |
| [`docs/4-Flow.md`](docs/4-Flow.md) | Step-by-step behaviour of every flow, connectivity, invariants |

## Working rules

- **Verify against the code, not old notes.** Where a claim cannot be verified, mark it *unverified*.
- **Status changes** go only through `App\Services\SellerOrderStatusService::transition()`; **stock** only through `App\Services\StockService`.
- **Literal routes before wildcard routes** (see the ordering comments in `routes/buyer.php` / `routes/seller.php`).
- **Backend invariants live in services**, not controllers. `status`/`courier_id` are not mass-assignable; use `forceFill()` for controller-owned audit fields.
- **UI:** Tailwind v4 utilities only; tokens in the `@theme` block of `resources/css/app.css`; no new plain-CSS files.
- Run `vendor/bin/pint --dirty --format agent` after PHP edits and `npm run build` after CSS/JS edits.
- Create tests with `php artisan make:test --phpunit <Name>`; run the narrowest set (`php artisan test --compact <path>`).
- Do not change dependencies, run `migrate:fresh`, or rewrite unrelated code without approval.

## Tooling

This project uses **Laravel Boost** (`composer require laravel/boost --dev && php artisan boost:install`). Use its MCP tools (`database-query`, `database-schema`, `search-docs`, `get-absolute-url`, `browser-logs`, `record-rule`, …) when available.
