<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit\Model\Schema;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseCore\Model\Schema\SchemaDiffer;
use PHPUnit\Framework\TestCase;

class SchemaDifferTest extends TestCase
{
    /** @var SchemaDiffer */
    private SchemaDiffer $differ;

    protected function setUp(): void
    {
        $this->differ = new SchemaDiffer();
    }

    /**
     * A collection schema as the engine returns it.
     *
     * @param array<int,array<string,mixed>> $fields
     * @param int $documents
     * @return array<string,mixed>
     */
    private function schema(array $fields, int $documents = 0): array
    {
        return ['name' => 'products', 'num_documents' => $documents, 'fields' => $fields];
    }

    public function testIdenticalFieldSetsProduceNoDiff(): void
    {
        $diff = $this->differ->diff(
            [new FieldSpec('sku', 'string', facet: false), new FieldSpec('price', 'float', facet: true)],
            $this->schema([
                ['name' => 'sku', 'type' => 'string', 'facet' => false],
                ['name' => 'price', 'type' => 'float', 'facet' => true],
            ])
        );

        $this->assertTrue($diff->isEmpty());
        $this->assertSame([], $diff->toPatchFields());
    }

    public function testAFieldMissingFromTheCollectionIsAdded(): void
    {
        $diff = $this->differ->diff(
            [new FieldSpec('sku', 'string'), new FieldSpec('color', 'string', facet: true)],
            $this->schema([['name' => 'sku', 'type' => 'string']])
        );

        $this->assertFalse($diff->isEmpty());
        $this->assertCount(1, $diff->getAdded());
        $this->assertSame('color', $diff->getAdded()[0]->getName());
        $this->assertSame([], $diff->getDropped());
        $this->assertSame([], $diff->getTypeChanged());
        $this->assertSame([], $diff->getFlagsChanged());
    }

    public function testAFieldAbsentFromTheDesiredSetIsDropped(): void
    {
        $diff = $this->differ->diff(
            [new FieldSpec('sku', 'string')],
            $this->schema([
                ['name' => 'sku', 'type' => 'string'],
                ['name' => 'legacy', 'type' => 'int32'],
            ])
        );

        $this->assertCount(1, $diff->getDropped());
        $this->assertSame('legacy', $diff->getDropped()[0]->getName());
        $this->assertSame([], $diff->getAdded());
    }

    public function testADifferentTypeIsATypeChangeCarryingBothSides(): void
    {
        $diff = $this->differ->diff(
            [new FieldSpec('price', 'string')],
            $this->schema([['name' => 'price', 'type' => 'float']])
        );

        $this->assertCount(1, $diff->getTypeChanged());
        $change = $diff->getTypeChanged()[0];
        $this->assertSame('price', $change->getName());
        $this->assertSame('float', $change->getActual()->getType());
        $this->assertSame('string', $change->getDesired()->getType());
        $this->assertSame([], $diff->getFlagsChanged());
    }

    public function testADifferentFlagIsAFlagChange(): void
    {
        $diff = $this->differ->diff(
            [new FieldSpec('color', 'string', facet: true)],
            $this->schema([['name' => 'color', 'type' => 'string', 'facet' => false]])
        );

        $this->assertCount(1, $diff->getFlagsChanged());
        $this->assertSame('color', $diff->getFlagsChanged()[0]->getName());
        $this->assertSame([], $diff->getTypeChanged());
    }

    public function testATypeChangeIsNotAlsoReportedAsAFlagChange(): void
    {
        $diff = $this->differ->diff(
            [new FieldSpec('price', 'string', facet: true)],
            $this->schema([['name' => 'price', 'type' => 'float', 'facet' => false]])
        );

        $this->assertCount(1, $diff->getTypeChanged());
        $this->assertSame([], $diff->getFlagsChanged());
    }

    public function testANullFlagLeavesTheEngineDefaultAloneAndIsNoChange(): void
    {
        $diff = $this->differ->diff(
            [new FieldSpec('color', 'string')],
            $this->schema([
                ['name' => 'color', 'type' => 'string', 'facet' => true, 'sort' => false, 'optional' => true],
            ])
        );

        $this->assertTrue($diff->isEmpty());
    }

    public function testEachModelledFlagIsCompared(): void
    {
        $cases = [
            'facet' => new FieldSpec('f', 'string', facet: true),
            'index' => new FieldSpec('f', 'string', index: false),
            'sort' => new FieldSpec('f', 'string', sort: true),
            'optional' => new FieldSpec('f', 'string', optional: true),
        ];

        foreach ($cases as $flag => $desired) {
            $actual = ['name' => 'f', 'type' => 'string', $flag => !$desired->toArray()[$flag]];
            $diff = $this->differ->diff([$desired], $this->schema([$actual]));

            $this->assertCount(1, $diff->getFlagsChanged(), sprintf('flag "%s" is not compared', $flag));
        }
    }

