<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit\Console\Command;

use DmLab\TypesenseCore\Console\Command\PruneCollectionsCommand;
use DmLab\TypesenseCore\Exception\TransportException;
use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Collection\OrphanFinder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
class PruneCollectionsCommandTest extends TestCase
{
    /** @var OrphanFinder|MockObject */
    private MockObject $orphans;

    /** @var CommandTester */
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->orphans = $this->createMock(OrphanFinder::class);
        $this->tester = new CommandTester(new PruneCollectionsCommand($this->orphans));
    }

    public function testDefaultMinAgeIsOneDay(): void
    {
        $this->orphans->expects($this->once())->method('find')->with(86400)->willReturn([]);

        $this->assertSame(Command::SUCCESS, $this->tester->execute([]));
        $this->assertStringContainsString('No unaliased versioned collections.', $this->tester->getDisplay());
    }

    public function testDryRunListsAndDropsNothing(): void
    {
        $this->orphans->method('find')->willReturn(['products_1752000000_9f3ac1']);
        $this->orphans->expects($this->never())->method('drop');

        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--dry-run' => true]));
        $this->assertStringContainsString('Would drop products_1752000000_9f3ac1', $this->tester->getDisplay());
    }

    public function testRealRunDropsEveryOrphan(): void
    {
        $this->orphans->expects($this->once())->method('find')->with(60)
            ->willReturn(['products_1752000000_9f3ac1', 'products_1752000100_0a1b2c']);
        $dropped = [];
        $this->orphans->expects($this->exactly(2))->method('drop')
            ->willReturnCallback(static function (string $name) use (&$dropped): bool {
                $dropped[] = $name;

                return true;
            });

        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--min-age' => '60']));
        $this->assertSame(['products_1752000000_9f3ac1', 'products_1752000100_0a1b2c'], $dropped);
        $this->assertStringContainsString('Dropped products_1752000100_0a1b2c', $this->tester->getDisplay());
    }

    public function testAlreadyGoneCollectionIsNotAFailure(): void
    {
        $this->orphans->method('find')->willReturn(['products_1752000000_9f3ac1']);
        $this->orphans->method('drop')->willThrowException(new TypesenseException('Not found.', 404));

        $this->assertSame(Command::SUCCESS, $this->tester->execute([]));
    }

    public function testFailedDropIsReportedAndTheRestStillDropped(): void
    {
        $this->orphans->method('find')->willReturn(['products_1752000000_9f3ac1', 'products_1752000100_0a1b2c']);
        $this->orphans->expects($this->exactly(2))->method('drop')->willReturnCallback(
            static function (string $name): bool {
                if ($name === 'products_1752000000_9f3ac1') {
                    throw new TypesenseException('Service unavailable.', 503);
                }

                return true;
            }
        );

        $this->assertSame(Command::FAILURE, $this->tester->execute([]));
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString(
            'Failed to drop products_1752000000_9f3ac1: Service unavailable.',
            $display
        );
        $this->assertStringContainsString('Dropped products_1752000100_0a1b2c', $display);
    }

    public function testCollectionAliasedSinceTheListingIsSkipped(): void
    {
        $this->orphans->method('find')->willReturn(['products_1752000000_9f3ac1', 'products_1752000100_0a1b2c']);
        $this->orphans->expects($this->exactly(2))->method('drop')
            ->willReturnCallback(static fn (string $name): bool => $name !== 'products_1752000100_0a1b2c');

        $this->assertSame(Command::SUCCESS, $this->tester->execute([]));
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('Dropped products_1752000000_9f3ac1', $display);
        $this->assertStringContainsString('Skipped products_1752000100_0a1b2c: an alias points to it now', $display);
        $this->assertStringNotContainsString('Dropped products_1752000100_0a1b2c', $display);
    }

    public function testFailedRecheckExitsNonZero(): void
    {
        $this->orphans->method('find')->willReturn(['products_1752000000_9f3ac1']);
        $this->orphans->method('drop')->willThrowException(new TransportException('connection refused'));

        $this->assertSame(Command::FAILURE, $this->tester->execute([]));
        $this->assertStringContainsString(
            'Failed to drop products_1752000000_9f3ac1: connection refused',
            $this->tester->getDisplay()
        );
    }

    public function testListingFailureExitsNonZero(): void
    {
        $this->orphans->method('find')->willThrowException(new TransportException('connection refused'));

        $this->assertSame(Command::FAILURE, $this->tester->execute([]));
        $this->assertStringContainsString('connection refused', $this->tester->getDisplay());
    }

    #[DataProvider('invalidMinAges')]
    public function testInvalidMinAgeFails(string $minAge): void
    {
        $this->orphans->expects($this->never())->method('find');

        $this->assertSame(Command::FAILURE, $this->tester->execute(['--min-age' => $minAge]));
        $this->assertStringContainsString('--min-age', $this->tester->getDisplay());
    }

    /**
     * @return array<string,array{string}>
     */
    public static function invalidMinAges(): array
    {
        return ['negative' => ['-1'], 'text' => ['day'], 'fraction' => ['1.5'], 'empty' => ['']];
    }
}
