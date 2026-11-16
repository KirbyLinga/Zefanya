# Visual Search semantic readiness and discovery follow-up

> Historical report, before approved CLIP activation. Current behavior: [Object-aware Visual Search](OBJECT_AWARE_VISUAL_SEARCH.md).

Date: November 17, 2026. Project: `C:\ProjectEcommerce\WebZefanya`. Origin: `https://zefanya.shop`.

## Outcome and root cause

**Semantic recognition remains inactive. The illustration-to-book matching problem is not fixed.** The tested `structure-color-v2` fallback is preserved. Its grayscale gradients and RGB features cannot reliably connect a book illustration with a photograph of a particular cover. Lowering thresholds would admit unrelated products without adding semantic evidence.

Empty results now explain illustration/group/viewpoint limitations and offer category navigation clearly labelled as browsing rather than image matches. Following Books opens the available book listings, including Rich Dad Poor Dad; this is an explicit Buyer browsing action, not automatic recognition.

No model, package, external inference API, credentials, service, environment change or production migration was introduced. No production business records were created or changed. Tests used in-memory SQLite and isolated upload/index paths.

## Methods and runtime assessment

- **Structural fallback, evaluated:** existing structure >= 0.80, combined score >= 0.82, within 0.08 of the top qualifying score. Cutoffs, exact-photo ranking, best-image-per-product deduplication, visibility and category filtering remain unchanged.
- **CLIP embeddings, assessed but not evaluated:** no checkpoint or inference endpoint exists in the retrieval path. The [Xenova INT8 CLIP vision export](https://huggingface.co/Xenova/clip-vit-base-patch32/blob/main/onnx/vision_model_int8.onnx) is listed at 88.6 MB. Its [published preprocessing](https://huggingface.co/Xenova/clip-vit-base-patch32/blob/main/preprocessor_config.json) includes a 224px crop, RGB conversion and specific normalization. File size is not process memory. No semantic accuracy, model RSS or inference latency was measured because no model was loaded.
- **Category navigation, implemented:** lists only existing categories containing `Product::discoverable()` listings, narrowed to the selected category when supplied. It does not infer a category or recommend every book as an image match. Successful photo results incur no extra category-navigation query.

Python 3.14.6; ONNX Runtime 1.27.0, NumPy 2.4.6, Pillow 12.3.0, FastAPI 0.104.1 and Uvicorn 0.24.0 are present. ONNX Runtime and FastAPI imports passed. PyTorch and Transformers are absent, although an ONNX-only encoder need not require either. ONNX reports CPU and Azure execution providers; no GPU/external provider was configured or used. Integrated AMD graphics reports 512 MiB adapter memory.

Physical RAM is approximately 15.3 GiB. Free memory varied from about 492 MiB to 1,345 MiB during this task; a later sample was about 1,014 MiB. Free disk was approximately 15 GiB. Existing applications were not stopped. Free memory is an observation, not reserved inference capacity.

Safe activation is blocked by the absent model/index, unmeasured inference memory/concurrency, missing service lifecycle/health checks and unvalidated semantic thresholds. This does not prove quantized CLIP cannot run on this CPU. It means an importable runtime is insufficient evidence to enable it on the live host. No numerical semantic-versus-fallback comparison was fabricated.

Before activation, provision an isolated evaluation worker with a reserved budget. Start with **2 GiB RAM and 1-2 CPU threads as a planning allowance**, not a measured minimum. Pin model revision/hash and preprocessing; measure cold/steady-state RSS, p50/p95 latency, bounded concurrency and timeout recovery. ONNX supports [thread controls](https://onnxruntime.ai/docs/performance/tune-performance/threading.html). Keep one warmed worker, a bounded queue, strict deadlines and a private endpoint. Protect against arbitrary paths and oversized inputs; do not silently use paid inference.

A future validated hybrid should retain strong exact-photo matches first, apply calibrated semantic thresholds separately, version embeddings by encoder/fingerprint through the existing index, recheck live visibility/category rules, and fall back when inference fails. Generic category recommendations must remain separately labelled. This inference path is not implemented or tested here.

## Changed files

- `app/Http/Controllers/Buyer/VisualSearchController.php`: eligible category navigation on empty results.
- `resources/views/Buyer/VisualSearch/_results.blade.php`: honest explanation and accessible navigation, preserving Rose Theme and product cards.
- `resources/js/buyer/visual-search.js`: useful empty-result announcement and singular/plural wording.
- `tests/Feature/Buyer/VisualSearchDiscoveryTest.php`: 13 new regressions.
- `tests/js/visual-search.test.mjs`: two new message regressions; upload checks retained.
- `tests/Fixtures/visual-search-discovery/`: three locally drawn illustrations and provenance README. These are not the original Buyer illustration. Shoe/bag fixtures test exact-image retrieval, not unseen-category recognition.
- `scripts/visual-search/benchmark.php`, `readiness.py`, `README.md`: read-only reproducible measurement tools; no model download, inference service, `.env` loading, application database connection or live-index writes. Temporary benchmark images are removed in `finally`.
- `docs/VISUAL_SEARCH_MVP.md` and this report.

Ignored frontend build assets were rebuilt, preserving earlier hashed assets and the pre-build Lucide bytes.

## Before/after and verification

| Scenario | Before this follow-up | After |
|---|---|---|
| Synthetic stacked-book illustration | Zero image matches; generic empty state | Still zero matches; explains limitation and offers labelled browsing |
| Exact Rich Dad Poor Dad cover | Matching book | Same book first; one live result |
| Books category chosen for illustration | Zero matches | Still zero matches; Books browsing opens three available books |
| Clothing photograph | Matching clothing | Same focused regressions pass without book filler |
| Exact shoe/bag illustration | Same-image fallback | Same isolated listing first; not semantic evidence |
| Unrecognizable flat image | Zero | Zero with useful browsing options |
| Hidden/unavailable products | Excluded from results | Still excluded; unavailable-only categories also omitted from navigation |

**86 PHPUnit tests passed, 708 assertions, zero failures, 58.222 seconds.** They cover visual search, indexing, accuracy, discovery and catalog eligibility. **4 JavaScript tests passed, zero failures.** Scoped Pint, PHP/JavaScript/Python syntax and scoped whitespace checks passed. Frontend build passed in 13.56 seconds. No full application or financial/concurrency suite is claimed. Checkout, financial and custody services were not edited or invoked.

Offline benchmark: 19 real catalog photos plus two synthetic exact-illustration entries; 25 queries with three timing iterations each. Queries include original photos, reduced exposure, background padding, exact shoe/bag illustrations, a stacked-book semantic gap and a flat negative. Returned-item precision was **23/23 (100%)**, false-positive returned items **0**, positive-query hit rate **23/24 (95.83%)**. The missed positive query was the stacked-book illustration. This small, same-photo-oriented benchmark is not evidence of general or semantic accuracy.

Average query feature extraction/ranking: **9.440 ms**; p95: **12.578 ms**; descriptor build: **193.210 ms**; peak PHP allocated memory: **16 MiB**. This excludes database/index/HTTP work; PHP heap is not whole-process RSS. Existing PHP 8.5 `imagedestroy()` deprecations were captured on stderr and did not prevent evaluation.

Live Edge via existing Playwright at **375px and 1280px**:

- Stack illustration: HTTP 200, zero matches. All-category navigation offered Books, Electronics and Women's Apparel, demonstrating no inferred object category.
- Choosing Books automatically resubmitted, retained zero matches and narrowed navigation. Following the link opened `/buyer/categories/1` with product IDs 3, 2, 1, including Rich Dad Poor Dad and no clothing.
- Exact Rich Dad Poor Dad photo: HTTP 200, one matching card, ID 3 first.
- Four measured uploads took **276, 337, 391 and 510 ms** end to end. This is a tiny sample, not a load-test p95.
- Preview/category changes worked; no horizontal overflow or page JavaScript errors. An intercepted 503 verified existing-engine error feedback and re-enabled submission. It does not prove model-timeout fallback: no model client exists. Retrieval tests assert no external inference requests, so a missing semantic service adds no dependency today.

The original Buyer illustration was not attached and remains unverified. Real unseen shoe/bag photos were not evaluated. Agent-browser CLI was unavailable, so installed Playwright/Edge was used. Graphify is installed, but the documented query for `VisualSearchService VisualCatalogIndex` found no nodes; `../CLAUDE_GRAPH_WORKFLOW.md` is absent. Routes/throttle, request/controller, descriptor/version/index, observer/scheduler, visibility scopes, category route and views/JS were traced directly instead.

## Rollback

Evidence and pre-follow-up copies are under `C:\ProjectEcommerce\.recovery\visual-search-semantic-readiness-20261117`, including source hashes, Git status, benchmark/readiness JSON, stderr, browser observations/screenshots and verification summary.

1. Compare against the saved copies and reverse only this follow-up's controller/view/JavaScript changes. Preserve subsequent and unrelated edits; do not reset the tree or blindly replace whole files.
2. Remove only this follow-up's new discovery tests/fixtures/scripts/docs if desired. Keep the earlier accuracy fixtures and v2 descriptor/index intact.
3. Rebuild assets from the resulting current source with `npm run build -- --emptyOutDir false`, preserving current Lucide bytes. Do not overwrite newer unrelated assets with an older manifest.
4. No environment change, production migration, business-data restoration, index rebuild or service restart is needed for this follow-up's rollback.

**The semantic illustration-to-book objective remains blocked pending isolated model evaluation and a measured, reserved inference budget. Category browsing is not presented as a semantic fix.**