    public function testAnUnmodelledPropertyTheConsumerDeclaresIsCompared(): void
    {
        $diff = $this->differ->diff(
            [new FieldSpec('embedding', 'float[]', extra: ['num_dim' => 768])],
            $this->schema([['name' => 'embedding', 'type' => 'float[]', 'num_dim' => 384]])
        );

        $this->assertCount(1, $diff->getFlagsChanged());
    }

    public function testAnUnmodelledPropertyTheConsumerDoesNotDeclareIsIgnored(): void
    {
        $diff = $this->differ->diff(
            [new FieldSpec('title', 'string')],
            $this->schema([['name' => 'title', 'type' => 'string', 'infix' => true, 'stem' => false]])
        );

        $this->assertTrue($diff->isEmpty());
    }

    public function testEngineDefaultsInsideADeclaredNestedPropertyAreIgnored(): void
    {
        // Typesense echoes hnsw_params back with ef_construction filled in; a strict compare
        // would report a difference forever and re-PATCH the vector field on every reconcile.
        $diff = $this->differ->diff(
            [new FieldSpec('embedding', 'float[]', extra: ['num_dim' => 4, 'hnsw_params' => ['M' => 8]])],
            $this->schema([[
                'name' => 'embedding',
                'type' => 'float[]',
                'num_dim' => 4,
                'hnsw_params' => ['M' => 8, 'ef_construction' => 200],
            ]])
        );

        $this->assertTrue($diff->isEmpty());
    }

    public function testADeclaredNestedPropertyThatActuallyDiffersIsStillCompared(): void
    {
        $diff = $this->differ->diff(
            [new FieldSpec('embedding', 'float[]', extra: ['hnsw_params' => ['M' => 16]])],
            $this->schema([[
                'name' => 'embedding',
                'type' => 'float[]',
                'hnsw_params' => ['M' => 8, 'ef_construction' => 200],
            ]])
        );

        $this->assertCount(1, $diff->getFlagsChanged());
    }

    public function testAShrunkNestedListIsCompared(): void
    {
        // A list is the complete value, not a subset of one.
        $diff = $this->differ->diff(
            [new FieldSpec('title', 'string', extra: ['locales' => ['en']])],
            $this->schema([['name' => 'title', 'type' => 'string', 'locales' => ['en', 'de']]])
        );

        $this->assertCount(1, $diff->getFlagsChanged());
    }

    public function testAllDiffKindsAreReportedTogether(): void
    {
        $diff = $this->differ->diff(
            [
                new FieldSpec('sku', 'string'),
                new FieldSpec('color', 'string', facet: true),
                new FieldSpec('price', 'string'),
                new FieldSpec('brand', 'string'),
            ],
            $this->schema([
                ['name' => 'sku', 'type' => 'string'],
                ['name' => 'color', 'type' => 'string', 'facet' => false],
                ['name' => 'price', 'type' => 'float'],
                ['name' => 'legacy', 'type' => 'int32'],
            ])
        );

        $this->assertSame(['brand'], array_map(static fn ($f): string => $f->getName(), $diff->getAdded()));
        $this->assertSame(['legacy'], array_map(static fn ($f): string => $f->getName(), $diff->getDropped()));
        $this->assertSame(['price'], array_map(static fn ($c): string => $c->getName(), $diff->getTypeChanged()));
        $this->assertSame(['color'], array_map(static fn ($c): string => $c->getName(), $diff->getFlagsChanged()));
    }

    public function testAnEmptyCollectionSchemaMakesEveryFieldAnAddition(): void
    {
        $diff = $this->differ->diff(
            [new FieldSpec('sku', 'string')],
            ['name' => 'products', 'num_documents' => 0]
        );

        $this->assertCount(1, $diff->getAdded());
    }

    public function testAFieldEntryWithoutANameIsSkipped(): void
    {
        $diff = $this->differ->diff(
            [new FieldSpec('sku', 'string')],
            $this->schema([['type' => 'string'], ['name' => 'sku', 'type' => 'string']])
        );

        $this->assertTrue($diff->isEmpty());
    }

    public function testDiffingAnEmptyDesiredSetDropsEverything(): void
    {
        $diff = $this->differ->diff([], $this->schema([['name' => 'sku', 'type' => 'string']]));

        $this->assertCount(1, $diff->getDropped());
    }
}
