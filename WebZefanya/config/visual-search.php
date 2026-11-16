<?php

return [
    // The MVP uses local structure/color/layout features, not a trained semantic AI model.
    'enabled' => true,
    'index_path' => storage_path('app/private/visual-search/catalog.json'),
    'max_catalog_images' => 100_000,
    'max_index_bytes' => 32 * 1024 * 1024,
    'max_cache_bytes' => 256 * 1024 * 1024,
    'results_limit' => 16,
    // Conservative fallback cutoffs; these scores are not probabilities.
    'minimum_similarity' => 0.82,
    'minimum_structure_similarity' => 0.80,
    'maximum_score_gap' => 0.08,
    'max_pixels' => 12_000_000,
    'max_side' => 5000,
    'semantic' => [
        // Approved pinned CPU model; structural fallback remains available on worker failure.
        'enabled' => true,
        'endpoint' => 'http://127.0.0.1:8766',
        'token_path' => storage_path('app/private/visual-search/clip-runtime.key'),
        'version' => 'clip-b32-int8-6b7c523-types-v1',
        'minimum_score' => 0.24,
        'minimum_margin' => 0.015,
        'supported_families' => ['books', 'clothing', 'footwear', 'bags', 'electronics'],
        'minimum_image_similarity' => 0.35,
        'index_batch' => 16,
        'timeout' => 1.5,
    ],
];
