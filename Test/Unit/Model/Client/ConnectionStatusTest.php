<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit\Model\Client;

use DmLab\TypesenseCore\Model\Client\ConnectionStatus;
use PHPUnit\Framework\TestCase;

class ConnectionStatusTest extends TestCase
{
    public function testOk(): void
    {
        $status = ConnectionStatus::ok('30.2', 'Connected');

        $this->assertTrue($status->isOk());
        $this->assertSame(ConnectionStatus::STATE_OK, $status->getState());
        $this->assertSame('Connected', $status->getMessage());
        $this->assertSame('30.2', $status->getVersion());
    }

    public function testUnreachable(): void
    {
        $status = ConnectionStatus::unreachable('Down');

        $this->assertFalse($status->isOk());
        $this->assertSame(ConnectionStatus::STATE_UNREACHABLE, $status->getState());
        $this->assertSame('Down', $status->getMessage());
        $this->assertNull($status->getVersion());
    }

    public function testUnauthorized(): void
    {
        $status = ConnectionStatus::unauthorized('Rejected');

        $this->assertFalse($status->isOk());
        $this->assertSame(ConnectionStatus::STATE_UNAUTHORIZED, $status->getState());
        $this->assertSame('Rejected', $status->getMessage());
        $this->assertNull($status->getVersion());
    }

    public function testIsNotFinalSoConsumersCanDoubleIt(): void
    {
        $this->assertFalse((new \ReflectionClass(ConnectionStatus::class))->isFinal());

        $stub = $this->createStub(ConnectionStatus::class);
        $stub->method('isOk')->willReturn(true);
        $this->assertTrue($stub->isOk());
    }
}
