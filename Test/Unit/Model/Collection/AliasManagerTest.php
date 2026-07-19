<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseCore\Test\Unit\Model\Collection;

use MageDevGroup\TypesenseCore\Exception\TypesenseException;
use MageDevGroup\TypesenseCore\Model\Client\TypesenseClient;
use MageDevGroup\TypesenseCore\Model\Collection\AliasManager;
use MageDevGroup\TypesenseCore\Model\Collection\CollectionManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class AliasManagerTest extends TestCase
{
    /** @var TypesenseClient|MockObject */
    private MockObject $client;

    /** @var CollectionManager|MockObject */
    private MockObject $collections;

    /** @var DateTime|MockObject */
    private MockObject $dateTime;

    /** @var AliasManager */
    private AliasManager $manager;

    protected function setUp(): void
    {
        $this->client = $this->createMock(TypesenseClient::class);
        $this->collections = $this->createMock(CollectionManager::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->manager = new AliasManager($this->client, $this->collections, $this->dateTime);
    }

    public function testGetListMapsAliasesToTheirTargets(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with('GET', '/aliases')
            ->willReturn(['aliases' => [
                ['name' => 'products_en', 'collection_name' => 'products_en_1'],
                ['name' => 'products_de', 'collection_name' => 'products_de_1'],
            ]]);

        $this->assertSame(
            ['products_en' => 'products_en_1', 'products_de' => 'products_de_1'],
            $this->manager->getList()
        );
    }

    public function testGetListOnAnEmptyServerIsAnEmptyArray(): void
    {
        $this->client->expects($this->once())->method('request')->with('GET', '/aliases')->willReturn([]);

        $this->assertSame([], $this->manager->getList());
    }

    /**
     * A proxy returning a JSON error envelope must not read as "no aliases defined": for a
     * diagnostic command a silent wrong answer is worse than a failure.
     */
    public function testGetListOnAMalformedAliasesValueThrows(): void
    {
        $this->client->method('request')->willReturn(['aliases' => 'nope']);

        $this->expectException(TypesenseException::class);
        $this->expectExceptionMessage('"aliases" is not an array');

        $this->manager->getList();
    }

    public function testGetListSkipsMalformedAliasEntries(): void
    {
        $this->client->method('request')->willReturn(['aliases' => [
            'nope',
            ['name' => 'products', 'collection_name' => 'products_1'],
        ]]);

        $this->assertSame(['products' => 'products_1'], $this->manager->getList());
    }

    public function testGetListFailureSurfaces(): void
    {
        $this->client->method('request')->willThrowException(new TypesenseException('Forbidden.', 401));

        $this->expectException(TypesenseException::class);

        $this->manager->getList();
    }

    public function testUpsertPutsTheTargetCollection(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with('PUT', '/aliases/products', ['collection_name' => 'products_1'])
            ->willReturn(['name' => 'products', 'collection_name' => 'products_1']);

        $this->assertSame(
            ['name' => 'products', 'collection_name' => 'products_1'],
            $this->manager->upsert('products', 'products_1')
        );
    }

    public function testUpsertEncodesTheAliasIntoThePath(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with('PUT', '/aliases/products%20eu', ['collection_name' => 'products_1'])
            ->willReturn([]);

        $this->manager->upsert('products eu', 'products_1');
    }

    public function testUpsertFailureSurfaces(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->willThrowException(new TypesenseException('Collection not found.', 404));

        $this->expectException(TypesenseException::class);
        $this->expectExceptionCode(404);

        $this->manager->upsert('products', 'missing');
    }

    public function testResolveReturnsTheTargetCollection(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with('GET', '/aliases/products')
            ->willReturn(['name' => 'products', 'collection_name' => 'products_1']);

        $this->assertSame('products_1', $this->manager->resolve('products'));
    }

    public function testResolveIsNullOnA404(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->willThrowException(new TypesenseException('Not found.', 404));

        $this->assertNull($this->manager->resolve('products'));
    }

    public function testResolveRethrowsAnythingOtherThanA404(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->willThrowException(new TypesenseException('Service unavailable.', 503));

        $this->expectException(TypesenseException::class);
        $this->expectExceptionCode(503);

        $this->manager->resolve('products');
    }

    public function testDeleteRemovesTheAlias(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with('DELETE', '/aliases/products')
            ->willReturn(['name' => 'products', 'collection_name' => 'products_1']);

        $this->assertSame(
            ['name' => 'products', 'collection_name' => 'products_1'],
            $this->manager->delete('products')
        );
    }

    public function testDeleteOnAMissingAliasThrows(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->willThrowException(new TypesenseException('Not found.', 404));

        $this->expectException(TypesenseException::class);
        $this->expectExceptionCode(404);

        $this->manager->delete('missing');
    }

    public function testSwapRepointsTheAliasThenDropsTheOldCollection(): void
    {
        $calls = [];

        $this->client->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $path, ?array $body = null) use (&$calls): array {
                $calls[] = [$method, $path, $body];

                return $method === 'GET' ? ['collection_name' => 'products_1'] : [];
            });

        $this->collections->expects($this->once())
            ->method('drop')
            ->with('products_1')
            ->willReturnCallback(function (string $name) use (&$calls): array {
                $calls[] = ['DROP', $name, null];

                return [];
            });

        $this->assertSame('products_1', $this->manager->swap('products', 'products_2'));

        // The drop happens only after the alias moved — readers are never left on a dropped collection.
        $this->assertSame(
            [
                ['GET', '/aliases/products', null],
                ['PUT', '/aliases/products', ['collection_name' => 'products_2']],
                ['DROP', 'products_1', null],
            ],
            $calls
        );
    }

    public function testSwapToleratesTheOldCollectionAlreadyBeingGone(): void
    {
        // A concurrent swap resolved the same predecessor and dropped it first. That is the
        // state this swap wanted; it must not throw after the alias has already moved.
        $this->client->method('request')
            ->willReturnCallback(
                static fn(string $method): array => $method === 'GET' ? ['collection_name' => 'products_1'] : []
            );

        $this->collections->expects($this->once())
            ->method('drop')
            ->with('products_1')
            ->willThrowException(new TypesenseException('Collection not found.', 404));

        $this->assertSame('products_1', $this->manager->swap('products', 'products_2'));
    }

    public function testSwapRethrowsADropFailureThatIsNotA404(): void
    {
        $this->client->method('request')
            ->willReturnCallback(
                static fn(string $method): array => $method === 'GET' ? ['collection_name' => 'products_1'] : []
            );

        $this->collections->method('drop')
            ->willThrowException(new TypesenseException('Service unavailable.', 503));

        $this->expectException(TypesenseException::class);
        $this->expectExceptionCode(503);

        $this->manager->swap('products', 'products_2');
    }

    public function testSwapCreatesTheAliasAndDropsNothingWhenItIsAbsent(): void
    {
        $this->client->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method): array {
                if ($method === 'GET') {
                    throw new TypesenseException('Not found.', 404);
                }

                return [];
            });

        $this->collections->expects($this->never())->method('drop');

        $this->assertNull($this->manager->swap('products', 'products_1'));
    }

    public function testSwapDropsNothingWhenTheAliasAlreadyPointsAtTheNewCollection(): void
    {
        $this->client->method('request')->willReturnCallback(
            static fn (string $method): array => $method === 'GET' ? ['collection_name' => 'products_1'] : []
        );

        $this->collections->expects($this->never())->method('drop');

        $this->assertNull($this->manager->swap('products', 'products_1'));
    }

    public function testSwapDoesNotDropWhenRepointingFails(): void
    {
        $this->client->method('request')->willReturnCallback(
            static function (string $method): array {
                if ($method === 'GET') {
                    return ['collection_name' => 'products_1'];
                }

                throw new TypesenseException('Collection not found.', 404);
            }
        );

        $this->collections->expects($this->never())->method('drop');

        $this->expectException(TypesenseException::class);

        $this->manager->swap('products', 'products_2');
    }

    public function testSwapSurfacesAFailedDropAfterTheAliasHasAlreadyMoved(): void
    {
        $repointed = false;

        $this->client->method('request')->willReturnCallback(
            function (string $method) use (&$repointed): array {
                if ($method === 'GET') {
                    return ['collection_name' => 'products_1'];
                }
                $repointed = true;

                return [];
            }
        );

        $this->collections->expects($this->once())
            ->method('drop')
            ->willThrowException(new TypesenseException('Collection is being written to.', 409));

        try {
            $this->manager->swap('products', 'products_2');
            $this->fail('The failed drop should have surfaced.');
        } catch (TypesenseException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        // The swap itself stood: readers are on products_2 and only the old collection leaked.
        $this->assertTrue($repointed);
    }

    public function testGenerateCollectionNameSuffixesTheAliasWithTheCurrentTimestamp(): void
    {
        $this->dateTime->method('gmtTimestamp')->willReturn(1752710400);

        $this->assertMatchesRegularExpression(
            '/^products_1752710400_[0-9a-f]{6}$/',
            $this->manager->generateCollectionName('products')
        );
    }

    public function testGenerateCollectionNameIsUniqueWithinTheSameSecond(): void
    {
        $this->dateTime->method('gmtTimestamp')->willReturn(1752710400);

        $this->assertNotSame(
            $this->manager->generateCollectionName('products'),
            $this->manager->generateCollectionName('products')
        );
    }
}
