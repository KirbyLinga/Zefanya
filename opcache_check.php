<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

$status = @opcache_get_status();
echo 'opcache_enabled: '.(@$status['opcache_enabled'] ?? 'n/a')."\n";
echo 'total_cached_files: '.(@$status['opcache_statistics']['total_files'] ?? 'n/a')."\n";

opcache_reset();

$status2 = @opcache_get_status();
echo 'after_reset_enabled: '.(@$status2['opcache_enabled'] ?? 'n/a')."\n";
echo "DONE\n";
