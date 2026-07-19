<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseCore\Test\Unit\Model\Collection;

use MageDevGroup\TypesenseCore\Model\Collection\FieldSpec;
use PHPUnit\Framework\TestCase;

class FieldSpecTest extends TestCase
{
    public function testOnlyNameAndTypeAreEmittedWhenNoFlagIsSet(): void
    {
        $spec = new FieldSpec('sku', 'string');

        $this->assertSame(['name' => 'sku', 'type' => 'string'], $spec->toArray());
    }

    public function testSetFlagsAreEmittedIncludingFalseOnes(): void
    {
        $spec = new FieldSpec('price', 'float', facet: false, index: true, sort: true, optional: false);

        $this->assertSame(
            [
                'name' => 'price',
                'type' => 'float',
                'facet' => false,
                'index' => true,
                'sort' => true,
                'optional' => false,
            ],
            $spec->toArray()
        );
    }

    public function testExtraPropertiesAreCarriedIntoThePayloadVerbatim(): void
    {
        $spec = new FieldSpec('embedding', 'float[]', extra: [
            'num_dim' => 384,
            'vec_dist' => 'cosine',
            'embed' => ['from' => ['name'], 'model_config' => ['model_name' => 'ts/e5-small']],
        ]);

        $this->assertSame(
            [
                'name' => 'embedding',
                'type' => 'float[]',
                'num_dim' => 384,
                'vec_dist' => 'cosine',
                'embed' => ['from' => ['name'], 'model_config' => ['model_name' => 'ts/e5-small']],
            ],
            $spec->toArray()
        );
    }

    public function testUnknownPropertiesSurviveAFromArrayToArrayRoundTrip(): void
    {
        // The extensibility invariant: typesense-semantic declares fields core never modelled.
        $schema = [
            'name' => 'embedding',
            'type' => 'float[]',
            'facet' => false,
            'optional' => true,
            'num_dim' => 384,
            'hnsw_params' => ['M' => 16, 'ef_construction' => 200],
            'range_index' => false,
            'stem' => true,
        ];

        $roundTripped = FieldSpec::fromArray($schema)->toArray();

        $this->assertSame($schema, $roundTripped);
    }

    public function testFromArrayPutsUnmodelledKeysInExtra(): void
    {
        $spec = FieldSpec::fromArray([
            'name' => 'title',
            'type' => 'string',
            'sort' => true,
            'infix' => true,
        ]);

        $this->assertSame('title', $spec->getName());
        $this->assertSame('string', $spec->getType());
        $this->assertTrue($spec->getSort());
        $this->assertNull($spec->getFacet());
        $this->assertNull($spec->getIndex());
        $this->assertNull($spec->getOptional());
        $this->assertSame(['infix' => true], $spec->getExtra());
    }

    public function testDropCarriesNoType(): void
    {
        $this->assertSame(['name' => 'old_field', 'drop' => true], FieldSpec::drop('old_field')->toArray());
    }

    public function testAccessorsExposeTheModelledProperties(): void
    {
        $spec = new FieldSpec(
            'qty',
            'int64',
            facet: true,
            index: false,
            sort: false,
            optional: true,
            extra: ['x' => 1]
        );

        $this->assertSame('qty', $spec->getName());
        $this->assertSame('int64', $spec->getType());
        $this->assertTrue($spec->getFacet());
        $this->assertFalse($spec->getIndex());
        $this->assertFalse($spec->getSort());
        $this->assertTrue($spec->getOptional());
        $this->assertSame(['x' => 1], $spec->getExtra());
    }

    public function testOverridingKeepsWhatTheOverrideLeavesUndeclared(): void
    {
        $actual = new FieldSpec('sku', 'string', facet: false, index: true, sort: true, extra: ['infix' => true]);

        $merged = $actual->withOverridesFrom(new FieldSpec('sku', 'string', facet: true));

        $this->assertTrue($merged->getFacet());
        $this->assertTrue($merged->getIndex());
        $this->assertTrue($merged->getSort());
        $this->assertSame(['infix' => true], $merged->getExtra());
    }

    public function testOverridingTakesTheOverridesValueWhereBothDeclareOne(): void
    {
        $actual = new FieldSpec('price', 'float', facet: true, optional: true, extra: ['range_index' => false]);

        $merged = $actual->withOverridesFrom(
            new FieldSpec('price', 'string', facet: false, optional: false, extra: ['range_index' => true])
        );

        $this->assertSame('string', $merged->getType());
        $this->assertFalse($merged->getFacet());
        $this->assertFalse($merged->getOptional());
        $this->assertSame(['range_index' => true], $merged->getExtra());
    }

    public function testOverridingANestedExtraKeepsTheUndeclaredSiblings(): void
    {
        $actual = new FieldSpec(
            'embedding',
            'float[]',
            extra: ['hnsw_params' => ['M' => 8, 'ef_construction' => 200]]
        );

        $merged = $actual->withOverridesFrom(
            new FieldSpec('embedding', 'float[]', extra: ['hnsw_params' => ['M' => 16]])
        );

        $this->assertSame(
            ['hnsw_params' => ['M' => 16, 'ef_construction' => 200]],
            $merged->getExtra()
        );
    }

    public function testOverridingAListExtraReplacesItWhole(): void
    {
        $actual = new FieldSpec('name', 'string', extra: ['locales' => ['en', 'de']]);

        $merged = $actual->withOverridesFrom(new FieldSpec('name', 'string', extra: ['locales' => ['fr']]));

        $this->assertSame(['locales' => ['fr']], $merged->getExtra());
    }

    public function testOverridingIsNonDestructiveOnTheReceiver(): void
    {
        $actual = new FieldSpec('sku', 'string', facet: false);

        $actual->withOverridesFrom(new FieldSpec('sku', 'string', facet: true));

        $this->assertFalse($actual->getFacet());
    }

    public function testAnOverrideWithoutATypeKeepsTheReceiversType(): void
    {
        $merged = (new FieldSpec('sku', 'string'))->withOverridesFrom(new FieldSpec('sku', ''));

        $this->assertSame('string', $merged->getType());
    }
}
