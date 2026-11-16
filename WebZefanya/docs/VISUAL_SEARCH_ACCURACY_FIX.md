# Visual search accuracy fix ? November 17, 2026

> Historical report, before approved CLIP activation. Current behavior: [Object-aware Visual Search](OBJECT_AWARE_VISUAL_SEARCH.md).

Implemented directly in `C:\ProjectEcommerce\WebZefanya`; current public-origin rendering and uploads verified at `https://zefanya.shop/buyer/visual-search`.

## Root cause and active engine

CLIP is not installed, loaded or called. No Python inference service exists. Having ONNX Runtime installed does not provide semantic inference. The old `color-layout-v1` ranking was 80% RGB histograms and 20% coarse spatial RGB; its 0.58 minimum admitted shared white backgrounds and similar palettes across unrelated objects. Categories and product names supplied no query-type evidence. Ranking already deduplicated by product and reused the discoverable scope correctly; those were not the cause.

The host had approximately 1.05 GiB free physical RAM during this work. No models, packages or services were installed. The engine remains an accurately labelled, non-semantic fallback.

## Implemented ranking

- New descriptor version `structure-color-v2`: normalized unsigned gradient-direction histograms across 4x4 spatial cells, with eight directions per cell. Uses grayscale structure, suppressing weak gradients below magnitude 12.
- Combined score: 80% structural cosine similarity, 20% the original color/layout appearance score. Normalization improves tolerance to moderate lighting changes.
- Require independent structural score >= 0.80 and combined score >= 0.82. Among those candidates, exclude scores more than 0.08 below the best qualifying score. Internal scores are not percentages or confidence probabilities.
- A flat color image has no structural evidence and cannot qualify by color alone. Results are not padded to a requested count; zero is valid.
- Keep the highest qualifying image score per product, then retain descending similarity order. Related-category listings can appear when their images qualify; no automatic category exclusions or title-specific rules exist.
- Optional Buyer-selected existing catalog category supplies explicit metadata evidence. The server validates it, narrows candidates and rechecks it with live visibility. Changing the selection automatically resubmits the chosen image. No uploaded object's category is guessed from color, filename or product popularity.
- Retired feature versions cannot be mixed with v2. Existing incremental indexing regenerates them. The live derived cache reconciliation completed with 25 eligible/cached image records, zero skipped. No application migration or business-record modification.

Cutoffs were checked against 19 representative catalog photos (three books, game cases, two blouse listings and a dress listing), with actual-photo exposure and padding variations. This is conservative local calibration, not a general image-recognition benchmark.

## Before and after evidence

The original reported stack-of-books screenshot was not attached as an image file in this task. Its exact before/after result is **unverified**. No substitute is labelled as that screenshot.

The saved original feature class was evaluated alongside v2 on the same catalog-photo fixture set. The following are padded-photo examples, not the missing screenshot:

| Query | Previous qualifying products | After |
|---|---|---|
| Atomic Habits photo | Atomic Habits, Women's Blouse, The Midnight Library, Rich Dad Poor Dad | Atomic Habits only |
| The Midnight Library photo | Six products, including dress and blouse listings | The Midnight Library only |
| Rich Dad Poor Dad photo | Five products, including blouse listings | Rich Dad Poor Dad only |
| Dress photo | Dress | Dress only |
| Blouse photos | Unrelated book/game listings could qualify for one blouse query | Matching blouse listing only |
| Game cases photo | Game cases and a blouse listing | Game cases only |

Reduced-exposure versions retained their matching book, blouse, dress or game-case product under the new ranking. Original matching photographs were checked as well. These queries test the same photographed items, not arbitrary unseen examples of those object classes.

Live Edge verification at both 375px and 1280px: padded Atomic Habits upload -> HTTP 200, one Atomic Habits card; padded dress -> HTTP 200, one dress card; choosing Books for the dress -> HTTP 200, zero results. Preview and loading appeared, category changes resubmitted, no horizontal overflow or page JavaScript errors. A controlled intercepted 503 verified error text and re-enabled submission; it is UI-error evidence, not a claim of an actual production outage. No cart or other business action was submitted. Agent-browser CLI was unavailable; the existing Playwright package and installed Edge were used.

## Verification

