<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit;

use PHPUnit\Framework\TestCase;

class LicenseHeaderTest extends TestCase
{
    private const HEADER = " * Copyright © DMLab. All rights reserved.\n"
        . " * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.\n";

    private const PATHS = ['Api', 'Console', 'Exception', 'Model', 'etc', 'Test', 'registration.php'];

    public function testEverySourceFileCarriesTheOslHeader(): void
    {
        $missing = [];
        foreach ($this->sourceFiles() as $file) {
            if (!str_contains((string)file_get_contents($file), self::HEADER)) {
                $missing[] = $file;
            }
        }

        self::assertSame([], $missing);
    }

    public function testScanFindsFilesInEveryPath(): void
    {
        $root = dirname(__DIR__, 2);
        $files = $this->sourceFiles();

        foreach (self::PATHS as $path) {
            $matches = array_filter($files, static fn (string $f): bool => str_starts_with($f, $root . '/' . $path));
            self::assertNotEmpty($matches, $path);
        }
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (self::PATHS as $path) {
            $full = $root . '/' . $path;
            if (is_file($full)) {
                $files[] = $full;
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (in_array($file->getExtension(), ['php', 'xml'], true)
                    && !str_contains($file->getPathname(), '/.phpunit.cache/')
                ) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
