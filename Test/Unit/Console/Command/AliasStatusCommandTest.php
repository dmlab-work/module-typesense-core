<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit\Console\Command;

use DmLab\TypesenseCore\Console\Command\AliasStatusCommand;
use DmLab\TypesenseCore\Exception\TransportException;
use DmLab\TypesenseCore\Model\Collection\AliasManager;
use DmLab\TypesenseCore\Model\Collection\OrphanFinder;
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

    /** @var OrphanFinder|MockObject */
    private MockObject $orphans;

    /** @var CommandTester */
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->aliases = $this->createMock(AliasManager::class);
        $this->orphans = $this->createMock(OrphanFinder::class);
        $this->tester = new CommandTester(new AliasStatusCommand($this->aliases, $this->orphans));
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

    public function testOrphanCountIsShownAfterTheTable(): void
    {
        $this->aliases->method('getList')->willReturn(['products' => 'products_1752710400_aaaaaa']);
        $this->orphans->expects($this->once())
            ->method('find')
            ->with(0)
            ->willReturn(['products_1752710000_bbbbbb', 'products_1752710100_cccccc']);

        $this->assertSame(Command::SUCCESS, $this->tester->execute([]));
        $this->assertStringContainsString(
            'Unaliased versioned collections: 2 (see dmlab:typesense:collections:prune)',
            $this->tester->getDisplay()
        );
    }

    public function testNoOrphansPrintsNoHint(): void
    {
        $this->aliases->method('getList')->willReturn(['products' => 'products_1752710400_aaaaaa']);
        $this->orphans->method('find')->willReturn([]);

        $this->assertSame(Command::SUCCESS, $this->tester->execute([]));
        $this->assertStringNotContainsString('Unaliased', $this->tester->getDisplay());
    }

    public function testOrphanLookupFailureDoesNotChangeTheExitCode(): void
    {
        $this->aliases->method('getList')->willReturn(['products' => 'products_1752710400_aaaaaa']);
        $this->orphans->method('find')->willThrowException(new TransportException('timeout'));

        $this->assertSame(Command::SUCCESS, $this->tester->execute([]));
        $this->assertStringNotContainsString('Unaliased', $this->tester->getDisplay());
    }
}
