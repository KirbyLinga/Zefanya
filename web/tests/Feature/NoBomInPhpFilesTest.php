<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * V1: No UTF-8 BOM in any PHP source file.
 *
 * A BOM (EF BB BF) before <?php makes PHP emit whitespace before the tag.
 * In the HTTP bootstrap path this corrupts JSON and breaks content-type.
 */
class NoBomInPhpFilesTest extends TestCase
{
    private const DIRS = ['app', 'routes', 'bootstrap', 'config', 'database', 'tests'];

    public function test_no_php_file_starts_with_a_bom(): void
    {
        $bomFiles = [];
        foreach (self::DIRS as $dir) {
            $path = base_path($dir);
            if (! is_dir($path)) { continue; }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') { continue; }
                $h = fopen($file->getRealPath(), 'rb');
                if ($h === false) { continue; }
                $first3 = fread($h, 3);
                fclose($h);
                if ($first3 === "\xEF\xBB\xBF") {
                    $bomFiles[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getRealPath());
                }
            }
        }
        $this->assertEmpty($bomFiles, 'PHP files with UTF-8 BOM detected:\n  ' . implode('\n  ', $bomFiles));
    }
}
