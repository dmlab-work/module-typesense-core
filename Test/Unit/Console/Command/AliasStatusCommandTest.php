<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit\Console\Command;

use DmLab\TypesenseCore\Console\Command\AliasStatusCommand;
use DmLab\TypesenseCore\Exception\TransportException;
use DmLab\TypesenseCore\Model\Collection\AliasManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
class AliasStatusCommandTest extends TestCase
{
    /** @var AliasManager|MockObject */
    private MockObject $aliases;

    /** @var CommandTester */
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->aliases = $this->createMock(AliasManager::class);
        $this->tester = new CommandTester(new AliasStatusCommand($this->aliases));
    }

    public function testAliasesAreListedWithTheirTargets(): void
    {
        $this->aliases->expects($this->once())
            ->method('getList')
            ->willReturn(['products_en' => 'products_en_1752710400', 'products_de' => 'products_de_1752710400']);

        $this->assertSame(Command::SUCCESS, $this->tester->execute([]));
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('products_en', $display);
        $this->assertStringContainsString('products_en_1752710400', $display);
        $this->assertStringContainsString('products_de_1752710400', $display);
    }

    public function testNoAliasesIsNotAFailure(): void
    {
        $this->aliases->method('getList')->willReturn([]);

        $this->assertSame(Command::SUCCESS, $this->tester->execute([]));
        $this->assertStringContainsString('No aliases defined.', $this->tester->getDisplay());
    }

    public function testConnectionFailureExitsNonZero(): void
    {
        $this->aliases->method('getList')->willThrowException(new TransportException('connection refused'));

        $this->assertSame(Command::FAILURE, $this->tester->execute([]));
        $this->assertStringContainsString('connection refused', $this->tester->getDisplay());
    }
}
