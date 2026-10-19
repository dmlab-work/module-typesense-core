<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Integration;

use DmLab\TypesenseCore\Console\Command\PruneCollectionsCommand;
use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseCore\Model\Collection\OrphanFinder;
use DmLab\TypesenseCore\Test\Integration\Doubles\ScopedOrphanFinder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class OrphanPruneTest extends TypesenseTestCase
{
    private const TWO_DAYS = 172800;

    public function testDryRunListsAndARealRunDropsOnlyOldUnaliasedCollections(): void
    {
        $alias = $this->resourceName('products');
        $now = (int)$this->dateTime->gmtTimestamp();
        $live = $alias . '_' . ($now - self::TWO_DAYS - 100) . '_aaaaaa';
        $orphan = $alias . '_' . ($now - self::TWO_DAYS) . '_bbbbbb';
        $pending = $alias . '_' . $now . '_cccccc';
        foreach ([$live, $orphan, $pending] as $collection) {
            $this->collections->create($collection, [new FieldSpec('sku', 'string')]);
        }
        $this->aliases->upsert($alias, $live);

        $finder = new ScopedOrphanFinder(
            new OrphanFinder($this->collections, $this->aliases, $this->dateTime, $this->aliasLock),
            $this->prefix
        );
        $tester = new CommandTester(new PruneCollectionsCommand($finder));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        $this->assertSame('Would drop ' . $orphan, trim($tester->getDisplay()));
        $this->assertTrue($this->collections->exists($orphan));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame('Dropped ' . $orphan, trim($tester->getDisplay()));
        $this->assertFalse($this->collections->exists($orphan));
        $this->assertTrue($this->collections->exists($live));
        $this->assertTrue($this->collections->exists($pending));
    }
}
