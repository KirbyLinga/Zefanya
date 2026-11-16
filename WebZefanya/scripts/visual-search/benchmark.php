<?php

// Read-only descriptor benchmark. Does not boot Laravel, load .env, or connect to a database.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Services\Buyer\VisualImageFeatures;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

$root = dirname(__DIR__, 2);
$container = new Application($root);
$configuration = require $root.'/config/visual-search.php';
$container->instance('config', new Repository(['visual-search' => $configuration]));
$settings = array_intersect_key($configuration, array_flip(['minimum_structure_similarity', 'minimum_similarity', 'maximum_score_gap']));
$engine = new VisualImageFeatures;
$catalog = [];
$fixtures = $root.'/tests/Fixtures/visual-search/';
$started = hrtime(true);
foreach (json_decode(file_get_contents($fixtures.'manifest.json'), true, flags: JSON_THROW_ON_ERROR) as $row) {
    $catalog[] = $row + ['descriptor' => $engine->describe($fixtures.$row['file'])];
}
foreach (['shoe' => 8, 'bag' => 9] as $label => $id) {
    $catalog[] = ['product' => $id, 'category' => $id, 'descriptor' => $engine->describe($root.'/tests/Fixtures/visual-search-discovery/'.$label.'-illustration.png')];
}
$indexMilliseconds = (hrtime(true) - $started) / 1e6;
$directory = sys_get_temp_dir().'/zefanya-visual-benchmark-'.bin2hex(random_bytes(8));
mkdir($directory);
$queries = [];
$report = [];
try {
    foreach (range(1, 7) as $id) {
        foreach (['original', 'lighting', 'background'] as $variation) {
            $source = imagecreatefromjpeg($fixtures.'catalog-'.$id.'-0.jpg');
            if ($variation === 'lighting') {
                imagefilter($source, IMG_FILTER_BRIGHTNESS, -32);
            } elseif ($variation === 'background') {
                $padded = imagecreatetruecolor(imagesx($source) + 30, imagesy($source) + 30);
                imagefill($padded, 0, 0, imagecolorallocate($padded, 210, 220, 235));
                imagecopy($padded, $source, 15, 15, 0, 0, imagesx($source), imagesy($source));
                imagedestroy($source);
                $source = $padded;
            }
            $path = $directory.'/'.$id.'-'.$variation.'.jpg';
            imagejpeg($source, $path, 85);
            imagedestroy($source);
            $queries[] = ['label' => 'same photographed item '.$id.' '.$variation, 'path' => $path, 'expected' => [$id], 'kind' => 'photo_retrieval'];
        }
    }
    foreach (['shoe' => 8, 'bag' => 9] as $label => $id) {
        $queries[] = ['label' => $label.' exact illustration', 'path' => $root.'/tests/Fixtures/visual-search-discovery/'.$label.'-illustration.png', 'expected' => [$id], 'kind' => 'exact_illustration_only'];
    }
    $queries[] = ['label' => 'stacked books illustration versus real book photographs', 'path' => $root.'/tests/Fixtures/visual-search-discovery/stacked-books-illustration.png', 'expected' => [1, 2, 3], 'kind' => 'semantic_category_gap'];
    $flat = imagecreatetruecolor(96, 96);
    imagefill($flat, 0, 0, imagecolorallocate($flat, 200, 200, 200));
    imagepng($flat, $directory.'/unknown.png');
    imagedestroy($flat);
    $queries[] = ['label' => 'unrecognizable flat image', 'path' => $directory.'/unknown.png', 'expected' => [], 'kind' => 'negative'];
    $latencies = [];
    $truePositive = $falsePositive = $retrieved = $recognized = $positiveQueries = 0;
    foreach ($queries as $query) {
        $times = [];
        for ($iteration = 0; $iteration < 3; $iteration++) {
            $started = hrtime(true);
            $descriptor = $engine->describe($query['path']);
            $scores = [];
            foreach ($catalog as $row) {
                if ($engine->structuralSimilarity($descriptor, $row['descriptor']) < $settings['minimum_structure_similarity']) {
                    continue;
                }
                $score = $engine->similarity($descriptor, $row['descriptor']);
                $scores[$row['product']] = max($scores[$row['product']] ?? 0, $score);
            }
            arsort($scores, SORT_NUMERIC);
            $minimum = max($settings['minimum_similarity'], ($scores ? reset($scores) : 0) - $settings['maximum_score_gap']);
            $ids = array_keys(array_filter($scores, fn ($score) => $score >= $minimum));
            $times[] = (hrtime(true) - $started) / 1e6;
        }
        $latencies = array_merge($latencies, $times);
        $correct = count(array_intersect($ids, $query['expected']));
        $truePositive += $correct;
        $falsePositive += count($ids) - $correct;
        $retrieved += count($ids);
        if ($query['expected']) {
            $positiveQueries++;
            $recognized += $correct > 0 ? 1 : 0;
        }
        $report[] = ['query' => $query['label'], 'kind' => $query['kind'], 'expected' => $query['expected'], 'results' => $ids, 'mean_ms' => round(array_sum($times) / count($times), 3)];
    }
    sort($latencies);
    echo json_encode([
        'method' => VisualImageFeatures::VERSION, 'semantic_executed_in_this_benchmark' => false, 'semantic_comparison' => 'This script measures only the structural baseline. Use evaluate_clip.py and object-aware retrieval tests for semantic evaluation.',
        'catalog' => ['photos' => 19, 'synthetic_shoe_and_bag_illustrations' => 2], 'cutoffs' => $settings,
        'precision_of_returned_items' => $retrieved ? round($truePositive / $retrieved, 4) : null,
        'false_positive_items' => $falsePositive, 'returned_items' => $retrieved,
        'positive_query_hit_rate' => round($recognized / $positiveQueries, 4),
        'mean_ms' => round(array_sum($latencies) / count($latencies), 3),
        'p95_ms' => round($latencies[(int) ceil(count($latencies) * 0.95) - 1], 3),
        'descriptor_build_ms' => round($indexMilliseconds, 3), 'php_peak_allocated_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
        'limits' => 'Small same-photo fixture set, not a semantic or unseen-category accuracy benchmark. No HTTP/database/index lookup time included. Shoes/bags are synthetic exact illustrations, not unseen photographs.',
        'queries' => $report,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    foreach (glob($directory.'/*') as $path) {
        unlink($path);
    }
    rmdir($directory);
}
