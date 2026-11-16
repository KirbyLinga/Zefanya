<?php

/*
 * Photo upload limits. Decoding an image needs about width x height x 4 bytes
 * of memory, so a small compressed file can still exhaust PHP. Keep the pixel
 * cap well under memory_limit (128M needs <= ~16 megapixels, 256M <= ~32).
 */
return [
    'max_side_px' => (int) env('UPLOAD_MAX_SIDE_PX', 5000),
    'max_pixels' => (int) env('UPLOAD_MAX_PIXELS', 16_000_000),

    // What one product save may carry. Must stay within the PHP limits below.
    'max_files_per_request' => (int) env('UPLOAD_MAX_FILES_PER_REQUEST', 30),
    'max_request_mb' => (int) env('UPLOAD_MAX_REQUEST_MB', 60),

    // Recommended PHP limits (post_max_size, max_file_uploads, memory_limit). The product form can send more than this; see the audit notes:
    // 9 cover photos plus colour and variant photos at 5 MB each is the ceiling;
    // `php artisan uploads:check` compares the PHP limits with these figures.
    'recommended_post_max_mb' => (int) env('UPLOAD_RECOMMENDED_POST_MAX_MB', 64),
    'recommended_max_file_uploads' => (int) env('UPLOAD_RECOMMENDED_MAX_FILES', 30),
    'recommended_memory_limit_mb' => (int) env('UPLOAD_RECOMMENDED_MEMORY_MB', 256),
];
