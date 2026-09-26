<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit\Model\Client;

use DmLab\TypesenseCore\Exception\TransportException;
use DmLab\TypesenseCore\Model\Client\HealthChecker;
use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// The logger double is built once in setUp(); only the failure cases assert on it.
#[AllowMockObjectsWithoutExpectations]
class HealthCheckerTest extends TestCase
{
    private const CACHE_KEY = 'dmlab_typesense_health';

    /** @var ConnectionSettingsInterface|MockObject */
    private MockObject $config;

    /** @var TypesenseClient|MockObject */
    private MockObject $client;

    /** @var CacheInterface|MockObject */
    private MockObject $cache;

    /** @var LoggerInterface|MockObject */
    private MockObject $logger;

    /** @var HealthChecker */
    private HealthChecker $checker;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConnectionSettingsInterface::class);
        $this->client = $this->createMock(TypesenseClient::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->config->method('getHealthCacheTtl')->willReturn(30);

        $this->checker = new HealthChecker($this->config, $this->client, $this->cache, $this->logger);
    }

    public function testHealthyResponseIsTrueAndUsesAShortTimeout(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->client->expects($this->once())
            ->method('request')
            ->with('GET', '/health', null, [], 2)
            ->willReturn(['ok' => true]);

        $this->assertTrue($this->checker->isHealthy());
    }

    public function testResultIsCachedWithTheConfiguredTtl(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->client->method('request')->willReturn(['ok' => true]);
        $this->cache->expects($this->once())
            ->method('save')
            ->with('1', self::CACHE_KEY, ['DMLAB_TYPESENSE'], 30);

        $this->checker->isHealthy();
    }

    public function testRepeatedCallsHitTheCacheAndTheTransportOnlyOnce(): void
    {
        $stored = null;
        $this->cache->method('load')->willReturnCallback(
            static function () use (&$stored): string|bool {
                return $stored ?? false;
            }
        );
        $this->cache->method('save')->willReturnCallback(
            function (string $data) use (&$stored): bool {
                $stored = $data;

                return true;
            }
        );

        $this->client->expects($this->once())->method('request')->willReturn(['ok' => true]);

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->checker->isHealthy());
        }
    }

    public function testCachedNegativeIsReturnedWithoutATransportCall(): void
    {
        $this->cache->method('load')->willReturn('0');
        $this->client->expects($this->never())->method('request');

        $this->assertFalse($this->checker->isHealthy());
    }

    public function testTtlExpiryTriggersAFreshCheck(): void
    {
        // Cache hits, then one expiry (load() false), then hits again: one live call in five invocations.
        $loads = ['1', '1', false, '1', '1'];
        $this->cache->method('load')->willReturnCallback(
            static function () use (&$loads): string|bool {
                return array_shift($loads);
            }
        );
        $this->client->expects($this->once())->method('request')->willReturn(['ok' => true]);

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->checker->isHealthy());
        }
    }

    public function testTransportFailureIsFalseNotAnException(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->client->method('request')->willThrowException(new TransportException('Connection refused'));
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Connection refused'));

        $this->assertFalse($this->checker->isHealthy());
    }

    public function testFailureIsCachedSoAnOutageDoesNotBecomeAPingStorm(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->client->method('request')->willThrowException(new TransportException('Connection refused'));
        $this->cache->expects($this->once())
            ->method('save')
            ->with('0', self::CACHE_KEY, ['DMLAB_TYPESENSE'], 30);

        $this->assertFalse($this->checker->isHealthy());
    }

    public function testNotOkResponseIsFalse(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->client->method('request')->willReturn(['ok' => false]);

        $this->assertFalse($this->checker->isHealthy());
    }

    public function testZeroTtlDisablesCachingAndPingsEveryTime(): void
    {
        $config = $this->createMock(ConnectionSettingsInterface::class);
        $config->method('getHealthCacheTtl')->willReturn(0);
        $checker = new HealthChecker($config, $this->client, $this->cache, $this->logger);

        $this->cache->expects($this->never())->method('load');
        $this->cache->expects($this->never())->method('save');
        $this->client->expects($this->exactly(2))->method('request')->willReturn(['ok' => true]);

        $this->assertTrue($checker->isHealthy());
        $this->assertTrue($checker->isHealthy());
    }

    public function testBrokenConfigIsFalseNotAnException(): void
    {
        $config = $this->createMock(ConnectionSettingsInterface::class);
        $config->method('getHealthCacheTtl')->willReturn(0);
        $checker = new HealthChecker($config, $this->client, $this->cache, $this->logger);

        $this->client->method('request')
            ->willThrowException(new \DmLab\TypesenseCore\Exception\ConfigurationException('No key'));

        $this->assertFalse($checker->isHealthy());
    }

    public function testAThrowingCacheReadFallsBackToALivePing(): void
    {
        $this->cache->method('load')->willThrowException(new \RuntimeException('Valkey is down'));
        $this->client->method('request')->willReturn(['ok' => true]);

        $this->assertTrue($this->checker->isHealthy());
    }

    public function testAThrowingCacheWriteStillReturnsTheVerdict(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->cache->method('save')->willThrowException(new \RuntimeException('Valkey is down'));
        $this->client->method('request')->willReturn(['ok' => true]);

        $this->assertTrue($this->checker->isHealthy());
    }
}
