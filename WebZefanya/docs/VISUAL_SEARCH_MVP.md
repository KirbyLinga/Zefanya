# Buyer visual search and automatic catalog indexing

Updated 2026-11-17 in `C:\ProjectEcommerce\WebZefanya`.

## Current status

**Object-aware search is enabled on the live Windows host (November 17, 2026).** A pinned, quantized CLIP ViT-B/32 encoder performs actual inference through a bounded loopback-only worker. A different book cover, a book-stack photograph and a stack illustration returned the three available books in public-site verification. Exact-cover photo comparison stays first; broader recommendations are displayed separately.

Supported recommendation families validated here: books, clothing, footwear, bags and electronics. Clothing/electronics recommendations also require compatible subtypes, so a phone does not recommend gaming products merely because both are electronics. Unknown or ambiguous recognition abstains. The structural fallback, optional manual category selector, product/Seller availability and visibility rules remain.

See [object-aware implementation, evidence, operations and rollback](OBJECT_AWARE_VISUAL_SEARCH.md). Earlier [fallback accuracy](VISUAL_SEARCH_ACCURACY_FIX.md) and [pre-activation readiness](VISUAL_SEARCH_SEMANTIC_READINESS.md) reports are historical; their inactive-model statements predate this activation.

## Index architecture and triggers

- `VisualSearchImageObserver` queues a lightweight private refresh marker after a ProductImage create, relevant update, or deletion commits. Rollbacks emit no work. Repeated events coalesce per image. Marker acknowledgement is serialized and token-checked, so a later event survives an earlier build.
- The existing Laravel scheduler runs `visual-search:index --batch=200 --max-batches=5` every minute, in the background, with overlap protection. The command processes up to 200 pending markers plus five 200-row scan batches. No inference/image decoding runs inside publishing requests. This uses the existing scheduler rather than database queue jobs, so it also works when the application's queue driver is `sync`.
- A persistent scan cursor ensures later records are eventually visited. Scanning includes nonvisible records so stale mappings are removed. Bulk edits/deletions that bypass Eloquent events, approval, restocking, and Seller visibility changes are reconciled on subsequent scan cycles. At the end of a complete pass, missing/ineligible image mappings and unreferenced features are pruned. Visibility is still rechecked immediately on every Buyer search.
- Features are stored in a **private disposable SQLite cache**, `storage/app/private/visual-search/catalog.json.sqlite`, with WAL/SHM files as needed. This is not the application database and requires no application migrations. PHP PDO SQLite is required. Refresh markers are beside it in `catalog.json.sqlite.pending/`.
- Cache entries are per image, with a descriptor-version identifier. SHA-256 content hashes share features across identical files; repeated physical paths also reuse their fingerprint. Changed paths, sizes or modification times invalidate entries immediately. Unchanged files are not decoded again. If a file is deliberately overwritten while preserving both size and modification time, run `--rebuild` to force regeneration.
- SQLite commits each scan batch atomically. Readers continue using already published entries; the original version-checked `catalog.json` is also retained as a transitional fallback for not-yet-built entries. No existing working index is cleared before rebuilding.
- Initial/full rebuilds run in CLI, with bounded memory rather than one catalog-sized PHP/JSON array. Limits: 100,000 eligible image records, 256 MB derived SQLite file, 1-1000 rows per batch; no intentional seven-photo cap. Disk limits stop further indexing rather than wiping working entries. Very large WAL files and disk exhaustion still need operational monitoring.

Automatic indexing requires the existing scheduler to continue invoking Laravel every minute. The local `Zefanya Laravel Scheduler` Windows task was observed running; no OS tasks, production environment settings, or queue-worker configuration were changed. Do not invoke all scheduled business tasks just to test image indexing.

## Commands

Run from the project root:

```powershell
# Initial complete catalog scan, or reconcile all current records
php artisan visual-search:index

# Bounded resumable work, also used by the schedule
php artisan visual-search:index --batch=200 --max-batches=5

# Force descriptors to be regenerated; retain working entries during replacement
php artisan visual-search:index --rebuild
```

Normal runs reuse unchanged descriptors. `--max-batches=0` scans to the end; a bounded invocation persists its cursor for the next normal invocation. `--rebuild` restarts the cursor, so do not repeatedly pass it when trying to resume a partial scan. Output reports eligible/cached counts, refreshed/reused/deduplicated/skipped entries, resume cursor and elapsed time. A cached count is not proof of current coverage while a scan is partial; served results always recheck current eligibility and fingerprints.

Missing or invalid local files are skipped. Interrupted batches roll back and retry on the next invocation. A conflicting indexer exits safely. Bad descriptor JSON/version entries are excluded and regenerated on a scan. A structurally corrupt SQLite file needs operator recovery of this disposable cache (including its WAL/SHM companions); `--rebuild` does not repair arbitrary SQLite-file corruption. Keep the original JSON fallback until the new cache is healthy. Never operate on the populated application database to recover this cache.

