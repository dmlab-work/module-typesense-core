<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit\Model\Client\Http;

use DmLab\TypesenseCore\Exception\TransportException;
use DmLab\TypesenseCore\Model\Client\Http\CurlTransport;
use Magento\Framework\HTTP\Adapter\Curl;
use Magento\Framework\HTTP\Adapter\CurlFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
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
            ->with([CURLOPT_TIMEOUT_MS => 3000, CURLOPT_CONNECTTIMEOUT_MS => 3000, CURLOPT_NOSIGNAL => true]);
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
            ->with([CURLOPT_TIMEOUT_MS => 300000, CURLOPT_CONNECTTIMEOUT_MS => 5000, CURLOPT_NOSIGNAL => true]);
        $this->curl->method('read')->willReturn("HTTP/1.1 200 OK\r\n\r\n{}");

        $this->transport->send('GET', 'http://typesense:8108/health', [], null, 300);
    }

    /**
     * @return array<string,array{float,int}>
     */
    public static function fractionalTimeoutProvider(): array
    {
        return [
            'sub-second' => [0.25, 250],
            'floored, never rounded up' => [1.9999, 1999],
            'below a millisecond' => [0.0004, 1],
        ];
    }

    #[DataProvider('fractionalTimeoutProvider')]
    public function testAFractionalTimeoutIsHonouredToTheMillisecond(float $timeout, int $expectedMs): void
    {
        $this->curl->expects($this->once())
            ->method('setOptions')
            ->with([
                CURLOPT_TIMEOUT_MS => $expectedMs,
                CURLOPT_CONNECTTIMEOUT_MS => $expectedMs,
                CURLOPT_NOSIGNAL => true,
            ]);
        $this->curl->method('read')->willReturn("HTTP/1.1 200 OK\r\n\r\n{}");

        $this->transport->send('GET', 'http://typesense:8108/health', [], null, $timeout);
    }

    public function testPatchIsWiredUpAsACustomRequestWithABody(): void
    {
        // The adapter's write() only branches on GET/POST/PUT/DELETE, so PATCH needs the options set here.
        $this->curl->expects($this->once())
            ->method('setOptions')
            ->with([
                CURLOPT_TIMEOUT_MS => 5000,
                CURLOPT_CONNECTTIMEOUT_MS => 5000,
                CURLOPT_NOSIGNAL => true,
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

    public function testHeadSetsNobodyAndNoPostfields(): void
    {
        // Without NOBODY cURL waits for a body a HEAD response never carries.
        $this->curl->expects($this->once())
            ->method('setOptions')
            ->with([
                CURLOPT_TIMEOUT_MS => 5000,
                CURLOPT_CONNECTTIMEOUT_MS => 5000,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_CUSTOMREQUEST => 'HEAD',
                CURLOPT_NOBODY => true,
            ]);
        $this->curl->method('read')->willReturn("HTTP/1.1 200 OK\r\n\r\n");

        $this->transport->send('HEAD', 'http://typesense:8108/health');
    }

    /**
     * @return array<string,array{int,float,bool,bool}>
     */
    public static function failureProvider(): array
    {
        return [
            'host not resolved' => [6, 0.0, false, false],
            'connection refused' => [7, 0.0, false, false],
            'connect timeout' => [28, 0.0, false, true],
            'timeout after the request left' => [28, 0.012, true, true],
            'other error' => [52, 0.012, true, false],
        ];
    }

    #[DataProvider('failureProvider')]
    public function testEmptyReadReportsErrnoAndWhetherTheRequestLeft(
        int $errno,
        float $pretransfer,
        bool $sent,
        bool $timeout
    ): void {
        $this->curl->method('read')->willReturn('');
        $this->curl->method('getErrno')->willReturn($errno);
        $this->curl->method('getError')->willReturn('boom');
        $this->curl->method('getInfo')->with(CURLINFO_PRETRANSFER_TIME)->willReturn($pretransfer);

        try {
            $this->transport->send('POST', 'http://typesense:8108/collections', [], '{}');
            $this->fail('TransportException expected');
        } catch (TransportException $e) {
            $this->assertSame($errno, $e->getCurlErrno());
            $this->assertSame($sent, $e->isRequestSent());
            $this->assertSame($timeout, $e->isTimeout());
        }
    }

    public function testErrorStateIsReadBeforeTheHandleIsClosed(): void
    {
        // close() drops the handle, and with it errno/error/info.
        $calls = [];
        $this->curl->method('read')->willReturn('');
        $this->curl->method('getErrno')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'getErrno';
            return 7;
        });
        $this->curl->method('getError')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'getError';
            return 'Connection refused';
        });
        $this->curl->method('getInfo')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'getInfo';
            return 0.0;
        });
        $this->curl->method('close')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'close';
            return $this->curl;
        });

        try {
            $this->transport->send('GET', 'http://typesense:8108/health');
            $this->fail('TransportException expected');
        } catch (TransportException $e) {
            $this->assertSame(7, $e->getCurlErrno());
        }

        $this->assertSame('close', end($calls));
        $this->assertSame(['getErrno', 'getError', 'getInfo'], array_slice($calls, 0, 3));
    }

    public function testExistingConstructorCallsDefaultToSentAndNoErrno(): void
    {
        $e = new TransportException('down');

        $this->assertSame(0, $e->getCurlErrno());
        $this->assertTrue($e->isRequestSent());
        $this->assertFalse($e->isTimeout());
    }

    public function testUnparseableMessageIsATransportException(): void
    {
        $this->curl->method('read')->willReturn('gibberish');

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('could not be parsed');

        $this->transport->send('GET', 'http://typesense:8108/health');
    }
}
