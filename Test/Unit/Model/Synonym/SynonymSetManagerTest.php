<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit\Model\Synonym;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseCore\Model\Synonym\SynonymSetManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SynonymSetManagerTest extends TestCase
{
    /** @var TypesenseClient|MockObject */
    private MockObject $client;

    /** @var SynonymSetManager */
    private SynonymSetManager $manager;

    protected function setUp(): void
    {
        $this->client = $this->createMock(TypesenseClient::class);
        $this->manager = new SynonymSetManager($this->client);
    }

    public function testUpsertPutsItemsAsJsonArrays(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with('PUT', '/synonym_sets/products_en_synonyms', ['items' => [
                ['id' => 'tee', 'synonyms' => ['t-shirt', 'tee', 'shirt']],
            ]])
            ->willReturn([]);

        $this->manager->upsert('products_en_synonyms', [
            ['synonyms' => [3 => 't-shirt', 7 => 'tee', 'x' => 'shirt'], 'id' => 'tee'],
        ]);
    }

    public function testUpsertWithNoItemsSendsAnEmptySet(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with('PUT', '/synonym_sets/empty', ['items' => []])
            ->willReturn([]);

        $this->manager->upsert('empty', []);
    }

    public function testUpsertEncodesTheName(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with('PUT', '/synonym_sets/a%20b%2Fc', $this->anything())
            ->willReturn([]);

        $this->manager->upsert('a b/c', []);
    }

    /**
     * @return array<string,array{0:string,1:array<mixed>}>
     */
    public static function invalidUpsertProvider(): array
    {
        return [
            'empty name' => ['', []],
            'items not a list' => ['set', ['a' => ['id' => 'x', 'synonyms' => ['a']]]],
            'item not an array' => ['set', ['nope']],
            'missing id' => ['set', [['synonyms' => ['a']]]],
            'empty id' => ['set', [['id' => '', 'synonyms' => ['a']]]],
            'non-string id' => ['set', [['id' => 5, 'synonyms' => ['a']]]],
            'missing synonyms' => ['set', [['id' => 'x']]],
            'synonyms not an array' => ['set', [['id' => 'x', 'synonyms' => 'a']]],
            'empty synonyms' => ['set', [['id' => 'x', 'synonyms' => []]]],
            'empty synonym' => ['set', [['id' => 'x', 'synonyms' => ['a', '']]]],
            'non-string synonym' => ['set', [['id' => 'x', 'synonyms' => ['a', 1]]]],
            'extra key' => ['set', [['id' => 'x', 'synonyms' => ['a'], 'root' => 'b']]],
        ];
    }

    /**
     * @param string $name
     * @param array<mixed> $items
     */
    #[DataProvider('invalidUpsertProvider')]
    public function testUpsertRejectsInvalidInputWithoutARequest(string $name, array $items): void
    {
        $this->client->expects($this->never())->method('request');

        $this->expectException(\InvalidArgumentException::class);

        $this->manager->upsert($name, $items);
    }

    public function testDeleteSendsDelete(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with('DELETE', '/synonym_sets/set')
            ->willReturn(['name' => 'set']);

        $this->manager->delete('set');
    }

    public function testDeleteIgnoresAMissingSet(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->willThrowException(new TypesenseException('Synonym index not found', 404));

        $this->manager->delete('set');
    }

    public function testDeleteRethrowsOtherFailures(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->willThrowException(new TypesenseException('Boom', 500));

        $this->expectException(TypesenseException::class);
        $this->expectExceptionCode(500);

        $this->manager->delete('set');
    }

    public function testDeleteRejectsAnEmptyName(): void
    {
        $this->client->expects($this->never())->method('request');

        $this->expectException(\InvalidArgumentException::class);

        $this->manager->delete('');
    }

    public function testExistsIsTrueWhenTheSetIsFound(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with('GET', '/synonym_sets/set')
            ->willReturn(['items' => []]);

        $this->assertTrue($this->manager->exists('set'));
    }

    public function testExistsIsFalseOnA404(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->willThrowException(new TypesenseException('Synonym index not found', 404));

        $this->assertFalse($this->manager->exists('set'));
    }

    public function testExistsRethrowsOtherFailures(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->willThrowException(new TypesenseException('Boom', 500));

        $this->expectException(TypesenseException::class);
        $this->expectExceptionCode(500);

        $this->manager->exists('set');
    }

    public function testExistsRejectsAnEmptyName(): void
    {
        $this->client->expects($this->never())->method('request');

        $this->expectException(\InvalidArgumentException::class);

        $this->manager->exists('');
    }
}
