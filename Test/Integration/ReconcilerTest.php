<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Integration;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseCore\Model\Document\DocumentWriter;
use DmLab\TypesenseCore\Model\Schema\Reconciler;
use DmLab\TypesenseCore\Model\Schema\ReconcilePolicy;
use DmLab\TypesenseCore\Model\Schema\SchemaDiffer;

class ReconcilerTest extends TypesenseTestCase
{
    /** @var Reconciler */
    private Reconciler $reconciler;

    /** @var string */
    private string $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reconciler = new Reconciler(new SchemaDiffer(), $this->collections);
        $this->collection = $this->resourceName('products');
        $this->collections->create($this->collection, [
            new FieldSpec('sku', 'string'),
            new FieldSpec('brand', 'string', facet: false),
            new FieldSpec('qty', 'int32'),
        ]);
        (new DocumentWriter($this->client, $this->settings))->importBatch($this->collection, [
            ['id' => '1', 'sku' => 'A-1', 'brand' => 'Acme', 'qty' => 3],
            ['id' => '2', 'sku' => 'B-2', 'brand' => 'Bolt', 'qty' => 7],
        ]);
    }

    public function testCompatibleChangesApplyInPlaceAndASecondRunIsANoOp(): void
    {
        $desired = [
            new FieldSpec('sku', 'string'),
            new FieldSpec('brand', 'string', facet: true),
            new FieldSpec('qty', 'int64'),
        ];

        $first = $this->reconciler->reconcile($this->collection, $desired, new ReconcilePolicy());

        $this->assertTrue($first->isInPlace());
        $this->assertNotNull($first->getSchema(), 'a PATCH was sent');
        $fields = array_column($this->collections->get($this->collection)['fields'], null, 'name');
        $this->assertTrue($fields['brand']['facet']);
        $this->assertSame('int64', $fields['qty']['type']);
        $this->assertSame(2, $this->collections->get($this->collection)['num_documents']);

        // The engine echoes defaults (index, optional, sort, locale, ...) the desired specs never declared.
        $second = $this->reconciler->reconcile($this->collection, $desired, new ReconcilePolicy());

        $this->assertTrue($second->isInPlace());
        $this->assertTrue($second->getDiff()->isEmpty());
        $this->assertNull($second->getSchema(), 'no PATCH was sent');
    }

    public function testAnIncompatibleChangeNeedsARebuildAndLeavesTheSchemaAlone(): void
    {
        $before = $this->collections->get($this->collection)['fields'];

        $decision = $this->reconciler->reconcile(
            $this->collection,
            [
                new FieldSpec('sku', 'int32'),
                new FieldSpec('brand', 'string', facet: false),
                new FieldSpec('qty', 'int32'),
            ],
            new ReconcilePolicy()
        );

        $this->assertTrue($decision->needsRebuild());
        $this->assertStringContainsString('"sku"', (string)$decision->getReason());
        $this->assertSame($before, $this->collections->get($this->collection)['fields']);
    }
}
