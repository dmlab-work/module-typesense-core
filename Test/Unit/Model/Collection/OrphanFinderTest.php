<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit\Model\Collection;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Collection\AliasLock;
use DmLab\TypesenseCore\Model\Collection\AliasManager;
use DmLab\TypesenseCore\Model\Collection\CollectionManager;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseCore\Model\Collection\OrphanFinder;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;

class OrphanFinderTest extends TestCase
{
    private const NOW = 1752800000;

    /**
     * @param string[] $collections
     * @param array<string,string> $aliases
     * @param CollectionManager|null $collectionManager
     * @param LockManagerInterface|null $locks
     */
    private function finder(
        array $collections,
        array $aliases,
        ?CollectionManager $collectionManager = null,
        ?LockManagerInterface $locks = null
    ): OrphanFinder {
        $collectionManager ??= $this->createStub(CollectionManager::class);
        $collectionManager->method('getList')->willReturn($collections);
        $aliasManager = $this->createStub(AliasManager::class);
        $aliasManager->method('getList')->willReturn($aliases);
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);

        if ($locks === null) {
            $locks = $this->createStub(LockManagerInterface::class);
            $locks->method('lock')->willReturn(true);
        }

        return new OrphanFinder($collectionManager, $aliasManager, $dateTime, new AliasLock($locks));
    }

    public function testOldUnaliasedVersionedCollectionIsReturned(): void
    {
        $finder = $this->finder(
            ['products_1752700000_0a1b2c', 'products_1752790000_9f3ac1'],
            ['products' => 'products_1752790000_9f3ac1']
        );

        $this->assertSame(['products_1752700000_0a1b2c'], $finder->find(3600));
    }

    public function testAliasedTargetIsKept(): void
    {
        $finder = $this->finder(['products_1752000000_9f3ac1'], ['products' => 'products_1752000000_9f3ac1']);

        $this->assertSame([], $finder->find(0));
    }

    public function testTargetOfAnotherAliasIsKept(): void
    {
        $finder = $this->finder(
            ['products_1752000000_9f3ac1'],
            ['products' => 'products_1752790000_aaaaaa', 'legacy' => 'products_1752000000_9f3ac1']
        );

        $this->assertSame([], $finder->find(0));
    }

    public function testNonVersionedNameIsKept(): void
    {
        $finder = $this->finder(
            ['products', 'products_backup', 'products_1752000000_XYZ123', 'products_175200000_9f3ac1'],
            ['products' => 'products_1752790000_aaaaaa']
        );

        $this->assertSame([], $finder->find(0));
    }

    public function testUnknownAliasPrefixIsKept(): void
    {
        // A fully removed alias leaves no proof the collection was ours.
        $finder = $this->finder(['orders_1752000000_9f3ac1'], ['products' => 'products_1752790000_aaaaaa']);

        $this->assertSame([], $finder->find(0));
    }

    public function testTooYoungCollectionIsKept(): void
    {
        $finder = $this->finder(
            ['products_1752799000_9f3ac1', 'products_1752713600_0a1b2c'],
            ['products' => 'products_1752790000_aaaaaa']
        );

        $this->assertSame(['products_1752713600_0a1b2c'], $finder->find(86400));
    }

    public function testAliasNameContainingUnderscoresIsParsed(): void
    {
        $finder = $this->finder(
            ['catalog_products_en_1752000000_9f3ac1', 'catalog_products_de_1752000000_0a1b2c'],
            ['catalog_products_en' => 'catalog_products_en_1752790000_aaaaaa']
        );

        $this->assertSame(['catalog_products_en_1752000000_9f3ac1'], $finder->find(0));
    }

    public function testGeneratedCollectionNameIsRecognised(): void
    {
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW - 100);
        $generator = new AliasManager(
            $this->createStub(TypesenseClient::class),
            $this->createStub(CollectionManager::class),
            $dateTime,
            new AliasLock($this->createStub(LockManagerInterface::class))
        );
        $name = $generator->generateCollectionName('catalog_products_en');

        $finder = $this->finder([$name], ['catalog_products_en' => 'catalog_products_en_1752790000_aaaaaa']);

        $this->assertSame([$name], $finder->find(0));
    }

    public function testCollectionsAreListedBeforeAliases(): void
    {
        $calls = [];
        $collectionManager = $this->createStub(CollectionManager::class);
        $collectionManager->method('getList')->willReturnCallback(function () use (&$calls): array {
            $calls[] = 'collections';

            return [];
        });
        $aliasManager = $this->createStub(AliasManager::class);
        $aliasManager->method('getList')->willReturnCallback(function () use (&$calls): array {
            $calls[] = 'aliases';

            return [];
        });

        (new OrphanFinder(
            $collectionManager,
            $aliasManager,
            $this->createStub(DateTime::class),
            new AliasLock($this->createStub(LockManagerInterface::class))
        ))->find(0);

        // A swap landing between the two reads then shows its new target as aliased, never as an orphan.
        $this->assertSame(['collections', 'aliases'], $calls);
    }

    public function testDropRechecksUnderTheLockOfTheParsedAlias(): void
    {
        $log = [];
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->expects($this->once())->method('lock')
            ->with('dmlab_typesense_alias_' . hash('sha256', 'catalog_products_en'))
            ->willReturnCallback(function () use (&$log): bool {
                $log[] = 'lock';

                return true;
            });
        $locks->expects($this->once())->method('unlock')->willReturnCallback(function () use (&$log): bool {
            $log[] = 'unlock';

            return true;
        });
        $collectionManager = $this->createMock(CollectionManager::class);
        $collectionManager->expects($this->once())->method('drop')->with('catalog_products_en_1752000000_9f3ac1')
            ->willReturnCallback(function () use (&$log): array {
                $log[] = 'drop';

                return [];
            });

        $finder = $this->finder(
            [],
            ['catalog_products_en' => 'catalog_products_en_1752790000_aaaaaa'],
            $collectionManager,
            $locks
        );

        $this->assertTrue($finder->drop('catalog_products_en_1752000000_9f3ac1'));
        $this->assertSame(['lock', 'drop', 'unlock'], $log);
    }

    public function testDropSkipsACollectionAliasedSinceTheListing(): void
    {
        $collectionManager = $this->createMock(CollectionManager::class);
        $collectionManager->expects($this->never())->method('drop');

        $finder = $this->finder([], ['products' => 'products_1752000000_9f3ac1'], $collectionManager);

        $this->assertFalse($finder->drop('products_1752000000_9f3ac1'));
    }

    public function testDropSkipsANonVersionedName(): void
    {
        $collectionManager = $this->createMock(CollectionManager::class);
        $collectionManager->expects($this->never())->method('drop');

        $this->assertFalse($this->finder([], ['products' => 'x'], $collectionManager)->drop('products'));
    }

    public function testDropTouchesNothingWithoutTheLock(): void
    {
        $locks = $this->createStub(LockManagerInterface::class);
        $locks->method('lock')->willReturn(false);
        $collectionManager = $this->createMock(CollectionManager::class);
        $collectionManager->expects($this->never())->method('drop');

        $this->expectException(TypesenseException::class);

        $this->finder([], ['products' => 'x'], $collectionManager, $locks)->drop('products_1752000000_9f3ac1');
    }
}
