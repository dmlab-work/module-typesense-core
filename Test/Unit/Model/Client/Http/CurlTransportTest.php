<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit\Model\Client\Http;

use DmLab\TypesenseCore\Exception\TransportException;
use DmLab\TypesenseCore\Model\Client\Http\CurlTransport;
use Magento\Framework\HTTP\Adapter\Curl;
use Magento\Framework\HTTP\Adapter\CurlFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

// The adapter double is a stub in most cases; only a few assert on its calls.
#[AllowMockObjectsWithoutExpectations]
class CurlTransportTest extends TestCase
{
    /** @var Curl|MockObject */
    private MockObject $curl;

    /** @var CurlTransport */
    private CurlTransport $transport;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $factory = $this->createMock(CurlFactory::class);
        $factory->method('create')->willReturn($this->curl);

        $this->transport = new CurlTransport($factory);
    }

    public function testStatusAndBodyAreParsedFromTheRawMessage(): void
    {
        $this->curl->method('read')->willReturn(
            "HTTP/1.1 201 Created\r\nContent-Type: application/json\r\n\r\n{\"name\":\"products\"}"
        );

        $response = $this->transport->send('POST', 'http://typesense:8108/collections', [], '{"name":"products"}');

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('{"name":"products"}', $response->getBody());
        $this->assertTrue($response->isSuccessful());
    }

    public function testErrorStatusIsReturnedRatherThanThrown(): void
    {
        // Interpreting statuses is the client's job; the transport only reports what came back.
        $this->curl->method('read')->willReturn("HTTP/1.1 404 Not Found\r\n\r\n{\"message\":\"Not found.\"}");

        $response = $this->transport->send('GET', 'http://typesense:8108/collections/x');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($response->isSuccessful());
    }

    public function testGetPassesMethodUrlHeadersAndTimeoutToTheAdapter(): void
    {
        $this->curl->expects($this->once())
            ->method('setOptions')
            ->with([CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 3]);
        // Flattened to `name: value` lines, not left as a map: older framework releases hand
        // the array to cURL unnormalized, where a map degrades to bare values.
        $this->curl->expects($this->once())
            ->method('write')
            ->with('GET', 'http://typesense:8108/health', '1.1', ['Accept: application/json'], '');
        $this->curl->expects($this->once())->method('close');
        $this->curl->method('read')->willReturn("HTTP/1.1 200 OK\r\n\r\n{\"ok\":true}");

        $this->transport->send('GET', 'http://typesense:8108/health', ['Accept' => 'application/json'], null, 3);
    }

    public function testTheApiKeyHeaderIsSentAsAFullHeaderLine(): void
    {
        $this->curl->expects($this->once())
            ->method('write')
            ->with(
                'GET',
                'http://typesense:8108/health',
                '1.1',
                ['X-TYPESENSE-API-KEY: secret-key', 'Accept: application/json'],
                ''
            );
        $this->curl->method('read')->willReturn("HTTP/1.1 200 OK\r\n\r\n{}");

        $this->transport->send(
            'GET',
            'http://typesense:8108/health',
            ['X-TYPESENSE-API-KEY' => 'secret-key', 'Accept' => 'application/json'],
            null,
            3
        );
    }

    public function testALongTimeoutDoesNotStretchTheConnectTimeout(): void
    {
        // A long write budget is for the work, not for waiting on a dead node's handshake.
        $this->curl->expects($this->once())
            ->method('setOptions')
            ->with([CURLOPT_TIMEOUT => 300, CURLOPT_CONNECTTIMEOUT => 5]);
        $this->curl->method('read')->willReturn("HTTP/1.1 200 OK\r\n\r\n{}");

        $this->transport->send('GET', 'http://typesense:8108/health', [], null, 300);
    }

    public function testPatchIsWiredUpAsACustomRequestWithABody(): void
    {
        // The adapter's write() only branches on GET/POST/PUT/DELETE, so PATCH needs the options set here.
        $this->curl->expects($this->once())
            ->method('setOptions')
            ->with([
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_CUSTOMREQUEST => 'PATCH',
                CURLOPT_POSTFIELDS => '{"fields":[]}',
            ]);
        $this->curl->method('read')->willReturn("HTTP/1.1 200 OK\r\n\r\n{}");

        $this->transport->send('PATCH', 'http://typesense:8108/collections/p', [], '{"fields":[]}');
    }

    public function testMethodIsUppercasedBeforeReachingTheAdapter(): void
    {
        $this->curl->expects($this->once())->method('write')->with('DELETE');
        $this->curl->method('read')->willReturn("HTTP/1.1 200 OK\r\n\r\n{}");

        $this->transport->send('delete', 'http://typesense:8108/collections/p');
    }

    public function testEmptyReadIsATransportException(): void
    {
        $this->curl->method('read')->willReturn('');
        $this->curl->method('getError')->willReturn('Connection refused');
        $this->curl->expects($this->once())->method('close');

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Connection refused');

        $this->transport->send('GET', 'http://typesense:8108/health');
    }

    public function testUnparseableMessageIsATransportException(): void
    {
        $this->curl->method('read')->willReturn('gibberish');

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('could not be parsed');

        $this->transport->send('GET', 'http://typesense:8108/health');
    }
}
