<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Integration;

use DmLab\TypesenseCore\Model\Client\ConnectionStatus;
use DmLab\TypesenseCore\Model\Client\HealthChecker;
use DmLab\TypesenseCore\Test\Integration\Doubles\EnvConnectionSettings;
use Psr\Log\NullLogger;

class VerifyConnectionTest extends TypesenseTestCase
{
    /** @var HealthChecker */
    private HealthChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checker = new HealthChecker(
            $this->settings,
            $this->client,
            $this->cache,
            new NullLogger(),
            $this->transport
        );
    }

    public function testTheConfiguredConnectionIsOkAndReportsTheVersion(): void
    {
        $status = $this->checker->verifyConnection();

        $this->assertTrue($status->isOk(), $status->getMessage());
        $this->assertSame(ConnectionStatus::STATE_OK, $status->getState());
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', (string)$status->getVersion());
    }

    public function testAWrongKeyIsUnauthorized(): void
    {
        $status = $this->checker->verifyConnection(
            new EnvConnectionSettings((string)getenv('TYPESENSE_TEST_URL'), 'dmlab-it-wrong-key')
        );

        $this->assertSame(ConnectionStatus::STATE_UNAUTHORIZED, $status->getState(), $status->getMessage());
    }

    public function testAClosedPortIsUnreachable(): void
    {
        $status = $this->checker->verifyConnection(
            new EnvConnectionSettings('http://127.0.0.1:1', $this->settings->getApiKey())
        );

        $this->assertSame(ConnectionStatus::STATE_UNREACHABLE, $status->getState());
        $this->assertStringContainsString('127.0.0.1:1', $status->getMessage());
    }
}
