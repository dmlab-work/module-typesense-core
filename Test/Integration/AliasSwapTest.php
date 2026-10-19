<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Integration;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Collection\FieldSpec;

class AliasSwapTest extends TypesenseTestCase
{
    public function testSwapPointsTheAliasAtTheNewCollectionAndDropsThePredecessor(): void
    {
        $alias = $this->resourceName('products');
        $first = $this->aliases->generateCollectionName($alias);
        $second = $this->aliases->generateCollectionName($alias);
        $this->collections->create($first, [new FieldSpec('sku', 'string')]);
        $this->collections->create($second, [new FieldSpec('sku', 'string')]);

        $this->assertNull($this->aliases->resolve($alias));
        $this->assertNull($this->aliases->swap($alias, $first));
        $this->assertSame($first, $this->aliases->resolve($alias));

        $this->assertSame($first, $this->aliases->swap($alias, $second));

        $this->assertSame($second, $this->aliases->resolve($alias));
        $this->assertSame($second, $this->aliases->getList()[$alias]);
        $this->assertFalse($this->collections->exists($first));
        $this->assertTrue($this->collections->exists($second));
    }

    public function testSwapOntoAMissingCollectionLeavesTheAliasAlone(): void
    {
        $alias = $this->resourceName('products');
        $live = $this->aliases->generateCollectionName($alias);
        $this->collections->create($live, [new FieldSpec('sku', 'string')]);
        $this->aliases->upsert($alias, $live);

        try {
            $this->aliases->swap($alias, $this->aliases->generateCollectionName($alias));
            $this->fail('Swapping onto a missing collection should have thrown.');
        } catch (TypesenseException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        // The engine itself would accept an alias pointing at nothing.
        $this->assertSame($live, $this->aliases->resolve($alias));
    }
}
