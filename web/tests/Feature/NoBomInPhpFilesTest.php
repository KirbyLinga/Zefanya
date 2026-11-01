<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * V1: No UTF-8 BOM in any PHP source file.
 */
class NoBomInPhpFilesTest extends TestCase
{
    /** @var list<string> */
    private const DIRS = ['app', 'routes', 'bootstrap', 'config', 'database', 'tests'];

    public function test_no_php_file_starts_with_a_bom(): void
    {
        $bomFiles = [];

        foreach (self::DIRS as $dir) {
            $path = base_path($dir);
            if (! is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $handle = fopen($file->getRealPath(), 'rb');
                if ($handle === false) {
                    continue;
                }
                $first3 = fread($handle, 3);
                fclose($handle);
                if ($first3 === "\xEF\xBB\xBF") {
                    $bomFiles[] = str_replace(
                        base_path().DIRECTORY_SEPARATOR,
                        '',
                        $file->getRealPath()
                    );
                }
            }
        }

        $this->assertEmpty(
            $bomFiles,
            'PHP files with UTF-8 BOM (corrupts JSON responses): '.implode(', ', $bomFiles)
        );
    }
}
