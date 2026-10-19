<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Integration;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseCore\Model\Document\DocumentWriter;

class DocumentWriterTest extends TypesenseTestCase
{
    /** @var DocumentWriter */
    private DocumentWriter $writer;

    /** @var string */
    private string $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = new DocumentWriter($this->client, $this->settings);
        $this->collection = $this->resourceName('products');
        $this->collections->create($this->collection, [
            new FieldSpec('name', 'string'),
            new FieldSpec('price', 'float'),
            new FieldSpec('category', 'string', facet: true),
        ]);
    }

    public function testImportReportsUnencodableAndRejectedDocumentsPerLine(): void
    {
        $result = $this->writer->importBatch($this->collection, [
            ['id' => '1', 'name' => 'Shoe', 'price' => 10.0, 'category' => 'shoes'],
            ['id' => '2', 'name' => "Bad \xB1 bytes", 'price' => 11.0, 'category' => 'shoes'],
            ['id' => '3', 'name' => 'No price', 'category' => 'shoes'],
            ['id' => '4', 'name' => 'Boot', 'price' => 12.0, 'category' => 'boots'],
        ], DocumentWriter::ACTION_UPSERT, 3);

        $this->assertSame(2, $result->getSuccessCount());
        $this->assertSame(2, $result->getFailureCount());
        $errors = $result->getErrors();
        $this->assertSame([1, 2], array_column($errors, 'line'));
        $this->assertStringContainsString('Unable to encode the document', $errors[0]['error']);
        $this->assertStringContainsString('price', $errors[1]['error']);
        $this->assertSame(2, $this->collections->get($this->collection)['num_documents']);
    }

    public function testUpsertDeleteAndDeleteBy(): void
    {
        $upserted = $this->writer->upsert(
            $this->collection,
            ['id' => '1', 'name' => 'Shoe', 'price' => 10.0, 'category' => 'shoes']
        );
        $this->assertSame('1', $upserted['id']);
        $this->writer->upsert(
            $this->collection,
            ['id' => '1', 'name' => 'Shoe v2', 'price' => 9.0, 'category' => 'shoes']
        );
        $this->assertSame(
            'Shoe v2',
            $this->client->request('GET', '/collections/' . $this->collection . '/documents/1')['name']
        );

        $this->writer->importBatch($this->collection, [
            ['id' => '2', 'name' => 'Boot', 'price' => 20.0, 'category' => 'boots'],
            ['id' => '3', 'name' => 'Clog', 'price' => 30.0, 'category' => 'shoes'],
        ]);

        $deleted = $this->writer->delete($this->collection, '2');
        $this->assertSame('2', $deleted['id']);

        $this->assertSame(2, $this->writer->deleteBy($this->collection, 'category:=shoes'));
        $this->assertSame(0, $this->collections->get($this->collection)['num_documents']);
    }
}
