<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Integration;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;

class CollectionLifecycleTest extends TypesenseTestCase
{
    public function testCreateGetExistsListAndDrop(): void
    {
        $name = $this->resourceName('products');

        $created = $this->collections->create($name, [
            new FieldSpec('sku', 'string'),
            new FieldSpec('price', 'float', facet: true),
        ]);
        $this->assertSame($name, $created['name']);

        $schema = $this->collections->get($name);
        $this->assertSame(['sku', 'price'], array_column($schema['fields'], 'name'));
        $this->assertTrue($schema['fields'][1]['facet']);
        $this->assertTrue($this->collections->exists($name));
        $this->assertContains($name, $this->collections->getList());

        $this->collections->drop($name);

        $this->assertFalse($this->collections->exists($name));
        $this->assertNotContains($name, $this->collections->getList());
    }
}
