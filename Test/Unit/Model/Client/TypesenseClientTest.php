<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit\Model\Client;

use DmLab\TypesenseCore\Exception\TransportException;
use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\Http\Response;
use DmLab\TypesenseCore\Model\Client\Http\TransportInterface;
use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Model\Client\Clock;
use DmLab\TypesenseCore\Model\Client\NodeHealthRegistry;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseCore\Model\Config\Node;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

// The config double is a stub throughout; only the transport carries expectations.
#[AllowMockObjectsWithoutExpectations]
class TypesenseClientTest extends TestCase
{
    /** @var ConnectionSettingsInterface|MockObject */
    private MockObject $config;

    /** @var TransportInterface|MockObject */
    private MockObject $transport;

    /** @var TypesenseClient */
    private TypesenseClient $client;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConnectionSettingsInterface::class);
        $this->transport = $this->createMock(TransportInterface::class);

        $this->config->method('getNodes')->willReturn([new Node('typesense', 8108)]);
        $this->config->method('getApiKey')->willReturn('secret-key');
        $this->config->method('getConnectionTimeout')->willReturn(7);
        $this->config->method('getRetryCount')->willReturn(0);

        $this->client = $this->buildClient($this->config);
    }

    public function testGetSendsApiKeyHeaderAndNoBody(): void
    {
        $this->transport->expects($this->once())
            ->method('send')
            ->with(
                'GET',
                'http://typesense:8108/collections',
                ['X-TYPESENSE-API-KEY' => 'secret-key', 'Accept' => 'application/json'],
                null,
                7
            )
            ->willReturn(new Response(200, '[]'));

        $this->assertSame([], $this->client->request('GET', '/collections'));
    }

    public function testPostEncodesBodyAndSetsContentType(): void
    {
        $this->transport->expects($this->once())
            ->method('send')
            ->with(
                'POST',
                'http://typesense:8108/collections',
                [
                    'X-TYPESENSE-API-KEY' => 'secret-key',
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                '{"name":"products"}',
                7
            )
            ->willReturn(new Response(201, '{"name":"products","num_documents":0}'));

        $result = $this->client->request('POST', 'collections', ['name' => 'products']);

        $this->assertSame(['name' => 'products', 'num_documents' => 0], $result);
    }

    public function testQueryParametersAreAppendedToThePath(): void
    {
        $this->transport->expects($this->once())
            ->method('send')
            ->with('POST', 'http://typesense:8108/collections/p/documents/import?action=upsert')
            ->willReturn(new Response(200, '{}'));

        $this->client->request('POST', '/collections/p/documents/import', ['a' => 1], ['action' => 'upsert']);
    }

    public function testTimeoutOverrideBeatsTheConfiguredTimeout(): void
    {
        $this->transport->expects($this->once())
            ->method('send')
            ->with('GET', 'http://typesense:8108/health', $this->anything(), null, 1)
            ->willReturn(new Response(200, '{"ok":true}'));

        $this->client->request('GET', '/health', null, [], 1);
    }

    public function testMethodIsNormalisedToUppercase(): void
    {
        $this->transport->expects($this->once())
            ->method('send')
            ->with('DELETE')
            ->willReturn(new Response(200, '{}'));

        $this->client->request('delete', '/collections/p');
    }

    public function testEmptyResponseBodyDecodesToAnEmptyArray(): void
    {
        $this->transport->method('send')->willReturn(new Response(204, ''));

        $this->assertSame([], $this->client->request('DELETE', '/aliases/p'));
    }

    public function testNotFoundIsMappedToAnExceptionCarryingTheServerMessage(): void
    {
        $this->transport->method('send')
            ->willReturn(new Response(404, '{"message":"Not found."}'));

        try {
            $this->client->request('GET', '/collections/missing');
            $this->fail('Expected a TypesenseException.');
        } catch (TypesenseException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame(['message' => 'Not found.'], $e->getResponseBody());
            $this->assertStringContainsString('Not found.', $e->getMessage());
            $this->assertStringContainsString('/collections/missing', $e->getMessage());
        }
    }

    public function testServerErrorIsMappedToAnException(): void
    {
        $this->transport->method('send')
            ->willReturn(new Response(500, '{"message":"Index busy."}'));

        $this->expectException(TypesenseException::class);
        $this->expectExceptionMessage('HTTP 500');

        $this->client->request('POST', '/collections', ['name' => 'p']);
    }

    public function testNonJsonErrorBodyStillProducesAnException(): void
    {
        $this->transport->method('send')->willReturn(new Response(502, 'Bad Gateway'));

        try {
            $this->client->request('GET', '/health');
            $this->fail('Expected a TypesenseException.');
        } catch (TypesenseException $e) {
            $this->assertSame(502, $e->getStatusCode());
            $this->assertSame([], $e->getResponseBody());
            $this->assertStringContainsString('Bad Gateway', $e->getMessage());
        }
    }

    public function testUndecodableSuccessBodyIsAnException(): void
    {
        $this->transport->method('send')->willReturn(new Response(200, 'not json'));

        $this->expectException(TypesenseException::class);
        $this->expectExceptionMessage('undecodable');

        $this->client->request('GET', '/collections');
    }

    public function testErrorResponseDoesNotFailOverToTheNextNode(): void
    {
        $config = $this->buildConfig([new Node('a', 8108), new Node('b', 8108)], 0);
        $client = $this->buildClient($config);

        // A node that answers 404 is up and disagreeing; asking another one changes nothing.
        $this->transport->expects($this->once())
            ->method('send')
            ->willReturn(new Response(404, '{"message":"Not found."}'));

        $this->expectException(TypesenseException::class);

        $client->request('GET', '/collections/missing');
    }

    public function testFirstNodeDownFailsOverToTheSecond(): void
    {
        $config = $this->buildConfig([new Node('down', 8108), new Node('up', 8108)], 0);
        $client = $this->buildClient($config);

        $urls = [];
        $this->transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $method, string $url) use (&$urls): Response {
                $urls[] = $url;
                if (str_contains($url, 'down')) {
                    throw new TransportException('Connection refused');
                }

                return new Response(200, '{"ok":true}');
            });

        $this->assertSame(['ok' => true], $client->request('GET', '/health'));
        $this->assertSame(
            ['http://down:8108/health', 'http://up:8108/health'],
            $urls
        );
    }

    public function testLaggingNodeAnsweringFiveHundredFailsOverToTheNext(): void
    {
        $config = $this->buildConfig([new Node('lagging', 8108), new Node('up', 8108)], 0);
        $client = $this->buildClient($config);

        // A 503 "Not Ready or Lagging" means this node is catching up; a peer can still serve.
        $this->transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $method, string $url): Response {
                return str_contains($url, 'lagging')
                    ? new Response(503, '{"message":"Not Ready or Lagging"}')
                    : new Response(200, '{"ok":true}');
            });

        $this->assertSame(['ok' => true], $client->request('GET', '/health'));
    }

    public function testEveryNodeFiveHundredSurfacesTheServerResponse(): void
    {
        $config = $this->buildConfig([new Node('a', 8108), new Node('b', 8108)], 0);
        $client = $this->buildClient($config);

        $this->transport->expects($this->exactly(2))
            ->method('send')
            ->willReturn(new Response(503, '{"message":"Not Ready or Lagging"}'));

        try {
            $client->request('GET', '/health');
            $this->fail('Expected a TypesenseException.');
        } catch (TypesenseException $e) {
            // The status and body survive rather than being flattened into a transport error.
            $this->assertSame(503, $e->getStatusCode());
            $this->assertSame(['message' => 'Not Ready or Lagging'], $e->getResponseBody());
        }
    }

    public function testAFiveHundredSurvivesALaterNodeBeingUnreachable(): void
    {
        // Node order must not decide the diagnosis: the server's own answer beats
        // "nobody answered", which is the less informative of the two.
        $config = $this->buildConfig([new Node('lagging', 8108), new Node('down', 8108)], 0);
        $client = $this->buildClient($config);

        $this->transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $method, string $url): Response {
                if (str_contains($url, 'down')) {
                    throw new TransportException('Connection refused');
                }

                return new Response(503, '{"message":"Not Ready or Lagging"}');
            });

        try {
            $client->request('GET', '/health');
            $this->fail('Expected a TypesenseException.');
        } catch (TypesenseException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertSame(['message' => 'Not Ready or Lagging'], $e->getResponseBody());
        }
    }

    public function testAllNodesDownThrowsAfterExhaustingRetries(): void
    {
        $config = $this->buildConfig([new Node('a', 8108), new Node('b', 8108)], 1);
        $client = $this->buildClient($config);

        // Two nodes, one retry => the whole list is walked twice.
        $this->transport->expects($this->exactly(4))
            ->method('send')
            ->willThrowException(new TransportException('Connection refused'));

        try {
            $client->request('GET', '/health');
            $this->fail('Expected a TransportException.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('No Typesense node answered GET /health', $e->getMessage());
            $this->assertStringContainsString('Connection refused', $e->getMessage());
        }
    }

    public function testRetryReachesANodeThatRecovers(): void
    {
        $config = $this->buildConfig([new Node('a', 8108)], 2);
        $client = $this->buildClient($config);

        $calls = 0;
        $this->transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function () use (&$calls): Response {
                $calls++;
                if ($calls === 1) {
                    throw new TransportException('Connection reset');
                }

                return new Response(200, '{"ok":true}');
            });

        $this->assertSame(['ok' => true], $client->request('GET', '/health'));
    }

    /**
     * A replayed POST is a duplicate key or a 409 on a create that in fact succeeded: the
     * transport cannot tell a request that never left from one whose response was lost.
     */
    public function testANonIdempotentWriteIsNeverRetried(): void
    {
        $config = $this->buildConfig([new Node('a', 8108), new Node('b', 8108)], 2);
        $client = $this->buildClient($config);

        $this->transport->expects($this->once())
            ->method('send')
            ->willThrowException(new TransportException('Connection reset'));

        $this->expectException(TransportException::class);

        $client->request('POST', '/keys', ['description' => 'search']);
    }

    public function testANonIdempotentWriteDoesNotFailOverOnAServerError(): void
    {
        $config = $this->buildConfig([new Node('a', 8108), new Node('b', 8108)], 2);
        $client = $this->buildClient($config);

        $this->transport->expects($this->once())
            ->method('send')
            ->willReturn(new Response(503, '{"message":"Not Ready or Lagging"}'));

        $this->expectException(TypesenseException::class);
        $this->expectExceptionCode(503);

        $client->request('POST', '/collections', ['name' => 'products']);
    }

    public function testASchemaPatchIsNeverRetried(): void
    {
        $config = $this->buildConfig([new Node('a', 8108), new Node('b', 8108)], 2);
        $client = $this->buildClient($config);

        // A half-applied PATCH replayed against a peer re-drops an already-dropped field.
        $this->transport->expects($this->once())
            ->method('send')
            ->willReturn(new Response(500, '{"message":"boom"}'));

        $this->expectException(TypesenseException::class);

        $client->request('PATCH', '/collections/products', ['fields' => []]);
    }

    public function testAnIdempotentWriteStillFailsOver(): void
    {
        $config = $this->buildConfig([new Node('a', 8108), new Node('b', 8108)], 0);
        $client = $this->buildClient($config);

        $calls = 0;
        $this->transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function () use (&$calls): Response {
                $calls++;
                if ($calls === 1) {
                    throw new TransportException('Connection refused');
                }

                return new Response(200, '{"name":"products"}');
            });

        $this->assertSame(['name' => 'products'], $client->request('PUT', '/aliases/products'));
    }

    public function testAReplayUnsafeDeleteIsDeliveredOnceToOneNode(): void
    {
        $config = $this->buildConfig([new Node('a', 8108), new Node('b', 8108)], 2);
        $client = $this->buildClient($config);

        // A filter-based delete replayed after a lost response removes whatever matches by then.
        $this->transport->expects($this->once())
            ->method('send')
            ->willThrowException(new TransportException('Connection reset'));

        $this->expectException(TransportException::class);

        $client->request(
            'DELETE',
            '/collections/products/documents',
            null,
            ['filter_by' => 'store_id:=1'],
            null,
            null,
            false
        );
    }

    public function testAWriteThatNeverLeftFailsOverToTheNextNode(): void
    {
        $config = $this->buildConfig([new Node('a', 8108), new Node('b', 8108)], 2);
        $client = $this->buildClient($config);

        $urls = [];
        $this->transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $m, string $url) use (&$urls): Response {
                $urls[] = $url;
                if (str_contains($url, '//a:')) {
                    throw new TransportException('Connection refused', 0, [], null, 7, false);
                }

                return new Response(201, '{"name":"products"}');
            });

        $this->assertSame(['name' => 'products'], $client->request('POST', '/collections', ['name' => 'products']));
        $this->assertSame(['http://a:8108/collections', 'http://b:8108/collections'], $urls);
    }

    public function testAWriteThatMayHaveLeftIsNotSentToAnotherNode(): void
    {
        $config = $this->buildConfig([new Node('a', 8108), new Node('b', 8108)], 2);
        $client = $this->buildClient($config);

        $this->transport->expects($this->once())
            ->method('send')
            ->with('POST', 'http://a:8108/collections')
            ->willThrowException(new TransportException('Operation timed out', 0, [], null, 28, true));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Operation timed out');

        $client->request('POST', '/collections', ['name' => 'products']);
    }

    public function testAnUnknownSentWriteIsNeverRepeatedAfterAFailover(): void
    {
        $config = $this->buildConfig([new Node('a', 8108), new Node('b', 8108), new Node('c', 8108)], 2);
        $client = $this->buildClient($config);

        $urls = [];
        $this->transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $m, string $url) use (&$urls): Response {
                $urls[] = $url;
                if (str_contains($url, '//a:')) {
                    throw new TransportException('Could not resolve host', 0, [], null, 6, false);
                }

                throw new TransportException('Connection reset', 0, [], null, 56, true);
            });

        try {
            $client->request('POST', '/keys', ['description' => 'search']);
            $this->fail('Expected a TransportException.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('Connection reset', $e->getMessage());
        }

        // A single round: b may have applied it, so neither b again nor c is tried.
        $this->assertSame(['http://a:8108/keys', 'http://b:8108/keys'], $urls);
    }

    public function testAWriteFailingOverToALaggingNodeSurfacesItsServerErrorWithoutGoingFurther(): void
    {
        $config = $this->buildConfig([new Node('a', 8108), new Node('b', 8108), new Node('c', 8108)], 2);
        $client = $this->buildClient($config);

        $this->transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $m, string $url): Response {
                if (str_contains($url, '//a:')) {
                    throw new TransportException('Connection refused', 0, [], null, 7, false);
                }

                return new Response(503, '{"message":"Not Ready or Lagging"}');
            });

        $this->expectException(TypesenseException::class);
        $this->expectExceptionCode(503);

        $client->request('POST', '/collections', ['name' => 'products']);
    }

    public function testAWriteWhereNoNodeWasReachedThrowsAfterOneRound(): void
    {
        $config = $this->buildConfig([new Node('a', 8108), new Node('b', 8108)], 2);
        $client = $this->buildClient($config);

        $this->transport->expects($this->exactly(2))
            ->method('send')
            ->willThrowException(new TransportException('Connection refused', 0, [], null, 7, false));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('No Typesense node answered POST /collections after 1 attempt(s)');

        $client->request('POST', '/collections', ['name' => 'products']);
    }

    public function testAReplaySafeOptInRetriesAWriteLikeARead(): void
    {
        $config = $this->buildConfig([new Node('a', 8108), new Node('b', 8108)], 1);
        $client = $this->buildClient($config);

        // Possibly-sent failures and a 5xx: replay is allowed, so both rounds walk both nodes.
        $calls = 0;
        $this->transport->expects($this->exactly(4))
            ->method('send')
            ->willReturnCallback(function () use (&$calls): Response {
                $calls++;
                if ($calls === 2) {
                    return new Response(503, '{"message":"Not Ready or Lagging"}');
                }
                if ($calls < 4) {
                    throw new TransportException('Connection reset', 0, [], null, 56, true);
                }

                return new Response(200, '{"id":"1"}');
            });

        $this->assertSame(
            ['id' => '1'],
            $client->request('POST', '/collections/p/documents', ['id' => '1'], [], replaySafe: true)
        );
    }

    public function testAReplaySafeOptOutGivesAReadASingleDelivery(): void
    {
        $config = $this->buildConfig([new Node('a', 8108), new Node('b', 8108)], 2);
        $client = $this->buildClient($config);

        $this->transport->expects($this->once())
            ->method('send')
            ->willReturn(new Response(503, '{"message":"Not Ready or Lagging"}'));

        $this->expectException(TypesenseException::class);
        $this->expectExceptionCode(503);

        $client->request('GET', '/collections', replaySafe: false);
    }

    public function testAReplaySafeOptOutStillLeavesANodeTheRequestNeverReached(): void
    {
        $config = $this->buildConfig([new Node('a', 8108), new Node('b', 8108)], 2);
        $client = $this->buildClient($config);

        $calls = 0;
        $this->transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function () use (&$calls): Response {
                if (++$calls === 1) {
                    throw new TransportException('Connection refused', 0, [], null, 7, false);
                }

                return new Response(200, '{"num_deleted":3}');
            });

        $this->assertSame(
            ['num_deleted' => 3],
            $client->request('DELETE', '/collections/p/documents', null, ['filter_by' => 'x:=1'], replaySafe: false)
        );
    }

    public function testRequestRawHonoursReplaySafeAndMaxAttempts(): void
    {
        $config = $this->buildConfig([new Node('a', 8108)], 0);
        $client = $this->buildClient($config);

        $calls = 0;
        $this->transport->expects($this->exactly(3))
            ->method('send')
            ->willReturnCallback(function (string $method) use (&$calls): Response {
                $calls++;
                $this->assertSame('POST', $method);
                if ($calls < 3) {
                    throw new TransportException('Connection reset', 0, [], null, 56, true);
                }

                return new Response(200, '{"success":true}');
            });

        $this->assertSame(
            '{"success":true}',
            $client->requestRaw('POST', '/collections/p/documents/import', '{"id":"1"}', 'text/plain', [], 5, 3, true)
        );
    }

    public function testRequestRawWithoutReplaySafeSendsAPostOnce(): void
    {
        $config = $this->buildConfig([new Node('a', 8108)], 3);
        $client = $this->buildClient($config);

        $this->transport->expects($this->once())
            ->method('send')
            ->willThrowException(new TransportException('Connection reset', 0, [], null, 56, true));

        $this->expectException(TransportException::class);

        $client->requestRaw('POST', '/collections/p/documents/import', '{"id":"1"}', 'text/plain');
    }

    public function testRequestRawHonoursTheDeadline(): void
    {
        $config = $this->buildConfig([new Node('a', 8108)], 5);
        $now = 0.0;
        $client = $this->buildClient($config, null, $this->fakeClock($now));

        $timeouts = [];
        $this->transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(
                function (string $m, string $u, array $h, ?string $b, float $t) use (&$now, &$timeouts): Response {
                    $timeouts[] = $t;
                    $now += 6.0;
                    throw new TransportException('Connection reset');
                }
            );

        try {
            $client->requestRaw('PUT', '/x', '{}', 'application/json', [], 60, null, null, 10);
            $this->fail('Expected a TransportException.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('exceeded its 10s deadline', $e->getMessage());
        }

        $this->assertSame([10.0, 4.0], $timeouts);
    }

    public function testMaxAttemptsOverrideCapsTheConfiguredRetryCount(): void
    {
        $config = $this->buildConfig([new Node('a', 8108)], 5);
        $client = $this->buildClient($config);

        $this->transport->expects($this->once())
            ->method('send')
            ->willThrowException(new TransportException('Connection refused'));

        $this->expectException(TransportException::class);

        $client->request('GET', '/health', null, [], 2, 1);
    }

    public function testRequestRawSendsTheBodyVerbatimAndReturnsItUndecoded(): void
    {
        $this->transport->expects($this->once())
            ->method('send')
            ->with(
                'POST',
                'http://typesense:8108/collections/products/documents/import?action=upsert',
                [
                    'X-TYPESENSE-API-KEY' => 'secret-key',
                    'Accept' => 'application/json',
                    'Content-Type' => 'text/plain',
                ],
                "{\"id\":\"1\"}\n{\"id\":\"2\"}",
                7
            )
            ->willReturn(new Response(200, "{\"success\":true}\n{\"success\":true}"));

        $this->assertSame(
            "{\"success\":true}\n{\"success\":true}",
            $this->client->requestRaw(
                'POST',
                '/collections/products/documents/import',
                "{\"id\":\"1\"}\n{\"id\":\"2\"}",
                'text/plain',
                ['action' => 'upsert']
            )
        );
    }

    public function testRequestRawMapsAnErrorResponse(): void
    {
        $this->transport->method('send')->willReturn(new Response(404, '{"message":"Not found."}'));

        $this->expectException(TypesenseException::class);
        $this->expectExceptionCode(404);

        $this->client->requestRaw('POST', '/collections/missing/documents/import', '{}', 'text/plain');
    }

    public function testDeadlineStopsFurtherAttempts(): void
    {
        $config = $this->buildConfig([new Node('a', 8108)], 5);
        $now = 0.0;
        $client = $this->buildClient($config, null, $this->fakeClock($now));

        $this->transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function () use (&$now): Response {
                $now += 7.0;
                throw new TransportException('Connection refused');
            });

        try {
            $client->request('GET', '/health');
            $this->fail('Expected a TransportException.');
        } catch (TransportException $e) {
            // Timeout 7 => default deadline 14: two attempts consume it, the third is never sent.
            $this->assertStringContainsString('exceeded its 14s deadline', $e->getMessage());
            $this->assertInstanceOf(TransportException::class, $e->getPrevious());
            $this->assertStringContainsString('Connection refused', $e->getPrevious()->getMessage());
        }
    }

    public function testADeadlineAfterAFiveHundredChainsTheServerResponse(): void
    {
        $config = $this->buildConfig([new Node('lagging', 8108), new Node('down', 8108)], 0);
        $now = 0.0;
        $client = $this->buildClient($config, null, $this->fakeClock($now));

        $this->transport->expects($this->once())
            ->method('send')
            ->willReturnCallback(function () use (&$now): Response {
                $now += 14.0;

                return new Response(503, '{"message":"Not Ready or Lagging"}');
            });

        try {
            $client->request('GET', '/health');
            $this->fail('Expected a TransportException.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('exceeded its 14s deadline', $e->getMessage());
            $this->assertInstanceOf(TypesenseException::class, $e->getPrevious());
            $this->assertSame(503, $e->getPrevious()->getStatusCode());
        }
    }

    public function testMaxAttemptsIsIgnoredForANonReplaySafeCall(): void
    {
        $config = $this->buildConfig([new Node('a', 8108)], 0);
        $client = $this->buildClient($config);

        $this->transport->expects($this->once())
            ->method('send')
            ->willThrowException(new TransportException('Connection refused', 0, [], null, 7, false));

        $this->expectException(TransportException::class);

        $client->request('POST', '/keys', ['description' => 'search'], [], null, 3);
    }

    public function testPerAttemptTimeoutShrinksToTheRemainingBudget(): void
    {
        $config = $this->buildConfig([new Node('a', 8108)], 5);
        $now = 0.0;
        $client = $this->buildClient($config, null, $this->fakeClock($now));

        $timeouts = [];
        $this->transport->expects($this->exactly(3))
            ->method('send')
            ->willReturnCallback(
                function (string $m, string $u, array $h, ?string $b, float $t) use (&$now, &$timeouts): Response {
                    $timeouts[] = $t;
                    $now += 6.5;
                    if (count($timeouts) < 3) {
                        throw new TransportException('Connection reset');
                    }

                    return new Response(200, '{"ok":true}');
                }
            );

        $client->request('GET', '/health', null, [], null, null, true, 15);

        // 15 s budget: 7 (configured), then 8.5 capped at 7, then the 2 s left.
        $this->assertSame([7.0, 7.0, 2.0], $timeouts);
    }

    public function testPerAttemptTimeoutNeverExceedsTheRemainingBudget(): void
    {
        $config = $this->buildConfig([new Node('a', 8108)], 1);
        $now = 0.0;
        $client = $this->buildClient($config, null, $this->fakeClock($now));

        $timeouts = [];
        $this->transport->method('send')
            ->willReturnCallback(
                function (string $m, string $u, array $h, ?string $b, float $t) use (&$now, &$timeouts): Response {
                    $timeouts[] = $t;
                    $now += 1.9;
                    throw new TransportException('Connection reset');
                }
            );

        try {
            $client->request('GET', '/health', null, [], null, null, true, 2);
        } catch (TransportException) {
            // expected
        }

        // A whole-second floor would let the second attempt overrun the deadline by 0.9 s.
        $this->assertCount(2, $timeouts);
        $this->assertSame(2.0, $timeouts[0]);
        $this->assertEqualsWithDelta(0.1, $timeouts[1], 1e-9);
    }

    public function testLessThanAMillisecondLeftCountsAsAnExpiredDeadline(): void
    {
        $config = $this->buildConfig([new Node('a', 8108)], 1);
        $now = 0.0;
        $client = $this->buildClient($config, null, $this->fakeClock($now));

        $this->transport->expects($this->once())
            ->method('send')
            ->willReturnCallback(function () use (&$now): Response {
                $now += 0.9995;
                throw new TransportException('Connection reset');
            });

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('exceeded its 1s deadline');

        $client->request('GET', '/health', null, [], null, null, true, 1);
    }

    /**
     * @return array<string,array{0:int|null}>
     */
    public static function deadlineProvider(): array
    {
        return ['default deadline' => [null], 'generous deadline' => [100]];
    }

    #[DataProvider('deadlineProvider')]
    public function testAHungSingleNodeIsTriedExactlyTwice(?int $deadline): void
    {
        $config = $this->buildConfig([new Node('hung', 8108)], 2, 5);
        $now = 0.0;
        $client = $this->buildClient($config, null, $this->fakeClock($now));

        // Retry 2 would mean three attempts; a timeout is retried at most once per node.
        $this->transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $m, string $u, array $h, ?string $b, float $timeout) use (&$now) {
                $now += $timeout;
                throw new TransportException('Operation timed out', 0, [], null, 28);
            });

        $this->expectException(TransportException::class);

        $client->request('GET', '/collections/p/documents/search', null, ['q' => 'x'], null, null, true, $deadline);
    }

    public function testATimedOutNodeIsSkippedWhileItsPeerIsStillRetried(): void
    {
        $config = $this->buildConfig([new Node('hung', 8108), new Node('flaky', 8108)], 3);
        $client = $this->buildClient($config);

        $urls = [];
        $this->transport->method('send')
            ->willReturnCallback(function (string $m, string $url) use (&$urls): Response {
                $urls[] = $url;
                if (str_contains($url, 'hung')) {
                    throw new TransportException('Operation timed out', 0, [], null, 28);
                }
                if (count($urls) < 6) {
                    throw new TransportException('Connection reset');
                }

                return new Response(200, '{"ok":true}');
            });

        $client->request('GET', '/health');

        $this->assertSame(
            [
                'http://hung:8108/health', 'http://flaky:8108/health',
                'http://hung:8108/health', 'http://flaky:8108/health',
                'http://flaky:8108/health', 'http://flaky:8108/health',
            ],
            $urls
        );
    }

    public function testAMarkedNodeIsSkippedWhileAPeerIsHealthy(): void
    {
        $config = $this->buildConfig([new Node('marked', 8108), new Node('up', 8108)], 2);
        $store = [];
        $registry = $this->registryOver($store);
        $registry->markDown(new Node('marked', 8108));
        $client = $this->buildClient($config, $registry);

        $this->transport->expects($this->once())
            ->method('send')
            ->with('GET', 'http://up:8108/health')
            ->willReturn(new Response(200, '{"ok":true}'));

        $client->request('GET', '/health');
    }

    public function testAMarkedNodeIsTriedLastAndUnmarkedOnRecovery(): void
    {
        $marked = new Node('marked', 8108);
        $config = $this->buildConfig([$marked, new Node('lagging', 8108)], 1);
        $store = [];
        $registry = $this->registryOver($store);
        $registry->markDown($marked);
        $client = $this->buildClient($config, $registry);

        $urls = [];
        $this->transport->method('send')
            ->willReturnCallback(function (string $m, string $url) use (&$urls): Response {
                $urls[] = $url;

                return str_contains($url, 'lagging')
                    ? new Response(503, '{"message":"Not Ready or Lagging"}')
                    : new Response(200, '{"ok":true}');
            });

        $this->assertSame(['ok' => true], $client->request('GET', '/health'));
        // Round 1 skips the marked node; once its peer is marked too, every node is eligible again.
        $this->assertSame(['http://lagging:8108/health', 'http://marked:8108/health'], $urls);
        $this->assertFalse($registry->isMarked($marked));
        $this->assertTrue($registry->isMarked(new Node('lagging', 8108)));
    }

    public function testASingleRoundCallStillReachesAMarkedNodeAfterItsPeerFails(): void
    {
        $marked = new Node('marked', 8108);
        $config = $this->buildConfig([$marked, new Node('down', 8108)], 0);
        $store = [];
        $registry = $this->registryOver($store);
        $registry->markDown($marked);
        $client = $this->buildClient($config, $registry);

        $urls = [];
        $this->transport->method('send')
            ->willReturnCallback(function (string $m, string $url) use (&$urls): Response {
                $urls[] = $url;
                if (str_contains($url, 'down')) {
                    throw new TransportException('Connection refused', 0, [], null, 7, false);
                }

                return new Response(201, '{"name":"p"}');
            });

        $this->assertSame(['name' => 'p'], $client->request('POST', '/collections', ['name' => 'p']));
        $this->assertSame(['http://down:8108/collections', 'http://marked:8108/collections'], $urls);
    }

    public function testANodeMarkedBeforeTheCallGetsOneAttempt(): void
    {
        $marked = new Node('marked', 8108);
        $config = $this->buildConfig([$marked], 2);
        $store = [];
        $registry = $this->registryOver($store);
        $registry->markDown($marked);
        $client = $this->buildClient($config, $registry);

        $this->transport->expects($this->once())
            ->method('send')
            ->willThrowException(new TransportException('Operation timed out', 0, [], null, 28, true));

        $this->expectException(TransportException::class);

        $client->request('GET', '/health');
    }

    public function testANodeMarkedDuringTheCallIsStillRetried(): void
    {
        $config = $this->buildConfig([new Node('flaky', 8108)], 1);
        $store = [];
        $client = $this->buildClient($config, $this->registryOver($store));

        $this->transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                new Response(503, '{"message":"Not Ready or Lagging"}'),
                new Response(200, '{"ok":true}')
            );

        $this->assertSame(['ok' => true], $client->request('GET', '/health'));
        $this->assertSame([], $store);
    }

    public function testASingleNodeSuccessReadsTheCacheOnceAndWritesNothing(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('load')->willReturn(false);
        $cache->expects($this->never())->method('save');
        $cache->expects($this->never())->method('remove');
        $client = $this->buildClient($this->config, new NodeHealthRegistry($cache, new NullLogger()));

        $this->transport->method('send')->willReturn(new Response(200, '{"ok":true}'));

        $this->assertSame(['ok' => true], $client->request('GET', '/health'));
    }

    public function testAMarkedNodeAnsweringIsMarkedUpWithASingleCacheRead(): void
    {
        $node = new Node('typesense', 8108);
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('load')->willReturn('1');
        $cache->expects($this->once())
            ->method('remove')
            ->with('dmlab_typesense_node_down_' . sha1($node->getBaseUrl()));
        $client = $this->buildClient($this->config, new NodeHealthRegistry($cache, new NullLogger()));

        $this->transport->method('send')->willReturn(new Response(200, '{"ok":true}'));

        $this->assertSame(['ok' => true], $client->request('GET', '/health'));
    }

    public function testFailingNodesAreMarkedDownAndAnUnmarkedSuccessIsNotMarkedUp(): void
    {
        $lagging = new Node('lagging', 8108);
        $down = new Node('down', 8108);
        $up = new Node('up', 8108);
        $config = $this->buildConfig([$lagging, $down, $up], 0);
        $registry = $this->createMock(NodeHealthRegistry::class);
        $registry->method('order')->willReturnArgument(0);
        $client = $this->buildClient($config, $registry);

        $marked = [];
        $registry->expects($this->exactly(2))
            ->method('markDown')
            ->willReturnCallback(function (Node $node) use (&$marked): void {
                $marked[] = $node->getBaseUrl();
            });
        $registry->expects($this->never())->method('markUp');

        $this->transport->method('send')
            ->willReturnCallback(function (string $m, string $url): Response {
                if (str_contains($url, 'down')) {
                    throw new TransportException('Connection refused', 0, [], null, 7, false);
                }

                return str_contains($url, 'lagging')
                    ? new Response(503, '{"message":"Not Ready or Lagging"}')
                    : new Response(200, '{"ok":true}');
            });

        $client->request('GET', '/health');

        $this->assertSame(['http://lagging:8108', 'http://down:8108'], $marked);
    }

    public function testAFourHundredNeitherMarksTheNodeDownNorLogs(): void
    {
        $registry = $this->createMock(NodeHealthRegistry::class);
        $registry->method('order')->willReturnArgument(0);
        $registry->expects($this->never())->method('markDown');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');
        $client = $this->buildClient($this->config, $registry, null, $logger);

        $this->transport->method('send')->willReturn(new Response(404, '{"message":"Not found."}'));

        $this->expectException(TypesenseException::class);

        $client->request('GET', '/collections/missing');
    }

    public function testBackoffSleepsOnlyBetweenRounds(): void
    {
        $config = $this->buildConfig([new Node('a', 8108), new Node('b', 8108)], 2);
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn(0.0);
        $client = $this->buildClient($config, null, $clock);

        $events = [];
        $clock->expects($this->exactly(2))
            ->method('sleep')
            ->willReturnCallback(function (int $us) use (&$events): void {
                $events[] = $us;
            });
        $this->transport->expects($this->exactly(6))
            ->method('send')
            ->willReturnCallback(function (string $m, string $url) use (&$events): Response {
                $events[] = parse_url($url, PHP_URL_HOST);
                throw new TransportException('Connection refused');
            });

        try {
            $client->request('GET', '/health');
            $this->fail('Expected a TransportException.');
        } catch (TransportException) {
            // expected
        }

        // Three rounds over two nodes: a pause between rounds, never between nodes of one round.
        $this->assertSame(['a', 'b'], array_slice($events, 0, 2));
        $this->assertIsInt($events[2]);
        $this->assertSame(['a', 'b'], array_slice($events, 3, 2));
        $this->assertIsInt($events[5]);
        $this->assertSame(['a', 'b'], array_slice($events, 6, 2));
        $this->assertLessThanOrEqual(100_000, $events[2]);
        $this->assertLessThanOrEqual(200_000, $events[5]);
        $this->assertGreaterThanOrEqual(0, min($events[2], $events[5]));
    }

    public function testBackoffNeverSleepsPastTheDeadline(): void
    {
        $config = $this->buildConfig([new Node('a', 8108)], 1);
        $now = 0.0;
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturnCallback(function () use (&$now): float {
            return $now;
        });
        $client = $this->buildClient($config, null, $clock);

        // The first attempt spends the whole 1 s budget: the clamp leaves nothing to sleep.
        $clock->expects($this->once())->method('sleep')->with(0);
        $this->transport->expects($this->once())
            ->method('send')
            ->willReturnCallback(function () use (&$now): Response {
                $now += 1.0;
                throw new TransportException('Connection refused');
            });

        try {
            $client->request('GET', '/health', null, [], null, null, true, 1);
        } catch (TransportException) {
            // expected
        }
    }

    public function testOneWarningPerFailedNodeWithoutKeyOrQueryString(): void
    {
        $config = $this->buildConfig([new Node('down', 8108), new Node('lagging', 8108), new Node('up', 8108)], 0);
        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))
            ->method('warning')
            ->willReturnCallback(function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            });
        $client = $this->buildClient($config, null, null, $logger);

        $this->transport->method('send')
            ->willReturnCallback(function (string $m, string $url): Response {
                if (str_contains($url, 'down')) {
                    // CurlTransport's own wording embeds the full URL.
                    throw new TransportException(sprintf('Typesense request to %s failed: Connection refused', $url));
                }

                return str_contains($url, 'lagging')
                    ? new Response(503, '{"message":"Not Ready or Lagging"}')
                    : new Response(200, '{"hits":[]}');
            });

        $client->request('GET', '/collections/p/documents/search', null, ['q' => 'secret query', 'x' => 'y']);

        $this->assertSame(
            [
                'Typesense node http://down:8108 failed for GET /collections/p/documents/search: '
                    . 'Typesense request to http://down:8108/collections/p/documents/search failed: Connection refused',
                'Typesense node http://lagging:8108 failed for GET /collections/p/documents/search: HTTP 503',
            ],
            $warnings
        );
        foreach ($warnings as $warning) {
            $this->assertStringNotContainsString('secret-key', $warning);
            $this->assertStringNotContainsString('?', $warning);
        }
    }

    /**
     * A client over the given config with the shared transport double.
     *
     * The registry defaults to "nothing marked", the clock to a frozen one and the logger to a sink.
     *
     * @param ConnectionSettingsInterface $config
     * @param NodeHealthRegistry|null $registry
     * @param Clock|null $clock
     * @param LoggerInterface|null $logger
     */
    private function buildClient(
        ConnectionSettingsInterface $config,
        ?NodeHealthRegistry $registry = null,
        ?Clock $clock = null,
        ?LoggerInterface $logger = null
    ): TypesenseClient {
        if ($registry === null) {
            $registry = $this->createStub(NodeHealthRegistry::class);
            $registry->method('order')->willReturnArgument(0);
        }
        if ($clock === null) {
            $clock = $this->createStub(Clock::class);
            $clock->method('now')->willReturn(0.0);
        }

        return new TypesenseClient($config, $this->transport, $registry, $clock, $logger ?? new NullLogger());
    }

    /**
     * A clock reading the referenced time; sleeping does not advance it.
     *
     * @param float $now
     */
    private function fakeClock(float &$now): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturnCallback(function () use (&$now): float {
            return $now;
        });

        return $clock;
    }

    /**
     * A real registry over an in-memory cache.
     *
     * @param array<string,string> $store
     */
    private function registryOver(array &$store): NodeHealthRegistry
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(function (string $id) use (&$store) {
            return $store[$id] ?? false;
        });
        $cache->method('save')->willReturnCallback(function (string $data, string $id) use (&$store): bool {
            $store[$id] = $data;

            return true;
        });
        $cache->method('remove')->willReturnCallback(function (string $id) use (&$store): bool {
            unset($store[$id]);

            return true;
        });

        return new NodeHealthRegistry($cache, new NullLogger());
    }

    /**
     * A config stub with the given nodes and retry count; everything else is fixed.
     *
     * @param Node[] $nodes
     * @param int $retries
     * @param int $timeout
     * @return ConnectionSettingsInterface|Stub
     */
    private function buildConfig(array $nodes, int $retries, int $timeout = 7): Stub
    {
        $config = $this->createStub(ConnectionSettingsInterface::class);
        $config->method('getNodes')->willReturn($nodes);
        $config->method('getApiKey')->willReturn('secret-key');
        $config->method('getConnectionTimeout')->willReturn($timeout);
        $config->method('getRetryCount')->willReturn($retries);

        return $config;
    }
}
