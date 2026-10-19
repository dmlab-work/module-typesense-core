<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit\Model\Schema;

use DmLab\TypesenseCore\Model\Schema\ReconcilePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReconcilePolicyTest extends TestCase
{
    public function testDefaults(): void
    {
        $policy = new ReconcilePolicy();

        $this->assertSame(0, $policy->getRebuildThreshold());
        $this->assertSame(ReconcilePolicy::AUTO, $policy->getDecisionOverride());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validOverrides(): array
    {
        return [
            'auto' => [ReconcilePolicy::AUTO],
            'in place' => [ReconcilePolicy::IN_PLACE],
            'rebuild' => [ReconcilePolicy::REBUILD],
        ];
    }

    #[DataProvider('validOverrides')]
    public function testAcceptsEachValidOverride(string $override): void
    {
        $policy = new ReconcilePolicy(500000, $override);

        $this->assertSame(500000, $policy->getRebuildThreshold());
        $this->assertSame($override, $policy->getDecisionOverride());
    }

    public function testRejectsUnknownOverride(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"inplace"');

        new ReconcilePolicy(0, 'inplace');
    }

    public function testRejectsNegativeThreshold(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('-1');

        new ReconcilePolicy(-1);
    }
}
