<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseCore\Test\Unit\Model\Schema;

use MageDevGroup\TypesenseCore\Model\Collection\FieldSpec;
use MageDevGroup\TypesenseCore\Model\Schema\FieldChange;
use MageDevGroup\TypesenseCore\Model\Schema\SchemaDiff;
use PHPUnit\Framework\TestCase;

class SchemaDiffTest extends TestCase
{
    /**
     * The payload `CollectionManager::patch()` would send for a diff.
     *
     * @param SchemaDiff $diff
     * @return array<int,array<string,mixed>>
     */
    private function patchPayload(SchemaDiff $diff): array
    {
        return array_map(static fn (FieldSpec $field): array => $field->toArray(), $diff->toPatchFields());
    }

    public function testAnEmptyDiffPatchesNothing(): void
    {
        $this->assertTrue((new SchemaDiff())->isEmpty());
        $this->assertSame([], (new SchemaDiff())->toPatchFields());
    }

    public function testAnAdditionIsPatchedAsTheDesiredField(): void
    {
        $diff = new SchemaDiff([new FieldSpec('color', 'string', facet: true)]);

        $this->assertSame([['name' => 'color', 'type' => 'string', 'facet' => true]], $this->patchPayload($diff));
    }

    public function testARemovalIsPatchedAsADropMarker(): void
    {
        $diff = new SchemaDiff([], [new FieldSpec('legacy', 'int32')]);

        $this->assertSame([['name' => 'legacy', 'drop' => true]], $this->patchPayload($diff));
    }

    public function testATypeChangeIsADropAndAReAddInTheSameCall(): void
    {
        $diff = new SchemaDiff(
            [],
            [],
            [new FieldChange(new FieldSpec('price', 'float'), new FieldSpec('price', 'string', facet: true))]
        );

        $this->assertSame(
            [
                ['name' => 'price', 'drop' => true],
                ['name' => 'price', 'type' => 'string', 'facet' => true],
            ],
            $this->patchPayload($diff)
        );
    }

    public function testAFlagChangeIsAlsoADropAndAReAdd(): void
    {
        $diff = new SchemaDiff(
            [],
            [],
            [],
            [new FieldChange(
                new FieldSpec('color', 'string', facet: false),
                new FieldSpec('color', 'string', facet: true)
            )]
        );

        $this->assertSame(
            [
                ['name' => 'color', 'drop' => true],
                ['name' => 'color', 'type' => 'string', 'facet' => true],
            ],
            $this->patchPayload($diff)
        );
    }

    public function testEveryDropPrecedesItsReAddInACombinedPatch(): void
    {
        $diff = new SchemaDiff(
            [new FieldSpec('brand', 'string')],
            [new FieldSpec('legacy', 'int32')],
            [new FieldChange(new FieldSpec('price', 'float'), new FieldSpec('price', 'string'))],
            [new FieldChange(new FieldSpec('color', 'string'), new FieldSpec('color', 'string', facet: true))]
        );

        $payload = $this->patchPayload($diff);

        $this->assertSame(
            [
                ['name' => 'legacy', 'drop' => true],
                ['name' => 'price', 'drop' => true],
                ['name' => 'price', 'type' => 'string'],
                ['name' => 'color', 'drop' => true],
                ['name' => 'color', 'type' => 'string', 'facet' => true],
                ['name' => 'brand', 'type' => 'string'],
            ],
            $payload
        );
    }

    public function testAnExtraPropertySurvivesIntoThePatchPayload(): void
    {
        $diff = new SchemaDiff([new FieldSpec('embedding', 'float[]', extra: ['num_dim' => 768])]);

        $this->assertSame(
            [['name' => 'embedding', 'type' => 'float[]', 'num_dim' => 768]],
            $this->patchPayload($diff)
        );
    }

    public function testAReAddKeepsPropertiesTheDesiredSpecDoesNotDeclare(): void
    {
        // The differ ignores a flag the desired spec leaves null, so the re-add must not
        // drop it either — otherwise a facet toggle silently un-sorts the field.
        $diff = new SchemaDiff(
            [],
            [],
            [],
            [new FieldChange(
                new FieldSpec('sku', 'string', facet: false, index: true, sort: true),
                new FieldSpec('sku', 'string', facet: true)
            )]
        );

        $this->assertSame(
            [
                ['name' => 'sku', 'drop' => true],
                ['name' => 'sku', 'type' => 'string', 'facet' => true, 'index' => true, 'sort' => true],
            ],
            $this->patchPayload($diff)
        );
    }

    public function testAReAddKeepsExtraPropertiesTheDesiredSpecDoesNotDeclare(): void
    {
        // A consumer's vector field must survive a PATCH it never asked for.
        $diff = new SchemaDiff(
            [],
            [],
            [],
            [new FieldChange(
                new FieldSpec('embedding', 'float[]', extra: ['num_dim' => 768, 'vec_dist' => 'cosine']),
                new FieldSpec('embedding', 'float[]', facet: true)
            )]
        );

        $this->assertSame(
            [
                ['name' => 'embedding', 'drop' => true],
                [
                    'name' => 'embedding',
                    'type' => 'float[]',
                    'facet' => true,
                    'num_dim' => 768,
                    'vec_dist' => 'cosine',
                ],
            ],
            $this->patchPayload($diff)
        );
    }

    public function testTheDesiredSpecWinsOverTheActualOneWhereBothDeclareAProperty(): void
    {
        $diff = new SchemaDiff(
            [],
            [],
            [new FieldChange(
                new FieldSpec('price', 'float', facet: true, sort: true, extra: ['range_index' => false]),
                new FieldSpec('price', 'string', facet: false, extra: ['range_index' => true])
            )]
        );

        $this->assertSame(
            [
                ['name' => 'price', 'drop' => true],
                ['name' => 'price', 'type' => 'string', 'facet' => false, 'sort' => true, 'range_index' => true],
            ],
            $this->patchPayload($diff)
        );
    }

    public function testADiffWithAnyKindOfChangeIsNotEmpty(): void
    {
        $change = new FieldChange(new FieldSpec('f', 'string'), new FieldSpec('f', 'int32'));

        $this->assertFalse((new SchemaDiff([new FieldSpec('f', 'string')]))->isEmpty());
        $this->assertFalse((new SchemaDiff([], [new FieldSpec('f', 'string')]))->isEmpty());
        $this->assertFalse((new SchemaDiff([], [], [$change]))->isEmpty());
        $this->assertFalse((new SchemaDiff([], [], [], [$change]))->isEmpty());
    }
}
