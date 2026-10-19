<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit\Model\Client;

use DmLab\TypesenseCore\Exception\ConfigurationException;
use DmLab\TypesenseCore\Exception\TransportException;
use DmLab\TypesenseCore\Model\Client\ConnectionStatus;
use DmLab\TypesenseCore\Model\Client\HealthChecker;
use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Model\Client\Http\Response;
use DmLab\TypesenseCore\Model\Client\Http\TransportInterface;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseCore\Model\Config\Node;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @var TransportInterface|MockObject */
    private MockObject $transport;

    /** @var HealthChecker */
    private HealthChecker $checker;

    /** @var string[] URLs the routed transport was called with, in order */
    private array $sentUrls = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConnectionSettingsInterface::class);
        $this->client = $this->createMock(TypesenseClient::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->transport = $this->createMock(TransportInterface::class);

        $this->config->method('getHealthCacheTtl')->willReturn(30);

        $this->checker = new HealthChecker(
            $this->config,
            $this->client,
            $this->cache,
            $this->logger,
            $this->transport
        );
    }

    public function testHealthyResponseIsTrueAndUsesAShortTimeout(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->client->expects($this->once())
            ->method('request')
            ->with('GET', '/health', null, [], 2, 1)
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
        $checker = new HealthChecker($config, $this->client, $this->cache, $this->logger, $this->transport);

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
        $checker = new HealthChecker($config, $this->client, $this->cache, $this->logger, $this->transport);

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

    public function testVerifyConnectionIsOkWithTheReportedVersion(): void
    {
        $this->configureNodes(['http://a:8108']);
        $this->config->method('getApiKey')->willReturn('secret');
        $this->routeTransport(['http://a:8108/health' => new Response(200, '{"ok":true}')]);

        $status = $this->checker->verifyConnection();

        $this->assertTrue($status->isOk());
        $this->assertSame(ConnectionStatus::STATE_OK, $status->getState());
        $this->assertSame('30.2', $status->getVersion());
        $this->assertSame('Connected to Typesense 30.2.', $status->getMessage());
    }

    public function testVerifyConnectionReportsAnUnknownVersionWhenDebugOmitsIt(): void
    {
        $this->configureNodes(['http://a:8108']);
        $this->config->method('getApiKey')->willReturn('secret');
        $this->routeTransport([
            'http://a:8108/health' => new Response(200, '{"ok":true}'),
            'http://a:8108/debug' => new Response(200, '{"state":1,"version":7}'),
        ]);

        $this->assertSame('unknown', $this->checker->verifyConnection()->getVersion());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function rejectedKeyStatuses(): array
    {
        return ['401' => [401], '403' => [403]];
    }

    #[DataProvider('rejectedKeyStatuses')]
    public function testVerifyConnectionFlagsARejectedKey(int $status): void
    {
        $this->configureNodes(['http://a:8108']);
        $this->config->method('getApiKey')->willReturn('wrong');
        $this->routeTransport([
            'http://a:8108/health' => new Response(200, '{"ok":true}'),
            'http://a:8108/debug' => new Response($status, '{"message":"Forbidden"}'),
        ]);

        $result = $this->checker->verifyConnection();

        $this->assertFalse($result->isOk());
        $this->assertSame(ConnectionStatus::STATE_UNAUTHORIZED, $result->getState());
        $this->assertSame('Typesense rejected the API key (HTTP ' . $status . ').', $result->getMessage());
        $this->assertNull($result->getVersion());
    }

    public function testVerifyConnectionWithoutAKeyIsUnauthorizedAndSkipsDebug(): void
    {
        $this->configureNodes(['http://a:8108']);
        $this->config->method('getApiKey')->willThrowException(new ConfigurationException('API key is not set'));
        $this->routeTransport(['http://a:8108/health' => new Response(200, '{"ok":true}')]);

        $result = $this->checker->verifyConnection();

        $this->assertSame(ConnectionStatus::STATE_UNAUTHORIZED, $result->getState());
        $this->assertSame('Typesense API key is not configured.', $result->getMessage());
        $this->assertSame(['http://a:8108/health'], $this->sentUrls);
    }

    public function testVerifyConnectionWithBrokenNodesConfigIsUnreachable(): void
    {
        $this->config->method('getNodes')->willThrowException(new ConfigurationException('No node configured'));
        $this->transport->expects($this->never())->method('send');

        $result = $this->checker->verifyConnection();

        $this->assertSame(ConnectionStatus::STATE_UNREACHABLE, $result->getState());
        $this->assertSame('Typesense connection is not configured: No node configured', $result->getMessage());
    }

    public function testVerifyConnectionWithEveryNodeDownListsEachNode(): void
    {
        $this->configureNodes(['http://a:8108', 'http://b:8108', 'http://c:8108']);
        $this->config->method('getApiKey')->willReturn('secret');
        $this->routeTransport([
            'http://a:8108/health' => new TransportException('Connection refused'),
            'http://b:8108/health' => new Response(503, '{"ok":false}'),
            'http://c:8108/health' => new Response(200, '{"ok":false}'),
        ]);

        $result = $this->checker->verifyConnection();

        $this->assertSame(ConnectionStatus::STATE_UNREACHABLE, $result->getState());
        $this->assertSame(
            'No Typesense node answered: http://a:8108: Connection refused; http://b:8108: HTTP 503; '
            . 'http://c:8108: unhealthy',
            $result->getMessage()
        );
        $this->assertNotContains('http://a:8108/debug', $this->sentUrls);
    }

    public function testVerifyConnectionUsesTheFirstHealthyNodeAndNamesTheDownOnes(): void
    {
        $this->configureNodes(['http://a:8108', 'http://b:8108']);
        $this->config->method('getApiKey')->willReturn('secret');
        $this->routeTransport([
            'http://a:8108/health' => new TransportException('Connection timed out'),
            'http://b:8108/health' => new Response(200, '{"ok":true}'),
        ]);

        $result = $this->checker->verifyConnection();

        $this->assertTrue($result->isOk());
        $this->assertSame(
            'Connected to Typesense 30.2. Unreachable nodes: http://a:8108: Connection timed out',
            $result->getMessage()
        );
        $this->assertSame(
            ['http://a:8108/health', 'http://b:8108/health', 'http://b:8108/debug'],
            $this->sentUrls
        );
    }

    public function testVerifyConnectionSendsTheKeyToDebugOnly(): void
    {
        $this->configureNodes(['http://a:8108']);
        $this->config->method('getApiKey')->willReturn('secret');

        $calls = [];
        $this->transport->method('send')->willReturnCallback(
            function (string $method, string $url, array $headers, ?string $body, float $timeout) use (&$calls) {
                $calls[$url] = [$method, $headers, $body, $timeout];

                return str_ends_with($url, '/health')
                    ? new Response(200, '{"ok":true}')
                    : new Response(200, '{"state":1,"version":"30.2"}');
            }
        );

        $this->checker->verifyConnection();

        $this->assertSame(['GET', ['Accept' => 'application/json'], null, 3.0], $calls['http://a:8108/health']);
        $this->assertSame(
            ['GET', ['X-TYPESENSE-API-KEY' => 'secret', 'Accept' => 'application/json'], null, 3.0],
            $calls['http://a:8108/debug']
        );
    }

    public function testVerifyConnectionPrefersExplicitSettings(): void
    {
        $this->config->expects($this->never())->method('getNodes');
        $this->config->expects($this->never())->method('getApiKey');

        $explicit = $this->createStub(ConnectionSettingsInterface::class);
        $explicit->method('getNodes')->willReturn([new Node('other', 9108)]);
        $explicit->method('getApiKey')->willReturn('explicit');

        $keys = [];
        $this->transport->method('send')->willReturnCallback(
            function (string $method, string $url, array $headers) use (&$keys) {
                $keys[$url] = $headers['X-TYPESENSE-API-KEY'] ?? null;

                return str_ends_with($url, '/health')
                    ? new Response(200, '{"ok":true}')
                    : new Response(200, '{"version":"30.2"}');
            }
        );

        $this->assertTrue($this->checker->verifyConnection($explicit)->isOk());
        $this->assertSame(['http://other:9108/health' => null, 'http://other:9108/debug' => 'explicit'], $keys);
    }

    public function testVerifyConnectionNeverTouchesTheCacheOrTheClient(): void
    {
        $this->configureNodes(['http://a:8108']);
        $this->config->method('getApiKey')->willReturn('secret');
        $this->routeTransport(['http://a:8108/health' => new Response(200, '{"ok":true}')]);

        $this->cache->expects($this->never())->method('load');
        $this->cache->expects($this->never())->method('save');
        $this->client->expects($this->never())->method('request');

        $this->checker->verifyConnection();
        $this->checker->verifyConnection();
    }

    public function testVerifyConnectionReportsADebugServerErrorAsUnreachable(): void
    {
        $this->configureNodes(['http://a:8108']);
        $this->config->method('getApiKey')->willReturn('secret');
        $this->routeTransport([
            'http://a:8108/health' => new Response(200, '{"ok":true}'),
            'http://a:8108/debug' => new Response(500, 'boom'),
        ]);

        $result = $this->checker->verifyConnection();

        $this->assertSame(ConnectionStatus::STATE_UNREACHABLE, $result->getState());
        $this->assertSame('Typesense answered /debug with an error: http://a:8108: HTTP 500', $result->getMessage());
    }

    public function testVerifyConnectionReportsADebugTransportFailureAsUnreachable(): void
    {
        $this->configureNodes(['http://a:8108']);
        $this->config->method('getApiKey')->willReturn('secret');
        $this->routeTransport([
            'http://a:8108/health' => new Response(200, '{"ok":true}'),
            'http://a:8108/debug' => new TransportException('Operation timed out'),
        ]);

        $result = $this->checker->verifyConnection();

        $this->assertSame(ConnectionStatus::STATE_UNREACHABLE, $result->getState());
        $this->assertSame(
            'Typesense answered /debug with an error: http://a:8108: Operation timed out',
            $result->getMessage()
        );
    }

    public function testVerifyConnectionTriesTheNextHealthyNodeWhenDebugFails(): void
    {
        $this->configureNodes(['http://a:8108', 'http://b:8108']);
        $this->config->method('getApiKey')->willReturn('secret');
        $this->routeTransport([
            'http://a:8108/health' => new Response(200, '{"ok":true}'),
            'http://b:8108/health' => new Response(200, '{"ok":true}'),
            'http://a:8108/debug' => new TransportException('Operation timed out'),
        ]);

        $result = $this->checker->verifyConnection();

        $this->assertTrue($result->isOk());
        $this->assertSame(
            'Connected to Typesense 30.2. Unreachable nodes: http://a:8108: Operation timed out',
            $result->getMessage()
        );
        $this->assertSame(
            ['http://a:8108/health', 'http://b:8108/health', 'http://a:8108/debug', 'http://b:8108/debug'],
            $this->sentUrls
        );
    }

    public function testVerifyConnectionIsOkWhenAnyHealthyNodeAcceptsTheKey(): void
    {
        $this->configureNodes(['http://a:8108', 'http://b:8108']);
        $this->config->method('getApiKey')->willReturn('secret');
        $this->routeTransport([
            'http://a:8108/health' => new Response(200, '{"ok":true}'),
            'http://b:8108/health' => new Response(200, '{"ok":true}'),
            'http://a:8108/debug' => new Response(401, '{"message":"Forbidden"}'),
        ]);

        $result = $this->checker->verifyConnection();

        $this->assertTrue($result->isOk());
        $this->assertSame('30.2', $result->getVersion());
    }

    public function testVerifyConnectionIsUnauthorizedWhenARejectionMeetsNoSuccess(): void
    {
        $this->configureNodes(['http://a:8108', 'http://b:8108']);
        $this->config->method('getApiKey')->willReturn('wrong');
        $this->routeTransport([
            'http://a:8108/health' => new Response(200, '{"ok":true}'),
            'http://b:8108/health' => new Response(200, '{"ok":true}'),
            'http://a:8108/debug' => new Response(403, '{"message":"Forbidden"}'),
            'http://b:8108/debug' => new Response(500, 'boom'),
        ]);

        $result = $this->checker->verifyConnection();

        $this->assertSame(ConnectionStatus::STATE_UNAUTHORIZED, $result->getState());
        $this->assertSame('Typesense rejected the API key (HTTP 403).', $result->getMessage());
    }

    public function testVerifyConnectionIsUnreachableWhenNoHealthyNodeAnswersDebug(): void
    {
        $this->configureNodes(['http://a:8108', 'http://b:8108', 'http://c:8108']);
        $this->config->method('getApiKey')->willReturn('secret');
        $this->routeTransport([
            'http://a:8108/health' => new Response(200, '{"ok":true}'),
            'http://b:8108/health' => new Response(200, '{"ok":true}'),
            'http://c:8108/health' => new TransportException('Connection refused'),
            'http://a:8108/debug' => new TransportException('Operation timed out'),
            'http://b:8108/debug' => new Response(500, 'boom'),
        ]);

        $result = $this->checker->verifyConnection();

        $this->assertSame(ConnectionStatus::STATE_UNREACHABLE, $result->getState());
        $this->assertSame(
            'Typesense answered /debug with an error: http://a:8108: Operation timed out; http://b:8108: HTTP 500',
            $result->getMessage()
        );
        $this->assertNotContains('http://c:8108/debug', $this->sentUrls);
    }

    public function testVerifyConnectionTurnsAnyThrowableIntoUnreachable(): void
    {
        $node = $this->createStub(Node::class);
        $node->method('getBaseUrl')->willThrowException(new \Error('engine fault'));
        $this->config->method('getNodes')->willReturn([$node]);

        $result = $this->checker->verifyConnection();

        $this->assertSame(ConnectionStatus::STATE_UNREACHABLE, $result->getState());
        $this->assertStringContainsString('engine fault', $result->getMessage());
    }

    /**
     * @param string[] $baseUrls
     */
    private function configureNodes(array $baseUrls): void
    {
        $nodes = [];
        foreach ($baseUrls as $baseUrl) {
            $parts = parse_url($baseUrl);
            $nodes[] = new Node($parts['host'], $parts['port'], $parts['scheme']);
        }
        $this->config->method('getNodes')->willReturn($nodes);
    }

    /**
     * Answer each URL from the map (a Throwable is thrown); unmapped `/debug` gets version 30.2.
     *
     * Called URLs are recorded in {@see $sentUrls}.
     *
     * @param array<string, Response|\Throwable> $routes
     */
    private function routeTransport(array $routes): void
    {
        $this->transport->method('send')->willReturnCallback(
            function (string $method, string $url) use ($routes): Response {
                $this->sentUrls[] = $url;
                $answer = $routes[$url] ?? new Response(200, '{"state":1,"version":"30.2"}');
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return $answer;
            }
        );
    }
}
