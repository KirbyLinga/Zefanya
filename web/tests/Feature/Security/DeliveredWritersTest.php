<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Step 3: Delivered-writers guard.
 *
 * Scans web/app, web/routes, and web/database for any file that ASSIGNS the
 * delivered status using any of three case-insensitive patterns:
 *
 *   1. status'\?\s*=>\s*'delivered'   (array key assignment)
 *   2. ->status\s*=\s*'delivered'     (property assignment)
 *   3. enum assignment of Delivered   (OrderStatus::Delivered)
 *
 * Whitelisted: OrderDeliveryService.php, OrderStatus.php, seeders, factories.
 *
 * Every non-whitelisted hit must route through OrderDeliveryService.
 *
 * Mutation: adding $order->status = 'delivered'; to any controller (or any
 * non-whitelisted file) causes this test to fail.
 */
class DeliveredWritersTest extends TestCase
{
    /**
     * Files that are allowed to reference the delivered status assignment.
     * Relative to base_path().
     *
     * @var list<string>
     */
    private const WHITELIST = [
        'app/Services/OrderDeliveryService.php',
        'app/Enums/OrderStatus.php',
    ];

    /**
     * Directory name fragments that are whitelisted wholesale
     * (seeders and factories write status for test data, not production).
     *
     * @var list<string>
     */
    private const WHITELIST_DIRS = [
        'database/seeders',
        'database/factories',
    ];

    /**
     * Case-insensitive patterns that indicate an ASSIGNMENT of delivered status.
     *
     * Read-only uses (assertSame, middleware checks, comments) are intentionally
     * not matched by these patterns.
     *
     * @var list<string>
     */
    private const PATTERNS = [
        // Array-key / closure assignment:  'status' => 'delivered'
        '/status\'?\s*=>\s*\'delivered\'/i',
        // Property assignment:             ->status = 'delivered'
        '/->status\s*=\s*[\'"]delivered[\'"]/i',
        // Enum assignment:                 OrderStatus::Delivered (write context)
        '/OrderStatus::Delivered/i',
    ];

    /**
     * Directories to scan, relative to base_path().
     *
     * @var list<string>
     */
    private const SCAN_DIRS = [
        'app',
        'routes',
        'database',
    ];

    public function test_only_whitelisted_files_assign_delivered_status(): void
    {
        $hits = $this->findDeliveredAssignments();

        $nonWhitelisted = array_filter(
            $hits,
            fn (string $relative): bool => ! $this->isWhitelisted($relative),
        );

        $this->assertEmpty(
            $nonWhitelisted,
            "Non-whitelisted files assign the delivered status.\n"
            ."Route all delivered writes through OrderDeliveryService.\n"
            .'Files: '.implode(', ', $nonWhitelisted),
        );
    }

    /**
     * @return list<string> relative paths (forward slashes) of matching files
     */
    private function findDeliveredAssignments(): array
    {
        $hits = [];

        foreach (self::SCAN_DIRS as $dir) {
            $absDir = base_path($dir);
            if (! is_dir($absDir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absDir),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                $relative = str_replace(
                    [base_path().DIRECTORY_SEPARATOR, '\\'],
                    ['', '/'],
                    $file->getPathname(),
                );

                foreach (self::PATTERNS as $pattern) {
                    if (preg_match($pattern, $contents) === 1) {
                        $hits[] = $relative;
                        break; // one match per file is enough
                    }
                }
            }
        }

        sort($hits);

        return array_values(array_unique($hits));
    }

    private function isWhitelisted(string $relative): bool
    {
        foreach (self::WHITELIST as $allowed) {
            if ($relative === $allowed || str_ends_with($relative, $allowed)) {
                return true;
            }
        }

        foreach (self::WHITELIST_DIRS as $dir) {
            if (str_contains($relative, $dir)) {
                return true;
            }
        }

        return false;
    }
}
