<?php

$path = 'app/Services/LogisticsIncidentAuthorizationService.php';
$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
echo 'TOTAL_LINES: '.count($lines)."\n";
$classes = [];
$methods = [];
foreach ($lines as $i => $line) {
    if (preg_match('/^class\s+(\w+)/', $line, $m)) {
        $classes[] = $m[1];
    }
    if (preg_match('/function\s+(\w+)\s*\(/', $line, $m)) {
        $methods[] = $m[1];
    }
}
echo 'CLASSES: '.implode(',', $classes)."\n";
echo 'METHODS: '.implode(',', $methods)."\n";
echo 'CLASS_COUNT: '.count($classes)."\n";
echo 'METHOD_REPORTERF_COUNT: '.substr_count(implode("\n", $lines), 'reporterForDelivery')."\n";
echo 'FILE_BYTES: '.filesize($path)."\n";
echo 'UNIQUE_LINES: '.count(array_unique($lines))."\n";
echo 'RAW_REPOSITION: ';
$pos = strpos(file_get_contents($path), 'class LogisticsIncidentAuthorizationService');
echo ($pos !== false ? 'found@'.$pos : 'NOT_FOUND')."\n";
