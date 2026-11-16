<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * PHP drops uploads that exceed its own limits before Laravel runs: a POST over
 * post_max_size is rejected, and files past max_file_uploads vanish without an
 * error. This compares the limits PHP is really running with what the photo
 * forms need (see config/uploads.php). Run it with the web server's php.ini.
 */
class CheckUploadLimitsCommand extends Command
{
    protected $signature = 'uploads:check';

    protected $description = 'Compare PHP upload limits with what the product, review and registration forms need';

    public function handle(): int
    {
        $rows = [
            ['post_max_size', $this->megabytes(ini_get('post_max_size')), (int) config('uploads.recommended_post_max_mb'), 'MB'],
            ['upload_max_filesize', $this->megabytes(ini_get('upload_max_filesize')), 5, 'MB'],
            ['max_file_uploads', (int) ini_get('max_file_uploads'), (int) config('uploads.recommended_max_file_uploads'), ''],
            ['memory_limit', $this->megabytes(ini_get('memory_limit')), (int) config('uploads.recommended_memory_limit_mb'), 'MB'],
        ];

        $failed = false;
        $table = [];
        foreach ($rows as [$name, $actual, $needed, $unit]) {
            $ok = $actual === -1 || $actual >= $needed;
            $failed = $failed || ! $ok;
            $table[] = [$name, ($actual === -1 ? 'unlimited' : $actual).$unit, '>= '.$needed.$unit, $ok ? 'ok' : 'TOO LOW'];
        }

        $this->components->info('php.ini in use: '.(php_ini_loaded_file() ?: 'none').' (SAPI '.PHP_SAPI.')');
        $this->table(['Setting', 'Current', 'Needed', 'Status'], $table);
        $this->line('Photos are capped at '.config('uploads.max_side_px').' px per side and '.round(config('uploads.max_pixels') / 1_000_000).' megapixels.');

        if ($failed) {
            $this->components->warn('Raise the flagged settings in the php.ini used by the web server, then restart it. The CLI and the web server can use different files.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function megabytes(string|false $value): int
    {
        $value = trim((string) $value);
        if ($value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024,
            'K' => intdiv($number, 1024),
            'M' => $number,
            default => intdiv($number, 1048576),
        };
    }
}
