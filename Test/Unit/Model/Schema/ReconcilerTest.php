<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit\Model\Schema;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Collection\CollectionManager;
use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseCore\Model\Schema\Decision;
use DmLab\TypesenseCore\Model\Schema\Reconciler;
use DmLab\TypesenseCore\Model\Schema\ReconcilePolicy;
use DmLab\TypesenseCore\Model\Schema\SchemaDiffer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ReconcilerTest extends TestCase
{
    /** @var CollectionManager|MockObject */
    private MockObject $collections;

    /** @var ReconcilePolicy the default policy the consumer hands in: automatic decision, 500k threshold */
    private ReconcilePolicy $policy;

    /** @var Reconciler */
    private Reconciler $reconciler;

    protected function setUp(): void
    {
        $this->collections = $this->createMock(CollectionManager::class);
        $this->policy = new ReconcilePolicy(500000, ReconcilePolicy::AUTO);

        $this->reconciler = new Reconciler(new SchemaDiffer(), $this->collections);
    }

    /**
     * Make `get()` return a collection schema.
     *
     * @param array<int,array<string,mixed>> $fields
     * @param int $documents
     */
    private function collectionHolds(array $fields, int $documents = 0): void
    {
        $this->collections->method('get')->willReturn([
            'name' => 'products',
            'num_documents' => $documents,
            'fields' => $fields,
        ]);
    }

    public function testAnUnchangedSchemaIsInPlaceAndWritesNothing(): void
    {
        $this->collectionHolds([['name' => 'sku', 'type' => 'string']]);
        $this->collections->expects($this->never())->method('patch');

        $decision = $this->reconciler->reconcile('products', [new FieldSpec('sku', 'string')], $this->policy);

        $this->assertTrue($decision->isInPlace());
        $this->assertTrue($decision->getDiff()->isEmpty());
        $this->assertNull($decision->getSchema());
        $this->assertNull($decision->getReason());
    }

    public function testBelowTheThresholdTheChangeIsPatchedInPlace(): void
    {
        $this->collectionHolds([['name' => 'sku', 'type' => 'string']], 499999);

        $this->collections->expects($this->once())
            ->method('patch')
            ->with(
                'products',
                $this->callback(static function (array $fields): bool {
                    return count($fields) === 1
                        && $fields[0]->toArray() === ['name' => 'color', 'type' => 'string', 'facet' => true];
                })
            )
            ->willReturn(['name' => 'products', 'fields' => []]);

        $decision = $this->reconciler->reconcile(
            'products',
            [new FieldSpec('sku', 'string'), new FieldSpec('color', 'string', facet: true)],
            $this->policy
        );

        $this->assertSame(Decision::OUTCOME_IN_PLACE, $decision->getOutcome());
        $this->assertSame(['name' => 'products', 'fields' => []], $decision->getSchema());
    }

    public function testAboveTheThresholdItNeedsARebuildAndNothingIsWritten(): void
    {
        $this->collectionHolds([['name' => 'sku', 'type' => 'string']], 500001);
        $this->collections->expects($this->never())->method('patch');

        $decision = $this->reconciler->reconcile(
            'products',
            [new FieldSpec('sku', 'string'), new FieldSpec('color', 'string', facet: true)],
            $this->policy
        );

        $this->assertTrue($decision->needsRebuild());
        $this->assertNull($decision->getSchema());
        $this->assertStringContainsString('500001 documents', (string)$decision->getReason());
        // The diff still travels with the decision — the consumer rebuilds with it.
        $this->assertCount(1, $decision->getDiff()->getAdded());
    }

    public function testTheThresholdIsAnUpperBoundNotAFloor(): void
    {
        $this->collectionHolds([], 500000);
        $this->collections->expects($this->once())->method('patch')->willReturn([]);

        $this->assertTrue(
            $this->reconciler->reconcile('products', [new FieldSpec('sku', 'string')], $this->policy)->isInPlace()
        );
    }

    public function testAZeroThresholdDisablesTheSizeRule(): void
    {
        $policy = new ReconcilePolicy(0, ReconcilePolicy::AUTO);

        $this->collectionHolds([], 10000000);
        $this->collections->expects($this->once())->method('patch')->willReturn([]);

        $this->assertTrue(
            $this->reconciler->reconcile('products', [new FieldSpec('sku', 'string')], $policy)->isInPlace()
        );
    }

    public function testAnIncompatibleTypeChangeNeedsARebuildWithAReason(): void
    {
        $this->collectionHolds([['name' => 'price', 'type' => 'string']]);
        $this->collections->expects($this->never())->method('patch');

        $decision = $this->reconciler->reconcile('products', [new FieldSpec('price', 'float')], $this->policy);

        $this->assertTrue($decision->needsRebuild());
        $this->assertNull($decision->getSchema());
        $this->assertStringContainsString('"price"', (string)$decision->getReason());
        $this->assertStringContainsString('"string" to "float"', (string)$decision->getReason());
    }

    /**
     * @param string $from
     * @param string $to
     */
    #[DataProvider('coercibleTypeChanges')]
    public function testACoercibleTypeChangeIsPatchedInPlace(string $from, string $to): void
    {
        $this->collectionHolds([['name' => 'f', 'type' => $from]]);
        $this->collections->expects($this->once())->method('patch')->willReturn([]);

        $this->assertTrue(
            $this->reconciler->reconcile('products', [new FieldSpec('f', $to)], $this->policy)->isInPlace()
        );
    }

    /**
     * @return array<string,array{string,string}>
     */
    public static function coercibleTypeChanges(): array
    {
        return [
            'float to string' => ['float', 'string'],
            'int64 to string' => ['int64', 'string'],
            'bool to string' => ['bool', 'string'],
            'geopoint to auto' => ['geopoint', 'auto'],
            'anything to auto' => ['int32', 'auto'],
            'int32 widens to int64' => ['int32', 'int64'],
            'int64 widens to float' => ['int64', 'float'],
            'array element widens' => ['int32[]', 'string[]'],
        ];
    }

    /**
     * @param string $from
     * @param string $to
     */
    #[DataProvider('incompatibleTypeChanges')]
    public function testAnIncompatibleTypeChangeIsRefused(string $from, string $to): void
    {
        $this->collectionHolds([['name' => 'f', 'type' => $from]]);
        $this->collections->expects($this->never())->method('patch');

        $this->assertTrue(
            $this->reconciler->reconcile('products', [new FieldSpec('f', $to)], $this->policy)->needsRebuild()
        );
    }

    /**
     * @return array<string,array{string,string}>
     */
    public static function incompatibleTypeChanges(): array
    {
        return [
            'string narrows to int64' => ['string', 'int64'],
            'float narrows to int32' => ['float', 'int32'],
            'string to bool' => ['string', 'bool'],
            'scalar to array' => ['string', 'string[]'],
            'array to scalar' => ['string[]', 'string'],
            // No scalar rendering: the rebuild would reject the value and lose the document.
            'geopoint to string' => ['geopoint', 'string'],
            'object to string' => ['object', 'string'],
            'geopoint[] to string[]' => ['geopoint[]', 'string[]'],
        ];
    }

    public function testAnIncompatibleTypeChangeIsRefusedEvenWhenAnotherFieldIsFine(): void
    {
        $this->collectionHolds([
            ['name' => 'price', 'type' => 'float'],
            ['name' => 'qty', 'type' => 'string'],
        ]);
        $this->collections->expects($this->never())->method('patch');

        $decision = $this->reconciler->reconcile(
            'products',
            [new FieldSpec('price', 'string'), new FieldSpec('qty', 'int32')],
            $this->policy
        );

        $this->assertTrue($decision->needsRebuild());
        $this->assertStringContainsString('"qty"', (string)$decision->getReason());
    }

    public function testAForcedRebuildIsHonouredBelowTheThreshold(): void
    {
        $policy = new ReconcilePolicy(500000, ReconcilePolicy::REBUILD);

        $this->collectionHolds([], 1);
        $this->collections->expects($this->never())->method('patch');

        $decision = $this->reconciler->reconcile('products', [new FieldSpec('sku', 'string')], $policy);

        $this->assertTrue($decision->needsRebuild());
        $this->assertSame('A rebuild is forced by configuration.', $decision->getReason());
    }

    public function testAForcedInPlaceOverridesBothTheThresholdAndTypeCompatibility(): void
    {
        $policy = new ReconcilePolicy(10, ReconcilePolicy::IN_PLACE);

        $this->collectionHolds([['name' => 'price', 'type' => 'string']], 999999);
        $this->collections->expects($this->once())->method('patch')->willReturn(['name' => 'products']);

        $decision = $this->reconciler->reconcile('products', [new FieldSpec('price', 'float')], $policy);

        $this->assertTrue($decision->isInPlace());
        $this->assertSame(['name' => 'products'], $decision->getSchema());
    }

    public function testAForcedRebuildStillDoesNothingWhenThereIsNoChange(): void
    {
        $policy = new ReconcilePolicy(0, ReconcilePolicy::REBUILD);

        $this->collectionHolds([['name' => 'sku', 'type' => 'string']]);
        $this->collections->expects($this->never())->method('patch');

        $decision = $this->reconciler->reconcile('products', [new FieldSpec('sku', 'string')], $policy);

        $this->assertTrue($decision->isInPlace());
    }

    public function testAMissingCollectionSurfaces(): void
    {
        $this->collections->method('get')->willThrowException(new TypesenseException('Not found.', 404));

        $this->expectException(TypesenseException::class);
        $this->expectExceptionCode(404);

        $this->reconciler->reconcile('missing', [new FieldSpec('sku', 'string')], $this->policy);
    }

    public function testACombinedDiffIsAppliedAsOneSinglePatch(): void
    {
        $this->collectionHolds([
            ['name' => 'price', 'type' => 'float'],
            ['name' => 'legacy', 'type' => 'int32'],
        ]);

        $payload = null;
        $this->collections->expects($this->once())
            ->method('patch')
            ->willReturnCallback(function (string $name, array $fields) use (&$payload): array {
                $payload = array_map(static fn (FieldSpec $f): array => $f->toArray(), $fields);

                return [];
            });

        $this->reconciler->reconcile(
            'products',
            [new FieldSpec('price', 'string'), new FieldSpec('brand', 'string')],
            $this->policy
        );

        $this->assertSame(
            [
                ['name' => 'legacy', 'drop' => true],
                ['name' => 'price', 'drop' => true],
                ['name' => 'price', 'type' => 'string'],
                ['name' => 'brand', 'type' => 'string'],
            ],
            $payload
        );
    }
}
