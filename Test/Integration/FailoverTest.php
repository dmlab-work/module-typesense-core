<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Integration;

use DmLab\TypesenseCore\Model\Client\NodeHealthRegistry;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseCore\Model\Collection\CollectionManager;
use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseCore\Model\Config\Node;
use DmLab\TypesenseCore\Test\Integration\Doubles\EnvConnectionSettings;
use Psr\Log\NullLogger;

/**
 * A refused port ahead of the real node: errno 7, so even a POST provably never left.
 */
class FailoverTest extends TypesenseTestCase
{
    /** @var Node */
    private Node $deadNode;

    /** @var EnvConnectionSettings */
    private EnvConnectionSettings $failoverSettings;

    /** @var TypesenseClient */
    private TypesenseClient $failoverClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deadNode = new Node('127.0.0.1', 1);
        $this->failoverSettings = new EnvConnectionSettings(
            (string)getenv('TYPESENSE_TEST_URL'),
            $this->settings->getApiKey(),
            [$this->deadNode]
        );
        $this->failoverClient = $this->createClient($this->failoverSettings);
    }

    public function testAGetFailsOverAndMarksTheDeadNode(): void
    {
        $this->assertTrue($this->failoverClient->request('GET', '/health')['ok']);
        $this->assertTrue((new NodeHealthRegistry($this->cache, new NullLogger()))->isMarked($this->deadNode));
    }

    public function testAPostCreateFailsOverWhenTheRequestNeverLeft(): void
    {
        $name = $this->resourceName('products');

        $created = (new CollectionManager($this->failoverClient, $this->failoverSettings))
            ->create($name, [new FieldSpec('sku', 'string')]);

        $this->assertSame($name, $created['name']);
        $this->assertTrue($this->collections->exists($name));
    }
}