## Fallback search, privacy and security

The available engine reduces images to 64x64 thumbnails, square-root RGB histograms (global and center), 4x4 spatial RGB descriptors and 4x4-cell unsigned gradient histograms (eight directions per cell). Ranking uses 80% grayscale structure cosine similarity and 20% the original appearance score. Gradient magnitude normalization reduces sensitivity to brightness; very weak gradients are discarded as noise. A candidate must meet both structure >= 0.80 and combined score >= 0.82, and be within 0.08 of the best qualifying score. Scores are internal heuristics, not probabilities. These conservative cutoffs were checked against 19 actual catalog photos and transformed examples; they are not a universal accuracy calibration.

Best qualifying image per product prevents duplicate cards. Up to 16 results are returned without padding a fixed count. The optional `category_id` is validated against existing catalog categories and applied only when explicitly selected by the Buyer; no category is inferred from the screenshot or its colors. All categories remain eligible by default. Retired descriptor versions are excluded and regenerated by the existing resumable indexer. These descriptors are not learned CLIP embeddings or verified exact matches.

JPEG, PNG and WebP are validated server-side, limited to 5 MB, 5000 pixels per side and 12 megapixels before decoding. Buyer screenshots remain request-temporary files; no screenshot bytes are written to the cache, session, or account. Preview blob URLs are revoked. Only catalog-controlled local public-disk paths are readable; remote URLs, arbitrary upload paths, traversal and symlink escapes remain rejected.

POST `/buyer/visual-search` retains CSRF and Laravel throttling (10 requests/minute/IP). Validation returns 422, session expiry 419, limiting 429, unavailable processing/index 503. Only existing `Product::discoverable()` items are returned, including a second live eligibility check after ranking. Approval/stock/Seller-state rules, existing text search, checkout and financial behavior are unchanged.

## Active CLIP runtime

The approved model is `Xenova/clip-vit-base-patch32`, revision `6b7c523703ae26e39be4b03774966f8571c7f523`, INT8 vision/text exports. Files and SHA-256 metadata are private under `storage/app/private/visual-search/clip-runtime/`. Existing ONNX Runtime, NumPy, Pillow and tokenizers packages are reused; PyTorch and Transformers are not required.

The Windows-only worker binds `127.0.0.1:8766`, requires a private request key, processes one image at a time, limits model/BLAS threads to one, runs below normal priority and applies a 512 MiB process committed-memory cap. Tested peak working set was below 183 MiB. Only the image encoder stays resident once text prototype vectors are cached. Inputs are bounded to the existing 5 MB/12-megapixel/5000px limits, with an additional encoder aspect-ratio bound. Uploaded query bytes are not retained.

Actual image embeddings are cached by content hash and pinned model version in `semantic_features`, an additive table in the existing disposable SQLite image cache. The existing scheduler/index command processes up to 16 missing unique semantic images per invocation; failure stops that inference batch after one attempt. A stale or unavailable model never grants catalog visibility. Normal photo comparisons do not depend on the worker succeeding.

A confident supported object family requires cosine score >= 0.24 and a >= 0.015 margin over the next family. A compatible indexed object plus image cosine >= 0.35 qualifies a category recommendation; ranking combines 85% semantic cosine, 15% structural/appearance similarity and a small same-subtype preference. These are small-fixture-calibrated heuristic scores, not probabilities. Strong photo comparisons appear before recommendations. Uncertain recognition retains only strong (>= 0.95) photo evidence; an unavailable worker retains the original structural fallback.

```powershell
powershell -NoProfile -File scripts/visual-search/clip-worker.ps1 Status
powershell -NoProfile -File scripts/visual-search/clip-worker.ps1 Start
powershell -NoProfile -File scripts/visual-search/clip-worker.ps1 Stop
python scripts/visual-search/readiness.py
python scripts/visual-search/evaluate_clip.py
```

The worker was started and stop/start recovery verified. No Windows startup task was installed: after a PC reboot, start it with the command above; photo comparison remains available while it is down. Laravel uses a bounded 1.5-second inference request deadline. No paid/external inference provider, application migration, business-data change or production web-server restart was used.

## Verification

Final targeted run: **83 PHPUnit tests passed, 684 assertions**. Both visual-search JavaScript tests passed. The unchanged existing Buyer text-search JavaScript suite passed 12/13; its keyboard-dropdown test at `tests/js/buyer-search.test.mjs:139` still expects `aria-expanded=false` but receives `true`, as reported before this upgrade. No text-search source/tests were changed here. Scoped Pint, PHP/JavaScript syntax checks, scoped diff whitespace checks and the frontend build passed. Old hashed assets and the exact existing Lucide bytes were preserved.

