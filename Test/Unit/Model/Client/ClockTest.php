<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit\Model\Client;

use DmLab\TypesenseCore\Model\Client\Clock;
use PHPUnit\Framework\TestCase;

class ClockTest extends TestCase
{
    public function testNowIsCurrentTime(): void
    {
        $this->assertEqualsWithDelta(microtime(true), (new Clock())->now(), 1.0);
    }

    public function testSleepWaitsAtLeastTheGivenTime(): void
    {
        $clock = new Clock();
        $start = microtime(true);
        $clock->sleep(2000);

        $this->assertGreaterThanOrEqual(0.0015, microtime(true) - $start);
    }

    public function testNonPositiveSleepReturnsImmediately(): void
    {
        $clock = new Clock();
        $start = microtime(true);
        $clock->sleep(0);
        $clock->sleep(-5);

        $this->assertLessThan(0.5, microtime(true) - $start);
    }
}
