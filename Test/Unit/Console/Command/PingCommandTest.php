<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit\Console\Command;

use DmLab\TypesenseCore\Console\Command\PingCommand;
use DmLab\TypesenseCore\Exception\ConfigurationException;
use DmLab\TypesenseCore\Exception\TransportException;
use DmLab\TypesenseCore\Model\Client\Http\Response;
use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Model\Client\Http\TransportInterface;
use DmLab\TypesenseCore\Model\Config\Node;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testTheApiKeyHeaderIsSentToDebugOnly(): void
    {
        $this->config->method('getNodes')->willReturn([new Node('typesense', 8108)]);
        $sent = [];
        $this->transport->method('send')->willReturnCallback(
            static function (string $method, string $url, array $headers) use (&$sent): Response {
                $sent[basename($url)] = $headers['X-TYPESENSE-API-KEY'] ?? null;

                return new Response(200, '{"ok":true,"version":"30.2"}');
            }
        );

        $this->assertSame(Command::SUCCESS, $this->tester->execute([]));
        $this->assertSame(['health' => null, 'debug' => 'secret'], $sent);
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

    public function testUnsetKeyStillProbesHealthWithoutKeyAndExitsNonZero(): void
    {
        $config = $this->createStub(ConnectionSettingsInterface::class);
        $config->method('getNodes')->willReturn([new Node('typesense', 8108)]);
        $config->method('getApiKey')->willThrowException(new ConfigurationException('API key is not set.'));
        $this->transport->expects($this->once())
            ->method('send')
            ->with(
                'GET',
                'http://typesense:8108/health',
                $this->callback(static fn(array $headers): bool => !isset($headers['X-TYPESENSE-API-KEY']))
            )
            ->willReturn(new Response(200, '{"ok":true}'));
        $tester = new CommandTester(new PingCommand($config, $this->transport));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('http://typesense:8108 OK', $display);
        $this->assertStringContainsString('API key not configured — health only', $display);
        $this->assertSame(1, substr_count($display, 'API key not configured'));
    }

    public function testUnsetKeyWithDownNodeReportsBoth(): void
    {
        $config = $this->createStub(ConnectionSettingsInterface::class);
        $config->method('getNodes')->willReturn([new Node('typesense', 8108)]);
        $config->method('getApiKey')->willThrowException(new ConfigurationException('API key is not set.'));
        $this->transport->method('send')->willThrowException(new TransportException('connection refused'));
        $tester = new CommandTester(new PingCommand($config, $this->transport));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('http://typesense:8108 DOWN', $tester->getDisplay());
        $this->assertStringContainsString('API key not configured', $tester->getDisplay());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function rejectedStatuses(): array
    {
        return ['401' => [401], '403' => [403]];
    }

    #[DataProvider('rejectedStatuses')]
    public function testRejectedKeyIsReportedDistinctlyAndExitsNonZero(int $status): void
    {
        $this->config->method('getNodes')->willReturn([new Node('typesense', 8108)]);
        $this->transport->method('send')->willReturnCallback(
            static fn(string $method, string $url): Response => str_ends_with($url, '/health')
                ? new Response(200, '{"ok":true}')
                : new Response($status, '{"message":"Forbidden - a valid `x-typesense-api-key` header must be sent."}')
        );

        $this->assertSame(Command::FAILURE, $this->tester->execute([]));
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString(sprintf('http://typesense:8108 KEY REJECTED (HTTP %d)', $status), $display);
        $this->assertStringNotContainsString('OK', $display);
    }

    public function testDebugServerErrorKeepsTheNodeUpWithAnUnknownVersion(): void
    {
        $this->config->method('getNodes')->willReturn([new Node('typesense', 8108)]);
        $this->transport->method('send')->willReturnCallback(
            static fn(string $method, string $url): Response => str_ends_with($url, '/health')
                ? new Response(200, '{"ok":true}')
                : new Response(500, 'boom')
        );

        $this->assertSame(Command::SUCCESS, $this->tester->execute([]));
        $this->assertStringContainsString('http://typesense:8108 OK (version unknown)', $this->tester->getDisplay());
    }

    public function testDebugIsSkippedForADownNode(): void
    {
        $this->config->method('getNodes')->willReturn([new Node('typesense', 8108)]);
        $this->transport->expects($this->once())->method('send')->willReturn(new Response(200, '{"ok":false}'));

        $this->assertSame(Command::FAILURE, $this->tester->execute([]));
        $this->assertStringContainsString('UNHEALTHY', $this->tester->getDisplay());
    }

    public function testApiKeyParametersAreSensitive(): void
    {
        $class = new \ReflectionClass(PingCommand::class);
        $checked = 0;
        foreach ($class->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                if ($parameter->getName() !== 'apiKey') {
                    continue;
                }
                $checked++;
                $this->assertNotEmpty(
                    $parameter->getAttributes(\SensitiveParameter::class),
                    $method->getName() . '($apiKey) lacks #[\\SensitiveParameter]'
                );
            }
        }
        $this->assertGreaterThan(0, $checked);
    }
}
