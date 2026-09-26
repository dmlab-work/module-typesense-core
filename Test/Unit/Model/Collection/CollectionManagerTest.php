<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit\Model\Collection;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseCore\Model\Collection\CollectionManager;
use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CollectionManagerTest extends TestCase
{
    private const OPERATION_TIMEOUT = 300;

    /** @var TypesenseClient|MockObject */
    private MockObject $client;

    /** @var CollectionManager */
    private CollectionManager $manager;

    protected function setUp(): void
    {
        $this->client = $this->createMock(TypesenseClient::class);
        $config = $this->createMock(ConnectionSettingsInterface::class);
        $config->method('getOperationTimeout')->willReturn(self::OPERATION_TIMEOUT);
        $this->manager = new CollectionManager($this->client, $config);
    }

    public function testCreatePostsNameFieldsAndOptions(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                '/collections',
                [
                    'default_sorting_field' => 'position',
                    'name' => 'products_1',
                    'fields' => [
                        ['name' => 'sku', 'type' => 'string'],
                        ['name' => 'position', 'type' => 'int32', 'sort' => true],
                    ],
                ]
            )
            ->willReturn(['name' => 'products_1', 'num_documents' => 0]);

        $result = $this->manager->create(
            'products_1',
            [new FieldSpec('sku', 'string'), new FieldSpec('position', 'int32', sort: true)],
            ['default_sorting_field' => 'position']
        );

        $this->assertSame(['name' => 'products_1', 'num_documents' => 0], $result);
    }

    public function testCreateSendsAnEmptyFieldListWhenThereAreNoFields(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with('POST', '/collections', ['name' => 'empty', 'fields' => []])
            ->willReturn([]);

        $this->manager->create('empty', []);
    }

    public function testCreateReindexesGappedFieldKeysSoTheyEncodeAsAJsonArray(): void
    {
        $fields = [3 => new FieldSpec('sku', 'string'), 7 => new FieldSpec('qty', 'int64')];

        $this->client->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                '/collections',
                [
                    'name' => 'products',
                    'fields' => [['name' => 'sku', 'type' => 'string'], ['name' => 'qty', 'type' => 'int64']],
                ]
            )
            ->willReturn([]);

        $this->manager->create('products', $fields);
    }

    public function testCreateOnAnExistingCollectionSurfacesTheConflict(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->willThrowException(new TypesenseException('Already exists.', 409, ['message' => 'Already exists.']));

        $this->expectException(TypesenseException::class);
        $this->expectExceptionCode(409);

        $this->manager->create('products', [new FieldSpec('sku', 'string')]);
    }

    public function testGetDecodesTheSchema(): void
    {
        $schema = ['name' => 'products', 'num_documents' => 42, 'fields' => [['name' => 'sku', 'type' => 'string']]];

        $this->client->expects($this->once())
            ->method('request')
            ->with('GET', '/collections/products')
            ->willReturn($schema);

        $this->assertSame($schema, $this->manager->get('products'));
    }

    public function testGetEncodesTheCollectionNameIntoThePath(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with('GET', '/collections/products%20eu')
            ->willReturn([]);

        $this->manager->get('products eu');
    }

    public function testGetOnAMissingCollectionThrows(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->willThrowException(new TypesenseException('Not found.', 404, ['message' => 'Not found.']));

        $this->expectException(TypesenseException::class);
        $this->expectExceptionCode(404);

        $this->manager->get('missing');
    }

    public function testExistsIsTrueWhenTheCollectionIsThere(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with('GET', '/collections/products')
            ->willReturn(['name' => 'products']);

        $this->assertTrue($this->manager->exists('products'));
    }

    public function testExistsIsFalseOnA404(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->willThrowException(new TypesenseException('Not found.', 404));

        $this->assertFalse($this->manager->exists('missing'));
    }

    public function testExistsRethrowsAnythingOtherThanA404(): void
    {
        // A 503 says nothing about whether the collection exists — swallowing it would lie.
        $this->client->expects($this->once())
            ->method('request')
            ->willThrowException(new TypesenseException('Service unavailable.', 503));

        $this->expectException(TypesenseException::class);
        $this->expectExceptionCode(503);

        $this->manager->exists('products');
    }

    public function testDropDeletesTheCollection(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with('DELETE', '/collections/products_1')
            ->willReturn(['name' => 'products_1']);

        $this->assertSame(['name' => 'products_1'], $this->manager->drop('products_1'));
    }

    public function testDropOnAMissingCollectionThrows(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->willThrowException(new TypesenseException('Not found.', 404));

        $this->expectException(TypesenseException::class);
        $this->expectExceptionCode(404);

        $this->manager->drop('missing');
    }

    public function testPatchSendsTheFieldChangesUnderAFieldsKey(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with(
                'PATCH',
                '/collections/products',
                ['fields' => [['name' => 'color', 'type' => 'string', 'facet' => true]]],
                [],
                self::OPERATION_TIMEOUT
            )
            ->willReturn(['fields' => [['name' => 'color', 'type' => 'string', 'facet' => true]]]);

        $this->manager->patch('products', [new FieldSpec('color', 'string', facet: true)]);
    }

    public function testPatchCombinesADropAndAReAddInOneCall(): void
    {
        // This is how a type change is expressed — Typesense cannot alter a field's type.
        $this->client->expects($this->once())
            ->method('request')
            ->with(
                'PATCH',
                '/collections/products',
                [
                    'fields' => [
                        ['name' => 'price', 'drop' => true],
                        ['name' => 'price', 'type' => 'string'],
                    ],
                ],
                [],
                self::OPERATION_TIMEOUT
            )
            ->willReturn([]);

        $this->manager->patch('products', [FieldSpec::drop('price'), new FieldSpec('price', 'string')]);
    }

    public function testPatchCarriesUnmodelledFieldPropertiesThrough(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with(
                'PATCH',
                '/collections/products',
                ['fields' => [['name' => 'embedding', 'type' => 'float[]', 'num_dim' => 384]]],
                [],
                self::OPERATION_TIMEOUT
            )
            ->willReturn([]);

        $this->manager->patch('products', [new FieldSpec('embedding', 'float[]', extra: ['num_dim' => 384])]);
    }

    public function testPatchFailureSurfaces(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->willThrowException(new TypesenseException('Field `price` is not found.', 404));

        $this->expectException(TypesenseException::class);
        $this->expectExceptionMessage('Field `price` is not found.');

        $this->manager->patch('products', [FieldSpec::drop('price')]);
    }
}
