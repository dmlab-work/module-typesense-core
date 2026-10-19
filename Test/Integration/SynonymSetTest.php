<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Integration;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseCore\Model\Document\DocumentWriter;
use DmLab\TypesenseCore\Model\Synonym\SynonymSetManager;

class SynonymSetTest extends TypesenseTestCase
{
    /** @var SynonymSetManager */
    private SynonymSetManager $synonyms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->synonyms = new SynonymSetManager($this->client);
    }

    public function testUpsertExistsAndDeleteTwice(): void
    {
        $set = $this->resourceName('synonyms');
        $this->assertFalse($this->synonyms->exists($set));

        $this->synonyms->upsert($set, []);
        $this->assertTrue($this->synonyms->exists($set));

        $this->synonyms->delete($set);
        $this->synonyms->delete($set);
        $this->assertFalse($this->synonyms->exists($set));
    }

    public function testASearchWithTheSetFindsASynonym(): void
    {
        $set = $this->resourceName('synonyms');
        $collection = $this->resourceName('products');
        $this->collections->create($collection, [new FieldSpec('name', 'string')]);
        (new DocumentWriter($this->client, $this->settings))
            ->importBatch($collection, [['id' => '1', 'name' => 'red sneaker']]);

        $this->synonyms->upsert($set, [['id' => 'shoes', 'synonyms' => ['sneaker', 'trainer']]]);

        $search = fn (array $extra): array => $this->client->request(
            'GET',
            '/collections/' . $collection . '/documents/search',
            null,
            ['q' => 'trainer', 'query_by' => 'name'] + $extra
        );
        $this->assertSame(0, $search([])['found']);
        $this->assertSame(1, $search(['synonym_sets' => $set])['found']);
    }
}
