<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseCore\Test\Unit\Console\Command;

use MageDevGroup\TypesenseCore\Console\Command\PingCommand;
use MageDevGroup\TypesenseCore\Exception\ConfigurationException;
use MageDevGroup\TypesenseCore\Exception\TransportException;
use MageDevGroup\TypesenseCore\Model\Client\Http\Response;
use MageDevGroup\TypesenseCore\Api\ConnectionSettingsInterface;
use MageDevGroup\TypesenseCore\Model\Client\Http\TransportInterface;
use MageDevGroup\TypesenseCore\Model\Config\Node;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
class PingCommandTest extends TestCase
{
    /** @var ConnectionSettingsInterface|MockObject */
    private MockObject $config;

    /** @var TransportInterface|MockObject */
    private MockObject $transport;

    /** @var CommandTester */
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConnectionSettingsInterface::class);
        $this->transport = $this->createMock(TransportInterface::class);
        $this->config->method('getApiKey')->willReturn('secret');

        $this->tester = new CommandTester(new PingCommand($this->config, $this->transport));
    }

    public function testHealthyNodeReportsOkAndVersion(): void
    {
        $this->config->method('getNodes')->willReturn([new Node('typesense', 8108)]);
        $this->transport->method('send')->willReturnCallback(
            static fn(string $method, string $url): Response => str_ends_with($url, '/health')
                ? new Response(200, '{"ok":true}')
                : new Response(200, '{"version":"30.2","state":1}')
        );

        $this->assertSame(Command::SUCCESS, $this->tester->execute([]));
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('http://typesense:8108 OK', $display);
        $this->assertStringContainsString('30.2', $display);
    }

    public function testEveryConfiguredNodeIsProbedSeparately(): void
    {
        // The client fails over and hides which node answered; a diagnostic must not.
        $this->config->method('getNodes')->willReturn([new Node('a', 8108), new Node('b', 8108)]);
        $this->transport->method('send')->willReturnCallback(
            static fn(string $method, string $url): Response => str_ends_with($url, '/health')
                ? new Response(200, '{"ok":true}')
                : new Response(200, '{"version":"30.2"}')
        );

        $this->assertSame(Command::SUCCESS, $this->tester->execute([]));
        $this->assertStringContainsString('http://a:8108 OK', $this->tester->getDisplay());
        $this->assertStringContainsString('http://b:8108 OK', $this->tester->getDisplay());
    }

    public function testTheApiKeyHeaderIsSent(): void
    {
        $this->config->method('getNodes')->willReturn([new Node('typesense', 8108)]);
        $this->transport->method('send')
            ->with(
                'GET',
                $this->anything(),
                $this->callback(
                    static fn(array $headers): bool => ($headers['X-TYPESENSE-API-KEY'] ?? null) === 'secret'
                )
            )
            ->willReturn(new Response(200, '{"ok":true,"version":"30.2"}'));

        $this->assertSame(Command::SUCCESS, $this->tester->execute([]));
    }

    public function testUnreachableNodeExitsNonZero(): void
    {
        $this->config->method('getNodes')->willReturn([new Node('typesense', 8108)]);
        $this->transport->method('send')->willThrowException(new TransportException('connection refused'));

        $this->assertSame(Command::FAILURE, $this->tester->execute([]));
        $this->assertStringContainsString('DOWN', $this->tester->getDisplay());
        $this->assertStringContainsString('connection refused', $this->tester->getDisplay());
    }

    public function testOneDownNodeAmongHealthyOnesStillExitsNonZero(): void
    {
        $this->config->method('getNodes')->willReturn([new Node('a', 8108), new Node('b', 8108)]);
        $this->transport->method('send')->willReturnCallback(
            static function (string $method, string $url): Response {
                if (str_starts_with($url, 'http://b:8108')) {
                    throw new TransportException('connection refused');
                }

                return new Response(200, '{"ok":true,"version":"30.2"}');
            }
        );

        $this->assertSame(Command::FAILURE, $this->tester->execute([]));
        $this->assertStringContainsString('http://a:8108 OK', $this->tester->getDisplay());
        $this->assertStringContainsString('http://b:8108 DOWN', $this->tester->getDisplay());
    }

    public function testUnhealthyNodeExitsNonZero(): void
    {
        $this->config->method('getNodes')->willReturn([new Node('typesense', 8108)]);
        $this->transport->method('send')->willReturn(new Response(503, '{"ok":false}'));

        $this->assertSame(Command::FAILURE, $this->tester->execute([]));
        $this->assertStringContainsString('DOWN', $this->tester->getDisplay());
    }

    public function testNodeAnsweringHealthButNotDebugIsStillUpWithAnUnknownVersion(): void
    {
        $this->config->method('getNodes')->willReturn([new Node('typesense', 8108)]);
        $this->transport->method('send')->willReturnCallback(
            static function (string $method, string $url): Response {
                if (str_ends_with($url, '/debug')) {
                    throw new TransportException('timeout');
                }

                return new Response(200, '{"ok":true}');
            }
        );

        $this->assertSame(Command::SUCCESS, $this->tester->execute([]));
        $this->assertStringContainsString('version unknown', $this->tester->getDisplay());
    }

    public function testBrokenConfigExitsNonZeroWithoutAnyRequest(): void
    {
        $this->config->method('getNodes')->willThrowException(new ConfigurationException('No Typesense nodes.'));
        $this->transport->expects($this->never())->method('send');

        $this->assertSame(Command::FAILURE, $this->tester->execute([]));
        $this->assertStringContainsString('No Typesense nodes.', $this->tester->getDisplay());
    }
}
