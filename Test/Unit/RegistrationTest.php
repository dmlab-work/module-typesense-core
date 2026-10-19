<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit;

use Magento\Framework\Component\ComponentRegistrar;
use PHPUnit\Framework\TestCase;

class RegistrationTest extends TestCase
{
    public function testModuleIsRegistered(): void
    {
        $paths = (new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE);

        self::assertArrayHasKey('DmLab_TypesenseCore', $paths);
    }

    public function testRegisteredPathPointsAtThisModule(): void
    {
        $paths = (new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE);
        $path = $paths['DmLab_TypesenseCore'] ?? null;

        self::assertNotNull($path);
        self::assertDirectoryExists($path);
        self::assertFileExists($path . '/etc/module.xml');
    }

    public function testModuleXmlHasNoSetupVersionAndNoSequence(): void
    {
        $xml = simplexml_load_file(self::moduleRoot() . '/etc/module.xml');

        self::assertNotFalse($xml);
        self::assertSame('DmLab_TypesenseCore', (string)$xml->module['name']);
        self::assertNull($xml->module['setup_version']);
        self::assertCount(0, $xml->module->sequence);
    }

    public function testComposerRequiresBoundedDependenciesOnly(): void
    {
        $composer = json_decode(
            (string)file_get_contents(self::moduleRoot() . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $require = $composer['require'];

        self::assertSame(
            ['ext-curl', 'laminas/laminas-http', 'magento/framework', 'php', 'psr/log', 'symfony/console'],
            self::sortedKeys($require)
        );
        self::assertSame('^103.0.9', $require['magento/framework']);
        foreach ($require as $package => $constraint) {
            self::assertStringNotContainsString('>=', $constraint, $package);
        }
    }

    public function testTranslationFileCoversEveryPhrase(): void
    {
        $handle = fopen(self::moduleRoot() . '/i18n/en_US.csv', 'r');
        self::assertNotFalse($handle);
        $translated = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $translated[] = $row[0];
        }
        fclose($handle);

        $used = [];
        foreach (['Api', 'Console', 'Exception', 'Model'] as $dir) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::moduleRoot() . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                if (preg_match_all("/__\\('([^']+)'/", (string)file_get_contents($file->getPathname()), $m)) {
                    array_push($used, ...$m[1]);
                }
            }
        }

        self::assertNotEmpty($used);
        self::assertSame([], array_values(array_diff(array_unique($used), $translated)));
    }

    private static function moduleRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param array<string, string> $map
     * @return list<string>
     */
    private static function sortedKeys(array $map): array
    {
        $keys = array_keys($map);
        sort($keys);

        return $keys;
    }
}
