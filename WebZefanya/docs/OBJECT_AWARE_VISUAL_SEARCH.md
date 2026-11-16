# Object-aware Visual Search - live implementation

Implemented November 17, 2026 in `C:\ProjectEcommerce\WebZefanya`, verified at `https://zefanya.shop/buyer/visual-search`.

## Outcome

**Actual CLIP inference and object-aware recommendations are enabled on the live Windows host.** A different book cover, a book-stack photograph and a stack illustration now return The Midnight Library, Atomic Habits and Rich Dad Poor Dad as category recommendations. Exact Rich Dad Poor Dad cover comparison stays first. An independent dress photo returns the dress listing without book or blouse filler.

Shoes, bags and phones are recognized, but the live catalog currently has no qualifying products of those specific types. Search returns no fabricated products. Isolated fixtures demonstrate retrieval when those types are present. No production business records, inventory, prices, checkout, authentication, financial records or application schema were changed.

## Root cause and model

The earlier engine had only RGB/layout/gradient descriptors; it had no learned object representation. Structural similarity cannot reliably connect a different cover or illustration with the concept of a book. Simply lowering thresholds or taking nearest image embeddings alone can still rank unrelated clothing highly.

The approved model is [Xenova CLIP ViT-B/32](https://huggingface.co/Xenova/clip-vit-base-patch32), pinned revision `6b7c523703ae26e39be4b03774966f8571c7f523`. INT8 exports:

- Vision: 88,648,877 bytes, SHA-256 `0ab0c1b3ace708e539633af1744d5a95247fe4e14d3e08ff197ef82a6cb9bd93`.
- Text: 64,070,791 bytes, SHA-256 `18845f2ccc35223bb7fec403383a131154b11ac0918df25cf51986df5efd3a21`.
- Tokenizer, preprocessing and configuration files are included in the private installation manifest. Combined download is approximately 155 MB decimal. Both encoder hashes are checked at worker startup; all downloaded files had their sizes/hashes verified.

Existing ONNX Runtime 1.27.0, NumPy, Pillow and tokenizers packages are reused. No Python packages, PyTorch, Transformers or paid/external inference service were installed. CLIP's [published preprocessing](https://huggingface.co/Xenova/clip-vit-base-patch32/blob/main/preprocessor_config.json) is reproduced: EXIF orientation, RGB, shortest-edge resize/224px center crop, bicubic sampling and fixed mean/std normalization. Output embeddings are normalized 512-dimensional vectors.

Text prototypes use general object descriptions and photo/illustration prompts. There are no production product-title, SKU or specific-listing exceptions. Supported recommendation families validated here are books, clothing, footwear, bags and electronics. Additional prototype families act as competing/negative evidence; recommendations for those unvalidated families remain disabled.

## Retrieval and labels

1. Existing upload validation and structural descriptors remain. Existing catalog/Seller visibility, stock, restriction and optional category scopes are applied before scoring and rechecked after ranking.
2. A bounded authenticated loopback request obtains a real query embedding and object-family/subtype scores. Supported recognition requires top cosine >= 0.24 and a margin >= 0.015 over the next family. These are small-fixture-calibrated heuristics, not probability estimates.
3. Catalog embeddings must match the pinned model version and current content hash/fingerprint. A category candidate requires confident compatible object recognition on both sides and image cosine >= 0.35. A phone cannot recommend gaming items merely because both belong to electronics; dresses do not broaden to tops. Shirt/blouse variants share a compatible tops group.
4. Broad recommendations rank by 85% semantic image cosine plus 15% existing visual score and a 0.05 same-subtype preference. Best qualifying image wins per product. Exact/close structural photo results appear first and are removed from the recommendation group. Results are never padded to a fixed count.
5. UI separates **Visually similar products** from **More products in Books/Dresses/etc.** The latter explicitly says category recommendations are not confirmed visual/exact matches. Scores/percentages are not displayed. Unsupported/uncertain recognition offers no category recommendations; only strong structural photo evidence (>= 0.95) may survive uncertainty.
6. If the worker fails, times out or returns invalid data, the original structural fallback remains. Its earlier accuracy limitations still apply during an outage, and the UI discloses that recognition was unavailable/uncertain.

`semantic_features` is an additive table in the existing **disposable private SQLite image cache**, not the application database. It is keyed by image content hash and model version. The existing observer, scheduled `visual-search:index` scan, locking and incremental infrastructure are reused. At most 16 missing unique semantic photos are processed per invocation. The batch stops after the first worker failure. Current coverage: 25 eligible image records, 19 unique semantic descriptors. User-uploaded query bytes/embeddings are not persisted.

## CPU, memory, privacy and lifecycle

- Windows-only stdlib HTTP worker binds **127.0.0.1:8766**. A generated private key is required for inference; Laravel never forwards that key outside loopback. Redirects and external endpoint configurations are rejected. No public recognition API/firewall exposure was added.
- One inference at a time, a bounded socket backlog, one ONNX/BLAS thread, below-normal priority and a **512 MiB committed-process-memory cap** via a Windows Job Object. No GPU or paid provider is used. Single-thread controls follow [ONNX Runtime threading documentation](https://onnxruntime.ai/docs/performance/tune-performance/threading.html).
- Measured worker peak working set across checks was **182.86 MiB**; peak committed/pagefile usage reached **193.08 MiB**. The final post-restart 40-query run peaked at 164.21 MiB working set. Earlier free-memory estimates alone were insufficient; actual bounded inference proved this model fits much less memory than the earlier conservative planning allowance.
- Existing 5 MB, 12-megapixel, 5000px JPEG/PNG/WebP limits apply. Extreme aspect ratios >12 are rejected by the encoder to prevent shortest-edge resize from allocating an enormous intermediate image. Malformed images, oversized requests, missing keys and wrong keys fail closed. The PHP client bounds response size and validates version, family, vector length/norm and finite scores.
- Inference request deadline: 1.5 seconds. An actually unavailable endpoint returned null in 1,558 ms; the full cold outage search returned the exact photo result in 2,449 ms. Normal public uploads measured 374-786 ms before the final wording-only asset rebuild. These are small samples, not load-test guarantees.
- Screenshots are processed request-temporarily. The worker stores no upload bytes, paths, credentials or per-user query logs. Only derived public-catalog embeddings and text prototypes are cached.

Private model files/logs/PID are under `storage/app/private/visual-search/clip-runtime`; the private key is beside that directory. They are Git-ignored and must never be copied to public storage or committed. Model downloads/evaluation recovery evidence is under `C:\ProjectEcommerce\.recovery\object-aware-clip-20261117`.

```powershell
powershell -NoProfile -File scripts/visual-search/clip-worker.ps1 Status
powershell -NoProfile -File scripts/visual-search/clip-worker.ps1 Start
powershell -NoProfile -File scripts/visual-search/clip-worker.ps1 Stop
python scripts/visual-search/readiness.py
python scripts/visual-search/evaluate_clip.py
```

The managed script resolves the actual Python interpreter instead of recording the Windows launcher PID, checks the script path before stopping a process, starts hidden, and refuses to replace an unrelated listener. Stop/start recovery was exercised. No auto-start OS task was installed: after a PC reboot, run `Start`; photo comparison works while the worker is down. This Windows resource guard must be adapted and revalidated before moving the worker to Linux/Hostinger; no such deployment is claimed.

## Verification and accuracy

**105 PHPUnit tests passed, 804 assertions, zero failures, 56.160 seconds** in the final combined run. Suites cover original visual search, indexing, accuracy, empty-state navigation, object-aware retrieval and catalog eligibility. PHPUnit uses outputs actually recorded from the pinned model to test retrieval deterministically; it does not contact the live worker. Existing tests automatically avoid production inference. **5 JavaScript tests passed**, including separate visual/category announcements and upload boundaries. Scoped Pint, PHP/JS/Python syntax and relevant whitespace checks passed. Frontend build passed; prior hashed assets and Lucide bytes were preserved.

The **actual worker** was separately evaluated on **40 images**: 19 catalog photos, seven independent real calibration photos, three illustrations, five flat/noisy/abstract negatives, and six held-out real photos (five object positives plus one landscape negative). Image sources and fixture provenance are checked in; no tests depend on internet access.

- Accepted classifications: **31/31 correct** on this small set.
- Positive coverage: **31/34 = 91.18%**; three ambiguous positives abstained.
- Negatives: **0/6 false-positive recognitions**; all abstained.
- Held-out split: five object positives recognized and the landscape rejected.
- Final real-worker HTTP mean **116.327 ms**, p95 **148.433 ms** across the 40 sequential requests. CPU time was **4.125 seconds over 4.668 seconds wall**, approximately 0.88 of one core during this batch, not 88% of the whole 16-logical-CPU machine. The earlier structural-only fixture benchmark is a separate method, not a semantic accuracy estimate.

These metrics do **not** establish 100% marketplace accuracy. Busy scenes, multiple objects, tiny objects, out-of-vocabulary types and center-crop losses can still cause abstention or error. Broader tests are needed before expanding supported families or relaxing thresholds.

The existing catalog scan remains linear. Large-catalog ANN retrieval and a multi-user load test were not implemented or validated; current live latency evidence is for 25 image records.

| Actual scenario | Before | After |
|---|---|---|
| Independent different book cover | Zero products | Three available books, explicitly category recommendations |
| Stack photo / drawn stack illustration | Zero products | Three available books; no clothing |
| Exact Rich Dad Poor Dad cover | Matching book | Same book first; two other books separately recommended |
| Independent dress | Zero products | Only the dress listing; no books or blouse filler |
| Shoes/bag/phone | Zero products | Correct recognized type, zero live recommendations because no qualifying stock of those types exists |
| Random-noise image | A structural game-case lookalike could appear | No products; no confident recognition |
| Recognition worker actually stopped | N/A | Exact photo fallback remained; zero category recommendations; restart restored recognition |

Public Edge/Playwright checks at **375px and 1280px** exercised real uploads, book recommendations, exact-cover order, dress specificity, uncertainty, manual narrowing and controlled upload-error recovery. All returned HTTP 200 for real search cases; cards were unique, preview worked, no horizontal overflow or page JavaScript errors. Source/worker readiness and actual public response behavior both confirmed activation. The earlier original Buyer screenshot's exact bytes were not attached; independent photos and a generated illustration were used transparently.

Safety checks on the real worker: invalid key ->403; malformed image ->422; oversized request ->413; extreme-aspect image ->422. Simulated timeout and real connection failure both preserve fallback. No application financial/concurrency suite, Android or unrelated workflow verification is claimed.

Dependency analysis used the installed Graphify CLI, whose query for the visual-search services found no nodes; the referenced `../CLAUDE_GRAPH_WORKFLOW.md` is absent. Current routes/throttle, request/controller, upload handling, services, cache, observer/scheduler, category/visibility scopes, frontend and tests were traced directly.

## Files changed

Runtime:
- `app/Services/Buyer/VisualSemanticRecognition.php` (new)
- `app/Services/Buyer/VisualSearchService.php`
- `app/Services/Buyer/VisualCatalogIndex.php`
- `app/Http/Controllers/Buyer/VisualSearchController.php`
- `config/visual-search.php`
- `resources/views/Buyer/VisualSearch/_results.blade.php`
- `resources/views/Buyer/VisualSearch/index.blade.php`
- `resources/views/Components/buyer/visual-search-panel.blade.php`
- `resources/js/buyer/visual-search.js`
- `scripts/visual-search/clip_runtime.py`, `clip-worker.ps1`, `evaluate_clip.py` (new)
- `scripts/visual-search/readiness.py`, `benchmark.php`, `README.md`

Verification/documentation:
- `tests/Feature/Buyer/ObjectAwareVisualSearchTest.php` (new)
- `tests/js/visual-search.test.mjs`
- `tests/Fixtures/object-aware/` (new real photos, negatives, held-out photos, source manifests, recorded descriptors and README)
- `docs/VISUAL_SEARCH_MVP.md`
- `docs/VISUAL_SEARCH_ACCURACY_FIX.md`, `VISUAL_SEARCH_SEMANTIC_READINESS.md` (historical-status links)
- `docs/OBJECT_AWARE_VISUAL_SEARCH.md` (this report)

Ignored private model/key/prototype/log/PID files, disposable semantic cache rows and built frontend assets were also created/refreshed. No `.env`, migrations, business-model logic, credentials belonging to existing accounts, Git branches, commits or production web-server configuration were changed.

## Rollback

1. Set only `visual-search.semantic.enabled` to false in `config/visual-search.php`. If configuration caching is used later, rebuild it using that environment's approved release procedure. The current host has no cached configuration.
2. Run `clip-worker.ps1 Stop` to stop the verified owned recognition process. This was tested; structural retrieval remains.
3. For source rollback, compare the recovery `before/` copies and reverse only this change set, preserving subsequent/unrelated edits. Do not reset or blindly restore the dirty working tree.
4. Rebuild assets from the resulting current source, preserving existing Lucide bytes and newer unrelated assets. Semantic cache rows/models can stay private and unused; no business database restoration or application migration is required.
5. To re-enable after rollback, start the worker, verify health and representative real uploads, then enable the flag. Do not reuse thresholds with another checkpoint/prototype version without revalidation.
