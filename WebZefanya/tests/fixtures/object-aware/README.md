# Object-aware search fixtures

Seven independent calibration photos and six held-out photos were retrieved at bounded 512px size from the public Unsplash image URLs in `sources.json` and `holdout/sources.json`. Labels were visually inspected. The held-out landscape is intentionally a negative, not a book. Original catalog photos remain in `../visual-search/`; hand-drawn illustrations remain in `../visual-search-discovery/`.

Five deterministic flat/noisy/abstract negatives were generated locally. `recorded-descriptors.json` contains outputs actually produced by the pinned INT8 CLIP worker for fixture bytes, keyed by SHA-256. It contains no query/customer image, credential, account or production business record. PHPUnit uses those recorded descriptors to test retrieval deterministically without contacting a production inference service; `evaluate_clip.py` exercises the real worker independently.

These are small calibration/holdout sets, not a representative statistical estimate for the whole marketplace. Public image sources are included for provenance; no tests require internet access.
