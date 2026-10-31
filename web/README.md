# Zefanya

A Laravel marketplace / ERP (buyers, sellers, couriers/riders, logistics sorting centers, admin).

## Documentation

All project documentation lives in **four** files — start here:

| File | What it covers |
|---|---|
| [`docs/1-Context.md`](docs/1-Context.md) | What the project is, the five roles, tech stack, how to run it and its tests, current status per module, known bugs, decisions, lessons |
| [`docs/2-Design.md`](docs/2-Design.md) | Architecture, services, full database schema, status model, auth/security, UI system, notifications/integrations |
| [`docs/3-Folder-Structure.md`](docs/3-Folder-Structure.md) | Annotated file map, naming conventions, orphaned/duplicate files, root clutter |
| [`docs/4-Flow.md`](docs/4-Flow.md) | Every flow step by step (actor → route → DB → notifications → status), connectivity map, drift list, invariants |

## Quick start

```sh
composer install
copy .env.example .env        # Windows (cp on Unix); set DB_*, APP_KEY, ADMIN_SEED_EMAIL, ADMIN_SEED_PASSWORD
php artisan key:generate
php artisan migrate
php artisan db:seed
npm install && npm run build
php artisan serve            # or: composer run dev
php artisan test --compact   # tests (SQLite in-memory)
```
