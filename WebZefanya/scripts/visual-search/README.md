# Visual search runtime and verification

Run from the WebZefanya root:

```powershell
powershell -NoProfile -File scripts/visual-search/clip-worker.ps1 Status
powershell -NoProfile -File scripts/visual-search/clip-worker.ps1 Start
powershell -NoProfile -File scripts/visual-search/clip-worker.ps1 Stop
python scripts/visual-search/readiness.py
python scripts/visual-search/evaluate_clip.py
php -d display_errors=stderr scripts/visual-search/benchmark.php
```

`clip_runtime.py` is the Windows-only, CPU-limited local CLIP worker. The managed script resolves the actual Python interpreter (not the Windows launcher PID), verifies process ownership before stopping and uses a hidden window. Port 8766 must be free. Approved pinned model files and a private key must already be installed; these commands do not download models or enable paid providers. The private runtime directory is `storage/app/private/visual-search/clip-runtime`; the key is beside it. Never publish the private directory or key. No auto-start OS task is installed; start after a reboot.

`readiness.py` inventories packages/resources and checks the configured source flag and actual worker health. Cached Laravel configuration, if present, requires separate effective-config verification. A public upload test is still the decisive behavior check.

`evaluate_clip.py` sends checked-in real/synthetic fixtures to the actual private worker, reporting accepted-category accuracy, abstentions, negatives, latency, CPU time and worker memory. No application database, product or inventory writes are performed. It reads the private key without displaying it. Use `--output <path>` for a report. The dataset includes a held-out split and provenance; metrics are small-fixture evidence, not general-market accuracy.

`benchmark.php` deliberately measures only the retained structural baseline. It uses an unbootstrapped application container for visual-search configuration, does not load `.env` or connect to the application database, and cleans its own OS-temporary derived images. It is not a semantic-runtime readiness check. PHP 8.5 GD deprecations go to stderr with the documented flag.

Operations, model hashes, thresholds, limitations and rollback are documented in `docs/OBJECT_AWARE_VISUAL_SEARCH.md`.
