<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = 'c:\Web\Zefanya';
$needle = 'reporterForDelivery';
$found = [];
if (! is_dir($root)) {
    echo "NOT A DIR: $root\n";
    exit(1);
}
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $s = @file_get_contents($file->getPathname());
        if ($s !== false && strpos($s, $needle) !== false) {
            $found[] = $file->getPathname();
        }
    }
}
foreach ($found as $f) {
    echo $f."\n";
}
echo 'TOTAL: '.count($found)."\n";