- Combined visual search, indexing, accuracy and catalog eligibility run: **72 tests passed, 621 assertions, zero failures**, 60.756 seconds. Then added one explicit cross-category ranking/deduplication regression; the final accuracy-suite rerun passed **26 tests, 173 assertions, zero failures**, 36.302 seconds.
- Existing JavaScript upload tests: **2 passed, 0 failed**, including JPEG/PNG/WebP, exact 5 MB boundary, oversized, unsupported and absent files.
- Real JPEG exactly 5 MB accepted in the accuracy suite. Existing PHP regressions cover oversized and corrupt uploads, JPEG/PNG/WebP processing, missing/corrupt/retired cache, empty catalog, pending/rejected/inactive/restricted/deleted/out-of-stock products and unavailable Sellers.
- Existing upload/HTTP tests now use structured synthetic fixtures rather than flat color swatches. Synthetic index benchmarks intentionally expect zero matches for their flat swatch catalog; those swatches cannot establish object structure. They still test cache count, reuse, bounded scan and resource behavior.
- Scoped Pint, PHP syntax, JavaScript syntax and frontend build checked. No broad dirty-tree formatting. Build preserves earlier hashed assets and restores the pre-build Lucide file bytes.
- Graphify is installed; `graphify query "VisualSearchService VisualImageFeatures" --graph graphify-out/graph.json --budget 350 --depth 1` found no matching nodes. Referenced `../CLAUDE_GRAPH_WORKFLOW.md` is absent. Actual routes/throttle, form request, controller, descriptor, index/cache version, scheduler/observer, discoverable/Seller/stock scopes, Blade component/cards and JavaScript callers were traced directly. Checkout, COD, commission, custody, returns, Rider and Logistics business services were not edited or invoked.

## Changed files

Runtime:
- `app/Services/Buyer/VisualImageFeatures.php`
- `app/Services/Buyer/VisualSearchService.php`
- `app/Http/Controllers/Buyer/VisualSearchController.php`
- `app/Http/Requests/Buyer/VisualSearchRequest.php`
- `config/visual-search.php`
- `resources/js/buyer/visual-search.js`
- `resources/views/Components/buyer/visual-search-panel.blade.php`
- `resources/views/Buyer/VisualSearch/index.blade.php`
- `resources/views/Buyer/VisualSearch/_results.blade.php`

Tests and documentation:
- `tests/Feature/Buyer/VisualSearchAccuracyTest.php` (new)
- `tests/Feature/Buyer/VisualSearchTest.php`
- `tests/Feature/Buyer/VisualCatalogIndexingTest.php`
- `tests/Fixtures/visual-search/` (19 public catalog-photo copies, manifest and provenance)
- `docs/VISUAL_SEARCH_MVP.md`
- `docs/VISUAL_SEARCH_ACCURACY_FIX.md` (this report)

Ignored generated frontend build artifacts and disposable v2 image-cache entries were refreshed. Pre-change source copies, calibration data, browser script, JSON observations, screenshots, build output and final file hashes are under `C:\ProjectEcommerce\.recovery\visual-search-accuracy-20261117`.

## Manual verification and limits

1. Open image search, keep All categories, and upload a catalog book photo. Confirm relevant qualifying results without clothing filler; try the same photo with moderate exposure/background padding changes.
2. Upload a dress/blouse photo and confirm books are not added merely to fill the grid.
3. Select a category; the current image should automatically be searched in that category. All categories restores unrestricted candidates.
4. Upload a featureless/very dissimilar image and verify the helpful empty state. Check JPEG, PNG, WebP and 5 MB validation.
5. Repeat at 375px and 1280px, including preview, loading, error and empty states.
6. Re-test the original stack-of-books screenshot when its actual bytes are available. No claim is made that it returns all three requested book titles.

This fix improves precision for similar photographs; it is **not semantic object recognition**. New viewpoints, substantial crops/background changes, multi-object stacks and visually similar unrelated objects can still fail or be rejected. Lighting tests cover moderate exposure changes only. Shoes and bags had no representative catalog fixtures here and are unverified. Robust category/style recognition still needs an approved inference runtime/model, semantic catalog embeddings, and a broader labelled evaluation set. Existing fallback remains operational. No production application data, secrets, migrations, checkout or financial rules were changed.
