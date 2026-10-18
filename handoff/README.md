# Backend handoff — what to send Claude

Everything here is generated from the Laravel app in `web\`. Nothing in this folder
is part of the app; it exists only to hand the server side over.

## Send these

| File | Size | What it is |
|---|---|---|
| `01-api-surface.md` | 201 KB · 5,863 lines · 65 files | Routes, controllers, services, resources, form requests, models, enums, migrations, seeders — the whole server side of `/api/*` |
| `02-api-tests.md` | 88 KB · 2,288 lines · 12 files | The tests that prove it, including the 7 service tests |
| `00-file-manifest.md` | 5 KB · 77 entries | Every path in both bundles, grouped |

Two files are listed in bundle 1 and marked `NOT FOUND` on purpose (see point 3 below);
they are the only gaps, and nothing else is omitted.

Send **`01`** for any question about the API. Add **`02`** when the question is about
correctness or "why does it behave like this" — the tests are where the decisions are
written down. **`00`** is a table of contents: paste it when you want Claude to tell you
what it needs, instead of sending everything.

Each bundle is markdown: one section per source file, headed `### web/... (N lines)`,
with the file **in full** in a fenced block — not excerpts, not signatures.

## Where the backend lives

All of it under `web\`:

| Path | Role |
|---|---|
| `web\routes\api.php` | the only file that lists the API surface — 119 lines, read this first |
| `web\app\Http\Controllers\Api\` | 7 controllers: auth, buyer registration + OTP, catalogue, vouchers, orders |
| `web\app\Services\CartSummary.php`, `OrderPlacer.php` | the money and the placing rules, shared by **both** the API and the web checkout |
| `web\app\Http\Resources\` | 4 resources — the JSON shape is defined here, not in the controllers |
| `web\app\Http\Requests\` | validation; the API uses the buyer and logistics ones |
| `web\app\Models\` + `web\app\Enums\` | 17 models, 5 enums |
| `web\database\migrations\` | 19 migrations |
| `web\database\seeders\` | demo catalogue, buyer and orders |
| `web\tests\Feature\Api\`, `...\Services\` | 95 tests / 476 assertions against `tests\Feature\Api` |

## Three things to know before you send it

**1. There are two `Product` classes, and one is dead.**
`web\app\Models\Product.php` (103 lines, `namespace App\Models`) is the live one every
controller imports. `web\app\Models\Seller\Product.php` (85 lines) declares
`namespace App\Models` **too** — same class — but PSR-4 resolves `App\Models\Product` to
the flat path, so the `Seller\` copy is unreachable while still declaring a duplicate.
It differs from the real one: it has `primaryImage()` but no `cartItems()`, and its
`isOutOfStock()` compares `=== 0` instead of `<= 0`. **It is not in the bundle, on
purpose** — send it and Claude will think there are two product models. Also
`web\app\Models\Seller\ProductImage.php` is a **0-byte file**. Both predate this work.

**2. `docs/api-contract.md` does not exist.** There is no `docs\` folder in this repo at
all, yet the file is cited 14× across 12 files in `mobile\lib\`, and once inside bundle 1
(`VoucherResource.php`) and once in bundle 2 (`VoucherApiTest.php`). If Claude asks for
it, say it's absent — `web\routes\api.php` is the real specification now.

**3. `config\cors.php` and `config\sanctum.php` are absent.** Normal for Laravel 11+, but
both are listed in bundle 1 and marked `NOT FOUND` so nobody proposes editing them.
`config\` contains ten files; `routes\` contains seven.

## Running the tests

```
cd web
php -d extension=pdo_sqlite vendor/bin/phpunit tests/Feature/Api tests/Feature/Services
```

`pdo_sqlite` is commented out in `C:\PHP\php.ini`, so the `-d` flag is required — and
`artisan test` will *not* work, because the child process doesn't inherit `-d`.

## Keeping this fresh

```
powershell -ExecutionPolicy Bypass -File handoff\rebuild-bundles.ps1
```

Re-run after changing any backend file, so what Claude reads is the code you have. It
derives its file lists from folders and globs, so new controllers, resources, migrations
and tests get picked up automatically.

## Not included

This is the backend only. The Flutter side of the same contract lives in
`mobile\lib\data\repositories\` and `mobile\lib\data\dto\` (`remote_catalog.dart`,
`remote_orders.dart`). Ask and I'll add `03-mobile-contract.md`.