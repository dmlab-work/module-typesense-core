<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseCore\Test\Unit\Model\Client;

use MageDevGroup\TypesenseCore\Exception\TransportException;
use MageDevGroup\TypesenseCore\Exception\TypesenseException;
use MageDevGroup\TypesenseCore\Model\Client\Http\Response;
use MageDevGroup\TypesenseCore\Model\Client\Http\TransportInterface;
use MageDevGroup\TypesenseCore\Api\ConnectionSettingsInterface;
use MageDevGroup\TypesenseCore\Model\Client\TypesenseClient;
use MageDevGroup\TypesenseCore\Model\Config\Node;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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

        $this->client = new TypesenseClient($this->config, $this->transport);
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
        $client = new TypesenseClient($config, $this->transport);

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
        $client = new TypesenseClient($config, $this->transport);

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
        $client = new TypesenseClient($config, $this->transport);

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
        $client = new TypesenseClient($config, $this->transport);

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
        $client = new TypesenseClient($config, $this->transport);

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
        $client = new TypesenseClient($config, $this->transport);

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
        $client = new TypesenseClient($config, $this->transport);

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
        $client = new TypesenseClient($config, $this->transport);

        $this->transport->expects($this->once())
            ->method('send')
            ->willThrowException(new TransportException('Connection reset'));

        $this->expectException(TransportException::class);

        $client->request('POST', '/keys', ['description' => 'search']);
    }

    public function testANonIdempotentWriteDoesNotFailOverOnAServerError(): void
    {
        $config = $this->buildConfig([new Node('a', 8108), new Node('b', 8108)], 2);
        $client = new TypesenseClient($config, $this->transport);

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
        $client = new TypesenseClient($config, $this->transport);

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
        $client = new TypesenseClient($config, $this->transport);

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
        $client = new TypesenseClient($config, $this->transport);

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

    public function testMaxAttemptsOverrideCapsTheConfiguredRetryCount(): void
    {
        $config = $this->buildConfig([new Node('a', 8108)], 5);
        $client = new TypesenseClient($config, $this->transport);

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

    /**
     * A config stub with the given nodes and retry count; everything else is fixed.
     *
     * @param Node[] $nodes
     * @param int $retries
     * @return ConnectionSettingsInterface|MockObject
     */
    private function buildConfig(array $nodes, int $retries): MockObject
    {
        $config = $this->createMock(ConnectionSettingsInterface::class);
        $config->method('getNodes')->willReturn($nodes);
        $config->method('getApiKey')->willReturn('secret-key');
        $config->method('getConnectionTimeout')->willReturn(7);
        $config->method('getRetryCount')->willReturn($retries);

        return $config;
    }
}