Live verification: the existing scheduler consumed an intentionally queued **derived refresh marker** without changing a catalog record. The cache remained 7/7. Headless Edge checked uploads at 375px and 1280px: both returned HTTP 200, four suggestions, the known catalog product first, no horizontal overflow and no page JavaScript errors. Evidence is in the pre-upgrade recovery directory. No semantic AI accuracy test, large MySQL benchmark or full application test suite is claimed.

Dependency analysis used `graphify query "ProductImage visual search" --graph graphify-out/graph.json --budget 350 --depth 1`. Graphify is installed; the graph did not include this new feature, so current routes/controller/request, ProductImage publishing and deletion paths, Product visibility/variant rules, provider observers, scheduler and tests were traced directly. `../CLAUDE_GRAPH_WORKFLOW.md` is absent. Source changes to the already-dirty provider and console routes were compared against pre-upgrade copies: only the observer registration and image-index schedule were added. No checkout, inventory mutation, order, custody or financial services were changed.

Tests use in-memory SQLite application fixtures, UUID-scoped private caches **and UUID-scoped image storage**. They do not copy or mutate live catalog records. Coverage includes after-commit events/rollback/coalescing, marker races, resumable batches, bulk changes/deletions, product approval with available variants, duplicate content, forced rebuilds, stale versions, legacy fallback during partial builds, scheduler registration, upload formats/security, live visibility, multi-photo deduplication and throttling.

Commands:

```powershell
php artisan test --compact tests/Feature/Buyer/VisualCatalogIndexingTest.php tests/Feature/Buyer/VisualSearchTest.php tests/Feature/Buyer/BuyerCatalogEligibilityTest.php tests/Feature/Buyer/BuyerSearchTest.php
node --test tests/js/visual-search.test.mjs tests/js/buyer-search.test.mjs
npm run build -- --emptyOutDir false
```

Synthetic scaling measurements from a passing isolated run (10 physical 96?96 PNGs shared across one product's image records, so this measures record scanning/deduplication, **not 10,000 distinct full-size photos or CLIP inference**):

| Image records | Cold index | Warm scan | Search | PHP peak |
| --- | ---: | ---: | ---: | ---: |
| 100 | 0.129 s | 0.050 s | 0.054 s | 72 MB |
| 1,000 | 0.710 s | 0.438 s | 0.397 s | 72 MB |
| 10,000 | 7.108 s | 4.716 s | 4.236 s | 88 MB |

The live warm seven-photo scan took 0.086 s. These are local SQLite/CPU observations; they do not establish large-catalog MySQL throughput or production concurrency limits. Search still performs a linear comparison and filesystem checks; very large catalogs require further retrieval/performance work.

Manual checks:

1. At 375px and 1280px, activate the navbar camera with keyboard/pointer, select a valid image, check preview/loading/cards and follow a product link. Escape should close the dialog and restore camera focus.
2. Use a known catalog photo/screenshot to check inclusion/ranking, and confirm multiple image records produce one product card. Assess color/layout limitations honestly.
3. In isolated fixtures, add/edit/remove an image, commit it, check the pending marker, run bounded indexing, and verify refresh/removal. Roll back another change and verify no marker remains.
4. In isolated fixtures, approve a product with an available variant, or change Seller visibility/stock; verify immediate serving eligibility and eventual indexing reconciliation. Do not edit live inventory for tests.
5. Try unsupported/invalid/oversized images, missing files, unavailable processing, empty catalogs and rate limits. Rebuild and verify recovery/reuse.

## Files and recovery

Upgrade adds `app/Services/Buyer/VisualCatalogIndex.php`, `app/Observers/VisualSearchImageObserver.php`, and `tests/Feature/Buyer/VisualCatalogIndexingTest.php`.

Upgrade changes `app/Services/Buyer/VisualSearchService.php`, `app/Console/Commands/IndexVisualSearch.php`, `app/Providers/AppServiceProvider.php` (only observer registration), `routes/console.php` (only image-index scheduling), `config/visual-search.php`, `tests/Feature/Buyer/VisualSearchTest.php`, and this document. No Buyer UI redesign or new frontend dependency is involved. Original MVP controller, upload request, image descriptor engine, routes, navbar camera, panels and existing cards remain in use.

Pre-upgrade copies: `C:\ProjectEcommerce\.recovery\visual-search-auto-index-20261116`. Before-MVP copies: `C:\ProjectEcommerce\.recovery\visual-search-mvp-20261116`. Compare and reverse only this feature's hunks; do not blindly replace files over unrelated changes. The original JSON cache was preserved. No application-database rollback/migration is needed. Keep old hashed frontend assets and preserve independently modified `public/js/lucide.min.js` when building.
